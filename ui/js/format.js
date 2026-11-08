const integer = new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 });
const oneDecimal = new Intl.NumberFormat('en-US', { minimumFractionDigits: 1, maximumFractionDigits: 1 });

export const formatInteger = (value) => integer.format(value);

export function formatMicroseconds(value) {
  return value >= 1000 ? `${oneDecimal.format(value / 1000)} ms` : `${oneDecimal.format(value)} µs`;
}

export function formatMilliseconds(value) {
  return value < 1 ? `${oneDecimal.format(value * 1000)} µs` : `${oneDecimal.format(value)} ms`;
}

export function formatBytes(value) {
  return `${oneDecimal.format(value / 1048576)} MiB`;
}

export function formatUptime(seconds) {
  const days = Math.floor(seconds / 86400);
  const hours = Math.floor((seconds % 86400) / 3600);
  const minutes = Math.floor((seconds % 3600) / 60);
  if (days > 0) return `${days}d ${hours}h`;
  if (hours > 0) return `${hours}h ${minutes}m`;
  if (minutes > 0) return `${minutes}m ${seconds % 60}s`;
  return `${seconds}s`;
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
