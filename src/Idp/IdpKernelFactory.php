<?php

declare(strict_types=1);

namespace Demo\Idp;

use Demo\Idp\Controller\JwksController;
use Demo\Idp\Controller\RotateController;
use Demo\Idp\Controller\TokenController;
use Psr\Log\LoggerInterface;
use Slimfony\Core\Config\Env;
use Slimfony\Core\Kernel;
use Slimfony\Core\Log\JsonStdoutLogger;
use Slimfony\Core\Log\RequestContext;

/** The composition root of the demo identity provider (a second service from the same image). */
final class IdpKernelFactory
{
    public function __construct(
        private readonly Env $env,
        private readonly string $projectDir,
    ) {}

    public function create(): Kernel
    {
        $context = new RequestContext();
        $logger = new JsonStdoutLogger($this->env->string('LOG_LEVEL', 'info'), $context);
        $keys = new ApcuKeyStore();
        $issuer = new TokenIssuer($keys, $this->env->string('JWT_ISSUER'), $this->env->string('JWT_AUDIENCE'));

        return new Kernel(
            $this->projectDir . '/config/idp/services.php',
            $this->projectDir . '/config/idp/routes.php',
            debug: $this->env->bool('APP_DEBUG'),
            logger: $logger,
            cacheDir: $this->projectDir . '/var/cache/idp',
            synthetic: [
                LoggerInterface::class => $logger,
                RequestContext::class => $context,
                JwksController::class => new JwksController($issuer),
                TokenController::class => new TokenController($issuer),
                RotateController::class => new RotateController($keys),
            ],
        );
    }
}
