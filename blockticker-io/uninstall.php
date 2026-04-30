<?php
/**
 * BlockTicker — Uninstall Script
 *
 * This file runs automatically when the plugin is DELETED from
 * WordPress Admin → Plugins → Delete.
 *
 * ╔═══════════════════════════════════════════════════════════════╗
 * ║  v119.28.35 — PRESERVE-BY-DEFAULT                             ║
 * ║                                                               ║
 * ║  Routine reinstalls (deactivate → delete → re-upload →        ║
 * ║  activate) used to wipe months of price history, all          ║
 * ║  provisioned pages, all settings, and all options.            ║
 * ║                                                               ║
 * ║  Now this script does NOTHING by default. Reinstalls are      ║
 * ║  safe. Data and configuration persist.                        ║
 * ║                                                               ║
 * ║  To genuinely uninstall and remove all traces, declare in     ║
 * ║  wp-config.php BEFORE deleting the plugin:                    ║
 * ║                                                               ║
 * ║    define( 'BT_PURGE_DATA_ON_UNINSTALL', true );              ║
 * ║                                                               ║
 * ║  Then delete the plugin from WP Admin. Remove the constant    ║
 * ║  afterwards.                                                  ║
 * ╚═══════════════════════════════════════════════════════════════╝
 *
 * When BT_PURGE_DATA_ON_UNINSTALL is true, this file removes:
 * - All plugin options from wp_options
 * - All scheduled cron jobs
 * - All pages created by the provisioner
 * - All navigation menus
 * - All categories created by the plugin
 * - All AI-generated posts
 * - All transients
 * - Newsletter subscribers
 * - Contact messages
 * - Consent logs
 * - Health logs
 * - Update logs
 * - All custom database tables (price history, news, signals, events)
 */

// Security: only run via WordPress uninstall
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// v119.28.35 — preserve-by-default. Without an explicit opt-in, this entire
// uninstall script does nothing. Reinstalling no longer wipes user data.
//
// Back-compat: BT_KEEP_DATA_ON_UNINSTALL=true (the v96.2-era opt-in) is
// still honored as a "preserve" signal, in case anyone explicitly set it.
$bt_should_purge = defined( 'BT_PURGE_DATA_ON_UNINSTALL' ) && BT_PURGE_DATA_ON_UNINSTALL;
if ( defined( 'BT_KEEP_DATA_ON_UNINSTALL' ) && BT_KEEP_DATA_ON_UNINSTALL ) {
    $bt_should_purge = false;
}

if ( ! $bt_should_purge ) {
    // Leave a breadcrumb so the next install knows data was preserved on purpose
    update_option( 'bt_data_preserved_on_uninstall_at', time(), false );
    return;
}

// ══════════════════════════════════════════════════
// 1. REMOVE ALL PLUGIN OPTIONS
// ══════════════════════════════════════════════════

$options_to_delete = array(
    // Core settings
    'fxlm_setup_progress',
    'fxlm_activated',
    'fxlm_site_name',
    'fxlm_fx_api_key',
    'fxlm_cg_api_key',
    'fxlm_adsense_id',
    'fxlm_ga_id',
    'fxlm_claude_key',

    // Data stores
    'fxlm_forex_data',
    'fxlm_crypto_data',
    'fxlm_news_items',
    'fxlm_news_updated',
    'fxlm_signal_items',
    'fxlm_fear_greed_data',
    'fxlm_ai_analysis',
    'fxlm_rss_feeds',

    // Social media URLs
    'fxlm_social_facebook',
    'fxlm_social_twitter',
    'fxlm_social_instagram',
    'fxlm_social_youtube',
    'fxlm_social_telegram',
    'fxlm_social_tiktok',
    'fxlm_social_discord',
    'fxlm_social_linkedin',

    // GDPR & compliance
    'fxlm_gdpr_enabled',
    'fxlm_consent_log',
    'fxlm_contact_messages',

    // Newsletter
    'fxlm_subscribers',

    // Security & health
    'fxlm_security_applied',
    'fxlm_security_note',
    'fxlm_xmlrpc_note',
    'fxlm_cron_note',
    'fxlm_health_issues',
    'fxlm_last_health_check',
    'fxlm_update_log',
    'fxlm_autopilot_enabled',

    // Logo
    'fxlm_logo_url',
);

foreach ( $options_to_delete as $option ) {
    delete_option( $option );
}

// ══════════════════════════════════════════════════
// 2. CLEAR ALL CRON JOBS
// ══════════════════════════════════════════════════

$crons_to_clear = array(
    'fxlm_refresh_prices',
    'fxlm_refresh_news',
    'fxlm_refresh_signals',
    'fxlm_ai_posts',
    'fxlm_refresh_fng',
    'fxlm_health_check',
    'fxlm_daily_ai_post',
);

