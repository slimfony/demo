<?php

declare(strict_types=1);

namespace Demo\Api\Controller;

use Demo\Imports\BatchRepository;
use Doctrine\DBAL\Connection;
use Slimfony\Core\Database\Retry;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Where every batch uploaded since a given moment stands, straight from the database. The page follows an import
 * with live events for quick feedback, and with this list for the truth: an event can be lost on its way, a row cannot.
 */
final class ImportRunController
{
    private const int MAX = 3000;

    public function __construct(
        private readonly BatchRepository $batches,
        private readonly Retry $retry,
    ) {}

    public function __invoke(Request $request): Response
    {
        $since = max(0, $request->query->getInt('since'));

        return new JsonResponse(
            ['batches' => $this->retry->read(fn(Connection $connection): array => $this->batches->since($connection, $since, self::MAX)), 'at' => time()],
            headers: ['Cache-Control' => 'no-store'],
        );
    }
}
