<?php

declare(strict_types=1);

use Demo\Api\Controller\RuntimeController;
use Demo\Api\Controller\StatsController;
use Demo\Api\Controller\StatusController;
use Demo\Infrastructure\Http\HttpReadinessCheck;
use Demo\Infrastructure\Prometheus\HttpPrometheusQuery;
use Demo\Infrastructure\Prometheus\PrometheusQuery;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
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

    // The middleware chain, outermost first.
    $c->register(MetricsMiddleware::class)->setAutowired(true);
    $c->register(RequestIdMiddleware::class)->setAutowired(true);
    $c->register(SecurityHeadersMiddleware::class);
    $c->register(ConnectionLifecycleMiddleware::class)->setAutowired(true);
    $c->register(BodySizeLimitMiddleware::class)->setArguments([65_536]);
    $c->setParameter('slimfony.middleware', [
        MetricsMiddleware::class,
        RequestIdMiddleware::class,
        SecurityHeadersMiddleware::class,
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
};
