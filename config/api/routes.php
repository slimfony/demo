<?php

declare(strict_types=1);

use Demo\Api\Controller\BatchController;
use Demo\Api\Controller\DemoTokenController;
use Demo\Api\Controller\RuntimeController;
use Demo\Api\Controller\SampleArchiveController;
use Demo\Api\Controller\StatsController;
use Demo\Api\Controller\StatusController;
use Demo\Api\Controller\UploadController;
use Slimfony\Core\Health\LivenessController;
use Slimfony\Core\Health\ReadinessController;
use Slimfony\Core\Observability\MetricsController;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

return static function (RouteCollection $r): void {
    // Every route needs a verified token unless it says `_public` (deny by default).
    $public = static fn(string $controller, array $more = []): array => ['_controller' => $controller, '_public' => true] + $more;

    $r->add('healthz', new Route('/healthz', $public(LivenessController::class), methods: ['GET']));
    $r->add('readyz', new Route('/readyz', $public(ReadinessController::class), methods: ['GET']));
    $r->add('metrics', new Route('/metrics', $public(MetricsController::class), methods: ['GET'])); // protected by its own bearer token
    $r->add('api_runtime', new Route('/api/runtime', $public(RuntimeController::class), methods: ['GET']));
    $r->add('api_status', new Route('/api/status', $public(StatusController::class), methods: ['GET']));
    $r->add('api_stats', new Route('/api/stats', $public(StatsController::class), methods: ['GET']));
    $r->add('api_sample', new Route('/api/sample.zip', $public(SampleArchiveController::class), methods: ['GET']));
    $r->add('api_demo_token', new Route('/api/demo-token', $public(DemoTokenController::class), methods: ['POST']));

    // Two ways in: with a token (the caller is known) and anonymously (sandbox with tighter limits).
    $r->add('import_upload', new Route('/api/imports', ['_controller' => UploadController::class, '_source' => 'jwt', '_scopes' => ['import:write']], methods: ['POST']));
    $r->add('import_drop', new Route('/public/drop', $public(UploadController::class, ['_source' => 'public']), methods: ['POST']));
    $r->add('import_show', new Route('/api/batches/{id}', ['_controller' => BatchController::class, '_scopes' => ['import:read']], ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'], methods: ['GET']));
};
