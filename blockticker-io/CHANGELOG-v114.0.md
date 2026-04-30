# BlockTicker v114.0.0 — Portfolio Tracker 3.0

**Release date:** April 2026
**Type:** Feature release (additive, no breaking changes)
**Upgrade path:** Drop-in over v113.0.0

---

## Overview

The legacy portfolio tracker (v52-era, still present as `BT_Portfolio` and
`[bt_portfolio]`) stores a flat list of holdings with a single `avgBuy` value
per coin. It cannot model sells, cannot compute realized P&L, has no concept
of tax-lots, and is not fee-aware. v114 ships a parallel `BT_PortfolioV2`
system that does all of the above — while leaving the legacy class and
shortcodes entirely untouched, so every existing page continues to work
identically.

The core upgrade is moving from a rolled-up "holdings" model to a
transaction-level ledger. Every buy and sell is its own record, and
portfolio state is computed by replaying the ledger through one of three
cost-basis engines.

---

## New: `includes/class-portfolio-v2.php`

One new class `BT_PortfolioV2` (~950 lines).

### Data model

Each transaction is stored as:

```json
{
  "id":      "txn_a1b2c3d4",
  "kind":    "buy" | "sell",
  "coin_id": "bitcoin",
  "symbol":  "BTC",
  "qty":     0.5,
  "price":   42000.00,
  "fee":     5.00,
  "ts":      1745200000,
  "note":    "DCA week 3"
}
```

Storage layer is dual:

| User type | Storage | Key |
|---|---|---|
| Logged-in | `wp_usermeta` | `bt_portfolio_txns` (JSON array) |
| Guest | `localStorage` | `bt_portfolio_txns` |

A guardrail caps the ledger at 5,000 transactions per user, keeping the
newest if exceeded.

### Cost-basis engines

Three engines are implemented in pure PHP, each taking a time-ordered
transaction list for a single coin and producing a position object:

```
{
  qty_held, cost_basis, realized_pnl, sell_count, broken
}
```

**FIFO** (default). Lots are maintained in buy order; every sell depletes
the oldest lots first. This matches the IRS default for crypto in the US
and is the most conservative default for tax reporting.

**HIFO** (Highest In First Out). At each sell, the highest-cost open lot
is consumed first — minimizing realized gains and therefore tax liability.
Legal in the US as a "Specific Identification" method as long as adequate
records are kept; the transaction ledger itself is that record.

**ACB** (Average Cost Basis). Maintains a single running pool of
`(total_cost, total_qty)` across all buys. Every sell computes realized
P&L against the pool's average, then proportionally reduces both
aggregates. This is the **required** method in Canada (Adjusted Cost Base
rule) and the **default** in the UK (Section 104 holding).

All three engines are **fee-aware** in the accounting-correct way:

- **Buy fees** are rolled into the lot's effective unit cost. A buy of
  1 BTC at $30,000 with a $100 fee creates a lot at $30,100/unit, so every
  future sell of that lot reports P&L against $30,100, not $30,000.
- **Sell fees** reduce realized proceeds. Selling 1 BTC at $40,000 with a
  $50 fee from a $30,100 basis lot reports $9,850 realized, not $9,900.

This matches how every major tax jurisdiction computes capital gains.

**Verification.** A 14-assertion smoke test in `_bt_pv2_test.php` (removed
from the release zip; source kept in dev history) validates all three
engines against hand-computed expected values for:

| Scenario | FIFO | HIFO | ACB |
|---|---|---|---|
| 3 buys ($30k, $50k, $40k) → sell 1 @ $60k | +$30,000 ✅ | +$10,000 ✅ | +$20,000 ✅ |
| 1 buy @ $30k w/$100 fee → sell 1 @ $40k w/$50 fee | +$9,850 ✅ | — | — |
| 1 buy 1 BTC → sell 2 BTC @ $40k (overshoot) | +$50k, broken=true ✅ | — | — |
| 2 buys → partial 0.5-BTC sell @ $60k | +$15,000, 1.5 held ✅ | — | — |

### Overshoot handling

If a sell exceeds available qty (typical cause: user imported only recent
CSV data and missed older buys), the engine treats the excess as pure
proceeds at zero basis and raises a `broken: true` flag. The UI surfaces
this as a ⚠ icon on the affected row with tooltip guidance.

### Portfolio-wide aggregator

`compute_portfolio($txns, $method)` groups transactions by `coin_id`,
runs the selected engine for each position, joins live prices from
`bt_crypto_data`, and returns:

