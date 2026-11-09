<?php

declare(strict_types=1);

namespace Demo\Import;

use Basis\Nats\Consumer\Consumer;
use Basis\Nats\Message\Payload;
use Demo\Events\EventPublisher;
use Psr\Log\LoggerInterface;
use Slimfony\Core\Observability\Counter;
use Slimfony\Core\Observability\Histogram;
use Slimfony\Core\Observability\ProcessMetricsEndpoint;

/**
 * One turn of an importer's loop: ask the stream for a batch, import it, count and announce the result, answer Prometheus.
 * A message is acknowledged only after its batch is imported; if the import throws, the message comes back later.
 */
final class ImporterWorker
{
    public function __construct(
        private readonly Consumer $consumer,
        private readonly BatchImporter $importer,
        private readonly ProcessMetricsEndpoint $endpoint,
        private readonly EventPublisher $events,
        private readonly LoggerInterface $logger,
        private readonly string $worker,
        private readonly Counter $batches,
        private readonly Counter $statements,
        private readonly Counter $rejections,
        private readonly Counter $entries,
        private readonly Counter $failures,
        private readonly Counter $deadLettered,
        private readonly Histogram $duration,
    ) {}

    public function tick(): bool
    {
        try {
            $this->consumer->handle($this->handle(...), null, true);
        } catch (\Throwable $e) {
            $this->failures->inc();
            $this->logger->error('Importing failed, the batch will be delivered again', ['exception' => $e, 'worker' => $this->worker]);
            usleep(500_000);
        }
        $this->endpoint->serve();

        return true;
    }

    /** The broker delivers a message this many times at most (the consumer is set up with it); after the last failed delivery the batch is given up. */
    public const int MAX_DELIVER = 5;

    private function handle(Payload $payload, ?string $replyTo = null): void
    {
        $message = json_decode($payload->body, true);
        $batchId = \is_array($message) && \is_string($message['batch_id'] ?? null) ? $message['batch_id'] : null;
        if (null === $batchId) {
            $this->logger->warning('A message without a batch id was dropped', ['worker' => $this->worker]);

            return;
        }

        $this->events->publish('batch', ['id' => $batchId, 'state' => 'running', 'worker' => $this->worker]);
        try {
            $result = $this->importer->import($batchId, $this->worker);
        } catch (\Throwable $e) {
            $delivered = DeliveryCount::fromReplySubject($replyTo);
            if ($delivered < self::MAX_DELIVER) {
                throw $e; // not acknowledged: the broker delivers it again
            }
            // the last delivery failed too: the batch ends as failed, on record, and the message is acknowledged
            $this->importer->giveUp($batchId, $delivered, $e);
            $this->deadLettered->inc();
            $this->logger->error('A batch was given up after the last delivery', ['batch_id' => $batchId, 'worker' => $this->worker, 'attempt' => $delivered, 'exception' => $e]);
            $this->events->publish('batch', ['id' => $batchId, 'state' => 'failed', 'worker' => $this->worker, 'ok' => 0, 'failed' => 0, 'rolled_back' => 0, 'ms' => 0]);

            return;
        }
        if (null === $result) {
            $this->logger->info('Batch already taken, message acknowledged', ['batch_id' => $batchId, 'worker' => $this->worker]);

            return;
        }

        $this->batches->inc([$result->mode, $result->source, $result->status]);
        $this->statements->inc(['booked'], $result->statementsOk);
        $this->statements->inc(['rejected'], $result->statementsFailed);
        $this->statements->inc(['rolled_back'], $result->rolledBack);
        $this->entries->inc(by: $result->entries);
        foreach ($result->reasons as $reason => $count) {
            $this->rejections->inc([$reason], $count);
        }
        $this->duration->observe($result->seconds);
        $this->logger->info('Batch imported', [
            'batch_id' => $batchId, 'worker' => $this->worker, 'mode' => $result->mode, 'status' => $result->status,
            'booked' => $result->statementsOk, 'rejected' => $result->statementsFailed, 'rolled_back' => $result->rolledBack,
        ]);
        $this->events->publish('batch', [
            'id' => $batchId, 'state' => $result->status, 'worker' => $this->worker, 'mode' => $result->mode, 'source' => $result->source,
            'ok' => $result->statementsOk, 'failed' => $result->statementsFailed, 'rolled_back' => $result->rolledBack, 'ms' => (int) round($result->seconds * 1000),
        ]);
    }
}
