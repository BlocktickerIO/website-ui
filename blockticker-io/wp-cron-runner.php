<?php
/**
 * BlockTicker — WordPress Cron Runner
 * 
 * Place this file in your WordPress root:
 *   /home/u540733623/domains/blockticker.io/public_html/wp-cron-runner.php
 *
 * Then in Hostinger hPanel → Cron Jobs → Create New:
 *   Type:    PHP
 *   Command: domains/blockticker.io/public_html/wp-cron-runner.php
 *   Minute:  Every 5 minutes (use the cron expression -slash-5)
 *   Hour:    Every hour (*)
 *   Day:     Every day (*)
 *   Month:   Every month (*)
 *   Weekday: Every weekday (*)
 */

// Load WordPress without full request handling
define( 'DOING_CRON', true );
define( 'DOING_AJAX', false );

// Find WordPress root (this file sits in WP root)
$wp_root = dirname( __FILE__ );
if ( ! file_exists( $wp_root . '/wp-load.php' ) ) {
    die( 'wp-load.php not found. Check file placement.' );
}

// Suppress output for cron context
ob_start();

// Bootstrap WordPress
require_once $wp_root . '/wp-load.php';

// Run all due cron events
if ( defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON ) {
    require_once ABSPATH . 'wp-cron.php';
} else {
    spawn_cron();
    // Also run our specific hooks directly for reliability
    do_action( 'fxlm_refresh_prices' );
}

// Manually fire overdue BlockTicker cron hooks
$crons = _get_cron_array();
$now   = time();
$ran   = [];

foreach ( $crons as $timestamp => $cron ) {
    if ( $timestamp > $now ) continue;
    foreach ( $cron as $hook => $events ) {
        if ( strpos( $hook, 'fxlm' ) === false ) continue;
        foreach ( $events as $key => $event ) {
            if ( ! isset( $ran[$hook] ) ) {
                do_action_ref_array( $hook, $event['args'] );
                $ran[$hook] = true;
                // Reschedule
                if ( $event['schedule'] ) {
                    $schedules = wp_get_schedules();
                    $interval  = $schedules[$event['schedule']]['interval'] ?? 3600;
                    wp_reschedule_event( $timestamp, $event['schedule'], $hook, $event['args'] );
                }
                wp_unschedule_event( $timestamp, $hook, $event['args'] );
            }
        }
    }
}

ob_end_clean();
echo date('Y-m-d H:i:s') . " — Cron OK\n";
