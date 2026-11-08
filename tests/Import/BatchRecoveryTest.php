<?php

declare(strict_types=1);

namespace Demo\Tests\Import;

use Demo\Camt\CamtParser;
use Demo\Camt\StatementGenerator;
use Demo\Camt\StatementRules;
use Demo\Import\ArchiveReader;
use Demo\Import\BatchImporter;
use Demo\Import\ImportRepository;
use Demo\Import\StatementWriter;
use Demo\Imports\Archive;
use Demo\Imports\BatchRepository;
use Demo\Imports\ImportMode;
use Demo\Imports\ImportSource;
use Demo\Tests\Support\PostgresTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Slimfony\Core\Database\Retry;

/** A batch must never be left behind: not when a worker gives up, not when it dies, and a second run must not book anything twice. */
final class BatchRecoveryTest extends PostgresTestCase
{
    private StatementGenerator $generator;
    private ImportRepository $repository;
    private BatchImporter $importer;
    private CamtParser $parser;
    private StatementWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new StatementGenerator();
        $this->repository = new ImportRepository($this->connection);
        $this->parser = new CamtParser(__DIR__ . '/../../schema/camt.053.001.02.xsd');
        $this->writer = new StatementWriter();
        $this->importer = new BatchImporter($this->repository, new ArchiveReader(), $this->parser, new StatementRules(), $this->writer, new Retry($this->connection));
    }

    /**
     * @param array<string, string> $files
     */
    private function queue(array $files): string
    {
        $bytes = $this->generator->zip($files);
        $id = '6f9619ff-8b86-4d11-b42d-' . str_pad((string) random_int(1, 999_999_999_999), 12, '0', \STR_PAD_LEFT);
        new BatchRepository()->insertAccepted($this->connection, $id, new Archive($bytes, hash('sha256', $bytes), \count($files)), ImportMode::Partial, ImportSource::Jwt, 'tester', 'k1');

        return $id;
    }

    private function batchStatus(string $id): string
    {
        $status = $this->connection->fetchOne('SELECT status FROM batches WHERE id = ?', [$id]);
        self::assertIsString($status);

        return $status;
    }

    public function testABatchThatAWorkerIsOnIsNotTakenButAnAbandonedOneIs(): void
    {
        $id = $this->queue($this->generator->files(1, 3));
        self::assertNotNull($this->repository->claim($id, 'w1'));

        self::assertNull($this->repository->claim($id, 'w2'), 'w1 is working on it');

        $this->connection->executeStatement("UPDATE batches SET started_at = now() - interval '2 minutes' WHERE id = ?", [$id]);
        self::assertNotNull($this->repository->claim($id, 'w2'), 'w1 started it two minutes ago and never finished: it is taken again');
    }

    public function testAFailureGivesTheBatchBackSoItIsNotLostWhenTheMessageComesAgain(): void
    {
        $id = $this->queue($this->generator->files(1, 3));
        $this->connection->executeStatement('DROP TABLE account_stats CASCADE'); // the first write of a statement fails for a reason the importer cannot fix

        try {
            $this->importer->import($id, 'w1');
            self::fail('The failure should have reached the worker, so the message is not acknowledged.');
        } catch (DriverException) {
        }

        self::assertSame('queued', $this->batchStatus($id));
        self::assertSame(0, $this->rows('statements'));
    }

    public function testARunThatWasCutShortIsFinishedWithoutBookingAnythingTwice(): void
    {
        $files = $this->generator->files(1, 10);
        $id = $this->queue($files);

        // an earlier run booked the first statement and then its worker died
        $this->repository->claim($id, 'dead-worker');
        $first = $this->parser->parse(array_values($files)[0], 'statement-01.xml');
        new Retry($this->connection)->transactional(fn(Connection $connection): int => $this->writer->write($connection, $first, $id));
        $this->connection->executeStatement("UPDATE batches SET started_at = now() - interval '2 minutes' WHERE id = ?", [$id]);

        $result = $this->importer->import($id, 'w2');

        self::assertNotNull($result);
        self::assertSame(10, $result->statementsOk);
        self::assertSame(0, $this->rows('import_errors'), 'the statement that was already booked is not reported as a duplicate');
        self::assertSame(10, $this->rows('statements'));
        self::assertSame('done', $this->batchStatus($id));
    }
}
