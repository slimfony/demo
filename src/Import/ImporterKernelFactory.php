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
use Demo\Infrastructure\Nats\StreamProvisioner;
use Demo\Infrastructure\ProcessSetup;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Slimfony\Core\Config\Env;
use Slimfony\Core\Database\ConnectionLifecycle;
use Slimfony\Core\Database\Retry;
use Slimfony\Core\Kernel;
use Slimfony\Core\Log\RequestContext;

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
        $setup = new ProcessSetup($this->env);
        $metrics = $setup->metrics('The importer');

        $worker = (string) gethostname();
        $context = new RequestContext();
        $events = $setup->events();
        $logger = $setup->logger('importer', $context, $events);
        $connection = $setup->connection('demo-importer');
        $lifecycle = new ConnectionLifecycle($connection, $logger);
        $retry = new Retry($connection, $logger, maxAttempts: 8, retries: $metrics->counter('db_retries_total', 'Database operations that were retried (deadlocks, lost connections).', ['reason']));
        $stages = $metrics->histogram('import_stage_seconds', 'Time of one stage of importing a batch (claim, unzip, parse, write, finish; events is the notifications to the page).', ['stage']);
        $ackWait = $this->env->int('IMPORT_ACK_WAIT_SECONDS', 60); // how long the broker waits for an acknowledgement before it delivers a message again
        $importer = new BatchImporter(
            new ImportRepository($connection, $ackWait + 30), // a batch still `running` after this long was abandoned
            new ArchiveReader(),
            new CamtParser($this->projectDir . '/schema/camt.053.001.02.xsd'),
            new StatementRules(),
            new StatementWriter(),
            $retry,
            $this->env->bool('CHAOS_ENABLED') ? new KillSwitchChaos(new KillTokens($connection), $logger) : new NoChaos(),
            $stages,
        );

        $nats = new Client(new Configuration(host: $this->env->string('NATS_HOST'), port: $this->env->int('NATS_PORT', 4222), timeout: 2.0));
        $consumer = new StreamProvisioner()->ensure($nats)->getConsumer(self::CONSUMER);
        $consumer->getConfiguration()
            ->setSubjectFilter(StreamProvisioner::ACCEPTED)
            ->setAckPolicy(AckPolicy::EXPLICIT)
            ->setAckWait($ackWait * 1_000_000_000)
            ->setMaxDeliver(ImporterWorker::MAX_DELIVER);
        $consumer->setBatching($this->env->int('IMPORT_PULL_BATCH', 5))->setIterations(1)->setExpires(0.5)->setDelay(0.0); // a few messages per pull request (every pull costs about 40 ms of waiting), then back to the loop (and Prometheus)
        $consumer->create();

        $endpoint = $setup->metricsEndpoint($metrics);
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
            $stages,
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
}
