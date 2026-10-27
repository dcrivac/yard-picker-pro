# Infrastructure & Deployment Notes

> Hard-won operational knowledge. Read this before touching deploys, caching, or
> the SiteGround server. Several non-obvious traps here cost a lot of debugging.

## Move to yardpicker.crivac.com (Oct 2026)

crivac.com is now the portfolio site on Cloudflare, so the app moved to its own
subdomain. The host is set by `APP_ORIGIN` in `.env.php` (see `config.php`).
CORS allowlists, Stripe redirect URLs, password-reset links and the eBay
deletion endpoint all derive from it. The frontend calls the PHP endpoints with
relative URLs. The docroot sections below describe the old SiteGround apex
setup; the deploy workflow now reads the docroot from the `DEPLOY_PATH` repo
variable.

After the subdomain is live, update these outside the repo:
- eBay developer portal: deletion endpoint URL (must match exactly, eBay re-verifies it)
- Stripe: webhook endpoint URL (new endpoint, new signing secret)
- Resend: sender domain, if reset emails use a different domain

## The real document root (critical)

crivac.com serves from:

```
~/www/crivac.com/public_html/        (resolves to /home/customer/www/crivac.com/public_html/)
```

It is **NOT** `~/public_html`. That path is a symlink to `/home/customer/public_html`,
a different folder the live site does not serve from. There are several decoy
directories on the account:

- `~/public_html` → `/home/customer/public_html` — wrong, not served
- `~/www/yardpicker.crivac.com/` — an old copy of the app (subdomain), not served by the apex
- `~/www/crivac.com/` (one level up from public_html) — has its own stray index.html/data.html
- `~/www/crivac.com/public_html/` — **the real one**

How to confirm the real docroot at any time (writes a marker, fetches it back):

```bash
TS=$(date +%s)
echo "REAL-$TS" > ~/www/crivac.com/public_html/marker-$TS.txt
curl -s "https://crivac.com/marker-$TS.txt"   # should print REAL-<TS>
rm ~/www/crivac.com/public_html/marker-$TS.txt
```

## Deploying manually (until deploy.yml is verified)

