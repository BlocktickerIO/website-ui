<?php
/**
 * Plugin Name: BlockTicker — Live Crypto & Forex Intelligence
 * Plugin URI:  https://blockticker.io
 * Description: Complete automated setup for your Crypto & Forex autoblog. One-click wizard: live data, 14+ news sources, AI content, newsletter, tools, education, SEO — fully autopilot.
 * Version:      119.29.0
 * Author:      BlockTicker
 * License:     GPL2
 * Text Domain: blockticker
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ── EARLIEST POSSIBLE wordpress.org HTTP block ───────────────────────────────
// This MUST run before plugins_loaded so it fires before any other plugin
// (WooCommerce, Action Scheduler, Yoast, etc.) makes outbound requests to
// api.wordpress.org during their own plugins_loaded callbacks.
//
// Problem: update_option('permalink_structure') and other wizard operations
// trigger wp_update_plugins() / wp_version_check() which make SSL connections
// to api.wordpress.org.  On restricted hosts (e.g. Hostinger) the server
// CANNOT reach wordpress.org — the SSL handshake hangs for ~60 seconds,
// PHP times out, the AJAX response is empty, and the step stays "Running..."
// forever.  Even with ob_start() in the handler, the hang occurs BEFORE our
// handler code runs — during the plugins_loaded boot phase triggered by the
// AJAX request itself.
//
// Solution: detect the step-run AJAX call by $_POST['action'] at file-load
// time (no WordPress functions needed) and add the pre_http_request block
// immediately.  This fires before ANY plugin's plugins_loaded hook.
if ( defined( 'DOING_AJAX' ) && DOING_AJAX &&
     isset( $_POST['action'] ) && $_POST['action'] === 'fxlm_run_step' ) {
    add_filter( 'pre_http_request', function( $preempt, $parsed_args, $url ) {
        if ( strpos( $url, 'wordpress.org' ) !== false ||
             strpos( $url, 'api.w.org' )     !== false ) {
            return new WP_Error(
                'bt_wporg_blocked',
                '[BlockTicker] Blocked wp.org HTTP during wizard step to prevent 60 s hang.'
            );
        }
        return $preempt;
    }, 1, 3 );
}
// ─────────────────────────────────────────────────────────────────────────────

// ── Error capture (v116.0.1) ─────────────────────────────────────────────────
// Writes fatal errors to blockticker-error.log inside the plugin directory so
// you can read it via FTP / File Manager even without server-log access.
// Remove or comment out once the root cause is confirmed.
define( 'BT_ERROR_LOG', plugin_dir_path( __FILE__ ) . 'blockticker-error.log' );
register_shutdown_function( function() {
    $e = error_get_last();
    if ( $e && in_array( $e['type'], array( E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR ), true ) ) {
        $line = date( 'Y-m-d H:i:s' ) . ' UTC | ' . $e['type'] . ' | ' . $e['message']
              . ' | File: ' . $e['file'] . ' | Line: ' . $e['line'] . PHP_EOL;
        // Keep log under 50 KB — rotate when it grows too large.
        if ( file_exists( BT_ERROR_LOG ) && filesize( BT_ERROR_LOG ) > 50000 ) {
            rename( BT_ERROR_LOG, BT_ERROR_LOG . '.1' );
        }
        file_put_contents( BT_ERROR_LOG, $line, FILE_APPEND | LOCK_EX );
    }
} );
// ─────────────────────────────────────────────────────────────────────────────

// ── Action Scheduler premature-call Notice suppression ──────────────────────
// WooCommerce / other plugins call as_next_scheduled_action() during
// plugins_loaded before AS's data store is initialised (it initialises on the
// 'init' hook).  This produces PHP Notices that contaminate AJAX responses
// even with our ob_start() handler-level guard, because the Notices fire
// during plugins_loaded — BEFORE our handler's ob_start() runs.  Suppress
// them at the source for our step-run AJAX requests by elevating the error
// reporting level early so notices are skipped entirely.
if ( defined( 'DOING_AJAX' ) && DOING_AJAX &&
     isset( $_POST['action'] ) && $_POST['action'] === 'fxlm_run_step' ) {
    // Skip E_NOTICE / E_USER_NOTICE during the AJAX request — keep warnings
    // and errors so genuine bugs still surface in the response.
    @error_reporting( E_ALL & ~E_NOTICE & ~E_USER_NOTICE & ~E_DEPRECATED & ~E_STRICT );
    @ini_set( 'display_errors', '0' );  // never echo to response
}
// v119.28.37 — General-context suppression of the same Notice.
// Production logs (April 2026) showed `as_next_scheduled_action was called
// incorrectly` notices firing on regular page loads (not just our AJAX path),
// triggered by other plugins that call AS too early. The wp_doing_it_wrong
// filter lets us suppress this ONE specific notice surgically — every other
// _doing_it_wrong call still fires normally so genuine bugs aren't masked.
add_filter( 'doing_it_wrong_trigger_error', function( $trigger, $function_name ) {
    if ( in_array( $function_name, array(
        'as_next_scheduled_action',
        'as_has_scheduled_action',
        'as_get_scheduled_actions',
    ), true ) ) {
        return false; // Suppress this specific notice only.
    }
    return $trigger;
}, 10, 2 );

// v119.28.37 — One-shot deprecation backtrace logger.
// Captures full call stack the first time a PHP deprecation fires per request,
// writes it to bt-error.log (existing log file), then turns itself off.
// Helps locate the source of "ltrim(): Passing null" / similar deprecations
// reported in production whose call site isn't visible from the message alone.
// Disabled by default — set BT_DEPRECATION_TRACE=true in wp-config.php to enable.
// NOTE: v119.29.0+ recommends using BlockTicker\Core\Security for production
// security hardening. This tracer remains useful for debugging deprecations.
if ( defined( 'BT_DEPRECATION_TRACE' ) && BT_DEPRECATION_TRACE ) {
    set_error_handler( function( $errno, $errstr, $errfile, $errline ) {
        static $logged = false;
        if ( $logged ) return false; // Only first one per request.
        if ( $errno !== E_DEPRECATED && $errno !== E_USER_DEPRECATED ) return false;
        $logged = true;
        if ( ! defined( 'BT_ERROR_LOG' ) ) return false;
        $bt = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 12 );
        $trace = '';
        foreach ( $bt as $i => $frame ) {
            $trace .= sprintf( "  #%d %s%s%s() in %s:%d\n",
                $i,
                isset( $frame['class']    ) ? $frame['class']    : '',
                isset( $frame['type']     ) ? $frame['type']     : '',
                isset( $frame['function'] ) ? $frame['function'] : '?',
                isset( $frame['file']     ) ? basename( $frame['file'] ) : '?',
                isset( $frame['line']     ) ? $frame['line']     : 0
            );
        }
        $line = '['.gmdate('Y-m-d H:i:s').' UTC] DEPRECATION: '.$errstr.' in '.$errfile.':'.$errline."\n".$trace."\n";
        @file_put_contents( BT_ERROR_LOG, $line, FILE_APPEND | LOCK_EX );
        return false; // Let PHP also handle it (so existing logging still works).
    }, E_DEPRECATED | E_USER_DEPRECATED );
}
// ───────────────────────────────────────────────────────────────────────────── (renamed from FXLM_* which were legacy).
// FXLM_* kept as one-release backward-compat shims — removed in v103.0.
// When bumping version, update the plugin header above AND BT_VERSION below.
define( 'BT_VERSION', '119.29.0' );
define( 'BT_DIR',     plugin_dir_path( __FILE__ ) );
define( 'BT_URL',     plugin_dir_url( __FILE__ ) );
// FXLM_VERSION / FXLM_DIR / FXLM_URL shims removed in v103.0.

// ── PSR-4 Autoloader Registration (v119.29.0) ────────────────────────────────
// Load modern PSR-4 autoloader for new BlockTicker\ namespace classes.
// This allows incremental migration from legacy class-*.php files to
// properly namespaced src/ modules without breaking existing functionality.
require_once BT_DIR . 'src/autoload.php';

// ── Initialize Modern Security Module (v119.29.0) ───────────────────────────
// Security headers, rate limiting, diagnostic file cleanup now handled by
// BlockTicker\Core\Security class via autoload.php plugins_loaded:1 hook.
// Legacy inline security code below will be removed in v120.0.0.
// ─────────────────────────────────────────────────────────────────────────────

require_once BT_DIR . 'includes/class-i18n.php';         // i18n — must load first
require_once BT_DIR . 'includes/class-utils.php';        // v69: Shared utilities — must load before classes that use it
require_once BT_DIR . 'includes/class-installer.php';
require_once BT_DIR . 'includes/class-pages.php';
require_once BT_DIR . 'includes/class-settings.php';
require_once BT_DIR . 'includes/class-widgets.php';
require_once BT_DIR . 'includes/class-rss.php';
require_once BT_DIR . 'includes/class-seo.php';
require_once BT_DIR . 'includes/class-admin.php';
require_once BT_DIR . 'includes/class-tools.php';
require_once BT_DIR . 'includes/class-autoupdate.php';
require_once BT_DIR . 'includes/class-navbar.php';
require_once BT_DIR . 'includes/class-trailer.php';
require_once BT_DIR . 'includes/class-gdpr.php';
require_once BT_DIR . 'includes/class-aiblog.php';
require_once BT_DIR . 'includes/class-contact.php';
require_once BT_DIR . 'includes/class-monetize.php';
require_once BT_DIR . 'includes/class-theme.php';
require_once BT_DIR . 'includes/class-asset-pages.php'; // P2-2: Individual asset pages
require_once BT_DIR . 'includes/class-eeat.php';        // E-E-A-T, author bylines, guest submissions
require_once BT_DIR . 'includes/class-portfolio.php';    // Portfolio tracker + price alerts
require_once BT_DIR . 'includes/class-userauth.php';      // User accounts, cloud portfolio/watchlist sync
require_once BT_DIR . 'includes/class-pwa.php';          // v64.1: PWA manifest + service worker + install prompt
require_once BT_DIR . 'includes/class-api.php';          // v65.1: Public REST API under /wp-json/blockticker/v1/
require_once BT_DIR . 'includes/class-signal-tracker.php'; // v70: Signal track-record (extracted from class-widgets)
require_once BT_DIR . 'includes/class-dex-tokens.php';   // v72: DEX pools + Solana memes (extracted from class-widgets)
require_once BT_DIR . 'includes/class-intelligence-brief.php'; // v73: Composite cross-market brief (rebuilt + extracted)
require_once BT_DIR . 'includes/class-signal-archive.php';     // v119.28: Public signal archive page
require_once BT_DIR . 'includes/class-desk-brief.php';          // v119.28: Daily desk brief page
require_once BT_DIR . 'includes/class-landing-revamp.php';     // v119.28: Landing page revamp
require_once BT_DIR . 'includes/class-landing-takeover.php';   // v119.28.17: Clean homepage takeover
require_once BT_DIR . 'includes/class-site-takeover.php';      // v119.28.23: Site-wide takeover for ALL non-homepage pages — bypasses theme entirely
require_once BT_DIR . 'includes/class-page-provisioner.php';   // v119.28.13: Pages diagnostic + auto-create + homepage switch
require_once BT_DIR . 'includes/class-exchanges.php';    // v74: Crypto exchange directory (extracted from class-widgets)
require_once BT_DIR . 'includes/class-database.php';     // v96.2: Custom DB tables for price/news/signals/events history
require_once BT_DIR . 'includes/class-source-validator.php'; // v96.3: RSS/API source health validator
require_once BT_DIR . 'includes/class-db-admin.php';         // v96.3: WP admin DB manager screen
require_once BT_DIR . 'includes/class-native-chart.php';       // v96.4: Native [bt_price_chart] shortcode (lightweight-charts)
require_once BT_DIR . 'includes/class-seo-sitemap.php';         // v96.5: Dynamic XML sitemap for asset pages
require_once BT_DIR . 'includes/class-cwv.php';                   // v96.6: Core Web Vitals optimizer
require_once BT_DIR . 'includes/class-a11y.php';                  // v96.7: WCAG 2.1 AA accessibility layer
require_once BT_DIR . 'includes/class-compat.php';                // v97.0: BT_ alias layer over all FXLM_ identifiers (load last)
require_once BT_DIR . 'includes/class-migration.php';             // v98.0: fxlm_* → bt_* option key migration engine
require_once BT_DIR . 'includes/class-deprecation.php';           // v99.0: pre_option shims + _doing_it_wrong on fxlm_* reads
require_once BT_DIR . 'includes/class-v100-upgrade.php';           // v100.0: legacy row deletion + cron event migration
require_once BT_DIR . 'includes/class-sentiment.php';             // v104.0: news sentiment scoring + market mood shortcodes
require_once BT_DIR . 'includes/class-alerts.php';                // v119.9.0: news alerts + alerts hub + Set Alert button + admin overview
require_once BT_DIR . 'includes/class-forecast.php';              // v119.10.0: daily SEO forecast pages /forecast/{slug}/
require_once BT_DIR . 'includes/class-performance.php';           // v119.11.0: signal track-record dashboard /performance/ + summary card + admin overview
require_once BT_DIR . 'includes/class-top-lists.php';             // v119.12.0: commercial-intent top-N landing pages /top/ + /top/{slug}/
require_once BT_DIR . 'includes/class-trust-strip.php';           // v119.13.0: homepage trust strip + compliance disclaimer + Schema.org AggregateRating
require_once BT_DIR . 'includes/class-mobile-ux.php';             // v119.14.0: mobile UX layer (sticky bottom nav + asset-page CTA bar + back-to-top FAB + WCAG-AAA touch targets)
require_once BT_DIR . 'includes/class-dashboard.php';             // v119.15.0: personalized dashboard (3 preset layouts + per-user persistence + widget registry)
require_once BT_DIR . 'includes/class-screeners.php';             // v119.16.0: saved screeners (filter combinations over bt_crypto_data + per-user save/load + dashboard widget)
require_once BT_DIR . 'includes/class-following.php';             // v119.17.0: following list (assets/news sources/signal sources) + personalized news feed + drop-in follow button
require_once BT_DIR . 'includes/class-personalized-pages.php';    // v119.21.0: cache-control headers (Cache-Control: private + nocache) on /dashboard/, /screeners/, /following/, /watchlist/, /portfolio/, /alerts/ — fixes "saves not visible after navigate-away" caused by full-page caches
require_once BT_DIR . 'includes/class-personalized-brief.php';     // v119.22.0: Personalized AI Brief — daily Claude-generated market narrative for each user's watchlist + screeners + following
require_once BT_DIR . 'includes/class-market-analysis-ux.php';     // v119.24.0: Market-analysis UX upgrades — audience split, sticky page nav, methodology card, exit-intent newsletter
require_once BT_DIR . 'includes/class-branding.php';               // v119.25.0: Site logo + favicon configuration
require_once BT_DIR . 'includes/class-autopilot-health.php';       // v119.27.0: Autopilot preflight, run logging, error decoder, status widget
require_once BT_DIR . 'includes/class-correlation.php';          // v106.0: price correlation heatmap shortcode
require_once BT_DIR . 'includes/class-newsletter.php';          // v108.0: weekly AI digest email
require_once BT_DIR . 'includes/class-webhook.php';             // v109.0: webhook event delivery engine
require_once BT_DIR . 'includes/class-social.php';             // v113.0: social auto-share (X + Telegram)
require_once BT_DIR . 'includes/class-portfolio-v2.php';       // v114.0: transaction-level portfolio tracker
require_once BT_DIR . 'includes/class-api-keys.php';            // v115.0: public API key management
require_once BT_DIR . 'includes/class-api-docs.php';            // v115.0: OpenAPI 3.0 spec + Swagger UI
require_once BT_DIR . 'includes/class-tax-report.php';          // v116.0: realized-gains tax report
require_once BT_DIR . 'includes/class-cron-health.php';         // v119.28.32: SLA monitoring for 5 critical cron jobs
require_once BT_DIR . 'includes/class-diagnostic.php';          // v119.28.36: 5-layer "did my changes ship?" diagnostic

register_activation_hook( __FILE__, array( 'BT_Admin', 'on_activate' ) );
// v96.2: Create custom DB tables on activation.
register_activation_hook( __FILE__, array( 'BT_Database', 'install' ) );
// v98.0: Seed bt_* option keys on fresh activation.
register_activation_hook( __FILE__, array( 'BT_Migration', 'run' ) );
register_activation_hook( __FILE__, function() {
    update_option( 'bt_db_version', BT_Database::DB_VERSION );
    // If activating for the first time, seed price_history from current options.
    if ( false !== get_option( 'bt_crypto_data' ) ) {
        BT_Database::snapshot_prices_after_fetch();
    }
} );
register_activation_hook( __FILE__, function() {
    // Re-register cron events on activation to fix 'invalid_schedule' errors
    // that occur when the plugin is updated and WP tries to fire old events
    // before the schedule filter loads
    foreach ( array( 'bt_refresh_prices', 'bt_refresh_news', 'bt_refresh_signals',
                     'bt_refresh_exchanges', 'bt_refresh_fng', 'bt_daily_ai_post',
                     'bt_check_price_alerts', 'bt_verdict_snapshot' ) as $hook ) {
        wp_clear_scheduled_hook( $hook );
    }
} );
register_activation_hook( __FILE__, array( 'BT_Installer', 'deactivate_conflicts' ) );
register_activation_hook( __FILE__, function() {
    // Clean up ghost cron jobs left by deactivated plugins
    $ghost_hooks = array( 'wpra.update', 'wpra_update_schedule', 'wprss_update_all_feeds', 'wprss_fetch_all_feeds' );
    foreach ( $ghost_hooks as $hook ) {
        wp_clear_scheduled_hook( $hook );
    }
} );
register_deactivation_hook( __FILE__, array( 'BT_Admin', 'on_deactivate' ) );
// v96.3: Clear source-validator hourly cron on deactivation.
register_deactivation_hook( __FILE__, array( 'BT_Source_Validator', 'deactivate' ) );
// v104.0: Clear sentiment cron on deactivation.
register_deactivation_hook( __FILE__, array( 'BT_Sentiment', 'deactivate' ) );
register_deactivation_hook( __FILE__, array( 'BT_Newsletter', 'deactivate' ) );
register_deactivation_hook( __FILE__, array( 'BT_Webhook', 'deactivate' ) );
register_deactivation_hook( __FILE__, array( 'BT_Social', 'deactivate' ) );
register_deactivation_hook( __FILE__, array( 'BT_APIKeys', 'deactivate' ) );
// v119.28.32: stop the cron-health watchdog on deactivation.
register_deactivation_hook( __FILE__, array( 'BT_Cron_Health', 'deactivate' ) );
// v119.28.37: clear ALL bt_* cron events on deactivation. Without this, events
// scheduled during normal operation (e.g. bt_verdict_snapshot, bt_refresh_*)
// survive deactivation and get fired by WP-Cron — but our cron_schedules
// filter isn't loaded while the plugin is inactive, so wp_reschedule_event()
// throws "Event schedule does not exist" errors that flood the PHP log.
register_deactivation_hook( __FILE__, function() {
    $bt_cron_hooks = array(
        // Core data refresh
        'bt_refresh_prices', 'bt_refresh_news', 'bt_refresh_signals',
        'bt_refresh_exchanges', 'bt_refresh_fng',
        // AI / content
        'bt_daily_ai_post', 'bt_ai_posts', 'bt_health_check',
        // User-facing
        'bt_check_price_alerts', 'bt_verdict_snapshot',
        // Maintenance
        'bt_purge_old_data', 'bt_validate_sources', 'bt_score_news_sentiment',
        'bt_regenerate_sitemap', 'bt_signal_tracker_tick',
    );
    foreach ( $bt_cron_hooks as $hook ) {
        wp_clear_scheduled_hook( $hook );
    }
} );

add_action( 'plugins_loaded', array( 'BT_Admin', 'init' ) );
// v96.2: Custom DB tables — install hook + snapshot cron hooks.
add_action( 'plugins_loaded', array( 'BT_Database', 'init' ) );
// v96.3: Source validator (hourly health check cron + AJAX).
add_action( 'plugins_loaded', array( 'BT_Source_Validator', 'init' ) );
// v96.3: DB admin screen under BlockTicker menu.
add_action( 'plugins_loaded', array( 'BT_DB_Admin', 'init' ) );
// v96.4: Native chart shortcode + REST endpoint.
add_action( 'plugins_loaded', array( 'BT_NativeChart', 'init' ) );
// v96.5: Dynamic asset sitemap + Google/Bing ping.
add_action( 'plugins_loaded', array( 'BT_SEO_Sitemap', 'init' ) );
// v96.6: Core Web Vitals optimizer (async fonts, defer JS, WebP, TV preload).
add_action( 'plugins_loaded', array( 'BT_CWV', 'init' ) );
add_action( 'wp_footer',      array( 'BT_CWV', 'record_tv_chart_page' ), 1 );
// v96.7: WCAG 2.1 AA accessibility layer.
add_action( 'plugins_loaded', array( 'BT_A11y', 'init' ) );
// v119.28.32: cron-health watchdog (5-min SLA checks for critical jobs).
add_action( 'plugins_loaded', array( 'BT_Cron_Health', 'init' ) );
// v119.28.36: 5-layer diagnostic page (admin-only + URL-token).
add_action( 'plugins_loaded', array( 'BT_Diagnostic', 'init' ) );
// v98.0: Option key migration engine — forward-sync hooks + admin AJAX.
add_action( 'plugins_loaded', array( 'BT_Migration', 'init' ) );
// v99.0: pre_option shims + deprecation warnings for legacy fxlm_* reads.
add_action( 'plugins_loaded', array( 'BT_Deprecation', 'init' ) );
// v100.0: Final cleanup — delete fxlm_* option rows + migrate cron events.
add_action( 'plugins_loaded', array( 'BT_V100_Upgrade', 'init' ) );
// v104.0: Sentiment scoring cron + [bt_sentiment_bar] shortcode.
add_action( 'plugins_loaded', array( 'BT_Sentiment', 'init' ) );
// v106.0: Correlation heatmap shortcode + REST endpoint.
add_action( 'plugins_loaded', array( 'BT_Correlation', 'init' ) );
// v108.0: Weekly newsletter digest cron + admin AJAX.
add_action( 'plugins_loaded', array( 'BT_Newsletter', 'init' ) );
// v109.0: Webhook event delivery cron + admin AJAX.
add_action( 'plugins_loaded', array( 'BT_Webhook', 'init' ) );
// v113.0: Social auto-share (X + Telegram) — listens on bt_asset_analysis_saved.
add_action( 'plugins_loaded', array( 'BT_Social', 'init' ) );
// v114.0: Portfolio Tracker 3.0 — transaction ledger, cost-basis engines, CSV import.
add_action( 'plugins_loaded', array( 'BT_PortfolioV2', 'init' ) );
// v115.0: API Key management — admin panel, user shortcode, usage metering, daily rollover.
add_action( 'plugins_loaded', array( 'BT_APIKeys', 'init' ) );
// v115.0: OpenAPI 3.0 spec + Swagger UI shortcode.
add_action( 'plugins_loaded', array( 'BT_APIDocs', 'init' ) );
// v116.0: Realized-gains tax report (HTML + CSV + Form 8949).
add_action( 'plugins_loaded', array( 'BT_TaxReport', 'init' ) );
add_action( 'plugins_loaded', array( 'BT_PersonalizedBrief', 'init' ) ); // v119.22.0
add_action( 'plugins_loaded', array( 'BT_MarketAnalysisUX',  'init' ) ); // v119.24.0
add_action( 'plugins_loaded', array( 'BT_Branding',          'init' ) ); // v119.25.0
add_action( 'plugins_loaded', array( 'BT_Autopilot_Health',  'init' ) ); // v119.27.0

// v96.7: Ensure the main content wrapper carries the skip-link target ID.
// GeneratePress uses #content; Astra uses #primary.  We add id="bt-main-content"
// via a wp_head <script> shim so the skip link always has a valid target even
// if the active theme uses neither of those IDs.
add_action( 'wp_footer', function() {
    if ( is_admin() ) return;
    ?>
    <script>
    (function(){
        var ids = ['content','primary','main','site-content','page-content'];
        for (var i=0; i<ids.length; i++){
            var el = document.getElementById(ids[i]);
            if (el && !document.getElementById('bt-main-content')){
                el.id = 'bt-main-content';
                break;
            }
        }
        // Fallback: first <main> element
        if (!document.getElementById('bt-main-content')){
            var main = document.querySelector('main,[role="main"]');
            if (main) main.id = 'bt-main-content';
        }
    })();
    </script>
    <?php
}, 5 );



// Flush rewrite rules: on activation, on version change, and if crypto rule is missing
register_activation_hook( __FILE__, function() {
    BT_AssetPages::add_rewrite_rules();
    flush_rewrite_rules( false );
    update_option( 'bt_rewrite_version', BT_VERSION );
    // v55: seed default Twitter handle + multi-format toggle
    add_option( 'bt_twitter_handle', '@blocktickerIO' );
    add_option( 'bt_ai_multiformat', '1' );
} );

add_action( 'init', function() {
    // v96.1 audit fix F-03 + F-18: Guard expensive rewrite-rules check from hot
    // request paths. Previously ran on EVERY init (REST, cron, heartbeat, ajax),
    // reading the full `rewrite_rules` option blob each time. Now:
    //   1. Skipped entirely during REST / cron / ajax / xmlrpc
    //   2. Cached result in a 1-hour transient so we don't hit the option table
    //      on every frontend page load either.
    if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST )
      || ( defined( 'DOING_CRON' ) && DOING_CRON )
      || ( defined( 'DOING_AJAX' ) && DOING_AJAX )
      || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) {
        return;
    }
    // Cheap bail: if we've recently verified rewrite state, skip the DB work.
    if ( get_transient( 'bt_rewrite_check_ok' ) === BT_VERSION ) {
        return;
    }

    $stored_ver = get_option( 'bt_rewrite_version' );
    $rules = get_option( 'rewrite_rules', array() );
    $has_crypto_rule = false;
    if ( is_array( $rules ) ) {
        foreach ( array_keys( $rules ) as $rule ) {
            if ( strpos( $rule, 'crypto' ) !== false ) { $has_crypto_rule = true; break; }
        }
    }
    if ( $stored_ver !== BT_VERSION || ! $has_crypto_rule ) {
        flush_rewrite_rules( false );
        update_option( 'bt_rewrite_version', BT_VERSION );
    }
    // Mark as verified for the next hour.
    set_transient( 'bt_rewrite_check_ok', BT_VERSION, HOUR_IN_SECONDS );

    // Schedule page update check - don't run create_all() directly on init
    // (can cause memory fatal on shared hosting). Admin button triggers it instead.
    // Trigger page update on version change OR if pages haven't been updated yet
    $stored_ver = get_option( 'bt_pages_version', '' );
    if ( $stored_ver !== BT_VERSION ) {
        update_option( 'bt_pages_need_update', '1' );
        update_option( 'bt_pages_version', BT_VERSION );
    }
}, 99 );

// Auto-update key pages silently on first admin load after version bump
// Runs on admin_init (safe: WP tables exist, memory OK in admin context)
add_action( 'admin_init', function() {
    if ( ! get_option( 'bt_pages_need_update' ) ) return;
    if ( ! current_user_can( 'manage_options' ) ) return;
    if ( class_exists( 'BT_Pages' ) ) {
        // create_all() now uses direct $wpdb->update to bypass all WP hooks
        // (Yoast, cache, etc.) that caused count(false) fatal on sc_crypto_category
        BT_Pages::create_all();
        delete_option( 'bt_pages_need_update' );
    }
} );


// Auto-seed blog posts on first admin visit if no posts exist (prevents empty pages)
add_action( 'admin_init', function() {
    if ( ! current_user_can( 'manage_options' ) ) return;
    if ( get_option( 'bt_seed_articles_done' ) ) return;
    // Only seed if the user has actually loaded the AI blog class context
    if ( ! class_exists( 'BT_AIBlog' ) ) return;
    // Check if we already have published posts
    $existing = wp_count_posts( 'post' );
    if ( $existing && $existing->publish > 0 ) {
        update_option( 'bt_seed_articles_done', true );
        return;
    }
    // No posts exist — seed now
    BT_AIBlog::setup();
}, 100 );

// Admin action: manual permalink flush button
add_action( 'wp_ajax_fxlm_flush_rewrites', function() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
    check_ajax_referer( 'fxlm_flush', '_ajax_nonce' );
    BT_AssetPages::add_rewrite_rules();
    flush_rewrite_rules( true );
    delete_option( 'bt_rewrite_version' );
    wp_send_json_success( '/crypto/bitcoin/ and /forex/eur-usd/ should now work — please reload those pages.' );
} );

// Register all shortcodes
add_action( 'init', array( 'BT_Widgets',  'register_shortcodes' ) );
add_action( 'init', array( 'BT_RSS',      'register_shortcodes' ) );
add_action( 'init', array( 'BT_Settings', 'register_shortcodes' ) );
add_action( 'init', array( 'BT_Tools',    'register_shortcodes' ) );
add_action( 'init', array( 'BT_SEO_Legacy',      'register_shortcodes' ) );
add_action( 'init', array( 'BT_Trailer',  'register_shortcodes' ) );
add_action( 'init', array( 'BT_Contact',  'register_shortcodes' ) );

// Custom navbar, GDPR, AI Blog
add_action( 'init', array( 'BT_Navbar', 'init' ) );
add_action( 'init', array( 'BT_GDPR',     'init' ) );
add_action( 'init', array( 'BT_AIBlog',   'init' ) );
add_action( 'init', array( 'BT_Monetize', 'init' ) );
add_action( 'init', array( 'BT_Theme',    'init' ) );
add_action( 'init', array( 'BT_AssetPages', 'init' ) ); // P2-2: asset landing pages
add_action( 'init', array( 'BT_I18N',    'init' ) );    // i18n translation system
add_action( 'init', array( 'BT_EEAT',    'init' ) );    // E-E-A-T, author bylines, guest submissions
add_action( 'init', array( 'BT_Portfolio', 'init' ) ); // Portfolio + price alerts
add_action( 'init', array( 'BT_UserAuth',  'init' ) );  // User accounts + cloud sync
add_action( 'init', array( 'BT_SignalTracker', 'setup' ) ); // v70: signal track-record (extracted from class-widgets)
add_action( 'init', array( 'BT_DexTokens', 'setup' ) );     // v72: DEX pools + Solana memes (extracted from class-widgets)
add_action( 'init', array( 'BT_IntelligenceBrief', 'setup' ) ); // v73: Composite cross-market brief
add_action( 'init', array( 'BT_SignalArchive',     'init'  ) ); // v119.28: Public signal archive page
add_action( 'init', array( 'BT_DeskBrief',        'init'  ) ); // v119.28: Daily desk brief page
add_action( 'init', array( 'BT_LandingRevamp',   'init'  ) ); // v119.28: Landing page revamp
add_action( 'init', array( 'BT_Exchanges', 'setup' ) );          // v74: Exchange directory (extracted from class-widgets)
add_action( 'wp',   array( 'BT_Pages',      'init_hero_script' ) ); // hero particle JS via footer

// GeneratePress (and Astra) full-width / no-sidebar on every page
// GeneratePress uses generate_sidebar_layout filter
add_filter( 'generate_sidebar_layout', function() { return 'no-sidebar'; } );
add_filter( 'generate_page_content_template', function() { return 'one-container'; } );
// Keep Astra filters for backwards compatibility if theme is switched
add_filter( 'astra_page_layout', function() { return 'no-sidebar'; } );
add_filter( 'astra_get_content_layout', function() { return 'page-builder'; } );
add_filter( 'astra_single_post_site_sidebar_layout', function() { return 'no-sidebar'; } );
add_filter( 'astra_page_site_sidebar_layout', function() { return 'no-sidebar'; } );
add_filter( 'astra_archive_site_sidebar_layout', function() { return 'no-sidebar'; } );

// Cron hooks (single registration point)
add_action( 'bt_refresh_prices',   array( 'BT_Widgets', 'fetch_and_store_all' ) );
add_action( 'bt_refresh_exchanges', array( 'BT_Exchanges', 'fetch_exchange_details' ) );
add_action( 'bt_refresh_news',    array( 'BT_RSS',     'fetch_all_feeds' ) );
add_action( 'bt_refresh_signals', array( 'BT_RSS',     'fetch_signal_feeds' ) );
add_action( 'bt_ai_posts',        array( 'BT_RSS',     'generate_ai_post' ) );
add_action( 'bt_refresh_fng',     array( 'BT_Tools',   'fetch_fear_greed' ) );
add_action( 'bt_daily_ai_post',   array( 'BT_AIBlog', 'generate_daily_post' ) );

// v82 — Verdict snapshot cron (hourly): records the desk's current verdict
// + BTC/ETH anchor prices so we can grade accuracy in hindsight.
// Note: the bt_verdict_snapshot hook binding lives inside BT_IntelligenceBrief::setup()
// for symmetry with the class's other hooks, but the schedule is registered here
// so all cron scheduling lives in one file.
add_action( 'init', function() {
    if ( ! wp_next_scheduled( 'bt_verdict_snapshot' ) ) {
        wp_schedule_event( time() + 60, 'bt_hourly', 'bt_verdict_snapshot' );
    }
} );

// Custom cron intervals — registered as early as possible AND defensively
// re-registered on later boot hooks so wp_get_schedules() always returns the
// bt_* schedule keys, even if a third-party plugin clobbers the filter chain.
//
// v37 hardening: the previous muplugins_loaded:1 hook was dead code (this
// plugin file is included AFTER muplugins_loaded has already fired), so it
// never executed.  Replaced with file-include + plugins_loaded:0 + init:0
// re-registration.  Idempotent: bt_register_cron_schedules() is a pure setter
// that can run any number of times with the same effect.
function bt_register_cron_schedules( $s ) {
    if ( ! is_array( $s ) ) $s = array(); // Defensive — never feed null back to WP core.
    $s['bt_five_minutes']    = array( 'interval' => 300,   'display' => 'Every 5 minutes' );
    $s['bt_fifteen_minutes'] = array( 'interval' => 900,   'display' => 'Every 15 minutes' );
    $s['bt_hourly']          = array( 'interval' => 3600,  'display' => 'Every hour' );
    $s['bt_twice_daily']     = array( 'interval' => 43200, 'display' => 'Twice daily' );
    return $s;
}
// 1) Inline at file-include time. Fires the moment this file is loaded by WP.
add_filter( 'cron_schedules', 'bt_register_cron_schedules', 1 );
// 2) Defensive re-add on plugins_loaded — catches the case where a previously
//    loaded plugin called remove_all_filters('cron_schedules') during its own
//    muplugins_loaded callback (rare but observed in the wild).
add_action( 'plugins_loaded', function() {
    if ( ! has_filter( 'cron_schedules', 'bt_register_cron_schedules' ) ) {
        add_filter( 'cron_schedules', 'bt_register_cron_schedules', 1 );
    }
}, 0 );
// 3) Defensive re-add on init — last line of defence. wp_reschedule_event()
//    runs LATE in the cron loop, often after init has fired, so this is the
//    closest guard we can place to the actual point of failure.
add_action( 'init', function() {
    if ( ! has_filter( 'cron_schedules', 'bt_register_cron_schedules' ) ) {
        add_filter( 'cron_schedules', 'bt_register_cron_schedules', 1 );
    }
}, 0 );

// v119.28.37 — One-time zombie-cron repair.
//
// Production logs (April 2026) showed wp_reschedule_event() failures for
// bt_verdict_snapshot and bt_refresh_exchanges with "Event schedule does not
// exist" / schedule=bt_hourly. Root causes (now fixed in v37):
//   a) muplugins_loaded:1 cron registration was dead code (fixed above).
//   b) Deactivation didn't clear bt_* events, so they fired during deactivated
//      windows when the schedule filter wasn't loaded (fixed above).
//
// Existing zombie events on disk still need a one-time repair: events whose
// stored recurrence isn't in wp_get_schedules() (e.g. an event saved against
// 'bt_hourly' before our filter loaded) get rescued here. Idempotent + marked
// complete via a versioned option key so this runs at most once per release.
add_action( 'admin_init', function() {
    $key = 'bt_cron_repair_v37_done';
    if ( get_option( $key ) === BT_VERSION ) return;
    if ( ! current_user_can( 'manage_options' ) ) return;

    // Map of every cron event the plugin manages to its canonical schedule.
    // If an event exists with a different / missing recurrence, we re-schedule
    // it under the canonical name.
    $canonical = array(
        'bt_refresh_prices'       => 'bt_five_minutes',
        'bt_refresh_news'         => 'bt_hourly',
        'bt_refresh_signals'      => 'bt_fifteen_minutes',
        'bt_refresh_exchanges'    => 'bt_hourly',
        'bt_refresh_fng'          => 'bt_hourly',
        'bt_verdict_snapshot'     => 'bt_hourly',
        'bt_check_price_alerts'   => 'bt_five_minutes',
        'bt_score_news_sentiment' => 'bt_hourly',
    );

    $valid_schedules = wp_get_schedules();
    if ( ! is_array( $valid_schedules ) ) $valid_schedules = array();
    $repaired = 0;

    foreach ( $canonical as $hook => $recurrence ) {
        // Pull every cron entry for this hook from the stored cron array.
        $crons = _get_cron_array();
        if ( ! is_array( $crons ) ) continue;
        $needs_repair = false;
        foreach ( $crons as $timestamp => $by_hook ) {
            if ( ! isset( $by_hook[ $hook ] ) ) continue;
            foreach ( (array) $by_hook[ $hook ] as $sig => $event ) {
                $stored_recurrence = isset( $event['schedule'] ) ? $event['schedule'] : '';
                if ( $stored_recurrence === false || $stored_recurrence === '' ) continue;
                if ( ! isset( $valid_schedules[ $stored_recurrence ] ) ) {
                    $needs_repair = true;
                    break 2;
                }
            }
        }
        if ( $needs_repair ) {
            wp_clear_scheduled_hook( $hook );
            wp_schedule_event( time() + wp_rand( 30, 120 ), $recurrence, $hook );
            $repaired++;
        }
    }

    update_option( $key, BT_VERSION, 'no' );

    if ( $repaired > 0 && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log( sprintf( '[BlockTicker v119.28.37] Cron repair: %d zombie event(s) rescheduled.', $repaired ) );
    }
}, 999 );

// Fix existing blog posts: convert stored ## markdown headings to HTML on display
add_filter( 'the_content', function( $content ) {
    if ( ! is_single() ) return $content;
    // Only process posts that still have raw markdown headings
    if ( strpos($content,'## ') === false && strpos($content,'# ') === false ) return $content;
    // Convert ## H2
    $content = preg_replace( '/^## (.+)$/m', '<h2>$1</h2>', $content );
    $content = preg_replace( '/^# (.+)$/m',  '<h1>$1</h1>', $content );
    $content = preg_replace( '/^### (.+)$/m','<h3>$1</h3>', $content );
    // Bold
    $content = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $content );
    return $content;
}, 5 );

// Add .fxlm-post-content class to single post entry-content for styling
add_filter( 'post_class', function($classes){
    if ( is_single() ) $classes[] = 'fxlm-single-post';
    return $classes;
} );

// Auto-update system
add_action( 'init', array( 'BT_AutoUpdate', 'init' ) );

// v119.29.0: Inline Critical CSS for fast initial paint (Core Web Vitals)
// This extracts above-the-fold styles from critical.css and inlines them
// directly in <head> to eliminate render-blocking CSS requests.
add_action( 'wp_head', function() {
    $critical_css_file = BT_DIR . 'assets/css/components/critical.css';
    if ( file_exists( $critical_css_file ) && ! is_admin() ) {
        $critical_css = file_get_contents( $critical_css_file );
        // Minify inline CSS by removing comments and extra whitespace
        $critical_css = preg_replace( '/\/\*.*?\*\//s', '', $critical_css );
        $critical_css = preg_replace( '/\s+/', ' ', $critical_css );
        echo '<style id="bt-critical-css">' . $critical_css . '</style>' . "\n";
    }
}, 1 );

// v6.2: Enqueue animation CSS + JS + critical fixes
add_action( 'wp_enqueue_scripts', function() {
    $ver = BT_VERSION;
    if ( file_exists( BT_DIR . 'assets/css/patch-animations.css' ) ) {
        wp_enqueue_style( 'fxlm-animations', BT_URL . 'assets/css/patch-animations.css', array( 'fxlm-frontend' ), $ver );
    }
    if ( file_exists( BT_DIR . 'assets/js/patch-animations.js' ) ) {
        wp_enqueue_script( 'fxlm-animations', BT_URL . 'assets/js/patch-animations.js', array(), $ver, true );
    }
    
    // v119.29.0: Enqueue modern frontend utilities module
    // Replaces inline scripts and provides skeleton loaders, price formatting, AJAX utils
    if ( file_exists( BT_DIR . 'assets/js/modules/frontend-utils.js' ) ) {
        wp_enqueue_script( 'bt-frontend-utils', BT_URL . 'assets/js/modules/frontend-utils.js', array(), $ver, true );
        // Add nonce for AJAX requests
        wp_add_inline_script( 'bt-frontend-utils', 'window.btNonce = "' . wp_create_nonce( 'bt_ajax_nonce' ) . '";', 'before' );
    }
}, 100 );

// v6.2: Critical site fixes — loaded LAST to override everything (priority 99999)
add_action( 'wp_enqueue_scripts', function() {
    if ( file_exists( BT_DIR . 'assets/css/critical-fixes.css' ) ) {
        wp_enqueue_style( 'fxlm-critical', BT_URL . 'assets/css/critical-fixes.css', array(), BT_VERSION );
    }
}, 99999 );

// Performance: Add async/defer to non-critical third-party scripts
// v96.6: BT_CWV::maybe_defer_script() handles the full plugin handle list.
// This filter is kept as a belt-and-suspenders fallback for any handle not
// covered by BT_CWV::DEFER_HANDLES.
add_filter( 'script_loader_tag', function( $tag, $handle, $src ) {
    $defer_handles = [ 'fxlm-animations', 'fxlm-patch', 'fxlm-revamp-v44', 'fxlm-frontend' ];
    if ( in_array( $handle, $defer_handles, true ) ) {
        if ( ! str_contains( $tag, 'defer' ) && ! str_contains( $tag, 'async' ) ) {
            return str_replace( ' src=', ' defer src=', $tag );
        }
    }
    return $tag;
}, 10, 3 );

// Performance: DNS prefetch + preconnect — now managed by BT_CWV and BT_SEO_Legacy::output_resource_hints().
// v96.6: The ad-hoc closure that previously duplicated these hints here has been removed.

// Performance: Add width/height to prevent CLS on plugin-generated images
add_filter( 'wp_get_attachment_image_attributes', function( $attr, $attachment, $size ) {
    // Ensure width/height always present to prevent layout shift
    if ( empty( $attr['width'] ) || empty( $attr['height'] ) ) {
        $meta = wp_get_attachment_metadata( $attachment->ID );
        if ( ! empty( $meta['width'] ) && ! empty( $meta['height'] ) ) {
            $attr['width']  = $meta['width'];
            $attr['height'] = $meta['height'];
        }
    }
    return $attr;
}, 10, 3 );

// v6.1 FIX: Ensure forex demo data exists immediately on activation
register_activation_hook( __FILE__, function() {
    $existing = get_option( 'bt_forex_data', array() );
    if ( empty( $existing ) || empty( $existing['rates'] ) ) {
        $demo = array(
            'EUR/USD' => array( 'rate' => 1.0845, 'change' => 0.12 ),
            'GBP/USD' => array( 'rate' => 1.2734, 'change' => -0.08 ),
            'USD/JPY' => array( 'rate' => 149.52, 'change' => 0.23 ),
            'USD/CHF' => array( 'rate' => 0.8923, 'change' => -0.05 ),
            'AUD/USD' => array( 'rate' => 0.6542, 'change' => 0.15 ),
            'USD/CAD' => array( 'rate' => 1.3678, 'change' => -0.11 ),
            'NZD/USD' => array( 'rate' => 0.6123, 'change' => 0.09 ),
            'EUR/GBP' => array( 'rate' => 0.8512, 'change' => 0.04 ),
        );
        update_option( 'bt_forex_data', array( 'rates' => $demo, 'updated' => time(), 'source' => 'demo' ) );
    }
} );

// v96.1 audit fix F-17: Removed the duplicate `add_action('init', ...)` demo-
// forex seeder that was firing on EVERY request. The activation hook above
// already seeds the same data once at plugin activation, which is the only
// time it is needed (demo data is a one-shot bootstrap, not a per-request
// fallback). If the fallback ever IS needed again, gate it behind
// `get_option('bt_forex_seed_done')` so it cannot re-run unconditionally.
