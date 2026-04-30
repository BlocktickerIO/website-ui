# BlockTicker v96.4.0 — Native Charting

**Release date:** April 2026
**Type:** Feature release (additive, no breaking changes)
**Upgrade path:** Drop-in over v96.3.0

---

## Overview

Replaces 100% TradingView-embed dependency with a proprietary chart widget
built on **lightweight-charts** (MIT licence, published by TradingView as
open-source, ~45 KB gzipped). The chart feeds from BlockTicker's own price
history database (introduced in v96.2), making it a true differentiator —
your charts, your data, your brand.

TradingView embeds are **not removed** — they are kept as the fallback
when fewer than 10 rows of local history exist (e.g. within the first few
cron cycles of a fresh install). Once history accumulates the native chart
takes over automatically, with no admin action required.

---

## New: `includes/class-native-chart.php`

One new class `BT_NativeChart` (~440 lines).

### Shortcode `[bt_price_chart]`

Place on any page or post. All attributes are optional.

```
[bt_price_chart symbol="BTC" type="area" days="30" resolution="1h" height="380" show_volume="1" show_ranges="1"]
[bt_price_chart symbol="EUR/USD" type="line" days="7" resolution="1h"]
[bt_price_chart symbol="ETH" type="signal" days="90" resolution="1d" title="ETH Signal Track Record"]
```

| Attribute | Values | Default | Description |
|-----------|--------|---------|-------------|
| `symbol` | BTC, ETH, EUR/USD … | BTC | Asset symbol (must match what's in `wp_bt_price_history`) |
| `type` | area \| line \| signal | area | Chart type. `signal` overlays buy/sell markers from `wp_bt_signals_history`. |
| `days` | 1–365 | 30 | History window |
| `resolution` | 5m \| 1h \| 1d | 1h | Time bucket size |
| `height` | px integer | 380 | Total chart height including volume panel |
| `theme` | dark \| light \| auto | auto | Currently dark; light/auto reserved for v96.7 |
| `show_volume` | 1 \| 0 | 1 | Show volume histogram below price chart |
| `show_ranges` | 1 \| 0 | 1 | Show 1D / 7D / 30D / 90D range buttons |
| `title` | string | auto | Chart title shown in header bar |
| `tv_symbol` | string | auto | TradingView symbol used by the fallback embed |

### Chart types

**`area`** (default)
Area chart with blue gradient fill. Price tooltip on crosshair. Volume
histogram on a separate synced panel below. Range buttons switch the data
window without a page reload.

**`line`**
Same as `area` without the gradient fill. Useful for overlaying on
content-heavy pages where the fill adds visual noise.

**`signal`**
Area chart with arrow markers overlaid from `wp_bt_signals_history`:
- `arrowUp` (below bar) = LONG signal
- `arrowDown` (above bar) = SHORT signal
- Color encodes outcome: `#26a69a` (green) = WIN, `#ef5350` (red) = LOSS,
  `#888` (gray) = OPEN / unknown

This is the "signal accountability" chart described in the audit report —
every historical call is plotted with its outcome visible.

### Graceful fallback

If `wp_bt_price_history` has fewer than 10 rows for the requested symbol,
the shortcode renders the existing TradingView embed instead, with a
small badge showing "⏳ Building history (N/10 rows)". This makes the
shortcode safe to place on pages immediately after upgrading, before the
cron has had a chance to accumulate data.

### Range buttons

1D / 7D / 30D / 90D buttons are wired to re-fetch from the REST endpoint
via `fetch()`. Switching range is instant (no page reload). Active range
button is highlighted. The initial render uses the `days` and `resolution`
shortcode attributes.

### Tooltip

Crosshair tooltip shows date + formatted price. Price format adapts:
- Forex pairs → 5 decimal places
- Crypto ≥ $1 000 → `$67,234.12`
- Crypto $1–$999 → `$0.0042`
- Micro-cap → 8 decimal places

### Time-scale sync

When `show_volume="1"`, the price and volume charts are two separate
lightweight-charts instances whose time scales are kept in sync via
`subscribeVisibleLogicalRangeChange`. Zooming or scrolling one chart
moves the other.

### ResizeObserver

Charts respond to container width changes (sidebar/no-sidebar layouts,
window resize) via `ResizeObserver`.

---

## New REST endpoint: `GET /wp-json/blockticker/v1/chart/{symbol}`

Extended version of the existing `/history/{symbol}` endpoint, optimised
for chart consumption.

Parameters: `days`, `resolution`, `signals` (0 or 1)

Response shape:
```json
{
  "data": {
    "symbol": "BTC",
    "days": 30,
    "resolution": "1h",
    "count": 720,
    "price_series":  [{ "time": 1744243200, "value": 67234.5 }, …],
    "volume_series": [{ "time": 1744243200, "value": 28500000000 }, …],
    "signals": [
      { "time": 1744300000, "position": "belowBar", "color": "#26a69a", "shape": "arrowUp", "text": "LONG · WIN" }
    ]
  },
  "meta": { "generated_at": "2026-04-20T21:00:00+00:00" },
  "source": "BlockTicker native chart data"
}
```

`Cache-Control: public, max-age=120` on the response — chart data is
safe to edge-cache, which matters for pages with multiple chart instances.

---

## Asset delivery

lightweight-charts is loaded via `wp_register_script` + `wp_enqueue_script`
(only enqueued on pages where `[bt_price_chart]` appears, in `wp_footer`).

CDN: `https://cdn.jsdelivr.net/npm/lightweight-charts@4.1.3/...`
Version pinned to 4.x — the v5.x API has breaking changes; upgrade will
be a deliberate v96.x step when ready.

---

## Verified

- PHP lint ✅ — `class-native-chart.php`, `fx-live-markets.php`
- Zero breaking changes ✅ — existing `[fxlm_tradingview_chart]` shortcode untouched
- Fallback path ✅ — graceful TradingView embed when history is empty
- REST namespace ✅ — new `/chart/` route registered in existing `blockticker/v1` namespace

---

## What's next

- **v96.5** — SEO layer: JSON-LD (BreadcrumbList, Article, FinancialProduct),
  dynamic `sitemap.xml`, OpenGraph tags, unique meta descriptions per
  asset page. *(ChatGPT audit: SEO scored 50/100 — biggest organic traffic gap.)*
- **v96.6** — Core Web Vitals: defer TradingView JS, WebP image fallbacks,
  asset cache headers.
- **v96.7** — WCAG 2.1 AA accessibility pass (scored 40/100).
- **v97.0** — `FXLM_` → `BT_` rename (breaking; own session).
