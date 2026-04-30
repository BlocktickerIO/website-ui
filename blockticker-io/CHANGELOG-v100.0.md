# BlockTicker v100.0.0 — Final Cleanup

**Release date:** April 2026
**Type:** Breaking change release — completes the FXLM_ → BT_ migration arc
**Upgrade path:** Drop-in over v99.0.0 — automatic upgrade fires on first activation

---

## Overview

v100.0 closes the four-version deprecation arc that began with v97.0. Every
`fxlm_*` identifier that was still active in the runtime has now been retired:

| Layer | v97.0 | v98.0 | v99.0 | **v100.0** |
|-------|-------|-------|-------|-----------|
| Class aliases | Added | — | — | Kept (see §2 below) |
| Option keys | — | bt_* seeded | Internal reads patched | **fxlm_* rows deleted** |
| Cron hook names | bt_* aliases | — | — | **fxlm_* unscheduled; bt_* native** |
| pre_option shims | — | — | Installed | Kept (retire in v101) |

After this upgrade, a `SHOW LIKE 'fxlm_%'` query against `wp_options` will
return zero rows. All eight scheduled WP-Cron events run under `bt_*` names.

---

## New: `includes/class-v100-upgrade.php`

One new class `BT_V100_Upgrade` (~180 lines).

### Upgrade routine (`BT_V100_Upgrade::run()`)

Fires automatically on `plugins_loaded` the first time v100.0 is active. Idempotent
— safe to run multiple times, tracked via `bt_v100_upgrade_done` option.

**Step 1 — Delete `fxlm_*` option rows**

Calls `BT_Deprecation::delete_legacy_rows()`, which issues a single
`DELETE FROM wp_options WHERE option_name LIKE 'fxlm_%'` query.

The `pre_option_fxlm_*` filters installed by `BT_Deprecation` remain active.
Any third-party code that still reads `get_option('fxlm_site_name')` after
this upgrade continues to receive the live `bt_site_name` value — zero breakage.

**Step 2 — Migrate cron events**

For each of the 8 cron events in `CRON_MAP`:

| `fxlm_*` hook (retired) | `bt_*` hook (canonical) | Schedule |
|------------------------|------------------------|----------|
| `fxlm_refresh_prices` | `bt_refresh_prices` | `bt_five_minutes` |
| `fxlm_refresh_news` | `bt_refresh_news` | `bt_hourly` |
| `fxlm_refresh_signals` | `bt_refresh_signals` | `bt_fifteen_minutes` |
| `fxlm_refresh_exchanges` | `bt_refresh_exchanges` | `bt_hourly` |
| `fxlm_refresh_fng` | `bt_refresh_fng` | `bt_hourly` |
| `fxlm_daily_ai_post` | `bt_daily_ai_post` | `daily` |
| `fxlm_ai_posts` | `bt_ai_posts` | `bt_twice_daily` |
| `fxlm_health_check` | `bt_health_check` | `daily` |

For each entry: calls `wp_clear_scheduled_hook( $fxlm_hook )`, then calls
`wp_schedule_event()` for the `bt_*` equivalent if not already scheduled.
The original next-scheduled timestamp is reused so there is no timing gap
in data refresh cycles.

### Admin panel

A **🏁 v100.0 Final Cleanup** panel appears at the top of the right column in
**BlockTicker → 🗄 Database**. It shows:

- Number of `fxlm_*` rows pending deletion (live count)
- A one-click **▶ Run v100 Upgrade Now** button (AJAX, `bt_v100_run_upgrade` action)
- ✅ confirmation message after completion with row and cron counts

The button also accepts re-runs for recovery after a rollback.

---

## Cron event rename — 7 class files patched

All `wp_schedule_event()`, `wp_next_scheduled()`, and `wp_clear_scheduled_hook()`
calls that referenced `fxlm_*` hook or schedule names have been updated to
their `bt_*` equivalents.

| File | Hook/schedule changes |
|------|-----------------------|
| `includes/class-rss.php` | 5 hook names + 4 schedule names |
| `includes/class-widgets.php` | 2 hook names + 2 schedule names |
| `includes/class-tools.php` | 1 hook name + 1 schedule name |
| `includes/class-aiblog.php` | 1 hook name |
| `includes/class-admin.php` | 1 hook name |
| `includes/class-autoupdate.php` | 1 hook name |
| `fx-live-markets.php` | 7 `add_action()` calls + deactivation array |

**PHP lint:** all 7 files clean.

