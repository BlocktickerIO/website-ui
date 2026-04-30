<?php
/**
 * BT_Compat — BlockTicker namespace compatibility shim.
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │ ACTIVE IN v100.0                                                    │
 * │                                                                     │
 * │  §1  Constants  : BT_VERSION / BT_DIR / BT_URL                     │
 * │  §2  Classes    : 29 × class_alias( 'FXLM_Foo', 'BT_Foo' )        │
 * │  §5  Shortcodes : bt_ aliases for all 33 fxlm_ shortcodes          │
 * │  §6  Helper fns : bt_get_option() / bt_update_option()             │
 * │  §7  Admin notice (v97 rename notice — auto-dismissed)             │
 * │  §8  Debug deprecation log (WP_DEBUG only)                         │
 * │  §9  Self-test on admin_init                                       │
 * │                                                                     │
 * │ RETIRED IN v100.0 (tombstones preserved for git history)           │
 * │  §3  Cron schedule aliases  — bt_* schedules now in main plugin    │
 * │  §4  Action hook aliases    — all cron events now bt_* native      │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 * Completed deprecation arc:
 *  v97.0  — BT_ alias layer added over all FXLM_ identifiers
 *  v98.0  — bt_* option keys seeded; forward-sync hooks installed
 *  v99.0  — All internal reads patched to bt_*; pre_option shims added
 *  v100.0 — fxlm_* option rows deleted; cron events migrated to bt_*
 *            §3 and §4 of this file retired (replaced with tombstones)
 *
 * Remaining in v101.0:
 *  — pre_option shims in class-deprecation.php (one further release cycle)
 *  — class_alias() entries in §2 (until underlying class files are renamed)
 *
 * @since 97.0.0
 * @updated 100.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ======================================================================
   1. CONSTANTS
   ====================================================================== */

/**
 * BT_VERSION — canonical plugin version string.
 * Alias of FXLM_VERSION; defined conditionally so child plugins that
 * check defined('BT_VERSION') work even if they load before us.
 */
// v103.0: FXLM_* constant shims removed from fx-live-markets.php.
// BT_VERSION / BT_DIR / BT_URL are defined there as the sole primary constants.
// These guards handle the edge case where this compat file loads before the
// main plugin header (e.g. mu-plugins, autoloaders, or direct inclusion).
if ( ! defined( 'BT_VERSION' ) ) {
    define( 'BT_VERSION', '103.0.0' );
}
if ( ! defined( 'BT_DIR' ) ) {
    define( 'BT_DIR', plugin_dir_path( dirname( __FILE__ ) ) );
}
if ( ! defined( 'BT_URL' ) ) {
    define( 'BT_URL', plugin_dir_url( dirname( __FILE__ ) ) );
}

/* ======================================================================
   2. CLASS ALIASES  — RETIRED in v102.0
   ====================================================================== */

/**
 * In v101.0, each class file appended a reverse class_alias('BT_Foo', 'FXLM_Foo')
 * block at the bottom. In v102.0 those blocks were removed (the 12-month
 * deprecation commitment from v97.0 has expired).
 *
 * This section is intentionally empty — left as a tombstone for git history.
 *
 * Any remaining third-party code using FXLM_* class names will now receive
 * a PHP fatal error. Migration guide: https://blockticker.io/docs/v97-migration
 *
 * @deprecated 102.0.0  FXLM_* class aliases fully removed.
 */

/* ======================================================================
   3. CRON SCHEDULE ALIASES  — RETIRED in v100.0
   ====================================================================== */

/**
 * Cron schedule aliases were needed in v97.0–v99.0 to let new code use
 * bt_hourly etc. while the main plugin still registered fxlm_* schedules.
 *
 * As of v100.0, fx-live-markets.php registers bt_* schedules directly via
 * bt_register_cron_schedules(). This section is intentionally empty — left
 * as a tombstone for git history.
 *
 * @since 97.0.0
 * @deprecated 100.0.0  No longer needed; bt_* schedules registered in main plugin.
 */

/* ======================================================================
   4. ACTION HOOK ALIASES  — RETIRED in v100.0
   ====================================================================== */

/**
 * Hook aliases were needed in v97.0–v99.0 to fire bt_refresh_prices etc.
 * in parallel with fxlm_refresh_prices while cron events still used fxlm_ names.
 *
 * As of v100.0, all cron events are scheduled as bt_* and all add_action()
 * calls in fx-live-markets.php hook directly onto bt_* names. The fxlm_*
 * cron events are unscheduled by BT_V100_Upgrade::migrate_cron_events().
 *
 * @since 97.0.0
 * @deprecated 100.0.0  No longer needed; all cron events are now bt_* native.
 */

