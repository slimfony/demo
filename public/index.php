<?php

declare(strict_types=1);

use Demo\Api\ApiKernelFactory;
use Demo\Api\ProcessMemory;
use Psr\Log\LoggerInterface;
use Slimfony\Core\Config\Env;
use Slimfony\Core\Config\Trust;
use Slimfony\Core\Observability\MetricsRegistry;
use Slimfony\Core\Worker\GarbageCollection;
use Slimfony\Core\Worker\WorkerRunner;
use Symfony\Component\HttpFoundation\Request;

require __DIR__ . '/../vendor/autoload.php';

$env = Env::fromGlobals();
new Trust($env)->apply(); // TRUSTED_PROXIES / TRUSTED_HOSTS: nothing is trusted unless configured

$kernel = new ApiKernelFactory($env, dirname(__DIR__))->create();
$logger = $kernel->get(LoggerInterface::class);
assert($logger instanceof LoggerInterface);

// the runtime reports it (/api/runtime): the classic entry script of the benchmarks does not define it
define('DEMO_WORKER_LOOP', true);

$metrics = $kernel->get(MetricsRegistry::class);
assert($metrics instanceof MetricsRegistry);
$process = new ProcessMemory();
$memory = $metrics->gauge('php_memory_bytes', 'Resident memory of the web server process (all its PHP threads).');

new WorkerRunner(
    $kernel,
    $logger,
    maxRequests: $env->int('MAX_REQUESTS', 0),
    requestFactory: static function () use ($memory, $process): Request {
        $memory->set($process->resident()); // the whole server process: FrankenPHP runs its PHP threads in one process

        return Request::createFromGlobals();
    },
    gc: new GarbageCollection(
        deferred: $env->bool('DEFER_GC'), // the collector runs between requests, not during them
        runs: $metrics->counter('php_gc_runs_total', 'Runs of the PHP cycle collector.'),
        collected: $metrics->counter('php_gc_collected_total', 'Cycles the collector freed.'),
    ),
)->run();
