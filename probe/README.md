# pyp.com reachability probe (throwaway)

Answers one question before porting the app to Cloudflare Workers: can a
Worker fetch the pyp.com pages that `scrape.php` and `api.php` depend on?
It sends the same requests the PHP code sends and reports what came back.

## Run

```bash
cd probe
npx wrangler login          # skip if already logged in
npx wrangler deploy
curl https://yp-pyp-probe.<your-subdomain>.workers.dev/
```

Optional: `?slug=<yard-slug>` to test a yard other than `chula-vista-1264`.

Hit it 3 or 4 times a few minutes apart. Blocks based on IP reputation can
be intermittent, and each run may leave from a different Cloudflare address.

## Reading the result

- `verdict: PASS` on every run: Workers can scrape PYP. Port is viable.
- `status` 403/429/503, or anything in `blockMarkers`: PYP (or its bot
  protection) is refusing Cloudflare egress. Use a VPS instead.
- `inventory p1` passes but `vehicles` is 0: the page loaded but the
  markup changed. That's a scraper bug, not a hosting problem.

## Clean up

```bash
npx wrangler delete yp-pyp-probe
```
