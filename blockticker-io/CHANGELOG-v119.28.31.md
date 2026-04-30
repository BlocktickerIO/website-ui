# BlockTicker v119.28.31 — Unified Design System + Tools/Signals/Desk-Brief redesigns

**Released:** 2026-04-29
**Type:** Architectural release — introduces a unified design tokens layer + redesigned key pages.
**Theme:** Design system foundation · Component library · Backlog priority items shipped.

## Senior UI/UX audit summary

Iterating on individual fixes was hitting diminishing returns. After 4 releases of CSS patches (v27 → v30) the underlying problem became clear: **the plugin has 8+ years of accumulated CSS files** (`frontend.css`, `revamp-v44.css`, `desk-brief.css`, `landing-revamp.css`, `typography.css`, `critical-fixes.css`, `signal-archive.css`, `patch-animations.css`...) all defining their own color, typography, and spacing values. Every new feature needs new CSS, every patch fights the cascade.

The fix: a **canonical design tokens layer** (`bt-tokens.css`) loaded LAST so it wins. It defines:
- Tokens (colors, typography, spacing, radii, shadows, transitions)
- Reusable components (`.bt-card`, `.bt-stat`, `.bt-badge`, `.bt-hero`, `.bt-tile`, `.bt-section`, `.bt-empty`, `.bt-grid`)

Once this exists, future pages just compose components — no new CSS required for routine work.

## Competitive inspirations applied

| Competitor | Pattern adopted |
|---|---|
| **CoinMarketCap** | Wide tables (1440px+), tabular numerals, semantic up/down colors |
| **Coinbase** | Generous whitespace, large prices as hero elements |
| **TradingView Ideas** | Card-based feed with sentiment-color sidebar, source pills colored by source |
| **Bloomberg Terminal** | Dense data display with hairline dividers, Inter at 13px for tables |
| **Stratechery / FT FastFT** | Editorial typography with Space Grotesk display + 17px Inter body for desk-brief |

## Backlog items shipped this release

### 1. Unified design tokens layer — `bt-tokens.css`

42KB / 1166 lines. Loaded after `typography.css` so it wins cascade conflicts on shared selectors. Defines:

- **Colors:** 6-step background ramp (`--bt-bg-page`, `--bt-bg-elev-1` through `--bt-bg-elev-3`), 6-step text ramp (`--bt-text-1` through `--bt-text-6`), brand + bull/bear/warn/info with `-soft` variants
- **Typography:** Space Grotesk for `--bt-font-display`, Inter for `--bt-font-ui`. Type scale from `--bt-fs-eyebrow` (11px) up to `--bt-fs-hero` (clamp 40-56px)
- **Spacing:** 10-step scale (`--bt-sp-1` 4px → `--bt-sp-10` 64px)
- **Radii:** 5 levels (`sm` 6px → `xl` 20px → `full` 999px)
- **Shadows + transitions:** consistent depth and timing

### 2. Reusable component classes

| Component | Purpose | Variants |
|---|---|---|
| `.bt-card` | Surface for any content block | `--hover`, `--accent`, `--compact` |
| `.bt-stat` | Big number with label | `__value--lg`, `__value--bull`, `__value--bear`, `__value--warn` |
| `.bt-badge` | Small pill label | `--bull`, `--bear`, `--warn`, `--accent`, `--info`, with `__dot` |
| `.bt-hero` | Page hero header | `__title`, `__lede`, `__stats` |
| `.bt-section` | Numbered section frame | `__num`, `__title`, `__meta`, `__meta--live` |
| `.bt-tile` | Feature tile (tools, etc.) | `__icon`, `__title`, `__desc`, `__arrow` with hover-glow |
| `.bt-grid` | Responsive grid | `--2`, `--3`, `--4`, `--2-fixed`, `--3-fixed`, `--sidebar` |
| `.bt-empty` | Empty state | `__icon`, `__title`, `__desc`, `__cta` |

