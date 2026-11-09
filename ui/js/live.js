import { getJson, poll } from './api.js';
import { animateNumber, formatBytes, formatInteger, formatMicroseconds } from './format.js';

const WINDOW = 60; // samples kept: two minutes at one sample every two seconds
const css = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim();
const FONT = '12px -apple-system, Inter, sans-serif';

function chart(element, colorVar, formatValue) {
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
      x: { time: true, range: () => { const now = Math.floor(Date.now() / 1000); return [now - 118, now]; } },
      y: { range: (self, min, max) => [0, Math.max(max * 1.15, 1)] },
    },
    axes: [
      { stroke: muted, grid: { show: false }, ticks: { show: false }, font: FONT },
      { stroke: muted, grid: { stroke: grid, width: 1 }, ticks: { show: false }, size: 56, font: FONT, values: (self, ticks) => ticks.map(formatValue) },
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

// Two series with their own scales (left: memory in MB, right: busy workers), on one time axis.
function dualChart(element) {
  const muted = () => css('--text-2');
  const grid = () => css('--line');
  const data = [[], [], []];
  const axis = (scale, side) => ({
    scale, side, stroke: muted, grid: { show: false }, ticks: { show: false }, size: 48, font: FONT,
    values: (self, ticks) => ticks.map((v) => formatInteger(v)),
  });
  const plot = new window.uPlot({
    width: element.clientWidth,
    height: element.clientHeight,
    legend: { show: false },
    cursor: { show: false },
    select: { show: false },
    scales: {
      x: { time: true, range: () => { const now = Math.floor(Date.now() / 1000); return [now - 118, now]; } },
      mb: { range: (self, min, max) => [0, Math.max(max * 1.15, 1)] },
      workers: { range: (self, min, max) => [0, Math.max(max * 1.15, 1)] },
    },
    axes: [
      { stroke: muted, grid: { show: false }, ticks: { show: false }, font: FONT },
      { ...axis('mb', 3), grid: { stroke: grid, width: 1 } },
      axis('workers', 1),
    ],
    series: [
      {},
      { scale: 'mb', stroke: () => css('--accent'), width: 2, points: { show: false } },
      { scale: 'workers', stroke: () => css('--tile-problems'), width: 2, points: { show: false } },
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
  const rpsChart = chart(document.getElementById('chart-rps'), '--accent', (v) => formatInteger(v));
  const p95Chart = chart(document.getElementById('chart-p95'), '--accent', (v) => formatInteger(v));
  const rps = document.getElementById('kpi-rps');
  const p95 = document.getElementById('kpi-p95');
  const total = document.getElementById('kpi-total');

  const sps = chart(document.getElementById('chart-sps'), '--accent', (v) => formatInteger(v));
  const importP95 = chart(document.getElementById('chart-import-p95'), '--accent', (v) => formatInteger(v));
  const kpiSps = document.getElementById('kpi-sps');
  const waiting = document.getElementById('kpi-waiting');
  const retries = document.getElementById('kpi-retries');

  const gcChart = chart(document.getElementById('chart-gc'), '--accent', (v) => formatInteger(v));
  const workersChart = dualChart(document.getElementById('chart-workers'));
  const kpiMemory = document.getElementById('kpi-memory');
  const kpiBusy = document.getElementById('kpi-busy');
  const kpiGc = document.getElementById('kpi-gc');

  // the importers' side: what they book, how many ZIPs wait, how often the database ended a transaction
  poll(async () => {
    try {
      const summary = await getJson('/api/import/summary');
      if (summary.statements_per_second !== null) {
        animateNumber(kpiSps, summary.statements_per_second, formatInteger);
        sps.push(summary.at, summary.statements_per_second);
      }
      if (summary.import_p95_milliseconds !== null) importP95.push(summary.at, summary.import_p95_milliseconds);
      animateNumber(waiting, (summary.batches?.queued ?? 0) + (summary.batches?.running ?? 0), formatInteger);
      if (summary.deadlock_retries !== null) animateNumber(retries, summary.deadlock_retries, formatInteger);
    } catch {
      // the status pills tell whether the api is reachable
    }
  }, 2000);

  poll(async () => {
    try {
      const stats = await getJson('/api/stats');
      if (stats.requests_per_second !== null) {
        animateNumber(rps, stats.requests_per_second, formatInteger);
        rpsChart.push(stats.at, stats.requests_per_second);
      }
      if (stats.p95_microseconds !== null) {
        animateNumber(p95, stats.p95_microseconds, formatMicroseconds);
        p95Chart.push(stats.at, stats.p95_microseconds);
      }
      if (stats.requests_total !== null) {
        animateNumber(total, stats.requests_total, formatInteger);
      }
      if (stats.gc_runs_total !== null) animateNumber(kpiGc, stats.gc_runs_total, formatInteger);
      if (stats.gc_cycles_per_second !== null) gcChart.push(stats.at, stats.gc_cycles_per_second);
      if (stats.memory_bytes !== null) animateNumber(kpiMemory, stats.memory_bytes, formatBytes);
      if (stats.busy_workers !== null) animateNumber(kpiBusy, stats.busy_workers, formatInteger);
      if (stats.memory_bytes !== null && stats.busy_workers !== null) {
        workersChart.push(stats.at, stats.memory_bytes / 1048576, stats.busy_workers);
      }
    } catch {
      // keep the last numbers: the status pills tell whether the api is reachable
    }
  }, 2000);
}
