<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * BT_Widgets — v17 Fixes
 * FX-01  : Forex via Frankfurter.app (free, no key).
 * FX-02  : 24h % change from stored previous rates.
 * FX-03  : Demo data badge shown when live fetch fails.
 * PERF-01: REST API endpoint replaces admin-ajax for price polling.
 * PERF-02: TradingView lazy-loads via IntersectionObserver.
 * ADS-01 : AdSense slot guarded against placeholder.
 * UX-02  : Ticker bar shows refresh timestamp.
 * UX-03  : Crypto full table has load-more link.
 */
class BT_Widgets {

    public static function setup() {
        self::schedule_refresh();
        self::fetch_forex_prices();
        self::fetch_crypto_prices();
        return array( 'success' => true, 'message' => 'Live data widgets configured. Frankfurter.app used for forex (no API key needed).' );
    }

    /**
     * Safe HTTP GET with timeout + HTTP 200 check.
     * Returns decoded JSON array or null on failure.
     */
    /**
     * Get a JSON-encoded option as an array.
     *
     * @deprecated 69.0.0 Use BT_Utils::get_option_json() directly. This static
     *             accessor is kept for backward compatibility — it's referenced
     *             from ~35 call sites across aiblog / admin / asset-pages / rss.
     *
     * @param string $key
     * @param mixed  $default
     * @return array|mixed
     */
    public static function get_json_option( $key, $default = array() ) {
        return BT_Utils::get_option_json( $key, $default );
    }

    /**
     * Store an array as a JSON-encoded option.
     *
     * @deprecated 69.0.0 Use BT_Utils::set_option_json() directly.
     * @param string $key
     * @param mixed  $value
     * @return void
     */
    public static function set_json_option( $key, $value ) {
        BT_Utils::set_option_json( $key, $value, false );
    }

    /**
     * Fetch a URL and decode as JSON. Returns null on error, with error_log().
     *
     * This helper predates BT_Utils::http_get_json() (which returns WP_Error
     * instead). It's retained as a thin adapter so existing callers inside this
     * class don't need null-check → WP_Error rewrites.
     *
     * @param string $url  Absolute URL to fetch.
     * @param array  $args wp_remote_get args. Merged with defaults by utility.
     * @return array|null  Decoded JSON as assoc array, or null on any failure.
     */
    private static function safe_get_json( $url, $args = array() ) {
        $result = BT_Utils::http_get_json( $url, $args );
        if ( is_wp_error( $result ) ) {
            error_log( 'BlockTicker HTTP: ' . $result->get_error_message() . ' — ' . $url );
            return null;
        }
        return $result;
    }

