<?php

declare(strict_types=1);

namespace Demo\Outbox;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Slimfony\Core\Database\Retry;

/**
 * Moves the rows of the outbox table to the message broker. Several relays can run at once: a row that another relay
 * is working on is skipped (`FOR UPDATE SKIP LOCKED`), so nothing is published twice by two relays.
 *
 * Delivery is at least once. The rows are marked as published in the same transaction that published them; if the
 * broker fails half way, the transaction rolls back and the batch is sent again. The row id travels as the message id,
 * so the broker drops what it has already stored.
 */
final class OutboxRelay
{
    public function __construct(
        private readonly Retry $retry,
        private readonly MessagePublisher $publisher,
        private readonly int $batchSize = 100,
    ) {
        if ($batchSize < 1) {
            throw new \InvalidArgumentException('The batch size must be at least 1.');
        }
    }

    /**
     * @return int the number of messages published (0 when there was nothing to do)
     */
    public function relayOnce(): int
    {
        return $this->retry->transactional(function (Connection $connection): int {
            $rows = $connection->fetchAllAssociative(
                'SELECT id, topic, payload::text AS payload FROM outbox WHERE published_at IS NULL ORDER BY id LIMIT ? FOR UPDATE SKIP LOCKED',
                [$this->batchSize],
                [ParameterType::INTEGER],
            );
            if ([] === $rows) {
                return 0;
            }

            $ids = [];
            foreach ($rows as $row) {
                if (!\is_int($row['id']) || !\is_string($row['topic']) || !\is_string($row['payload'])) {
                    throw new \UnexpectedValueException('An outbox row does not have the expected column types.');
                }
                $this->publisher->publish($row['topic'], $row['payload'], (string) $row['id']);
                $ids[] = $row['id'];
            }
            $connection->executeStatement('UPDATE outbox SET published_at = now() WHERE id IN (?)', [$ids], [ArrayParameterType::INTEGER]);

            return \count($ids);
        });
    }
}
