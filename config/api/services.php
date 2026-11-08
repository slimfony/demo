<?php

declare(strict_types=1);

use Demo\Api\Controller\BatchController;
use Demo\Api\Controller\BatchListController;
use Demo\Api\Controller\DemoRotateController;
use Demo\Api\Controller\DemoTokenController;
use Demo\Api\Controller\ImportSummaryController;
use Demo\Api\Controller\RuntimeController;
use Demo\Api\Controller\SampleArchiveController;
use Demo\Api\Controller\StatsController;
use Demo\Api\Controller\StatusController;
use Demo\Api\Controller\UploadController;
use Demo\Camt\StatementGenerator;
use Demo\Imports\ArchiveInspector;
use Demo\Imports\BatchRepository;
use Demo\Imports\RateLimiter;
use Demo\Infrastructure\Http\HttpReadinessCheck;
use Demo\Infrastructure\Idp\HttpTokenSource;
use Demo\Infrastructure\Idp\TokenSource;
use Demo\Infrastructure\Prometheus\HttpPrometheusQuery;
use Demo\Infrastructure\Prometheus\PrometheusQuery;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Slimfony\Core\Auth\Authenticator;
use Slimfony\Core\Auth\AuthMiddleware;
use Slimfony\Core\Database\ConnectionLifecycle;
use Slimfony\Core\Database\ConnectionLifecycleMiddleware;
use Slimfony\Core\Database\Retry;
use Slimfony\Core\Health\DatabaseReadinessCheck;
use Slimfony\Core\Health\LivenessController;
use Slimfony\Core\Health\ReadinessController;
use Slimfony\Core\Http\BodySizeLimitMiddleware;
use Slimfony\Core\Http\RequestIdMiddleware;
use Slimfony\Core\Http\SecurityHeadersMiddleware;
use Slimfony\Core\Log\RequestContext;
use Slimfony\Core\Observability\MetricsController;
use Slimfony\Core\Observability\MetricsMiddleware;
use Slimfony\Core\Observability\MetricsRegistry;
use Slimfony\Core\Worker\ResetPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

return static function (ContainerBuilder $c): void {
    // Built at boot from the environment (they carry secrets or addresses), so the compiled cache holds none of them.
    $c->register(LoggerInterface::class)->setSynthetic(true)->setPublic(true);
    $c->register(RequestContext::class)->setSynthetic(true)->setPublic(true)->addTag(ResetPass::TAG);
    $c->register(MetricsRegistry::class)->setSynthetic(true)->setPublic(true);
    $c->register(Connection::class)->setSynthetic(true)->setPublic(true);
    $c->register(ConnectionLifecycle::class)->setSynthetic(true)->setPublic(true)->addTag(ResetPass::TAG);
    $c->register(Retry::class)->setSynthetic(true)->setPublic(true);
    $c->register(MetricsController::class)->setSynthetic(true)->setPublic(true);
    $c->register(RateLimiter::class)->setSynthetic(true)->setPublic(true); // the limit comes from the environment

    // The middleware chain, outermost first.
    $c->register(MetricsMiddleware::class)->setAutowired(true);
    $c->register(RequestIdMiddleware::class)->setAutowired(true);
    $c->register(SecurityHeadersMiddleware::class);
    $c->register(ConnectionLifecycleMiddleware::class)->setAutowired(true);
    $c->register(BodySizeLimitMiddleware::class)->setArguments([1_048_576]); // the largest upload; the sandbox route is held to less by its own limits

    // Authentication: deny by default. The authenticator (built at boot from the identity provider's settings) checks the token.
    $c->register(Authenticator::class)->setSynthetic(true)->setPublic(true);
    $c->register(AuthMiddleware::class)->setAutowired(true);
    $c->setParameter('slimfony.middleware', [
        MetricsMiddleware::class,
        RequestIdMiddleware::class,
        SecurityHeadersMiddleware::class,
        AuthMiddleware::class,
        ConnectionLifecycleMiddleware::class,
        BodySizeLimitMiddleware::class,
    ]);

    // Health: liveness never touches a dependency, readiness checks the database only (the hard dependency).
    $c->register(DatabaseReadinessCheck::class)->setAutowired(true);
    $c->register(LivenessController::class);
    $c->register(ReadinessController::class)->setArguments([[new Reference(DatabaseReadinessCheck::class)], new Reference(LoggerInterface::class)]);

    // Status of everything the page shows.
    $c->register('demo.check.nats', HttpReadinessCheck::class)->setArguments(['nats', '%env(NATS_MONITOR_URL)%/healthz']);
    $c->register('demo.check.prometheus', HttpReadinessCheck::class)->setArguments(['prometheus', '%env(PROMETHEUS_URL)%/-/ready']);
    $c->register(PrometheusQuery::class, HttpPrometheusQuery::class)->setArguments(['%env(PROMETHEUS_URL)%']);
    $c->register(StatusController::class)->setArguments([[
        new Reference(DatabaseReadinessCheck::class),
        new Reference('demo.check.nats'),
        new Reference('demo.check.prometheus'),
    ]]);
    $c->register(StatsController::class)->setAutowired(true);
    $c->register(RuntimeController::class);

    // Imports: the upload (two routes), the state of a batch, and the demo tokens for the page.
    $c->register(ArchiveInspector::class);
    $c->register(BatchRepository::class);
    $c->register(UploadController::class)->setAutowired(true);
    $c->register(BatchController::class)->setAutowired(true);
    $c->register(TokenSource::class, HttpTokenSource::class)->setArguments(['%env(IDP_URL)%']);
    $c->register(DemoTokenController::class)->setAutowired(true);
    $c->register(DemoRotateController::class)->setAutowired(true);
    $c->register(ImportSummaryController::class)->setAutowired(true);
    $c->register(BatchListController::class)->setAutowired(true);
    $c->register(StatementGenerator::class);
    $c->register(SampleArchiveController::class)->setAutowired(true);
};
