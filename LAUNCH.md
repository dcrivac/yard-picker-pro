# Yard Picker Pro — Status & Launch Roadmap

> Single source of truth for the project. Reading top-to-bottom should get a new contributor fully up to speed: what the app is, what's live in production, what's been built recently, and what's planned next.

## What this is

AI-powered junkyard profitability analyzer. User pastes a Pick Your Part (pyp.com) yard URL, the app scrapes the inventory, and Claude returns the 5 most profitable parts to pull per vehicle with a full profit breakdown (eBay sold price minus LKQ part cost, labor, fees, shipping). Pull list is ranked by total profit potential per vehicle.

Live at **https://crivac.com/yp2.html**. Source at **https://github.com/dcrivac/yard-picker-pro** (public).

Originally built for personal use by David + one friend. Now being prepared for a public launch as a paid or freemium SaaS.

## Production status (as of this writing)

| Area | Status |
|---|---|
| App | Live, fully functional |
| Auto-deploy | Working — every merge to `main` ships within ~30s |
| Secrets | All in `public_html/.env.php` on SiteGround (gitignored) |
| eBay integration | Live data overrides Claude's price guesses |
| Security | Hardened (env-var secrets, CORS allowlist, SSL verify, rate limit, generic errors) |
| Repo visibility | Public |
| Monitoring | None yet |
| Analytics | None yet |
| Auth / accounts | None yet |
| Payments | None yet |
| ToS / Privacy Policy | None yet |

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

## Architecture today

```
┌─────────────┐                                 ┌──────────────────┐
│  Browser    │  POST /api.php (Anthropic call) │  api.php         │
│  yp2.html   │ ──────────────────────────────► │  (server-side    │
│             │                                 │   key, CORS,     │
│             │  GET /scrape.php?slug=...       │   rate limit)    │
│             │ ──────────────────────────────► │                  │
│             │                                 └─────┬────────────┘
│             │  GET /ebay.php?q=...                  │
│             │ ──────────────────────────────► ┌─────▼────────────┐
│             │                                 │  ebay-prices.php │
└─────────────┘                                 │  (OAuth + cache) │
                                                └─────┬────────────┘
                                                      │
                                  ┌───────────────────┼───────────────────┐
                                  ▼                   ▼                   ▼
                          api.anthropic.com   api.ebay.com/buy    www.pyp.com
                          (Claude Haiku 4.5)  (Browse search)     (inventory HTML)
```

Files in `public_html/`:

| File | Purpose |
|---|---|
| `yp2.html` | Full single-file web app (HTML+CSS+JS in one file for Safari compat) |
| `index.html` | Landing page |
| `api.php` | Anthropic API proxy + LKQ price injection + eBay price injection |
| `scrape.php` | pyp.com inventory scraper (curl with iPhone UA) |
| `ebay.php` | Standalone eBay Browse API lookup endpoint |
| `ebay-prices.php` | Helper: `ebayGetToken()` + `ebaySearchMedian()` |
| `ebay-deletion.php` | eBay Marketplace Account Deletion webhook |
| `.env.php` | Server-side secrets (gitignored, **not in repo**) |
| `.htaccess` | Cache control + headers |
| `.gitignore` | Excludes `.env*` etc. |

`.env.php` on the server holds:
```php
<?php
putenv('ANTHROPIC_API_KEY=sk-ant-...');
putenv('EBAY_VERIFICATION_TOKEN=...');     // 64-char random string
putenv('EBAY_CLIENT_ID=YourApp-Name-PRD-...');
putenv('EBAY_CLIENT_SECRET=PRD-...');
```

---

# Forward-looking roadmap

## The single most important decision

**Who pays for the API calls under public traffic?** Every other choice flows from this one.

Right now Crivac's Anthropic + eBay keys are used for every request. With friends-only traffic that costs ~$0/month. With a Reddit post going semi-viral, 5,000 sessions in a day could be a **$75 surprise on the card**, and someone running a script in a loop could 10× that easily.

Three viable models:

### A. Bring-your-own-key (BYOK) — pure open-source play
- User pastes their Anthropic API key into the UI; stored in `localStorage`, sent with each `/api.php` request.
- Crivac pays nothing per user. Hosting costs only.
- ~30% expected drop-off at the "go get an API key" step.
- **Probably not the right audience** — junkyard pickers aren't going to set up an Anthropic console account.

### B. Managed subscription — Crivac absorbs cost + charges users
- Stripe checkout, $5–10/mo, unlimited analyses (with per-user rate limit).
- Requires accounts, auth, billing, abuse prevention.
- Maximum addressable market. Real recurring revenue.
- Highest upfront engineering cost.

### C. Freemium — best of both
- Free tier: N analyses/month with Crivac's key (e.g. 5/mo) — gets people hooked.
- Paid tier: Stripe subscription for unlimited.
- Optional BYOK escape hatch for power users.
- Best for launch. Lets people try before paying without burning Crivac on freeloaders.

**Recommendation: Path C (freemium).** But commit to a model before anything below makes sense to build.

## Open questions needing answers

