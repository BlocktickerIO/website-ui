# BlockTicker — v119.15.0

**Released:** 2026-04-25
**Theme:** Personalized dashboard (🟠 P1 from Phase 2 Personalization — only remaining 🟠 unblocked)

## Summary

Closes the only remaining 🟠 P1 in the unblocked backlog: a configurable
single-page dashboard that composes existing widgets into three preset layouts
targeting distinct user intents. This is the third leg of the
acquisition→retention chain built across v119.10–v119.14:

- **v119.10 / v119.12** — SEO content brings visitors in
- **v119.13** — trust strip wins first-visit credibility
- **v119.14** — mobile UX retains them on phone
- **v119.15** — personalized dashboard pulls them back for repeat visits

**Scope discipline.** The roadmap line said "drag-and-drop widgets." Drag-drop
is high-risk in one session — pointer/touch handling, persistence, conflict
resolution, and meaningful undo all need to work or the feature is worse than
nothing. v1 ships **preset-based** with drag-drop reorder and per-widget
show/hide explicitly deferred to v2. The widget registry and persistence
layer underneath are designed so v2 can add those controls without restructuring.

## What's new

### `/dashboard/` page + `[bt_dashboard]` shortcode

A new top-level personalized page registered through the existing
`BT_Pages::get_pages_config()` mechanism — auto-creates on first admin load
after upgrade via the standard `bt_pages_need_update` flag (no manual setup).

The `/dashboard/` page is `noindex, nofollow` — personalized content shouldn't
be in SERPs because the same URL serves different layouts to different users,
and there's no single canonical version for Google to index. Marketing
surfaces (homepage, `/forecast/`, `/top/`, `/performance/`) remain fully
indexable.

### Three preset layouts

| Preset | Default for | Widgets |
|---|---|---|
| **Markets Focus** | New / general visitors (default) | Intelligence Brief · Market Pulse · Top Movers · Top 10 by Mcap · Crypto News · Forex News |
| **Watchlist Focus** | Logged-in users with saved watchlist | Watchlist · Price Alerts · Portfolio · Performance Summary · Fear & Greed · Crypto News |
| **Signals Focus** | Active traders | Signals Feed · Performance Summary · Signal Leaderboard · Intelligence Brief · Forex Verdict · Sentiment Bar |

Each preset is a pure composition of existing shortcodes — no new visual
components. Adding a new preset is a single array addition to
`get_presets()` (or via the `bt_dashboard_presets` filter for site-specific
extensions). Existing widgets retain all their behaviour automatically; the
dashboard stays in lockstep without coordinated updates.

### Widget registry (15 widgets)

Each entry: title, icon, shortcode, atts, width (`full` / `half` / `third`),
optional `require_login`. The registry is filterable via
`bt_dashboard_widget_registry` so site-specific code can add custom widgets
without touching core.

Login-gated widgets (Watchlist, Price Alerts, Portfolio) render a sign-in
upsell card for anonymous users instead of breaking — the card maintains the
same width slot in the grid so layout doesn't shift on auth.

### Persistence model

| User type | Storage | Persists across |
|---|---|---|
| Logged in | User meta (`bt_dashboard_preset`) | All devices, all sessions |
| Anonymous | `localStorage` (`bt_dashboard_preset`) | Same browser only |
| URL param `?preset=` | None — transient | Single request (useful for shared links) |

**No flash-of-wrong-preset.** Anonymous users with a saved preset get a soft
URL redirect to `?preset=…` on initial load — server renders the right
layout from the start rather than rendering the default and swapping
client-side after 100-300ms. The redirect is one extra request, but it's
cacheable HTML; the alternative is visibly worse UX.

### AJAX preset persistence

| Endpoint | Behaviour |
|---|---|
| `wp_ajax_bt_dashboard_set_preset` | Logged-in: saves to user meta, returns success |
| `wp_ajax_nopriv_bt_dashboard_set_preset` | Anon: returns 401 (anon clients use localStorage; this exists for clean error response if a client reaches it accidentally) |

Both nonce-protected via `bt_dashboard` nonce action. Frontend dispatches
fire-and-forget POSTs and navigates immediately — the network request is
cosmetic since the navigation handles the visible state change.

### Safe-fail rendering

`render_shortcode_safe()` returns an empty string when a shortcode tag isn't
registered, rather than letting WP leave the literal `[shortcode]` marker on
the page. If a widget references a shortcode whose class has been filtered
out, the dashboard shows the "Data warming up" empty-state inside the card
frame — no raw markup leaks to the browser.

### Admin overview at `BlockTicker → Dashboards`

Three-table read-only operator screen:

1. **Active presets** — per-preset widget list, user-count distribution
   (queried directly from `wp_usermeta`), preview link with `?preset=…` param
