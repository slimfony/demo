import { values, withBusy } from './controls.js';
import { describeStatus } from './words.js';

const SENDERS = {
  sandbox: 'Anonymous sandbox',
  valid: 'Valid token',
  expired: 'Expired token',
  bad_signature: 'Forged token',
  wrong_audience: 'Token for another service',
  missing_scope: 'Token without permission',
};

// a failure whose message is meant for the visitor
const known = (message) => Object.assign(new Error(message), { known: true });

async function tokenFor(kind) {
  const response = await fetch(`/api/demo-token?kind=${kind}`, { method: 'POST', cache: 'no-store' });
  if (!response.ok) throw known('The identity provider did not answer.');
  return (await response.json()).token;
}

async function send(kind, mode) {
  const sampleResponse = await fetch('/api/sample.zip?files=10', { cache: 'no-store' });
  if (!sampleResponse.ok) throw known(`The API could not make a sample ZIP (HTTP ${sampleResponse.status}).`);
  const sample = await sampleResponse.blob();
  if ('sandbox' === kind) {
    return fetch('/public/drop', { method: 'POST', body: sample });
  }
  // the token lives in memory for this one request: it is never stored
  return fetch(`/api/imports?mode=${mode}`, { method: 'POST', body: sample, headers: { Authorization: `Bearer ${await tokenFor(kind)}` } });
}

function row(label, ok, text) {
  const item = document.createElement('div');
  const term = Object.assign(document.createElement('dt'), { textContent: label });
  const result = Object.assign(document.createElement('dd'), { className: ok ? 'ok' : 'bad', textContent: text });
  item.append(term, result);

  return item;
}

export function setupUpload() {
  const form = document.getElementById('upload-form');
  const button = document.getElementById('upload-start');
  const rotate = document.getElementById('upload-rotate');
  const results = document.getElementById('upload-results');

  function show(item) {
    results.prepend(item);
    results.hidden = false;
    while (results.children.length > 6) results.lastElementChild.remove();
  }

  rotate.addEventListener('click', () => withBusy(rotate, 'Rotating…', async () => {
    try {
      const response = await fetch('/api/demo-rotate', { method: 'POST', cache: 'no-store' });
      if (!response.ok) throw new Error('refused');
      const { kid } = await response.json();
      show(row('Identity provider', true, `Now signing with key ${kid.slice(0, 8)}, for every visitor. The next token carries it; the API fetches the new key list when it first sees it. Tokens signed before still work.`));
    } catch {
      show(row('Identity provider', false, 'The identity provider did not answer.'));
    }
  }));

  // the anonymous sandbox takes no mode: it always keeps the good ones
  const hint = document.getElementById('upload-hint');
  function sync() {
    const sandbox = 'sandbox' === form.elements.kind.value;
    form.elements.mode.disabled = sandbox;
    if (sandbox) form.elements.mode.value = 'partial';
    hint.textContent = sandbox ? 'The anonymous sandbox always keeps the good ones.' : '';
  }
  form.addEventListener('change', sync);
  sync();

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const { kind, mode } = values(form);
    await withBusy(button, 'Sending…', async () => {
      try {
        const response = await send(kind, mode);
        const body = await response.json().catch(() => ({}));
        show(row(SENDERS[kind], 202 === response.status, `${describeStatus(response.status)}${body.id ? `, batch ${body.id.slice(0, 8)}` : ''}`));
      } catch (error) {
        show(row(SENDERS[kind], false, error.known ? error.message : `${describeStatus(0)}.`));
      }
    });
  });
}