/* ======================================================================
   5. SHORTCODE ALIASES  (33 fxlm_ → bt_ mappings)
   ====================================================================== */

/**
 * Register bt_ shortcodes as pass-through wrappers for the fxlm_ originals.
 * Runs at init priority 20 so all fxlm_ shortcodes are already registered
 * (they register at priority 10 via class::register_shortcodes() calls).
 *
 * Only creates a bt_ alias if:
 *  a) The fxlm_ shortcode is registered, AND
 *  b) No bt_ shortcode of the same name already exists (prevents clobbering
 *     the native bt_price_chart, bt_intelligence_brief etc.).
 */
add_action( 'init', function() {

    // Map fxlm_X → bt_X (strip fxlm_ prefix, add bt_).
    $fxlm_tags = array(
        'fxlm_adsense_banner',
        'fxlm_affiliate',
        'fxlm_ai_analysis',
        'fxlm_blog_posts',
        'fxlm_bottom_ticker',
        'fxlm_breadcrumbs',
        'fxlm_breaking_news',
        'fxlm_calculator',
        'fxlm_contact_form',
        'fxlm_cookie_settings',
        'fxlm_crypto_category',
        'fxlm_crypto_converter',
        'fxlm_crypto_full_table',
        'fxlm_crypto_table',
        'fxlm_economic_calendar',
        'fxlm_exchanges',
        'fxlm_fear_greed',
        'fxlm_forex_table',
        'fxlm_gainers_losers',
        'fxlm_glossary',
        'fxlm_live_prices',
        'fxlm_market_mood',
        'fxlm_news_feed',
        'fxlm_newsletter',
        'fxlm_portfolio',
        'fxlm_price_alerts',
        'fxlm_price_cards',
        'fxlm_search_bar',
        'fxlm_signals_feed',
        'fxlm_social',
        'fxlm_ticker_bar',
        'fxlm_tradingview_chart',
        'fxlm_trending_bar',
        'fxlm_watchlist',
    );

    foreach ( $fxlm_tags as $fxlm_tag ) {
        $bt_tag = 'bt_' . substr( $fxlm_tag, strlen( 'fxlm_' ) );

        // Skip if bt_ shortcode is already natively registered.
        if ( shortcode_exists( $bt_tag ) ) continue;
        // Skip if the fxlm_ original isn't registered (defensive guard).
        if ( ! shortcode_exists( $fxlm_tag ) ) continue;

        // Register bt_ as a proxy — passes all atts and content through.
        add_shortcode( $bt_tag, function( $atts, $content, $tag ) use ( $fxlm_tag ) {
            // Reconstruct the original shortcode string with all attributes.
            $attr_str = '';
            if ( is_array( $atts ) ) {
                foreach ( $atts as $k => $v ) {
                    if ( is_numeric( $k ) ) {
                        $attr_str .= ' ' . esc_attr( $v );
                    } else {
                        $attr_str .= ' ' . esc_attr( $k ) . '="' . esc_attr( $v ) . '"';
                    }
                }
            }
            $sc = '[' . $fxlm_tag . $attr_str . ']';
            if ( $content !== null ) {
                $sc .= $content . '[/' . $fxlm_tag . ']';
            }
            return do_shortcode( $sc );
        } );
    }

}, 20 );

/* ======================================================================
   6. OPTION KEY HELPER FUNCTIONS
   ====================================================================== */

/**
 * bt_get_option( $key, $default )
 *
 * Reads an option by its BT_ key.  In v97.0 option keys have not been
 * migrated, so this falls through to the fxlm_ equivalent automatically.
 * In v98.0, the fallback will be dropped once keys are physically renamed.
 *
 * Usage:
 *   $name = bt_get_option( 'bt_site_name', 'BlockTicker' );
 *   // same as: get_option( 'fxlm_site_name', 'BlockTicker' );
 *
 * @param string $key      Option key with bt_ prefix.
 * @param mixed  $default  Fallback value.
 * @return mixed
 */
if ( ! function_exists( 'bt_get_option' ) ) {
    function bt_get_option( $key, $default = false ) {
        // Try the bt_ key first (will exist after v98.0 migration).
        $val = get_option( $key, null );
        if ( null !== $val ) return $val;

        // Fall through to the legacy fxlm_ key.
        $fxlm_key = preg_replace( '/^bt_/', 'fxlm_', $key );
        if ( $fxlm_key !== $key ) {
            return get_option( $fxlm_key, $default );
        }

        return $default;
    }
}

