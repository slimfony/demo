<?php

declare(strict_types=1);

namespace Demo\Imports;

/**
 * Looks at a ZIP before anything is stored: size, number of files, file names, declared unpacked size and
 * compression ratio. The sizes come from the ZIP's own headers, which a hostile file can lie about, so the importer
 * enforces the same limits again while it reads the data.
 */
final class ArchiveInspector
{
    private const string NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}\.xml$/';

    /**
     * @throws RejectedUpload
     */
    public function inspect(string $bytes, ArchiveLimits $limits): Archive
    {
        if ('' === $bytes) {
            throw RejectedUpload::empty();
        }
        if (\strlen($bytes) > $limits->maxBytes) {
            throw RejectedUpload::tooLarge($limits->maxBytes);
        }
        if (!str_starts_with($bytes, "PK\x03\x04")) {
            throw RejectedUpload::notZip();
        }

        $path = tempnam(sys_get_temp_dir(), 'zip');
        if (false === $path) {
            throw new \RuntimeException('No temporary file available.');
        }

        try {
            file_put_contents($path, $bytes);
            $files = $this->check($path, $limits);
        } finally {
            unlink($path);
        }

        return new Archive($bytes, hash('sha256', $bytes), $files);
    }

    private function check(string $path, ArchiveLimits $limits): int
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($path, \ZipArchive::RDONLY)) {
            throw RejectedUpload::notZip();
        }

        try {
            $count = $zip->numFiles;
            if ($count < 1 || $count > $limits->maxFiles) {
                throw RejectedUpload::tooManyFiles($limits->maxFiles);
            }

            $unpacked = 0;
            for ($i = 0; $i < $count; ++$i) {
                $stat = $zip->statIndex($i);
                if (false === $stat || 1 !== preg_match(self::NAME_PATTERN, $stat['name'])) {
                    throw RejectedUpload::unsafeName();
                }
                $unpacked += $stat['size'];
                if ($unpacked > $limits->maxUnpackedBytes) {
                    throw RejectedUpload::tooMuchData($limits->maxUnpackedBytes);
                }
                if ($stat['size'] > ArchiveLimits::MAX_COMPRESSION_RATIO * max(1, $stat['comp_size'])) {
                    throw RejectedUpload::compressionRatio();
                }
            }
        } finally {
            $zip->close();
        }

        return $count;
    }
}
