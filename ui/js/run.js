import { getJson } from './api.js';
import { on } from './events.js';
import { animateNumber, formatInteger } from './format.js';
import { createTiles } from './tiles.js';

const CONCURRENCY = 6; // what a browser allows per host over HTTP/1.1
const SANDBOX_EVERY = 20; // one ZIP in twenty goes through the anonymous sandbox, the rest with a token
const TOKEN_REFRESH_MS = 4 * 60 * 1000; // a demo token lives five minutes
const RECONCILE_MS = 1000; // how often the page asks the database where every batch of the run stands
const STALL_MS = 60_000; // no progress for this long: stop waiting

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

  const state = () => ({
    accepted: 0, refused: 0, total: 0, finished: 0, booked: 0, rejected: 0, rolledBack: 0,
    workers: new Set(), startedAt: 0, finishedAt: 0, progressAt: 0, since: 0,
  });

  function render(s) {
    $('p-uploaded').textContent = formatInteger(s.accepted + s.refused);
    $('p-total').textContent = formatInteger(s.total);
    $('p-imported').textContent = formatInteger(s.finished);
    $('run-bar').style.width = `${s.total ? Math.round((s.finished / s.total) * 100) : 0}%`;
    show('s-booked', s.booked);
    show('s-rejected', s.rejected);
    show('s-rolled', s.rolledBack);
  }

  // Events make the tiles move at once. They are only a courtesy: one can be lost on the way, so they never count anything.
  on('batch', (event) => {
    if (!run || !event.id) return;
    if (!tiles.update(event)) setTimeout(() => tiles.update(event), 300); // it can come before the answer to the upload
  });

  // The database is the truth: every second the page asks where each batch of this run stands, paints the tiles from
  // that and counts from it. A lost event, a dropped connection or a reload of the hub change nothing.
  async function reconcile() {
    const { batches } = await getJson(`/api/import/run?since=${run.since}`);
    let finished = 0;
    let booked = 0;
    let rejected = 0;
    let rolledBack = 0;
    const workers = new Set();
    for (const batch of batches) {
      if (!tiles.apply(batch)) continue; // another visitor's batch
      if ('done' === batch.status || 'failed' === batch.status) {
        finished += 1;
        booked += batch.statements_ok;
        rejected += batch.statements_failed;
        rolledBack += batch.statements_rolled_back;
      }
      if (batch.worker) workers.add(batch.worker);
    }
    finished += run.refused; // nothing will be imported for the refused ones
    if (finished !== run.finished) {
      run.progressAt = performance.now();
      if (finished === run.total) run.finishedAt = performance.now();
    }
    Object.assign(run, { finished, booked, rejected, rolledBack, workers });
    render(run);
  }

  async function upload(index, mode, faults, token, seed) {
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
      run.accepted += 1;
    } else {
      tiles.refused(index, response.status, !sandbox);
      run.refused += 1;
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
    button.disabled = true;
    button.querySelector('span').textContent = 'Importing…';

    const before = await snapshot();
    // the moment the run starts, by the server's clock, so the two clocks need not agree
    run.since = (before?.at ?? Math.floor(Date.now() / 1000)) - 2;
    run.startedAt = performance.now();
    run.progressAt = run.startedAt;
    render(run);
    let token = await newToken();
    let tokenAt = performance.now();
    const seedBase = Math.floor(Date.now() % 900_000_000) + 1;
    let next = 0;

    const worker = async () => {
      while (next < zips) {
        const index = next++;
        if (performance.now() - tokenAt > TOKEN_REFRESH_MS) { token = await newToken(); tokenAt = performance.now(); }
        try {
          await upload(index, mode, faults, () => token, seedBase + index);
        } catch {
          tiles.refused(index, 0, index % SANDBOX_EVERY !== 0);
          run.refused += 1;
          render(run);
        }
      }
    };

    // while the ZIPs go up and are imported, the page keeps in step with the database, and Prometheus says how fast
    const timer = setInterval(() => reconcile().catch(() => {}), RECONCILE_MS);
    const rates = setInterval(async () => {
      const summary = await snapshot();
      if (summary?.statements_per_second != null) show('s-rate', Math.round(summary.statements_per_second));
      if (before && summary?.deadlock_retries != null) show('s-retries', Math.max(0, Math.round(summary.deadlock_retries - (before.deadlock_retries ?? 0))));
    }, 1000);

    await Promise.all(Array.from({ length: CONCURRENCY }, worker));
    while (run.finished < run.total && performance.now() - run.progressAt < STALL_MS) await sleep(250);
    clearInterval(timer);
    clearInterval(rates);
    await reconcile().catch(() => {});

    const seconds = ((run.finishedAt || performance.now()) - run.startedAt) / 1000;
    await sleep(2500); // Prometheus scrapes every two seconds: let it catch up before the retry count is read
    finish(run, seconds, before, await snapshot());
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
    const stalled = s.finished < s.total;

    $('r-time').textContent = `${seconds.toFixed(1)} s`;
    $('r-zips').textContent = seconds > 0 ? `${formatInteger(s.accepted / seconds)} ZIPs per second${stalled ? `, ${formatInteger(s.total - s.finished)} not finished` : ''}` : '';
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
