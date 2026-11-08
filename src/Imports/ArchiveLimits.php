<?php

declare(strict_types=1);

namespace Demo\Imports;

final readonly class ArchiveLimits
{
    public const int MAX_COMPRESSION_RATIO = 100;

    public function __construct(
        public int $maxBytes,
        public int $maxFiles,
        public int $maxUnpackedBytes,
    ) {}
}
