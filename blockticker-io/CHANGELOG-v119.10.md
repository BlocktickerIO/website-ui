# BlockTicker — v119.10.0

**Released:** 2026-04-25
**Theme:** SEO content pipeline — daily forecast pages (🔴 P0 from Phase 2 of roadmap)

## Summary

Closes the third 🔴 P0 item from the v119.8 deep-research audit: the daily
auto-generated forecast pages that the report flagged as the primary organic-traffic
growth lever. Adds 11 new high-priority indexable URLs to the sitemap (1 index +
10 evergreen detail pages) plus a rolling 30-day archive (~300 historical URLs)
that compounds search visibility over time.

## What's new

### URL routing

- `/forecast/` — index page showing all tracked assets with verdict, price, change
- `/forecast/{slug}/` — **evergreen** detail page (always today's forecast)
- `/forecast/{slug}/YYYY-MM-DD/` — historical archive entry

Initial tracked set (10 assets):
- **Crypto:** btc, eth, sol, xrp, bnb
- **Forex:** eurusd, gbpusd, usdjpy, audusd, usdcad

Adding more assets is one filter call: `add_filter('bt_forecast_tracked_assets', …)`.

### Content generation

Two-tier strategy so pages **never** show empty content:

1. **Deterministic template** — runs always. Uses live data from
   `BT_AIBlog::assemble_asset_context()`: current price, 24h/7d change, market cap,
   sentiment mood, recent headlines mentioning the symbol, recent trading signals.
   Renders 5 sections (Snapshot · 24h Outlook · News Context · 7d Context ·
   Methodology) with verdict-aware copy that adapts to the actual market state.

2. **AI enhancement** — runs on top **only** if `bt_anthropic_api_key` is configured.
   Reuses the existing `BT_AIBlog::generate_asset_analysis()` pipeline so token usage
   and rate limits stay unified. AI failure or rate-limit silently falls back to the
   template — operator never gets a broken page.

### Verdict scoring

Coarse 24h verdict (BULLISH / BEARISH / NEUTRAL) computed from a 5-point composite:
- 24h price change (±0.3 / ±1.5 thresholds, ±1 / ±2 score)
- 7d trend (±5% threshold, ±1 score)
- News sentiment if `BT_Sentiment::get_market_mood()` available (±1 score)

Total ≥ 2 → BULLISH. Total ≤ -2 → BEARISH. Otherwise NEUTRAL. Displayed as a
neon-green / red / grey pill on every detail page and on every index card.

### SEO discipline

- **Canonical strategy:** dated URLs canonical-point to evergreen for the first 7
  days (so today's content under multiple URLs doesn't dilute), then become
  self-canonical + `noindex,follow` (the page is unique historical content but no
  longer competing with today's evergreen).
- **Sitemap inclusion** via new `bt_sitemap_extra_urls` filter on
  `BT_SEO_Sitemap::get_all_asset_urls()`. Forecast index = priority 0.8 daily;
  evergreen pages = priority 0.9 daily; recent dated URLs (≤7 days) = priority 0.5
  changefreq=never.
- **Schema.org Article markup** with `datePublished`, `dateModified`, `mainEntityOfPage`,
  `author` (Organization → Research Desk), `publisher`, `about` (the asset).
- **OG + Twitter** meta tags on every detail page.
- **Disclaimer prominent** on every page (yellow accent bar) — not just in the footer.

### Cron architecture

Daily run at **06:00 UTC**, processes assets one at a time with 30-second
spacing between them. Implementation:
- `bt_forecast_daily_kick` (hooked to WP `daily` event) calls `cron_kick()`
- `cron_kick()` rotates the previous day's snapshot into archive, then schedules
  `bt_forecast_one_asset` for each asset 30s apart
- Each `bt_forecast_one_asset` event calls `generate_forecast()` for one asset
- This avoids long-running PHP processes (no `max_execution_time` risk) and respects
  API rate limits

### Admin UI (BlockTicker → Forecasts)

- AI status indicator (ENABLED / OFF based on Anthropic key configuration)
- Next scheduled run with countdown (`human_time_diff`)
- Per-asset table: name, slug, last generated (relative time), verdict, source
  (template / ai), public URL, "Generate now" button
- "Run full daily cycle now" button at the bottom for on-demand bulk regeneration

### First-hit guarantee

If a user visits `/forecast/btc/` before the cron has ever run for that asset, the
detail renderer calls `generate_forecast()` synchronously on the request. Adds ~200ms
to that one request but means new installs don't show "Generating..." placeholders
to real users.

## Files changed

| File | Change |
|---|---|
| `includes/class-forecast.php` | **NEW** — full forecast subsystem (~770 lines) |
| `fx-live-markets.php` | `require_once` new class; version → 119.10.0 |
| `includes/class-seo-sitemap.php` | Add `bt_sitemap_extra_urls` filter at end of `get_all_asset_urls()` |
| `assets/css/revamp-v44.css` | Append forecast CSS module (~50 lines) — verdict pills, article typography, mobile single-column index |
| `docs/ROADMAP.md` | Mark forecast pipeline ✅; v119.10 decisions logged |
| `CHANGELOG-v119.10.md` | This file |

## Validation

- All 55 PHP files: `php -l` clean
- CSS braces balanced: 2435 / 2435
- Single `blockticker-io/` root folder

## Cron summary

| Hook | Frequency | Handler |
|---|---|---|
| `bt_check_price_alerts` | every 15 min | `BT_Portfolio::check_and_send_alerts` |
| `bt_check_news_alerts` | hourly | `BT_Alerts::check_and_send_news_digests` |
| `bt_forecast_daily_kick` | daily 06:00 UTC | `BT_Forecast::cron_kick` |
| `bt_forecast_one_asset` | one-shot, 30s spaced | `BT_Forecast::cron_process_one` |

## Storage

| Option | Autoload | Shape |
|---|---|---|
| `bt_forecast_{slug}` (10 keys) | no | Current-day forecast snapshot per asset |
| `bt_forecast_archive` | no | Rolling 30-day archive, keyed `{slug}_{YYYY-MM-DD}` |

When either grows past ~500 entries we should migrate to a custom DB table. See the
roadmap's "Plugin folder structure cleanup" P0 item for the broader migration plan.

## Roadmap impact

**Phase 2 — SEO Content Pipeline:**
- ✅ Daily forecast pages — done in this release
- 📋 Top-N landing pages, educational longform, auto-internal-linking — still todo

**Cumulative roadmap status:**
Three of the four 🔴 P0 items the v119.8 audit identified are now closed:
1. ✅ Price alerts (v119.9)
2. ✅ News alerts (v119.9)
3. ✅ Daily forecast pages (v119.10)
4. 📋 Plugin folder structure cleanup (deferred — needs phased migration)

## Known follow-ups (not in this release)

- AI enhancement currently piggy-backs on `BT_AIBlog::generate_asset_analysis()`,
  which produces an *analysis*, not a *forecast*. The two are similar enough that
  it works, but a dedicated forecast prompt would produce better content. To do
  this properly we need a public `BT_AIBlog::call_ai_public()` method (currently
  `call_ai` is private). Tracked as a follow-up in the roadmap.
- The HTML-in-PHP page templates should move to `templates/forecast/` as part of
  the Phase 1 P0 cleanup.
- Extending tracked assets to 25 is one filter call but currently requires editing
  PHP. An admin UI for this is a P2 polish item.
- The dated-URL `noindex` after 7 days is conservative (Google sometimes prefers
  fewer canonicals); A/B testing this against `index,follow` for older URLs is a
  data-driven experiment to run after we have analytics on the section.
