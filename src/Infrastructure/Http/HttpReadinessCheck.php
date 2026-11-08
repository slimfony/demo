<?php

declare(strict_types=1);

namespace Demo\Infrastructure\Http;

use Slimfony\Core\Health\ReadinessCheck;

/**
 * A dependency is healthy when its HTTP health endpoint answers 2xx within the timeout (NATS monitoring, Prometheus).
 */
final class HttpReadinessCheck implements ReadinessCheck
{
    /** @var \Closure(string, float): int */
    private readonly \Closure $status;

    /**
     * @param (\Closure(string, float): int)|null $status returns the HTTP status of a GET; defaults to a bounded stream request
     */
    public function __construct(
        private readonly string $name,
        private readonly string $url,
        private readonly float $timeoutSeconds = 1.0,
        ?\Closure $status = null,
    ) {
        $this->status = $status ?? self::request(...);
    }

    #[\Override]
    public function name(): string
    {
        return $this->name;
    }

    #[\Override]
    public function check(): void
    {
        $code = ($this->status)($this->url, $this->timeoutSeconds);
        if ($code < 200 || $code > 299) {
            throw new \RuntimeException(\sprintf('%s answered HTTP %d.', $this->name, $code));
        }
    }

    private static function request(string $url, float $timeout): int
    {
        $context = stream_context_create(['http' => ['timeout' => $timeout, 'ignore_errors' => true, 'follow_location' => 0]]);
        $body = @file_get_contents($url, false, $context, 0, 4096);
        $headers = http_get_last_response_headers();
        if (false === $body || null === $headers || !isset($headers[0]) || 1 !== preg_match('#^HTTP/\S+\s+(\d{3})#', $headers[0], $match)) {
            throw new \RuntimeException('No HTTP answer.');
        }

        return (int) $match[1];
    }
}
