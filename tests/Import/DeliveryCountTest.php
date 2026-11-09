<?php

declare(strict_types=1);

namespace Demo\Tests\Import;

use Demo\Import\DeliveryCount;
use PHPUnit\Framework\TestCase;

final class DeliveryCountTest extends TestCase
{
    public function testTheCountIsReadFromTheReplySubjectOfJetStream(): void
    {
        self::assertSame(1, DeliveryCount::fromReplySubject('$JS.ACK.IMPORTS.importers.1.15.9.1794000000000000000.0'));
        self::assertSame(5, DeliveryCount::fromReplySubject('$JS.ACK.IMPORTS.importers.5.15.9.1794000000000000000.0'));
    }

    public function testAnythingUnexpectedCountsAsTheFirstDelivery(): void
    {
        self::assertSame(1, DeliveryCount::fromReplySubject(null));
        self::assertSame(1, DeliveryCount::fromReplySubject(''));
        self::assertSame(1, DeliveryCount::fromReplySubject('_INBOX.abc.def'));
        self::assertSame(1, DeliveryCount::fromReplySubject('$JS.ACK.IMPORTS.importers.x.1'));
    }
}
