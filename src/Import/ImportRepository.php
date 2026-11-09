<?php

declare(strict_types=1);

namespace Demo\Import;

use Demo\Camt\StatementRejected;
use Doctrine\DBAL\Connection;

/** The importer's view of the batches table. Each method is one short, self-contained statement. */
final class ImportRepository
{
    public function __construct(
        private readonly Connection $connection,
        private readonly int $abandonedAfterSeconds = self::ABANDONED_AFTER_SECONDS,
    ) {}

    /** Seconds after which a batch that is still `running` is taken to be abandoned (the broker redelivers its message after 60). */
    public const int ABANDONED_AFTER_SECONDS = 90;

    /**
     * Takes a batch for this worker: a queued one, or one that a worker started and then abandoned. Null when it is not available
     * (another worker is on it, or it is finished: this is a repeated delivery).
     *
     * @return array{mode: string, source: string, lock_order: string, bytes: string}|null
     */
    public function claim(string $batchId, string $worker): ?array
    {
        $row = $this->connection->fetchAssociative(
            "UPDATE batches SET status = 'running', worker = ?, started_at = now() WHERE id = ? AND (status = 'queued' OR (status = 'running' AND started_at < now() - make_interval(secs => ?))) RETURNING mode, source, lock_order, payload",
            [$worker, $batchId, $this->abandonedAfterSeconds],
        );
        if (false === $row) {
            return null;
        }
        $payload = $row['payload']; // pdo_pgsql hands bytea over as a stream
        $bytes = \is_resource($payload) ? stream_get_contents($payload) : $payload;
        if (!\is_string($row['mode']) || !\is_string($row['source']) || !\is_string($row['lock_order']) || !\is_string($bytes)) {
            throw new \UnexpectedValueException('A batch row does not have the expected column types.');
        }

        return ['mode' => $row['mode'], 'source' => $row['source'], 'lock_order' => $row['lock_order'], 'bytes' => $bytes];
    }

    /**
     * Gives a batch back to the queue after a failure, so that the redelivered message finds it waiting.
     */
    public function release(string $batchId): void
    {
        $this->connection->executeStatement("UPDATE batches SET status = 'queued', worker = NULL, started_at = NULL WHERE id = ? AND status = 'running'", [$batchId]);
    }

    /**
     * The batch could not be imported however often it was tried: it ends as failed, with the reason on record, instead of waiting forever.
     */
    public function giveUp(string $batchId, int $attempts, string $cause): void
    {
        $this->connection->executeStatement(
            "INSERT INTO import_errors (batch_id, file, reason, detail) VALUES (?, '*', ?, ?)",
            [$batchId, StatementRejected::GAVE_UP, mb_substr(\sprintf('The importer gave up after %d attempts (%s).', $attempts, $cause), 0, 500)],
        );
        $this->finish($batchId, 'failed', 0);
    }

    /**
     * The ids of the statements of this batch that are already booked (a repeated run of the batch must not book them twice).
     *
     * @return array<string, true>
     */
    public function bookedStatements(string $batchId): array
    {
        $ids = [];
        foreach ($this->connection->fetchFirstColumn('SELECT statement_id FROM statements WHERE batch_id = ?', [$batchId]) as $id) {
            if (\is_string($id)) {
                $ids[$id] = true;
            }
        }

        return $ids;
    }

    /**
     * Closes the batch. What was booked is counted from the database, which is the truth even when the batch was run more than once.
     */
    public function finish(string $batchId, string $status, int $statementsFailed): void
    {
        $this->connection->executeStatement(
            'UPDATE batches SET status = ?, statements_failed = ?, finished_at = now(),
                statements_ok = (SELECT count(*) FROM statements WHERE batch_id = batches.id),
                statements_rolled_back = (SELECT count(*) FROM import_errors WHERE batch_id = batches.id AND reason = \'rolled_back\'),
                entries_booked = (SELECT count(*) FROM entries WHERE statement_pk IN (SELECT id FROM statements WHERE batch_id = batches.id))
             WHERE id = ?',
            [$status, $statementsFailed, $batchId],
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
