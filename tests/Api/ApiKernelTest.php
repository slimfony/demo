<?php

declare(strict_types=1);

namespace Demo\Tests\Api;

use Demo\Tests\Support\InMemoryStorage;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Slimfony\Core\Database\ConnectionLifecycle;
use Slimfony\Core\Database\Retry;
use Slimfony\Core\Kernel;
use Slimfony\Core\Log\RequestContext;
use Slimfony\Core\Observability\MetricsController;
use Slimfony\Core\Observability\MetricsRegistry;
use Symfony\Component\HttpFoundation\Request;

/** The real routes and service configuration of the api, with a SQLite connection instead of PostgreSQL. */
final class ApiKernelTest extends TestCase
{
    private const string TOKEN = 'a-test-token-of-sufficient-length';

    protected function setUp(): void
    {
        putenv('PROMETHEUS_URL=http://127.0.0.1:1'); // nothing listens there: the checks fail fast
        putenv('NATS_MONITOR_URL=http://127.0.0.1:1');
    }

    protected function tearDown(): void
    {
        putenv('PROMETHEUS_URL');
        putenv('NATS_MONITOR_URL');
    }

    private function kernel(): Kernel
    {
        $metrics = new MetricsRegistry(new InMemoryStorage());
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $context = new RequestContext();

        return new Kernel(
            __DIR__ . '/../../config/api/services.php',
            __DIR__ . '/../../config/api/routes.php',
            synthetic: [
                LoggerInterface::class => new NullLogger(),
                RequestContext::class => $context,
                MetricsRegistry::class => $metrics,
                Connection::class => $connection,
                ConnectionLifecycle::class => new ConnectionLifecycle($connection),
                Retry::class => new Retry($connection),
                MetricsController::class => new MetricsController($metrics, self::TOKEN),
            ],
        );
    }

    public function testLivenessAndReadinessAnswerAndEveryResponseCarriesTheCoreHeaders(): void
    {
        $kernel = $this->kernel();

        $live = $kernel->handle(Request::create('/healthz'));
        self::assertSame(200, $live->getStatusCode());
        self::assertSame('nosniff', $live->headers->get('X-Content-Type-Options'));
        self::assertNotNull($live->headers->get('X-Request-Id'));

        self::assertSame(200, $kernel->handle(Request::create('/readyz'))->getStatusCode());
    }

    public function testMetricsAreProtectedAndCountTheRequestsByRouteName(): void
    {
        $kernel = $this->kernel();
        $kernel->handle(Request::create('/healthz'));

        self::assertSame(401, $kernel->handle(Request::create('/metrics'))->getStatusCode());

        $metrics = $kernel->handle(Request::create('/metrics', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . self::TOKEN]));
        self::assertSame(200, $metrics->getStatusCode());
        self::assertStringContainsString('http_requests_total{method="GET",route="healthz",status="200"} 1', (string) $metrics->getContent());
    }

    public function testTheStatusPageListsEveryDependencyAndFlagsTheOnesThatAreDown(): void
    {
        $response = $this->kernel()->handle(Request::create('/api/status'));

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true, 8, \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        $services = array_column(\is_array($body['services'] ?? null) ? $body['services'] : [], 'ok', 'name');
        self::assertSame(['api' => true, 'database' => true, 'nats' => false, 'prometheus' => false], $services);
    }

    public function testUnknownPathsAreProblemJson(): void
    {
        $response = $this->kernel()->handle(Request::create('/nope'));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
    }
}
