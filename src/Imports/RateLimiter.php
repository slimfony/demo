<?php

declare(strict_types=1);

namespace Demo\Imports;

/**
 * Lets a client through `$limit` times in each fixed window and says how long to wait after that.
 * A fixed window lets a client send up to twice the limit around the edge of two windows: good enough to stop a flood,
 * which is all the anonymous sandbox needs.
 */
final class RateLimiter
{
    /** @var \Closure(): int */
    private readonly \Closure $now;

    /**
     * @param (\Closure(): int)|null $now seconds; defaults to time()
     */
    public function __construct(
        private readonly WindowCounter $counter,
        private readonly int $limit,
        private readonly int $windowSeconds = 60,
        ?\Closure $now = null,
    ) {
        if ($limit < 1 || $windowSeconds < 1) {
            throw new \InvalidArgumentException('The limit and the window must be positive.');
        }
        $this->now = $now ?? time(...);
    }

    /**
     * @return int|null null when the client may go on, else the seconds until the next window starts
     */
    public function retryAfter(string $client): ?int
    {
        $now = ($this->now)();
        $window = intdiv($now, $this->windowSeconds);
        if ($this->counter->increment(\sprintf('demo.rate.%s.%d', $client, $window), $this->windowSeconds) <= $this->limit) {
            return null;
        }

        return ($window + 1) * $this->windowSeconds - $now;
    }
}
