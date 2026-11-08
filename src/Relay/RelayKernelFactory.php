<?php

declare(strict_types=1);

namespace Demo\Relay;

use Basis\Nats\Client;
use Basis\Nats\Configuration;
use Demo\Infrastructure\Nats\NatsMessagePublisher;
use Demo\Outbox\OutboxRelay;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Slimfony\Core\Config\Env;
use Slimfony\Core\Database\ConnectionFactory;
use Slimfony\Core\Database\ConnectionLifecycle;
use Slimfony\Core\Database\Retry;
use Slimfony\Core\Kernel;
use Slimfony\Core\Log\JsonStdoutLogger;
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
        $context = new RequestContext();
        $logger = new JsonStdoutLogger($this->env->string('LOG_LEVEL', 'info'), $context);
        $connection = ConnectionFactory::create($this->databaseUrl(), transactionPooling: true, applicationName: 'demo-relay');
        $lifecycle = new ConnectionLifecycle($connection, $logger);
        $retry = new Retry($connection, $logger);
        $nats = new Client(new Configuration(host: $this->env->string('NATS_HOST'), port: $this->env->int('NATS_PORT', 4222), timeout: 2.0));
        $relay = new OutboxRelay($retry, new NatsMessagePublisher($nats, 'IMPORTS', ['imports.>']));

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
                OutboxRelay::class => $relay,
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