The real docroot is a git repo (we `git init`'d it). To deploy latest main:

```bash
cd ~/www/crivac.com/public_html
git fetch origin
git reset --hard origin/main      # updates tracked files; leaves .env.php + untracked alone
```

`.env.php` is gitignored, so `reset --hard` never touches it. It holds all four
secrets: `ANTHROPIC_API_KEY`, `EBAY_VERIFICATION_TOKEN`, `EBAY_CLIENT_ID`,
`EBAY_CLIENT_SECRET`. Confirm it survived with `ls -la .env.php` after any reset.

## SiteGround caching traps

1. **Edge/proxy cache** (`x-proxy-cache-info: DT:1` header). Caches GET responses
   by URL path; ignores query strings and `Cache-Control`. This *looked* like the
   villain for days but the actual cause was the wrong-docroot bug — the proxy was
   faithfully caching the (stale, decoy-directory) origin. Once deploys hit the
   real docroot, the proxy served fresh content normally. If you ever see stale
   GETs again, first confirm the file on disk in the REAL docroot is current
   (don't chase the cache until you've ruled out the docroot).

2. **PHP OpCache**. Survives `git pull` and file edits. A `touch` of the .php
   files did not reliably clear it; the double PHP-version-toggle in PHP Manager
   does. Rarely the actual problem though — usually it's #1 or the wrong docroot.

3. **POST is not cached** — `api.php` / `yp-api.php` analyses return fresh data
   each time. Only GETs (HTML, scrape.php) hit the path cache.

## The yp-* workaround files (RESOLVED — removed)

There used to be `yp-app.html` / `yp-api.php` / `yp-scrape.php` duplicates on fresh
paths, created to dodge the edge proxy serving stale copies of the canonical files.

Root cause turned out to be the wrong-docroot bug, not the proxy: deploys were
landing in `~/public_html` (a decoy) instead of `~/www/crivac.com/public_html`.
Once that was fixed and the real docroot received the current code, the proxy began
serving fresh content for the canonical paths on its own (no support ticket needed).

The `yp-*` duplicates were deleted. **Live app URL is back to
https://crivac.com/yp2.html.** No SiteGround proxy ticket required.

## eBay Browse API gotchas

- `compatibility_filter` (Year/Make/Model) REQUIRES a `category_ids` param, or the
  API returns `400 "You must provide a category ID that supports fitment."`
  Per-part category map lives in `api.php` (`$PART_CATEGORIES`), default fallback
  `6030` (Car & Truck Parts & Accessories).
- Some category IDs in that map were estimated and may be wrong — when a part shows
  "(AI est.)" despite being common, its category is probably returning junk (median
  too low, rejected by the 40%-of-AI guard). Tune by testing on the server:
  ```bash
  cd ~/www/crivac.com/public_html
  php -r 'require ".env.php"; require "ebay-prices.php"; var_dump(ebaySearchMedian("Transmission", ["Year"=>"2007","Make"=>"Chrysler","Model"=>"Aspen"], "33692"));'
  ```
- PYP price list comes from `/DesktopModules/pyp_api/api/PriceList/?locationCode=1264`
  (numeric location id, not slug), with header `x-requested-with: XMLHttpRequest`
  and a `Referer` of the prices page. Non-primo tier matches our LKQ list.

## MySQL + Stripe setup (Phase 2/3)

Provisioning steps, condensed (full walkthrough was done interactively; this is the reference version):

1. **SiteGround Site Tools → MySQL** → create a database and a user, add the user to the database with all privileges. SiteGround prefixes both names (e.g. `dbABC123_yardpicker`, `uABC123_yp`) — always copy the *exact* full name from the dashboard. A common mistake here is leaving placeholder text (like `dbxxxxx_yardpicker` from an example) in `.env.php` instead of the real generated names — this produces a `1045 Access denied` PDO error that looks like a wrong password but is actually a wrong/placeholder username.
2. **phpMyAdmin** (linked from the same MySQL page) → select the new database → SQL tab → paste `schema.sql` → Go.
3. Add `DB_HOST` (`localhost` on SiteGround), `DB_NAME`, `DB_USER`, `DB_PASS` to `.env.php`.
4. Verify from SSH before trusting the app UI:
   ```bash
   cd ~/www/crivac.com/public_html
   php -r 'require ".env.php"; $p=new PDO("mysql:host=".(getenv("DB_HOST")?:"localhost").";dbname=".getenv("DB_NAME"), getenv("DB_USER"), getenv("DB_PASS")); print_r($p->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN));'
   ```
   Expect `[users, credit_events]`. Empty array means the schema wasn't run; a PDOException means the credentials are wrong.
5. **Stripe**: dashboard → Developers → API keys → copy the test-mode secret key into `.env.php` as `STRIPE_SECRET_KEY`. Then Developers → Webhooks → Add endpoint → `https://crivac.com/stripe-webhook.php`, event `checkout.session.completed` → copy the signing secret into `.env.php` as `STRIPE_WEBHOOK_SECRET`.
6. Test with Stripe's test card `4242 4242 4242 4242`, any future expiry, any CVC.

Going live is a key swap only: `sk_test_...` → `sk_live_...`, plus a **new** webhook endpoint created in Stripe's live mode (test-mode and live-mode webhooks are separate) with its own signing secret. No code changes.

## Current production state (checkpoint)

Working and verified live on the canonical `yp2.html`:
- Row numbers extracted from PYP inventory
- 10 parts per vehicle
- Multi-yard live PYP pricing — every yard, not just Chula Vista (PR #41; the price refresh below is now superseded by this)
- Live eBay median pricing (upper-half median, category-filtered, self-healing fallback) with per-listing shipping
- Security hardening, rate limits, cost ceiling, Umami analytics, Sentry, ToS/Privacy
- **Accounts**: email/password auth, MySQL-backed (PR #43)
- **Credits**: 5 free analyses/month, then paid credits, spent per analysis (PR #44)
- **Payments**: Stripe Checkout credit packs + webhook, test-mode verified end-to-end (PR #45)
- **Revenue**: eBay Partner Network affiliate links, campaign active (PR #42)

Outstanding:
- [ ] Tune wrong eBay category IDs (a handful of parts still show AI-est; the broad-category fallback from PR #38 covers them functionally, this is pure accuracy polish)
- [ ] Password reset flow — needs a transactional email sender (Resend/Postmark), not yet set up. A lost password is currently unrecoverable.
- [ ] LLC formation before Stripe live-mode cutover
- [ ] `LAUNCH.md` compliance section needs a fresh pass now that accounts + payments exist (privacy policy language predates them)

Resolved:
- [x] `deploy.yml` now targets the real docroot — auto-deploy works (PR #38)
- [x] Edge-proxy stale content — fixed by the docroot correction, no ticket needed
- [x] `yp-*` workaround files removed; app back on canonical `yp2.html`
- [x] eBay "sold" / "how to remove" links returning 0 results for parenthetical part names (PR #43, bundled with the accounts foundation)
- [x] Profit-tier colors recolored to the intuitive green/grey/red scheme (PR #40)
