<?php

declare(strict_types=1);

namespace Demo\Api;

use Demo\Chaos\ChaosSwitch;
use Demo\Imports\RateLimiter;
use Demo\Infrastructure\ApcuWindowCounter;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Slimfony\Core\Auth\Authenticator;
use Slimfony\Core\Auth\Jwt\JwksKeyProvider;
use Slimfony\Core\Auth\Jwt\JwtAuthenticator;
use Slimfony\Core\Auth\Jwt\JwtConfiguration;
use Slimfony\Core\Config\Env;
use Slimfony\Core\Database\ConnectionFactory;
use Slimfony\Core\Database\ConnectionLifecycle;
use Slimfony\Core\Database\Retry;
use Slimfony\Core\Kernel;
use Slimfony\Core\Log\JsonStdoutLogger;
use Slimfony\Core\Log\RequestContext;
use Slimfony\Core\Observability\ApcuStorage;
use Slimfony\Core\Observability\MetricsController;
use Slimfony\Core\Observability\MetricsRegistry;

/**
 * The composition root of the api. Everything that needs a secret or an environment value is built here, at boot,
 * and handed to the container as a synthetic service: the compiled container (built at image build time) holds no secrets.
 */
final class ApiKernelFactory
{
    public function __construct(
        private readonly Env $env,
        private readonly string $projectDir,
    ) {}

    public function create(): Kernel
    {
        $context = new RequestContext();
        $logger = new JsonStdoutLogger($this->env->string('LOG_LEVEL', 'info'), $context);
        $metrics = new MetricsRegistry(new ApcuStorage());
        $connection = ConnectionFactory::create($this->databaseUrl(), transactionPooling: true, applicationName: 'demo-api');
        $lifecycle = new ConnectionLifecycle(
            $connection,
            $logger,
            leakedTransactions: $metrics->counter('db_transactions_leaked_total', 'Transactions a request left open and that were rolled back.'),
        );
        $retry = new Retry(
            $connection,
            $logger,
            retries: $metrics->counter('db_retries_total', 'Database operations that were retried.', ['reason']),
        );

        // fail at boot, not at the first request, when an address the container resolves from the environment is missing
        $this->env->string('PROMETHEUS_URL');
        $this->env->string('NATS_MONITOR_URL');
        $this->env->string('IDP_URL');
        $keys = new JwksKeyProvider($this->env->string('JWKS_URI'), $logger, minRefetchSeconds: 2); // a new key id is looked up at once (at most every two seconds)
        $keys->keyFor('warm-up'); // load the provider's keys now: the first requests must not find the key list empty while another thread is still fetching it
        $authenticator = new JwtAuthenticator(
            new JwtConfiguration($this->env->string('JWT_ISSUER'), $this->env->string('JWT_AUDIENCE'), ['EdDSA']), // only the algorithm the identity provider signs with
            $keys,
            $logger,
        );

        apcu_add('demo.started', time()); // the first worker thread wins: the uptime of the process

        return new Kernel(
            $this->projectDir . '/config/api/services.php',
            $this->projectDir . '/config/api/routes.php',
            debug: $this->env->bool('APP_DEBUG'),
            logger: $logger,
            cacheDir: $this->projectDir . '/var/cache/api',
            synthetic: [
                LoggerInterface::class => $logger,
                RequestContext::class => $context,
                MetricsRegistry::class => $metrics,
                Connection::class => $connection,
                ConnectionLifecycle::class => $lifecycle,
                Retry::class => $retry,
                Authenticator::class => $authenticator,
                ChaosSwitch::class => new ChaosSwitch($this->env->bool('CHAOS_ENABLED')),
                RateLimiter::class => new RateLimiter(new ApcuWindowCounter(), $this->env->int('SANDBOX_RATE_LIMIT', 1200)), // uploads per minute and client
                MetricsController::class => new MetricsController($metrics, $this->env->string('METRICS_TOKEN')),
            ],
        );
    }

    private function databaseUrl(): string
    {
        return \sprintf(
            'pdo-pgsql://%s:%s@%s:%d/%s',
            rawurlencode($this->env->string('DB_USER')),
            rawurlencode($this->env->string('DB_PASSWORD')),
            $this->env->string('DB_HOST'),
            $this->env->int('DB_PORT', 5432),
            $this->env->string('DB_NAME'),
        );
    }
}
