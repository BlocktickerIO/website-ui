# BlockTicker — v119.11.0

**Released:** 2026-04-25
**Theme:** Trust & E-E-A-T — public signal performance dashboard (🟠 P1 from Phase 1 of roadmap)

## Summary

Closes the highest-impact unblocked Phase 1 🟠 item from the v119.8 deep-research
audit: a public, audited track-record page for the trading signals BlockTicker
publishes. Without verified accuracy numbers, the AI-assisted alerts (v119.9) and
daily forecasts (v119.10) are dismissable as "just AI guesses." This release ships
the credibility anchor that those features need.

The new `/performance/` page reads the same `bt_signal_tracker` option that was
already capturing direction-aligned outcomes 48 hours after each signal — so the
dashboard goes live with whatever history the site has accumulated, no migration
or backfill required. Pure aggregation, no AI, no editorial choices.

## What's new

### URL routing

- **`/performance/`** — full track-record dashboard, registered through the same
  `class-pages.php` page-registry mechanism used for `/alerts/` and `/forecast/`.

### Public shortcodes

#### `[bt_performance_dashboard]`

The dashboard itself. Six sections, top to bottom:

1. **Hero stats** — Verified Accuracy (highlighted in accent green), Total
   Signals, Avg. Move, Tracking Window. Includes "X wins / Y resolved" subtext
   so the denominator is visible, not implied.

2. **30-day daily-accuracy sparkline** — inline SVG (no JS dep). Each day
   renders a volume bar (height proportional to resolved signal count, capped)
   with a polyline overlay showing daily win-rate. Days with zero resolved
   signals are gaps — not "0%" — to avoid misleading the eye. A dashed 50%
   reference line gives the reader a baseline.

3. **Methodology box** — four numbered cards explaining capture-on-publish,
   48-hour fixed window, 0.3% direction-aligned win threshold, and "every
   result published, no removal." This section is **prominent** by design,
   not buried in a tooltip — the trust play depends on the rules being
   self-evident before the reader sees the numbers.

4. **Per-asset breakdown** — top 12 assets by signal volume with W/L/Flat
   counts, win-rate (color-coded high ≥60% / mid 45-60% / low <45%), avg
   absolute move, and best call. Min. 2 signals to qualify (sub-min entries
   would be statistically noise).

5. **Recent verified signals** — last 25 resolved entries with verified-at
   date, symbol, direction emoji, signed move %, and outcome pill. Loss/win
   rows get a subtle left-edge tint (green/red gradient) for at-a-glance
   scanning without being garish.

6. **Source leaderboard** — embeds the existing
   `[bt_signal_leaderboard min_signals="3" window_days="90" max_rows="8"]`
   so the per-source view stays consistent with what's already published
   in widgets elsewhere.

A bright-yellow disclaimer footer (`Not investment advice. Past performance
does not guarantee future results.`) closes the page. Required compliance
copy on a page like this — not optional.

#### `[bt_performance_summary_card]`

Compact 4-tile homepage embed. Shows accuracy / total / avg move / window
with a CTA button → `/performance/`. Built for the homepage trust strip
without taking dashboard-sized real estate. Returns a "warming up" empty
state if no signals have resolved yet.

### Schema.org markup

Adds `Dataset` JSON-LD to the dashboard (not just `Article`), declaring the
track record as a verifiable factual dataset with `variableMeasured` properties
for accuracy, total signals, and average movement. This is a stronger E-E-A-T
signal to Google than a generic article tag — it tells search engines this is
auditable performance data, not opinion content. Creator points to the site's
Organization, license is CC-BY-ND 4.0.

### Performance & caching

- Dashboard HTML is cached as a 5-minute WordPress transient (`bt_perf_dashboard_html_v1`).
- Cache auto-busts whenever `update_option_bt_signal_tracker` fires, so newly
  resolved outcomes appear within the cron tick — never staler than the cache.
- Pure aggregation over the existing option; no extra DB calls, no new tables,
  no new cron jobs.

### Admin overview

