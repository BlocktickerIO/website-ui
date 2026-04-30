# BlockTicker — v119.16.0

**Released:** 2026-04-25
**Theme:** Saved screeners — filter combinations over the live market data (🟠 P1 from Phase 2 Personalization)

## Summary

Continues the Phase 2 personalization track that v119.15 opened. Where v119.15
gave repeat visitors a personalized starting view, v119.16 gives them a saved
investigation tool — a `/screeners/` page where they build filter combinations,
save them with names, and re-run them anytime. Same audience as v119.15
(returning users), deeper engagement (active investigation rather than passive
consumption).

Pure aggregation play — reads the existing `bt_crypto_data` option (top-500 by
mcap, refreshed by the existing cron) and CoinGecko-category transients
populated by `[fxlm_crypto_category]`. No new API calls, no new tables, no AI.

The class auto-registers as a v119.15 dashboard widget via the
`bt_dashboard_widget_registry` filter — Salah gets `screeners_summary` in the
widget catalog without touching dashboard code.

## What's new

### `/screeners/` page + `[bt_screeners]` shortcode

New top-level page registered via `BT_Pages::get_pages_config()`. Auto-creates
on first admin load after upgrade through the standard `bt_pages_need_update`
flag mechanism — same pattern as v119.13/v119.15.

Three sections, top to bottom:

1. **Saved list** — your screeners with one-line criteria summary, click to
   load, × to delete (max 10, mirrors watchlist cap)
2. **Builder** — 6 filter fields:
   - **Sort field** (mcap, rank, price, 24h %, 7d %, volume, name)
   - **Direction** (asc / desc)
   - **Category** (10 options — see below)
   - **Mcap range** (USD min / max, either bound optional)
   - **24h % range** (min / max, either bound optional)
   - **Volume min** (USD)
   - **Limit** (5 / 10 / 20 / 30 / 50)
   plus four actions: **Run · Save · Copy share link · Reset**
3. **Results** — ranked table (Rank · Asset · Price · 24h % · 7d % · Mcap ·
   Volume) with mobile-collapsed columns, color-coded changes,
   `aria-live="polite"` announcements on AJAX updates

### Ten categories

| Category | Source |
|---|---|
| `all` | `bt_crypto_data['coins']` (top-500) |
| `altcoins` | top-500 minus BTC + 15 stablecoins |
| `large-cap` | rank 1-100 |
| `mid-cap` | rank 101-300 |
| `small-cap` | rank 301-500 |
| `defi` · `stablecoins` · `gaming` · `metaverse` · `nft` | CoinGecko category transients (`fxlm_cat_{cat}_v3`) populated by `[fxlm_crypto_category]` |

CoinGecko-category screeners reuse the existing per-category transient cache —
same pattern as v119.12 top-N pages. Cold cache falls through to the general
universe rather than returning empty (better UX than "no results" when
warming up).

Filterable via `bt_screener_categories` to add custom slugs.

### Persistence

Mirrors the v119.15 dashboard pattern:

| User type | Storage | Persists across |
|---|---|---|
| Logged in | User meta `bt_user_screeners` (JSON, max 10) | All devices, all sessions |
| Anonymous | `localStorage` | Same browser only |
| Shared link | None — base64 in URL | Single visit, recipient can save |

**Why base64-encoded share links rather than per-share DB rows.** Stateless
sharing is one of the design points: no DB write per share, no
garbage-collection problem, no orphaned rows when the URL is forgotten. The
canonical criteria JSON is ~150 bytes; base64 brings it to ~200 bytes; URLs
stay under 250 chars even with realistic filter combinations. Trade-off: no
share analytics, which is fine — that's icebox-tier.

### Auto-composition with the v119.15 dashboard

Class `setup()` adds a callback to `bt_dashboard_widget_registry` that
registers `screeners_summary` as a half-width widget. Salah can drop it into
any preset config (or anon clients see the upsell variant). The dashboard's
v119.15 safe-fail rendering layer handles the case where this class is
filtered out — the card frame stays intact, the empty-state shows.

`[bt_screeners_summary_card]` has three variants:
- **Anonymous** — soft upsell with "Try the screener →" CTA
- **Logged-in, no saves** — encouraging blurb with same CTA
- **Logged-in, with saves** — shows top 3 saved screeners with one-line
  criteria summary, each click-through to `/screeners/?run={id}`

### AJAX endpoints (logged-in only)

| Endpoint | Behaviour |
|---|---|
| `wp_ajax_bt_screener_save` | Create new screener (returns id) — enforces 10-cap, 60-char name limit |
| `wp_ajax_bt_screener_delete` | Delete by id |
| `wp_ajax_bt_screener_run` | Returns rendered table HTML for builder preview (lets the builder show results without page reload) |

All three nonce-protected via `bt_screener` nonce action. Anon clients use
`localStorage` and never hit these endpoints.

### Admin overview at `BlockTicker → Screeners`

