<?php

declare(strict_types=1);

// For the comparison only: the same kernel, but booted and thrown away on every request (how PHP ran before worker mode).
use Demo\Api\ApiKernelFactory;
use Slimfony\Core\Config\Env;
use Slimfony\Core\Config\Trust;
use Symfony\Component\HttpFoundation\Request;

require __DIR__ . '/../vendor/autoload.php';

$env = Env::fromGlobals();
new Trust($env)->apply();
new ApiKernelFactory($env, dirname(__DIR__))->create()->handle(Request::createFromGlobals())->send();
