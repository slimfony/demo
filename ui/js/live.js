import { getJson, poll } from './api.js';
import { report } from './connection.js';
import { animateNumber, formatBytes, formatInteger, formatMicroseconds, formatMilliseconds } from './format.js';

const WINDOW = 60; // samples kept: two minutes at one sample every two seconds
const css = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim();
const FONT = '12px -apple-system, Inter, sans-serif';
// The window ends at the newest point the server sent (its clock, not this browser's), or now while there is none.
const windowOf = (times) => { const end = times.length ? times[times.length - 1] : Math.floor(Date.now() / 1000); return [end - 118, end]; };
const MIN_TOP = 4; // an empty chart still has a scale: 0 1 2 3 4, and it grows with the data
const WHOLE_STEPS = [1, 2, 5, 10, 20, 50, 100, 200, 500, 1000, 2000, 5000, 10_000, 20_000, 50_000, 100_000, 200_000, 500_000, 1_000_000]; // every value on these charts is a whole number

function chart(element, colorVar, formatValue, unit = '') {
  const color = () => css(colorVar);
  const muted = () => css('--text-2');
  const grid = () => css('--line');
  const data = [[], []];
  const plot = new window.uPlot({
    width: element.clientWidth,
    height: element.clientHeight,
    legend: { show: false },
    cursor: { show: false },
    select: { show: false },
    scales: {
      // a window that follows the clock, so an empty chart still shows the last two minutes (not years)
      x: { time: true, range: () => windowOf(data[0]) },
      y: { range: (self, min, max) => [0, Math.max(max * 1.15, MIN_TOP)] },
    },
    axes: [
      { stroke: muted, grid: { show: false }, ticks: { show: false }, font: FONT },
      { stroke: muted, grid: { stroke: grid, width: 1 }, ticks: { show: false }, size: 56, font: FONT, space: 28, incrs: WHOLE_STEPS, values: (self, ticks) => ticks.map(formatValue), ...(unit && { label: unit, labelSize: 18, labelFont: FONT }) },
    ],
    series: [
      {},
      { stroke: color, width: 2, points: { show: false } },
    ],
  }, data, element);

  window.addEventListener('themechange', () => plot.redraw(false));
  new ResizeObserver(() => plot.setSize({ width: element.clientWidth, height: element.clientHeight })).observe(element);

  return {
    push(time, value) {
      data[0].push(time);
      data[1].push(value);
      if (data[0].length > WINDOW) {
        data[0].shift();
        data[1].shift();
      }
      plot.setData(data);
    },
  };
}

// The top of a scale: a multiple of 4 above the data, so that four equal parts are whole numbers (the two sides of a two-scale chart line up).
const quarters = (max) => Math.max(MIN_TOP, Math.ceil((max * 1.1) / 4) * 4);

// Two series with their own scales (left: memory in MiB, right: busy workers), on one time axis.
function dualChart(element) {
  const muted = () => css('--text-2');
  const grid = () => css('--line');
  const data = [[], [], []];
  const axis = (scale, side, label) => ({
    scale, side, label, labelSize: 18, labelFont: FONT, stroke: muted, grid: { show: false }, ticks: { show: false }, size: 48, font: FONT, space: 28, incrs: WHOLE_STEPS,
    values: (self, ticks) => ticks.map((v) => formatInteger(v)),
    splits: (self, axisIndex, min, max) => [0, 0.25, 0.5, 0.75, 1].map((part) => min + (max - min) * part), // four equal parts on both sides: the ticks line up with the grid
  });
  const plot = new window.uPlot({
    width: element.clientWidth,
    height: element.clientHeight,
    legend: { show: false },
    cursor: { show: false },
    select: { show: false },
    scales: {
      x: { time: true, range: () => windowOf(data[0]) },
      mb: { range: (self, min, max) => [0, quarters(max)] },
      workers: { range: (self, min, max) => [0, quarters(max)] },
    },
    axes: [
      { stroke: muted, grid: { show: false }, ticks: { show: false }, font: FONT },
      { ...axis('mb', 3, 'MiB'), grid: { stroke: grid, width: 1 } },
      axis('workers', 1, 'busy workers'),
    ],
    series: [
      {},
      { scale: 'mb', stroke: () => css('--accent'), width: 2, points: { show: false } },
      { scale: 'workers', stroke: () => css('--series-2'), width: 2, points: { show: false } },
    ],
  }, data, element);

  window.addEventListener('themechange', () => plot.redraw(false));
  new ResizeObserver(() => plot.setSize({ width: element.clientWidth, height: element.clientHeight })).observe(element);

  return {
    push(time, megabytes, workers) {
      data[0].push(time);
      data[1].push(megabytes);
      data[2].push(workers);
      if (data[0].length > WINDOW) {
        data.forEach((column) => column.shift());
      }
      plot.setData(data);
    },
  };
}