```json
{
  "method":   "fifo",
  "rows": {
    "bitcoin": { "symbol": "BTC", "name": "Bitcoin", "qty_held": 2.0,
                 "cost_basis": 90000, "avg_cost": 45000, "current_price": 67234,
                 "value": 134468, "realized_pnl": 30000, "unrealized_pnl": 44468,
                 "sell_count": 1, "broken": false, "txn_count": 4 }
  },
  "totals": { "value", "cost_basis", "realized_pnl", "unrealized_pnl",
              "total_pnl", "total_pct" },
  "generated_at": 1745200000
}
```

Rows are sorted by current USD value descending.

---

## Shortcodes

### `[bt_portfolio_v2]`

Full dashboard. Renders:

- 5 summary tiles: current value, cost basis, unrealized P&L, realized
  P&L, total return %
- Method selector dropdown (FIFO / HIFO / Average) — changes persist
  server-side for logged-in users, force a reload
- Add-transaction form: coin dropdown (top 100 by market cap from
  `bt_crypto_data`), kind (BUY/SELL), qty, price, fee, date, note
- Per-coin positions table: symbol, qty held, avg cost, current price,
  value, unrealized P&L, realized P&L — with ⚠ marker on broken positions
- Transaction log (last 100, newest first) with delete button per row
- CSV import widget (logged-in only)

Attributes:

