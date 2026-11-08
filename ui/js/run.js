import { getJson } from './api.js';
import { on } from './events.js';
import { animateNumber, formatInteger } from './format.js';
import { createTiles } from './tiles.js';

const CONCURRENCY = 6; // what a browser allows per host over HTTP/1.1
const SANDBOX_EVERY = 20; // one ZIP in twenty goes through the anonymous sandbox, the rest with a token
const TOKEN_REFRESH_MS = 4 * 60 * 1000; // a demo token lives five minutes

const $ = (id) => document.getElementById(id);
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function newToken() {
  const response = await fetch('/api/demo-token?kind=valid', { method: 'POST', cache: 'no-store' });
  if (!response.ok) throw new Error('The identity provider did not answer.');
  return (await response.json()).token;
}

function show(id, value) {
  animateNumber($(id), value, formatInteger, 250);
}

export function setupRun() {
  const tiles = createTiles($('run-tiles'));
  const form = $('run-form');
  const button = $('run-start');
  const result = $('run-result');
  let run = null; // the run in progress

  const state = () => ({ uploaded: 0, refused: 0, total: 0, finished: 0, booked: 0, rejected: 0, rolledBack: 0, workers: new Set(), startedAt: 0, lastEventAt: 0 });

  function render(s) {
    $('p-uploaded').textContent = formatInteger(s.uploaded + s.refused);
    $('p-total').textContent = formatInteger(s.total);
    $('p-imported').textContent = formatInteger(s.finished);
    $('run-bar').style.width = `${s.total ? Math.round((s.finished / s.total) * 100) : 0}%`;
    show('s-booked', s.booked);
    show('s-rejected', s.rejected);
    show('s-rolled', s.rolledBack);
  }

  on('batch', (event) => {
    if (!run || !event.id) return;
    // an event can arrive before the answer to the upload that created the batch: wait for it a moment
    const apply = () => {
      if (!tiles.update(event)) return false;
      if ('running' !== event.state) {
        run.finished += 1;
        run.booked += event.ok;
        run.rejected += event.failed;
        run.rolledBack += event.rolled_back;
        run.lastEventAt = performance.now();
      }
      run.workers.add(event.worker);
      render(run);
      return true;
    };
    if (!apply()) setTimeout(apply, 300);
  });

  async function upload(index, kind, mode, faults, token, seed) {
    const sample = await (await fetch(`/api/sample.zip?files=10&seed=${seed}&fault_rate=${faults}`, { cache: 'no-store' })).blob();
    const sandbox = index % SANDBOX_EVERY === 0;
    const response = await fetch(sandbox ? '/public/drop' : `/api/imports?mode=${mode}`, {
      method: 'POST',
      body: sample,
      headers: sandbox ? {} : { Authorization: `Bearer ${token()}` },
    });
    if (202 === response.status) {
      const body = await response.json();
      tiles.accepted(index, body.id, !sandbox);
      run.uploaded += 1;
    } else {
      tiles.refused(index, response.status, !sandbox);
      run.refused += 1;
      run.finished += 1; // nothing will be imported for this one
    }
    render(run);
  }

  async function snapshot() {
    return getJson('/api/import/summary').catch(() => null);
  }

  async function start({ zips, faults, mode }) {
    run = state();
    run.total = zips;
    result.hidden = true;
    tiles.reset(zips);
    render(run);
    button.disabled = true;
    button.querySelector('span').textContent = 'Importing…';

    const before = await snapshot();
    let token = await newToken();
    let tokenAt = performance.now();
    const seedBase = Math.floor(Date.now() % 900_000_000) + 1;
    let next = 0;
    run.startedAt = performance.now();

    const worker = async () => {
      while (next < zips) {
        const index = next++;
        if (performance.now() - tokenAt > TOKEN_REFRESH_MS) { token = await newToken(); tokenAt = performance.now(); }
        try {
          await upload(index, 'valid', mode, faults, () => token, seedBase + index);
        } catch {
          tiles.refused(index, 0, index % SANDBOX_EVERY !== 0);
          run.refused += 1;
          run.finished += 1;
          render(run);
        }
      }
    };

    // while the ZIPs go up, Prometheus is asked how fast the importers book statements
    const rates = setInterval(async () => {
      const summary = await snapshot();
      if (summary?.statements_per_second != null) show('s-rate', Math.round(summary.statements_per_second));
      if (before && summary?.deadlock_retries != null) show('s-retries', Math.max(0, Math.round(summary.deadlock_retries - (before.deadlock_retries ?? 0))));
    }, 1000);

    await Promise.all(Array.from({ length: CONCURRENCY }, worker));

    // every ZIP is up: wait until the importers have reported on all of them (or stopped reporting)
    while (run.finished < run.total && performance.now() - Math.max(run.lastEventAt, run.startedAt) < 45_000) await sleep(250);
    clearInterval(rates);

    const seconds = (Math.max(run.lastEventAt, run.startedAt) - run.startedAt) / 1000;
    await sleep(2500); // Prometheus scrapes every two seconds: let it catch up before the retry count is read
    const after = await snapshot();
    finish(run, seconds, before, after);
    run = null;
    button.disabled = false;
    button.querySelector('span').textContent = 'Start import';
  }

  function finish(s, seconds, before, after) {
    const delta = (key) => (after && before ? Math.max(0, (after.batches?.[key] ?? 0) - (before.batches?.[key] ?? 0)) : null);
    const reasons = after?.rejected_by_reason ?? {};
    const rejected = Object.entries(reasons)
      .map(([reason, count]) => [reason, count - (before?.rejected_by_reason?.[reason] ?? 0)])
      .filter(([, count]) => count > 0);

    $('r-time').textContent = `${seconds.toFixed(1)} s`;
    $('r-zips').textContent = seconds > 0 ? `${formatInteger(s.uploaded / seconds)} ZIPs per second` : '';
    $('r-booked').textContent = formatInteger(s.booked);
    $('r-statements-rate').textContent = seconds > 0 ? `${formatInteger(s.booked / seconds)} statements per second` : '';
    $('r-entries').textContent = null === delta('entries_booked') ? '–' : formatInteger(delta('entries_booked'));
    const retries = after?.deadlock_retries != null && before ? Math.max(0, Math.round(after.deadlock_retries - (before.deadlock_retries ?? 0))) : null;
    $('r-retries').textContent = null === retries ? '–' : formatInteger(retries);
    $('r-importers').textContent = formatInteger(s.workers.size);
    $('r-rolled').textContent = formatInteger(s.rolledBack);
    $('r-refused').textContent = formatInteger(s.refused);
    $('r-reasons').replaceChildren(...rejected.map(([reason, count]) => {
      const item = document.createElement('li');
      item.textContent = `${reason.replaceAll('_', ' ')}: ${formatInteger(count)}`;
      return item;
    }));
    $('r-reasons-title').hidden = 0 === rejected.length;
    result.hidden = false;
    result.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  form.addEventListener('submit', (event) => {
    event.preventDefault();
    if (run) return;
    const { zips, faults, mode } = Object.fromEntries(new FormData(form));
    start({ zips: Number(zips), faults: Number(faults), mode }).catch(() => {
      run = null;
      button.disabled = false;
      button.querySelector('span').textContent = 'Start import';
    });
  });
}
