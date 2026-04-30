# BlockTicker v103.0.0 — Final FXLM_ Removal

**Release date:** April 2026
**Type:** Final cleanup — zero FXLM_* identifiers remaining in active code
**Upgrade path:** Drop-in over v102.0.0

---

## Overview

v103.0 removes the last three active `FXLM_*` items from the codebase:

1. **Three constant shims deleted** — `FXLM_VERSION`, `FXLM_DIR`, `FXLM_URL` no
   longer defined anywhere in active code.
2. **`FXLM_BOTTOM_TICKER_RENDERED` render-guard renamed** to `BT_BOTTOM_TICKER_RENDERED`.
3. **Doc comments and error strings** updated across 11 files.

After this upgrade, `grep -rn "FXLM_"` across the entire plugin returns zero
results outside the five intentionally-preserved tombstone files:
`class-compat.php`, `class-migration.php`, `class-deprecation.php`,
`class-v100-upgrade.php`, `uninstall.php`.

---

## Changes

### `fx-live-markets.php`

Removed the three backward-compat constant shims added in v102.0:

```php
// REMOVED:
if ( ! defined( 'FXLM_VERSION' ) ) define( 'FXLM_VERSION', BT_VERSION );
if ( ! defined( 'FXLM_DIR' ) )     define( 'FXLM_DIR',     BT_DIR );
if ( ! defined( 'FXLM_URL' ) )     define( 'FXLM_URL',     BT_URL );
```

Replaced with a single tombstone comment. External code still reading
`FXLM_VERSION` will now get a PHP "undefined constant" notice.

Version → `103.0.0` (both header and `BT_VERSION` constant).

### `includes/class-navbar.php`

`FXLM_BOTTOM_TICKER_RENDERED` → `BT_BOTTOM_TICKER_RENDERED`.

This was a runtime render-guard constant (`define` + `defined` check) used to
prevent the bottom ticker from being output twice per page. The name was a
legacy remnant — no external code is expected to check it.

### `includes/class-compat.php` — §1 simplified

The `BT_*` constant guards no longer fall back to `FXLM_*` values since those
constants no longer exist. The guards now provide a safe fallback value directly:

```php
// Before (v102.0):
if ( ! defined( 'BT_VERSION' ) ) {
    define( 'BT_VERSION', defined( 'FXLM_VERSION' ) ? FXLM_VERSION : '102.0.0' );
}

// After (v103.0):
if ( ! defined( 'BT_VERSION' ) ) {
    define( 'BT_VERSION', '103.0.0' );
}
```

### Doc comments updated (11 files)

File header `@class` doc-comment strings updated from legacy `FXLM_*` names to
current `BT_*` names in: `class-database.php`, `class-widgets.php`,
`class-userauth.php`, `class-portfolio.php`, `class-eeat.php`, `class-i18n.php`,
`class-asset-pages.php`, `class-installer.php`, `class-exchanges.php`,
`class-navbar.php`, and `blockticker-cron.php` (error string).

---

## Breaking changes

| What broke | Fix |
|-----------|-----|
| `FXLM_VERSION` constant | Use `BT_VERSION` |
| `FXLM_DIR` constant | Use `BT_DIR` |
| `FXLM_URL` constant | Use `BT_URL` |
| `FXLM_BOTTOM_TICKER_RENDERED` constant | Use `BT_BOTTOM_TICKER_RENDERED` |

---

## PHP lint

All 44 plugin PHP files clean.

---

## Migration arc — complete

The FXLM_ → BT_ migration that began with v97.0 is fully complete.

| Version | What was done |
|---------|---------------|
| v97.0 | `BT_` alias layer added over all `FXLM_` identifiers |
| v98.0 | `bt_*` option rows seeded; forward-sync hooks |
| v99.0 | 216 internal reads patched; `pre_option` shims installed |
| v100.0 | `fxlm_*` option rows deleted; cron events migrated |
| v101.0 | 28 class files physically renamed `FXLM_` → `BT_` |
| v102.0 | `BT_*` constants made primary; 268 internal refs patched; class aliases removed |
| **v103.0** | `FXLM_*` constant shims removed; render-guard renamed; zero active `FXLM_*` |

**Zero `FXLM_*` identifiers remain in any active code path.** The five preserved
tombstone files (`class-compat.php`, `class-migration.php`, etc.) contain `FXLM_*`
only in comments and in data structures that handle backward compatibility at the
database and option layer — none of it executes as a live identifier.

---

## What's next

The codebase is fully clean. All new development starts from `BT_*` with no
legacy constraints. Suggested priorities:

- **New feature work** — sentiment analysis on news items (flagged in v96.2 schema
  as `sentiment_score` column, never populated), correlation heatmap between
  asset pairs, signal leaderboard
- **v104.0** — Remove the five tombstone files themselves (or consolidate them into
  a single `class-legacy-tombstones.php`) now that their protective role is complete