| Attribute | Default | Description |
|---|---|---|
| `default_method` | `fifo` | Method used for guests (logged-in users' setting wins) |
| `show_import` | `1` | Show the CSV import block |

### `[bt_portfolio_summary]`

One-line summary widget suitable for sidebars and user-dashboard pages:

> 💼 Value **$12,450.32** · P&L **+$2,180.50** (+21.20%) `FIFO`

Attributes:

| Attribute | Default | Description |
|---|---|---|
| `show_method` | `0` | Render the method badge on the right |

Guest users see a "Log in to view your portfolio" message instead.

### `[bt_portfolio_import]`

Standalone CSV import form for a dedicated import page.

---

## REST API

Four new routes under `/wp-json/blockticker/v1/portfolio/v2/`:

| Method | Path | Description |
|---|---|---|
| `GET` | `/transactions` | List transactions + computed summary |
| `POST` | `/transactions` | Add one transaction (upsert by id) OR bulk replace with `{transactions:[…]}` |
| `DELETE` | `/transactions/{id}` | Delete a single transaction |
| `POST` | `/import` | Multipart CSV upload |
| `POST` | `/method` | Set cost-basis method (`fifo`/`hifo`/`acb`) |

All routes require `is_user_logged_in()`. Nonce: standard `X-WP-Nonce`
(`wp_rest` action).

POST `/transactions` response:

```json
{
  "success": true,
  "txn":     { "id":"txn_a1b2c3d4", "kind":"buy", … },
  "summary": { "method":"fifo", "rows":{…}, "totals":{…} }
}
```

---

## CSV import

### Dialect auto-detection

Four dialects are auto-detected from the header row:

| Dialect | Detection signal |
|---|---|
| **Binance** | Headers include "Base Asset" + "Executed Qty" |
| **Coinbase** | Headers include "Transaction Type" + "Spot Price USD" |
| **Kraken** | Headers include "ledgers" or "refid" |
| **Generic** | Any other CSV with recognised columns |

### Column aliases

The parser recognises a wide set of header aliases per column, so
manual-exported CSVs from any source tend to work without edits:

| Column | Recognised headers |
|---|---|
| **Date** | date, timestamp, time, created at, transaction date, trade time, filled at, time (utc) |
| **Type** | type, side, kind, transaction type, operation, buy/sell, action |
| **Symbol** | symbol, asset, ticker, currency, base asset, asset symbol, pair |
| **Coin ID** | coin, coin id, asset id, asset name, coin name |
| **Qty** | qty, quantity, amount, size, units, filled, executed qty, shares |
| **Price** | price, unit price, price per coin, trade price, fill price, spot price usd |
| **Fee** | fee, fees, commission, trading fee, fee amount |
| **Note** | note, notes, memo, description, remark |

### Smart symbol handling

- If only a `symbol` column is present, the parser maps 30 major tickers
  to CoinGecko `coin_id`s via a built-in lookup (BTC→bitcoin,
  ETH→ethereum, SOL→solana, USDT→tether, etc.).
- Exchange pairs with USD/USDT/USDC/BUSD suffixes are stripped
  (`BTCUSDT` → `BTC` → `bitcoin`).
- Unknown tickers fall through as `lowercase(symbol)` as the coin_id, so
  they still track correctly in localStorage even if CoinGecko pricing
  isn't available.

### Type normalisation

The `type` column accepts any of: `buy`, `b`, `long`, `purchase`, `in`,
`deposit`, `acquire` → buy; and `sell`, `s`, `short`, `disposal`, `out`,
`withdraw`, `dispose` → sell. Case-insensitive substring match so
"Market Buy" and "BUY LIMIT" both resolve cleanly.

### Delimiter detection

Tries comma, semicolon, and tab — picks whichever appears most frequently
in the first line. Handles European CSV exports (semicolon-delimited with
comma decimal separators) cleanly.

### Currency stripping

The `strip_currency()` helper removes `$`, `€`, `£`, and comma
thousands-separators before parsing numbers, so
`"$1,234.56"`, `"€1.234,56"` (via locale fallback), and `"1234.56 USD"`
all yield `1234.56`.

### Dedupe

Before appending, each incoming row is checked against the existing
ledger: if another transaction has the same `coin_id`, `kind`, `qty`
(±1e-9), `price` (±1e-6), and timestamp (±60s), it's skipped. This makes
re-imports safe — uploading the same CSV twice doesn't duplicate
everything.

Response reports `added`, `skipped`, `total`, and the detected `dialect`.

### Mode: append vs replace

Two import modes:

| Mode | Behaviour |
|---|---|
| `append` | Add new rows, dedupe against existing (default) |
| `replace` | Clear existing ledger, import from scratch |

### File size limit

2 MB hard cap on the upload, enforced server-side before parsing.

---

## Options introduced

| Option | Type | Default | Purpose |
|---|---|---|---|
| `bt_portfolio_txns` (usermeta) | JSON array | `[]` | Per-user transaction ledger |
| `bt_portfolio_method` (usermeta) | string | `'fifo'` | Selected cost-basis engine |

---

## `fx-live-markets.php`

- Version → 114.0.0 (plugin header + `BT_VERSION`)
- `require_once BT_DIR . 'includes/class-portfolio-v2.php'`
- `add_action( 'plugins_loaded', array( 'BT_PortfolioV2', 'init' ) )`

---

## PHP lint

All 2 touched files clean:
- `includes/class-portfolio-v2.php` ✅ (new)
- `fx-live-markets.php` ✅

---

## Backward compatibility

Legacy `BT_Portfolio` class is **entirely untouched**. Every existing
page using `[bt_portfolio]`, `[fxlm_portfolio]`, `[bt_price_alerts]`, or
`[bt_alert_manager]` continues to work identically. The two systems share
no storage keys — `bt_portfolio` (legacy localStorage) and
`bt_portfolio_txns` (v2) coexist without interfering.

---

## Safety & rollback

No database schema changes. No cron events added. Per-user data is stored
in standard `wp_usermeta` rows. To fully roll back:

```sql
DELETE FROM wp_usermeta WHERE meta_key IN ('bt_portfolio_txns', 'bt_portfolio_method');
```

…then reinstall v113.0.0. Users' legacy `bt_portfolio` data in localStorage
and the old holdings key are unaffected.

---

## Usage

### Logged-in user, fresh start

Add `[bt_portfolio_v2]` to a page (a user-dashboard page is the natural
home). Users can:

1. Pick a cost-basis method (FIFO by default).
2. Add transactions one at a time, or import a CSV export from their
   exchange.
3. Every change is saved server-side instantly via the REST API.
4. The same data appears on every device they log in from.

### Guest user

Same dashboard, but transactions live in `localStorage` under
`bt_portfolio_txns`. A yellow notice at the top encourages logging in
for cross-device sync. Logging in does not auto-migrate guest data —
this is a deliberate trust boundary (the guest ledger could be
anyone's); users can manually export/re-import via CSV if desired.

### Compact summary embed

On dashboards or sidebars, `[bt_portfolio_summary show_method="1"]`
renders a single-line view with value + total P&L + method badge.

### CSV import-only page

For a dedicated upload page (e.g. `/portfolio/import/`), use
`[bt_portfolio_import]` — the full dashboard is overkill if the user
just needs to import.

---

## What's next

- **v115.0** — Public API Keys: per-user API tokens for the existing
  `/wp-json/blockticker/v1/*` endpoints with per-key rate limits, usage
  metering, and admin panel for revocation.
- **v116.0** — Tax-year report export: on-demand PDF/CSV of realized
  gains grouped by tax year, with long-term vs short-term classification
  (>1-year lot ageing) — builds directly on the v114 ledger.