1. **Monetization model**: A / B / C above? (Recommend C.)
2. **Auth provider**: Google sign-in (lowest friction)? Magic-link email (no third-party dependency)? Both?
3. **Stripe vs LemonSqueezy** for payments? (LemonSqueezy handles global sales tax; Stripe is more flexible but you handle tax yourself.)
4. **LLC / business entity**: Do you have one? Required for taking payments under your own name.
5. **Free-tier cap**: 3 / 5 / 10 sessions per month? Per IP, per email, or per device?
6. **Pricing**: $5/mo? $10/mo? Pay-per-session ($1 each)?
7. **Branding**: Stay on `crivac.com/yp2.html`? Buy `yardpickerpro.com` and migrate?
8. **Mobile**: PWA "Add to Home Screen" at launch, or wait for a native iOS app?

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

### Phase 2 — accounts + auth

Goal: know who your users are.

- [ ] Database. SQLite on SiteGround is simplest; Supabase Postgres if you want a managed option.
- [ ] Auth: Google sign-in primary, magic-link email backup.
- [ ] Per-user rate limiting (replaces per-IP).
- [ ] User dashboard: history of past analyses, saved favorites.
- [ ] Move tracker data from `localStorage` to server-side per user.

### Phase 3 — payments

Goal: take money.

- [ ] LLC (LegalZoom ~$200, or do it yourself for ~$70 in your state).
- [ ] Stripe (or LemonSqueezy) integration.
- [ ] Free tier: N sessions/month for signed-in users.
- [ ] Paid tier: $X/mo unlimited.
- [ ] Webhook handlers for subscription lifecycle (created, cancelled, payment failed).
- [ ] Account-management UI: cancel, change card, download receipts.
- [ ] eBay attribution per their commercial-use ToS (logo + "Powered by eBay" somewhere).

### Phase 4 — growth

Goal: actually market it.

- [ ] SEO: real metadata, sitemap, blog posts on "how to flip junkyard parts on eBay."
- [ ] eBay Partner Network: earn % on eBay traffic the app drives.
- [ ] Referral codes: existing users get free month for each signup.
- [ ] Reddit posts in r/Justrolledintotheshop, r/MechanicAdvice, r/Flipping.
- [ ] TikTok demos.
- [ ] Email list capture from landing page → MailerLite or ConvertKit.
- [ ] Better landing page: real pull-list screenshots, social proof, testimonials.

### Phase 5 — moat-building

Goal: hard for competitors to copy.

- [ ] Support more yard chains (U-Pull-It, Pull-A-Part, LKQ Pick Your Part outside CA).
- [ ] Row numbers: scrape or crowdsource. "L9" beside each car is a huge UX win.
- [ ] Native iOS/Android app (React Native) for offline yard usage.
- [ ] Photo recognition: snap a VIN, get instant analysis.
- [ ] Community features: share pull lists, "what I made" leaderboard.

## Compliance items

These need actual research before going live, not just engineering:

- **Anthropic ToS**: confirm offering Claude through a proxied SaaS is allowed (generally yes, but re-read).
- **eBay Developer Agreement**: commercial use rules, branding requirements, attribution, sold-listings restrictions.
- **PYP / LKQ legal**: scraping `pyp.com` is in a gray zone. Robots.txt currently allows it; their ToS may not. Read it. Consider asking for an official feed.
- **State sales tax**: where you have nexus, you must collect. Stripe Tax or LemonSqueezy can handle this.
- **GDPR / CCPA**: privacy policy must cover user data retention and deletion requests.

## Quick wins (low effort, high leverage, do soon regardless of model)

- [ ] **Daily cost ceiling on `api.php`** — ~30 min of work, prevents disaster.
- [ ] **Add analytics now** so you have baseline metrics before launch.
- [ ] **Feedback channel** for early users.
- [ ] **Generate ToS + Privacy Policy** via Termly.io (free, ~15 min).

## For contributors (your friend reading this)

If you want to jump in:

1. **Read this doc and `CONTEXT.md`** in the repo root. CONTEXT.md has the deep technical details (file purposes, Safari quirks, AI models used, deployment specifics).
2. **Get the secrets** from David — you'll need `ANTHROPIC_API_KEY`, `EBAY_CLIENT_ID`, `EBAY_CLIENT_SECRET`, `EBAY_VERIFICATION_TOKEN` to run locally.
3. **Local dev**: clone the repo, `cp .env.example .env.php` with real values, run `php -S localhost:8000` in the repo root, hit `http://localhost:8000/yp2.html`. (`scrape.php` needs to hit a real pyp.com URL.)
4. **Pick something off Phase 1** to ship first — most things are independent and ~half-day-sized tasks.
5. **PR flow**: branch from `main`, open PR, merge → live in ~30s automatically.

Things that are deliberately *not* on the roadmap and shouldn't be added without discussion:

- Microservices, Kubernetes, anything "enterprise-scale." This app serves hundreds of users at most for the foreseeable future. SiteGround shared hosting is fine.
- Rewriting `yp2.html` as a React app. The vanilla-JS-in-one-file architecture is intentional (Safari quirks, no build step). If/when it becomes painful, split into a few `<script src>` files first; don't jump to a framework.
- New AI features that increase per-session API cost without an obvious revenue path. Cost ceiling and rate limit come first.
