<?php

declare(strict_types=1);

namespace Demo\Infrastructure\Nats;

use Basis\Nats\Client;
use Basis\Nats\Message\Payload;
use Basis\Nats\Stream\StorageBackend;
use Basis\Nats\Stream\Stream;
use Demo\Outbox\MessagePublisher;

/** Publishes to a JetStream stream and waits for the stream's acknowledgement. The stream is created when it is missing. */
final class NatsMessagePublisher implements MessagePublisher
{
    private ?Stream $stream = null;

    /**
     * @param list<string> $subjects the subjects the stream stores
     */
    public function __construct(
        private readonly Client $client,
        private readonly string $streamName,
        private readonly array $subjects,
        private readonly int $maxAgeSeconds = 3600,
    ) {}

    #[\Override]
    public function publish(string $subject, string $body, string $messageId): void
    {
        $ack = $this->stream()->publish($subject, new Payload($body, ['Nats-Msg-Id' => $messageId]));

        if (!$ack instanceof Payload || null !== $ack->getValue('error') || null === $ack->getValue('seq')) {
            throw new \RuntimeException('The broker did not confirm the message.');
        }
    }

    private function stream(): Stream
    {
        if (null === $this->stream) {
            $stream = $this->client->getApi()->getStream($this->streamName);
            $stream->getConfiguration()
                ->setSubjects($this->subjects)
                ->setStorageBackend(StorageBackend::FILE)
                ->setMaxAge($this->maxAgeSeconds * 1_000_000_000);
            $stream->createIfNotExists();
            $this->stream = $stream;
        }

        return $this->stream;
    }
}
