# Yard Picker Pro

AI-powered junkyard profitability analyzer for Pick Your Part (pyp.com).

## What It Does

Fetches the full vehicle inventory from any PYP yard, analyzes each car’s most profitable parts to pull and sell on eBay, and returns a ranked pull list with full cost breakdown — eBay price, PYP cost, labor, fees, shipping, and net profit.

## Live App

[crivac.com/yp2.html](https://crivac.com/yp2.html)

## Stack

- **Frontend:** Single-file HTML/JS (`yp2.html`) — mobile-first, Safari compatible
- **Scraper:** PHP (`scrape.php`) — fetches pyp.com inventory via curl
- **API proxy:** PHP (`api.php`) — proxies to Anthropic API, injects PYP pricing server-side
- **Hosting:** SiteGround (`crivac.com`)
- **AI:** Claude Haiku (analysis) + Claude Sonnet (image fallback)

## Setup

1. Upload `yp2.html`, `api.php`, `scrape.php` to your server’s `public_html/`
1. Add your Anthropic API key to line 2 of `api.php`
1. Open `yourdomain.com/yp2.html`

## Deploy

```bash
scp -P 18765 yp2.html api.php scrape.php u2074-ems391e7qg8i@gcam1116.siteground.biz:public_html/
```

## Known Issues

See `CONTEXT.md` for full project state, outstanding bugs, and technical notes.

## Pricing Data

PYP Chula Vista price list (Total Price including core deposit and 90-day guarantee) is embedded in both `api.php` and `yp2.html`. Update both files if prices change.
