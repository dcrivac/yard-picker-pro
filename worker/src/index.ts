// Yard Picker Pro on Cloudflare Workers.
// Keeps the old *.php paths so yp2.html works unchanged; everything else is
// served from static assets (see scripts/copy-assets.mjs).
import type { Env } from './env';
import { HttpError, json, withCors } from './http';
import { cleanup } from './store';
import { handleAuth } from './routes/auth';
import { handleApi } from './routes/api';
import { handleScrape } from './routes/scrape';
import { handleTracker } from './routes/tracker';
import { handleCheckout, handleStripeWebhook } from './routes/stripe';
import { handleEbayDebug, handleEbayDeletion, handlePrices, handleVin } from './routes/misc';

type Handler = (req: Request, env: Env) => Promise<Response>;
interface Route { handler: Handler; methods: string; credentials?: boolean; cors?: boolean }

const ROUTES: Record<string, Route> = {
  '/auth.php': { handler: handleAuth, methods: 'GET, POST, OPTIONS', credentials: true, cors: true },
  '/api.php': { handler: handleApi, methods: 'POST, OPTIONS', credentials: true, cors: true },
  '/tracker.php': { handler: handleTracker, methods: 'GET, POST, OPTIONS', credentials: true, cors: true },
  '/scrape.php': { handler: handleScrape, methods: 'GET, OPTIONS', cors: true },
  '/vin.php': { handler: handleVin, methods: 'GET, OPTIONS', cors: true },
  '/ebay.php': { handler: handleEbayDebug, methods: 'GET, OPTIONS', cors: true },
  '/checkout.php': { handler: handleCheckout, methods: 'GET' },
  '/stripe-webhook.php': { handler: handleStripeWebhook, methods: 'POST' },
  '/ebay-deletion.php': { handler: handleEbayDeletion, methods: 'GET, POST' },
  '/prices.php': { handler: handlePrices, methods: 'GET' },
};

// Server-only PHP files and repo docs that must never be served.
const BLOCKED = /^\/(\.env|config\.php|db\.php|.*-lib\.php|ebay-prices\.php|schema\.sql|.*\.md$|tests\/|deploy\/|worker\/)/i;

export default {
  async fetch(req: Request, env: Env, ctx: ExecutionContext): Promise<Response> {
    const path = new URL(req.url).pathname;
    const route = ROUTES[path];

    // Housekeeping without a cron trigger: about 1 request in 200 sweeps
    // expired rows after the response is sent.
    if (route && Math.random() < 0.005) ctx.waitUntil(cleanup(env.DB).catch((e) => console.log('cleanup failed', String(e))));

    if (!route) {
      if (BLOCKED.test(path)) return new Response('Not found', { status: 404 });
      // html_handling is "none" so /yp2.html keeps its URL (sitemap, Stripe
      // return links, reset emails); only the home page needs mapping.
      if (path === '/') return env.ASSETS.fetch(new Request(new URL('/index.html', req.url), req));
      return env.ASSETS.fetch(req);
    }

    const opts = { credentials: route.credentials, methods: route.methods };
    if (req.method === 'OPTIONS' && route.cors) return withCors(req, env, new Response(null, { status: 204 }), opts);

    let res: Response;
    try {
      res = await route.handler(req, env);
    } catch (e) {
      if (e instanceof HttpError) {
        console.log(`[${path}] ${e.status} ${e.message}`);
        res = json(e.payload ?? { error: e.message }, e.status, e.headers);
      } else {
        console.log(`[${path}] unhandled`, e instanceof Error ? e.stack || e.message : String(e));
        res = json({ error: 'Server error' }, 500);
      }
    }
    return route.cors ? withCors(req, env, res, opts) : res;
  },
} satisfies ExportedHandler<Env>;
