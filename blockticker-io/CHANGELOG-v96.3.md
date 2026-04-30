# BlockTicker v96.3.0 — Source Validation + DB Admin Screen

**Release date:** April 2026
**Type:** Feature release (additive, no breaking changes)
**Upgrade path:** Drop-in over v96.2.0 or v96.1.0

---

## Overview

This release has two goals:

1. **Own your sources** — every feed and API is now health-checked hourly,
   and two sources that were silently failing have been replaced.
2. **Own your database** — a new WordPress admin screen gives you full
   visibility and control over the custom tables introduced in v96.2.

Together they make BlockTicker's data pipeline observable and maintainable
from inside WP Admin, with no SSH or phpMyAdmin access required.

---

## New: `includes/class-source-validator.php`

A new class `BT_Source_Validator` validates every RSS feed and data API
on an hourly WordPress cron schedule and stores the results in
`wp_options`.

### What it checks per source

| Check | Detail |
|-------|--------|
| HTTP status | Must be exactly 200 |
| Response body | Must be non-empty |
| XML parsability | `simplexml_load_string()` must succeed for RSS feeds |
| Item count | At least 1 `<item>` element |
| Freshness | Newest `<pubDate>` must be ≤ 24 h old for news feeds |
| Latency | > 8 000 ms triggers `degraded` status |
| JSON schema | API sources check for an expected top-level key |

### Status values

- `healthy` — all checks pass
- `degraded` — reachable but slow, stale, or schema mismatch
- `dead` — HTTP error, empty body, or XML parse failure

### Feed definitions

`BT_Source_Validator::get_sources()` is now the canonical feed list for
validation. The existing `FXLM_RSS::$feeds` array in `class-rss.php` is
the source of truth for runtime fetching; both lists have been brought
into alignment.

### Bootstrap

```php
// In fx-live-markets.php (plugins_loaded, priority 10):
add_action( 'plugins_loaded', array( 'BT_Source_Validator', 'init' ) );
```

`init()` registers the `bt_validate_sources` hourly cron and the
`wp_ajax_bt_validate_sources_now` handler. Deactivation clears the cron.

---

## New: `includes/class-db-admin.php`

A new class `BT_DB_Admin` adds **BlockTicker → 🗄 Database** to the WP
admin menu. The screen is split into two columns.

### Left column: Database Operations

**Table Status panel**

Shows all four `wp_bt_*` tables with:
- Existence (✓ / ✗)
- Row count (formatted with thousands separator)
- Disk size (data + index, in KB or MB)
- Last write timestamp (human diff, e.g. "3 minutes ago")
- Schema version installed vs required

Auto-refreshes after every action (AJAX, no full page reload).

**Migration panel**

Two buttons:

| Button | Action |
|--------|--------|
| Run Migration (dbDelta) | Calls `BT_Database::install()`, updates `bt_db_version`. Safe to run repeatedly — dbDelta is a no-op when schema is current. |
| Backfill from Current Snapshot | Reads `fxlm_crypto_data` and `fxlm_forex_rates` from wp_options and inserts one anchor row per asset into `wp_bt_price_history`. Run once after first migration to seed day-zero chart data. |

**Retention & Purge panel**

Shows a preview table of rows eligible for purging under the current
retention policy:

| Table | Window |
|-------|--------|
| `wp_bt_price_history` | 90 days |
| `wp_bt_news_items` | Never (archive) |
| `wp_bt_signals_history` | Never (competitive moat) |
| `wp_bt_events` | 30 days (delivered only) |

"Run Purge Now" triggers `BT_Database::purge_old_data()` immediately and
shows deleted row counts. The daily `bt_purge_old_data` cron runs this
automatically.

### Right column: Source Health Dashboard

Lists every feed and API from `BT_Source_Validator::get_sources()` grouped
by category, with:
- Status badge (Healthy / Degraded / Dead)
- HTTP code
- Latency (ms)
- Item count + freshness (e.g. "42 items · 1.2h ago")
- Note column: shows replacement reason for feeds that replaced dead ones,
  and error detail for degraded/dead sources

