<?php

declare(strict_types=1);

namespace Demo\Infrastructure\Nats;

use Basis\Nats\Client;
use Basis\Nats\Message\Payload;
use Basis\Nats\Stream\Stream;
use Demo\Outbox\MessagePublisher;

/** Publishes to the JetStream stream and waits for the stream's acknowledgement. */
final class NatsMessagePublisher implements MessagePublisher
{
    private ?Stream $stream = null;

    public function __construct(
        private readonly Client $client,
        private readonly StreamProvisioner $streams,
    ) {}

    #[\Override]
    public function publish(string $subject, string $body, string $messageId): void
    {
        $this->stream ??= $this->streams->ensure($this->client);
        $ack = $this->stream->publish($subject, new Payload($body, ['Nats-Msg-Id' => $messageId]));

        if (!$ack instanceof Payload || null !== $ack->getValue('error') || null === $ack->getValue('seq')) {
            throw new \RuntimeException('The broker did not confirm the message.');
        }
    }
}
