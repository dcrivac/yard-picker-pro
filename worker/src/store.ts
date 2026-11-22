// D1-backed replacements for the PHP temp files: cache, rate limits, daily cost.
import { HttpError } from './http';
import { sha256Hex } from './crypto';

const now = () => Math.floor(Date.now() / 1000);

export async function cacheGet<T>(db: D1Database, key: string): Promise<T | null> {
  const row = await db.prepare('SELECT value FROM cache WHERE key = ? AND expires_at > ?').bind(key, now()).first<{ value: string }>();
  if (!row) return null;
  try { return JSON.parse(row.value) as T; } catch { return null; }
}

export async function cachePut(db: D1Database, key: string, value: unknown, ttlSeconds: number): Promise<void> {
  await db.prepare('INSERT INTO cache (key, value, expires_at) VALUES (?, ?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value, expires_at = excluded.expires_at')
    .bind(key, JSON.stringify(value), now() + ttlSeconds).run();
}

// Fixed-window counter. Throws 429 when the window's limit is reached.
// One D1 write per hit; the free plan allows 100,000 writes a day.
export async function rateLimit(db: D1Database, name: string, ip: string, limit: number, windowSeconds: number, message = 'Too many requests'): Promise<void> {
  const t = now();
  const windowStart = t - (t % windowSeconds);
  const bucket = `${name}:${(await sha256Hex(ip)).slice(0, 32)}:${windowStart}`;
  const row = await db.prepare('INSERT INTO rate_hits (bucket, count, expires_at) VALUES (?, 1, ?) ON CONFLICT(bucket) DO UPDATE SET count = count + 1 RETURNING count')
    .bind(bucket, windowStart + windowSeconds).first<{ count: number }>();
  if (row && row.count > limit) {
    throw new HttpError(429, `${message} (${name})`, { error: message }, { 'Retry-After': String(windowStart + windowSeconds - t) });
  }
}

export function utcDay(d = new Date()): string { return d.toISOString().slice(0, 10); }

export async function getDailyCost(db: D1Database): Promise<number> {
  const row = await db.prepare('SELECT usd FROM daily_cost WHERE day = ?').bind(utcDay()).first<{ usd: number }>();
  return row ? row.usd : 0;
}

// List prices in USD per million tokens (input, output), from Anthropic's
// pricing for each model. Haiku 5.5 has a second, higher rate card when the
// prompt is over 100K tokens. Unknown models fall back to the highest rate
// listed here, so the daily ceiling errs on the safe side.
const PRICES: Array<{ match: string; rates: [number, number]; longRates?: [number, number] }> = [
  { match: 'haiku-5-5', rates: [0.1, 0.5], longRates: [0.5, 2.5] },
  { match: 'haiku-4-5', rates: [1, 5] },
  { match: 'sonnet-5', rates: [2, 10] }, // Sonnet 5 and 5.5
  { match: 'sonnet', rates: [3, 15] }, // older Sonnet models
];
const FALLBACK: [number, number] = [3, 15];
const LONG_PROMPT_TOKENS = 100_000;

export function estimateCostUsd(model: string, inputTokens: number, outputTokens: number): number {
  const entry = PRICES.find((p) => model.includes(p.match));
  let [pin, pout] = entry ? entry.rates : FALLBACK;
  if (entry?.longRates && inputTokens > LONG_PROMPT_TOKENS) [pin, pout] = entry.longRates;
  return (inputTokens * pin + outputTokens * pout) / 1_000_000;
}

export async function recordCost(db: D1Database, model: string, usage: { input_tokens?: number; output_tokens?: number } | undefined): Promise<void> {
  if (!usage || usage.input_tokens == null || usage.output_tokens == null) return;
  const cost = estimateCostUsd(model, usage.input_tokens, usage.output_tokens);
  await db.prepare('INSERT INTO daily_cost (day, usd) VALUES (?, ?) ON CONFLICT(day) DO UPDATE SET usd = usd + excluded.usd').bind(utcDay(), cost).run();
}

// Called from a small share of requests in ctx.waitUntil, instead of a cron
// trigger (the free plan allows only 5 cron triggers per account).
export async function cleanup(db: D1Database): Promise<void> {
  const t = now();
  await db.batch([
    db.prepare('DELETE FROM rate_hits WHERE expires_at < ?').bind(t),
    db.prepare('DELETE FROM cache WHERE expires_at < ?').bind(t),
    db.prepare('DELETE FROM sessions WHERE expires_at < ?').bind(t),
    db.prepare("DELETE FROM password_resets WHERE expires_at < datetime('now', '-7 days')"),
    db.prepare("DELETE FROM daily_cost WHERE day < date('now', '-30 days')"),
  ]);
}