The `fxlm_*` schedule definitions (`fxlm_five_minutes` etc.) remain registered
in `bt_register_cron_schedules()` for one further release cycle to support any
site whose cron table still has a stale `fxlm_*` event from before the upgrade
ran. They will be removed in v101.0.

---

## `includes/class-compat.php` — Sections 3 and 4 retired

| Section | Status |
|---------|--------|
| §1 Constants | ✅ Active |
| §2 Class aliases (29 × `class_alias`) | ✅ Active — external `BT_Foo` usage still works |
| §3 Cron schedule aliases | **🗃 Retired** — replaced with tombstone comment |
| §4 Action hook aliases | **🗃 Retired** — replaced with tombstone comment |
| §5 Shortcode aliases (33) | ✅ Active |
| §6 Option key helpers | ✅ Active |
| §7 Admin notice | ✅ Active (auto-dismisses per-user) |
| §8 Debug log | ✅ Active |
| §9 Self-test | ✅ Active |

**Why §3 and §4 are tombstones, not deletions:**
- §3 (schedule aliases) is redundant — `bt_*` schedules are now registered
  directly in `fx-live-markets.php` via `bt_register_cron_schedules()`.
- §4 (hook aliases) is redundant — all `add_action()` calls in
  `fx-live-markets.php` now target `bt_*` hooks directly. There are no
  `fxlm_*` cron events left to piggyback on.

**Why §2 (class aliases) is kept:**
The underlying class *declarations* are still `class FXLM_Admin { … }`. Removing
the `class_alias('FXLM_Admin', 'BT_Admin')` entries would remove the `BT_Admin`
name — breaking any external code that adopted the v97+ naming. The physical
class renames (`class BT_Admin { … }`) plus reverse aliases for `FXLM_Admin`
are planned for a future minor version.

---

## `fx-live-markets.php`

- Version → 100.0.0
- `require_once` for `class-v100-upgrade.php` added
- `add_action('plugins_loaded', ['BT_V100_Upgrade', 'init'])` added
- All 7 `add_action('fxlm_refresh_*', …)` calls replaced with `bt_*`

---

## `uninstall.php` — Section 13 added

New section clears all `bt_*` WP-Cron events on plugin deletion:
`bt_refresh_prices`, `bt_refresh_news`, `bt_refresh_signals`,
`bt_refresh_exchanges`, `bt_refresh_fng`, `bt_daily_ai_post`,
`bt_ai_posts`, `bt_health_check`, `bt_regenerate_sitemap`,
`bt_purge_old_data`, `bt_validate_sources`, `bt_verdict_snapshot`.

Also deletes `bt_v100_upgrade_done` option.

---

## PHP lint

All touched files clean:
- `includes/class-v100-upgrade.php` ✅ (new)
- `includes/class-compat.php` ✅
- `includes/class-db-admin.php` ✅
- `uninstall.php` ✅
- `fx-live-markets.php` ✅
- `includes/class-rss.php` ✅
- `includes/class-widgets.php` ✅
- `includes/class-tools.php` ✅
- `includes/class-aiblog.php` ✅
- `includes/class-admin.php` ✅
- `includes/class-autoupdate.php` ✅

---

## Safety & rollback

**No data is lost on rollback.** The `fxlm_*` rows are deleted but the
`bt_*` rows that replaced them are untouched. If you need to roll back to
v99.0, restore the `fxlm_*` rows by running the v98.0 migration again
(Force Re-migrate button in BlockTicker → 🗄 Database).

The upgrade routine is tracked by `bt_v100_upgrade_done`. Reinstalling
v99.0 over this release will not re-delete rows — the v99 code simply
doesn't call `BT_V100_Upgrade`.

---

## Deprecation arc — complete

| Version | Change |
|---------|--------|
| v97.0 | `BT_` class / shortcode / hook / constant aliases added |
| v98.0 | `bt_*` option keys seeded; forward-sync hooks installed |
| v99.0 | All 216 internal reads patched to `bt_*`; `pre_option` shims installed |
| **v100.0** ← *this release* | `fxlm_*` option rows deleted; cron events migrated to `bt_*`; §3 and §4 of class-compat.php retired |

---

## What's next

- **v101.0** — Remove `pre_option` shims from `class-deprecation.php` (the
  final safety net, kept for one further release cycle as promised).
  Remove `fxlm_*` schedule definitions from `bt_register_cron_schedules()`.
  Begin physical class file renames (`class BT_Admin` etc.) with reverse
  `FXLM_Admin` aliases for backward compatibility.
