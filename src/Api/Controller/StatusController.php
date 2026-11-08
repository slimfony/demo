<?php

declare(strict_types=1);

namespace Demo\Api\Controller;

use Slimfony\Core\Health\ReadinessCheck;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Status of the services the demo depends on, with the time each check took. The api reports itself as up because it answered.
 */
final class StatusController
{
    /**
     * @param list<ReadinessCheck> $checks
     */
    public function __construct(private readonly array $checks) {}

    public function __invoke(Request $request): Response
    {
        $services = [['name' => 'api', 'ok' => true, 'ms' => 0.0]];
        foreach ($this->checks as $check) {
            $start = hrtime(true);
            try {
                $check->check();
                $ok = true;
            } catch (\Throwable) {
                $ok = false;
            }
            $services[] = ['name' => $check->name(), 'ok' => $ok, 'ms' => round((hrtime(true) - $start) / 1_000_000, 1)];
        }

        return new JsonResponse(['services' => $services]);
    }
}
