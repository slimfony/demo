<?php

declare(strict_types=1);

namespace Demo\Tests\Relay;

use Demo\Relay\MetricsEndpoint;
use Demo\Tests\Support\InMemoryStorage;
use PHPUnit\Framework\TestCase;
use Slimfony\Core\Observability\MetricsController;
use Slimfony\Core\Observability\MetricsRegistry;

final class MetricsEndpointTest extends TestCase
{
    private const string TOKEN = 'a-test-token-of-sufficient-length';

    private MetricsEndpoint $endpoint;

    protected function setUp(): void
    {
        $metrics = new MetricsRegistry(new InMemoryStorage());
        $metrics->counter('outbox_published_total', 'Messages published.')->inc(by: 7);
        $this->endpoint = new MetricsEndpoint('tcp://127.0.0.1:0', new MetricsController($metrics, self::TOKEN));
    }

    /** Sends a raw request to the endpoint and returns what it answers. The endpoint is polled the way the relay does. */
    private function ask(string $request): string
    {
        $client = stream_socket_client('tcp://' . $this->endpoint->address(), $code, $message, 2);
        self::assertIsResource($client);
        fwrite($client, $request);
        $this->endpoint->serve();

        return (string) stream_get_contents($client);
    }

    public function testPrometheusReadsTheMetricsWithTheToken(): void
    {
        $answer = $this->ask("GET /metrics HTTP/1.1\r\nHost: relay\r\nAuthorization: Bearer " . self::TOKEN . "\r\n\r\n");

        self::assertStringStartsWith('HTTP/1.1 200 OK', $answer);
        self::assertStringContainsString('outbox_published_total 7', $answer);
    }

    public function testNobodyReadsThemWithoutTheToken(): void
    {
        self::assertStringStartsWith('HTTP/1.1 401', $this->ask("GET /metrics HTTP/1.1\r\nHost: relay\r\n\r\n"));
        self::assertStringStartsWith('HTTP/1.1 401', $this->ask("GET /metrics HTTP/1.1\r\nAuthorization: Bearer wrong-token-of-the-same-size!!\r\n\r\n"));
    }

    public function testOnlyTheMetricsPathExistsAndGarbageIsRefused(): void
    {
        self::assertStringStartsWith('HTTP/1.1 404', $this->ask("GET /admin HTTP/1.1\r\nAuthorization: Bearer " . self::TOKEN . "\r\n\r\n"));
        self::assertStringStartsWith('HTTP/1.1 404', $this->ask("POST /metrics HTTP/1.1\r\n\r\n"));
        self::assertStringStartsWith('HTTP/1.1 400', $this->ask("\x00\x01 not http\r\n\r\n"));
    }

    public function testServeReturnsImmediatelyWhenNobodyIsWaiting(): void
    {
        $this->endpoint->serve(); // would hang if it waited for a client

        $this->addToAssertionCount(1);
    }
}
