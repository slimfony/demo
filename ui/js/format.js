const integer = new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 });
const oneDecimal = new Intl.NumberFormat('en-US', { minimumFractionDigits: 1, maximumFractionDigits: 1 });

export const formatInteger = (value) => integer.format(value);

export const EMPTY = '–'; // nothing to show (yet)
export const NOT_AVAILABLE = 'n/a'; // there is a value, but this page cannot know it

export const formatRate = (value) => `${integer.format(value)} /s`;

/** Seconds as 12.3 s, 1 min 5 s, 2 h 3 min or 1 d 2 h. */
export function formatDuration(seconds) {
  if (seconds < 60) return `${oneDecimal.format(Math.max(0, seconds))} s`;
  const total = Math.floor(seconds);
  const days = Math.floor(total / 86400);
  const hours = Math.floor((total % 86400) / 3600);
  const minutes = Math.floor((total % 3600) / 60);
  if (days > 0) return `${days} d ${hours} h`;
  if (hours > 0) return `${hours} h ${minutes} min`;
  return `${minutes} min ${total % 60} s`;
}

export function formatMilliseconds(value) {
  if (value < 1) return `${oneDecimal.format(value * 1000)} µs`;
  return value < 1000 ? `${oneDecimal.format(value)} ms` : formatDuration(value / 1000);
}

export const formatMicroseconds = (value) => formatMilliseconds(value / 1000);

export function formatBytes(value) {
  return `${oneDecimal.format(value / 1048576)} MiB`;
}

/**
 * Eases a number on screen from its current value to the new one, so changes read as movement, not flicker.
 * Respects the reduced-motion preference.
 */
export function animateNumber(element, target, format, durationMs = 450) {
  const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const from = Number(element.dataset.value ?? 0);
  element.dataset.value = String(target);
  if (reduce || !Number.isFinite(from) || from === target) {
    element.textContent = format(target);
    return;
  }

  const start = performance.now();
  const step = (now) => {
    const t = Math.min(1, (now - start) / durationMs);
    const eased = 1 - (1 - t) ** 3;
    element.textContent = format(from + (target - from) * eased);
    if (t < 1 && Number(element.dataset.value) === target) {
      requestAnimationFrame(step);
    } else {
      element.textContent = format(target);
    }
  };
  requestAnimationFrame(step);
}
