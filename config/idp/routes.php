<?php

declare(strict_types=1);

use Demo\Idp\Controller\JwksController;
use Demo\Idp\Controller\TokenController;
use Slimfony\Core\Health\LivenessController;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

return static function (RouteCollection $r): void {
    $r->add('healthz', new Route('/healthz', ['_controller' => LivenessController::class], methods: ['GET']));
    $r->add('jwks', new Route('/.well-known/jwks.json', ['_controller' => JwksController::class], methods: ['GET']));
    $r->add('token', new Route('/token', ['_controller' => TokenController::class], methods: ['POST']));
};
