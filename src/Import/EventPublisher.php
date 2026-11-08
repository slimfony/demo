<?php

declare(strict_types=1);

namespace Demo\Import;

/** Tells whoever is watching (the page) what the importers are doing. Losing an event must never stop an import. */
interface EventPublisher
{
    /**
     * @param array<string, scalar|null> $data
     */
    public function publish(string $topic, array $data): void;
}
