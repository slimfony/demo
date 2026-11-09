<?php

declare(strict_types=1);

namespace Demo\Chaos;

/** A place in the importer where somebody may have arranged for it to fail, to show that the system copes. */
interface Chaos
{
    /** Called right after an importer has taken a batch. Normally does nothing. */
    public function strike(): void;
}