foreach ( $crons_to_clear as $hook ) {
    wp_clear_scheduled_hook( $hook );
}

// ══════════════════════════════════════════════════
// 3. DELETE ALL PAGES CREATED BY PLUGIN
// ══════════════════════════════════════════════════

$page_slugs = array(
    'home', 'forex-charts', 'crypto-markets', 'trading-signals',
    'financial-news', 'market-analysis', 'economic-calendar',
    'recommended-brokers', 'about', 'contact', 'privacy-policy',
    'tools', 'learn',
);

foreach ( $page_slugs as $slug ) {
    $page = get_page_by_path( $slug );
    if ( $page ) {
        wp_delete_post( $page->ID, true ); // true = force delete, skip trash
    }
}

// ══════════════════════════════════════════════════
// 4. DELETE NAVIGATION MENUS
// ══════════════════════════════════════════════════

$menus_to_delete = array( 'Main Navigation', 'Footer Menu' );

foreach ( $menus_to_delete as $menu_name ) {
    $menu = get_term_by( 'name', $menu_name, 'nav_menu' );
    if ( $menu ) {
        // Delete all menu items first
        $items = wp_get_nav_menu_items( $menu->term_id );
        if ( $items ) {
            foreach ( $items as $item ) {
                wp_delete_post( $item->ID, true );
            }
        }
        wp_delete_nav_menu( $menu->term_id );
    }
}

// Reset theme menu locations
set_theme_mod( 'nav_menu_locations', array() );

// ══════════════════════════════════════════════════
// 5. DELETE CATEGORIES CREATED BY PLUGIN
// ══════════════════════════════════════════════════

$categories_to_delete = array(
    'Forex News', 'Crypto News', 'Trading Signals', 'Market Analysis',
    'Education', 'DeFi & Web3', 'NFT News', 'Regulation',
    'Press Releases', 'Bitcoin', 'Ethereum', 'Altcoins',
);

foreach ( $categories_to_delete as $cat_name ) {
    $term = get_term_by( 'name', $cat_name, 'category' );
    if ( $term ) {
        wp_delete_term( $term->term_id, 'category' );
    }
}

// ══════════════════════════════════════════════════
// 6. DELETE AI-GENERATED POSTS
// ══════════════════════════════════════════════════

// Delete posts that match AI blog title patterns
$ai_patterns = array(
    'Bitcoin Price Analysis:%',
    'Ethereum Market Update:%',
    'Forex Weekly:%',
    'Daily Crypto Roundup:%',
    'Altcoin Spotlight:%',
    'DeFi & Web3 Weekly:%',
    'Beginner Guide:%',
    'Market Roundup:%',
);

global $wpdb;
foreach ( $ai_patterns as $pattern ) {
    $posts = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_title LIKE %s AND post_type = 'post'",
            $pattern
        )
    );
    if ( $posts ) {
        foreach ( $posts as $post ) {
            // Delete attached media
            $attachments = get_posts( array(
                'post_type'   => 'attachment',
                'post_parent' => $post->ID,
                'numberposts' => -1,
            ) );
            foreach ( $attachments as $att ) {
                wp_delete_attachment( $att->ID, true );
            }
            wp_delete_post( $post->ID, true );
        }
    }
}

// ══════════════════════════════════════════════════
// 7. CLEAN UP TRANSIENTS
// ══════════════════════════════════════════════════

$wpdb->query(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_bt_contact_%' OR option_name LIKE '_transient_timeout_bt_contact_%'"
);

// ══════════════════════════════════════════════════
// 8. RESET WORDPRESS SETTINGS TO DEFAULTS
// ══════════════════════════════════════════════════

// Restore default reading settings
update_option( 'show_on_front', 'posts' );
delete_option( 'page_on_front' );

// Re-enable Astra default header/footer (our CSS was hiding it)
// This happens automatically when our CSS is no longer loaded

// Restore default Astra settings if they were modified
$astra = get_option( 'astra-settings', array() );
if ( ! empty( $astra ) ) {
    // Remove our custom color overrides but keep other user settings
    $keys_to_remove = array(
        'body-bg-obj', 'text-color', 'link-color', 'link-hover-color',
        'theme-color', 'header-bg-color-responsive', 'header-main-bg-color',
        'footer-bg-color', 'footer-color',
    );
    foreach ( $keys_to_remove as $key ) {
        unset( $astra[ $key ] );
    }
    update_option( 'astra-settings', $astra );
}

// ══════════════════════════════════════════════════
// 9. REMOVE WP RSS AGGREGATOR FEED SOURCES
// ══════════════════════════════════════════════════

$rss_feeds = get_posts( array(
    'post_type'   => 'wprss_feed',
    'numberposts' => -1,
) );
foreach ( $rss_feeds as $feed ) {
    wp_delete_post( $feed->ID, true );
}

