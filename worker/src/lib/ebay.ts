// Port of ebay-prices.php: cached OAuth token + upper-half median price.
import type { Env } from '../env';
import type { Budget } from './budget';
import { cacheGet, cachePut } from '../store';
import { sha256Hex } from '../crypto';

export interface EbayResult { avg: number; low: number; high: number; shipping: number | null; count: number }

const round2 = (n: number) => Math.round(n * 100) / 100;

export async function ebayGetToken(env: Env, budget: Budget): Promise<string | null> {
  const cached = await cacheGet<{ access_token: string; expires_at: number }>(env.DB, 'ebay-token');
  if (cached && cached.expires_at > Date.now() / 1000 + 60) return cached.access_token;
  if (!env.EBAY_CLIENT_ID || !env.EBAY_CLIENT_SECRET) { console.log('EBAY_CLIENT_ID / EBAY_CLIENT_SECRET not set'); return null; }
  const r = await budget.fetch('https://api.ebay.com/identity/v1/oauth2/token', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded',
      Authorization: 'Basic ' + btoa(`${env.EBAY_CLIENT_ID}:${env.EBAY_CLIENT_SECRET}`),
    },
    body: 'grant_type=client_credentials&scope=' + encodeURIComponent('https://api.ebay.com/oauth/api_scope'),
  });
  if (r.status !== 200) { console.log('ebay token fetch failed', r.status, (await r.text()).slice(0, 300)); return null; }
  const data = (await r.json()) as { access_token?: string; expires_in?: number };
  if (!data.access_token) return null;
  const expiresIn = data.expires_in ?? 7200;
  await cachePut(env.DB, 'ebay-token', { access_token: data.access_token, expires_at: Math.floor(Date.now() / 1000) + expiresIn }, expiresIn);
  return data.access_token;
}

export function medianFromItems(items: Array<any>): EbayResult | null {
  const prices: number[] = [];
  const ship: number[] = [];
  for (const item of items) {
    const v = item?.price?.value;
    if (v != null && !Number.isNaN(Number(v))) {
      const p = Number(v);
      if (p >= 5 && p <= 10000) prices.push(p);
    }
    const s = item?.shippingOptions?.[0]?.shippingCost?.value;
    if (s != null && s !== '' && Number(s) && !Number.isNaN(Number(s))) {
      const sp = Number(s);
      if (sp >= 1 && sp <= 500) ship.push(sp);
    }
  }
  if (prices.length < 3) return null;
  prices.sort((a, b) => a - b);
  const n = prices.length;
  // Median of the upper half, to push out cheap accessories (see ebay-prices.php).
  const upper = prices.slice(Math.floor(n / 2));
  const un = upper.length;
  const median = un % 2 ? upper[Math.floor(un / 2)] : (upper[un / 2 - 1] + upper[un / 2]) / 2;
  let shipMedian: number | null = null;
  if (ship.length >= 3) {
    ship.sort((a, b) => a - b);
    const sn = ship.length;
    shipMedian = sn % 2 ? ship[Math.floor(sn / 2)] : (ship[sn / 2 - 1] + ship[sn / 2]) / 2;
  }
  return {
    avg: round2(median),
    low: round2(prices[Math.floor(n * 0.1)]),
    high: round2(prices[Math.floor(n * 0.9)]),
    shipping: shipMedian !== null ? round2(shipMedian) : null,
    count: n,
  };
}

export async function ebaySearchMedian(
  env: Env, budget: Budget, query: string, compat: Record<string, string> | null = null, categoryId = '6030',
): Promise<EbayResult | null> {
  query = query.trim();
  if (!query) return null;
  let compatStr = '';
  if (compat) {
    compatStr = Object.entries(compat).map(([k, v]) => [k, String(v).trim()]).filter(([, v]) => v !== '').map(([k, v]) => `${k}:${v}`).join(';');
  }
  const key = 'ebay:' + (await sha256Hex(`${query.toLowerCase()}|${compatStr}|${categoryId}`));
  const cached = await cacheGet<EbayResult>(env.DB, key);
  if (cached) return cached;

  // Need one call for the search, plus one more if the token isn't cached.
  if (budget.remaining < 1) return null;
  const token = await ebayGetToken(env, budget);
  if (!token || budget.remaining < 1) return null;

  const params = new URLSearchParams({
    q: query,
    category_ids: categoryId,
    filter: 'conditions:{USED|FOR_PARTS_OR_NOT_WORKING},buyingOptions:{FIXED_PRICE}',
    limit: '50',
  });
  if (compatStr) params.set('compatibility_filter', compatStr);
  const r = await budget.fetch('https://api.ebay.com/buy/browse/v1/item_summary/search?' + params.toString(), {
    headers: { Authorization: `Bearer ${token}`, 'X-EBAY-C-MARKETPLACE-ID': 'EBAY_US' },
  });
  if (r.status !== 200) { console.log('ebay search failed', r.status, query); return null; }
  const data = (await r.json()) as { itemSummaries?: unknown[] };
  const result = medianFromItems(data.itemSummaries ?? []);
  // PHP only cached successes (it returned before writing on < 3 prices).
  if (result) await cachePut(env.DB, key, result, 86400);
  return result;
}
