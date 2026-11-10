<?php

declare(strict_types=1);

namespace Demo\Tests\Api;

use Demo\Api\ProcessMemory;
use PHPUnit\Framework\TestCase;

final class ProcessMemoryTest extends TestCase
{
    public function testTheResidentPagesOfTheProcessAreCountedInBytes(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'statm');
        self::assertIsString($file);
        file_put_contents($file, "123456 5000 1000 10 0 4000 0\n"); // size resident shared ... in pages

        try {
            self::assertSame(5000 * 4096, new ProcessMemory($file)->resident());
        } finally {
            unlink($file);
        }
    }

    public function testWithoutProcTheHeapOfThisThreadIsTheBestThatIsKnown(): void
    {
        self::assertGreaterThan(0, new ProcessMemory('/no/such/file')->resident());
    }
}
