<?php

declare(strict_types=1);

use Demo\Api\Controller\RuntimeController;
use Demo\Api\Controller\StatsController;
use Demo\Api\Controller\StatusController;
use Slimfony\Core\Health\LivenessController;
use Slimfony\Core\Health\ReadinessController;
use Slimfony\Core\Observability\MetricsController;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

return static function (RouteCollection $r): void {
    $r->add('healthz', new Route('/healthz', ['_controller' => LivenessController::class], methods: ['GET']));
    $r->add('readyz', new Route('/readyz', ['_controller' => ReadinessController::class], methods: ['GET']));
    $r->add('metrics', new Route('/metrics', ['_controller' => MetricsController::class], methods: ['GET']));
    $r->add('api_runtime', new Route('/api/runtime', ['_controller' => RuntimeController::class], methods: ['GET']));
    $r->add('api_status', new Route('/api/status', ['_controller' => StatusController::class], methods: ['GET']));
    $r->add('api_stats', new Route('/api/stats', ['_controller' => StatsController::class], methods: ['GET']));
};
