// The drop-down menus in the bar are <details> elements: they open and close without script. This only adds
// what people expect of a menu: it closes on Escape, on a click outside and after a choice.
export function setupMenus() {
  const menus = document.querySelectorAll('.menu');
  const closeAll = (except) => menus.forEach((menu) => { if (menu !== except) menu.open = false; });

  document.addEventListener('click', (event) => closeAll(event.target.closest('.menu')));
  document.addEventListener('keydown', (event) => {
    if ('Escape' !== event.key) return;
    const open = document.querySelector('.menu[open]');
    if (open) {
      open.open = false;
      open.querySelector('summary').focus();
    }
  });
  menus.forEach((menu) => menu.querySelectorAll('a').forEach((link) => link.addEventListener('click', () => { menu.open = false; })));
}
