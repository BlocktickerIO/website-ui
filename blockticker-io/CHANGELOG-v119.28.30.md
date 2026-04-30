# BlockTicker v119.28.30 — Audit-driven release · 11 fixes + calculators rebuilt

**Released:** 2026-04-29
**Type:** Audit-driven fix release with prioritized backlog. Addresses 11 issues from screenshots.
**Theme:** Critical bugs · Display polish · Calculator implementation · News page styling.

## Audit summary

I went through each screenshot and traced every issue to its root cause. The critical finding: **80% of issues are CSS cascade failures** — selectors like `.bt-breaking-bar` (didn't exist; real class is `.fxlm-breaking-bar`), or asset pages missing the typography stylesheet because they don't use the takeover renderer. The remaining 20% are real bugs (e.g., `[fxlm_calculator]` shortcode silently ignoring its `type` attribute).

## Prioritized backlog applied

### Critical (this release) — DONE

| # | Issue | Root cause | Fix |
|---|---|---|---|
| 1 | `,287.53` orphan text leak (DevTools confirmed in screenshot 4 of last batch) | `inject_live_prices()` PHP regex producing partial matches | Already fixed in v29 — disabled PHP injection + added JS cleanup |
| 2 | All 4 calculators (`/tools/profit-calculator/`, `currency-converter/`, `position-size/`, `pip-margin/`) showed the SAME page (currency converter) | `sc_calculator()` ignored the `type` attribute completely | Refactored — dispatches by `type` to 4 distinct calculators with proper inputs and outputs (P/L %, R-multiples, dollar risk, pip values, margin requirements) |
| 3 | News pages (`/news/crypto/`, `/news/web3/`, etc.) had double H1 (e.g., "Crypto News" + "Crypto news") | `has_own_header` detection missed news shortcodes and content starting with `<h2>` | Extended detection to: `[fxlm_news_feed]`, `[fxlm_breaking_news]`, `[bt_sentiment_bar]`, content starting with `<h2>`, and the `bt-auth-card` from v28 |
| 4 | Breaking news bar (`/news/breaking/`) styled wrong — items overflowing red box | v29 §28 targeted `.bt-breaking-bar` but the real class is `.fxlm-breaking-bar` | New §32 with proper selectors: fixed-width BREAKING tile (100-120px), horizontally-scrolling items with hairline separators, proper red-tinted background |
| 5 | Asset pages (`/analysis/bitcoin/`) had dark-blue background instead of `#0A0B0D` | Theme cascade winning on `body`, `#page`, `#content`, etc. | New §34 — locks asset page background with full container chain |
| 6 | Asset page mega menu rendering all-green (screenshot 8) | Theme cascade overrode `.mega__col a` color | New §33 — explicit color lock on `body.bt-asset-page .mega__col a` with full priority chain |
| 7 | News headlines rendering in solid green | `.fxlm-news-item h3` and `.fxlm-news-item a` defaulting to accent color | New §41 — white default + green-on-hover for all news titles |
| 8 | Crypto category tables (`/defi/`, `/nfts/`) too tall row padding | No `td` padding override | New §35 — 8px row padding + tabular numerals + up/down color coding |
| 9 | Double pagination on `/crypto-markets/` (screenshot 1) | Two pager systems both rendering | New §31 — hides duplicate pagination via sibling-presence selector + proper styling for the surviving `fxlm-pg-btn` set |
| 10 | Mega menu cards bad spacing (screenshots 7, 8) | Default `.mega__col a` had `padding: 7px 8px` and inline display | New §37 — 9px padding, flex layout with 10px icon gap, proper sub-text sizing |
| 11 | News page section H2 + lede needed sane sizing | Cascading from page-content > h2 was inflating | New §36 — first-child h2 = 26px Space Grotesk; first-child p = 15px Inter at 680px max-width |

### High priority (next release)

| Issue | Status |
|---|---|
| `/desk-brief/` page improvements | Empty-state CSS added (§40) but content rendering needs deeper work |
| Trading-signals page redesign | NEW §39 — full Trade-Ideas-inspired card-based layout with hairline separators between cards, 4px sentiment-color sidebar, source pills in source-color, hover-translate arrow |

### Lower priority (backlog)

- `/sentiment/` widget styling
- `/economic-calendar/` table layout
- `/api-docs/` syntax highlighting
- Mobile breadcrumb truncation on long paths

## Files changed

| File | Change |
|---|---|
| `includes/class-widgets.php` | Refactored `sc_calculator()` — now dispatches by `type` attribute to 4 distinct calculator implementations: `render_currency_converter()`, `render_pip_calculator()`, `render_position_calculator()`, `render_profit_calculator()` |
| `includes/class-site-takeover.php` | Extended `has_own_header` detection to news shortcodes, content starting with `<h2>`, and auth pages |
| `assets/css/typography.css` | New §31 (pagination), §32 (breaking bar — correct class names), §33 (mega menu lock), §34 (asset bg lock), §35 (crypto table density), §36 (news prose), §37 (mega card polish), §38 (calculator widgets), §39 (signals redesign), §40 (desk-brief fallback), §41 (news headlines) — +301 lines |
| `fx-live-markets.php` | Version bump 119.28.29 → 119.28.30 |

## What each new calculator does

### `[fxlm_calculator type="pip"]` — `/tools/pip-margin/`
- **Inputs:** Currency pair (6 majors), lot size, leverage (1:1 to 500:1)
- **Outputs:** Pip value (USD per pip move), Margin required (at selected leverage), Notional value (contract size in USD)
- **Logic:** Handles USD-quote pairs (EUR/USD), USD-base pairs (USD/JPY, USD/CAD, USD/CHF), and JPY pairs with proper pip-size differentiation (0.0001 vs 0.01)

### `[fxlm_calculator type="position"]` — `/tools/position-size/`
- **Inputs:** Account equity, risk % per trade, entry price, stop-loss price
- **Outputs:** Position size (units), Dollar risk, Risk per unit (entry − stop)
- **Logic:** `position = floor((equity × risk%) / |entry − stop|)`. Includes inline tip about 0.5–2% professional risk caps

### `[fxlm_calculator type="profit"]` — `/tools/profit-calculator/`
- **Inputs:** Direction (long/short), entry, exit, position size, stop (for R), fees per side
- **Outputs:** Gross P/L, Net P/L (after fees both sides), R-multiple, % return on entry
- **Logic:** Direction-aware. R-multiple = `pnl_per_unit / |entry − stop|`. Color-coded green for positive, red for negative

### `[fxlm_calculator type="converter"]` — `/tools/currency-converter/`
- Original USD ↔ crypto converter, kept verbatim under new method name

## Pre-package validation

- ✅ All 68 PHP files in `includes/`: `php -l` clean
- ✅ Plugin header version: 119.28.30
- ✅ `BT_VERSION` constant: 119.28.30
- ✅ `typography.css`: 2480 lines (was 2179 in v29, +301)
- ✅ ZIP: 2.19 MB

## Deploy

1. **Deactivate** plugin → upload `blockticker-io-v119.28.30-full.zip` → **activate**
2. **Hard-refresh** (Ctrl+Shift+R) and **purge CDN** for:
   - `/wp-content/plugins/blockticker-io/assets/css/typography.css?ver=119.28.30`
   - All HTML pages — particularly `/tools/*`, `/news/*`, `/analysis/*`, `/crypto-markets/`, `/trading-signals/`

## Verification checklist

After deploy:

- [ ] `/tools/pip-margin/` — pip calculator with Pair / Lot / Leverage inputs and 3 result cards
- [ ] `/tools/position-size/` — position calc with equity/risk/entry/stop inputs and tip box
- [ ] `/tools/profit-calculator/` — profit calc with direction/entry/exit/size/fees and color-coded results
- [ ] `/tools/currency-converter/` — original USD↔crypto converter (unchanged)
- [ ] `/news/crypto/` — single H1 only ("Crypto news" — no duplicate "Crypto News" above)
- [ ] `/news/breaking/` — BREAKING bar fits inside container, items scroll horizontally with hairline separators
- [ ] `/analysis/bitcoin/` — dark-charcoal background (`#0A0B0D`), NOT dark blue
- [ ] `/analysis/bitcoin/` — Markets/Analysis/Tools mega menu cards render with white text + gray sub-text (NOT solid green)
- [ ] `/crypto-markets/` — only ONE pagination (the `‹ 1 2 3 ›` style), not two
- [ ] `/trading-signals/` — signal cards render with 4px sentiment-color sidebar, source pill in source-color, arrow that translates on hover
- [ ] News page headlines — white by default, green ONLY on hover

## What this release does NOT yet fix

- **Trading-signals page deeper restructure** — §39 styling improves the look significantly but the underlying page structure (4 panel sections) wasn't redesigned. Could be replaced with a TradingView-Ideas-style 2-column feed in a future release.
- **Desk-brief content rendering** — §40 adds an empty-state CSS but if the desk brief data isn't being generated, the empty state shows. Need to check if `BT_Desk_Brief::generate()` is running on its cron schedule.
- **Calculator data persistence** — calculations don't save between page reloads. Could add localStorage if requested.

## Rollback

```sql
INSERT INTO wp_options (option_name, option_value) VALUES ('bt_disable_site_takeover', '1');
```

Calculator changes are additive (the `type=converter` default preserves old behavior). Hash-drift mechanism from v28 ensures the calculator pages auto-update on activation.