// ══════════════════════════════════════════════════
// 10. DISABLE AUTO-UPDATES (restore to WP defaults)
// ══════════════════════════════════════════════════

delete_option( 'auto_update_plugins' );
delete_option( 'auto_update_themes' );
delete_option( 'generate_settings' );
delete_option( 'generate_header_setting' );
delete_option( 'generate_sidebar_widget_setting' );

// ══════════════════════════════════════════════════
// 11. DROP CUSTOM DB TABLES
// ══════════════════════════════════════════════════
// We only reach this point when BT_PURGE_DATA_ON_UNINSTALL=true was set
// in wp-config.php (the early return at the top of this file handles the
// preserve-by-default case). At this point the user has explicitly asked
// to remove all traces.

global $wpdb;
$bt_tables = array(
    $wpdb->prefix . 'bt_price_history',
    $wpdb->prefix . 'bt_news_items',
    $wpdb->prefix . 'bt_signals_history',
    $wpdb->prefix . 'bt_events',
);
foreach ( $bt_tables as $bt_table ) {
    $wpdb->query( "DROP TABLE IF EXISTS `$bt_table`" );
}
delete_option( 'bt_db_version' );

// Section 12 — v98.0: Delete all bt_* option keys created by BT_Migration.
// Also cleans v96.x–v97.x BT_ infrastructure options.
$bt_named_options = array(
    // Migration engine metadata
    'bt_migration_v98_status', 'bt_compat_tested',
    // Sitemap
    'bt_sitemap_xml_cache', 'bt_sitemap_last_built',
    // v97.0 compat notice
    'bt_v97_notice_dismissed',
    // Migrated settings
    'bt_site_name','bt_fx_api_key','bt_cg_api_key','bt_cmc_api_key',
    'bt_openai_key','bt_openai_model','bt_claude_key','bt_claude_model',
    'bt_adsense_id','bt_adsense_slot','bt_ad_frequency','bt_ga_id',
    'bt_og_image','bt_logo_url','bt_twitter_handle','bt_gdpr_enabled',
    'bt_menu_config','bt_ai_provider','bt_ai_review_mode','bt_ai_multiformat',
    'bt_autopilot_enabled','bt_ai_analysis_topic','bt_ai_autopilot_topic',
    'bt_rss_feeds',
    // Migrated data
    'bt_crypto_data','bt_forex_data','bt_forex_rates','bt_forex_prev_rates',
    'bt_forex_prev_updated','bt_fear_greed_data','bt_news_items','bt_news_updated',
    'bt_signal_items','bt_ai_analysis','bt_exchange_details',
    // Migrated user data
    'bt_subscribers','bt_contact_messages','bt_consent_log',
    // Migrated state
    'bt_activated','bt_setup_progress','bt_security_applied','bt_security_note',
    'bt_cron_note','bt_update_log','bt_last_ai_post_date','bt_last_fng_fetch',
    'bt_last_health_check','bt_last_news_fetch','bt_last_signals_fetch',
    'bt_seed_articles_done','bt_forex_seed_done','bt_pages_need_update',
    'bt_pages_version','bt_rewrite_version','bt_health_issues',
    // Migrated social links
    'bt_social_facebook','bt_social_twitter','bt_social_instagram',
    'bt_social_youtube','bt_social_telegram','bt_social_tiktok',
    'bt_social_discord','bt_social_linkedin',
);
foreach ( $bt_named_options as $bt_opt ) {
    delete_option( $bt_opt );
}

// Bulk-delete wildcard bt_* rows from the database.
$bt_wildcard_prefixes = array(
    'bt_exchanges_data_v2_', 'bt_exchanges_rest_', 'bt_coin_detail_',
    'bt_cat_', 'bt_mood_', 'bt_feat_', 'bt_vote_', 'bt_votes_',
    'bt_page_has_tv_',  // CWV TV-preload transient tracking
);
foreach ( $bt_wildcard_prefixes as $pfx ) {
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like( $pfx ) . '%'
        )
    );
}

// Section 13 — v100.0: Clear bt_* cron events + v100 upgrade tracking key.
$bt_cron_hooks = array(
    'bt_refresh_prices', 'bt_refresh_news', 'bt_refresh_signals',
    'bt_refresh_exchanges', 'bt_refresh_fng', 'bt_daily_ai_post',
    'bt_ai_posts', 'bt_health_check', 'bt_regenerate_sitemap',
    'bt_purge_old_data', 'bt_validate_sources', 'bt_verdict_snapshot',
);
foreach ( $bt_cron_hooks as $hook ) {
    wp_clear_scheduled_hook( $hook );
}
delete_option( 'bt_v100_upgrade_done' );

// Done. The plugin is fully uninstalled.
// WordPress will automatically delete the plugin files after this script runs.
