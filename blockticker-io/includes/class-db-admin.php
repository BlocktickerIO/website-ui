<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * BT_DB_Admin
 *
 * Adds "🗄 Database" to the BlockTicker admin menu.
 *
 * The screen provides:
 *   1. Table status panel — existence, row count, disk size, last-write age
 *   2. Migration panel   — run dbDelta, backfill from options, set DB version
 *   3. Retention panel   — manual purge with preview counts
 *   4. Source health     — live dashboard of all RSS feeds + data APIs
 *
 * All mutating actions go through wp_ajax with nonce + capability check.
 * Every action returns JSON so the UI can update without a full page reload.
 *
 * Added in v96.3 — no changes to any existing class.
 */
class BT_DB_Admin {

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'add_submenu' ) );

        add_action( 'wp_ajax_bt_db_run_migration',  array( __CLASS__, 'ajax_run_migration' ) );
        add_action( 'wp_ajax_bt_db_backfill',       array( __CLASS__, 'ajax_backfill' ) );
        add_action( 'wp_ajax_bt_db_purge',          array( __CLASS__, 'ajax_purge' ) );
        add_action( 'wp_ajax_bt_db_table_status',   array( __CLASS__, 'ajax_table_status' ) );
        add_action( 'wp_ajax_bt_force_db_snapshot', array( __CLASS__, 'ajax_force_snapshot' ) );  // v119.25.0
        add_action( 'wp_ajax_bt_sentiment_run_batch', array( 'BT_Sentiment', 'ajax_run_batch' ) );
        add_action( 'wp_ajax_bt_webhook_deliver_now', function() {
            check_ajax_referer( 'bt_db_admin', '_nonce' );
            if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error();
            $result = BT_Webhook::deliver_pending_batch();
            wp_send_json_success( $result );
        } );
    }

    // -------------------------------------------------------------------
    // Menu registration
    // -------------------------------------------------------------------
    public static function add_submenu() {
        add_submenu_page(
            'fxlm-wizard',
            'Database Manager',
            '🗄 Database',
            'manage_options',
            'bt-database',
            array( __CLASS__, 'render_page' )
        );
    }

    // -------------------------------------------------------------------
    // Page render
    // -------------------------------------------------------------------
    /**
     * Database admin page — v119.6 redesigned.
     *
     * Replaces the previous dual-column postbox layout (which mixed 7 different
     * integration panels from Social/Webhook/Newsletter/Sentiment/etc.) with a
     * clean 3-tab layout: Overview, Maintenance, Source Health.
     *
     * Panels from other classes (Social, Webhook, etc.) keep their own admin
     * screens via their own submenu registration — they are no longer crammed
     * into the DB admin page.
     */
    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        $nonce        = wp_create_nonce( 'bt_db_admin' );
        $db_version   = get_option( 'bt_db_version', '—' );
        $tables       = self::get_table_status();
        $summary      = class_exists( 'BT_Source_Validator' ) ? BT_Source_Validator::get_summary() : null;
        $health       = class_exists( 'BT_Source_Validator' ) ? BT_Source_Validator::get_health() : array();
        $migration_ok = ( $db_version === BT_Database::DB_VERSION );
        $tab          = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'overview';
        $base_url     = admin_url( 'admin.php?page=bt-database' );

        $total_rows = 0;
        foreach ( $tables as $t ) { $total_rows += intval( $t['rows'] ?? 0 ); }
        ?>
        <div class="wrap" id="bt-db-wrap" style="max-width:1200px">

            <!-- Header -->
            <div style="display:flex;align-items:center;justify-content:space-between;margin:20px 0 8px;flex-wrap:wrap;gap:12px">
                <h1 style="margin:0;font-size:22px;display:flex;align-items:center;gap:10px">
                    Database
                    <span style="font-size:11px;font-weight:500;color:#646970;background:#f0f0f1;padding:3px 8px;text-transform:uppercase;letter-spacing:.08em">Schema v<?php echo esc_html( $db_version ); ?></span>
                </h1>
                <div style="font-size:12px;color:#646970;font-family:Menlo,Consolas,monospace">
                    <?php echo count( $tables ); ?> tables · <?php echo number_format( $total_rows ); ?> rows total
                </div>
            </div>

            <?php if ( ! $migration_ok ) : ?>
                <div class="notice notice-warning" style="margin:10px 0 15px">
                    <p><strong>Schema update available.</strong>
                    Installed: <code><?php echo esc_html( $db_version ); ?></code> → Required: <code><?php echo esc_html( BT_Database::DB_VERSION ); ?></code>.
                    Run migration in the Maintenance tab.</p>
                </div>
            <?php endif; ?>

            <div id="bt-db-notice" style="display:none" class="notice notice-success is-dismissible"><p id="bt-db-notice-msg"></p></div>

            <!-- Tabs -->
            <h2 class="nav-tab-wrapper" style="margin-top:0">
                <a href="<?php echo esc_url( add_query_arg( 'tab', 'overview', $base_url ) ); ?>" class="nav-tab <?php echo $tab === 'overview' ? 'nav-tab-active' : ''; ?>">Overview</a>
                <a href="<?php echo esc_url( add_query_arg( 'tab', 'maintenance', $base_url ) ); ?>" class="nav-tab <?php echo $tab === 'maintenance' ? 'nav-tab-active' : ''; ?>">Maintenance</a>
                <a href="<?php echo esc_url( add_query_arg( 'tab', 'sources', $base_url ) ); ?>" class="nav-tab <?php echo $tab === 'sources' ? 'nav-tab-active' : ''; ?>">Source Health</a>
                <a href="<?php echo esc_url( add_query_arg( 'tab', 'forex-diag', $base_url ) ); ?>" class="nav-tab <?php echo $tab === 'forex-diag' ? 'nav-tab-active' : ''; ?>">Forex Diagnostic</a>
            </h2>

            <div style="background:#fff;border:1px solid #c3c4c7;border-top:none;padding:22px 26px;margin-bottom:20px">
            <?php
            if ( $tab === 'overview' ) {
                self::render_tab_overview( $tables );
            } elseif ( $tab === 'maintenance' ) {
                self::render_tab_maintenance( $tables, $nonce );
            } elseif ( $tab === 'sources' ) {
                self::render_tab_sources( $summary, $health, $nonce );
            } elseif ( $tab === 'forex-diag' ) {
                self::render_tab_forex_diag();
            }
            ?>
            </div>

        </div><!-- /.wrap -->

        <script>
        (function($) {
            var nonce = '<?php echo esc_js( $nonce ); ?>';
            function doAction(action, btn, spinnerEl, onSuccess) {
                btn.prop('disabled', true);
                spinnerEl.css('display', 'inline-block');
                $.post(ajaxurl, { action: action, nonce: nonce }, function(resp) {
                    spinnerEl.hide();
                    btn.prop('disabled', false);
                    if (resp.success) { onSuccess(resp.data); }
                    else { alert('Error: ' + (resp.data || 'Unknown error')); }
                }).fail(function() {
                    spinnerEl.hide();
                    btn.prop('disabled', false);
                    alert('Request failed. Check browser console.');
                });
            }
            function showNotice(msg) {
                $('#bt-db-notice-msg').text(msg);
                $('#bt-db-notice').slideDown();
                setTimeout(function() { $('#bt-db-notice').slideUp(); }, 6000);
            }
            $('#btn-migrate').on('click', function() {
                doAction('bt_db_run_migration', $(this), $('#btn-migrate-spinner'), function(d) {
                    showNotice(d.message);
                    if (d.table_html) $('#bt-table-status-panel').html(d.table_html);
                });
            });
            $('#btn-backfill').on('click', function() {
                doAction('bt_db_backfill', $(this), $('#btn-migrate-spinner'), function(d) {
                    showNotice(d.message);
                    if (d.table_html) $('#bt-table-status-panel').html(d.table_html);
                });
            });
            $('#btn-purge').on('click', function() {
                if (!confirm('Purge data outside the retention windows now?')) return;
                doAction('bt_db_purge', $(this), $('#btn-purge-spinner'), function(d) { showNotice(d.message); });
            });
            $('#btn-validate').on('click', function() {
                var btn = $(this), spinner = $('#btn-validate-spinner'), result = $('#validate-result');
                btn.prop('disabled', true);
                spinner.css('display', 'inline-block');
                result.text('Running — this may take 30–60s');
                $.post(ajaxurl, { action: 'bt_validate_sources_now', nonce: nonce }, function(resp) {
                    spinner.hide();
                    btn.prop('disabled', false);
                    if (resp.success) { result.text(resp.data.message); location.reload(); }
                    else { result.text('Error: ' + (resp.data || 'Unknown')); }
                }).fail(function() {
                    spinner.hide();
                    btn.prop('disabled', false);
                    result.text('Request failed.');
                });
            });
        })(jQuery);
        </script>
        <?php
    }

    /** Tab: Overview — at-a-glance table status + summary cards */
    private static function render_tab_overview( $tables ) {
        $total_rows = 0; foreach ( $tables as $t ) { $total_rows += intval( $t['rows'] ?? 0 ); }

        // v119.28.35: surface uninstall behavior so admins know whether
        // their data is safe across a reinstall cycle.
        $will_purge = defined( 'BT_PURGE_DATA_ON_UNINSTALL' ) && BT_PURGE_DATA_ON_UNINSTALL;
        $will_keep_legacy = defined( 'BT_KEEP_DATA_ON_UNINSTALL' ) && BT_KEEP_DATA_ON_UNINSTALL;
        $preserved_at = intval( get_option( 'bt_data_preserved_on_uninstall_at', 0 ) );
        ?>

        <!-- v119.28.35: Data preservation status -->
        <?php if ( $will_purge && ! $will_keep_legacy ) : ?>
            <div class="notice notice-warning inline" style="padding:10px 14px;margin:0 0 16px">
                <strong style="color:#b91c1c">⚠ Purge on uninstall is enabled.</strong>
                <code>BT_PURGE_DATA_ON_UNINSTALL</code> is set to <code>true</code> in
                <code>wp-config.php</code>. If you delete this plugin, all
                <strong><?php echo number_format( $total_rows ); ?> rows</strong> across
                <?php echo count( $tables ); ?> tables, all provisioned pages, all
                settings, and all options will be permanently dropped. Remove the
                constant if you want to preserve data across reinstalls.
            </div>
        <?php else : ?>
            <div class="notice notice-success inline" style="padding:10px 14px;margin:0 0 16px">
                <strong>✓ Data preserved on reinstall.</strong>
                Deactivating and re-installing this plugin will <em>not</em> drop the
                custom tables, delete provisioned pages, or wipe options.
                <?php if ( $preserved_at ) : ?>
                    <br><small style="color:#646970">Last uninstall preserved data on
                    <?php echo esc_html( gmdate( 'Y-m-d H:i', $preserved_at ) ); ?> UTC
                    (<?php echo esc_html( human_time_diff( $preserved_at ) ); ?> ago).</small>
                <?php endif; ?>
                <br><small style="color:#646970">To genuinely remove all traces, set
                <code>define( 'BT_PURGE_DATA_ON_UNINSTALL', true );</code> in
                <code>wp-config.php</code> before deleting the plugin.</small>
            </div>
        <?php endif; ?>

        <!-- Quick stats cards -->
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-bottom:24px">
            <div style="background:#f6f7f7;border:1px solid #dcdcde;padding:14px 16px">
                <div style="font-size:11px;color:#646970;text-transform:uppercase;letter-spacing:.08em;margin-bottom:4px">Tables</div>
                <div style="font-size:22px;font-weight:600;color:#1d2327"><?php echo count( $tables ); ?></div>
            </div>
            <div style="background:#f6f7f7;border:1px solid #dcdcde;padding:14px 16px">
                <div style="font-size:11px;color:#646970;text-transform:uppercase;letter-spacing:.08em;margin-bottom:4px">Total rows</div>
                <div style="font-size:22px;font-weight:600;color:#1d2327"><?php echo number_format( $total_rows ); ?></div>
            </div>
            <div style="background:#f6f7f7;border:1px solid #dcdcde;padding:14px 16px">
                <div style="font-size:11px;color:#646970;text-transform:uppercase;letter-spacing:.08em;margin-bottom:4px">Schema version</div>
                <div style="font-size:22px;font-weight:600;color:#1d2327">v<?php echo esc_html( BT_Database::DB_VERSION ); ?></div>
            </div>
        </div>
        <h3 style="margin:0 0 10px;font-size:14px">Table Status</h3>
        <div id="bt-table-status-panel"><?php self::render_table_status( $tables ); ?></div>
        <?php
    }

    /** Tab: Maintenance — migrate, backfill, purge */
    private static function render_tab_maintenance( $tables, $nonce ) {
        ?>
        <h3 style="margin:0 0 8px;font-size:14px">Schema Migration</h3>
        <p style="color:#646970;font-size:13px;margin:0 0 14px;max-width:680px">
            <strong>Run migration</strong> executes <code>dbDelta()</code> to create or update all tables.
            Safe to run any time — no-op if already current.
            <strong>Backfill</strong> seeds <code>wp_bt_price_history</code> from the current snapshot stored in <code>wp_options</code>.
            Only run once after the first migration.
        </p>
        <p style="margin:0 0 24px">
            <button id="btn-migrate" class="button button-primary" data-nonce="<?php echo esc_attr( $nonce ); ?>">Run Migration</button>
            <button id="btn-backfill" class="button" data-nonce="<?php echo esc_attr( $nonce ); ?>" style="margin-left:6px">Backfill Price History</button>
            <span id="btn-migrate-spinner" class="spinner" style="display:none;float:none;vertical-align:middle"></span>
        </p>

        <?php if ( class_exists( 'BT_Migration' ) ) echo BT_Migration::admin_panel_html( $nonce ); ?>

        <hr style="margin:24px 0;border:none;border-top:1px solid #dcdcde">

        <h3 style="margin:0 0 8px;font-size:14px">Retention &amp; Purge</h3>
        <p style="color:#646970;font-size:13px;margin:0 0 14px;max-width:680px">
            Data outside the retention windows is purged automatically every night by the
            <code>bt_purge_old_data</code> cron. Define <code>BT_KEEP_DATA_ON_UNINSTALL</code>
            in <code>wp-config.php</code> to preserve tables when the plugin is removed.
        </p>
        <table class="widefat striped" style="font-size:13px;max-width:700px;margin-bottom:12px">
            <thead><tr><th>Table</th><th>Retention window</th><th>Rows to purge</th></tr></thead>
            <tbody>
            <?php foreach ( self::get_purge_preview( $tables ) as $row ) : ?>
                <tr>
                    <td><code><?php echo esc_html( $row['table'] ); ?></code></td>
                    <td><?php echo esc_html( $row['window'] ); ?></td>
                    <td><?php echo esc_html( $row['purgeable'] ); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p style="margin:0">
            <button id="btn-purge" class="button" data-nonce="<?php echo esc_attr( $nonce ); ?>">Purge Now</button>
            <span id="btn-purge-spinner" class="spinner" style="display:none;float:none;vertical-align:middle"></span>
        </p>

        <hr style="margin:24px 0;border:none;border-top:1px solid #dcdcde">

        <h3 style="margin:0 0 8px;font-size:14px">Force Snapshot (Diagnostic)</h3>
        <p style="color:#646970;font-size:13px;margin:0 0 14px;max-width:680px">
            v119.25 fixed a hook-prefix bug that prevented the cron from writing to these tables (<code>fxlm_refresh_*</code> → <code>bt_refresh_*</code>). If your tables show 0 rows, click below to force an immediate snapshot from the live <code>bt_crypto_data</code> / <code>bt_news_items</code> / <code>bt_signal_items</code> options. The next scheduled cron will keep them updated automatically.
        </p>
        <p style="margin:0">
            <button id="btn-force-snapshot" class="button button-primary" data-nonce="<?php echo esc_attr( $nonce ); ?>">📸 Force Snapshot Now</button>
            <span id="btn-snapshot-spinner" class="spinner" style="display:none;float:none;vertical-align:middle"></span>
            <span id="btn-snapshot-result" style="font-size:13px;color:#646970;margin-left:8px"></span>
        </p>
        <script>
        jQuery(function($){
            $('#btn-force-snapshot').on('click', function(){
                var btn = $(this).prop('disabled', true);
                $('#btn-snapshot-spinner').css('display','inline-block').addClass('is-active');
                $('#btn-snapshot-result').text('');
                $.post(ajaxurl, {
                    action: 'bt_force_db_snapshot',
                    nonce:  btn.data('nonce')
                }, function(res){
                    btn.prop('disabled', false);
                    $('#btn-snapshot-spinner').css('display','none').removeClass('is-active');
                    if (res && res.success) {
                        $('#btn-snapshot-result').css('color','#22c55e').text('✓ ' + (res.data || 'Snapshot complete. Reload page to see row counts.'));
                        setTimeout(function(){ location.reload(); }, 1500);
                    } else {
                        $('#btn-snapshot-result').css('color','#dc2626').text('✗ ' + ((res && res.data) || 'Snapshot failed'));
                    }
                }).fail(function(){
                    btn.prop('disabled', false);
                    $('#btn-snapshot-spinner').css('display','none').removeClass('is-active');
                    $('#btn-snapshot-result').css('color','#dc2626').text('✗ Request failed');
                });
            });
        });
        </script>
        <?php
    }

    /** Tab: Source Health */
    private static function render_tab_sources( $summary, $health, $nonce ) {
        ?>
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:10px">
            <div>
                <h3 style="margin:0 0 4px;font-size:14px">Data Source Health</h3>
                <?php if ( $summary ) : ?>
                    <div style="font-size:12px;color:#646970">
                        <?php echo self::render_health_summary_badge( $summary ); ?>
                        <span style="margin-left:8px">Last checked: <?php echo esc_html( human_time_diff( $summary['checked_at'] ) . ' ago' ); ?></span>
                    </div>
                <?php endif; ?>
            </div>
            <div>
                <button id="btn-validate" class="button" data-nonce="<?php echo esc_attr( $nonce ); ?>">↻ Validate All Sources</button>
                <span id="btn-validate-spinner" class="spinner" style="display:none;float:none;vertical-align:middle"></span>
                <span id="validate-result" style="font-size:12px;color:#646970;margin-left:8px"></span>
            </div>
        </div>
        <div id="bt-health-table"><?php self::render_health_table( $health ); ?></div>
        <?php
    }

    /** Tab: Forex Diagnostic — merged from the old standalone page */
    private static function render_tab_forex_diag() {
        ?>
        <h3 style="margin:0 0 8px;font-size:14px">Forex API Diagnostic</h3>
        <p style="color:#646970;font-size:13px;margin:0 0 14px;max-width:680px">
            Tests each forex API in parallel and reports response latency and status. Useful when forex prices show <code>0.00%</code> on market pages.
        </p>
        <?php
        if ( class_exists( 'BT_Forex_Diag' ) && method_exists( 'BT_Forex_Diag', 'render' ) ) {
            BT_Forex_Diag::render();
        } else {
            /* Lightweight inline diagnostic — shows last fetched state */
            $forex = get_option( 'fxlm_forex_data', array() );
            $updated = isset( $forex['updated'] ) ? intval( $forex['updated'] ) : 0;
            $age = $updated ? ( time() - $updated ) : 0;
            ?>
            <table class="widefat striped" style="max-width:700px;font-size:13px">
                <tbody>
                    <tr><td><strong>Last fetch</strong></td><td><?php echo $updated ? esc_html( human_time_diff( $updated ) . ' ago (' . date( 'Y-m-d H:i', $updated ) . ')' ) : '<em>never</em>'; ?></td></tr>
                    <tr><td><strong>Pairs loaded</strong></td><td><?php echo count( $forex['rates'] ?? array() ); ?></td></tr>
                    <tr><td><strong>Age</strong></td><td><?php echo $age < 900 ? '<span style="color:#008a20">Fresh</span>' : ( $age < 3600 ? '<span style="color:#dba617">Stale</span>' : '<span style="color:#d63638">Very stale</span>' ); ?></td></tr>
                </tbody>
            </table>
            <p style="color:#646970;font-size:12px;margin-top:10px">
                Use the <strong>Source Health</strong> tab to run a full API test across all configured providers.
            </p>
            <?php
        }
    }

    // -------------------------------------------------------------------
    // HTML fragments (reused by AJAX refreshes)
    // -------------------------------------------------------------------
    private static function render_table_status( $tables ) {
        ?>
        <table class="widefat striped" style="font-size:13px;">
            <thead><tr>
                <th>Table</th>
                <th>Status</th>
                <th style="text-align:right">Rows</th>
                <th style="text-align:right">Size</th>
                <th>Last write</th>
            </tr></thead>
            <tbody>
            <?php foreach ( $tables as $t ) : ?>
            <tr>
                <td><code><?php echo esc_html( $t['name'] ); ?></code></td>
                <td>
                    <?php if ( $t['exists'] ) : ?>
                        <span style="color:#2e7d32;font-weight:500;">✓ exists</span>
                    <?php else : ?>
                        <span style="color:#c62828;font-weight:500;">✗ missing</span>
                    <?php endif; ?>
                </td>
                <td style="text-align:right"><?php echo $t['exists'] ? esc_html( number_format( $t['rows'] ) ) : '—'; ?></td>
                <td style="text-align:right"><?php echo $t['exists'] ? esc_html( $t['size'] ) : '—'; ?></td>
                <td style="font-size:12px;color:#666;"><?php echo $t['exists'] && $t['last_write'] ? esc_html( human_time_diff( strtotime( $t['last_write'] ) ) . ' ago' ) : '—'; ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p style="font-size:12px;color:#888;margin:8px 0 0;">
            Schema version installed: <code><?php echo esc_html( get_option( 'bt_db_version', 'not set' ) ); ?></code>
            &nbsp;|&nbsp; Required: <code><?php echo esc_html( BT_Database::DB_VERSION ); ?></code>
        </p>
        <?php
    }

    private static function render_health_table( $health ) {
        if ( empty( $health ) ) {
            echo '<p style="padding:15px;color:#666;font-size:13px;">No health data yet — click "Validate All Sources Now" to run the first check.</p>';
            return;
        }

        $status_map = array(
            'healthy'  => array( 'color' => '#2e7d32', 'label' => '✓ Healthy',  'bg' => '#e8f5e9' ),
            'degraded' => array( 'color' => '#e65100', 'label' => '⚠ Degraded', 'bg' => '#fff3e0' ),
            'dead'     => array( 'color' => '#c62828', 'label' => '✗ Dead',     'bg' => '#ffebee' ),
            'unknown'  => array( 'color' => '#888',    'label' => '? Unknown',   'bg' => '#f5f5f5' ),
        );

        // Group by category
        $grouped = array();
        foreach ( $health as $row ) {
            $cat = $row['category'] ?? 'Other';
            $grouped[ $cat ][] = $row;
        }

        echo '<table class="widefat" style="font-size:12px;border:none;">';
        echo '<thead><tr style="background:#f9f9f9;">
            <th style="padding:8px 10px;">Source</th>
            <th style="padding:8px 10px;">Status</th>
            <th style="padding:8px 10px;text-align:right;">HTTP</th>
            <th style="padding:8px 10px;text-align:right;">Latency</th>
            <th style="padding:8px 10px;text-align:right;">Items</th>
            <th style="padding:8px 10px;">Note</th>
        </tr></thead><tbody>';

        foreach ( $grouped as $category => $rows ) {
            echo '<tr><td colspan="6" style="background:#f0f0f0;padding:5px 10px;font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#555;">' . esc_html( $category ) . '</td></tr>';

            foreach ( $rows as $r ) {
                $s     = $r['status'] ?? 'unknown';
                $meta  = $status_map[ $s ] ?? $status_map['unknown'];
                $error = ! empty( $r['error'] ) ? $r['error'] : '';
                $note  = '';
                if ( ! empty( $r['replaces'] ) ) $note = '<span style="color:#1565c0;font-size:11px;">Replaces: ' . esc_html( $r['replaces'] ) . '</span>';
                if ( $error ) $note .= ( $note ? '<br>' : '' ) . '<span style="color:#c62828;font-size:11px;">' . esc_html( $error ) . '</span>';

                $freshness = '';
                if ( isset( $r['freshness_h'] ) && $r['freshness_h'] !== null ) {
                    $freshness = $r['freshness_h'] . 'h ago';
                }

                $latency_disp = isset( $r['latency_ms'] ) && $r['latency_ms'] > 0
                    ? $r['latency_ms'] . ' ms'
                    : '—';

                echo '<tr style="border-top:1px solid #eee;">';
                echo '<td style="padding:7px 10px;font-weight:500;">' . esc_html( $r['name'] ?? '—' ) . '</td>';
                echo '<td style="padding:7px 10px;"><span style="background:' . esc_attr( $meta['bg'] ) . ';color:' . esc_attr( $meta['color'] ) . ';padding:2px 7px;border-radius:3px;font-size:11px;font-weight:500;">' . $meta['label'] . '</span></td>';
                echo '<td style="padding:7px 10px;text-align:right;color:#666;">' . esc_html( $r['http_code'] ?? '—' ) . '</td>';
                echo '<td style="padding:7px 10px;text-align:right;color:#666;">' . esc_html( $latency_disp ) . '</td>';
                echo '<td style="padding:7px 10px;text-align:right;color:#666;">' . esc_html( $r['item_count'] ?? '—' ) . ( $freshness ? '<br><span style="font-size:11px;color:#888;">' . esc_html( $freshness ) . '</span>' : '' ) . '</td>';
                echo '<td style="padding:7px 10px;">' . wp_kses( $note, array( 'span' => array( 'style' => array() ), 'br' => array() ) ) . '</td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table>';
    }

    private static function render_health_summary_badge( $summary ) {
        $total    = $summary['total'] ?? 0;
        $healthy  = $summary['healthy'] ?? 0;
        $degraded = $summary['degraded'] ?? 0;
        $dead     = $summary['dead'] ?? 0;

        $parts = array();
        if ( $healthy )  $parts[] = '<span style="color:#2e7d32;">' . $healthy . ' healthy</span>';
        if ( $degraded ) $parts[] = '<span style="color:#e65100;">' . $degraded . ' degraded</span>';
        if ( $dead )     $parts[] = '<span style="color:#c62828;">' . $dead . ' dead</span>';

        return implode( ' · ', $parts ) . ' <span style="color:#888;">of ' . $total . '</span>';
    }

    // -------------------------------------------------------------------
    // Data helpers
    // -------------------------------------------------------------------
    private static function get_table_status() {
        global $wpdb;

        $table_defs = array(
            array(
                'name'       => $wpdb->prefix . 'bt_price_history',
                'date_col'   => 'captured_at',
            ),
            array(
                'name'       => $wpdb->prefix . 'bt_news_items',
                'date_col'   => 'ingested_at',
            ),
            array(
                'name'       => $wpdb->prefix . 'bt_signals_history',
                'date_col'   => 'signaled_at',
            ),
            array(
                'name'       => $wpdb->prefix . 'bt_events',
                'date_col'   => 'created_at',
            ),
        );

        $results = array();
        foreach ( $table_defs as $def ) {
            $name   = $def['name'];
            $exists = (bool) $wpdb->get_var( $wpdb->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
                $name
            ) );

            if ( ! $exists ) {
                $results[] = array(
                    'name'       => $name,
                    'exists'     => false,
                    'rows'       => 0,
                    'size'       => '0 KB',
                    'last_write' => null,
                );
                continue;
            }

            $rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$name}`" ); // phpcs:ignore

            // Table size (data + index)
            $size_row = $wpdb->get_row( $wpdb->prepare(
                "SELECT ROUND((data_length + index_length) / 1024, 0) AS size_kb
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
                $name
            ) );
            $size_kb  = $size_row ? (int) $size_row->size_kb : 0;
            $size_str = $size_kb >= 1024
                ? round( $size_kb / 1024, 1 ) . ' MB'
                : $size_kb . ' KB';

            // Last write timestamp
            $last_write = null;
            if ( $def['date_col'] ) {
                $last_write = $wpdb->get_var( "SELECT MAX(`{$def['date_col']}`) FROM `{$name}`" ); // phpcs:ignore
            }

            $results[] = array(
                'name'       => $name,
                'exists'     => true,
                'rows'       => $rows,
                'size'       => $size_str,
                'last_write' => $last_write,
            );
        }

        return $results;
    }

    private static function get_purge_preview( $tables ) {
        global $wpdb;

        $windows = array(
            $wpdb->prefix . 'bt_price_history'   => array( 'label' => '90 days',  'days' => 90,  'col' => 'captured_at' ),
            $wpdb->prefix . 'bt_news_items'       => array( 'label' => 'Never',    'days' => null, 'col' => null ),
            $wpdb->prefix . 'bt_signals_history'  => array( 'label' => 'Never',    'days' => null, 'col' => null ),
            $wpdb->prefix . 'bt_events'           => array( 'label' => '30 days',  'days' => 30,  'col' => 'created_at' ),
        );

        $preview = array();
        foreach ( $windows as $table => $w ) {
            $purgeable = '—';

            $t = current( array_filter( $tables, function( $x ) use ( $table ) {
                return $x['name'] === $table && $x['exists'];
            } ) );

            if ( $t && $w['days'] && $w['col'] ) {
                $threshold = date( 'Y-m-d H:i:s', strtotime( '-' . $w['days'] . ' days' ) );
                $count     = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore
                    "SELECT COUNT(*) FROM `{$table}` WHERE `{$w['col']}` < %s", // phpcs:ignore
                    $threshold
                ) );
                $purgeable = number_format( $count ) . ' rows';
            } elseif ( $t && ! $w['days'] ) {
                $purgeable = 'Never purged';
            }

            $preview[] = array(
                'table'     => $table,
                'window'    => $w['label'],
                'purgeable' => $purgeable,
            );
        }

        return $preview;
    }

    // -------------------------------------------------------------------
    // AJAX handlers
    // -------------------------------------------------------------------
    public static function ajax_run_migration() {
        check_ajax_referer( 'bt_db_admin', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        BT_Database::install();
        update_option( 'bt_db_version', BT_Database::DB_VERSION );

        $tables    = self::get_table_status();
        $table_html = self::capture_render( 'render_table_status', $tables );

        wp_send_json_success( array(
            'message'    => 'Migration complete. Schema is now at version ' . BT_Database::DB_VERSION . '.',
            'table_html' => $table_html,
        ) );
    }

    public static function ajax_backfill() {
        check_ajax_referer( 'bt_db_admin', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $inserted = 0;
        $data     = get_option( 'bt_crypto_data', false );

        if ( $data ) {
            $coins = json_decode( $data, true );
            if ( is_array( $coins ) ) {
                foreach ( $coins as $coin ) {
                    $id = BT_Database::insert_price_snapshot( array(
                        'symbol'        => strtoupper( $coin['symbol'] ?? '' ),
                        'asset_class'   => 'crypto',
                        'price_usd'     => $coin['current_price'] ?? 0,
                        'volume_24h'    => $coin['total_volume'] ?? 0,
                        'market_cap'    => $coin['market_cap'] ?? 0,
                        'pct_change_24h'=> $coin['price_change_percentage_24h'] ?? 0,
                    ) );
                    if ( $id ) $inserted++;
                }
            }
        }

        // Also backfill from forex snapshot
        $forex = get_option( 'bt_forex_rates', false );
        if ( $forex ) {
            $rates = json_decode( $forex, true );
            if ( is_array( $rates ) ) {
                foreach ( $rates as $pair => $rate ) {
                    $id = BT_Database::insert_price_snapshot( array(
                        'symbol'        => strtoupper( $pair ),
                        'asset_class'   => 'forex',
                        'price_usd'     => $rate,
                        'volume_24h'    => 0,
                        'market_cap'    => 0,
                        'pct_change_24h'=> 0,
                    ) );
                    if ( $id ) $inserted++;
                }
            }
        }

        $tables     = self::get_table_status();
        $table_html = self::capture_render( 'render_table_status', $tables );

        wp_send_json_success( array(
            'message'    => sprintf( 'Backfill complete. Inserted %d anchor rows from current snapshot.', $inserted ),
            'table_html' => $table_html,
        ) );
    }

    public static function ajax_purge() {
        check_ajax_referer( 'bt_db_admin', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $deleted = BT_Database::purge_old_data();

        wp_send_json_success( array(
            'message' => sprintf(
                'Purge complete. Removed %d price rows older than 90 days and %d event rows older than 30 days.',
                $deleted['price'],
                $deleted['events']
            ),
        ) );
    }

    public static function ajax_table_status() {
        check_ajax_referer( 'bt_db_admin', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $tables     = self::get_table_status();
        $table_html = self::capture_render( 'render_table_status', $tables );

        wp_send_json_success( array( 'table_html' => $table_html ) );
    }

    /**
     * v119.25.0: Force an immediate snapshot. Calls the same callbacks the
     * cron does, so the operator can verify data flow without waiting up to
     * an hour for the next scheduled run. Returns row counts so they can see
     * exactly what was inserted.
     */
    public static function ajax_force_snapshot() {
        check_ajax_referer( 'bt_db_admin', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        global $wpdb;
        $before_price   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}bt_price_history" );
        $before_news    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}bt_news_items" );
        $before_signals = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}bt_signals_history" );

        // Call the snapshot methods directly — bypasses the cron hook chain.
        if ( method_exists( 'BT_Database', 'snapshot_prices_after_fetch' ) )  BT_Database::snapshot_prices_after_fetch();
        if ( method_exists( 'BT_Database', 'snapshot_news_after_fetch' ) )    BT_Database::snapshot_news_after_fetch();
        if ( method_exists( 'BT_Database', 'snapshot_signals_after_fetch' ) ) BT_Database::snapshot_signals_after_fetch();

        $after_price    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}bt_price_history" );
        $after_news     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}bt_news_items" );
        $after_signals  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}bt_signals_history" );

        $delta_price    = $after_price    - $before_price;
        $delta_news     = $after_news     - $before_news;
        $delta_signals  = $after_signals  - $before_signals;
        $total_inserted = $delta_price + $delta_news + $delta_signals;

        // If nothing was inserted, the source options are likely empty —
        // user needs to run the wizard's data-fetch steps first or wait for
        // the bt_refresh_prices cron to fire.
        if ( $total_inserted === 0 ) {
            $crypto_count = is_array( get_option( 'bt_crypto_data' ) ) ? count( get_option( 'bt_crypto_data' )['coins'] ?? array() ) : 0;
            $news_count   = is_array( get_option( 'bt_news_items', array() ) ) ? count( get_option( 'bt_news_items', array() ) ) : 0;
            wp_send_json_success( array(
                'message' => "No new rows inserted. Source options have {$crypto_count} crypto coins and {$news_count} news items — either rows were already present (deduped) or the source options are empty. Wait for the next cron tick or trigger a fetch via the wizard's data steps."
            ) );
        }

        wp_send_json_success( array(
            'message' => sprintf(
                'Inserted %d rows: %d prices, %d news, %d signals. Tables now contain %d / %d / %d rows.',
                $total_inserted, $delta_price, $delta_news, $delta_signals,
                $after_price, $after_news, $after_signals
            ),
        ) );
    }

    // -------------------------------------------------------------------
    // Utility: capture output buffer of a private static render method
    // -------------------------------------------------------------------
    private static function capture_render( $method, ...$args ) {
        ob_start();
        self::$method( ...$args );
        return ob_get_clean();
    }
}
