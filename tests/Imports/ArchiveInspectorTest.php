<?php

declare(strict_types=1);

namespace Demo\Tests\Imports;

use Demo\Imports\ArchiveInspector;
use Demo\Imports\ArchiveLimits;
use Demo\Imports\RejectedUpload;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ArchiveInspectorTest extends TestCase
{
    private const string XML = '<Document><BkToCstmrStmt/></Document>';

    /**
     * @param array<string, string> $files name => content
     */
    public static function zip(array $files): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'test');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    public function testAGoodArchiveIsAcceptedAndIdentifiedByItsHash(): void
    {
        $bytes = self::zip(['a.xml' => self::XML, 'b.xml' => self::XML]);

        $archive = new ArchiveInspector()->inspect($bytes, new ArchiveLimits(10_000, 10, 100_000));

        self::assertSame(2, $archive->files);
        self::assertSame(hash('sha256', $bytes), $archive->sha256);
    }

    /**
     * @return iterable<string, array{string, ArchiveLimits, string}>
     */
    public static function badUploads(): iterable
    {
        $limits = new ArchiveLimits(10_000, 2, 5_000);

        yield 'empty body' => ['', $limits, 'empty'];
        yield 'not a zip' => [str_repeat('x', 100), $limits, 'not_zip'];
        yield 'larger than the limit' => [self::zip(['a.xml' => self::XML]), new ArchiveLimits(10, 2, 5_000), 'too_large'];
        yield 'too many files' => [self::zip(['a.xml' => self::XML, 'b.xml' => self::XML, 'c.xml' => self::XML]), $limits, 'too_many_files'];
        yield 'path traversal' => [self::zip(['../evil.xml' => self::XML]), $limits, 'unsafe_name'];
        yield 'a folder' => [self::zip(['dir/a.xml' => self::XML]), $limits, 'unsafe_name'];
        yield 'not an xml name' => [self::zip(['run.php' => self::XML]), $limits, 'unsafe_name'];
        yield 'unpacks to too much' => [self::zip(['a.xml' => random_bytes(3_000), 'b.xml' => random_bytes(3_000)]), $limits, 'too_much_data'];
        yield 'zip bomb ratio' => [self::zip(['a.xml' => str_repeat('a', 4_000)]), new ArchiveLimits(10_000, 2, 5_000), 'compression_ratio'];
    }

    #[DataProvider('badUploads')]
    public function testABadUploadIsRefusedWithItsReason(string $bytes, ArchiveLimits $limits, string $reason): void
    {
        try {
            new ArchiveInspector()->inspect($bytes, $limits);
            self::fail('The upload should have been refused.');
        } catch (RejectedUpload $e) {
            self::assertSame($reason, $e->reason);
        }
    }
}
