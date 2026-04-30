# BlockTicker — v119.14.0

**Released:** 2026-04-25
**Theme:** Mobile UX layer (🟠 P1 from Phase 1 UX & Visual Polish)

## Summary

Reframes the long-pending "Mobile UX audit" backlog item as a concrete, shippable
subsystem rather than an open-ended investigation. A coordinated mobile-only UI
layer activates at viewports under 768px and adds the four pieces that have the
most universal impact: sticky bottom nav, asset-page CTA bar, back-to-top FAB,
and WCAG 2.1 AAA touch-target enforcement.

The pieces are built as a single class because they share infrastructure
(viewport detection, scroll observers, z-index coordination, the `.bt-mux-on`
flag on `<html>`) — splitting them across files would duplicate the wiring and
make it harder to keep ordering sane.

This release closes the conversion funnel started by v119.10–v119.13:

- **v119.10 / v119.12** — SEO content brought visitors in
- **v119.13** — trust strip built credibility above the fold
- **v119.14** — mobile UX ensures those visitors don't bounce on phone-sized screens

Mobile is the dominant traffic source for crypto/forex content; without this
layer, the upstream work was leaking.

## What's new

### 1. Sticky bottom navigation

Four-item bottom bar — **Markets · Watchlist · Alerts · More** — fixed at the
foot of the viewport on every page. Sits within thumb reach (the bottom third
of a phone screen, where one-handed taps land naturally), supplementing the
existing top navbar rather than replacing it.

**Hide-on-scroll-down behaviour** — when the user is reading content (scrolling
down), the nav slides off so the content gets the full viewport. Scrolling up,
or being within 100px of the top, brings it back. Implemented with
`requestAnimationFrame` throttling and an 8px scroll-delta threshold so tiny
movements don't flicker the bar.

**Active-state highlighting** is server-side — the active nav item is detected
by matching the request URL path against item hrefs and gets a top accent bar
plus accent-coloured icon. No JS flash on page load.

**`:has()` selector** auto-adjusts the grid for any nav-item count between 3
and 6 — `bt_mobile_nav_items` filter can return any number of entries and the
layout balances itself.

### 2. Sticky CTA bar on asset pages

On `/crypto/{slug}/` and `/forex/{slug}/`, a second sticky bar sits **above**
the bottom nav with the asset's primary actions: **Set Alert** (highlighted in
accent green — the page's primary CTA), **Watchlist**, and **Analysis** (crypto
only — links to `/analysis/{slug}/`).

Context detection mirrors `BT_AssetPages::intercept_asset_url()` — REQUEST_URI
regex parsing — so it works regardless of rewrite-rule flush state.

**Loose-coupled to existing alert/watchlist subsystems.** Buttons dispatch
`CustomEvent('bt:open-alert-modal')` and `CustomEvent('bt:toggle-watchlist')`
with click-fallback to in-page `.bt-set-alert-btn` / `.bt-watchlist-toggle`
buttons. If neither exists, falls back to navigating to `/alerts/?symbol=…`.
Deleting `BT_Alerts` doesn't break the CTA — it just degrades to navigation.

CTA stays visible on scroll-down (it's the page's primary action — the bar
should be findable, not hidden); the bottom nav still hides for content
priority.

### 3. Back-to-top FAB

Auto-shows after 800px of scroll (filterable). 44×44 button at bottom-right
with smooth-scroll behaviour where supported, hard-jump fallback otherwise.
Z-index ordering automatically nudges it up when the asset CTA bar is also
visible (uses `:has()` and adjacent-sibling selectors so no JS coordination
needed).

### 4. Touch target audit (WCAG 2.1 AAA)

Site-wide CSS layer ensuring all `.bt-mobile-nav a`, `.bt-mobile-cta-btn`, and
`.bt-mobile-fab` elements meet a 44×44px minimum tap area on viewports under
768px. Filterable to 36–60px via `bt_mobile_touch_target_size`. Implemented as
`min-height` / `min-width` rather than padding so visual layout is preserved
while the hit area is enforced.

## Coordination with existing subsystems

**PWA install prompt** (`BT_PWA::output_pwa_client_script`) sits at
`position: fixed; bottom: 16px`, which would collide with the bottom nav. A
`MutationObserver` watches the prompt's visibility and toggles a
`.bt-pwa-prompt-above-nav` class that pushes it above the nav stack —
implemented entirely in the mobile-UX client script rather than by editing
`class-pwa.php`, so the subsystem stays self-contained.

