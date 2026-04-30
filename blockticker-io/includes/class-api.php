<?php
/**
 * BlockTicker Public REST API.
 *
 * Exposes live crypto/forex prices, Fear & Greed, signals and news to third parties
 * under /wp-json/blockticker/v1/. All endpoints are read-only (GET) and return JSON
 * with a consistent { data, meta } envelope.
 *
 * Design:
 *   - No auth required for the public tier (60 req/min per IP, enforced via transients)
 *   - Optional X-BT-API-Key header unlocks the standard tier (600 req/min per key)
 *   - CORS: Access-Control-Allow-Origin: * — the whole point is cross-origin widgets
 *   - Cache-Control tuned per-endpoint (prices 60s, F&G 300s, news 180s)
 *   - Each response includes meta.rate_limit + meta.cached_at so clients self-regulate
 *
 * v65.1 — initial API release
 *
 * @package BlockTicker
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BT_API {

    const NAMESPACE = 'blockticker/v1';
    const RATE_ANON  = 60;   // requests per minute, unauthenticated
    const RATE_KEYED = 600;  // requests per minute, with valid API key

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
        // CORS must be sent before routes dispatch — use the rest_pre_serve_request filter
        add_filter( 'rest_pre_serve_request', array( __CLASS__, 'send_cors_headers' ), 10, 4 );
        // v67: HTML documentation shortcode (CMC-style sticky-sidebar layout)
        add_shortcode( 'bt_api_docs', array( __CLASS__, 'sc_api_docs' ) );
        // v68: Embeddable widget SDK served at /bt-widget.js
        add_action( 'init',              array( __CLASS__, 'register_widget_rewrites' ) );
        add_filter( 'query_vars',        array( __CLASS__, 'add_widget_query_vars' ) );
        add_action( 'template_redirect', array( __CLASS__, 'maybe_serve_widget_js' ), 1 );
        // v68: HTML widget gallery/demo page
        add_shortcode( 'bt_widget_gallery', array( __CLASS__, 'sc_widget_gallery' ) );
    }

    public static function register_widget_rewrites() {
        add_rewrite_rule( '^bt-widget\.js$', 'index.php?bt_widget=sdk', 'top' );
    }

    public static function add_widget_query_vars( $vars ) {
        $vars[] = 'bt_widget';
        return $vars;
    }

    public static function maybe_serve_widget_js() {
        if ( get_query_var( 'bt_widget' ) !== 'sdk' ) return;
        nocache_headers();
        header( 'Content-Type: application/javascript; charset=utf-8' );
        header( 'Cache-Control: public, max-age=3600' );  // 1h — intentionally cacheable
        header( 'Access-Control-Allow-Origin: *' );
        echo self::build_widget_sdk();
        exit;
    }

    public static function register_routes() {
        $routes = array(
            '/'                      => array( 'callback' => 'endpoint_root',       'args' => array() ),
            '/prices/crypto'         => array( 'callback' => 'endpoint_crypto',     'args' => array(
                'limit'  => array( 'type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => 500 ),
                'symbol' => array( 'type' => 'string', 'required' => false ),
            ) ),
            '/prices/crypto/(?P<symbol>[A-Za-z0-9-]+)' => array( 'callback' => 'endpoint_crypto_single', 'args' => array() ),
            '/prices/forex'          => array( 'callback' => 'endpoint_forex',      'args' => array() ),
            '/prices/forex/(?P<pair>[A-Za-z0-9]+)' => array( 'callback' => 'endpoint_forex_single', 'args' => array() ),
            '/fear-greed'            => array( 'callback' => 'endpoint_fear_greed', 'args' => array() ),
            '/signals'               => array( 'callback' => 'endpoint_signals',    'args' => array(
                'limit' => array( 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100 ),
            ) ),
            '/news'                  => array( 'callback' => 'endpoint_news',       'args' => array(
                'limit'    => array( 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 50 ),
                'category' => array( 'type' => 'string', 'required' => false ),
            ) ),
            // v96.2 audit fix F-02: native price history backed by custom DB tables.
            '/history/(?P<symbol>[A-Za-z0-9\-\/]+)' => array( 'callback' => 'endpoint_history', 'args' => array(
                'days'       => array( 'type' => 'integer', 'default' => 7,  'minimum' => 1, 'maximum' => 365 ),
                'resolution' => array( 'type' => 'string',  'default' => '1h', 'enum' => array( '5m', '1h', '1d' ) ),
            ) ),
        );

        foreach ( $routes as $path => $cfg ) {
            register_rest_route( self::NAMESPACE, $path, array(
                'methods'             => 'GET',
                'callback'            => array( __CLASS__, $cfg['callback'] ),
                'permission_callback' => array( __CLASS__, 'check_rate_limit' ),
                'args'                => $cfg['args'],
            ) );
        }
    }

    /**
     * Emit CORS headers on every API response. `*` is intentional — this API is
     * designed for cross-origin widget embedding.
     */
    public static function send_cors_headers( $served, $result, $request, $server ) {
        if ( strpos( $request->get_route(), '/' . self::NAMESPACE ) !== 0 ) {
            return $served;
        }
        header( 'Access-Control-Allow-Origin: *' );
        header( 'Access-Control-Allow-Methods: GET, OPTIONS' );
        header( 'Access-Control-Allow-Headers: X-BT-API-Key, Content-Type, Authorization' );
        header( 'Access-Control-Expose-Headers: X-BT-RateLimit-Remaining, X-BT-RateLimit-Reset' );
        return $served;
    }

    /**
     * Rate-limit check. Used as permission_callback so rejections come back as
     * standard WP_Error → HTTP 429 without dispatching the endpoint body.
     *
     * Strategy: sliding-window-ish using transients keyed by IP (or API key).
     * Not perfectly atomic — a burst right at the tick boundary can overspend
     * by a few. Good enough for abuse prevention, not meant to be bank-grade.
     */
    public static function check_rate_limit( $request ) {
        $auth = self::resolve_auth( $request );
        $key  = $auth['key'] ?: ( 'ip_' . self::client_ip() );
        // v115.0: per-key custom limit takes precedence; otherwise use the tier default.
        if ( $auth['key'] && ! empty( $auth['per_min'] ) ) {
            $max = (int) $auth['per_min'];
        } else {
            $max = $auth['key'] ? self::RATE_KEYED : self::RATE_ANON;
        }

        $bucket = 'bt_api_rl_' . md5( $key );
        $data   = get_transient( $bucket );
        if ( ! is_array( $data ) ) {
            $data = array( 'count' => 0, 'reset' => time() + 60 );
        }
        if ( $data['reset'] <= time() ) {
            $data = array( 'count' => 0, 'reset' => time() + 60 );
        }
        $data['count']++;

        // Store remaining + reset for the endpoint callbacks to surface in headers
        $GLOBALS['bt_api_rate_state'] = array(
            'remaining' => max( 0, $max - $data['count'] ),
            'reset'     => $data['reset'],
            'limit'     => $max,
            'keyed'     => (bool) $auth['key'],
        );

        set_transient( $bucket, $data, 120 );

        if ( $data['count'] > $max ) {
            return new WP_Error(
                'rate_limit_exceeded',
                sprintf( 'Rate limit exceeded (%d requests/minute). Reset at %s UTC. Register an API key for higher limits.', $max, gmdate( 'H:i:s', $data['reset'] ) ),
                array( 'status' => 429 )
            );
        }
        return true;
    }

    /**
     * Figure out whether the request has a valid API key. Returns
     * array( 'key' => string|null, 'tier' => 'public'|'standard'|'premium',
     *        'key_id' => string|null, 'per_min' => int|null ).
     *
     * v115.0: now reads bt_api_keys_v2 (managed by BT_APIKeys) first, falling
     * back to the legacy bt_api_keys option for anyone who hand-seeded keys
     * before the v115 UI shipped. Also bumps per-key usage counters.
     */
    private static function resolve_auth( $request ) {
        $provided = $request->get_header( 'x-bt-api-key' );
        if ( empty( $provided ) ) {
            $provided = $request->get_param( 'api_key' );
        }
        if ( empty( $provided ) ) {
            return array( 'key' => null, 'tier' => 'public', 'key_id' => null, 'per_min' => null );
        }

        // v115: consult the managed key store first.
        if ( class_exists( 'BT_APIKeys' ) ) {
            $rec = BT_APIKeys::find_by_key( $provided );
            if ( $rec && empty( $rec['revoked'] ) ) {
                BT_APIKeys::bump_usage( $rec['id'] );
                return array(
                    'key'     => $provided,
                    'tier'    => $rec['tier'] ?? 'standard',
                    'key_id'  => $rec['id']  ?? null,
                    'per_min' => (int) ( $rec['rate_per_min'] ?? 600 ),
                );
            }
        }

        // Legacy fallback — the original v65.1 option shape.
        $keys = get_option( 'bt_api_keys', array() );
        if ( ! is_array( $keys ) ) $keys = array();
        foreach ( $keys as $rec ) {
            if ( ! empty( $rec['key'] ) && hash_equals( $rec['key'], $provided ) && empty( $rec['revoked'] ) ) {
                return array(
                    'key'     => $provided,
                    'tier'    => $rec['tier'] ?? 'standard',
                    'key_id'  => null,
                    'per_min' => null,
                );
            }
        }
        // Key provided but invalid — treat as anonymous (don't 401, API is public)
        return array( 'key' => null, 'tier' => 'public', 'key_id' => null, 'per_min' => null );
    }

    private static function client_ip() {
        $candidates = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' );
        foreach ( $candidates as $h ) {
            if ( ! empty( $_SERVER[ $h ] ) ) {
                $ip = sanitize_text_field( explode( ',', $_SERVER[ $h ] )[0] );
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) return $ip;
            }
        }
        return '0.0.0.0';
    }

    /**
     * Wrap a payload in the standard { data, meta } envelope and set
     * rate-limit + cache headers. All endpoint callbacks funnel through here.
     */
    private static function respond( $data, $cache_seconds = 60, $source = null ) {
        $state = $GLOBALS['bt_api_rate_state'] ?? array( 'remaining' => 0, 'reset' => 0, 'limit' => 0, 'keyed' => false );
        $response = new WP_REST_Response( array(
            'data' => $data,
            'meta' => array(
                'api_version'   => 'v1',
                'generated_at'  => gmdate( 'c' ),
                'source'        => $source,
                'rate_limit'    => array(
                    'limit'     => $state['limit'],
                    'remaining' => $state['remaining'],
                    'reset'     => gmdate( 'c', $state['reset'] ),
                    'tier'      => $state['keyed'] ? 'standard' : 'public',
                ),
                'docs_url'      => home_url( '/api-docs/' ),
            ),
        ) );
        $response->header( 'Cache-Control', 'public, max-age=' . intval( $cache_seconds ) );
        $response->header( 'X-BT-RateLimit-Limit',     (string) $state['limit'] );
        $response->header( 'X-BT-RateLimit-Remaining', (string) $state['remaining'] );
        $response->header( 'X-BT-RateLimit-Reset',     (string) $state['reset'] );
        return $response;
    }

    // ─── Endpoint callbacks ─────────────────────────────────────────────────

    public static function endpoint_root( $request ) {
        return self::respond( array(
            'name'        => 'BlockTicker API',
            'description' => 'Live crypto and forex prices, Fear & Greed Index, signals and news aggregated from 14+ sources.',
            'endpoints'   => array(
                'GET /prices/crypto'        => 'Top crypto prices. Params: limit (1-500, default 50), symbol (filter by ticker).',
                'GET /prices/crypto/:symbol' => 'Single coin by symbol or slug.',
                'GET /prices/forex'         => 'All forex pair rates.',
                'GET /prices/forex/:pair'   => 'Single forex pair (e.g. EURUSD, USDJPY).',
                'GET /fear-greed'           => 'Crypto Fear & Greed Index.',
                'GET /signals'              => 'Latest trading signals. Params: limit (1-100, default 20).',
                'GET /news'                 => 'Latest financial news. Params: limit (1-50, default 20), category.',
            ),
            'rate_limits' => array(
                'public'   => self::RATE_ANON . ' req/min per IP',
                'standard' => self::RATE_KEYED . ' req/min with API key (X-BT-API-Key header)',
            ),
            'attribution' => 'Data sourced from CoinGecko, ECB/Frankfurter, Alternative.me, CoinDesk, Reuters, FXStreet. Include "Powered by BlockTicker" with a link back to ' . home_url() . ' when embedding.',
            'docs_url'    => home_url( '/api-docs/' ),
        ), 3600, 'BlockTicker' );
    }

    public static function endpoint_crypto( $request ) {
        $crypto = BT_Widgets::get_json_option( 'fxlm_crypto_data' );
        $coins  = isset( $crypto['coins'] ) ? $crypto['coins'] : array();
        $limit  = $request->get_param( 'limit' );
        $symbol = $request->get_param( 'symbol' );

        if ( $symbol ) {
            $sym_l = strtolower( $symbol );
            $coins = array_values( array_filter( $coins, function ( $c ) use ( $sym_l ) {
                return strtolower( $c['symbol'] ?? '' ) === $sym_l || strtolower( $c['id'] ?? '' ) === $sym_l;
            } ) );
        }
        $coins = array_slice( $coins, 0, $limit );

        return self::respond(
            array_map( array( __CLASS__, 'shape_coin' ), $coins ),
            60,
            'CoinGecko'
        );
    }

    public static function endpoint_crypto_single( $request ) {
        $symbol = sanitize_text_field( $request->get_param( 'symbol' ) );
        $crypto = BT_Widgets::get_json_option( 'fxlm_crypto_data' );
        $coins  = isset( $crypto['coins'] ) ? $crypto['coins'] : array();
        $sym_l  = strtolower( $symbol );

        foreach ( $coins as $c ) {
            if ( strtolower( $c['symbol'] ?? '' ) === $sym_l || strtolower( $c['id'] ?? '' ) === $sym_l ) {
                return self::respond( self::shape_coin( $c ), 60, 'CoinGecko' );
            }
        }
        return new WP_Error( 'not_found', 'Coin not found: ' . esc_html( $symbol ), array( 'status' => 404 ) );
    }

    public static function endpoint_forex( $request ) {
        $forex = BT_Widgets::get_json_option( 'fxlm_forex_data' );
        $rates = isset( $forex['rates'] ) ? $forex['rates'] : array();
        $out   = array();
        foreach ( $rates as $pair => $info ) {
            $out[] = array(
                'pair'       => $pair,
                'rate'       => is_array( $info ) ? floatval( $info['rate'] ?? 0 ) : floatval( $info ),
                'change_24h' => is_array( $info ) ? floatval( $info['change'] ?? 0 ) : 0,
            );
        }
        return self::respond( $out, 300, $forex['source'] ?? 'Frankfurter/ECB' );
    }

    public static function endpoint_forex_single( $request ) {
        $pair  = strtoupper( sanitize_text_field( $request->get_param( 'pair' ) ) );
        // Accept EURUSD → EUR/USD normalization
        if ( strlen( $pair ) === 6 && ! strpos( $pair, '/' ) ) {
            $pair = substr( $pair, 0, 3 ) . '/' . substr( $pair, 3, 3 );
        }
        $forex = BT_Widgets::get_json_option( 'fxlm_forex_data' );
        $rates = isset( $forex['rates'] ) ? $forex['rates'] : array();
        if ( ! isset( $rates[ $pair ] ) ) {
            return new WP_Error( 'not_found', 'Forex pair not found: ' . esc_html( $pair ), array( 'status' => 404 ) );
        }
        $info = $rates[ $pair ];
        return self::respond( array(
            'pair'       => $pair,
            'rate'       => is_array( $info ) ? floatval( $info['rate'] ?? 0 ) : floatval( $info ),
            'change_24h' => is_array( $info ) ? floatval( $info['change'] ?? 0 ) : 0,
        ), 300, $forex['source'] ?? 'Frankfurter/ECB' );
    }

    public static function endpoint_fear_greed( $request ) {
        $fng = BT_Widgets::get_json_option( 'fxlm_fear_greed_data' );
        if ( empty( $fng['data'][0] ) ) {
            return self::respond( null, 60, 'Alternative.me' );
        }
        $entry = $fng['data'][0];
        return self::respond( array(
            'value'          => intval( $entry['value'] ?? 0 ),
            'classification' => (string) ( $entry['value_classification'] ?? '' ),
            'timestamp'      => isset( $entry['timestamp'] ) ? intval( $entry['timestamp'] ) : time(),
            'iso_time'       => isset( $entry['timestamp'] ) ? gmdate( 'c', intval( $entry['timestamp'] ) ) : gmdate( 'c' ),
        ), 300, 'Alternative.me' );
    }

    public static function endpoint_signals( $request ) {
        $limit   = intval( $request->get_param( 'limit' ) );
        $signals = get_option( 'bt_signal_items', array() );
        if ( ! is_array( $signals ) ) $signals = array();
        $signals = array_slice( $signals, 0, $limit );

        $out = array();
        foreach ( $signals as $s ) {
            $out[] = array(
                'title'     => wp_strip_all_tags( (string) ( $s['title'] ?? '' ) ),
                'link'      => esc_url_raw( (string) ( $s['link'] ?? '' ) ),
                'source'    => (string) ( $s['source'] ?? '' ),
                'category'  => (string) ( $s['category'] ?? 'forex' ),
                'timestamp' => intval( $s['timestamp'] ?? 0 ),
                'iso_time'  => isset( $s['timestamp'] ) ? gmdate( 'c', intval( $s['timestamp'] ) ) : null,
            );
        }
        return self::respond( $out, 180, 'FXStreet, DailyFX, CoinDesk' );
    }

    public static function endpoint_news( $request ) {
        $limit    = intval( $request->get_param( 'limit' ) );
        $category = sanitize_key( $request->get_param( 'category' ) );
        $items    = BT_Widgets::get_json_option( 'fxlm_news_items' );
        if ( ! is_array( $items ) ) $items = array();

        if ( $category ) {
            $items = array_values( array_filter( $items, function ( $n ) use ( $category ) {
                return stripos( (string) ( $n['category'] ?? '' ), $category ) !== false;
            } ) );
        }
        $items = array_slice( $items, 0, $limit );

        $out = array();
        foreach ( $items as $n ) {
            $out[] = array(
                'title'     => wp_strip_all_tags( (string) ( $n['title'] ?? '' ) ),
                'link'      => esc_url_raw( (string) ( $n['link'] ?? '' ) ),
                'source'    => (string) ( $n['source'] ?? '' ),
                'category'  => (string) ( $n['category'] ?? '' ),
                'image'     => isset( $n['image'] ) ? esc_url_raw( (string) $n['image'] ) : null,
                'timestamp' => intval( $n['timestamp'] ?? 0 ),
                'iso_time'  => isset( $n['timestamp'] ) ? gmdate( 'c', intval( $n['timestamp'] ) ) : null,
            );
        }
        return self::respond( $out, 180, '14+ financial news sources' );
    }

    /**
     * v96.2: GET /blockticker/v1/history/{symbol}?days=7&resolution=1h
     *
     * Reads from the custom `wp_bt_price_history` table. Returns OHLCV-style
     * rows aggregated to the requested bucket (5m raw, 1h avg, 1d avg).
     *
     * Empty response is legitimate — means the table hasn't accumulated
     * enough data yet. Clients should handle `data: []` gracefully.
     */
    public static function endpoint_history( $request ) {
        if ( ! class_exists( 'BT_Database' ) ) {
            return new WP_Error( 'bt_not_ready', 'History storage is not initialised on this install.', array( 'status' => 503 ) );
        }
        $symbol     = strtoupper( (string) $request->get_param( 'symbol' ) );
        $days       = intval( $request->get_param( 'days' ) );
        $resolution = (string) $request->get_param( 'resolution' );

        $rows = BT_Database::get_price_history( $symbol, $days, $resolution );

        $out = array();
        foreach ( $rows as $r ) {
            $out[] = array(
                'captured_at'    => (string) ( $r['captured_at'] ?? '' ),
                'timestamp'      => isset( $r['captured_at'] ) ? strtotime( $r['captured_at'] . ' UTC' ) : 0,
                'price_usd'      => round( (float) ( $r['price_usd'] ?? 0 ), 8 ),
                'volume_24h'     => round( (float) ( $r['volume_24h'] ?? 0 ), 2 ),
                'market_cap'     => round( (float) ( $r['market_cap'] ?? 0 ), 2 ),
                'pct_change_24h' => round( (float) ( $r['pct_change_24h'] ?? 0 ), 4 ),
            );
        }

        // Shorter cache than spot endpoints — history queries are expensive
        // against MySQL at scale and are read repeatedly by chart widgets.
        return self::respond( array(
            'symbol'     => $symbol,
            'days'       => $days,
            'resolution' => $resolution,
            'count'      => count( $out ),
            'data'       => $out,
        ), 120, 'BlockTicker historical data (own DB)' );
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    /**
     * Shape a single coin into the public API response structure.
     * We deliberately DON'T pass through every CoinGecko field — this keeps the
     * response size down and our API surface stable even if CG adds/removes fields.
     */
    private static function shape_coin( $c ) {
        return array(
            'id'                 => (string) ( $c['id'] ?? '' ),
            'symbol'             => strtoupper( (string) ( $c['symbol'] ?? '' ) ),
            'name'               => (string) ( $c['name'] ?? '' ),
            'image'              => esc_url_raw( (string) ( $c['image'] ?? '' ) ),
            'price_usd'          => floatval( $c['current_price'] ?? 0 ),
            'market_cap'         => floatval( $c['market_cap'] ?? 0 ),
            'market_cap_rank'    => intval( $c['market_cap_rank'] ?? 0 ),
            'volume_24h'         => floatval( $c['total_volume'] ?? 0 ),
            'change_24h_percent' => floatval( $c['price_change_percentage_24h'] ?? 0 ),
            'change_7d_percent'  => floatval( $c['price_change_percentage_7d_in_currency'] ?? 0 ),
            'high_24h'           => floatval( $c['high_24h'] ?? 0 ),
            'low_24h'            => floatval( $c['low_24h'] ?? 0 ),
            'ath'                => floatval( $c['ath'] ?? 0 ),
            'ath_change_percent' => floatval( $c['ath_change_percentage'] ?? 0 ),
            'last_updated'       => (string) ( $c['last_updated'] ?? '' ),
        );
    }

    // ─── API key management (admin) ────────────────────────────────────────

    /**
     * Generate a random 40-char API key (hex). Returns the new key record.
     */
    public static function generate_key( $label = '', $tier = 'standard' ) {
        $raw = bin2hex( random_bytes( 20 ) );
        $rec = array(
            'key'        => 'btk_' . $raw,
            'label'      => sanitize_text_field( $label ) ?: 'API Key',
            'tier'       => in_array( $tier, array( 'standard' ), true ) ? $tier : 'standard',
            'created_at' => time(),
            'revoked'    => false,
        );
        $keys = get_option( 'bt_api_keys', array() );
        if ( ! is_array( $keys ) ) $keys = array();
        $keys[] = $rec;
        update_option( 'bt_api_keys', $keys, false );
        return $rec;
    }

    public static function revoke_key( $key ) {
        $keys = get_option( 'bt_api_keys', array() );
        if ( ! is_array( $keys ) ) return false;
        foreach ( $keys as $i => $rec ) {
            if ( ! empty( $rec['key'] ) && hash_equals( $rec['key'], $key ) ) {
                $keys[ $i ]['revoked']    = true;
                $keys[ $i ]['revoked_at'] = time();
                update_option( 'bt_api_keys', $keys, false );
                return true;
            }
        }
        return false;
    }

    // ─── Public HTML documentation page ────────────────────────────────────

    /**
     * v67: Renders an interactive CMC-style API docs page. Sticky sidebar,
     * code samples in 3 languages, live response previews.
     */
    public static function sc_api_docs( $atts ) {
        $base = esc_url( home_url( '/wp-json/blockticker/v1' ) );
        $site = esc_html( get_option( 'bt_site_name', 'BlockTicker' ) );

        // Endpoint catalog used to render both the sidebar nav and the body sections.
        // [ slug, http method, path, summary, params (label => description), sample query ]
        $endpoints = array(
            array( 'getting-started', '',     '',                     'Getting Started',           array(), '' ),
            array( 'authentication',  '',     '',                     'Authentication & Rate Limits', array(), '' ),
            array( 'envelope',        '',     '',                     'Response Envelope',         array(), '' ),
            array( 'errors',          '',     '',                     'Error Handling',            array(), '' ),
            array( 'crypto-list',     'GET',  '/prices/crypto',       'List crypto prices',
                array(
                    'limit'  => 'Number of coins to return (1-500, default 50)',
                    'symbol' => 'Filter by ticker symbol (e.g. btc, eth)',
                ),
                '?limit=5' ),
            array( 'crypto-single',   'GET',  '/prices/crypto/{symbol}', 'Single coin lookup',
                array(),
                '/btc' ),
            array( 'forex-list',      'GET',  '/prices/forex',        'List forex pair rates',
                array(),
                '' ),
            array( 'forex-single',    'GET',  '/prices/forex/{pair}', 'Single forex pair',
                array(),
                '/EURUSD' ),
            array( 'fear-greed',      'GET',  '/fear-greed',          'Fear & Greed Index',
                array(),
                '' ),
            array( 'signals',         'GET',  '/signals',             'Latest trading signals',
                array( 'limit' => 'Number of signals (1-100, default 20)' ),
                '?limit=10' ),
            array( 'news',            'GET',  '/news',                'Latest financial news',
                array(
                    'limit'    => 'Number of articles (1-50, default 20)',
                    'category' => 'Filter by category slug (e.g. crypto, forex)',
                ),
                '?limit=10' ),
            array( 'attribution',     '',     '',                     'Attribution Requirements',  array(), '' ),
        );

        ob_start();
        
?>
<style>
/* ═══ BlockTicker API Docs — v67 ═══ */
.bt-docs { display:grid; grid-template-columns:240px 1fr; gap:0; max-width:1280px; margin:0 auto; min-height:calc(100vh - 120px); background:#0A0B0D; color:#cbd5e1; font-family:'IBM Plex Sans',system-ui,sans-serif; }
.bt-docs * { box-sizing:border-box; }
.bt-docs-sidebar { background:#0d1219; border-right:1px solid rgba(255,255,255,.05); padding:32px 0 60px; position:sticky; top:0; height:100vh; overflow-y:auto; }
.bt-docs-sidebar-title { font-family:'JetBrains Mono','SF Mono',monospace; font-size:11px; font-weight:800; letter-spacing:1.6px; text-transform:uppercase; color:#00FF66; padding:0 24px 8px; }
.bt-docs-sidebar-tagline { font-size:11px; color:var(--bt-text-3); padding:0 24px 24px; line-height:1.5; }
.bt-docs-nav-section { font-size:10px; font-weight:800; letter-spacing:1.4px; text-transform:uppercase; color:var(--bt-text-3); padding:14px 24px 6px; margin-top:8px; }
.bt-docs-nav-link { display:flex; align-items:center; gap:8px; padding:8px 24px; color:var(--bt-text-2); text-decoration:none; font-size:13px; border-left:2px solid transparent; transition:all .15s; }
.bt-docs-nav-link:hover { color:var(--bt-text); background:rgba(255,255,255,.02); }
.bt-docs-nav-link.active { color:#00FF66; background:rgba(0,255,102,.04); border-left-color:#00FF66; }
.bt-docs-nav-method { font-family:'JetBrains Mono','SF Mono',monospace; font-size:9px; font-weight:800; padding:2px 5px; border-radius:3px; background:rgba(34,197,94,.15); color:#22c55e; flex-shrink:0; letter-spacing:.5px; }
.bt-docs-content { padding:48px 56px 80px; min-width:0; max-width:920px; }
.bt-docs-h1 { font-size:32px; font-weight:800; color:var(--bt-text); margin:0 0 8px; letter-spacing:-1px; }
.bt-docs-lede { font-size:15px; color:var(--bt-text-2); line-height:1.7; margin:0 0 32px; max-width:680px; }
.bt-docs-section { padding:48px 0; border-top:1px solid rgba(255,255,255,.04); scroll-margin-top:24px; }
.bt-docs-section:first-of-type { border-top:none; padding-top:0; }
.bt-docs-section h2 { font-size:22px; font-weight:700; color:var(--bt-text); margin:0 0 6px; letter-spacing:-.4px; display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
.bt-docs-method-pill { font-family:'JetBrains Mono','SF Mono',monospace; font-size:11px; font-weight:800; padding:3px 9px; border-radius:5px; background:rgba(34,197,94,.15); color:#22c55e; letter-spacing:.5px; }
.bt-docs-path { font-family:'JetBrains Mono','SF Mono',monospace; font-size:14px; color:#cbd5e1; background:rgba(255,255,255,.03); padding:3px 10px; border-radius:5px; font-weight:500; }
.bt-docs-summary { font-size:14px; color:var(--bt-text-2); line-height:1.7; margin:14px 0 24px; }
.bt-docs-subhead { font-size:11px; font-weight:800; letter-spacing:1.4px; text-transform:uppercase; color:var(--bt-text-3); margin:24px 0 10px; }
.bt-docs-table { width:100%; border-collapse:collapse; margin-bottom:24px; font-size:13px; background:rgba(255,255,255,.02); border-radius:0; overflow:hidden; }
.bt-docs-table th { text-align:left; padding:12px 16px; background:rgba(255,255,255,.03); color:var(--bt-text-3); font-weight:700; font-size:11px; text-transform:uppercase; letter-spacing:.8px; border-bottom:1px solid rgba(255,255,255,.04); }
.bt-docs-table td { padding:12px 16px; color:#cbd5e1; border-bottom:1px solid rgba(255,255,255,.04); vertical-align:top; }
.bt-docs-table td:first-child { font-family:'JetBrains Mono','SF Mono',monospace; color:#00FF66; font-size:12.5px; font-weight:600; white-space:nowrap; }
.bt-docs-table tr:last-child td { border-bottom:none; }

.bt-docs-tabs { display:flex; gap:0; margin:18px 0 0; border-bottom:1px solid rgba(255,255,255,.06); }
.bt-docs-tab { background:transparent; border:none; color:var(--bt-text-3); padding:10px 16px; font-size:12px; font-weight:600; cursor:pointer; border-bottom:2px solid transparent; transition:all .15s; font-family:inherit; letter-spacing:.3px; }
.bt-docs-tab:hover { color:#cbd5e1; }
.bt-docs-tab.active { color:#00FF66; border-bottom-color:#00FF66; }
.bt-docs-code { background:#0d1219; border:1px solid rgba(255,255,255,.05); border-radius:0 0 10px 10px; padding:18px 20px; overflow-x:auto; margin:0 0 24px; }
.bt-docs-code pre { margin:0; font-family:'JetBrains Mono','SF Mono',monospace; font-size:12.5px; line-height:1.7; color:var(--bt-text); white-space:pre; }
.bt-docs-code .tok-key { color:#7dd3fc; }
.bt-docs-code .tok-str { color:#86efac; }
.bt-docs-code .tok-num { color:#fbbf24; }
.bt-docs-code .tok-cmt { color:var(--bt-text-3); font-style:italic; }
.bt-docs-code .tok-fn  { color:#a78bfa; }
.bt-docs-code .tok-kw  { color:#f472b6; }

.bt-docs-try { background:linear-gradient(135deg, rgba(0,255,102,.06), rgba(0,255,102,.04)); border:1px solid rgba(0,255,102,.2); border-radius:0; padding:18px 20px; margin:24px 0; }
.bt-docs-try-row { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
.bt-docs-try-url { flex:1; min-width:280px; background:#0A0B0D; border:1px solid rgba(255,255,255,.08); color:#cbd5e1; padding:9px 14px; border-radius:7px; font-family:'JetBrains Mono','SF Mono',monospace; font-size:12px; }
.bt-docs-try-btn { background:linear-gradient(135deg, #00FF66, var(--bt-accent)); color:#0A0B0D; border:none; padding:9px 18px; border-radius:7px; font-size:12px; font-weight:700; cursor:pointer; font-family:inherit; transition:transform .15s; }
.bt-docs-try-btn:hover { transform:translateY(-1px); }
.bt-docs-try-btn:disabled { opacity:.6; cursor:wait; transform:none; }
.bt-docs-try-out { margin-top:14px; background:#0A0B0D; border:1px solid rgba(255,255,255,.05); border-radius:7px; padding:14px 18px; max-height:340px; overflow-y:auto; font-family:'JetBrains Mono','SF Mono',monospace; font-size:11.5px; line-height:1.6; color:#86efac; white-space:pre-wrap; word-break:break-word; display:none; }
.bt-docs-try-out.error { color:#fca5a5; }

.bt-docs-callout { background:rgba(0,255,102,.05); border-left:3px solid var(--bt-accent); padding:14px 18px; border-radius:0 8px 8px 0; margin:20px 0; font-size:13px; color:#bae6fd; line-height:1.65; }
.bt-docs-callout strong { color:var(--bt-text); }
.bt-docs p { font-size:14px; color:#cbd5e1; line-height:1.75; margin:0 0 14px; }
.bt-docs code { background:rgba(255,255,255,.05); padding:2px 7px; border-radius:0; font-family:'JetBrains Mono','SF Mono',monospace; font-size:12.5px; color:#7dd3fc; }
.bt-docs ul { margin:8px 0 18px; padding-left:22px; color:#cbd5e1; }
.bt-docs ul li { margin:5px 0; line-height:1.7; font-size:14px; }
.bt-docs a { color:#00FF66; text-decoration:none; }
.bt-docs a:hover { text-decoration:underline; }

@media (max-width: 900px) {
    .bt-docs { grid-template-columns:1fr; }
    .bt-docs-sidebar { position:static; height:auto; max-height:none; border-right:none; border-bottom:1px solid rgba(255,255,255,.05); padding:20px 0; }
    .bt-docs-content { padding:32px 20px 60px; }
    .bt-docs-h1 { font-size:26px; }
}
</style>

<div class="bt-docs">
  <aside class="bt-docs-sidebar">
    <div class="bt-docs-sidebar-title"><?php echo $site; ?> API · v1</div>
    <div class="bt-docs-sidebar-tagline">Live crypto &amp; forex prices, signals and news. Free public tier.</div>

    <div class="bt-docs-nav-section">Introduction</div>
    <a class="bt-docs-nav-link" href="#getting-started">Getting Started</a>
    <a class="bt-docs-nav-link" href="#authentication">Authentication</a>
    <a class="bt-docs-nav-link" href="#envelope">Response Envelope</a>
    <a class="bt-docs-nav-link" href="#errors">Error Handling</a>

    <div class="bt-docs-nav-section">Crypto</div>
    <a class="bt-docs-nav-link" href="#crypto-list"><span class="bt-docs-nav-method">GET</span><span>List prices</span></a>
    <a class="bt-docs-nav-link" href="#crypto-single"><span class="bt-docs-nav-method">GET</span><span>Single coin</span></a>

    <div class="bt-docs-nav-section">Forex</div>
    <a class="bt-docs-nav-link" href="#forex-list"><span class="bt-docs-nav-method">GET</span><span>List pairs</span></a>
    <a class="bt-docs-nav-link" href="#forex-single"><span class="bt-docs-nav-method">GET</span><span>Single pair</span></a>

    <div class="bt-docs-nav-section">Sentiment &amp; Feeds</div>
    <a class="bt-docs-nav-link" href="#fear-greed"><span class="bt-docs-nav-method">GET</span><span>Fear &amp; Greed</span></a>
    <a class="bt-docs-nav-link" href="#signals"><span class="bt-docs-nav-method">GET</span><span>Trading Signals</span></a>
    <a class="bt-docs-nav-link" href="#news"><span class="bt-docs-nav-method">GET</span><span>News</span></a>

    <div class="bt-docs-nav-section">Legal</div>
    <a class="bt-docs-nav-link" href="#attribution">Attribution</a>

    <div class="bt-docs-nav-section" style="margin-top:24px;padding-top:14px;border-top:1px solid rgba(255,255,255,.05)">Also see</div>
    <a class="bt-docs-nav-link" href="<?php echo esc_url( home_url('/widgets/') ); ?>" style="color:#00FF66">📦 Embeddable Widgets →</a>
  </aside>

  <main class="bt-docs-content">
    <h1 class="bt-docs-h1"><?php echo $site; ?> API Documentation</h1>
    <p class="bt-docs-lede">A free REST API for live crypto prices, forex rates, the Fear &amp; Greed Index, and aggregated trading signals from 14+ premium financial sources. No authentication required for the public tier.</p>

    <section id="getting-started" class="bt-docs-section">
      <h2>Getting Started</h2>
      <p class="bt-docs-summary">All endpoints live under <code><?php echo $base; ?></code>. Every response is JSON. CORS is enabled with <code>Access-Control-Allow-Origin: *</code> so you can call directly from browser-side code.</p>
      <p>Smoke test it right now from your terminal:</p>
      <div class="bt-docs-tabs">
        <button class="bt-docs-tab active" data-tab="curl">cURL</button>
        <button class="bt-docs-tab" data-tab="js">JavaScript</button>
        <button class="bt-docs-tab" data-tab="py">Python</button>
      </div>
      <div class="bt-docs-code" data-lang="curl"><pre><span class="tok-cmt"># Get the API root — lists all endpoints</span>
curl <?php echo $base; ?>/</pre></div>
      <div class="bt-docs-code" data-lang="js" hidden><pre><span class="tok-kw">const</span> res = <span class="tok-kw">await</span> <span class="tok-fn">fetch</span>(<span class="tok-str">'<?php echo $base; ?>/'</span>);
<span class="tok-kw">const</span> data = <span class="tok-kw">await</span> res.<span class="tok-fn">json</span>();
console.<span class="tok-fn">log</span>(data);</pre></div>
      <div class="bt-docs-code" data-lang="py" hidden><pre><span class="tok-kw">import</span> requests
res = requests.<span class="tok-fn">get</span>(<span class="tok-str">'<?php echo $base; ?>/'</span>)
<span class="tok-fn">print</span>(res.<span class="tok-fn">json</span>())</pre></div>
      <div class="bt-docs-callout"><strong>Tip:</strong> bookmark <code><?php echo $base; ?>/</code> — it always returns a self-describing catalog of every endpoint with current rate limits.</div>
    </section>

    <section id="authentication" class="bt-docs-section">
      <h2>Authentication &amp; Rate Limits</h2>
      <p class="bt-docs-summary">No authentication is required. Include an API key only if you need the higher rate tier.</p>
      <table class="bt-docs-table">
        <thead><tr><th>Tier</th><th>Rate limit</th><th>How to authenticate</th></tr></thead>
        <tbody>
          <tr><td>Public</td><td><?php echo self::RATE_ANON; ?> requests/min per IP</td><td>None — use the API directly</td></tr>
          <tr><td>Standard</td><td><?php echo self::RATE_KEYED; ?> requests/min per key</td><td>Send <code>X-BT-API-Key</code> header (or <code>?api_key=</code> query param)</td></tr>
        </tbody>
      </table>
      <p>Every response carries rate-limit headers so your client can self-regulate without parsing the body:</p>
      <ul>
        <li><code>X-BT-RateLimit-Limit</code> — your tier's per-minute ceiling</li>
        <li><code>X-BT-RateLimit-Remaining</code> — requests left in the current window</li>
        <li><code>X-BT-RateLimit-Reset</code> — Unix timestamp when the window resets</li>
      </ul>
      <p>Authenticated request example:</p>
      <div class="bt-docs-tabs">
        <button class="bt-docs-tab active" data-tab="curl">cURL</button>
        <button class="bt-docs-tab" data-tab="js">JavaScript</button>
        <button class="bt-docs-tab" data-tab="py">Python</button>
      </div>
      <div class="bt-docs-code" data-lang="curl"><pre>curl -H <span class="tok-str">"X-BT-API-Key: btk_yourkeyhere"</span> \
     <?php echo $base; ?>/prices/crypto?limit=5</pre></div>
      <div class="bt-docs-code" data-lang="js" hidden><pre><span class="tok-kw">const</span> res = <span class="tok-kw">await</span> <span class="tok-fn">fetch</span>(<span class="tok-str">'<?php echo $base; ?>/prices/crypto?limit=5'</span>, {
    headers: { <span class="tok-str">'X-BT-API-Key'</span>: <span class="tok-str">'btk_yourkeyhere'</span> }
});
<span class="tok-kw">const</span> data = <span class="tok-kw">await</span> res.<span class="tok-fn">json</span>();</pre></div>
      <div class="bt-docs-code" data-lang="py" hidden><pre>res = requests.<span class="tok-fn">get</span>(
    <span class="tok-str">'<?php echo $base; ?>/prices/crypto?limit=5'</span>,
    headers={<span class="tok-str">'X-BT-API-Key'</span>: <span class="tok-str">'btk_yourkeyhere'</span>}
)</pre></div>
      <div class="bt-api-key-card">
        <div class="bt-api-key-card-icon">🔑</div>
        <div class="bt-api-key-card-body">
          <div class="bt-api-key-card-title">Request API Access</div>
          <p class="bt-api-key-card-desc">Need an API key or higher rate limits? Reach out and we'll get back to you within 24 hours.</p>
          <a href="<?php echo esc_url( home_url( '/contact/' ) ); ?>" class="bt-api-key-card-btn">Contact Us →</a>
        </div>
      </div>
    </section>

    <section id="envelope" class="bt-docs-section">
      <h2>Response Envelope</h2>
      <p class="bt-docs-summary">Every successful response wraps the payload in a stable <code>{ data, meta }</code> structure.</p>
      <div class="bt-docs-tabs"><button class="bt-docs-tab active">JSON</button></div>
      <div class="bt-docs-code"><pre>{
  <span class="tok-key">"data"</span>: <span class="tok-cmt">// the actual response — shape varies by endpoint</span>
    [ ... ],
  <span class="tok-key">"meta"</span>: {
    <span class="tok-key">"api_version"</span>:  <span class="tok-str">"v1"</span>,
    <span class="tok-key">"generated_at"</span>: <span class="tok-str">"2026-04-19T10:30:00+00:00"</span>,
    <span class="tok-key">"source"</span>:       <span class="tok-str">"CoinGecko"</span>,
    <span class="tok-key">"rate_limit"</span>: {
      <span class="tok-key">"limit"</span>:     <span class="tok-num">60</span>,
      <span class="tok-key">"remaining"</span>: <span class="tok-num">58</span>,
      <span class="tok-key">"reset"</span>:     <span class="tok-str">"2026-04-19T10:31:00+00:00"</span>,
      <span class="tok-key">"tier"</span>:      <span class="tok-str">"public"</span>
    },
    <span class="tok-key">"docs_url"</span>: <span class="tok-str">"<?php echo $base; ?>/"</span>
  }
}</pre></div>
    </section>

    <section id="errors" class="bt-docs-section">
      <h2>Error Handling</h2>
      <p class="bt-docs-summary">Errors return a non-2xx status code with a JSON body following WordPress REST conventions.</p>
      <table class="bt-docs-table">
        <thead><tr><th>Status</th><th>Meaning</th></tr></thead>
        <tbody>
          <tr><td>200</td><td>Success</td></tr>
          <tr><td>404</td><td>Resource not found (e.g. unknown coin symbol)</td></tr>
          <tr><td>429</td><td>Rate limit exceeded — see <code>X-BT-RateLimit-Reset</code> header</td></tr>
          <tr><td>500</td><td>Server error — please retry or report</td></tr>
        </tbody>
      </table>
      <div class="bt-docs-code"><pre>{
  <span class="tok-key">"code"</span>:    <span class="tok-str">"rate_limit_exceeded"</span>,
  <span class="tok-key">"message"</span>: <span class="tok-str">"Rate limit exceeded (60 requests/minute). Reset at 10:31:00 UTC."</span>,
  <span class="tok-key">"data"</span>:    { <span class="tok-key">"status"</span>: <span class="tok-num">429</span> }
}</pre></div>
    </section>

    <?php
    // Render endpoint sections from the catalog
    foreach ( $endpoints as $ep ) :
        list( $slug, $method, $path, $title, $params, $sample ) = $ep;
        if ( ! $method ) continue; // skip introduction sections handled above
        $full_url = $base . $path . $sample;
        // Replace path placeholders for the sample
        $display_url = $base . str_replace( array( '{symbol}', '{pair}' ), array( 'btc', 'EURUSD' ), $path . $sample );
    ?>
    <section id="<?php echo esc_attr( $slug ); ?>" class="bt-docs-section">
      <h2><span class="bt-docs-method-pill"><?php echo esc_html( $method ); ?></span><span class="bt-docs-path"><?php echo esc_html( $path ); ?></span><?php echo esc_html( $title ); ?></h2>
      <p class="bt-docs-summary"><?php
        $summaries = array(
            'crypto-list'   => 'Returns the top N cryptocurrencies by market cap with price, volume, market cap, and 24h/7d changes. Cached 60 seconds. Source: CoinGecko.',
            'crypto-single' => 'Returns a single coin by ticker symbol or CoinGecko ID. Same shape as items in the list endpoint. Returns 404 if the symbol isn\'t in our cached top-500.',
            'forex-list'    => 'Returns all tracked forex pair rates with 24h change percentage. Cached 5 minutes. Source: ECB / Frankfurter.app, with multi-source failover for exotic pairs.',
            'forex-single'  => 'Returns a single forex pair. Accepts both compact (EURUSD) and slashed (EUR/USD) formats — case-insensitive.',
            'fear-greed'    => 'The current Crypto Fear &amp; Greed Index — a 0-100 sentiment indicator. 0-24 extreme fear · 25-49 fear · 50 neutral · 51-74 greed · 75-100 extreme greed.',
            'signals'       => 'Returns the latest trading signals aggregated from FXStreet, DailyFX, CoinDesk and others. Each signal includes title, source, category and a deep-link to the original analysis.',
            'news'          => 'Returns the latest financial news headlines from 14+ premium sources. Each item includes title, source, category, image URL, and a link to the original article.',
        );
        echo esc_html( $summaries[ $slug ] ?? '' );
      ?></p>

      <?php if ( ! empty( $params ) ) : ?>
      <div class="bt-docs-subhead">Query Parameters</div>
      <table class="bt-docs-table">
        <thead><tr><th>Parameter</th><th>Description</th></tr></thead>
        <tbody>
          <?php foreach ( $params as $name => $desc ) : ?>
          <tr><td><?php echo esc_html( $name ); ?></td><td><?php echo esc_html( $desc ); ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>

      <div class="bt-docs-subhead">Example Request</div>
      <div class="bt-docs-tabs">
        <button class="bt-docs-tab active" data-tab="curl">cURL</button>
        <button class="bt-docs-tab" data-tab="js">JavaScript</button>
        <button class="bt-docs-tab" data-tab="py">Python</button>
      </div>
      <div class="bt-docs-code" data-lang="curl"><pre>curl <?php echo esc_html( $display_url ); ?></pre></div>
      <div class="bt-docs-code" data-lang="js" hidden><pre><span class="tok-kw">const</span> res = <span class="tok-kw">await</span> <span class="tok-fn">fetch</span>(<span class="tok-str">'<?php echo esc_js( $display_url ); ?>'</span>);
<span class="tok-kw">const</span> { data, meta } = <span class="tok-kw">await</span> res.<span class="tok-fn">json</span>();</pre></div>
      <div class="bt-docs-code" data-lang="py" hidden><pre>res = requests.<span class="tok-fn">get</span>(<span class="tok-str">'<?php echo esc_js( $display_url ); ?>'</span>)
data = res.<span class="tok-fn">json</span>()[<span class="tok-str">'data'</span>]</pre></div>

      <div class="bt-docs-subhead">Try It Live</div>
      <div class="bt-docs-try">
        <div class="bt-docs-try-row">
          <input type="text" class="bt-docs-try-url" value="<?php echo esc_attr( $display_url ); ?>" data-default="<?php echo esc_attr( $display_url ); ?>">
          <button type="button" class="bt-docs-try-btn">▶ Send Request</button>
        </div>
        <pre class="bt-docs-try-out"></pre>
      </div>
    </section>
    <?php endforeach; ?>

    <section id="attribution" class="bt-docs-section">
      <h2>Attribution Requirements</h2>
      <p class="bt-docs-summary">When you display data from this API publicly (web, app, dashboard), please credit <?php echo $site; ?> with a backlink. We don't enforce this technically — we trust you. It helps us keep the API free.</p>
      <p>Suggested attribution:</p>
      <div class="bt-docs-code"><pre><span class="tok-cmt">&lt;!-- Where you display the data --&gt;</span>
&lt;p&gt;Data via &lt;a href=<span class="tok-str">"<?php echo esc_html( home_url() ); ?>"</span>&gt;<?php echo $site; ?>&lt;/a&gt;&lt;/p&gt;</pre></div>
      <p>Per the original sources, you should also surface their names where appropriate:</p>
      <ul>
        <li>Crypto prices: <a href="https://coingecko.com" target="_blank" rel="noopener">CoinGecko</a></li>
        <li>Forex rates: <a href="https://www.ecb.europa.eu/" target="_blank" rel="noopener">European Central Bank</a> via <a href="https://frankfurter.app" target="_blank" rel="noopener">Frankfurter.app</a></li>
        <li>Fear &amp; Greed: <a href="https://alternative.me/crypto/fear-and-greed-index/" target="_blank" rel="noopener">Alternative.me</a></li>
        <li>News &amp; signals: 14+ sources including CoinDesk, Reuters, FXStreet, DailyFX (each item links back to the original)</li>
      </ul>
      <div class="bt-docs-callout"><strong>Service is provided as-is.</strong> No SLA, no guarantee of uptime. We do our best to keep the data fresh and the API up, but the public tier exists at our discretion. Build defensive clients — handle 429s, retry on 5xx, fall back gracefully.</div>
    </section>
  </main>
</div>

<script>
(function(){
  // Tab switching for code samples
  document.querySelectorAll('.bt-docs-tabs').forEach(function(group){
    var tabs = group.querySelectorAll('.bt-docs-tab');
    tabs.forEach(function(tab){
      tab.addEventListener('click', function(){
        var lang = tab.dataset.tab;
        if(!lang) return;
        tabs.forEach(function(t){ t.classList.remove('active'); });
        tab.classList.add('active');
        // Hide all code blocks immediately following this tab group
        var sib = group.nextElementSibling;
        while(sib && sib.classList.contains('bt-docs-code')){
          sib.hidden = sib.dataset.lang !== lang;
          sib = sib.nextElementSibling;
        }
      });
    });
  });

  // Live "Send Request" button
  document.querySelectorAll('.bt-docs-try').forEach(function(box){
    var btn   = box.querySelector('.bt-docs-try-btn');
    var input = box.querySelector('.bt-docs-try-url');
    var out   = box.querySelector('.bt-docs-try-out');
    btn.addEventListener('click', function(){
      var url = input.value.trim();
      if(!url) return;
      btn.disabled = true; btn.textContent = '⏳ Sending…';
      out.style.display = 'block'; out.classList.remove('error'); out.textContent = 'Loading…';
      fetch(url, { headers: { 'Accept': 'application/json' } })
        .then(function(r){ return r.json().then(function(j){ return { status: r.status, body: j }; }); })
        .then(function(o){
          out.classList.toggle('error', o.status >= 400);
          out.textContent = 'HTTP ' + o.status + '\n\n' + JSON.stringify(o.body, null, 2);
        })
        .catch(function(e){
          out.classList.add('error');
          out.textContent = 'Network error: ' + e.message;
        })
        .finally(function(){ btn.disabled = false; btn.textContent = '▶ Send Request'; });
    });
  });

  // Active section highlighting in sidebar via IntersectionObserver
  if (!('IntersectionObserver' in window)) return;
  var links = document.querySelectorAll('.bt-docs-nav-link');
  var byHash = {};
  links.forEach(function(a){
    var h = a.getAttribute('href');
    if(h && h.startsWith('#')) byHash[h.slice(1)] = a;
  });
  var io = new IntersectionObserver(function(entries){
    entries.forEach(function(e){
      var a = byHash[e.target.id];
      if(!a) return;
      if(e.isIntersecting){
        links.forEach(function(l){ l.classList.remove('active'); });
        a.classList.add('active');
      }
    });
  }, { rootMargin: '-20% 0px -70% 0px', threshold: 0 });
  document.querySelectorAll('.bt-docs-section[id]').forEach(function(s){ io.observe(s); });
})();
</script>
        <?php
        return ob_get_clean();
    }

    /**
     * v68: Build the embeddable widget SDK — served at /bt-widget.js.
     *
     * Self-contained IIFE. Zero deps. Uses Shadow DOM so host-page CSS can't
     * bleed in and our styles can't leak out. Hits the v66 public API for data.
     *
     * Usage on a 3rd-party site:
     *   <div class="bt-widget" data-widget="ticker" data-symbols="BTC,ETH,SOL"></div>
     *   <script async src="https://blockticker.io/bt-widget.js"></script>
     *
     * Or programmatically:
     *   BlockTicker.mount(el, { widget: 'fear-greed', theme: 'light' });
     *
     * The auto-mount scanner fires on DOMContentLoaded and again after 500ms
     * (to catch late-inserted elements from page builders).
     */
    private static function build_widget_sdk() {
        $api_base    = esc_url_raw( home_url( '/wp-json/blockticker/v1' ) );
        $home        = esc_url_raw( home_url( '/?utm_source=widget&utm_medium=embed' ) );
        $version     = BT_VERSION;

        // Return a single-line-preferred JS file. Vars interpolated from PHP are
        // JSON-encoded for safety.
        ob_start();
        ?>
/*! BlockTicker Widget SDK v<?php echo esc_js( $version ); ?> — https://blockticker.io · Licensed for free embed with attribution */
(function (global) {
    'use strict';
    if (global.BlockTicker) return; // Idempotent — safe to include twice

    var API_BASE   = <?php echo wp_json_encode( $api_base ); ?>;
    var HOME_URL   = <?php echo wp_json_encode( $home ); ?>;
    var VERSION    = <?php echo wp_json_encode( $version ); ?>;

    /* ─── Tiny fetch cache so multiple widgets on one page share one request ─── */
    var _cache = {}; // url -> { promise, expires }
    function getJSON(url, ttl) {
        var now = Date.now();
        var hit = _cache[url];
        if (hit && hit.expires > now) return hit.promise;
        var p = fetch(url, { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : Promise.reject(new Error('HTTP ' + r.status)); });
        _cache[url] = { promise: p, expires: now + (ttl || 30000) };
        return p;
    }

    /* ─── Theme tokens (light & dark) ─── */
    var THEMES = {
        dark: {
            bg: '#0A0B0D', panel: '#121316', text: 'var(--bt-text)', muted: 'var(--bt-text-2)',
            border: 'rgba(255,255,255,.08)', brand: '#00FF66', brand2: 'var(--bt-accent)',
            up: '#00FF66', down: '#FF3B30', track: 'rgba(255,255,255,.04)'
        },
        light: {
            bg: '#ffffff', panel: '#f8fafc', text: '#0f172a', muted: 'var(--bt-text-3)',
            border: 'rgba(15,23,42,.1)', brand: '#00a887', brand2: '#0077cc',
            up: '#00a887', down: '#dc2626', track: 'rgba(15,23,42,.04)'
        }
    };

    /* ─── Shared CSS shipped into each widget's Shadow DOM ─── */
    function baseCSS(t) {
        return [
            ':host{all:initial;display:block;contain:content}',
            '*,*::before,*::after{box-sizing:border-box}',
            '.bt{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Plus Jakarta Sans",system-ui,sans-serif;',
            '  color:' + t.text + ';background:' + t.bg + ';border:1px solid ' + t.border + ';',
            '  border-radius:0;overflow:hidden;line-height:1.4;position:relative;width:100%}',
            '.bt a{color:inherit;text-decoration:none}',
            '.bt-head{display:flex;align-items:center;justify-content:space-between;',
            '  padding:10px 14px;border-bottom:1px solid ' + t.border + ';background:' + t.panel + '}',
            '.bt-head h4{font-size:11px;font-weight:800;letter-spacing:1.2px;text-transform:uppercase;',
            '  color:' + t.muted + ';margin:0}',
            '.bt-brand{display:flex;align-items:center;gap:6px;font-size:10px;',
            '  color:' + t.muted + ';font-weight:600;opacity:.85;transition:opacity .15s}',
            '.bt-brand:hover{opacity:1;color:' + t.brand + '}',
            '.bt-brand-dot{width:6px;height:6px;border-radius:50%;background:' + t.brand + ';',
            '  box-shadow:0 0 8px ' + t.brand + '55}',
            '.bt-pulse{animation:btPulse 2s ease-in-out infinite}',
            '@keyframes btPulse{0%,100%{opacity:1}50%{opacity:.4}}',
            '.bt-err{padding:20px 16px;text-align:center;font-size:12px;color:' + t.muted + '}',
            '.bt-foot{padding:8px 14px;display:flex;align-items:center;justify-content:space-between;',
            '  font-size:10px;color:' + t.muted + ';border-top:1px solid ' + t.border + '}',
            '@media (prefers-reduced-motion:reduce){.bt *{animation:none!important}}'
        ].join('');
    }

    /* ─── Utility: format a price with sensible decimals ─── */
    function fmtPrice(p) {
        if (p === null || p === undefined || isNaN(p)) return '—';
        if (p >= 1000)  return '$' + p.toLocaleString('en-US', { maximumFractionDigits: 0 });
        if (p >= 1)     return '$' + p.toFixed(2);
        if (p >= 0.01)  return '$' + p.toFixed(4);
        return '$' + p.toFixed(8);
    }
    function fmtChange(c) {
        if (c === null || c === undefined || isNaN(c)) return '—';
        var sign = c >= 0 ? '▲' : '▼';
        return sign + ' ' + Math.abs(c).toFixed(2) + '%';
    }

    /* ─── Builders return { html, css, mount(shadow) } for each widget type ─── */

    function buildTicker(opts, t) {
        var symbols = (opts.symbols || 'BTC,ETH,SOL,BNB,XRP').toUpperCase().split(',').map(function (s) { return s.trim(); }).filter(Boolean);
        var limit   = Math.max(symbols.length * 2, 10);
        var css = [
            '.bt-ticker{padding:0}',
            '.bt-ticker-track{display:flex;gap:0;overflow:hidden;padding:12px 0;position:relative;mask-image:linear-gradient(to right,transparent,#000 4%,#000 96%,transparent)}',
            '.bt-ticker-row{display:flex;gap:32px;animation:btMarquee 60s linear infinite;padding-right:32px;flex-shrink:0}',
            '.bt-ticker:hover .bt-ticker-row{animation-play-state:paused}',
            '.bt-coin{display:flex;align-items:center;gap:8px;white-space:nowrap;font-size:13px;font-weight:600}',
            '.bt-coin img{width:18px;height:18px;border-radius:50%}',
            '.bt-coin-sym{color:' + t.muted + ';font-weight:700;font-size:11px;letter-spacing:.4px}',
            '.bt-coin-price{color:' + t.text + '}',
            '.bt-coin-chg{font-size:11px;font-weight:700}',
            '.bt-up{color:' + t.up + '}',
            '.bt-down{color:' + t.down + '}',
            '@keyframes btMarquee{from{transform:translateX(0)}to{transform:translateX(-100%)}}'
        ].join('');
        return {
            css: css,
            html: '<div class="bt bt-ticker"><div class="bt-head"><h4>Live Prices</h4>' +
                  '<a class="bt-brand" href="' + HOME_URL + '" target="_blank" rel="noopener">' +
                  '<span class="bt-brand-dot bt-pulse"></span>BlockTicker</a></div>' +
                  '<div class="bt-ticker-track"><div class="bt-ticker-row" data-row>' +
                  '<span class="bt-coin" style="color:' + t.muted + '">Loading…</span></div></div></div>',
            mount: function (shadow) {
                var row = shadow.querySelector('[data-row]');
                getJSON(API_BASE + '/prices/crypto?limit=' + limit, 30000).then(function (res) {
                    var want = {};
                    symbols.forEach(function (s) { want[s] = true; });
                    var coins = (res.data || []).filter(function (c) { return want[(c.symbol || '').toUpperCase()]; });
                    // If user specified symbols aren't in top-N, keep what we got
                    if (!coins.length) coins = (res.data || []).slice(0, symbols.length);
                    // Duplicate the row for seamless marquee
                    var inner = coins.map(function (c) {
                        var up = c.change_24h_percent >= 0;
                        return '<span class="bt-coin">' +
                               (c.image ? '<img src="' + c.image + '" alt="">' : '') +
                               '<span class="bt-coin-sym">' + c.symbol + '</span>' +
                               '<span class="bt-coin-price">' + fmtPrice(c.price_usd) + '</span>' +
                               '<span class="bt-coin-chg ' + (up ? 'bt-up' : 'bt-down') + '">' +
                               fmtChange(c.change_24h_percent) + '</span></span>';
                    }).join('');
                    // Duplicate content so marquee loops seamlessly without gap
                    row.innerHTML = inner + inner;
                }).catch(function () {
                    row.innerHTML = '<span class="bt-coin" style="color:' + t.down + '">Data unavailable</span>';
                });
            }
        };
    }

    function buildPrice(opts, t) {
        var symbol = (opts.symbol || 'BTC').toUpperCase();
        var css = [
            '.bt-price-body{padding:18px 16px}',
            '.bt-price-top{display:flex;align-items:center;gap:10px;margin-bottom:10px}',
            '.bt-price-top img{width:28px;height:28px;border-radius:50%}',
            '.bt-price-name{font-size:14px;font-weight:700;color:' + t.text + '}',
            '.bt-price-sym{font-size:11px;color:' + t.muted + ';font-weight:600;letter-spacing:.5px}',
            '.bt-price-val{font-size:26px;font-weight:800;margin-bottom:4px;letter-spacing:-.5px}',
            '.bt-price-chg{font-size:12px;font-weight:700}',
            '.bt-price-meta{display:flex;justify-content:space-between;margin-top:14px;padding-top:12px;border-top:1px solid ' + t.border + ';font-size:11px;color:' + t.muted + '}',
            '.bt-price-meta b{color:' + t.text + ';font-weight:600}',
            '.bt-up{color:' + t.up + '}',
            '.bt-down{color:' + t.down + '}'
        ].join('');
        return {
            css: css,
            html: '<div class="bt bt-price"><div class="bt-price-body" data-body>' +
                  '<div style="color:' + t.muted + ';font-size:12px">Loading ' + symbol + '…</div>' +
                  '</div><div class="bt-foot"><span>Source: CoinGecko</span>' +
                  '<a class="bt-brand" href="' + HOME_URL + '" target="_blank" rel="noopener">' +
                  '<span class="bt-brand-dot"></span>BlockTicker</a></div></div>',
            mount: function (shadow) {
                var body = shadow.querySelector('[data-body]');
                getJSON(API_BASE + '/prices/crypto/' + encodeURIComponent(symbol.toLowerCase()), 30000).then(function (res) {
                    var c = res.data;
                    if (!c) throw new Error('no data');
                    var up = c.change_24h_percent >= 0;
                    body.innerHTML =
                        '<div class="bt-price-top">' +
                        (c.image ? '<img src="' + c.image + '" alt="">' : '') +
                        '<div><div class="bt-price-name">' + c.name + '</div>' +
                        '<div class="bt-price-sym">' + c.symbol + '</div></div></div>' +
                        '<div class="bt-price-val">' + fmtPrice(c.price_usd) + '</div>' +
                        '<div class="bt-price-chg ' + (up ? 'bt-up' : 'bt-down') + '">' + fmtChange(c.change_24h_percent) + ' (24h)</div>' +
                        '<div class="bt-price-meta"><span>Market Cap: <b>$' + (c.market_cap / 1e9).toFixed(2) + 'B</b></span>' +
                        '<span>Rank: <b>#' + c.market_cap_rank + '</b></span></div>';
                }).catch(function () {
                    body.innerHTML = '<div class="bt-err">Price data unavailable for ' + symbol + '</div>';
                });
            }
        };
    }

    function buildFearGreed(opts, t) {
        var css = [
            '.bt-fg-body{padding:20px 16px;text-align:center}',
            '.bt-fg-dial{position:relative;width:160px;height:96px;margin:6px auto 10px}',
            '.bt-fg-value{font-size:32px;font-weight:800;letter-spacing:-1px;margin-top:-20px;position:relative;z-index:2}',
            '.bt-fg-label{font-size:12px;font-weight:700;letter-spacing:1px;text-transform:uppercase;margin-top:4px}',
            '.bt-fg-scale{display:flex;justify-content:space-between;margin-top:16px;padding:0 4px;font-size:10px;color:' + t.muted + '}'
        ].join('');
        function fgColor(v) {
            if (v < 25) return '#ef4444';
            if (v < 45) return '#f97316';
            if (v < 55) return '#eab308';
            if (v < 75) return '#84cc16';
            return '#22c55e';
        }
        return {
            css: css,
            html: '<div class="bt bt-fg"><div class="bt-head"><h4>Fear &amp; Greed Index</h4>' +
                  '<a class="bt-brand" href="' + HOME_URL + '" target="_blank" rel="noopener">' +
                  '<span class="bt-brand-dot"></span>BlockTicker</a></div>' +
                  '<div class="bt-fg-body" data-body>' +
                  '<div style="color:' + t.muted + ';font-size:12px;padding:24px 0">Loading…</div></div>' +
                  '<div class="bt-foot"><span>Source: Alternative.me</span>' +
                  '<span data-ts></span></div></div>',
            mount: function (shadow) {
                var body = shadow.querySelector('[data-body]');
                var foot = shadow.querySelector('[data-ts]');
                getJSON(API_BASE + '/fear-greed', 300000).then(function (res) {
                    var d = res.data;
                    if (!d) throw new Error('no data');
                    var v = d.value, color = fgColor(v);
                    // Gauge: SVG arc, 0° (left) → 180° (right), filled proportionally to value
                    var angle = (v / 100) * 180 - 90; // -90 to +90
                    var rad = (angle - 90) * Math.PI / 180;
                    var cx = 80, cy = 80, r = 68;
                    var endX = cx + r * Math.cos(rad);
                    var endY = cy + r * Math.sin(rad);
                    var largeArc = v > 50 ? 1 : 0;
                    var arcPath = 'M ' + (cx - r) + ' ' + cy +
                                  ' A ' + r + ' ' + r + ' 0 ' + largeArc + ' 1 ' + endX + ' ' + endY;
                    body.innerHTML =
                        '<div class="bt-fg-dial">' +
                        '<svg viewBox="0 0 160 96" style="width:100%;height:100%">' +
                        '<path d="M 12 80 A 68 68 0 0 1 148 80" fill="none" stroke="' + t.track + '" stroke-width="10" stroke-linecap="round"/>' +
                        '<path d="' + arcPath + '" fill="none" stroke="' + color + '" stroke-width="10" stroke-linecap="round"/>' +
                        '</svg></div>' +
                        '<div class="bt-fg-value" style="color:' + color + '">' + v + '</div>' +
                        '<div class="bt-fg-label" style="color:' + color + '">' + d.classification + '</div>' +
                        '<div class="bt-fg-scale"><span>0 Fear</span><span>50</span><span>100 Greed</span></div>';
                    if (d.iso_time) {
                        var t2 = new Date(d.iso_time);
                        foot.textContent = 'Updated ' + t2.toLocaleString('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
                    }
                }).catch(function () {
                    body.innerHTML = '<div class="bt-err">Fear &amp; Greed data unavailable</div>';
                });
            }
        };
    }

    function buildSignals(opts, t) {
        var limit = parseInt(opts.limit || 5, 10);
        if (limit < 1) limit = 1;
        if (limit > 20) limit = 20;
        var css = [
            '.bt-sig-list{display:flex;flex-direction:column}',
            '.bt-sig{display:flex;gap:10px;padding:11px 14px;border-bottom:1px solid ' + t.border + ';text-decoration:none;color:inherit;transition:background .12s}',
            '.bt-sig:last-child{border-bottom:none}',
            '.bt-sig:hover{background:' + t.panel + '}',
            '.bt-sig-dot{flex-shrink:0;width:8px;height:8px;margin-top:6px;border-radius:50%;background:' + t.brand + '}',
            '.bt-sig-body{flex:1;min-width:0}',
            '.bt-sig-title{font-size:12.5px;font-weight:600;color:' + t.text + ';line-height:1.4;',
            '  overflow:hidden;text-overflow:ellipsis;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}',
            '.bt-sig-meta{font-size:10.5px;color:' + t.muted + ';margin-top:3px;font-weight:600;letter-spacing:.3px}'
        ].join('');
        return {
            css: css,
            html: '<div class="bt bt-sig-wrap"><div class="bt-head"><h4>Trading Signals</h4>' +
                  '<a class="bt-brand" href="' + HOME_URL + '" target="_blank" rel="noopener">' +
                  '<span class="bt-brand-dot bt-pulse"></span>BlockTicker</a></div>' +
                  '<div class="bt-sig-list" data-list>' +
                  '<div style="padding:24px;text-align:center;color:' + t.muted + ';font-size:12px">Loading signals…</div>' +
                  '</div></div>',
            mount: function (shadow) {
                var list = shadow.querySelector('[data-list]');
                getJSON(API_BASE + '/signals?limit=' + limit, 60000).then(function (res) {
                    var items = res.data || [];
                    if (!items.length) { list.innerHTML = '<div class="bt-err">No signals available</div>'; return; }
                    list.innerHTML = items.map(function (s) {
                        var when = '';
                        if (s.iso_time) {
                            var diff = Math.floor((Date.now() - new Date(s.iso_time).getTime()) / 60000);
                            when = diff < 60 ? diff + 'm ago' :
                                   diff < 1440 ? Math.floor(diff/60) + 'h ago' :
                                   Math.floor(diff/1440) + 'd ago';
                        }
                        return '<a class="bt-sig" href="' + (s.link || HOME_URL) + '" target="_blank" rel="noopener">' +
                               '<span class="bt-sig-dot"></span>' +
                               '<div class="bt-sig-body">' +
                               '<div class="bt-sig-title">' + escapeHTML(s.title) + '</div>' +
                               '<div class="bt-sig-meta">' + escapeHTML(s.source || '') + (when ? ' · ' + when : '') + '</div>' +
                               '</div></a>';
                    }).join('');
                }).catch(function () {
                    list.innerHTML = '<div class="bt-err">Signals unavailable</div>';
                });
            }
        };
    }

    function escapeHTML(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c];
        });
    }

    /* ─── Public API ─── */
    var BUILDERS = { ticker: buildTicker, price: buildPrice, 'fear-greed': buildFearGreed, signals: buildSignals };

    function mount(target, opts) {
        opts = opts || {};
        var el = (typeof target === 'string') ? document.querySelector(target) : target;
        if (!el) { console.warn('[BlockTicker] target not found'); return null; }
        if (el.__btMounted) return el.__btMounted;

        var type = opts.widget || el.getAttribute('data-widget') || 'ticker';
        var builder = BUILDERS[type];
        if (!builder) { console.warn('[BlockTicker] unknown widget type:', type); return null; }

        // Merge options from data-* attributes (DOM wins for presentation, opts override)
        var attrs = {};
        for (var i = 0; i < el.attributes.length; i++) {
            var a = el.attributes[i];
            if (a.name.indexOf('data-') === 0) attrs[a.name.slice(5)] = a.value;
        }
        var merged = Object.assign({}, attrs, opts);
        var themeName = (merged.theme === 'light') ? 'light' : 'dark';
        var t = THEMES[themeName];

        // Build Shadow DOM so host-page CSS can't bleed in
        var shadow;
        try { shadow = el.attachShadow({ mode: 'open' }); }
        catch (e) { shadow = el; } // Shadow DOM unsupported — degrade gracefully

        var widget = builder(merged, t);
        var styleEl = document.createElement('style');
        styleEl.textContent = baseCSS(t) + widget.css;
        shadow.appendChild(styleEl);
        var host = document.createElement('div');
        host.innerHTML = widget.html;
        shadow.appendChild(host);

        // Initial data fetch
        widget.mount(shadow);

        // Auto-refresh (opt-in via data-refresh, in seconds)
        var refreshSec = parseInt(merged.refresh || '0', 10);
        if (refreshSec >= 15) {
            setInterval(function () { widget.mount(shadow); }, refreshSec * 1000);
        }

        el.__btMounted = { type: type, target: el };
        return el.__btMounted;
    }

    function auto() {
        document.querySelectorAll('.bt-widget, [data-bt-widget]').forEach(function (el) {
            if (!el.__btMounted) mount(el);
        });
    }

    global.BlockTicker = { mount: mount, auto: auto, version: VERSION };

    if (document.readyState !== 'loading') auto();
    else document.addEventListener('DOMContentLoaded', auto);
    // Late-mount pass for page builders / dynamic content
    setTimeout(auto, 500);
})(window);
        <?php
        return ob_get_clean();
    }

    /**
     * v68: Widget gallery / demo page shortcode. Shows every widget rendered
     * live plus the exact 2-line snippet users need to paste.
     */
    public static function sc_widget_gallery( $atts ) {
        $sdk_url = esc_url( home_url( '/bt-widget.js' ) );
        $site    = esc_html( get_option( 'bt_site_name', 'BlockTicker' ) );
        ob_start();
        ?>
<style>
.bt-gallery{max-width:1180px;margin:0 auto;padding:0 20px 80px;font-family:'IBM Plex Sans',system-ui,sans-serif;color:#cbd5e1}
.bt-gallery h1{font-size:34px;font-weight:800;color:var(--bt-text);margin:0 0 6px;letter-spacing:-.8px}
.bt-gallery .lede{color:var(--bt-text-2);font-size:15px;line-height:1.7;max-width:680px;margin:0 0 36px}
.bt-gallery .install{background:linear-gradient(135deg,rgba(0,255,102,.08),rgba(0,255,102,.05));border:1px solid rgba(0,255,102,.25);border-radius:0;padding:22px 26px;margin-bottom:44px}
.bt-gallery .install h2{margin:0 0 10px;font-size:15px;color:var(--bt-text);font-weight:700}
.bt-gallery pre{background:#0d1219;border:1px solid rgba(255,255,255,.06);border-radius:9px;padding:14px 18px;overflow-x:auto;font-family:'JetBrains Mono','SF Mono',monospace;font-size:12.5px;color:var(--bt-text);margin:8px 0;line-height:1.7}
.bt-gallery .tok-a{color:#7dd3fc}.bt-gallery .tok-v{color:#86efac}.bt-gallery .tok-t{color:#f472b6}
.bt-gallery .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:32px}
.bt-gallery .card{background:#0d1219;border:1px solid rgba(255,255,255,.05);border-radius:0;overflow:hidden}
.bt-gallery .card-head{padding:18px 22px;border-bottom:1px solid rgba(255,255,255,.05)}
.bt-gallery .card-head h3{margin:0 0 4px;font-size:16px;color:var(--bt-text);font-weight:700}
.bt-gallery .card-head p{margin:0;font-size:12.5px;color:var(--bt-text-2);line-height:1.55}
.bt-gallery .card-preview{padding:22px;background:#0A0B0D;border-bottom:1px solid rgba(255,255,255,.05)}
.bt-gallery .card-snippet{padding:14px 18px 18px;background:#0b0f1a}
.bt-gallery .card-snippet strong{display:block;font-size:10px;font-weight:800;letter-spacing:1.4px;text-transform:uppercase;color:var(--bt-text-3);margin-bottom:6px}
.bt-gallery .card-snippet pre{margin:0;font-size:11.5px;padding:11px 14px}
.bt-gallery .card-copy{float:right;background:rgba(0,255,102,.1);border:1px solid rgba(0,255,102,.3);color:#00FF66;padding:3px 10px;border-radius:5px;font-size:10.5px;font-weight:700;cursor:pointer;font-family:inherit;transition:background .15s}
.bt-gallery .card-copy:hover{background:rgba(0,255,102,.2)}
.bt-gallery .card-copy.ok{color:#0A0B0D;background:#00FF66;border-color:#00FF66}
.bt-gallery .opts{margin-top:32px;background:#0d1219;border:1px solid rgba(255,255,255,.05);border-radius:0;padding:22px 26px}
.bt-gallery .opts h3{margin:0 0 14px;font-size:15px;color:var(--bt-text);font-weight:700}
.bt-gallery table{width:100%;border-collapse:collapse;font-size:13px}
.bt-gallery th{text-align:left;padding:10px 14px;background:rgba(255,255,255,.03);color:var(--bt-text-3);font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.8px;border-bottom:1px solid rgba(255,255,255,.04)}
.bt-gallery td{padding:10px 14px;border-bottom:1px solid rgba(255,255,255,.04);vertical-align:top}
.bt-gallery td:first-child{color:#00FF66;font-family:var(--bt-font-mono);white-space:nowrap}
.bt-gallery td code{color:#7dd3fc;background:rgba(125,211,252,.08);padding:1px 6px;border-radius:0;font-size:11.5px}
</style>

<div class="bt-gallery">
  <h1>📦 Embeddable Widgets</h1>
  <p class="lede">Drop live <?php echo $site; ?> widgets into any site with one line of code. No API key needed. No build tools. No framework. Just HTML.</p>

  <div class="install">
    <h2>1. Install the SDK (once, anywhere in your HTML)</h2>
    <pre>&lt;<span class="tok-t">script</span> <span class="tok-a">async</span> <span class="tok-a">src</span>=<span class="tok-v">"<?php echo $sdk_url; ?>"</span>&gt;&lt;/<span class="tok-t">script</span>&gt;</pre>
    <p style="color:var(--bt-text-2);font-size:13px;margin:12px 0 0">The SDK is ~8 KB gzipped, cached for 1 hour, and uses Shadow DOM so it can't interfere with your site's CSS.</p>
  </div>

  <h2 style="color:var(--bt-text);font-size:22px;margin:0 0 8px">2. Drop in any widget</h2>
  <p style="color:var(--bt-text-2);font-size:14px;margin:0 0 28px">Four widget types. All support <code style="background:rgba(125,211,252,.08);color:#7dd3fc;padding:2px 8px;border-radius:0">data-theme="light"</code> and <code style="background:rgba(125,211,252,.08);color:#7dd3fc;padding:2px 8px;border-radius:0">data-refresh="60"</code>.</p>

  <div class="grid">

    <!-- Ticker -->
    <div class="card">
      <div class="card-head">
        <h3>🎞 Price Ticker</h3>
        <p>Horizontal scrolling prices for multiple coins. Pauses on hover.</p>
      </div>
      <div class="card-preview">
        <div class="bt-widget" data-widget="ticker" data-symbols="BTC,ETH,SOL,BNB,XRP"></div>
      </div>
      <div class="card-snippet">
        <button class="card-copy" onclick="btCopy(this, '&lt;div class=\'bt-widget\' data-widget=\'ticker\' data-symbols=\'BTC,ETH,SOL,BNB,XRP\'&gt;&lt;/div&gt;')">Copy</button>
        <strong>Snippet</strong>
        <pre>&lt;<span class="tok-t">div</span> <span class="tok-a">class</span>=<span class="tok-v">"bt-widget"</span> <span class="tok-a">data-widget</span>=<span class="tok-v">"ticker"</span>
     <span class="tok-a">data-symbols</span>=<span class="tok-v">"BTC,ETH,SOL,BNB,XRP"</span>&gt;&lt;/<span class="tok-t">div</span>&gt;</pre>
      </div>
    </div>

    <!-- Price card -->
    <div class="card">
      <div class="card-head">
        <h3>💰 Single Price Card</h3>
        <p>One coin with big price, 24h change, market cap and rank.</p>
      </div>
      <div class="card-preview">
        <div class="bt-widget" data-widget="price" data-symbol="BTC" style="max-width:300px;margin:0 auto"></div>
      </div>
      <div class="card-snippet">
        <button class="card-copy" onclick="btCopy(this, '&lt;div class=\'bt-widget\' data-widget=\'price\' data-symbol=\'BTC\'&gt;&lt;/div&gt;')">Copy</button>
        <strong>Snippet</strong>
        <pre>&lt;<span class="tok-t">div</span> <span class="tok-a">class</span>=<span class="tok-v">"bt-widget"</span> <span class="tok-a">data-widget</span>=<span class="tok-v">"price"</span>
     <span class="tok-a">data-symbol</span>=<span class="tok-v">"BTC"</span>&gt;&lt;/<span class="tok-t">div</span>&gt;</pre>
      </div>
    </div>

    <!-- Fear & Greed -->
    <div class="card">
      <div class="card-head">
        <h3>😨 Fear &amp; Greed Gauge</h3>
        <p>The Crypto F&amp;G Index as a colored arc gauge. Updates every 5 min.</p>
      </div>
      <div class="card-preview">
        <div class="bt-widget" data-widget="fear-greed" style="max-width:280px;margin:0 auto"></div>
      </div>
      <div class="card-snippet">
        <button class="card-copy" onclick="btCopy(this, '&lt;div class=\'bt-widget\' data-widget=\'fear-greed\'&gt;&lt;/div&gt;')">Copy</button>
        <strong>Snippet</strong>
        <pre>&lt;<span class="tok-t">div</span> <span class="tok-a">class</span>=<span class="tok-v">"bt-widget"</span>
     <span class="tok-a">data-widget</span>=<span class="tok-v">"fear-greed"</span>&gt;&lt;/<span class="tok-t">div</span>&gt;</pre>
      </div>
    </div>

    <!-- Signals -->
    <div class="card">
      <div class="card-head">
        <h3>📡 Trading Signals Feed</h3>
        <p>Latest signals from FXStreet, DailyFX, CoinDesk. Each item links out.</p>
      </div>
      <div class="card-preview">
        <div class="bt-widget" data-widget="signals" data-limit="4"></div>
      </div>
      <div class="card-snippet">
        <button class="card-copy" onclick="btCopy(this, '&lt;div class=\'bt-widget\' data-widget=\'signals\' data-limit=\'5\'&gt;&lt;/div&gt;')">Copy</button>
        <strong>Snippet</strong>
        <pre>&lt;<span class="tok-t">div</span> <span class="tok-a">class</span>=<span class="tok-v">"bt-widget"</span> <span class="tok-a">data-widget</span>=<span class="tok-v">"signals"</span>
     <span class="tok-a">data-limit</span>=<span class="tok-v">"5"</span>&gt;&lt;/<span class="tok-t">div</span>&gt;</pre>
      </div>
    </div>

  </div>

  <div class="opts">
    <h3>Common Options (work on every widget)</h3>
    <table>
      <thead><tr><th>Attribute</th><th>Values</th><th>Description</th></tr></thead>
      <tbody>
        <tr><td>data-theme</td><td><code>dark</code> (default) · <code>light</code></td><td>Color scheme — picks whichever fits your site</td></tr>
        <tr><td>data-refresh</td><td>seconds, min <code>15</code></td><td>Auto-refresh interval. Omit for single-load.</td></tr>
        <tr><td>data-symbols</td><td>Comma-separated (ticker only)</td><td>E.g. <code>BTC,ETH,SOL</code>. Uses top coins if omitted.</td></tr>
        <tr><td>data-symbol</td><td>Single ticker (price only)</td><td>E.g. <code>BTC</code>, <code>ETH</code>, <code>SOL</code></td></tr>
        <tr><td>data-limit</td><td>1-20 (signals only)</td><td>How many signals to show (default 5)</td></tr>
      </tbody>
    </table>
  </div>

  <div class="opts" style="background:rgba(0,255,102,.04);border-color:rgba(0,255,102,.2)">
    <h3 style="color:#bae6fd">Programmatic API</h3>
    <p style="color:var(--bt-text-2);font-size:13px;line-height:1.65;margin:0 0 12px">Prefer JS? Mount widgets imperatively:</p>
    <pre style="margin:0"><span class="tok-t">BlockTicker</span>.<span class="tok-a">mount</span>(<span class="tok-v">'#my-container'</span>, {
  <span class="tok-a">widget</span>: <span class="tok-v">'fear-greed'</span>,
  <span class="tok-a">theme</span>:  <span class="tok-v">'light'</span>,
  <span class="tok-a">refresh</span>: <span class="tok-v">60</span>
});</pre>
  </div>
</div>

<script>
function btCopy(btn, text){
  var decoded = text.replace(/&lt;/g,'<').replace(/&gt;/g,'>').replace(/&amp;/g,'&');
  navigator.clipboard.writeText(decoded).then(function(){
    var o = btn.textContent; btn.textContent = '✓ Copied'; btn.classList.add('ok');
    setTimeout(function(){ btn.textContent = o; btn.classList.remove('ok'); }, 1500);
  });
}
</script>
<script async src="<?php echo $sdk_url; ?>?v=<?php echo esc_attr( BT_VERSION ); ?>"></script>
        <?php
        return ob_get_clean();
    }
}

BT_API::init();
