// Ports of vin.php, ebay.php (debug lookup), ebay-deletion.php and prices.php.
import { appOrigin, type Env } from '../env';
import { clientIp, json, text } from '../http';
import { sha256Hex } from '../crypto';
import { cacheGet, cachePut, rateLimit } from '../store';
import { Budget } from '../lib/budget';
import { fetchPyp } from '../lib/pyp';
import { ebaySearchMedian } from '../lib/ebay';
import { vinCheckDigitOk, vinFormatOk, vinNormalize, vinVehicleFromVpic, type Vehicle } from '../lib/vin';

// ── vin.php ──────────────────────────────────────────────────────────
async function vpicGet(path: string): Promise<any[] | null> {
  const url = 'https://vpic.nhtsa.dot.gov/api/vehicles/' + path + (path.includes('?') ? '&' : '?') + 'format=json';
  try {
    const r = await fetch(url, { signal: AbortSignal.timeout(15_000) });
    if (r.status !== 200) { console.log('vPIC HTTP', r.status, 'for', path); return null; }
    const d = (await r.json()) as any;
    return Array.isArray(d?.Results) ? d.Results : null;
  } catch (e) {
    console.log('vPIC error for', path, String(e));
    return null;
  }
}

const FOREVER = 10 * 365 * 86400;

export async function handleVin(req: Request, env: Env): Promise<Response> {
  await rateLimit(env.DB, 'vin', clientIp(req), 30, 60, 'Too many lookups. Wait a minute.');
  const q = new URL(req.url).searchParams;

  if (q.has('vin')) {
    const vin = vinNormalize(q.get('vin'));
    if (!vinFormatOk(vin)) return json({ error: 'A VIN is 17 letters and numbers (no I, O or Q).' }, 400);
    if (!vinCheckDigitOk(vin)) return json({ error: 'That VIN fails its check digit. One character is probably misread.' }, 400);
    const key = 'vin:' + vin;
    const cached = await cacheGet<Vehicle>(env.DB, key);
    if (cached) return json({ vehicle: cached });
    const rows = await vpicGet('DecodeVinValues/' + vin);
    if (!rows || !rows.length) return json({ error: 'VIN lookup is unavailable right now. Enter year, make and model instead.' }, 502);
    const v = vinVehicleFromVpic(vin, rows[0]);
    if (!v) return json({ error: 'No vehicle found for that VIN. Enter year, make and model instead.' }, 404);
    await cachePut(env.DB, key, v, FOREVER);
    return json({ vehicle: v });
  }

  if (q.has('make') && q.has('year')) {
    const make = (q.get('make') || '').trim().toUpperCase();
    const year = (q.get('year') || '').trim();
    if (!/^[A-Z0-9 \-]{2,30}$/.test(make) || !/^(19[5-9]\d|20\d\d)$/.test(year)) return json({ error: 'Invalid make or year' }, 400);
    const key = `models:${make}:${year}`;
    const cached = await cacheGet<string[]>(env.DB, key);
    if (cached) return json({ models: cached });
    const rows = await vpicGet('GetModelsForMakeYear/make/' + encodeURIComponent(make) + '/modelyear/' + year);
    if (rows === null) return json({ error: 'Model lookup unavailable' }, 502);
    const set = new Set<string>();
    for (const r of rows) if (r?.Model_Name) set.add(String(r.Model_Name).trim().toUpperCase());
    // PHP sort() on strings: byte order.
    const models = [...set].sort((a, b) => (a < b ? -1 : a > b ? 1 : 0));
    await cachePut(env.DB, key, models, 30 * 86400);
    return json({ models });
  }

  return json({ error: 'Pass vin, or make and year' }, 400);
}

// ── ebay.php (developer lookup) ─────────────────────────────────────
export async function handleEbayDebug(req: Request, env: Env): Promise<Response> {
  if (req.method !== 'GET') return json({ error: 'Method not allowed' }, 405);
  // New: rate limited, since every miss spends eBay API quota.
  await rateLimit(env.DB, 'ebay', clientIp(req), 20, 60);
  const p = new URL(req.url).searchParams;
  const q = (p.get('q') || '').trim();
  if (q === '' || q.length > 200) return json({ error: 'Invalid q parameter' }, 400);
  let compat: Record<string, string> | null = null;
  for (const [k, param] of [['Year', 'year'], ['Make', 'make'], ['Model', 'model']] as const) {
    const v = (p.get(param) || '').trim();
    if (v) (compat ??= {})[k] = v;
  }
  const cat = (p.get('cat') || '').trim();
  const result = await ebaySearchMedian(env, new Budget(), q, compat, cat || '6030');
  if (!result) return json({ error: 'No data', q, compat });
  return json(result);
}

// ── ebay-deletion.php ────────────────────────────────────────────────
export async function handleEbayDeletion(req: Request, env: Env): Promise<Response> {
  const token = env.EBAY_VERIFICATION_TOKEN || '';
  if (!token) { console.log('EBAY_VERIFICATION_TOKEN not set'); return json({ error: 'Misconfigured' }, 500); }
  // Must exactly match the URL configured in the eBay developer portal.
  const endpoint = env.EBAY_DELETION_ENDPOINT || appOrigin(env) + '/ebay-deletion.php';
  if (req.method === 'GET') {
    const code = new URL(req.url).searchParams.get('challenge_code') || '';
    if (!code) return json({ error: 'Missing challenge_code' }, 400);
    return json({ challengeResponse: await sha256Hex(code + token + endpoint) });
  }
  if (req.method === 'POST') {
    // Yard Picker Pro stores no eBay user data; log for the audit trail only.
    console.log('ebay deletion notification:', (await req.text()).slice(0, 2000));
    return json({ status: 'ok' });
  }
  return json({ error: 'Method not allowed' }, 405);
}

// ── prices.php (developer tool) ──────────────────────────────────────
export async function handlePrices(req: Request, env: Env): Promise<Response> {
  await rateLimit(env.DB, 'prices', clientIp(req), 10, 60);
  const p = new URL(req.url).searchParams;
  const slug = (p.get('slug') || 'chula-vista-1264').trim();
  const raw = !!p.get('raw');
  if (!/^[a-z0-9-]+$/i.test(slug)) return json({ error: 'Invalid slug' });
  let r: Response;
  try {
    r = await fetchPyp(new Budget(), `https://www.pyp.com/prices/${slug}/`, 'text/html');
  } catch (e) {
    return json({ error: 'Fetch failed', code: 0, curl_error: String(e) });
  }
  const html = await r.text();
  if (r.status !== 200 || !html) return json({ error: 'Fetch failed', code: r.status, curl_error: '' });
  if (raw) return text(html, 200, { 'Content-Type': 'text/html; charset=UTF-8' });
  const prices: Record<string, number> = {};
  const re = /<td[^>]*>\s*([A-Za-z][A-Za-z0-9/()\s.\-]{2,60}?)\s*<\/td>\s*<td[^>]*>\s*\$?([\d,]+\.\d{2})\s*<\/td>/gi;
  for (const m of html.matchAll(re)) {
    const clean = m[1].replace(/\s+/g, ' ').trim();
    if (clean) prices[clean] = parseFloat(m[2].replace(/,/g, ''));
  }
  return json({ location: slug, prices, count: Object.keys(prices).length, fetched_at: new Date().toISOString().replace(/\.\d{3}Z$/, '+00:00') });
}
