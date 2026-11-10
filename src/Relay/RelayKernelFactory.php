<?php

declare(strict_types=1);

namespace Demo\Relay;

use Basis\Nats\Client;
use Basis\Nats\Configuration;
use Demo\Infrastructure\Nats\NatsMessagePublisher;
use Demo\Infrastructure\Nats\StreamProvisioner;
use Demo\Infrastructure\ProcessSetup;
use Demo\Outbox\OutboxRelay;
use Demo\Outbox\StaleBatchSweeper;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Slimfony\Core\Config\Env;
use Slimfony\Core\Database\ConnectionLifecycle;
use Slimfony\Core\Database\Retry;
use Slimfony\Core\Kernel;
use Slimfony\Core\Log\RequestContext;

/** The composition root of the outbox relay, a command line process that uses the same core kernel as the api. */
final class RelayKernelFactory
{
    public function __construct(
        private readonly Env $env,
        private readonly string $projectDir,
    ) {}

    public function create(): Kernel
    {
        $setup = new ProcessSetup($this->env);
        $context = new RequestContext();
        $logger = $setup->logger('relay', $context, $setup->events());
        $connection = $setup->connection('demo-relay');
        $lifecycle = new ConnectionLifecycle($connection, $logger);
        $retry = new Retry($connection, $logger);
        $nats = new Client(new Configuration(host: $this->env->string('NATS_HOST'), port: $this->env->int('NATS_PORT', 4222), timeout: 2.0));
        $relay = new OutboxRelay($retry, new NatsMessagePublisher($nats, new StreamProvisioner()));

        $metrics = $setup->metrics('The relay');
        $endpoint = $setup->metricsEndpoint($metrics);
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
}
