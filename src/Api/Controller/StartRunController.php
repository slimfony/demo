<?php

declare(strict_types=1);

namespace Demo\Api\Controller;

use Demo\Imports\ImportMode;
use Demo\Imports\LockOrder;
use Demo\Imports\RunRepository;
use Doctrine\DBAL\Connection;
use Slimfony\Core\Database\Retry;
use Slimfony\Core\Exception\HttpException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Asks for a run of the page's import. The generator service uploads the ZIPs; the answer is the id of the run, and the
 * page follows it with `GET /api/import/runs/latest`. One run at a time: while one is going the answer is 409.
 */
final class StartRunController
{
    public function __construct(
        private readonly RunRepository $runs,
        private readonly Retry $retry,
    ) {}

    public function __invoke(Request $request): Response
    {
        $input = json_decode($request->getContent(), true, 4);
        $input = \is_array($input) ? $input : [];

        $zips = \is_int($input['zips'] ?? null) ? $input['zips'] : 0;
        $faults = \is_int($input['faults'] ?? null) || \is_float($input['faults'] ?? null) ? (float) $input['faults'] : -1.0;
        $accounts = \is_int($input['accounts'] ?? null) ? $input['accounts'] : 0;
        $mode = ImportMode::tryFrom(\is_string($input['mode'] ?? null) ? $input['mode'] : '');
        $order = LockOrder::tryFrom(\is_string($input['lock_order'] ?? null) ? $input['lock_order'] : '');
        if ($zips < 1 || $zips > 1000 || $faults < 0.0 || $faults > 1.0 || $accounts < 1 || $accounts > 500 || null === $mode || null === $order) {
            throw new HttpException(400, 'The run needs zips (1-1000), faults (0-1), mode, accounts and lock_order.');
        }

        $id = \sprintf('%08x-%04x-4%03x-%04x-%012x', random_int(0, 0xFFFFFFFF), random_int(0, 0xFFFF), random_int(0, 0xFFF), random_int(0x8000, 0xBFFF), random_int(0, 0xFFFFFFFFFFFF));
        $created = $this->retry->transactional(fn(Connection $connection): bool => $this->runs->create($connection, $id, $zips, $faults, $mode, $accounts, $order));
        if (!$created) {
            throw new HttpException(409, 'A run is still going.');
        }

        return new JsonResponse(['id' => $id], 202, ['Cache-Control' => 'no-store']);
    }
}
