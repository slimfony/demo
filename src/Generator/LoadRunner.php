<?php

declare(strict_types=1);

namespace Demo\Generator;

use Demo\Imports\LoadRunRepository;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Serves the runs of the page's "Try it": sends the requests to the API as fast as it can over a few connections (as a browser
 * would), times every request on its own, and writes down how many were sent and the latency distribution.
 */
final class LoadRunner
{
    private const int CONCURRENCY = 6; // what a browser opens per host
    private const int PROGRESS_EVERY = 100;

    /** @var \Closure(string, int, \Closure(float, bool): void): void */
    private readonly \Closure $send;

    /**
     * @param (\Closure(string, int, \Closure(float, bool): void): void)|null $send url and number of requests in; calls the callback with the seconds
     *                                                                              and the success of every request when it is done
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly LoadRunRepository $runs,
        private readonly string $apiUrl,
        private readonly ?LoggerInterface $logger = null,
        ?\Closure $send = null,
    ) {
        $this->send = $send ?? self::sendWithCurl(...);
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
        $this->logger?->info('A load run starts', ['run' => $run['id'], 'requests' => $run['requests'], 'endpoint' => $run['endpoint']]);

        $latencies = [];
        $errors = 0;
        $begin = hrtime(true);
        ($this->send)($this->apiUrl . $run['endpoint'], $run['requests'], function (float $seconds, bool $ok) use (&$latencies, &$errors, $run): void {
            $latencies[] = $seconds * 1000;
            $errors += $ok ? 0 : 1;
            if (0 === \count($latencies) % self::PROGRESS_EVERY) {
                $this->runs->progress($this->connection, $run['id'], \count($latencies), $errors);
            }
        });
        $seconds = (hrtime(true) - $begin) / 1e9;

        sort($latencies);
        $percentile = static fn(float $p): float => [] === $latencies ? 0.0 : $latencies[min(\count($latencies) - 1, (int) floor($p * \count($latencies)))];
        $this->runs->finish($this->connection, $run['id'], \count($latencies), $errors, $seconds, $percentile(0.5), $percentile(0.95), $percentile(0.99));
        $this->logger?->info('A load run is done', ['run' => $run['id'], 'sent' => \count($latencies), 'errors' => $errors]);

        return true;
    }

    /**
     * @param \Closure(float, bool): void $record
     */
    private static function sendWithCurl(string $url, int $total, \Closure $record): void
    {
        $multi = curl_multi_init();
        $started = 0;
        $finished = 0;
        $start = static function () use ($multi, $url, &$started): void {
            $handle = curl_init($url);
            if (false === $handle) {
                return;
            }
            curl_setopt_array($handle, [\CURLOPT_RETURNTRANSFER => true, \CURLOPT_TIMEOUT => 10, \CURLOPT_FOLLOWLOCATION => false]);
            curl_multi_add_handle($multi, $handle);
            ++$started;
        };

        for ($i = 0; $i < min(self::CONCURRENCY, $total); ++$i) {
            $start();
        }
        while ($finished < $total) {
            curl_multi_exec($multi, $running);
            while (false !== ($info = curl_multi_info_read($multi))) {
                $handle = $info['handle'];
                if (!$handle instanceof \CurlHandle) {
                    continue;
                }
                $ok = \CURLE_OK === $info['result'] && 200 === curl_getinfo($handle, \CURLINFO_RESPONSE_CODE);
                $record(curl_getinfo($handle, \CURLINFO_TOTAL_TIME), $ok);
                curl_multi_remove_handle($multi, $handle); // the handle is freed with its last reference
                ++$finished;
                if ($started < $total) {
                    $start();
                }
            }
            if ($running > 0) {
                curl_multi_select($multi, 0.05);
            }
        }
        curl_multi_close($multi);
    }
}
