<?php

declare(strict_types=1);

use Demo\Idp\Controller\JwksController;
use Demo\Idp\Controller\TokenController;
use Psr\Log\LoggerInterface;
use Slimfony\Core\Health\LivenessController;
use Slimfony\Core\Http\BodySizeLimitMiddleware;
use Slimfony\Core\Http\RequestIdMiddleware;
use Slimfony\Core\Http\SecurityHeadersMiddleware;
use Slimfony\Core\Log\RequestContext;
use Slimfony\Core\Worker\ResetPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;

return static function (ContainerBuilder $c): void {
    $c->register(LoggerInterface::class)->setSynthetic(true)->setPublic(true);
    $c->register(RequestContext::class)->setSynthetic(true)->setPublic(true)->addTag(ResetPass::TAG);
    $c->register(JwksController::class)->setSynthetic(true)->setPublic(true); // they hold the signing key: built at boot
    $c->register(TokenController::class)->setSynthetic(true)->setPublic(true);

    $c->register(RequestIdMiddleware::class)->setAutowired(true);
    $c->register(SecurityHeadersMiddleware::class);
    $c->register(BodySizeLimitMiddleware::class)->setArguments([1_024]);
    $c->setParameter('slimfony.middleware', [
        RequestIdMiddleware::class,
        SecurityHeadersMiddleware::class,
        BodySizeLimitMiddleware::class,
    ]);

    $c->register(LivenessController::class);
};
