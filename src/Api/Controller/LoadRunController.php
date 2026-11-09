<?php

declare(strict_types=1);

namespace Demo\Api\Controller;

use Demo\Imports\LoadRunRepository;
use Doctrine\DBAL\Connection;
use Slimfony\Core\Database\Retry;
use Slimfony\Core\Exception\HttpException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The page's "Try it": `POST` asks for a run of requests to one endpoint (the generator service sends them), `GET` is the latest
 * run and where it stands. One run at a time: while one is going the answer to a `POST` is 409.
 */
final class LoadRunController
{
    private const int MAX_REQUESTS = 200_000;

    public function __construct(
        private readonly LoadRunRepository $runs,
        private readonly Retry $retry,
    ) {}

    public function __invoke(Request $request): Response
    {
        return $request->isMethod('POST') ? $this->start($request) : $this->latest();
    }

    private function start(Request $request): Response
    {
        $input = json_decode($request->getContent(), true, 4);
        $input = \is_array($input) ? $input : [];
        $requests = \is_int($input['requests'] ?? null) ? $input['requests'] : 0;
        $endpoint = \is_string($input['endpoint'] ?? null) ? $input['endpoint'] : '';
        if ($requests < 1 || $requests > self::MAX_REQUESTS || !\in_array($endpoint, LoadRunRepository::ENDPOINTS, true)) {
            throw new HttpException(400, 'The run needs requests (1-200000) and an endpoint of ' . implode(', ', LoadRunRepository::ENDPOINTS) . '.');
        }

        $id = \sprintf('%08x-%04x-4%03x-%04x-%012x', random_int(0, 0xFFFFFFFF), random_int(0, 0xFFFF), random_int(0, 0xFFF), random_int(0x8000, 0xBFFF), random_int(0, 0xFFFFFFFFFFFF));
        if (!$this->retry->transactional(fn(Connection $connection): bool => $this->runs->create($connection, $id, $requests, $endpoint))) {
            throw new HttpException(409, 'A run is still going.');
        }

        return new JsonResponse(['id' => $id], 202, ['Cache-Control' => 'no-store']);
    }

    private function latest(): Response
    {
        $run = $this->retry->read(fn(Connection $connection): ?array => $this->runs->latest($connection));

        return new JsonResponse(['run' => $run, 'at' => time()], headers: ['Cache-Control' => 'no-store']);
    }
}
