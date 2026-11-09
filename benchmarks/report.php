<?php

declare(strict_types=1);

// Turns the raw results of a benchmark run (k6 summaries and import.jsonl) into Markdown tables.
//   php benchmarks/report.php benchmarks/results/2026-11-09

$dir = $argv[1] ?? throw new InvalidArgumentException('Give the results directory.');

/** @return array<string, mixed> */
function load(string $file): array
{
    $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

    return is_array($data) ? $data : [];
}

function ms(mixed $value): string
{
    return is_numeric($value) ? number_format((float) $value, 1) : '-';
}

foreach (['health' => 'GET /healthz', 'auth' => 'GET /api/batches/{id}, verified token and a database read'] as $route => $title) {
    $summary = load("$dir/api-$route.json");
    if ([] === $summary) {
        continue;
    }
    $metrics = $summary['metrics'];
    echo "### API lane: $title\n\n| Target rate (req/s) | Achieved (req/s) | Dropped | Errors | median ms | p95 ms | p99 ms | max ms |\n|---:|---:|---:|---:|---:|---:|---:|---:|\n";
    uksort($metrics, static fn(string $a, string $b): int => (int) preg_replace('/\D/', '', $a) <=> (int) preg_replace('/\D/', '', $b));
    foreach ($metrics as $name => $values) {
        if (1 !== preg_match('/^http_req_duration\{rate:(\d+)\}$/', $name, $m)) {
            continue;
        }
        $rate = $m[1];
        $count = (float) ($metrics["http_reqs{rate:$rate}"]['count'] ?? 0);
        $stepSeconds = (float) trim((string) @file_get_contents("$dir/api-$route.step") ?: '15');
        $dropped = (int) ($metrics["dropped_iterations{rate:$rate}"]['count'] ?? 0);
        $failed = (float) ($metrics["http_req_failed{rate:$rate}"]['value'] ?? 0);
        printf("| %s | %s | %d | %.2f%% | %s | %s | %s | %s |\n", $rate, number_format($count / $stepSeconds), $dropped, $failed * 100, ms($values['med'] ?? null), ms($values['p(95)'] ?? null), ms($values['p(99)'] ?? null), ms($values['max'] ?? null));
    }
    echo "\n";
}

$lines = is_file("$dir/import.jsonl") ? file("$dir/import.jsonl", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
if ([] !== $lines) {
    /** @var array<string, list<array<string, mixed>>> $groups */
    $groups = [];
    foreach ($lines as $line) {
        $r = json_decode($line, true);
        if (is_array($r)) {
            $groups[($r['lock_order'] ?? 'appearance') . '-' . $r['pooler'] . '-' . str_pad((string) $r['importers'], 3, '0', STR_PAD_LEFT)][] = $r;
        }
    }
    ksort($groups);
    $median = static function (array $values): float {
        sort($values);
        $n = count($values);

        return $n % 2 ? (float) $values[intdiv($n, 2)] : ((float) $values[$n / 2 - 1] + (float) $values[$n / 2]) / 2;
    };

    echo "### Import lane: ZIPs of 10 statements, from the first upload to the last booked statement\n\nEvery configuration was run several times; the figures are the median of the runs, with the fastest and the slowest run next to it.\n\n| Lock order | Importers | PgBouncer | Runs | ZIPs | Seconds (median, fastest-slowest) | ZIPs/s | Statements/s | Deadlock retries | Not finished |\n|:---|---:|:---:|---:|---:|---:|---:|---:|---:|---:|\n";
    foreach ($groups as $runs) {
        $seconds = array_map(static fn(array $r): float => max(0.001, (float) $r['seconds']), $runs);
        $m = $median($seconds);
        $first = $runs[0];
        $statements = $median(array_map(static fn(array $r): float => ($r['statements_booked'] + $r['statements_rejected']) / max(0.001, (float) $r['seconds']), $runs));
        printf(
            "| %s | %d | %s | %d | %d | %.1f (%.1f-%.1f) | %.1f | %.0f | %.0f | %d |\n",
            $first['lock_order'] ?? 'appearance',
            $first['importers'],
            'on' === $first['pooler'] ? 'yes' : 'no',
            count($runs),
            $first['zips'],
            $m,
            min($seconds),
            max($seconds),
            $first['zips'] / $m,
            $statements,
            $median(array_map(static fn(array $r): float => (float) $r['deadlock_retries'], $runs)),
            array_sum(array_map(static fn(array $r): int => (int) $r['still_open'], $runs)),
        );
    }
    echo "\n";
}
