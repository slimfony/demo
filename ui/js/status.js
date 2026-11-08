import { getJson, poll } from './api.js';

const NAMES = { api: 'API', database: 'Database', nats: 'NATS', prometheus: 'Prometheus' };

export function startStatus(root) {
  const text = root.querySelector('#status-text');
  poll(async () => {
    let down;
    try {
      const { services } = await getJson('/api/status');
      down = services.filter((s) => !s.ok).map((s) => NAMES[s.name] ?? s.name);
    } catch {
      down = ['API'];
    }
    root.className = `status ${down.length ? 'down' : 'ok'}`;
    text.textContent = down.length ? `Not answering: ${down.join(', ')}` : 'All systems normal';
  }, 2000);
}