Read-only operator screen with three aggregation tables — all queried directly
from `wp_usermeta` with `JSON_DECODE` in PHP, no per-user data displayed:

1. **Adoption** — users with saves, total screeners, avg per user, cap
2. **Top categories used** — which segments users actually investigate
3. **Top sort fields** — what they care about ranking on

Useful for product decisions: if `mid-cap × 7d %` is a popular pattern, a
preset hint or a `/top/` extension might be worth shipping.

### Filter API

| Filter | Default | Purpose |
|---|---|---|
| `bt_screener_categories` | 10 entries | Add custom category slugs |
| `bt_screener_max_per_user` | 10 | Change save cap (clamped 1-50) |
| `bt_screener_default_criteria` | mcap-desc, no ranges, top 20 | Change defaults for new screeners |
| `bt_dashboard_widget_registry` | — | Hook used by class-screeners to register itself |

## Files changed

| File | Change |
|---|---|
| `includes/class-screeners.php` | NEW — full subsystem (~1,150 lines, 47 KB) |
| `includes/class-pages.php` | Register `/screeners/` page between `/dashboard/` and `/tools/` |
| `fx-live-markets.php` | `require_once` after class-dashboard; version → 119.16.0 |
| `assets/css/revamp-v44.css` | Append screeners CSS (83 new rules, ~370 lines) |
| `docs/ROADMAP.md` | Saved screeners ✅; v119.16 added; 8 decision-log entries |
| `CHANGELOG-v119.16.md` | NEW |

## Validation

- `includes/class-screeners.php` — phply parse FAILED on `??` and `<=>`
  operators (both PHP 7+ syntax phply doesn't support; production runs PHP 8+
  and supports them). Verified syntactically valid by stripping those operators
  and re-parsing — clean. Brace/paren balance: 0/0 on the original file.
- `includes/class-pages.php` — still parses cleanly after `/screeners/`
  insertion (202,655 chars)
- All four critical bootstrap edits in `fx-live-markets.php` verified
- CSS brace balance: 2844/2844 (83 rules added)
- All `echo $var` sites in the new class are commented as either escaped
  HTML or trusted inline-rendered fragments (JS-quote regression: clean)
- AJAX endpoints all nonce-protected via `check_ajax_referer( 'bt_screener' )`
- Save endpoint enforces user-cap, name-length, and `is_user_logged_in()` —
  returns 401 for anon, 400 with explanatory error for cap exceeded

## Roadmap status

**Phase 2 → Personalization track**:
- ✅ Watchlist (basic)
- ✅ Portfolio tracker (basic)
- ✅ Custom dashboard layouts (preset v1) (v119.15)
- ✅ Saved screeners (v119.16)
- 📋 🟠 "Following" list — assets, sources, tags
- 📋 🟡 Personalized AI brief based on watchlist holdings

Two of three remaining 🟠 P1s in unblocked Phase 2 are now closed in
consecutive releases. The "Following" list is the natural next move.

## Acquisition→retention chain — seven releases

```
v119.10  forecast SEO       ── informational traffic in
v119.11  performance        ── verified track record
v119.12  top-N SEO          ── commercial traffic in
v119.13  trust strip        ── credibility above the fold
v119.14  mobile UX          ── retention on the dominant viewport
v119.15  dashboard          ── personalized return surface
v119.16  screeners          ── deeper engagement for returning users
```

## Setup — no manual steps required

First admin load after upgrade auto-creates the `/screeners/` page via the
standard page-update flow. The dashboard widget appears automatically in any
preset that lists `screeners_summary`. To wire it into the default Markets
Focus preset, drop one filter:

```php
add_filter( 'bt_dashboard_presets', function( $presets ) {
    $presets['markets']['widgets'][] = 'screeners_summary';
    return $presets;
});
```

## Next-up unblocked items in priority order

1. **🟠 "Following" list** — last remaining 🟠 P1 in Phase 2; track favourite
   assets, news sources, signal sources for content filtering. Three entity
   types but each with simpler UI than screeners
2. **🟡 Browser push notifications** — completes alerts triangle; needs
   Composer or vendored Web Push library for VAPID + crypto
3. **🟡 Mobile search overlay** — natural v119.14 follow-up; full-screen
   modal with debounced asset autocomplete from `bt_crypto_data`
4. **🟡 Personalized AI brief** — v119.15 + v119.16 give us watchlist + screener
   context per user; an AI brief that reads those would be Phase 2's flagship
   personalization feature
5. **🟡 Pull-to-refresh on data pages** — v119.14 follow-up
6. **🟡 Rich snippets audit** — Search Console verification across schema
   shipped v119.10–v119.13
7. **🟡 Dashboard v2** — drag-and-drop reorder, per-widget show/hide, custom
   user presets (deferred from v119.15 scope)
8. **🟡 Screener v2** — cron-based "alert me when this screener has new
   results" + chain filter + sector-level grouping
