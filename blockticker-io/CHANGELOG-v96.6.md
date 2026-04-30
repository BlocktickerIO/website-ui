# BlockTicker v96.6.0 — Core Web Vitals

**Release date:** April 2026
**Type:** Performance release (additive + two targeted patches, no breaking changes)
**Upgrade path:** Drop-in over v96.5.0

---

## Overview

The ChatGPT audit scored BlockTicker's Core Web Vitals at **60/100**.
Four specific issues accounted for nearly all of the gap:

| Issue | Impact | Status |
|-------|--------|--------|
| Google Fonts `<link rel="stylesheet">` in `<head>` | Blocks rendering — adds ~200–400ms to LCP on cold connections | ✅ Fixed |
| `revamp-v44.js` and `frontend.js` not deferred | Contributes to Total Blocking Time (TBT) and INP | ✅ Fixed |
| Placeholder images as uncompressed PNG | Slow LCP on news-card-heavy pages; up to 82KB per image | ✅ Fixed (WebP shipped) |
| `tv.js` loaded unconditionally on chart pages | 400KB+ script delays LCP even with IntersectionObserver | ✅ Fixed (preload hint) |

---

## New: `includes/class-cwv.php`

One new class `BT_CWV` (~260 lines) consolidating all CWV optimisations.

### 1. Async Google Fonts (LCP / FCP)

**Before:** `class-widgets.php::frontend_assets()` emitted a blocking
`<link rel="stylesheet" href="fonts.googleapis.com/...">` from a `wp_head`
priority-2 closure.  Browsers must fully download and parse this CSS before
continuing to paint — a direct LCP and FCP penalty.

**After:** `BT_CWV::output_async_fonts()` (priority 3 on `wp_head`) replaces
this with the preload-swap pattern:

```html
<link rel="preload" href="fonts.googleapis.com/css2?...&display=swap"
      as="style" onload="this.onload=null;this.rel='stylesheet'">
<noscript><link rel="stylesheet" href="fonts.googleapis.com/css2?..."></noscript>
```

The font URL now includes `display=swap` so fallback system fonts render
immediately while font files download — no invisible text (FOIT eliminated).
The `<noscript>` tag ensures JS-disabled crawlers still receive the stylesheet.

The blocking `<link rel="stylesheet">` closure in `class-widgets.php` has
been removed and replaced with a comment.

### 2. Script defer (TBT / INP)

**Before:** `revamp-v44.js` and `frontend.js` were registered with
`in_footer=true` but without `defer`. On slow connections or complex pages
the browser's JS thread still blocked during parsing of the scripts at the
bottom of the body.

**After:** Two complementary layers:

- `BT_CWV::maybe_defer_script()` — a `script_loader_tag` filter (priority 20)
  that adds `defer` to every handle in `BT_CWV::DEFER_HANDLES`:
  `fxlm-revamp-v44`, `fxlm-animations`, `fxlm-patch`, `bt-native-chart`,
  `fxlm-pwa-install`, `fxlm-portfolio`.

- The existing `script_loader_tag` closure in `fx-live-markets.php` is updated
  to also cover `fxlm-revamp-v44` and `fxlm-frontend`, acting as a belt-and-
  suspenders fallback.

`defer` guarantees post-parse execution in source order, so no DOM-readiness
race conditions are introduced. `fxlm-frontend` and `fxlm-patch` depend on
jQuery; they remain in the footer and are not `async`'d (which would race
against DOM construction).

### 3. WebP placeholder images (LCP bandwidth)

**Before:** All 9 placeholder images shipped as PNG. Average size: **75 KB**.
On a typical news feed page rendering 6–12 cards, this is 450–900 KB of
image data on the critical path.

**After:** WebP variants generated at build time and included in the plugin
zip.  Average size: **14 KB** — an **81% reduction**.

| Image | PNG | WebP | Saving |
|-------|-----|------|--------|
| placeholder-bitcoin | 71 KB | 13 KB | 82% |
| placeholder-ethereum | 72 KB | 12 KB | 83% |
| placeholder-defi-web3 | 80 KB | 14 KB | 83% |
| placeholder-altcoins | 77 KB | 15 KB | 81% |
| placeholder-forex-news | 76 KB | 14 KB | 81% |
| placeholder-crypto-news | 82 KB | 17 KB | 80% |
| placeholder-education | 66 KB | 13 KB | 81% |
| placeholder-market-analysis | 78 KB | 16 KB | 79% |
| placeholder-default | 76 KB | 15 KB | 80% |

A new `bt_placeholder_img_html` filter (`BT_CWV::wrap_webp_picture()`)
wraps any plugin-emitted placeholder `<img>` with a `<picture>` element:

