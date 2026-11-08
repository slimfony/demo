<?php

declare(strict_types=1);

use Demo\Relay\RelayWorker;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Slimfony\Core\Database\ConnectionLifecycle;
use Slimfony\Core\Log\RequestContext;
use Slimfony\Core\Worker\ResetPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;

return static function (ContainerBuilder $c): void {
    // Everything is built at start-up from the environment; the container only holds them and resets them between runs.
    $c->register(LoggerInterface::class)->setSynthetic(true)->setPublic(true);
    $c->register(RequestContext::class)->setSynthetic(true)->setPublic(true)->addTag(ResetPass::TAG);
    $c->register(Connection::class)->setSynthetic(true)->setPublic(true);
    $c->register(ConnectionLifecycle::class)->setSynthetic(true)->setPublic(true)->addTag(ResetPass::TAG);
    $c->register(RelayWorker::class)->setSynthetic(true)->setPublic(true);
};
