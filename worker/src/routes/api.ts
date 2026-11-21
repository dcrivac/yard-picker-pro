// Port of api.php: the analysis and VIN-photo endpoint.
//   POST /api.php?slug=<yard>  {"mode":"analyze","vehicles":[...]}  or  {"mode":"vin_ocr","image":"<base64 jpeg>"}
//
// Free-plan changes from the PHP version:
// - Outside calls share a 50-per-request budget (Anthropic, pyp.com, eBay).
//   Each part gets its primary eBay lookup first; the broad-category retry
//   only runs while budget is left. A part with no lookup keeps the AI
//   estimate, exactly as when eBay fails in the PHP app.
// - eBay lookups run 6 at a time instead of one by one.
// - Caches, rate limits and the daily cost ceiling live in D1.
import type { Env } from '../env';
import { clientIp, HttpError, json, readJson } from '../http';
import { creditStatus, currentUser } from '../session';
import { getDailyCost, rateLimit, recordCost } from '../store';
import { Budget, BudgetExceeded, pool } from '../lib/budget';
import { buildAnalysisPrompt, findLKQPrice, validateVehicles, type CleanVehicle } from '../lib/analyze';
import { LKQ_FALLBACK, PART_CATEGORIES, PROMPT_PARTS, PYP_NAME_MAP } from '../lib/tables';
import { fetchYardPrices } from '../lib/pyp';
import { ebaySearchMedian, type EbayResult } from '../lib/ebay';

// claude-haiku-4-5 is past its earliest retirement date (2026-10-15).
export const MODEL = 'claude-haiku-5-5';

function fail(status: number, log: string, headers: Record<string, string> = {}): never {
  throw new HttpError(status, log, { error: { message: 'Request failed' } }, headers);
}

function isJpegBase64(img: string): boolean {
  // Check the JPEG magic bytes without decoding the whole image.
  try {
    const head = atob(img.slice(0, 8));
    return head.charCodeAt(0) === 0xff && head.charCodeAt(1) === 0xd8 && head.charCodeAt(2) === 0xff && /^[A-Za-z0-9+/]*={0,2}$/.test(img);
  } catch { return false; }
}

export async function handleApi(req: Request, env: Env): Promise<Response> {
  if (req.method !== 'POST') fail(405, 'Method not allowed: ' + req.method);
  if (!env.ANTHROPIC_API_KEY) fail(500, 'ANTHROPIC_API_KEY not set');

  const ip = clientIp(req);
  await rateLimit(env.DB, 'api-min', ip, 10, 60);
  await rateLimit(env.DB, 'api-day', ip, 50, 86400);

  const ceiling = parseFloat(env.DAILY_COST_CEILING_USD || '5') || 5;
  if ((await getDailyCost(env.DB)) >= ceiling) fail(503, `Daily cost ceiling reached ($${ceiling})`, { 'Retry-After': '3600' });

  const decoded = await readJson(req, 2_000_000);
  if (!decoded) fail(400, 'Invalid JSON');

  let vehicles: CleanVehicle[] = [];
  let img = '';
  const mode = decoded.mode;
  if (mode === 'analyze') {
    const [v, err] = validateVehicles(decoded.vehicles);
    if (err) fail(400, 'Bad vehicles: ' + err);
    vehicles = v!;
  } else if (mode === 'vin_ocr') {
    img = typeof decoded.image === 'string' ? decoded.image : '';
    if (!isJpegBase64(img)) fail(400, 'vin_ocr image must be base64 JPEG');
  } else {
    fail(400, 'Unknown mode: ' + JSON.stringify(mode));
  }

  // Sign-in required for both modes; analysis also needs a credit.
  const me = await currentUser(req, env);
  if (!me) fail(401, 'Sign in required');
  let [freeRemaining, paidCredits, totalAvail] = await creditStatus(env, me);
  if (mode === 'analyze' && totalAvail <= 0) fail(402, 'Out of credits for user ' + me.id, { 'X-Credits-Remaining': '0' });

  const budget = new Budget();

  // This yard's live prices override the fallback table, keeping our keys.
  const lkq: Record<string, number> = { ...LKQ_FALLBACK };
  const slug = (new URL(req.url).searchParams.get('slug') || '').trim();
  if (mode === 'analyze' && slug !== '' && /^[a-z0-9-]+$/i.test(slug)) {
    const live = await fetchYardPrices(env.DB, budget, slug);
    if (live) {
      const upper: Record<string, number> = {};
      for (const [desc, price] of Object.entries(live)) upper[desc.trim().toUpperCase()] = price;
      for (const ours of Object.keys(lkq)) {
        const k = (PYP_NAME_MAP[ours] ?? ours.toUpperCase()).trim().toUpperCase();
        if (k in upper) lkq[ours] = upper[k];
      }
    }
  }

  let content: unknown;
  let maxTokens: number;
  if (mode === 'analyze') {
    content = buildAnalysisPrompt(vehicles, PROMPT_PARTS, lkq);
    maxTokens = 8000;
  } else {
    content = [
      { type: 'image', source: { type: 'base64', media_type: 'image/jpeg', data: img } },
      { type: 'text', text: 'This photo should show a vehicle VIN (dashboard plate, door jamb sticker, or title). Read the 17-character VIN exactly. VINs never contain I, O or Q. Return ONLY JSON: {"vin":"1HGCM82633A004352"} or {"vin":null} if no VIN is readable.' },
    ];
    maxTokens = 100;
  }

  let ar: Response;
  try {
    ar = await budget.fetch('https://api.anthropic.com/v1/messages', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'x-api-key': env.ANTHROPIC_API_KEY!, 'anthropic-version': '2023-06-01' },
      body: JSON.stringify({ model: MODEL, max_tokens: maxTokens, messages: [{ role: 'user', content }] }),
    });
  } catch (e) {
    fail(502, 'Anthropic fetch error: ' + String(e));
  }
  const responseText = await ar.text();
  if (!responseText) fail(502, `Empty response from Anthropic (HTTP ${ar.status})`);
  // Don't proxy upstream error messages; they reveal billing/quota state.
  if (ar.status < 200 || ar.status >= 300) fail(502, `Anthropic HTTP ${ar.status}: ${responseText.slice(0, 500)}`);

  let parsed: any = null;
  try { parsed = JSON.parse(responseText); } catch { /* handled below */ }
  if (parsed?.usage) await recordCost(env.DB, MODEL, parsed.usage);

  // VIN reads are free: hand the reply straight back.
  if (mode !== 'analyze') return json(parsed ?? {}, ar.status);

  // Deduct 1 credit now that the call succeeded: free allowance first.
  if (freeRemaining > 0) {
    await env.DB.batch([
      env.DB.prepare('UPDATE users SET free_credits_used = free_credits_used + 1 WHERE id = ?').bind(me.id),
      env.DB.prepare('INSERT INTO credit_events (user_id, delta, reason) VALUES (?, 0, ?)').bind(me.id, 'analysis_free'),
    ]);
    freeRemaining--;
  } else {
    await env.DB.batch([
      env.DB.prepare('UPDATE users SET credits = MAX(credits - 1, 0) WHERE id = ?').bind(me.id),
      env.DB.prepare('INSERT INTO credit_events (user_id, delta, reason) VALUES (?, -1, ?)').bind(me.id, 'analysis_paid'),
    ]);
    paidCredits = Math.max(0, paidCredits - 1);
  }
  const creditsHeader = { 'X-Credits-Remaining': String(freeRemaining + paidCredits) };

  if (!parsed || !Array.isArray(parsed.content)) return new Response(responseText, { status: ar.status, headers: { 'Content-Type': 'application/json', ...creditsHeader } });

  for (const block of parsed.content) {
    if (block.type !== 'text') continue;
    const t: string = block.text;
    const s = t.indexOf('['), e = t.lastIndexOf(']');
    if (s === -1 || e === -1) continue;
    let cars: any;
    try { cars = JSON.parse(t.slice(s, e + 1)); } catch { continue; }
    if (!Array.isArray(cars) || !cars.length) continue;
    await enrichWithPrices(env, budget, cars, vehicles, lkq);
    block.text = t.slice(0, s) + JSON.stringify(cars) + t.slice(e + 1);
  }
  return json(parsed, ar.status, creditsHeader);
}

