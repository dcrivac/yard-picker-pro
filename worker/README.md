# Yard Picker Pro on Cloudflare Workers

A TypeScript port of the PHP backend, built for the Workers **free plan**.
The `*.php` URLs are kept, so `yp2.html` and `index.html` run unchanged.

| PHP file | Worker module |
| --- | --- |
| auth.php, db.php | `src/routes/auth.ts`, `src/session.ts`, `src/password.ts` |
| api.php, analyze-lib.php, ebay-prices.php | `src/routes/api.ts`, `src/lib/analyze.ts`, `src/lib/ebay.ts` |
| scrape.php, prices.php | `src/routes/scrape.ts`, `src/routes/misc.ts`, `src/lib/pyp.ts` |
| tracker.php | `src/routes/tracker.ts` |
| checkout.php, stripe-webhook.php | `src/routes/stripe.ts` |
| vin.php, vin-lib.php | `src/routes/misc.ts`, `src/lib/vin.ts` |
| ebay.php, ebay-deletion.php | `src/routes/misc.ts` |
| MySQL (schema.sql) and temp files | D1 (`migrations/0001_init.sql`) |

## Free-plan limits and how the code fits them

- **50 outside fetches per request.** Every call to Anthropic, pyp.com, eBay
  and vPIC goes through `Budget` (`src/lib/budget.ts`). An analysis spends
  1 on Anthropic, up to 1 on the yard's price list and up to 2 on the eBay
  token; the rest go to eBay. Every part first gets its own category lookup;
  the broad-category retry runs only while budget is left. Results are cached
  in D1 for 24 hours (D1 calls have their own limit of 1,000), so repeat
  cars cost far less. A part with no lookup keeps the AI estimate, exactly
  as when eBay fails in the PHP app.
- **10 ms CPU per request.** Waiting on fetches doesn't count. Inventory pages
  are parsed with `indexOf` instead of a regex over the whole page.
- **Passwords.** New hashes use PBKDF2-SHA256 (WebCrypto, 100,000
  iterations, the Workers maximum). Old `$2y$` bcrypt hashes from MySQL
  still work: the first login checks it with bcryptjs and stores a PBKDF2
  hash. That one bcrypt check costs more CPU than 10 ms, so it can fail
  with error 1102 for a few users; "Forgot password" always works because
  it never reads the old hash. `PBKDF2_ITERATIONS` can be lowered if normal
  logins ever hit the CPU limit.
- **No cron.** About 1 request in 200 sweeps expired cache, rate-limit and
  session rows after its response is sent.
- Sessions are a D1 table and an `HttpOnly` cookie, so people signed in on
  the PHP site sign in once more after the switch.

## Run locally

```sh
npm install
npm run assets                      # copies the site's HTML into public/
npm run db:migrate:local
npx wrangler dev --env dev          # http://localhost:8787
npm test && npm run typecheck
```

Put local secrets in `.dev.vars` (git-ignored), one `NAME=value` per line.

## Test Worker

`npm run deploy:dev` builds the site, applies the D1 migration to
`yardpicker-dev` and deploys to https://yardpicker-dev.crivac.workers.dev.
Run `npx wrangler login` once first. To deploy from GitHub instead, connect
the repo in Workers & Pages (Workers Builds, free plan: 3,000 build minutes
a month) with root directory `/worker` and deploy command
`npm run assets && npx wrangler deploy --env dev`. The build token that
Cloudflare creates for Workers Builds has no D1 permission, so database
migrations run from your own machine (`npm run deploy:dev`), or after you
add D1 Edit to that token under My Profile > API Tokens.

## Secrets

Set each with `npx wrangler secret put NAME` (add `--env dev` for the test
Worker). None are stored in this repo.

| Name | Needed for |
| --- | --- |
| `ANTHROPIC_API_KEY` | analyses and VIN photos |
| `EBAY_CLIENT_ID`, `EBAY_CLIENT_SECRET` | eBay prices |
| `EBAY_VERIFICATION_TOKEN` | eBay account-deletion endpoint |
| `STRIPE_SECRET_KEY` | checkout |
| `STRIPE_WEBHOOK_SECRET` | the webhook (a new one, from the new endpoint) |
| `RESEND_API_KEY`, `RESEND_FROM` | password-reset email |

## Deploy and cut over

1. `npx wrangler d1 create yardpicker`, put the id in `wrangler.jsonc`, then
   `npm run db:migrate:remote`.
2. Copy the data: see the top of `scripts/mysql-to-d1.py`.
3. Set the secrets above, then `npm run deploy`.
4. In Cloudflare, add `yardpicker.crivac.com` as a Custom Domain on the
   `yardpicker` Worker (this replaces the DNS record that points at SiteGround).
5. In Stripe, add a webhook endpoint for
   `https://yardpicker.crivac.com/stripe-webhook.php`
   (`checkout.session.completed`) and set its signing secret as
   `STRIPE_WEBHOOK_SECRET`. The URL is unchanged, so the old endpoint can
   stay until the switch; afterwards remove whichever is unused.
6. The eBay deletion endpoint URL is unchanged; re-run eBay's verification
   once to confirm the Worker answers it.
7. In Resend, check the sending domain still verifies.
