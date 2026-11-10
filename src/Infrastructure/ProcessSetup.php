<?php

declare(strict_types=1);

namespace Demo\Infrastructure;

use Demo\Events\EventPublisher;
use Demo\Events\NullEventPublisher;
use Demo\Infrastructure\Mercure\MercurePublisher;
use Demo\Infrastructure\Mercure\TeeLogger;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Slimfony\Core\Config\Env;
use Slimfony\Core\Database\ConnectionFactory;
use Slimfony\Core\Exception\ConfigurationException;
use Slimfony\Core\Log\JsonStdoutLogger;
use Slimfony\Core\Log\RequestContext;
use Slimfony\Core\Observability\ApcuStorage;
use Slimfony\Core\Observability\MetricsController;
use Slimfony\Core\Observability\MetricsRegistry;
use Slimfony\Core\Observability\ProcessMetricsEndpoint;

/**
 * What every command line process of the demo (relay, importer, generator) sets up the same way from its environment: the
 * database connection, the log that also reaches the page, the metrics and the port Prometheus reads them from.
 */
final class ProcessSetup
{
    public function __construct(private readonly Env $env) {}

    public function connection(string $applicationName): Connection
    {
        return ConnectionFactory::create(
            \sprintf(
                'pdo-pgsql://%s:%s@%s:%d/%s',
                rawurlencode($this->env->string('DB_USER')),
                rawurlencode($this->env->string('DB_PASSWORD')),
                $this->env->string('DB_HOST'),
                $this->env->int('DB_PORT', 5432),
                $this->env->string('DB_NAME'),
            ),
            transactionPooling: $this->env->bool('DB_TRANSACTION_POOLING', true),
            applicationName: $applicationName,
        );
    }

    /** Events and the log view go to the page through the Mercure hub of the web server (off when no hub is configured). */
    public function events(): EventPublisher
    {
        $hub = $this->env->string('MERCURE_URL', '');

        return '' === $hub ? new NullEventPublisher() : new MercurePublisher($hub, $this->env->string('MERCURE_KEY'));
    }

    public function logger(string $service, RequestContext $context, EventPublisher $events): LoggerInterface
    {
        return new TeeLogger(new JsonStdoutLogger($this->env->string('LOG_LEVEL', 'info'), $context), $events, $service);
    }

    /** The metrics live in the process's own APCu (it must start with `-d apc.enable_cli=1`) and are served on a port for Prometheus. */
    public function metrics(string $process): MetricsRegistry
    {
        if (!\function_exists('apcu_enabled') || !apcu_enabled()) {
            throw new ConfigurationException(\sprintf('%s keeps its metrics in APCu: start PHP with -d apc.enable_cli=1.', $process));
        }

        return new MetricsRegistry(new ApcuStorage());
    }

    public function metricsEndpoint(MetricsRegistry $metrics): ProcessMetricsEndpoint
    {
        return new ProcessMetricsEndpoint('tcp://0.0.0.0:' . $this->env->int('METRICS_PORT', 9100), new MetricsController($metrics, $this->env->string('METRICS_TOKEN')));
    }
}
