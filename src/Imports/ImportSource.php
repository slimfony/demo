<?php

declare(strict_types=1);

namespace Demo\Imports;

/** Where an upload came from. The anonymous sandbox gets much tighter limits than an authenticated caller. */
enum ImportSource: string
{
    case Jwt = 'jwt';
    case Public = 'public';

    public function limits(): ArchiveLimits
    {
        return match ($this) {
            self::Jwt => new ArchiveLimits(maxBytes: 1_048_576, maxFiles: 50, maxUnpackedBytes: 10_485_760),
            self::Public => new ArchiveLimits(maxBytes: 131_072, maxFiles: 10, maxUnpackedBytes: 1_048_576),
        };
    }
}
