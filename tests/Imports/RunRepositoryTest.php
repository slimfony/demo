<?php

declare(strict_types=1);

namespace Demo\Tests\Imports;

use Demo\Imports\ImportMode;
use Demo\Imports\LoadRunRepository;
use Demo\Imports\LockOrder;
use Demo\Imports\RunRepository;
use Demo\Tests\Support\PostgresTestCase;
use Doctrine\DBAL\Connection;

/** Two requests that ask for a run at the same moment: one starts it, the other is told a run is going. */
final class RunRepositoryTest extends PostgresTestCase
{
    public function testTwoImportRunsAskedForAtTheSameTimeAreNotBothStarted(): void
    {
        $runs = new RunRepository();
        $other = $this->newConnection();
        $this->connection->beginTransaction(); // the first request has checked and inserted, and has not committed yet

        self::assertTrue($runs->create($this->connection, '11111111-1111-4111-8111-111111111111', 5, 0.0, ImportMode::Partial, 60, LockOrder::Sorted));
        $second = $other->transactional(static fn(Connection $connection): bool => $runs->create($connection, '22222222-2222-4222-8222-222222222222', 5, 0.0, ImportMode::Partial, 60, LockOrder::Sorted));
        $this->connection->commit();

        self::assertFalse($second, 'the second request cannot see the first one yet, and must not start a run');
        self::assertSame(1, $this->rows('import_runs'));
    }

    public function testTwoLoadRunsAskedForAtTheSameTimeAreNotBothStarted(): void
    {
        $runs = new LoadRunRepository();
        $other = $this->newConnection();
        $this->connection->beginTransaction();

        self::assertTrue($runs->create($this->connection, '11111111-1111-4111-8111-111111111111', 10, '/healthz'));
        $second = $other->transactional(static fn(Connection $connection): bool => $runs->create($connection, '22222222-2222-4222-8222-222222222222', 10, '/healthz'));
        $this->connection->commit();

        self::assertFalse($second);
        self::assertSame(1, $this->rows('load_runs'));
    }
}
