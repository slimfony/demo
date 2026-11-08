import { formatInteger, formatMilliseconds } from './format.js';
import { startLive } from './live.js';
import { runLoad } from './loadgen.js';
import { startRuntime } from './runtime.js';
import { startStatus } from './status.js';
import { setupUpload } from './upload.js';

const CONCURRENCY = 6; // what a browser allows per host over HTTP/1.1

function setupPush() {
  const form = document.getElementById('push-form');
  const button = document.getElementById('push-start');
  const label = button.querySelector('span');
  const bar = document.getElementById('push-bar');
  const progress = document.getElementById('push-progress');
  const results = document.getElementById('push-results');
  const cell = (id) => document.getElementById(id);

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const { total, url } = Object.fromEntries(new FormData(form));

    button.disabled = true;
    label.textContent = 'Sending…';
    results.hidden = true;

    const outcome = await runLoad({
      url,
      total: Number(total),
      concurrency: CONCURRENCY,
      onProgress: (done, all) => {
        const percent = Math.round((done / all) * 100);
        bar.style.width = `${percent}%`;
        progress.setAttribute('aria-valuenow', String(percent));
      },
    });

    cell('res-done').textContent = formatInteger(outcome.done);
    cell('res-rps').textContent = `${formatInteger(outcome.rps)} /s`;
    cell('res-p50').textContent = formatMilliseconds(outcome.p50);
    cell('res-p95').textContent = formatMilliseconds(outcome.p95);
    cell('res-p99').textContent = formatMilliseconds(outcome.p99);
    cell('res-errors').textContent = formatInteger(outcome.errors);
    results.hidden = false;

    label.textContent = 'Send requests';
    button.disabled = false;
  });
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
