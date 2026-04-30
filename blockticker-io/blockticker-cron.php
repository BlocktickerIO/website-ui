<?php
/**
 * BlockTicker Cron Runner — For Hostinger PHP Cron Jobs
 *
 * Add this in Hostinger hPanel → Advanced → Cron Jobs:
 *   Type:    PHP
 *   Command: public_html/wp-content/plugins/blockticker-io/blockticker-cron.php
 *   Minute:  Every 5 minutes (or use -slash-5 in Custom mode)
 *
 * This file boots WordPress CLI-style and fires all data refresh hooks.
 * It is safe to run via CLI (php file.php) or via HTTP.
 */

// ── Security: block direct browser access unless CLI or has secret key ──────
$is_cli = ( php_sapi_name() === 'cli' || defined('STDIN') );

// Security: allow CLI (server cron) or valid key from wp-config.php
// Add to wp-config.php: define('BT_CRON_KEY', 'your-random-secret');
if ( ! $is_cli ) {
    $provided = $_GET['key'] ?? $_SERVER['HTTP_X_BT_CRON_KEY'] ?? '';
    $expected = defined('BT_CRON_KEY') ? BT_CRON_KEY : '';
    if ( empty($expected) || $provided !== $expected ) {
        http_response_code(403);
        exit("Forbidden. Add define('BT_CRON_KEY','secret') to wp-config.php");
    }
}

// Recommended usage (Hostinger PHP cron - no key needed from CLI):
// /usr/bin/php /home/u540733623/public_html/wp-content/plugins/blockticker-io/blockticker-cron.php

// ── Find WordPress root ───────────────────────────────────────────────────────
$possible_roots = [
    dirname(dirname(dirname(dirname(dirname(__FILE__))))), // Standard: plugins/blockticker-io/ → public_html/
    '/home/' . get_current_user() . '/public_html',
    '/var/www/html',
];

$wp_root = null;
foreach ($possible_roots as $path) {
    if (file_exists($path . '/wp-load.php')) {
        $wp_root = $path;
        break;
    }
}

if (!$wp_root) {
    // Last resort: search up from this file
    $dir = __DIR__;
    for ($i = 0; $i < 8; $i++) {
        if (file_exists($dir . '/wp-load.php')) { $wp_root = $dir; break; }
        $dir = dirname($dir);
    }
}

if (!$wp_root) {
    fwrite(STDERR, "BlockTicker Cron: Could not find WordPress root.\n");
    exit(1);
}

// ── Boot WordPress ────────────────────────────────────────────────────────────
define('DOING_CRON', true);
define('DOING_AJAX', false); // Prevent admin-ajax interference

// Suppress HTML output from WordPress
ob_start();
require_once $wp_root . '/wp-load.php';
ob_end_clean();

// ── Run all BlockTicker refresh tasks ─────────────────────────────────────────
$start   = microtime(true);
$results = [];

// 1. Forex prices (every run — fast, Frankfurter API)
if (class_exists('BT_Widgets')) {
    BT_Widgets::fetch_forex_prices();
    $results[] = '✅ Forex prices refreshed';
} else {
    $results[] = '⚠️ BT_Widgets not found — is BlockTicker plugin active?';
}

// 2. Crypto prices (every run — CoinGecko, ~100 coins)
if (class_exists('BT_Widgets')) {
    BT_Widgets::fetch_crypto_prices();
    $results[] = '✅ Crypto prices refreshed';
}

// 3. News feeds (every 60 min to avoid rate limits)
$last_news = get_option('bt_last_news_fetch', 0);
if ((time() - $last_news) >= 3600) {
    if (class_exists('BT_RSS')) {
        BT_RSS::fetch_all_feeds();
        update_option('bt_last_news_fetch', time());
        $results[] = '✅ News feeds refreshed (' . count(get_option('bt_news_items', [])) . ' items)';
    }
} else {
    $results[] = '⏭ News: skipped (refreshed ' . round((time() - $last_news)/60) . 'min ago)';
}

// 4. Fear & Greed Index (every 60 min)
$last_fng = get_option('bt_last_fng_fetch', 0);
if ((time() - $last_fng) >= 3600) {
    if (class_exists('BT_Tools')) {
        BT_Tools::fetch_fear_greed();
        update_option('bt_last_fng_fetch', time());
        $results[] = '✅ Fear & Greed Index refreshed';
    }
} else {
    $results[] = '⏭ FNG: skipped (refreshed ' . round((time() - $last_fng)/60) . 'min ago)';
}

// 5. Trading signals (every 15 min)
$last_sig = get_option('bt_last_signals_fetch', 0);
if ((time() - $last_sig) >= 900) {
    if (class_exists('BT_RSS')) {
        BT_RSS::fetch_signal_feeds();
        update_option('bt_last_signals_fetch', time());
        $results[] = '✅ Trading signals refreshed';
    }
} else {
    $results[] = '⏭ Signals: skipped';
}

// 6. Daily AI blog post (once per day at 08:00 UTC)
$last_post = get_option('bt_last_ai_post_date', '');
$today     = date('Y-m-d');
$hour_utc  = (int) gmdate('G');
if ($last_post !== $today && $hour_utc >= 8 && class_exists('BT_AIBlog')) {
    BT_AIBlog::generate_daily_post();
    update_option('bt_last_ai_post_date', $today);
    $results[] = '✅ AI blog post generated for ' . $today;
}

// ── Output results ────────────────────────────────────────────────────────────
$elapsed = round(microtime(true) - $start, 2);
$output  = '[' . date('Y-m-d H:i:s T') . '] BlockTicker Cron (' . $elapsed . 's)' . "\n";
foreach ($results as $r) {
    $output .= '  ' . $r . "\n";
}

if ($is_cli) {
    echo $output;
} else {
    header('Content-Type: text/plain');
    echo $output;
}

// Log to WP error log for debugging
error_log(str_replace("\n", ' | ', trim($output)));
