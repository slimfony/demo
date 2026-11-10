<?php

declare(strict_types=1);

namespace Demo\Tests\Api\Controller;

use Demo\Api\Controller\RuntimeController;
use Demo\Api\Controller\StatusController;
use Demo\Tests\Support\FakeCheck;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class StatusAndRuntimeControllerTest extends TestCase
{
    public function testStatusReportsEachDependencyWithItsOwnResult(): void
    {
        $controller = new StatusController([new FakeCheck('database', true), new FakeCheck('nats', false)]);

        $body = json_decode((string) $controller(Request::create('/api/status'))->getContent(), true, 8, \JSON_THROW_ON_ERROR);

        self::assertIsArray($body);
        self::assertIsArray($body['services']);
        self::assertSame(['api' => true, 'database' => true, 'nats' => false], array_column($body['services'], 'ok', 'name'));
    }

    public function testTheVersionIsReadFromTheFileTheBuildWrote(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'version');
        self::assertIsString($file);
        file_put_contents($file, "0.5.1+dev\n");

        try {
            $body = json_decode((string) new RuntimeController(versionFile: $file)(Request::create('/api/runtime'))->getContent(), true, 8, \JSON_THROW_ON_ERROR);
        } finally {
            unlink($file);
        }

        self::assertIsArray($body);
        self::assertSame('0.5.1+dev', $body['app_version']);
    }

    public function testRuntimeFactsAreReadFromTheRealRuntime(): void
    {
        $body = json_decode((string) new RuntimeController()(Request::create('/api/runtime'))->getContent(), true, 8, \JSON_THROW_ON_ERROR);

        self::assertIsArray($body);
        self::assertSame(\PHP_VERSION, $body['php']);
        self::assertSame('v0.5.0', $body['core']);
        self::assertFalse($body['worker_mode'], 'only the worker script (public/index.php) says it runs the worker loop');
        self::assertIsBool($body['jit']);
        self::assertIsInt($body['memory_bytes']);
        self::assertIsInt($body['server_memory_bytes']);
        self::assertGreaterThanOrEqual($body['memory_bytes'], $body['server_memory_bytes'], 'the server process holds at least the heap of one thread');
        self::assertSame('dev', $body['app_version'], 'built without a version');
        self::assertGreaterThan(0, $body['memory_bytes']);
    }
}
