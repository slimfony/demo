<?php

declare(strict_types=1);

namespace Demo\Tests\Generator;

use Demo\Generator\LoadRunner;
use Demo\Imports\LoadRunRepository;
use Demo\Tests\Support\PostgresTestCase;

/** The run of the page's "Try it" against a real PostgreSQL: asked for, sent, measured, followed. */
final class LoadRunnerTest extends PostgresTestCase
{
    private LoadRunRepository $runs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runs = new LoadRunRepository();
    }

    public function testEveryRequestIsTimedAndTheDistributionIsWrittenDown(): void
    {
        self::assertTrue($this->runs->create($this->connection, '11111111-1111-4111-8111-111111111111', 200, '/healthz'));
        $urls = [];
        $runner = new LoadRunner($this->connection, $this->runs, 'http://api.test', send: static function (string $url, int $total, \Closure $record) use (&$urls): void {
            $urls[] = $url;
            for ($i = 1; $i <= $total; ++$i) {
                $record($i / 1000, 0 !== $i % 50); // 1 ms, 2 ms, ... 200 ms; every 50th request fails
            }
        });

        self::assertTrue($runner->serveOne());

        self::assertSame(['http://api.test/healthz'], $urls);
        $run = $this->runs->latest($this->connection);
        self::assertNotNull($run);
        self::assertSame('done', $run['status']);
        self::assertSame(200, $run['sent']);
        self::assertSame(4, $run['errors']);
        self::assertEqualsWithDelta(101.0, $run['p50_ms'], 0.001, 'the 101st of 200 sorted latencies');
        self::assertEqualsWithDelta(191.0, $run['p95_ms'], 0.001);
        self::assertEqualsWithDelta(199.0, $run['p99_ms'], 0.001);
    }

    public function testOneRunAtATimeAndNothingToDoMeansNothingHappens(): void
    {
        $runner = new LoadRunner($this->connection, $this->runs, 'http://api.test', send: static function (string $url, int $total, \Closure $record): void {
            $record(0.001, true);
        });
        self::assertFalse($runner->serveOne(), 'no run is waiting');

        self::assertTrue($this->runs->create($this->connection, '11111111-1111-4111-8111-111111111111', 1, '/api/runtime'));
        self::assertFalse($this->runs->create($this->connection, '22222222-2222-4222-8222-222222222222', 1, '/healthz'), 'the first run is still going');

        $runner->serveOne();
        self::assertTrue($this->runs->create($this->connection, '22222222-2222-4222-8222-222222222222', 1, '/healthz'), 'a finished run does not block');
    }
}
