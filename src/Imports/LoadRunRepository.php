<?php

declare(strict_types=1);

namespace Demo\Imports;

use Doctrine\DBAL\Connection;

/**
 * The runs of the page's "Try it": a number of requests to one endpoint. The page asks for a run (`create`), the generator takes
 * it (`claim`), counts as it sends (`progress`) and writes the result (`finish`); the page follows it (`latest`).
 */
final class LoadRunRepository
{
    public const array ENDPOINTS = ['/healthz', '/api/runtime'];

    /** A run nobody has finished after this long does not block a new one (the generator may have died with it). */
    private const int STALE_SECONDS = 300;

    /**
     * @return bool false when another run is still going: one at a time, so every visitor sees the same run
     */
    public function create(Connection $connection, string $id, int $requests, string $endpoint): bool
    {
        $busy = $connection->fetchOne(
            "SELECT 1 FROM load_runs WHERE status <> 'done' AND created_at > now() - make_interval(secs => ?)",
            [self::STALE_SECONDS],
        );
        if (false !== $busy) {
            return false;
        }
        $connection->executeStatement('INSERT INTO load_runs (id, requests, endpoint) VALUES (?, ?, ?)', [$id, $requests, $endpoint]);

        return true;
    }

    /**
     * @return array{id: string, requests: int, endpoint: string}|null the oldest waiting run, now claimed
     */
    public function claim(Connection $connection): ?array
    {
        $row = $connection->fetchAssociative(
            "UPDATE load_runs SET status = 'running'
             WHERE id = (SELECT id FROM load_runs WHERE status = 'queued' ORDER BY created_at LIMIT 1 FOR UPDATE SKIP LOCKED)
             RETURNING id, requests, endpoint",
        );
        if (false === $row) {
            return null;
        }
        if (!\is_string($row['id'] ?? null) || !is_numeric($row['requests'] ?? null) || !\is_string($row['endpoint'] ?? null)) {
            throw new \UnexpectedValueException('A load run row does not have the expected column types.');
        }

        return ['id' => $row['id'], 'requests' => (int) $row['requests'], 'endpoint' => $row['endpoint']];
    }

    public function progress(Connection $connection, string $id, int $sent, int $errors): void
    {
        $connection->executeStatement('UPDATE load_runs SET sent = ?, errors = ? WHERE id = ?', [$sent, $errors, $id]);
    }

    /** Times are milliseconds. */
    public function finish(Connection $connection, string $id, int $sent, int $errors, float $seconds, float $p50, float $p95, float $p99): void
    {
        $connection->executeStatement(
            "UPDATE load_runs SET status = 'done', sent = ?, errors = ?, seconds = ?, p50_ms = ?, p95_ms = ?, p99_ms = ?, finished_at = now() WHERE id = ?",
            [$sent, $errors, $seconds, $p50, $p95, $p99, $id],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function latest(Connection $connection): ?array
    {
        $row = $connection->fetchAssociative(
            'SELECT id, requests, endpoint, status, sent, errors, seconds::float8 AS seconds, p50_ms::float8 AS p50_ms, p95_ms::float8 AS p95_ms, p99_ms::float8 AS p99_ms,
                    extract(epoch FROM created_at)::int AS created_at
             FROM load_runs ORDER BY created_at DESC LIMIT 1',
        );

        return false === $row ? null : $row;
    }
}
