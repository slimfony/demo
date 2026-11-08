<?php

declare(strict_types=1);

namespace Demo\Tests\Support;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Slimfony\Core\Database\ConnectionFactory;

/**
 * A test that needs a real PostgreSQL (row locks, savepoints, deadlocks are not something SQLite does the same way).
 * Set TEST_DATABASE_URL, as the CI does; without it the tests are skipped. Every test starts with the demo schema, freshly created.
 */
abstract class PostgresTestCase extends TestCase
{
    protected Connection $connection;

    protected function setUp(): void
    {
        if (!\is_string(getenv('TEST_DATABASE_URL')) || '' === getenv('TEST_DATABASE_URL')) {
            self::markTestSkipped('TEST_DATABASE_URL is not set.');
        }
        $this->connection = $this->newConnection();
        $this->connection->executeStatement('DROP SCHEMA public CASCADE');
        $this->connection->executeStatement('CREATE SCHEMA public');
        foreach (['20-schema.sql', '30-import.sql'] as $file) {
            $this->connection->executeStatement((string) file_get_contents(__DIR__ . '/../../docker/postgres/' . $file));
        }
    }

    /** Another connection to the same database (to play a second worker). */
    protected function newConnection(): Connection
    {
        return ConnectionFactory::create((string) getenv('TEST_DATABASE_URL'));
    }

    protected function rows(string $table, string $where = 'true'): int
    {
        $count = $this->connection->fetchOne("SELECT count(*) FROM $table WHERE $where");
        self::assertIsInt($count);

        return $count;
    }
}
