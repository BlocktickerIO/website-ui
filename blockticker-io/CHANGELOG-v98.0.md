# BlockTicker v98.0.0 — Option Key Migration

**Release date:** April 2026
**Type:** Data migration release — additive, fully reversible, zero breaking changes
**Upgrade path:** Drop-in over v97.0.0

---

## Overview

v97.0 added `BT_` as a full alias layer over every `FXLM_` class, shortcode,
and hook. v98.0 completes the migration at the data layer: every `fxlm_*`
option row in `wp_options` is copied to a `bt_*` equivalent, and forward-sync
hooks ensure both key sets stay in sync going forward.

**Nothing breaks.** All existing code reading `get_option('fxlm_crypto_data')`
continues to work — the legacy rows are preserved, not deleted. Deletion of
`fxlm_*` rows is deferred to v99.0, giving a full release cycle as buffer.

---

## New: `includes/class-migration.php`

One new class `BT_Migration` (~320 lines).

### Migration scope

**58 named keys** — copied individually with correct autoload values:

| Group | Keys | Autoload |
|-------|------|----------|
| Settings & API keys | `bt_site_name`, `bt_cg_api_key`, `bt_openai_key`, `bt_claude_key`, `bt_adsense_id`, `bt_ga_id`, `bt_twitter_handle`, `bt_gdpr_enabled`, `bt_menu_config`, `bt_ai_provider`, `bt_og_image`, `bt_logo_url` … (24 total) | `yes` |
| Live data / cache | `bt_crypto_data`, `bt_forex_data`, `bt_news_items`, `bt_signal_items`, `bt_fear_greed_data`, `bt_ai_analysis`, `bt_exchange_details` … (11 total) | `no` |
| User data | `bt_subscribers`, `bt_contact_messages`, `bt_consent_log` | `no` |
| State & version flags | `bt_activated`, `bt_setup_progress`, `bt_pages_version`, `bt_rewrite_version`, `bt_last_ai_post_date`, `bt_health_issues` … (16 total) | `yes` |
| Social links | `bt_social_facebook`, `bt_social_twitter`, `bt_social_instagram`, `bt_social_youtube`, `bt_social_telegram`, `bt_social_tiktok`, `bt_social_discord`, `bt_social_linkedin` | `yes` |

**8 wildcard prefixes** — bulk-copied via a single `INSERT IGNORE … SELECT` SQL
query per prefix:

| fxlm_ prefix | bt_ prefix | Type |
|-------------|-----------|------|
| `fxlm_exchanges_data_v2_` | `bt_exchanges_data_v2_` | Exchange detail cache |
| `fxlm_exchanges_rest_` | `bt_exchanges_rest_` | REST exchange cache |
| `fxlm_coin_detail_` | `bt_coin_detail_` | Individual coin cache |
| `fxlm_cat_` | `bt_cat_` | Category data |
| `fxlm_mood_` | `bt_mood_` | Market mood data |
| `fxlm_feat_` | `bt_feat_` | Feature flags |
| `fxlm_vote_` | `bt_vote_` | User votes |
| `fxlm_votes_` | `bt_votes_` | Vote aggregates |

### Migration behaviour

**Idempotent.** `INSERT IGNORE` skips rows where the `bt_*` key already exists.
Re-running migration (or using "Force Re-migrate") is always safe.

**Non-destructive.** `fxlm_*` rows are never modified or deleted. Rolling back
simply deletes the `bt_*` rows; the `fxlm_*` rows remain untouched.

**Autoload-aware.** Settings that are read on every page load (`bt_site_name`,
API keys, social links) are copied with `autoload='yes'`. Large data blobs
(crypto prices, news items) are copied with `autoload='no'` to keep the
options cache lean.

### Forward-sync hooks

`install_sync_hooks()` — called on `plugins_loaded` — adds 58 `update_option_{fxlm_key}`
hooks. Whenever existing code writes to a legacy key, the `bt_*` key is
updated automatically in the same request:

```php
// Existing cron code (unchanged):
update_option( 'fxlm_crypto_data', $fresh_prices );
// Sync hook fires: bt_crypto_data is also updated — no code change needed.
```

Wildcard key writes are caught by a generic `updated_option` action that
checks for any of the 8 wildcard prefixes.

Static guard prevents recursion if `update_option('bt_*')` triggers another
`update_option_fxlm_*` event.

