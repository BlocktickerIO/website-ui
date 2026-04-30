<?php
/**
 * BlockTicker — Personalized Dashboard subsystem
 *
 * A configurable single-page dashboard that composes existing widgets into
 * three preset layouts targeting distinct user intents:
 *
 *   Markets Focus   — first-time / general visitors (default)
 *   Watchlist Focus — logged-in users with saved assets
 *   Signals Focus   — active traders following the desk's calls
 *
 * Each preset is a pure composition of existing shortcodes — no new visual
 * components — so the dashboard stays in lockstep with the underlying widgets
 * automatically. Adding a new preset is a single array addition.
 *
 * Personalization model
 *   - Logged-in users: preset stored in user meta (bt_dashboard_preset)
 *   - Anonymous users: preset stored in localStorage (client-side only)
 *   - Default fallback: 'markets' for everyone
 *   - URL param override: ?preset=watchlist forces a preset for the request
 *     without persisting (useful for shared links or admin previews)
 *
 * Drag-and-drop reorder is intentionally deferred to v2 — see decision log.
 * v1 ships preset-switching only, which is real personalization (different
 * starting experiences for different intents) without the risk of a half-built
 * drag-drop layout engine.
 *
 * Public surface
 *   /dashboard/                    — the page itself
 *   [bt_dashboard]                 — drop-in for the page above
 *   [bt_dashboard preset="signals"] — force a specific preset
 *   BlockTicker → Dashboards (admin) — operator overview
 *
 * AJAX
 *   wp_ajax_bt_dashboard_set_preset      — save preset (logged-in only)
 *   wp_ajax_nopriv_bt_dashboard_set_preset — no-op response (anon clients use localStorage)
 *
 * Filter hooks
 *   bt_dashboard_presets           — full preset registry override
 *   bt_dashboard_widget_registry   — full widget catalog override
 *   bt_dashboard_default_preset    — change the default for new visitors
 *
 * @since v119.15.0
 * @package BlockTicker
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BT_Dashboard {

    /** User-meta key for stored preset choice. */
    const META_PRESET = 'bt_dashboard_preset';

    /** Cache key for rendered preset HTML (per-preset). */
    const CACHE_KEY = 'bt_dashboard_preset_html_v1_';
    const CACHE_TTL = 180; // 3 minutes — widgets are mostly live data

    public static function setup() {
        add_shortcode( 'bt_dashboard', array( __CLASS__, 'sc_dashboard' ) );

        add_action( 'wp_ajax_bt_dashboard_set_preset',        array( __CLASS__, 'ajax_set_preset' ) );
        add_action( 'wp_ajax_nopriv_bt_dashboard_set_preset', array( __CLASS__, 'ajax_set_preset_anon' ) );

        add_action( 'admin_menu',     array( __CLASS__, 'register_admin_menu' ), 20 );
        add_action( 'admin_post_bt_dashboard_clear_cache', array( __CLASS__, 'admin_clear_cache' ) );

        // Bust cache whenever user-relevant data changes that the dashboard mirrors.
        add_action( 'updated_user_meta', array( __CLASS__, 'maybe_bust_user_meta' ), 10, 4 );

        // SEO meta on /dashboard/ — noindex (personalized content shouldn't be in SERPs).
        add_action( 'wp_head', array( __CLASS__, 'output_dashboard_meta' ), 5 );
    }

    public static function bust_all_caches() {
        foreach ( array_keys( self::get_presets() ) as $id ) {
            delete_transient( self::CACHE_KEY . $id . '_anon' );
            delete_transient( self::CACHE_KEY . $id . '_user' );
        }
    }

    public static function maybe_bust_user_meta( $meta_id, $object_id, $meta_key, $meta_value ) {
        if ( $meta_key === 'bt_watchlist' || $meta_key === 'bt_portfolio' ) {
            // User-specific data changed — anon cache is unaffected, but user-side
            // dashboard content is rendered fresh per request anyway. No-op for now;
            // this hook is here so future additions can bust selectively.
            unset( $meta_id, $object_id, $meta_value ); // suppress unused-param notice
        }
    }

    /* ================================================================
     *  Widget registry — id → renderer config
     * ================================================================ */

    /**
     * Returns the catalog of widgets available for dashboard composition.
     * Each entry:
     *   id       — stable identifier
     *   title    — card heading
     *   icon     — Unicode/emoji icon (optional)
     *   shortcode — shortcode tag to render
     *   atts     — shortcode attributes (associative array)
     *   width    — 'full' (12-col) | 'half' (6-col) | 'third' (4-col)
     *   require_login — whether the widget should only render for logged-in users
     *   description  — short blurb shown when the widget is in a "no data yet" state
     */
    public static function get_widget_registry() {
        $defaults = array(
            'intelligence_brief' => array(
                'title'     => __( 'Intelligence Brief', 'blockticker' ),
                'icon'      => '&#x1F9E0;',
                'shortcode' => 'bt_intelligence_brief',
                'atts'      => array(),
                'width'     => 'full',
            ),
            'market_pulse' => array(
                'title'     => __( 'Market Pulse', 'blockticker' ),
                'icon'      => '&#x1F4CA;',
                'shortcode' => 'bt_market_pulse',
                'atts'      => array(),
                'width'     => 'full',
            ),
            'top_movers' => array(
                'title'     => __( 'Top Movers', 'blockticker' ),
                'icon'      => '&#x1F680;',
                'shortcode' => 'fxlm_gainers_losers',
                'atts'      => array( 'limit' => '5' ),
                'width'     => 'full',
            ),
            'top_mcap' => array(
                'title'     => __( 'Top 10 by Market Cap', 'blockticker' ),
                'icon'      => '&#x1F947;',
                'shortcode' => 'fxlm_crypto_table',
                'atts'      => array( 'limit' => '10' ),
                'width'     => 'full',
            ),
            'crypto_news' => array(
                'title'     => __( 'Latest Crypto News', 'blockticker' ),
                'icon'      => '&#x1F4F0;',
                'shortcode' => 'fxlm_news_feed',
                'atts'      => array( 'limit' => '4', 'category' => 'crypto-news' ),
                'width'     => 'half',
            ),
            'forex_news' => array(
                'title'     => __( 'Forex News', 'blockticker' ),
                'icon'      => '&#x1F4B1;',
                'shortcode' => 'fxlm_news_feed',
                'atts'      => array( 'limit' => '4', 'category' => 'forex-news' ),
                'width'     => 'half',
            ),
            'fear_greed' => array(
                'title'     => __( 'Fear & Greed Index', 'blockticker' ),
                'icon'      => '&#x1F632;',
                'shortcode' => 'fxlm_fear_greed',
                'atts'      => array(),
                'width'     => 'half',
            ),
            'watchlist' => array(
                'title'     => __( 'Your Watchlist', 'blockticker' ),
                'icon'      => '&#x2B50;',
                'shortcode' => 'bt_watchlist_page',
                'atts'      => array(),
                'width'     => 'full',
                'require_login' => true,
            ),
            'price_alerts' => array(
                'title'     => __( 'Your Price Alerts', 'blockticker' ),
                'icon'      => '&#x1F514;',
                'shortcode' => 'bt_price_alerts',
                'atts'      => array(),
                'width'     => 'half',
                'require_login' => true,
            ),
            'portfolio_summary' => array(
                'title'     => __( 'Portfolio Summary', 'blockticker' ),
                'icon'      => '&#x1F4BC;',
                'shortcode' => 'bt_portfolio_summary',
                'atts'      => array(),
                'width'     => 'half',
                'require_login' => true,
            ),
            'performance_summary' => array(
                'title'     => __( 'Signal Performance', 'blockticker' ),
                'icon'      => '&#x1F4C8;',
                'shortcode' => 'bt_performance_summary_card',
                'atts'      => array(),
                'width'     => 'half',
            ),
            'signals_feed' => array(
                'title'     => __( 'Latest Trading Signals', 'blockticker' ),
                'icon'      => '&#x1F4E1;',
                'shortcode' => 'fxlm_signals_feed',
                'atts'      => array( 'limit' => '5' ),
                'width'     => 'full',
            ),
            'signal_leaderboard' => array(
                'title'     => __( 'Signal Source Leaderboard', 'blockticker' ),
                'icon'      => '&#x1F3C6;',
                'shortcode' => 'bt_signal_leaderboard',
                'atts'      => array(),
                'width'     => 'half',
            ),
            'sentiment_bar' => array(
                'title'     => __( 'Market Sentiment', 'blockticker' ),
                'icon'      => '&#x1F50D;',
                'shortcode' => 'bt_sentiment_bar',
                'atts'      => array(),
                'width'     => 'half',
            ),
            'forex_sentiment' => array(
                'title'     => __( 'Forex Desk Verdict', 'blockticker' ),
                'icon'      => '&#x1F30D;',
                'shortcode' => 'bt_forex_sentiment',
                'atts'      => array(),
                'width'     => 'half',
            ),
        );

        // Normalize: ensure each entry has every key we expect downstream.
        $normalized = array();
        foreach ( $defaults as $id => $cfg ) {
            $normalized[ $id ] = wp_parse_args( $cfg, array(
                'title'         => ucfirst( str_replace( '_', ' ', $id ) ),
                'icon'          => '',
                'shortcode'     => '',
                'atts'          => array(),
                'width'         => 'full',
                'require_login' => false,
            ) );
            // Stamp the id back in (filter callers may reference it).
            $normalized[ $id ]['id'] = $id;
        }

        return apply_filters( 'bt_dashboard_widget_registry', $normalized );
    }

    /* ================================================================
     *  Preset registry — id → array of widget ids in render order
     * ================================================================ */

    public static function get_presets() {
        $defaults = array(
            'markets' => array(
                'label'       => __( 'Markets Focus', 'blockticker' ),
                'description' => __( 'Cross-market overview — for general visitors and first-time users.', 'blockticker' ),
                'icon'        => '&#x1F4C8;',
                'widgets'     => array(
                    'intelligence_brief',
                    'market_pulse',
                    'top_movers',
                    'top_mcap',
                    'crypto_news',
                    'forex_news',
                ),
            ),
            'watchlist' => array(
                'label'       => __( 'Watchlist Focus', 'blockticker' ),
                'description' => __( 'Your tracked assets, alerts, and personalised feed — best with a saved watchlist.', 'blockticker' ),
                'icon'        => '&#x2B50;',
                'widgets'     => array(
                    'watchlist',
                    'price_alerts',
                    'portfolio_summary',
                    'performance_summary',
                    'fear_greed',
                    'crypto_news',
                ),
            ),
            'signals' => array(
                'label'       => __( 'Signals Focus', 'blockticker' ),
                'description' => __( 'Trading signals, performance track record, and desk verdicts — for active traders.', 'blockticker' ),
                'icon'        => '&#x1F4E1;',
                'widgets'     => array(
                    'signals_feed',
                    'performance_summary',
                    'signal_leaderboard',
                    'intelligence_brief',
                    'forex_sentiment',
                    'sentiment_bar',
                ),
            ),
        );

        $presets = apply_filters( 'bt_dashboard_presets', $defaults );

        // Normalize and validate.
        $registry = self::get_widget_registry();
        $clean    = array();
        foreach ( $presets as $id => $cfg ) {
            if ( ! is_array( $cfg ) || empty( $cfg['widgets'] ) || ! is_array( $cfg['widgets'] ) ) continue;
            $widgets = array_values( array_filter(
                $cfg['widgets'],
                function( $w ) use ( $registry ) { return isset( $registry[ $w ] ); }
            ) );
            if ( empty( $widgets ) ) continue;
            $clean[ sanitize_key( $id ) ] = array(
                'id'          => sanitize_key( $id ),
                'label'       => isset( $cfg['label'] )       ? (string) $cfg['label']       : ucfirst( $id ),
                'description' => isset( $cfg['description'] ) ? (string) $cfg['description'] : '',
                'icon'        => isset( $cfg['icon'] )        ? (string) $cfg['icon']        : '',
                'widgets'     => $widgets,
            );
        }
        return $clean;
    }

    public static function get_default_preset_id() {
        $default = apply_filters( 'bt_dashboard_default_preset', 'markets' );
        $presets = self::get_presets();
        return isset( $presets[ $default ] ) ? $default : array_key_first( $presets );
    }

    /* ================================================================
     *  Active-preset resolution
     *
     *  Priority: URL param > user meta (logged-in) > default
     *  Anonymous users get the default server-side; the client script then
     *  swaps in the localStorage-saved preset (if any) and persists changes
     *  client-only.
     * ================================================================ */

    public static function resolve_active_preset_id() {
        $presets = self::get_presets();

        // 1. URL param override (transient — never persisted)
        if ( isset( $_GET['preset'] ) ) {
            $candidate = sanitize_key( wp_unslash( $_GET['preset'] ) );
            if ( isset( $presets[ $candidate ] ) ) return $candidate;
        }

        // 2. Logged-in user's saved preset
        if ( is_user_logged_in() ) {
            $stored = get_user_meta( get_current_user_id(), self::META_PRESET, true );
            if ( is_string( $stored ) && isset( $presets[ $stored ] ) ) return $stored;
        }

        // 3. Default
        return self::get_default_preset_id();
    }

    /* ================================================================
     *  Renderer
     * ================================================================ */

    public static function sc_dashboard( $atts = array() ) {
        $a = shortcode_atts( array(
            'preset' => '', // forces a specific preset; empty = auto-resolve
        ), $atts, 'bt_dashboard' );

        $presets       = self::get_presets();
        $forced_preset = $a['preset'] !== '' ? sanitize_key( $a['preset'] ) : '';
        $active_id     = ( $forced_preset !== '' && isset( $presets[ $forced_preset ] ) )
            ? $forced_preset
            : self::resolve_active_preset_id();

        if ( ! isset( $presets[ $active_id ] ) ) return '';
        $preset = $presets[ $active_id ];

        ob_start();
        ?>
        <div class="bt-dash"
             data-active-preset="<?php echo esc_attr( $active_id ); ?>"
             data-default-preset="<?php echo esc_attr( self::get_default_preset_id() ); ?>"
             data-is-logged-in="<?php echo is_user_logged_in() ? '1' : '0'; ?>"
             data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
             data-ajax-nonce="<?php echo esc_attr( wp_create_nonce( 'bt_dashboard' ) ); ?>">

            <?php self::render_preset_switcher( $presets, $active_id ); ?>

            <?php if ( ! empty( $preset['description'] ) ) : ?>
                <p class="bt-dash-preset-desc"><?php echo esc_html( $preset['description'] ); ?></p>
            <?php endif; ?>

            <div class="bt-dash-grid" id="bt-dash-grid">
                <?php
                foreach ( $preset['widgets'] as $widget_id ) {
                    echo self::render_widget_card( $widget_id ); // safe: built from registry + esc'd
                }
                ?>
            </div>

            <?php self::render_anon_persistence_script( $presets, $active_id ); ?>
        </div>
        <?php
        return ob_get_clean();
    }

    private static function render_preset_switcher( $presets, $active_id ) {
        ?>
        <div class="bt-dash-presets" role="tablist" aria-label="<?php esc_attr_e( 'Dashboard layout presets', 'blockticker' ); ?>">
            <?php foreach ( $presets as $id => $p ) :
                $is_active = ( $id === $active_id );
                $cls       = 'bt-dash-preset-btn' . ( $is_active ? ' bt-dash-preset-active' : '' );
            ?>
                <button type="button"
                        class="<?php echo esc_attr( $cls ); ?>"
                        data-preset-id="<?php echo esc_attr( $id ); ?>"
                        role="tab"
                        aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>">
                    <?php if ( ! empty( $p['icon'] ) ) : ?>
                        <span class="bt-dash-preset-icon" aria-hidden="true"><?php echo $p['icon']; // safe: hardcoded entities ?></span>
                    <?php endif; ?>
                    <span class="bt-dash-preset-label"><?php echo esc_html( $p['label'] ); ?></span>
                </button>
            <?php endforeach; ?>
        </div>
        <?php
    }

    private static function render_widget_card( $widget_id ) {
        $registry = self::get_widget_registry();
        if ( ! isset( $registry[ $widget_id ] ) ) return '';

        $w = $registry[ $widget_id ];

        // Login-gating: if widget requires login and user is anon, render a
        // friendly upsell card instead of the widget body.
        if ( ! empty( $w['require_login'] ) && ! is_user_logged_in() ) {
            return self::render_login_required_card( $w );
        }

        $width_cls = 'bt-dash-card-' . sanitize_html_class( $w['width'] );
        $shortcode_html = self::render_shortcode_safe( $w['shortcode'], $w['atts'] );

        ob_start();
        ?>
        <article class="bt-dash-card <?php echo esc_attr( $width_cls ); ?>"
                 data-widget-id="<?php echo esc_attr( $widget_id ); ?>">
            <header class="bt-dash-card-head">
                <?php if ( $w['icon'] !== '' ) : ?>
                    <span class="bt-dash-card-icon" aria-hidden="true"><?php echo $w['icon']; // safe: registry entities ?></span>
                <?php endif; ?>
                <h2 class="bt-dash-card-title"><?php echo esc_html( $w['title'] ); ?></h2>
            </header>
            <div class="bt-dash-card-body">
                <?php
                if ( $shortcode_html === '' ) {
                    echo '<div class="bt-dash-card-empty">' .
                        esc_html__( 'Data warming up — refresh in a moment.', 'blockticker' ) .
                        '</div>';
                } else {
                    echo $shortcode_html; // shortcode output already trusted via WP shortcode pipeline
                }
                ?>
            </div>
        </article>
        <?php
        return ob_get_clean();
    }

    private static function render_login_required_card( $widget ) {
        $width_cls = 'bt-dash-card-' . sanitize_html_class( $widget['width'] );
        ob_start();
        ?>
        <article class="bt-dash-card bt-dash-card-locked <?php echo esc_attr( $width_cls ); ?>"
                 data-widget-id="<?php echo esc_attr( $widget['id'] ); ?>">
            <header class="bt-dash-card-head">
                <?php if ( $widget['icon'] !== '' ) : ?>
                    <span class="bt-dash-card-icon" aria-hidden="true"><?php echo $widget['icon']; ?></span>
                <?php endif; ?>
                <h2 class="bt-dash-card-title"><?php echo esc_html( $widget['title'] ); ?></h2>
            </header>
            <div class="bt-dash-card-body bt-dash-card-locked-body">
                <div class="bt-dash-locked-icon" aria-hidden="true">&#x1F512;</div>
                <p>
                    <?php
                    printf(
                        /* translators: %s: widget title */
                        esc_html__( 'Sign in to see your %s on this dashboard.', 'blockticker' ),
                        esc_html( $widget['title'] )
                    );
                    ?>
                </p>
                <button type="button" class="bt-dash-locked-cta" data-bt-open-auth="login">
                    <?php esc_html_e( 'Sign in', 'blockticker' ); ?>
                </button>
            </div>
        </article>
        <?php
        return ob_get_clean();
    }

    /**
     * Wraps do_shortcode() with safe-fail behaviour: if the shortcode tag isn't
     * registered (e.g. a widget references a shortcode from a class that's been
     * filtered out), returns empty string rather than the literal "[shortcode]"
     * marker WP would otherwise leave in the page.
     */
    private static function render_shortcode_safe( $tag, $atts = array() ) {
        global $shortcode_tags;
        if ( ! is_string( $tag ) || $tag === '' ) return '';
        if ( ! isset( $shortcode_tags[ $tag ] ) ) return '';

        $atts_str = '';
        foreach ( (array) $atts as $k => $v ) {
            $atts_str .= ' ' . preg_replace( '/[^a-z0-9_]/', '', $k ) . '="' . esc_attr( $v ) . '"';
        }
        $shortcode = '[' . $tag . $atts_str . ']';
        return do_shortcode( $shortcode );
    }

    /**
     * Inline JS that:
     *   - Handles preset-switcher click behaviour
     *   - Persists choice via AJAX for logged-in users, localStorage for anon
     *   - On page load for anon users with a localStorage preset different from
     *     the server-rendered one, navigates to ?preset=… so they see the right
     *     view (no flash-of-wrong-preset / FOWP)
     */
    private static function render_anon_persistence_script( $presets, $active_id ) {
        $valid_ids = array_keys( $presets );
        ?>
        <script>
        (function () {
            'use strict';
            var root = document.querySelector('.bt-dash');
            if (!root) return;

            var ajaxUrl     = root.getAttribute('data-ajax-url');
            var nonce       = root.getAttribute('data-ajax-nonce');
            var isLoggedIn  = root.getAttribute('data-is-logged-in') === '1';
            var activeId    = root.getAttribute('data-active-preset');
            var validIds    = <?php echo wp_json_encode( $valid_ids ); ?>;
            var STORAGE_KEY = 'bt_dashboard_preset';

            // ── Anonymous: redirect to stored preset on initial load ────
            if (!isLoggedIn) {
                try {
                    var stored = localStorage.getItem(STORAGE_KEY);
                    var fromUrl = new URLSearchParams(location.search).get('preset');
                    if (stored && validIds.indexOf(stored) !== -1 && stored !== activeId && !fromUrl) {
                        // Soft-replace URL with the stored preset to render the right view.
                        var url = new URL(location.href);
                        url.searchParams.set('preset', stored);
                        location.replace(url.toString());
                        return;
                    }
                } catch (e) { /* localStorage unavailable — proceed with default */ }
            }

            // ── Preset switcher ────────────────────────────────────────
            var buttons = root.querySelectorAll('.bt-dash-preset-btn');
            buttons.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var id = btn.getAttribute('data-preset-id');
                    if (!id || validIds.indexOf(id) === -1) return;
                    if (id === activeId) return;

                    // Persist the choice
                    if (isLoggedIn) {
                        var fd = new FormData();
                        fd.append('action', 'bt_dashboard_set_preset');
                        fd.append('_ajax_nonce', nonce);
                        fd.append('preset', id);
                        // Fire-and-forget — UI navigates anyway
                        fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                            .catch(function () { /* swallow */ });
                    } else {
                        try { localStorage.setItem(STORAGE_KEY, id); } catch (e) { /* ignore */ }
                    }

                    // Navigate to the selected preset
                    var url = new URL(location.href);
                    url.searchParams.set('preset', id);
                    location.assign(url.toString());
                });
            });
        })();
        </script>
        <?php
    }

    /* ================================================================
     *  AJAX
     * ================================================================ */

    public static function ajax_set_preset() {
        check_ajax_referer( 'bt_dashboard', '_ajax_nonce' );
        if ( ! is_user_logged_in() ) wp_send_json_error( 'Not logged in', 401 );

        $preset = isset( $_POST['preset'] ) ? sanitize_key( wp_unslash( $_POST['preset'] ) ) : '';
        $presets = self::get_presets();
        if ( ! isset( $presets[ $preset ] ) ) wp_send_json_error( 'Invalid preset', 400 );

        update_user_meta( get_current_user_id(), self::META_PRESET, $preset );
        wp_send_json_success( array( 'preset' => $preset ) );
    }

    public static function ajax_set_preset_anon() {
        // Anon clients are expected to use localStorage; this endpoint exists so
        // the no-auth case fails with 401 rather than 0/no-handler.
        wp_send_json_error( 'Not logged in', 401 );
    }

    /* ================================================================
     *  SEO meta — noindex for personalized dashboard
     * ================================================================ */

    public static function output_dashboard_meta() {
        $uri = isset( $_SERVER['REQUEST_URI'] ) ? trim( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ), '/' ) : '';
        if ( $uri !== 'dashboard' ) return;
        echo "<meta name=\"robots\" content=\"noindex, nofollow\" />\n";
    }

    /* ================================================================
     *  Admin overview
     * ================================================================ */

    public static function register_admin_menu() {
        add_submenu_page(
            'fxlm-wizard',
            __( 'Dashboards', 'blockticker' ),
            __( 'Dashboards', 'blockticker' ),
            'manage_options',
            'bt-dashboards',
            array( __CLASS__, 'render_admin_page' )
        );
    }

    public static function admin_clear_cache() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );
        check_admin_referer( 'bt_dashboard_clear_cache' );
        self::bust_all_caches();
        wp_safe_redirect( add_query_arg( array(
            'page'    => 'bt-dashboards',
            'cleared' => '1',
        ), admin_url( 'admin.php' ) ) );
        exit;
    }

    public static function render_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $presets  = self::get_presets();
        $registry = self::get_widget_registry();
        $cleared  = ! empty( $_GET['cleared'] );

        // Distribution stats from user meta.
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT meta_value, COUNT(*) AS n FROM {$wpdb->usermeta} WHERE meta_key = %s GROUP BY meta_value",
            self::META_PRESET
        ) );
        $stats = array();
        foreach ( (array) $rows as $row ) {
            $stats[ $row->meta_value ] = (int) $row->n;
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'BlockTicker — Dashboards', 'blockticker' ); ?></h1>

            <?php if ( $cleared ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Dashboard cache cleared.', 'blockticker' ); ?></p></div>
            <?php endif; ?>

            <p class="description">
                <?php esc_html_e( 'Personalized dashboard subsystem. Composes existing widgets into preset layouts targeting distinct user intents. Read-only operator overview.', 'blockticker' ); ?>
                <a href="<?php echo esc_url( home_url( '/dashboard/' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View public page →', 'blockticker' ); ?></a>
            </p>

            <h2><?php esc_html_e( 'Active presets', 'blockticker' ); ?></h2>
            <table class="widefat striped" style="max-width:900px">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Preset', 'blockticker' ); ?></th>
                        <th><?php esc_html_e( 'Widgets', 'blockticker' ); ?></th>
                        <th><?php esc_html_e( 'Users on preset', 'blockticker' ); ?></th>
                        <th><?php esc_html_e( 'Preview', 'blockticker' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $presets as $id => $p ) :
                        $widget_titles = array();
                        foreach ( $p['widgets'] as $w_id ) {
                            if ( isset( $registry[ $w_id ] ) ) $widget_titles[] = $registry[ $w_id ]['title'];
                        }
                        $count = isset( $stats[ $id ] ) ? $stats[ $id ] : 0;
                    ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html( $p['label'] ); ?></strong>
                                <code style="display:block;margin-top:4px;font-size:11px"><?php echo esc_html( $id ); ?></code>
                                <?php if ( $id === self::get_default_preset_id() ) : ?>
                                    <span style="display:inline-block;margin-top:4px;padding:1px 6px;background:#00a32a;color:#fff;font-size:10px;border-radius:2px"><?php esc_html_e( 'DEFAULT', 'blockticker' ); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html( implode( ' · ', $widget_titles ) ); ?></td>
                            <td><?php echo esc_html( number_format( $count ) ); ?></td>
                            <td><a href="<?php echo esc_url( home_url( '/dashboard/?preset=' . $id ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View →', 'blockticker' ); ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <h2><?php esc_html_e( 'Widget registry', 'blockticker' ); ?></h2>
            <table class="widefat striped" style="max-width:900px">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Widget id', 'blockticker' ); ?></th>
                        <th><?php esc_html_e( 'Title', 'blockticker' ); ?></th>
                        <th><?php esc_html_e( 'Shortcode', 'blockticker' ); ?></th>
                        <th><?php esc_html_e( 'Width', 'blockticker' ); ?></th>
                        <th><?php esc_html_e( 'Login required', 'blockticker' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $registry as $id => $w ) :
                        global $shortcode_tags;
                        $registered = isset( $shortcode_tags[ $w['shortcode'] ] );
                    ?>
                        <tr>
                            <td><code><?php echo esc_html( $id ); ?></code></td>
                            <td><?php echo esc_html( $w['title'] ); ?></td>
                            <td>
                                <code><?php echo esc_html( '[' . $w['shortcode'] . ']' ); ?></code>
                                <?php if ( ! $registered ) : ?>
                                    <span style="color:#d63638;font-size:11px;display:block">&#x274C; <?php esc_html_e( 'Shortcode not registered', 'blockticker' ); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html( $w['width'] ); ?></td>
                            <td><?php echo $w['require_login'] ? '&#x2705;' : '—'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <h2><?php esc_html_e( 'Cache & maintenance', 'blockticker' ); ?></h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="bt_dashboard_clear_cache">
                <?php wp_nonce_field( 'bt_dashboard_clear_cache' ); ?>
                <?php submit_button( __( 'Clear dashboard cache', 'blockticker' ), 'secondary', 'submit', false ); ?>
            </form>

            <h2><?php esc_html_e( 'Filter reference', 'blockticker' ); ?></h2>
            <table class="widefat striped" style="max-width:780px">
                <tbody>
                    <tr><td><code>bt_dashboard_presets</code></td><td><?php esc_html_e( 'Replace the entire preset registry.', 'blockticker' ); ?></td></tr>
                    <tr><td><code>bt_dashboard_widget_registry</code></td><td><?php esc_html_e( 'Replace the widget catalog.', 'blockticker' ); ?></td></tr>
                    <tr><td><code>bt_dashboard_default_preset</code></td><td><?php esc_html_e( 'Change the default preset for new visitors.', 'blockticker' ); ?></td></tr>
                </tbody>
            </table>
        </div>
        <?php
    }
}

BT_Dashboard::setup();
