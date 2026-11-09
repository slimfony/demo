<?php

declare(strict_types=1);

namespace Demo\Relay;

use Basis\Nats\Client;
use Basis\Nats\Configuration;
use Demo\Events\NullEventPublisher;
use Demo\Infrastructure\Mercure\MercurePublisher;
use Demo\Infrastructure\Mercure\TeeLogger;
use Demo\Infrastructure\Nats\NatsMessagePublisher;
use Demo\Infrastructure\Nats\StreamProvisioner;
use Demo\Outbox\OutboxRelay;
use Demo\Outbox\StaleBatchSweeper;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Slimfony\Core\Config\Env;
use Slimfony\Core\Database\ConnectionFactory;
use Slimfony\Core\Database\ConnectionLifecycle;
use Slimfony\Core\Database\Retry;
use Slimfony\Core\Exception\ConfigurationException;
use Slimfony\Core\Kernel;
use Slimfony\Core\Log\JsonStdoutLogger;
use Slimfony\Core\Log\RequestContext;
use Slimfony\Core\Observability\ApcuStorage;
use Slimfony\Core\Observability\MetricsController;
use Slimfony\Core\Observability\MetricsRegistry;
use Slimfony\Core\Observability\ProcessMetricsEndpoint;

/** The composition root of the outbox relay, a command line process that uses the same core kernel as the api. */
final class RelayKernelFactory
{
    public function __construct(
        private readonly Env $env,
        private readonly string $projectDir,
    ) {}

    public function create(): Kernel
    {
        $context = new RequestContext();
        // events and the log view go to the page through the Mercure hub of the web server (off when no hub is configured)
        $hub = $this->env->string('MERCURE_URL', '');
        $events = '' === $hub ? new NullEventPublisher() : new MercurePublisher($hub, $this->env->string('MERCURE_KEY'));
        $logger = new TeeLogger(new JsonStdoutLogger($this->env->string('LOG_LEVEL', 'info'), $context), $events, 'relay');
        $connection = ConnectionFactory::create($this->databaseUrl(), transactionPooling: $this->env->bool('DB_TRANSACTION_POOLING', true), applicationName: 'demo-relay');
        $lifecycle = new ConnectionLifecycle($connection, $logger);
        $retry = new Retry($connection, $logger);
        $nats = new Client(new Configuration(host: $this->env->string('NATS_HOST'), port: $this->env->int('NATS_PORT', 4222), timeout: 2.0));
        $relay = new OutboxRelay($retry, new NatsMessagePublisher($nats, new StreamProvisioner()));

        // the metrics live in this process's APCu (start it with -d apc.enable_cli=1) and are served on a port for Prometheus
        if (!\function_exists('apcu_enabled') || !apcu_enabled()) {
            throw new ConfigurationException('The relay keeps its metrics in APCu: start PHP with -d apc.enable_cli=1.');
        }
        $metrics = new MetricsRegistry(new ApcuStorage());
        $endpoint = new ProcessMetricsEndpoint('tcp://0.0.0.0:' . $this->env->int('METRICS_PORT', 9100), new MetricsController($metrics, $this->env->string('METRICS_TOKEN')));
        $worker = new RelayWorker(
            $relay,
            new StaleBatchSweeper($retry, $this->env->int('SWEEP_AFTER_SECONDS', 120)),
            $endpoint,
            $logger,
            $metrics->counter('outbox_published_total', 'Outbox messages published to the broker.'),
            $metrics->counter('outbox_swept_total', 'Batches that were forgotten (waiting or running for too long) and announced again.'),
            $metrics->counter('outbox_failures_total', 'Relay turns that failed (broker or database).'),
            $metrics->gauge('outbox_pending', 'Outbox messages waiting to be published.'),
        );

        return new Kernel(
            $this->projectDir . '/config/relay/services.php',
            $this->projectDir . '/config/relay/routes.php',
            debug: $this->env->bool('APP_DEBUG'),
            logger: $logger,
            cacheDir: $this->projectDir . '/var/cache/relay',
            synthetic: [
                LoggerInterface::class => $logger,
                RequestContext::class => $context,
                Connection::class => $connection,
                ConnectionLifecycle::class => $lifecycle,
                RelayWorker::class => $worker,
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
