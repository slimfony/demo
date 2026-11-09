<?php

declare(strict_types=1);

namespace Demo\Api\Controller;

use Demo\Chaos\ChaosSwitch;
use Demo\Chaos\KillTokens;
use Doctrine\DBAL\Connection;
use Slimfony\Core\Http\Attributes;
use Slimfony\Core\Http\ProblemResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Breaks things on purpose, to show that imports still finish. Needs the `chaos:write` scope, and does not exist
 * (404) unless the environment turns it on.
 *
 * - `kill_importer`: the next importer that takes a batch dies in the middle of it.
 * - `cut_connections`: every database connection of the importers, the relay and the pooler's other clients is terminated.
 */
final class ChaosController
{
    public function __construct(
        private readonly ChaosSwitch $switch,
        private readonly KillTokens $tokens,
        private readonly Connection $connection,
    ) {}

    public function __invoke(Request $request): Response
    {
        $id = $request->attributes->get(Attributes::REQUEST_ID);
        $requestId = \is_string($id) ? $id : null;
        if (!$this->switch->enabled) {
            return new ProblemResponse(404, $requestId);
        }

        return match ($request->attributes->getString('_action')) {
            'kill_importer' => $this->killImporter(),
            'cut_connections' => $this->cutConnections(),
            default => new ProblemResponse(400, $requestId, 'Unknown action.'),
        };
    }

    private function killImporter(): Response
    {
        $this->tokens->add();

        return new JsonResponse(['action' => 'kill_importer', 'detail' => 'The next importer that takes a batch will die in the middle of it.']);
    }

    private function cutConnections(): Response
    {
        $cut = $this->connection->fetchOne(
            "SELECT count(pg_terminate_backend(pid)) FROM pg_stat_activity WHERE datname = current_database() AND pid <> pg_backend_pid() AND application_name LIKE 'demo-%'",
        );

        return new JsonResponse(['action' => 'cut_connections', 'terminated' => is_numeric($cut) ? (int) $cut : 0]);
    }
}
