<?php

declare(strict_types=1);

namespace Demo\Tests\Support;

use Demo\Outbox\MessagePublisher;

/** Test double for the broker: records what is published and can fail or call back while publishing. */
final class RecordingPublisher implements MessagePublisher
{
    /** @var list<array{subject: string, body: string, id: string}> */
    public array $messages = [];
    /** @var list<int> */
    public array $nested = [];
    private int $calls = 0;

    /**
     * @param (\Closure(): int)|null $whilePublishing called during the first publish; its result is recorded in `$nested`
     */
    public function __construct(private readonly ?int $failOnCall = null, private readonly ?\Closure $whilePublishing = null) {}

    #[\Override]
    public function publish(string $subject, string $body, string $messageId): void
    {
        if (++$this->calls === $this->failOnCall) {
            throw new \RuntimeException('The broker is down.');
        }
        if (null !== $this->whilePublishing && 1 === $this->calls) {
            $this->nested[] = ($this->whilePublishing)();
        }
        $this->messages[] = ['subject' => $subject, 'body' => $body, 'id' => $messageId];
    }
}
