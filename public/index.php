<?php

declare(strict_types=1);

use Demo\Api\ApiKernelFactory;
use Psr\Log\LoggerInterface;
use Slimfony\Core\Config\Env;
use Slimfony\Core\Config\Trust;
use Slimfony\Core\Observability\MetricsRegistry;
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
$memory = $metrics->gauge('php_memory_bytes', 'Resident memory of the web server process (all its PHP threads).');

new WorkerRunner(
    $kernel,
    $logger,
    maxRequests: $env->int('MAX_REQUESTS', 0),
    requestFactory: static function () use ($memory): Request {
        // the resident memory of the whole server process (Linux): FrankenPHP runs its PHP threads in one process
        $statm = is_readable('/proc/self/statm') ? explode(' ', (string) file_get_contents('/proc/self/statm')) : [];
        $memory->set(isset($statm[1]) ? (int) $statm[1] * 4096 : memory_get_usage(true));

        return Request::createFromGlobals();
    },
    deferGc: $env->bool('DEFER_GC'), // the collector runs between requests, not during them
    gcRuns: $metrics->counter('php_gc_runs_total', 'Runs of the PHP cycle collector.'),
    gcCollected: $metrics->counter('php_gc_collected_total', 'Cycles the collector freed.'),
)->run();
