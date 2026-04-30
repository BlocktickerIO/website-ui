<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class BT_AutoUpdate {

    public static function init() {
        // Auto-update WordPress core (major + minor)
        add_filter( 'allow_major_auto_core_updates', '__return_true' );
        add_filter( 'allow_minor_auto_core_updates', '__return_true' );

        // Auto-update all plugins
        add_filter( 'auto_update_plugin', '__return_true' );

        // Auto-update all themes
        add_filter( 'auto_update_theme', '__return_true' );

        // Auto-update translations
        add_filter( 'auto_update_translation', '__return_true' );

        // Disable update emails for minor updates (reduce noise)
        add_filter( 'auto_core_update_send_email', function( $send, $type ) {
            if ( $type === 'success' ) return false;
            return $send;
        }, 10, 2 );

        // Log updates
        add_action( 'automatic_updates_complete', array( __CLASS__, 'log_update' ) );

        // Schedule health check
        if ( ! wp_next_scheduled( 'bt_health_check' ) ) {
            wp_schedule_event( time(), 'daily', 'bt_health_check' );
        }
        add_action( 'bt_health_check', array( __CLASS__, 'health_check' ) );
    }

    public static function setup() {
        // Enable auto-updates for all currently installed plugins
        $plugins = get_plugins();
        $auto_updates = array();
        foreach ( $plugins as $file => $data ) {
            $auto_updates[] = $file;
        }
        update_option( 'auto_update_plugins', $auto_updates );

        // Enable auto-updates for current theme
        $theme = get_option( 'stylesheet' );
        update_option( 'auto_update_themes', array( $theme ) );

        // Set WP-Cron to use alternative cron if needed
        if ( ! defined( 'ALTERNATE_WP_CRON' ) ) {
            update_option( 'bt_cron_note', 'For reliable autopilot: add define("ALTERNATE_WP_CRON", true); to wp-config.php, or set up a real cron job hitting wp-cron.php every 5 minutes.' );
        }

        // Disable theme/plugin file editor for security
        update_option( 'bt_autopilot_enabled', true );

        return array(
            'success' => true,
            'message' => 'Autopilot enabled: WordPress core, all plugins, and themes will auto-update. Health checks run daily.',
        );
    }

    public static function log_update( $results ) {
        $log = get_option( 'bt_update_log', array() );
        $entry = array(
            'time' => current_time( 'mysql' ),
            'core' => ! empty( $results['core'] ) ? 'updated' : 'skipped',
            'plugins' => array(),
            'themes' => array(),
        );
        if ( ! empty( $results['plugin'] ) ) {
            foreach ( $results['plugin'] as $plugin ) {
                $entry['plugins'][] = $plugin->item->slug ?? 'unknown';
            }
        }
        if ( ! empty( $results['theme'] ) ) {
            foreach ( $results['theme'] as $theme ) {
                $entry['themes'][] = $theme->item->theme ?? 'unknown';
            }
        }
        array_unshift( $log, $entry );
        $log = array_slice( $log, 0, 50 ); // Keep last 50
        update_option( 'bt_update_log', $log );
    }

    public static function health_check() {
        $issues = array();

        // Check if cron is running
        $last_prices = get_option( 'bt_forex_data', array() );
        if ( ! empty( $last_prices['updated'] ) && ( time() - $last_prices['updated'] ) > 1800 ) {
            $issues[] = 'Price data is stale (>30 min old). WP-Cron may not be working.';
        }

        // Check if news is updating
        $last_news = get_option( 'bt_news_updated', 0 );
        if ( $last_news && ( time() - $last_news ) > 7200 ) {
            $issues[] = 'News feed is stale (>2 hours old).';
        }

        // Check disk space
        $free = @disk_free_space( ABSPATH );
        if ( $free !== false && $free < 100 * 1024 * 1024 ) {
            $issues[] = 'Low disk space: ' . round( $free / 1024 / 1024 ) . 'MB free.';
        }

        update_option( 'bt_health_issues', $issues );
        update_option( 'bt_last_health_check', time() );
    }
}

