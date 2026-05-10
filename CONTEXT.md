# Yard Picker Pro — Project Context

## What This Is

AI-powered junkyard profitability analyzer for Pick Your Part (pyp.com) yards. Helps pickers identify the most profitable cars and parts to pull and sell on eBay. Shows full profit breakdown: eBay estimated price, PYP part cost, labor, eBay fees, shipping, and net profit per part.

## Live URLs

- App: `crivac.com/yp2.html`
- Landing page: `crivac.com/index.html`
- API proxy: `crivac.com/api.php`
- Scraper: `crivac.com/scrape.php`

## Hosting

- **Provider:** SiteGround
- **Host:** `gcam1116.siteground.biz`
- **Port:** 18765
- **User:** `u2074-ems391e7qg8i`
- **Files go in:** `public_html/`
- **SCP command:** `scp -P 18765 file.php u2074-ems391e7qg8i@gcam1116.siteground.biz:public_html/`

-----

## File Structure

```
yard-picker-pro/
├── yp2.html        # Full single-file web app
├── api.php         # Anthropic API proxy (server-side, holds API key)
├── scrape.php      # PYP inventory scraper
├── index.html      # Landing page
├── README.md       # GitHub readme
└── CONTEXT.md      # This file
```

-----

## How It Works

### User Flow

1. User opens `yp2.html` on mobile Safari
1. Pastes pyp.com inventory URL (e.g. `https://www.pyp.com/inventory/chula-vista-1264/`)
1. App calls `scrape.php` → fetches full yard inventory (200-400+ vehicles across 20 pages)
1. User searches/filters vehicle list, selects cars to analyze
1. App calls `api.php` → Claude Haiku analyzes top 5 most profitable parts per vehicle
1. Pull list appears ranked by total profit potential

### Backend Flow

```
Browser → scrape.php (fetches pyp.com with curl, iPhone UA) → JSON vehicle list
Browser → api.php (proxies to Anthropic API, injects LKQ prices) → analysis JSON
```

-----

## Key Technical Decisions

### scrape.php

- Uses PHP curl with iPhone user-agent to fetch pyp.com (blocks direct browser fetches)
- **CORS allowlist**: same `crivac.com` allowlist as `api.php`
- **SSL verification on** (`CURLOPT_SSL_VERIFYPEER => true`, `VERIFYHOST => 2`) — MITM-safe
- Extracts vehicle names from image alt text: `alt="2009 CHEVROLET SILVERADO 1500 available for parts"`
- Slug extracted via `strpos`/`strcspn` — NOT regex (PHP regex delimiter `#` conflicts with `#` in character classes)
- Browser extracts slug client-side via JS and sends as `&slug=` parameter to avoid server-side parsing issues
- `ob_start()`/`ob_end_clean()` prevents PHP warnings from corrupting JSON output
- Fetches all pages (up to 20), deduplicates vehicles

### api.php

- Proxies requests to Anthropic API (keeps API key server-side)
- **Reads `ANTHROPIC_API_KEY` from env** (or `./.env.php` if present) — never hardcoded in source
- **CORS allowlist**: only `https://crivac.com` / `https://www.crivac.com` may call the endpoint
- **Rate limit**: 10 POSTs/minute per IP (file-based counter in the system temp dir)
- **Input validation**: rejects bodies > 20KB, requires `messages`, allowlists `claude-haiku-*` / `claude-sonnet-*` models, caps `max_tokens` at 4096
- **Generic errors**: all failures return `{"error":{"message":"Request failed"}}`; details go to the PHP error log via `error_log()`
- **Injects LKQ prices server-side** — reads AI response JSON, matches part names to PYP price list, adds `lkqPrice` and `lkqMatched` fields before returning to browser
- Uses fuzzy matching (partial string + word overlap) to handle AI part name variations

### yp2.html

- Single file — all HTML, CSS, JS in one file (Safari compatibility)
- Uses `XMLHttpRequest` not `fetch` (more reliable on older Safari)
- All JS in one `<script>` block — inline scripts cause button failures on Safari
- Reads `p.lkqPrice` from server response (injected by api.php) for accurate costs
- Falls back to client-side `lkqLookup()` fuzzy match if server price missing

-----

## PYP Chula Vista Price List

