// Port of scrape.php: fetch every page of a yard's pyp.com inventory.
//   GET /scrape.php?url=<inventory url>&slug=<slug>
import type { Env } from '../env';
import { clientIp, json } from '../http';
import { rateLimit } from '../store';
import { Budget } from '../lib/budget';
import { fetchPyp, hasNextPage, parseInventoryPage, type InvVehicle } from '../lib/pyp';

const MAX_PAGES = 20;

export function slugFrom(url: string, slug: string): string {
  if (slug) return slug;
  const pos = url.indexOf('/inventory/');
  if (pos === -1) return '';
  return url.slice(pos + '/inventory/'.length).split(/[/?#]/)[0];
}

export async function handleScrape(req: Request, env: Env): Promise<Response> {
  const q = new URL(req.url).searchParams;
  const url = (q.get('url') || '').trim();
  const rawSlug = (q.get('slug') || '').trim();
  const log: string[] = [];
  if (!url && !rawSlug) return json({ error: 'No URL provided', log: [] });

  // New: the PHP version had no limit and would fetch any path on pyp.com.
  await rateLimit(env.DB, 'scrape', clientIp(req), 20, 60, 'Too many inventory fetches. Wait a minute.');

  log.push('▶ Fetching inventory for: ' + url);
  const slug = slugFrom(url, rawSlug);
  log.push('▶ Yard: ' + slug);
  if (!slug) return json({ error: 'Could not extract location slug', log });
  if (!/^[a-z0-9-]+$/i.test(slug)) return json({ error: 'Invalid yard in URL', log });

  const base = 'https://www.pyp.com/inventory/' + slug;
  const budget = new Budget();
  const vehicles: InvVehicle[] = [];
  const seen = new Set<string>();
  let page = 1;
  while (page <= MAX_PAGES) {
    const pageUrl = base + '/' + (page > 1 ? `?page=${page}` : '');
    let html: string;
    try {
      const r = await fetchPyp(budget, pageUrl, 'text/html');
      if (r.status !== 200) { log.push(`HTTP ${r.status} stopping`); break; }
      html = await r.text();
    } catch (e) {
      log.push('fetch error: ' + String(e));
      break;
    }
    const count = parseInventoryPage(html, seen, vehicles);
    if (!hasNextPage(html, page) || count === 0) break;
    page++;
    await new Promise((r) => setTimeout(r, 250)); // be polite to pyp.com, as before
  }
  log.push(`DONE: ${vehicles.length} vehicles`);
  return json({ vehicles, pages: page, log });
}
