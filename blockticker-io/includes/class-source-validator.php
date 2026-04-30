<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * BT_Source_Validator
 *
 * Validates all RSS feeds and data-source endpoints on a schedule.
 * Stores per-source health records (HTTP code, latency, freshness, schema OK)
 * in wp_options so the DB admin screen can display a live health dashboard.
 *
 * Added in v96.3 — no breaking changes to existing code.
 */
class BT_Source_Validator {

    const OPTION_HEALTH  = 'bt_source_health';   // array keyed by URL-hash
    const OPTION_SUMMARY = 'bt_source_summary';  // { checked_at, healthy, degraded, dead }
    const CRON_HOOK      = 'bt_validate_sources';

    // -------------------------------------------------------------------
    // Feed definitions — single source of truth for RSS sources.
    // Replaces dead Reuters RSS and deduplicates Investing.com.
    // -------------------------------------------------------------------
    public static function get_sources() {
        return array(

            // ── Macro / Policy ────────────────────────────────────────────
            array(
                'name'     => 'MarketWatch Top Stories',
                'url'      => 'https://feeds.marketwatch.com/marketwatch/topstories/',
                'category' => 'Macro & Policy',
                'type'     => 'news',
                'replaces' => 'Reuters (dead since 2020)',
            ),
            array(
                'name'     => 'CNBC Finance',
                'url'      => 'https://search.cnbc.com/rs/search/combinedcombined/view/rss/tag=10001109',
                'category' => 'Macro & Policy',
                'type'     => 'news',
            ),
            array(
                'name'     => 'MarketWatch Economy',
                'url'      => 'https://feeds.marketwatch.com/marketwatch/economy-politics/',
                'category' => 'Macro & Policy',
                'type'     => 'news',
            ),
            array(
                'name'     => 'Federal Reserve',
                'url'      => 'https://www.federalreserve.gov/feeds/press_all.xml',
                'category' => 'Macro & Policy',
                'type'     => 'news',
            ),
            array(
                'name'     => 'ECB Press',
                'url'      => 'https://www.ecb.europa.eu/rss/press.html',
                'category' => 'Macro & Policy',
                'type'     => 'news',
            ),

            // ── Forex News ────────────────────────────────────────────────
            array(
                'name'     => 'FXStreet News',
                'url'      => 'https://www.fxstreet.com/rss/news',
                'category' => 'Forex News',
                'type'     => 'news',
            ),
            array(
                'name'     => 'DailyFX',
                'url'      => 'https://www.dailyfx.com/feeds/all',
                'category' => 'Forex News',
                'type'     => 'news',
            ),
            array(
                'name'     => 'ForexLive',
                'url'      => 'https://www.forexlive.com/feed/news',
                'category' => 'Forex News',
                'type'     => 'news',
                'replaces' => 'Investing.com (rate-limited)',
            ),
            array(
                'name'     => 'Nasdaq Forex',
                'url'      => 'https://www.nasdaq.com/feed/rssoutbound?category=Currencies',
                'category' => 'Forex News',
                'type'     => 'news',
            ),

            // ── Crypto News ───────────────────────────────────────────────
            array(
                'name'     => 'CoinDesk',
                'url'      => 'https://www.coindesk.com/arc/outboundfeeds/rss/',
                'category' => 'Crypto News',
                'type'     => 'news',
            ),
            array(
                'name'     => 'CoinTelegraph',
                'url'      => 'https://cointelegraph.com/rss',
                'category' => 'Crypto News',
                'type'     => 'news',
            ),
            array(
                'name'     => 'Bitcoin Magazine',
                'url'      => 'https://bitcoinmagazine.com/.rss/full/',
                'category' => 'Crypto News',
                'type'     => 'news',
            ),
            array(
                'name'     => 'Decrypt',
                'url'      => 'https://decrypt.co/feed',
                'category' => 'Crypto News',
                'type'     => 'news',
            ),
            array(
                'name'     => 'The Block',
                'url'      => 'https://www.theblock.co/rss.xml',
                'category' => 'Crypto News',
                'type'     => 'news',
            ),
            array(
                'name'     => 'Blockworks',
                'url'      => 'https://blockworks.co/feed',
                'category' => 'Crypto News',
                'type'     => 'news',
            ),
            array(
                'name'     => 'BeInCrypto',
                'url'      => 'https://beincrypto.com/feed/',
                'category' => 'Crypto News',
                'type'     => 'news',
            ),
            array(
                'name'     => 'CryptoSlate',
                'url'      => 'https://cryptoslate.com/feed/',
                'category' => 'Crypto News',
                'type'     => 'news',
            ),

            // ── DeFi / On-Chain ───────────────────────────────────────────
            array(
                'name'     => 'The Defiant',
                'url'      => 'https://thedefiant.io/feed',
                'category' => 'DeFi News',
                'type'     => 'news',
            ),
            array(
                'name'     => 'DeFiLlama Blog',
                'url'      => 'https://blog.defillama.com/rss/',
                'category' => 'DeFi News',
                'type'     => 'news',
            ),

            // ── Trading Signals ───────────────────────────────────────────
            array(
                'name'     => 'FXStreet Analysis',
                'url'      => 'https://www.fxstreet.com/rss/analysis',
                'category' => 'Trading Signals',
                'type'     => 'signals',
            ),
            array(
                'name'     => 'ForexLive Analysis',
                'url'      => 'https://www.forexlive.com/feed/analysis',
                'category' => 'Trading Signals',
                'type'     => 'signals',
                'replaces' => 'Investing.com Signals (rate-limited)',
            ),
            array(
                'name'     => 'CoinDesk Markets',
                'url'      => 'https://www.coindesk.com/arc/outboundfeeds/rss/category/markets/',
                'category' => 'Trading Signals',
                'type'     => 'signals',
            ),
            array(
                'name'     => 'The Block Markets',
                'url'      => 'https://www.theblock.co/rss.xml',
                'category' => 'Trading Signals',
                'type'     => 'signals',
            ),

            // ── Data APIs (non-RSS; validated via HTTP HEAD or lightweight GET) ──
            array(
                'name'     => 'CoinGecko API',
                'url'      => 'https://api.coingecko.com/api/v3/ping',
                'category' => 'Data API',
                'type'     => 'api',
                'expect'   => 'gecko_say',  // key expected in JSON response
            ),
            array(
                'name'     => 'ECB Forex XML',
                'url'      => 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml',
                'category' => 'Data API',
                'type'     => 'api',
            ),
            array(
                'name'     => 'DeFiLlama TVL',
                'url'      => 'https://api.llama.fi/v2/globalcharts',
                'category' => 'Data API',
                'type'     => 'api',
            ),
        );
    }

