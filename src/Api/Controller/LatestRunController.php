<?php

declare(strict_types=1);

namespace Demo\Api\Controller;

use Demo\Imports\RunRepository;
use Doctrine\DBAL\Connection;
use Slimfony\Core\Database\Retry;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The latest run and where every ZIP of it stands, straight from the database: what a page that was just opened (or reloaded)
 * needs to follow a run that is going on.
 */
final class LatestRunController
{
    public function __construct(
        private readonly RunRepository $runs,
        private readonly Retry $retry,
    ) {}

    public function __invoke(Request $request): Response
    {
        $latest = $this->retry->read(fn(Connection $connection): ?array => $this->runs->latest($connection));

        return new JsonResponse(
            ['run' => $latest['run'] ?? null, 'items' => $latest['items'] ?? [], 'at' => time()],
            headers: ['Cache-Control' => 'no-store'],
        );
    }
}
