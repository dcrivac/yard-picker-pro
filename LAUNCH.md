# Public Launch Roadmap

Living doc for the public launch of Yard Picker Pro. Tracks open architectural questions, the recommended sequence to ship, and what's already done. Update as decisions are made.

## Status snapshot

- **Today**: Internal use by one person + a friend. Crivac pays for all Anthropic + eBay calls. CORS locked to `crivac.com`. No auth. No accounts. No payments. No analytics.
- **Goal**: Publicly marketable app where users sign up, run analyses on the yards they visit, and either bring their own API key or pay for managed access.

## The single most important decision

**Who pays for API calls under public traffic?** Every other decision flows from this one.

Three viable models:

### A. Bring-your-own-key (BYOK) — pure open-source play
- User pastes their Anthropic API key into the UI; it's stored in `localStorage` and sent with each `/api.php` request.
- Crivac pays nothing per user. Hosting costs only.
- ~30% expected drop-off at the "go get an API key" step.
- Best for: hobbyist/dev audience. Probably **not** the right audience for this app — pickers aren't going to set up an Anthropic console account.

### B. Managed subscription — Crivac absorbs cost + charges users
- Stripe checkout, $5–10/mo, unlimited analyses (rate-limited per user).
- Requires: accounts, auth, billing, abuse prevention.
- Best for: maximum addressable market. Real revenue.
- Highest upfront engineering cost.

### C. Freemium — best of both
- Free tier: N analyses/month with Crivac's key (e.g. 5/mo) — gets people hooked.
- Paid tier: Stripe subscription for unlimited.
- Optional: BYOK escape hatch for power users who want unlimited free.
- Best for: launch. Lets users try before paying without burning Crivac on freeloaders.

**Recommended: Path C** — but commit to a model before any of the below work makes sense.

## Open questions (need answers before implementation)

1. **Monetization model**: A / B / C above? (Recommend C.)
2. **Auth provider**: Google sign-in (lowest friction)? Magic link email (no third-party dependency)? Both?
3. **Stripe vs LemonSqueezy** for payments? (LemonSqueezy handles sales tax globally; Stripe is more flexible but you handle tax yourself.)
4. **LLC / business entity**: Do you have one? Required for taking payments under your own name.
5. **Free-tier cap**: 3 / 5 / 10 sessions per month? Per IP, per email, or per device?
6. **Pricing**: $5/mo? $10/mo? Pay-per-session ($1 each)?
7. **Branding**: Stay on `crivac.com/yp2.html`? Buy `yardpickerpro.com` and migrate?
8. **Mobile app**: Web-only at launch, or PWA "Add to Home Screen" push?

## Recommended sequence (after model is picked)

Each phase is shippable on its own. Don't start phase N until N-1 is in production.

### Phase 0 — what's done

- [x] Security hardening (CORS, SSL verify, rate limit, generic errors, env-var secrets)
- [x] eBay Marketplace Account Deletion endpoint (required for production eBay API)
- [x] eBay Browse API price lookup (`ebay.php` standalone + `api.php` injection)
- [x] Auto-deploy via SSH `git pull` (currently broken on `api.php` — fix in Phase 1)

### Phase 1 — public readiness (no auth yet)

Goal: app survives being shared on Reddit without bleeding money.

- [ ] **Cost ceiling**: hard daily budget cap in `api.php`. If today's Anthropic spend (tracked via a counter file) exceeds $X, return a "service paused for the day" message. Prevents catastrophic surprise bills.
- [ ] **Tighter per-IP rate limit** for unauthenticated users (e.g. 3 analyses/day total, not 10/min).
- [ ] **Analytics**: Plausible or Umami (privacy-friendly, no cookie banner needed). Track: unique visitors, sessions started, sessions completed, error rate.
- [ ] **Error reporting**: Sentry free tier for client-side JS errors and server-side PHP errors.
- [ ] **Fix the auto-deploy workflow** (currently fails on `api.php` due to drift). Either rewrite to scp or stash before pull.
- [ ] **ToS + Privacy Policy** pages. Required for both Anthropic and eBay commercial usage.
- [ ] **Buy `yardpickerpro.com`** (or your preferred domain) and set up redirect from `crivac.com/yp2.html`.

### Phase 2 — accounts + auth

Goal: know who your users are.

- [ ] Database. SQLite on SiteGround is simplest; Supabase Postgres if you want serverless.
- [ ] Auth: Google sign-in via `google-auth-library`. Magic-link email backup.
- [ ] Per-user rate limiting (replace per-IP).
- [ ] User dashboard: history of past analyses, saved favorites.
- [ ] Move tracker data from `localStorage` to server-side per user.

### Phase 3 — payments

Goal: take money.

- [ ] LLC (LegalZoom ~$200, or do it yourself for ~$70 in your state).
- [ ] Stripe (or LemonSqueezy) integration.
- [ ] Free tier: N sessions/month for signed-in users.
- [ ] Paid tier: $X/mo unlimited.
- [ ] Webhook handlers for subscription lifecycle (created, cancelled, payment failed).
- [ ] Account management UI: cancel subscription, change card, download receipts.
- [ ] eBay attribution per their commercial-use ToS (logo + "Powered by eBay" somewhere).

### Phase 4 — growth

Goal: actually market it.

- [ ] SEO: real metadata, sitemap, blog posts on "how to flip junkyard parts on eBay."
- [ ] Affiliate program for eBay (eBay Partner Network) — earn % on eBay traffic the app drives.
- [ ] Referral codes: existing users get free month for each signup.
- [ ] Reddit posts in r/Justrolledintotheshop, r/MechanicAdvice, r/Flipping.
- [ ] TikTok demos.
- [ ] Email list: capture from landing page → MailerLite or ConvertKit.
- [ ] Better landing page: add screenshots of real pull lists, social proof, testimonials.

### Phase 5 — moat-building

Goal: hard for competitors to copy.

- [ ] Support more yard chains (U-Pull-It, Pull-A-Part, LKQ Pick Your Part outside CA).
- [ ] Row numbers: scrape or crowdsource. "L9" beside each car is a huge UX win.
- [ ] Native iOS/Android app (React Native) for offline yard usage.
- [ ] Photo recognition: snap a VIN or photo of a row, get instant analysis.
- [ ] Community features: share pull lists, post "what I made" leaderboard.

## Open compliance items

These need actual research, not just engineering. Before going live:

- **Anthropic ToS**: confirm offering Claude through a proxied SaaS is allowed. (It generally is, but worth re-reading.)
- **eBay Developer Agreement**: commercial use rules, branding requirements, attribution, sold-listings restrictions.
- **PYP / LKQ legal**: scraping `pyp.com` is in a gray zone. Robots.txt currently allows it; their ToS may not. Read it. Consider asking for an official feed or API.
- **State sales tax**: where you have nexus, you must collect. Stripe Tax or LemonSqueezy can handle this.
- **GDPR / CCPA**: privacy policy must cover user data retention, deletion requests.

## Quick wins worth doing soon (regardless of monetization choice)

These are non-blocking but high-leverage:

- [ ] Add analytics now so you have baseline metrics before launch.
- [ ] Daily cost ceiling on `api.php` — costs ~30 min, prevents disaster.
- [ ] Set up a feedback channel (Tally.so form or Discord) so early users can report bugs.
- [ ] Write the ToS + Privacy Policy now (Termly.io generates them for free; takes 15 min).
