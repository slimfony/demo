<?php

declare(strict_types=1);

namespace Demo\Outbox;

interface MessagePublisher
{
    /**
     * Publishes one message and returns only when the broker has stored it.
     *
     * @param string $messageId the same id for the same message, so the broker can drop a repeated delivery
     *
     * @throws \RuntimeException when the broker did not confirm
     */
    public function publish(string $subject, string $body, string $messageId): void;
}
