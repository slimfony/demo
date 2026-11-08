<?php

declare(strict_types=1);

namespace Demo\Tests\Support;

use Slimfony\Core\Health\ReadinessCheck;

/** Test double: a dependency that is either healthy or down. */
final class FakeCheck implements ReadinessCheck
{
    public function __construct(
        private readonly string $name,
        private readonly bool $healthy,
    ) {}

    #[\Override]
    public function name(): string
    {
        return $this->name;
    }

    #[\Override]
    public function check(): void
    {
        $this->healthy || throw new \RuntimeException($this->name . ' is down');
    }
}
