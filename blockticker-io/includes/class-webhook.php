<?php
/**
 * BT_Webhook — Event Delivery Engine.
 *
 * Delivers undelivered rows from wp_bt_events to configured webhook endpoints
 * via HTTP POST. Supports per-event-type endpoint routing, retry with
 * exponential back-off, and a delivery status dashboard in the DB admin screen.
 *
 * Architecture:
 *
 *   Configuration (stored in bt_webhook_config option):
 *     endpoints[] → {url, event_types[], secret, enabled, label}
 *     max_retries  → int (default 3)
 *     timeout      → int seconds (default 10)
 *
 *   Cron: bt_deliver_webhooks (every 15 minutes)
 *     → deliver_pending_batch()
 *     → for each undelivered event, POST to matching endpoints
 *     → on success: delivered = 1
 *     → on failure: attempts++, last_attempt_at = now
 *     → after max_retries: mark delivered = -1 (dead-lettered)
 *
 *   Signature: X-BT-Signature: sha256=HMAC(secret, body)
 *     → matches GitHub/Stripe webhook signature pattern
 *
 *   Admin panel: BlockTicker → 🗄 Database
 *     → endpoint config (add/remove/toggle)
 *     → delivery metrics: pending / delivered / dead-lettered
 *     → recent event log with retry button
 *     → "Send Test Event" to verify endpoint connectivity
 *
 * @package BlockTicker
 * @since   109.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BT_Webhook {

    const CRON_HOOK    = 'bt_deliver_webhooks';
    const CONFIG_KEY   = 'bt_webhook_config';
    const BATCH_SIZE   = 50;   // events per cron run
    const DEAD_LETTER  = -1;   // delivered value for exhausted retries

    /* ------------------------------------------------------------------
     * Bootstrap
     * ------------------------------------------------------------------ */

    public static function init() {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + 60, 'bt_fifteen_minutes', self::CRON_HOOK );
        }
        add_action( self::CRON_HOOK, array( __CLASS__, 'deliver_pending_batch' ) );

        add_action( 'wp_ajax_bt_webhook_save_config',  array( __CLASS__, 'ajax_save_config' ) );
        add_action( 'wp_ajax_bt_webhook_retry_event',  array( __CLASS__, 'ajax_retry_event' ) );
        add_action( 'wp_ajax_bt_webhook_send_test',    array( __CLASS__, 'ajax_send_test' ) );
        add_action( 'wp_ajax_bt_webhook_delete_event', array( __CLASS__, 'ajax_delete_event' ) );
    }

    public static function deactivate() {
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }

    /* ------------------------------------------------------------------
     * Config helpers
     * ------------------------------------------------------------------ */

    public static function get_config(): array {
        $defaults = array(
            'endpoints'   => array(),
            'max_retries' => 3,
            'timeout'     => 10,
        );
        return wp_parse_args( get_option( self::CONFIG_KEY, array() ), $defaults );
    }

    /**
     * Return endpoints that should receive a given event_type.
     */
    private static function matching_endpoints( string $event_type, array $config ): array {
        return array_filter( $config['endpoints'], function( $ep ) use ( $event_type ) {
            if ( empty( $ep['enabled'] ) ) return false;
            if ( empty( $ep['url'] ) || ! filter_var( $ep['url'], FILTER_VALIDATE_URL ) ) return false;
            $types = $ep['event_types'] ?? array();
            return empty( $types ) || in_array( $event_type, $types, true ) || in_array( '*', $types, true );
        } );
    }

    /* ------------------------------------------------------------------
     * Delivery engine
     * ------------------------------------------------------------------ */

    /**
     * Fetch and deliver up to BATCH_SIZE undelivered events.
     *
     * @return array { delivered: int, failed: int, dead_lettered: int }
     */
    public static function deliver_pending_batch(): array {
        global $wpdb;
        $config = self::get_config();

        if ( empty( $config['endpoints'] ) ) {
            return array( 'delivered' => 0, 'failed' => 0, 'dead_lettered' => 0 );
        }

        $max_retries = intval( $config['max_retries'] );
        $table       = $wpdb->prefix . 'bt_events';

        // Fetch undelivered rows (delivered = 0), not yet exhausted.
        // Exponential back-off: skip rows attempted too recently.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, event_type, symbol, payload, attempts, last_attempt_at
             FROM {$table}
             WHERE delivered = 0
               AND attempts < %d
               AND (last_attempt_at IS NULL OR last_attempt_at <= %s)
             ORDER BY created_at ASC
             LIMIT %d",
            $max_retries,
            gmdate( 'Y-m-d H:i:s', time() - self::backoff_seconds( 1 ) ), // at minimum 1 backoff step
            self::BATCH_SIZE
        ), ARRAY_A );

        $stats = array( 'delivered' => 0, 'failed' => 0, 'dead_lettered' => 0 );

        foreach ( $rows as $row ) {
            $endpoints = self::matching_endpoints( $row['event_type'], $config );
            if ( empty( $endpoints ) ) {
                // No endpoint wants this event — mark delivered to avoid retrying forever.
                $wpdb->update( $table, array( 'delivered' => 1 ), array( 'id' => $row['id'] ), array( '%d' ), array( '%d' ) );
                $stats['delivered']++;
                continue;
            }

            $payload_json = $row['payload'];
            $attempts     = intval( $row['attempts'] ) + 1;
            $all_ok       = true;

            foreach ( $endpoints as $ep ) {
                $ok = self::post_to_endpoint( $ep, $row, $payload_json, $config );
                if ( ! $ok ) $all_ok = false;
            }

            if ( $all_ok ) {
                $wpdb->update( $table,
                    array( 'delivered' => 1, 'attempts' => $attempts, 'last_attempt_at' => current_time( 'mysql', true ) ),
                    array( 'id' => $row['id'] ),
                    array( '%d', '%d', '%s' ), array( '%d' )
                );
                $stats['delivered']++;
            } else {
                if ( $attempts >= $max_retries ) {
                    // Dead-letter.
                    $wpdb->update( $table,
                        array( 'delivered' => self::DEAD_LETTER, 'attempts' => $attempts, 'last_attempt_at' => current_time( 'mysql', true ) ),
                        array( 'id' => $row['id'] ),
                        array( '%d', '%d', '%s' ), array( '%d' )
                    );
                    $stats['dead_lettered']++;
                } else {
                    $wpdb->update( $table,
                        array( 'attempts' => $attempts, 'last_attempt_at' => current_time( 'mysql', true ) ),
                        array( 'id' => $row['id'] ),
                        array( '%d', '%s' ), array( '%d' )
                    );
                    $stats['failed']++;
                }
            }
        }

        return $stats;
    }

    /**
     * POST a single event to a single endpoint.
     *
     * @return bool  True if HTTP 2xx response received.
     */
    private static function post_to_endpoint( array $ep, array $row, string $body_json, array $config ): bool {
        $site    = get_option( 'bt_site_name', 'BlockTicker' );
        $envelope = wp_json_encode( array(
            'id'         => intval( $row['id'] ),
            'event_type' => $row['event_type'],
            'symbol'     => $row['symbol'],
            'payload'    => json_decode( $body_json, true ),
            'attempt'    => intval( $row['attempts'] ) + 1,
            'sent_at'    => gmdate( 'c' ),
            'source'     => $site,
        ) );

        $headers = array(
            'Content-Type' => 'application/json',
            'User-Agent'   => 'BlockTicker-Webhook/1.0',
            'X-BT-Event'   => $row['event_type'],
        );

        // HMAC signature if secret configured.
        if ( ! empty( $ep['secret'] ) ) {
            $headers['X-BT-Signature'] = 'sha256=' . hash_hmac( 'sha256', $envelope, $ep['secret'] );
        }

        $response = wp_remote_post( $ep['url'], array(
            'timeout'   => intval( $config['timeout'] ?? 10 ),
            'blocking'  => true,
            'headers'   => $headers,
            'body'      => $envelope,
        ) );

        if ( is_wp_error( $response ) ) return false;

        $code = wp_remote_retrieve_response_code( $response );
        return $code >= 200 && $code < 300;
    }

    /**
     * Back-off schedule: attempt N waits 2^(N-1) × 15 minutes.
     * Attempt 1 → 15 min, attempt 2 → 30 min, attempt 3 → 60 min.
     */
    private static function backoff_seconds( int $attempt ): int {
        return (int) pow( 2, max( 0, $attempt - 1 ) ) * 15 * MINUTE_IN_SECONDS;
    }

    /* ------------------------------------------------------------------
     * AJAX handlers
     * ------------------------------------------------------------------ */

    public static function ajax_save_config() {
        check_ajax_referer( 'bt_db_admin', '_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Insufficient permissions.' );

        $raw     = json_decode( stripslashes( $_POST['config'] ?? '{}' ), true );
        $config  = self::get_config();

        // Merge top-level settings.
        if ( isset( $raw['max_retries'] ) ) $config['max_retries'] = max( 1, min( 10, intval( $raw['max_retries'] ) ) );
        if ( isset( $raw['timeout'] ) )     $config['timeout']     = max( 5, min( 60, intval( $raw['timeout'] ) ) );

        // Sanitise and validate endpoints.
        $endpoints = array();
        foreach ( (array) ( $raw['endpoints'] ?? array() ) as $ep ) {
            $url = esc_url_raw( $ep['url'] ?? '' );
            if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) continue;
            $endpoints[] = array(
                'id'          => sanitize_text_field( $ep['id'] ?? wp_generate_password( 8, false ) ),
                'label'       => sanitize_text_field( $ep['label'] ?? 'Webhook' ),
                'url'         => $url,
                'secret'      => sanitize_text_field( $ep['secret'] ?? '' ),
                'event_types' => array_map( 'sanitize_text_field', (array) ( $ep['event_types'] ?? array() ) ),
                'enabled'     => (bool) ( $ep['enabled'] ?? true ),
            );
        }
        $config['endpoints'] = $endpoints;
        update_option( self::CONFIG_KEY, $config );

        wp_send_json_success( array( 'saved' => count( $endpoints ) . ' endpoint(s)' ) );
    }

    public static function ajax_retry_event() {
        check_ajax_referer( 'bt_db_admin', '_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error();

        global $wpdb;
        $id = intval( $_POST['event_id'] ?? 0 );
        if ( ! $id ) wp_send_json_error( 'Invalid event ID.' );

        // Reset the event so the cron picks it up.
        $updated = $wpdb->update(
            $wpdb->prefix . 'bt_events',
            array( 'delivered' => 0, 'attempts' => 0, 'last_attempt_at' => null ),
            array( 'id' => $id ),
            array( '%d', '%d', null ), array( '%d' )
        );
        wp_send_json_success( array( 'reset' => (bool) $updated ) );
    }

    public static function ajax_send_test() {
        check_ajax_referer( 'bt_db_admin', '_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error();

        $ep_id = sanitize_text_field( $_POST['endpoint_id'] ?? '' );
        $config = self::get_config();

        $ep = null;
        foreach ( $config['endpoints'] as $candidate ) {
            if ( ( $candidate['id'] ?? '' ) === $ep_id ) { $ep = $candidate; break; }
        }
        if ( ! $ep ) wp_send_json_error( 'Endpoint not found.' );

        // Log a test event then deliver it immediately.
        $event_id = BT_Database::log_event( 'BT_WEBHOOK_TEST', 'TEST', array(
            'message'  => 'BlockTicker webhook test event',
            'site'     => home_url(),
            'sent_at'  => gmdate( 'c' ),
        ) );

        if ( ! $event_id ) wp_send_json_error( 'Could not create test event.' );

        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bt_events WHERE id = %d", $event_id
        ), ARRAY_A );

        $ok = self::post_to_endpoint( $ep, $row, $row['payload'], $config );

        if ( $ok ) {
            $wpdb->update( $wpdb->prefix . 'bt_events', array( 'delivered' => 1 ), array( 'id' => $event_id ), array( '%d' ), array( '%d' ) );
            wp_send_json_success( array( 'delivered' => true, 'event_id' => $event_id ) );
        } else {
            wp_send_json_error( 'Delivery failed — check the endpoint URL and that it returns HTTP 2xx.' );
        }
    }

    public static function ajax_delete_event() {
        check_ajax_referer( 'bt_db_admin', '_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error();

        global $wpdb;
        $id = intval( $_POST['event_id'] ?? 0 );
        $wpdb->delete( $wpdb->prefix . 'bt_events', array( 'id' => $id ), array( '%d' ) );
        wp_send_json_success();
    }

    /* ------------------------------------------------------------------
     * Admin panel
     * ------------------------------------------------------------------ */

    public static function admin_panel_html(): string {
        global $wpdb;
        $config = self::get_config();
        $table  = $wpdb->prefix . 'bt_events';
        $nonce  = wp_create_nonce( 'bt_db_admin' );

        // Metrics.
        $pending      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE delivered = 0" );
        $delivered    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE delivered = 1" );
        $dead         = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE delivered = " . self::DEAD_LETTER );

        // Recent events (last 20, any status).
        $recent = $wpdb->get_results(
            "SELECT id, event_type, symbol, delivered, attempts, last_attempt_at, created_at
             FROM {$table}
             ORDER BY created_at DESC LIMIT 20",
            ARRAY_A
        ) ?: array();

        // Known event types for the filter UI.
        $event_types = array( '*', 'PRICE_ALERT', 'BT_WEBHOOK_TEST', 'PRICE_HIGH', 'PRICE_LOW', 'SIGNAL_PUBLISHED', 'NEWS_PUBLISHED' );

        ob_start(); ?>
        <div class="postbox">
            <div class="postbox-header">
                <h2 class="hndle" style="padding:12px 15px;font-size:14px;">
                    🔗 Webhook Event Delivery
                </h2>
            </div>
            <div class="inside">

                <!-- Metrics row -->
                <table class="widefat striped" style="font-size:13px;margin-bottom:16px;">
                    <tbody>
                        <tr>
                            <th>Pending delivery</th>
                            <td><strong style="color:<?php echo $pending > 0 ? '#e6972b' : '#00a32a'; ?>"><?php echo number_format( $pending ); ?></strong></td>
                            <th>Delivered</th>
                            <td><strong style="color:#00a32a;"><?php echo number_format( $delivered ); ?></strong></td>
                            <th>Dead-lettered</th>
                            <td><strong style="color:<?php echo $dead > 0 ? '#d63638' : '#888'; ?>"><?php echo number_format( $dead ); ?></strong></td>
                        </tr>
                        <tr>
                            <th>Endpoints</th>
                            <td><?php echo count( $config['endpoints'] ); ?> configured</td>
                            <th>Max retries</th>
                            <td><?php echo esc_html( $config['max_retries'] ); ?></td>
                            <th>Timeout</th>
                            <td><?php echo esc_html( $config['timeout'] ); ?>s</td>
                        </tr>
                    </tbody>
                </table>

                <!-- Endpoint config -->
                <details style="margin-bottom:14px;">
                    <summary style="font-size:13px;font-weight:600;cursor:pointer;padding:6px 0;">⚙️ Configure Endpoints</summary>
                    <div style="margin-top:12px;padding:14px;background:#f6f7f7;border-radius:0;border:1px solid #ddd;">
                        <div id="bt-wh-endpoints">
                        <?php if ( empty( $config['endpoints'] ) ) : ?>
                            <p style="color:#888;font-size:13px;">No endpoints configured. Add one below.</p>
                        <?php else : ?>
                            <?php foreach ( $config['endpoints'] as $ep ) : ?>
                            <div class="bt-wh-ep-row" style="background:#fff;border:1px solid #ddd;border-radius:0;padding:12px;margin-bottom:10px;">
                                <table width="100%" cellpadding="0" cellspacing="0">
                                    <tr>
                                        <td style="padding:2px 0;font-size:13px;">
                                            <label><input type="checkbox" class="bt-wh-enabled" <?php checked( $ep['enabled'] ?? true ); ?>> Enabled</label>
                                            &nbsp;
                                            <strong><?php echo esc_html( $ep['label'] ?? 'Webhook' ); ?></strong>
                                        </td>
                                        <td align="right">
                                            <button class="button button-small bt-wh-test-btn"
                                                    data-ep-id="<?php echo esc_attr( $ep['id'] ?? '' ); ?>"
                                                    data-nonce="<?php echo esc_attr( $nonce ); ?>">
                                                Test
                                            </button>
                                        </td>
                                    </tr>
                                    <tr><td colspan="2" style="padding-top:6px;font-size:12px;word-break:break-all;color:#0073aa;"><?php echo esc_html( $ep['url'] ); ?></td></tr>
                                    <tr><td colspan="2" style="font-size:11px;color:#888;padding-top:4px;">
                                        Events: <?php echo esc_html( implode( ', ', $ep['event_types'] ?? array( '*' ) ) ); ?>
                                        <?php if ( ! empty( $ep['secret'] ) ) echo ' · Secret: ✓'; ?>
                                    </td></tr>
                                </table>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </div>

                        <!-- Add endpoint form -->
                        <fieldset style="border:1px solid #ddd;border-radius:0;padding:12px;margin-top:10px;">
                            <legend style="font-size:12px;font-weight:600;padding:0 6px;">Add Endpoint</legend>
                            <table style="width:100%;font-size:13px;border-collapse:collapse;">
                                <tr>
                                    <td style="padding:4px 0;"><label>Label<br><input type="text" id="bt-wh-new-label" placeholder="My Webhook" style="width:160px;"></label></td>
                                    <td style="padding:4px 8px;"><label>URL *<br><input type="url" id="bt-wh-new-url" placeholder="https://..." style="width:260px;"></label></td>
                                </tr>
                                <tr>
                                    <td style="padding:4px 0;"><label>HMAC Secret (optional)<br><input type="text" id="bt-wh-new-secret" placeholder="your-secret" style="width:160px;"></label></td>
                                    <td style="padding:4px 8px;"><label>Event types (comma-sep, * = all)<br><input type="text" id="bt-wh-new-types" placeholder="* (all events)" style="width:260px;"></label></td>
                                </tr>
                            </table>
                            <button type="button" class="button button-primary" id="bt-wh-add-btn"
                                    data-nonce="<?php echo esc_attr( $nonce ); ?>"
                                    style="margin-top:8px;">
                                + Add Endpoint
                            </button>
                            <span id="bt-wh-save-status" style="font-size:12px;margin-left:8px;"></span>
                        </fieldset>

                        <p style="font-size:11px;color:#888;margin:8px 0 0;">
                            Payload is signed with <code>X-BT-Signature: sha256=HMAC(secret, body)</code>.
                            Your endpoint should return HTTP 200–299.
                            Retry schedule: 15 min → 30 min → 60 min (then dead-lettered).
                        </p>
                    </div>
                </details>

                <!-- Run delivery now -->
                <div style="margin-bottom:14px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                    <button type="button" class="button button-primary" id="bt-wh-run-btn"
                            data-nonce="<?php echo esc_attr( $nonce ); ?>">
                        ▶ Deliver Pending Now
                    </button>
                    <span id="bt-wh-run-status" style="font-size:13px;"></span>
                </div>

                <!-- Recent event log -->
                <?php if ( ! empty( $recent ) ) : ?>
                <p style="font-size:12px;font-weight:600;margin:0 0 6px;">Recent Events (last 20)</p>
                <div style="overflow-x:auto;">
                    <table class="widefat" style="font-size:12px;">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Type</th>
                                <th>Symbol</th>
                                <th>Status</th>
                                <th>Attempts</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ( $recent as $ev ) :
                            $status_label = $ev['delivered'] == 1 ? '✅ Delivered' : ( $ev['delivered'] == self::DEAD_LETTER ? '💀 Dead-lettered' : '⏳ Pending' );
                            $status_col   = $ev['delivered'] == 1 ? '#00a32a' : ( $ev['delivered'] == self::DEAD_LETTER ? '#d63638' : '#e6972b' );
                        ?>
                            <tr>
                                <td><?php echo esc_html( $ev['id'] ); ?></td>
                                <td><code><?php echo esc_html( $ev['event_type'] ); ?></code></td>
                                <td><?php echo esc_html( $ev['symbol'] ); ?></td>
                                <td style="color:<?php echo esc_attr( $status_col ); ?>;font-weight:600;"><?php echo $status_label; ?></td>
                                <td><?php echo esc_html( $ev['attempts'] ); ?></td>
                                <td style="color:#888;"><?php echo esc_html( human_time_diff( strtotime( $ev['created_at'] ) ) . ' ago' ); ?></td>
                                <td>
                                    <?php if ( $ev['delivered'] != 1 ) : ?>
                                    <button class="button button-small bt-wh-retry-btn"
                                            data-event-id="<?php echo esc_attr( $ev['id'] ); ?>"
                                            data-nonce="<?php echo esc_attr( $nonce ); ?>">Retry</button>
                                    <?php endif; ?>
                                    <button class="button button-small bt-wh-del-btn"
                                            data-event-id="<?php echo esc_attr( $ev['id'] ); ?>"
                                            data-nonce="<?php echo esc_attr( $nonce ); ?>">✕</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else : ?>
                <p style="color:#888;font-size:13px;font-style:italic;">No events logged yet. Events are written by the price alert system and other triggers.</p>
                <?php endif; ?>

            </div><!-- .inside -->
        </div>

        <script>
        (function(){
            var nonce = '<?php echo esc_js( $nonce ); ?>';

            function post(action, extra){
                var fd = new FormData();
                fd.append('action', action);
                fd.append('_nonce', nonce);
                if(extra) Object.keys(extra).forEach(function(k){ fd.append(k, extra[k]); });
                return fetch(ajaxurl, {method:'POST', body:fd}).then(function(r){ return r.json(); });
            }

            // Deliver now.
            document.getElementById('bt-wh-run-btn')?.addEventListener('click', function(){
                var st = document.getElementById('bt-wh-run-status');
                st.textContent = 'Delivering…'; st.style.color = '#888';
                post('bt_webhook_deliver_now').then(function(d){
                    if(d.success){
                        st.style.color='#00a32a';
                        var r=d.data;
                        st.textContent='✅ Delivered: '+(r.delivered||0)+', Failed: '+(r.failed||0)+', Dead-lettered: '+(r.dead_lettered||0);
                    } else { st.style.color='#d63638'; st.textContent='✗ '+(d.data||'Error'); }
                });
            });

            // Retry event.
            document.querySelectorAll('.bt-wh-retry-btn').forEach(function(btn){
                btn.addEventListener('click', function(){
                    post('bt_webhook_retry_event', {event_id: btn.dataset.eventId}).then(function(d){
                        btn.textContent = d.success ? '✓ Reset' : '✗ Error';
                        btn.disabled = true;
                    });
                });
            });

            // Delete event.
            document.querySelectorAll('.bt-wh-del-btn').forEach(function(btn){
                btn.addEventListener('click', function(){
                    if(!confirm('Delete this event?')) return;
                    post('bt_webhook_delete_event', {event_id: btn.dataset.eventId}).then(function(d){
                        if(d.success) btn.closest('tr').remove();
                    });
                });
            });

            // Test endpoint.
            document.querySelectorAll('.bt-wh-test-btn').forEach(function(btn){
                btn.addEventListener('click', function(){
                    btn.disabled = true; btn.textContent = 'Testing…';
                    post('bt_webhook_send_test', {endpoint_id: btn.dataset.epId}).then(function(d){
                        btn.textContent = d.success ? '✓ OK' : '✗ Failed';
                        btn.style.color = d.success ? '#00a32a' : '#d63638';
                    });
                });
            });

            // Add endpoint.
            document.getElementById('bt-wh-add-btn')?.addEventListener('click', function(){
                var url    = document.getElementById('bt-wh-new-url').value.trim();
                var label  = document.getElementById('bt-wh-new-label').value.trim() || 'Webhook';
                var secret = document.getElementById('bt-wh-new-secret').value.trim();
                var types  = document.getElementById('bt-wh-new-types').value.trim();
                var st     = document.getElementById('bt-wh-save-status');

                if(!url){ st.textContent='URL is required.'; st.style.color='#d63638'; return; }

                // Build config with new endpoint appended.
                var currentConfig = <?php echo wp_json_encode( $config ); ?>;
                currentConfig.endpoints.push({
                    id: Math.random().toString(36).slice(2,10),
                    label: label, url: url, secret: secret,
                    event_types: types ? types.split(',').map(function(t){ return t.trim(); }) : ['*'],
                    enabled: true
                });

                post('bt_webhook_save_config', {config: JSON.stringify(currentConfig)}).then(function(d){
                    if(d.success){
                        st.style.color='#00a32a';
                        st.textContent='✅ Saved. Reload to see updated list.';
                    } else { st.style.color='#d63638'; st.textContent='✗ '+(d.data||'Error'); }
                });
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }
}
