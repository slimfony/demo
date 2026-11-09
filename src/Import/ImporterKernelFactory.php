<?php

declare(strict_types=1);

namespace Demo\Import;

use Basis\Nats\Client;
use Basis\Nats\Configuration;
use Basis\Nats\Consumer\AckPolicy;
use Demo\Camt\CamtParser;
use Demo\Camt\StatementRules;
use Demo\Chaos\KillSwitchChaos;
use Demo\Chaos\KillTokens;
use Demo\Chaos\NoChaos;
use Demo\Events\NullEventPublisher;
use Demo\Infrastructure\Mercure\MercurePublisher;
use Demo\Infrastructure\Mercure\TeeLogger;
use Demo\Infrastructure\Nats\StreamProvisioner;
use Demo\Relay\MetricsEndpoint;
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

/** The composition root of an importer: a command line process, any number of which can run side by side. */
final class ImporterKernelFactory
{
    private const string CONSUMER = 'importers';

    public function __construct(
        private readonly Env $env,
        private readonly string $projectDir,
    ) {}

    public function create(): Kernel
    {
        if (!\function_exists('apcu_enabled') || !apcu_enabled()) {
            throw new ConfigurationException('The importer keeps its metrics in APCu: start PHP with -d apc.enable_cli=1.');
        }

        $worker = (string) gethostname();
        $context = new RequestContext();
        $metrics = new MetricsRegistry(new ApcuStorage());
        // events and the log view go to the page through the Mercure hub of the web server (off when no hub is configured)
        $hub = $this->env->string('MERCURE_URL', '');
        $events = '' === $hub ? new NullEventPublisher() : new MercurePublisher($hub, $this->env->string('MERCURE_KEY'));
        $logger = new TeeLogger(new JsonStdoutLogger($this->env->string('LOG_LEVEL', 'info'), $context), $events, 'importer');
        $connection = ConnectionFactory::create($this->databaseUrl(), transactionPooling: $this->env->bool('DB_TRANSACTION_POOLING', true), applicationName: 'demo-importer');
        $lifecycle = new ConnectionLifecycle($connection, $logger);
        $retry = new Retry($connection, $logger, maxAttempts: 8, retries: $metrics->counter('db_retries_total', 'Database operations that were retried (deadlocks, lost connections).', ['reason']));
        $ackWait = $this->env->int('IMPORT_ACK_WAIT_SECONDS', 60); // how long the broker waits for an acknowledgement before it delivers a message again
        $importer = new BatchImporter(
            new ImportRepository($connection, $ackWait + 30), // a batch still `running` after this long was abandoned
            new ArchiveReader(),
            new CamtParser($this->projectDir . '/schema/camt.053.001.02.xsd'),
            new StatementRules(),
            new StatementWriter(),
            $retry,
            $this->env->bool('CHAOS_ENABLED') ? new KillSwitchChaos(new KillTokens($connection), $logger) : new NoChaos(),
        );

        $nats = new Client(new Configuration(host: $this->env->string('NATS_HOST'), port: $this->env->int('NATS_PORT', 4222), timeout: 2.0));
        $consumer = new StreamProvisioner()->ensure($nats)->getConsumer(self::CONSUMER);
        $consumer->getConfiguration()
            ->setSubjectFilter(StreamProvisioner::ACCEPTED)
            ->setAckPolicy(AckPolicy::EXPLICIT)
            ->setAckWait($ackWait * 1_000_000_000)
            ->setMaxDeliver(ImporterWorker::MAX_DELIVER);
        $consumer->setBatching(1)->setIterations(1)->setExpires(0.5)->setDelay(0.0); // one message per turn, then back to the loop (and Prometheus)
        $consumer->create();

        $endpoint = new MetricsEndpoint('tcp://0.0.0.0:' . $this->env->int('METRICS_PORT', 9100), new MetricsController($metrics, $this->env->string('METRICS_TOKEN')));
        $importerWorker = new ImporterWorker(
            $consumer,
            $importer,
            $endpoint,
            $events,
            $logger,
            $worker,
            $metrics->counter('import_batches_total', 'Batches imported.', ['mode', 'source', 'status']),
            $metrics->counter('import_statements_total', 'Statements by what became of them.', ['result']),
            $metrics->counter('import_statements_rejected_total', 'Statements that ended with a reason other than being booked.', ['reason']),
            $metrics->counter('import_entries_total', 'Entries booked.'),
            $metrics->counter('import_worker_failures_total', 'Turns that failed (broker or database).'),
            $metrics->counter('import_dead_lettered_total', 'Batches given up after the last delivery the broker allows.'),
            $metrics->histogram('import_batch_seconds', 'Time to import one batch.'),
        );

        return new Kernel(
            $this->projectDir . '/config/importer/services.php',
            $this->projectDir . '/config/importer/routes.php',
            debug: $this->env->bool('APP_DEBUG'),
            logger: $logger,
            cacheDir: $this->projectDir . '/var/cache/importer',
            synthetic: [
                LoggerInterface::class => $logger,
                RequestContext::class => $context,
                Connection::class => $connection,
                ConnectionLifecycle::class => $lifecycle,
                ImporterWorker::class => $importerWorker,
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