    public static function register_shortcodes() {
        add_shortcode( 'fxlm_live_prices',       array( __CLASS__, 'sc_live_prices' ) );
        add_shortcode( 'fxlm_forex_table',       array( __CLASS__, 'sc_forex_table' ) );
        // v56: Auto-inject Twitter thread preview on daily-report blog posts
        add_filter( 'the_content', array( __CLASS__, 'append_thread_to_content' ), 20 );
        // v63: Social share bar prepended to AI-generated posts
        // v93: share bar now handled exclusively by BT_EEAT::inject_share_bar (priority 15)
        add_shortcode( 'fxlm_crypto_table',      array( __CLASS__, 'sc_crypto_table' ) );
        add_shortcode( 'fxlm_crypto_full_table', array( __CLASS__, 'sc_crypto_full_table' ) );
        add_shortcode( 'fxlm_tradingview_chart', array( __CLASS__, 'sc_tradingview_chart' ) );
        add_shortcode( 'fxlm_ticker_bar',        array( __CLASS__, 'sc_ticker_bar' ) );
        add_shortcode( 'fxlm_economic_calendar', array( __CLASS__, 'sc_economic_calendar' ) );
        add_shortcode( 'fxlm_adsense_banner',    array( __CLASS__, 'sc_adsense_banner' ) );
        add_shortcode( 'fxlm_ai_analysis',       array( __CLASS__, 'sc_ai_analysis' ) );
        add_shortcode( 'fxlm_market_mood',        array( __CLASS__, 'sc_market_mood' ) ); // SENT-01
        add_shortcode( 'fxlm_gainers_losers',     array( __CLASS__, 'sc_gainers_losers' ) );
        add_shortcode( 'fxlm_watchlist',          array( __CLASS__, 'sc_watchlist' ) );
        add_shortcode( 'bt_market_pulse',          array( __CLASS__, 'sc_market_pulse' ) );
        add_shortcode( 'bt_forex_sentiment',       array( __CLASS__, 'sc_forex_sentiment' ) );
        add_shortcode( 'fxlm_calculator',         array( __CLASS__, 'sc_calculator' ) );
        add_shortcode( 'fxlm_bottom_ticker',      array( __CLASS__, 'sc_bottom_ticker' ) );
        add_shortcode( 'fxlm_crypto_category',    array( __CLASS__, 'sc_crypto_category' ) );
        add_shortcode( 'bt_threads_archive',       array( __CLASS__, 'sc_threads_archive' ) );

        // v70: Signal tracker (cron + shortcodes) moved to BT_SignalTracker class

        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'frontend_assets' ) );

        // Legacy admin-ajax kept for backward compat
        add_action( 'wp_ajax_fxlm_get_prices',        array( __CLASS__, 'ajax_get_prices' ) );
        add_action( 'wp_ajax_nopriv_fxlm_get_prices', array( __CLASS__, 'ajax_get_prices' ) );

        // REST API — lighter than admin-ajax (FIX PERF-01)
        add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
    }

    public static function register_rest_routes() {
        register_rest_route( 'blockticker/v1', '/prices', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'rest_get_prices' ),
            'permission_callback' => '__return_true',
        ) );
        register_rest_route( 'blockticker/v1', '/gainers', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'rest_get_gainers' ),
            'permission_callback' => '__return_true',
        ) );
        register_rest_route( 'blockticker/v1', '/watchlist', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'rest_get_watchlist' ),
            'permission_callback' => '__return_true',
        ) );
    }


    public static function rest_get_prices( WP_REST_Request $request ) {
        $crypto = self::get_json_option( 'fxlm_crypto_data' );
        $forex  = self::get_json_option( 'fxlm_forex_data' );
        // If forex is still demo, attempt a fresh fetch (cached 5 min to avoid hammering)
        if ( ( $forex['source'] ?? '' ) === 'demo' || empty( $forex['rates'] ) ) {
            if ( ! get_transient('bt_forex_rest_fresh') ) {
                self::fetch_forex_prices();
                set_transient( 'bt_forex_rest_fresh', 1, 5 * MINUTE_IN_SECONDS );
                $forex = self::get_json_option( 'fxlm_forex_data' );
            }
        }
        return rest_ensure_response( array(
            'forex'  => $forex,
            'crypto' => $crypto,
        ) );
    }

    // REST: /wp-json/blockticker/v1/gainers?limit=100|500|all
    public static function rest_get_gainers( WP_REST_Request $request ) {
        $crypto = self::get_json_option( 'fxlm_crypto_data' );
        $coins  = $crypto['coins'] ?? [];
        $limit  = $request->get_param('limit') ?? '100';
        if ( $limit !== 'all' ) {
            $lim   = intval($limit);
            $coins = array_slice( $coins, 0, $lim ); // coins are sorted by mcap, top N by mcap
        }
        usort( $coins, fn($a,$b) => floatval($b['price_change_percentage_24h']??0) <=> floatval($a['price_change_percentage_24h']??0) );
        $gainers = array_slice( $coins, 0, 10 );
        $losers  = array_slice( array_reverse( $coins ), 0, 10 );
        $total   = count( $crypto['coins'] ?? [] );
        return rest_ensure_response( array( 'gainers'=>$gainers, 'losers'=>$losers, 'total'=>$total ) );
    }

    // REST: /wp-json/blockticker/v1/watchlist?ids=bitcoin,ethereum
    public static function rest_get_watchlist( WP_REST_Request $request ) {
        $ids    = array_filter( array_map( 'sanitize_text_field', explode( ',', $request->get_param('ids') ?? '' ) ) );
        $crypto = self::get_json_option( 'fxlm_crypto_data' );
        $coins  = $crypto['coins'] ?? [];
        $result = [];
        foreach ( $coins as $coin ) {
            if ( in_array( $coin['id'], $ids ) || in_array( strtolower($coin['symbol']), $ids ) ) {
                $result[] = $coin;
            }
        }
        return rest_ensure_response( $result );
    }

    public static function frontend_assets() {
        // Load on ALL front-end pages — navbar and footer CSS must be present everywhere
        // The CSS is lightweight enough that loading everywhere is correct behaviour
        if ( is_admin() ) return;

        global $post;

        // Enqueue on every public-facing page (navbar, footer, dark theme all need this)
        $ver = BT_VERSION . '.' . max(
            filemtime( BT_DIR . 'assets/css/frontend.css' ),
            filemtime( BT_DIR . 'assets/css/critical-fixes.css' )
        );
        wp_enqueue_style( 'fxlm-critical', BT_URL . 'assets/css/critical-fixes.css', array(), $ver );
        wp_enqueue_style( 'fxlm-frontend', BT_URL . 'assets/css/frontend.css', array('fxlm-critical'), $ver );
        wp_enqueue_style( 'fxlm-patch',    BT_URL . 'assets/css/patch-animations.css', array('fxlm-frontend'), $ver );
        // v44 Revamp layer — loads LAST so it overrides both frontend + patch styles.
        if ( file_exists( BT_DIR . 'assets/css/revamp-v44.css' ) ) {
            wp_enqueue_style( 'fxlm-revamp-v44', BT_URL . 'assets/css/revamp-v44.css',
                array('fxlm-patch'), BT_VERSION . '.' . filemtime( BT_DIR . 'assets/css/revamp-v44.css' ) );
        }
        // Google Fonts — v96.6: moved to BT_CWV::output_async_fonts() (async preload
        // pattern) so the font stylesheet no longer blocks rendering (LCP / FCP fix).
        // The blocking <link rel="stylesheet"> that was here has been removed.
        wp_enqueue_script( 'fxlm-frontend', BT_URL . 'assets/js/frontend.js', array( 'jquery' ), $ver, true );
        wp_enqueue_script( 'fxlm-patch',    BT_URL . 'assets/js/patch-animations.js', array( 'jquery' ), $ver, true );
        if ( file_exists( BT_DIR . 'assets/js/revamp-v44.js' ) ) {
            wp_enqueue_script( 'fxlm-revamp-v44', BT_URL . 'assets/js/revamp-v44.js',
                array(), BT_VERSION . '.' . filemtime( BT_DIR . 'assets/js/revamp-v44.js' ), true );
        }
        wp_localize_script( 'fxlm-frontend', 'fxlm_data', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'rest_url' => rest_url( 'blockticker/v1/prices' ),
            'nonce'    => wp_create_nonce( 'fxlm_prices' ),
            'refresh'  => 60,
            'i18n'     => array(
                'no_data' => BT_I18N::get( 'widget.no_data' ),
                'loading' => BT_I18N::get( 'widget.loading' ),
            ),
        ) );
    }

    public static function schedule_refresh() {
        if ( ! wp_next_scheduled( 'bt_refresh_prices' ) ) {
            wp_schedule_event( time(), 'bt_five_minutes', 'bt_refresh_prices' );
        }
        // Exchange enrichment: runs every 6 hours
        if ( ! wp_next_scheduled( 'bt_refresh_exchanges' ) ) {
            wp_schedule_event( time(), 'bt_hourly', 'bt_refresh_exchanges' );
        }
    }

    public static function fetch_and_store_all() {
        self::fetch_forex_prices();
        self::fetch_crypto_prices();
    }

    /**
     * Fetch detail data for top exchanges (markets, coins, fiat).
     * CoinGecko /exchanges list doesn't include these — requires individual calls.
     * Fetches top 30 and caches results for 6 hours.
     */

    // FIX FX-01 + FX-02 + FX-03
    public static function fetch_forex_prices() {
        $rates_new = self::fetch_frankfurter_rates();
        $source    = 'live';

        // Fallback: ExchangeRate-API if Frankfurter fails and key is set
        if ( empty( $rates_new ) ) {
            $api_key = get_option( 'bt_fx_api_key', '' );
            if ( ! empty( $api_key ) ) {
                $response = wp_remote_get( "https://v6.exchangerate-api.com/v6/{$api_key}/latest/USD", array( 'timeout' => 15 ) );
                if ( ! is_wp_error( $response ) ) {
                    $body = json_decode( wp_remote_retrieve_body( $response ), true );
                    if ( ! empty( $body['conversion_rates'] ) ) {
                        foreach ( array( 'EUR', 'GBP', 'JPY', 'CHF', 'AUD', 'CAD', 'NZD', 'THB', 'TRY', 'HKD', 'ILS', 'MXN', 'SGD' ) as $p ) {
                            if ( isset( $body['conversion_rates'][ $p ] ) ) {
                                $rates_new[ 'USD/' . $p ] = floatval( $body['conversion_rates'][ $p ] );
                            }
                        }
                    }
                }
            }
        }

        if ( ! empty( $rates_new ) ) {
            // Compute 24h % change using yesterday's snapshot
            $prev  = get_option( 'bt_forex_prev_rates', array() );
            $rates = array();
            foreach ( $rates_new as $pair => $rate ) {
                $prev_rate = isset( $prev[ $pair ] ) ? floatval( $prev[ $pair ] ) : 0;
                $change    = $prev_rate > 0 ? round( ( ( $rate - $prev_rate ) / $prev_rate ) * 100, 3 ) : 0;
                $rates[ $pair ] = array( 'rate' => $rate, 'change' => $change );
            }
            // Store snapshot: update once per hour so next hour's fetch has a real baseline
            $last = get_option( 'bt_forex_prev_updated', 0 );
            if ( ( time() - $last ) >= 3600 ) {
                update_option( 'bt_forex_prev_rates',   $rates_new );
                update_option( 'bt_forex_prev_updated', time() );
            }
            self::set_json_option( 'fxlm_forex_data', array( 'rates' => $rates, 'updated' => time(), 'source' => $source ) );
            return;
        }

        // Last resort: keep last live data rather than overwriting with demo (FIX FX-03)
        $existing = self::get_json_option( 'fxlm_forex_data' );
        if ( ! empty( $existing['rates'] ) && ( $existing['source'] ?? '' ) !== 'demo' ) {
            return;
        }
        $demo = array(
            'EUR/USD' => array( 'rate' => 1.0845, 'change' => 0.12 ),
            'GBP/USD' => array( 'rate' => 1.2734, 'change' => -0.08 ),
            'USD/JPY' => array( 'rate' => 149.52,  'change' => 0.23 ),
            'USD/CHF' => array( 'rate' => 0.8923,  'change' => -0.05 ),
            'AUD/USD' => array( 'rate' => 0.6542,  'change' => 0.15 ),
            'USD/CAD' => array( 'rate' => 1.3678,  'change' => -0.11 ),
            'NZD/USD' => array( 'rate' => 0.6123,  'change' => 0.09 ),
            'EUR/GBP' => array( 'rate' => 0.8512,  'change' => 0.04 ),
        );
        update_option( 'bt_forex_data', array( 'rates' => $demo, 'updated' => time(), 'source' => 'demo' ) );
    }

    private static function fetch_frankfurter_rates() {
        // --- Source 1: Frankfurter.app (ECB data, free, no key) ---
        $usd_body = self::safe_get_json( 'https://api.frankfurter.app/latest?from=USD&to=EUR,GBP,JPY,CHF,AUD,CAD,NZD,THB,TRY,HKD,ILS,MXN,SGD' );

        // --- Source 2: Fallback to exchangerate.host (free, no key needed) ---
        if ( empty( $usd_body['rates'] ) ) {
            $usd_body = self::safe_get_json( 'https://open.er-api.com/v6/latest/USD' );
            if ( ! empty( $usd_body['rates'] ) ) {
                // Normalize: pick only the pairs we need
                $keep = [ 'EUR', 'GBP', 'JPY', 'CHF', 'AUD', 'CAD', 'NZD', 'THB', 'TRY', 'HKD', 'ILS', 'MXN', 'SGD' ];
                $usd_body['rates'] = array_intersect_key( $usd_body['rates'], array_flip($keep) );
            }
        }

        // --- Source 3: fawazahmed0 currency API (truly free, GitHub-hosted) ---
        if ( empty( $usd_body['rates'] ) ) {
            $fb = self::safe_get_json( 'https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@latest/v1/currencies/usd.json' );
            if ( ! empty( $fb['usd'] ) ) {
                $keep = [ 'eur'=>'EUR', 'gbp'=>'GBP', 'jpy'=>'JPY', 'chf'=>'CHF', 'aud'=>'AUD', 'cad'=>'CAD', 'nzd'=>'NZD',
                          'thb'=>'THB', 'try'=>'TRY', 'hkd'=>'HKD', 'ils'=>'ILS', 'mxn'=>'MXN', 'sgd'=>'SGD' ];
                $built = [];
                foreach ( $keep as $lc => $uc ) {
                    if ( isset($fb['usd'][$lc]) ) $built[$uc] = floatval($fb['usd'][$lc]);
                }
                if ( ! empty($built) ) $usd_body = ['rates' => $built];
            }
        }

        // --- Source 4: exchangerate.host (last resort) ---
        if ( empty( $usd_body['rates'] ) ) {
            $fb4 = self::safe_get_json( 'https://api.exchangerate.host/latest?base=USD&symbols=EUR,GBP,JPY,CHF,AUD,CAD,NZD,THB,TRY,HKD,ILS,MXN,SGD' );
            if ( ! empty( $fb4['rates'] ) ) {
                $usd_body = ['rates' => $fb4['rates']];
            }
        }

        // --- Source 5: currencyfreaks.com (free tier, no key) ---
        if ( empty( $usd_body['rates'] ) ) {
            $fb5 = self::safe_get_json( 'https://api.currencyfreaks.com/v2.0/rates/latest?symbols=EUR,GBP,JPY,CHF,AUD,CAD,NZD,THB,TRY,HKD,ILS,MXN,SGD' );
            if ( ! empty( $fb5['rates'] ) ) {
                $usd_body = ['rates' => $fb5['rates']];
            }
        }

        if ( empty( $usd_body['rates'] ) ) return array();

        $rates = array();
        foreach ( $usd_body['rates'] as $currency => $rate ) {
            $rates[ 'USD/' . $currency ] = floatval( $rate );
        }
        // Convert USD/EUR -> EUR/USD
        if ( isset( $rates['USD/EUR'] ) && $rates['USD/EUR'] > 0 ) {
            $rates['EUR/USD'] = round( 1 / $rates['USD/EUR'], 5 );
            unset( $rates['USD/EUR'] );
        }
        // Compute GBP/USD from USD/GBP
        if ( isset( $rates['USD/GBP'] ) && $rates['USD/GBP'] > 0 ) {
            $rates['GBP/USD'] = round( 1 / $rates['USD/GBP'], 5 );
            unset( $rates['USD/GBP'] );
        }

        // EUR/GBP from second call (Frankfurter first, fallback computed)
        $eur_resp = wp_remote_get( 'https://api.frankfurter.app/latest?from=EUR&to=GBP', array( 'timeout' => 8 ) );
        if ( ! is_wp_error( $eur_resp ) ) {
            $eur_body = json_decode( wp_remote_retrieve_body( $eur_resp ), true );
            if ( ! empty( $eur_body['rates']['GBP'] ) ) {
                $rates['EUR/GBP'] = floatval( $eur_body['rates']['GBP'] );
            }
        }
        // Compute EUR/GBP if still missing
        if ( ! isset($rates['EUR/GBP']) && isset($rates['EUR/USD']) && isset($rates['GBP/USD']) && $rates['GBP/USD'] > 0 ) {
            $rates['EUR/GBP'] = round( $rates['EUR/USD'] / $rates['GBP/USD'], 5 );
        }

        // FIX FX-CHANGE: Seed prev_rates from yesterday if not yet set
        $prev_rates = get_option( 'bt_forex_prev_rates', array() );
        if ( empty( $prev_rates ) ) {
            $yesterday = date( 'Y-m-d', strtotime( '-1 day' ) );
            $prev_resp = wp_remote_get( 'https://api.frankfurter.app/' . $yesterday . '?from=USD&to=EUR,GBP,JPY,CHF,AUD,CAD,NZD,THB,TRY,HKD,ILS,MXN,SGD', array( 'timeout' => 10 ) );
            if ( ! is_wp_error( $prev_resp ) ) {
                $prev_body = json_decode( wp_remote_retrieve_body( $prev_resp ), true );
                if ( ! empty( $prev_body['rates'] ) ) {
                    $prev_seed = array();
                    foreach ( $prev_body['rates'] as $currency => $rate ) {
                        $prev_seed[ 'USD/' . $currency ] = floatval( $rate );
                    }
                    if ( isset( $prev_seed['USD/EUR'] ) && $prev_seed['USD/EUR'] > 0 ) {
                        $prev_seed['EUR/USD'] = round( 1 / $prev_seed['USD/EUR'], 5 );
                        unset( $prev_seed['USD/EUR'] );
                    }
                    if ( isset( $prev_seed['USD/GBP'] ) && $prev_seed['USD/GBP'] > 0 ) {
                        $prev_seed['GBP/USD'] = round( 1 / $prev_seed['USD/GBP'], 5 );
                        unset( $prev_seed['USD/GBP'] );
                    }
                    $prev_eur_resp = wp_remote_get( 'https://api.frankfurter.app/' . $yesterday . '?from=EUR&to=GBP', array( 'timeout' => 8 ) );
                    if ( ! is_wp_error( $prev_eur_resp ) ) {
                        $prev_eur_body = json_decode( wp_remote_retrieve_body( $prev_eur_resp ), true );
                        if ( ! empty( $prev_eur_body['rates']['GBP'] ) ) {
                            $prev_seed['EUR/GBP'] = floatval( $prev_eur_body['rates']['GBP'] );
                        }
                    }
                    update_option( 'bt_forex_prev_rates',   $prev_seed );
                    update_option( 'bt_forex_prev_updated', time() - 86400 );
                }
            }
        }

        return $rates;
    }

    public static function fetch_crypto_prices() {
        $url     = 'https://api.coingecko.com/api/v3/coins/markets?vs_currency=usd&order=market_cap_desc&per_page=100&page=1&sparkline=true&price_change_percentage=24h';
        $api_key = get_option( 'bt_cg_api_key', '' );
        $args    = array( 'timeout' => 15 );
        if ( $api_key ) $args['headers'] = array( 'x-cg-demo-api-key' => $api_key );
        $body = self::safe_get_json( $url, $args );
        if ( empty( $body ) || ! is_array( $body ) ) return;
        self::set_json_option( 'fxlm_crypto_data', array( 'coins' => $body, 'updated' => time() ) );
    }

    public static function ajax_get_prices() {
        BT_Utils::verify_public_ajax( 'fxlm_prices' );
        wp_send_json_success( array(
            'forex'  => self::get_json_option( 'fxlm_forex_data' ),
            'crypto' => self::get_json_option( 'fxlm_crypto_data' ),
        ) );
    }

    // ── SHORTCODES ────────────────────────────────────────────────

    // FIX UX-02: timestamp on ticker
    public static function sc_ticker_bar( $atts ) {
        $forex   = self::get_json_option( 'fxlm_forex_data' );
        $crypto  = self::get_json_option( 'fxlm_crypto_data' );
        $ts      = isset( $forex['updated'] ) ? $forex['updated'] : ( $crypto['updated'] ?? 0 );
        $ts_html = $ts ? '<span class="fxlm-ticker-ts">⟳ ' . esc_html( human_time_diff( $ts ) ) . ' ago</span>' : '';
        ob_start();
        echo '<div class="fxlm-ticker-wrap"><span class="fxlm-ticker-live" aria-label="Live data">LIVE</span>' . $ts_html . '<div class="fxlm-ticker-inner" id="fxlm-ticker-inner">';
        if ( ! empty( $forex['rates'] ) ) {
            foreach ( $forex['rates'] as $pair => $data ) {
                $chg = floatval( $data['change'] ); $cls = $chg >= 0 ? 'up' : 'down'; $arrow = $chg >= 0 ? '▲' : '▼';
                echo '<span class="fxlm-tick"><strong>' . esc_html( $pair ) . '</strong> ' . number_format( $data['rate'], 4 ) . ' <em class="' . $cls . '">' . $arrow . ' ' . number_format( abs( $chg ), 2 ) . '%</em></span>';
            }
        }
        if ( ! empty( $crypto['coins'] ) ) {
            foreach ( array_slice( $crypto['coins'], 0, 10 ) as $coin ) {
                $chg = floatval( $coin['price_change_percentage_24h'] ?? 0 ); $cls = $chg >= 0 ? 'up' : 'down'; $arrow = $chg >= 0 ? '▲' : '▼';
                echo '<span class="fxlm-tick"><strong>' . esc_html( strtoupper( $coin['symbol'] ) ) . '/USD</strong> $' . number_format( $coin['current_price'], 2 ) . ' <em class="' . $cls . '">' . $arrow . ' ' . number_format( abs( $chg ), 2 ) . '%</em></span>';
            }
        }
        echo '</div></div>';
        return ob_get_clean();
    }

    public static function sc_live_prices( $atts ) { return do_shortcode( '[fxlm_ticker_bar]' ); }

    // FIX FX-03: demo badge in table
    public static function sc_forex_table( $atts ) {
        $forex   = self::get_json_option( 'fxlm_forex_data' );
        $is_demo = ( $forex['source'] ?? '' ) === 'demo' || empty( $forex['rates'] );

        // If stored data is demo, try a fresh fetch right now (transient-cached 5 min)
        if ( $is_demo ) {
            $fresh = get_transient( 'bt_forex_fresh' );
            if ( ! $fresh ) {
                self::fetch_forex_prices();
                set_transient( 'bt_forex_fresh', 1, 5 * MINUTE_IN_SECONDS );
                $forex   = self::get_json_option( 'fxlm_forex_data' );
                $is_demo = ( $forex['source'] ?? '' ) === 'demo';
            }
        }

        if ( empty( $forex['rates'] ) ) return '<p class="fxlm-loading">Loading forex data...</p>';

        // Currency flag emoji map
        $flags = [
            'USD'=>'🇺🇸','EUR'=>'🇪🇺','GBP'=>'🇬🇧','JPY'=>'🇯🇵',
            'CHF'=>'🇨🇭','AUD'=>'🇦🇺','CAD'=>'🇨🇦','NZD'=>'🇳🇿',
            'HKD'=>'🇭🇰','SGD'=>'🇸🇬','NOK'=>'🇳🇴','SEK'=>'🇸🇪',
        ];

        ob_start();
        
?>
        <div class="fxlm-forex-wrap" id="fxlm-forex-wrap">
            <div class="fxlm-forex-header">
                <div>
                    <h2 class="fxlm-forex-title" data-i18n="widget.top_forex_pairs"><?php _ebt('widget.top_forex_pairs'); ?></h2>
                    <p class="fxlm-forex-sub">Major currency pairs · Real-time rates via Frankfurter.app</p>
                </div>
                <div style="display:flex;align-items:center;gap:10px">
                    <?php if ( $is_demo ) : ?>
                    <span class="fxlm-forex-demo-badge">⚠️ Demo data</span>
                    <?php else : ?>
                    <span class="fxlm-forex-live-badge">● LIVE</span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ( $is_demo ) : ?>
            <div class="fxlm-demo-notice">⚠️ <strong>Demo data</strong> — live rates temporarily unavailable. Rates shown are illustrative only.</div>
            <?php endif; ?>

            <div class="fxlm-forex-table-wrap">
                <table class="fxlm-forex-table">
                    <thead>
                        <tr>
                            <th><?php echo esc_html( __bt( 'col.pair' ) ); ?></th>
                            <th class="fxlm-fx-th-num">Rate</th>
                            <th class="fxlm-fx-th-num">24h Change</th>
                            <th class="fxlm-fx-th-num">Bid / Ask</th>
                            <th class="fxlm-fx-th-num">Trend (7d)</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    $pair_meta = [
                        'EUR/USD' => ['base'=>'EUR','quote'=>'USD','name'=>'Euro / US Dollar'],
                        'GBP/USD' => ['base'=>'GBP','quote'=>'USD','name'=>'British Pound / US Dollar'],
                        'USD/JPY' => ['base'=>'USD','quote'=>'JPY','name'=>'US Dollar / Japanese Yen'],
                        'USD/CHF' => ['base'=>'USD','quote'=>'CHF','name'=>'US Dollar / Swiss Franc'],
                        'AUD/USD' => ['base'=>'AUD','quote'=>'USD','name'=>'Australian Dollar / US Dollar'],
                        'USD/CAD' => ['base'=>'USD','quote'=>'CAD','name'=>'US Dollar / Canadian Dollar'],
                        'NZD/USD' => ['base'=>'NZD','quote'=>'USD','name'=>'New Zealand Dollar / US Dollar'],
                        'EUR/GBP' => ['base'=>'EUR','quote'=>'GBP','name'=>'Euro / British Pound'],
                    ];
                    foreach ( $forex['rates'] as $pair => $data ) :
                        $chg       = floatval( $data['change'] ?? 0 );
                        $cls       = $chg >= 0 ? 'up' : 'down';
                        $arrow     = $chg >= 0 ? '▲' : '▼';
                        $rate      = floatval( $data['rate'] ?? 0 );
                        $meta      = $pair_meta[$pair] ?? [];
                        $base_flag = $flags[$meta['base']??''] ?? '';
                        $qt_flag   = $flags[$meta['quote']??''] ?? '';
                        // Estimate bid/ask spread (typical forex spreads)
                        $spread    = ($rate > 100) ? 0.05 : 0.0003;
                        $bid       = number_format($rate - $spread/2, ($rate>10?4:5));
                        $ask       = number_format($rate + $spread/2, ($rate>10?4:5));
                        // v83 — Removed fabricated 7-point sparkline. Frankfurter.app only
                        // provides latest + 1-day-prior data, so intraday sparklines can't be
                        // generated honestly. We now show a simple magnitude bar derived
                        // from the real 24h change — no synthetic pixels.
                        $chg_mag = min( 100, round( abs( $chg ) * 33, 1 ) ); // 3% change → full bar
                        $sc = $chg>=0?'var(--bt-accent)':'var(--bt-danger)';
                    ?>
                    <tr class="fxlm-forex-row">
                        <td class="fxlm-fx-pair-cell">
                            <div class="fxlm-fx-pair-flags"><?php echo $base_flag; ?><span class="fxlm-fx-flag-sep">/</span><?php echo $qt_flag; ?></div>
                            <div>
                                <div class="fxlm-fx-pair-name"><strong><?php echo esc_html($pair); ?></strong></div>
                                <?php if ($meta['name']??''): ?><div class="fxlm-fx-pair-full"><?php echo esc_html($meta['name']); ?></div><?php endif; ?>
                            </div>
                        </td>
                        <td class="fxlm-fx-rate-cell"><strong><?php echo number_format($rate, ($rate>10?4:5)); ?></strong></td>
                        <td class="fxlm-fx-chg-cell <?php echo $cls; ?>">
                            <?php echo $chg != 0 ? $arrow . ' ' . number_format(abs($chg),3) . '%' : '— 0.000%'; ?>
                        </td>
                        <td class="fxlm-fx-bidask-cell">
                            <span class="fxlm-fx-bid"><?php echo $bid; ?></span>
                            <span class="fxlm-fx-sep"> / </span>
                            <span class="fxlm-fx-ask"><?php echo $ask; ?></span>
                        </td>
                        <td class="fxlm-fx-spark-cell">
                            <div class="fxlm-fx-chg-bar" title="24h change magnitude (real data, Frankfurter)" aria-label="24h change: <?php echo number_format($chg,3); ?>%">
                                <div class="fxlm-fx-chg-bar-fill" style="width:<?php echo esc_attr( $chg_mag ); ?>%;background:<?php echo esc_attr( $sc ); ?>"></div>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="fxlm-forex-footer">
                <span>Updated: <?php echo isset($forex['updated']) ? human_time_diff($forex['updated']) . ' ago' : 'N/A'; ?></span>
                <span><?php echo $is_demo ? '<span style="color:var(--bt-accent-warm)">Demo mode</span>' : '<span style="color:var(--bt-accent)">Live — Frankfurter.app</span>'; ?></span>
                <a href="/forex-charts/" class="fxlm-forex-footer-link">View All Forex Charts →</a>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function sc_crypto_table( $atts ) {
        $crypto = self::get_json_option( 'fxlm_crypto_data' );
        if ( empty( $crypto['coins'] ) ) return '<p class="fxlm-loading">Loading crypto data...</p>';
        $a = shortcode_atts( array( 'count' => 10 ), $atts );
        ob_start();
        echo '<div class="fxlm-table-wrap"><table class="fxlm-table"><thead><tr><th>Coin</th><th>Price (USD)</th><th>24h</th></tr></thead><tbody>';
        foreach ( array_slice( $crypto['coins'], 0, intval( $a['count'] ) ) as $coin ) {
            $chg = floatval( $coin['price_change_percentage_24h'] ?? 0 ); $cls = $chg >= 0 ? 'up' : 'down'; $arrow = $chg >= 0 ? '▲' : '▼';
            echo '<tr><td><img src="' . esc_url( $coin['image'] ) . '" width="20" loading="lazy" alt=""> <strong>' . esc_html( $coin['name'] ) . '</strong> <small>' . esc_html( strtoupper( $coin['symbol'] ) ) . '</small></td>';
            echo '<td>$' . number_format( $coin['current_price'], 2 ) . '</td>';
            echo '<td class="' . $cls . '">' . $arrow . ' ' . number_format( abs( $chg ), 2 ) . '%</td></tr>';
        }
        echo '</tbody></table></div>';
        return ob_get_clean();
    }

    // FIX UX-03: load-more link
    public static function sc_crypto_full_table( $atts ) {
        $crypto   = self::get_json_option( 'fxlm_crypto_data' );
        if ( empty( $crypto['coins'] ) ) return '<p class="fxlm-loading">Loading crypto market data...</p>';
        $a        = shortcode_atts( array( 'per_page' => 100 ), $atts );
        $per_page = intval( $a['per_page'] );
        $all      = $crypto['coins'];
        $total    = count( $all );
        ob_start();
        ?>
        <div class="fxlm-cft-wrap" id="fxlm-cft">
            <div class="fxlm-cft-controls">
                <button class="fxlm-wl-star" id="fxlm-cft-wl-btn" title="Toggle Watchlist only">☆</button>
                <input type="text" id="fxlm-cft-search" class="fxlm-wl-search" style="max-width:280px;padding:8px 14px" placeholder="🔍 Search coins…" autocomplete="off">
            </div>
            <div class="fxlm-table-wrap fxlm-table-full" style="overflow-x:auto">
                <table class="fxlm-table" id="fxlm-cft-table">
                    <thead>
                        <tr>
                            <th style="width:30px"></th>
                            <th data-sort="rank" class="fxlm-sortable">⇅ #</th>
                            <th data-sort="name" class="fxlm-sortable">Name</th>
                            <th data-sort="price" class="fxlm-sortable">Price</th>
                            <th data-sort="chg24" class="fxlm-sortable">24h %</th>
                            <th data-sort="mcap" class="fxlm-sortable">Market Cap</th>
                            <th data-sort="vol" class="fxlm-sortable">Volume 24h</th>
                            <th>Price Graph (7d)</th>
                        </tr>
                    </thead>
                    <tbody id="fxlm-cft-body">
                    <?php foreach ( $all as $i => $coin ) :
                        $chg = floatval($coin['price_change_percentage_24h']??0);
                        $cls = $chg >= 0 ? 'up' : 'down';
                        $arrow = $chg >= 0 ? '▲' : '▼';
                        $price = floatval($coin['current_price']??0);
                        $priceStr = '$'.number_format($price, $price>=1?2:6);
                        // Build sparkline SVG from 7d data if available
                        $spark_html = '';
                        if ( !empty($coin['sparkline_in_7d']['price']) ) {
                            $pts = array_values($coin['sparkline_in_7d']['price']);
                            $step = max(1, intval(count($pts)/40));
                            $sampled = [];
                            for($j=0;$j<count($pts);$j+=$step) $sampled[]=$pts[$j];
                            $min_p = min($sampled); $max_p = max($sampled);
                            $range = $max_p - $min_p ?: 1;
                            $w = 100; $h = 36;
                            $points = '';
                            foreach($sampled as $si=>$sp) {
                                $x = round($si/max(1,count($sampled)-1)*$w,1);
                                $y = round($h - (($sp-$min_p)/$range)*$h,1);
                                $points .= "$x,$y ";
                            }
                            $color = $chg>=0?'var(--bt-accent)':'var(--bt-danger)';
                            $spark_html = '<svg viewBox="0 0 '.$w.' '.$h.'" width="100" height="36" xmlns="http://www.w3.org/2000/svg"><polyline points="'.trim($points).'" fill="none" stroke="'.$color.'" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round"/></svg>';
                        }
                    ?>
                    <tr class="fxlm-cft-row" data-id="<?php echo esc_attr($coin['id']); ?>"
                        data-rank="<?php echo $i+1; ?>"
                        data-orig="<?php echo $i+1; ?>"
                        data-price="<?php echo $price; ?>"
                        data-chg="<?php echo $chg; ?>"
                        data-mcap="<?php echo floatval($coin['market_cap']??0); ?>"
                        data-vol="<?php echo floatval($coin['total_volume']??0); ?>"
                        data-name="<?php echo esc_attr($coin['name']); ?>">
                        <td><button class="fxlm-wl-star fxlm-cft-star" data-id="<?php echo esc_attr($coin['id']); ?>" title="Add to watchlist">☆</button></td>
                        <td class="fxlm-exc-rank"><?php echo $i+1; ?></td>
                        <td>
                            <a class="fxlm-cft-asset" href="<?php echo esc_url(home_url('/crypto/'.sanitize_title($coin['id']).'/'));?>">
                                <?php if(!empty($coin['image'])): ?><img class="fxlm-cft-asset__img" src="<?php echo esc_url($coin['image']); ?>" width="24" height="24" loading="lazy" alt=""><?php endif; ?>
                                <span class="fxlm-cft-asset__name"><?php echo esc_html($coin['name']); ?></span>
                                <span class="fxlm-cft-asset__sym"><?php echo esc_html(strtoupper($coin['symbol'])); ?></span>
                            </a>
                        </td>
                        <td style="font-weight:700;color:var(--bt-text)"><?php echo $priceStr; ?></td>
                        <td class="<?php echo $cls; ?>"><?php echo $arrow.' '.number_format(abs($chg),2); ?>%</td>
                        <td>$<?php echo self::format_large($coin['market_cap']??0); ?></td>
                        <td>$<?php echo self::format_large($coin['total_volume']??0); ?></td>
                        <td><?php echo $spark_html ?: '<span style="color:var(--bt-text-4);font-size:11px">—</span>'; ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="fxlm-table-footer">
                <span class="fxlm-table-count" id="fxlm-cft-count">Showing <?php echo $total; ?> assets</span>
                <div class="fxlm-cft-pagination" id="fxlm-cft-pagination"></div>
            </div>
        </div>
        <script>
        (function(){
            var watchlist = JSON.parse(localStorage.getItem('bt_watchlist')||'[]');
            var rows = Array.from(document.querySelectorAll('.fxlm-cft-row'));
            var showWlOnly = false;

            // Init star states
            function updateStars(){
                document.querySelectorAll('.fxlm-cft-star').forEach(function(btn){
                    var id = btn.dataset.id;
                    btn.textContent = watchlist.includes(id) ? '★' : '☆';
                    btn.classList.toggle('starred', watchlist.includes(id));
                });
                var wlBtn = document.getElementById('fxlm-cft-wl-btn');
                if(wlBtn) wlBtn.textContent = showWlOnly ? '★' : '☆';
            }
            updateStars();

            // Star click
            document.querySelectorAll('.fxlm-cft-star').forEach(function(btn){
                btn.addEventListener('click', function(){
                    var id = btn.dataset.id;
                    if(watchlist.includes(id)) watchlist = watchlist.filter(function(w){return w!==id;});
                    else watchlist.push(id);
                    localStorage.setItem('bt_watchlist', JSON.stringify(watchlist));
                    updateStars();
                    if(showWlOnly) filterRows();
                });
            });

            // Watchlist filter toggle
            var wlBtn = document.getElementById('fxlm-cft-wl-btn');
            if(wlBtn) wlBtn.addEventListener('click', function(){
                showWlOnly = !showWlOnly;
                updateStars();
                filterRows();
            });

            // Search
            var searchInp = document.getElementById('fxlm-cft-search');
            if(searchInp) searchInp.addEventListener('input', function(){ filterRows(); });

            // Pagination state (defined before filterRows so it's in scope)
            var perPage = 25;
            var currentPage = 0;

            function filterRows(){
                // Reset to page 0 on any filter change
                currentPage = 0;
                renderPage();
            }

            // Column sort
            document.querySelectorAll('.fxlm-sortable').forEach(function(th){
                th.style.cursor='pointer';
                th.addEventListener('click', function(){
                    var key = th.dataset.sort;
                    var tbody = document.getElementById('fxlm-cft-body');
                    var asc = th.dataset.asc !== '1';
                    th.dataset.asc = asc ? '1' : '0';
                    document.querySelectorAll('.fxlm-sortable').forEach(function(t){ t.dataset.asc=''; });
                    th.dataset.asc = asc ? '1' : '0';
                    rows.sort(function(a,b){
                        var av=0,bv=0;
                        if(key==='rank'){av=parseFloat(a.dataset.rank);bv=parseFloat(b.dataset.rank);}
                        else if(key==='name'){av=a.dataset.name.toLowerCase();bv=b.dataset.name.toLowerCase();return asc?(av>bv?1:-1):(av<bv?1:-1);}
                        else if(key==='price'){av=parseFloat(a.dataset.price);bv=parseFloat(b.dataset.price);}
                        else if(key==='chg24'){av=parseFloat(a.dataset.chg);bv=parseFloat(b.dataset.chg);}
                        else if(key==='mcap'){av=parseFloat(a.dataset.mcap);bv=parseFloat(b.dataset.mcap);}
                        else if(key==='vol'){av=parseFloat(a.dataset.vol);bv=parseFloat(b.dataset.vol);}
                        return asc ? av-bv : bv-av;
                    });
                    rows.forEach(function(r){ tbody.appendChild(r); });
                    rows = Array.from(document.querySelectorAll('.fxlm-cft-row'));
                    currentPage = 0;
                    renderPage();
                });
            });

            // ── PAGINATION ─────────────────────────────────────────
            function renderPage() {
                var allRows = Array.from(document.querySelectorAll('.fxlm-cft-row'));
                var searchQ = (document.getElementById('fxlm-cft-search') ? document.getElementById('fxlm-cft-search').value.toLowerCase() : '');
                // Determine which rows match search/watchlist filter
                var visible = allRows.filter(function(r){
                    var name = (r.dataset.name||'').toLowerCase();
                    var sym = r.querySelector('.fxlm-gl-sym');
                    var symT = sym ? sym.textContent.toLowerCase() : '';
                    var matchSearch = !searchQ || name.includes(searchQ) || symT.includes(searchQ);
                    var matchWl = !showWlOnly || watchlist.includes(r.dataset.id);
                    return matchSearch && matchWl;
                });

                var total = visible.length;
                var pages = Math.max(1, Math.ceil(total / perPage));
                currentPage = Math.min(currentPage, pages - 1);
                var start = currentPage * perPage;
                var end   = start + perPage;

                // Show/hide rows
                allRows.forEach(function(r){ r.style.display = 'none'; });
                visible.slice(start, end).forEach(function(r){ r.style.display = ''; });

                // Update count
                var cnt = document.getElementById('fxlm-cft-count');
                if(cnt) cnt.textContent = 'Showing ' + Math.min(end, total) + ' of ' + total + ' assets';

                // Render page buttons
                var pgDiv = document.getElementById('fxlm-cft-pagination');
                if (!pgDiv) return;
                var html = '';
                if(pages > 1) {
                    if(currentPage > 0)
                        html += '<button class="fxlm-pg-btn" onclick="fxlmCftGoPage('+(currentPage-1)+')">‹</button>';
                    var start_pg = Math.max(0, currentPage - 2);
                    var end_pg   = Math.min(pages - 1, currentPage + 2);
                    if(start_pg > 0) html += '<button class="fxlm-pg-btn" onclick="fxlmCftGoPage(0)">1</button>' + (start_pg > 1 ? '<span class="fxlm-pg-info">…</span>' : '');
                    for(var p = start_pg; p <= end_pg; p++) {
                        html += '<button class="fxlm-pg-btn'+(p===currentPage?' active':'')+'" onclick="fxlmCftGoPage('+p+')">'+(p+1)+'</button>';
                    }
                    if(end_pg < pages - 1) html += (end_pg < pages - 2 ? '<span class="fxlm-pg-info">…</span>' : '') + '<button class="fxlm-pg-btn" onclick="fxlmCftGoPage('+(pages-1)+')">'+pages+'</button>';
                    if(currentPage < pages - 1)
                        html += '<button class="fxlm-pg-btn" onclick="fxlmCftGoPage('+(currentPage+1)+')">›</button>';
                }
                pgDiv.innerHTML = html;
            }

            window.fxlmCftGoPage = function(p) {
                currentPage = p;
                renderPage();
                // Scroll to table top
                var wrap = document.querySelector('.fxlm-cft-wrap');
                if(wrap) wrap.scrollIntoView({behavior:'smooth', block:'start'});
            };

            // Initial render
            renderPage();

            // Re-render on filter changes

            // Initial render
            renderPage();
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    private static function format_large( $n ) {
        if ( $n >= 1e12 ) return number_format( $n / 1e12, 2 ) . 'T';
        if ( $n >= 1e9 )  return number_format( $n / 1e9,  2 ) . 'B';
        if ( $n >= 1e6 )  return number_format( $n / 1e6,  2 ) . 'M';
        return number_format( $n, 0 );
    }

    // FIX PERF-02: lazy-load TradingView charts
    /**
     * Shortcode: [fxlm_tradingview_chart symbol="..." height="..." ...]
     *
     * Thin adapter — actual rendering lives in BT_Utils::render_tv_chart().
     * Kept as a separate method so it can match the shortcode_atts signature.
     *
     * @param array $atts Shortcode attributes.
     * @return string
     */
    public static function sc_tradingview_chart( $atts ) {
        $a = shortcode_atts( array(
            'symbol'    => 'FX:EURUSD',
            'height'    => '400',
            'theme'     => 'dark',
            'interval'  => 'D',
            'hide_top'  => '0',
            'hide_side' => '0',
        ), $atts );

        return BT_Utils::render_tv_chart( array(
            'symbol'    => $a['symbol'],
            'height'    => intval( $a['height'] ),
            'interval'  => $a['interval'],
            'hide_top'  => ! empty( $a['hide_top']  ) && $a['hide_top']  !== '0',
            'hide_side' => ! empty( $a['hide_side'] ) && $a['hide_side'] !== '0',
            'show_fullscreen' => true,
        ) );
    }

    public static function sc_economic_calendar( $atts ) {
        $a = shortcode_atts( array( 'height' => '600', 'currencies' => 'USD,EUR,GBP,JPY,AUD,CAD,CHF,CNY,BTC,ETH' ), $atts );
        $uid = 'econ_cal_' . substr( md5( uniqid() ), 0, 8 );
        ob_start();
        ?>
        <div class="fxlm-econ-cal-wrap" id="<?php echo esc_attr($uid); ?>" style="min-height:<?php echo intval($a['height']); ?>px;border-radius:0;overflow:hidden">
            <div class="fxlm-chart-placeholder fxlm-econ-placeholder">
                <span>📅 Loading Economic Calendar…</span>
            </div>
        </div>
        <script>
        (function(){
            var wrap = document.getElementById('<?php echo esc_js($uid); ?>');
            if(!wrap) return;
            function loadCal(){
                if(wrap.dataset.loaded) return;
                wrap.dataset.loaded='1';
                wrap.innerHTML='';
                var s = document.createElement('script');
                s.src = 'https://s3.tradingview.com/external-embedding/embed-widget-events.js';
                s.async = true;
                s.innerHTML = JSON.stringify({
                    colorTheme: 'dark',
                    isTransparent: false,
                    width: '100%',
                    height: '<?php echo intval($a['height']); ?>',
                    locale: 'en',
                    importanceFilter: '-1,0,1',
                    countryFilter: 'us,eu,gb,jp,au,ca,ch,cn'
                });
                wrap.appendChild(s);
            }
            if('IntersectionObserver' in window){
                var obs = new IntersectionObserver(function(e){
                    e.forEach(function(x){ if(x.isIntersecting){ loadCal(); obs.unobserve(wrap); } });
                }, {rootMargin:'200px'});
                obs.observe(wrap);
            } else { loadCal(); }
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    // FIX ADS-01: guard placeholder slot
    public static function sc_adsense_banner( $atts ) {
        $a      = shortcode_atts( array( 'zone' => 'top', 'slot' => '' ), $atts );
        $pub_id = get_option( 'bt_adsense_id', '' );
        $slot   = ! empty( $a['slot'] ) ? $a['slot'] : get_option( 'bt_adsense_slot', '' );
        if ( empty( $pub_id ) ) return '<!-- AdSense: add publisher ID in BlockTicker Setup -->';
        if ( empty( $slot ) || strpos( $slot, 'YOUR_' ) === 0 ) return '<!-- AdSense slot not configured -->';
        return '<div class="fxlm-ad-zone fxlm-ad-' . esc_attr($a['zone']) . '"><script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=' . esc_attr($pub_id) . '" crossorigin="anonymous"></script><ins class="adsbygoogle" style="display:block" data-ad-client="' . esc_attr($pub_id) . '" data-ad-slot="' . esc_attr($slot) . '" data-ad-format="auto" data-full-width-responsive="true"></ins><script>(adsbygoogle=window.adsbygoogle||[]).push({});</script></div>';
    }

    public static function sc_ai_analysis( $atts ) {
        $analysis = get_option( 'bt_ai_analysis', '' );
        if ( empty( $analysis ) ) return '<div class="fxlm-ai-placeholder"><p>AI market analysis will appear here.</p></div>';
        return '<div class="fxlm-ai-analysis">' . wp_kses_post( $analysis ) . '</div>';
    }

    // ── SENT-01: AI Market Mood Badge ─────────────────────────────
    // Scans recent news headlines for an asset and produces Bullish/Neutral/Bearish
    public static function sc_market_mood( $atts ) {
        $a    = shortcode_atts( array( 'asset' => 'bitcoin', 'show_label' => '1' ), $atts );
        $mood = self::compute_mood( $a['asset'] );
        $cfg  = array(
            'bullish' => array( 'label' => 'Bullish',  'icon' => '🐂', 'color' => 'var(--bt-accent)', 'bg' => 'rgba(0,255,102,.1)',  'border' => 'rgba(0,255,102,.25)'  ),
            'neutral' => array( 'label' => 'Neutral',  'icon' => '😐', 'color' => 'var(--bt-accent-warm)', 'bg' => 'rgba(245,158,11,.1)', 'border' => 'rgba(245,158,11,.25)' ),
            'bearish' => array( 'label' => 'Bearish',  'icon' => '🐻', 'color' => 'var(--bt-danger)', 'bg' => 'rgba(255,59,48,.1)', 'border' => 'rgba(255,59,48,.25)' ),
        );
        $c = $cfg[ $mood ] ?? $cfg['neutral'];
        $label_html = $a['show_label'] === '1'
            ? '<span style="font-size:10px;color:var(--bt-text-3);text-transform:uppercase;letter-spacing:.8px;display:block;margin-bottom:5px">AI Mood</span>'
            : '';
        return '<div style="display:inline-flex;flex-direction:column;align-items:center">'
            . $label_html
            . '<span style="display:inline-flex;align-items:center;gap:6px;padding:5px 14px;border-radius:20px;border:1px solid ' . $c['border'] . ';background:' . $c['bg'] . ';color:' . $c['color'] . ';font-size:13px;font-weight:700">'
            . $c['icon'] . ' ' . $c['label']
            . '</span></div>';
    }

    public static function compute_mood( $asset_keyword ) {
        // Cache mood per asset for 1 hour
        $cache_key = 'fxlm_mood_' . sanitize_key( $asset_keyword );
        $cached    = get_transient( $cache_key );
        if ( $cached !== false ) return $cached;

        $news = self::get_json_option( 'fxlm_news_items' );
        if ( empty( $news ) ) return 'neutral';

        // Keyword matching in recent 24h headlines
        $bullish_words = array( 'surge', 'rally', 'bullish', 'gain', 'rise', 'soar', 'pump', 'breakout', 'high', 'buy', 'inflow', 'accumulate', 'positive', 'growth', 'adoption', 'all-time', 'ath', 'recover', 'jump', 'spike' );
        $bearish_words = array( 'crash', 'fall', 'drop', 'bearish', 'sell', 'dump', 'loss', 'plunge', 'low', 'outflow', 'fear', 'ban', 'hack', 'scam', 'negative', 'decline', 'slide', 'dip', 'correction', 'warning' );

        $bull_score = 0;
        $bear_score = 0;
        $now        = time();
        $count      = 0;

        foreach ( array_slice( $news, 0, 50 ) as $item ) {
            // Only headlines mentioning this asset
            if ( stripos( $item['title'], $asset_keyword ) === false ) continue;
            // Only last 48 hours
            if ( isset( $item['timestamp'] ) && ( $now - $item['timestamp'] ) > 172800 ) continue;

            $title_lower = strtolower( $item['title'] );
            foreach ( $bullish_words as $w ) { if ( strpos( $title_lower, $w ) !== false ) $bull_score++; }
            foreach ( $bearish_words as $w ) { if ( strpos( $title_lower, $w ) !== false ) $bear_score++; }
            $count++;
        }

        if ( $count === 0 ) { set_transient( $cache_key, 'neutral', HOUR_IN_SECONDS ); return 'neutral'; }

        if ( $bull_score > $bear_score * 1.3 )      $mood = 'bullish';
        elseif ( $bear_score > $bull_score * 1.3 )  $mood = 'bearish';
        else                                         $mood = 'neutral';

        set_transient( $cache_key, $mood, HOUR_IN_SECONDS );
        return $mood;
    }
    // ── GAINERS & LOSERS ─────────────────────────────────────────────────────
    public static function sc_gainers_losers( $atts ) {
        $crypto = self::get_json_option( 'fxlm_crypto_data' );
        $coins  = $crypto['coins'] ?? [];
        if ( empty( $coins ) ) return '<p class="fxlm-loading">Loading market data…</p>';

        // Sort by 24h change
        $sorted = $coins;
        usort( $sorted, fn($a,$b) => floatval($b['price_change_percentage_24h']??0) <=> floatval($a['price_change_percentage_24h']??0) );
        $gainers = array_slice( $sorted, 0, 10 );
        $losers  = array_slice( array_reverse( $sorted ), 0, 10 );
        $updated = isset($crypto['updated']) ? human_time_diff($crypto['updated']) . ' ago' : 'N/A';
        $rest    = rest_url('blockticker/v1/gainers');

        ob_start();
        ?>
        <div class="fxlm-gl-wrap" id="fxlm-gl-wrap" data-rest="<?php echo esc_url($rest); ?>">
            <div class="fxlm-gl-header">
                <div>
                    <h1 class="fxlm-gl-title" data-i18n="widget.gainers_losers_title"><?php _ebt('widget.gainers_losers_title'); ?></h1>
                    <p class="fxlm-gl-sub">Coins with the biggest 24h price moves · Updated: <span id="fxlm-gl-updated"><?php echo esc_html($updated); ?></span></p>
                </div>
                <div class="fxlm-gl-filters">
                    <button class="fxlm-gl-filter active" data-filter="top100">Top 100</button>
                    <button class="fxlm-gl-filter" data-filter="top500">Top 500</button>
                    <button class="fxlm-gl-filter" data-filter="all">All</button>
                </div>
            </div>
            <div class="fxlm-gl-grid">
                <!-- Gainers -->
                <div class="fxlm-gl-col">
                    <div class="fxlm-gl-col-header fxlm-gl-col-up">
                        <span>🚀 <span data-i18n="widget.top_gainers"><?php _ebt('widget.top_gainers'); ?></span></span>
                        <span class="fxlm-gl-badge fxlm-gl-badge-up">24h Change</span>
                    </div>
                    <div class="fxlm-gl-col-head-row">
                        <span><?php echo esc_html( __bt( 'col.name' ) ); ?></span><span><?php echo esc_html( __bt( 'col.price' ) ); ?></span><span><?php echo esc_html( __bt( 'col.change_24h' ) ); ?></span>
                    </div>
                    <div id="fxlm-gl-gainers">
                    <?php foreach ( $gainers as $coin ) :
                        $chg = floatval($coin['price_change_percentage_24h']??0);
                    ?>
                    <div class="fxlm-gl-row" data-id="<?php echo esc_attr($coin['id']); ?>">
                        <div class="fxlm-gl-name">
                            <?php if(!empty($coin['image'])): ?><img src="<?php echo esc_url($coin['image']); ?>" width="24" height="24" loading="lazy" alt=""><?php endif; ?>
                            <div>
                                <strong><?php echo esc_html($coin['name']); ?></strong>
                                <span class="fxlm-gl-sym"><?php echo esc_html(strtoupper($coin['symbol'])); ?></span>
                            </div>
                        </div>
                        <span class="fxlm-gl-price">$<?php echo number_format(floatval($coin['current_price']),floatval($coin['current_price'])>=1?2:6); ?></span>
                        <span class="fxlm-gl-chg up">▲ <?php echo number_format($chg,2); ?>%</span>
                    </div>
                    <?php endforeach; ?>
                    </div>
                </div>
                <!-- Losers -->
                <div class="fxlm-gl-col">
                    <div class="fxlm-gl-col-header fxlm-gl-col-down">
                        <span>📉 <span data-i18n="widget.top_losers"><?php _ebt('widget.top_losers'); ?></span></span>
                        <span class="fxlm-gl-badge fxlm-gl-badge-down">24h Change</span>
                    </div>
                    <div class="fxlm-gl-col-head-row">
                        <span><?php echo esc_html( __bt( 'col.name' ) ); ?></span><span><?php echo esc_html( __bt( 'col.price' ) ); ?></span><span><?php echo esc_html( __bt( 'col.change_24h' ) ); ?></span>
                    </div>
                    <div id="fxlm-gl-losers">
                    <?php foreach ( $losers as $coin ) :
                        $chg = floatval($coin['price_change_percentage_24h']??0);
                    ?>
                    <div class="fxlm-gl-row" data-id="<?php echo esc_attr($coin['id']); ?>">
                        <div class="fxlm-gl-name">
                            <?php if(!empty($coin['image'])): ?><img src="<?php echo esc_url($coin['image']); ?>" width="24" height="24" loading="lazy" alt=""><?php endif; ?>
                            <div>
                                <strong><?php echo esc_html($coin['name']); ?></strong>
                                <span class="fxlm-gl-sym"><?php echo esc_html(strtoupper($coin['symbol'])); ?></span>
                            </div>
                        </div>
                        <span class="fxlm-gl-price">$<?php echo number_format(floatval($coin['current_price']),floatval($coin['current_price'])>=1?2:6); ?></span>
                        <span class="fxlm-gl-chg down">▼ <?php echo number_format(abs($chg),2); ?>%</span>
                    </div>
                    <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        <script>
        (function(){
            var REST_BASE = document.getElementById('fxlm-gl-wrap').dataset.rest;
            var currentLimit = '100';

            function renderRows(coins, containerId, isUp){
                var container = document.getElementById(containerId);
                if(!container) return;
                if(!coins||!coins.length){container.innerHTML='<div style="padding:20px;text-align:center;color:var(--bt-text-3)">'+(fxlm_data.i18n&&fxlm_data.i18n.no_data||'No data')+'</div>';return;}
                container.innerHTML = coins.map(function(c){
                    var chg = parseFloat(c.price_change_percentage_24h||0);
                    var price = parseFloat(c.current_price||0);
                    var priceStr = '$'+price.toLocaleString('en-US',{minimumFractionDigits:price>=1?2:6,maximumFractionDigits:price>=1?2:6});
                    return '<div class="fxlm-gl-row" data-id="'+c.id+'">'+
                        '<div class="fxlm-gl-name">'+
                        (c.image?'<img src="'+c.image+'" width="24" height="24" loading="lazy" alt="">':'')+
                        '<div><strong>'+c.name+'</strong><span class="fxlm-gl-sym">'+c.symbol.toUpperCase()+'</span></div></div>'+
                        '<span class="fxlm-gl-price">'+priceStr+'</span>'+
                        '<span class="fxlm-gl-chg '+(isUp?'up':'down')+'">'+(isUp?'▲ '+chg.toFixed(2):'▼ '+Math.abs(chg).toFixed(2))+'%</span></div>';
                }).join('');
            }

            function loadGL(limit){
                var url = REST_BASE + (REST_BASE.includes('?')?'&':'?') + 'limit=' + limit;
                fetch(url).then(r=>r.json()).then(function(d){
                    if(!d.gainers||!d.losers) return;
                    renderRows(d.gainers, 'fxlm-gl-gainers', true);
                    renderRows(d.losers,  'fxlm-gl-losers',  false);
                    var el = document.getElementById('fxlm-gl-updated');
                    if(el) el.textContent = 'just now (Top '+limit+')';
                }).catch(function(e){ console.error('GL fetch error', e); });
            }

            // Filter buttons
            document.querySelectorAll('.fxlm-gl-filter').forEach(function(btn){
                btn.addEventListener('click', function(){
                    document.querySelectorAll('.fxlm-gl-filter').forEach(function(b){ b.classList.remove('active'); });
                    btn.classList.add('active');
                    var f = btn.dataset.filter;
                    currentLimit = f === 'top500' ? '500' : f === 'all' ? 'all' : '100';
                    // Show loading
                    var loadingTxt = (fxlm_data.i18n&&fxlm_data.i18n.loading)||'Loading…';
                    document.getElementById('fxlm-gl-gainers').innerHTML = '<div style="padding:20px;text-align:center;color:var(--bt-text-3)">'+loadingTxt+'</div>';
                    document.getElementById('fxlm-gl-losers').innerHTML  = '<div style="padding:20px;text-align:center;color:var(--bt-text-3)">'+loadingTxt+'</div>';
                    loadGL(currentLimit);
                });
            });

            // Auto-refresh every 60s with current filter
            setInterval(function(){ loadGL(currentLimit); }, 60000);
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    // ── EXCHANGES ────────────────────────────────────────────────────────────

    // ── MARKET PULSE ─ LIVE market stats widget (Analysis page hero) ──
    public static function sc_market_pulse( $atts ) {
        $crypto = self::get_json_option( 'fxlm_crypto_data' );
        $coins  = $crypto['coins'] ?? array();
        $fng    = get_option( 'bt_fear_greed', array() );
        $fng_val   = is_array($fng) ? ($fng['value'] ?? 50) : 50;
        $fng_label = is_array($fng) ? ($fng['classification'] ?? 'Neutral') : 'Neutral';

        // Extract key metrics
        $btc = null; $eth = null; $sol = null;
        $total_mcap = 0; $total_vol = 0;
        foreach ( $coins as $c ) {
            if ( $c['id'] === 'bitcoin' )  $btc = $c;
            if ( $c['id'] === 'ethereum' ) $eth = $c;
            if ( $c['id'] === 'solana' )   $sol = $c;
            $total_mcap += floatval( $c['market_cap'] ?? 0 );
            $total_vol  += floatval( $c['total_volume'] ?? 0 );
        }
        $btc_dom = ( $btc && $total_mcap > 0 ) ? ( $btc['market_cap'] / $total_mcap * 100 ) : 0;

        $fmt = function( $n ) {
            if ( $n >= 1e12 ) return '$' . number_format( $n / 1e12, 2 ) . 'T';
            if ( $n >= 1e9 )  return '$' . number_format( $n / 1e9,  2 ) . 'B';
            if ( $n >= 1e6 )  return '$' . number_format( $n / 1e6,  2 ) . 'M';
            return '$' . number_format( $n );
        };

        $fng_color = $fng_val <= 25 ? '#ef4444' : ( $fng_val <= 45 ? 'var(--bt-accent-warm)' : ( $fng_val <= 55 ? '#eab308' : ( $fng_val <= 75 ? '#84cc16' : '#10b981' ) ) );

        ob_start();
        ?>
        <div class="bt-pulse-wrap">
            <div class="bt-pulse-header">
                <div class="bt-pulse-title"><span class="bt-pulse-live-dot"></span><strong data-i18n="widget.live_market_pulse"><?php _ebt('widget.live_market_pulse'); ?></strong></div>
                <div class="bt-pulse-time"><span data-i18n="widget.pulse_updated"><?php _ebt('widget.pulse_updated'); ?></span> <?php echo esc_html( date( 'H:i', current_time( 'timestamp' ) ) ); ?> UTC · <span data-i18n="widget.pulse_refresh"><?php _ebt('widget.pulse_refresh'); ?></span></div>
            </div>
            <div class="bt-pulse-grid">
                <!-- Total Market Cap -->
                <div class="bt-pulse-stat">
                    <div class="bt-pulse-label" data-i18n="widget.total_market_cap"><?php _ebt('widget.total_market_cap'); ?></div>
                    <div class="bt-pulse-value"><?php echo esc_html( $fmt( $total_mcap ) ); ?></div>
                    <div class="bt-pulse-sub"><span data-i18n="widget.vol_24h"><?php _ebt('widget.vol_24h'); ?></span>: <?php echo esc_html( $fmt( $total_vol ) ); ?></div>
                </div>
                <!-- BTC Dominance -->
                <div class="bt-pulse-stat">
                    <div class="bt-pulse-label" data-i18n="widget.btc_dominance"><?php _ebt('widget.btc_dominance'); ?></div>
                    <div class="bt-pulse-value"><?php echo esc_html( number_format( $btc_dom, 1 ) ); ?>%</div>
                    <div class="bt-pulse-bar"><div class="bt-pulse-bar-fill" style="width:<?php echo esc_attr($btc_dom); ?>%;background:linear-gradient(90deg,#f7931a,#fbbf24)"></div></div>
                </div>
                <!-- Fear & Greed -->
                <div class="bt-pulse-stat">
                    <div class="bt-pulse-label" data-i18n="widget.fng_index"><?php _ebt('widget.fng_index'); ?></div>
                    <div class="bt-pulse-value" style="color:<?php echo esc_attr($fng_color); ?>"><?php echo esc_html( $fng_val ); ?></div>
                    <div class="bt-pulse-sub" style="color:<?php echo esc_attr($fng_color); ?>"><?php echo esc_html( $fng_label ); ?></div>
                </div>
                <!-- BTC Price -->
                <?php if ( $btc ): $chg = floatval( $btc['price_change_percentage_24h'] ?? 0 ); $clr = $chg >= 0 ? 'var(--bt-accent)' : 'var(--bt-danger)'; ?>
                <div class="bt-pulse-stat">
                    <div class="bt-pulse-label"><img src="<?php echo esc_url($btc['image']); ?>" width="14" height="14" style="vertical-align:middle;border-radius:50%;margin-right:4px" alt="">BTC</div>
                    <div class="bt-pulse-value"><?php echo esc_html( $fmt( $btc['current_price'] ) ); ?></div>
                    <div class="bt-pulse-sub" style="color:<?php echo esc_attr($clr); ?>"><?php echo ( $chg >= 0 ? '▲' : '▼' ) . ' ' . number_format( abs($chg), 2 ); ?>% (24h)</div>
                </div>
                <?php endif; ?>
                <!-- ETH Price -->
                <?php if ( $eth ): $chg = floatval( $eth['price_change_percentage_24h'] ?? 0 ); $clr = $chg >= 0 ? 'var(--bt-accent)' : 'var(--bt-danger)'; ?>
                <div class="bt-pulse-stat">
                    <div class="bt-pulse-label"><img src="<?php echo esc_url($eth['image']); ?>" width="14" height="14" style="vertical-align:middle;border-radius:50%;margin-right:4px" alt="">ETH</div>
                    <div class="bt-pulse-value"><?php echo esc_html( $fmt( $eth['current_price'] ) ); ?></div>
                    <div class="bt-pulse-sub" style="color:<?php echo esc_attr($clr); ?>"><?php echo ( $chg >= 0 ? '▲' : '▼' ) . ' ' . number_format( abs($chg), 2 ); ?>% (24h)</div>
                </div>
                <?php endif; ?>
                <!-- SOL Price -->
                <?php if ( $sol ): $chg = floatval( $sol['price_change_percentage_24h'] ?? 0 ); $clr = $chg >= 0 ? 'var(--bt-accent)' : 'var(--bt-danger)'; ?>
                <div class="bt-pulse-stat">
                    <div class="bt-pulse-label"><img src="<?php echo esc_url($sol['image']); ?>" width="14" height="14" style="vertical-align:middle;border-radius:50%;margin-right:4px" alt="">SOL</div>
                    <div class="bt-pulse-value"><?php echo esc_html( $fmt( $sol['current_price'] ) ); ?></div>
                    <div class="bt-pulse-sub" style="color:<?php echo esc_attr($clr); ?>"><?php echo ( $chg >= 0 ? '▲' : '▼' ) . ' ' . number_format( abs($chg), 2 ); ?>% (24h)</div>
                </div>
                <?php endif; ?>
            </div>
            <div class="bt-pulse-footer">
                <span class="bt-pulse-footnote">📊 AI analysis reports on this page use these live numbers as ground truth for market commentary.</span>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    // ── FOREX POSITIONING — v83 honest replacement ──────────────────────
    // Replaced the v-syntheticforex sentiment widget with a real data source:
    // CFTC Commitments of Traders (COT) data — free, public, weekly.
    // If the fetch fails we display an honest "data unavailable" state instead
    // of fabricating numbers. No more mt_rand sentiment.
    public static function sc_forex_sentiment( $atts ) {
        $cot      = self::get_cot_positioning();
        $has_data = ! empty( $cot['rows'] );

        // Signal → explanation + contrarian interpretation
        $signal_explain = array(
            'Bullish'  => array( 'icon' => '▲', 'col' => 'var(--bt-accent)', 'text' => 'Large speculators hold a majority long position. Contrarian reading: if longs exceed 75%, the trade is crowded and a reversal risk is elevated.' ),
            'Bearish'  => array( 'icon' => '▼', 'col' => 'var(--bt-danger)', 'text' => 'Large speculators hold a majority short position. Contrarian reading: extreme shorts (>75%) can signal exhaustion and a potential squeeze higher.' ),
            'Mixed'    => array( 'icon' => '◆', 'col' => 'var(--bt-accent-warm)', 'text' => 'Positioning is balanced between longs and shorts. No strong directional lean from the institutional futures market.' ),
            'Neutral'  => array( 'icon' => '◆', 'col' => 'var(--bt-text-3)', 'text' => 'Positioning is close to 50/50. Watch for a break in either direction to confirm a new trend.' ),
        );

        ob_start();
        ?>
<div class="bt-cot-wrap">

  <!-- ── HEADER ── -->
  <div class="bt-cot-header">
    <div class="bt-cot-header-left">
      <div class="bt-cot-eyebrow">INSTITUTIONAL POSITIONING · CFTC COT REPORT</div>
      <h2 class="bt-cot-title">Forex Client Sentiment</h2>
      <p class="bt-cot-desc">Real non-commercial (speculator) positioning from the <strong>CFTC Commitments of Traders</strong> report — the weekly gold standard for institutional FX futures data. Published every Friday at 15:30 ET covering the preceding Tuesday.</p>
    </div>
    <?php if ( $has_data ): ?>
    <div class="bt-cot-badge">
      <div class="bt-cot-badge-label">Data as of</div>
      <div class="bt-cot-badge-date"><?php echo esc_html( $cot['as_of_fmt'] ); ?></div>
      <div class="bt-cot-badge-src">Source: CFTC.gov</div>
    </div>
    <?php endif; ?>
  </div>

  <!-- ── HOW TO READ ── -->
  <div class="bt-cot-howto">
    <div class="bt-cot-howto-head">📖 How to read this data</div>
    <div class="bt-cot-howto-grid">
      <div class="bt-cot-howto-item">
        <span class="bt-cot-howto-icon" style="color:var(--bt-accent)">▲</span>
        <div>
          <strong>Bullish signal</strong>
          <p>More longs than shorts among large speculators. When longs exceed 75%, the trade is crowded — smart money often fades extremes.</p>
        </div>
      </div>
      <div class="bt-cot-howto-item">
        <span class="bt-cot-howto-icon" style="color:var(--bt-danger)">▼</span>
        <div>
          <strong>Bearish signal</strong>
          <p>More shorts than longs. Extreme short positioning (&gt;75%) can signal exhaustion — short squeezes become more likely.</p>
        </div>
      </div>
      <div class="bt-cot-howto-item">
        <span class="bt-cot-howto-icon" style="color:var(--bt-accent-warm)">◆</span>
        <div>
          <strong>Mixed / Neutral</strong>
          <p>Near-equal positioning with no strong directional lean. Wait for a catalyst to tip the balance before taking a directional view.</p>
        </div>
      </div>
      <div class="bt-cot-howto-item">
        <span class="bt-cot-howto-icon" style="color:#a78bfa">⚠</span>
        <div>
          <strong>Contrarian tool</strong>
          <p>COT data is best used as a contrarian indicator at extremes, not as a trend-following signal. Always combine with technicals and macro context.</p>
        </div>
      </div>
    </div>
  </div>

  <?php if ( ! $has_data ): ?>
  <div class="bt-cot-unavail">
    <div style="font-size:28px;margin-bottom:10px">📡</div>
    <strong>Live positioning data unavailable</strong>
    <p>We source this directly from the CFTC public COT feed. It refreshes automatically — check back in 30–60 minutes. We never show estimated or synthetic data as a substitute.</p>
    <?php if ( ! empty( $cot['error'] ) ): ?>
    <code><?php echo esc_html( $cot['error'] ); ?></code>
    <?php endif; ?>
  </div>
  <?php else: ?>

  <!-- ── CARDS GRID ── -->
  <div class="bt-cot-cards">
    <?php foreach ( $cot['rows'] as $p ):
      $sig     = $p['signal'] ?? 'Neutral';
      $ex      = $signal_explain[ $sig ] ?? $signal_explain['Neutral'];
      $long    = intval( $p['long_pct'] );
      $short   = intval( $p['short_pct'] );
      $extreme = $long >= 75 || $short >= 75;
      $dominant_side = $long >= $short ? 'long' : 'short';
      $dominant_pct  = max( $long, $short );
    ?>
    <div class="bt-cot-card<?php echo $extreme ? ' bt-cot-card--extreme' : ''; ?>" style="--cc:<?php echo esc_attr($ex['col']); ?>">

      <!-- Pair + signal -->
      <div class="bt-cot-card-top">
        <div>
          <a href="<?php echo esc_url( home_url( '/forex/' . esc_attr( $p['slug'] ) . '/' ) ); ?>" class="bt-cot-pair"><?php echo esc_html( $p['sym'] ); ?></a>
          <?php if ( $extreme ): ?><span class="bt-cot-extreme-tag">⚠ EXTREME</span><?php endif; ?>
        </div>
        <span class="bt-cot-signal" style="color:<?php echo esc_attr($ex['col']); ?>"><?php echo esc_html($ex['icon'] . ' ' . $sig); ?></span>
      </div>

      <!-- Bar -->
      <div class="bt-cot-bar-wrap">
        <div class="bt-cot-bar">
          <div class="bt-cot-bar-long" style="width:<?php echo esc_attr($long); ?>%"></div>
          <div class="bt-cot-bar-short" style="width:<?php echo esc_attr($short); ?>%"></div>
        </div>
        <div class="bt-cot-bar-labels">
          <span class="bt-cot-long-pct"><?php echo $long; ?>% Long</span>
          <span class="bt-cot-short-pct"><?php echo $short; ?>% Short</span>
        </div>
      </div>

      <!-- Dominant stat -->
      <div class="bt-cot-dominant">
        <div class="bt-cot-dominant-num" style="color:<?php echo esc_attr($ex['col']); ?>"><?php echo $dominant_pct; ?>%</div>
        <div class="bt-cot-dominant-lbl"><?php echo $dominant_side === 'long' ? 'net long' : 'net short'; ?> · large speculators</div>
      </div>

      <!-- Interpretation -->
      <div class="bt-cot-interp"><?php echo esc_html( $ex['text'] ); ?></div>

    </div>
    <?php endforeach; ?>
  </div>

  <!-- ── METHODOLOGY STRIP ── -->
  <div class="bt-cot-method">
    <div class="bt-cot-method-head">📊 Methodology</div>
    <div class="bt-cot-method-body">
      <p><strong>What is "non-commercial" positioning?</strong> The CFTC splits traders into commercials (hedgers — airlines hedging fuel, exporters hedging receivables) and non-commercials (large speculators — hedge funds, CTAs, and proprietary traders). Non-commercial positioning is the best proxy for "smart money" directional bets in the futures market.</p>
      <p><strong>Why is this contrarian?</strong> When speculator positioning reaches extreme levels (&gt;75% net long or net short), the trade is crowded. There are few participants left to push price further in that direction, and a catalyst can trigger rapid unwinding. Historically, extreme COT readings have preceded significant reversals in EUR/USD, GBP/USD, and USD/JPY.</p>
      <p><strong>Limitations:</strong> COT data is published weekly with a 3-day lag. It captures futures positioning only, not spot or OTC markets. Use alongside technicals, fundamental analysis, and the live desk signals above for best results.</p>
    </div>
  </div>
  <?php endif; ?>

</div>

<style>
.bt-cot-wrap{color:var(--bt-text);font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif}
.bt-cot-header{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;margin-bottom:20px}
.bt-cot-eyebrow{font-size:10px;text-transform:uppercase;letter-spacing:.08em;color:var(--bt-text-3);margin-bottom:6px;font-weight:700}
.bt-cot-title{font-size:24px;font-weight:800;color:var(--bt-text);margin:0 0 8px}
.bt-cot-desc{font-size:13px;color:var(--bt-text-2);line-height:1.6;margin:0;max-width:580px}
.bt-cot-desc strong{color:#cbd5e1}
.bt-cot-badge{background:var(--bt-bg-elev);border:1px solid #1e2535;border-radius:0;padding:12px 16px;text-align:right;min-width:160px;flex-shrink:0}
.bt-cot-badge-label{font-size:10px;color:var(--bt-text-3);text-transform:uppercase;letter-spacing:.06em}
.bt-cot-badge-date{font-size:15px;font-weight:700;color:var(--bt-text);margin:4px 0}
.bt-cot-badge-src{font-size:11px;color:var(--bt-text-3)}
.bt-cot-howto{background:#0d1117;border:1px solid #1e2535;border-radius:0;padding:18px 20px;margin-bottom:20px}
.bt-cot-howto-head{font-size:12px;font-weight:700;color:var(--bt-text-2);text-transform:uppercase;letter-spacing:.05em;margin-bottom:14px}
.bt-cot-howto-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px}
.bt-cot-howto-item{display:flex;gap:10px;align-items:flex-start}
.bt-cot-howto-icon{font-size:18px;flex-shrink:0;line-height:1.2}
.bt-cot-howto-item strong{display:block;font-size:13px;color:var(--bt-text);margin-bottom:3px}
.bt-cot-howto-item p{font-size:12px;color:var(--bt-text-3);margin:0;line-height:1.5}
.bt-cot-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px;margin-bottom:20px}
.bt-cot-card{background:var(--bt-bg-elev);border:1px solid #1e2535;border-top:3px solid var(--cc,var(--bt-accent));border-radius:0;padding:16px 18px;transition:transform .2s,border-color .2s}
.bt-cot-card:hover{transform:translateY(-2px)}
.bt-cot-card--extreme{border-color:var(--bt-accent-warm);background:rgba(245,158,11,.04)}
.bt-cot-card-top{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:14px}
.bt-cot-pair{font-size:20px;font-weight:800;color:var(--bt-text);font-family:monospace;text-decoration:none}
.bt-cot-pair:hover{color:var(--cc,var(--bt-accent))}
.bt-cot-extreme-tag{display:block;font-size:10px;font-weight:700;color:var(--bt-accent-warm);text-transform:uppercase;letter-spacing:.05em;margin-top:3px}
.bt-cot-signal{font-size:13px;font-weight:700}
.bt-cot-bar-wrap{margin-bottom:12px}
.bt-cot-bar{display:flex;height:10px;border-radius:5px;overflow:hidden;background:#1e2535;margin-bottom:6px}
.bt-cot-bar-long{background:var(--bt-accent);transition:width .4s}
.bt-cot-bar-short{background:var(--bt-danger);transition:width .4s}
.bt-cot-bar-labels{display:flex;justify-content:space-between;font-size:11px}
.bt-cot-long-pct{color:var(--bt-accent);font-weight:600}
.bt-cot-short-pct{color:var(--bt-danger);font-weight:600}
.bt-cot-dominant{display:flex;align-items:baseline;gap:6px;margin-bottom:10px}
.bt-cot-dominant-num{font-size:28px;font-weight:800;line-height:1}
.bt-cot-dominant-lbl{font-size:12px;color:var(--bt-text-3)}
.bt-cot-interp{font-size:12px;color:var(--bt-text-2);line-height:1.55;border-top:1px solid #1e2535;padding-top:10px}
.bt-cot-unavail{text-align:center;padding:48px 24px;background:var(--bt-bg-elev);border-radius:0;color:var(--bt-text-3)}
.bt-cot-unavail strong{display:block;color:var(--bt-text);font-size:16px;margin-bottom:8px}
.bt-cot-unavail p{font-size:13px;line-height:1.6;margin:0 auto 12px;max-width:480px}
.bt-cot-unavail code{font-size:11px;color:#ef4444;background:#0d1117;padding:4px 8px;border-radius:0}
.bt-cot-method{background:#0d1117;border:1px solid #1e2535;border-radius:0;padding:18px 20px}
.bt-cot-method-head{font-size:12px;font-weight:700;color:var(--bt-text-2);text-transform:uppercase;letter-spacing:.05em;margin-bottom:12px}
.bt-cot-method-body p{font-size:12px;color:var(--bt-text-3);line-height:1.7;margin:0 0 10px}
.bt-cot-method-body p:last-child{margin:0}
.bt-cot-method-body strong{color:var(--bt-text-2)}
</style>
        <?php
        return ob_get_clean();
    }

    /**
     * v83 — Fetch CFTC Commitments of Traders data for major FX futures.
     * Cached 12 hours. Returns normalized rows ready for display, or an
     * empty rows array with error string if the fetch fails.
     *
     * @return array { rows: [...], as_of_fmt: string, error: string }
     */
    private static function get_cot_positioning() {
        $cache_key = 'bt_cot_positioning_v1';
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) ) return $cached;

        // CFTC market names we care about (Financial Futures — Disaggregated report)
        // keyed by the display symbol we want to show
        $markets = array(
            'EUR/USD' => array( 'slug' => 'eur-usd', 'query' => 'EURO FX'       ),
            'GBP/USD' => array( 'slug' => 'gbp-usd', 'query' => 'BRITISH POUND' ),
            'USD/JPY' => array( 'slug' => 'usd-jpy', 'query' => 'JAPANESE YEN'  ),
            'USD/CHF' => array( 'slug' => 'usd-chf', 'query' => 'SWISS FRANC'   ),
            'AUD/USD' => array( 'slug' => 'aud-usd', 'query' => 'AUSTRALIAN DOLLAR' ),
            'USD/CAD' => array( 'slug' => 'usd-cad', 'query' => 'CANADIAN DOLLAR' ),
            'NZD/USD' => array( 'slug' => 'nzd-usd', 'query' => 'NEW ZEALAND DOLLAR' ),
        );

        // CFTC's SODA endpoint — Traders in Financial Futures (Futures-Only) dataset.
        // Legacy Commitments of Traders: https://publicreporting.cftc.gov/resource/6dca-aqww.json
        $endpoint = 'https://publicreporting.cftc.gov/resource/6dca-aqww.json';
        $result   = array( 'rows' => array(), 'as_of_fmt' => '', 'error' => '' );

        $latest_report_date = null;
        foreach ( $markets as $sym => $meta ) {
            // URL-encode the market query (must match CFTC naming exactly, they use upper-case)
            $query  = rawurlencode( $meta['query'] );
            $url    = $endpoint
                . '?$select=market_and_exchange_names,report_date_as_yyyy_mm_dd,'
                . 'noncomm_positions_long_all,noncomm_positions_short_all'
                . '&$where=starts_with(upper(market_and_exchange_names),%27' . $query . '%27)'
                . '&$order=report_date_as_yyyy_mm_dd%20DESC'
                . '&$limit=1';

            $resp = wp_remote_get( $url, array( 'timeout' => 12, 'user-agent' => 'BlockTicker/83' ) );
            if ( is_wp_error( $resp ) ) {
                $result['error'] = $resp->get_error_message();
                continue;
            }
            $body = wp_remote_retrieve_body( $resp );
            $data = json_decode( $body, true );
            if ( ! is_array( $data ) || empty( $data[0] ) ) continue;

            $row        = $data[0];
            $longs      = intval( $row['noncomm_positions_long_all']  ?? 0 );
            $shorts     = intval( $row['noncomm_positions_short_all'] ?? 0 );
            $total      = $longs + $shorts;
            if ( $total <= 0 ) continue;

            $long_pct   = round( $longs  / $total * 100 );
            $short_pct  = 100 - $long_pct;

            // If the base currency is USD (e.g. USD/JPY), the CFTC quotes the
            // FOREIGN currency's future, so longs-on-yen = shorts-on-USD/JPY.
            // Invert for USD-base pairs so the "long/short" shown matches the pair.
            $is_usd_base = ( strpos( $sym, 'USD/' ) === 0 );
            if ( $is_usd_base ) {
                $tmp       = $long_pct;
                $long_pct  = $short_pct;
                $short_pct = $tmp;
            }

            $signal       = $long_pct < 40 ? 'Bearish' : ( $long_pct > 60 ? 'Bullish' : 'Mixed' );
            $signal_color = $long_pct < 40 ? 'var(--bt-danger)' : ( $long_pct > 60 ? 'var(--bt-accent)' : 'var(--bt-text-2)' );

            $result['rows'][] = array(
                'sym'          => $sym,
                'slug'         => $meta['slug'],
                'long_pct'     => $long_pct,
                'short_pct'    => $short_pct,
                'signal'       => $signal,
                'signal_color' => $signal_color,
            );

            if ( ! empty( $row['report_date_as_yyyy_mm_dd'] ) ) {
                $d = substr( $row['report_date_as_yyyy_mm_dd'], 0, 10 );
                if ( ! $latest_report_date || $d > $latest_report_date ) $latest_report_date = $d;
            }
        }

        if ( $latest_report_date ) {
            $result['as_of_fmt'] = date( 'M j, Y', strtotime( $latest_report_date ) );
        }

        // Cache 12h on success (CFTC updates weekly so this is plenty).
        // Cache 1h on failure so we don't hammer them.
        $ttl = ! empty( $result['rows'] ) ? 12 * HOUR_IN_SECONDS : HOUR_IN_SECONDS;
        set_transient( $cache_key, $result, $ttl );

        return $result;
    }

    // ── WATCHLIST ────────────────────────────────────────────────────────────
    public static function sc_watchlist( $atts ) {
        $rest = esc_url(rest_url('blockticker/v1/watchlist'));
        $prices_rest = esc_url(rest_url('blockticker/v1/gainers'));
        ob_start();
        ?>
        <div class="fxlm-wl-wrap" id="fxlm-watchlist">
            <div class="fxlm-wl-header">
                <h2 class="fxlm-wl-title">⭐ My Watchlist</h2>
                <span class="fxlm-wl-hint">Search and star any coin to track it. Saved in your browser.</span>
            </div>
            <div class="fxlm-wl-search-wrap">
                <input type="text" id="fxlm-wl-search" placeholder="🔍 Search coins (Bitcoin, ETH, SOL…)" class="fxlm-wl-search" autocomplete="off">
                <div id="fxlm-wl-suggestions" class="fxlm-wl-suggestions"></div>
            </div>
            <div id="fxlm-wl-empty" class="fxlm-wl-empty" style="display:none">
                <p>⭐ Your watchlist is empty.<br>Search for a coin above and click the star to add it.</p>
            </div>
            <table class="fxlm-wl-table" id="fxlm-wl-table" style="display:none">
                <thead><tr>
                    <th></th><th><?php echo esc_html( __bt( 'col.coin' ) ); ?></th><th><?php echo esc_html( __bt( 'col.price' ) ); ?></th><th><?php echo esc_html( __bt( 'col.change_24h' ) ); ?></th><th><?php echo esc_html( __bt( 'col.market_cap' ) ); ?></th><th><?php echo esc_html( __bt( 'col.volume_24h' ) ); ?></th>
                </tr></thead>
                <tbody id="fxlm-wl-body"></tbody>
            </table>
        </div>
        <script>
        (function(){
            var REST_GL = '<?php echo $prices_rest; ?>';
            var allCoins = [];
            var watchlist = JSON.parse(localStorage.getItem('bt_watchlist') || '[]');

            // Load all coin data once
            fetch(REST_GL).then(r=>r.json()).then(function(d){
                var all = (d.gainers||[]).concat(d.losers||[]);
                // We need full list — re-fetch from prices
                return fetch('<?php echo esc_url(rest_url("blockticker/v1/prices")); ?>');
            }).then(r=>r.json()).then(function(d){
                allCoins = d.crypto && d.crypto.coins ? d.crypto.coins : [];
                renderWatchlist();
                setupSearch();
            }).catch(function(){});

            function fmt(n, big){
                if(big) return n>=1e12?'$'+(n/1e12).toFixed(2)+'T':n>=1e9?'$'+(n/1e9).toFixed(2)+'B':n>=1e6?'$'+(n/1e6).toFixed(2)+'M':'$'+n.toLocaleString();
                return n>=1?'$'+n.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}):'$'+n.toFixed(6);
            }

            function renderWatchlist(){
                var body = document.getElementById('fxlm-wl-body');
                var table = document.getElementById('fxlm-wl-table');
                var empty = document.getElementById('fxlm-wl-empty');
                if(!body) return;
                var items = watchlist.map(id=>allCoins.find(c=>c.id===id||c.symbol===id)).filter(Boolean);
                if(items.length===0){
                    table.style.display='none'; empty.style.display='block'; return;
                }
                table.style.display='table'; empty.style.display='none';
                body.innerHTML = items.map(function(c){
                    var chg = parseFloat(c.price_change_percentage_24h||0);
                    var cls = chg>=0?'up':'down';
                    return '<tr class="fxlm-wl-row">'+
                        '<td><button class="fxlm-wl-star starred" onclick="btWlRemove(''+c.id+'')">★</button></td>'+
                        '<td class="fxlm-gl-name">'+(c.image?'<img src="'+c.image+'" width="20" height="20" style="border-radius:50%;margin-right:6px" alt="">':'')+
                        '<div><strong>'+c.name+'</strong><span class="fxlm-gl-sym">'+c.symbol.toUpperCase()+'</span></div></td>'+
                        '<td>'+fmt(parseFloat(c.current_price||0))+'</td>'+
                        '<td class="'+cls+'">'+(chg>=0?'▲ ':'▼ ')+Math.abs(chg).toFixed(2)+'%</td>'+
                        '<td>'+fmt(parseFloat(c.market_cap||0),true)+'</td>'+
                        '<td>'+fmt(parseFloat(c.total_volume||0),true)+'</td>'+
                        '</tr>';
                }).join('');
            }

            window.btWlRemove = function(id){
                watchlist = watchlist.filter(function(w){ return w!==id; });
                localStorage.setItem('bt_watchlist', JSON.stringify(watchlist));
                renderWatchlist();
            };

            window.btWlAdd = function(id){
                if(!watchlist.includes(id)) watchlist.push(id);
                localStorage.setItem('bt_watchlist', JSON.stringify(watchlist));
                renderWatchlist();
                document.getElementById('fxlm-wl-search').value='';
                document.getElementById('fxlm-wl-suggestions').innerHTML='';
            };

            function setupSearch(){
                var inp = document.getElementById('fxlm-wl-search');
                var sug = document.getElementById('fxlm-wl-suggestions');
                if(!inp) return;
                inp.addEventListener('input', function(){
                    var q = inp.value.toLowerCase().trim();
                    if(q.length<2){sug.innerHTML='';return;}
                    var hits = allCoins.filter(function(c){
                        return c.name.toLowerCase().includes(q)||c.symbol.toLowerCase().includes(q);
                    }).slice(0,8);
                    if(!hits.length){sug.innerHTML='<div class="fxlm-wl-sug-none">No results</div>';return;}
                    sug.innerHTML = hits.map(function(c){
                        var inWl = watchlist.includes(c.id);
                        return '<div class="fxlm-wl-sug-item" onclick="btWlAdd(''+c.id+'')">'+
                            (c.image?'<img src="'+c.image+'" width="20" height="20" loading="lazy" alt="">':'')+
                            '<span><strong>'+c.name+'</strong> <small>'+c.symbol.toUpperCase()+'</small></span>'+
                            '<span class="fxlm-wl-sug-star">'+(inWl?'★ Added':'☆ Add')+'</span></div>';
                    }).join('');
                });
                document.addEventListener('click', function(e){ if(!sug.contains(e.target)&&e.target!==inp) sug.innerHTML=''; });
            }

            // Auto-refresh every 60s
            setInterval(function(){
                fetch('<?php echo esc_url(rest_url("blockticker/v1/prices")); ?>').then(r=>r.json()).then(function(d){
                    allCoins = d.crypto && d.crypto.coins ? d.crypto.coins : [];
                    renderWatchlist();
                }).catch(function(){});
            }, 60000);
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    // ── CALCULATOR ───────────────────────────────────────────────────────────
    public static function sc_calculator( $atts ) {
        $a = shortcode_atts( array( 'type' => 'converter' ), $atts );
        $type = sanitize_key( $a['type'] );
        // v119.28.30 — dispatch by type so each /tools/ page renders the
        // correct calculator (was always rendering the converter).
        switch ( $type ) {
            case 'pip':
                return self::render_pip_calculator();
            case 'position':
                return self::render_position_calculator();
            case 'profit':
                return self::render_profit_calculator();
            case 'converter':
            default:
                return self::render_currency_converter();
        }
    }

    /**
     * Currency converter — original calculator implementation.
     * @since v119.28.30 (extracted from sc_calculator)
     */
    private static function render_currency_converter() {
        $crypto = self::get_json_option( 'fxlm_crypto_data' );
        $coins  = array_slice($crypto['coins'] ?? [], 0, 50);
        ob_start();
        ?>
        <div class="fxlm-calc-wrap" id="fxlm-calc">
            <div class="fxlm-calc-row">
                <div class="fxlm-calc-field">
                    <label><?php echo esc_html( __bt( 'ui.amount' ) ); ?></label>
                    <input type="number" id="fxlm-calc-amount" value="1" min="0" step="any" oninput="btCalc()">
                </div>
                <div class="fxlm-calc-field">
                    <label><?php echo esc_html( __bt( 'ui.from' ) ); ?></label>
                    <select id="fxlm-calc-from" onchange="btCalc()">
                        <option value="usd" data-price="1">USD ($)</option>
                        <?php foreach($coins as $c): ?>
                        <option value="<?php echo esc_attr($c['id']); ?>" data-price="<?php echo esc_attr($c['current_price']); ?>"><?php echo esc_html(strtoupper($c['symbol']).' ('.$c['name'].')'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button onclick="btCalcSwap()" class="fxlm-calc-swap">⇄</button>
                <div class="fxlm-calc-field">
                    <label>To</label>
                    <select id="fxlm-calc-to" onchange="btCalc()">
                        <option value="usd" data-price="1">USD ($)</option>
                        <?php foreach($coins as $c): ?>
                        <option value="<?php echo esc_attr($c['id']); ?>" data-price="<?php echo esc_attr($c['current_price']); ?>"><?php echo esc_html(strtoupper($c['symbol']).' ('.$c['name'].')'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div id="fxlm-calc-result" class="fxlm-calc-result">Enter an amount above</div>
        </div>
        <script>
        function btCalc(){
            var amt=parseFloat(document.getElementById('fxlm-calc-amount').value)||0;
            var from=document.getElementById('fxlm-calc-from');
            var to=document.getElementById('fxlm-calc-to');
            var fromP=parseFloat(from.options[from.selectedIndex].dataset.price)||1;
            var toP=parseFloat(to.options[to.selectedIndex].dataset.price)||1;
            var result=(amt*fromP)/toP;
            var fromSym=from.options[from.selectedIndex].text.split(' ')[0];
            var toSym=to.options[to.selectedIndex].text.split(' ')[0];
            document.getElementById('fxlm-calc-result').textContent=amt+' '+fromSym+' = '+result.toLocaleString('en-US',{maximumFractionDigits:8})+' '+toSym;
        }
        function btCalcSwap(){
            var f=document.getElementById('fxlm-calc-from');
            var t=document.getElementById('fxlm-calc-to');
            var tmp=f.value;f.value=t.value;t.value=tmp;btCalc();
        }
        btCalc();
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Pip & margin calculator.
     * Inputs: pair, lot size, exchange rate. Outputs: pip value, margin required.
     * @since v119.28.30
     */
    private static function render_pip_calculator() {
        ob_start();
        ?>
        <div class="bt-calc-wrap" id="bt-calc-pip">
            <div class="bt-calc-grid">
                <div class="bt-calc-field">
                    <label for="bt-pip-pair">Currency pair</label>
                    <select id="bt-pip-pair" onchange="btPipCalc()">
                        <option value="EURUSD" data-rate="1.0834" data-pipsize="0.0001">EUR/USD</option>
                        <option value="GBPUSD" data-rate="1.2548" data-pipsize="0.0001">GBP/USD</option>
                        <option value="USDJPY" data-rate="152.34"  data-pipsize="0.01">USD/JPY</option>
                        <option value="USDCAD" data-rate="1.3712" data-pipsize="0.0001">USD/CAD</option>
                        <option value="AUDUSD" data-rate="0.6582" data-pipsize="0.0001">AUD/USD</option>
                        <option value="USDCHF" data-rate="0.8845" data-pipsize="0.0001">USD/CHF</option>
                    </select>
                </div>
                <div class="bt-calc-field">
                    <label for="bt-pip-lot">Lot size (units)</label>
                    <input type="number" id="bt-pip-lot" value="100000" min="0" step="1000" oninput="btPipCalc()">
                </div>
                <div class="bt-calc-field">
                    <label for="bt-pip-leverage">Leverage</label>
                    <select id="bt-pip-leverage" onchange="btPipCalc()">
                        <option value="1">1:1</option>
                        <option value="10">10:1</option>
                        <option value="30" selected>30:1</option>
                        <option value="50">50:1</option>
                        <option value="100">100:1</option>
                        <option value="200">200:1</option>
                        <option value="500">500:1</option>
                    </select>
                </div>
            </div>
            <div class="bt-calc-results">
                <div class="bt-calc-result-card">
                    <div class="bt-calc-result-label">Pip value</div>
                    <div class="bt-calc-result-val" id="bt-pip-value">$10.00</div>
                    <div class="bt-calc-result-meta">per 1 pip move</div>
                </div>
                <div class="bt-calc-result-card">
                    <div class="bt-calc-result-label">Margin required</div>
                    <div class="bt-calc-result-val" id="bt-pip-margin">$3,611.13</div>
                    <div class="bt-calc-result-meta">at selected leverage</div>
                </div>
                <div class="bt-calc-result-card">
                    <div class="bt-calc-result-label">Notional value</div>
                    <div class="bt-calc-result-val" id="bt-pip-notional">$108,340.00</div>
                    <div class="bt-calc-result-meta">contract size in USD</div>
                </div>
            </div>
        </div>
        <script>
        function btPipCalc(){
            var pair=document.getElementById('bt-pip-pair');
            var rate=parseFloat(pair.options[pair.selectedIndex].dataset.rate);
            var pipsize=parseFloat(pair.options[pair.selectedIndex].dataset.pipsize);
            var lot=parseFloat(document.getElementById('bt-pip-lot').value)||0;
            var lev=parseFloat(document.getElementById('bt-pip-leverage').value)||1;
            var sym=pair.value;
            var pipVal = lot * pipsize;
            // For JPY pairs, divide by rate; for USD-quote pairs, pipVal is direct
            if(sym.endsWith('USD')){ /* USD-quote — pip value direct in USD */ }
            else if(sym.startsWith('USD')){ pipVal = pipVal / rate; }
            else { pipVal = pipVal / rate; }
            var notional = lot * rate;
            if(sym.startsWith('USD')) notional = lot;
            var margin = notional / lev;
            document.getElementById('bt-pip-value').textContent='$'+pipVal.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
            document.getElementById('bt-pip-margin').textContent='$'+margin.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
            document.getElementById('bt-pip-notional').textContent='$'+notional.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
        }
        btPipCalc();
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Position size calculator.
     * Inputs: account equity, risk %, entry, stop. Outputs: position size, R, dollar risk.
     * @since v119.28.30
     */
    private static function render_position_calculator() {
        ob_start();
        ?>
        <div class="bt-calc-wrap" id="bt-calc-pos">
            <div class="bt-calc-grid">
                <div class="bt-calc-field">
                    <label for="bt-pos-equity">Account equity</label>
                    <input type="number" id="bt-pos-equity" value="10000" min="0" step="any" oninput="btPosCalc()">
                </div>
                <div class="bt-calc-field">
                    <label for="bt-pos-risk">Risk per trade (%)</label>
                    <input type="number" id="bt-pos-risk" value="1" min="0" max="100" step="0.1" oninput="btPosCalc()">
                </div>
                <div class="bt-calc-field">
                    <label for="bt-pos-entry">Entry price</label>
                    <input type="number" id="bt-pos-entry" value="50" min="0" step="any" oninput="btPosCalc()">
                </div>
                <div class="bt-calc-field">
                    <label for="bt-pos-stop">Stop-loss price</label>
                    <input type="number" id="bt-pos-stop" value="48" min="0" step="any" oninput="btPosCalc()">
                </div>
            </div>
            <div class="bt-calc-results">
                <div class="bt-calc-result-card">
                    <div class="bt-calc-result-label">Position size</div>
                    <div class="bt-calc-result-val" id="bt-pos-size">50</div>
                    <div class="bt-calc-result-meta">units / shares / contracts</div>
                </div>
                <div class="bt-calc-result-card">
                    <div class="bt-calc-result-label">Dollar risk</div>
                    <div class="bt-calc-result-val" id="bt-pos-risk-val">$100.00</div>
                    <div class="bt-calc-result-meta">capital at risk on stop-out</div>
                </div>
                <div class="bt-calc-result-card">
                    <div class="bt-calc-result-label">Risk per unit</div>
                    <div class="bt-calc-result-val" id="bt-pos-runit">$2.00</div>
                    <div class="bt-calc-result-meta">entry − stop</div>
                </div>
            </div>
            <p class="bt-calc-tip">Tip: most professional traders cap risk at <strong>0.5–2%</strong> per trade. Position size is a function of stop distance — never the other way around.</p>
        </div>
        <script>
        function btPosCalc(){
            var eq=parseFloat(document.getElementById('bt-pos-equity').value)||0;
            var rp=parseFloat(document.getElementById('bt-pos-risk').value)||0;
            var en=parseFloat(document.getElementById('bt-pos-entry').value)||0;
            var st=parseFloat(document.getElementById('bt-pos-stop').value)||0;
            var dollarRisk = eq * (rp/100);
            var perUnit = Math.abs(en - st);
            var size = perUnit > 0 ? Math.floor(dollarRisk / perUnit) : 0;
            document.getElementById('bt-pos-size').textContent=size.toLocaleString('en-US');
            document.getElementById('bt-pos-risk-val').textContent='$'+dollarRisk.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
            document.getElementById('bt-pos-runit').textContent='$'+perUnit.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:4});
        }
        btPosCalc();
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Profit calculator.
     * Inputs: entry, exit, position size. Outputs: P/L $, P/L %, R-multiple.
     * @since v119.28.30
     */
    private static function render_profit_calculator() {
        ob_start();
        ?>
        <div class="bt-calc-wrap" id="bt-calc-prof">
            <div class="bt-calc-grid">
                <div class="bt-calc-field">
                    <label for="bt-prof-side">Direction</label>
                    <select id="bt-prof-side" onchange="btProfCalc()">
                        <option value="long" selected>Long</option>
                        <option value="short">Short</option>
                    </select>
                </div>
                <div class="bt-calc-field">
                    <label for="bt-prof-entry">Entry price</label>
                    <input type="number" id="bt-prof-entry" value="50" min="0" step="any" oninput="btProfCalc()">
                </div>
                <div class="bt-calc-field">
                    <label for="bt-prof-exit">Exit price</label>
                    <input type="number" id="bt-prof-exit" value="56" min="0" step="any" oninput="btProfCalc()">
                </div>
                <div class="bt-calc-field">
                    <label for="bt-prof-size">Position size (units)</label>
                    <input type="number" id="bt-prof-size" value="50" min="0" step="any" oninput="btProfCalc()">
                </div>
                <div class="bt-calc-field">
                    <label for="bt-prof-stop">Stop (for R)</label>
                    <input type="number" id="bt-prof-stop" value="48" min="0" step="any" oninput="btProfCalc()">
                </div>
                <div class="bt-calc-field">
                    <label for="bt-prof-fees">Fees per side ($)</label>
                    <input type="number" id="bt-prof-fees" value="0" min="0" step="any" oninput="btProfCalc()">
                </div>
            </div>
            <div class="bt-calc-results">
                <div class="bt-calc-result-card">
                    <div class="bt-calc-result-label">P/L (gross)</div>
                    <div class="bt-calc-result-val" id="bt-prof-gross">+$300.00</div>
                    <div class="bt-calc-result-meta">before fees</div>
                </div>
                <div class="bt-calc-result-card">
                    <div class="bt-calc-result-label">P/L (net)</div>
                    <div class="bt-calc-result-val" id="bt-prof-net">+$300.00</div>
                    <div class="bt-calc-result-meta">after fees</div>
                </div>
                <div class="bt-calc-result-card">
                    <div class="bt-calc-result-label">R-multiple</div>
                    <div class="bt-calc-result-val" id="bt-prof-r">+3.0R</div>
                    <div class="bt-calc-result-meta">vs stop distance</div>
                </div>
                <div class="bt-calc-result-card">
                    <div class="bt-calc-result-label">% return</div>
                    <div class="bt-calc-result-val" id="bt-prof-pct">+12.00%</div>
                    <div class="bt-calc-result-meta">on capital deployed</div>
                </div>
            </div>
        </div>
        <script>
        function btProfCalc(){
            var side=document.getElementById('bt-prof-side').value;
            var en=parseFloat(document.getElementById('bt-prof-entry').value)||0;
            var ex=parseFloat(document.getElementById('bt-prof-exit').value)||0;
            var sz=parseFloat(document.getElementById('bt-prof-size').value)||0;
            var st=parseFloat(document.getElementById('bt-prof-stop').value)||0;
            var fee=parseFloat(document.getElementById('bt-prof-fees').value)||0;
            var pnlPerUnit = side === 'long' ? (ex - en) : (en - ex);
            var gross = pnlPerUnit * sz;
            var net   = gross - (fee*2);
            var rUnit = Math.abs(en - st);
            var rMult = rUnit > 0 ? (pnlPerUnit / rUnit) : 0;
            var pct   = en > 0 ? (pnlPerUnit / en) * 100 : 0;
            var sign = (n) => (n >= 0 ? '+' : '');
            var color = (n) => n >= 0 ? '#00C853' : '#FF453A';
            document.getElementById('bt-prof-gross').textContent  = sign(gross)+'$'+Math.abs(gross).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
            document.getElementById('bt-prof-gross').style.color  = color(gross);
            document.getElementById('bt-prof-net').textContent    = sign(net)+'$'+Math.abs(net).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
            document.getElementById('bt-prof-net').style.color    = color(net);
            document.getElementById('bt-prof-r').textContent      = sign(rMult)+rMult.toFixed(1)+'R';
            document.getElementById('bt-prof-r').style.color      = color(rMult);
            document.getElementById('bt-prof-pct').textContent    = sign(pct)+pct.toFixed(2)+'%';
            document.getElementById('bt-prof-pct').style.color    = color(pct);
        }
        btProfCalc();
        </script>
        <?php
        return ob_get_clean();
    }

    // ── CRYPTO CATEGORY PAGE ─────────────────────────────────────────────────
    public static function sc_crypto_category( $atts ) {
        $a   = shortcode_atts(['cat'=>'defi','coins'=>'','title'=>'','show_charts'=>'1'], $atts);
        $cat = sanitize_key($a['cat']);

        // Category metadata: description, color, top TradingView symbols
        $cat_meta = [
            'defi' => [
                'label'   => 'DeFi — Decentralized Finance',
                'desc'    => 'DeFi protocols enable permissionless financial services — lending, borrowing, trading and earning yield — without banks or intermediaries. Powered by smart contracts on Ethereum and other blockchains.',
                'color'   => '#a78bfa',
                'emoji'   => '🔗',
                'cg_id'   => 'decentralized-finance-defi',
                'charts'  => ['BINANCE:UNIUSDT','BINANCE:AAVEUSDT','BINANCE:MKRUSDT'],
                'learn'   => 'DeFi protocols allow anyone with an internet connection to access financial services. Key metrics include Total Value Locked (TVL), which measures the total assets deposited in DeFi protocols.',
            ],
            'nft' => [
                'label'   => 'NFT — Non-Fungible Tokens',
                'desc'    => 'NFT tokens power digital ownership, gaming economies, virtual worlds and creator monetization. These assets represent unique digital items on the blockchain.',
                'color'   => 'var(--bt-accent-warm)',
                'emoji'   => '🖼',
                'cg_id'   => 'non-fungible-tokens-nft',
                'charts'  => ['BINANCE:APEUSDT','BINANCE:SANDUSDT','BINANCE:MANAUSDT'],
                'learn'   => 'NFT market volume surged in 2021-2022 and has since evolved toward utility-focused projects in gaming, music and digital identity.',
            ],
            'stablecoins' => [
                'label'   => 'Stablecoins',
                'desc'    => 'Stablecoins are cryptocurrencies pegged to stable assets like the US Dollar. They enable fast, low-cost global transfers and serve as the primary medium of exchange in DeFi.',
                'color'   => 'var(--bt-accent)',
                'emoji'   => '🔒',
                'cg_id'   => 'stablecoins',
                'charts'  => ['BINANCE:USDTUSDC','BINANCE:DAIUSDT','KRAKEN:USDCUSD'],
                'learn'   => 'The total stablecoin market cap exceeds $150B. USDT leads by volume, USDC by regulatory compliance, and DAI by decentralization.',
            ],
            'metaverse' => [
                'label'   => 'Metaverse & Gaming',
                'desc'    => 'Metaverse tokens power virtual worlds, play-to-earn games and digital real estate. These projects are building the infrastructure for the next generation of the internet.',
                'color'   => 'var(--bt-accent)',
                'emoji'   => '🌐',
                'cg_id'   => 'metaverse',
                'charts'  => ['BINANCE:SANDUSDT','BINANCE:MANAUSDT','BINANCE:AXSUSDT'],
                'learn'   => 'Virtual world tokens grant ownership over digital land, items and governance rights. Key platforms include Decentraland, The Sandbox and Axie Infinity.',
            ],
            'gaming' => [
                'label'   => 'Gaming Tokens',
                'desc'    => 'Blockchain gaming tokens enable true asset ownership, play-to-earn economies and cross-game interoperability.',
                'color'   => '#f7931a',
                'emoji'   => '🎮',
                'cg_id'   => 'gaming',
                'charts'  => ['BINANCE:AXSUSDT','BINANCE:GALAUSDT','BINANCE:IMXUSDT'],
                'learn'   => 'The GameFi sector combines gaming with DeFi, allowing players to earn real value through gameplay.',
            ],
        ];

        $meta      = $cat_meta[$cat] ?? $cat_meta['defi'];
        $cg_cat_id = $meta['cg_id'];
        $color     = $meta['color'];

        // ── Fetch live coins from CoinGecko category endpoint (30 min cache) ──
        $cache_key  = 'fxlm_cat_' . $cat . '_v3';
        $cached     = get_transient( $cache_key );
        $live_coins = is_array( $cached ) ? $cached : array();  // never false
        if ( empty( $live_coins ) ) {
            $api_key = get_option( 'bt_cg_api_key', '' );
            $args    = ['timeout' => 20];
            if ( $api_key ) $args['headers'] = ['x-cg-demo-api-key' => $api_key];
            $url  = "https://api.coingecko.com/api/v3/coins/markets?vs_currency=usd"
                  . "&category={$cg_cat_id}&order=market_cap_desc&per_page=50&page=1"
                  . "&sparkline=true&price_change_percentage=24h,7d";
            $resp = wp_remote_get( $url, $args );
            if ( ! is_wp_error($resp) && wp_remote_retrieve_response_code($resp) === 200 ) {
                $body = json_decode( wp_remote_retrieve_body($resp), true );
                if ( is_array($body) && !empty($body) ) {
                    $live_coins = $body;
                    set_transient( $cache_key, $live_coins, 30 * MINUTE_IN_SECONDS );
                }
            }
        }

        // Fallback to hardcoded coin IDs from stored top-100
        if ( empty($live_coins) ) {
            $ids   = array_filter(array_map('trim', explode(',', $a['coins'])));
            $store = self::get_json_option('fxlm_crypto_data');
            foreach ( ($store['coins'] ?? []) as $c ) {
                if ( in_array($c['id'], $ids) || in_array(strtolower($c['symbol']), $ids) )
                    $live_coins[] = $c;
            }
            if ( count($live_coins) < 3 ) $live_coins = array_slice($store['coins'] ?? [], 0, 20);
        }

        if ( empty($live_coins) ) return '<p class="fxlm-loading">Loading category data…</p>';

        // ── Compute category stats ──
        $total_mcap = 0; $total_vol = 0; $gainers = 0; $losers = 0;
        $top_gainer = null; $top_gainer_pct = -INF;
        foreach ( $live_coins as $c ) {
            $total_mcap += floatval($c['market_cap'] ?? 0);
            $total_vol  += floatval($c['total_volume'] ?? 0);
            $chg = floatval($c['price_change_percentage_24h'] ?? 0);
            if ( $chg >= 0 ) $gainers++; else $losers++;
            if ( $chg > $top_gainer_pct ) { $top_gainer_pct = $chg; $top_gainer = $c; }
        }
        $is_live  = ! empty(get_transient($cache_key));
        $total    = count($live_coins);

        ob_start();
        ?>
        <style>
        .bt-cat-page { max-width:1400px; margin:0 auto; padding:0 20px; }
        /* v58: Hero layout parity with coin/forex pages (.bcp-hero / .bfp-hero pattern) */
        .bt-cat-hero { display:grid; grid-template-columns:1fr 340px; gap:28px; align-items:start; margin:16px 0 28px; }
        @media(max-width:1000px){ .bt-cat-hero { grid-template-columns:1fr; } }
        .bt-cat-hero-main { background:linear-gradient(135deg,<?php echo esc_attr($color); ?>0d,transparent 60%); border:1px solid <?php echo esc_attr($color); ?>22; border-radius:0; padding:24px 28px; }
        .bt-cat-hero-aside { display:flex; flex-direction:column; gap:16px; }
        .bt-cat-stats-inline { display:grid; grid-template-columns:repeat(2,1fr); gap:12px; margin-top:20px; }
        @media(max-width:520px){ .bt-cat-stats-inline { grid-template-columns:1fr; } }
        .bt-cat-stat { background:rgba(255,255,255,.02); border:1px solid rgba(255,255,255,.06); border-radius:0; padding:12px 16px; }
        .bt-cat-stat-lbl { font-size:10.5px!important; color:var(--bt-text-3)!important; text-transform:uppercase; letter-spacing:.6px; margin-bottom:5px; font-weight:600; }
        .bt-cat-stat-val { font-size:17px!important; font-weight:800!important; color:var(--bt-text)!important; font-variant-numeric:tabular-nums; letter-spacing:-0.3px; }
        .bt-cat-stat-sub { font-size:11px!important; margin-top:3px; color:var(--bt-text-3); }
        .bt-cat-chart-card { background:var(--bt-bg-elev); border:1px solid rgba(255,255,255,.07); border-radius:0; overflow:hidden; }
        .bt-cat-chart-lbl { padding:12px 16px 0; font-size:13px!important; font-weight:700!important; color:var(--bt-text-2)!important; display:flex; justify-content:space-between; align-items:center; }
        .bt-cat-chart-lbl .bt-cat-chart-badge { font-family:'SF Mono',Menlo,monospace; font-size:9.5px; font-weight:700; background:rgba(0,255,102,.1); color:var(--bt-accent); padding:3px 8px; border-radius:0; border:1px solid rgba(0,255,102,.2); text-transform:none; letter-spacing:0.3px; }
        .bt-cat-learn { background:<?php echo esc_attr($color); ?>0d; border-left:3px solid <?php echo esc_attr($color); ?>; border-radius:0; padding:16px 20px; margin-bottom:28px; }
        .bt-cat-learn p { color:var(--bt-text-2)!important; font-size:14px!important; line-height:1.7!important; margin:0!important; }
        .bt-cat-table-wrap { overflow-x:auto; border-radius:0; border:1px solid rgba(255,255,255,.06); }
        .bt-cat-table { width:100%; border-collapse:collapse; }
        .bt-cat-table thead th { background:var(--bt-bg); padding:11px 14px; font-size:11px!important; font-weight:700!important; color:var(--bt-text-3)!important; text-transform:uppercase; letter-spacing:.6px; border-bottom:1px solid rgba(255,255,255,.07); white-space:nowrap; }
        .bt-cat-table tbody tr { border-bottom:1px solid rgba(255,255,255,.04); transition:background .15s; }
        .bt-cat-table tbody tr:hover { background:rgba(255,255,255,.025); }
        .bt-cat-table td { padding:13px 14px!important; vertical-align:middle; font-size:13px!important; }
        .bt-cat-coin-link { display:flex; align-items:center; gap:10px; text-decoration:none!important; color:inherit!important; }
        .bt-cat-coin-link:hover strong { color:<?php echo esc_attr($color); ?>!important; }
        .bt-cat-coin-link strong { color:var(--bt-text)!important; font-size:13px!important; transition:color .15s; }
        </style>

        <div class="bt-cat-page">

            <!-- v58: HERO — 2-column layout matching coin/forex pages -->
            <div class="bt-cat-hero">
                <!-- Left: sector identity + stats + featured chart -->
                <div class="bt-cat-hero-main">
                    <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:8px">
                        <span style="font-size:30px;line-height:1"><?php echo $meta['emoji']; ?></span>
                        <?php if ($is_live || !empty($live_coins)): ?>
                        <span style="background:rgba(0,255,102,.1);border:1px solid rgba(0,255,102,.25);color:var(--bt-accent);font-size:10.5px;font-weight:700;padding:3px 10px;border-radius:0;letter-spacing:.4px">● LIVE · CoinGecko</span>
                        <?php endif; ?>
                        <span style="font-size:11px;color:var(--bt-text-3);margin-left:auto"><?php echo $total; ?> assets · Updates every 30 min</span>
                    </div>
                    <h1 style="font-size:clamp(22px,3vw,32px)!important;font-weight:900!important;color:var(--bt-text)!important;margin:0 0 10px!important;letter-spacing:-0.5px!important"><?php echo esc_html($meta['label']); ?></h1>
                    <p style="color:var(--bt-text-2)!important;font-size:14.5px!important;line-height:1.65!important;margin:0 0 6px!important;max-width:620px"><?php echo esc_html($meta['desc']); ?></p>

                    <!-- Inline stats (first 4) -->
                    <div class="bt-cat-stats-inline">
                        <div class="bt-cat-stat">
                            <div class="bt-cat-stat-lbl" data-i18n="widget.total_market_cap"><?php _ebt('widget.total_market_cap'); ?></div>
                            <div class="bt-cat-stat-val">$<?php echo self::format_large($total_mcap); ?></div>
                            <div class="bt-cat-stat-sub"><?php echo $total; ?> assets</div>
                        </div>
                        <div class="bt-cat-stat">
                            <div class="bt-cat-stat-lbl">24h Volume</div>
                            <div class="bt-cat-stat-val">$<?php echo self::format_large($total_vol); ?></div>
                            <div class="bt-cat-stat-sub">Combined sector</div>
                        </div>
                        <div class="bt-cat-stat">
                            <div class="bt-cat-stat-lbl">Gainers / Losers</div>
                            <div class="bt-cat-stat-val"><span style="color:var(--bt-accent)"><?php echo $gainers; ?></span> <span style="color:var(--bt-text-3);font-weight:400">/</span> <span style="color:var(--bt-danger)"><?php echo $losers; ?></span></div>
                            <div class="bt-cat-stat-sub">Last 24 hours</div>
                        </div>
                        <?php if ($top_gainer): ?>
                        <div class="bt-cat-stat">
                            <div class="bt-cat-stat-lbl">Top Performer (24h)</div>
                            <div class="bt-cat-stat-val" style="color:var(--bt-accent)">+<?php echo number_format($top_gainer_pct, 2); ?>%</div>
                            <div class="bt-cat-stat-sub" style="color:<?php echo esc_attr($color); ?>;font-weight:600"><?php echo esc_html(wp_trim_words($top_gainer['name'], 3, '...')); ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Right sidebar: featured + secondary charts stacked (v58) -->
                <?php if ( $a['show_charts'] !== '0' && !empty($meta['charts']) ):
                    $charts = array_slice($meta['charts'], 0, 2); // only 2 fit the sidebar ~340px
                ?>
                <div class="bt-cat-hero-aside">
                    <?php foreach ( $charts as $idx => $tv_sym ): ?>
                    <div class="bt-cat-chart-card">
                        <div class="bt-cat-chart-lbl">
                            <span><?php echo esc_html(str_replace(['BINANCE:','KRAKEN:'], '', $tv_sym)); ?></span>
                            <span class="bt-cat-chart-badge">LIVE</span>
                        </div>
                        <?php echo do_shortcode('[fxlm_tradingview_chart symbol="' . esc_attr($tv_sym) . '" height="' . ( $idx === 0 ? 220 : 180 ) . '" hide_side="1"]'); ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <?php
            // v58: Third/overflow charts shown as a secondary strip below the hero if there are 3+ charts
            if ( $a['show_charts'] !== '0' && !empty($meta['charts']) && count($meta['charts']) > 2 ):
                $overflow = array_slice($meta['charts'], 2);
            ?>
            <div style="display:grid;grid-template-columns:repeat(<?php echo count($overflow); ?>,1fr);gap:16px;margin-bottom:28px">
                <?php foreach ( $overflow as $tv_sym ): ?>
                <div class="bt-cat-chart-card">
                    <div class="bt-cat-chart-lbl">
                        <span><?php echo esc_html(str_replace(['BINANCE:','KRAKEN:'], '', $tv_sym)); ?></span>
                        <span class="bt-cat-chart-badge">LIVE</span>
                    </div>
                    <?php echo do_shortcode('[fxlm_tradingview_chart symbol="' . esc_attr($tv_sym) . '" height="200" hide_side="1"]'); ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Sector Description / Learn -->
            <div class="bt-cat-learn">
                <p><strong style="color:<?php echo esc_attr($color); ?>"><?php echo esc_html(ucfirst($cat)); ?> Sector Overview —</strong> <?php echo esc_html($meta['learn']); ?></p>
            </div>

            <!-- Live Price Table -->
            <div class="fxlm-section-header" style="margin-bottom:12px">
                <h2><?php echo esc_html($meta['emoji']); ?> <?php echo esc_html( sprintf( __bt( 'widget.all_x_tokens' ), ucfirst($cat) ) ); ?></h2>
                <div style="display:flex;gap:8px;align-items:center;margin-left:auto">
                    <input type="text" id="bt-cat-search-<?php echo esc_attr($cat); ?>" placeholder="🔍 Search…" oninput="btCatSearch_<?php echo esc_attr($cat); ?>(this.value)" class="bt-cat-search-input">
                    <?php if ($is_live): ?><span style="background:rgba(0,255,102,.1);color:var(--bt-accent);font-size:10px;font-weight:700;padding:2px 8px;border-radius:0">LIVE</span><?php endif; ?>
                </div>
            </div>
            <div class="bt-cat-table-wrap">
                <table class="bt-cat-table" id="bt-cat-tbl-<?php echo esc_attr($cat); ?>">
                    <thead>
                        <tr>
                            <th style="width:40px" data-sort="rank">#</th>
                            <th data-sort="name"><?php echo esc_html( __bt( 'col.name' ) ); ?></th>
                            <th style="text-align:right" data-sort="price">Price</th>
                            <th style="text-align:right" data-sort="24h">24h %</th>
                            <th style="text-align:right" data-sort="7d">7d %</th>
                            <th style="text-align:right" data-sort="mcap">Market Cap</th>
                            <th style="text-align:right">Volume 24h</th>
                            <th style="text-align:right">Price Graph (7d)</th>
                        </tr>
                    </thead>
                    <tbody id="bt-cat-body-<?php echo esc_attr($cat); ?>">
                    <?php foreach ( $live_coins as $i => $coin ):
                        $chg    = floatval($coin['price_change_percentage_24h'] ?? 0);
                        $chg7   = floatval($coin['price_change_percentage_7d_in_currency'] ?? $coin['price_change_percentage_7d'] ?? 0);
                        $cls    = $chg >= 0 ? 'up' : 'down';
                        $cls7   = $chg7 >= 0 ? 'up' : 'down';
                        $arr    = $chg >= 0 ? '▲' : '▼';
                        $arr7   = $chg7 >= 0 ? '▲' : '▼';
                        $price  = floatval($coin['current_price'] ?? 0);
                        $prStr  = '$' . number_format($price, $price >= 1 ? 2 : 6);
                        $coinUrl = home_url('/crypto/' . sanitize_title($coin['id'] ?? $coin['name']) . '/');
                        // Sparkline
                        $spark = '';
                        if ( !empty($coin['sparkline_in_7d']['price']) ) {
                            $pts  = array_values($coin['sparkline_in_7d']['price']);
                            $step = max(1, intval(count($pts)/40));
                            $sam  = []; for($j=0;$j<count($pts);$j+=$step) $sam[]=$pts[$j];
                            $mn=min($sam); $mx=max($sam); $rng=$mx-$mn?:1;
                            $ps=''; foreach($sam as $si=>$sv){ $ps.=round($si/max(1,count($sam)-1)*100,1).','.round(36-(($sv-$mn)/$rng)*36,1).' '; }
                            $sc = $chg>=0?'var(--bt-accent)':'var(--bt-danger)';
                            $spark='<svg viewBox="0 0 100 36" width="100" height="36" xmlns="http://www.w3.org/2000/svg"><polyline points="'.trim($ps).'" fill="none" stroke="'.$sc.'" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round"/></svg>';
                        }
                    ?>
                    <tr class="bt-cat-row" data-name="<?php echo esc_attr(strtolower($coin['name'])); ?>" data-sym="<?php echo esc_attr(strtolower($coin['symbol'])); ?>">
                        <td style="color:var(--bt-text-3);font-weight:700;text-align:center"><?php echo $i+1; ?></td>
                        <td>
                            <a href="<?php echo esc_url($coinUrl); ?>" class="bt-cat-coin-link">
                                <?php if(!empty($coin['image'])): ?>
                                <img src="<?php echo esc_url($coin['image']); ?>" width="28" height="28" loading="lazy" style="border-radius:50%;flex-shrink:0" alt="<?php echo esc_attr($coin['name']); ?>">
                                <?php endif; ?>
                                <div>
                                    <strong><?php echo esc_html($coin['name']); ?></strong>
                                    <span class="fxlm-gl-sym"><?php echo esc_html(strtoupper($coin['symbol'])); ?></span>
                                </div>
                            </a>
                        </td>
                        <td style="text-align:right;font-weight:700;color:var(--bt-text)"><?php echo $prStr; ?></td>
                        <td style="text-align:right" class="<?php echo $cls; ?>"><?php echo $arr.' '.number_format(abs($chg),2); ?>%</td>
                        <td style="text-align:right" class="<?php echo $cls7; ?>"><?php echo $arr7.' '.number_format(abs($chg7),2); ?>%</td>
                        <td style="text-align:right">$<?php echo self::format_large($coin['market_cap']??0); ?></td>
                        <td style="text-align:right">$<?php echo self::format_large($coin['total_volume']??0); ?></td>
                        <td style="text-align:right"><?php echo $spark ?: '<span style="color:var(--bt-text-4)">—</span>'; ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div id="bt-cat-pager-<?php echo esc_attr($cat); ?>" class="bt-cat-pager"></div>
            <p style="font-size:11px;color:var(--bt-text-4);margin-top:8px">Data: CoinGecko · Category: <?php echo esc_html($cg_cat_id); ?> · Auto-refreshes every 30 minutes</p>

        </div>

        <script>
        (function(){
            var CAT  = '<?php echo esc_js($cat); ?>';
            var PER  = 25; // rows per page
            var page = 1;
            var query= '';
            var sortCol = 'rank';
            var sortDir = 1; // 1=asc, -1=desc
            var allRows = [];

            function getRows(){
                return Array.from(document.querySelectorAll('#bt-cat-body-'+CAT+' .bt-cat-row'));
            }
            function getVal(row, col){
                var cells = row.querySelectorAll('td');
                if(col==='rank')  return parseFloat(cells[0]?.textContent) || 0;
                if(col==='name')  return (row.dataset.name||'');
                if(col==='price') return parseFloat((cells[2]?.textContent||'0').replace(/[$,]/g,'')) || 0;
                if(col==='24h')   return parseFloat((cells[3]?.textContent||'0').replace(/[▲▼%,]/g,'').trim()) * (cells[3]?.classList.contains('up') ? 1 : -1) || 0;
                if(col==='7d')    return parseFloat((cells[4]?.textContent||'0').replace(/[▲▼%,]/g,'').trim()) * (cells[4]?.classList.contains('up') ? 1 : -1) || 0;
                if(col==='mcap')  { var v=(cells[5]?.textContent||'0').replace(/[$,]/g,''); return parseFloat(v.replace(/[BMK]/g,'')) * (/B$/i.test(v)?1e9:/M$/i.test(v)?1e6:/K$/i.test(v)?1e3:1)||0; }
                return 0;
            }
            function render(){
                var body = document.getElementById('bt-cat-body-'+CAT);
                if(!body) return;
                if(!allRows.length) allRows = getRows();
                var q = query.toLowerCase();
                // Filter
                var filtered = allRows.filter(function(r){
                    return !q || r.dataset.name.includes(q) || r.dataset.sym.includes(q);
                });
                // Sort
                if(sortCol !== 'rank'){
                    filtered.sort(function(a,b){
                        var av=getVal(a,sortCol), bv=getVal(b,sortCol);
                        return (sortCol==='name') ? av.localeCompare(bv)*sortDir : (av-bv)*sortDir;
                    });
                }
                // Paginate
                var total = filtered.length;
                var pages = Math.ceil(total/PER) || 1;
                if(page > pages) page = pages;
                var start = (page-1)*PER;
                var visible = filtered.slice(start, start+PER);
                // Hide all, show slice
                allRows.forEach(function(r){ r.style.display='none'; body.appendChild(r); });
                visible.forEach(function(r){ r.style.display=''; });
                // Update pagination
                var pg = document.getElementById('bt-cat-pager-'+CAT);
                if(pg){
                    pg.innerHTML = '';
                    if(pages > 1){
                        var info = document.createElement('span');
                        info.className = 'bt-pager-info';
                        info.textContent = 'Showing '+(start+1)+'–'+Math.min(start+PER,total)+' of '+total;
                        pg.appendChild(info);
                        var nav = document.createElement('div');
                        nav.className = 'bt-pager-nav';
                        // Prev
                        var prev = document.createElement('button');
                        prev.className = 'bt-pager-btn'; prev.textContent = '← Prev';
                        prev.disabled = page <= 1;
                        prev.onclick = function(){ page--; render(); };
                        nav.appendChild(prev);
                        // Page numbers (max 7 visible)
                        var start_p = Math.max(1, page-3), end_p = Math.min(pages, page+3);
                        if(start_p > 1){ var sp=document.createElement('button'); sp.className='bt-pager-btn'; sp.textContent='1'; sp.onclick=function(){page=1;render();}; nav.appendChild(sp); if(start_p>2){var el=document.createElement('span');el.textContent='…';el.className='bt-pager-ellipsis';nav.appendChild(el);} }
                        for(var p=start_p;p<=end_p;p++){
                            var btn=document.createElement('button');
                            btn.className='bt-pager-btn'+(p===page?' active':'');
                            btn.textContent=p;
                            btn.dataset.p=p;
                            (function(pp){ btn.onclick=function(){page=pp;render();}; })(p);
                            nav.appendChild(btn);
                        }
                        if(end_p < pages){ if(end_p<pages-1){var el2=document.createElement('span');el2.textContent='…';el2.className='bt-pager-ellipsis';nav.appendChild(el2);} var lp=document.createElement('button'); lp.className='bt-pager-btn'; lp.textContent=pages; lp.onclick=function(){page=pages;render();}; nav.appendChild(lp); }
                        // Next
                        var nxt = document.createElement('button');
                        nxt.className = 'bt-pager-btn'; nxt.textContent = 'Next →';
                        nxt.disabled = page >= pages;
                        nxt.onclick = function(){ page++; render(); };
                        nav.appendChild(nxt);
                        pg.appendChild(nav);
                    }
                }
                // Update sort header indicators
                document.querySelectorAll('#bt-cat-tbl-'+CAT+' th[data-sort]').forEach(function(th){
                    var active = th.dataset.sort === sortCol;
                    th.classList.toggle('sort-active', active);
                    var ind = th.querySelector('.sort-ind');
                    if(ind) ind.textContent = active ? (sortDir===1 ? ' ▲' : ' ▼') : ' ↕';
                });
            }
            // Expose search
            window.btCatSearch_<?php echo esc_js($cat); ?> = function(q){
                query = q; page = 1; render();
            };
            // Sort click handler
            document.addEventListener('DOMContentLoaded', function(){
                allRows = getRows();
                // Add sort indicators to headers
                var headers = document.querySelectorAll('#bt-cat-tbl-'+CAT+' th[data-sort]');
                headers.forEach(function(th){
                    var ind = document.createElement('span');
                    ind.className = 'sort-ind';
                    ind.textContent = ' ↕';
                    th.appendChild(ind);
                    th.style.cursor = 'pointer';
                    th.addEventListener('click', function(){
                        var col = th.dataset.sort;
                        if(sortCol === col){ sortDir *= -1; }
                        else { sortCol = col; sortDir = col==='name'?1:-1; }
                        page = 1; render();
                    });
                });
                render();
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }


    // ── BOTTOM TICKER BAR ────────────────────────────────────────────────────
    public static function sc_bottom_ticker( $atts ) {
        $crypto = self::get_json_option( 'fxlm_crypto_data' );
        $coins  = array_slice($crypto['coins'] ?? [], 0, 20);
        $rest   = esc_url(rest_url('blockticker/v1/prices'));
        ob_start();
        ?>
        <div class="fxlm-bottom-ticker" id="fxlm-bottom-ticker">
            <div class="fxlm-bottom-ticker-inner" id="fxlm-bottom-ticker-inner">
            <?php foreach($coins as $c):
                $chg = floatval($c['price_change_percentage_24h']??0);
                $cls = $chg>=0?'up':'down';
                $arrow = $chg>=0?'▲':'▼';
            ?>
                <span class="fxlm-btick-item">
                    <?php if(!empty($c['image'])): ?><img src="<?php echo esc_url($c['image']); ?>" width="14" height="14" loading="lazy" alt=""><?php endif; ?>
                    <strong><?php echo esc_html(strtoupper($c['symbol'])); ?></strong>
                    $<?php echo number_format(floatval($c['current_price']),floatval($c['current_price'])>=1?2:4); ?>
                    <span class="<?php echo $cls; ?>"><?php echo $arrow.' '.number_format(abs($chg),2); ?>%</span>
                </span>
            <?php endforeach; ?>
            </div>
        </div>
        <script>
        (function(){
            function refreshBottomTicker(){
                fetch('<?php echo $rest; ?>').then(r=>r.json()).then(function(d){
                    var coins=(d.crypto&&d.crypto.coins?d.crypto.coins:[]).slice(0,20);
                    var el=document.getElementById('fxlm-bottom-ticker-inner');
                    if(!el||!coins.length) return;
                    el.innerHTML=coins.map(function(c){
                        var chg=parseFloat(c.price_change_percentage_24h||0);
                        var price=parseFloat(c.current_price||0);
                        var cls=chg>=0?'up':'down';
                        var arrow=chg>=0?'▲':'▼';
                        var priceStr=price>=1?price.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}):price.toFixed(4);
                        return'<span class="fxlm-btick-item">'+
                            (c.image?'<img src="'+c.image+'" width="14" height="14" loading="lazy" alt="">':'')+
                            '<strong>'+c.symbol.toUpperCase()+'</strong> $'+priceStr+
                            ' <span class="'+cls+'">'+arrow+' '+Math.abs(chg).toFixed(2)+'%</span></span>';
                    }).join('');
                }).catch(function(){});
            }
            setInterval(refreshBottomTicker, 30000);
        })();
        </script>
        <?php
        return ob_get_clean();
    }



    /**
     * v56: [bt_threads_archive] — public archive of AI-generated Twitter threads.
     * Lists all posts with the _bt_twitter_thread meta key, displayed as thread cards.
     * Each card shows the hook tweet inline with an expand-to-read-full-thread button.
     */
    public static function sc_threads_archive( $atts ) {
        $a = shortcode_atts( array( 'limit' => 30 ), $atts );
        $limit  = max( 1, min( 100, intval( $a['limit'] ) ) );
        $handle = get_option( 'bt_twitter_handle', '@blocktickerIO' );

        $posts = get_posts( array(
            'numberposts' => $limit,
            'post_status' => 'publish',
            'meta_query'  => array(
                array( 'key' => '_bt_twitter_thread', 'compare' => 'EXISTS' ),
            ),
            'orderby' => 'date',
            'order'   => 'DESC',
        ) );

        if ( empty( $posts ) ) {
            return '<div class="bt-threads-empty">
                <div class="bt-threads-empty-icon">🧵</div>
                <h3>' . esc_html( __bt( 'threads.none' ) ) . '</h3>
                <p>Daily institutional threads will appear here once the AI Analysis generator runs. Each thread is derived from live market data and published alongside the daily report.</p>
                <a href="' . esc_url( home_url( '/market-analysis/' ) ) . '" class="bt-threads-cta">See Market Analysis →</a>
            </div>';
        }

        ob_start();
        ?>
        <div class="bt-threads-archive">
            <div class="bt-threads-intro">
                <div class="bt-threads-intro-left">
                    <h2><?php echo esc_html( __bt( 'threads.title' ) ); ?></h2>
                    <p>Every AI analysis report is distilled into a ready-to-read thread. Institutional tone, live data, zero filler.</p>
                </div>
                <a href="https://twitter.com/<?php echo esc_attr( ltrim( $handle, '@' ) ); ?>" target="_blank" rel="noopener" class="bt-threads-follow">
                    Follow <?php echo esc_html( $handle ); ?> on 𝕏
                </a>
            </div>

            <div class="bt-threads-list">
                <?php foreach ( $posts as $post ):
                    $thread = get_post_meta( $post->ID, '_bt_twitter_thread', true );
                    if ( ! is_array( $thread ) || empty( $thread ) ) continue;
                    $date_ts  = get_the_time( 'U', $post );
                    $time_ago = human_time_diff( $date_ts ) . ' ago';
                    $date_fmt = get_the_date( 'M j, Y', $post->ID );
                    $post_url = get_permalink( $post->ID );
                    $hook     = $thread[0];
                    $count    = count( $thread );
                    $intent   = 'https://twitter.com/intent/tweet?text=' . rawurlencode( $hook );
                ?>
                <article class="bt-thread-card" data-thread-id="<?php echo intval( $post->ID ); ?>">
                    <header class="bt-thread-head">
                        <div class="bt-thread-avatar"><?php echo esc_html( strtoupper( substr( ltrim( $handle, '@' ), 0, 2 ) ) ); ?></div>
                        <div class="bt-thread-meta">
                            <div class="bt-thread-author">BlockTicker <span class="bt-thread-check">✓</span></div>
                            <div class="bt-thread-handle"><?php echo esc_html( $handle ); ?> · <span title="<?php echo esc_attr( $date_fmt ); ?>"><?php echo esc_html( $time_ago ); ?></span></div>
                        </div>
                        <span class="bt-thread-count"><?php echo intval( $count ); ?> tweets</span>
                    </header>
                    <div class="bt-thread-hook"><?php echo esc_html( $hook ); ?></div>
                    <div class="bt-thread-body" style="display:none">
                        <?php foreach ( array_slice( $thread, 1 ) as $i => $t ): ?>
                        <div class="bt-thread-tweet">
                            <span class="bt-thread-tweet-n"><?php echo $i + 2; ?>/</span>
                            <span class="bt-thread-tweet-t"><?php echo esc_html( $t ); ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <footer class="bt-thread-foot">
                        <button type="button" class="bt-thread-expand" data-target="<?php echo intval( $post->ID ); ?>" aria-expanded="false">Read thread ↓</button>
                        <div class="bt-thread-actions">
                            <button type="button" class="bt-thread-copy" data-tweets="<?php echo esc_attr( wp_json_encode( $thread ) ); ?>">📋 Copy</button>
                            <a class="bt-thread-share" href="<?php echo esc_url( $intent ); ?>" target="_blank" rel="noopener">Share on 𝕏</a>
                            <a class="bt-thread-perma" href="<?php echo esc_url( $post_url ); ?>">Full report →</a>
                        </div>
                    </footer>
                </article>
                <?php endforeach; ?>
            </div>
        </div>

        <script>
        (function(){
            document.addEventListener('click', function(e){
                var btn = e.target.closest('.bt-thread-expand');
                if (btn) {
                    var card = btn.closest('.bt-thread-card');
                    var body = card.querySelector('.bt-thread-body');
                    var open = body.style.display !== 'none';
                    body.style.display = open ? 'none' : 'block';
                    btn.setAttribute('aria-expanded', open ? 'false' : 'true');
                    btn.textContent = open ? 'Read thread ↓' : 'Collapse ↑';
                    return;
                }
                var copy = e.target.closest('.bt-thread-copy');
                if (copy) {
                    e.preventDefault();
                    try {
                        var tweets = JSON.parse(copy.getAttribute('data-tweets'));
                        var joined = tweets.map(function(t, i){ return (i+1) + '/ ' + t; }).join('\n\n');
                        navigator.clipboard.writeText(joined).then(function(){
                            var orig = copy.textContent;
                            copy.textContent = '✓ Copied';
                            setTimeout(function(){ copy.textContent = orig; }, 1800);
                        });
                    } catch(err) {}
                }
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * v56: Auto-append Twitter thread + Newsletter preview to the blog content
     * on ai-daily-report posts, so visitors see the full package, not just the article.
     */
    public static function append_thread_to_content( $content ) {
        if ( ! is_singular( 'post' ) || ! in_the_loop() || ! is_main_query() ) return $content;
        global $post;
        if ( ! $post ) return $content;
        $thread  = get_post_meta( $post->ID, '_bt_twitter_thread', true );
        if ( ! is_array( $thread ) || empty( $thread ) ) return $content;
        $handle  = get_option( 'bt_twitter_handle', '@blocktickerIO' );
        $hook    = $thread[0];
        $intent  = 'https://twitter.com/intent/tweet?text=' . rawurlencode( $hook );
        $follow  = 'https://twitter.com/' . ltrim( $handle, '@' );

        ob_start();
        ?>
        <div class="bt-inline-thread">
            <div class="bt-inline-thread-head">
                <span class="bt-inline-thread-badge">𝕏 Thread version</span>
                <a href="<?php echo esc_url( $follow ); ?>" target="_blank" rel="noopener" class="bt-inline-thread-follow">Follow <?php echo esc_html( $handle ); ?></a>
            </div>
            <p class="bt-inline-thread-intro">This report is also available as a Twitter/X thread. <?php echo intval( count( $thread ) ); ?> tweets, ready to read or share.</p>
            <div class="bt-inline-thread-tweets">
                <?php foreach ( $thread as $i => $t ): ?>
                <div class="bt-thread-tweet">
                    <span class="bt-thread-tweet-n"><?php echo $i + 1; ?>/</span>
                    <span class="bt-thread-tweet-t"><?php echo esc_html( $t ); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="bt-inline-thread-actions">
                <a class="bt-thread-share" href="<?php echo esc_url( $intent ); ?>" target="_blank" rel="noopener">𝕏 Share the hook</a>
                <a class="bt-thread-perma" href="<?php echo esc_url( home_url( '/threads/' ) ); ?>">See all threads →</a>
            </div>
        </div>
        <?php
        return $content . ob_get_clean();
    }

    /**
     * v63: Prepend a social-share bar to single post content. Hits all posts,
     * but only renders once per page. Includes native web share API fallback for mobile.
     * Uses intent URLs (no tracking scripts, no GDPR concerns).
     */
    public static function prepend_share_bar_to_content( $content ) {
        if ( ! is_singular( 'post' ) || ! in_the_loop() || ! is_main_query() ) return $content;
        // Idempotency: only prepend once, even if the_content filter fires multiple times
        static $already_rendered = array();
        global $post;
        if ( ! $post || isset( $already_rendered[ $post->ID ] ) ) return $content;
        $already_rendered[ $post->ID ] = true;

        $url   = get_permalink( $post->ID );
        $title = $post->post_title;
        $u_url = rawurlencode( $url );
        $u_ttl = rawurlencode( $title );
        $handle = ltrim( get_option( 'bt_twitter_handle', '@blocktickerIO' ), '@' );

        $tw  = "https://twitter.com/intent/tweet?text={$u_ttl}&url={$u_url}&via={$handle}";
        $fb  = "https://www.facebook.com/sharer/sharer.php?u={$u_url}";
        $li  = "https://www.linkedin.com/sharing/share-offsite/?url={$u_url}";
        $rd  = "https://reddit.com/submit?url={$u_url}&title={$u_ttl}";
        $tg  = "https://t.me/share/url?url={$u_url}&text={$u_ttl}";
        $wa  = "https://api.whatsapp.com/send?text={$u_ttl}%20{$u_url}";
        $em  = "mailto:?subject={$u_ttl}&body={$u_url}";

        ob_start();
        ?>
        <div class="bt-share-bar" role="group" aria-label="Share this article">
            <span class="bt-share-label">Share</span>
            <a class="bt-share-btn bt-share-tw" href="<?php echo esc_url( $tw ); ?>" target="_blank" rel="noopener" aria-label="Share on X/Twitter" title="Share on X">𝕏</a>
            <a class="bt-share-btn bt-share-li" href="<?php echo esc_url( $li ); ?>" target="_blank" rel="noopener" aria-label="Share on LinkedIn" title="Share on LinkedIn">in</a>
            <a class="bt-share-btn bt-share-fb" href="<?php echo esc_url( $fb ); ?>" target="_blank" rel="noopener" aria-label="Share on Facebook" title="Share on Facebook">f</a>
            <a class="bt-share-btn bt-share-rd" href="<?php echo esc_url( $rd ); ?>" target="_blank" rel="noopener" aria-label="Share on Reddit" title="Share on Reddit">r</a>
            <a class="bt-share-btn bt-share-tg" href="<?php echo esc_url( $tg ); ?>" target="_blank" rel="noopener" aria-label="Share on Telegram" title="Share on Telegram">✈</a>
            <a class="bt-share-btn bt-share-wa" href="<?php echo esc_url( $wa ); ?>" target="_blank" rel="noopener" aria-label="Share on WhatsApp" title="Share on WhatsApp">W</a>
            <a class="bt-share-btn bt-share-em" href="<?php echo esc_url( $em ); ?>" aria-label="Share via email" title="Share via email">@</a>
            <button type="button" class="bt-share-btn bt-share-copy" aria-label="Copy link" title="Copy link" onclick="(function(b){var u='<?php echo esc_js( $url ); ?>';if(navigator.clipboard){navigator.clipboard.writeText(u).then(function(){var o=b.textContent;b.textContent='✓';b.style.background='rgba(0,255,102,.18)';b.style.color='var(--bt-accent)';setTimeout(function(){b.textContent=o;b.style.background='';b.style.color='';},1500);});}})(this)">⧉</button>
        </div>
        <?php
        return ob_get_clean() . $content;
    }


}

// ══════════════════════════════════════════════════════════════
//  NEW FEATURES v26
//  1. Gainers & Losers shortcode
//  2. Exchanges shortcode
//  3. Watchlist (localStorage-based, real prices)
//  4. Bottom currency ticker bar
//  5. Crypto calculator shortcode
// ══════════════════════════════════════════════════════════════

