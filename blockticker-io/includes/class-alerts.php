<?php
/**
 * BT_Alerts — Unified alerts subsystem
 *
 * Adds:
 *   • News alerts — keyword-based hourly digest email (NEW in v119.9)
 *   • Unified alerts hub — single page combining price + news forms
 *   • Contextual "Set Alert" button — drops onto any asset page via shortcode
 *   • Admin overview — ops view of all active alerts + recent firings
 *
 * Reuses the existing price-alert plumbing in BT_Portfolio. Storage layouts:
 *   bt_price_alerts  — array of price alert objects (managed by BT_Portfolio)
 *   bt_news_alerts   — array of news alert objects (managed here)
 *
 * News-alert object shape:
 * {
 *   id:          string  md5 unique id
 *   keywords:    array   normalized lower-case keyword list (1-5 entries)
 *   match_mode:  string  'any' | 'all'   how multi-keyword filter combines
 *   sources:     array   optional list of source names to restrict to (empty = all)
 *   email:       string
 *   frequency:   string  'instant' | 'hourly' | 'daily'
 *   token:       string  management token for the [bt_alert_manager] page
 *   created:     int     unix ts
 *   last_sent:   int     unix ts of last digest sent
 *   last_seen:   int     ts of newest article already included in a digest
 *   match_count: int     lifetime articles matched
 * }
 *
 * @since 119.9.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BT_Alerts {

    /** Cap on active news alerts per email. */
    const MAX_ACTIVE_PER_EMAIL = 5;

    /** Cap on keywords per single news alert. */
    const MAX_KEYWORDS = 5;

    /** Min characters per keyword (avoid spam matches on "a", "of"). */
    const MIN_KEYWORD_LEN = 3;

    /** Re-arm cooldown for instant alerts (seconds). */
    const INSTANT_COOLDOWN = 1800; // 30 min

    public static function init() {
        // Shortcodes.
        add_shortcode( 'bt_news_alerts',   array( __CLASS__, 'sc_news_alerts' ) );
        add_shortcode( 'bt_alert_hub',     array( __CLASS__, 'sc_alert_hub' ) );
        add_shortcode( 'bt_set_alert_btn', array( __CLASS__, 'sc_set_alert_btn' ) );

        // AJAX handlers.
        add_action( 'wp_ajax_bt_save_news_alert',        array( __CLASS__, 'ajax_save_news_alert' ) );
        add_action( 'wp_ajax_nopriv_bt_save_news_alert', array( __CLASS__, 'ajax_save_news_alert' ) );

        // Cron — hourly news digest.
        add_action( 'bt_check_news_alerts', array( __CLASS__, 'check_and_send_news_digests' ) );
        add_action( 'init', array( __CLASS__, 'maybe_schedule_cron' ), 30 );

        // Admin page.
        add_action( 'admin_menu', array( __CLASS__, 'register_admin_menu' ), 20 );
        add_action( 'admin_post_bt_delete_alert_admin', array( __CLASS__, 'admin_handle_delete' ) );

        // Footer-injected modal (loaded once per page that uses sc_set_alert_btn).
        add_action( 'wp_footer', array( __CLASS__, 'maybe_render_modal' ), 5 );
    }

    /* ============================================================
     *  Cron scheduling
     * ============================================================ */

    public static function maybe_schedule_cron() {
        if ( ! wp_next_scheduled( 'bt_check_news_alerts' ) ) {
            wp_schedule_event( time() + 60, 'hourly', 'bt_check_news_alerts' );
        }
    }

    /* ============================================================
     *  Shortcode: [bt_news_alerts]  — news alert form
     * ============================================================ */

    public static function sc_news_alerts( $atts = array() ) {
        $atts  = shortcode_atts( array( 'compact' => 0 ), $atts );
        $nonce = wp_create_nonce( 'bt_news_alert' );

        // Build source list from configured RSS feeds (top 12 by name).
        $sources = self::get_known_sources();

        ob_start(); ?>
        <div class="bt-alerts-wrap bt-news-alerts-wrap">
            <?php if ( ! $atts['compact'] ) : ?>
            <h3 class="bt-port-section-title">📰 <?php esc_html_e( 'News Alerts', 'blockticker' ); ?></h3>
            <p class="bt-alerts-intro">
                <?php esc_html_e( 'Get an email digest when articles match your keywords. Track tokens, themes (e.g. "ETF", "regulation"), or any phrase.', 'blockticker' ); ?>
            </p>
            <?php endif; ?>

            <form id="bt-news-alert-form" class="bt-alert-form" novalidate>
                <input type="hidden" name="nonce" value="<?php echo esc_attr( $nonce ); ?>">

                <div class="bt-alert-row">
                    <input type="text" name="keywords"
                        class="bt-port-input bt-alert-kw-input"
                        placeholder="<?php esc_attr_e( 'Bitcoin, ETF, regulation (comma-separated, max 5)', 'blockticker' ); ?>"
                        required maxlength="200">
                </div>

                <div class="bt-alert-row bt-alert-row-2col">
                    <select name="match_mode" class="bt-port-select" title="<?php esc_attr_e( 'How keywords combine', 'blockticker' ); ?>">
                        <option value="any"><?php esc_html_e( 'Match ANY keyword', 'blockticker' ); ?></option>
                        <option value="all"><?php esc_html_e( 'Match ALL keywords', 'blockticker' ); ?></option>
                    </select>

                    <select name="frequency" class="bt-port-select">
                        <option value="hourly"><?php esc_html_e( 'Hourly digest', 'blockticker' ); ?></option>
                        <option value="daily"><?php esc_html_e( 'Daily digest', 'blockticker' ); ?></option>
                        <option value="instant"><?php esc_html_e( 'Instant (max 1/30min)', 'blockticker' ); ?></option>
                    </select>
                </div>

                <details class="bt-alert-advanced">
                    <summary><?php esc_html_e( 'Restrict to sources (optional)', 'blockticker' ); ?></summary>
                    <div class="bt-alert-source-grid">
                        <?php foreach ( $sources as $src ) : ?>
                        <label class="bt-alert-src-chk">
                            <input type="checkbox" name="sources[]" value="<?php echo esc_attr( $src ); ?>">
                            <span><?php echo esc_html( $src ); ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </details>

                <div class="bt-alert-row bt-alert-row-2col" style="margin-top:10px">
                    <input type="email" name="email" placeholder="your@email.com" class="bt-port-input" required>
                    <button type="submit" class="bt-port-add-btn">📰 <?php esc_html_e( 'Set News Alert', 'blockticker' ); ?></button>
                </div>

                <div id="bt-news-alert-msg" class="bt-alert-msg" style="display:none"></div>
            </form>

            <p class="bt-alerts-foot">
                <?php
                printf(
                    /* translators: %d = max */
                    esc_html__( 'Hourly/daily digests grouped per recipient. Max %d active news alerts per email. Manage via the link in your confirmation email.', 'blockticker' ),
                    self::MAX_ACTIVE_PER_EMAIL
                );
                ?>
            </p>
        </div>

        <script>
        (function(){
            var form = document.getElementById('bt-news-alert-form');
            if (!form) return;
            form.addEventListener('submit', function(e){
                e.preventDefault();
                var msg = document.getElementById('bt-news-alert-msg');
                msg.style.display = 'none';
                var fd = new FormData(form);
                fd.append('action', 'bt_save_news_alert');
                fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {method:'POST', body:fd})
                    .then(function(r){ return r.json(); })
                    .then(function(d){
                        msg.style.display = 'block';
                        msg.className = 'bt-alert-msg ' + (d.success ? 'bt-alert-msg-ok' : 'bt-alert-msg-err');
                        msg.textContent = (d.success ? '✓ ' : '✕ ') + (d.data || 'Error');
                        if (d.success) form.reset();
                    })
                    .catch(function(){
                        msg.style.display = 'block';
                        msg.className = 'bt-alert-msg bt-alert-msg-err';
                        msg.textContent = '✕ Network error — please try again.';
                    });
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    /* ============================================================
     *  Shortcode: [bt_alert_hub]  — combined price + news tabs
     * ============================================================ */

    public static function sc_alert_hub( $atts = array() ) {
        ob_start(); ?>
        <div class="bt-alert-hub">
            <div class="bt-alert-hub-tabs" role="tablist">
                <button type="button" class="bt-alert-hub-tab is-active" data-tab="price" role="tab" aria-selected="true">
                    🔔 <?php esc_html_e( 'Price Alerts', 'blockticker' ); ?>
                </button>
                <button type="button" class="bt-alert-hub-tab" data-tab="news" role="tab" aria-selected="false">
                    📰 <?php esc_html_e( 'News Alerts', 'blockticker' ); ?>
                </button>
                <button type="button" class="bt-alert-hub-tab" data-tab="manage" role="tab" aria-selected="false">
                    ⚙ <?php esc_html_e( 'Manage', 'blockticker' ); ?>
                </button>
            </div>

            <div class="bt-alert-hub-panel is-active" data-panel="price">
                <?php echo do_shortcode( '[bt_price_alerts]' ); ?>
            </div>
            <div class="bt-alert-hub-panel" data-panel="news" hidden>
                <?php echo self::sc_news_alerts(); ?>
            </div>
            <div class="bt-alert-hub-panel" data-panel="manage" hidden>
                <?php echo do_shortcode( '[bt_alert_manager]' ); ?>
            </div>
        </div>

        <script>
        (function(){
            var tabs = document.querySelectorAll('.bt-alert-hub-tab');
            tabs.forEach(function(btn){
                btn.addEventListener('click', function(){
                    var t = btn.getAttribute('data-tab');
                    tabs.forEach(function(b){
                        var active = b.getAttribute('data-tab') === t;
                        b.classList.toggle('is-active', active);
                        b.setAttribute('aria-selected', active ? 'true' : 'false');
                    });
                    document.querySelectorAll('.bt-alert-hub-panel').forEach(function(p){
                        var active = p.getAttribute('data-panel') === t;
                        p.classList.toggle('is-active', active);
                        if (active) { p.removeAttribute('hidden'); } else { p.setAttribute('hidden',''); }
                    });
                });
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    /* ============================================================
     *  Shortcode: [bt_set_alert_btn coin_id="..." symbol="..." name="..."]
     *  Drops a button on asset pages that opens the price-alert form pre-filled.
     * ============================================================ */

    public static function sc_set_alert_btn( $atts ) {
        $atts = shortcode_atts( array(
            'coin_id' => '',
            'symbol'  => '',
            'name'    => '',
            'price'   => '',
            'type'    => 'crypto',
        ), $atts );

        if ( empty( $atts['coin_id'] ) ) return '';

        // Flag so the modal renders in the footer.
        global $bt_alert_modal_needed;
        $bt_alert_modal_needed = true;

        $label = sprintf(
            /* translators: %s = symbol */
            __( 'Set alert for %s', 'blockticker' ),
            $atts['symbol'] ?: $atts['coin_id']
        );

        return sprintf(
            '<button type="button" class="bt-set-alert-btn" data-coin="%s" data-symbol="%s" data-name="%s" data-price="%s" data-type="%s" aria-label="%s">🔔 <span>%s</span></button>',
            esc_attr( $atts['coin_id'] ),
            esc_attr( $atts['symbol'] ),
            esc_attr( $atts['name'] ),
            esc_attr( $atts['price'] ),
            esc_attr( $atts['type'] ),
            esc_attr( $label ),
            esc_html__( 'Set Alert', 'blockticker' )
        );
    }

    /* ============================================================
     *  Footer modal — rendered once on pages that include sc_set_alert_btn
     * ============================================================ */

    public static function maybe_render_modal() {
        global $bt_alert_modal_needed;
        if ( empty( $bt_alert_modal_needed ) ) return;

        $nonce = wp_create_nonce( 'bt_price_alert' );
        ?>
        <div id="bt-alert-modal" class="bt-alert-modal" role="dialog" aria-modal="true" aria-labelledby="bt-alert-modal-title" hidden>
            <div class="bt-alert-modal-backdrop" data-close></div>
            <div class="bt-alert-modal-card" role="document">
                <button type="button" class="bt-alert-modal-x" data-close aria-label="<?php esc_attr_e( 'Close', 'blockticker' ); ?>">×</button>
                <h3 id="bt-alert-modal-title" class="bt-alert-modal-title">🔔 <?php esc_html_e( 'Set Price Alert', 'blockticker' ); ?></h3>
                <p class="bt-alert-modal-sub" id="bt-alert-modal-sub"></p>

                <form id="bt-alert-modal-form" class="bt-alert-form" novalidate>
                    <input type="hidden" name="nonce" value="<?php echo esc_attr( $nonce ); ?>">
                    <input type="hidden" name="coin_id" id="bt-alert-modal-coinid">

                    <div class="bt-alert-row bt-alert-row-2col">
                        <select name="direction" class="bt-port-select">
                            <option value="above"><?php esc_html_e( 'rises above', 'blockticker' ); ?></option>
                            <option value="below"><?php esc_html_e( 'drops below', 'blockticker' ); ?></option>
                        </select>
                        <input type="number" name="target_price" id="bt-alert-modal-target"
                            placeholder="<?php esc_attr_e( 'Target price', 'blockticker' ); ?>"
                            min="0" step="any" class="bt-port-input" required>
                    </div>

                    <div class="bt-alert-row bt-alert-row-2col" style="margin-top:10px">
                        <input type="email" name="email" placeholder="your@email.com" class="bt-port-input" required>
                        <select name="repeat_max" class="bt-port-select">
                            <option value="0"><?php esc_html_e( 'Fire once', 'blockticker' ); ?></option>
                            <option value="2"><?php esc_html_e( 'Repeat 2×', 'blockticker' ); ?></option>
                            <option value="5"><?php esc_html_e( 'Repeat 5×', 'blockticker' ); ?></option>
                            <option value="99"><?php esc_html_e( 'Always', 'blockticker' ); ?></option>
                        </select>
                    </div>

                    <input type="hidden" name="webhook_url" value="">

                    <button type="submit" class="bt-port-add-btn bt-alert-modal-submit">
                        🔔 <?php esc_html_e( 'Set Alert', 'blockticker' ); ?>
                    </button>

                    <div id="bt-alert-modal-msg" class="bt-alert-msg" style="display:none"></div>
                </form>

                <p class="bt-alert-modal-foot">
                    <?php esc_html_e( 'Want news alerts on this asset too?', 'blockticker' ); ?>
                    <a href="<?php echo esc_url( home_url( '/alerts/' ) ); ?>"><?php esc_html_e( 'Visit the Alerts hub →', 'blockticker' ); ?></a>
                </p>
            </div>
        </div>

        <script>
        (function(){
            var modal  = document.getElementById('bt-alert-modal');
            var subEl  = document.getElementById('bt-alert-modal-sub');
            var idEl   = document.getElementById('bt-alert-modal-coinid');
            var tgtEl  = document.getElementById('bt-alert-modal-target');
            var msgEl  = document.getElementById('bt-alert-modal-msg');
            var formEl = document.getElementById('bt-alert-modal-form');

            function open(coin, symbol, name, price){
                idEl.value = coin;
                subEl.textContent = name + (symbol ? ' (' + symbol + ')' : '') +
                    (price ? ' — current $' + parseFloat(price).toLocaleString(undefined,{maximumFractionDigits:8}) : '');
                if (price) { tgtEl.placeholder = parseFloat(price).toFixed(parseFloat(price) < 1 ? 6 : 2); }
                msgEl.style.display = 'none';
                modal.removeAttribute('hidden');
                document.body.style.overflow = 'hidden';
                setTimeout(function(){ tgtEl.focus(); }, 50);
            }
            function close(){
                modal.setAttribute('hidden','');
                document.body.style.overflow = '';
            }

            document.addEventListener('click', function(ev){
                var btn = ev.target.closest('.bt-set-alert-btn');
                if (btn) {
                    ev.preventDefault();
                    open(btn.dataset.coin, btn.dataset.symbol, btn.dataset.name, btn.dataset.price);
                    return;
                }
                if (ev.target.closest('[data-close]')) {
                    close();
                }
            });
            document.addEventListener('keydown', function(ev){
                if (ev.key === 'Escape' && !modal.hasAttribute('hidden')) close();
            });

            formEl.addEventListener('submit', function(e){
                e.preventDefault();
                msgEl.style.display = 'none';
                var fd = new FormData(formEl);
                fd.append('action', 'bt_save_alert');
                fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {method:'POST', body:fd})
                    .then(function(r){ return r.json(); })
                    .then(function(d){
                        msgEl.style.display = 'block';
                        msgEl.className = 'bt-alert-msg ' + (d.success ? 'bt-alert-msg-ok' : 'bt-alert-msg-err');
                        msgEl.textContent = (d.success ? '✓ ' : '✕ ') + (d.data || 'Error');
                        if (d.success) {
                            setTimeout(close, 2500);
                            formEl.reset();
                        }
                    })
                    .catch(function(){
                        msgEl.style.display = 'block';
                        msgEl.className = 'bt-alert-msg bt-alert-msg-err';
                        msgEl.textContent = '✕ Network error — please try again.';
                    });
            });
        })();
        </script>
        <?php
    }

    /* ============================================================
     *  AJAX: save news alert
     * ============================================================ */

    public static function ajax_save_news_alert() {
        if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'bt_news_alert' ) ) {
            wp_send_json_error( __( 'Security check failed.', 'blockticker' ) );
        }

        $email = sanitize_email( $_POST['email'] ?? '' );
        if ( ! is_email( $email ) ) {
            wp_send_json_error( __( 'Please enter a valid email address.', 'blockticker' ) );
        }

        // Parse + sanitize keywords.
        $raw_kw   = sanitize_text_field( $_POST['keywords'] ?? '' );
        $keywords = array_filter( array_map( 'trim', explode( ',', strtolower( $raw_kw ) ) ) );
        $keywords = array_values( array_filter( $keywords, function( $k ) {
            return strlen( $k ) >= self::MIN_KEYWORD_LEN;
        } ) );
        $keywords = array_slice( array_unique( $keywords ), 0, self::MAX_KEYWORDS );

        if ( empty( $keywords ) ) {
            wp_send_json_error( sprintf(
                /* translators: %d = min length */
                __( 'Enter at least one keyword (min %d characters).', 'blockticker' ),
                self::MIN_KEYWORD_LEN
            ) );
        }

        $match_mode = ( $_POST['match_mode'] ?? 'any' ) === 'all' ? 'all' : 'any';
        $frequency  = in_array( $_POST['frequency'] ?? 'hourly', array( 'instant', 'hourly', 'daily' ), true )
            ? $_POST['frequency'] : 'hourly';

        $sources = array();
        if ( ! empty( $_POST['sources'] ) && is_array( $_POST['sources'] ) ) {
            $sources = array_map( 'sanitize_text_field', $_POST['sources'] );
            $sources = array_slice( $sources, 0, 20 );
        }

        // Rate limit.
        $all = self::get_news_alerts();
        $for_email = array_filter( $all, function( $a ) use ( $email ) {
            return ( $a['email'] ?? '' ) === $email;
        } );
        if ( count( $for_email ) >= self::MAX_ACTIVE_PER_EMAIL ) {
            wp_send_json_error( sprintf(
                /* translators: %d = max */
                __( 'You already have %d active news alerts. Manage them via the link in your confirmation email.', 'blockticker' ),
                self::MAX_ACTIVE_PER_EMAIL
            ) );
        }

        $token = wp_generate_password( 24, false );
        $id    = md5( $email . implode( ',', $keywords ) . microtime( true ) );

        $alert = array(
            'id'          => $id,
            'keywords'    => $keywords,
            'match_mode'  => $match_mode,
            'sources'     => $sources,
            'email'       => $email,
            'frequency'   => $frequency,
            'token'       => $token,
            'created'     => time(),
            'last_sent'   => 0,
            'last_seen'   => time(), // only match articles published after this
            'match_count' => 0,
        );

        $all[] = $alert;
        update_option( 'bt_news_alerts', $all );

        // Confirmation email — HTML rich template.
        $site    = get_option( 'bt_site_name', 'BlockTicker' );
        $mgr_url = add_query_arg( 'bt_alert_token', $token, home_url( '/alerts/' ) );
        $subject = sprintf( '[%s] News alert set: %s', $site, implode( ', ', $keywords ) );

        $rows = array(
            array( 'Keywords',  implode( ', ', $keywords ) ),
            array( 'Match',     $match_mode === 'all' ? 'All keywords required' : 'Any keyword' ),
            array( 'Frequency', ucfirst( $frequency ) ),
        );
        if ( $sources ) {
            $rows[] = array( 'Sources', implode( ', ', $sources ) );
        }

        $body_html = '';
        if ( class_exists( 'BT_Portfolio' ) ) {
            $body_html = BT_Portfolio::build_alert_html( array(
                'site'    => $site,
                'kicker'  => 'News Alert Active',
                'headline'=> 'Your news alert is live',
                'sub'     => "We'll deliver matching stories as a {$frequency} digest.",
                'rows'    => $rows,
                'cta_url' => $mgr_url,
                'cta_lbl' => 'MANAGE YOUR ALERTS →',
            ) );
        }

        if ( $body_html ) {
            wp_mail( $email, $subject, $body_html, array( 'Content-Type: text/html; charset=UTF-8' ) );
        } else {
            // Fallback if BT_Portfolio isn't loaded for some reason.
            $body = "Your news alert has been set.\n\n"
                  . 'Keywords: ' . implode( ', ', $keywords ) . "\n"
                  . 'Mode: match ' . $match_mode . "\n"
                  . 'Frequency: ' . $frequency . "\n"
                  . ( $sources ? 'Sources: ' . implode( ', ', $sources ) . "\n" : '' )
                  . "\nManage your alerts: " . $mgr_url
                  . "\n\n-- " . $site;
            wp_mail( $email, $subject, $body );
        }

        wp_send_json_success( sprintf(
            /* translators: 1: keywords 2: frequency */
            __( 'News alert active for: %1$s. You\'ll get a %2$s digest. Check your email for the management link.', 'blockticker' ),
            implode( ', ', $keywords ),
            $frequency
        ) );
    }

    /* ============================================================
     *  Cron: build + send news digests
     * ============================================================ */

    public static function check_and_send_news_digests() {
        $alerts = self::get_news_alerts();
        if ( empty( $alerts ) ) return;

        $news = get_option( 'bt_news_items', array() );
        if ( empty( $news ) || ! is_array( $news ) ) return;

        $now     = time();
        $site    = get_option( 'bt_site_name', 'BlockTicker' );
        $updated = false;

        foreach ( $alerts as &$alert ) {
            // Determine cadence window.
            $freq    = $alert['frequency'] ?? 'hourly';
            $elapsed = $now - ( $alert['last_sent'] ?? 0 );
            $due = false;
            if ( $freq === 'instant' ) {
                $due = $elapsed >= self::INSTANT_COOLDOWN;
            } elseif ( $freq === 'hourly' ) {
                $due = $elapsed >= 3500; // ~hourly with 100s slack for cron jitter
            } elseif ( $freq === 'daily' ) {
                $due = $elapsed >= 23 * HOUR_IN_SECONDS;
            }
            if ( ! $due ) continue;

            $matches = self::find_matches( $news, $alert );
            if ( empty( $matches ) ) {
                // Even if no matches, advance last_seen so next cycle starts fresh.
                continue;
            }

            // Send the digest.
            $sent_ok = self::send_digest_email( $alert, $matches, $site );
            if ( ! $sent_ok ) continue;

            // Webhook log → wp_bt_events for ops visibility.
            if ( class_exists( 'BT_Database' ) ) {
                BT_Database::log_event( 'NEWS_ALERT', implode( ',', $alert['keywords'] ), array(
                    'email'     => $alert['email'],
                    'matched'   => count( $matches ),
                    'frequency' => $freq,
                ) );
            }

            $alert['last_sent']    = $now;
            $alert['last_seen']    = isset( $matches[0]['timestamp'] ) ? max( $alert['last_seen'], (int) $matches[0]['timestamp'] ) : $now;
            $alert['match_count']  = ( $alert['match_count'] ?? 0 ) + count( $matches );
            $updated = true;
        }
        unset( $alert );

        if ( $updated ) {
            update_option( 'bt_news_alerts', $alerts );
        }
    }

    /**
     * Filter news items for a single alert. Returns matched items newest-first,
     * limited to 25 per digest.
     */
    private static function find_matches( $news, $alert ) {
        $kws        = $alert['keywords'] ?? array();
        $mode       = $alert['match_mode'] ?? 'any';
        $sources    = $alert['sources'] ?? array();
        $last_seen  = (int) ( $alert['last_seen'] ?? 0 );

        $out = array();
        foreach ( $news as $item ) {
            $ts = isset( $item['timestamp'] ) ? (int) $item['timestamp'] : 0;
            if ( $ts <= $last_seen ) continue;

            if ( ! empty( $sources ) && ! in_array( $item['source'] ?? '', $sources, true ) ) continue;

            $hay = strtolower( ( $item['title'] ?? '' ) . ' ' . ( $item['description'] ?? '' ) );
            $hits = 0;
            foreach ( $kws as $kw ) {
                if ( $kw !== '' && strpos( $hay, $kw ) !== false ) $hits++;
            }
            $matched = ( $mode === 'all' ) ? ( $hits === count( $kws ) ) : ( $hits > 0 );
            if ( $matched ) $out[] = $item;
        }

        // Newest first (some feeds aren't sorted).
        usort( $out, function( $a, $b ) {
            return ( $b['timestamp'] ?? 0 ) <=> ( $a['timestamp'] ?? 0 );
        } );

        return array_slice( $out, 0, 25 );
    }

    private static function send_digest_email( $alert, $matches, $site ) {
        $kws_disp = implode( ', ', $alert['keywords'] );
        $mgr_url  = add_query_arg( 'bt_alert_token', $alert['token'] ?? '', home_url( '/alerts/' ) );

        $subject = sprintf( '[%s] %d new article%s for: %s',
            $site,
            count( $matches ),
            count( $matches ) === 1 ? '' : 's',
            $kws_disp
        );

        // Plain-text body.
        $lines = array(
            sprintf( 'BlockTicker News Digest — %s', $kws_disp ),
            str_repeat( '=', 60 ),
            '',
        );
        foreach ( $matches as $i => $item ) {
            $when = isset( $item['timestamp'] ) ? gmdate( 'Y-m-d H:i T', (int) $item['timestamp'] ) : '';
            $lines[] = sprintf( '%d. %s', $i + 1, wp_strip_all_tags( $item['title'] ?? '' ) );
            $lines[] = sprintf( '   %s — %s', $item['source'] ?? '', $when );
            $lines[] = '   ' . ( $item['link'] ?? '' );
            $lines[] = '';
        }
        $lines[] = str_repeat( '-', 60 );
        $lines[] = 'Manage your alerts: ' . $mgr_url;
        $lines[] = '-- ' . $site;
        $body_text = implode( "\n", $lines );

        // HTML body.
        $body_html = self::build_digest_html( $alert, $matches, $site, $mgr_url );
        $headers   = array( 'Content-Type: text/html; charset=UTF-8' );

        return wp_mail( $alert['email'], $subject, $body_html, $headers );
    }

    private static function build_digest_html( $alert, $matches, $site, $mgr_url ) {
        $kws_disp = esc_html( implode( ', ', $alert['keywords'] ) );
        $rows = '';
        foreach ( $matches as $item ) {
            $when = isset( $item['timestamp'] ) ? gmdate( 'M j, H:i T', (int) $item['timestamp'] ) : '';
            $rows .= sprintf(
                '<tr><td style="padding:14px 16px;border-bottom:1px solid #1a1c20">'
              . '<div style="font-size:11px;color:#00FF66;font-family:Menlo,Monaco,monospace;text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px">%s · %s</div>'
              . '<a href="%s" style="color:#fff;text-decoration:none;font-weight:600;font-size:15px;line-height:1.4">%s</a>'
              . '</td></tr>',
                esc_html( $item['source'] ?? '' ),
                esc_html( $when ),
                esc_url( $item['link'] ?? '' ),
                esc_html( wp_strip_all_tags( $item['title'] ?? '' ) )
            );
        }

        return '<!doctype html><html><body style="margin:0;padding:0;background:#0a0a0a;font-family:Inter,Arial,sans-serif">'
             . '<table width="100%" cellpadding="0" cellspacing="0" style="background:#0a0a0a"><tr><td align="center" style="padding:24px 12px">'
             . '<table width="600" style="max-width:600px;background:#121316;border:1px solid #1a1c20" cellpadding="0" cellspacing="0">'
             . '<tr><td style="padding:24px;border-bottom:1px solid #1a1c20">'
             . '<div style="font-size:12px;color:#00FF66;font-family:Menlo,Monaco,monospace;text-transform:uppercase;letter-spacing:.08em">News Digest</div>'
             . '<h1 style="margin:6px 0 0;color:#fff;font-size:22px;font-weight:900">' . esc_html( $site ) . '</h1>'
             . '<p style="margin:10px 0 0;color:#888;font-size:13px">Matching: <strong style="color:#00FF66">' . $kws_disp . '</strong></p>'
             . '</td></tr>'
             . $rows
             . '<tr><td style="padding:18px 16px;text-align:center;background:#0e0f12">'
             . '<a href="' . esc_url( $mgr_url ) . '" style="color:#00FF66;font-size:12px;text-decoration:none;font-family:Menlo,Monaco,monospace">MANAGE ALERTS →</a>'
             . '</td></tr>'
             . '</table>'
             . '<p style="color:#555;font-size:11px;margin:14px 0 0">— ' . esc_html( $site ) . '</p>'
             . '</td></tr></table></body></html>';
    }

    /* ============================================================
     *  Helpers
     * ============================================================ */

    public static function get_news_alerts() {
        $a = get_option( 'bt_news_alerts', array() );
        return is_array( $a ) ? $a : array();
    }

    public static function get_known_sources() {
        $feeds = get_option( 'bt_rss_feeds', array() );
        $names = array();
        if ( is_array( $feeds ) ) {
            foreach ( $feeds as $f ) {
                if ( ! empty( $f['name'] ) ) $names[] = $f['name'];
            }
        }
        // Fall back to what's actually in the news cache.
        if ( empty( $names ) ) {
            $news = get_option( 'bt_news_items', array() );
            foreach ( $news as $item ) {
                if ( ! empty( $item['source'] ) ) $names[] = $item['source'];
            }
        }
        $names = array_unique( $names );
        sort( $names );
        return array_slice( $names, 0, 24 );
    }

    /* ============================================================
     *  Admin overview
     * ============================================================ */

    public static function register_admin_menu() {
        add_submenu_page(
            'fxlm-wizard',
            __( 'Alerts', 'blockticker' ),
            __( 'Alerts', 'blockticker' ),
            'manage_options',
            'bt-alerts-overview',
            array( __CLASS__, 'render_admin_page' )
        );
    }

    public static function admin_handle_delete() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );
        check_admin_referer( 'bt_admin_alert_delete' );
        $kind = sanitize_text_field( $_POST['kind'] ?? '' );
        $id   = sanitize_text_field( $_POST['id']   ?? '' );

        if ( $kind === 'price' ) {
            $list = get_option( 'bt_price_alerts', array() );
            $list = array_values( array_filter( $list, function( $a ) use ( $id ) { return ( $a['id'] ?? '' ) !== $id; } ) );
            update_option( 'bt_price_alerts', $list );
        } elseif ( $kind === 'news' ) {
            $list = self::get_news_alerts();
            $list = array_values( array_filter( $list, function( $a ) use ( $id ) { return ( $a['id'] ?? '' ) !== $id; } ) );
            update_option( 'bt_news_alerts', $list );
        }
        wp_safe_redirect( add_query_arg( array( 'page' => 'bt-alerts-overview', 'deleted' => 1 ), admin_url( 'admin.php' ) ) );
        exit;
    }

    public static function render_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );

        $price_alerts = get_option( 'bt_price_alerts', array() );
        $news_alerts  = self::get_news_alerts();

        $price_active = count( array_filter( $price_alerts, function( $a ) { return empty( $a['fired'] ); } ) );
        $price_fired  = count( $price_alerts ) - $price_active;
        $news_count   = count( $news_alerts );
        $next_news    = wp_next_scheduled( 'bt_check_news_alerts' );
        $next_price   = wp_next_scheduled( 'bt_check_price_alerts' );

        $deleted = ! empty( $_GET['deleted'] );
        ?>
        <div class="wrap bt-admin-wrap">
            <h1 style="font-family:'Chivo',sans-serif;font-weight:900">🔔 Alerts</h1>
            <p>Operator view of all active price and news alerts. Recent firings logged to <code>wp_bt_events</code> (<code>PRICE_ALERT</code> / <code>NEWS_ALERT</code>).</p>

            <?php if ( $deleted ) : ?>
            <div class="notice notice-success is-dismissible"><p>Alert deleted.</p></div>
            <?php endif; ?>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;margin:20px 0">
                <div style="padding:18px;background:#fff;border:1px solid #c3c4c7;border-left:4px solid #00cc55">
                    <div style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#666">Price Alerts</div>
                    <div style="font-size:28px;font-weight:900;margin-top:6px"><?php echo esc_html( $price_active ); ?> <span style="font-size:14px;color:#888;font-weight:400">active · <?php echo esc_html( $price_fired ); ?> fired</span></div>
                    <div style="font-size:11px;color:#666;margin-top:6px">Next check: <?php echo $next_price ? esc_html( human_time_diff( time(), $next_price ) ) . ' from now' : 'not scheduled'; ?></div>
                </div>
                <div style="padding:18px;background:#fff;border:1px solid #c3c4c7;border-left:4px solid #2271b1">
                    <div style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#666">News Alerts</div>
                    <div style="font-size:28px;font-weight:900;margin-top:6px"><?php echo esc_html( $news_count ); ?> <span style="font-size:14px;color:#888;font-weight:400">active</span></div>
                    <div style="font-size:11px;color:#666;margin-top:6px">Next check: <?php echo $next_news ? esc_html( human_time_diff( time(), $next_news ) ) . ' from now' : 'not scheduled'; ?></div>
                </div>
            </div>

            <h2 style="margin-top:30px">Active price alerts</h2>
            <?php self::render_price_table( $price_alerts ); ?>

            <h2 style="margin-top:30px">Active news alerts</h2>
            <?php self::render_news_table( $news_alerts ); ?>
        </div>
        <?php
    }

    private static function render_price_table( $alerts ) {
        if ( empty( $alerts ) ) {
            echo '<p style="color:#666;font-style:italic">No price alerts set yet.</p>';
            return;
        }
        ?>
        <table class="widefat striped">
            <thead><tr>
                <th>Asset</th><th>Direction</th><th>Target</th><th>Email</th>
                <th>State</th><th>Created</th><th></th>
            </tr></thead>
            <tbody>
            <?php foreach ( $alerts as $a ) :
                $state = ! empty( $a['fired'] ) ? sprintf( 'Fired %d×', $a['repeat_count'] ?? 1 ) : 'Active';
            ?>
            <tr>
                <td><strong><?php echo esc_html( strtoupper( $a['coin_id'] ?? '' ) ); ?></strong></td>
                <td><?php echo esc_html( ( $a['direction'] ?? 'above' ) === 'above' ? '↑ above' : '↓ below' ); ?></td>
                <td><?php echo esc_html( number_format( (float) ( $a['target'] ?? 0 ), ( $a['asset_type'] ?? 'crypto' ) === 'forex' ? 5 : 2 ) ); ?></td>
                <td style="font-family:monospace;font-size:12px"><?php echo esc_html( $a['email'] ?? '' ); ?></td>
                <td><?php echo esc_html( $state ); ?></td>
                <td><?php echo esc_html( ! empty( $a['created'] ) ? human_time_diff( $a['created'], time() ) . ' ago' : '' ); ?></td>
                <td><?php self::print_delete_form( 'price', $a['id'] ?? '' ); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private static function render_news_table( $alerts ) {
        if ( empty( $alerts ) ) {
            echo '<p style="color:#666;font-style:italic">No news alerts set yet.</p>';
            return;
        }
        ?>
        <table class="widefat striped">
            <thead><tr>
                <th>Keywords</th><th>Mode</th><th>Sources</th><th>Email</th>
                <th>Frequency</th><th>Matched</th><th>Last sent</th><th></th>
            </tr></thead>
            <tbody>
            <?php foreach ( $alerts as $a ) : ?>
            <tr>
                <td><strong><?php echo esc_html( implode( ', ', $a['keywords'] ?? array() ) ); ?></strong></td>
                <td><?php echo esc_html( $a['match_mode'] ?? 'any' ); ?></td>
                <td><?php echo esc_html( empty( $a['sources'] ) ? 'all' : implode( ', ', array_slice( $a['sources'], 0, 3 ) ) . ( count( $a['sources'] ) > 3 ? '…' : '' ) ); ?></td>
                <td style="font-family:monospace;font-size:12px"><?php echo esc_html( $a['email'] ?? '' ); ?></td>
                <td><?php echo esc_html( $a['frequency'] ?? 'hourly' ); ?></td>
                <td><?php echo (int) ( $a['match_count'] ?? 0 ); ?></td>
                <td><?php echo esc_html( ! empty( $a['last_sent'] ) ? human_time_diff( $a['last_sent'], time() ) . ' ago' : 'never' ); ?></td>
                <td><?php self::print_delete_form( 'news', $a['id'] ?? '' ); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private static function print_delete_form( $kind, $id ) {
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0">
            <?php wp_nonce_field( 'bt_admin_alert_delete' ); ?>
            <input type="hidden" name="action" value="bt_delete_alert_admin">
            <input type="hidden" name="kind" value="<?php echo esc_attr( $kind ); ?>">
            <input type="hidden" name="id" value="<?php echo esc_attr( $id ); ?>">
            <button type="submit" class="button button-small button-link-delete" onclick="return confirm('Delete this alert?')">Delete</button>
        </form>
        <?php
    }
}

BT_Alerts::init();
