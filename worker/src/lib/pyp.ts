// Pick Your Part (pyp.com) access: inventory pages and the per-yard price list.
// Tested from a Worker on Nov 21 2026: inventory pages and the PriceList API
// both answered 200 with no Cloudflare challenge.
import type { Budget } from './budget';
import { cacheGet, cachePut } from '../store';

export const PYP_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15';

export interface InvVehicle { year: string; make: string; model: string; row: string }

const ALT_RE = /alt="(\d{4})\s+([A-Z][A-Z0-9\s\-]+?)\s+available for parts"/i;
const ROW_RE = /Row:?[\s<>\/b]+([A-Z]?\d+)/i;
const COMPAT_RE = /year=(\d{4})&(?:amp;)?make=([^&]+)&(?:amp;)?model=([^"'>\n&]+)/gi;
const ROW_TAG = '<div class="pypvi_resultRow"';

function decodeEntities(s: string): string {
  return s.replace(/&(amp|lt|gt|quot|#39|apos);/g, (_, e) => ({ amp: '&', lt: '<', gt: '>', quot: '"', '#39': "'", apos: "'" } as Record<string, string>)[e]);
}

function safeDecode(s: string): string {
  try { return decodeURIComponent(s.replace(/\+/g, ' ')); } catch { return s; }
}

// Parse one inventory page. Same two methods as scrape.php, but it finds row
// starts with indexOf instead of a regex over the whole 320 KB page, to stay
// inside the free plan's 10 ms CPU budget.
export function parseInventoryPage(html: string, seen: Set<string>, out: InvVehicle[]): number {
  let count = 0;
  const starts: number[] = [];
  for (let p = html.indexOf(ROW_TAG); p !== -1; p = html.indexOf(ROW_TAG, p + ROW_TAG.length)) starts.push(p);
  for (let i = 0; i < starts.length; i++) {
    const pos = starts[i];
    const end = i + 1 < starts.length ? starts[i + 1] : pos + 3000;
    const sec = html.slice(pos, end);
    const am = ALT_RE.exec(sec);
    if (!am) continue;
    const year = am[1];
    const full = am[2].trim();
    const sp = full.indexOf(' ');
    const make = (sp === -1 ? full : full.slice(0, sp)).toUpperCase();
    const model = (sp === -1 ? '' : full.slice(sp + 1)).toUpperCase();
    const key = `${year}|${make}|${model}`;
    if (seen.has(key)) continue;
    const rm = ROW_RE.exec(sec);
    seen.add(key);
    out.push({ year, make, model, row: rm ? rm[1] : '' });
    count++;
  }
  // Fallback: "Compatible Parts" links.
  for (const m of html.matchAll(COMPAT_RE)) {
    const make = decodeEntities(safeDecode(m[2].trim())).toUpperCase();
    const model = decodeEntities(safeDecode(m[3].trim())).toUpperCase();
    const key = `${m[1]}|${make}|${model}`;
    if (!seen.has(key)) {
      seen.add(key);
      out.push({ year: m[1], make, model, row: '' });
      count++;
    }
  }
  return count;
}

export function hasNextPage(html: string, page: number): boolean {
  return html.includes(`?page=${page + 1}`) || html.includes('Next Page');
}

export async function fetchPyp(budget: Budget, url: string, accept: string, extra: Record<string, string> = {}): Promise<Response> {
  // Reserve 2 in case pyp.com answers with one redirect.
  return budget.fetch(url, {
    headers: { 'User-Agent': PYP_UA, Accept: accept, 'Accept-Language': 'en-US,en;q=0.9', ...extra },
    redirect: 'follow',
  }, 1);
}

// Live per-yard price list (non-Primo total = price + core + warranty),
// cached 24 h in D1. Returns null on failure so callers use the fallback table.
export async function fetchYardPrices(db: D1Database, budget: Budget, slug: string): Promise<Record<string, number> | null> {
  const m = /(\d{3,})$/.exec(slug);
  if (!m) return null;
  const code = m[1];
  const key = `yard-prices:${code}`;
  const cached = await cacheGet<Record<string, number>>(db, key);
  if (cached && Object.keys(cached).length) return cached;
  try {
    const r = await fetchPyp(budget, `https://www.pyp.com/DesktopModules/pyp_api/api/PriceList/?locationCode=${encodeURIComponent(code)}`, 'application/json', {
      'x-requested-with': 'XMLHttpRequest',
      Referer: `https://www.pyp.com/prices/${slug}/`,
    });
    if (r.status !== 200) { console.log('yard price fetch failed', r.status, code); return null; }
    const rows = (await r.json()) as Array<Record<string, unknown>>;
    if (!Array.isArray(rows) || !rows.length) return null;
    const prices: Record<string, number> = {};
    for (const row of rows) {
      if (row.IsPrimo) continue;
      if (row.Description == null) continue;
      const name = String(row.Description).trim();
      const total = Number(row.Price ?? 0) + Number(row.Core ?? 0) + Number(row.Warranty ?? 0);
      if (name !== '' && total > 0) prices[name] = Math.round(total * 100) / 100;
    }
    if (!Object.keys(prices).length) return null;
    await cachePut(db, key, prices, 86400);
    return prices;
  } catch (e) {
    console.log('yard price fetch error', String(e));
    return null;
  }
}
