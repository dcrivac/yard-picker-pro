# 🔧 Yard Picker Pro

**AI-powered junkyard profitability analyzer for Pick Your Part (pyp.com)**

Find the most valuable cars at your local salvage yard — and exactly how much profit you’ll make pulling and selling each part on eBay. No car knowledge needed.

→ **[Live App](https://crivac.com/yp2.html)** · **[Landing Page](https://crivac.com)**

-----

## What it does

You walk into a Pick Your Part junkyard with 200+ cars. Which ones are worth pulling parts from? Which should you skip?

Yard Picker Pro answers that in 30 seconds:

1. Paste your yard’s inventory URL from pyp.com
1. Search and select the cars you want analyzed
1. Get a ranked pull list — 5 most profitable parts per car, with full profit math

Every number is shown with its source: eBay price (AI estimate), LKQ cost (published PYP list), your labor, eBay fees, shipping. Each part links directly to eBay sold listings so you can verify before pulling.

-----

## How it works technically

```
Browser → scrape.php (fetches pyp.com) → vehicle list
Browser → api.php (Anthropic API proxy) → AI analysis
```

Two PHP files on your server handle everything:

- **`scrape.php`** — fetches the full PYP inventory across all pages, returns JSON
- **`api.php`** — proxies requests to Anthropic’s API (keeps your key server-side)
- **`yp2.html`** — the entire app, single file, no build step

-----

## Setup

### Requirements

- A web host with PHP + cURL (shared hosting works fine — SiteGround, Bluehost, etc.)
- An [Anthropic API key](https://console.anthropic.com) (~$5 gets ~300 sessions)

### Installation

1. **Clone or download** this repo
1. **Edit `api.php`** — paste your Anthropic API key on line 5:
   
   ```php
   define('ANTHROPIC_KEY', 'sk-ant-your-key-here');
   ```
1. **Upload all three files** to your server’s `public_html` folder:
- `yp2.html`
- `api.php`
- `scrape.php`
1. **Open** `yourdomain.com/yp2.html` in Safari

That’s it. No npm, no build step, no database.

-----

## Daily use

1. Open [pyp.com](https://www.pyp.com) → your yard → View Inventory
1. Copy the URL from your browser’s address bar
1. Paste it into Yard Picker Pro → tap **Fetch Full Inventory**
1. Search or tap vehicles → tap **Analyze**
1. Pull list appears ranked by profit potential

-----

## Cost

|Action             |Model             |Cost       |
|-------------------|------------------|-----------|
|Fetch inventory    |scrape.php (no AI)|Free       |
|Parse screenshot   |claude-sonnet     |~$0.01     |
|Analyze 5 vehicles |claude-haiku      |~$0.005    |
|**Typical session**|                  |**~$0.015**|

$5 in API credits ≈ 300 sessions.

-----

## Project structure

```
yard-picker-pro/
├── yp2.html        # The full app (single file)
├── api.php         # Anthropic API proxy
├── scrape.php      # PYP inventory scraper
├── index.html      # Landing page
└── README.md
```

-----

## Contributing

Pull requests welcome. Here are the most impactful things to work on:

### 🔥 High priority

- **Live eBay prices** — replace AI estimates with real sold listing data
- **U-Pull-It support** — add scraper for upullit.com
- **Pull-A-Part support** — add scraper for pullapart.com

### 📋 Good first issues

- Add more parts to the LKQ price list (`yp2.html` → `LKQ` object)
- Add more labor time estimates (`yp2.html` → `LAB` object)
- Improve the AI prompt for better part recommendations
- Add a “history” tab to save past pull lists

### 💡 Ideas

- Offline mode / PWA
- Push notifications when high-value cars are added to yard
- Part condition notes
- Photo documentation of pulled parts

-----

## How the profit math works

```
Net Profit = eBay avg sold
           − LKQ part cost       (published PYP price list)
           − Labor cost          (pull hours × your hourly rate)
           − eBay fees           (13.25% of sale price)
           − Estimated shipping  ($25 flat estimate)
```

eBay prices are AI-estimated based on recent sold listings. Always verify on eBay before pulling — each part links directly to eBay completed listings.

-----

## License

MIT — use it, fork it, improve it.

-----

## Disclaimer

Not affiliated with LKQ, Pick Your Part, or eBay. eBay prices are AI estimates — actual prices vary. Always verify before pulling parts.