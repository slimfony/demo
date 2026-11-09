<?php

declare(strict_types=1);

namespace Demo\Imports;

/** In which order the importer locks the shared account rows (see Import\StatementWriter). */
enum LockOrder: string
{
    case Sorted = 'sorted';
    case Appearance = 'appearance';
}
