<?php

declare(strict_types=1);

namespace Demo\Generator;

use Demo\Camt\StatementGenerator;
use Demo\Imports\ImportSource;
use Demo\Imports\RunRepository;
use Demo\Infrastructure\Idp\HttpTokenSource;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Serves the runs the page asks for: takes the oldest waiting run, generates its ZIPs of statements and uploads them to the
 * API, one in twenty through the anonymous sandbox and the rest with a token, and writes down what the API answered for each.
 * The batches then go the usual way (outbox, relay, importers); the page follows them from the database.
 */
final class RunGenerator
{
    private const int SANDBOX_EVERY = 20;
    private const int TOKEN_REFRESH_SECONDS = 240; // a demo token lives five minutes
    private const int STATEMENTS_PER_ZIP = 10;

    /** @var \Closure(string, string, array<string, string>): array{int, string} */
    private readonly \Closure $post;

    /**
     * @param (\Closure(string, string, array<string, string>): array{int, string})|null $post url, body and headers in; status and body out
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly RunRepository $runs,
        private readonly StatementGenerator $generator,
        private readonly HttpTokenSource $tokens,
        private readonly string $apiUrl,
        private readonly ?LoggerInterface $logger = null,
        ?\Closure $post = null,
    ) {
        $this->post = $post ?? self::send(...);
    }

    /**
     * @return bool true when a run was served, false when none was waiting
     */
    public function serveOne(): bool
    {
        $run = $this->runs->claim($this->connection);
        if (null === $run) {
            return false;
        }
        $this->logger?->info('A run starts', ['run' => $run['id'], 'zips' => $run['zips']]);

        $token = $this->tokens->token('valid');
        $tokenAt = hrtime(true) / 1e9;
        $seedBase = (int) (microtime(true) * 1000) % 900_000_000 + 1;

        for ($index = 0; $index < $run['zips']; ++$index) {
            if (hrtime(true) / 1e9 - $tokenAt > self::TOKEN_REFRESH_SECONDS) {
                $token = $this->tokens->token('valid');
                $tokenAt = hrtime(true) / 1e9;
            }
            $sandbox = 0 === $index % self::SANDBOX_EVERY;
            $zip = $this->generator->zip($this->generator->files($seedBase + $index, self::STATEMENTS_PER_ZIP, $run['fault_rate'], null, $run['accounts']));
            $url = $this->apiUrl . ($sandbox ? '/public/drop' : \sprintf('/api/imports?mode=%s&lock_order=%s', $run['mode']->value, $run['lock_order']->value));
            $headers = ['Content-Type' => 'application/zip'] + ($sandbox ? [] : ['Authorization' => 'Bearer ' . $token]);

            try {
                [$status, $body] = ($this->post)($url, $zip, $headers);
            } catch (\Throwable) {
                [$status, $body] = [0, ''];
            }
            $answer = json_decode($body, true);
            $batchId = 202 === $status && \is_array($answer) && \is_string($answer['id'] ?? null) ? $answer['id'] : null;
            $this->runs->recordItem($this->connection, $run['id'], $index, $batchId, $sandbox ? ImportSource::Public : ImportSource::Jwt, $status);
        }

        $this->runs->finishUploading($this->connection, $run['id']);
        $this->logger?->info('A run is uploaded', ['run' => $run['id']]);

        return true;
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{int, string}
     */
    private static function send(string $url, string $body, array $headers): array
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $context = stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", $lines), 'content' => $body, 'timeout' => 10.0, 'follow_location' => 0, 'ignore_errors' => true]]);
        $response = @file_get_contents($url, false, $context, 0, 65_536);
        $status = isset($http_response_header[0]) && 1 === preg_match('#^HTTP/\S+ (\d{3})#', $http_response_header[0], $match) ? (int) $match[1] : 0;

        return [$status, false === $response ? '' : $response];
    }
}
