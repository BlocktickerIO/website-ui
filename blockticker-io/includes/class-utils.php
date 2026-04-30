<?php
/**
 * BlockTicker shared utilities.
 *
 * Single source of truth for helpers that were previously duplicated across
 * class-aiblog.php, class-asset-pages.php, class-widgets.php, and others.
 *
 * Organized by concern:
 *   - Number / time formatting       (::fmt_large, ::fmt_time_ago)
 *   - HTTP helpers                   (::http_get_json)
 *   - JSON-option accessors          (::get_option_json, ::set_option_json)
 *   - TradingView chart rendering    (::render_tv_chart)
 *   - Admin AJAX guard               (::verify_admin_ajax)
 *
 * Rule of thumb: if two or more classes need the same helper, it belongs here.
 *
 * @package BlockTicker
 * @since   69.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BT_Utils {

    // ───────────────────────────────────────────────────────────────────────
    // Number & time formatting
    // ───────────────────────────────────────────────────────────────────────

    /**
     * Format a large number with SI-ish suffix (K / M / B / T).
     *
     * Replaces the older duplicated BT_AIBlog::fmt_big() and
     * BT_AssetPages::fmt_large() methods. Both called this with
     * different precision expectations — the $decimals_base parameter
     * preserves the original call sites' formatting.
     *
     * @param float $n              Raw number.
     * @param int   $decimals       Decimals for K/M/B/T suffixed output. Default 2.
     * @param int   $decimals_base  Decimals for numbers below 1000 (un-suffixed). Default 0.
     * @return string
     *
     * @example BT_Utils::fmt_large( 2_450_000_000 );    // "2.45B"
     * @example BT_Utils::fmt_large( 123.45, 2, 2 );     // "123.45"
     */
    public static function fmt_large( $n, $decimals = 2, $decimals_base = 0 ) {
        $n = floatval( $n );
        if ( $n >= 1e12 ) return number_format( $n / 1e12, $decimals ) . 'T';
        if ( $n >= 1e9 )  return number_format( $n / 1e9,  $decimals ) . 'B';
        if ( $n >= 1e6 )  return number_format( $n / 1e6,  $decimals ) . 'M';
        if ( $n >= 1e3 )  return number_format( $n / 1e3,  $decimals ) . 'K';
        return number_format( $n, $decimals_base );
    }

    /**
     * Format a unix timestamp as a human-readable "X ago" string.
     *
     * Wraps WP's human_time_diff() with a consistent suffix and guards against
     * missing/future timestamps (which WP normally renders as "0 seconds ago").
     *
     * @param int $timestamp Unix timestamp.
     * @return string "2 hours ago" | "Just now" | empty string if no valid timestamp
     */
    public static function fmt_time_ago( $timestamp ) {
        $t = intval( $timestamp );
        if ( $t <= 0 ) return '';
        $diff = time() - $t;
        if ( $diff < 30 ) return 'Just now';
        if ( $diff < 0 )  return '';  // Future timestamp — skip rather than confuse
        return human_time_diff( $t, time() ) . ' ago';
    }

    // ───────────────────────────────────────────────────────────────────────
    // HTTP helpers
    // ───────────────────────────────────────────────────────────────────────

    /**
     * Fetch a URL and decode JSON, with consistent timeout + error handling.
     *
     * Replaces the 20+ inline wp_remote_get() call sites that all did the same
     * timeout → is_wp_error → json_decode pattern with slight variations.
     *
     * @param string $url      Absolute URL to fetch.
     * @param array  $args     wp_remote_get args. Merged on top of defaults.
     *                         - timeout (default 15)
     *                         - headers (default [])
     *                         - user-agent (default "BlockTicker/{version}")
     * @return array|WP_Error  Decoded JSON as assoc array, or WP_Error on failure.
     */
    public static function http_get_json( $url, $args = array() ) {
        $defaults = array(
            'timeout'    => 15,
            'headers'    => array(
                'Accept'     => 'application/json',
                'User-Agent' => 'BlockTicker/' . ( defined( 'BT_VERSION' ) ? BT_VERSION : 'unknown' ),
            ),
            'sslverify'  => true,
            'redirection' => 3,
        );
        // Deep-merge headers so callers can add without clobbering defaults
        if ( ! empty( $args['headers'] ) && is_array( $args['headers'] ) ) {
            $args['headers'] = array_merge( $defaults['headers'], $args['headers'] );
        }
        $args = array_merge( $defaults, $args );

        $response = wp_remote_get( $url, $args );
        if ( is_wp_error( $response ) ) return $response;

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error( 'http_error', 'HTTP ' . $code . ' from ' . $url, array( 'status' => $code ) );
        }

        $body    = wp_remote_retrieve_body( $response );
        $decoded = json_decode( $body, true );
        if ( null === $decoded && json_last_error() !== JSON_ERROR_NONE ) {
            return new WP_Error( 'json_decode_error', json_last_error_msg(), array( 'body_preview' => substr( $body, 0, 200 ) ) );
        }
        return $decoded;
    }

    // ───────────────────────────────────────────────────────────────────────
    // JSON-option accessors
    // ───────────────────────────────────────────────────────────────────────

    /**
     * Read a stored option and decode it as JSON/array.
     *
     * WordPress serializes arrays automatically, but we use JSON strings for
     * some large payloads (crypto, forex, news) because they came from APIs as
     * JSON and round-tripping through serialize + unserialize is wasteful.
     * This helper transparently handles either format.
     *
     * @param string $key     Option name.
     * @param mixed  $default Default to return if missing/invalid.
     * @return array|mixed
     */
    public static function get_option_json( $key, $default = array() ) {
        $raw = get_option( $key );

        // Transparent fxlm_ -> bt_ fallback (v116.0.3+).
        // pre_option shims were removed in v101.0 but ~60 read-sites still use
        // fxlm_* keys. Fall through to the canonical bt_* key when slot is empty.
        if ( empty( $raw ) && strncmp( $key, 'fxlm_', 5 ) === 0 ) {
            $bt_key = 'bt_' . substr( $key, 5 );
            $raw    = get_option( $bt_key );
        }

        if ( empty( $raw ) ) return $default;
        if ( is_array( $raw ) ) return $raw;
        if ( is_string( $raw ) ) {
            $decoded = json_decode( $raw, true );
            if ( is_array( $decoded ) ) return $decoded;
        }
        return $default;
    }

    /**
     * Write an array as JSON into an option.
     *
     * @param string $key   Option name.
     * @param array  $data  Data to persist.
     * @param bool   $autoload Whether to autoload on every page. Default false —
     *                       most of our JSON blobs are large and rarely needed.
     * @return bool
     */
    public static function set_option_json( $key, $data, $autoload = false ) {
        return update_option( $key, wp_json_encode( $data ), $autoload );
    }

    // ───────────────────────────────────────────────────────────────────────
    // TradingView chart rendering (consolidates the two near-duplicates)
    // ───────────────────────────────────────────────────────────────────────

    /**
     * Render a TradingView chart wrapper. The shared window.btObserveTvCharts()
     * in revamp-v44.js auto-attaches an IntersectionObserver and inits the
     * widget via the shared window.btLoadTV() promise (single tv.js download
     * per page).
     *
     * Replaces the two near-identical implementations previously in
     * BT_Widgets::sc_tradingview_chart() and BT_AssetPages::tv_chart().
     * Both now delegate here.
     *
     * Uses a static per-request counter to assign deterministic UIDs and to
     * flag the first chart on a page as data-priority="high" so it eager-loads
     * with a generous rootMargin.
     *
     * @param array $args {
     *     @type string $symbol      Required. TradingView symbol ('BINANCE:BTCUSDT', 'FX:EURUSD').
     *     @type int    $height      Chart height in px. Default 400.
     *     @type string $interval    Timeframe. Default 'D'.
     *     @type bool   $hide_top    Hide top toolbar. Default false.
     *     @type bool   $hide_side   Hide side toolbar. Default false.
     *     @type bool   $show_fullscreen  Show ⛶ Fullscreen overlay button. Default true.
     * }
     * @return string HTML markup (no inline <script> — loader is shared).
     */
    public static function render_tv_chart( $args = array() ) {
        $args = wp_parse_args( $args, array(
            'symbol'          => 'FX:EURUSD',
            'height'          => 400,
            'interval'        => 'D',
            'hide_top'        => false,
            'hide_side'       => false,
            'show_fullscreen' => true,
            'show_controls'   => true,  // v118: advanced interval/type controls
        ) );

        $height = intval( $args['height'] );
        if ( $height < 200 ) $height = 400;

        // Deterministic UID — stable per-request so HTML caches (WP Super Cache,
        // LiteSpeed, Cloudflare) don't get busted. Counter ensures uniqueness
        // when the same symbol is rendered multiple times on one page.
        static $chart_idx = 0;
        $chart_idx++;
        $uid      = 'tv' . $chart_idx . '_' . substr( md5( $args['symbol'] . $height ), 0, 8 );
        $priority = $chart_idx === 1 ? 'high' : 'normal';

        ob_start();
        
?>
        <div class="fxlm-chart-wrap fxlm-tv-lazy"
             id="wrap_<?php echo esc_attr( $uid ); ?>"
             data-symbol="<?php echo esc_attr( $args['symbol'] ); ?>"
             data-height="<?php echo esc_attr( $height ); ?>"
             data-interval="<?php echo esc_attr( $args['interval'] ); ?>"
             data-hide-top="<?php echo $args['hide_top']  ? '1' : '0'; ?>"
             data-hide-side="<?php echo $args['hide_side'] ? '1' : '0'; ?>"
             data-priority="<?php echo esc_attr( $priority ); ?>"
             style="min-height:<?php echo esc_attr( $height ); ?>px;position:relative">
            <?php if ( $args['show_fullscreen'] ) : ?>
            <button class="bt-chart-expand-overlay"
                    onclick="btOpenChart('<?php echo esc_js( $args['symbol'] ); ?>','<?php echo esc_js( $args['symbol'] ); ?>')"
                    title="Open fullscreen chart">⛶ Fullscreen</button>
            <?php endif; ?>
            <div id="<?php echo esc_attr( $uid ); ?>" data-tv-host style="height:<?php echo esc_attr( $height ); ?>px">
                <div class="fxlm-chart-placeholder"><span>📊 Chart loading…</span></div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    // ───────────────────────────────────────────────────────────────────────
    // Admin AJAX guard
    // ───────────────────────────────────────────────────────────────────────

    /**
     * Verify that the current admin AJAX request has a valid nonce AND the
     * current user has the given capability. On failure, sends wp_send_json_error()
     * and dies — the caller never returns.
     *
     * Replaces the ~20 call sites that all duplicated this pattern:
     *     check_ajax_referer( 'xxx', 'nonce' );
     *     if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );
     *
     * @param string $nonce_action Nonce action name.
     * @param string $capability   Required WP capability. Default 'manage_options'.
     * @param string $nonce_key    POST key for the nonce. Default 'nonce'.
     * @return void
     */
    public static function verify_admin_ajax( $nonce_action, $capability = 'manage_options', $nonce_key = 'nonce' ) {
        if ( ! check_ajax_referer( $nonce_action, $nonce_key, false ) ) {
            wp_send_json_error( array( 'message' => 'Security check failed. Please refresh the page and try again.' ), 403 );
        }
        if ( ! current_user_can( $capability ) ) {
            wp_send_json_error( array( 'message' => 'You do not have permission to perform this action.' ), 403 );
        }
    }

    /**
     * Verify that a public AJAX request (no capability check) has a valid nonce.
     *
     * Used for endpoints that are intentionally accessible to logged-out users —
     * contact form, prices feed, newsletter subscription, signals feed — where
     * capability gating would be wrong but CSRF protection still matters.
     *
     * On failure, sends wp_send_json_error() and dies — the caller never returns.
     *
     * @param string $nonce_action Nonce action name.
     * @param string $nonce_key    POST key for the nonce. Default 'nonce'.
     * @return void
     * @since 75.0.0
     */
    public static function verify_public_ajax( $nonce_action, $nonce_key = 'nonce' ) {
        if ( ! check_ajax_referer( $nonce_action, $nonce_key, false ) ) {
            wp_send_json_error( array( 'message' => 'Security check failed. Please refresh the page and try again.' ), 403 );
        }
    }

    // ───────────────────────────────────────────────────────────────────────
    // Multi-byte string helpers (mbstring-optional)
    // ───────────────────────────────────────────────────────────────────────

    /**
     * mb_strlen() with a byte-count fallback when the mbstring extension is
     * not loaded. Twitter, Telegram, and similar platforms count characters,
     * not bytes, so we prefer the mb_* path — but some shared hosts ship PHP
     * without mbstring and we refuse to fatal-error over a helper.
     *
     * @param string $s
     * @return int
     * @since 115.0.0
     */
    public static function strlen_unicode( $s ) {
        $s = (string) $s;
        if ( function_exists( 'mb_strlen' ) ) return mb_strlen( $s, 'UTF-8' );
        // Fallback: count UTF-8 code points via regex.
        return preg_match_all( '/./su', $s );
    }

    /**
     * mb_substr() with a byte-substring fallback when mbstring is absent.
     * Matches the mb_substr signature.
     *
     * @param string $s
     * @param int    $start
     * @param int|null $length
     * @return string
     * @since 115.0.0
     */
    public static function substr_unicode( $s, $start, $length = null ) {
        $s = (string) $s;
        if ( function_exists( 'mb_substr' ) ) {
            return $length === null
                ? mb_substr( $s, $start, null, 'UTF-8' )
                : mb_substr( $s, $start, $length, 'UTF-8' );
        }
        if ( $length === null ) return substr( $s, $start );
        return substr( $s, $start, $length );
    }
}
