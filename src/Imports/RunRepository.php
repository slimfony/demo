<?php

declare(strict_types=1);

namespace Demo\Imports;

use Doctrine\DBAL\Connection;

/**
 * The runs of the page's import: one is asked for (`create`), the generator takes it (`claim`), uploads its ZIPs and writes
 * down what the API answered for each (`recordItem`), and the page follows it (`latest`).
 */
final class RunRepository
{
    /** A run nobody has finished after this long does not block a new one (the generator may have died with it). */
    private const int STALE_SECONDS = 900;

    /**
     * @return bool false when another run is still going: one at a time, so every visitor sees the same run
     */
    public function create(Connection $connection, string $id, int $zips, float $faultRate, ImportMode $mode, int $accounts, LockOrder $lockOrder): bool
    {
        $busy = $connection->fetchOne(
            "SELECT 1 FROM import_runs WHERE status <> 'uploaded' AND created_at > now() - make_interval(secs => ?)",
            [self::STALE_SECONDS],
        );
        if (false !== $busy) {
            return false;
        }
        $connection->executeStatement(
            'INSERT INTO import_runs (id, zips, fault_rate, mode, accounts, lock_order) VALUES (?, ?, ?, ?, ?, ?)',
            [$id, $zips, $faultRate, $mode->value, $accounts, $lockOrder->value],
        );

        return true;
    }

    /**
     * @return array{id: string, zips: int, fault_rate: float, mode: ImportMode, accounts: int, lock_order: LockOrder}|null the oldest waiting run, now claimed
     */
    public function claim(Connection $connection): ?array
    {
        $row = $connection->fetchAssociative(
            "UPDATE import_runs SET status = 'uploading'
             WHERE id = (SELECT id FROM import_runs WHERE status = 'queued' ORDER BY created_at LIMIT 1 FOR UPDATE SKIP LOCKED)
             RETURNING id, zips, fault_rate, mode, accounts, lock_order",
        );
        if (false === $row) {
            return null;
        }
        $mode = \is_string($row['mode'] ?? null) ? ImportMode::tryFrom($row['mode']) : null;
        $order = \is_string($row['lock_order'] ?? null) ? LockOrder::tryFrom($row['lock_order']) : null;
        if (!\is_string($row['id'] ?? null) || !is_numeric($row['zips'] ?? null) || !is_numeric($row['fault_rate'] ?? null) || !is_numeric($row['accounts'] ?? null) || null === $mode || null === $order) {
            throw new \UnexpectedValueException('A run row does not have the expected column types.');
        }

        return ['id' => $row['id'], 'zips' => (int) $row['zips'], 'fault_rate' => (float) $row['fault_rate'], 'mode' => $mode, 'accounts' => (int) $row['accounts'], 'lock_order' => $order];
    }

    public function recordItem(Connection $connection, string $runId, int $index, ?string $batchId, ImportSource $via, int $httpStatus): void
    {
        $connection->executeStatement(
            'INSERT INTO import_run_items (run_id, idx, batch_id, via, http_status) VALUES (?, ?, ?, ?, ?)',
            [$runId, $index, $batchId, $via->value, $httpStatus],
        );
    }

    public function finishUploading(Connection $connection, string $runId): void
    {
        $connection->executeStatement("UPDATE import_runs SET status = 'uploaded', uploaded_at = now() WHERE id = ?", [$runId]);
    }

    /**
     * The latest run with, for every ZIP of it, the answer of the API and where its batch stands now.
     *
     * @return array{run: array<string, mixed>, items: list<array<string, mixed>>}|null
     */
    public function latest(Connection $connection): ?array
    {
        $run = $connection->fetchAssociative(
            'SELECT id, zips, mode, lock_order, status, extract(epoch FROM created_at)::int AS created_at FROM import_runs ORDER BY created_at DESC LIMIT 1',
        );
        if (false === $run) {
            return null;
        }
        $items = $connection->fetchAllAssociative(
            'SELECT i.idx, i.batch_id AS id, i.via, i.http_status, b.status, b.worker, b.statements_ok, b.statements_failed, b.statements_rolled_back
             FROM import_run_items i LEFT JOIN batches b ON b.id = i.batch_id WHERE i.run_id = ? ORDER BY i.idx',
            [$run['id']],
        );

        return ['run' => $run, 'items' => $items];
    }
}