**Body padding** auto-adjusted via `:has(.bt-mobile-cta)` — pages with the CTA
bar get extra `padding-bottom` so the last bit of content isn't hidden behind
the sticky elements. Reduced-motion users get the layer with all transitions
disabled.

**Top navbar** (`BT_Navbar`) is untouched — bottom nav supplements it for
thumb-reach actions; top nav still handles full menu, search, and branding.

## Filter API

All configuration via WordPress filters (Salah is the developer; filters are
testable and version-controllable, no admin-form sanitization burden):

| Filter | Purpose | Default |
|---|---|---|
| `bt_mobile_ux_enable` | Master kill-switch | `true` |
| `bt_mobile_nav_items` | Replace nav item list entirely | 4-item default |
| `bt_mobile_nav_show_on_admin` | Show nav on `wp-admin` | `false` |
| `bt_mobile_show_sticky_cta_on` | Per-page CTA suppression (receives `ctx` array) | `true` |
| `bt_mobile_back_to_top_threshold` | Pixels before FAB shows | `800` |
| `bt_mobile_touch_target_size` | Min touch target (clamped 36–60) | `44` |

The admin page at `BlockTicker → Mobile UX` is **read-only visibility** into
the active configuration — shows current nav items, threshold values, and
filter snippets to copy-paste.

## Files changed

| File | Change |
|---|---|
| `includes/class-mobile-ux.php` | NEW — full subsystem (~700 lines, 28 KB) |
| `fx-live-markets.php` | `require_once` after class-trust-strip; version → 119.14.0 |
| `assets/css/revamp-v44.css` | Append mobile-UX CSS module (38 new rules, ~200 lines) |
| `docs/ROADMAP.md` | Mobile UX audit ✅; v119.14 added to Phase 1 done list; 7 new decision-log entries |
| `CHANGELOG-v119.14.md` | NEW |

## Validation

- `includes/class-mobile-ux.php` parsed clean via phply
- All four critical bootstrap edits in `fx-live-markets.php` verified
- CSS brace balance: 2720/2720 (38 rules added)
- All `echo $var` sites in the new class commented as either escaped HTML or
  hardcoded entity strings (JS-quote-in-PHP-string regression risk: clean)
- Subsystem self-disables on admin, login, AJAX, REST, and 404 — verified by
  conditional in `should_render()`
- Subsystem entirely hidden on desktop — `display: none` defaults overridden
  only when client script applies `.bt-mux-on` to `<html>` (server-side
  `wp_is_mobile()` is a hint only; client-side viewport check is authoritative)

## Roadmap status

**Phase 1 — UX & Visual Polish track** is now substantially closed:
v119.4 (pagination, eyebrows) → v119.5 (LIVE badge, perf) → v119.6 (admin
cleanup, DB redesign) → v119.7 (TradingView fix) → v119.8 (news dedup) →
v119.9 (alerts) → v119.10 (forecasts) → v119.11 (performance) →
v119.12 (top-N) → v119.13 (trust strip) → v119.14 (mobile UX).

**Phase 1 — Trust & E-E-A-T track**: 6 of 7 items done; the remaining item is
FINRA/SEC review of US-targeted content — legal work, not engineering.

The remaining 🔴 P0 in Phase 1 is **plugin folder structure cleanup** — still
explicitly deferred per the decision log because it needs phased migration
rather than a single-session refactor.

## Next-up unblocked items

🟠 **Custom dashboard layouts** — Phase 2 personalization; could ship as
preset-only v1 (Markets Focus / Watchlist Focus / Signals Focus switcher) to
avoid drag-drop complexity, with full drag-drop deferred to v2

🟡 **Browser push notifications** — completes the alerts triangle; needs
Composer or vendored Web Push library to handle VAPID + ECDSA P-256 + AES-GCM
without rolling crypto by hand

🟡 **Rich snippets audit** — Search Console verification of all schema markup
shipped across v119.10–v119.13 (Article · ItemList · FAQPage · Dataset ·
Organization · AggregateRating)

🟡 **Site-wide search overlay (mobile)** — natural follow-up to v119.14;
full-screen search modal with debounced autocomplete from existing crypto data
(deferred from v119.14 scope as standalone feature)

🟡 **Pull-to-refresh on data pages** — `/crypto-markets/`, `/watchlist/`,
`/alerts/` — also deferred from v119.14 (limited universal benefit, single-page
scope per page)