    // -------------------------------------------------------------------
    // Bootstrap
    // -------------------------------------------------------------------
    public static function init() {
        // Hourly validation cron
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), 'hourly', self::CRON_HOOK );
        }
        add_action( self::CRON_HOOK, array( __CLASS__, 'run_validation' ) );

        // AJAX: force-validate from admin screen
        add_action( 'wp_ajax_bt_validate_sources_now', array( __CLASS__, 'ajax_validate_now' ) );
    }

    public static function deactivate() {
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }

    // -------------------------------------------------------------------
    // Validation runner
    // -------------------------------------------------------------------

    /**
     * Validate every source.  Called by cron and by admin AJAX.
     * Stores results in wp_options (no DB write if data unchanged).
     *
     * @param bool $force_all   When true, bypass the per-source 1h cooldown.
     * @return array            Summary array.
     */
    public static function run_validation( $force_all = false ) {
        $health   = get_option( self::OPTION_HEALTH, array() );
        $now      = time();
        $cooldown = HOUR_IN_SECONDS;

        $healthy  = 0;
        $degraded = 0;
        $dead     = 0;

        foreach ( self::get_sources() as $source ) {
            $key = md5( $source['url'] );

            // Skip if validated recently and not forced
            if ( ! $force_all && isset( $health[ $key ]['checked_at'] ) ) {
                if ( ( $now - $health[ $key ]['checked_at'] ) < $cooldown ) {
                    // Use cached result for summary counts
                    $status = $health[ $key ]['status'] ?? 'unknown';
                    if ( $status === 'healthy' )  $healthy++;
                    elseif ( $status === 'degraded' ) $degraded++;
                    elseif ( $status === 'dead' )  $dead++;
                    continue;
                }
            }

            $result = self::check_source( $source );
            $health[ $key ] = array_merge( $source, $result, array( 'checked_at' => $now ) );

            if ( $result['status'] === 'healthy' )  $healthy++;
            elseif ( $result['status'] === 'degraded' ) $degraded++;
            else $dead++;

            // Brief pause between requests — don't hammer external servers
            usleep( 150000 ); // 150 ms
        }

        update_option( self::OPTION_HEALTH, $health, false );

        $summary = array(
            'checked_at' => $now,
            'total'      => count( $health ),
            'healthy'    => $healthy,
            'degraded'   => $degraded,
            'dead'       => $dead,
        );
        update_option( self::OPTION_SUMMARY, $summary, false );

        return $summary;
    }

    /**
     * Check a single source and return a result array.
     *
     * @param array $source   Source definition from get_sources().
     * @return array          { status, http_code, latency_ms, item_count, freshness_h, error }
     */
    private static function check_source( $source ) {
        $start = microtime( true );

        $args = array(
            'timeout'    => 12,
            'user-agent' => 'BlockTicker/' . BT_VERSION . ' SourceValidator/1.0',
            'sslverify'  => true,
        );

        $response = wp_remote_get( $source['url'], $args );
        $latency  = (int) round( ( microtime( true ) - $start ) * 1000 );

        if ( is_wp_error( $response ) ) {
            return array(
                'status'      => 'dead',
                'http_code'   => 0,
                'latency_ms'  => $latency,
                'item_count'  => 0,
                'freshness_h' => null,
                'error'       => $response->get_error_message(),
            );
        }

        $http_code = (int) wp_remote_retrieve_response_code( $response );
        $body      = wp_remote_retrieve_body( $response );

        if ( $http_code !== 200 ) {
            return array(
                'status'      => ( $http_code >= 500 ) ? 'dead' : 'degraded',
                'http_code'   => $http_code,
                'latency_ms'  => $latency,
                'item_count'  => 0,
                'freshness_h' => null,
                'error'       => 'HTTP ' . $http_code,
            );
        }

        if ( empty( $body ) ) {
            return array(
                'status'      => 'dead',
                'http_code'   => $http_code,
                'latency_ms'  => $latency,
                'item_count'  => 0,
                'freshness_h' => null,
                'error'       => 'Empty response body',
            );
        }

        // ── API sources: JSON schema check ────────────────────────────
        if ( isset( $source['type'] ) && $source['type'] === 'api' ) {
            $result = self::validate_api_response( $source, $body, $http_code, $latency );
        } else {
            // ── RSS / Atom sources ─────────────────────────────────────
            $result = self::validate_rss_response( $source, $body, $http_code, $latency );
        }

        return $result;
    }

    /**
     * Validate an RSS/Atom feed body.
     */
    private static function validate_rss_response( $source, $body, $http_code, $latency ) {
        libxml_use_internal_errors( true );
        $xml = simplexml_load_string( $body );

        if ( ! $xml ) {
            return array(
                'status'      => 'dead',
                'http_code'   => $http_code,
                'latency_ms'  => $latency,
                'item_count'  => 0,
                'freshness_h' => null,
                'error'       => 'XML parse failed: ' . ( libxml_get_last_error() ? libxml_get_last_error()->message : 'unknown' ),
            );
        }

        $channel    = $xml->channel ?? $xml;
        $items      = $channel->item ?? array();
        $item_count = count( $items );

        if ( $item_count === 0 ) {
            return array(
                'status'      => 'degraded',
                'http_code'   => $http_code,
                'latency_ms'  => $latency,
                'item_count'  => 0,
                'freshness_h' => null,
                'error'       => 'Feed returned 0 items',
            );
        }

        // Check freshness: when was the newest item published?
        $newest_ts  = 0;
        foreach ( $items as $item ) {
            $pub  = (string) ( $item->pubDate ?? $item->updated ?? '' );
            $ts   = $pub ? strtotime( $pub ) : 0;
            if ( $ts > $newest_ts ) $newest_ts = $ts;
        }

        $freshness_h = $newest_ts ? round( ( time() - $newest_ts ) / 3600, 1 ) : null;

        // Status: degraded if newest item > 24 h old on a news feed
        $status = 'healthy';
        $error  = '';
        if ( $freshness_h !== null && $freshness_h > 24 && $source['type'] !== 'api' ) {
            $status = 'degraded';
            $error  = 'Newest item ' . $freshness_h . 'h old';
        }
        if ( $latency > 8000 ) {
            $status = 'degraded';
            $error  = 'Slow response (' . $latency . ' ms)';
        }

        return array(
            'status'      => $status,
            'http_code'   => $http_code,
            'latency_ms'  => $latency,
            'item_count'  => $item_count,
            'freshness_h' => $freshness_h,
            'error'       => $error,
        );
    }

    /**
     * Validate a JSON API response.
     */
    private static function validate_api_response( $source, $body, $http_code, $latency ) {
        $data  = json_decode( $body, true );
        $error = '';

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return array(
                'status'      => 'dead',
                'http_code'   => $http_code,
                'latency_ms'  => $latency,
                'item_count'  => 0,
                'freshness_h' => null,
                'error'       => 'JSON parse failed',
            );
        }

        // Optional: check for an expected key in the response
        if ( ! empty( $source['expect'] ) && ! isset( $data[ $source['expect'] ] ) ) {
            return array(
                'status'      => 'degraded',
                'http_code'   => $http_code,
                'latency_ms'  => $latency,
                'item_count'  => is_array( $data ) ? count( $data ) : 1,
                'freshness_h' => null,
                'error'       => 'Missing expected key: ' . $source['expect'],
            );
        }

        return array(
            'status'      => ( $latency > 8000 ) ? 'degraded' : 'healthy',
            'http_code'   => $http_code,
            'latency_ms'  => $latency,
            'item_count'  => is_array( $data ) ? count( $data ) : 1,
            'freshness_h' => null,
            'error'       => ( $latency > 8000 ) ? 'Slow response (' . $latency . ' ms)' : '',
        );
    }

    // -------------------------------------------------------------------
    // Public getters (used by admin screen)
    // -------------------------------------------------------------------

    /** @return array Health records keyed by URL-hash */
    public static function get_health() {
        return get_option( self::OPTION_HEALTH, array() );
    }

    /** @return array|false Summary or false if never run */
    public static function get_summary() {
        return get_option( self::OPTION_SUMMARY, false );
    }

    // -------------------------------------------------------------------
    // AJAX
    // -------------------------------------------------------------------
    public static function ajax_validate_now() {
        check_ajax_referer( 'bt_db_admin', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $summary = self::run_validation( true );
        wp_send_json_success( array(
            'summary' => $summary,
            'message' => sprintf(
                'Validated %d sources — %d healthy, %d degraded, %d dead.',
                $summary['total'],
                $summary['healthy'],
                $summary['degraded'],
                $summary['dead']
            ),
        ) );
    }
}
