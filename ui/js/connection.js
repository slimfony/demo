// What is out of date, said next to the numbers: the live numbers and the batch table keep their last values when a source
// goes quiet, so the page must say so. Sources report whether their last attempt worked.
const REASONS = {
  api: 'The API is not answering.',
  events: 'The live event stream (log and tiles) is disconnected and reconnecting.',
  charts: 'The charts could not be loaded.',
};
const failing = new Map(); // source -> reason

export function report(source, ok, reason = source) {
  if (ok) failing.delete(source);
  else failing.set(source, reason);
  const text = [...new Set(failing.values())].map((key) => REASONS[key]).join(' ');
  for (const note of document.querySelectorAll('.conn-note')) {
    note.hidden = '' === text;
    note.textContent = text ? `${text} The numbers shown may be out of date.` : '';
  }
  const apiDown = [...failing.values()].includes('api');
  for (const area of document.querySelectorAll('[data-stale-when-api-down]')) area.classList.toggle('stale', apiDown);
}
