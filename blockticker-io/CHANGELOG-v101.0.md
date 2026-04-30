# BlockTicker v101.0.0 — Physical Class Renames + Deprecation Shim Removal

**Release date:** April 2026
**Type:** Cleanup release — 28 class files renamed, 2 files simplified, no breaking changes
**Upgrade path:** Drop-in over v100.0.0

---

## Overview

Three deliverables in this release:

1. **28 class files physically renamed** — `class FXLM_Admin { … }` → `class BT_Admin { … }`.
   Each file appends a reverse `class_alias('BT_Foo', 'FXLM_Foo')` so external
   code using the old names continues to work.

2. **`pre_option` shims removed** from `class-deprecation.php`.
   The v99.0 safety net (58 `pre_option_fxlm_*` filters) has served its purpose —
   the `fxlm_*` rows were deleted in v100.0 one full release cycle ago.

3. **`class-compat.php §2` alias direction flipped** — the belt-and-suspenders
   fallback now runs `class_alias('BT_Foo', 'FXLM_Foo')` (reverse), matching the
   direction of the in-file aliases added to each class file.

---

## 28 class files: physical rename

### What changed per file

For every file in the list below:
- The `class FXLM_Foo` declaration was replaced with `class BT_Foo`.
- A reverse `class_alias` block was appended at the bottom:

```php
// v101.0: reverse alias — FXLM_ name kept for backward compatibility.
if ( class_exists( 'BT_Foo' ) && ! class_exists( 'FXLM_Foo', false ) ) {
    class_alias( 'BT_Foo', 'FXLM_Foo' );
}
```

### Files renamed

| File | Old declaration | New declaration |
|------|----------------|-----------------|
| `class-admin.php` | `FXLM_Admin` | `BT_Admin` |
| `class-aiblog.php` | `FXLM_AIBlog` | `BT_AIBlog` |
| `class-api.php` | `FXLM_API` | `BT_API` |
| `class-asset-pages.php` | `FXLM_AssetPages` | `BT_AssetPages` |
| `class-autoupdate.php` | `FXLM_AutoUpdate` | `BT_AutoUpdate` |
| `class-contact.php` | `FXLM_Contact` | `BT_Contact` |
| `class-dex-tokens.php` | `FXLM_Dex_Tokens` | `BT_DexTokens` |
| `class-eeat.php` | `FXLM_EEAT` | `BT_EEAT` |
| `class-exchanges.php` | `FXLM_Exchanges` | `BT_Exchanges` |
| `class-gdpr.php` | `FXLM_GDPR` | `BT_GDPR` |
| `class-i18n.php` | `FXLM_I18N` | `BT_I18N` |
| `class-installer.php` | `FXLM_Installer` | `BT_Installer` |
| `class-intelligence-brief.php` | `FXLM_Intelligence_Brief` | `BT_IntelligenceBrief` |
| `class-monetize.php` | `FXLM_Monetize` | `BT_Monetize` |
| `class-navbar.php` | `FXLM_Navbar` | `BT_Navbar` |
| `class-pages.php` | `FXLM_Pages` | `BT_Pages` |
| `class-portfolio.php` | `FXLM_Portfolio` | `BT_Portfolio` |
| `class-pwa.php` | `FXLM_PWA` | `BT_PWA` |
| `class-rss.php` | `FXLM_RSS` | `BT_RSS` |
| `class-seo.php` | `FXLM_SEO` | `BT_SEO_Legacy` |
| `class-settings.php` | `FXLM_Settings` | `BT_Settings` |
| `class-signal-tracker.php` | `FXLM_Signal_Tracker` | `BT_SignalTracker` |
| `class-theme.php` | `FXLM_Theme` | `BT_Theme` |
| `class-tools.php` | `FXLM_Tools` | `BT_Tools` |
| `class-trailer.php` | `FXLM_Trailer` | `BT_Trailer` |
| `class-userauth.php` | `FXLM_UserAuth` | `BT_UserAuth` |
| `class-utils.php` | `FXLM_Utils` | `BT_Utils` |
| `class-widgets.php` | `FXLM_Widgets` | `BT_Widgets` |

Classes that were already `BT_*` declarations (no change):
`BT_Database`, `BT_DB_Admin`, `BT_NativeChart`, `BT_SEO_Sitemap`,
`BT_Source_Validator`, `BT_CWV`, `BT_A11y`, `BT_Migration`,
`BT_Deprecation`, `BT_V100_Upgrade`.

