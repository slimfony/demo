<?php

declare(strict_types=1);

namespace Demo\Infrastructure\Mercure;

use Demo\Events\EventPublisher;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Writes every log line to the real logger and also sends the important ones (info and above) to the page's log view.
 * Only a short list of harmless context keys goes along: what is in a log line is not automatically fit for every visitor.
 */
final class TeeLogger extends AbstractLogger
{
    private const array SHOWN = ['batch_id', 'worker', 'mode', 'status', 'booked', 'rejected', 'rolled_back', 'count', 'attempt', 'reason'];
    private const array LEVELS = [LogLevel::INFO, LogLevel::NOTICE, LogLevel::WARNING, LogLevel::ERROR, LogLevel::CRITICAL, LogLevel::ALERT, LogLevel::EMERGENCY];

    public function __construct(
        private readonly LoggerInterface $inner,
        private readonly EventPublisher $events,
        private readonly string $service,
    ) {}

    #[\Override]
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->inner->log($level, $message, $context);

        if (!\is_string($level) || !\in_array($level, self::LEVELS, true)) {
            return;
        }
        $shown = [];
        foreach (self::SHOWN as $key) {
            if (isset($context[$key]) && \is_scalar($context[$key])) {
                $shown[$key] = $context[$key];
            }
        }
        $this->events->publish('log', ['level' => $level, 'service' => $this->service, 'message' => (string) $message] + $shown);
    }
}
