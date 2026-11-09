<?php

declare(strict_types=1);

namespace Demo\Tests\Generator;

use Demo\Camt\StatementGenerator;
use Demo\Generator\RunGenerator;
use Demo\Imports\ImportMode;
use Demo\Imports\LockOrder;
use Demo\Imports\RunRepository;
use Demo\Infrastructure\Idp\HttpTokenSource;
use Demo\Tests\Support\PostgresTestCase;

/** The run of the page's import against a real PostgreSQL: asked for, claimed, uploaded, followed. */
final class RunGeneratorTest extends PostgresTestCase
{
    private RunRepository $runs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runs = new RunRepository();
    }

    private function ask(int $zips = 40, string $id = '11111111-1111-4111-8111-111111111111'): string
    {
        self::assertTrue($this->runs->create($this->connection, $id, $zips, 0.03, ImportMode::Partial, 60, LockOrder::Sorted));

        return $id;
    }

    /**
     * @param list<array{string, array<string, string>}> $seen every upload the generator made: url and headers
     * @param \Closure(int): int                          $status the answer of the API for the n-th upload
     */
    private function generator(array &$seen, \Closure $status): RunGenerator
    {
        $n = 0;

        return new RunGenerator(
            $this->connection,
            $this->runs,
            new StatementGenerator(),
            new HttpTokenSource('https://idp.test', static fn(string $url): string => '{"token":"t"}'),
            'http://api.test',
            post: static function (string $url, string $body, array $headers) use (&$seen, &$n, $status): array {
                $seen[] = [$url, $headers];
                $code = $status($n);
                $id = \sprintf('00000000-0000-4000-8000-%012d', $n++);

                return [$code, 202 === $code ? '{"id":"' . $id . '"}' : '{}'];
            },
        );
    }

    public function testARunIsUploadedOneInTwentyAnonymouslyAndTheRestWithAToken(): void
    {
        $id = $this->ask(40);
        $seen = [];

        self::assertTrue($this->generator($seen, static fn(int $n): int => 202)->serveOne());

        self::assertCount(40, $seen);
        self::assertStringEndsWith('/public/drop', $seen[0][0], 'ZIP 0 goes through the sandbox');
        self::assertArrayNotHasKey('Authorization', $seen[0][1]);
        self::assertStringContainsString('/api/imports?mode=partial&lock_order=sorted', $seen[1][0]);
        self::assertSame('Bearer t', $seen[1][1]['Authorization']);
        self::assertStringEndsWith('/public/drop', $seen[20][0]);

        $latest = $this->runs->latest($this->connection);
        self::assertNotNull($latest);
        self::assertSame($id, $latest['run']['id']);
        self::assertSame('uploaded', $latest['run']['status']);
        self::assertCount(40, $latest['items']);
        self::assertSame(['public', 'jwt'], [$latest['items'][0]['via'], $latest['items'][1]['via']]);
    }

    public function testWhatTheApiRefusedIsWrittenDownWithoutABatch(): void
    {
        $this->ask(3);
        $seen = [];

        $this->generator($seen, static fn(int $n): int => 1 === $n ? 401 : 202)->serveOne();

        $latest = $this->runs->latest($this->connection);
        self::assertNotNull($latest);
        self::assertSame([202, 401, 202], array_column($latest['items'], 'http_status'));
        self::assertNull($latest['items'][1]['id']);
    }

    public function testOneRunAtATimeAndNothingToDoMeansNothingHappens(): void
    {
        $seen = [];
        self::assertFalse($this->generator($seen, static fn(int $n): int => 202)->serveOne(), 'no run is waiting');

        $this->ask(5, '11111111-1111-4111-8111-111111111111');
        self::assertFalse($this->runs->create($this->connection, '22222222-2222-4222-8222-222222222222', 5, 0.0, ImportMode::Strict, 60, LockOrder::Appearance), 'the first run is still going');

        $this->generator($seen, static fn(int $n): int => 202)->serveOne();
        self::assertTrue($this->runs->create($this->connection, '22222222-2222-4222-8222-222222222222', 5, 0.0, ImportMode::Strict, 60, LockOrder::Appearance), 'a finished run does not block');
    }

    public function testTheLatestRunShowsWhereEachBatchStands(): void
    {
        $this->ask(2);
        $seen = [];
        $this->generator($seen, static fn(int $n): int => 202)->serveOne();
        $this->connection->executeStatement(
            "INSERT INTO batches (id, sha256, mode, source, size_bytes, entries, payload, status, statements_ok) VALUES ('00000000-0000-4000-8000-000000000001', 'x', 'partial', 'jwt', 1, 1, '\\x00', 'done', 7)",
        );

        $latest = $this->runs->latest($this->connection);

        self::assertNotNull($latest);
        self::assertNull($latest['items'][0]['status'], 'its batch is not in the table (yet): the page shows it as waiting');
        self::assertSame('done', $latest['items'][1]['status']);
        self::assertSame(7, $latest['items'][1]['statements_ok']);
    }
}
