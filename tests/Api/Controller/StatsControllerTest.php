<?php

declare(strict_types=1);

namespace Demo\Tests\Api\Controller;

use Demo\Api\Controller\StatsController;
use Demo\Tests\Support\FakePrometheus;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class StatsControllerTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\extension_loaded('apcu') || !filter_var(\ini_get('apc.enabled'), \FILTER_VALIDATE_BOOLEAN) || !filter_var(\ini_get('apc.enable_cli'), \FILTER_VALIDATE_BOOLEAN)) {
            self::markTestSkipped('Needs APCu with apc.enable_cli=1 (CI always has it).');
        }
        apcu_delete('demo.stats');
    }

    protected function tearDown(): void
    {
        apcu_delete('demo.stats');
    }

    public function testNumbersComeFromPrometheusInTheUnitsThePageShowsAndAreSharedForASecond(): void
    {
        $prometheus = new FakePrometheus([
            'sum(rate(http_requests_total[10s]))' => 8421.5,
            'histogram_quantile(0.99, sum by (le) (rate(http_request_duration_seconds_bucket[10s])))' => 0.0000234,
            'sum(http_requests_total)' => 120000.0,
            'sum(php_gc_runs_total) or vector(0)' => 42.0,
            'sum(rate(php_gc_collected_total[10s])) or vector(0)' => 310.5,
            'max(php_memory_bytes)' => 33554432.0,
            'sum(frankenphp_busy_workers)' => 5.0,
            'sum(frankenphp_total_workers)' => 16.0,
        ]);
        $controller = new StatsController($prometheus);

        $first = json_decode((string) $controller(Request::create('/api/stats'))->getContent(), true, 8, \JSON_THROW_ON_ERROR);
        $controller(Request::create('/api/stats'));

        self::assertIsArray($first);
        self::assertSame(8421.5, $first['requests_per_second']);
        self::assertSame(23.4, $first['p99_microseconds'], 'seconds are shown as microseconds');
        self::assertEquals(120000, $first['requests_total']); // JSON has one number type
        self::assertEquals(42, $first['gc_runs_total']);
        self::assertSame(310.5, $first['gc_cycles_per_second']);
        self::assertEquals(33554432, $first['memory_bytes']);
        self::assertEquals(5, $first['busy_workers']);
        self::assertEquals(16, $first['total_workers']);
        self::assertSame(8, $prometheus->queries, 'the second call is served from the one-second share, Prometheus is not asked again');
    }

    public function testNoDataYetIsNullNotAnError(): void
    {
        $body = json_decode((string) new StatsController(new FakePrometheus([]))(Request::create('/api/stats'))->getContent(), true, 8, \JSON_THROW_ON_ERROR);

        self::assertIsArray($body);
        self::assertNull($body['requests_per_second']);
        self::assertNull($body['p99_microseconds']);
        self::assertNull($body['busy_workers']);
    }
}