2. **Widget registry** — all 15 widgets with shortcode-registered status
   indicator (red ❌ next to widgets whose shortcode is missing, helpful
   for debugging filter conflicts)
3. **Cache & maintenance** — clear-cache button + filter reference

## Files changed

| File | Change |
|---|---|
| `includes/class-dashboard.php` | NEW — full subsystem (~870 lines, 34 KB) |
| `includes/class-pages.php` | Register `/dashboard/` page between `/performance/` and `/tools/` |
| `fx-live-markets.php` | `require_once` after class-mobile-ux; version → 119.15.0 |
| `assets/css/revamp-v44.css` | Append dashboard CSS (41 new rules, ~190 lines) |
| `docs/ROADMAP.md` | Custom dashboard layouts ✅; v119.15 added; 8 decision-log entries |
| `CHANGELOG-v119.15.md` | NEW |

## Filter API

| Filter | Purpose |
|---|---|
| `bt_dashboard_presets` | Replace the entire preset registry |
| `bt_dashboard_widget_registry` | Replace the widget catalog |
| `bt_dashboard_default_preset` | Change the default preset for new visitors |

## Validation

- `includes/class-dashboard.php` parsed clean via phply (34,489 chars)
- `includes/class-pages.php` still parses after page registration insertion
  (201,707 chars)
- All four critical bootstrap edits in `fx-live-markets.php` verified
  (header version, `BT_VERSION`, require_once, mobile-ux still present)
- CSS brace balance: 2761/2761 (41 rules added)
- All `echo $var` sites in the new class are commented as either escaped
  HTML, registry-sourced entity strings, or trusted shortcode pipeline
  output (JS-quote-in-PHP-string regression risk: clean)
- AJAX endpoint nonce-protected via `check_ajax_referer( 'bt_dashboard' )`
- `noindex, nofollow` meta verified to fire only on `/dashboard/` (path
  match in `output_dashboard_meta()`)

## Roadmap status

**Phase 1 → UX & Visual Polish track is now substantially closed.** Twelve
consecutive UX/feature releases v119.4 → v119.15 have closed every 🟠 in the
track. The last open Phase 1 item is the 🔴 plugin folder structure cleanup,
still explicitly deferred per the decision log (needs phased migration, not
single-session refactor).

**Phase 2 → Personalization track**:
- ✅ Watchlist (basic)
- ✅ Portfolio tracker (basic)
- ✅ Custom dashboard layouts (preset v1) (v119.15)
- 📋 🟠 Saved screeners (filter combinations)
- 📋 🟠 "Following" list — assets, sources, tags
- 📋 🟡 Personalized AI brief based on watchlist holdings

## Acquisition→retention chain — six releases, all shipped

```
v119.10  forecast SEO       ── informational traffic in
v119.11  performance        ── verified track record
v119.12  top-N SEO          ── commercial traffic in
v119.13  trust strip        ── credibility above the fold
v119.14  mobile UX          ── retention on the dominant viewport
v119.15  dashboard          ── personalized return surface
```

## Setup actions Salah needs to take post-deploy

1. **First admin load** — `bt_pages_need_update` flag auto-flips on version
   change, `BT_Pages::create_all()` runs, `/dashboard/` page is created
   automatically. No manual setup.
2. **Optional**: add Dashboard to mobile bottom nav by replacing the v119.14
   filter:
   ```php
   add_filter( 'bt_mobile_nav_items', function( $items ) {
       array_splice( $items, 1, 1, array( array(
           'id'    => 'dashboard',
           'label' => 'Dashboard',
           'href'  => home_url( '/dashboard/' ),
           'icon'  => '🏠',
       )));
       return $items;
   });
   ```
3. **Optional**: add to top navbar via the existing `BT_Navbar` menu config.

## Next-up unblocked items in priority order

1. **🟠 Saved screeners** — Phase 2 personalization continuation; filter
   combinations on `/crypto-markets/` saved per-user (parallel pattern to
   v119.15's user-meta + localStorage)
2. **🟠 "Following" list** — Phase 2 personalization; track favourite assets,
   news sources, signal sources for content filtering
3. **🟡 Browser push notifications** — completes alerts triangle; needs
   Composer or vendored Web Push library
4. **🟡 Mobile search overlay** — natural v119.14 follow-up; full-screen
   modal with debounced asset autocomplete
5. **🟡 Pull-to-refresh on data pages** — v119.14 follow-up; per-page scope
6. **🟡 Rich snippets audit** — Search Console verification of all schema
   markup shipped across v119.10–v119.13 (Article · ItemList · FAQPage ·
   Dataset · Organization · AggregateRating)
7. **🟡 Dashboard v2** — drag-and-drop reorder, per-widget show/hide, custom
   user presets (deferred from v119.15 scope)
