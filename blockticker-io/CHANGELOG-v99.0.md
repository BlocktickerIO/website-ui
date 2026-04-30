# BlockTicker v99.0.0 — Deprecation Warnings + Internal Code Cleanup

**Release date:** April 2026
**Type:** Cleanup release — 216 internal patches, one new class, no breaking changes
**Upgrade path:** Drop-in over v98.0.0

---

## Overview

v98.0 migrated all `fxlm_*` option data to `bt_*` rows and installed
forward-sync hooks.  v99.0 completes the internal cleanup: every `get_option`,
`update_option`, `add_option`, and `delete_option` call inside the plugin
itself now uses the canonical `bt_*` key directly.

A new deprecation shim class installs `pre_option_fxlm_*` filters that:
- Return the `bt_*` value transparently (third-party reads never break)
- Emit `_doing_it_wrong()` notices in `WP_DEBUG` mode so external developers
  know which of their code still reads legacy keys

The `fxlm_*` option rows in `wp_options` are **not yet deleted** — that is
the v100.0 upgrade step. The `pre_option` shims installed here guarantee that
even after v100.0 deletes those rows, external reads will still return live
`bt_*` data.

---

## Internal patch: 216 option-key replacements across 26 files

A precision Python script replaced every instance of:

```php
// Before (v98.x and earlier)
get_option( 'fxlm_crypto_data' )
update_option( 'fxlm_news_items', $items )
get_option( 'fxlm_site_name', 'BlockTicker' )
```

with the canonical `bt_*` form:

```php
// After (v99.0+)
get_option( 'bt_crypto_data' )
update_option( 'bt_news_items', $items )
get_option( 'bt_site_name', 'BlockTicker' )
```

The replacement was applied using a regex that only matched option function
call contexts — `get_option(`, `update_option(`, `add_option(`, `delete_option(` —
preventing accidental replacement of shortcode names, AJAX action names, hook
names, or HTML attributes that happen to contain `fxlm_`.

Three files were intentionally excluded from patching:

| File | Reason |
|------|--------|
| `includes/class-migration.php` | `KEY_MAP` contains `fxlm_*` keys as data |
| `includes/class-compat.php` | `bt_get_option()` fallthrough reads `fxlm_*` as last resort |
| `uninstall.php` | Deletes `fxlm_*` rows intentionally |

### Files patched and replacement counts

| File | Replacements |
|------|-------------|
| `includes/class-admin.php` | 57 |
| `includes/class-widgets.php` | 22 |
| `includes/class-aiblog.php` | 19 |
| `fx-live-markets.php` | 19 |
| `includes/class-seo.php` | 15 |
| `includes/class-rss.php` | 9 |
| `blockticker-cron.php` | 9 |
| `includes/class-asset-pages.php` | 6 (+wildcards) |
| `includes/class-autoupdate.php` | 8 |
| `includes/class-tools.php` | 7 |
| `includes/class-exchanges.php` | 6 |
| `includes/class-database.php` | 5 |
| `includes/class-monetize.php` | 4 |
| `includes/class-navbar.php` | 4 |
| `includes/class-api.php` | 3 |
| `includes/class-contact.php` | 3 |
| `includes/class-eeat.php` | 3 |
| `includes/class-gdpr.php` | 3 |
| `includes/class-intelligence-brief.php` | 3 |
| `includes/class-settings.php` | 3 |
| `includes/class-db-admin.php` | 2 |
| `includes/class-pwa.php` | 2 |
| `includes/class-i18n.php` | 1 |
| `includes/class-pages.php` | 1 |
| `includes/class-portfolio.php` | 1 |
| `includes/class-userauth.php` | 1 |

**Post-patch verification:** zero `get_option('fxlm_*')` calls remain outside the
three excluded files. Confirmed via Python regex scan of all 26 PHP files.

**PHP lint:** all 26 patched files pass `php -l` with no syntax errors.

---

## New: `includes/class-deprecation.php`

One new class `BT_Deprecation` (~180 lines).

### `pre_option_fxlm_*` read-through shims

For every key in `BT_Migration::KEY_MAP` (58 keys), installs a
`pre_option_fxlm_{key}` filter at `plugins_loaded`.

**Behaviour when a third-party plugin calls `get_option('fxlm_site_name')`:**

```
pre_option_fxlm_site_name filter fires
  → reads get_option('bt_site_name') → returns live value
  → (if WP_DEBUG) calls BT_Deprecation::maybe_warn()
  → returns bt_site_name value to caller
WordPress never reads the fxlm_site_name row from the DB
```

**After v100.0 deletes the `fxlm_*` rows:** the filter fires first,
reads `bt_*`, and returns the live value. The caller never notices the
row is gone. **This is the v100.0 safety guarantee.**

### `_doing_it_wrong()` notices

Fires at most **once per key per request** when:
- `WP_DEBUG` is `true`, AND
- The call originates from outside the plugin directory

The internal-vs-external check uses `debug_backtrace()` to inspect the call
stack. Frames from `wp-includes/` and this class are excluded. If every
remaining frame belongs to `FXLM_DIR`, the notice is suppressed (internal call
during any transition period). If any frame is from outside the plugin, the
notice fires.

```
PHP Notice: BlockTicker: get_option('fxlm_site_name') is deprecated since
BlockTicker 97.0. Use get_option( 'bt_site_name' ) instead. The legacy key
will be removed in BlockTicker v100.0. See blockticker.io/docs/v99-migration.
Called from /wp-content/themes/my-theme/functions.php on line 47.
```

### v100.0 helpers

`BT_Deprecation::delete_legacy_rows()` — deletes all `fxlm_*` rows from
`wp_options` in one `DELETE … WHERE option_name LIKE 'fxlm_%'` query.
This method is called by the v100.0 upgrade routine.

`BT_Deprecation::count_legacy_rows()` — returns the current count of
`fxlm_*` rows, used by the v100.0 upgrade preview.

### Admin panel

A new **⚠️ Deprecation Notices** panel appears at the top of the right
column in **BlockTicker → 🗄 Database**. It shows:

- Number of guarded keys (58)
- Whether `WP_DEBUG` warnings are active
- Count of keys warned about in this admin request
- Reminder that removal is v100.0

---

## `fx-live-markets.php`

- Version → 99.0.0
- `require_once FXLM_DIR . 'includes/class-deprecation.php'`
- `add_action( 'plugins_loaded', array( 'BT_Deprecation', 'init' ) )`

---

## PHP lint

All files clean — new class and all 26 mass-patched files.

---

## Deprecation timeline — final state

| Version | Change |
|---------|--------|
| v97.0 | `BT_` class/shortcode/hook aliases added |
| v98.0 | `bt_*` option rows seeded; forward-sync hooks installed |
| **v99.0** ← *this release* | All internal reads patched to `bt_*`; `pre_option` shims + deprecation warnings for external `fxlm_*` reads |
| **v100.0** | `fxlm_*` option rows deleted from `wp_options`; `FXLM_` class names emit `@deprecated`; cron hook names retired |

---

## What's next

- **v100.0** — Final cleanup: call `BT_Deprecation::delete_legacy_rows()` on
  upgrade, remove `FXLM_` class aliases from `class-compat.php`, retire the
  `fxlm_` cron hook names. The `pre_option` shims remain for one further
  release cycle before being removed in v101.0.
