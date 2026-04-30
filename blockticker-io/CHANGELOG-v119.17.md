# BlockTicker — v119.17.0

**Released:** 2026-04-25
**Theme:** Following list — assets, news sources, signal authors (🟠 P1 from Phase 2 Personalization — last remaining 🟠 unblocked)

## Summary

Closes the last remaining 🟠 P1 in unblocked Phase 2. Three consecutive
releases now form the personalization story:

- **v119.15** — *what you see* (preset dashboard layouts)
- **v119.16** — *what you investigate* (saved screeners)
- **v119.17** — *what you care about* (followed assets/sources/signals)

The point of "follows" is the integration, not the list. v1 ships **capture +
integration** in one release: a `/following/` management page, a drop-in
`[bt_follow_button]` for any entity card, a `[bt_personalized_news]` feed
that's filtered by follows, and two auto-registered dashboard widgets. Tags
are deferred to v2 — the news/signals data shape doesn't have a mature tag
taxonomy yet, and shipping just (1) capture would have left users with a
list and no payoff.

## What's new

### `/following/` management page

New top-level page registered through `BT_Pages::get_pages_config()` —
auto-creates on first admin load via the standard `bt_pages_need_update` flag
mechanism (same pattern as v119.15/v119.16).

Three tabbed panels:

| Tab | UI | Source |
|---|---|---|
| **Assets** | Card grid (auto-fit columns, icon + name + symbol + state badge) | Top 100 from `bt_crypto_data['coins']` |
| **News sources** | Row list with 30-day item count meta | `bt_news_items` table grouped by source, last 30 days only (avoids dead-feed pollution) |
| **Signal sources** | Row list with recent signal count | `bt_signal_items` option (matches what `[fxlm_signals_feed]` actually surfaces) |

Each follow toggle is AJAX-driven — instant UI flip with cap-reached error
messaging if the user hits a limit. Cap-flash feedback on the count badge
when an action is rejected.

**Caps**: 50 assets, 30 news sources, 30 signal sources. All filterable via
`bt_following_max_per_type`.

### Three follow types stored in one user-meta record

```json
{
  "assets":  ["bitcoin", "ethereum", "..."],
  "sources": ["CoinDesk", "FXStreet", "..."],
  "signals": ["DailyFX", "FXStreet", "..."]
}
```

One `get_user_meta()` per page-render that needs follows; one
`update_user_meta()` per toggle. Trade-off: every flip rewrites all three
arrays, but the full JSON blob stays under 5KB even at max caps, so I/O is
negligible — and the alternative (three separate meta keys) would mean three
reads on every personalised render.

### Drop-in `[bt_follow_button]`

```
[bt_follow_button type="asset" id="bitcoin"]
[bt_follow_button type="source" id="CoinDesk"]
[bt_follow_button type="signal" id="DailyFX" label="Track this analyst"]
```

Self-contained: one shortcode invocation includes both the button HTML and
its delegated click handler (one event listener total regardless of how many
buttons are on the page, gated by `window.__btFollowWired` to prevent
double-wiring if the shortcode renders twice).

Designed for embedding inside existing card templates — drop one into the
hero of `/crypto/{slug}/`, into news cards, into signal items. No script
enqueueing required.

Anonymous clicks open the v119's existing auth modal (via the `[data-bt-open-auth]`
trigger).

### `[bt_personalized_news]` — the actual payoff

```
[bt_personalized_news limit="6"]
```

Renders a news feed filtered to the user's follows:

- **WHERE** clause: `source IN (followed_sources)` ORed with `symbols_mentioned LIKE` chain on followed assets
- Single SQL with prepared statements, no PHP-side filtering
- Falls through to recent news (no filter) when user follows nothing — better
  UX than an empty "you have no follows" upsell. The widget renders a soft
  hint at the top inviting follow setup; the news stays informative.
- New users see useful content immediately; returning users with follows get
  the personalised view; no broken empty state ever.

### Dashboard widget composition (v119.15 pattern)

Class auto-registers TWO widgets via `bt_dashboard_widget_registry` filter:

| Widget id | Title | Width | Login required |
|---|---|---|---|
| `following_summary` | Following | half | No (anon shows upsell) |
| `personalized_news` | For You | half | No |

Drop into any v119.15 dashboard preset config — the `Watchlist Focus` preset
is a natural fit since users with watchlists are the audience most likely to
have follows too.

### Anon parity

Persistence model mirrors v119.15/v119.16:

- Logged-in: user meta `bt_user_following`
- Anonymous: localStorage (client-side only)
- AJAX endpoint returns 401 for anon — same pattern as `bt_dashboard_set_preset`

### Admin overview at `BlockTicker → Following`

Read-only operator screen with four aggregation tables, all queried directly
from `wp_usermeta` with `JSON_DECODE` in PHP, no per-user data displayed:

