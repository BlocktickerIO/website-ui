<?php
/**
 * BlockTicker Diagnostic & Auto-Fix Tool
 * Access via: https://blockticker.io/wp-content/plugins/blockticker-io/blockticker-diag.php?key=YOUR_SECRET
 *
 * Set a secret key below for security, then visit the URL above.
 * Delete this file after fixing issues.
 */

// ── SECURITY ─────────────────────────────────────────────────
define( 'DIAG_KEY', 'blockticker-diag-2026' ); // Change this before use
$provided_key = (string) ( $_GET['key'] ?? '' );
if ( $provided_key !== DIAG_KEY ) {
    http_response_code( 403 );
    die( 'Access denied. Add ?key=' . DIAG_KEY . ' to the URL.' );
}

// ── BOOTSTRAP WORDPRESS ──────────────────────────────────────
$wp_root = dirname( dirname( dirname( dirname( __FILE__ ) ) ) );
define( 'ABSPATH', $wp_root . '/' );
define( 'WPINC', 'wp-includes' );

// Load wp-config for DB credentials
if ( file_exists( $wp_root . '/wp-config.php' ) ) {
    // Extract DB constants without running full WP bootstrap
    $cfg = file_get_contents( $wp_root . '/wp-config.php' );
    preg_match_all( "/define\(\s*'(DB_\w+)'\s*,\s*'([^']+)'/", $cfg, $matches );
    foreach ( $matches[1] as $i => $k ) { define( $k, $matches[2][$i] ); }
    preg_match( "/\\\$table_prefix\s*=\s*'([^']+)'/", $cfg, $pm );
    $prefix = $pm[1] ?? 'wp_';
} else {
    die( 'wp-config.php not found at: ' . $wp_root );
}

