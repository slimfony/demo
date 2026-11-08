<?php

declare(strict_types=1);

namespace Demo\Import;

use Demo\Camt\CamtParser;
use Demo\Camt\Statement;
use Demo\Camt\StatementRejected;
use Demo\Camt\StatementRules;
use Demo\Imports\ImportMode;
use Demo\Imports\ImportSource;
use Demo\Imports\RejectedUpload;
use Doctrine\DBAL\Connection;
use Slimfony\Core\Database\Retry;

/**
 * Imports one uploaded ZIP.
 *
 * - `partial`: every statement is its own transaction. A bad statement is rolled back and recorded; the others stay.
 * - `strict`: the ZIP is one transaction and each statement has a savepoint. All the statements are tried so every
 *   mistake is reported, and if there was any, the whole ZIP is rolled back (the good statements too).
 *
 * In both modes a transaction that the database ends because of a deadlock is run again by `Retry::transactional()`.
 */
final class BatchImporter
{
    private const string SAVEPOINT = 'statement';

    public function __construct(
        private readonly ImportRepository $batches,
        private readonly ArchiveReader $archives,
        private readonly CamtParser $parser,
        private readonly StatementRules $rules,
        private readonly StatementWriter $writer,
        private readonly Retry $retry,
    ) {}

    /**
     * @return ImportResult|null null when the batch was not waiting for this worker (a repeated delivery)
     */
    public function import(string $batchId, string $worker): ?ImportResult
    {
        $started = hrtime(true);
        $batch = $this->batches->claim($batchId, $worker);
        if (null === $batch) {
            return null;
        }
        $mode = ImportMode::from($batch['mode']);
        $source = ImportSource::from($batch['source']);

        try {
            $files = $this->archives->read($batch['bytes'], $source->limits());
        } catch (RejectedUpload $e) {
            $this->batches->recordError($batchId, new StatementRejected($e->reason, '*', $e->getMessage()));
            $this->batches->finish($batchId, ImportResult::FAILED, 0, 0, 0);

            return new ImportResult($batchId, $mode->value, $source->value, ImportResult::FAILED, 0, 0, 0, 0, (hrtime(true) - $started) / 1e9, [$e->reason => 1]);
        }

        // read and check everything that needs no database first
        $statements = [];
        $errors = [];
        foreach ($files as $name => $xml) {
            try {
                $statement = $this->parser->parse($xml, $name);
                $this->rules->check($statement);
                $statements[] = $statement;
            } catch (StatementRejected $e) {
                $errors[] = $e;
            }
        }

        [$ok, $entries, $errors, $rolledBack] = ImportMode::Strict === $mode
            ? $this->strict($batchId, $statements, $errors)
            : $this->partial($batchId, $statements, $errors);

        foreach ($errors as $error) {
            $this->batches->recordError($batchId, $error); // outside every transaction: the rows survive the rollbacks above
        }
        $failed = \count(array_filter($errors, static fn(StatementRejected $e): bool => StatementRejected::ROLLED_BACK !== $e->reason));
        $status = ImportMode::Strict === $mode && $failed > 0 ? ImportResult::FAILED : ImportResult::DONE;
        $this->batches->finish($batchId, $status, $ok, $failed, $entries);

        $reasons = array_count_values(array_map(static fn(StatementRejected $error): string => $error->reason, $errors));

        return new ImportResult($batchId, $mode->value, $source->value, $status, $ok, $failed, $rolledBack, $entries, (hrtime(true) - $started) / 1e9, $reasons);
    }

    /**
     * @param list<Statement>         $statements
     * @param list<StatementRejected> $errors
     *
     * @return array{int, int, list<StatementRejected>, int} statements booked, entries booked, all errors, rolled back
     */
    private function partial(string $batchId, array $statements, array $errors): array
    {
        $ok = 0;
        $entries = 0;
        foreach ($statements as $statement) {
            try {
                $entries += $this->retry->transactional(fn(Connection $connection): int => $this->writer->write($connection, $statement, $batchId));
                ++$ok;
            } catch (StatementRejected $e) { // this statement was rolled back; the next one is a new transaction
                $errors[] = $e;
            }
        }

        return [$ok, $entries, $errors, 0];
    }

    /**
     * @param list<Statement>         $statements
     * @param list<StatementRejected> $errors
     *
     * @return array{int, int, list<StatementRejected>, int}
     */
    private function strict(string $batchId, array $statements, array $errors): array
    {
        try {
            $entries = $this->retry->transactional(function (Connection $connection) use ($batchId, $statements, $errors): int {
                $booked = [];
                $bad = $errors;
                $entries = 0;
                foreach ($statements as $statement) {
                    $connection->createSavepoint(self::SAVEPOINT);
                    try {
                        $entries += $this->writer->write($connection, $statement, $batchId);
                        $connection->releaseSavepoint(self::SAVEPOINT);
                        $booked[] = $statement;
                    } catch (StatementRejected $e) {
                        $connection->rollbackSavepoint(self::SAVEPOINT); // only this statement is undone; the rest can still report their own problems
                        $bad[] = $e;
                    }
                }
                if ([] !== $bad) {
                    throw new ZipRolledBack($bad, array_map(
                        static fn(Statement $s): StatementRejected => new StatementRejected(StatementRejected::ROLLED_BACK, $s->file, 'This statement was fine; it was rolled back because another statement in the ZIP was not.', $s->id),
                        $booked,
                    ));
                }

                return $entries;
            });
        } catch (ZipRolledBack $e) { // the transaction ended with a rollback: nothing of this ZIP is in the database
            return [0, 0, [...$e->errors, ...$e->innocent], \count($e->innocent)];
        }

        return [\count($statements), $entries, [], 0];
    }
}
