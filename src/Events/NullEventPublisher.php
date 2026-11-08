<?php

declare(strict_types=1);

namespace Demo\Events;

final class NullEventPublisher implements EventPublisher
{
    #[\Override]
    public function publish(string $topic, array $data): void {}
}
