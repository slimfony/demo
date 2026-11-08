<?php

declare(strict_types=1);

namespace Demo\Import;

/** What happened to one batch. */
final readonly class ImportResult
{
    public const string DONE = 'done';
    public const string FAILED = 'failed';

    /**
     * @param int $rolledBack statements that were fine but were rolled back with their ZIP (strict mode only)
     */
    public function __construct(
        public string $batchId,
        public string $mode,
        public string $source,
        public string $status,
        public int $statementsOk,
        public int $statementsFailed,
        public int $rolledBack,
        public int $entries,
        public float $seconds,
    ) {}
}