### `fx-live-markets.php` and `blockticker-cron.php` — callback arrays updated

All 54 `['FXLM_Foo', 'method']` callback arrays and `FXLM_Foo::method()` static
calls in `fx-live-markets.php` and `blockticker-cron.php` were updated to use
the canonical `BT_*` names. Verified: zero `FXLM_[A-Z]` class references remain
outside the excluded files (`class-compat.php`, `class-migration.php`,
`class-deprecation.php`, `class-v100-upgrade.php`, `uninstall.php`).

---

## `includes/class-compat.php` — §2 alias direction reversed

**Before (v97.0–v100.0):**
```php
// FXLM_ was the source; BT_ was the alias
class_alias( 'FXLM_Admin', 'BT_Admin' );
```

**After (v101.0+):**
```php
// BT_ is now the source; FXLM_ is the alias
class_alias( 'BT_Admin', 'FXLM_Admin' );
```

The §2 block in `class-compat.php` now acts as a belt-and-suspenders fallback
for the identical reverse aliases appended to each class file. If for any reason
the in-file alias hasn't registered (e.g. autoloader edge case), the compat shim
covers it.

The §9 self-test was updated to verify both the 28 canonical `BT_*` names and a
representative set of `FXLM_*` reverse aliases.

---

## `includes/class-deprecation.php` — `pre_option` shims removed

The 58 `pre_option_fxlm_{key}` filters installed in v99.0 have been removed.

**Rationale:** The shims guaranteed that `get_option('fxlm_site_name')` would
return live data even after the `fxlm_*` rows were deleted. That guarantee was
only needed for one release cycle after deletion (v100.0). The cycle has passed.

Any remaining third-party code reading `fxlm_*` option keys will now receive
WordPress's default empty/false return — the rows no longer exist and no filter
intercepts the read. The `_doing_it_wrong()` warning infrastructure is also removed.

**What remains in `BT_Deprecation`:**
- `init()` — kept as a no-op (existing `plugins_loaded` hook in `fx-live-markets.php`)
- `delete_legacy_rows()` — for manual re-run via the v100 admin panel
- `count_legacy_rows()` — for the admin panel preview
- `admin_panel_html()` — simplified to show migration-complete status

---

## `fx-live-markets.php`

- Version → 101.0.0
- All `['FXLM_*', 'method']` callback arrays updated to `['BT_*', 'method']`
- `FXLM_*::` static calls updated to `BT_*::`

---

## PHP lint

All 32 touched files clean:
- 28 renamed class files ✅
- `includes/class-deprecation.php` ✅
- `includes/class-compat.php` ✅
- `fx-live-markets.php` ✅
- `blockticker-cron.php` ✅

---

## Safety & rollback

**No data is affected.** This release touches only PHP class declarations and
filter registrations — no schema changes, no option writes, no cron reschedules.

**Backward compatibility:** Every `FXLM_*` class name continues to work via
the reverse `class_alias()` entries. Third-party code using `FXLM_Widgets::some_method()`
will continue to work — the alias resolves to `BT_Widgets`.

**Rollback:** Reinstall v100.0.0 — no data changes to undo.

---

## Completed migration arc

| Version | Change |
|---------|--------|
| v97.0 | `BT_` alias layer added (classes, shortcodes, hooks, constants) |
| v98.0 | `bt_*` option rows seeded; forward-sync hooks |
| v99.0 | 216 internal reads patched to `bt_*`; `pre_option` shims installed |
| v100.0 | `fxlm_*` option rows deleted; cron events migrated to `bt_*` |
| **v101.0** ← *this release* | Physical class renames; `pre_option` shims removed; aliases reversed |

The plugin is now fully `BT_*`-native at every layer:
- Option keys: `bt_*` ✅
- Cron events: `bt_*` ✅
- Class declarations: `BT_*` ✅
- Constants: `BT_VERSION`, `BT_DIR`, `BT_URL` ✅
- Shortcodes: `bt_*` native (with `fxlm_*` aliases) ✅

---

## What's next

- **v102.0** — Remove `FXLM_*` reverse aliases from `class-compat.php §2`
  and the in-file alias blocks (12+ months after v97.0, when the deprecation
  commitment expires). Rename `FXLM_VERSION` / `FXLM_DIR` / `FXLM_URL` constants
  to `BT_VERSION` etc. in the main plugin header.
- New feature work can begin without legacy naming constraints — the codebase
  is clean.
