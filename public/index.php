<?php

declare(strict_types=1);

use Demo\Api\ApiKernelFactory;
use Psr\Log\LoggerInterface;
use Slimfony\Core\Config\Env;
use Slimfony\Core\Config\Trust;
use Slimfony\Core\Worker\WorkerRunner;

require __DIR__ . '/../vendor/autoload.php';

$env = Env::fromGlobals();
new Trust($env)->apply(); // TRUSTED_PROXIES / TRUSTED_HOSTS: nothing is trusted unless configured

$kernel = new ApiKernelFactory($env, dirname(__DIR__))->create();
$logger = $kernel->get(LoggerInterface::class);
assert($logger instanceof LoggerInterface);

new WorkerRunner($kernel, $logger, maxRequests: $env->int('MAX_REQUESTS', 0))->run();
