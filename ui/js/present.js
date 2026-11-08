// Presenter mode: only the parts that are worth showing on a big screen, in larger type. Escape leaves it.
export function setupPresent() {
  const root = document.documentElement;
  const button = document.getElementById('present-toggle');

  function set(on) {
    root.classList.toggle('present', on);
    button.setAttribute('aria-pressed', String(on));
    button.textContent = on ? 'Exit' : 'Present';
    if (on) {
      window.scrollTo({ top: 0 });
      document.documentElement.requestFullscreen?.().catch(() => {}); // a full screen is nice to have, not needed
    } else if (document.fullscreenElement) {
      document.exitFullscreen().catch(() => {});
    }
  }

  button.addEventListener('click', () => set(!root.classList.contains('present')));
  document.addEventListener('keydown', (event) => {
    if ('Escape' === event.key && root.classList.contains('present')) set(false);
  });
  document.addEventListener('fullscreenchange', () => {
    if (!document.fullscreenElement && root.classList.contains('present')) set(false);
  });
}
