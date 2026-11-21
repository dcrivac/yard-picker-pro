// Port of analyze-lib.php plus findLKQPrice from api.php. Pure helpers, no I/O.

export interface CleanVehicle {
  year: string; make: string; model: string; row: string;
  trim: string; engine: string; drive: string; transmission: string;
}

const FIELDS: [keyof CleanVehicle, boolean][] = [
  ['make', true], ['model', true], ['row', false], ['trim', false], ['engine', false], ['drive', false], ['transmission', false],
];
const REQUIRED_RE = /^[A-Za-z0-9 .,\/()&+\-]{1,40}$/;
const STRIP_RE = /[^A-Za-z0-9 .,\/()&+\-]/g;

export function validateVehicles(list: unknown): [CleanVehicle[] | null, string | null] {
  if (!Array.isArray(list) || list.length < 1 || list.length > 5) return [null, 'Send 1 to 5 vehicles'];
  const maxYear = new Date().getUTCFullYear() + 1;
  const out: CleanVehicle[] = [];
  for (let i = 0; i < list.length; i++) {
    const v = list[i];
    if (!v || typeof v !== 'object' || Array.isArray(v)) return [null, `Vehicle ${i} is not an object`];
    const rec = v as Record<string, unknown>;
    const year = rec.year == null ? '' : String(rec.year).trim();
    if (!/^\d{4}$/.test(year) || Number(year) < 1950 || Number(year) > maxYear) return [null, `Vehicle ${i}: bad year`];
    const clean = { year } as CleanVehicle;
    for (const [k, required] of FIELDS) {
      let s = rec[k] == null ? '' : String(rec[k]).trim();
      if (required) {
        if (s === '') return [null, `Vehicle ${i}: missing ${k}`];
        if (!REQUIRED_RE.test(s)) return [null, `Vehicle ${i}: bad ${k}`];
      } else {
        // Optional details come from vPIC or the user; drop odd characters.
        s = s.replace(STRIP_RE, '').trim().slice(0, 60);
      }
      clean[k] = s;
    }
    out.push(clean);
  }
  return [out, null];
}

export function buildAnalysisPrompt(vehicles: CleanVehicle[], partNames: string[], prices: Record<string, number>): string {
  const lines = vehicles.map((v, i) => {
    let line = `Car ${i}: ${v.year} ${v.make} ${v.model}`;
    const details = [v.trim, v.engine, v.drive, v.transmission].filter((s) => s.length > 0);
    if (details.length) line += ` (${details.join(', ')})`;
    if (v.row !== '') line += ` Row ${v.row}`;
    return line;
  });
  const list = partNames.map((n) => `${n} ($${(prices[n] ?? 50).toFixed(2)})`);
  return 'You are a junkyard parts expert. For each vehicle pick the 10 most profitable parts to pull and sell on eBay.'
    + '\n\nVehicles:\n' + lines.join('\n')
    + '\n\nWhen a vehicle lists details in parentheses (trim, engine, drive, transmission), only pick parts this exact vehicle has: no turbocharger unless it says turbo, no transfer case unless it is 4WD or AWD, no hybrid parts unless it is a hybrid.'
    + '\n\nYou MUST choose part names ONLY from this list — use the EXACT name and copy the lkqPrice shown into your JSON:\n'
    + list.join(', ')
    + '\n\nReturn ONLY a JSON array, no markdown. Copy lkqPrice exactly from the list above:\n'
    + '[{"carIndex":0,"parts":[{"name":"ECU / PCM","reason":"High demand, sells fast","ebayAvg":420,"ebayLow":280,"ebayHigh":600,"lkqPrice":92.09}]}]'
    + '\nExactly 10 parts per car. Prices in whole USD.';
}

// Fuzzy match: best LKQ price for a part name (port of findLKQPrice).
export function findLKQPrice(name: string, lkq: Record<string, number>): { price: number; matched: string } {
  if (Object.prototype.hasOwnProperty.call(lkq, name)) return { price: lkq[name], matched: name };
  const nu = name.toUpperCase();
  const split = (s: string) => s.split(/[\s\/\(\)]+/);
  const nw = split(nu);
  let bestKey: string | null = null, bestScore = 0;
  for (const k of Object.keys(lkq)) {
    const ku = k.toUpperCase();
    if (nu.includes(ku) || ku.includes(nu)) {
      const score = Math.min(nu.length, ku.length);
      if (score > bestScore) { bestScore = score; bestKey = k; }
    }
    const kw = split(ku);
    let overlap = 0;
    for (const w of nw) if (w.length > 3 && kw.includes(w)) overlap++;
    if (overlap > 0 && overlap * 10 > bestScore) { bestScore = overlap * 10; bestKey = k; }
  }
  return bestKey ? { price: lkq[bestKey], matched: bestKey } : { price: 50, matched: 'DEFAULT' };
}
