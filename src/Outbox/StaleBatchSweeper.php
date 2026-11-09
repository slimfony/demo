<?php

declare(strict_types=1);

namespace Demo\Outbox;

use Demo\Imports\BatchRepository;
use Doctrine\DBAL\Connection;
use Slimfony\Core\Database\Retry;

/**
 * Makes sure no batch is forgotten. A batch that has waited (or been "running") for too long gets a new outbox row, so
 * the relay announces it again. Whatever went wrong on the way (the broker dropped the message after too many failed
 * deliveries, an importer died with it, the stream was lost), the database still knows the batch is not finished.
 *
 * Announcing a batch twice is harmless: an importer that finds it taken or finished only acknowledges the message.
 * The delay must be longer than the importers' own timeouts (60 seconds for a delivery, 90 for an abandoned batch),
 * or the sweeper would announce batches that are simply being worked on.
 */
final class StaleBatchSweeper
{
    public function __construct(
        private readonly Retry $retry,
        private readonly int $afterSeconds = 120,
    ) {
        if ($afterSeconds < 1) {
            throw new \InvalidArgumentException('The delay must be at least one second.');
        }
    }

    /**
     * @return int the number of batches announced again
     */
    public function sweep(): int
    {
        return $this->retry->transactional(fn(Connection $connection): int => (int) $connection->executeStatement(
            'WITH stale AS (
                UPDATE batches SET reannounced_at = now()
                WHERE status IN (\'queued\', \'running\') AND COALESCE(reannounced_at, started_at, created_at) < now() - make_interval(secs => ?)
                RETURNING id, source, mode
             )
             INSERT INTO outbox (topic, payload)
             SELECT ?, jsonb_build_object(\'batch_id\', id, \'source\', source, \'mode\', mode) FROM stale',
            [$this->afterSeconds, BatchRepository::ACCEPTED_TOPIC],
        ), true); // idempotent: running it twice only announces a batch once more
    }
}
