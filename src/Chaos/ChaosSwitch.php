<?php

declare(strict_types=1);

namespace Demo\Chaos;

/** Whether the chaos routes exist at all. Off unless the environment turns it on: nobody should be able to break a real deployment this way. */
final readonly class ChaosSwitch
{
    public function __construct(public bool $enabled) {}
}