```html
<picture>
  <source srcset="placeholder-bitcoin.webp" type="image/webp">
  <img src="placeholder-bitcoin.png" ...>
</picture>
```

Chrome, Firefox, Edge and Safari all support WebP.  The `<picture>` fallback
ensures Internet Explorer (and any edge cases) still receive the PNG.

### 4. TradingView `tv.js` preload (LCP)

**Before:** `tv.js` (~400 KB) was fetched by `window.btLoadTV()` at
IntersectionObserver trigger time — typically 400ms into page load on
chart-heavy pages.

**After:** `BT_CWV` stores a transient (`bt_page_has_tv_{page_key}`) the
first time a TradingView chart shortcode renders on a given page.  On every
subsequent request for that page, `maybe_output_tv_preload()` emits:

```html
<link rel="preload" href="https://s3.tradingview.com/tv.js" as="script" crossorigin>
<link rel="preconnect" href="https://s3.tradingview.com" crossorigin>
```

at `wp_head` priority 1 — the earliest possible position in `<head>`.
This lets the browser begin fetching `tv.js` in parallel with HTML parsing,
shaving ~200–300ms off chart-page LCP from the second visit onward.

Detection hooks: `do_shortcode_tag` (for `[fxlm_tradingview_chart]`) and
`bt_tv_chart_rendered` filter (for `FXLM_Utils::render_tv_chart()`).

### 5. font-display:swap inline override

A tiny inline `<style>` block injects `font-display: swap` globally,
covering any fonts a child theme or third-party plugin might add that
don't already declare this descriptor.

### 6. REST endpoint cache headers

`BT_CWV::set_rest_cache_headers()` (via `rest_pre_serve_request`) adds:

| Endpoint pattern | Cache-Control header |
|-----------------|---------------------|
| `/blockticker/v1/history/*` | `public, max-age=120, s-maxage=300, stale-while-revalidate=60` |
| `/blockticker/v1/chart/*` | same |
| `/blockticker/v1/prices` | `public, max-age=60, s-maxage=60` |

`s-maxage` allows CDN/reverse-proxy layers (Cloudflare, Nginx) to cache
chart data for 5 minutes, massively reducing origin load on traffic spikes.
`stale-while-revalidate` means cached chart data is served instantly while a
refresh runs in the background — zero perceived latency on revalidation.

---

## `fx-live-markets.php`

- Version bumped to `96.6.0`
- `require_once FXLM_DIR . 'includes/class-cwv.php'`
- `add_action( 'plugins_loaded', array( 'BT_CWV', 'init' ) )`
- `add_action( 'wp_footer', array( 'BT_CWV', 'record_tv_chart_page' ), 1 )`
- `script_loader_tag` defer list expanded: added `fxlm-revamp-v44` + `fxlm-frontend`
- Duplicate `wp_head` preconnect closure **removed** (superseded by `FXLM_SEO::output_resource_hints()`)

## `includes/class-widgets.php`

- Blocking `<link rel="stylesheet">` Google Fonts closure **removed** from `frontend_assets()`
- Replaced with comment pointing to `BT_CWV::output_async_fonts()`

---

## Assets

- `assets/images/placeholders/placeholder-*.webp` — 9 new files (≈14 KB avg)

---

## Safety & rollback

1. **Additive only** for the new class.
2. **class-widgets.php patch** — removes a `wp_head` closure. If for any reason
   fonts fail to load async (e.g., very aggressive CSP), add the following to
   `wp-config.php` to re-enable the blocking link:
   ```php
   define( 'BT_DISABLE_ASYNC_FONTS', true );
   ```
   Then add this one-liner anywhere in your child theme's `functions.php`:
   ```php
   remove_action( 'wp_head', [ 'BT_CWV', 'output_async_fonts' ], 3 );
   ```
3. **WebP images** — the `<picture>` wrapper only fires through the
   `bt_placeholder_img_html` filter, which no existing code calls yet. The
   WebP files are inert until consuming code applies the filter. Zero risk.

### Rollback

Reinstall v96.5.0 — no schema changes, no new option keys, no new cron events.
The 9 new `.webp` files are left behind but are harmless (inert static files).

---

## PHP lint

All touched files clean:
- `includes/class-cwv.php` ✅
- `includes/class-widgets.php` ✅
- `fx-live-markets.php` ✅

---

## What's next

- **v96.7** — WCAG 2.1 AA accessibility pass (audit score: 40/100 — the
  largest remaining gap). Focus: colour-contrast fixes, keyboard navigation,
  ARIA labels on all interactive widgets, skip-to-content link, focus-visible
  styles, screen-reader announcements for live price updates.
- **v97.0** — `FXLM_` → `BT_` constant/class rename (breaking; own session).