All prices are **Total Price** = base price + refundable core deposit + 90-day guarantee.
This is what actually comes out of pocket at checkout.

Key prices (full list in `yp2.html` LKQ object and `api.php` $LKQ array):

- Engine (Long Block): $468.89
- Transmission: $266.33
- Transfer Case: $253.20
- ECU / PCM: $92.09
- ABS Module: $106.19
- Alternator: $59.39
- Cylinder Head: $111.79
- Radiator: $67.19
- Steering Rack: $82.79
- Hood: $117.89
- Door (Front/Rear): $108.79
- Turbocharger: $140.00

-----

## Profit Formula

```
Net Profit = eBay avg sold price (AI estimate)
           − PYP Total Price (from price list)
           − Labor cost (pull hours × user's hourly rate)
           − eBay fees (13.25% of sale price)
           − Estimated shipping ($25 flat)
```

-----

## Known Issues / Outstanding Work

### 🔴 Critical

- **LKQ prices showing $50** — The server-side injection in `api.php` was just implemented but not fully verified. The `lkqPrice` field needs to be confirmed as reaching the browser correctly. Debug by checking green log box for `Part: [name] price: $X matched: Y` lines after analysis.

### 🟡 Important

- **Row numbers missing** — Vehicle row/aisle numbers not extracted from PYP pages (shows blank for most vehicles)
- **Drivetrain prices estimated** — Transmission (Manual) uses same price as Auto ($266.33) — PYP lists one “Transmission” price for both

### 🟢 Nice to Have

- Live eBay price lookup (currently AI-estimated)
- Support for other PYP yard locations (currently hardcoded to Chula Vista price list)
- Support for other yard chains (U-Pull-It, Pull-A-Part)
- Save/export pull list as PDF
- GitHub username placeholders in `index.html` and `README.md` need replacing

-----

## AI Models Used

- **Inventory parsing (screenshot fallback):** `claude-sonnet-4-20250514` (~$0.01/image)
- **Part profitability analysis:** `claude-haiku-4-5-20251001` (~$0.005/5 vehicles)
- **Typical session cost:** ~$0.015

-----

## Safari/Mobile Compatibility Notes

- All JS must be in a single `<script>` block — multiple blocks or inline `onclick` scripts fail
- Use `XMLHttpRequest` not `fetch`
- Force light mode: `<meta name="color-scheme" content="light">` + hardcoded colors (no CSS variables) to prevent dark mode override
- `ob_start()` in PHP prevents warning output from corrupting JSON

-----

## Deployment Checklist

When deploying updates:

1. `scp -P 18765 yp2.html u2074-ems391e7qg8i@gcam1116.siteground.biz:public_html/yp2.html`
1. `scp -P 18765 api.php u2074-ems391e7qg8i@gcam1116.siteground.biz:public_html/api.php`
1. `scp -P 18765 scrape.php u2074-ems391e7qg8i@gcam1116.siteground.biz:public_html/scrape.php`
1. Verify the server has `ANTHROPIC_API_KEY` set — either as a SiteGround PHP env var, or via a `public_html/.env.php` file (chmod 600, not in git) that calls `putenv('ANTHROPIC_API_KEY=sk-ant-...')`. `.env.php` survives `git pull` because it's gitignored.
1. Test in private/incognito Safari window to bypass cache
1. Check green log box for expected output

### Required server env vars

- `ANTHROPIC_API_KEY` — your `sk-ant-...` key. `api.php` returns 500 if missing.

### CORS allowlist

`api.php` and `scrape.php` only honor requests from `https://crivac.com` and `https://www.crivac.com`. If you add another domain, edit the `$allowedOrigins` array in both files.

-----

## What Was Built This Session

- ✅ PYP inventory scraper (441 vehicles, all pages, correct make/model including hyphens)
- ✅ AI part profitability analysis with full cost breakdown
- ✅ Vehicle search/filter + tap-to-select
- ✅ Load More batching (5 vehicles per analysis call)
- ✅ Full PYP Chula Vista price list (110+ parts, Total Price)
- ✅ Server-side LKQ price injection in api.php
- ✅ Landing page (white→warm→dark progression, orange accent, light mode forced)
- ✅ README.md for GitHub
- ✅ Mobile-first UI, single file architecture