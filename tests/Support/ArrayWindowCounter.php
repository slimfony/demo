<?php

declare(strict_types=1);

namespace Demo\Tests\Support;

use Demo\Imports\WindowCounter;

/** Test double: counts in an array, never forgets (the test's clock moves the window instead). */
final class ArrayWindowCounter implements WindowCounter
{
    /** @var array<string, int> */
    private array $counts = [];

    #[\Override]
    public function increment(string $key, int $ttlSeconds): int
    {
        return $this->counts[$key] = ($this->counts[$key] ?? 0) + 1;
    }
}
