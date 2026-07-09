# Yard Picker Pro — Status & Launch Roadmap

> Single source of truth for the project. Reading top-to-bottom should get a new contributor fully up to speed: what the app is, what's live in production, what's been built recently, and what's planned next.

## What this is

AI-powered junkyard profitability analyzer. User pastes a Pick Your Part (pyp.com) yard URL, the app scrapes the inventory, and Claude returns the 5 most profitable parts to pull per vehicle with a full profit breakdown (eBay sold price minus LKQ part cost, labor, fees, shipping). Pull list is ranked by total profit potential per vehicle.

Live at **https://crivac.com/yp2.html**. Source at **https://github.com/dcrivac/yard-picker-pro** (public).

Originally built for personal use by David + one friend. **Now a monetized, multi-yard, publicly launched product** with accounts and paid credit packs.

## Production status (as of this writing)

| Area | Status |
|---|---|
| App | Live, fully functional, works at any PYP yard location |
| Auto-deploy | Working — merges to `main` deploy to the real docroot in ~30s (see `INFRA.md` for the docroot history) |
| Secrets | All in `public_html/.env.php` on SiteGround (gitignored) |
| eBay integration | Live median pricing + per-listing shipping, per-part category filtering with self-healing fallback |
| PYP pricing | Live per-yard prices fetched from PYP's own API, not hardcoded |
| Security | Hardened (env-var secrets, CORS allowlist, SSL verify, rate limit, generic errors, brute-force-limited auth) |
| Repo visibility | Public |
| Monitoring | Sentry (client-side JS errors on `yp2.html` + `index.html`) |
| Analytics | Umami Cloud (page views + funnel events) |
| Auth / accounts | **Live** — email/password, MySQL-backed, bcrypt hashed |
| Payments | **Live** — Stripe Checkout, 2 credit packs, webhook-driven crediting (test mode; live-mode cutover is a key swap, no code change) |
| Credits / metering | **Live** — 5 free analyses/month per account, then paid credits; every `api.php` analysis call spends 1 |
| Revenue | **Live** — eBay Partner Network affiliate links (campaign active) + credit pack sales |
| ToS / Privacy Policy | Live, linked from both footers (see Phase 1 below) |

## Recently shipped (this session)

In rough chronological order. PR numbers reference https://github.com/dcrivac/yard-picker-pro/pulls.

### Security hardening — PR #8
- **API key out of source code**. `api.php` previously had `define('ANTHROPIC_KEY', 'YOUR_API_KEY_HERE')` and was hand-patched on the server after every deploy (fragile). Now reads `ANTHROPIC_API_KEY` from env via a gitignored `public_html/.env.php`.
- **CORS lockdown**. `api.php` and `scrape.php` were sending `Access-Control-Allow-Origin: *` — any third-party site could call them from a visitor's browser and spend our credits. Now restricted to `https://crivac.com` / `https://www.crivac.com` with proper `Vary: Origin`.
- **SSL verification re-enabled** in `scrape.php` (`CURLOPT_SSL_VERIFYPEER => true`). Previously off, leaving the PYP fetch MITM-able.
- **Per-IP rate limit**: 10 req/min on `api.php` via file-based counter in temp dir. Returns 429 on overflow.
- **Input validation**: rejects bodies > 20KB, allowlist on `model` (`claude-haiku-*` / `claude-sonnet-*`), `max_tokens` capped at 4096, requires `messages` array.
- **Generic error responses**: failures funnel through a `fail()` helper that logs details via `error_log()` and returns `{"error":{"message":"Request failed"}}`. Previously upstream Anthropic error bodies (and cURL internals) were proxied verbatim, leaking billing state.

### eBay Marketplace Account Deletion endpoint — PR #9
Required by eBay before granting production API access. `ebay-deletion.php`:
- Handles verification handshake (`GET ?challenge_code=...` → SHA-256 hash response per eBay spec).
- Acknowledges deletion notifications with 200 and logs them. Yard Picker Pro stores no eBay user data (Browse API only returns public listings), so there's nothing to scrub.
- Reads `EBAY_VERIFICATION_TOKEN` from env / `.env.php`.

### eBay Browse API price lookup — PRs #10 + #11
Two-step ship: standalone endpoint first to validate data quality, then wired into the main flow.

