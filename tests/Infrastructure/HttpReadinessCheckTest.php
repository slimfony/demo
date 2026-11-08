<?php

declare(strict_types=1);

namespace Demo\Tests\Infrastructure;

use Demo\Infrastructure\Http\HttpReadinessCheck;
use PHPUnit\Framework\TestCase;

final class HttpReadinessCheckTest extends TestCase
{
    public function testA2xxAnswerIsHealthy(): void
    {
        new HttpReadinessCheck('nats', 'http://nats/healthz', status: static fn(string $url, float $timeout): int => 200)->check();

        $this->addToAssertionCount(1); // no exception is the assertion
    }

    public function testAnythingElseIsNotHealthy(): void
    {
        $check = new HttpReadinessCheck('nats', 'http://nats/healthz', status: static fn(string $url, float $timeout): int => 503);

        $this->expectException(\RuntimeException::class);
        $check->check();
    }

    public function testAnUnreachableServiceIsNotHealthyAndTheNameIsReported(): void
    {
        $check = new HttpReadinessCheck('prometheus', 'http://127.0.0.1:1/-/ready', 0.5);

        self::assertSame('prometheus', $check->name());
        $this->expectException(\RuntimeException::class);
        $check->check();
    }
}
