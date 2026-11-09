<?php

declare(strict_types=1);

namespace Demo\Chaos;

use Doctrine\DBAL\Connection;

/**
 * "Kill an importer" is a token in a table: the next importer that takes a batch finds it, removes it and dies.
 * Exactly one importer dies per token, however many look for it at once (`FOR UPDATE SKIP LOCKED`).
 */
final class KillTokens
{
    public function __construct(private readonly Connection $connection) {}

    public function add(): void
    {
        $this->connection->executeStatement("INSERT INTO chaos (action) VALUES ('kill_importer')");
    }

    /** True when this caller got a token, and so is the one that has to die. */
    public function take(): bool
    {
        return false !== $this->connection->fetchOne(
            "DELETE FROM chaos WHERE id = (SELECT id FROM chaos WHERE action = 'kill_importer' ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED) RETURNING id",
        );
    }
}
