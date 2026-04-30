# BlockTicker v112.0.0 — AI Market Analysis 2.0

**Release date:** April 2026
**Type:** Feature release (additive, no breaking changes)
**Upgrade path:** Drop-in over v111.0.0

---

## Overview

The existing daily AI post covers the whole market. v112 adds the missing
per-asset layer: on-demand deep-dive reports for any symbol, served from
a 1-hour cache and auto-published as WordPress posts with Article schema
and Yoast meta.

---

## New shortcode: `[bt_ai_analysis_v2]`

```
[bt_ai_analysis_v2 symbol="BTC" show_refresh="1" show_post_link="1" autoload="0"]
```

Place on any asset page. On the first render, the shortcode checks for a
cached analysis post in the database. If one exists and is under 1 hour old,
it renders server-side (zero AJAX, instant). If stale or missing, it shows
a "Generate Analysis Now" button (or triggers automatically when
`autoload="1"`).

**Attributes:**

| Attribute | Default | Description |
|-----------|---------|-------------|
| `symbol` | BTC | Asset symbol (crypto or forex) |
| `show_refresh` | 1 | Show the ↻ Refresh Analysis button |
| `show_post_link` | 1 | Show "View full analysis post →" footer link |
| `autoload` | 0 | Auto-trigger AJAX generation on page load if no cache |

**Rendered sections (5 × AI-generated, 80–120 words each):**

1. **Market Overview** — price context, 7d range, market cap significance
2. **Technical Picture** — price action, momentum, key levels
3. **Sentiment & News Flow** — F&G reading, news sentiment score, headline interpretation
4. **Signal Activity** — recent signals for this symbol, or "No active signals"
5. **Outlook & Key Levels** — 3 actionable takeaways: support/resistance, catalyst, risk

**Data fed into the prompt (all live):**
- Current price + 24h/7d % change from `bt_crypto_data` / `bt_forex_data`
- Market cap + 24h volume
- 7d high/low from `wp_bt_price_history`
- Fear & Greed Index value + label
- 48h news sentiment score from `BT_Sentiment`
- Up to 8 recent headlines mentioning the symbol (from `wp_bt_news_items`,
  each with its sentiment score)
- Up to 5 recent trading signals for the symbol (from `bt_signal_items`)

---

## Auto-published WordPress posts

Every generated analysis creates or updates a WordPress post:

| Property | Value |
|----------|-------|
| Post slug | `bt-analysis-{sym}` (e.g. `bt-analysis-btc`) |
| Category | "AI Market Analysis" (auto-created if missing) |
| Tags | `[SYM, Full name, AI Analysis, Market Analysis]` |
| Yoast meta description | `"AI-generated market analysis for {name} ({sym}) — price, technicals, sentiment, and key levels."` |
| Yoast focus keyword | `"{sym} analysis"` |
| Post meta `_bt_asset_analysis_html` | Cached rendered HTML |
| Post meta `_bt_asset_analysis_ts` | Unix timestamp of last generation |
| Post meta `_bt_article_schema` | `FinancialProduct` JSON-LD for schema injection |

The post is published immediately (or set to `pending` if AI review mode is
enabled). Re-running analysis updates the existing post rather than creating
a new one, keeping a clean URL structure.

---

## `BT_AIBlog::generate_asset_analysis( $symbol, $force )` — public method

Central method that orchestrates fetch → generate → cache → persist.
Returns:

```php
array(
    'html'         => '<h2>Market Overview</h2>…',
    'post_id'      => 42,
    'post_url'     => 'https://example.com/bt-analysis-btc/',
    'cached'       => true,   // false when freshly generated
    'generated_at' => 1745200000,
)
// or on failure:
array( 'error' => 'AI generation failed. Check your API key…' )
```

**Cache logic:** The method checks `_bt_asset_analysis_ts` post meta. If
the timestamp is within `ANALYSIS_CACHE_TTL` (1 hour) and `$force` is false,
the cached HTML is returned without an API call. This means repeated shortcode
renders on the same page hit WordPress's post cache, not the AI API.

---

## AJAX endpoint: `bt_generate_asset_analysis`

Available to both logged-in and guest users (rate-limited by nonce per symbol
per page load). The "↻ Refresh Analysis" button calls this; admins can also
pass `force=1` to bypass the 1-hour cache.

---

## `includes/class-aiblog.php`

- `ANALYSIS_CACHE_TTL`, `ANALYSIS_META_KEY`, `ANALYSIS_META_TS`, `ANALYSIS_CATEGORY` constants added
- 6 new static methods: `assemble_asset_context`, `build_asset_analysis_prompt`,
  `upsert_asset_analysis_post`, `generate_asset_analysis`, `ajax_generate_asset_analysis`,
  `sc_ai_analysis_v2`
- `bt_generate_asset_analysis` AJAX action registered (logged-in + guest)
- `bt_ai_analysis_v2` shortcode registered

---

## `fx-live-markets.php`

- Version → 112.0.0

---

## PHP lint

- `includes/class-aiblog.php` ✅
- `fx-live-markets.php` ✅

---

## Usage

On any crypto asset page:

```
[bt_ai_analysis_v2 symbol="BTC" autoload="1"]
```

On a forex page:

```
[bt_ai_analysis_v2 symbol="EUR/USD" show_post_link="0"]
```

In a widget or sidebar (cached, no AJAX on page load):

```
[bt_ai_analysis_v2 symbol="ETH" show_refresh="0" autoload="0"]
```

---

## What's next

- **v113.0** — Social Sharing 2.0: Twitter/X auto-post + Telegram channel push
  when new analysis posts are published, using the existing
  `_bt_twitter_thread` and `_bt_newsletter_body` multiformat infrastructure.
