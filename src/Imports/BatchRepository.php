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
     * Counts for the page: batches by state and by way in, statements and entries, and why statements were turned away.
     *
     * @return array<string, mixed>
     */
    public function summary(Connection $connection): array
    {
        $counts = $connection->fetchAssociative(
            "SELECT count(*) FILTER (WHERE status = 'queued') AS queued, count(*) FILTER (WHERE status = 'running') AS running,
                count(*) FILTER (WHERE status = 'done') AS done, count(*) FILTER (WHERE status = 'failed') AS failed,
                count(*) FILTER (WHERE source = 'jwt') AS with_token, count(*) FILTER (WHERE source = 'public') AS sandbox,
                coalesce(sum(statements_ok), 0) AS statements_booked, coalesce(sum(statements_failed), 0) AS statements_failed,
                coalesce(sum(entries_booked), 0) AS entries_booked FROM batches",
        );
        $batches = [];
        foreach (false === $counts ? [] : $counts as $name => $value) {
            $batches[$name] = is_numeric($value) ? (int) $value : 0;
        }
        $reasons = [];
        foreach ($connection->fetchAllAssociative('SELECT reason, count(*) AS n FROM import_errors GROUP BY reason ORDER BY n DESC') as $row) {
            if (\is_string($row['reason']) && is_numeric($row['n'])) {
                $reasons[$row['reason']] = (int) $row['n'];
            }
        }

        return ['batches' => $batches, 'rejected_by_reason' => $reasons];
    }

    /**
     * Every batch created since the given moment (unix seconds), oldest first: its state and what became of its statements.
     * The page uses it to follow an import (see ImportRunController).
     *
     * @return list<array<string, mixed>>
     */
    public function since(Connection $connection, int $unixSeconds, int $limit): array
    {
        return $connection->fetchAllAssociative(
            'SELECT id, status, source, statements_ok, statements_failed, statements_rolled_back, worker FROM batches WHERE created_at >= ? ORDER BY created_at LIMIT ?',
            [gmdate('Y-m-d H:i:s', $unixSeconds) . '+00', $limit],
        );
    }

    /**
     * @return list<array<string, mixed>> the latest batches, newest first, without data, sender and key id
     */
    public function recent(Connection $connection, int $limit): array
    {
        return $connection->fetchAllAssociative(
            'SELECT id, status, source, mode, entries AS files, statements_ok, statements_failed, entries_booked, worker, created_at, started_at, finished_at FROM batches ORDER BY created_at DESC LIMIT ?',
            [$limit],
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
