<?php

declare(strict_types=1);

namespace Demo\Tests\Outbox;

use Demo\Camt\StatementGenerator;
use Demo\Import\ImportRepository;
use Demo\Imports\Archive;
use Demo\Imports\BatchRepository;
use Demo\Imports\ImportMode;
use Demo\Imports\ImportSource;
use Demo\Outbox\StaleBatchSweeper;
use Demo\Tests\Support\PostgresTestCase;
use Slimfony\Core\Database\Retry;

/** No batch is forgotten: one that has waited too long is announced again, one that is fine is left alone. */
final class StaleBatchSweeperTest extends PostgresTestCase
{
    private function batch(string $status, string $age): string
    {
        $bytes = new StatementGenerator()->zip(new StatementGenerator()->files(1, 1));
        $id = '6f9619ff-8b86-4d11-b42d-' . str_pad((string) random_int(1, 999_999_999_999), 12, '0', \STR_PAD_LEFT);
        new BatchRepository()->insertAccepted($this->connection, $id, new Archive($bytes, hash('sha256', $bytes), 1), ImportMode::Partial, ImportSource::Jwt, null, null);
        $this->connection->executeStatement(
            "UPDATE batches SET status = ?, created_at = now() - ?::interval, started_at = CASE WHEN ? = 'running' THEN now() - ?::interval ELSE NULL END WHERE id = ?",
            [$status, $age, $status, $age, $id],
        );
        $this->connection->executeStatement('DELETE FROM outbox'); // only what the sweeper adds counts

        return $id;
    }

    public function testABatchThatHasWaitedTooLongIsAnnouncedAgainOnceAndOnlyOnce(): void
    {
        $forgotten = $this->batch('queued', '5 minutes');
        $fresh = $this->batch('queued', '5 seconds');
        $sweeper = new StaleBatchSweeper(new Retry($this->connection), 120);

        self::assertSame(1, $sweeper->sweep());
        self::assertSame($forgotten, $this->connection->fetchOne("SELECT payload->>'batch_id' FROM outbox"));
        self::assertSame(0, $sweeper->sweep(), 'it is not announced every ten seconds, only again after another delay');
        self::assertSame(1, $this->rows('outbox'));
        self::assertNull($this->connection->fetchOne('SELECT reannounced_at FROM batches WHERE id = ?', [$fresh]), 'a batch that is only a few seconds old is left alone');
    }

    public function testABatchAWorkerAbandonedWhileRunningIsAnnouncedAgainButFinishedOnesAreNot(): void
    {
        $abandoned = $this->batch('running', '5 minutes');
        $this->batch('done', '5 minutes');
        $this->batch('failed', '5 minutes');

        self::assertSame(1, new StaleBatchSweeper(new Retry($this->connection), 120)->sweep());
        self::assertSame($abandoned, $this->connection->fetchOne("SELECT payload->>'batch_id' FROM outbox"));
    }

    public function testAGivenUpBatchIsFailedOnRecord(): void
    {
        $id = $this->batch('running', '1 minute');

        new ImportRepository($this->connection)->giveUp($id, 5, 'DeadlockException');

        self::assertSame('failed', $this->connection->fetchOne('SELECT status FROM batches WHERE id = ?', [$id]));
        self::assertSame('gave_up', $this->connection->fetchOne('SELECT reason FROM import_errors WHERE batch_id = ?', [$id]));
        self::assertSame(0, new StaleBatchSweeper(new Retry($this->connection), 1)->sweep(), 'a failed batch is finished: nobody announces it again');
    }
}
