import { getJson } from './api.js';
import { setBusy, values } from './controls.js';
import { on } from './events.js';
import { EMPTY, NOT_AVAILABLE, animateNumber, formatDuration, formatInteger, formatRate } from './format.js';
import { createTiles } from './tiles.js';
import { STATES, describeStatus } from './words.js';

const POLL_MS = 1000; // how often the page asks the database where the run stands
const STALL_MS = 150_000; // no progress for this long: stop waiting (a killed importer's batch comes back after about a minute)
const RESULT_ICONS = { done: 'shield-check', failed: 'circle-x', stalled: 'clock' };

const $ = (id) => document.getElementById(id);
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

function show(id, value) {
  animateNumber($(id), value, formatInteger, 250);
}

function showNotAvailable(id) {
  $(id).textContent = NOT_AVAILABLE;
  $(id).dataset.value = '0';
}

export function setupRun() {
  const tiles = createTiles($('run-tiles'));
  const form = $('run-form');
  const button = $('run-start');
  const result = $('run-result');
  let run = null; // the run in progress

  const state = () => ({
    accepted: 0, refused: 0, total: 0, finished: 0, booked: 0, rejected: 0, rolledBack: 0,
    workers: new Set(), refusals: new Map(), startedAt: 0, finishedAt: 0, progressAt: 0, uploaded: false,
  });

  const say = (text) => { $('run-status').textContent = text; }; // for screen readers: only when the state changes
  const note = (text) => { $('run-note').textContent = text; };

  function render(s) {
    $('p-uploaded').textContent = formatInteger(s.accepted + s.refused);
    $('p-total').textContent = formatInteger(s.total);
    $('p-imported').textContent = formatInteger(s.finished - s.refused);
    $('p-refused-part').hidden = 0 === s.refused;
    $('p-refused').textContent = formatInteger(s.refused);
    const percent = s.total ? Math.round((s.finished / s.total) * 100) : 0;
    $('run-bar').style.width = `${percent}%`;
    $('run-progress').setAttribute('aria-valuenow', String(percent));
    show('s-booked', s.booked);
    show('s-rejected', s.rejected);
    show('s-rolled', s.rolledBack);

    // the state of the tiles as text: colour alone says nothing to some readers
    const counts = tiles.counts();
    const summary = Object.keys(STATES).filter((name) => counts[name] > 0).map((name) => `${formatInteger(counts[name])} ${STATES[name]}`).join(' · ');
    $('tile-summary').textContent = summary;
    $('run-tiles').setAttribute('aria-label', `One tile for every ZIP file, coloured by what happened to it${summary ? `: ${summary}` : ''}`);
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
    const refusals = new Map();
    for (const item of items) {
      tiles.fromItem(item);
      if (202 !== item.http_status) {
        refused += 1;
        refusals.set(item.http_status || 0, (refusals.get(item.http_status || 0) ?? 0) + 1);
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
    Object.assign(run, { finished, booked, rejected, rolledBack, workers, refusals, refused, accepted: items.length - refused });
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
    note('');
    tiles.reset(zips);
    setBusy(button, 'Importing…');
    run.startedAt = performance.now();
    run.progressAt = run.startedAt;
    show('s-rate', 0); // the numbers of the run before must not show in this one
    show('s-retries', 0);
    render(run);
    say('Import started.');
  }

  function idle() {
    run = null;
    setBusy(button, null);
  }

  /** The import did not start: say why and give the form back. */
  function abort(message) {
    idle();
    tiles.reset(0);
    render(state());
    say('');
    note(message);
  }

  /** Follows the run that is going on until every ZIP is finished (or nothing moves for a long time), then shows the result. */
  async function follow(before) {
    if (!before) showNotAvailable('s-retries'); // after a reload there is no starting point to count from
    const rates = setInterval(async () => {
      const summary = await snapshot();
      if (summary?.statements_per_second != null) show('s-rate', Math.round(summary.statements_per_second));
      if (before && summary?.deadlock_retries != null) show('s-retries', Math.max(0, Math.round(summary.deadlock_retries - (before.deadlock_retries ?? 0))));
    }, 1000);

    try {
      let reachable = true;
      while (true) {
        reachable = await poll().then(() => true, () => false);
        if (run.finished >= run.total && run.uploaded) break;
        if (performance.now() - run.progressAt > STALL_MS) break;
        await sleep(POLL_MS);
      }
      const outcome = run.finished >= run.total && run.uploaded ? 'done' : reachable ? 'stalled' : 'unreachable';
      const seconds = ((run.finishedAt || performance.now()) - run.startedAt) / 1000;
      await sleep(2500); // Prometheus scrapes every two seconds: let it catch up before the retry count is read
      finish(run, seconds, before, await snapshot(), outcome);
    } finally {
      clearInterval(rates);
      idle();
    }
  }

  async function start({ zips, faults, mode, accounts, lockOrder }) {
    busy(zips); // at once, so that a second click cannot start a second run
    let response;
    try {
      response = await fetch('/api/import/runs', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ zips, faults, mode, accounts, lock_order: lockOrder }),
      });
    } catch {
      abort(`The import was not started. ${describeStatus(0)}.`);
      return;
    }
    if (409 === response.status) {
      // someone else's run is going on: show it instead
      idle();
      if (!(await attach())) abort('Another import is going on, but this page could not join it. Try again in a moment.');
      return;
    }
    if (!response.ok) {
      abort(`The import was not started. ${describeStatus(response.status)}.`);
      return;
    }
    await follow(await snapshot());
  }

  /** A page that was just opened or reloaded joins the run that is going on, if there is one. Returns whether there was one. */
  async function attach() {
    const { run: serverRun, items } = await getJson('/api/import/runs/latest');
    if (!serverRun || run) return false;
    const settled = items.length === serverRun.zips && items.every((item) => 202 !== item.http_status || 'done' === item.status || 'failed' === item.status);
    if ('uploaded' === serverRun.status && settled) return false; // nothing is going on
    if (Date.now() / 1000 - serverRun.created_at > 900) return false; // an abandoned run
    busy(serverRun.zips);
    await follow(null);

    return true;
  }

  function finish(s, seconds, before, after, outcome) {
    const delta = (key) => (after && before ? Math.max(0, (after.batches?.[key] ?? 0) - (before.batches?.[key] ?? 0)) : null);
    const reasons = after?.rejected_by_reason ?? {};
    const rejected = Object.entries(reasons)
      .map(([reason, count]) => [reason, count - (before?.rejected_by_reason?.[reason] ?? 0)])
      .filter(([, count]) => count > 0);
    const retries = after?.deadlock_retries != null && before ? Math.max(0, Math.round(after.deadlock_retries - (before.deadlock_retries ?? 0))) : null;
    const time = formatDuration(seconds);
    const refusals = [...s.refusals].map(([status, count]) => `${describeStatus(status)}: ${formatInteger(count)}`).join('; ');

    // what happened, in words, an icon and a colour: a run that failed or stalled must not read like a success
    let title = `Done in ${time}`;
    let problem = s.refused > 0 ? `${formatInteger(s.refused)} ZIPs were refused at the door. ${refusals}.` : '';
    let icon = 'done';
    if ('unreachable' === outcome) {
      title = `Lost contact with the API after ${time}`;
      problem = `The page could not read the state of the run, so it does not know what happened to the other ${formatInteger(s.total - s.finished)} ZIPs.`;
      icon = 'failed';
    } else if ('stalled' === outcome) {
      title = `Stalled after ${time}: ${formatInteger(s.total - s.finished)} of ${formatInteger(s.total)} ZIPs not finished`;
      problem = `Nothing moved for ${STALL_MS / 1000} s. The importers may be down; the log may say why. ${problem}`.trim();
      icon = 'stalled';
    } else if (s.total > 0 && s.refused === s.total) {
      title = 'Failed: every ZIP was refused, nothing was imported';
      icon = 'failed';
    }

    result.classList.toggle('bad', 'done' !== icon);
    $('r-icon').setAttribute('href', `/vendor/lucide/sprite.svg#${RESULT_ICONS[icon]}`);
    $('r-title').textContent = title;
    $('r-problem').textContent = problem;
    $('r-zips').textContent = seconds > 0 ? `${formatInteger(s.accepted)} accepted, ${formatRate(s.accepted / seconds)}` : EMPTY;
    $('r-booked').textContent = formatInteger(s.booked);
    $('r-statements-rate').textContent = seconds > 0 ? formatRate(s.booked / seconds) : EMPTY;
    $('r-entries').textContent = null === delta('entries_booked') ? NOT_AVAILABLE : formatInteger(delta('entries_booked'));
    $('r-retries').textContent = null === retries ? NOT_AVAILABLE : formatInteger(retries);
    $('r-importers').textContent = formatInteger(s.workers.size);
    $('r-rolled').textContent = formatInteger(s.rolledBack);
    $('r-refused').textContent = formatInteger(s.refused);
    $('r-refused').className = s.refused > 0 ? 'bad' : '';
    $('r-reasons').replaceChildren(...rejected.map(([reason, count]) => {
      const item = document.createElement('li');
      item.textContent = `${reason.replaceAll('_', ' ')}: ${formatInteger(count)}`;
      return item;
    }));
    $('r-reasons-title').hidden = 0 === rejected.length;
    result.hidden = false;
    result.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    say(`${title}.`);
  }

  form.addEventListener('submit', (event) => {
    event.preventDefault();
    if (run) return;
    const { zips, faults, mode, accounts, lock_order: lockOrder } = values(form);
    start({ zips: Number(zips), faults: Number(faults), mode, accounts: Number(accounts), lockOrder }).catch(() => abort('The import stopped unexpectedly. Reload the page to look at it again.'));
  });

  // some choices rule others out: the dependent control is set and disabled, and the hint says why
  const hint = $('run-hint');
  function sync() {
    const { mode, lock_order: lockOrder, accounts } = values(form);
    const strict = 'strict' === mode;
    form.elements.lock_order.disabled = strict;
    if (strict) form.elements.lock_order.value = 'sorted';
    if (strict) hint.textContent = 'Rolling back the whole ZIP always locks the accounts in sorted order, so there are no deadlocks.';
    else hint.textContent = 'sorted' === lockOrder && '6' === accounts ? 'With a sorted lock order, high contention makes the importers wait for each other but causes no deadlocks.' : '';
  }
  form.addEventListener('change', sync);
  sync();

  attach().catch(() => {});
}
