<?php

declare(strict_types=1);

namespace Demo\Import;

final class NullEventPublisher implements EventPublisher
{
    #[\Override]
    public function publish(string $topic, array $data): void {}
}
