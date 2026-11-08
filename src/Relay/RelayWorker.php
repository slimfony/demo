<?php

declare(strict_types=1);

namespace Demo\Relay;

use Demo\Outbox\OutboxRelay;
use Psr\Log\LoggerInterface;
use Slimfony\Core\Observability\Counter;
use Slimfony\Core\Observability\Gauge;

/** One turn of the relay's loop: publish a batch, count it, answer Prometheus if it is asking, rest if there was nothing to do. */
final class RelayWorker
{
    private const int PENDING_REFRESH_SECONDS = 2;

    private float $pendingRefreshedAt = 0.0;

    public function __construct(
        private readonly OutboxRelay $relay,
        private readonly MetricsEndpoint $endpoint,
        private readonly LoggerInterface $logger,
        private readonly Counter $published,
        private readonly Counter $failures,
        private readonly Gauge $pending,
    ) {}

    public function tick(): bool
    {
        $rest = 0;
        try {
            $count = $this->relay->relayOnce();
            if ($count > 0) {
                $this->published->inc(by: $count);
                $this->logger->info('Published outbox messages', ['count' => $count]);
            } else {
                $rest = 200_000; // nothing to do
            }
        } catch (\Throwable $e) {
            $this->failures->inc();
            $this->logger->error('Relaying failed, trying again in a second', ['exception' => $e]);
            $rest = 1_000_000;
        }

        $this->refreshPending();
        $this->endpoint->serve();
        if ($rest > 0) {
            usleep($rest);
            $this->endpoint->serve(); // a scrape that arrived while resting
        }

        return true;
    }

    private function refreshPending(): void
    {
        $now = hrtime(true) / 1e9;
        if ($now - $this->pendingRefreshedAt < self::PENDING_REFRESH_SECONDS) {
            return;
        }
        $this->pendingRefreshedAt = $now;

        try {
            $this->pending->set($this->relay->pending());
        } catch (\Throwable) {
            // the database is unreachable: the failure counter already says so, keep the last value
        }
    }
}
