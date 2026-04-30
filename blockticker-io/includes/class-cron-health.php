<?php
/**
 * BT_Cron_Health — drop-in observability for wp_schedule_event hooks.
 *
 * Why this class exists
 * ---------------------
 * Up through v119.28.31, only the daily AI-post pipeline had structured
 * observability (BT_Autopilot_Health, since v119.27.0). Every other cron
 * job — price refresh, signal tracker tick, RSS source validator, sitemap
 * regeneration, old-data purge — failed silently. When CoinGecko was rate-
 * limited or a DB query timed out, the only signal was a vanished cron
 * registration or a stale option, both invisible without SSH.
 *
 * This class generalizes the autopilot pattern:
 *
 *   1. WATCH — register any wp_schedule_event hook with an SLA. The class
 *      wraps add_action() at priorities 1 + 99 so it captures both start
 *      and finish of every run.
 *
 *   2. RING-BUFFER LOG — last 50 runs per hook stored in an option (with
 *      autoload=false so this never bloats the wp_options autoload query).
 *      Each entry: started_at, finished_at, duration_ms, memory_peak, status.
 *
 *   3. WATCHDOG — runs every 5 minutes, compares each watched hook's last-run
 *      timestamp against its SLA, flags breaches, and sends a deduped admin
 *      email when any CRITICAL job blows its SLA.
 *
 *   4. ADMIN PANEL — single screen at BlockTicker → Cron Health showing
 *      every watched hook with last-run age, status, mean duration, and a
 *      "Run now" button for ad-hoc debugging.
 *
 * What gets watched in this release (v119.28.32)
 * -----------------------------------------------
 *   - bt_refresh_prices         5 min   CRITICAL (entire UI shows zeros without it)
 *   - bt_signal_tracker_tick    1 hour  CRITICAL (track-record depends on it)
 *   - bt_purge_old_data         1 day            (silent failure → unbounded table growth)
 *   - bt_regenerate_sitemap     1 day            (silent failure → SEO impact)
 *   - bt_score_news_sentiment   12 hour          (silent failure → stale sentiment composite)
 *
 * Not watched yet (covered by BT_Autopilot_Health):
 *   - bt_daily_ai_post
 *
 * @since v119.28.32
 * @package BlockTicker
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BT_Cron_Health {

    /** Per-hook log option prefix. Each watched hook gets its own option. */
    const LOG_PREFIX = 'bt_cron_log_';

    /** Maximum number of run entries to retain per hook. */
    const LOG_MAX_ROWS = 50;

    /** Per-hook last-state option prefix (cheap one-row read). */
    const STATE_PREFIX = 'bt_cron_state_';

    /** Aggregated current-alert array (for the admin panel + email dedup). */
    const ALERT_OPTION = 'bt_cron_health_alerts';

    /** Email-dedup transient prefix — same alert shouldn't email more than 1×/hour. */
    const ALERT_DEDUP_PREFIX = 'bt_cron_alerted_';

    /** Watchdog cron hook name. */
    const WATCHDOG_HOOK = 'bt_cron_watchdog';

    /** In-memory registry of watched hooks. Keyed by hook name. */
    private static $watches = array();

    /**
     * Bootstrap. Called from fx-live-markets.php at plugins_loaded.
     */
    public static function init() {
        // Register the default set of watches. Other classes can add more
        // via the `bt_cron_health_watches` filter.
        self::register_default_watches();

        // Wire the watchdog cron.
        add_action( self::WATCHDOG_HOOK, array( __CLASS__, 'run_watchdog' ) );
        if ( ! wp_next_scheduled( self::WATCHDOG_HOOK ) ) {
            wp_schedule_event( time() + 300, 'bt_five_minutes', self::WATCHDOG_HOOK );
        }

        // Admin menu (under BlockTicker).
        add_action( 'admin_menu', array( __CLASS__, 'register_admin_menu' ), 25 );

        // AJAX endpoints (admin only).
        add_action( 'wp_ajax_bt_cron_health_run_now', array( __CLASS__, 'ajax_run_now' ) );
    }

    /**
     * Stop the watchdog on plugin deactivation.
     */
    public static function deactivate() {
        wp_clear_scheduled_hook( self::WATCHDOG_HOOK );
    }

    // ─── Registration ───────────────────────────────────────────────────────

    /**
     * Watch a cron hook.
     *
     * @param string $hook   Cron hook name (the one passed to wp_schedule_event).
     * @param array  $config {
     *     @type string $name      Human-readable label.            Default = $hook.
     *     @type int    $sla_secs  Max acceptable seconds since last
     *                             successful run before alerting.
     *                             Default = HOUR_IN_SECONDS.
     *     @type bool   $critical  If true, SLA breach sends admin email.
     *                             Default = false.
     * }
     */
    public static function watch( $hook, array $config = array() ) {
        $config = wp_parse_args( $config, array(
            'name'     => $hook,
            'sla_secs' => HOUR_IN_SECONDS,
            'critical' => false,
        ) );
        self::$watches[ $hook ] = $config;

        // Wrap the hook at priority 1 (before the real handler) and 99 (after).
        add_action( $hook, array( __CLASS__, 'before_run' ),  1 );
        add_action( $hook, array( __CLASS__, 'after_run' ),  99 );
    }

    /**
     * The default watch list for v119.28.32. Other classes can extend this
     * via the `bt_cron_health_watches` filter — e.g. a future class might
     * register its own watches in its init().
     */
    private static function register_default_watches() {
        $defaults = array(
            'bt_refresh_prices' => array(
                'name'     => 'CoinGecko price refresh',
                'sla_secs' => 10 * MINUTE_IN_SECONDS,   // 5-min cron, 10-min SLA
                'critical' => true,
            ),
            'bt_signal_tracker_tick' => array(
                'name'     => 'Signal outcome tracking',
                'sla_secs' => 2 * HOUR_IN_SECONDS,      // hourly cron, 2-hour SLA
                'critical' => true,
            ),
            'bt_purge_old_data' => array(
                'name'     => 'Old-data purge',
                'sla_secs' => 2 * DAY_IN_SECONDS,       // daily cron, 2-day SLA
                'critical' => false,
            ),
            'bt_regenerate_sitemap' => array(
                'name'     => 'XML sitemap rebuild',
                'sla_secs' => 2 * DAY_IN_SECONDS,
                'critical' => false,
            ),
            'bt_score_news_sentiment' => array(
                'name'     => 'News sentiment scoring',
                'sla_secs' => 24 * HOUR_IN_SECONDS,     // twice-daily cron, 1-day SLA
                'critical' => false,
            ),
        );

        // Filter so other classes (or the admin) can add/override.
        $watches = apply_filters( 'bt_cron_health_watches', $defaults );

        foreach ( $watches as $hook => $config ) {
            self::watch( $hook, $config );
        }
    }

    // ─── Run wrappers (priority 1 + 99 on every watched hook) ───────────────

    public static function before_run() {
        $hook = current_action();
        $log = get_option( self::LOG_PREFIX . $hook, array() );

        $entry = array(
            'started_at' => time(),
            'memory'     => memory_get_usage( true ),
            'status'     => 'started',
        );
        array_unshift( $log, $entry );
        $log = array_slice( $log, 0, self::LOG_MAX_ROWS );

        // autoload=false: cron logs should never bloat the autoload query.
        update_option( self::LOG_PREFIX . $hook, $log, false );
    }

    public static function after_run() {
        $hook = current_action();
        $log = get_option( self::LOG_PREFIX . $hook, array() );
        if ( empty( $log[0] ) || ! isset( $log[0]['started_at'] ) ) {
            return;
        }

        $log[0]['finished_at'] = time();
        $log[0]['duration_ms'] = (int) ( ( time() - $log[0]['started_at'] ) * 1000 );
        $log[0]['memory_peak'] = memory_get_peak_usage( true );
        $log[0]['status']      = 'ok';

        update_option( self::LOG_PREFIX . $hook, $log, false );
        update_option( self::STATE_PREFIX . $hook, array(
            'last_run'    => time(),
            'last_status' => 'ok',
            'duration_ms' => $log[0]['duration_ms'],
        ), false );

        // If this run cleared a previous alert, drop it from the alert list.
        self::clear_alert( $hook );
    }

    // ─── Watchdog (every 5 min) ─────────────────────────────────────────────

    /**
     * Walk every watched hook, compare last-run age to SLA, build the alert
     * list, and email if any CRITICAL hook is in breach.
     *
     * Runs from its own cron at priority 10. Cheap; no external HTTP.
     */
    public static function run_watchdog() {
        $alerts = array();

        foreach ( self::$watches as $hook => $cfg ) {
            $state = get_option( self::STATE_PREFIX . $hook, array() );
            $last = isset( $state['last_run'] ) ? (int) $state['last_run'] : 0;
            $age = time() - $last;

            if ( $last === 0 ) {
                // Never run since the watcher was attached. Could be a
                // freshly-deployed cron that hasn't fired yet — give it
                // 2× the SLA before alerting.
                if ( $age <= ( 2 * $cfg['sla_secs'] ) ) {
                    continue;
                }
            }

            if ( $age > $cfg['sla_secs'] ) {
                $alerts[] = array(
                    'hook'     => $hook,
                    'name'     => $cfg['name'],
                    'age_secs' => $age,
                    'sla_secs' => $cfg['sla_secs'],
                    'critical' => $cfg['critical'],
                );
            }
        }

        update_option( self::ALERT_OPTION, $alerts, false );

        // Notify on critical breaches, deduped per-hook for 1 hour.
        foreach ( $alerts as $alert ) {
            if ( ! $alert['critical'] ) continue;

            $dedup_key = self::ALERT_DEDUP_PREFIX . md5( $alert['hook'] );
            if ( get_transient( $dedup_key ) ) continue;

            self::send_alert_email( $alert );
            set_transient( $dedup_key, 1, HOUR_IN_SECONDS );
        }
    }

    private static function clear_alert( $hook ) {
        $alerts = get_option( self::ALERT_OPTION, array() );
        $filtered = array_values( array_filter( $alerts, function( $a ) use ( $hook ) {
            return $a['hook'] !== $hook;
        } ) );
        if ( count( $filtered ) !== count( $alerts ) ) {
            update_option( self::ALERT_OPTION, $filtered, false );
        }
        delete_transient( self::ALERT_DEDUP_PREFIX . md5( $hook ) );
    }

    private static function send_alert_email( $alert ) {
        $to      = get_option( 'admin_email' );
        $subject = sprintf(
            '[BlockTicker] Cron SLA breach — %s',
            $alert['name']
        );
        $body  = sprintf(
            "The cron job \"%s\" has not run successfully within its SLA.\n\n",
            $alert['name']
        );
        $body .= sprintf( "Last successful run: %d minutes ago\n", round( $alert['age_secs'] / 60 ) );
        $body .= sprintf( "SLA: %d minutes\n",                     round( $alert['sla_secs'] / 60 ) );
        $body .= sprintf( "Hook: %s\n\n",                          $alert['hook'] );
        $body .= "Likely causes:\n";
        $body .= "  - The provider returned 429 (rate-limited)\n";
        $body .= "  - The cron handler is throwing an uncaught exception\n";
        $body .= "  - WP-Cron is wedged (clear with: DELETE FROM wp_options WHERE option_name = '_transient_doing_cron')\n\n";
        $body .= sprintf(
            "Investigate at: %s\n",
            admin_url( 'admin.php?page=bt-cron-health' )
        );
        wp_mail( $to, $subject, $body );
    }

    // ─── Admin UI ────────────────────────────────────────────────────────────

    public static function register_admin_menu() {
        // Only add if a top-level "BlockTicker" menu already exists.
        if ( ! function_exists( 'add_submenu_page' ) ) return;

        add_submenu_page(
            'blockticker',
            'Cron Health',
            'Cron Health',
            'manage_options',
            'bt-cron-health',
            array( __CLASS__, 'render_admin_page' )
        );
    }

    public static function render_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'You do not have permission to access this page.' );
        }

        $alerts = get_option( self::ALERT_OPTION, array() );
        $alerts_by_hook = array();
        foreach ( $alerts as $a ) $alerts_by_hook[ $a['hook'] ] = $a;
        ?>
        <div class="wrap">
            <h1>Cron Health</h1>
            <p>
                Watches <strong><?php echo count( self::$watches ); ?></strong> cron jobs.
                The watchdog runs every 5 minutes and compares each job's last-run
                timestamp to its SLA. Critical breaches send an email to the admin
                address (deduped to once per hour per hook).
            </p>

            <?php if ( $alerts ) : ?>
                <div class="notice notice-error">
                    <p>
                        <strong><?php echo count( $alerts ); ?></strong> job(s) breaching SLA.
                        See table below.
                    </p>
                </div>
            <?php else : ?>
                <div class="notice notice-success">
                    <p>All watched jobs within SLA. ✓</p>
                </div>
            <?php endif; ?>

            <table class="wp-list-table widefat striped">
                <thead>
                    <tr>
                        <th>Job</th>
                        <th>Hook</th>
                        <th>Last run</th>
                        <th>Status</th>
                        <th>Last duration</th>
                        <th>SLA</th>
                        <th>Next scheduled</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( self::$watches as $hook => $cfg ) :
                    $state = get_option( self::STATE_PREFIX . $hook, array() );
                    $last = isset( $state['last_run'] ) ? (int) $state['last_run'] : 0;
                    $next = wp_next_scheduled( $hook );
                    $in_alert = isset( $alerts_by_hook[ $hook ] );
                    $duration = isset( $state['duration_ms'] ) ? $state['duration_ms'] . ' ms' : '—';
                ?>
                    <tr<?php echo $in_alert ? ' style="background:rgba(220,38,38,0.08)"' : ''; ?>>
                        <td>
                            <strong><?php echo esc_html( $cfg['name'] ); ?></strong>
                            <?php if ( $cfg['critical'] ) : ?>
                                <span style="color:#b91c1c;font-size:11px;font-weight:600;letter-spacing:0.04em">  · CRITICAL</span>
                            <?php endif; ?>
                        </td>
                        <td><code><?php echo esc_html( $hook ); ?></code></td>
                        <td>
                            <?php if ( $last === 0 ) : ?>
                                <em>never</em>
                            <?php else : ?>
                                <?php echo esc_html( human_time_diff( $last ) ); ?> ago
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ( $in_alert ) : ?>
                                <span style="color:#b91c1c;font-weight:600">⚠ SLA BREACH</span>
                            <?php elseif ( $last > 0 ) : ?>
                                <span style="color:#16a34a">✓ OK</span>
                            <?php else : ?>
                                <span style="color:#a16207">⏳ pending first run</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html( $duration ); ?></td>
                        <td><?php echo esc_html( self::format_duration( $cfg['sla_secs'] ) ); ?></td>
                        <td>
                            <?php echo $next ? esc_html( human_time_diff( $next ) ) . ' from now' : '<em>not scheduled</em>'; ?>
                        </td>
                        <td>
                            <button type="button"
                                    class="button button-small bt-cron-run-now"
                                    data-hook="<?php echo esc_attr( $hook ); ?>"
                                    data-nonce="<?php echo esc_attr( wp_create_nonce( 'bt_cron_health_run_now' ) ); ?>">
                                Run now
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <h2 style="margin-top:32px">Recent runs (last 10)</h2>
            <?php foreach ( self::$watches as $hook => $cfg ) :
                $log = get_option( self::LOG_PREFIX . $hook, array() );
                $log = array_slice( $log, 0, 10 );
                if ( ! $log ) continue;
            ?>
                <h3 style="margin-top:18px"><?php echo esc_html( $cfg['name'] ); ?> · <code><?php echo esc_html( $hook ); ?></code></h3>
                <table class="wp-list-table widefat striped" style="max-width:720px">
                    <thead><tr><th>Started</th><th>Finished</th><th>Duration</th><th>Memory peak</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ( $log as $entry ) : ?>
                        <tr>
                            <td><?php echo isset( $entry['started_at'] )
                                ? esc_html( gmdate( 'Y-m-d H:i:s', $entry['started_at'] ) ) . ' UTC'
                                : '—'; ?></td>
                            <td><?php echo isset( $entry['finished_at'] )
                                ? esc_html( gmdate( 'H:i:s', $entry['finished_at'] ) )
                                : '<em>started, no finish recorded</em>'; ?></td>
                            <td><?php echo isset( $entry['duration_ms'] )
                                ? esc_html( $entry['duration_ms'] . ' ms' )
                                : '—'; ?></td>
                            <td><?php echo isset( $entry['memory_peak'] )
                                ? esc_html( size_format( $entry['memory_peak'] ) )
                                : '—'; ?></td>
                            <td><?php echo esc_html( $entry['status'] ?? '?' ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endforeach; ?>

            <script>
            document.querySelectorAll('.bt-cron-run-now').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var hook = btn.dataset.hook;
                    var nonce = btn.dataset.nonce;
                    btn.disabled = true;
                    btn.textContent = 'Running…';

                    var fd = new FormData();
                    fd.append('action', 'bt_cron_health_run_now');
                    fd.append('hook', hook);
                    fd.append('_wpnonce', nonce);

                    fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
                        .then(function(r) { return r.json(); })
                        .then(function(d) {
                            btn.textContent = d.success ? '✓ done' : '✗ failed';
                            setTimeout(function() { window.location.reload(); }, 1200);
                        })
                        .catch(function() {
                            btn.textContent = '✗ error';
                            btn.disabled = false;
                        });
                });
            });
            </script>
        </div>
        <?php
    }

    public static function ajax_run_now() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json( array( 'success' => false, 'message' => 'denied' ), 403 );
        }
        check_ajax_referer( 'bt_cron_health_run_now' );

        $hook = sanitize_key( $_POST['hook'] ?? '' );
        if ( ! $hook || ! isset( self::$watches[ $hook ] ) ) {
            wp_send_json( array( 'success' => false, 'message' => 'unknown hook' ), 400 );
        }

        // Fire the action synchronously. The before_run / after_run wrappers
        // will record start + end exactly as if the cron had triggered it.
        do_action( $hook );

        wp_send_json( array( 'success' => true ) );
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private static function format_duration( $secs ) {
        if ( $secs < HOUR_IN_SECONDS )    return round( $secs / 60 )    . ' min';
        if ( $secs < DAY_IN_SECONDS )     return round( $secs / 3600 )  . ' hr';
        return round( $secs / 86400 ) . ' day';
    }
}
