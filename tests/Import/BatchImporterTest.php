<?php

declare(strict_types=1);

namespace Demo\Tests\Import;

use Demo\Camt\CamtParser;
use Demo\Camt\StatementGenerator;
use Demo\Camt\StatementRejected;
use Demo\Camt\StatementRules;
use Demo\Import\ArchiveReader;
use Demo\Import\BatchImporter;
use Demo\Import\ImportRepository;
use Demo\Import\ImportResult;
use Demo\Import\StatementWriter;
use Demo\Imports\Archive;
use Demo\Imports\ArchiveLimits;
use Demo\Imports\BatchRepository;
use Demo\Imports\ImportMode;
use Demo\Imports\ImportSource;
use Demo\Imports\LockOrder;
use Demo\Imports\RejectedUpload;
use Demo\Tests\Support\PostgresTestCase;
use Slimfony\Core\Database\Retry;

/** The import against a real PostgreSQL: what is booked, what is rolled back, what is recorded. */
final class BatchImporterTest extends PostgresTestCase
{
    private StatementGenerator $generator;
    private BatchImporter $importer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new StatementGenerator();
        $this->importer = new BatchImporter(
            new ImportRepository($this->connection),
            new ArchiveReader(),
            new CamtParser(__DIR__ . '/../../schema/camt.053.001.02.xsd'),
            new StatementRules(),
            new StatementWriter(),
            new Retry($this->connection),
        );
    }

    /**
     * Stores a ZIP of the files as a queued batch, the way the upload route does.
     *
     * @param array<string, string> $files
     */
    private function queue(array $files, ImportMode $mode = ImportMode::Partial, ImportSource $source = ImportSource::Jwt, LockOrder $order = LockOrder::Sorted): string
    {
        $bytes = $this->generator->zip($files);
        $id = '6f9619ff-8b86-4d11-b42d-' . str_pad((string) random_int(1, 999_999_999_999), 12, '0', \STR_PAD_LEFT);
        new BatchRepository()->insertAccepted($this->connection, $id, new Archive($bytes, hash('sha256', $bytes), \count($files)), $mode, $source, 'tester', 'k1', $order);

        return $id;
    }

    /**
     * Ten good statements, with the one at $position replaced by a statement that is wrong in the given way.
     *
     * @return array<string, string>
     */
    private function tenWithOneWrong(string $fault, int $position = 4): array
    {
        $files = $this->generator->files(1, 10);
        $names = array_keys($files);
        $files[$names[$position]] = array_values($this->generator->files(2, 1, 1.0, $fault))[0];

        return $files;
    }

    private function balanceOf(int $account): string
    {
        $balance = $this->connection->fetchOne('SELECT booked_balance FROM accounts WHERE iban = ?', [StatementGenerator::iban($account)]);
        self::assertIsString($balance);

        return $balance;
    }

    public function testInPartialModeAGoodZipIsBookedCompletely(): void
    {
        $id = $this->queue($this->generator->files(1, 10));

        $result = $this->importer->import($id, 'w1');

        self::assertInstanceOf(ImportResult::class, $result);
        self::assertSame([ImportResult::DONE, 10, 0], [$result->status, $result->statementsOk, $result->statementsFailed]);
        self::assertSame(10, $this->rows('statements'));
        self::assertSame($result->entries, $this->rows('entries'));
        self::assertSame(['done', 'w1', 10, 0], array_values((array) $this->connection->fetchAssociative('SELECT status, worker, statements_ok, statements_failed FROM batches WHERE id = ?', [$id])));
    }

    public function testInPartialModeABadStatementIsRolledBackAndTheOthersStay(): void
    {
        $id = $this->queue($this->tenWithOneWrong(StatementRejected::BALANCE_MISMATCH));

        $result = $this->importer->import($id, 'w1');

        self::assertNotNull($result);
        self::assertSame([ImportResult::DONE, 9, 1, 0], [$result->status, $result->statementsOk, $result->statementsFailed, $result->rolledBack]);
        self::assertSame(9, $this->rows('statements'));
        $error = $this->connection->fetchAssociative('SELECT file, reason FROM import_errors');
        self::assertSame(['file' => 'statement-05.xml', 'reason' => 'balance_mismatch'], $error);
    }

    public function testTheRulesThatNeedTheDatabaseRejectOnlyTheirOwnStatement(): void
    {
        foreach ([StatementRejected::UNKNOWN_ACCOUNT, StatementRejected::CURRENCY_MISMATCH] as $fault) {
            $id = $this->queue($this->tenWithOneWrong($fault));
            $result = $this->importer->import($id, 'w1');

            self::assertNotNull($result);
            self::assertSame([9, 1], [$result->statementsOk, $result->statementsFailed], $fault);
            self::assertSame($fault, $this->connection->fetchOne('SELECT reason FROM import_errors WHERE batch_id = ?', [$id]));
            $this->connection->executeStatement('DELETE FROM entries; DELETE FROM statements'); // so the next round can reuse the statement ids
        }
    }

    public function testInStrictModeOneBadStatementRollsBackTheWholeZipAndEveryoneIsAccountedFor(): void
    {
        $before = $this->balanceOf(1);
        $id = $this->queue($this->tenWithOneWrong(StatementRejected::BALANCE_MISMATCH), ImportMode::Strict);

        $result = $this->importer->import($id, 'w1');

        self::assertNotNull($result);
        self::assertSame([ImportResult::FAILED, 0, 1, 9], [$result->status, $result->statementsOk, $result->statementsFailed, $result->rolledBack]);
        self::assertSame(0, $this->rows('statements'), 'nothing of the ZIP is in the database');
        self::assertSame(0, $this->rows('entries'));
        self::assertSame($before, $this->balanceOf(1), 'the account balances did not move');
        self::assertSame(1, $this->rows('import_errors', "reason = 'balance_mismatch'")); // the errors were written after the rollback and stay
        self::assertSame(9, $this->rows('import_errors', "reason = 'rolled_back'"));
    }

    public function testInStrictModeEveryMistakeIsReportedNotOnlyTheFirst(): void
    {
        $files = $this->tenWithOneWrong(StatementRejected::BALANCE_MISMATCH, 2);
        $names = array_keys($files);
        $files[$names[7]] = array_values($this->generator->files(3, 1, 1.0, StatementRejected::UNKNOWN_ACCOUNT))[0];

        $result = $this->importer->import($this->queue($files, ImportMode::Strict), 'w1');

        self::assertNotNull($result);
        self::assertSame([2, 8], [$result->statementsFailed, $result->rolledBack]);
        self::assertSame(0, $this->rows('statements'));
    }

    public function testInStrictModeAGoodZipIsBookedInOneTransaction(): void
    {
        $result = $this->importer->import($this->queue($this->generator->files(1, 10), ImportMode::Strict), 'w1');

        self::assertNotNull($result);
        self::assertSame([ImportResult::DONE, 10], [$result->status, $result->statementsOk]);
        self::assertSame(10, $this->rows('statements'));
    }

    public function testARepeatedDeliveryDoesNothing(): void
    {
        $id = $this->queue($this->generator->files(1, 10));

        self::assertNotNull($this->importer->import($id, 'w1'));
        self::assertNull($this->importer->import($id, 'w2')); // the batch is not queued any more
        self::assertSame(10, $this->rows('statements'));
    }

    public function testTheSameStatementCannotBeImportedTwice(): void
    {
        $files = $this->generator->files(1, 10);
        $this->importer->import($this->queue($files), 'w1');

        $second = $this->importer->import($this->queue($files), 'w1');

        self::assertNotNull($second);
        self::assertSame([0, 10], [$second->statementsOk, $second->statementsFailed]);
        self::assertSame(10, $this->rows('import_errors', "reason = 'duplicate_statement'"));
        self::assertSame(10, $this->rows('statements'));
    }

    public function testAZipOverTheSourceLimitsFailsAsAWhole(): void
    {
        $id = $this->queue($this->generator->files(1, 11), ImportMode::Partial, ImportSource::Public); // the sandbox takes 10 files

        $result = $this->importer->import($id, 'w1');

        self::assertNotNull($result);
        self::assertSame(ImportResult::FAILED, $result->status);
        self::assertSame('too_many_files', $this->connection->fetchOne('SELECT reason FROM import_errors WHERE batch_id = ?', [$id]));
        self::assertSame(0, $this->rows('statements'));
    }

    public function testTheReaderCountsTheBytesThatReallyComeOut(): void
    {
        $bomb = $this->generator->zip(['a.xml' => str_repeat('a', 3_000_000)]); // a few KB in the ZIP, three MB out
        $reader = new ArchiveReader();

        try {
            $reader->read($bomb, new ArchiveLimits(maxBytes: 1_000_000, maxFiles: 5, maxUnpackedBytes: 1_048_576));
            self::fail('The reader should have stopped.');
        } catch (RejectedUpload $e) {
            self::assertSame('too_much_data', $e->reason);
        }
    }
    public function testBothLockOrdersBookExactlyTheSame(): void
    {
        $files = $this->generator->files(1, 10);
        $sorted = $this->importer->import($this->queue($files, order: LockOrder::Sorted), 'w1');
        $after = [$this->rows('statements'), $this->rows('entries'), $this->balanceOf(1), $this->connection->fetchOne('SELECT sum(entries_total) FROM account_stats')];

        $this->connection->executeStatement('TRUNCATE batches, outbox CASCADE');
        $this->connection->executeStatement("UPDATE accounts SET booked_balance = 100000.00; UPDATE account_stats SET entries_total = 0, last_booking_date = NULL");
        $appearance = $this->importer->import($this->queue($files, order: LockOrder::Appearance), 'w1');

        self::assertNotNull($sorted);
        self::assertNotNull($appearance);
        self::assertSame([$sorted->statementsOk, $sorted->entries], [$appearance->statementsOk, $appearance->entries]);
        self::assertSame($after, [$this->rows('statements'), $this->rows('entries'), $this->balanceOf(1), $this->connection->fetchOne('SELECT sum(entries_total) FROM account_stats')], 'the same statements, the same balances, the same statistics');
    }
}