### 3. `/tools/` landing page — full redesign

**Before:** A bullet list of 4 calculator links + a Fear & Greed widget. Looked like a 2014 WP page.

**After:** Two-section layout using `.bt-section` + `.bt-tile`:
- **Section 01 — Calculators & converters:** 4 tile cards (Profit, Currency Converter, Position Size, Pip & Margin) with icons, descriptions, and hover-glow with translating arrow
- **Section 02 — Decision aids:** 4 more tiles (Fear & Greed Index, Economic Calendar, Watchlist & Portfolio, API & Webhooks)

Each tile is a real `<a>` link wrapped in `.bt-tile` with proper accessibility, smooth hover transition (translateY + accent-color glow), and the `→ OPEN TOOL` micro-CTA that animates on hover.

### 4. `/trading-signals/` page — deeper restructure

**Before:** Single-column stack with 4 panels stacked vertically. Cramped.

**After:**
- **Hero header** with eyebrow + H1 + lede (using `.bt-hero` pattern)
- **Source disclosure banner** (kept — important for trust)
- **Section 01 — Track record** (`[bt_signal_track_record]`)
- **Section 02 — Live signal feed** with new TradingView-Ideas-inspired 2-column layout:
  - LEFT: signal feed cards (the redesigned `.fxlm-signal-v2` from v30 §39 — sentiment-color sidebar + source pill + relative time)
  - RIGHT (≥1100px): sidebar with "Sources Tracked" card + "How to read these" explainer card + "Browse signal archive →" CTA card
  - Below 1100px: stacks to single column
- **Section 03 — AI Market Analysis** (kept — `[bt_cross_market_card]` + `[bt_intelligence_brief]`)

### 5. Desk-brief — token alignment + section polish

Refined `.bt-brief__sec`, `.bt-brief__sec-head`, `.bt-brief__sec-num`, `.bt-brief__sec-h`, `.bt-brief__sec-tag`, `.bt-brief__lede`, `.bt-brief__chart-wrap`, `.bt-brief__levels`, `.bt-brief__level-cell` to use the unified token system. Now the per-section render (Crypto, Forex, Macro, Watching) is visually consistent with the rest of the site.

Specifically: numbered eyebrows in accent-soft pill, Space Grotesk H2s at 24px, lede paragraphs in Inter 15px / 1.65 line-height with 68ch max-measure, level cells with tabular numerals + token-driven colors.

### 6. Footer final polish

Caps any older `.foot__*` rule to use unified token colors. Footer column headers now: Inter 11px / 600 / uppercase / `--bt-text-1`. Links: Inter 13px / `--bt-text-4` → hover `--bt-text-1`. Bottom strip in `--bt-text-5`.

### 7. Forex & commodities tables — token-driven

`.fxlm-forex-table` and `.fxlm-commodities-table` now use:
- Background: `--bt-bg-elev-1` with `--bt-border-1` 1px border + `--bt-radius-lg`
- Headers: `--bt-bg-elev-2` background, uppercase Inter 11px / 600 in `--bt-text-4`
- Cells: 13px Inter, tabular numerals, hover-row `--bt-bg-elev-2`

### 8. Utility classes

`.bt-mt-*`, `.bt-mb-*` (token-driven margins), `.bt-text-bull/bear/warn/mute/1/2`, `.bt-num` (tabular slashed-zero) — handy for inline overrides without writing one-off CSS.

## Files changed

| File | Change |
|---|---|
| `assets/css/bt-tokens.css` | **NEW** 1166 lines · 11 sections · canonical tokens + components + page-specific polish |
| `includes/class-site-takeover.php` | Enqueues `bt-tokens.css` LAST after `bt-typography` |
| `includes/class-landing-revamp.php` | Same enqueue chain on landing path |
| `includes/class-asset-pages.php` | Same enqueue chain on asset detail pages |
| `includes/class-page-provisioner.php` | Rebuilt `/tools/` page content with bt-section + bt-tile components |
| `includes/class-pages.php` | Rebuilt `/trading-signals/` content with bt-hero + bt-section + 2-col bt-signals-layout |
| `fx-live-markets.php` | Version bump 119.28.30 → 119.28.31 |