New submenu **BlockTicker → Performance** with:
- Four operator stat cards (accuracy, total signals, avg move, window)
- Per-asset table with full counts (no min-volume filter — admins see everything)
- Last 10 verified signals with raw direction/move/outcome
- "Recompute outcomes & clear cache" button — invokes `BT_Signal_Tracker::cron_signal_tracker_tick()`
  synchronously, then busts the dashboard transient. Useful for triage when an
  outcome looks off.
- Public-URL link for one-click preview.

### Aggregation API (for other classes)

Three new public static methods on `BT_Performance` that other code can call
without rendering anything:

- `get_aggregate_stats()` — returns `[total, wins, losses, flats, resolved, accuracy, avg_move, days, first_ts, last_ts]`
- `get_resolved_signals( $limit, $symbol )` — filtered + sorted DESC by verification time
- `get_per_asset_breakdown( $min_signals )` — per-symbol rollup
- `get_daily_accuracy_series( $days )` — bucketed daily series for sparkline rendering

Useful if a future widget wants to embed live performance numbers somewhere
new without re-implementing the rollups.

## Files changed

| File                                  | Change                                          |
|---------------------------------------|-------------------------------------------------|
| `includes/class-performance.php`      | NEW — full subsystem (~755 lines)               |
| `fx-live-markets.php`                 | `require_once` + version → 119.11.0             |
| `includes/class-pages.php`            | Register `/performance/` page                   |
| `assets/css/revamp-v44.css`           | Append performance CSS module (~250 lines)      |
| `docs/ROADMAP.md`                     | Performance audit ✅; v119.11 decisions logged   |
| `CHANGELOG-v119.11.md`                | NEW                                             |

## Validation

- 56 PHP files all `php -l` clean
- CSS braces 2526/2526 balanced (was 2435 before this release)
- JS-quote-in-PHP-string regression scan clean (the v119.2 fatal pattern can't recur)
- Single `blockticker-io/` root folder, no nested wrapper

## Decision log highlights (full entries in ROADMAP.md)

- **`bt_signal_tracker` over `bt_signal_track`** — the two systems are
  independent. We need the direction-aligned tracker (with `final_pct` and
  `outcome ∈ {win,loss,flat}`) — not the older hit/miss/pending counter.
- **Methodology box as a top-level section, not a tooltip** — the trust play
  depends on the rules being unmissable.
- **Schema.org `Dataset`, not just `Article`** — gives Google a stronger E-E-A-T
  signal that this is verifiable factual data.
- **Flats excluded from accuracy denominator, but shown in tables** — including
  ±0.3% noise in the rate would let market chop dilute the numbers; counting
  flats honestly (visible, but separate) is the transparent choice.
- **5-min transient cache, auto-bust on tracker update** — the page renders
  five tables; aggregation is cheap but DOM build isn't free, and we want
  recently-resolved outcomes visible immediately.

## Roadmap status after v119.11

**Phase 1 — Foundation:**
- ✅ Alerts subsystem (v119.9)
- ✅ SEO forecast pipeline (v119.10)
- ✅ Performance audit page (v119.11)
- 📋 🟠 Mobile UX audit (next-up Phase 1)
- 📋 🟠 Trustpilot widget
- 📋 🟠 Reduce homepage info density
- 📋 🔴 Plugin folder structure cleanup (still deferred — phased migration)

**Phase 2 — Content & Growth:**
- ✅ Price alerts · ✅ News alerts · ✅ Email delivery · ✅ Daily forecast pages
- 📋 🟡 Browser push notifications (PWA)
- 📋 🟠 Custom dashboard layouts
- 📋 🟠 Top-N landing pages

Three of four 🔴 P0 audit items are now closed. The remaining 🔴 (folder cleanup)
is explicitly deferred per the decision log — it needs a phased migration, not a
single-session refactor. The next-up unblocked items are the homepage trust strip
(Trustpilot widget — would naturally pair with the new `[bt_performance_summary_card]`),
mobile UX audit, or Top-N landing pages to extend the SEO momentum from v119.10.
