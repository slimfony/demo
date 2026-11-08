<?php

declare(strict_types=1);

use Demo\Idp\IdpKernelFactory;
use Psr\Log\LoggerInterface;
use Slimfony\Core\Config\Env;
use Slimfony\Core\Worker\WorkerRunner;

require __DIR__ . '/../vendor/autoload.php';

$env = Env::fromGlobals();

$kernel = new IdpKernelFactory($env, dirname(__DIR__))->create();
$logger = $kernel->get(LoggerInterface::class);
assert($logger instanceof LoggerInterface);

new WorkerRunner($kernel, $logger, maxRequests: $env->int('MAX_REQUESTS', 0))->run();
