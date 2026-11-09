import { startBatches } from './batches.js';
import { setupChaos } from './chaos.js';
import { getJson } from './api.js';
import { on, startEvents } from './events.js';
import { formatInteger, formatMilliseconds } from './format.js';
import { startLive } from './live.js';
import { setupMenus } from './menu.js';
import { setupPresent } from './present.js';
import { setupRun } from './run.js';
import { startRuntime } from './runtime.js';
import { startStatus } from './status.js';
import { setupTour } from './tour.js';
import { setupUpload } from './upload.js';

const POLL_MS = 500; // how often the page asks where the run stands

// "Try it": the server sends the requests (the generator service), so a reload of the page does not stop the run: the page joins it again.
function setupPush() {
  const form = document.getElementById('push-form');
  const button = document.getElementById('push-start');
  const label = button.querySelector('span');
  const bar = document.getElementById('push-bar');
  const progress = document.getElementById('push-progress');
  const results = document.getElementById('push-results');
  const cell = (id) => document.getElementById(id);
  let following = false;

  function show(run) {
    const percent = run.requests ? Math.round((run.sent / run.requests) * 100) : 0;
    bar.style.width = `${percent}%`;
    progress.setAttribute('aria-valuenow', String(percent));
  }

  function showResult(run) {
    cell('res-done').textContent = formatInteger(run.sent);
    cell('res-rps').textContent = run.seconds > 0 ? `${formatInteger(run.sent / run.seconds)} /s` : '–';
    cell('res-p50').textContent = formatMilliseconds(run.p50_ms);
    cell('res-p95').textContent = formatMilliseconds(run.p95_ms);
    cell('res-p99').textContent = formatMilliseconds(run.p99_ms);
    cell('res-errors').textContent = formatInteger(run.errors);
    results.hidden = false;
  }

  async function follow() {
    if (following) return;
    following = true;
    button.disabled = true;
    label.textContent = 'Sending…';
    results.hidden = true;
    try {
      while (true) {
        const { run } = await getJson('/api/load/runs/latest').catch(() => ({ run: null }));
        if (run) {
          show(run);
          if ('done' === run.status) {
            showResult(run);
            break;
          }
        }
        await new Promise((resolve) => setTimeout(resolve, POLL_MS));
      }
    } finally {
      following = false;
      label.textContent = 'Send requests';
      button.disabled = false;
    }
  }

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const { total, endpoint } = Object.fromEntries(new FormData(form));
    button.disabled = true;
    const response = await fetch('/api/load/runs', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ requests: Number(total), endpoint }),
    }).catch(() => null);
    if (response && (response.ok || 409 === response.status)) { // 409: another run is going on, follow that one
      await follow();
    } else {
      button.disabled = false;
    }
  });

  // a page that was just opened or reloaded joins a run that is still going
  getJson('/api/load/runs/latest')
    .then(({ run, at }) => {
      if (run && 'done' !== run.status && at - run.created_at < 300) return follow();
      return null;
    })
    .catch(() => {});
}

startStatus(document.getElementById('status'));
startRuntime();
if (window.uPlot) {
  startLive();
} else {
  window.addEventListener('load', startLive, { once: true });
}
setupPush();
setupUpload();
setupMenus();
// the log view is an extra: if a browser extension blocks its script, the rest of the page must still work
import('./activity.js').then(({ setupLogView }) => on('log', setupLogView())).catch(() => {});
setupRun();
setupChaos();
startEvents();
startBatches();
setupPresent();
setupTour();
