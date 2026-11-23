// Shared by the forms: one pattern for a button that waits for an answer, and the values of a form with disabled controls.

/** Disables the button and shows `label` and a spinner; `setBusy(button, null)` puts it back. */
export function setBusy(button, label) {
  const text = button.querySelector('span') ?? button;
  if (label) {
    button.dataset.label ??= text.textContent;
    text.textContent = label;
  } else if (button.dataset.label) {
    text.textContent = button.dataset.label;
    delete button.dataset.label;
  }
  button.disabled = Boolean(label);
  if (label) button.setAttribute('aria-busy', 'true');
  else button.removeAttribute('aria-busy');
}

export async function withBusy(button, label, task) {
  setBusy(button, label);
  try {
    return await task();
  } finally {
    setBusy(button, null);
  }
}

/** Every named control of a form, also the disabled ones (FormData leaves those out). */
export function values(form) {
  return Object.fromEntries([...form.elements].filter((element) => element.name).map((element) => [element.name, element.value]));
}
