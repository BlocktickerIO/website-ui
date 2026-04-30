<?php
/**
 * BT_Database — Custom tables for BlockTicker historical data.
 *
 * v96.2 audit fix F-02: Replaces the wp_options blob storage pattern for
 * time-series data. Gives the plugin its own schema so we can do:
 *   - historical price queries ("BTC 30 days ago")
 *   - deduped news archive ("search by title, grouped by source")
 *   - signal accountability ("what was source X's win rate last quarter?")
 *   - unified event stream (for future webhook delivery)
 *
 * Design principles:
 *   1. Additive only. Existing wp_options storage keeps working. This class
 *      writes IN ADDITION to the options, not instead of. Code reading from
 *      options is untouched. Once all readers migrate, the option writers
 *      can be removed in v97+.
 *   2. dbDelta-safe schema (strict formatting, required by WordPress).
 *   3. Every public method is idempotent. Calling install() twice is fine.
 *   4. Uses the `BT_` prefix — all identifiers are `BT_` as of v103.0.
 *      After v97's constant rename, this class needs zero changes.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BT_Database {

    /** Schema version. Bump when ANY CREATE TABLE statement changes. */
    const DB_VERSION = '1.1.0'; // v109.0: added attempts + last_attempt_at to bt_events

    /** Option key storing the currently-installed schema version. */
    const VERSION_OPTION = 'bt_db_version';

    /**
     * Retention windows (in days). Purge cron deletes rows older than these.
     * Price history: 90 days covers the longest default chart window.
     * Events: 30 days is plenty for webhook retry / audit.
     * News & signals: no automatic purge (both compact; signals are our moat).
     */
    const RETAIN_PRICE_DAYS = 90;
    const RETAIN_EVENT_DAYS = 30;

    /* ============================================================= *
     * BOOTSTRAP
     * ============================================================= */

    public static function init() {
        // Snapshot hooks — run AFTER the existing fetch handlers (priority 20).
        // CRITICAL FIX (v119.25): these hooks were registered to legacy
        // fxlm_refresh_* action names, but the cron fires bt_refresh_* (per
        // fx-live-markets.php). Result: tables existed but were never written
        // to — 0 rows after weeks of running. The bt_refresh_* names are the
        // canonical ones used since v98.
        add_action( 'bt_refresh_prices',  array( __CLASS__, 'snapshot_prices_after_fetch' ), 20 );
        add_action( 'bt_refresh_news',    array( __CLASS__, 'snapshot_news_after_fetch' ),   20 );
        add_action( 'bt_refresh_signals', array( __CLASS__, 'snapshot_signals_after_fetch' ), 20 );
        // Keep the legacy fxlm_* hook bindings as a one-version compat shim
        // in case any in-flight cron jobs were queued with the old name.
        add_action( 'fxlm_refresh_prices',  array( __CLASS__, 'snapshot_prices_after_fetch' ), 20 );
        add_action( 'fxlm_refresh_news',    array( __CLASS__, 'snapshot_news_after_fetch' ),   20 );
        add_action( 'fxlm_refresh_signals', array( __CLASS__, 'snapshot_signals_after_fetch' ), 20 );

        // Daily retention cron.
        add_action( 'bt_purge_old_data', array( __CLASS__, 'purge_old_data' ) );
        if ( ! wp_next_scheduled( 'bt_purge_old_data' ) ) {
            wp_schedule_event( time() + 3600, 'daily', 'bt_purge_old_data' );
        }

        // Run install / upgrade check once per admin load (cheap; dbDelta is
        // a no-op when schema is already current).
        if ( is_admin() ) {
            self::maybe_install();
        }
    }

    /* ============================================================= *
     * INSTALL / UPGRADE
     * ============================================================= */

    /**
     * Create tables if missing; re-run dbDelta if schema version changed.
     * Safe to call repeatedly.
     */
    public static function maybe_install() {
        $installed = get_option( self::VERSION_OPTION );
        if ( $installed === self::DB_VERSION ) return;

        self::install();
        update_option( self::VERSION_OPTION, self::DB_VERSION );

        // First-time install: seed a row per currently-known coin so users
        // have an anchor point immediately rather than waiting 5 minutes.
        if ( $installed === false ) {
            self::backfill_seed_from_options();
        }
    }

    /**
     * Run the actual CREATE TABLE IF NOT EXISTS statements via dbDelta.
     * WordPress requires very specific formatting — DO NOT reformat these
     * SQL strings or dbDelta's diff parser will re-run ALTER TABLE on every
     * request. See: https://codex.wordpress.org/Creating_Tables_with_Plugins
     */
    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $prefix  = $wpdb->prefix;

        $sql = array();

        // ─── wp_bt_price_history ──────────────────────────────────────
        $sql[] = "CREATE TABLE {$prefix}bt_price_history (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            symbol varchar(32) NOT NULL,
            asset_class varchar(16) NOT NULL DEFAULT 'crypto',
            price_usd decimal(24,10) NOT NULL DEFAULT 0,
            volume_24h decimal(24,2) NOT NULL DEFAULT 0,
            market_cap decimal(24,2) NOT NULL DEFAULT 0,
            pct_change_24h decimal(10,4) NOT NULL DEFAULT 0,
            captured_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY symbol_time (symbol, captured_at),
            KEY asset_class_time (asset_class, captured_at),
            KEY captured_at (captured_at)
        ) $charset;";

        // ─── wp_bt_news_items ─────────────────────────────────────────
        $sql[] = "CREATE TABLE {$prefix}bt_news_items (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            url_hash char(64) NOT NULL,
            source varchar(128) NOT NULL,
            category varchar(64) NOT NULL DEFAULT '',
            title varchar(500) NOT NULL,
            url text NOT NULL,
            summary text NOT NULL,
            sentiment_score decimal(5,3) DEFAULT NULL,
            symbols_mentioned varchar(255) NOT NULL DEFAULT '',
            published_at datetime NOT NULL,
            ingested_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY url_hash (url_hash),
            KEY source_published (source, published_at),
            KEY published_at (published_at),
            KEY category (category)
        ) $charset;";

        // ─── wp_bt_signals_history ────────────────────────────────────
        $sql[] = "CREATE TABLE {$prefix}bt_signals_history (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            symbol varchar(32) NOT NULL,
            direction varchar(12) NOT NULL DEFAULT 'NEUTRAL',
            source_name varchar(200) NOT NULL,
            entry_price decimal(24,10) DEFAULT NULL,
            target_price decimal(24,10) DEFAULT NULL,
            stop_loss decimal(24,10) DEFAULT NULL,
            outcome varchar(12) DEFAULT NULL,
            outcome_pct decimal(10,4) DEFAULT NULL,
            signaled_at datetime NOT NULL,
            closed_at datetime DEFAULT NULL,
            raw_url text NOT NULL,
            PRIMARY KEY  (id),
            KEY symbol_signaled (symbol, signaled_at),
            KEY source_signaled (source_name, signaled_at),
            KEY outcome (outcome),
            KEY signaled_at (signaled_at)
        ) $charset;";

        // ─── wp_bt_events ─────────────────────────────────────────────
        $sql[] = "CREATE TABLE {$prefix}bt_events (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_type varchar(64) NOT NULL,
            symbol varchar(32) NOT NULL DEFAULT '',
            payload longtext NOT NULL,
            delivered tinyint(1) NOT NULL DEFAULT 0,
            attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
            last_attempt_at datetime DEFAULT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY event_type_created (event_type, created_at),
            KEY symbol_created (symbol, created_at),
            KEY delivered (delivered),
            KEY created_at (created_at)
        ) $charset;";

        foreach ( $sql as $stmt ) {
            dbDelta( $stmt );
        }
    }

    /**
     * Drop all custom tables. Called from uninstall.php when the user
     * has NOT set BT_KEEP_DATA_ON_UNINSTALL.
     */
    public static function drop_all() {
        global $wpdb;
        $tables = array(
            $wpdb->prefix . 'bt_price_history',
            $wpdb->prefix . 'bt_news_items',
            $wpdb->prefix . 'bt_signals_history',
            $wpdb->prefix . 'bt_events',
        );
        foreach ( $tables as $t ) {
            $wpdb->query( "DROP TABLE IF EXISTS `$t`" );
        }
        delete_option( self::VERSION_OPTION );
    }

    /**
     * On first install, copy the current `fxlm_crypto_data` option into
     * price_history as a seed row per coin. This means charts have at
     * least one data point from minute-zero rather than "no data yet."
     */
    protected static function backfill_seed_from_options() {
        $data = get_option( 'bt_crypto_data' );
        if ( is_string( $data ) ) $data = json_decode( $data, true );
        if ( empty( $data['coins'] ) || ! is_array( $data['coins'] ) ) return;

        $now = current_time( 'mysql', true ); // UTC
        foreach ( $data['coins'] as $coin ) {
            if ( empty( $coin['symbol'] ) ) continue;
            self::insert_price_snapshot( $coin, $now );
        }
    }

    /* ============================================================= *
     * INSERT HELPERS
     * ============================================================= */

    /**
     * Insert one crypto price snapshot. Accepts the CoinGecko coin shape
     * that BT_Widgets stores in `fxlm_crypto_data`.
     *
     * @param array  $coin        Single coin array from the CoinGecko payload.
     * @param string $captured_at Optional UTC mysql datetime; defaults to now.
     * @return int|false Inserted row id, or false on failure.
     */
    public static function insert_price_snapshot( $coin, $captured_at = null ) {
        global $wpdb;
        if ( empty( $coin['symbol'] ) ) return false;
        if ( ! $captured_at ) $captured_at = current_time( 'mysql', true );

        $data = array(
            'symbol'         => strtoupper( (string) $coin['symbol'] ),
            'asset_class'    => 'crypto',
            'price_usd'      => isset( $coin['current_price'] )        ? (float) $coin['current_price']        : 0,
            'volume_24h'     => isset( $coin['total_volume'] )         ? (float) $coin['total_volume']         : 0,
            'market_cap'     => isset( $coin['market_cap'] )           ? (float) $coin['market_cap']           : 0,
            'pct_change_24h' => isset( $coin['price_change_percentage_24h'] )
                                ? (float) $coin['price_change_percentage_24h']
                                : 0,
            'captured_at'    => $captured_at,
        );
        $ok = $wpdb->insert( $wpdb->prefix . 'bt_price_history', $data,
            array( '%s', '%s', '%f', '%f', '%f', '%f', '%s' ) );
        return $ok ? (int) $wpdb->insert_id : false;
    }

    /**
     * Insert one forex pair snapshot.
     *
     * @param string $pair     e.g. "EUR/USD"
     * @param float  $rate
     * @param float  $change   daily % change
     */
    public static function insert_forex_snapshot( $pair, $rate, $change = 0, $captured_at = null ) {
        global $wpdb;
        if ( ! $captured_at ) $captured_at = current_time( 'mysql', true );
        $ok = $wpdb->insert( $wpdb->prefix . 'bt_price_history', array(
            'symbol'         => strtoupper( (string) $pair ),
            'asset_class'    => 'forex',
            'price_usd'      => (float) $rate, // Note: for forex we store the quoted rate, not USD conversion.
            'volume_24h'     => 0,
            'market_cap'     => 0,
            'pct_change_24h' => (float) $change,
            'captured_at'    => $captured_at,
        ), array( '%s', '%s', '%f', '%f', '%f', '%f', '%s' ) );
        return $ok ? (int) $wpdb->insert_id : false;
    }

    /**
     * Insert a news item (deduplicated by SHA-256 of URL).
     * Returns inserted id, or 0 if the URL was already present.
     */
    public static function insert_news_item( $item ) {
        global $wpdb;
        if ( empty( $item['url'] ) ) return 0;

        $url_hash = hash( 'sha256', (string) $item['url'] );

        // Dedup check — cheaper than catching a unique-key violation.
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}bt_news_items WHERE url_hash = %s LIMIT 1",
            $url_hash
        ) );
        if ( $existing ) return 0;

        $published_at = ! empty( $item['timestamp'] )
            ? gmdate( 'Y-m-d H:i:s', (int) $item['timestamp'] )
            : current_time( 'mysql', true );

        $title = isset( $item['title'] ) ? (string) $item['title'] : '';
        if ( strlen( $title ) > 500 ) $title = substr( $title, 0, 497 ) . '...';

        $ok = $wpdb->insert( $wpdb->prefix . 'bt_news_items', array(
            'url_hash'          => $url_hash,
            'source'            => isset( $item['source'] )   ? substr( (string) $item['source'],   0, 128 ) : '',
            'category'          => isset( $item['category'] ) ? substr( (string) $item['category'], 0,  64 ) : '',
            'title'             => $title,
            'url'               => (string) $item['url'],
            'summary'           => isset( $item['description'] ) ? (string) $item['description'] : '',
            'sentiment_score'   => null, // populated later by sentiment cron (v98+)
            'symbols_mentioned' => '',   // populated later by NER cron (v98+)
            'published_at'      => $published_at,
            'ingested_at'       => current_time( 'mysql', true ),
        ), array( '%s','%s','%s','%s','%s','%s',null,'%s','%s','%s' ) );

        return $ok ? (int) $wpdb->insert_id : 0;
    }

    /**
     * Insert a signal into history. Called from the RSS signal fetcher.
     * Uses url_hash equivalent for dedup so the same signal feed item
     * isn't logged twice per refresh.
     */
    public static function insert_signal( $signal ) {
        global $wpdb;
        if ( empty( $signal['url'] ) && empty( $signal['raw_url'] ) ) return 0;
        $url = ! empty( $signal['url'] ) ? $signal['url'] : $signal['raw_url'];

        // Dedup on (source + url) — same feed item should only be recorded once.
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}bt_signals_history
             WHERE source_name = %s AND raw_url = %s LIMIT 1",
            isset( $signal['source'] ) ? (string) $signal['source'] : '',
            (string) $url
        ) );
        if ( $existing ) return 0;

        $signaled_at = ! empty( $signal['timestamp'] )
            ? gmdate( 'Y-m-d H:i:s', (int) $signal['timestamp'] )
            : current_time( 'mysql', true );

        $direction = isset( $signal['direction'] ) ? strtoupper( (string) $signal['direction'] ) : 'NEUTRAL';
        if ( ! in_array( $direction, array( 'LONG', 'SHORT', 'NEUTRAL' ), true ) ) {
            $direction = 'NEUTRAL';
        }

        $ok = $wpdb->insert( $wpdb->prefix . 'bt_signals_history', array(
            'symbol'       => isset( $signal['symbol'] ) ? strtoupper( (string) $signal['symbol'] ) : '',
            'direction'    => $direction,
            'source_name'  => isset( $signal['source'] ) ? (string) $signal['source'] : '',
            'entry_price'  => isset( $signal['entry_price'] )  ? (float) $signal['entry_price']  : null,
            'target_price' => isset( $signal['target_price'] ) ? (float) $signal['target_price'] : null,
            'stop_loss'    => isset( $signal['stop_loss'] )    ? (float) $signal['stop_loss']    : null,
            'outcome'      => null,
            'outcome_pct'  => null,
            'signaled_at'  => $signaled_at,
            'closed_at'    => null,
            'raw_url'      => (string) $url,
        ) );
        return $ok ? (int) $wpdb->insert_id : 0;
    }

    /**
     * Log a structured event. Lightweight — this is the seed of the
     * webhook delivery system (v98+).
     */
    public static function log_event( $event_type, $symbol = '', $payload = array() ) {
        global $wpdb;
        $ok = $wpdb->insert( $wpdb->prefix . 'bt_events', array(
            'event_type' => substr( (string) $event_type, 0, 64 ),
            'symbol'     => substr( (string) $symbol, 0, 32 ),
            'payload'    => wp_json_encode( $payload ),
            'delivered'  => 0,
            'created_at' => current_time( 'mysql', true ),
        ), array( '%s', '%s', '%s', '%d', '%s' ) );
        return $ok ? (int) $wpdb->insert_id : false;
    }

    /* ============================================================= *
     * READ HELPERS
     * ============================================================= */

    /**
     * Fetch price history rows.
     *
     * @param string $symbol     e.g. 'BTC'
     * @param int    $days       how many days back (max 365)
     * @param string $resolution '5m' | '1h' | '1d' — aggregation bucket
     * @return array             rows: [{captured_at, price_usd, volume_24h, ...}]
     */
    public static function get_price_history( $symbol, $days = 7, $resolution = '1h' ) {
        global $wpdb;
        $symbol = strtoupper( preg_replace( '/[^A-Z0-9\/\-]/i', '', (string) $symbol ) );
        $days   = max( 1, min( 365, (int) $days ) );

        $since = gmdate( 'Y-m-d H:i:s', time() - ( $days * 86400 ) );

        // For tight resolutions we just return raw rows. For wider ones we
        // aggregate by date bucket on the SQL side — avoids shipping
        // thousands of rows over the wire.
        if ( $resolution === '5m' ) {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT captured_at, price_usd, volume_24h, market_cap, pct_change_24h
                 FROM {$wpdb->prefix}bt_price_history
                 WHERE symbol = %s AND captured_at >= %s
                 ORDER BY captured_at ASC
                 LIMIT 5000",
                $symbol, $since
            ), ARRAY_A );
            return $rows ?: array();
        }

        $bucket = ( $resolution === '1d' )
            ? "DATE_FORMAT(captured_at, '%%Y-%%m-%%d 00:00:00')"
            : "DATE_FORMAT(captured_at, '%%Y-%%m-%%d %%H:00:00')";

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT $bucket AS captured_at,
                    AVG(price_usd)      AS price_usd,
                    MAX(volume_24h)     AS volume_24h,
                    MAX(market_cap)     AS market_cap,
                    AVG(pct_change_24h) AS pct_change_24h
             FROM {$wpdb->prefix}bt_price_history
             WHERE symbol = %s AND captured_at >= %s
             GROUP BY $bucket
             ORDER BY captured_at ASC
             LIMIT 5000",
            $symbol, $since
        ), ARRAY_A );
        return $rows ?: array();
    }

    /**
     * Count rows in each table — for admin dashboard later.
     */
    public static function get_row_counts() {
        global $wpdb;
        $tables = array(
            'price_history'    => 'bt_price_history',
            'news_items'       => 'bt_news_items',
            'signals_history'  => 'bt_signals_history',
            'events'           => 'bt_events',
        );
        $out = array();
        foreach ( $tables as $label => $t ) {
            $out[ $label ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}$t" );
        }
        return $out;
    }

    /* ============================================================= *
     * SNAPSHOT HOOKS (fire after existing cron handlers at priority 20)
     * ============================================================= */

    public static function snapshot_prices_after_fetch() {
        // Crypto
        $crypto = get_option( 'bt_crypto_data' );
        if ( is_string( $crypto ) ) $crypto = json_decode( $crypto, true );
        if ( ! empty( $crypto['coins'] ) && is_array( $crypto['coins'] ) ) {
            $now = current_time( 'mysql', true );
            foreach ( $crypto['coins'] as $coin ) {
                self::insert_price_snapshot( $coin, $now );
            }
        }

        // Forex
        $forex = get_option( 'bt_forex_data' );
        if ( is_string( $forex ) ) $forex = json_decode( $forex, true );
        if ( ! empty( $forex['rates'] ) && is_array( $forex['rates'] ) ) {
            $now = current_time( 'mysql', true );
            foreach ( $forex['rates'] as $pair => $data ) {
                $rate   = is_array( $data ) && isset( $data['rate'] )   ? (float) $data['rate']   : (float) $data;
                $change = is_array( $data ) && isset( $data['change'] ) ? (float) $data['change'] : 0;
                self::insert_forex_snapshot( $pair, $rate, $change, $now );
            }
        }
    }

    public static function snapshot_news_after_fetch() {
        $news = get_option( 'bt_news_items', array() );
        if ( ! is_array( $news ) ) return;
        foreach ( $news as $item ) {
            self::insert_news_item( $item );
        }
    }

    public static function snapshot_signals_after_fetch() {
        $signals = get_option( 'bt_signal_items', array() );
        if ( ! is_array( $signals ) ) return;
        foreach ( $signals as $item ) {
            self::insert_signal( $item );
        }
    }

    /* ============================================================= *
     * RETENTION
     * ============================================================= */

    public static function purge_old_data() {
        global $wpdb;
        $price_cutoff = gmdate( 'Y-m-d H:i:s', time() - ( self::RETAIN_PRICE_DAYS * 86400 ) );
        $event_cutoff = gmdate( 'Y-m-d H:i:s', time() - ( self::RETAIN_EVENT_DAYS * 86400 ) );

        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}bt_price_history WHERE captured_at < %s",
            $price_cutoff
        ) );
        $deleted_price = (int) $wpdb->rows_affected;

        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}bt_events WHERE created_at < %s AND delivered = 1",
            $event_cutoff
        ) );
        $deleted_events = (int) $wpdb->rows_affected;

        // News and signals are NOT automatically purged — news archive is
        // compact, and signal history is the moat (never delete).
        return array(
            'price'  => $deleted_price,
            'events' => $deleted_events,
        );
    }
}
