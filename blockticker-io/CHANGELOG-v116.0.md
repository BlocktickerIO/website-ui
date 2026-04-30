# BlockTicker v116.0.0 — Tax Year Report

**Release date:** April 2026
**Type:** Feature release (additive, no breaking changes)
**Upgrade path:** Drop-in over v115.0.0

---

## Overview

Completes the tax-reporting story the v114 portfolio tracker opened. The
v114 engine computes realized P&L as a single rolled-up number per coin —
useful for the dashboard, useless for filing a tax return. v116 replays the
same FIFO/HIFO matching logic but preserves every **closed-lot match** —
each pair of (acquire → dispose) with both dates, both prices, holding
period, proceeds, cost basis, and gain — which is exactly what tax forms
need row-by-row.

Three export formats ship:

1. **HTML tax report** — print-optimized full-page layout with summary
   totals, paginated lot table, and a disclaimer block. Converts to PDF
   cleanly via Ctrl/Cmd-P (browser-driven PDF rendering; no heavy PHP PDF
   library bundled).
2. **Raw realized-gains CSV** — all engine fields exposed for custom
   analysis in Excel / Sheets.
3. **Form 8949-compatible CSV** — exact column order accepted by TurboTax
   and FreeTaxUSA's crypto Form 8949 bulk-import.

All three driven from the same engine, the same lot-matching pass, and the
same classifier.

---

## New: `includes/class-tax-report.php`

One new class `BT_TaxReport` (~670 lines).

### Closed-lot engine

`BT_TaxReport::compute_closed_lots($txns, $method)` replays a coin's
ledger through FIFO or HIFO matching and returns one row per **matched
partition**. A sell that draws from three open lots produces three rows —
each with the specific buy date that was paired with that portion of the
sell.

Example row shape:

```json
{
  "symbol":         "BTC",
  "coin_id":        "bitcoin",
  "qty":            0.5,
  "acquired_at":    1641024000,
  "disposed_at":    1737936000,
  "unit_cost":      30000.00,
  "unit_sale":      60000.00,
  "cost_basis":     15000.00,
  "proceeds":       30000.00,
  "sell_fee_share":     0.00,
  "gain":           15000.00,
  "holding_days":   1121,
  "term":           "long",
  "gain_type":      "gain",
  "buy_id":         "txn_abc12345",
  "sell_id":        "txn_def67890"
}
```