1. **Adoption** — users with at least one follow, total per type
2. **Most-followed assets** — top 20 with follower count (product signal:
   if `bitcoin` and `ethereum` dominate, the `Markets Focus` preset is
   already serving most users; if niche assets show high follower counts,
   they're candidates for dedicated landing pages)
3. **Most-followed news sources** — top 20 (signal for editorial relationships)
4. **Most-followed signal sources** — top 20 (signal for which analysts
   the audience trusts most — pairs well with the v119.11 performance data)

## Filter API

| Filter | Default | Purpose |
|---|---|---|
| `bt_following_max_per_type` | `{asset: 50, source: 30, signal: 30}` | Change caps (clamped 1-200) |
| `bt_following_default_sources` | `[]` | Seed news sources for the picker (added to discovered list) |
| `bt_following_default_signal_sources` | `[]` | Seed signal sources for the picker |
| `bt_dashboard_widget_registry` | — | Hook used by class-following to register itself |

## Files changed

| File | Change |
|---|---|
| `includes/class-following.php` | NEW — full subsystem (~1,140 lines, 47 KB) |
| `includes/class-pages.php` | Register `/following/` page between `/screeners/` and `/tools/` |
| `fx-live-markets.php` | `require_once` after class-screeners; version → 119.17.0 |
| `assets/css/revamp-v44.css` | Append following CSS (71 new rules, ~330 lines) |
| `docs/ROADMAP.md` | "Following" list ✅; v119.17 added; 8 decision-log entries |
| `CHANGELOG-v119.17.md` | NEW |

## Validation

- `includes/class-following.php` — phply parse FAILED on `??` and `<=>`
  operators (PHP 7+ syntax phply doesn't support). Verified syntactically
  valid by stripping those operators and re-parsing — clean.
- `includes/class-pages.php` — still parses cleanly after `/following/`
  insertion (203,636 chars)
- All four critical bootstrap edits in `fx-live-markets.php` verified
- CSS brace balance: 2915/2915 (71 rules added)
- AJAX endpoints all nonce-protected via `check_ajax_referer( 'bt_following' )`
- `wp_ajax_nopriv_bt_follow_toggle` returns 401 for anon (matches dashboard pattern)
- Cap-reached error returns explanatory 400 with type-specific count, not just "failed"
- All `echo $var` sites in the new class commented as escaped HTML or
  hardcoded entity strings (JS-quote regression: clean)

## Roadmap status — Phase 2 Personalization track substantially closed

```
Phase 2 → Personalization track:
  ✅ Watchlist (basic)                          (pre-v119.x)
  ✅ Portfolio tracker (basic)                  (pre-v119.x)
  ✅ Custom dashboard layouts (preset v1)       (v119.15)
  ✅ Saved screeners                            (v119.16)
  ✅ Following list                             (v119.17)
  📋 🟡 Personalized AI brief                   (only remaining unblocked)
```

All three 🟠 P1 items in Phase 2 closed in three consecutive releases. The
last remaining unblocked Phase 2 item is `🟡 Personalized AI brief based on
watchlist holdings` — and v119.15 + v119.16 + v119.17 give us the full
context per user (preset · screeners · followed assets/sources/signals)
that an AI brief would need.

## Eight-release acquisition→retention chain

```
v119.10  forecast SEO       ── informational traffic in
v119.11  performance        ── verified track record
v119.12  top-N SEO          ── commercial traffic in
v119.13  trust strip        ── credibility above the fold
v119.14  mobile UX          ── retention on the dominant viewport
v119.15  dashboard          ── personalized return surface
v119.16  screeners          ── deeper engagement (investigation tool)
v119.17  following          ── personalised content (engagement memory)
```

## Setup actions Salah may want to take post-deploy

1. **First admin load** — `/following/` page auto-creates. No manual setup.
2. **Add follow buttons to existing asset detail pages** — drop the shortcode
   into the hero block of `/crypto/{slug}/`:
   ```
   [bt_follow_button type="asset" id="bitcoin"]
   ```
   Class `BT_AssetPages` could be edited to inject this dynamically per asset
   slug — single-line addition near the existing alert/watchlist buttons.
3. **Add personalised news to dashboard's Watchlist Focus preset** — single
   filter:
   ```php
   add_filter( 'bt_dashboard_presets', function( $presets ) {
       $presets['watchlist']['widgets'][] = 'personalized_news';
       return $presets;
   });
   ```
4. **Optional**: surface follow buttons on news/signal cards for in-context
   capture — pairs well with the existing `class-rss.php` card template.

## Next-up unblocked items in priority order

1. **🟡 Personalized AI brief based on watchlist holdings** — Phase 2's
   flagship personalization. Now well-positioned: v119.15 + v119.16 + v119.17
   give us preset · screeners · follows per user; the brief reads all four
   contexts and produces a "good morning, here's what matters to you"
   summary. Composes naturally with `BT_IntelligenceBrief`'s existing
   verdict engine.
2. **🟡 Browser push notifications** — completes the alerts triangle; needs
   Composer or vendored Web Push library for VAPID + crypto.
3. **🟡 Mobile search overlay** — natural v119.14 follow-up; full-screen
   modal with debounced asset autocomplete from `bt_crypto_data`.
4. **🟡 Pull-to-refresh on data pages** — v119.14 follow-up.
5. **🟡 Rich snippets audit** — Search Console verification of all schema
   markup shipped across v119.10–v119.13.
6. **🟡 Dashboard v2** — drag-and-drop reorder, per-widget show/hide
   (deferred from v119.15).
7. **🟡 Screener v2** — cron-based "alert me when this screener has new
   results", chain filter, sector grouping (deferred from v119.16).
8. **🟡 Following v2** — tags as a fourth entity type; per-source / per-asset
   notification toggles ("alert me when CoinDesk publishes about Bitcoin").