### Migration status tracking

Results are stored in `bt_migration_v98_status` (autoload=no):

```json
{
  "version":     "98.0.0",
  "timestamp":   1745289600,
  "migrated":    58,
  "skipped":     12,
  "wildcards":   340,
  "errors":      [],
  "complete":    true,
  "duration_ms": 87
}
```

### Rollback

`BT_Migration::rollback()` deletes all `bt_*` option rows created by the
migration (both named keys and wildcard rows) using targeted `DELETE` queries.
The `fxlm_*` rows remain intact — rolling back has zero effect on site
functionality.

---

## Admin: Option Key Migration panel

The **BlockTicker → 🗄 Database** screen gains a new **🔑 Option Key Migration**
panel between the DB Migration and Retention panels.

The panel shows:

- Named keys: N migrated, N skipped
- Wildcard rows copied
- Last run timestamp
- Any errors

Three buttons:

| Button | Action |
|--------|--------|
| **▶ Run Migration** | Copy `fxlm_*` → `bt_*` (skips existing `bt_*` rows) |
| **↺ Force Re-migrate** | Overwrite existing `bt_*` rows with current `fxlm_*` values |
| **✕ Rollback** | Delete all `bt_*` rows; restore `fxlm_*`-only state |

All three wire to AJAX actions gated by `manage_options` + the existing
`bt_db_admin` nonce.

---

## Automatic migration on activation

```php
register_activation_hook( __FILE__, array( 'BT_Migration', 'run' ) );
```

Fresh installs and upgrades automatically seed `bt_*` option keys at
activation time. Existing sites upgrading from v97.0 get the migration
triggered on their first activation of v98.0 — no manual button-press required.

---

## `uninstall.php` — Section 12 added

Comprehensive cleanup of all `bt_*` option rows on plugin deletion:

- 58 named `bt_*` options deleted individually
- 8 wildcard prefixes bulk-deleted via `DELETE … WHERE option_name LIKE`
- Covers all v96.x–v98.0 infrastructure keys (`bt_sitemap_xml_cache`,
  `bt_migration_v98_status`, `bt_page_has_tv_*`, `bt_compat_tested`, etc.)

---

## `fx-live-markets.php`

- Version → 98.0.0
- `require_once FXLM_DIR . 'includes/class-migration.php'`
- `add_action( 'plugins_loaded', array( 'BT_Migration', 'init' ) )`
- `register_activation_hook( __FILE__, array( 'BT_Migration', 'run' ) )`

---

## PHP lint

All files clean:
- `includes/class-migration.php` ✅
- `includes/class-db-admin.php`  ✅
- `uninstall.php`                ✅
- `fx-live-markets.php`          ✅

---

## Safety & rollback

1. `INSERT IGNORE` never overwrites existing data.
2. The `fxlm_*` rows are preserved throughout — rollback is always instant.
3. Forward-sync hooks are idempotent: running `install_sync_hooks()` on
   every request has no side effects beyond the `add_action()` calls.
4. The activation hook runs `BT_Migration::run()` without `$force`, so
   upgrading sites with existing `bt_*` data (e.g., from a manual first-run)
   do not get overwritten.

### Manual rollback

```sql
-- Remove all bt_* option rows created by this migration:
DELETE FROM wp_options WHERE option_name LIKE 'bt_%'
  AND option_name NOT IN ('bt_db_version','bt_source_health','bt_source_summary');
```

Or use the **✕ Rollback** button in the admin screen.

---

## Deprecation timeline update

| Version | Change |
|---------|--------|
| v97.0 | `BT_` aliases added (classes, shortcodes, hooks, constants) |
| **v98.0** ← *this release* | `bt_*` option keys seeded; forward-sync hooks installed |
| v99.0 | `fxlm_*` option keys marked deprecated; `_doing_it_wrong()` on direct reads |
| v100.0 | `fxlm_*` option rows deleted from wp_options during upgrade |

---

## What's next

- **v99.0** — Deprecation warnings: `_doing_it_wrong()` on all direct
  `get_option('fxlm_*')` calls; signal to remaining third-party code that
  the fxlm_ keys will be deleted in v100.0.
- **v100.0** — Final cleanup: `fxlm_*` option rows deleted; `FXLM_` class
  aliases removed; legacy cron hook names retired.
