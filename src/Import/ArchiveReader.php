<?php

declare(strict_types=1);

namespace Demo\Import;

use Demo\Imports\ArchiveLimits;
use Demo\Imports\RejectedUpload;

/**
 * Unpacks a ZIP in memory under the same limits the upload was checked against, and counts the bytes it really reads.
 * The sizes in a ZIP's headers can lie; what counts is how much comes out of the decompressor.
 */
final class ArchiveReader
{
    private const string NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}\.xml$/';
    private const int CHUNK = 16_384;

    /**
     * @return array<string, string> file name => content
     *
     * @throws RejectedUpload
     */
    public function read(string $bytes, ArchiveLimits $limits): array
    {
        $path = tempnam(sys_get_temp_dir(), 'import');
        if (false === $path) {
            throw new \RuntimeException('No temporary file available.');
        }

        try {
            file_put_contents($path, $bytes);

            return $this->extract($path, $limits);
        } finally {
            unlink($path);
        }
    }

    /**
     * @return array<string, string>
     */
    private function extract(string $path, ArchiveLimits $limits): array
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($path, \ZipArchive::RDONLY)) {
            throw RejectedUpload::notZip();
        }

        try {
            if ($zip->numFiles < 1 || $zip->numFiles > $limits->maxFiles) {
                throw RejectedUpload::tooManyFiles($limits->maxFiles);
            }

            $files = [];
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $name = (string) $zip->getNameIndex($i);
                if (1 !== preg_match(self::NAME_PATTERN, $name)) {
                    throw RejectedUpload::unsafeName();
                }
                $stream = $zip->getStream($name);
                if (false === $stream) {
                    throw RejectedUpload::notZip();
                }

                $content = '';
                while (!feof($stream)) {
                    $chunk = fread($stream, self::CHUNK);
                    if (false === $chunk) {
                        throw RejectedUpload::notZip();
                    }
                    $total += \strlen($chunk);
                    if ($total > $limits->maxUnpackedBytes) { // counted as it comes out, whatever the header claimed
                        fclose($stream);
                        throw RejectedUpload::tooMuchData($limits->maxUnpackedBytes);
                    }
                    $content .= $chunk;
                }
                fclose($stream);
                $files[$name] = $content;
            }

            return $files;
        } finally {
            $zip->close();
        }
    }
}
