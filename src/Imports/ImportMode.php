<?php

declare(strict_types=1);

namespace Demo\Imports;

/** What a bad statement does to its ZIP: `partial` keeps the good ones, `strict` rolls the whole ZIP back. */
enum ImportMode: string
{
    case Partial = 'partial';
    case Strict = 'strict';
}