**`ebay-prices.php`** — helper exposing `ebaySearchMedian($query)`:
- OAuth 2.0 `client_credentials` grant. Access token cached in temp dir for ~2h.
- Query results cached for 24h (SHA-256 of query as cache key). Cuts repeat-yard API calls by ~90%, keeps us well under the 5000/day Browse quota.
- Outlier guard: drops listings outside $5–$10,000.
- Returns `null` if fewer than 3 usable listings, so callers can fall back.
- Float precision fix (`serialize_precision=-1`) — SiteGround's default emits floats like `28.989999999999998` for what should be `28.99`. PR #11.

**`ebay.php`** — standalone `GET ebay.php?q=2009+CHEVROLET+SILVERADO+ECU` returning `{avg, low, high, count}`. Same CORS allowlist as the rest.

### eBay prices wired into the analysis flow — PR #12
`api.php` now overrides Claude's `ebayAvg` / `ebayLow` / `ebayHigh` estimates with live Browse API medians for every part in every analysis.

- Parses `Car N: YYYY MAKE MODEL [Row X]` lines back out of the prompt to recover per-car metadata (Claude's response only includes `carIndex` + parts).
- Composes a query like `"2009 CHEVROLET SILVERADO ECU"` per part and calls `ebaySearchMedian()`.
- **Accessory-bleed guard**: only overrides when eBay's median is ≥ 40% of Claude's estimate. Otherwise (e.g. ECU searches return lots of $20 brackets and connectors that drag the median below the real part price), keeps the AI estimate.
- Frontend now displays green **"(live · N listings)"** next to each eBay average when real data was used, or gray **"(AI est.)"** for the accessory-bleed-guarded fallback.

### Auto-deploy fixes — PRs #13, #14, #15
The GitHub Actions deploy workflow at `.github/workflows/deploy.yml` had been silently broken for months. Required 3 sequential fixes:

- **PR #13**: `git pull` was refusing to overwrite tracked files that had been hand-edited or replaced via SiteGround File Manager. Added `git checkout -- .` before `git pull` to discard tracked drift. Safe because `.env.php` is gitignored and untracked uploads are untouched.
- **PR #14**: the workflow used SSH (`git@github.com:`) but SiteGround's `~/.ssh/known_hosts` had no github.com host key. Every `git fetch` failed with `Host key verification failed`. Switched the remote to HTTPS.
- **PR #15**: added `workflow_dispatch:` trigger so deploys can be fired manually from the Actions tab without a dummy commit.

Followed by manual user action: **repo visibility flipped to public** so HTTPS `git pull` no longer requires auth.

**End result**: every PR merge now auto-deploys to SiteGround in ~30s. No more File Manager uploads except for `.env.php` edits.

### Phase 1 completion — PRs #16–22

Umami analytics + custom funnel events, Terms of Service + Privacy Policy pages, a `mailto:` feedback link, Sentry error reporting, a daily Anthropic cost ceiling ($5/day, `DAILY_COST_CEILING_USD`), and a per-IP daily request cap (50/day on top of the existing 10/min). All the "survive being shared publicly" items from Phase 1 below.

### The docroot bug hunt — PRs #23–39

The single biggest time sink of the project. Short version: **every deploy for weeks landed in the wrong folder.** `deploy.yml` ran `git pull` in `~/public_html`, which is a symlink to a decoy directory the live site never served from — the real docroot is `~/www/crivac.com/public_html`. Every "the eBay prices still look stale" or "the site is caching something" investigation during this window chased SiteGround's edge proxy and OpCache, both innocent; the actual bug was one `cd` in a YAML file. Full postmortem, the decoy-directory map, and the diagnostic commands live in **`INFRA.md`** — read it before touching deploy or caching again.

Once the docroot was fixed (PR #38), the *real* remaining issues surfaced fast and got fixed the same day:
- eBay's `compatibility_filter` (Year/Make/Model matching) requires a `category_ids` param or it 400s. Added a ~80-entry per-part category map plus a self-healing fallback to the broad "Car & Truck Parts" category when a narrow one returns nothing usable (PR #37, #38).
- Replaced simple-median eBay pricing with an upper-half median to resist "accessory bleed" (cheap brackets/connectors dragging the price down) — PR #29.
- Refreshed the entire hardcoded LKQ price list from PYP's live pricing API — PR #32. (Superseded by PR #41, see below — prices are no longer hardcoded at all.)
- Fixed PYP row-number extraction (`scrape.php` was anchoring on the wrong HTML element) — PR #33.

### Multi-yard support — PR #41

The app was hardcoded to Chula Vista pricing. Now `api.php` fetches the visited yard's live price list from PYP's own internal pricing API (keyed by the numeric locationCode in the inventory slug), caches it 24h, and overrides the fallback price table. Works at any PYP location, and prices never go stale again — no more manual refreshes like PR #32's.

### UX + bug fixes — PRs #40, #43 (partial)

- Per-part profit colors recolored to the intuitive scheme: green ≥ $50, grey $0–49, red negative (was orange/green, which read backwards).
- Fixed the "eBay sold" and "how to remove" outbound links: they searched the literal part name including parenthetical qualifiers (`Engine (Long Block)`), which almost never appears in real listing titles and returned 0 results. Now stripped before building the search query.

### Revenue: eBay Partner Network — PR #42

Every "eBay sold →" link now carries an EPN affiliate tag (campaign `5339163850`, active). Zero change to recommendations — same links, just tagged. FTC disclosure auto-appears in the results footer. This was revenue stream #1, shippable with zero new infrastructure since the app already sent this traffic to eBay for free.

### Phase 2: accounts, credits, payments — PRs #43, #44, #45

The big one. Three-phase build, each independently shippable:

- **2a (accounts, PR #43)**: MySQL database (`schema.sql`: `users` + `credit_events` ledger), email/password auth (`auth.php`, bcrypt via `password_hash`, rate-limited, session-cookie based), login/register modal in `yp2.html`. Shipped as a no-op until the DB was provisioned — anonymous use kept working throughout.
- **2b (metering, PR #44)**: 5 free analyses/month per account (config: `FREE_MONTHLY`), then spends purchased credits. `api.php` checks credits before calling Anthropic and deducts 1 only after a successful response (failed calls aren't charged). `X-Credits-Remaining` response header keeps the UI in sync.
- **2c (payments, PR #45)**: Stripe Checkout for two credit packs ($5 → 30 analyses, $15 → 100 analyses), plain REST calls via curl (no SDK/composer dependency — consistent with how the app talks to eBay). `stripe-webhook.php` verifies the `Stripe-Signature` header manually and credits the account on `checkout.session.completed`, idempotent via a `UNIQUE(ref, reason)` constraint so Stripe's webhook retries can't double-credit.

**Verified working end-to-end in test mode**: register → free analyses → buy credits → Stripe checkout → webhook fires → balance updates → out-of-credits gate re-engages correctly. Flipping to live payments is a key swap (`sk_test_...`/`whsec_...` → live equivalents + a new live-mode webhook endpoint), no code changes needed.

## Architecture today

```
┌─────────────┐  POST /api.php (Anthropic + credit spend) ┌──────────────────┐
│  Browser    │ ──────────────────────────────────────────►│  api.php         │
│  yp2.html   │  GET /scrape.php?slug=...                   │  db.php          │
│             │ ──────────────────────────────────────────►│  ebay-prices.php │
│             │  POST /auth.php {register,login,logout}     └─────┬───┬────────┘
│             │ ──────────────────────────────────────────►       │   │
│             │  GET /checkout.php?pack=...                       │   │
│             │ ──────────────────────────────────────────► ┌─────▼┐ ┌▼─────────┐
└─────────────┘                                              │MySQL │ │eBay/PYP  │
       ▲                                                     │(users│ │APIs      │
       │  redirect to Stripe hosted checkout                 │credit│ └──────────┘
       └──────────────────────────────────────────────────── │events│
                                                               └──────┘
Stripe ──POST checkout.session.completed──► stripe-webhook.php ──► credits user, MySQL
```

Files in `public_html/`:

| File | Purpose |
|---|---|
| `yp2.html` | Full single-file web app (HTML+CSS+JS in one file for Safari compat) |
| `index.html` | Landing page |
| `api.php` | Anthropic API proxy + LKQ/eBay price injection + credit check & spend |
| `scrape.php` | pyp.com inventory scraper (curl with iPhone UA) |
| `ebay.php` | Standalone eBay Browse API lookup endpoint |
| `ebay-prices.php` | Helper: `ebayGetToken()` + `ebaySearchMedian()` (category-aware, upper-half median) |
| `ebay-deletion.php` | eBay Marketplace Account Deletion webhook |
| `prices.php` | One-off / on-demand PYP yard price list fetcher (used by `api.php`'s multi-yard lookup) |
| `db.php` | Shared MySQL (PDO) connection + session/auth helpers (`currentUser()`, `creditStatus()`) |
| `auth.php` | Register / login / logout / current-session endpoint |
| `checkout.php` | Creates a Stripe Checkout Session for a credit pack, redirects to Stripe |
| `stripe-webhook.php` | Verifies Stripe webhook signature, credits the account on paid checkout |
| `schema.sql` | MySQL schema (`users`, `credit_events`) — run once in phpMyAdmin |
| `.env.php` | Server-side secrets (gitignored, **not in repo**) |
| `.htaccess` | Cache control + headers |
| `.gitignore` | Excludes `.env*` etc. |

`.env.php` on the server holds (see `.env.example` for the authoritative, commented list):
```php
<?php
putenv('ANTHROPIC_API_KEY=sk-ant-...');
putenv('EBAY_VERIFICATION_TOKEN=...');     // 64-char random string
putenv('EBAY_CLIENT_ID=YourApp-Name-PRD-...');
putenv('EBAY_CLIENT_SECRET=PRD-...');
putenv('DB_HOST=localhost');
putenv('DB_NAME=dbxxxxxxxx_yardpicker');
putenv('DB_USER=uxxxxxxxx_yp');
putenv('DB_PASS=...');
putenv('STRIPE_SECRET_KEY=sk_test_...');   // sk_live_... once cut over
putenv('STRIPE_WEBHOOK_SECRET=whsec_...');
```

---

# Forward-looking roadmap

## The monetization decision — RESOLVED

Went with a **hybrid of freemium credits + affiliate revenue**, not a pure subscription:

- **Free tier**: 5 analyses/month per account (config: `FREE_MONTHLY` in `.env.php`).
- **Credit packs** (usage-based, not subscription): $5 → 30 analyses, $15 → 100 analyses, via Stripe Checkout. Chosen over a flat monthly subscription because picker usage is bursty (weekend yard trips, not daily) — pay-as-you-go maps better to that pattern than a recurring charge someone forgets about.
- **eBay Partner Network affiliate links** as a second, independent revenue stream layered on top — zero incremental cost, already-existing traffic.
- Auth is email/password (not Google/magic-link) — simplest to build and audit, no third-party OAuth dependency. Password reset is deferred (needs a transactional email service — Resend/Postmark free tier — not yet set up).

This is genuinely live in production (test-mode Stripe; live-mode cutover is a key swap only).

## Open questions still needing answers

1. **LLC / business entity**: still needed before this can take *real* money at scale — required for Stripe live mode payouts and clean tax treatment. Not yet formed.
2. **Live-mode Stripe cutover**: when to flip from test to live keys. No code change, just new keys + a new live-mode webhook endpoint in the Stripe dashboard.
3. **Password reset flow**: needs a transactional email sender. Free tier of Resend or Postmark, ~30 min to wire up.
4. **Free-tier cap tuning**: is 5/month the right number once real usage data comes in via Umami/Sentry? Config is a single env var, easy to adjust.
5. **Branding**: still on `crivac.com/yp2.html`. Buy `yardpickerpro.com` and migrate? Low urgency, doesn't block revenue.
6. **Mobile**: PWA "Add to Home Screen," or wait for a native app? Still open, still low priority — the mobile *web* experience already works well.

## Phased roadmap

Each phase is shippable on its own. Don't start phase N until N-1 is in production.

### Phase 0 — done ✓

- Security hardening
- eBay Marketplace Account Deletion endpoint
- eBay Browse API price lookup + integration
- Auto-deploy working end-to-end

### Phase 1 — public readiness (no auth yet)

Goal: app survives being shared publicly without bleeding money.

- [x] **Daily cost ceiling** in `api.php` — PR #17. $5/day default, tunable via `DAILY_COST_CEILING_USD` env var.
- [x] **Tighter per-IP rate limit** — PR #17. 50 req/day per IP, on top of the existing 10/min limit.
- [x] **Analytics** — PR #18. Umami Cloud free tier, no cookie banner. Tracking site visits + 4 custom funnel events (`analyze_clicked`, `analysis_complete`, `ebay_link_clicked`, `add_to_tracker`).
- [x] **Error reporting** — PR #22. Sentry Loader Script on both `yp2.html` and `index.html`. PHP-side server errors still go to `error_log` only; can add Sentry PHP SDK later if needed.
- [x] **ToS + Privacy Policy** pages — PR #19. `/terms.html` + `/privacy.html`, linked from both footers. Effective date / jurisdiction / business name are placeholders to revisit when forming an LLC.
- [x] **Feedback channel** — PR #21. `mailto:` link for now; swap to a Tally form URL later if email volume gets noisy.
- [ ] **Buy a real domain** (e.g. `yardpickerpro.com`) and redirect from `crivac.com/yp2.html`. Manual purchase + DNS step.

### Phase 2 — accounts + auth — DONE ✓ (PR #43)

- [x] Database — MySQL on SiteGround (`schema.sql`).
- [x] Auth — email/password, bcrypt, rate-limited, session-cookie based.
- [ ] Per-user rate limiting (currently still per-IP in `api.php`; the credit system is the real usage cap now, so this is lower priority than it was).
- [ ] User dashboard: history of past analyses, saved favorites. Not built — currently no analysis history is persisted server-side, only credit balance.
- [ ] Move tracker data (`localStorage`-based profit tracker in `yp2.html`) to server-side per user. Still client-only.

### Phase 3 — payments — DONE ✓ (PRs #44, #45)

- [ ] LLC (LegalZoom ~$200, or do it yourself for ~$70 in your state). **Still needed before going live with real charges.**
- [x] Stripe integration — Checkout Sessions for one-time credit packs (not a subscription; see monetization decision above).
- [x] Free tier — 5 analyses/month per signed-in account (`FREE_MONTHLY`).
- [x] Paid tier — credit packs, no expiry.
- [x] Webhook handler — `stripe-webhook.php`, verified signature, idempotent crediting.
- [ ] Account-management UI: view purchase history, download receipts. Not built — Stripe's own customer portal could cover this cheaply if enabled in the Stripe dashboard.
- [ ] eBay attribution per their commercial-use ToS (logo + "Powered by eBay" somewhere) — not yet added; EPN affiliate disclosure is live, but the branding requirement is separate and worth double-checking against eBay's current developer agreement.

### Phase 4 — growth

Goal: actually market it.

- [x] SEO: real metadata, sitemap, robots.txt, Open Graph/Twitter tags, favicon — PR #48.
- [x] eBay Partner Network — PR #42. Campaign `5339163850` active on every eBay outbound link.
- [ ] Referral codes: existing users get free credits for each signup.
- [x] First Reddit traffic test — see "Growth channel notes" below.
- [ ] TikTok demos.
- [ ] Email list capture from landing page → MailerLite or ConvertKit.
- [ ] Better landing page: real pull-list screenshots, social proof, testimonials.

#### Growth channel notes (from the first Reddit push)

Builder-audience subreddits (r/SideProject, r/SaaS, r/indiehackers, r/microsaas) test whether *other builders* find the idea interesting, not whether the actual target audience (junkyard part flippers) wants it. Worth remembering before re-running this playbook:

- **r/SideProject, r/SaaS, r/indiehackers, r/microsaas** — every attempt got auto-removed or mod-removed, regardless of content (tested plain prose, zero links, no bullet lists — none of it mattered). Root cause confirmed via Automod's own message on one attempt: per-subreddit comment-karma gate (10 karma minimum, earned *within that specific subreddit*, not sitewide). Fix is building a little real karma in each sub first, not rewriting the post.
- **r/buildinpublic** — the one channel that worked. Survived on the first attempt, no gate hit. 8 replies of genuine back-and-forth from real builders. Two independent commenters gave the same advice: post in the exact niche where the pain already lives (comment, don't broadcast), and specific, honest "plain breakdown" posts outperform polished feature-list pitches.
- **r/Flipping** — confirmed real target-audience overlap, but bans self-promotion posts outright. Only viable via organic participation: reply to existing threads where someone's asking what's worth pulling/flipping, mention the tool only if genuinely relevant.
- **r/Justrolledintotheshop** — wrong community entirely (posts photos of weird/broken cars, not a tools/parts-sourcing discussion space). Don't reuse this one.
- **PYP/LKQ-specific Facebook groups** — flagged by a commenter as a likely real channel, unverified. Facebook groups don't surface in web search, so this needs to be found and joined manually.
- Umami events (`buy_credits_opened`, `checkout_started`, the full signup→analyze funnel) are the source of truth for whether any channel actually converts, not comment count or upvotes.

### Phase 5 — moat-building

Goal: hard for competitors to copy.

- [ ] Support more yard chains (U-Pull-It, Pull-A-Part, LKQ Pick Your Part outside CA).
- [ ] Row numbers: scrape or crowdsource. "L9" beside each car is a huge UX win.
- [ ] Native iOS/Android app (React Native) for offline yard usage.
- [ ] Photo recognition: snap a VIN, get instant analysis.
- [ ] Community features: share pull lists, "what I made" leaderboard.

## Compliance items

These need actual research before real money flows at scale, not just engineering. Now more urgent than when this list was first written, since payments are live:

- **LLC formation** — the single biggest gap. Taking real payments as an individual (not an entity) is a personal liability and tax-reporting exposure once volume is non-trivial. Do this before flipping Stripe to live mode.
- **Anthropic ToS**: confirm offering Claude through a proxied, paid SaaS is allowed (generally yes for this scale, but re-read given payments are now involved).
- **eBay Developer Agreement**: commercial use rules, branding/attribution requirements (see the unchecked Phase 3 item above), sold-listings restrictions. Also re-check EPN's specific program terms now that the campaign is active.
- **PYP / LKQ legal**: scraping `pyp.com` (both inventory and the pricing API used for multi-yard support) is in a gray zone. Robots.txt currently allows it; their ToS may not. Read it. Consider asking for an official feed, especially now that the app depends on their internal pricing API more heavily.
- **State sales tax on credit sales**: where you have nexus, you must collect. Stripe Tax can be enabled on the existing Checkout Sessions with minimal code change.
- **GDPR / CCPA**: privacy policy needs an update pass — it was written pre-accounts and should now describe what's stored for registered users (email, hashed password, credit balance, purchase history) and the deletion process for account data, not just the anonymous-analytics language it currently has.
- **PCI scope**: none — Stripe Checkout is fully hosted, card data never touches the server. Worth stating explicitly if this ever comes up in a security review.

## Quick wins (low effort, high leverage)

- [x] ~~Daily cost ceiling on `api.php`~~ — done, PR #17.
- [x] ~~Add analytics~~ — done, PR #18 (Umami) + Sentry, PR #22.
- [x] ~~Feedback channel~~ — done, PR #21.
- [x] ~~Generate ToS + Privacy Policy~~ — done, PR #19. **Needs a fresh pass now that accounts + payments exist** (see compliance items above).
- [ ] **Password reset flow** — needs a transactional email sender (Resend/Postmark free tier). Currently a lost password is unrecoverable; support this before real users accumulate.
- [ ] **Stripe customer portal** — enabling it in the Stripe dashboard gives users self-serve purchase history/receipts with near-zero code.

## For contributors (your friend reading this)

If you want to jump in:

1. **Read this doc, `INFRA.md`, and `CONTEXT.md`** in the repo root, in that order. `INFRA.md` especially — it documents the real production docroot, SiteGround's caching quirks, and several bugs that ate real debugging time; skipping it risks re-discovering them the hard way.
2. **Get the secrets** from David — the full list is `.env.example`. As of Phase 2/3 that's `ANTHROPIC_API_KEY`, `EBAY_CLIENT_ID`/`EBAY_CLIENT_SECRET`/`EBAY_VERIFICATION_TOKEN`, `DB_HOST`/`DB_NAME`/`DB_USER`/`DB_PASS`, and `STRIPE_SECRET_KEY`/`STRIPE_WEBHOOK_SECRET` — noticeably more than the read-only-API days.
3. **Local dev**: clone the repo, `cp .env.example .env.php` with real values, run `php -S localhost:8000` in the repo root, hit `http://localhost:8000/yp2.html`. (`scrape.php` needs to hit a real pyp.com URL. Auth/credits/checkout need a local or shared MySQL instance and Stripe test-mode keys — ask David for a dev DB or point at a local MySQL with `schema.sql` run against it.)
4. **Pick something off Phase 4 or the compliance list** to ship first — Phases 1–3 are done. Most remaining items are independent and ~half-day-sized.
5. **PR flow**: branch from `main`, open PR, merge → live in ~30s automatically, targeting the real docroot (see `INFRA.md`).

Things that are deliberately *not* on the roadmap and shouldn't be added without discussion:

- Microservices, Kubernetes, anything "enterprise-scale." This app serves hundreds of users at most for the foreseeable future. SiteGround shared hosting is fine.
- Rewriting `yp2.html` as a React app. The vanilla-JS-in-one-file architecture is intentional (Safari quirks, no build step). If/when it becomes painful, split into a few `<script src>` files first; don't jump to a framework.
- New AI features that increase per-session API cost without an obvious revenue path. Cost ceiling and rate limit come first.
