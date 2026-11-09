<?php

declare(strict_types=1);

namespace Demo\Chaos;

final class NoChaos implements Chaos
{
    #[\Override]
    public function strike(): void {}
}
