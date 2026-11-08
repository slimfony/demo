<?php

declare(strict_types=1);

namespace Demo\Tests\Support;

use Slimfony\Core\Observability\Storage;

/** Test double for the metrics storage; the demo uses APCu. */
final class InMemoryStorage implements Storage
{
    /** @var array<string, int> */
    private array $values = [];

    #[\Override]
    public function add(string $key, int $by): void
    {
        $this->values[$key] = ($this->values[$key] ?? 0) + $by;
    }

    #[\Override]
    public function set(string $key, int $value): void
    {
        $this->values[$key] = $value;
    }

    #[\Override]
    public function all(): array
    {
        return $this->values;
    }
}
