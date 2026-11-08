<?php

declare(strict_types=1);

namespace Demo\Import;

use Demo\Camt\StatementRejected;
use Doctrine\DBAL\Connection;

/** The importer's view of the batches table. Each method is one short, self-contained statement. */
final class ImportRepository
{
    public function __construct(private readonly Connection $connection) {}

    /**
     * Takes a queued batch for this worker. Null when it is not queued any more (another worker has it, or this is a repeated delivery).
     *
     * @return array{mode: string, source: string, bytes: string}|null
     */
    public function claim(string $batchId, string $worker): ?array
    {
        $row = $this->connection->fetchAssociative(
            "UPDATE batches SET status = 'running', worker = ?, started_at = now() WHERE id = ? AND status = 'queued' RETURNING mode, source, payload",
            [$worker, $batchId],
        );
        if (false === $row) {
            return null;
        }
        $payload = $row['payload']; // pdo_pgsql hands bytea over as a stream
        $bytes = \is_resource($payload) ? stream_get_contents($payload) : $payload;
        if (!\is_string($row['mode']) || !\is_string($row['source']) || !\is_string($bytes)) {
            throw new \UnexpectedValueException('A batch row does not have the expected column types.');
        }

        return ['mode' => $row['mode'], 'source' => $row['source'], 'bytes' => $bytes];
    }

    public function finish(string $batchId, string $status, int $statementsOk, int $statementsFailed, int $entries): void
    {
        $this->connection->executeStatement(
            'UPDATE batches SET status = ?, statements_ok = ?, statements_failed = ?, entries_booked = ?, finished_at = now() WHERE id = ?',
            [$status, $statementsOk, $statementsFailed, $entries, $batchId],
        );
    }

    /** Written on its own (outside the statement's transaction), so the row stays when the statement is rolled back. */
    public function recordError(string $batchId, StatementRejected $error): void
    {
        $this->connection->executeStatement(
            'INSERT INTO import_errors (batch_id, file, statement_id, reason, detail) VALUES (?, ?, ?, ?, ?)',
            [$batchId, $error->document, $error->statementId, $error->reason, mb_substr($error->getMessage(), 0, 500)],
        );
    }
}
