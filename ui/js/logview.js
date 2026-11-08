// The log view: what the services write to their log, as it happens. Lines are collected and drawn once per frame,
// so a burst of hundreds a second costs one redraw. Only the latest lines are kept.
const KEEP = 150;

export function setupLogView() {
  const view = document.getElementById('log-view');
  const pause = document.getElementById('log-pause');
  const clear = document.getElementById('log-clear');
  let paused = false;
  let queue = [];
  let scheduled = false;

  function line(data) {
    const row = document.createElement('div');
    row.className = `log-line ${data.level}`;
    const time = new Date(data.at ?? Date.now()).toLocaleTimeString('en-GB', { hour12: false });
    const details = Object.entries(data)
      .filter(([key]) => !['level', 'service', 'message', 'at'].includes(key))
      .map(([key, value]) => `${key}=${'batch_id' === key ? String(value).slice(0, 8) : value}`)
      .join(' ');
    row.append(
      Object.assign(document.createElement('span'), { className: 'log-time', textContent: time }),
      Object.assign(document.createElement('span'), { className: 'log-service', textContent: data.service }),
      Object.assign(document.createElement('span'), { className: 'log-message', textContent: `${data.message} ${details}`.trim() }),
    );

    return row;
  }

  function flush() {
    scheduled = false;
    if (paused || 0 === queue.length) return;
    const stick = view.scrollHeight - view.scrollTop - view.clientHeight < 40; // follow the end only if the reader is there
    view.append(...queue.map(line));
    queue = [];
    while (view.children.length > KEEP) view.firstElementChild.remove();
    if (stick) view.scrollTop = view.scrollHeight;
  }

  pause.addEventListener('click', () => {
    paused = !paused;
    pause.textContent = paused ? 'Resume' : 'Pause';
    pause.setAttribute('aria-pressed', String(paused));
    if (!paused) flush();
  });
  clear.addEventListener('click', () => { view.replaceChildren(); queue = []; });

  return (data) => {
    queue.push(data);
    if (queue.length > KEEP) queue.splice(0, queue.length - KEEP);
    if (!scheduled) { scheduled = true; requestAnimationFrame(flush); }
  };
}
