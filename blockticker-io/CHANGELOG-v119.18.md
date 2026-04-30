# BlockTicker — v119.18.0 (hotfix)

**Released:** 2026-04-25
**Theme:** Hotfix — admin submenu URLs broken across v119.11 → v119.17

## Summary

Hotfix release. Every admin submenu added since v119.11 was generating broken
URLs — clicking **Performance**, **Trust Strip**, **Mobile UX**, **Dashboards**,
**Screeners**, or **Following** in `wp-admin → BlockTicker` resolved to
`/wp-admin/{slug}` (which the front-end served as a 404) instead of
`/wp-admin/admin.php?page={slug}`.

Reported by Salah, root-caused, and fixed in one turn. No new features;
six one-line changes plus a version bump.

## Root cause

`BT_Admin::add_menu()` registers the `fxlm-wizard` top-level menu via
`add_action('plugins_loaded', 'BT_Admin::init')` — `init()` then registers
its own `admin_menu` listener.

Meanwhile, the six classes shipped v119.11 → v119.17 each called `setup()`
at file-include time, registering their `admin_menu` listeners **directly
and immediately** — before `plugins_loaded` had even fired.

At default priority 10, WordPress fires callbacks in **insertion order**.
So when `admin_menu` ran:

1. The six new classes' `register_admin_menu` callbacks fired first
   (registered earliest)
2. `BT_Admin::add_menu` fired last

This meant every `add_submenu_page('fxlm-wizard', …)` call ran when
`$admin_page_hooks['fxlm-wizard']` didn't exist yet. WordPress's
`get_plugin_page_hookname()` then fell back to the generic `admin_page_*`
prefix instead of the correct `toplevel_page_*`:

```
expected:  toplevel_page_bt-performance
actual:    admin_page_bt-performance
```

Later, when WordPress builds the submenu link in `wp-admin/menu-header.php`,
it does `get_plugin_page_hook('bt-performance', 'fxlm-wizard')` — which
looks for `_registered_pages['fxlm-wizard_page_bt-performance']`. That
lookup misses (we registered under `admin_page_*`), so WordPress falls
through to its last-resort branch:

```php
$sub_item_url = admin_url( $sub_item[2] );
// → /wp-admin/bt-performance
```

…producing a literal admin path that doesn't route to anything, which the
front-end theme catches and renders as a 404.

## Fix

Single-line priority bump on six `add_action` calls:

```diff
-add_action( 'admin_menu', array( __CLASS__, 'register_admin_menu' ) );
+add_action( 'admin_menu', array( __CLASS__, 'register_admin_menu' ), 20 );
```

Priority 20 puts these callbacks safely after `BT_Admin::add_menu`
(priority 10), so by the time `add_submenu_page` runs the parent menu
has been registered and `get_plugin_page_hookname()` correctly returns
`toplevel_page_bt-{slug}`.

## Why priority 20 instead of 11

11 would also work today, but priority 20 leaves headroom for any future
class-admin.php internals that might land between 10 and 20 (e.g. if
class-admin.php someday splits its menu registration across multiple
priorities for ordering reasons). 20 is conventional for "I want to run
clearly after default-priority registrations" without being so high that
something else inevitably wedges itself between.

## Files changed

| File | Change |
|---|---|
| `includes/class-performance.php` | `admin_menu` hook priority 10 → 20 |
| `includes/class-trust-strip.php` | `admin_menu` hook priority 10 → 20 |
| `includes/class-mobile-ux.php` | `admin_menu` hook priority 10 → 20 |
| `includes/class-dashboard.php` | `admin_menu` hook priority 10 → 20 |
| `includes/class-screeners.php` | `admin_menu` hook priority 10 → 20 |
| `includes/class-following.php` | `admin_menu` hook priority 10 → 20 |
| `fx-live-markets.php` | Version → 119.18.0 (header + `BT_VERSION`) |
| `docs/ROADMAP.md` | Decision-log entry recording the bug + future guidance (priority ≥ 20 for admin submenu registrations) |
| `CHANGELOG-v119.18.md` | NEW |

No CSS, no shortcodes, no schema changes, no new files.

## Validation

- All 6 patched class files parsed clean via phply (PHP-7+-stripped probe)
- `fx-live-markets.php` version constants verified
- All 6 priority bumps verified via grep — every `register_admin_menu`
  registration now runs at priority 20

## Verifying after deploy

After installing v119.18.0:

1. Navigate to `wp-admin → BlockTicker`
2. Click any of: **Performance**, **Trust Strip**, **Mobile UX**,
   **Dashboards**, **Screeners**, **Following**
3. URL should be `/wp-admin/admin.php?page=bt-{slug}` (admin shell loads)
4. Page should render the admin overview UI for that subsystem

If the URL still shows `/wp-admin/bt-{slug}` after the upgrade, hard-refresh
the admin (Cmd+Shift+R) — WordPress caches the menu structure within a
session and a fresh page load is needed to pick up the new priority.

## Note on future submenu additions

Any future admin submenu registrations against the `fxlm-wizard` parent
should use priority 20 (or higher) on their `admin_menu` action to avoid
this same bug. The decision-log entry in ROADMAP records this as standing
guidance.

## Roadmap

No roadmap status changes — this is purely a hotfix. v119.17's "Following
list ✅" remains the most recent feature shipped; the eight-release
acquisition→retention chain is unchanged.

## Next-up unblocked items (unchanged from v119.17)

1. **🟡 Personalized AI brief based on watchlist holdings**
2. **🟡 Browser push notifications**
3. **🟡 Mobile search overlay**
4. **🟡 Pull-to-refresh on data pages**
5. **🟡 Rich snippets audit**
6. **🟡 Dashboard v2** (drag-drop)
7. **🟡 Screener v2** (cron alerts on screener results)
8. **🟡 Following v2** (tags, per-source notification toggles)
