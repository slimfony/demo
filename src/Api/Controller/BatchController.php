<?php

declare(strict_types=1);

namespace Demo\Api\Controller;

use Demo\Imports\BatchRepository;
use Doctrine\DBAL\Connection;
use Slimfony\Core\Database\Retry;
use Slimfony\Core\Http\Attributes;
use Slimfony\Core\Http\ProblemResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** The state of one upload. Needs the `import:read` scope. */
final class BatchController
{
    public function __construct(
        private readonly BatchRepository $batches,
        private readonly Retry $retry,
    ) {}

    public function __invoke(Request $request): Response
    {
        $id = $request->attributes->getString('id');
        $batch = $this->retry->read(fn(Connection $connection): ?array => $this->batches->find($connection, $id));
        if (null === $batch) {
            $requestId = $request->attributes->get(Attributes::REQUEST_ID);

            return new ProblemResponse(404, \is_string($requestId) ? $requestId : null);
        }

        return new JsonResponse($batch, headers: ['Cache-Control' => 'no-store']);
    }
}
