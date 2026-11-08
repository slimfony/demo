<?php

declare(strict_types=1);

namespace Demo\Tests\Api;

use Demo\Idp\InMemoryKeySet;
use Demo\Idp\SigningKey;
use Demo\Idp\TokenIssuer;
use Demo\Idp\TokenKind;
use Demo\Imports\RateLimiter;
use Demo\Tests\Imports\ArchiveInspectorTest;
use Demo\Tests\Support\ArrayWindowCounter;
use Demo\Tests\Support\InMemoryStorage;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Firebase\JWT\JWK;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Slimfony\Core\Auth\Authenticator;
use Slimfony\Core\Auth\Jwt\JwtAuthenticator;
use Slimfony\Core\Auth\Jwt\JwtConfiguration;
use Slimfony\Core\Auth\Jwt\StaticKeyProvider;
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
        putenv('IDP_URL=https://idp.invalid');
    }

    protected function tearDown(): void
    {
        putenv('PROMETHEUS_URL');
        putenv('NATS_MONITOR_URL');
        putenv('IDP_URL');
    }

    private TokenIssuer $idp;

    private function kernel(): Kernel
    {
        $metrics = new MetricsRegistry(new InMemoryStorage());
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE batches (id TEXT PRIMARY KEY, sha256 TEXT, mode TEXT, status TEXT NOT NULL DEFAULT \'queued\', source TEXT, subject TEXT, token_kid TEXT, size_bytes INTEGER, entries INTEGER, payload BLOB, created_at TEXT DEFAULT CURRENT_TIMESTAMP, worker TEXT, started_at TEXT, finished_at TEXT, statements_ok INTEGER DEFAULT 0, statements_failed INTEGER DEFAULT 0, entries_booked INTEGER DEFAULT 0)');
        $connection->executeStatement('CREATE TABLE import_errors (reason TEXT)');
        $connection->executeStatement('CREATE TABLE outbox (id INTEGER PRIMARY KEY AUTOINCREMENT, topic TEXT, payload TEXT, published_at TEXT)');
        $context = new RequestContext();

        // the identity provider's key is known to the verifier, as the JWKS download would make it
        $this->idp = new TokenIssuer(new InMemoryKeySet(SigningKey::generate()), 'https://idp.test', 'demo-api');
        $authenticator = new JwtAuthenticator(new JwtConfiguration('https://idp.test', 'demo-api', ['EdDSA']), new StaticKeyProvider(JWK::parseKeySet($this->idp->jwks(), 'EdDSA')), cacheSeconds: 0);

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
                Authenticator::class => $authenticator,
                RateLimiter::class => new RateLimiter(new ArrayWindowCounter(), 3), // a small limit, to hit it
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

    private function post(Kernel $kernel, string $uri, string $body, ?TokenKind $kind = null): \Symfony\Component\HttpFoundation\Response
    {
        $server = null === $kind ? [] : ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->idp->issue($kind)];

        return $kernel->handle(Request::create($uri, 'POST', server: $server, content: $body));
    }

    public function testTheTokenRouteRefusesEveryBadTokenForTheRightReason(): void
    {
        $kernel = $this->kernel();
        $zip = ArchiveInspectorTest::zip(['a.xml' => '<x/>']);

        self::assertSame(401, $this->post($kernel, '/api/imports', $zip)->getStatusCode());
        self::assertSame(401, $kernel->handle(Request::create('/api/imports', 'POST', server: ['HTTP_AUTHORIZATION' => 'Bearer not.a.token'], content: $zip))->getStatusCode());
        self::assertSame(401, $this->post($kernel, '/api/imports', $zip, TokenKind::Expired)->getStatusCode());
        self::assertSame(401, $this->post($kernel, '/api/imports', $zip, TokenKind::BadSignature)->getStatusCode());
        self::assertSame(401, $this->post($kernel, '/api/imports', $zip, TokenKind::WrongAudience)->getStatusCode());
        self::assertSame(403, $this->post($kernel, '/api/imports', $zip, TokenKind::MissingScope)->getStatusCode()); // known caller, not allowed
    }

    public function testAValidTokenUploadsAndTheBatchCanBeRead(): void
    {
        $kernel = $this->kernel();

        $accepted = $this->post($kernel, '/api/imports?mode=strict', ArchiveInspectorTest::zip(['a.xml' => '<x/>']), TokenKind::Valid);
        self::assertSame(202, $accepted->getStatusCode());
        $body = json_decode((string) $accepted->getContent(), true, 8, \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        $id = $body['id'] ?? null;
        self::assertIsString($id);

        $batch = $kernel->handle(Request::create('/api/batches/' . $id, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->idp->issue(TokenKind::Valid)]));
        self::assertSame(200, $batch->getStatusCode());
        self::assertStringContainsString('"source":"jwt"', (string) $batch->getContent());
        self::assertStringNotContainsString('payload', (string) $batch->getContent());
    }

    public function testTheSampleZipIsWhatTheSandboxAccepts(): void
    {
        $kernel = $this->kernel();

        $sample = $kernel->handle(Request::create('/api/sample.zip?files=4'));
        self::assertSame(200, $sample->getStatusCode());
        self::assertSame(202, $this->post($kernel, '/public/drop', (string) $sample->getContent())->getStatusCode());
    }

    public function testTheSandboxIsRationedPerClientButATokenIsNot(): void
    {
        $kernel = $this->kernel();
        $zip = ArchiveInspectorTest::zip(['a.xml' => '<x/>']);

        foreach ([202, 202, 202] as $expected) {
            self::assertSame($expected, $this->post($kernel, '/public/drop', $zip)->getStatusCode());
        }
        $limited = $this->post($kernel, '/public/drop', $zip);
        self::assertSame(429, $limited->getStatusCode());
        self::assertNotNull($limited->headers->get('Retry-After'));
        self::assertSame(202, $this->post($kernel, '/api/imports', $zip, TokenKind::Valid)->getStatusCode());
    }

    public function testTheBatchListAndTheSummaryArePublicAndLeakNoSender(): void
    {
        $kernel = $this->kernel();
        $this->post($kernel, '/api/imports', ArchiveInspectorTest::zip(['a.xml' => '<x/>']), TokenKind::Valid);

        $list = $kernel->handle(Request::create('/api/batches'));
        self::assertSame(200, $list->getStatusCode());
        self::assertStringContainsString('"source":"jwt"', (string) $list->getContent());
        self::assertStringNotContainsString('demo-client', (string) $list->getContent());
        self::assertStringNotContainsString('token_kid', (string) $list->getContent());

        $summary = $kernel->handle(Request::create('/api/import/summary'));
        self::assertSame(200, $summary->getStatusCode());
        $body = json_decode((string) $summary->getContent(), true, 8, \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsArray($body['batches']);
        self::assertSame(['queued' => 1, 'with_token' => 1], array_intersect_key($body['batches'], ['queued' => 0, 'with_token' => 0]));
    }

    public function testTheSandboxRouteIsOpenButLooksInsideTheFile(): void
    {
        $kernel = $this->kernel();

        self::assertSame(422, $this->post($kernel, '/public/drop', 'this is not a zip file')->getStatusCode()); // reached the controller without a token
        self::assertSame(202, $this->post($kernel, '/public/drop', ArchiveInspectorTest::zip(['a.xml' => '<x/>']))->getStatusCode());
    }
}