export function startLive() {
  // the numbers work without the charts: if the chart library did not load, say so and go on
  const hasCharts = Boolean(window.uPlot);
  if (!hasCharts) report('charts', false);
  const none = { push() {} };
  const line = (id, formatValue, unit) => (hasCharts ? chart(document.getElementById(id), '--accent', formatValue, unit) : none);

  const rpsChart = line('chart-rps', formatInteger, 'requests /s');
  const p99Chart = line('chart-p99', formatMicroseconds);
  const rps = document.getElementById('kpi-rps');
  const p99 = document.getElementById('kpi-p99');
  const total = document.getElementById('kpi-total');

  const sps = line('chart-sps', formatInteger, 'statements /s');
  const importP95 = line('chart-import-p95', formatMilliseconds);
  const kpiSps = document.getElementById('kpi-sps');
  const waiting = document.getElementById('kpi-waiting');
  const retries = document.getElementById('kpi-retries');

  const gcChart = line('chart-gc', formatInteger, 'cycles /s');
  const workersChart = hasCharts ? dualChart(document.getElementById('chart-workers')) : none;
  const kpiMemory = document.getElementById('kpi-memory');
  const kpiBusy = document.getElementById('kpi-busy');
  const kpiGc = document.getElementById('kpi-gc');
  const gcNote = document.getElementById('gc-note');

  // the importers' side: what they book, how many ZIPs wait, how often the database ended a transaction
  poll(async () => {
    try {
      const summary = await getJson('/api/import/summary');
      report('summary', true);
      if (summary.statements_per_second !== null) {
        animateNumber(kpiSps, summary.statements_per_second, formatInteger);
        sps.push(summary.at, summary.statements_per_second);
      }
      if (summary.import_p95_milliseconds !== null) importP95.push(summary.at, summary.import_p95_milliseconds);
      animateNumber(waiting, (summary.batches?.queued ?? 0) + (summary.batches?.running ?? 0), formatInteger);
      if (summary.deadlock_retries !== null) animateNumber(retries, summary.deadlock_retries, formatInteger);
    } catch {
      report('summary', false, 'api'); // keep the last numbers, and say they are old
    }
  }, 2000);

  poll(async () => {
    try {
      const stats = await getJson('/api/stats');
      report('stats', true);
      if (stats.requests_per_second !== null) {
        animateNumber(rps, stats.requests_per_second, formatInteger);
        rpsChart.push(stats.at, stats.requests_per_second);
      }
      if (stats.p99_microseconds !== null) {
        animateNumber(p99, stats.p99_microseconds, formatMicroseconds);
        p99Chart.push(stats.at, stats.p99_microseconds);
      }
      if (stats.requests_total !== null) {
        animateNumber(total, stats.requests_total, formatInteger);
      }
      if (stats.gc_runs_total !== null) {
        animateNumber(kpiGc, stats.gc_runs_total, formatInteger);
        gcNote.hidden = stats.gc_runs_total > 0; // an empty chart is a result, not a fault: say why
      }
      if (stats.gc_cycles_per_second !== null) gcChart.push(stats.at, stats.gc_cycles_per_second);
      if (stats.memory_bytes !== null) animateNumber(kpiMemory, stats.memory_bytes, formatBytes);
      if (stats.busy_workers !== null) animateNumber(kpiBusy, stats.busy_workers, formatInteger);
      if (stats.memory_bytes !== null && stats.busy_workers !== null) {
        workersChart.push(stats.at, stats.memory_bytes / 1048576, stats.busy_workers);
      }
    } catch {
      report('stats', false, 'api');
    }
  }, 2000);
}