interface PartJob { part: any; vehicle: CleanVehicle; clean: string; compat: Record<string, string>; aiAvg: number; cat: string; result: EbayResult | null; usable: boolean }

function cleanPartName(name: string): string {
  return name.replace(/\([^)]*\)/g, '').replace(/\//g, ' ').replace(/\s+/g, ' ').trim();
}

const ucfirstLower = (s: string) => { const l = s.toLowerCase(); return l.charAt(0).toUpperCase() + l.slice(1); };

async function enrichWithPrices(env: Env, budget: Budget, cars: any[], vehicles: CleanVehicle[], lkq: Record<string, number>) {
  const jobs: PartJob[] = [];
  for (const car of cars) {
    if (!car || !Array.isArray(car.parts)) continue;
    const idx = car.carIndex != null ? parseInt(car.carIndex, 10) : -1;
    const vehicle = vehicles[idx];
    for (const part of car.parts) {
      if (!part || typeof part.name !== 'string') continue;
      const lk = findLKQPrice(part.name, lkq);
      part.lkqPrice = Math.round(lk.price * 100) / 100;
      part.lkqMatched = lk.matched;
      part.ebaySource = 'ai_estimate';
      if (!vehicle) continue;
      jobs.push({
        part, vehicle, clean: cleanPartName(part.name),
        compat: { Year: vehicle.year, Make: ucfirstLower(vehicle.make), Model: vehicle.model.trim().split(' ')[0] },
        aiAvg: part.ebayAvg != null ? Number(part.ebayAvg) || 0 : 0,
        cat: PART_CATEGORIES[part.name] ?? '6030',
        result: null, usable: false,
      });
    }
  }
  const usableFor = (j: PartJob, r: EbayResult | null) => !!r && (j.aiAvg < 1 || r.avg >= j.aiAvg * 0.4);
  const lookup = async (j: PartJob, cat: string) => {
    try { return await ebaySearchMedian(env, budget, j.clean, j.compat, cat); }
    catch (e) { if (!(e instanceof BudgetExceeded)) console.log('ebay lookup error', String(e)); return null; }
  };

  // Pass 1: each part's own category.
  await pool(jobs, 6, async (j) => { j.result = await lookup(j, j.cat); j.usable = usableFor(j, j.result); });
  // Pass 2: broad 6030 retry for unusable results, while budget lasts.
  await pool(jobs.filter((j) => !j.usable && j.cat !== '6030'), 6, async (j) => {
    const broad = await lookup(j, '6030');
    if (usableFor(j, broad)) { j.result = broad; j.usable = true; }
  });

  for (const j of jobs) {
    if (!j.result) continue;
    if (j.usable) {
      j.part.ebayAvg = j.result.avg;
      j.part.ebayLow = j.result.low;
      j.part.ebayHigh = j.result.high;
      j.part.ebaySource = 'ebay_' + j.result.count;
    }
    if (j.result.shipping !== null && j.result.shipping !== undefined) j.part.shipping = j.result.shipping;
  }
}
