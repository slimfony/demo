<?php

declare(strict_types=1);

namespace Demo\Import;

/** How many times the broker has delivered a message, read from the reply subject JetStream gives it: `$JS.ACK.<stream>.<consumer>.<delivered>.…`. */
final class DeliveryCount
{
    public static function fromReplySubject(?string $subject): int
    {
        $parts = explode('.', (string) $subject);

        return isset($parts[1], $parts[4]) && ctype_digit($parts[4]) && 'ACK' === $parts[1] ? max(1, (int) $parts[4]) : 1;
    }
}
