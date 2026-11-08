<?php

declare(strict_types=1);

namespace Demo\Api\Controller;

use Demo\Imports\BatchRepository;
use Doctrine\DBAL\Connection;
use Slimfony\Core\Database\Retry;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** The latest batches, newest first, for the page's batch list. No sender and no key id: the list is public. */
final class BatchListController
{
    private const int MAX = 50;

    public function __construct(
        private readonly BatchRepository $batches,
        private readonly Retry $retry,
    ) {}

    public function __invoke(Request $request): Response
    {
        $limit = max(1, min(self::MAX, $request->query->getInt('limit', 20)));

        return new JsonResponse(
            ['batches' => $this->retry->read(fn(Connection $connection): array => $this->batches->recent($connection, $limit))],
            headers: ['Cache-Control' => 'no-store'],
        );
    }
}
