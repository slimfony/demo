<?php

declare(strict_types=1);

namespace Demo\Infrastructure\Prometheus;

/**
 * Asks Prometheus for one number. The only caller is the api, with fixed queries: the browser never sends PromQL.
 */
interface PrometheusQuery
{
    /**
     * @return float|null the first value of an instant query, or null when there is none (no data yet, Prometheus down)
     */
    public function value(string $promql): ?float;
}
