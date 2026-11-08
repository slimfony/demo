<?php

declare(strict_types=1);

namespace Demo\Imports;

/** An uploaded ZIP that passed the inspection. */
final readonly class Archive
{
    public function __construct(
        public string $bytes,
        public string $sha256,
        public int $files,
    ) {}
}
