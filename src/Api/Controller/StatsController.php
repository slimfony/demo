<?php

declare(strict_types=1);

namespace Demo\Api\Controller;

use Demo\Infrastructure\Prometheus\PrometheusQuery;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Live numbers for the page, taken from Prometheus with fixed queries. The answer is shared for one second,
 * so a hundred open pages cost Prometheus one query set per second, not a hundred.
 */
final class StatsController
{
    private const string REQUESTS_PER_SECOND = 'sum(rate(http_requests_total[10s]))';
    private const string P95_SECONDS = 'histogram_quantile(0.95, sum by (le) (rate(http_request_duration_seconds_bucket[10s])))';
    private const string REQUESTS_TOTAL = 'sum(http_requests_total)';
    private const string CACHE_KEY = 'demo.stats';

    public function __construct(private readonly PrometheusQuery $prometheus) {}

    public function __invoke(Request $request): Response
    {
        $cached = apcu_fetch(self::CACHE_KEY);
        if (\is_array($cached)) {
            return new JsonResponse($cached);
        }

        $p95 = $this->prometheus->value(self::P95_SECONDS);
        $stats = [
            'requests_per_second' => $this->prometheus->value(self::REQUESTS_PER_SECOND),
            'p95_microseconds' => null === $p95 ? null : round($p95 * 1_000_000, 1),
            'requests_total' => $this->prometheus->value(self::REQUESTS_TOTAL),
            'at' => time(),
        ];
        apcu_store(self::CACHE_KEY, $stats, 1);

        return new JsonResponse($stats);
    }
}
