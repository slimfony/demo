<?php

declare(strict_types=1);

namespace Demo\Tests\Support;

use Demo\Infrastructure\Prometheus\PrometheusQuery;

/** Test double: answers from a fixed map of query to value and counts the questions. */
final class FakePrometheus implements PrometheusQuery
{
    public int $queries = 0;

    /**
     * @param array<string, float> $values by PromQL text; a query that is not in the map has no data
     */
    public function __construct(private readonly array $values) {}

    #[\Override]
    public function value(string $promql): ?float
    {
        ++$this->queries;

        return $this->values[$promql] ?? null;
    }
}
