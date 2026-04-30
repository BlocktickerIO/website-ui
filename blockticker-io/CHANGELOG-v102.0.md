# BlockTicker v102.0.0 — Constant Renames + Final Alias Removal

**Release date:** April 2026
**Type:** Breaking change release — FXLM_* class aliases and constant aliases removed
**Upgrade path:** Drop-in over v101.0.0

---

## Overview

This is the final breaking change in the FXLM_ → BT_ migration arc that began
with v97.0. Three things happen in this release:

1. **`BT_VERSION` / `BT_DIR` / `BT_URL` become the primary plugin constants.**
   `FXLM_VERSION`, `FXLM_DIR`, `FXLM_URL` are kept as one-release shims (removed in v103).

2. **All 268 internal `FXLM_*` constant and class references patched** across 35 files.
   Every `FXLM_DIR`, `FXLM_VERSION`, `FXLM_URL`, and `FXLM_Foo::method()` call
   inside the plugin itself now uses canonical `BT_*` names.

3. **All 28 in-file reverse alias blocks removed.** The `class_alias('BT_Foo', 'FXLM_Foo')`
   blocks appended in v101.0 are gone. External code using `FXLM_*` class names
   will now receive a PHP fatal error — the 12-month grace period is over.

---

## `fx-live-markets.php` — constants flipped

**Before (v97.0–v101.0):**
```php
define( 'FXLM_VERSION', '101.0.0' );   // primary
define( 'FXLM_DIR', plugin_dir_path( __FILE__ ) );
define( 'FXLM_URL', plugin_dir_url( __FILE__ ) );
// BT_* defined later in class-compat.php as aliases of FXLM_*
```

**After (v102.0+):**
```php
define( 'BT_VERSION', '102.0.0' );     // primary
define( 'BT_DIR', plugin_dir_path( __FILE__ ) );
define( 'BT_URL', plugin_dir_url( __FILE__ ) );
// FXLM_* kept as backward-compat shims for one release (removed in v103.0)
if ( ! defined( 'FXLM_VERSION' ) ) define( 'FXLM_VERSION', BT_VERSION );
if ( ! defined( 'FXLM_DIR' ) )     define( 'FXLM_DIR',     BT_DIR );
if ( ! defined( 'FXLM_URL' ) )     define( 'FXLM_URL',     BT_URL );
```

---

## 35 files patched — constants + inter-class calls

A precision Python script replaced all occurrences of `FXLM_DIR`, `FXLM_VERSION`,
`FXLM_URL`, and every `FXLM_Foo::` static call in all plugin PHP files (excluding
the intentionally-preserved files: `class-compat.php`, `class-migration.php`,
`class-deprecation.php`, `class-v100-upgrade.php`, `uninstall.php`).

| Category | Refs patched |
|----------|-------------|
| `FXLM_DIR` → `BT_DIR` | 60 |
| `FXLM_VERSION` → `BT_VERSION` | 29 |
| `FXLM_URL` → `BT_URL` | 23 |
| `FXLM_Foo::` inter-class calls | 156 |
| **Total** | **268** |

### Files patched

`fx-live-markets.php`, `blockticker-cron.php`, and 33 `includes/class-*.php` files
(every class file except the 5 excluded above).

**Post-patch verification:** `grep -rn "FXLM_DIR\|FXLM_VERSION\|FXLM_URL"` returns
zero results outside the 5 excluded files.

---

## 28 in-file reverse alias blocks removed

The `class_alias('BT_Foo', 'FXLM_Foo')` blocks appended to each class file in
v101.0 have been removed. These were the last mechanism keeping `FXLM_*` class
names alive.

After this upgrade, calling `new FXLM_Admin()` or `FXLM_Widgets::method()` will
trigger a PHP fatal error: `Class "FXLM_Admin" not found`.

---

## `includes/class-compat.php`

### §1 — Constants updated

The belt-and-suspenders constant guards now fall back correctly regardless of
load order:

```php
if ( ! defined( 'BT_VERSION' ) ) {
    define( 'BT_VERSION', defined( 'FXLM_VERSION' ) ? FXLM_VERSION : '102.0.0' );
}
```

### §2 — Class aliases section retired

The §2 block (29 reverse `class_alias` entries) has been replaced with a
tombstone comment. `FXLM_*` class names are now fully unsupported.

### §9 — Self-test simplified

The admin-init self-test now verifies only the 28 canonical `BT_*` class names.
The `FXLM_*` alias checks added in v101.0 have been removed.

---

## PHP lint

All 44 plugin PHP files clean — every class file, the main plugin file, cron
runner, diagnostic file, and uninstall script.

---

## Breaking changes

| What broke | Who is affected | Fix |
|-----------|----------------|-----|
| `FXLM_Admin`, `FXLM_Widgets` etc. class names | Third-party plugins / child themes that still use old class names | Use `BT_Admin`, `BT_Widgets` etc. — see [migration guide](https://blockticker.io/docs/v97-migration) |
| `FXLM_DIR`, `FXLM_VERSION`, `FXLM_URL` constants | External code reading these constants | Still defined as shims in v102.0 — **removed in v103.0** |
| `fxlm_*` option keys | Code reading via `get_option('fxlm_*')` | Rows deleted in v100.0; use `get_option('bt_*')` |

---

## Safety & rollback

No database changes. Rollback = reinstall v101.0.

The `FXLM_*` constant shims in `fx-live-markets.php` provide a one-release
buffer for external code that reads `FXLM_VERSION` or uses `FXLM_DIR` to build
asset paths. Those shims are removed in v103.0.

---

## Completed migration arc — all layers done

| Layer | v97 | v98 | v99 | v100 | v101 | **v102** |
|-------|-----|-----|-----|------|------|---------|
| Option keys | alias | migrated | patched | deleted | — | — |
| Cron events | alias | — | — | migrated | — | — |
| Class declarations | alias | — | — | — | renamed | aliases removed |
| Constants | alias | — | — | — | — | **BT_* primary** |
| Shortcodes | alias | — | — | — | — | — |

**Every identifier in BlockTicker is now canonical `BT_*`.** The only remaining
legacy items are the `FXLM_*` constant shims (removed in v103) and the `fxlm_*`
shortcode aliases in `class-compat.php §5` (kept indefinitely — shortcode names
embedded in page content are not safe to break).

---

## What's next

- **v103.0** — Remove `FXLM_VERSION` / `FXLM_DIR` / `FXLM_URL` constant shims
  from `fx-live-markets.php`. Remove the §1 fallback guards from `class-compat.php`.
  The codebase will then have zero FXLM_ identifiers outside of shortcode aliases
  and the migration/deprecation tombstone files.
- **New feature work** — the codebase is fully clean; all new classes, hooks,
  options and shortcodes use `BT_*` from day one.
