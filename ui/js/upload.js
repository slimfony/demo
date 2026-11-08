// What the api answered, in words a visitor understands.
const OUTCOMES = {
  202: 'Accepted',
  400: 'Refused: the request was not valid',
  401: 'Refused: the token was not accepted',
  403: 'Refused: the token has no permission to import',
  413: 'Refused: the file is too large',
  422: 'Refused: the file is not an acceptable ZIP',
};

const SENDERS = {
  sandbox: 'Anonymous sandbox',
  valid: 'Valid token',
  expired: 'Expired token',
  bad_signature: 'Forged token',
  wrong_audience: 'Token for another service',
  missing_scope: 'Token without permission',
};

async function tokenFor(kind) {
  const response = await fetch(`/api/demo-token?kind=${kind}`, { method: 'POST', cache: 'no-store' });
  if (!response.ok) throw new Error('The identity provider did not answer.');
  return (await response.json()).token;
}

async function send(kind, mode) {
  const sample = await (await fetch('/api/sample.zip?files=10', { cache: 'no-store' })).blob();
  if ('sandbox' === kind) {
    return fetch('/public/drop', { method: 'POST', body: sample });
  }
  // the token lives in memory for this one request: it is never stored
  return fetch(`/api/imports?mode=${mode}`, { method: 'POST', body: sample, headers: { Authorization: `Bearer ${await tokenFor(kind)}` } });
}

function row(kind, status, detail) {
  const item = document.createElement('div');
  const label = document.createElement('dt');
  label.textContent = SENDERS[kind];
  const result = document.createElement('dd');
  result.className = 202 === status ? 'ok' : 'bad';
  result.textContent = `${OUTCOMES[status] ?? `Refused (${status})`}${detail}`;
  item.append(label, result);

  return item;
}

export function setupUpload() {
  const form = document.getElementById('upload-form');
  const button = document.getElementById('upload-start');
  const results = document.getElementById('upload-results');

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const { kind, mode } = Object.fromEntries(new FormData(form));
    button.disabled = true;
    try {
      const response = await send(kind, mode);
      const body = await response.json().catch(() => ({}));
      const detail = body.id ? `, batch ${body.id.slice(0, 8)}` : '';
      results.prepend(row(kind, response.status, detail));
    } catch {
      results.prepend(row(kind, 0, ''));
    } finally {
      button.disabled = false;
      results.hidden = false;
      while (results.children.length > 6) results.lastElementChild.remove();
    }
  });
}
