// One connection to the Mercure hub of the web server; the rest of the page subscribes to what it needs.
// The browser reconnects by itself when the connection drops.
const listeners = { batch: [], log: [] };
let connected = false;

export function on(topic, listener) {
  listeners[topic].push(listener);
}

export function isConnected() {
  return connected;
}

export function startEvents() {
  const source = new EventSource('/.well-known/mercure?topic=urn:demo:batch&topic=urn:demo:log');
  source.onopen = () => { connected = true; };
  source.onerror = () => { connected = false; };
  source.onmessage = (event) => {
    let data;
    try { data = JSON.parse(event.data); } catch { return; }
    // the topic is not part of the message: batch events carry an id and a state, log events a level
    const topic = 'level' in data ? 'log' : 'batch';
    for (const listener of listeners[topic]) listener(data);
  };
}
