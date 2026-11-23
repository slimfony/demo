import { getJson, poll } from './api.js';
import { report } from './connection.js';
import { EMPTY, formatInteger, formatMilliseconds } from './format.js';
import { MODES, SENDERS, STATES, stateOf } from './words.js';

function cell(text, className = '') {
  const td = document.createElement('td');
  td.textContent = text;
  if (className) td.className = className;
  return td;
}

function took(from, to) {
  if (!from || !to) return EMPTY;
  const ms = new Date(to.replace(' ', 'T')) - new Date(from.replace(' ', 'T'));
  return Number.isFinite(ms) ? formatMilliseconds(Math.max(0, ms)) : EMPTY;
}

function emptyRow() {
  const row = document.createElement('tr');
  const td = cell('No ZIP files yet. Start an import above and they appear here.', 'empty');
  td.colSpan = 6;
  row.append(td);

  return row;
}

export function startBatches() {
  const body = document.getElementById('batch-rows');
  poll(async () => {
    try {
      const { batches } = await getJson('/api/batches?limit=15');
      report('batches', true);
      body.replaceChildren(...(0 === batches.length ? [emptyRow()] : batches.map((b) => {
        const row = document.createElement('tr');
        const state = stateOf(b);
        row.append(
          cell(b.id.slice(0, 8), 'mono'),
          cell(SENDERS[b.source] ?? b.source),
          cell(MODES[b.mode] ?? b.mode),
          cell(STATES[state], `outcome ${'failed' === state ? 'bad' : 'problems' === state ? 'warn' : ''}`),
          cell(`${formatInteger(b.statements_ok)} / ${formatInteger(b.statements_failed)}`),
          cell(took(b.started_at, b.finished_at)),
        );
        return row;
      })));
    } catch {
      report('batches', false, 'api'); // keep the last rows, and say they are old
    }
  }, 3000);
}
