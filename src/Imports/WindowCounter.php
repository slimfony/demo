<?php

declare(strict_types=1);

namespace Demo\Imports;

/** Counts events per key in a time window that the counter forgets by itself. */
interface WindowCounter
{
    /**
     * Adds one to the key's count and returns the new count. The count disappears `$ttlSeconds` after the first event.
     */
    public function increment(string $key, int $ttlSeconds): int;
}
