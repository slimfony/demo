import { getJson, poll } from './api.js';
import { formatBytes, formatUptime } from './format.js';

function set(name, text) {
  for (const element of document.querySelectorAll(`[data-fact="${name}"]`)) {
    element.textContent = text;
  }
}

export function startRuntime() {
  poll(async () => {
    try {
      const runtime = await getJson('/api/runtime');
      set('php', `PHP ${runtime.php}`);
      set('jit', runtime.jit ? 'on (tracing)' : 'off');
      set('server', runtime.worker_mode ? `FrankenPHP ${runtime.frankenphp ?? ''} worker`.replace('  ', ' ') : 'classic request mode');
      set('core', runtime.core);
      set('demo', runtime.app_version);
      set('uptime', formatUptime(runtime.uptime_seconds));
      set('memory', formatBytes(runtime.memory_bytes));
      set('server-memory', formatBytes(runtime.server_memory_bytes));
      set('core-footer', runtime.core);
      set('php-footer', `PHP ${runtime.php}`);
    } catch {
      // the status pills already say the api is down; keep the last facts on screen
    }
  }, 2000);
}
