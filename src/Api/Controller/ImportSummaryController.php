<?php

declare(strict_types=1);

namespace Demo\Api\Controller;

use Demo\Imports\BatchRepository;
use Demo\Infrastructure\Prometheus\PrometheusQuery;
use Doctrine\DBAL\Connection;
use Slimfony\Core\Database\Retry;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * What the importers have done so far, for the page: the counts come from the database, the rates and the number of
 * deadlock retries from Prometheus. Shared for a second, like the other live numbers.
 */
final class ImportSummaryController
{
    private const string STATEMENTS_PER_SECOND = 'sum(rate(import_statements_total{result="booked"}[10s]))';
    private const string BATCHES_PER_SECOND = 'sum(rate(import_batches_total[10s]))';
    private const string DEADLOCK_RETRIES = 'sum(db_retries_total{job="importer"})';
    private const string IMPORT_P95_SECONDS = 'histogram_quantile(0.95, sum by (le) (rate(import_batch_seconds_bucket[10s])))';
    private const string CACHE_KEY = 'demo.import.summary';

    public function __construct(
        private readonly BatchRepository $batches,
        private readonly Retry $retry,
        private readonly PrometheusQuery $prometheus,
    ) {}

    public function __invoke(Request $request): Response
    {
        $cached = apcu_fetch(self::CACHE_KEY);
        if (\is_array($cached)) {
            return new JsonResponse($cached, headers: ['Cache-Control' => 'no-store']);
        }

        $p95 = $this->prometheus->value(self::IMPORT_P95_SECONDS);
        $summary = $this->retry->read(fn(Connection $connection): array => $this->batches->summary($connection)) + [
            'import_p95_milliseconds' => null === $p95 ? null : round($p95 * 1000, 1),
            'statements_per_second' => $this->prometheus->value(self::STATEMENTS_PER_SECOND),
            'batches_per_second' => $this->prometheus->value(self::BATCHES_PER_SECOND),
            'deadlock_retries' => $this->prometheus->value(self::DEADLOCK_RETRIES),
            'at' => time(),
        ];
        apcu_store(self::CACHE_KEY, $summary, 1);

        return new JsonResponse($summary, headers: ['Cache-Control' => 'no-store']);
    }
}
