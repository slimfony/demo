/**
 * Fetches JSON from the api with a timeout. Never throws away the reason: callers decide what a failure means.
 */
export async function getJson(url, timeoutMs = 3000) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);
  try {
    const response = await fetch(url, {
      signal: controller.signal,
      headers: { Accept: 'application/json' },
      cache: 'no-store',
    });
    if (!response.ok) {
      throw new Error(`HTTP ${response.status}`);
    }
    return await response.json();
  } finally {
    clearTimeout(timer);
  }
}

/**
 * Calls `tick` repeatedly, one run after the other (never overlapping), `everyMs` apart.
 */
export function poll(tick, everyMs) {
  const run = async () => {
    try {
      await tick();
    } finally {
      setTimeout(run, everyMs);
    }
  };
  run();
}
