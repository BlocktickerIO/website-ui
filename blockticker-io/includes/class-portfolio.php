<?php
/**
 * BT_Portfolio — Portfolio Tracker + Price Alerts
 * - Portfolio: localStorage P&L tracker, no account needed
 * - Price Alerts: email alerts when a coin crosses a threshold
 * Shortcodes: [fxlm_portfolio] [fxlm_price_alerts]
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class BT_Portfolio {

    public static function init() {
        add_shortcode( 'fxlm_portfolio',    array( __CLASS__, 'sc_portfolio' ) );
        add_shortcode( 'fxlm_price_alerts', array( __CLASS__, 'sc_price_alerts' ) );
        add_shortcode( 'bt_price_alerts',   array( __CLASS__, 'sc_price_alerts' ) );
        add_shortcode( 'bt_alert_manager',  array( __CLASS__, 'sc_alert_manager' ) );
        add_action( 'wp_ajax_bt_save_alert',        array( __CLASS__, 'ajax_save_alert' ) );
        add_action( 'wp_ajax_nopriv_bt_save_alert', array( __CLASS__, 'ajax_save_alert' ) );
        // v119.21: customise the sender of every wp_mail() call (alert
        // confirmations, alert-fired notifications, news digests, password
        // resets, etc.). Default WP sender is wordpress@<domain>; admin can
        // override via the Credentials page → Email group.
        add_filter( 'wp_mail_from',      array( __CLASS__, 'filter_mail_from' ) );
        add_filter( 'wp_mail_from_name', array( __CLASS__, 'filter_mail_from_name' ) );
        // Cron: check price alerts every 15 minutes
        add_action( 'bt_check_price_alerts', array( __CLASS__, 'check_and_send_alerts' ) );
        // v92: Robust cron heal — clear ALL stale instances of this hook then
        // reschedule fresh. wp_clear_scheduled_hook() removes every occurrence
        // regardless of what schedule name was stored, so the "invalid_schedule"
        // reschedule error cannot persist across page loads.
        if ( ! wp_next_scheduled( 'bt_check_price_alerts' ) ) {
            wp_clear_scheduled_hook( 'bt_check_price_alerts' ); // belt-and-suspenders flush
            wp_schedule_event( time(), 'bt_fifteen_minutes', 'bt_check_price_alerts' );
        } else {
            // Event exists — verify the stored schedule name is still valid.
            $ev = wp_get_scheduled_event( 'bt_check_price_alerts' );
            $schedules = wp_get_schedules();
            if ( $ev && ! isset( $schedules[ $ev->schedule ] ) ) {
                // Schedule name no longer recognised — nuke and recreate.
                wp_clear_scheduled_hook( 'bt_check_price_alerts' );
                wp_schedule_event( time(), 'bt_fifteen_minutes', 'bt_check_price_alerts' );
            }
        }
    }

    /**
     * Filter the From: address on outgoing mail.
     *
     * Falls back to WordPress's default ($from = wordpress@<domain>) only
     * when the operator hasn't set bt_mail_from_email. Validates the address
     * before applying — silently keeps the default for malformed input
     * rather than producing broken envelopes.
     */
    public static function filter_mail_from( $from ) {
        $custom = (string) get_option( 'bt_mail_from_email', '' );
        $custom = trim( $custom );
        return ( $custom && is_email( $custom ) ) ? $custom : $from;
    }

    /**
     * Filter the From: display name on outgoing mail.
     * Defaults to bt_site_name when bt_mail_from_name is unset; falls back
     * to WordPress's default ('WordPress') only if neither is configured.
     */
    public static function filter_mail_from_name( $from_name ) {
        $custom = (string) get_option( 'bt_mail_from_name', '' );
        $custom = trim( wp_strip_all_tags( $custom ) );
        if ( $custom !== '' ) return $custom;
        $site = (string) get_option( 'bt_site_name', '' );
        return $site !== '' ? $site : $from_name;
    }

    // ── PORTFOLIO TRACKER ────────────────────────────────────────────────────
    public static function sc_portfolio( $atts ) {
        $crypto = BT_Widgets::get_json_option( 'bt_crypto_data' );
        $coins  = ! empty( $crypto['coins'] ) ? array_slice( $crypto['coins'], 0, 100 ) : array();

        $coin_opts = '';
        foreach ( $coins as $c ) {
            $coin_opts .= '<option value="' . esc_attr($c['id']) . '" data-price="' . esc_attr($c['current_price']) . '" data-sym="' . esc_attr(strtoupper($c['symbol'])) . '">'
                . esc_html($c['name']) . ' (' . esc_html(strtoupper($c['symbol'])) . ')</option>';
        }

        ob_start(); 
?>
        <div class="bt-portfolio-wrap" id="bt-portfolio">

            <!-- Add holding -->
            <div class="bt-port-add-card">
                <h3 class="bt-port-section-title">➕ <span data-i18n="port.add_holding"><?php _ebt("port.add_holding"); ?></span></h3>
                <div class="bt-port-add-row">
                    <select id="bt-port-coin" class="bt-port-select">
                        <?php echo $coin_opts; ?>
                    </select>
                    <input type="number" id="bt-port-qty" placeholder="<?php echo esc_attr( __bt( "port.ph_qty" ) ); ?>" min="0" step="any" class="bt-port-input">
                    <input type="number" id="bt-port-buy-price" placeholder="<?php echo esc_attr( __bt( "port.ph_buy_price" ) ); ?>" min="0" step="any" class="bt-port-input">
                    <button onclick="btPortAdd()" class="bt-port-add-btn" data-i18n="port.add_btn"><?php _ebt("port.add_btn"); ?></button>
                </div>
            </div>

            <!-- Holdings table -->
            <div class="bt-port-holdings" id="bt-port-holdings">
                <h3 class="bt-port-section-title">📊 <span data-i18n="port.my_portfolio"><?php _ebt("port.my_portfolio"); ?></span></h3>
                <div id="bt-port-empty" style="display:none">
                    <p style="color:var(--bt-text-3);text-align:center;padding:32px" data-i18n="port.no_holdings"><?php _ebt("port.no_holdings"); ?></p>
                </div>
                <div class="bt-port-table-wrap" id="bt-port-table-wrap">
                    <table class="bt-port-table" id="bt-port-table">
                        <thead>
                            <tr>
                                <th data-i18n="port.col.asset"><?php _ebt("port.col.asset"); ?></th><th data-i18n="port.col.qty"><?php _ebt("port.col.qty"); ?></th><th data-i18n="port.col.buy_price"><?php _ebt("port.col.buy_price"); ?></th>
                                <th data-i18n="port.col.current"><?php _ebt("port.col.current"); ?></th><th data-i18n="port.col.value"><?php _ebt("port.col.value"); ?></th><th data-i18n="port.col.pnl"><?php _ebt("port.col.pnl"); ?></th><th></th>
                            </tr>
                        </thead>
                        <tbody id="bt-port-tbody"></tbody>
                    </table>
                </div>
                <!-- Summary -->
                <div class="bt-port-summary" id="bt-port-summary" style="display:none">
                    <div class="bt-port-sum-item">
                        <span data-i18n="port.total_invested"><?php _ebt("port.total_invested"); ?></span>
                        <strong id="bt-port-invested">$0</strong>
                    </div>
                    <div class="bt-port-sum-item">
                        <span data-i18n="port.current_value"><?php _ebt("port.current_value"); ?></span>
                        <strong id="bt-port-value">$0</strong>
                    </div>
                    <div class="bt-port-sum-item">
                        <span data-i18n="port.total_pnl"><?php _ebt("port.total_pnl"); ?></span>
                        <strong id="bt-port-pnl">$0</strong>
                    </div>
                    <div class="bt-port-sum-item">
                        <span data-i18n="port.return"><?php _ebt("port.return"); ?></span>
                        <strong id="bt-port-pct">0%</strong>
                    </div>
                </div>
            </div>
            <p style="font-size:11px;color:var(--bt-text-4);margin-top:12px;text-align:center" data-i18n="port.saved_notice"><?php _ebt("port.saved_notice"); ?></p>
        </div>

        <script>
        (function(){
            var PRICES = {};
            <?php foreach ($coins as $c): ?>
            PRICES['<?php echo esc_js($c['id']); ?>'] = {name:'<?php echo esc_js($c['name']); ?>',sym:'<?php echo esc_js(strtoupper($c['symbol'])); ?>',price:<?php echo floatval($c['current_price']); ?>};
            <?php endforeach; ?>

            function load(){ try{ return JSON.parse(localStorage.getItem('bt_portfolio')||'[]'); }catch(e){ return []; } }
            function save(h){ localStorage.setItem('bt_portfolio', JSON.stringify(h)); }
            function fmt(n){ return n>=1e9?'$'+(n/1e9).toFixed(2)+'B':n>=1e6?'$'+(n/1e6).toFixed(2)+'M':'$'+n.toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2}); }
            function fmtSmall(n){ return n<0.001?'$'+n.toFixed(6):n<1?'$'+n.toFixed(4):fmt(n); }

            window.btPortAdd = function(){
                var sel = document.getElementById('bt-port-coin');
                var id  = sel.value;
                var qty = parseFloat(document.getElementById('bt-port-qty').value);
                var buy = parseFloat(document.getElementById('bt-port-buy-price').value);
                if(!id||!qty||qty<=0||!buy||buy<=0) return alert('Please enter a valid quantity and buy price.');
                var h = load();
                // Check if already exists — update if so
                var existing = h.find(function(x){return x.id===id;});
                if(existing){
                    existing.qty += qty;
                    existing.avgBuy = (existing.avgBuy * (existing.qty - qty) + buy * qty) / existing.qty;
                } else {
                    h.push({id:id,qty:qty,avgBuy:buy,addedAt:Date.now()});
                }
                save(h);
                document.getElementById('bt-port-qty').value='';
                document.getElementById('bt-port-buy-price').value='';
                render();
            };

            window.btPortRemove = function(id){
                save(load().filter(function(x){return x.id!==id;}));
                render();
            };

            function render(){
                var h = load();
                var tbody = document.getElementById('bt-port-tbody');
                var summary = document.getElementById('bt-port-summary');
                var empty = document.getElementById('bt-port-empty');
                var wrap = document.getElementById('bt-port-table-wrap');

                if(!h.length){ tbody.innerHTML=''; summary.style.display='none'; empty.style.display='block'; wrap.style.display='none'; return; }
                empty.style.display='none'; wrap.style.display='block'; summary.style.display='flex';

                var totalInvested=0, totalValue=0;
                tbody.innerHTML = h.map(function(item){
                    var info = PRICES[item.id] || {name:item.id,sym:item.id.toUpperCase(),price:0};
                    var cur  = info.price;
                    var val  = cur * item.qty;
                    var inv  = item.avgBuy * item.qty;
                    var pnl  = val - inv;
                    var pct  = inv > 0 ? (pnl/inv)*100 : 0;
                    var clr  = pnl>=0?'var(--bt-accent)':'var(--bt-danger)';
                    totalInvested += inv; totalValue += val;
                    return '<tr>'
                        +'<td><strong style="color:var(--bt-text)">'+info.sym+'</strong><br><small style="color:var(--bt-text-3)">'+info.name+'</small></td>'
                        +'<td>'+item.qty.toLocaleString()+'</td>'
                        +'<td>'+fmtSmall(item.avgBuy)+'</td>'
                        +'<td>'+fmtSmall(cur)+'</td>'
                        +'<td>'+fmt(val)+'</td>'
                        +'<td style="color:'+clr+'"><strong>'+(pnl>=0?'+':'')+fmt(pnl)+'</strong><br><small>'+(pct>=0?'+':'')+pct.toFixed(2)+'%</small></td>'
                        +'<td><button onclick="btPortRemove(\''+item.id+'\')" style="background:none;border:none;color:var(--bt-text-4);cursor:pointer;font-size:16px;padding:4px" title="Remove">✕</button></td>'
                        +'</tr>';
                }).join('');

                var pnl = totalValue - totalInvested;
                var pct = totalInvested > 0 ? (pnl/totalInvested)*100 : 0;
                var clr = pnl>=0?'var(--bt-accent)':'var(--bt-danger)';
                document.getElementById('bt-port-invested').textContent = fmt(totalInvested);
                document.getElementById('bt-port-value').textContent    = fmt(totalValue);
                document.getElementById('bt-port-pnl').style.color = clr;
                document.getElementById('bt-port-pnl').textContent  = (pnl>=0?'+':'')+fmt(pnl);
                document.getElementById('bt-port-pct').style.color  = clr;
                document.getElementById('bt-port-pct').textContent  = (pct>=0?'+':'')+pct.toFixed(2)+'%';
            }

            document.addEventListener('DOMContentLoaded', render);
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    // ── PRICE ALERTS ─────────────────────────────────────────────────────────
    /* ======================================================================
     * PRICE ALERTS v107.0 — email + webhook, forex + crypto, repeating
     * ====================================================================== */

    /**
     * Alert object shape (stored in bt_price_alerts option array):
     * {
     *   id:          string  md5 unique id
     *   coin_id:     string  e.g. 'bitcoin' or 'EUR/USD'
     *   asset_type:  string  'crypto' | 'forex'
     *   direction:   string  'above' | 'below'
     *   target:      float   price threshold
     *   email:       string
     *   webhook_url: string  optional — POSTed with JSON payload on trigger
     *   repeat_max:  int     0 = fire once, N = re-arm up to N times
     *   repeat_count:int     how many times fired so far
     *   token:       string  management token (lets user list/delete via URL)
     *   created:     int     unix timestamp
     *   fired:       bool
     *   fired_at:    int|null
     * }
     */

    /** [bt_price_alerts] */
    public static function sc_price_alerts( $atts ) {
        $a = shortcode_atts( array( 'show_forex' => 1 ), $atts );

        $crypto = BT_Widgets::get_json_option( 'bt_crypto_data' );
        $coins  = ! empty( $crypto['coins'] ) ? array_slice( $crypto['coins'], 0, 60 ) : array();
        $nonce  = wp_create_nonce( 'bt_price_alert' );

        $opts = '<optgroup label="Crypto">';
        foreach ( $coins as $c ) {
            $opts .= '<option value="' . esc_attr( $c['id'] ) . '" data-price="' . esc_attr( $c['current_price'] ?? 0 ) . '" data-type="crypto">'
                . esc_html( $c['name'] ) . ' — $' . number_format( floatval( $c['current_price'] ?? 0 ), 2 ) . '</option>';
        }
        $opts .= '</optgroup>';

        if ( $a['show_forex'] ) {
            $forex = BT_Widgets::get_json_option( 'bt_forex_data' );
            $pairs = ! empty( $forex['rates'] ) ? $forex['rates'] : ( ! empty( $forex['pairs'] ) ? $forex['pairs'] : array() );
            if ( ! empty( $pairs ) ) {
                $opts .= '<optgroup label="Forex">';
                foreach ( array_slice( (array) $pairs, 0, 20 ) as $pair => $rate ) {
                    if ( is_string( $pair ) && is_numeric( $rate ) ) {
                        $opts .= '<option value="' . esc_attr( $pair ) . '" data-price="' . esc_attr( $rate ) . '" data-type="forex">'
                            . esc_html( $pair ) . ' — ' . number_format( floatval( $rate ), 5 ) . '</option>';
                    }
                }
                $opts .= '</optgroup>';
            }
        }

        ob_start(); ?>
        <div class="bt-alerts-wrap">
            <h3 class="bt-port-section-title">🔔 <?php esc_html_e( 'Price Alerts', 'blockticker' ); ?></h3>
            <p style="font-size:13px;color:var(--bt-text-3);margin-bottom:20px"><?php esc_html_e( 'Get notified by email (and optionally webhook) when an asset crosses your target price.', 'blockticker' ); ?></p>

            <form id="bt-alert-form" class="bt-alert-form">
                <input type="hidden" name="nonce" value="<?php echo esc_attr( $nonce ); ?>">

                <div class="bt-alert-row">
                    <select name="coin_id" class="bt-port-select" id="bt-alert-asset"><?php echo $opts; // phpcs:ignore ?></select>

                    <select name="direction" class="bt-port-select" style="max-width:120px">
                        <option value="above"><?php esc_html_e( 'rises above', 'blockticker' ); ?></option>
                        <option value="below"><?php esc_html_e( 'drops below', 'blockticker' ); ?></option>
                    </select>

                    <input type="number" name="target_price" placeholder="<?php esc_attr_e( 'Target price', 'blockticker' ); ?>"
                           min="0" step="any" class="bt-port-input" required>
                </div>

                <div class="bt-alert-row" style="margin-top:10px">
                    <input type="email" name="email" placeholder="your@email.com" class="bt-port-input" required>

                    <input type="url" name="webhook_url" placeholder="<?php esc_attr_e( 'Webhook URL (optional)', 'blockticker' ); ?>"
                           class="bt-port-input">

                    <select name="repeat_max" class="bt-port-select" style="max-width:140px" title="<?php esc_attr_e( 'Repeat alert', 'blockticker' ); ?>">
                        <option value="0"><?php esc_html_e( 'Fire once', 'blockticker' ); ?></option>
                        <option value="2"><?php esc_html_e( 'Repeat 2×', 'blockticker' ); ?></option>
                        <option value="5"><?php esc_html_e( 'Repeat 5×', 'blockticker' ); ?></option>
                        <option value="99"><?php esc_html_e( 'Always', 'blockticker' ); ?></option>
                    </select>

                    <button type="submit" class="bt-port-add-btn">🔔 <?php esc_html_e( 'Set Alert', 'blockticker' ); ?></button>
                </div>

                <div id="bt-alert-msg" style="display:none;margin-top:12px;padding:10px 16px;border-radius:0;font-size:13px"></div>
                <div id="bt-alert-current" style="margin-top:8px;font-size:12px;color:var(--bt-text-3)"></div>
            </form>

            <p style="font-size:11px;color:var(--bt-text-4);margin-top:12px">
                <?php esc_html_e( 'Alerts checked every 15 minutes. Max 5 active alerts per email address. Use the management link in your confirmation email to view or delete alerts.', 'blockticker' ); ?>
            </p>
        </div>

        <script>
        (function(){
            var sel = document.getElementById('bt-alert-asset');
            var cur = document.getElementById('bt-alert-current');
            function updateCur(){
                var opt = sel.options[sel.selectedIndex];
                var p = opt ? opt.getAttribute('data-price') : null;
                if(p) cur.textContent = '<?php echo esc_js( __( 'Current price', 'blockticker' ) ); ?>: ' +
                    (parseFloat(p) >= 1 ? '$' + parseFloat(p).toLocaleString() : parseFloat(p).toFixed(6));
            }
            sel.addEventListener('change', updateCur);
            updateCur();

            document.getElementById('bt-alert-form').addEventListener('submit', function(e){
                e.preventDefault();
                var msg = document.getElementById('bt-alert-msg');
                msg.style.display = 'none';
                var fd = new FormData(this);
                fd.append('action', 'bt_save_alert');
                fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {method:'POST',body:fd})
                .then(function(r){return r.json();})
                .then(function(d){
                    msg.style.display = 'block';
                    msg.style.background = d.success ? 'rgba(0,255,102,.1)' : 'rgba(255,59,48,.08)';
                    msg.style.border = '1px solid ' + (d.success ? 'rgba(0,255,102,.2)' : 'rgba(255,59,48,.2)');
                    msg.style.color = d.success ? 'var(--bt-accent)' : 'var(--bt-danger)';
                    msg.textContent = (d.success ? '✅ ' : '❌ ') + (d.data || 'Error');
                    if(d.success) this.reset();
                }.bind(this));
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * [bt_alert_manager] — alert management UI.
     *
     * Two access paths:
     *  1. Logged-in user: list of alerts owned by that user (via user_id).
     *     No token required — alerts are discoverable from the account.
     *  2. Token URL: list of alerts matching ?bt_alert_token=… (legacy path
     *     used by email links so anonymous-created alerts are still
     *     manageable). Compatible with pre-v119.21 alerts that have no
     *     user_id field.
     *
     * Both lists merge when a logged-in user clicks a token link — they see
     * their account-owned alerts AND the token-matched alert in one view.
     */
    public static function sc_alert_manager( $atts ) {
        $token   = sanitize_text_field( $_GET['bt_alert_token'] ?? '' );
        $user_id = is_user_logged_in() ? get_current_user_id() : 0;

        if ( empty( $token ) && $user_id <= 0 ) {
            return '<p class="bt-alert-mgr-empty">'
                . esc_html__( 'Sign in to view your alerts, or open the link from your confirmation email.', 'blockticker' )
                . '</p>';
        }

        $alerts = get_option( 'bt_price_alerts', array() );

        // Handle delete action — accept either a token-match or owner-match
        // for authorisation. Both paths still go through the WP nonce.
        if ( isset( $_GET['bt_delete_alert'] ) && check_admin_referer( 'bt_delete_alert', '_alert_nonce' ) ) {
            $del_id = sanitize_text_field( $_GET['bt_delete_alert'] );
            $alerts = array_values( array_filter( $alerts, function( $a ) use ( $del_id, $token, $user_id ) {
                if ( $a['id'] !== $del_id ) return true; // not this one — keep
                $owner_match = ( $user_id > 0 && (int) ( $a['user_id'] ?? 0 ) === $user_id );
                $token_match = ( $token !== '' && ( $a['token'] ?? '' ) === $token );
                // Delete only if requester proves ownership via token OR account.
                return ! ( $owner_match || $token_match );
            } ) );
            update_option( 'bt_price_alerts', $alerts );
        }

        // Build the visible set: union of token-match, owner-match (user_id),
        // and email-match (catches alerts created anonymously by this person
        // before they had an account — common case: visitor sets alert with
        // their email, later signs up with the same address).
        $current_email = '';
        if ( $user_id > 0 ) {
            $u = wp_get_current_user();
            $current_email = strtolower( trim( $u->user_email ?? '' ) );
        }
        $my = array_filter( $alerts, function( $a ) use ( $token, $user_id, $current_email ) {
            if ( $token !== '' && ( $a['token'] ?? '' ) === $token ) return true;
            if ( $user_id > 0 && (int) ( $a['user_id'] ?? 0 ) === $user_id ) return true;
            if ( $current_email !== '' && strtolower( trim( $a['email'] ?? '' ) ) === $current_email ) return true;
            return false;
        } );

        if ( empty( $my ) ) {
            return '<p class="bt-alert-mgr-empty">'
                . esc_html__( 'No active price alerts. Set one from any asset detail page.', 'blockticker' )
                . '</p>';
        }

        ob_start(); ?>
        <div class="bt-alert-mgr">
            <h3><?php esc_html_e( 'Your Price Alerts', 'blockticker' ); ?></h3>
            <table class="bt-alert-mgr-table">
                <thead><tr>
                    <th><?php esc_html_e( 'Asset', 'blockticker' ); ?></th>
                    <th><?php esc_html_e( 'Condition', 'blockticker' ); ?></th>
                    <th><?php esc_html_e( 'Target', 'blockticker' ); ?></th>
                    <th><?php esc_html_e( 'Fires', 'blockticker' ); ?></th>
                    <th><?php esc_html_e( 'Fired', 'blockticker' ); ?></th>
                    <th></th>
                </tr></thead>
                <tbody>
                <?php foreach ( $my as $alert ) :
                    $del_url = add_query_arg( array(
                        'bt_delete_alert' => $alert['id'],
                        '_alert_nonce'    => wp_create_nonce( 'bt_delete_alert' ),
                    ) );
                ?>
                <tr>
                    <td><strong><?php echo esc_html( strtoupper( $alert['coin_id'] ) ); ?></strong></td>
                    <td><?php echo esc_html( $alert['direction'] === 'above' ? '↑ rises above' : '↓ drops below' ); ?></td>
                    <td><?php echo esc_html( number_format( $alert['target'], $alert['asset_type'] === 'forex' ? 5 : 2 ) ); ?></td>
                    <td><?php echo $alert['repeat_max'] > 0 ? esc_html( $alert['repeat_count'] . '/' . $alert['repeat_max'] ) : esc_html__( 'once', 'blockticker' ); ?></td>
                    <td><?php echo $alert['fired'] ? esc_html( human_time_diff( $alert['fired_at'] ) . ' ago' ) : '—'; ?></td>
                    <td><a href="<?php echo esc_url( $del_url ); ?>" class="bt-alert-del"
                           onclick="return confirm('<?php esc_attr_e( 'Delete this alert?', 'blockticker' ); ?>')">
                        🗑 <?php esc_html_e( 'Delete', 'blockticker' ); ?>
                    </a></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <style>
        .bt-alert-mgr{margin:16px 0}.bt-alert-mgr h3{font-size:15px;margin-bottom:10px}
        .bt-alert-mgr-table{width:100%;border-collapse:collapse;font-size:13px}
        .bt-alert-mgr-table th,.bt-alert-mgr-table td{padding:8px 10px;border-bottom:1px solid rgba(255,255,255,.06);text-align:left}
        .bt-alert-mgr-table th{font-size:11px;text-transform:uppercase;color:#888;letter-spacing:.04em}
        .bt-alert-del{color:#d63638;font-size:12px;text-decoration:none}
        .bt-alert-del:hover{text-decoration:underline}
        .bt-alert-mgr-empty{color:#888;font-style:italic}
        </style>
        <?php
        return ob_get_clean();
    }

    public static function ajax_save_alert() {
        if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'bt_price_alert' ) ) {
            wp_send_json_error( 'Security check failed.' );
        }
        $coin_id     = sanitize_text_field( $_POST['coin_id'] ?? '' );
        $direction   = sanitize_text_field( $_POST['direction'] ?? 'above' );
        $target      = floatval( $_POST['target_price'] ?? 0 );
        $email       = sanitize_email( $_POST['email'] ?? '' );
        $webhook_url = esc_url_raw( $_POST['webhook_url'] ?? '' );
        $repeat_max  = min( 99, max( 0, intval( $_POST['repeat_max'] ?? 0 ) ) );

        // v119.21: capture owner user_id when logged in so the alert is
        // discoverable from the user's account/dashboard, not just via the
        // emailed token URL. Anonymous alerts get user_id=0.
        $user_id = is_user_logged_in() ? get_current_user_id() : 0;

        // For logged-in users, default the email to their account address
        // if they didn't supply one — saves a step in the flow.
        if ( $user_id > 0 && ! is_email( $email ) ) {
            $u = wp_get_current_user();
            if ( $u && is_email( $u->user_email ) ) $email = $u->user_email;
        }

        if ( ! $coin_id || ! $target || ! is_email( $email ) ) {
            wp_send_json_error( 'Please fill in all fields correctly.' );
        }

        // Rate limit: 5 active alerts per scope. For logged-in users the
        // scope is the user account (so changing email mid-flow can't
        // bypass the cap); for anonymous users it's the email address.
        $all_alerts = get_option( 'bt_price_alerts', array() );
        if ( $user_id > 0 ) {
            $active_for_owner = array_filter( $all_alerts, function( $a ) use ( $user_id ) {
                return ( ( $a['user_id'] ?? 0 ) === $user_id ) && empty( $a['fired'] );
            } );
        } else {
            $active_for_owner = array_filter( $all_alerts, function( $a ) use ( $email ) {
                return ( ( $a['email'] ?? '' ) === $email ) && empty( $a['fired'] ) && empty( $a['user_id'] );
            } );
        }
        if ( count( $active_for_owner ) >= 5 ) {
            wp_send_json_error( 'You already have 5 active alerts. Manage them at /alerts/ or via the link in your confirmation email.' );
        }

        $token = wp_generate_password( 24, false );
        $id    = md5( $email . $coin_id . $target . time() );

        $alert = array(
            'id'           => $id,
            'user_id'      => $user_id,
            'coin_id'      => $coin_id,
            'asset_type'   => ( stripos( $coin_id, '/' ) !== false ) ? 'forex' : 'crypto',
            'direction'    => in_array( $direction, array( 'above', 'below' ), true ) ? $direction : 'above',
            'target'       => $target,
            'email'        => $email,
            'webhook_url'  => $webhook_url,
            'repeat_max'   => $repeat_max,
            'repeat_count' => 0,
            'token'        => $token,
            'created'      => time(),
            'fired'        => false,
            'fired_at'     => null,
        );
        $all_alerts[] = $alert;
        update_option( 'bt_price_alerts', $all_alerts );

        // Send confirmation email with management link.
        // v119.21: Link points to /alerts/ (the dedicated hub) rather than
        // the generic /tools/ page. The /alerts/ page already exists and
        // class-alerts.php's news alerts have always pointed there;
        // price alerts were inconsistent.
        $mgr_url  = add_query_arg( 'bt_alert_token', $token, home_url( '/alerts/' ) );
        $site     = get_option( 'bt_site_name', 'BlockTicker' );
        $dir_lbl  = $direction === 'above' ? 'rises above' : 'drops below';
        $decimals = $alert['asset_type'] === 'forex' ? 5 : 2;
        $sym      = strtoupper( $coin_id );
        $tgt_fmt  = number_format( $target, $decimals );

        $subject = "[{$site}] Alert set: {$sym} {$dir_lbl} {$tgt_fmt}";

        $rows = array(
            array( 'Asset',     $sym ),
            array( 'Condition', $dir_lbl . ' ' . $tgt_fmt ),
            array( 'Repeat',    $repeat_max > 0 ? "Up to {$repeat_max}×" : 'Once' ),
        );
        $body_html = self::build_alert_html( array(
            'site'    => $site,
            'kicker'  => 'Alert Confirmed',
            'headline'=> "{$sym} alert is active",
            'sub'     => "We'll email you the moment {$sym} " . $dir_lbl . ' ' . $tgt_fmt . '.',
            'rows'    => $rows,
            'cta_url' => $mgr_url,
            'cta_lbl' => 'MANAGE YOUR ALERTS →',
            'accent'  => '#00FF66',
        ) );

        wp_mail( $email, $subject, $body_html, array( 'Content-Type: text/html; charset=UTF-8' ) );

        wp_send_json_success( "Alert set! You'll be emailed when {$sym} {$dir_lbl} {$tgt_fmt}. Check your email for a management link." );
    }

    /**
     * Build dark-themed HTML email body.
     * Matches the visual language of BT_Alerts::build_digest_html() so all
     * BlockTicker emails share one design system. Inline styles only — gmail/outlook
     * strip <style> blocks, so every property has to be on the element.
     *
     * @param array $a {
     *   site:    string  Site name (header)
     *   kicker:  string  Small uppercase label above the headline
     *   headline:string  H1 line
     *   sub:     string  Sub-line under headline (optional)
     *   rows:    array   List of [label, value] pairs rendered as a key/value table
     *   cta_url: string  Primary CTA URL
     *   cta_lbl: string  Primary CTA text
     *   accent:  string  Accent color (default #00FF66 brand green)
     *   triggered: bool  When true, headline gets the warm/red accent (alert fired vs alert set)
     * }
     */
    public static function build_alert_html( $a ) {
        $site     = $a['site']      ?? get_option( 'bt_site_name', 'BlockTicker' );
        $kicker   = $a['kicker']    ?? 'Notification';
        $headline = $a['headline']  ?? '';
        $sub      = $a['sub']       ?? '';
        $rows     = is_array( $a['rows'] ?? null ) ? $a['rows'] : array();
        $cta_url  = $a['cta_url']   ?? '';
        $cta_lbl  = $a['cta_lbl']   ?? 'OPEN BLOCKTICKER →';
        $accent   = $a['accent']    ?? '#00FF66';
        $triggered = ! empty( $a['triggered'] );
        $headline_color = $triggered ? '#FF7A45' : '#ffffff';

        $rows_html = '';
        foreach ( $rows as $r ) {
            $lbl = isset( $r[0] ) ? esc_html( $r[0] ) : '';
            $val = isset( $r[1] ) ? esc_html( $r[1] ) : '';
            $rows_html .= '<tr>'
                . '<td style="padding:10px 16px;border-bottom:1px solid #1a1c20;font-size:11px;color:#888;font-family:Menlo,Monaco,monospace;text-transform:uppercase;letter-spacing:.04em;width:140px">' . $lbl . '</td>'
                . '<td style="padding:10px 16px;border-bottom:1px solid #1a1c20;font-size:14px;color:#fff;font-weight:600">' . $val . '</td>'
                . '</tr>';
        }

        $cta_html = '';
        if ( $cta_url ) {
            $cta_html = '<tr><td style="padding:24px 16px 18px;text-align:center;background:#0e0f12">'
                . '<a href="' . esc_url( $cta_url ) . '" style="display:inline-block;padding:12px 22px;background:' . esc_attr( $accent ) . ';color:#0a0a0a;font-size:12px;font-weight:700;text-decoration:none;font-family:Menlo,Monaco,monospace;letter-spacing:.06em;border-radius:3px">' . esc_html( $cta_lbl ) . '</a>'
                . '</td></tr>';
        }

        $sub_html = $sub ? '<p style="margin:10px 0 0;color:#aaa;font-size:13px;line-height:1.5">' . esc_html( $sub ) . '</p>' : '';

        return '<!doctype html><html><body style="margin:0;padding:0;background:#0a0a0a;font-family:Inter,Arial,sans-serif">'
            . '<table width="100%" cellpadding="0" cellspacing="0" style="background:#0a0a0a"><tr><td align="center" style="padding:24px 12px">'
            . '<table width="600" style="max-width:600px;background:#121316;border:1px solid #1a1c20;border-radius:6px;overflow:hidden" cellpadding="0" cellspacing="0">'
            . '<tr><td style="padding:24px 24px 20px;border-bottom:1px solid #1a1c20">'
            . '<div style="font-size:11px;color:' . esc_attr( $accent ) . ';font-family:Menlo,Monaco,monospace;text-transform:uppercase;letter-spacing:.1em;font-weight:700">' . esc_html( $kicker ) . '</div>'
            . '<h1 style="margin:8px 0 0;color:' . esc_attr( $headline_color ) . ';font-size:22px;font-weight:900;line-height:1.3">' . esc_html( $headline ) . '</h1>'
            . $sub_html
            . '</td></tr>'
            . ( $rows_html ? '<tr><td style="padding:6px 0"><table width="100%" cellpadding="0" cellspacing="0">' . $rows_html . '</table></td></tr>' : '' )
            . $cta_html
            . '<tr><td style="padding:14px 16px;text-align:center;background:#0a0a0a;font-size:11px;color:#555;font-family:Menlo,Monaco,monospace;letter-spacing:.04em">'
            . esc_html( $site ) . ' — Live Markets Intelligence'
            . '</td></tr>'
            . '</table>'
            . '</td></tr></table></body></html>';
    }

    public static function check_and_send_alerts() {
        $alerts = get_option( 'bt_price_alerts', array() );
        if ( empty( $alerts ) ) return;

        // Build price lookup: crypto by coin_id, forex by pair string.
        $prices = array();

        $crypto = BT_Widgets::get_json_option( 'bt_crypto_data' );
        foreach ( $crypto['coins'] ?? array() as $c ) {
            if ( ! empty( $c['id'] ) ) {
                $prices[ $c['id'] ] = floatval( $c['current_price'] ?? 0 );
            }
        }

        $forex = BT_Widgets::get_json_option( 'bt_forex_data' );
        $fx_rates = $forex['rates'] ?? $forex['pairs'] ?? array();
        foreach ( (array) $fx_rates as $pair => $rate ) {
            if ( is_string( $pair ) ) {
                $prices[ $pair ] = floatval( $rate );
            }
        }

        $site    = get_option( 'bt_site_name', 'BlockTicker' );
        $updated = false;

        foreach ( $alerts as &$alert ) {
            // Skip if fully exhausted.
            $max_fires = $alert['repeat_max'] ?? 0;
            $fired_ct  = $alert['repeat_count'] ?? 0;
            if ( $alert['fired'] && $fired_ct > $max_fires ) continue;

            // Enforce re-arm cooldown: don't re-trigger within 15 minutes.
            if ( $alert['fired'] && ( time() - ( $alert['fired_at'] ?? 0 ) ) < 900 ) continue;

            $cur = $prices[ $alert['coin_id'] ] ?? null;
            if ( $cur === null || $cur <= 0 ) continue;

            $triggered = ( $alert['direction'] === 'above' && $cur >= $alert['target'] )
                      || ( $alert['direction'] === 'below' && $cur <= $alert['target'] );

            if ( ! $triggered ) {
                // Reset fired flag if price moved away (allows repeating alerts to re-arm).
                if ( $alert['fired'] && $max_fires > 0 ) {
                    $alert['fired'] = false;
                }
                continue;
            }

            // Fire the alert.
            $dir_lbl  = $alert['direction'] === 'above' ? 'risen above' : 'dropped below';
            $decimals = ( $alert['asset_type'] ?? 'crypto' ) === 'forex' ? 5 : 2;
            $sym      = strtoupper( $alert['coin_id'] );
            $cur_fmt  = number_format( $cur, $decimals );
            $tgt_fmt  = number_format( $alert['target'], $decimals );

            // Email — HTML rich template (matches alert-set design language).
            $subject = "[{$site}] Price Alert: {$sym} has {$dir_lbl} {$tgt_fmt}";
            $detail_url = home_url( '/crypto/' . rawurlencode( $alert['coin_id'] ) . '/' );
            $manage_url = add_query_arg( 'bt_alert_token', $alert['token'] ?? '', home_url( '/alerts/' ) );

            $body_html = self::build_alert_html( array(
                'site'      => $site,
                'kicker'    => 'Price Alert Triggered',
                'headline'  => "{$sym} {$dir_lbl} {$tgt_fmt}",
                'sub'       => "Current price: {$cur_fmt}. Your alert threshold has been crossed.",
                'rows'      => array(
                    array( 'Asset',         $sym ),
                    array( 'Current price', $cur_fmt ),
                    array( 'Threshold',     $tgt_fmt . ' (' . $dir_lbl . ')' ),
                    array( 'Triggered',     gmdate( 'M j, Y · H:i T' ) ),
                ),
                'cta_url'   => $detail_url,
                'cta_lbl'   => 'VIEW LIVE PRICE →',
                'triggered' => true,
            ) );

            // Append a secondary "manage alerts" link below the primary CTA.
            $body_html = str_replace(
                '</table></td></tr></table></body>',
                '</table>'
                . '<p style="color:#666;font-size:11px;margin:14px 0 0;text-align:center">'
                . '<a href="' . esc_url( $manage_url ) . '" style="color:#888;text-decoration:underline">Manage alerts</a>'
                . '</p>'
                . '</td></tr></table></body>',
                $body_html
            );

            wp_mail( $alert['email'], $subject, $body_html, array( 'Content-Type: text/html; charset=UTF-8' ) );

            // Webhook.
            if ( ! empty( $alert['webhook_url'] ) ) {
                $payload = array(
                    'event'     => 'price_alert',
                    'symbol'    => $sym,
                    'direction' => $alert['direction'],
                    'target'    => $alert['target'],
                    'current'   => $cur,
                    'fired_at'  => gmdate( 'c' ),
                    'site'      => $site,
                );
                wp_remote_post( $alert['webhook_url'], array(
                    'timeout'  => 10,
                    'blocking' => false,
                    'headers'  => array( 'Content-Type' => 'application/json' ),
                    'body'     => wp_json_encode( $payload ),
                ) );
            }

            // Log to wp_bt_events.
            if ( class_exists( 'BT_Database' ) ) {
                BT_Database::log_event( 'PRICE_ALERT', $sym, array(
                    'email'     => $alert['email'],
                    'direction' => $alert['direction'],
                    'target'    => $alert['target'],
                    'current'   => $cur,
                    'webhook'   => ! empty( $alert['webhook_url'] ),
                ) );
            }

            // Update alert state.
            $alert['fired']        = true;
            $alert['fired_at']     = time();
            $alert['repeat_count'] = $fired_ct + 1;
            $updated = true;
        }
        unset( $alert );

        if ( $updated ) {
            // Remove exhausted alerts older than 30 days.
            $alerts = array_values( array_filter( $alerts, function( $a ) {
                if ( ! $a['fired'] ) return true;
                $max   = $a['repeat_max'] ?? 0;
                $fired = $a['repeat_count'] ?? 0;
                if ( $fired <= $max ) return true; // still has repeats left
                return ( time() - ( $a['fired_at'] ?? 0 ) ) < 30 * DAY_IN_SECONDS;
            } ) );
            update_option( 'bt_price_alerts', $alerts );
        }
    }


}
