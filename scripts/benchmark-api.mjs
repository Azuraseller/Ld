#!/usr/bin/env node

const baseUrl = process.env.BENCHMARK_URL || "http://localhost:3000";
const action = process.env.BENCHMARK_ACTION || "ping";
const iterations = Math.max(1, Number(process.env.BENCHMARK_ITERATIONS || 30));
const concurrency = Math.max(1, Number(process.env.BENCHMARK_CONCURRENCY || 1));

const samples = [];
let next = 0;
async function worker() {
  while (true) {
    const index = next++;
    if (index >= iterations) return;
    const started = performance.now();
    try {
      const response = await fetch(`${baseUrl}/api/legacy?action=${encodeURIComponent(action)}`, {
        headers: { Accept: "application/json" },
      });
      await response.arrayBuffer();
      samples[index] = { ms: performance.now() - started, status: response.status };
    } catch (error) {
      samples[index] = { ms: performance.now() - started, status: 0, error: String(error) };
    }
  }
}

await Promise.all(Array.from({ length: Math.min(concurrency, iterations) }, worker));
const values = samples.map(sample => sample.ms).sort((a, b) => a - b);
const percentile = p => values[Math.min(values.length - 1, Math.floor(values.length * p))];
const errors = samples.filter(sample => sample.status < 200 || sample.status >= 300).length;
console.log(JSON.stringify({
  url: baseUrl,
  action,
  iterations,
  concurrency,
  p50Ms: Number(percentile(0.5).toFixed(2)),
  p95Ms: Number(percentile(0.95).toFixed(2)),
  p99Ms: Number(percentile(0.99).toFixed(2)),
  minMs: Number(values[0].toFixed(2)),
  maxMs: Number(values.at(-1).toFixed(2)),
  errors,
}, null, 2));