Fee handling matches the v114 engine exactly: buy fees roll into the lot's
effective `unit_cost`; sell fees allocate proportionally to each closed
lot (so a $10 fee on a 2-BTC sell that matches two 1-BTC lots deducts $5
from each lot's realized gain).

The ACB engine is **intentionally excluded** from tax reports — average
cost basis has no single acquisition date per sold unit, so per-lot
holding-period classification doesn't apply. Users on Canada-style ACB
regimes should consult an accountant. Tax-report mode accepts only FIFO
or HIFO as the `method` parameter.

### LT vs ST classification

Holding period is computed as `floor((disposed_at - acquired_at) / 86400)`
and compared against a configurable threshold:

| Jurisdiction | Long-term threshold | Stored as |
|---|---|---|
| US (default) | 366+ days | `bt_tax_lt_days = 366` |
| DE / AT 10%-rule holdings | 366+ days | same default |
| UK | (doesn't apply — §104 pool) | flag as FYI only |
| Per-request override | `?lt_days=N` parameter | |

The threshold is settable globally via `bt_tax_lt_days` option OR
per-request via the shortcode/REST parameter, so a multi-jurisdictional
user can generate US + DE reports from the same ledger.

### Year filtering

Only lots whose `disposed_at` falls within the requested tax year
(Jan 1 00:00 UTC → Dec 31 23:59 UTC) appear in the report. Buys in
previous years that match a current-year sell are fully attributed
to the current year's gain calculation — which is correct because
realized gains are taxed in the disposal year, not the acquisition year.

### Verification

Offline smoke test with **30 assertions across 6 scenarios** — validates:

| Scenario | What it proves |
|---|---|
| Mixed LT/ST FIFO (1 BTC 4-year-old + 1 BTC 7-month-old, partial sell) | 1.5-BTC sell splits correctly into 1 LT lot + 0.5 ST lot with the right basis each ✅ |
| 365-day boundary | exactly 365 days = SHORT; exactly 366 days = LONG (matches IRS §1222) ✅ |
| Year filtering | a 2025 sell does NOT leak into the 2026 report ✅ |
| Broken lot | sell > buy produces separate broken row with $0 basis + `broken=true` flag; broken_count increments ✅ |
| Raw CSV | header row + data rows + summary footer with all totals ✅ |
| Form 8949 CSV | exact column names `Description, Date Acquired, Date Sold, Proceeds, Cost Basis, Gain/Loss, Short/Long` ✅ |
| HTML render | DOCTYPE, summary table, tax year title present ✅ |
| Year discovery | multi-year ledger returns distinct years sorted newest-first ✅ |

---

## Three export formats

### 1. HTML report (print-to-PDF)

`BT_TaxReport::to_html($report, $user_info)` produces a standalone HTML
document with:

- Letter-size `@page` margins (0.6in) — converts to PDF at exactly 8.5×11
- Header with site name + tax year + filer name (from user profile)
- 4-tile meta strip: Filer, Tax Year, Method, LT Threshold
- Summary table with three rows (Short-term / Long-term / Total) —
  Proceeds, Cost Basis, Gain/Loss columns with colour-coded gains
- Full lot table with alternating row backgrounds, broken-row amber
  highlighting, inline ST/LT term badges
- Disclaimer block warning that this isn't tax advice, with a specific
  call-out when broken lots are present
- Footer with generation timestamp + plugin version
- `@media print` CSS that hides non-essential chrome, so the browser's
  File → Print dialog produces a clean PDF with header/footer suppressed

No PHP PDF library (dompdf / mpdf / TCPDF) is bundled. Browser-driven
PDF rendering adds zero dependencies to the plugin zip and produces
better typography than any library because it uses the OS's native font
rasterizer. Users hit **Ctrl-P → Save as PDF** and get a pixel-perfect
document.

### 2. Raw realized-gains CSV

Columns (17 in total):

```
Symbol, Coin ID, Quantity, Acquired, Disposed, Holding Days, Term (ST/LT),
Unit Cost, Unit Sale, Cost Basis, Proceeds, Sell Fee Share, Gain/Loss,
Gain Type, Buy TXN ID, Sell TXN ID, Broken
```

Followed by a blank-line separator and a SUMMARY block with the
short-term / long-term / total breakdown. Dates are ISO `YYYY-MM-DD`.
Numbers use period decimal separator, no thousands separator — parses
cleanly in every locale.

### 3. Form 8949-compatible CSV

Exact column names and order accepted by TurboTax, FreeTaxUSA, and most
US tax software for crypto Form 8949 bulk-import:

```
Description, Date Acquired, Date Sold, Proceeds, Cost Basis, Gain/Loss, Short/Long
```

Key format decisions:

- **Description** = `"0.50000000 BTC"` — quantity (trimmed trailing
  zeros) + uppercase symbol, which is what Form 8949 Instructions
  example B uses.
- **Dates** = `MM/DD/YYYY` — US format, required by the IRS.
- **Broken lots** use `"Various"` in the Date Acquired column, which the
  IRS accepts for lots inherited or acquired in multiple lots that can't
  be specifically identified.
- **Short/Long** = `"Short"` or `"Long"` capitalised — matches the IRS
  instructions exactly.

No summary footer in this file — Form 8949 expects only transaction
rows, summary totals go on Schedule D.

---

## Shortcode: `[bt_tax_report]`

Full interactive tax report UI:

```
[bt_tax_report default_year="2026" default_method="fifo"]
```

| Attribute | Default | Description |
|---|---|---|
| `default_year` | current UTC year | Year pre-selected in the dropdown |
| `default_method` | `fifo` | FIFO or HIFO pre-selected |

Renders:

- **Controls row** — year dropdown (auto-populated with years that have
  sell activity), method dropdown (FIFO/HIFO), LT threshold numeric input
  (defaults from `bt_tax_lt_days` option), "↻ Update preview" button
- **3 download buttons** — HTML report (opens in new tab for printing),
  Realized-gains CSV, Form 8949 CSV. Each link rebuilds its URL on every
  control change so the downloaded file always matches the current preview.
- **Live preview** — auto-loads on mount, refreshes on "Update preview"
  click. Shows 5 summary tiles (ST gain, LT gain, total proceeds, total
  basis, net gain) + the full lot table with broken-row warnings.
- Guest users see a login prompt instead.

---

## REST API

One new route:

```
GET /wp-json/blockticker/v1/portfolio/v2/tax-report
  ?year=2026
  [&method=fifo|hifo]
  [&lt_days=366]
  [&format=json|csv|csv_form8949|html]
```

| Parameter | Default | Description |
|---|---|---|
| `year` | current UTC year | Tax year, `YYYY` |
| `method` | `fifo` | `fifo` or `hifo` |
| `lt_days` | `bt_tax_lt_days` option (366) | Long-term threshold |
| `format` | `json` | `json`, `csv`, `csv_form8949`, or `html` |

Cookie-auth required (reads user's ledger from usermeta).

Non-JSON formats emit the file directly with proper `Content-Type` +
`Content-Disposition: attachment` headers, so browsers download rather
than render — `blockticker-tax-2026.csv`, `blockticker-form8949-2026.csv`,
or the HTML report opens in a new tab for printing.

---

## Options introduced

| Option | Type | Default | Purpose |
|---|---|---|---|
| `bt_tax_lt_days` | int | `366` | Long-term classification threshold in days |

Per-user preferences are carried in the URL/shortcode parameters rather
than usermeta — tax report settings are ephemeral per generation, not
persistent state.

---

## `fx-live-markets.php`

- Version → 116.0.0 (header + `BT_VERSION`)
- `require_once BT_DIR . 'includes/class-tax-report.php'`
- `add_action( 'plugins_loaded', array( 'BT_TaxReport', 'init' ) )`

No deactivation hook — no cron events added.

---

## PHP lint

All 53 plugin PHP files clean.

---

## Backward compatibility

The legacy v52-era `BT_Portfolio` class is untouched. The v114
`BT_PortfolioV2` engine is untouched — `BT_TaxReport` calls
`BT_PortfolioV2::get_user_txns()` read-only and runs its own
replay pass.

---

## Safety & rollback

No database schema changes. To roll back:

```sql
DELETE FROM wp_options WHERE option_name = 'bt_tax_lt_days';
```

…then reinstall v115.0.0. Users' v114 transaction ledger is unaffected.

---

## Usage

### As an end user

1. Host `[bt_tax_report]` on a dashboard page.
2. Open it in January of the new year, pick the previous year in the
   dropdown, verify the method matches what you used all year.
3. Click **HTML report**, review every lot for accuracy against your
   exchange statements, then Ctrl-P → Save as PDF for your records.
4. Upload the **Form 8949 CSV** directly into TurboTax's crypto
   bulk-import wizard OR attach to Form 8949 as supporting detail.
5. If any lots show the ⚠ broken flag, go back to the portfolio page
   and import your older exchange history — those rows are
   over-reporting your gain with a $0 cost basis.

### As a site operator

No configuration required. Optionally tune `bt_tax_lt_days` if your
primary user base operates in a jurisdiction with a different long-term
threshold.

---

## Design call: why browser-print, not PHP PDF

Three PHP PDF libraries are plausible for WordPress: dompdf, mpdf,
TCPDF. All three share problems that would make a poor fit here:

| Concern | Impact |
|---|---|
| Bundle size | dompdf = 4 MB, mpdf = 12 MB, TCPDF = 8 MB. Would near-double the plugin zip. |
| Font handling | All three need bundled fonts for CJK/Unicode. Missing a font glyph renders as ▯. |
| Typography quality | All three produce "PDF-looking" PDFs — justified text is uneven, kerning is approximate, font rendering is chunky. |
| CSS support | dompdf supports CSS 2.1 + some 3; mpdf is better but still has quirks with flexbox/grid. |
| Performance | Rendering a 100-lot tax report on dompdf is ~2-4 seconds server-side. |

Browser-driven print-to-PDF via `@page` CSS bypasses every one of these.
The user's browser already has every font they'd ever need, it supports
CSS perfectly, and rendering is instant because it's local. Every major
competitor doing tax reports on the web (CoinLedger, Koinly's web view,
CoinTracker's preview mode) does exactly this. The UX cost — a 2-click
workflow (Ctrl-P → Save as PDF) — is far smaller than the engineering
cost of bundling, maintaining, and debugging a PHP PDF library.

A future v117+ "Cloud PDF" candidate could wire a server-side PDF
service (e.g. a worker that runs Chromium headless) for sites that want
one-click PDF export, at the cost of an external dependency.

---

## What's next

- **v117.0** — Asset Page 3.0: on-page integration of the v112 AI
  analysis + v106 correlation + v114 portfolio position for the current
  symbol, plus v113 share buttons, into a single cohesive per-asset
  template that replaces the v52-era asset pages.
- **v118.0** — Zero-Trust API Keys (opt-in hashed-at-rest mode) deferred
  from v115.
