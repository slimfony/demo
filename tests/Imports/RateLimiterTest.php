<?php

declare(strict_types=1);

namespace Demo\Tests\Imports;

use Demo\Imports\RateLimiter;
use Demo\Tests\Support\ArrayWindowCounter;
use PHPUnit\Framework\TestCase;

final class RateLimiterTest extends TestCase
{
    public function testAClientIsLetThroughUpToTheLimitThenToldHowLongToWait(): void
    {
        $now = 999_999_970; // exactly 10 seconds into a 60 second window
        $limiter = new RateLimiter(new ArrayWindowCounter(), limit: 3, windowSeconds: 60, now: static function () use (&$now): int {
            return $now;
        });

        self::assertNull($limiter->retryAfter('a'));
        self::assertNull($limiter->retryAfter('a'));
        self::assertNull($limiter->retryAfter('a'));
        self::assertSame(50, $limiter->retryAfter('a')); // the next window starts in 50 seconds
        self::assertNull($limiter->retryAfter('b'), 'another client has its own count');

        $now += 60; // a new window
        self::assertNull($limiter->retryAfter('a'));
    }
}
