// Chaos buttons: break something on purpose while an import runs, to show that every ZIP still ends up booked or failed on record.
const ACTIONS = {
  'chaos-kill': { path: '/api/chaos/kill-importer', done: () => 'An importer will die the next time it takes a batch, in the middle of it. The batch it was working on shows as importing for about a minute, then another importer takes it again. Watch the log.' },
  'chaos-cut': { path: '/api/chaos/cut-connections', done: (body) => `${body.terminated} database connections were terminated. The services reconnect and go on.` },
};

export function setupChaos() {
  const note = document.getElementById('chaos-note');

  for (const [id, action] of Object.entries(ACTIONS)) {
    const button = document.getElementById(id);
    button.addEventListener('click', async () => {
      button.disabled = true;
      note.textContent = '…';
      try {
        // the token lives in memory for this one request
        const token = (await (await fetch('/api/demo-token?kind=valid', { method: 'POST', cache: 'no-store' })).json()).token;
        const response = await fetch(action.path, { method: 'POST', headers: { Authorization: `Bearer ${token}` } });
        note.textContent = response.ok ? action.done(await response.json()) : 404 === response.status ? 'The chaos switches are turned off on this server.' : `Refused (HTTP ${response.status}).`;
      } catch {
        note.textContent = 'The server did not answer.';
      } finally {
        button.disabled = false;
      }
    });
  }
}
