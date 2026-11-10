<?php

declare(strict_types=1);

namespace Demo\Api;

/**
 * The memory of the server process as the operating system counts it (resident set). FrankenPHP runs all its PHP threads in
 * one process, so this is the memory of the whole web server; `memory_get_usage()` is only the heap of one PHP thread.
 */
final class ProcessMemory
{
    private const int PAGE_BYTES = 4096;

    /** @param string $statm where Linux keeps the numbers of the process (a parameter so a test can point it at a file) */
    public function __construct(private readonly string $statm = '/proc/self/statm') {}

    /** Bytes; without /proc (not Linux) the heap of this thread, the best that is known. */
    public function resident(): int
    {
        $fields = is_readable($this->statm) ? explode(' ', (string) file_get_contents($this->statm)) : [];

        return isset($fields[1]) && ctype_digit($fields[1]) ? (int) $fields[1] * self::PAGE_BYTES : memory_get_usage(true);
    }
}
