import { getJson } from './api.js';
import { on } from './events.js';
import { animateNumber, formatInteger } from './format.js';
import { createTiles } from './tiles.js';

const POLL_MS = 1000; // how often the page asks the database where the run stands
const STALL_MS = 150_000; // no progress for this long: stop waiting (a killed importer's batches come back after about a minute)

const $ = (id) => document.getElementById(id);
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

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
    workers: new Set(), startedAt: 0, finishedAt: 0, progressAt: 0, uploaded: false,
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

  // The database is the truth: the server uploads the ZIPs of a run (the generator service), and the page asks every second
  // where every ZIP stands, paints the tiles from that and counts from it. Events only make the tiles move a little sooner.
  // Because the run lives on the server, a reload of the page changes nothing: it finds the run and follows it again.
  async function poll() {
    const { run: serverRun, items, at } = await getJson('/api/import/runs/latest');
    if (!serverRun) return null;
    run.uploaded = 'uploaded' === serverRun.status;
    run.startedAt = performance.now() - Math.max(0, at - serverRun.created_at) * 1000; // by the server's clock, so the two clocks need not agree
    let finished = 0;
    let booked = 0;
    let rejected = 0;
    let rolledBack = 0;
    let refused = 0;
    const workers = new Set();
    for (const item of items) {
      tiles.fromItem(item);
      if (202 !== item.http_status) {
        refused += 1;
        finished += 1; // nothing will be imported for a refused ZIP
        continue;
      }
      if ('done' === item.status || 'failed' === item.status) {
        finished += 1;
        booked += item.statements_ok;
        rejected += item.statements_failed;
        rolledBack += item.statements_rolled_back;
      }
      if (item.worker) workers.add(item.worker);
    }
    if (finished !== run.finished || items.length !== run.accepted + run.refused) {
      run.progressAt = performance.now();
    }
    if (finished === run.total && !run.finishedAt) run.finishedAt = performance.now();
    Object.assign(run, { finished, booked, rejected, rolledBack, workers, refused, accepted: items.length - refused });
    render(run);

    return serverRun;
  }

  async function snapshot() {
    return getJson('/api/import/summary').catch(() => null);
  }

  function busy(zips) {
    run = state();
    run.total = zips;
    result.hidden = true;
    tiles.reset(zips);
    button.disabled = true;
    button.querySelector('span').textContent = 'Importing…';
    run.startedAt = performance.now();
    run.progressAt = run.startedAt;
    render(run);
  }

  function idle() {
    run = null;
    button.disabled = false;
    button.querySelector('span').textContent = 'Start import';
  }

  /** Follows the run that is going on until every ZIP is finished (or nothing moves for a long time), then shows the result. */
  async function follow(before) {
    const rates = setInterval(async () => {
      const summary = await snapshot();
      if (summary?.statements_per_second != null) show('s-rate', Math.round(summary.statements_per_second));
      if (before && summary?.deadlock_retries != null) show('s-retries', Math.max(0, Math.round(summary.deadlock_retries - (before.deadlock_retries ?? 0))));
    }, 1000);

    try {
      while (true) {
        await poll().catch(() => {});
        if (run.finished >= run.total && run.uploaded) break;
        if (performance.now() - run.progressAt > STALL_MS) break;
        await sleep(POLL_MS);
      }
      const seconds = ((run.finishedAt || performance.now()) - run.startedAt) / 1000;
      await sleep(2500); // Prometheus scrapes every two seconds: let it catch up before the retry count is read
      finish(run, seconds, before, await snapshot());
    } finally {
      clearInterval(rates);
      idle();
    }
  }

  async function start({ zips, faults, mode, accounts, lockOrder }) {
    const response = await fetch('/api/import/runs', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ zips, faults, mode, accounts, lock_order: lockOrder }),
    });
    if (409 === response.status) {
      // someone else's run is going on: show it instead
      await attach();
      return;
    }
    if (!response.ok) throw new Error('The run was not accepted.');
    const before = await snapshot();
    busy(zips);
    await follow(before);
  }

  /** A page that was just opened or reloaded joins the run that is going on, if there is one. */
  async function attach() {
    const { run: serverRun, items } = await getJson('/api/import/runs/latest');
    if (!serverRun || run) return;
    const settled = items.length === serverRun.zips && items.every((item) => 202 !== item.http_status || 'done' === item.status || 'failed' === item.status);
    if ('uploaded' === serverRun.status && settled) return; // nothing is going on
    if (Date.now() / 1000 - serverRun.created_at > 900) return; // an abandoned run
    busy(serverRun.zips);
    await follow(null);
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
    const { zips, faults, mode, accounts, lock_order: lockOrder } = Object.fromEntries(new FormData(form));
    start({ zips: Number(zips), faults: Number(faults), mode, accounts: Number(accounts), lockOrder }).catch(idle);
  });

  attach().catch(() => {});
}
