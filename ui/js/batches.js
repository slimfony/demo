import { getJson, poll } from './api.js';

const VIA = { jwt: 'token', public: 'sandbox' };
const OUTCOME = { queued: 'waiting', running: 'importing', done: 'booked', failed: 'rolled back' };

function cell(text, className = '') {
  const td = document.createElement('td');
  td.textContent = text;
  if (className) td.className = className;
  return td;
}

function seconds(from, to) {
  if (!from || !to) return '–';
  const ms = new Date(to.replace(' ', 'T')) - new Date(from.replace(' ', 'T'));
  return Number.isFinite(ms) ? `${Math.max(0, Math.round(ms))} ms` : '–';
}

export function startBatches() {
  const body = document.getElementById('batch-rows');
  poll(async () => {
    try {
      const { batches } = await getJson('/api/batches?limit=15');
      body.replaceChildren(...batches.map((b) => {
        const row = document.createElement('tr');
        const problems = 'done' === b.status && b.statements_failed > 0;
        row.append(
          cell(b.id.slice(0, 8), 'mono'),
          cell(VIA[b.source] ?? b.source),
          cell(b.mode),
          cell(problems ? 'booked, with problems' : OUTCOME[b.status] ?? b.status, `outcome ${'failed' === b.status ? 'bad' : problems ? 'warn' : ''}`),
          cell(`${b.statements_ok} / ${b.statements_failed}`),
          cell(seconds(b.started_at, b.finished_at)),
        );
        return row;
      }));
    } catch {
      // the status line already says when the API is down; keep the last rows
    }
  }, 3000);
}