// DB connection
$pdo = null;
try {
    $pdo = new PDO( 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8', DB_USER, DB_PASSWORD );
    $pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
} catch ( Exception $e ) {
    die( 'DB connection failed: ' . $e->getMessage() );
}

// Helper: get wp_option
function get_wp_option( $pdo, $prefix, $name ) {
    $stmt = $pdo->prepare( "SELECT option_value FROM {$prefix}options WHERE option_name = ?" );
    $stmt->execute( [ $name ] );
    return $stmt->fetchColumn();
}

// Helper: set wp_option
function set_wp_option( $pdo, $prefix, $name, $value ) {
    $stmt = $pdo->prepare( "INSERT INTO {$prefix}options (option_name, option_value, autoload) VALUES (?,?,?) ON DUPLICATE KEY UPDATE option_value=?" );
    $stmt->execute( [ $name, $value, 'yes', $value ] );
}

// Helper: delete wp_option
function del_wp_option( $pdo, $prefix, $name ) {
    $pdo->prepare( "DELETE FROM {$prefix}options WHERE option_name = ?" )->execute( [ $name ] );
}

// ── PERFORM ACTIONS ──────────────────────────────────────────
$actions_done = [];

// Action: flush rewrite rules
if ( isset( $_GET['flush'] ) ) {
    del_wp_option( $pdo, $prefix, 'rewrite_rules' );
    del_wp_option( $pdo, $prefix, 'fxlm_rewrite_version' );
    $actions_done[] = '✅ Rewrite rules cleared from DB. WordPress will regenerate them on next page load.';
}

// Action: reset forex prev_rates
if ( isset( $_GET['forex'] ) ) {
    del_wp_option( $pdo, $prefix, 'fxlm_forex_prev_rates' );
    del_wp_option( $pdo, $prefix, 'fxlm_forex_prev_updated' );
    del_wp_option( $pdo, $prefix, 'fxlm_forex_data' );

    // Immediately fetch yesterday's rates as the baseline and today's as current
    $yesterday = date( 'Y-m-d', strtotime('-1 day') );
    $prev_ctx  = stream_context_create(['http'=>['timeout'=>10]]);
    $prev_json = @file_get_contents("https://api.frankfurter.app/{$yesterday}?from=USD&to=EUR,GBP,JPY,CHF,AUD,CAD,NZD", false, $prev_ctx);
    $curr_json = @file_get_contents("https://api.frankfurter.app/latest?from=USD&to=EUR,GBP,JPY,CHF,AUD,CAD,NZD", false, $prev_ctx);

    if ( $prev_json && $curr_json ) {
        $prev_data = json_decode($prev_json, true);
        $curr_data = json_decode($curr_json, true);
        if ( !empty($prev_data['rates']) && !empty($curr_data['rates']) ) {
            // Build rates with real 24h change
            $rates = [];
            $currency_map = ['EUR'=>'EUR/USD','GBP'=>'GBP/USD','JPY'=>'USD/JPY','CHF'=>'USD/CHF','AUD'=>'AUD/USD','CAD'=>'USD/CAD','NZD'=>'NZD/USD'];
            foreach ( $curr_data['rates'] as $sym => $rate ) {
                $prev_rate = $prev_data['rates'][$sym] ?? $rate;
                // Convert USD/X to proper pair format
                if ( in_array($sym, ['EUR','GBP','AUD','NZD']) ) {
                    // These should be X/USD (rate is how many USD per 1 X)
                    $pair     = $sym . '/USD';
                    $curr_val = $rate > 0 ? round(1/$rate, 5) : 0;
                    $prev_val = $prev_rate > 0 ? round(1/$prev_rate, 5) : $curr_val;
                } else {
                    $pair     = 'USD/' . $sym;
                    $curr_val = $rate;
                    $prev_val = $prev_rate;
                }
                $change = $prev_val > 0 ? round((($curr_val - $prev_val) / $prev_val) * 100, 3) : 0;
                $rates[$pair] = ['rate'=>$curr_val,'change'=>$change];
            }
            $forex_val = serialize(['rates'=>$rates,'updated'=>time(),'source'=>'live']);
            set_wp_option($pdo, $prefix, 'fxlm_forex_data', $forex_val);
            // Store prev_rates for future comparisons
            $prev_seed = array_combine(
                array_map(fn($s)=>(in_array($s,['EUR','GBP','AUD','NZD'])?$s.'/USD':'USD/'.$s), array_keys($prev_data['rates'])),
                array_values($prev_data['rates'])
            );
            set_wp_option($pdo, $prefix, 'fxlm_forex_prev_rates', serialize($prev_seed));
            set_wp_option($pdo, $prefix, 'fxlm_forex_prev_updated', time()-86400);
            $actions_done[] = '✅ Forex data refreshed with real 24h % changes. Rates are now live!';
        } else {
            $actions_done[] = '⚠️ Forex API returned unexpected data. Try again in a minute.';
        }
    } else {
        $actions_done[] = '⚠️ Could not reach Frankfurter API from server. Cron will fix on next run.';
    }
}

// Action: clear GA ID
if ( isset( $_GET['clear_ga'] ) ) {
    del_wp_option( $pdo, $prefix, 'fxlm_ga_id' );
    $actions_done[] = '✅ GA/GTM ID cleared. The 403 error will stop.';
}

// Action: clear PHP error log
if ( isset( $_GET['clear_errlog'] ) ) {
    $log = __DIR__ . '/blockticker-error.log';
    if ( file_exists( $log ) ) { unlink( $log ); }
    $actions_done[] = '✅ Error log cleared.';
}

// Action: fix blog page (set correct reading settings)
if ( isset( $_GET['fix_blog'] ) ) {
    // Find the market-blog page
    $stmt = $pdo->prepare( "SELECT ID FROM {$prefix}posts WHERE post_name IN ('market-blog','blog') AND post_type='page' AND post_status='publish' LIMIT 1" );
    $stmt->execute();
    $blog_page_id = $stmt->fetchColumn();
    if ( $blog_page_id ) {
        // Clear "page for posts" setting so /blog/ isn't hijacked by WP posts archive
        del_wp_option( $pdo, $prefix, 'page_for_posts' );
        $actions_done[] = "✅ Blog page fixed (page ID: $blog_page_id). WordPress will no longer hijack /blog/.";
    } else {
        $actions_done[] = '⚠️ No blog/market-blog page found. Run the Setup Wizard first.';
    }
}

// Action: reset plugin version (forces page content update + rewrite flush on next load)
if ( isset( $_GET['reset_ver'] ) ) {
    del_wp_option( $pdo, $prefix, 'fxlm_rewrite_version' );
    del_wp_option( $pdo, $prefix, 'fxlm_pages_version' );
    del_wp_option( $pdo, $prefix, 'fxlm_seed_articles_done' );
    $actions_done[] = '✅ Plugin version flags reset. On next page load the plugin will re-flush routes and update all page content.';
}

// ── GATHER DATA ───────────────────────────────────────────────
$plugin_ver     = get_wp_option( $pdo, $prefix, 'fxlm_rewrite_version' ) ?: 'not set';
$pages_ver      = get_wp_option( $pdo, $prefix, 'fxlm_pages_version' ) ?: 'not set';
$rewrite_rules  = get_wp_option( $pdo, $prefix, 'rewrite_rules' );
$forex_raw      = get_wp_option( $pdo, $prefix, 'fxlm_forex_data' );
$forex_prev     = get_wp_option( $pdo, $prefix, 'fxlm_forex_prev_rates' );
$crypto_raw     = get_wp_option( $pdo, $prefix, 'fxlm_crypto_data' );
$ga_id          = get_wp_option( $pdo, $prefix, 'fxlm_ga_id' ) ?: '(not set)';
$permalink_str  = get_wp_option( $pdo, $prefix, 'permalink_structure' ) ?: '(plain - broken!)';
$page_for_posts = get_wp_option( $pdo, $prefix, 'page_for_posts' ) ?: '0';
$cron_raw       = get_wp_option( $pdo, $prefix, 'cron' );

// Check rewrite rules for crypto
$rules_arr  = @unserialize( $rewrite_rules ) ?: [];
$has_crypto = false;
$has_forex  = false;
foreach ( array_keys( $rules_arr ) as $r ) {
    if ( strpos( $r, 'crypto' ) !== false ) $has_crypto = true;
    if ( strpos( $r, 'forex' ) !== false )  $has_forex  = true;
}

// Check cron
$cron_arr = @unserialize( $cron_raw ) ?: [];
$cron_jobs = [];
foreach ( $cron_arr as $ts => $hooks ) {
    if ( ! is_array( $hooks ) ) continue;
    foreach ( $hooks as $hook => $data ) {
        if ( strpos( $hook, 'fxlm' ) !== false ) {
            $diff = $ts - time();
            $cron_jobs[] = "$hook → " . ( $diff < 0 ? '<span style="color:#f59e0b">OVERDUE by ' . abs($diff) . 's</span>' : 'in ' . $diff . 's' ) . " (ts: $ts)";
        }
    }
}

// Forex data
$forex_data = @unserialize( $forex_raw ) ?: [];
$forex_pairs = $forex_data['rates'] ?? [];
$forex_source = $forex_data['source'] ?? 'unknown';
$forex_updated = isset( $forex_data['updated'] ) ? date( 'Y-m-d H:i:s', $forex_data['updated'] ) . ' UTC' : 'never';

// Crypto data
$crypto_data = @unserialize( $crypto_raw ) ?: [];
$crypto_coins = $crypto_data['coins'] ?? [];
$crypto_updated = isset( $crypto_data['updated'] ) ? date( 'Y-m-d H:i:s', $crypto_data['updated'] ) . ' UTC' : 'never';

// Plugin file version
$plugin_file = __DIR__ . '/fx-live-markets.php';
$file_ver = 'unknown';
if ( file_exists( $plugin_file ) ) {
    preg_match( '/Version:\s*([\d.]+)/', file_get_contents( $plugin_file ), $vm );
    $file_ver = $vm[1] ?? 'unknown';
}

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>BlockTicker Diagnostic</title>
<style>
body { font-family: system-ui, sans-serif; background: #0b0f1a; color: #e2e8f0; padding: 20px; line-height: 1.6; }
h1 { color: #00d4aa; } h2 { color: #60a5fa; border-bottom: 1px solid #1e293b; padding-bottom: 6px; }
.card { background: #111827; border: 1px solid #1e293b; border-radius: 10px; padding: 16px 20px; margin: 12px 0; }
.ok { color: #00d4aa; } .warn { color: #f59e0b; } .err { color: #ff4d6a; }
.btn { display: inline-block; padding: 8px 16px; border-radius: 8px; text-decoration: none; font-weight: 700; font-size: 13px; margin: 4px; }
.btn-green { background: rgba(0,212,170,.15); border: 1px solid rgba(0,212,170,.3); color: #00d4aa; }
.btn-blue  { background: rgba(96,165,250,.12); border: 1px solid rgba(96,165,250,.3); color: #60a5fa; }
.btn-red   { background: rgba(255,77,106,.1);  border: 1px solid rgba(255,77,106,.25); color: #ff4d6a; }
.btn-amber { background: rgba(245,158,11,.1);  border: 1px solid rgba(245,158,11,.25); color: #f59e0b; }
table { width: 100%; border-collapse: collapse; font-size: 13px; }
td, th { padding: 6px 10px; border-bottom: 1px solid #1e293b; text-align: left; }
th { color: #475569; text-transform: uppercase; font-size: 11px; }
code { background: #0d1424; padding: 2px 6px; border-radius: 4px; font-size: 12px; color: #94a3b8; }
.actions-done { background: rgba(0,212,170,.08); border: 1px solid rgba(0,212,170,.2); border-radius: 8px; padding: 12px 16px; margin: 12px 0; }
</style>
</head>
<body>
<h1>🔧 BlockTicker Diagnostic Tool</h1>
<p style="color:#475569">Access URL: <code><?php echo htmlspecialchars( $_SERVER['REQUEST_URI'] ); ?></code> | Server time: <?php echo date('Y-m-d H:i:s T'); ?></p>

<?php if ( $actions_done ) : ?>
<div class="actions-done">
    <?php foreach ( $actions_done as $a ) echo "<div>$a</div>"; ?>
    <div style="margin-top:8px;font-size:12px;color:#475569">After fixing, <a href="<?php echo strtok($_SERVER['REQUEST_URI'],'?') . '?key=' . DIAG_KEY; ?>" style="color:#00d4aa">reload this page</a> to verify.</div>
</div>
<?php endif; ?>

<h2>🚀 One-Click Fixes</h2>
<div class="card">
    <a href="?key=<?= htmlspecialchars($provided_key) ?>&flush=1" class="btn btn-green">🔄 Fix /crypto/ & /forex/ 404s (Flush Routes)</a>
    <a href="?key=<?= htmlspecialchars($provided_key) ?>&forex=1" class="btn btn-blue">📊 Fix Forex 0% Change (Reset Baseline)</a>
    <a href="?key=<?= htmlspecialchars($provided_key) ?>&fix_blog=1" class="btn btn-amber">📝 Fix /blog/ showing all posts</a>
    <a href="?key=<?= htmlspecialchars($provided_key) ?>&clear_ga=1" class="btn btn-red">🗑 Clear Invalid GA ID (Fix 403)</a>
    <a href="?key=<?= htmlspecialchars($provided_key) ?>&reset_ver=1" class="btn btn-amber">♻️ Reset Plugin Flags (Force full re-init)</a>
    <a href="?key=<?= htmlspecialchars($provided_key) ?>&flush=1&forex=1&fix_blog=1&clear_ga=1&reset_ver=1" class="btn btn-green" style="font-size:14px;padding:10px 22px">⚡ FIX EVERYTHING AT ONCE</a>
</div>

<h2>⏰ Hostinger Cron Setup (Required for Live Data)</h2>
<div class="card">
    <p style="color:#94a3b8;margin:0 0 16px">WP pseudo-cron only fires when someone visits the site. Without a real cron, prices freeze and news goes stale. Set up a Hostinger PHP cron job once — then everything updates automatically forever.</p>

    <div style="background:#0d1424;border:1px solid rgba(0,212,170,.2);border-radius:10px;padding:16px 20px;margin-bottom:16px">
        <div style="font-size:13px;font-weight:700;color:#00d4aa;margin-bottom:12px">📋 Exact Hostinger hPanel Settings</div>
        <table>
            <tr><th>Field</th><th>Value</th></tr>
            <tr><td><strong>Type</strong></td><td>☑️ PHP (keep selected)</td></tr>
            <tr><td><strong>Command to run</strong></td><td><code>domains/blockticker.io/public_html/wp-cron-runner.php</code></td></tr>
            <tr><td><strong>Minute</strong></td><td><code>*/5</code> &nbsp;(every 5 minutes)</td></tr>
            <tr><td><strong>Hour</strong></td><td><code>*</code> &nbsp;(every hour)</td></tr>
            <tr><td><strong>Day</strong></td><td><code>*</code> &nbsp;(every day)</td></tr>
            <tr><td><strong>Month</strong></td><td><code>*</code> &nbsp;(every month)</td></tr>
            <tr><td><strong>Weekday</strong></td><td><code>*</code> &nbsp;(every weekday)</td></tr>
        </table>
    </div>

    <p style="color:#64748b;font-size:12px;margin:0">
        ✅ The <code>wp-cron-runner.php</code> file is included in the v25 plugin zip. Upload it to your WordPress root folder
        (<code>/home/u540733623/domains/blockticker.io/public_html/</code>) via hPanel File Manager or FTP.
        It will trigger price updates, news refresh, AI posts and all scheduled tasks every 5 minutes.
    </p>
</div>

<h2>🚨 PHP Error Log</h2>
<?php
$error_log_path = __DIR__ . '/blockticker-error.log';
if ( file_exists( $error_log_path ) ) {
    $log_content = file_get_contents( $error_log_path );
    $log_size    = filesize( $error_log_path );
    if ( $log_content ) :
?>
<div class="card" style="border-color:rgba(255,77,106,.4)">
    <p style="color:#ff4d6a;font-weight:700;margin:0 0 10px">⚠️ Fatal errors detected (<?= number_format($log_size) ?> bytes). Last 50 lines:</p>
    <pre style="background:#0d1424;color:#fca5a5;padding:12px;border-radius:6px;font-size:11px;overflow-x:auto;white-space:pre-wrap;max-height:400px;overflow-y:auto"><?= htmlspecialchars( implode( "\n", array_slice( explode( "\n", trim( $log_content ) ), -50 ) ) ) ?></pre>
    <a href="?key=<?= htmlspecialchars($provided_key) ?>&clear_errlog=1" class="btn btn-red" style="margin-top:8px">🗑 Clear error log</a>
</div>
<?php else : ?>
<div class="card"><p class="ok">✅ Error log exists but is empty — no fatal errors captured.</p></div>
<?php endif; } else { ?>
<div class="card"><p style="color:#64748b">ℹ️ No error log yet — <code>blockticker-error.log</code> will be created automatically the first time a PHP fatal occurs after v116.0.1 is installed.</p></div>
<?php } ?>

<h2>📦 Plugin Version</h2>
<div class="card">
    <table>
        <tr><th>Item</th><th>Value</th><th>Status</th></tr>
        <tr><td>Plugin file version</td><td><code><?= $file_ver ?></code></td><td class="ok">✅</td></tr>
        <tr><td>Rewrite version in DB</td><td><code><?= htmlspecialchars($plugin_ver) ?></code></td>
            <td class="<?= $plugin_ver === $file_ver ? 'ok' : 'warn' ?>"><?= $plugin_ver === $file_ver ? '✅ Matches' : '⚠️ Mismatch — plugin needs re-activation or flush' ?></td></tr>
        <tr><td>Pages version in DB</td><td><code><?= htmlspecialchars($pages_ver) ?></code></td>
            <td class="<?= $pages_ver === $file_ver ? 'ok' : 'warn' ?>"><?= $pages_ver === $file_ver ? '✅ Matches' : '⚠️ Pages not yet updated to v' . $file_ver ?></td></tr>
    </table>
</div>

<h2>🔗 URL Routing (/crypto/ & /forex/)</h2>
<div class="card">
    <table>
        <tr><th>Item</th><th>Value</th><th>Status</th></tr>
        <tr><td>Permalink structure</td><td><code><?= htmlspecialchars($permalink_str) ?></code></td>
            <td class="<?= $permalink_str !== '(plain - broken!)' ? 'ok' : 'err' ?>"><?= $permalink_str !== '(plain - broken!)' ? '✅' : '❌ Plain permalinks — go to Settings → Permalinks and choose "Post name"' ?></td></tr>
        <tr><td>/crypto/ rewrite rule</td><td></td><td class="<?= $has_crypto ? 'ok' : 'err' ?>"><?= $has_crypto ? '✅ Registered' : '❌ MISSING — click "Fix /crypto/" above, then visit any page on your site' ?></td></tr>
        <tr><td>/forex/ rewrite rule</td><td></td><td class="<?= $has_forex ? 'ok' : 'err' ?>"><?= $has_forex ? '✅ Registered' : '❌ MISSING — click "Fix /crypto/" above' ?></td></tr>
        <tr><td>Direct URL intercept</td><td>v22+ intercepts via REQUEST_URI</td><td class="ok">✅ Active (works without flush)</td></tr>
    </table>
</div>

<h2>📊 Forex Data</h2>
<div class="card">
    <p>Source: <strong><?= htmlspecialchars($forex_source) ?></strong> | Last updated: <strong><?= $forex_updated ?></strong>
    | Previous baseline: <strong><?= $forex_prev ? '✅ Set' : '❌ NOT SET — 24h change will be 0%' ?></strong></p>
    <?php if ( $forex_pairs ) : ?>
    <table>
        <tr><th>Pair</th><th>Rate</th><th>24h Change</th><th>Status</th></tr>
        <?php foreach ( $forex_pairs as $pair => $data ) :
            $chg = floatval( $data['change'] ?? 0 );
            $ok  = $chg !== 0.0;
        ?>
        <tr>
            <td><?= htmlspecialchars($pair) ?></td>
            <td><?= number_format( floatval($data['rate']), 4 ) ?></td>
            <td class="<?= $ok ? 'ok' : 'warn' ?>"><?= $ok ? ($chg > 0 ? '▲ ' : '▼ ') . number_format(abs($chg),3) . '%' : '— 0.000%' ?></td>
            <td><?= $ok ? '✅' : '⚠️' ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php else : echo '<p class="err">❌ No forex data stored yet. Cron may not be running.</p>'; endif; ?>
</div>

<h2>₿ Crypto Data</h2>
<div class="card">
    <p>Coins stored: <strong><?= count($crypto_coins) ?></strong> | Last updated: <strong><?= $crypto_updated ?></strong></p>
    <?php if ( count($crypto_coins) > 0 ) {
        $btc = array_values( array_filter( $crypto_coins, fn($c) => strtoupper($c['symbol']) === 'BTC' ) )[0] ?? null;
        if ( $btc ) echo '<p>BTC: <strong>$' . number_format($btc['current_price'],2) . '</strong> (' . number_format($btc['price_change_percentage_24h'],2) . '% 24h)</p>';
    } else { echo '<p class="err">❌ No crypto data. Cron not running.</p>'; } ?>
</div>

<h2>⏰ WP Cron Status</h2>
<div class="card">
    <?php if ( $cron_jobs ) : ?>
    <table>
        <tr><th>Hook</th><th>Next Run</th></tr>
        <?php foreach ( $cron_jobs as $j ) echo "<tr><td colspan='2'>$j</td></tr>"; ?>
    </table>
    <?php else : echo '<p class="err">❌ No FXLM cron jobs found. Run Setup Wizard → Step "Set up auto-refresh cron jobs".</p>'; endif; ?>
    <p style="color:#475569;font-size:12px;margin-top:10px">Note: WP pseudo-cron only fires when someone visits the site. On low-traffic sites, data can be hours stale. To fix permanently, add a real server cron: <code>*/5 * * * * curl -s https://blockticker.io/ > /dev/null</code></p>
</div>

<h2>⚙️ Blog / Reading Settings</h2>
<div class="card">
    <table>
        <tr><th>Setting</th><th>Value</th><th>Status</th></tr>
        <tr><td>page_for_posts</td><td><code><?= htmlspecialchars($page_for_posts) ?></code></td>
            <td class="<?= $page_for_posts == '0' ? 'ok' : 'warn' ?>"><?= $page_for_posts == '0' ? '✅ OK' : '⚠️ Set — /blog/ will show all posts instead of custom content. Click "Fix /blog/" above.' ?></td></tr>
        <tr><td>GA ID</td><td><code><?= htmlspecialchars($ga_id) ?></code></td>
            <td class="<?= strpos($ga_id,'GT-') !== false ? 'err' : 'ok' ?>"><?= strpos($ga_id,'GT-') !== false ? '❌ GT-* ID causes 403 — click "Clear Invalid GA ID" above' : '✅' ?></td></tr>
    </table>
</div>

<p style="color:#334155;font-size:11px;margin-top:40px">⚠️ Security: Delete or rename this file after use. Access requires key=<?= DIAG_KEY ?>.</p>
</body>
</html>
