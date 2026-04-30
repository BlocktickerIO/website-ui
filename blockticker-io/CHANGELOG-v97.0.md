# BlockTicker v97.0.0 — BT_ Namespace Unification

**Release date:** April 2026
**Type:** Compatibility release — additive aliases, zero breaking changes
**Upgrade path:** Drop-in over v96.7.0

---

## Overview

Every public identifier in BlockTicker has historically carried the `FXLM_`
prefix (a legacy artefact from the plugin's original name, "FX Live Markets").
v97.0 begins the structured migration to the canonical `BT_` prefix.

**This release is strictly additive.** Every `FXLM_` class, hook, shortcode
and constant continues to work exactly as before.  No existing site, child
theme, or third-party plugin requires any change to keep working.

---

## Deprecation roadmap

| Version | Change |
|---------|--------|
| **v97.0** (this release) | `BT_` aliases added alongside all `FXLM_` identifiers |
| **v98.0** | Option keys in `wp_options` migrated from `fxlm_*` to `bt_*`; `fxlm_*` keys kept as read-through aliases |
| **v99.0** | `FXLM_` constants and class names marked `@deprecated` in docblocks; PHP `_doing_it_wrong()` calls added |
| **v100.0** | `FXLM_` identifiers removed (minimum 12 months after v97.0) |

---

## New: `includes/class-compat.php`

Loaded last in `fx-live-markets.php` (after all class files are required).

### 1. Constants

```php
define( 'BT_VERSION', FXLM_VERSION );
define( 'BT_DIR',     FXLM_DIR );
define( 'BT_URL',     FXLM_URL );
```

All three are conditional (`if ! defined`) so child plugins that declare
them first are not overwritten.

### 2. Class aliases (29 classes)

`class_alias()` creates a live reference — both names point to the exact
same class object; there is no code duplication or performance overhead.

| Old name | New name |
|----------|----------|
| `FXLM_Admin` | `BT_Admin` |
| `FXLM_AIBlog` | `BT_AIBlog` |
| `FXLM_API` | `BT_API` |
| `FXLM_AssetPages` | `BT_AssetPages` |
| `FXLM_AutoUpdate` | `BT_AutoUpdate` |
| `FXLM_Contact` | `BT_Contact` |
| `FXLM_Dex_Tokens` | `BT_DexTokens` |
| `FXLM_EEAT` | `BT_EEAT` |
| `FXLM_Exchanges` | `BT_Exchanges` |
| `FXLM_GDPR` | `BT_GDPR` |
| `FXLM_I18N` | `BT_I18N` |
| `FXLM_Installer` | `BT_Installer` |
| `FXLM_Intelligence_Brief` | `BT_IntelligenceBrief` |
| `FXLM_Monetize` | `BT_Monetize` |
| `FXLM_Navbar` | `BT_Navbar` |
| `FXLM_Pages` | `BT_Pages` |
| `FXLM_Portfolio` | `BT_Portfolio` |
| `FXLM_PWA` | `BT_PWA` |
| `FXLM_RSS` | `BT_RSS` |
| `FXLM_SEO` | `BT_SEO_Legacy` |
| `FXLM_Settings` | `BT_Settings` |
| `FXLM_Signal_Tracker` | `BT_SignalTracker` |
| `FXLM_Theme` | `BT_Theme` |
| `FXLM_Tools` | `BT_Tools` |
| `FXLM_Trailer` | `BT_Trailer` |
| `FXLM_UserAuth` | `BT_UserAuth` |
| `FXLM_Utils` | `BT_Utils` |
| `FXLM_Widgets` | `BT_Widgets` |

Note: `BT_SEO_Sitemap`, `BT_NativeChart`, `BT_Database`, `BT_DB_Admin`,
`BT_Source_Validator`, `BT_CWV`, `BT_A11y` were already canonical `BT_`
names from v96.x — no aliases needed.

### 3. Cron schedule aliases

```php
'bt_five_minutes'    ← same interval as 'fxlm_five_minutes'    (300s)
'bt_fifteen_minutes' ← same interval as 'fxlm_fifteen_minutes' (900s)
'bt_hourly'          ← same interval as 'fxlm_hourly'          (3600s)
'bt_twice_daily'     ← same interval as 'fxlm_twice_daily'     (43200s)
```

Registered via `cron_schedules` filter at priority 2 (after the `fxlm_`
ones at priority 1).

### 4. Action hook aliases

When any of the following hooks fires, a parallel `bt_` hook fires at
priority 5 (before the payload at priority 10):

| Legacy hook | New hook |
|-------------|----------|
| `fxlm_refresh_prices` | `bt_refresh_prices` |
| `fxlm_refresh_news` | `bt_refresh_news` |
| `fxlm_refresh_signals` | `bt_refresh_signals` |
| `fxlm_refresh_exchanges` | `bt_refresh_exchanges` |
| `fxlm_refresh_fng` | `bt_refresh_fng` |
| `fxlm_daily_ai_post` | `bt_daily_ai_post` |
| `fxlm_ai_posts` | `bt_ai_posts` |

New cron callbacks should hook on `bt_refresh_prices` etc.

### 5. Shortcode aliases (33 shortcodes)

Every `[fxlm_*]` shortcode gets a `[bt_*]` alias registered at `init`
priority 20 (after originals at priority 10).  Attributes and enclosed
content are forwarded exactly.

Already-native `bt_` shortcodes are not overwritten:
`bt_price_chart`, `bt_intelligence_brief`, `bt_market_pulse`,
`bt_signal_track_record`, `bt_portfolio_page`, `bt_watchlist_page` etc.

Example equivalences:

| Old shortcode | New shortcode |
|---------------|--------------|
| `[fxlm_crypto_table]` | `[bt_crypto_table]` |
| `[fxlm_forex_table]` | `[bt_forex_table]` |
| `[fxlm_news_feed]` | `[bt_news_feed]` |
| `[fxlm_tradingview_chart symbol="BTC"]` | `[bt_tradingview_chart symbol="BTC"]` |
| `[fxlm_fear_greed]` | `[bt_fear_greed]` |
| `[fxlm_signals_feed]` | `[bt_signals_feed]` |

Full list of 33 aliases in `class-compat.php` §5.

### 6. Option key helpers

Two new global functions available to child themes and third-party plugins:

```php
// Read bt_site_name, falling through to fxlm_site_name if bt_ not set.
$name = bt_get_option( 'bt_site_name', 'BlockTicker' );

// Write to bt_site_name AND fxlm_site_name (dual-write until v98.0).
bt_update_option( 'bt_site_name', 'My BlockTicker Site' );
```

### 7. Admin migration notice

A one-time dismissible notice appears in WP Admin explaining the rename
and linking to the migration guide at `blockticker.io/docs/v97-migration`.
Dismissed per-user via user meta.

### 8. Debug mode deprecation log

When `WP_DEBUG=true`, `bt_deprecated_hook_used` fires alongside each
legacy cron hook. Third-party developers can hook on this to detect
deprecated usage in their own code:

```php
add_action( 'bt_deprecated_hook_used', function( $old, $new, $since ) {
    error_log( "Deprecated: $old → use $new (since BlockTicker $since)" );
}, 10, 3 );
```

### 9. Self-test on admin_init

On first admin load after an upgrade, the compat layer verifies all 28
`BT_` class aliases resolved correctly and logs missing aliases to the WP
debug log (`WP_DEBUG_LOG=true` required to see the output). Non-fatal.

---

## `fx-live-markets.php`

- Version → 97.0.0
- `require_once FXLM_DIR . 'includes/class-compat.php'` (after all other includes)

---

## Safety & rollback

1. Strictly additive — no existing code path is modified.
2. `class_alias()` is guarded with `class_exists($fxlm_class)` before
   calling, so a missing source class causes a silent skip rather than
   a fatal error.
3. Shortcode aliases are guarded with `shortcode_exists()` checks in both
   directions — neither direction can clobber an existing registration.

### Rollback

Reinstall v96.7.0. The only data written is:
- `bt_compat_tested` option (autoload=no) — harmless if left behind
- `bt_v97_notice_dismissed` user meta — harmless if left behind

---

## PHP lint

- `includes/class-compat.php` ✅
- `fx-live-markets.php` ✅

---

## Migration guide for third-party developers

If you have a child theme or mu-plugin that reads BlockTicker data:

```php
// BEFORE (v96.x and earlier — still works in v97+)
$data = get_option( 'fxlm_crypto_data' );
FXLM_Widgets::some_method();
add_action( 'fxlm_refresh_prices', 'my_callback' );

// AFTER (v97+ preferred style)
$data = bt_get_option( 'bt_crypto_data' );  // falls through to fxlm_crypto_data
BT_Widgets::some_method();                   // class_alias points to same class
add_action( 'bt_refresh_prices', 'my_callback' );
```

Option key physical migration (`wp_options` rows renamed from `fxlm_*` to
`bt_*`) happens in v98.0.  Until then, `bt_get_option('bt_crypto_data')`
automatically reads from `fxlm_crypto_data`.

---

## What's next

- **v98.0** — Option key migration: all `fxlm_*` wp_options rows copied
  to `bt_*` equivalents; `fxlm_*` keys become read-through aliases.
  Includes a one-click migration tool in the BlockTicker → 🗄 Database screen.
