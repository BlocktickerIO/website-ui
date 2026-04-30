# BlockTicker v105.0.0 — Signal Source Leaderboard

**Release date:** April 2026
**Type:** Feature release (additive, no breaking changes)
**Upgrade path:** Drop-in over v104.0.0

---

## Overview

A new `[bt_signal_leaderboard]` shortcode ranks every signal provider by
verified win rate — entirely from data already in `bt_signal_tracker` and
`bt_signal_items` options, with zero API calls and zero new database tables.

---

## New: `BT_SignalTracker::get_leaderboard()` and `sc_signal_leaderboard()`

Two new public methods appended to `includes/class-signal-tracker.php`.

### How it works

1. Reads `bt_signal_tracker` — every tracked signal with its resolved outcome
   (`win` / `loss` / `flat`) and `final_pct` move.
2. Reads `bt_signal_items` — the live signal feed items, which carry `source`
   and `title`.
3. Cross-references by `md5($title)` — the same hash `sc_signals_feed` passes
   to the tracker badge — to assign a source name to every resolved outcome.
4. Groups by source and computes: wins, losses, flats, win rate, avg absolute
   move, best verified call.
5. Sorts by win rate (descending); sources below `min_signals` threshold are
   shown at the bottom without a rank badge.

**Win rate definition:** `wins / (wins + losses)` — flat outcomes (move < 0.3%)
are excluded from the denominator, matching how sports-style accuracy is
conventionally computed.

### Shortcode: `[bt_signal_leaderboard]`

```
[bt_signal_leaderboard min_signals="3" window_days="90" max_rows="10" show_best="1"]
```

**Attributes:**

| Attribute | Default | Description |
|-----------|---------|-------------|
| `min_signals` | 3 | Min. resolved signals (win+loss) to earn a rank badge |
| `window_days` | 90 | Look-back window in days; `0` = all-time |
| `max_rows` | 10 | Maximum rows shown |
| `show_best` | 1 | Show "Best Call" column (symbol, direction, move %) |
| `title` | "Signal Source Leaderboard" | Card heading; set to empty to hide |

**Rendered columns:**

| Column | Description |
|--------|-------------|
| # | Rank (🥇🥈🥉 for top 3, `—` for unqualified sources) |
| Source | Provider name from RSS feed metadata |
| Win Rate | `W/(W+L)` with colour-coded bar (green ≥60%, amber ≥45%, red <45%) |
| W / L | Raw win/loss counts, flat count in parentheses if any |
| Avg Move | Average absolute % price change across all resolved signals |
| Best Call | Symbol + direction emoji + best win % for the source |

**Transparency note** displayed below the table:
> "Win rate counts LONG signals that moved up ≥0.3% and SHORT signals that moved
> down ≥0.3% within 48 hours. Flat outcomes excluded from rate. No
> cherry-picking — every tracked signal is included."

### Design

Pure CSS — no images, no external dependencies. The win rate bar uses a CSS
custom property (`--pct`) for the fill width, so it renders correctly in both
dark and light themes. The table is horizontally scrollable on narrow viewports.

---

## `includes/class-signal-tracker.php`

- `get_leaderboard( $min_signals, $window_days )` added — returns sorted array
- `sc_signal_leaderboard( $atts )` added — renders ranked table with inline CSS
- `setup()` patched — `add_shortcode( 'bt_signal_leaderboard', … )` registered

---

## `fx-live-markets.php`

- Version → 105.0.0 (header + `BT_VERSION`)

---

## PHP lint

- `includes/class-signal-tracker.php` ✅
- `fx-live-markets.php` ✅

---

## Usage

Place `[bt_signal_leaderboard]` on any page — the Signals, Trading Signals,
or About page are natural homes. No configuration required beyond having the
signal tracker cron active (running since v70).

The leaderboard auto-updates as the hourly `bt_signal_tracker_tick` cron
resolves new outcomes — no manual refresh needed.

---

## What's next

- **v106.0** — Correlation Heatmap: `[bt_correlation_heatmap]` showing price
  correlation between crypto/forex pairs from `wp_bt_price_history`, rendered
  as a colour-coded matrix using the native chart library.
