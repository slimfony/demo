<?php

declare(strict_types=1);

namespace Demo\Infrastructure;

use Demo\Imports\WindowCounter;

/** The counts live in APCu, which all worker threads of the container share. */
final class ApcuWindowCounter implements WindowCounter
{
    #[\Override]
    public function increment(string $key, int $ttlSeconds): int
    {
        apcu_add($key, 0, $ttlSeconds); // starts the window; does nothing when the key is already counting
        $count = apcu_inc($key);

        return \is_int($count) ? $count : 1; // APCu unavailable or evicted the key: do not block anybody because of it
    }
}