## Pre-package validation

- ✅ All 68 PHP files in `includes/`: `php -l` clean
- ✅ Plugin header version: 119.28.31
- ✅ `BT_VERSION` constant: 119.28.31
- ✅ `bt-tokens.css`: 1166 lines (NEW)
- ✅ `typography.css`: 2480 lines (unchanged from v30)
- ✅ Asset enqueue chain: `landing-revamp` → `typography` → `bt-tokens`
- ✅ ZIP: 2.20 MB

## Deploy

1. **Deactivate** plugin → upload `blockticker-io-v119.28.31-full.zip` → **activate**
2. The version bump triggers `bt_pages_need_update`. The hash-drift mechanism from v28 detects content changes in `/tools/` and `/trading-signals/` and rebuilds them automatically
3. **Hard-refresh** + **purge CDN** for:
   - `/wp-content/plugins/blockticker-io/assets/css/bt-tokens.css?ver=119.28.31` (NEW)
   - `/wp-content/plugins/blockticker-io/assets/css/typography.css?ver=119.28.31`
   - All HTML pages

## Verification checklist

- [ ] `/tools/` — 4 calculator tile cards in section 01 (Profit / Currency / Position / Pip), 4 decision-aid tiles in section 02. Hover any tile → translateY animation + accent radial-gradient glow + arrow gap-spreads
- [ ] `/trading-signals/` — at desktop ≥1100px, two-column layout with feed left + sidebar right (Sources Tracked / How to read / Browse archive cards). Below 1100px: stacks
- [ ] `/desk-brief/` — section headers have green numbered pill + Space Grotesk H2 + uppercase tag pill on right; "Levels" grid cells render with tabular numerals and proper bull/bear coloring
- [ ] All footers — column headers in white uppercase 11px, links in `--bt-text-4` gray (`#94a3b8`), hover to white
- [ ] Forex & Commodities tables — clean header bar in elevated bg, hairline row dividers, hover-row highlight
- [ ] Any page that uses `<h1>` `<h2>` `<h3>` — Space Grotesk applied consistently
- [ ] Any page with prices/percentages — tabular-nums + slashed-zero applied (numbers align column-by-column)

## Architectural win

The most important deliverable here isn't any single page redesign — it's that **future visual work no longer requires new CSS**. The next time we want to:
- Add a new dashboard card → use `.bt-card`
- Add a new section → use `.bt-section`
- Add a new metric tile → use `.bt-stat`
- Add a new feature link → use `.bt-tile`
- Add a sentiment label → use `.bt-badge--bull/bear/warn`
- Show an empty state → use `.bt-empty`

Components compose without selector wars. Token tweaks (e.g., changing `--bt-accent` from `#00FF66` to `#22D38F`) propagate everywhere automatically.

## Next backlog (if user wants to continue)

| Priority | Item |
|---|---|
| High | Mobile chrome polish (test at 360px / 414px / 768px viewports) |
| High | A/B test the hero section copy on `/` for conversion |
| Med | Analyst photos / bylines on desk-brief for trust signals |
| Med | Real-time WebSocket price updates on `/crypto-markets/` (currently 90s polling) |
| Med | Cohort analysis page for signal track record (which sources perform best) |
| Low | Dark/light mode toggle (currently dark-only) |
| Low | Sparkline charts on the watchlist tile cards |

## Rollback

```sql
INSERT INTO wp_options (option_name, option_value) VALUES ('bt_disable_site_takeover', '1');
```

`bt-tokens.css` is purely additive — even if takeover is disabled, the file just sits unused (it's scoped to `body.bt-site-takeover` and `body.bt-asset-page`). Page content rebuilds for `/tools/` and `/trading-signals/` are persistent in DB.
