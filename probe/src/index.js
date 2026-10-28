// Throwaway probe: can a Cloudflare Worker fetch the pyp.com pages that
// scrape.php and api.php depend on? Mirrors the PHP requests (same URLs,
// User-Agent and headers) and reports what came back. Delete after use.

const UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15';

// Strings that mean we got a bot wall instead of real content.
const BLOCK_MARKERS = [
  'Just a moment', 'cf-chl', 'challenge-platform', 'Attention Required',
  'captcha', 'Access Denied', 'Incapsula', 'Request unsuccessful', 'px-captcha',
];

async function probe(name, url, headers, check) {
  const started = Date.now();
  try {
    const res = await fetch(url, { headers: { 'User-Agent': UA, ...headers }, redirect: 'follow' });
    const body = await res.text();
    const blocked = BLOCK_MARKERS.filter((m) => body.toLowerCase().includes(m.toLowerCase()));
    const result = check(body);
    return {
      name, url, status: res.status, ms: Date.now() - started, bytes: body.length,
      server: res.headers.get('server'), cfRay: res.headers.get('cf-ray'),
      blockMarkers: blocked, ...result,
      pass: res.status === 200 && result.ok,
    };
  } catch (e) {
    return { name, url, error: String(e), ms: Date.now() - started, pass: false };
  }
}

export default {
  async fetch(request) {
    const slug = new URL(request.url).searchParams.get('slug') || 'chula-vista-1264';
    const code = (slug.match(/(\d{3,})$/) || [])[1];
    const html = { Accept: 'text/html', 'Accept-Language': 'en-US,en;q=0.9' };

    const results = await Promise.all([
      // scrape.php: inventory pages
      probe('inventory p1', `https://www.pyp.com/inventory/${slug}/`, html, (b) => {
        const rows = (b.match(/<div class="pypvi_resultRow"/gi) || []).length;
        const vehicles = (b.match(/alt="\d{4}\s+[A-Z][A-Z0-9\s\-]+?\s+available for parts"/gi) || []).length;
        return { rows, vehicles, ok: vehicles > 0 };
      }),
      probe('inventory p2', `https://www.pyp.com/inventory/${slug}/?page=2`, html, (b) => {
        const vehicles = (b.match(/available for parts"/gi) || []).length;
        return { vehicles, ok: vehicles > 0 };
      }),
      // api.php fetchYardPrices(): JSON price list
      probe('price list API', `https://www.pyp.com/DesktopModules/pyp_api/api/PriceList/?locationCode=${code}`, {
        'x-requested-with': 'XMLHttpRequest', Accept: 'application/json',
        Referer: `https://www.pyp.com/prices/${slug}/`,
      }, (b) => {
        try { const rows = JSON.parse(b); return { priceRows: rows.length, ok: Array.isArray(rows) && rows.length > 0 }; }
        catch { return { priceRows: 0, ok: false }; }
      }),
      // prices.php: HTML prices page
      probe('prices page', `https://www.pyp.com/prices/${slug}/`, html, (b) => ({ ok: b.length > 5000 })),
    ]);

    const trace = await fetch('https://www.cloudflare.com/cdn-cgi/trace').then((r) => r.text()).catch(() => '');
    const egress = Object.fromEntries(trace.trim().split('\n').map((l) => l.split('=')).filter((p) => ['ip', 'colo'].includes(p[0])));

    return Response.json({
      verdict: results.every((r) => r.pass) ? 'PASS: pyp.com is reachable from Workers' : 'FAIL: see results',
      slug, egress, at: new Date().toISOString(), results,
    }, { headers: { 'Cache-Control': 'no-store' } });
  },
};
