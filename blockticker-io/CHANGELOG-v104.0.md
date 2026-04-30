# BlockTicker v104.0.0 — News Sentiment Analysis

**Release date:** April 2026
**Type:** Feature release (additive, no breaking changes)
**Upgrade path:** Drop-in over v103.0.0

---

## Overview

The `sentiment_score` column in `wp_bt_news_items` has been empty since v96.2
when the schema was first created. v104.0 fills it.

A new `BT_Sentiment` class runs a twice-daily cron that batches up to 40 unscored
news items, sends their titles and summaries to the configured AI provider
(Claude Haiku or GPT-4o-mini) in a single API call, and writes a score between
-1.0 (extremely bearish) and +1.0 (extremely bullish) back to the database.
It also populates the `symbols_mentioned` column with tickers extracted from
each headline.

The scored data powers two new shortcodes and a REST endpoint.

---

## New: `includes/class-sentiment.php`

One new class `BT_Sentiment` (~330 lines).

### Cron: `bt_score_news_sentiment`

Schedule: `bt_twice_daily` (every 12 hours).

Each run:
1. Fetches up to 40 unscored rows from `wp_bt_news_items` (oldest first, so
   history fills forward).
2. Sends all 40 titles + summaries in **one API call** — one call per run
   regardless of batch size, keeping costs minimal.
3. Parses the JSON array response.
4. Writes `sentiment_score` (clamped to [-1.0, +1.0], 3 decimal places) and
   `symbols_mentioned` back to each row.
5. Busts the mood transient cache.

**API model selection (cost-optimised):**
- Claude provider → `claude-haiku-4-5-20251001` (fast, cheapest Anthropic model)
- OpenAI provider → `gpt-4o-mini` (cheapest classification-grade OpenAI model)

At 40 items per run, twice daily, a site with 200 new articles/day reaches
full coverage in 2-3 days. Backfill of historical items happens automatically
run-by-run until `SELECT COUNT(*) WHERE sentiment_score IS NULL` reaches zero.

### Market mood calculation: `BT_Sentiment::get_market_mood( $hours )`

Computes a **recency-weighted** average of all scored items in the look-back
window:
- Items published in the last 6 hours: weight **3×**
- Items published in the last 12 hours: weight **2×**
- Items published in the last 24 hours: weight **1×**

Returns: `{ score, label, count, total, scored_pct }`.

Results are cached as a transient (`bt_sentiment_mood_{hours}`) for 30 minutes.
The cache is invalidated whenever a scoring batch completes or when new news
is stored (via `bt_news_stored` action hook).

**Mood labels:**

| Score range | Label |
|-------------|-------|
| ≥ 0.60 | Extreme Greed 🚀 |
| 0.25 – 0.59 | Greed 😀 |
| -0.24 – 0.24 | Neutral 😐 |
| -0.59 – -0.25 | Fear 😰 |
| ≤ -0.60 | Extreme Fear 💀 |

---

### Shortcode: `[bt_sentiment_bar]`

```
[bt_sentiment_bar hours="24" show_breakdown="1" show_score="1" title="Market Mood"]
```

Renders a gradient gauge bar (Extreme Fear → Extreme Greed) with:
- A needle emoji positioned at the current score
- A colour-coded label and numeric score beneath
- A meta line showing article count, window, and scoring coverage %

The bar uses a pure CSS gradient (no images, no external dependencies) and is
fully responsive. Colour zones:

| Zone | Colour |
|------|--------|
| Extreme Greed | `#00a32a` (WP admin green) |
| Greed | `#5bc15b` |
| Neutral | `#888888` |
| Fear | `#e6972b` |
| Extreme Fear | `#d63638` (WP admin red) |

**Attributes:**

| Attribute | Default | Description |
|-----------|---------|-------------|
| `hours` | 24 | Look-back window (1–168) |
| `show_breakdown` | 1 | Show article count + scored% meta line |
| `show_score` | 1 | Show numeric score next to label |
| `title` | "Market Mood" | Section heading (empty to hide) |

---

### Shortcode: `[bt_sentiment_ticker]`

```
[bt_sentiment_ticker hours="24"]
```

Inline one-liner for sidebars and widget areas:

> Market Mood: **Greed** (+0.42) · 84 articles

---

### REST endpoint: `GET /wp-json/blockticker/v1/sentiment`

Parameters: `hours` (1–168, default 24).

Response:
```json
{
  "data": {
    "score": 0.312,
    "label": "Greed",
    "count": 84,
    "total": 91,
    "scored_pct": 92.3
  },
  "meta": { "generated_at": "2026-04-21T14:00:00+00:00", "hours": 24 },
  "source": "BlockTicker sentiment analysis"
}
```

---

## `includes/class-db-admin.php`

- **🧠 Sentiment Scoring** panel injected at the top of the right column (above the v100 upgrade panel), showing:
  - Total scored / total news items + percentage
  - Current 24h mood score and label
  - AI provider and batch size
  - **▶ Score Next Batch Now** button (AJAX, `bt_sentiment_run_batch` action)
- `wp_ajax_bt_sentiment_run_batch` action registered in `init()`

---

## `fx-live-markets.php`

- Version → 104.0.0
- `require_once BT_DIR . 'includes/class-sentiment.php'`
- `add_action( 'plugins_loaded', ['BT_Sentiment', 'init'] )`
- `register_deactivation_hook( …, ['BT_Sentiment', 'deactivate'] )`

---

## PHP lint

All 3 touched files clean:
- `includes/class-sentiment.php` ✅ (new)
- `includes/class-db-admin.php` ✅
- `fx-live-markets.php` ✅

---

## Usage

1. Ensure an AI API key is set in **BlockTicker → Settings** (`bt_claude_key` or
   `bt_openai_key`, depending on `bt_ai_provider`).
2. The cron runs automatically twice daily. For immediate backfill, go to
   **BlockTicker → 🗄 Database** and click **▶ Score Next Batch Now** repeatedly
   until the scored% reaches 100%.
3. Add `[bt_sentiment_bar]` to any page or widget area.
4. Optionally hit `GET /wp-json/blockticker/v1/sentiment` for live data in a
   headless or widget context.

---

## What's next

- **v105.0** — Signal Source Leaderboard: `[bt_signal_leaderboard]` shortcode
  ranking signal providers by win rate, avg gain, and sample size — purely
  database-driven from `wp_bt_signals_history` (no API cost).
- **v106.0** — Correlation Heatmap: `[bt_correlation_heatmap]` visualising
  price correlation between asset pairs using `wp_bt_price_history`.
