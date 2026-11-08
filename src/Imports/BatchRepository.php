<?php

declare(strict_types=1);

namespace Demo\Imports;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * The batch row and its outbox row are written in one transaction: either the upload is stored and announced,
 * or nothing happened. The caller owns the transaction (it runs inside `Retry::transactional()`).
 */
final class BatchRepository
{
    public const string ACCEPTED_TOPIC = 'imports.accepted';

    public function insertAccepted(Connection $connection, string $id, Archive $archive, ImportMode $mode, ImportSource $source, ?string $subject, ?string $keyId): void
    {
        // A bytea column takes its value in PostgreSQL's hex format: a plain string parameter that works the same with or
        // without server-side prepared statements (PgBouncer's transaction pooling has none).
        $payload = '\x' . bin2hex($archive->bytes);

        $connection->executeStatement(
            'INSERT INTO batches (id, sha256, mode, source, subject, token_kid, size_bytes, entries, payload) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$id, $archive->sha256, $mode->value, $source->value, $subject, $keyId, \strlen($archive->bytes), $archive->files, $payload],
            [ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::STRING],
        );
        $connection->executeStatement(
            'INSERT INTO outbox (topic, payload) VALUES (?, ?)',
            [self::ACCEPTED_TOPIC, json_encode(['batch_id' => $id, 'source' => $source->value, 'mode' => $mode->value], \JSON_THROW_ON_ERROR)],
        );
    }

    /**
     * @return array<string, mixed>|null the batch without its data
     */
    public function find(Connection $connection, string $id): ?array
    {
        $row = $connection->fetchAssociative(
            'SELECT id, status, source, mode, subject, token_kid, size_bytes, entries AS files, statements_ok, statements_failed, entries_booked, worker, created_at, started_at, finished_at FROM batches WHERE id = ?',
            [$id],
        );

        return false === $row ? null : $row;
    }
}
