// Classic script in <head>: sets the theme before the first paint, so there is no flash.
// The system preference decides until the visitor picks a theme with the button; that choice is remembered.
(() => {
  const root = document.documentElement;
  const dark = window.matchMedia('(prefers-color-scheme: dark)');
  const read = () => { try { return localStorage.getItem('theme'); } catch { return null; } };
  const apply = (theme) => {
    root.dataset.theme = theme;
    const button = document.getElementById('theme-toggle');
    if (button) button.setAttribute('aria-label', theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode');
    window.dispatchEvent(new Event('themechange'));
  };

  root.dataset.theme = read() ?? (dark.matches ? 'dark' : 'light');

  dark.addEventListener('change', (event) => {
    if (read() === null) apply(event.matches ? 'dark' : 'light');
  });

  document.addEventListener('DOMContentLoaded', () => {
    const button = document.getElementById('theme-toggle');
    apply(root.dataset.theme);
    button.addEventListener('click', () => {
      const next = root.dataset.theme === 'dark' ? 'light' : 'dark';
      try { localStorage.setItem('theme', next); } catch { /* the choice just is not remembered */ }
      apply(next);
    });
  });
})();
