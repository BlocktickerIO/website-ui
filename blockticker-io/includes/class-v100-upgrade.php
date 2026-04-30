<?php
/**
 * BT_V100_Upgrade — BlockTicker v100.0 upgrade engine.
 *
 * Responsibilities:
 *  1. Delete all fxlm_* option rows from wp_options — the pre_option shims
 *     installed by BT_Deprecation ensure external reads still return live bt_* data.
 *  2. Unschedule every fxlm_* WP-Cron event and replace it with the canonical
 *     bt_* equivalent using the same schedule interval.
 *  3. Record upgrade completion so the routine is idempotent.
 *
 * The upgrade fires automatically on activation after a version bump, via
 * the bt_v100_upgrade WP-Cron event (scheduled once), and can be triggered
 * manually from BlockTicker → 🗄 Database.
 *
 * @since 100.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BT_V100_Upgrade {

    /** Option key that records the last completed upgrade version. */
    const DONE_KEY = 'bt_v100_upgrade_done';

    /**
     * Cron event rename map: fxlm_ hook → bt_ hook + schedule.
     *
     * 'schedule' is the bt_* recurrence name used when scheduling the
     * replacement event.  'daily' and 'hourly' are WP core schedules.
     *
     * @var array<string, array{bt_hook: string, schedule: string}>
     */
    const CRON_MAP = array(
        'fxlm_refresh_prices'    => array( 'bt_hook' => 'bt_refresh_prices',    'schedule' => 'bt_five_minutes' ),
        'fxlm_refresh_news'      => array( 'bt_hook' => 'bt_refresh_news',      'schedule' => 'bt_hourly' ),
        'fxlm_refresh_signals'   => array( 'bt_hook' => 'bt_refresh_signals',   'schedule' => 'bt_fifteen_minutes' ),
        'fxlm_refresh_exchanges' => array( 'bt_hook' => 'bt_refresh_exchanges', 'schedule' => 'bt_hourly' ),
        'fxlm_refresh_fng'       => array( 'bt_hook' => 'bt_refresh_fng',       'schedule' => 'bt_hourly' ),
        'fxlm_daily_ai_post'     => array( 'bt_hook' => 'bt_daily_ai_post',     'schedule' => 'daily' ),
        'fxlm_ai_posts'          => array( 'bt_hook' => 'bt_ai_posts',          'schedule' => 'bt_twice_daily' ),
        'fxlm_health_check'      => array( 'bt_hook' => 'bt_health_check',      'schedule' => 'daily' ),
    );

    /* ------------------------------------------------------------------
     * Bootstrap
     * ------------------------------------------------------------------ */

    public static function init() {
        // Run upgrade on plugins_loaded if not yet complete for this version.
        if ( get_option( self::DONE_KEY ) !== BT_VERSION ) {
            add_action( 'plugins_loaded', array( __CLASS__, 'run' ), 99 );
        }

        // AJAX handler for the admin "Run v100 Upgrade" button.
        add_action( 'wp_ajax_bt_v100_run_upgrade', array( __CLASS__, 'ajax_run' ) );
    }

    /* ------------------------------------------------------------------
     * Main upgrade routine (idempotent)
     * ------------------------------------------------------------------ */

    /**
     * Run the v100.0 upgrade.
     *
     * Safe to call multiple times — INSERT IGNORE and wp_next_scheduled()
     * guards prevent double-work.
     *
     * @param bool $force  If true, re-run even if already marked complete.
     * @return array  Summary array with keys: rows_deleted, cron_migrated, errors[].
     */
    public static function run( $force = false ) {
        if ( ! $force && get_option( self::DONE_KEY ) === BT_VERSION ) {
            return array( 'skipped' => true );
        }

        $result = array(
            'rows_deleted'  => 0,
            'cron_migrated' => 0,
            'errors'        => array(),
            'timestamp'     => time(),
        );

        // ── Step 1: delete fxlm_* option rows ────────────────────────────
        if ( class_exists( 'BT_Deprecation' ) ) {
            $result['rows_deleted'] = BT_Deprecation::delete_legacy_rows();
        } else {
            $result['errors'][] = 'BT_Deprecation class not found — option rows not deleted.';
        }

        // ── Step 2: migrate cron events ───────────────────────────────────
        $result['cron_migrated'] = self::migrate_cron_events();

        // ── Step 3: mark upgrade complete ─────────────────────────────────
        update_option( self::DONE_KEY, BT_VERSION, 'yes' );

        // ── Step 4: log to WP debug log ───────────────────────────────────
        if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log( sprintf(
                '[BlockTicker v100] Upgrade complete. Rows deleted: %d, Cron events migrated: %d, Errors: %d.',
                $result['rows_deleted'],
                $result['cron_migrated'],
                count( $result['errors'] )
            ) );
        }

        return $result;
    }

    /* ------------------------------------------------------------------
     * Cron migration
     * ------------------------------------------------------------------ */

    /**
     * For each fxlm_* cron event in CRON_MAP:
     *  1. Find the next-scheduled timestamp (if any).
     *  2. Unschedule all instances of the fxlm_* hook.
     *  3. Schedule the bt_* equivalent if not already scheduled.
     *
     * The strategy: re-use the existing next_scheduled time so there is
     * no timing gap.  If the fxlm_* event was never scheduled (e.g. already
     * replaced on a previous partial upgrade), skip it.
     *
     * @return int  Number of cron events migrated.
     */
    public static function migrate_cron_events() {
        $migrated = 0;

        foreach ( self::CRON_MAP as $fxlm_hook => $cfg ) {
            $bt_hook  = $cfg['bt_hook'];
            $schedule = $cfg['schedule'];

            // Find when the fxlm_ event was next due (may be false).
            $next = wp_next_scheduled( $fxlm_hook );

            // Unschedule all instances of the fxlm_ hook.
            wp_clear_scheduled_hook( $fxlm_hook );

            // Schedule the bt_ equivalent if not already registered.
            if ( ! wp_next_scheduled( $bt_hook ) ) {
                // If we found an existing timestamp, preserve it; otherwise
                // schedule slightly in the future so we don't flood the server.
                $start = $next ? $next : ( time() + wp_rand( 30, 120 ) );
                wp_schedule_event( $start, $schedule, $bt_hook );
                $migrated++;
            }
        }

        return $migrated;
    }

    /* ------------------------------------------------------------------
     * AJAX handler
     * ------------------------------------------------------------------ */

    public static function ajax_run() {
        check_ajax_referer( 'bt_db_admin', '_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions.' );
        }

        $result = self::run( true );
        wp_send_json_success( $result );
    }

    /* ------------------------------------------------------------------
     * Admin panel HTML
     * ------------------------------------------------------------------ */

    /**
     * Return HTML for the v100.0 upgrade panel shown in BlockTicker → 🗄 Database.
     *
     * @return string
     */
    public static function admin_panel_html() {
        $done        = get_option( self::DONE_KEY );
        $is_complete = ( $done === BT_VERSION );
        $legacy_rows = class_exists( 'BT_Deprecation' ) ? BT_Deprecation::count_legacy_rows() : '?';

        ob_start(); ?>
        <div class="postbox">
            <div class="postbox-header">
                <h2 class="hndle" style="padding:12px 15px;font-size:14px;">
                    🏁 v100.0 Final Cleanup
                </h2>
            </div>
            <div class="inside">
                <?php if ( $is_complete ) : ?>
                    <p style="color:#00a32a;font-weight:600;">
                        ✅ v100.0 upgrade complete (ran on version <?php echo esc_html( $done ); ?>).
                        All <code>fxlm_*</code> option rows deleted and cron events migrated to <code>bt_*</code>.
                    </p>
                <?php else : ?>
                    <p>
                        This upgrade will:
                    </p>
                    <ol style="margin-left:1.5em;font-size:13px;">
                        <li>Delete <strong><?php echo intval( $legacy_rows ); ?> <code>fxlm_*</code> option rows</strong> from <code>wp_options</code>.
                            The <code>pre_option</code> shims installed in v99.0 ensure any external code reading
                            <code>fxlm_*</code> keys continues to receive live <code>bt_*</code> data.
                        </li>
                        <li>Unschedule all <strong>8 <code>fxlm_*</code> WP-Cron events</strong> and replace them with
                            canonical <code>bt_*</code> equivalents using identical intervals.
                        </li>
                    </ol>
                    <p style="margin-bottom:12px;">
                        <strong>This runs automatically on activation.</strong> Use the button below if you need
                        to re-run or force it after a rollback.
                    </p>
                <?php endif; ?>

                <button type="button" id="bt-v100-upgrade-btn" class="button button-primary"
                        data-nonce="<?php echo esc_attr( wp_create_nonce( 'bt_db_admin' ) ); ?>">
                    <?php echo $is_complete ? '↺ Re-run v100 Upgrade' : '▶ Run v100 Upgrade Now'; ?>
                </button>
                <span id="bt-v100-status" style="margin-left:10px;font-size:13px;"></span>

                <script>
                (function(){
                    var btn = document.getElementById('bt-v100-upgrade-btn');
                    var status = document.getElementById('bt-v100-status');
                    if (!btn) return;
                    btn.addEventListener('click', function(){
                        btn.disabled = true;
                        status.textContent = 'Running…';
                        fetch(ajaxurl, {
                            method: 'POST',
                            headers: {'Content-Type':'application/x-www-form-urlencoded'},
                            body: 'action=bt_v100_run_upgrade&_nonce=' + btn.dataset.nonce
                        })
                        .then(r => r.json())
                        .then(function(d){
                            if (d.success) {
                                var r = d.data;
                                status.style.color = '#00a32a';
                                status.textContent = '✅ Done — ' + r.rows_deleted + ' rows deleted, ' + r.cron_migrated + ' cron events migrated.';
                            } else {
                                status.style.color = '#d63638';
                                status.textContent = '✗ Error: ' + (d.data || 'unknown');
                            }
                            btn.disabled = false;
                        })
                        .catch(function(e){
                            status.style.color = '#d63638';
                            status.textContent = '✗ Request failed: ' + e;
                            btn.disabled = false;
                        });
                    });
                })();
                </script>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