/**
 * bt_update_option( $key, $value, $autoload )
 *
 * Writes an option under its BT_ key AND its legacy fxlm_ key so that
 * existing code reading fxlm_ keys continues to see the updated value.
 *
 * In v98.0 the dual-write will be dropped once all code reads bt_ keys.
 *
 * @param string    $key      Option key with bt_ prefix.
 * @param mixed     $value    Value to store.
 * @param string|bool $autoload  'yes'|'no'|true|false. Default 'yes'.
 * @return bool  True if either write succeeded.
 */
if ( ! function_exists( 'bt_update_option' ) ) {
    function bt_update_option( $key, $value, $autoload = 'yes' ) {
        $result   = update_option( $key, $value, $autoload );
        $fxlm_key = preg_replace( '/^bt_/', 'fxlm_', $key );
        if ( $fxlm_key !== $key ) {
            update_option( $fxlm_key, $value, $autoload );
        }
        return $result;
    }
}

/* ======================================================================
   7. ADMIN MIGRATION NOTICE
   ====================================================================== */

/**
 * Show a one-time dismissible admin notice explaining the rename.
 * Dismissed by the user clicking "×" which sets a flag in user-meta.
 */
/* v119.6 — v97 migration notice removed.
   The namespace unification was in v97.0 (now ~22 versions ago).
   The notice was still firing for any user who had not clicked dismiss.
   The underlying compat layer below remains so legacy code keeps working. */

add_action( 'wp_ajax_bt_dismiss_v97_notice', function() {
    check_ajax_referer( 'bt_dismiss_v97_notice', '_nonce' );
    update_user_meta( get_current_user_id(), 'bt_v97_notice_dismissed', true );
    wp_send_json_success();
} );

/* ======================================================================
   8. DEPRECATION LOG (debug mode only)
   ====================================================================== */

/**
 * In WP_DEBUG mode, add a do_action trigger that third-party code can
 * hook to detect when deprecated fxlm_ identifiers are being called.
 * This is intentionally a no-op in production.
 */
if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
    add_action( 'fxlm_refresh_prices',   function() { do_action( 'bt_deprecated_hook_used', 'fxlm_refresh_prices',   'bt_refresh_prices',   '97.0' ); }, 1 );
    add_action( 'fxlm_refresh_news',     function() { do_action( 'bt_deprecated_hook_used', 'fxlm_refresh_news',     'bt_refresh_news',     '97.0' ); }, 1 );
    add_action( 'fxlm_refresh_signals',  function() { do_action( 'bt_deprecated_hook_used', 'fxlm_refresh_signals',  'bt_refresh_signals',  '97.0' ); }, 1 );
    add_action( 'fxlm_daily_ai_post',    function() { do_action( 'bt_deprecated_hook_used', 'fxlm_daily_ai_post',    'bt_daily_ai_post',    '97.0' ); }, 1 );
}

/* ======================================================================
   9. SELF-TEST (admin-only, non-fatal)
   ====================================================================== */

/**
 * On activation, verify that all expected class aliases resolved.
 * Logs a warning to the WP debug log if any alias is missing.
 * Never throws or displays errors to the end user.
 */
add_action( 'admin_init', function() {
    if ( ! current_user_can( 'manage_options' ) ) return;
    // Run once per version.
    if ( get_option( 'bt_compat_tested' ) === BT_VERSION ) return;

    // v102.0: Only BT_* names checked — FXLM_* reverse aliases removed.
    $expected = array(
        'BT_Admin', 'BT_AIBlog', 'BT_API', 'BT_AssetPages', 'BT_AutoUpdate',
        'BT_Contact', 'BT_DexTokens', 'BT_EEAT', 'BT_Exchanges', 'BT_GDPR',
        'BT_I18N', 'BT_Installer', 'BT_IntelligenceBrief', 'BT_Monetize',
        'BT_Navbar', 'BT_Pages', 'BT_Portfolio', 'BT_PWA', 'BT_RSS',
        'BT_SEO_Legacy', 'BT_Settings', 'BT_SignalTracker', 'BT_Theme',
        'BT_Tools', 'BT_Trailer', 'BT_UserAuth', 'BT_Utils', 'BT_Widgets',
    );
    $missing = array();
    foreach ( $expected as $cls ) {
        if ( ! class_exists( $cls ) ) $missing[] = $cls;
    }
    if ( $missing && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log( '[BlockTicker v97.0] BT_ class aliases missing: ' . implode( ', ', $missing ) );
    }

    update_option( 'bt_compat_tested', BT_VERSION, 'no' );
}, 99 );
