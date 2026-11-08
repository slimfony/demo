// A short guided tour: each step scrolls to a part of the page, outlines it and says what it is.
const STEPS = [
  { target: '#top', title: 'A PHP service that stays in memory', text: 'This page is served by a PHP worker (FrankenPHP) that starts once and answers thousands of requests from memory. The facts below the title are read from the running system.' },
  { target: '#import', title: 'Start an import', text: 'The page generates ZIP files of bank statements (ISO 20022 CAMT.053) and uploads them to the API, the same way any client would. Press Start import.' },
  { target: '#run-tiles', title: 'One tile per ZIP', text: 'A tile moves from waiting to importing to booked. A shield means the ZIP came with a verified token; the others went through the anonymous sandbox. Hover a tile for details.' },
  { target: '#log-view', title: 'What the services say', text: 'The API, the relay and the importers write structured logs. The important lines arrive here as they happen. Pause the view to read.' },
  { target: '#upload', title: 'Tokens that fail', text: 'Send a ZIP with an expired, forged or wrong token and see the API refuse it for its own reason. Rotate the identity provider\'s key and see the API pick up the new one.' },
  { target: '#live', title: 'Numbers from Prometheus', text: 'Requests per second and latency of the API, read from Prometheus every two seconds. Grafana (linked in the footer) shows the importers too.' },
];

export function setupTour() {
  const card = document.getElementById('tour');
  const title = document.getElementById('tour-title');
  const text = document.getElementById('tour-text');
  const next = document.getElementById('tour-next');
  const back = document.getElementById('tour-back');
  const close = document.getElementById('tour-close');
  let index = 0;
  let focused = null;

  function show(i) {
    index = i;
    focused?.classList.remove('tour-focus');
    focused = document.querySelector(STEPS[i].target);
    focused.classList.add('tour-focus');
    focused.scrollIntoView({ behavior: 'smooth', block: 'center' });
    title.textContent = STEPS[i].title;
    text.textContent = STEPS[i].text;
    back.disabled = 0 === i;
    next.textContent = i === STEPS.length - 1 ? 'Done' : 'Next';
    document.getElementById('tour-step').textContent = `${i + 1} of ${STEPS.length}`;
  }

  function stop() {
    focused?.classList.remove('tour-focus');
    card.hidden = true;
  }

  document.getElementById('tour-start').addEventListener('click', () => { card.hidden = false; show(0); next.focus(); });
  next.addEventListener('click', () => (index === STEPS.length - 1 ? stop() : show(index + 1)));
  back.addEventListener('click', () => show(Math.max(0, index - 1)));
  close.addEventListener('click', stop);
  document.addEventListener('keydown', (event) => { if ('Escape' === event.key && !card.hidden) stop(); });
}
