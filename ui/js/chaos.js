// Chaos buttons: break something on purpose while an import runs, to show that every ZIP still ends up booked or failed on record.
// They break things for every visitor, so the first click only arms the button; the second one does it.
import { setBusy } from './controls.js';
import { on } from './events.js';

const ARM_MS = 4000; // an armed button disarms itself after this long
const OFF = 'The chaos switches are turned off on this server, so these buttons do nothing.';
const ACTIONS = {
  'chaos-kill': { path: '/api/chaos/kill-importer', confirm: 'Kill an importer for everyone?', done: () => 'An importer will die the next time it takes a batch, in the middle of it. The batch it was working on shows as importing for about a minute, then another importer takes it again. Watch the log.' },
  'chaos-cut': { path: '/api/chaos/cut-connections', confirm: 'Cut the connections for everyone?', done: (body) => `${body.terminated} database connections were terminated. The services reconnect and go on.` },
};

export function setupChaos() {
  const note = document.getElementById('chaos-note');
  const pending = document.getElementById('chaos-pending');
  const buttons = Object.keys(ACTIONS).map((id) => document.getElementById(id));
  let waiting = 0; // kill tokens queued from this page that no importer has taken yet
  let off = false;

  const showPending = () => { pending.textContent = waiting > 0 ? `Kills waiting for an importer to take a batch: ${waiting}.` : ''; };
  // an importer that dies says so in the log: that token is used up
  on('log', (data) => {
    if (waiting > 0 && 'string' === typeof data.message && data.message.startsWith('Chaos: this importer is being killed')) {
      waiting -= 1;
      showPending();
    }
  });

  for (const [id, action] of Object.entries(ACTIONS)) {
    const button = document.getElementById(id);
    const text = button.querySelector('span');
    const original = text.textContent;
    let timer = 0;
    const disarm = () => {
      clearTimeout(timer);
      if (button.dataset.armed) text.textContent = original;
      delete button.dataset.armed;
    };

    button.addEventListener('click', async () => {
      if (!button.dataset.armed) {
        button.dataset.armed = 'true';
        text.textContent = action.confirm;
        timer = setTimeout(disarm, ARM_MS);
        return;
      }
      disarm();
      setBusy(button, 'Working…');
      note.textContent = '';
      try {
        // the token lives in memory for this one request
        const token = (await (await fetch('/api/demo-token?kind=valid', { method: 'POST', cache: 'no-store' })).json()).token;
        const response = await fetch(action.path, { method: 'POST', headers: { Authorization: `Bearer ${token}` } });
        if (404 === response.status) {
          off = true; // the server has no chaos: there is nothing to press
          note.textContent = OFF;
        } else if (!response.ok) {
          note.textContent = `Refused (HTTP ${response.status}).`;
        } else {
          if ('chaos-kill' === id) {
            waiting += 1;
            showPending();
          }
          note.textContent = action.done(await response.json());
        }
      } catch {
        note.textContent = 'The server did not answer.';
      } finally {
        setBusy(button, null);
        for (const other of buttons) {
          other.disabled = off;
          other.title = off ? OFF : '';
        }
      }
    });
  }
}
