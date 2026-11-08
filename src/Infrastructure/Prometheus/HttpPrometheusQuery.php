<?php

declare(strict_types=1);

namespace Demo\Infrastructure\Prometheus;

final class HttpPrometheusQuery implements PrometheusQuery
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly float $timeoutSeconds = 1.0,
    ) {}

    #[\Override]
    public function value(string $promql): ?float
    {
        $context = stream_context_create(['http' => ['timeout' => $this->timeoutSeconds, 'follow_location' => 0]]);
        $body = @file_get_contents(rtrim($this->baseUrl, '/') . '/api/v1/query?query=' . rawurlencode($promql), false, $context, 0, 65536);
        if (false === $body) {
            return null;
        }

        try {
            $answer = json_decode($body, true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        $data = \is_array($answer) ? ($answer['data'] ?? null) : null;
        $result = \is_array($data) ? ($data['result'] ?? null) : null;
        $first = \is_array($result) ? ($result[0] ?? null) : null;
        $pair = \is_array($first) ? ($first['value'] ?? null) : null;
        $value = \is_array($pair) ? ($pair[1] ?? null) : null;

        return \is_string($value) && is_numeric($value) && is_finite((float) $value) ? (float) $value : null; // "NaN" means no data
    }
}
