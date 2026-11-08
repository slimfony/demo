<?php

declare(strict_types=1);

// Builds the Grafana dashboard JSON: `php scripts/dashboard.php`. The JSON is committed; this keeps its structure readable and valid.

$ds = ['type' => 'prometheus', 'uid' => 'prometheus'];

$target = static fn(string $expr, string $legend = '', string $ref = 'A'): array => [
    'datasource' => $ds, 'expr' => $expr, 'legendFormat' => $legend, 'refId' => $ref, 'interval' => '2s',
];

$stat = static fn(int $id, string $title, string $expr, string $unit, int $x, int $w = 4, ?array $thresholds = null): array => [
    'id' => $id, 'type' => 'stat', 'title' => $title, 'datasource' => $ds,
    'gridPos' => ['h' => 4, 'w' => $w, 'x' => $x, 'y' => 0],
    'targets' => [$target($expr)],
    'fieldConfig' => ['defaults' => ['unit' => $unit, 'decimals' => 1, 'color' => ['mode' => 'thresholds'], 'thresholds' => $thresholds ?? ['mode' => 'absolute', 'steps' => [['color' => '#2DD4BF', 'value' => null]]]], 'overrides' => []],
    'options' => ['colorMode' => 'background_solid', 'graphMode' => 'area', 'textMode' => 'value', 'reduceOptions' => ['calcs' => ['lastNotNull'], 'fields' => '', 'values' => false]],
];

$series = static fn(int $id, string $title, array $targets, string $unit, int $x, int $y, int $w = 12, int $h = 8, string $fill = '12'): array => [
    'id' => $id, 'type' => 'timeseries', 'title' => $title, 'datasource' => $ds,
    'gridPos' => ['h' => $h, 'w' => $w, 'x' => $x, 'y' => $y],
    'targets' => $targets,
    'fieldConfig' => ['defaults' => ['unit' => $unit, 'custom' => ['lineWidth' => 2, 'fillOpacity' => (int) $fill, 'showPoints' => 'never', 'spanNulls' => true]], 'overrides' => []],
    'options' => ['legend' => ['displayMode' => 'list', 'placement' => 'bottom'], 'tooltip' => ['mode' => 'multi', 'sort' => 'desc']],
];

$p = static fn(string $q): string => \sprintf('histogram_quantile(%s, sum by (le) (rate(http_request_duration_seconds_bucket[10s]))) * 1000000', $q);

$panels = [
    $stat(1, 'Requests / s', 'sum(rate(http_requests_total[10s]))', 'reqps', 0),
    $stat(2, 'p95 latency', $p('0.95'), 'µs', 4),
    $stat(3, 'p99 latency', $p('0.99'), 'µs', 8),
    $stat(4, 'Requests served', 'sum(http_requests_total)', 'short', 12),
    $stat(5, 'Server errors / s', 'sum(rate(http_requests_total{status=~"5.."}[10s])) or vector(0)', 'reqps', 16, 4, ['mode' => 'absolute', 'steps' => [['color' => '#2DD4BF', 'value' => null], ['color' => '#F87171', 'value' => 0.001]]]),
    $stat(6, 'Rejected (401/403) / s', 'sum(rate(http_requests_total{status=~"40[13]"}[10s])) or vector(0)', 'reqps', 20, 4, ['mode' => 'absolute', 'steps' => [['color' => '#2DD4BF', 'value' => null], ['color' => '#FBBF24', 'value' => 0.001]]]),
    $series(7, 'Requests per second by route', [$target('sum by (route) (rate(http_requests_total[10s]))', '{{route}}')], 'reqps', 0, 4),
    $series(8, 'Latency percentiles (core + app, per request)', [$target($p('0.5'), 'p50', 'A'), $target($p('0.95'), 'p95', 'B'), $target($p('0.99'), 'p99', 'C')], 'µs', 12, 4),
    $series(9, 'Responses by status', [$target('sum by (status) (rate(http_requests_total[10s]))', '{{status}}')], 'reqps', 0, 12),
    $series(10, 'Database: retries and leaked transactions', [$target('sum by (reason) (rate(db_retries_total[10s]))', 'retries: {{reason}}', 'A'), $target('sum(rate(db_transactions_leaked_total[10s]))', 'leaked transactions', 'B')], 'ops', 12, 12),
    $series(11, 'Outbox relay: published per second and waiting', [$target('sum(rate(outbox_published_total[10s]))', 'published / s', 'A'), $target('sum(outbox_pending)', 'waiting in the outbox', 'B'), $target('sum(rate(outbox_failures_total[10s]))', 'failed turns / s', 'C')], 'short', 0, 20),
    $series(12, 'Uploads: accepted and refused by source and reason', [$target('sum by (source) (rate(imports_accepted_total[10s]))', 'accepted: {{source}}', 'A'), $target('sum by (source, reason) (rate(imports_rejected_total[10s]))', 'refused: {{source}} {{reason}}', 'B')], 'short', 12, 20),
];

$dashboard = [
    'uid' => 'slimfony-demo',
    'title' => 'slimfony demo',
    'tags' => ['slimfony'],
    'timezone' => 'browser',
    'schemaVersion' => 39,
    'version' => 1,
    'refresh' => '2s',
    'time' => ['from' => 'now-5m', 'to' => 'now'],
    'timepicker' => ['refresh_intervals' => ['1s', '2s', '5s', '10s']],
    'editable' => false,
    'panels' => $panels,
];

file_put_contents(__DIR__ . '/../docker/grafana/dashboards/demo.json', json_encode($dashboard, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
echo "dashboard written\n";
