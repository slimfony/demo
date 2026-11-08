import { getJson, poll } from './api.js';
import { animateNumber, formatInteger, formatMicroseconds } from './format.js';

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

export function startLive() {
  const rpsChart = chart(document.getElementById('chart-rps'), '--accent', (v) => formatInteger(v));
  const p95Chart = chart(document.getElementById('chart-p95'), '--accent', (v) => formatInteger(v));
  const rps = document.getElementById('kpi-rps');
  const p95 = document.getElementById('kpi-p95');
  const total = document.getElementById('kpi-total');

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
    } catch {
      // keep the last numbers: the status pills tell whether the api is reachable
    }
  }, 2000);
}
