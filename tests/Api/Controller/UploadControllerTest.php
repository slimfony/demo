<?php

declare(strict_types=1);

namespace Demo\Tests\Api\Controller;

use Demo\Api\Controller\UploadController;
use Demo\Imports\ArchiveInspector;
use Demo\Imports\BatchRepository;
use Demo\Imports\RateLimiter;
use Demo\Tests\Imports\ArchiveInspectorTest;
use Demo\Tests\Support\ArrayWindowCounter;
use Demo\Tests\Support\InMemoryStorage;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Slimfony\Core\Auth\Principal;
use Slimfony\Core\Database\Retry;
use Slimfony\Core\Http\Attributes;
use Slimfony\Core\Observability\MetricsRegistry;
use Symfony\Component\HttpFoundation\Request;

/** An accepted upload leaves a batch and an outbox row; a refused one leaves nothing. The database is SQLite here, PostgreSQL in the stack. */
final class UploadControllerTest extends TestCase
{
    private Connection $connection;
    private UploadController $controller;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement('CREATE TABLE batches (id TEXT PRIMARY KEY, sha256 TEXT, mode TEXT, status TEXT NOT NULL DEFAULT \'queued\', source TEXT, lock_order TEXT NOT NULL DEFAULT \'sorted\', subject TEXT, token_kid TEXT, size_bytes INTEGER, entries INTEGER, payload BLOB, created_at TEXT DEFAULT CURRENT_TIMESTAMP, worker TEXT, started_at TEXT, finished_at TEXT, statements_ok INTEGER DEFAULT 0, statements_failed INTEGER DEFAULT 0, statements_rolled_back INTEGER DEFAULT 0, entries_booked INTEGER DEFAULT 0)');
        $this->connection->executeStatement('CREATE TABLE outbox (id INTEGER PRIMARY KEY AUTOINCREMENT, topic TEXT, payload TEXT, published_at TEXT)');
        $this->controller = new UploadController(new ArchiveInspector(), new BatchRepository(), new Retry($this->connection), new RateLimiter(new ArrayWindowCounter(), 1000), new MetricsRegistry(new InMemoryStorage()));
    }

    private function upload(string $source, string $body, string $query = ''): Request
    {
        $request = Request::create('/upload' . $query, 'POST', content: $body);
        $request->attributes->set('_source', $source);

        return $request;
    }

    public function testAnAuthenticatedUploadRecordsWhoSentItAndWhichKeyVouchedForIt(): void
    {
        $header = rtrim(strtr(base64_encode('{"alg":"EdDSA","kid":"key-1"}'), '+/', '-_'), '=');
        $request = $this->upload('jwt', ArchiveInspectorTest::zip(['a.xml' => '<x/>']), '?mode=strict');
        $request->headers->set('Authorization', "Bearer $header.payload.signature");
        $request->attributes->set(Attributes::PRINCIPAL, new Principal('demo-client', ['import:write']));

        $response = ($this->controller)($request);

        self::assertSame(202, $response->getStatusCode());
        $row = $this->connection->fetchAssociative('SELECT source, subject, token_kid, mode, status FROM batches');
        self::assertSame(['source' => 'jwt', 'subject' => 'demo-client', 'token_kid' => 'key-1', 'mode' => 'strict', 'status' => 'queued'], $row);
        self::assertSame(1, $this->connection->fetchOne('SELECT COUNT(*) FROM outbox'));
    }

    public function testTheAnonymousSandboxRecordsNoIdentity(): void
    {
        $response = ($this->controller)($this->upload('public', ArchiveInspectorTest::zip(['a.xml' => '<x/>'])));

        self::assertSame(202, $response->getStatusCode());
        self::assertSame(['source' => 'public', 'subject' => null, 'token_kid' => null], $this->connection->fetchAssociative('SELECT source, subject, token_kid FROM batches'));
    }

    public function testTheSandboxLimitsAreTighterThanTheAuthenticatedOnes(): void
    {
        $big = ArchiveInspectorTest::zip(['a.xml' => random_bytes(140_000)]); // fits the authenticated limit, not the sandbox

        self::assertSame(413, ($this->controller)($this->upload('public', $big))->getStatusCode());
        $request = $this->upload('jwt', $big);
        $request->attributes->set(Attributes::PRINCIPAL, new Principal('demo-client'));
        self::assertSame(202, ($this->controller)($request)->getStatusCode());
    }

    public function testARefusedUploadLeavesNothingBehind(): void
    {
        self::assertSame(422, ($this->controller)($this->upload('public', str_repeat('x', 50)))->getStatusCode());
        self::assertSame(400, ($this->controller)($this->upload('public', ArchiveInspectorTest::zip(['a.xml' => '<x/>']), '?mode=sideways'))->getStatusCode());

        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM batches'));
        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM outbox'));
    }
}
