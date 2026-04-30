# BlockTicker v96.2.0 — DB History Foundation

**Release date:** April 2026
**Type:** Feature release (additive, no breaking changes)
**Upgrade path:** Drop-in over v96.1.0 or v96.0.0

---

## What this release ships

The single biggest recommendation from the audit: **own your history**.
Until now every dataset — prices, news, signals — was stored as a serialized
JSON blob in `wp_options`, overwritten every time the cron ran. You could
see today's BTC price; you could not see last Tuesday's. As of v96.2 the
plugin has its own schema and accumulates real time-series data from the
moment you upgrade.

## What was added (code changes)

### 1 · New class: `includes/class-database.php`

~440 lines, one class `BT_Database` with:

- **`install()`** — `dbDelta()` creates the 4 tables with strict WordPress-
  compatible formatting (double-space before `PRIMARY KEY`, each index on
  its own line — the formatting that makes dbDelta's diff parser idempotent).
- **`maybe_install()`** — runs on every admin load, compares `bt_db_version`
  option against the class's declared schema version, re-runs dbDelta if
  mismatched.  Cheap; dbDelta is a no-op when schema is current.
- **Insert helpers** — `insert_price_snapshot()`, `insert_forex_snapshot()`,
  `insert_news_item()` (with SHA-256 URL dedup), `insert_signal()`,
  `log_event()`. All return the new row id or `false`/`0`.
- **Read helpers** — `get_price_history($symbol, $days, $resolution)`
  with SQL-side aggregation for `1h` and `1d` buckets so the API doesn't
  ship 5000 rows when the user asks for a 30-day daily chart.
- **Snapshot hooks** — `snapshot_prices_after_fetch`,
  `snapshot_news_after_fetch`, `snapshot_signals_after_fetch`. These read
  from the existing `wp_options` that the original cron handlers already
  populate, then insert into the custom tables.
- **Retention** — `purge_old_data()` runs daily, enforces the retention
  windows.
- **`drop_all()`** — called from `uninstall.php`.

### 2 · Hook wiring in `fx-live-markets.php`

```php
require_once FXLM_DIR . 'includes/class-database.php';

register_activation_hook( __FILE__, array( 'BT_Database', 'install' ) );
register_activation_hook( __FILE__, function() {
    update_option( 'bt_db_version', BT_Database::DB_VERSION );
    if ( false !== get_option( 'fxlm_crypto_data' ) ) {
        BT_Database::snapshot_prices_after_fetch();
    }
} );

add_action( 'plugins_loaded', array( 'BT_Database', 'init' ) );
```

The `init()` method registers:
- `fxlm_refresh_prices`   priority 20 → `snapshot_prices_after_fetch`
- `fxlm_refresh_news`     priority 20 → `snapshot_news_after_fetch`
- `fxlm_refresh_signals`  priority 20 → `snapshot_signals_after_fetch`
- `bt_purge_old_data`     (daily)    → `purge_old_data`

Priority 20 is key — the existing `FXLM_Widgets::fetch_and_store_all` runs
at default priority 10, so by the time our snapshotter fires, the options
are already updated with fresh data. This means **zero modification** to
`class-widgets.php` or `class-rss.php`.

### 3 · New REST endpoint in `includes/class-api.php`

Registered alongside existing endpoints in the same `blockticker/v1`
namespace, same rate limiting, same CORS config:

```
GET /wp-json/blockticker/v1/history/{symbol}?days=7&resolution=1h
```

Parameters:
- `symbol` — e.g. `BTC`, `ETH`, `EUR/USD` (URL-encoded slash is fine)
- `days` — 1 to 365, default 7
- `resolution` — `5m` (raw rows, up to ~2000), `1h` (SQL-side AVG per
  hour bucket), `1d` (daily AVG)

Response shape:
```json
{
  "data": {
    "symbol": "BTC",
    "days": 7,
    "resolution": "1h",
    "count": 168,
    "data": [
      { "captured_at": "2026-04-13 00:00:00", "timestamp": 1744243200,
        "price_usd": 67234.5, "volume_24h": 28500000000, ... },
      ...
    ]
  },
  "meta": { ... },
  "source": "BlockTicker historical data (own DB)"
}
```

Returns empty `data: []` legitimately when the table has no history yet
(e.g., fresh install within the first cron cycle). Clients should handle
that gracefully.

### 4 · `uninstall.php` additions

New Section 11 drops the 4 custom tables. Respects:

```php
define( 'BT_KEEP_DATA_ON_UNINSTALL', true ); // wp-config.php
```

This matches the convention Yoast, WooCommerce, ACF use for data tables:
users who want to uninstall temporarily without losing months of
accumulated history can opt out.

## Schema

### `wp_bt_price_history`
```
id              BIGINT UNSIGNED  PK AUTO
symbol          VARCHAR(32)      IDX + captured_at
asset_class     VARCHAR(16)      IDX + captured_at    (crypto|forex|commodity)
price_usd       DECIMAL(24,10)                         (forex: the quoted rate)
volume_24h      DECIMAL(24,2)
market_cap      DECIMAL(24,2)
pct_change_24h  DECIMAL(10,4)
captured_at     DATETIME          IDX
```

### `wp_bt_news_items`
```
id                 BIGINT UNSIGNED   PK AUTO
url_hash           CHAR(64)          UNIQUE              SHA-256 of URL
source             VARCHAR(128)      IDX + published_at
category           VARCHAR(64)       IDX
title              VARCHAR(500)
url                TEXT
summary            TEXT
sentiment_score    DECIMAL(5,3) NULL                     (populated v98+)
symbols_mentioned  VARCHAR(255)                          (populated v98+)
published_at       DATETIME          IDX
ingested_at        DATETIME
```

### `wp_bt_signals_history`
```
id             BIGINT UNSIGNED   PK AUTO
symbol         VARCHAR(32)       IDX + signaled_at
direction      VARCHAR(12)                                LONG|SHORT|NEUTRAL
source_name    VARCHAR(200)      IDX + signaled_at
entry_price    DECIMAL(24,10) NULL
target_price   DECIMAL(24,10) NULL
stop_loss      DECIMAL(24,10) NULL
outcome        VARCHAR(12)    NULL  IDX                    WIN|LOSS|OPEN|EXPIRED
outcome_pct    DECIMAL(10,4)  NULL
signaled_at    DATETIME          IDX
closed_at      DATETIME       NULL
raw_url        TEXT                                        dedup key
```

### `wp_bt_events`
```
id          BIGINT UNSIGNED   PK AUTO
event_type  VARCHAR(64)       IDX + created_at             PRICE_ALERT|NEWS_PUBLISHED|...
symbol      VARCHAR(32)       IDX + created_at
payload     LONGTEXT                                       JSON
delivered   TINYINT(1)        IDX                          0|1 (for webhook retry)
created_at  DATETIME          IDX
```

## Retention policy

| Table              | Retention          | Why                                         |
|--------------------|--------------------|---------------------------------------------|
| price_history      | 90 days            | Covers longest default chart window         |
| news_items         | ∞ (no auto-purge)  | Compact text; archive is SEO content        |
| signals_history    | ∞ (no auto-purge)  | Competitive moat — never delete             |
| events (delivered) | 30 days            | Sufficient for webhook retry & audit        |

## Growth math

- 100 coins × 8 forex pairs × one snapshot per 5 minutes = 108 rows × 288 /day = ~31,000 rows/day for price_history.
- After 90 days (retention): ~2.8M rows in price_history. Each row ~64 bytes
  raw + index overhead. Total table size: ~250–350 MB. Well within any
  reasonable hosting plan.
- MySQL InnoDB with the compound index `(symbol, captured_at)` makes
  symbol-scoped range queries O(log n) regardless of table size.

## Safety & rollback

### This release is safe because…

1. **Additive only.** Not a single existing line of `class-widgets.php`,
   `class-rss.php`, or any options-reading code was modified. The new
   snapshotter runs after the existing cron does its thing.
2. **Silent failure.** If any of the insert helpers hit an error (e.g.,
   corrupted wpdb connection mid-cron), the existing options data is
   already saved, so the frontend still has current prices. The only
   consequence is a missing row in history — a gap that gets filled
   on the next cron tick.
3. **No option key collisions.** Only one new option is added:
   `bt_db_version`.

### Rollback

Same as v96.1 rollback, plus one extra step if you want the tables gone:

1. Delete the plugin folder (`/wp-content/plugins/blockticker-io/`).
2. Reinstall v96.1.0 or v96.0.0.
3. If you also want the custom tables dropped, define
   `BT_KEEP_DATA_ON_UNINSTALL` as `false` **before** deleting the plugin
   via the WP admin, OR manually run:
   ```sql
   DROP TABLE wp_bt_price_history, wp_bt_news_items,
              wp_bt_signals_history, wp_bt_events;
   DELETE FROM wp_options WHERE option_name = 'bt_db_version';
   ```

## What's next in the roadmap

This release delivers the **storage layer**. The next release consumes it:

- **v96.3** — Source validation framework + dead-feed replacement (Reuters
  RSS, Investing.com rate-limiting). Feeds write to `wp_bt_news_items`
  with deduplication already in place.
- **v96.4** — Proprietary charting (lightweight-charts). Native chart widget
  consumes `GET /history/{symbol}`.
- **v97.0** — `FXLM_` → `BT_` constant rename (breaking change); existing
  options keys kept as aliases.
- **v98.0** — Signal accountability leaderboard, sentiment enrichment on
  news, correlation heatmap.

See the full audit report for the complete roadmap.
