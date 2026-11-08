/**
 * A small load generator in the browser: `concurrency` workers send requests one after the other until `total` are done,
 * and every request is timed on its own, so the result is a latency distribution and not an average.
 */
export async function runLoad({ url, total, concurrency, onProgress }) {
  const latencies = new Float64Array(total);
  let started = 0;
  let finished = 0;
  let errors = 0;
  const begin = performance.now();

  const worker = async () => {
    while (started < total) {
      const index = started++;
      const t0 = performance.now();
      try {
        const response = await fetch(url, { cache: 'no-store' });
        await response.arrayBuffer();
        if (!response.ok) errors++;
      } catch {
        errors++;
      }
      latencies[index] = performance.now() - t0;
      finished++;
      if (finished % 25 === 0 || finished === total) onProgress(finished, total);
    }
  };

  await Promise.all(Array.from({ length: concurrency }, worker));

  const seconds = (performance.now() - begin) / 1000;
  const sorted = Array.from(latencies.subarray(0, finished)).sort((a, b) => a - b);
  const percentile = (p) => sorted[Math.min(sorted.length - 1, Math.floor(p * sorted.length))] ?? 0;

  return { done: finished, errors, seconds, rps: finished / seconds, p50: percentile(0.5), p95: percentile(0.95), p99: percentile(0.99) };
}