**"↻ Validate All Sources Now"** triggers a forced re-validation of all
sources (bypassing the 1-hour cooldown) and reloads the page when done.
Validation of ~27 sources typically takes 30–60 seconds.

### AJAX actions (all require `manage_options` + nonce)

| Action | Handler |
|--------|---------|
| `bt_db_run_migration` | `BT_DB_Admin::ajax_run_migration()` |
| `bt_db_backfill` | `BT_DB_Admin::ajax_backfill()` |
| `bt_db_purge` | `BT_DB_Admin::ajax_purge()` |
| `bt_db_table_status` | `BT_DB_Admin::ajax_table_status()` |
| `bt_validate_sources_now` | `BT_Source_Validator::ajax_validate_now()` |

---

## Feed replacements in `class-rss.php`

Two sources were silently failing (returning non-200 or no items):

| Old source | Problem | Replacement |
|------------|---------|-------------|
| Reuters (`feeds.reuters.com/reuters/businessNews`) | 404 since 2020 — Reuters killed public RSS | **MarketWatch Top Stories** (`feeds.marketwatch.com/marketwatch/topstories/`) |
| Investing.com Forex (`investing.com/rss/news_14.rss`) | Rate-limited aggressively; appears twice (news + signals) | **ForexLive** news feed (news) and **ForexLive Analysis** (signals) |

Additional changes in `class-rss.php`:

- `$source_tier`: `'Reuters'` key renamed to `'MarketWatch Top Stories'`
- `$breaking_sources`: `'Reuters Finance'` → `'MarketWatch Top Stories'`
- `$src_colors`: `reuters` entry replaced with `marketwatch` + `forexlive`
  color codes
- `setup()` success message updated to reflect new sources

---

## `class-database.php` — minor patch

`purge_old_data()` now returns an associative array:

```php
return array(
    'price'  => $deleted_price_rows,
    'events' => $deleted_event_rows,
);
```

This is a backwards-compatible change — existing callers that ignore the
return value are unaffected. The DB admin screen uses the return value to
display a "Purge complete: removed N price rows and M event rows" message.

---

## `fx-live-markets.php`

- Version bumped to `96.3.0` (header + `FXLM_VERSION` constant)
- Two new `require_once` lines for `class-source-validator.php` and
  `class-db-admin.php`
- Two new `add_action( 'plugins_loaded', ... )` calls
- One new `register_deactivation_hook` for `BT_Source_Validator::deactivate()`

---

## Safety & rollback

### This release is safe because…

1. **Additive only.** No existing class method signatures were changed.
   The only existing file with functional changes is `class-rss.php`
   (feed URL swaps) and the cosmetic `purge_old_data()` return value.
2. **Feed swaps are live immediately** — no DB migration, no cron
   reschedule. The next `fxlm_refresh_news` cron cycle picks up the
   new URLs automatically.
3. **No new options autoloaded.** `bt_source_health` and
   `bt_source_summary` are stored with `$autoload = false`.

### Rollback

Reinstall v96.2.0 — no schema changes, no new option keys that would
conflict. The `bt_source_health` and `bt_source_summary` options are
left behind but are harmless; remove them manually if desired:

```sql
DELETE FROM wp_options WHERE option_name IN ('bt_source_health', 'bt_source_summary');
```

---

## What's next

- **v96.4** — Native charting widget (`lightweight-charts`, MIT).
  A `[bt_price_chart]` shortcode consuming `GET /history/{symbol}`
  with signal-outcome overlay, no TradingView dependency.
- **v96.5** — SEO layer: JSON-LD (BreadcrumbList, Article, FinancialProduct),
  dynamic sitemap.xml, unique meta descriptions per asset page.
  *(New — from ChatGPT audit; SEO scored 50/100 vs competitors.)*
- **v96.6** — Core Web Vitals: defer TradingView widget scripts,
  WebP fallback for AVIF images, asset versioning.
- **v96.7** — WCAG 2.1 AA accessibility pass (scored 40/100 — worst gap).
- **v97.0** — `FXLM_` → `BT_` constant rename (breaking; own session).

See `CHANGELOG-v96.2.md` and the full audit report for the complete roadmap.
