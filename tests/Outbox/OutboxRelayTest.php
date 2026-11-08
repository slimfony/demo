<?php

declare(strict_types=1);

namespace Demo\Tests\Outbox;

use Demo\Outbox\OutboxRelay;
use Demo\Tests\Support\PostgresTestCase;
use Demo\Tests\Support\RecordingPublisher;
use Slimfony\Core\Database\Retry;

final class OutboxRelayTest extends PostgresTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['first', 'second', 'third'] as $name) {
            $this->connection->executeStatement('INSERT INTO outbox (topic, payload) VALUES (?, ?::jsonb)', ['imports.accepted', json_encode(['name' => $name], \JSON_THROW_ON_ERROR)]);
        }
    }

    public function testEveryRowIsPublishedOnceInOrderWithItsIdAndThenMarked(): void
    {
        $publisher = new RecordingPublisher();
        $relay = new OutboxRelay(new Retry($this->connection), $publisher);

        self::assertSame(3, $relay->relayOnce());
        self::assertSame(['first', 'second', 'third'], array_map(static function (array $message): string {
            $body = json_decode($message['body'], true);

            return \is_array($body) && \is_string($body['name'] ?? null) ? $body['name'] : '';
        }, $publisher->messages));
        self::assertSame(['imports.accepted'], array_unique(array_column($publisher->messages, 'subject')));
        self::assertCount(3, array_unique(array_column($publisher->messages, 'id'))); // distinct ids: the broker can drop repeats
        self::assertSame(0, $relay->pending());
        self::assertSame(0, $relay->relayOnce()); // nothing is sent twice
    }

    public function testAFailureHalfWayLeavesEveryRowUnpublishedSoTheBatchIsSentAgain(): void
    {
        $failing = new RecordingPublisher(failOnCall: 2);
        $relay = new OutboxRelay(new Retry($this->connection), $failing);

        try {
            $relay->relayOnce();
            self::fail('The broker failure should have reached the caller.');
        } catch (\RuntimeException) {
        }
        self::assertSame(3, $relay->pending());

        $healthy = new RecordingPublisher();
        self::assertSame(3, new OutboxRelay(new Retry($this->connection), $healthy)->relayOnce());
        self::assertSame($failing->messages[0]['id'], $healthy->messages[0]['id']); // the repeat carries the same id, so the broker can drop it
    }

    public function testTwoRelaysAtTheSameTimeDoNotPublishTheSameRow(): void
    {
        $other = new OutboxRelay(new Retry($this->newConnection()), $second = new RecordingPublisher());
        $first = new RecordingPublisher(whilePublishing: static fn(): int => $other->relayOnce());

        self::assertSame(3, new OutboxRelay(new Retry($this->connection), $first)->relayOnce());
        self::assertSame([0], $first->nested); // while the first relay holds the rows, the second finds nothing to take
        self::assertSame([], $second->messages);
    }
}
