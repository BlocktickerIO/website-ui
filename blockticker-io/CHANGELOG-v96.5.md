# BlockTicker v96.5.0 — SEO Layer

**Release date:** April 2026
**Type:** Feature release (additive, no breaking changes)
**Upgrade path:** Drop-in over v96.4.0

---

## Overview

The ChatGPT audit scored BlockTicker's SEO at **50/100** — the worst gap
in the entire plugin.  The root cause: all the per-coin and per-pair pages
(`/crypto/bitcoin/`, `/forex/eur-usd/`, etc.) are virtual routes handled by
rewrite rules, not WordPress posts.  That means Yoast and WP's own sitemap
never include them, search engines never discover them at scale, and the
`~600 unique URL` asset library is essentially invisible to crawlers.

v96.5 solves this with a purpose-built sitemap class, two new schema types
that unlock additional Google SERP features, and Google News eligibility tags
on article pages.

---

## New: `includes/class-seo-sitemap.php`

One new class `BT_SEO_Sitemap` (~310 lines).

### Three sitemap integration paths

The class registers asset URLs through every possible sitemap path, so it
works whether Yoast is installed, removed, or the site runs vanilla WP:

| Path | Mechanism | When active |
|------|-----------|-------------|
| **Standalone endpoint** | Rewrite rule → `/blockticker-assets-sitemap.xml` | Always |
| **WP 5.5+ core sitemap** | Custom provider under `/wp-sitemap.xml` (`bt-assets` key) | WP ≥ 5.5, no Yoast |
| **Yoast sub-sitemap** | Named module in Yoast sitemap index | Yoast active |

All three paths expose the same URL set; search engines can discover via any.

### URL coverage

All crypto coin pages and all forex pair pages are included automatically
using live data from `fxlm_crypto_data` and `fxlm_forex_data` options —
the same source the frontend widgets use.

Priority is assigned by market-cap rank:

| Rank | `<priority>` |
|------|-------------|
| Top 10  | 0.9 |
| 11–50   | 0.8 |
| 51+     | 0.7 |
| Forex pairs | 0.7 |

`<changefreq>` is `hourly` for all asset pages (accurate — prices update
every 5 minutes).

### Rebuild schedule

The sitemap regenerates:
- **Daily** via a new `bt_regenerate_sitemap` WP cron event
- **After every price refresh** — hooked at priority 99 on `fxlm_refresh_prices`,
  so coin additions and removals are reflected on the next cycle

Results are cached in `wp_options` (`bt_sitemap_xml_cache`, autoload=no)
with a 6-hour TTL, so the XML is never generated on every request.

### Search engine pings

After every rebuild, the class fires a non-blocking `wp_remote_get()` to:
- `https://www.google.com/ping?sitemap={url}`
- `https://www.bing.com/ping?sitemap={url}`

Both use `blocking=false` (fire-and-forget). A cron failure or network error
never prevents the sitemap from being served.

### robots.txt

A `Sitemap:` directive pointing to the standalone endpoint is appended to
WordPress's virtual robots.txt via the `robots_txt` filter — duplicate-safe.

### WP Admin rebuild button

`BT_SEO_Sitemap::admin_panel_html()` renders a panel (🗺 Asset Sitemap)
with URL count, last-built timestamp and a "↻ Rebuild & Ping Now" button.
The button calls the `bt_rebuild_sitemap` AJAX action (requires
`manage_options` + nonce).  The admin AJAX hook is registered in
`FXLM_SEO::register_shortcodes()` so it wires up alongside the existing
SEO hooks.

---

## `includes/class-seo.php` — three new methods

### `output_faq_schema()` — FAQPage rich results

Outputs `FAQPage` JSON-LD on seven key pages:

| Page slug | Q&A pairs |
|-----------|-----------|
| `crypto-markets` | 4 |
| `forex-charts` | 4 |
| `trading-signals` | 4 |
| `financial-news` | 3 |
| `market-analysis` | 3 |
| `learn` | 3 |
| `tools` | 3 |

Google displays FAQPage markup as expandable accordion panels directly in
the SERP, increasing organic click-through significantly for informational
queries.  Zero output on all other pages — no render cost.

### `output_data_feed_schema()` — Dataset / DataFeed

Outputs `Dataset` JSON-LD on two high-traffic pages:

| Page | Schema name |
|------|-------------|
| `crypto-markets` | "Live Cryptocurrency Prices — BlockTicker" |
| `forex-charts` | "Live Forex Exchange Rates — BlockTicker" |

Key properties: `isAccessibleForFree: true`, `temporalCoverage: Real-time`,
`updateFrequency: PT5M`.  Signals to Google that the page contains structured
financial data — improves Knowledge Graph association and eligibility for
data-rich result types.

### `output_news_meta()` — Google News eligibility

On every single post:
- Outputs `<meta name="news_keywords">` populated from the post's tags
  (up to 6) and categories (up to 4, excluding Uncategorised)
- Outputs `<meta name="revisit-after" content="1 day">` to hint crawlers
  to return promptly after article publication

---

## `fx-live-markets.php`

- Version bumped to `96.5.0` (header + `FXLM_VERSION` constant)
- `require_once FXLM_DIR . 'includes/class-seo-sitemap.php'`
- `add_action( 'plugins_loaded', array( 'BT_SEO_Sitemap', 'init' ) )`

---

## Safety & rollback

1. **Additive only.** No existing method signatures changed.  The only
   existing file with functional changes is `class-seo.php` — three new
   methods appended and one new hook registered in `register_shortcodes()`.
2. **Rewrite rule** — adds `^blockticker-assets-sitemap\.xml$` to the top
   of the rewrite stack.  Safe to add without a permalink flush: the regex
   is extremely specific and will not intercept any existing URL.
3. **New cron event** — `bt_regenerate_sitemap` schedules daily.  Cleared
   on deactivation via `BT_SEO_Sitemap::deactivate()`.  Existing cron events
   unchanged.

### Rollback

Reinstall v96.4.0 — no schema changes, no new tables.  Two `wp_options`
rows are left behind; remove if desired:

```sql
DELETE FROM wp_options WHERE option_name IN ('bt_sitemap_xml_cache', 'bt_sitemap_last_built');
```

---

## PHP lint

All touched files clean:
- `includes/class-seo-sitemap.php` ✅
- `includes/class-seo.php`         ✅
- `fx-live-markets.php`            ✅

---

## What's next

- **v96.6** — Core Web Vitals: defer TradingView JS, WebP image fallbacks,
  asset versioning + cache headers.
  *(LCP and INP improvements — audit scored Core Web Vitals at 60/100.)*
- **v96.7** — WCAG 2.1 AA accessibility pass (scored 40/100 — largest
  remaining gap).
- **v97.0** — `FXLM_` → `BT_` constant/class rename (breaking; own session).
