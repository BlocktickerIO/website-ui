<?php
/**
 * BlockTicker — Saved Screeners subsystem
 *
 * User-saved filter combinations over the live crypto market data. Pure
 * aggregation play — no new API calls, no new tables, no AI. Reads the
 * existing `bt_crypto_data` option (top-500 by mcap, refreshed by the
 * existing cron) and applies user-defined criteria to produce a filtered
 * ranked list.
 *
 * Persistence model — mirrors v119.15 dashboard pattern
 *   - Logged-in: user meta `bt_user_screeners` (JSON array, max 10 entries)
 *   - Anonymous:  localStorage `bt_user_screeners` (client-side only)
 *   - Shared:     URL `?criteria={base64-json}` for one-shot links without
 *                 persistence (recipient gets the screener result; can save
 *                 it themselves if they want)
 *
 * Each saved screener:
 *   { id, name, criteria, created_at, updated_at }
 * Where criteria is:
 *   {
 *     sort_field, sort_dir,
 *     mcap_min, mcap_max,             // USD; null = unbounded
 *     change_24h_min, change_24h_max, // percent; null = unbounded
 *     volume_min,                     // USD; null = unbounded
 *     category,                       // slug | 'all' | 'altcoins' | 'large-cap'
 *     limit                           // 5..50
 *   }
 *
 * Why a separate criteria schema (not just URL params)
 *   - Validation centralised in one place
 *   - Forward-compatible: adding a `chain` filter is a schema bump, not a
 *     URL-format change
 *   - Compact for storage (no URL-encoding overhead in user meta)
 *
 * Public surface
 *   /screeners/                          — builder + saved-screener list
 *   [bt_screeners]                       — drop-in for the page above
 *   [bt_screeners_summary_card]          — dashboard widget (recent + top 3)
 *   /screeners/?run={id}                 — open with a specific saved screener
 *   /screeners/?criteria={base64}        — open with shared criteria
 *   BlockTicker → Screeners (admin)      — operator overview
 *
 * AJAX (logged-in only — anon clients use localStorage)
 *   wp_ajax_bt_screener_save     — create/update a screener
 *   wp_ajax_bt_screener_delete   — delete by id
 *   wp_ajax_bt_screener_run      — return JSON result for builder preview
 *
 * Filter hooks
 *   bt_screener_categories       — extend the category dropdown
 *   bt_screener_max_per_user     — change cap (default 10)
 *   bt_screener_default_criteria — change default criteria for new screeners
 *
 * @since v119.16.0
 * @package BlockTicker
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BT_Screeners {

    /** User-meta key for stored screeners (logged-in users). */
    const META_KEY = 'bt_user_screeners';

    /** Max screeners per user — mirrors watchlist cap. */
    const DEFAULT_MAX = 10;

    /** Categories the filter engine understands. */
    private static $base_categories = array(
        'all',
        'altcoins',     // exclude BTC + stablecoins
        'large-cap',    // top-100 by mcap rank
        'mid-cap',      // rank 101-300
        'small-cap',    // rank 301-500
    );

    /** Field whitelist for sort. Every entry must exist on a CoinGecko coin. */
    private static $sort_fields = array(
        'market_cap',
        'market_cap_rank',
        'current_price',
        'price_change_percentage_24h',
        'price_change_percentage_7d',
        'total_volume',
        'name',
    );

    public static function setup() {
        add_shortcode( 'bt_screeners',              array( __CLASS__, 'sc_screeners' ) );
        add_shortcode( 'bt_screeners_summary_card', array( __CLASS__, 'sc_summary_card' ) );

        add_action( 'wp_ajax_bt_screener_save',   array( __CLASS__, 'ajax_save' ) );
        add_action( 'wp_ajax_bt_screener_delete', array( __CLASS__, 'ajax_delete' ) );
        add_action( 'wp_ajax_bt_screener_run',    array( __CLASS__, 'ajax_run' ) );

        add_action( 'admin_menu', array( __CLASS__, 'register_admin_menu' ), 20 );

        // Dashboard composition: register summary card as a widget so v119.15
        // dashboard preset configs can include it.
        add_filter( 'bt_dashboard_widget_registry', array( __CLASS__, 'register_dashboard_widget' ) );
    }

    /* ================================================================
     *  Schema & defaults
     * ================================================================ */

    public static function get_max_per_user() {
        $max = (int) apply_filters( 'bt_screener_max_per_user', self::DEFAULT_MAX );
        return max( 1, min( 50, $max ) );
    }

    public static function get_categories() {
        $cats = self::$base_categories;
        // Allow site code to add CoinGecko-category-backed slugs (defi, gaming, etc.)
        // — they're handled in apply_category_filter() via the existing transient cache.
        $extra = apply_filters( 'bt_screener_categories', array(
            'defi',
            'stablecoins',
            'gaming',
            'metaverse',
            'nft',
        ) );
        return array_values( array_unique( array_merge( $cats, (array) $extra ) ) );
    }

    public static function get_default_criteria() {
        return apply_filters( 'bt_screener_default_criteria', array(
            'sort_field'      => 'market_cap',
            'sort_dir'        => 'desc',
            'mcap_min'        => null,
            'mcap_max'        => null,
            'change_24h_min'  => null,
            'change_24h_max'  => null,
            'volume_min'      => null,
            'category'        => 'all',
            'limit'           => 20,
        ) );
    }

    /**
     * Sanitizes a raw criteria array — every field clamped to a known good
     * value or null. Returns the canonical shape.
     */
    public static function normalize_criteria( $raw ) {
        $defaults = self::get_default_criteria();
        if ( ! is_array( $raw ) ) return $defaults;

        $out = $defaults;

        // Sort field
        if ( isset( $raw['sort_field'] ) && in_array( $raw['sort_field'], self::$sort_fields, true ) ) {
            $out['sort_field'] = $raw['sort_field'];
        }

        // Sort direction
        if ( isset( $raw['sort_dir'] ) && in_array( $raw['sort_dir'], array( 'asc', 'desc' ), true ) ) {
            $out['sort_dir'] = $raw['sort_dir'];
        }

        // Numeric ranges — null if not set or non-numeric
        $num_fields = array( 'mcap_min', 'mcap_max', 'change_24h_min', 'change_24h_max', 'volume_min' );
        foreach ( $num_fields as $f ) {
            if ( isset( $raw[ $f ] ) && $raw[ $f ] !== '' && is_numeric( $raw[ $f ] ) ) {
                $out[ $f ] = (float) $raw[ $f ];
            } else {
                $out[ $f ] = null;
            }
        }

        // Category — must be in the allowed list
        if ( isset( $raw['category'] ) ) {
            $cat = sanitize_key( $raw['category'] );
            if ( in_array( $cat, self::get_categories(), true ) ) {
                $out['category'] = $cat;
            }
        }

        // Limit — clamp 5..50
        if ( isset( $raw['limit'] ) && is_numeric( $raw['limit'] ) ) {
            $out['limit'] = max( 5, min( 50, (int) $raw['limit'] ) );
        }

        return $out;
    }

    /* ================================================================
     *  Storage — user meta (logged-in)
     * ================================================================ */

    public static function get_user_screeners( $user_id = 0 ) {
        if ( $user_id <= 0 ) $user_id = get_current_user_id();
        if ( $user_id <= 0 ) return array();

        $raw = get_user_meta( $user_id, self::META_KEY, true );
        if ( ! is_string( $raw ) || $raw === '' ) return array();

        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) ) return array();

        $clean = array();
        foreach ( $decoded as $entry ) {
            if ( ! is_array( $entry ) || empty( $entry['id'] ) || empty( $entry['name'] ) ) continue;
            $clean[] = array(
                'id'         => sanitize_key( $entry['id'] ),
                'name'       => sanitize_text_field( $entry['name'] ),
                'criteria'   => self::normalize_criteria( $entry['criteria'] ?? array() ),
                'created_at' => isset( $entry['created_at'] ) ? (int) $entry['created_at'] : 0,
                'updated_at' => isset( $entry['updated_at'] ) ? (int) $entry['updated_at'] : 0,
            );
        }
        return $clean;
    }

    public static function save_user_screener( $user_id, $name, $criteria, $id = '' ) {
        if ( $user_id <= 0 ) return false;
        $name = trim( wp_strip_all_tags( (string) $name ) );
        if ( $name === '' || strlen( $name ) > 60 ) return false;

        $screeners = self::get_user_screeners( $user_id );
        $criteria  = self::normalize_criteria( $criteria );
        $now       = time();

        if ( $id !== '' ) {
            // Update existing.
            $found = false;
            foreach ( $screeners as &$s ) {
                if ( $s['id'] === $id ) {
                    $s['name']       = $name;
                    $s['criteria']   = $criteria;
                    $s['updated_at'] = $now;
                    $found = true;
                    break;
                }
            }
            unset( $s );
            if ( ! $found ) return false;
        } else {
            // Create new — enforce cap.
            if ( count( $screeners ) >= self::get_max_per_user() ) return false;

            $screeners[] = array(
                'id'         => self::generate_id( $screeners ),
                'name'       => $name,
                'criteria'   => $criteria,
                'created_at' => $now,
                'updated_at' => $now,
            );
        }

        update_user_meta( $user_id, self::META_KEY, wp_json_encode( $screeners ) );
        return true;
    }

    public static function delete_user_screener( $user_id, $id ) {
        if ( $user_id <= 0 || $id === '' ) return false;
        $screeners = self::get_user_screeners( $user_id );
        $filtered  = array_values( array_filter(
            $screeners,
            function( $s ) use ( $id ) { return $s['id'] !== $id; }
        ) );
        if ( count( $filtered ) === count( $screeners ) ) return false; // nothing removed

        update_user_meta( $user_id, self::META_KEY, wp_json_encode( $filtered ) );
        return true;
    }

    private static function generate_id( $existing ) {
        $existing_ids = array_column( $existing, 'id' );
        $tries = 0;
        do {
            $id = 'scr_' . substr( md5( uniqid( '', true ) . $tries ), 0, 8 );
            $tries++;
        } while ( in_array( $id, $existing_ids, true ) && $tries < 10 );
        return $id;
    }

    /* ================================================================
     *  Filter engine — the actual screening logic
     * ================================================================ */

    /**
     * Runs a screener and returns the filtered+sorted+sliced coin list.
     * Each entry is the raw CoinGecko-shape coin object (downstream renderers
     * can pick the fields they want without us imposing a presentation shape).
     */
    public static function run_screener( $criteria ) {
        $criteria = self::normalize_criteria( $criteria );
        $coins    = self::load_coin_universe( $criteria['category'] );
        if ( empty( $coins ) ) return array();

        $coins = self::apply_category_filter( $coins, $criteria['category'] );

        // Numeric-range filters
        if ( $criteria['mcap_min'] !== null ) {
            $min = $criteria['mcap_min'];
            $coins = array_filter( $coins, function( $c ) use ( $min ) {
                return floatval( $c['market_cap'] ?? 0 ) >= $min;
            } );
        }
        if ( $criteria['mcap_max'] !== null ) {
            $max = $criteria['mcap_max'];
            $coins = array_filter( $coins, function( $c ) use ( $max ) {
                return floatval( $c['market_cap'] ?? 0 ) <= $max;
            } );
        }
        if ( $criteria['change_24h_min'] !== null ) {
            $min = $criteria['change_24h_min'];
            $coins = array_filter( $coins, function( $c ) use ( $min ) {
                return floatval( $c['price_change_percentage_24h'] ?? 0 ) >= $min;
            } );
        }
        if ( $criteria['change_24h_max'] !== null ) {
            $max = $criteria['change_24h_max'];
            $coins = array_filter( $coins, function( $c ) use ( $max ) {
                return floatval( $c['price_change_percentage_24h'] ?? 0 ) <= $max;
            } );
        }
        if ( $criteria['volume_min'] !== null ) {
            $min = $criteria['volume_min'];
            $coins = array_filter( $coins, function( $c ) use ( $min ) {
                return floatval( $c['total_volume'] ?? 0 ) >= $min;
            } );
        }

        // Sort
        $coins = self::apply_sort( array_values( $coins ), $criteria['sort_field'], $criteria['sort_dir'] );

        // Slice
        return array_slice( $coins, 0, (int) $criteria['limit'] );
    }

    /** Returns the full coin list source for a given category — defaults to bt_crypto_data['coins']. */
    private static function load_coin_universe( $category ) {
        // CoinGecko-category slugs (defi/stablecoins/etc.) have their own
        // transient cache populated by [fxlm_crypto_category]; reuse that data
        // when available so we don't double-fetch.
        $gecko_cats = array( 'defi', 'stablecoins', 'gaming', 'metaverse', 'nft' );
        if ( in_array( $category, $gecko_cats, true ) ) {
            $cached = get_transient( 'fxlm_cat_' . $category . '_v3' );
            if ( is_array( $cached ) && ! empty( $cached ) ) return $cached;
            // Fall through to general universe if no cached data — better than
            // returning empty (cold cache shouldn't break the screener).
        }

        $data = get_option( 'bt_crypto_data', array() );
        if ( is_string( $data ) ) $data = json_decode( $data, true );
        if ( ! is_array( $data ) || empty( $data['coins'] ) ) return array();
        return $data['coins'];
    }

    private static function apply_category_filter( $coins, $category ) {
        if ( $category === 'all' || in_array( $category, array( 'defi', 'stablecoins', 'gaming', 'metaverse', 'nft' ), true ) ) {
            // Either no filter, or universe was already category-filtered upstream
            return $coins;
        }
        if ( $category === 'altcoins' ) {
            $stables = self::stablecoin_symbols();
            return array_values( array_filter( $coins, function( $c ) use ( $stables ) {
                $sym = strtolower( $c['symbol'] ?? '' );
                if ( $sym === 'btc' ) return false;
                if ( in_array( $sym, $stables, true ) ) return false;
                return true;
            } ) );
        }
        if ( $category === 'large-cap' ) {
            return array_values( array_filter( $coins, function( $c ) {
                $rank = intval( $c['market_cap_rank'] ?? 999 );
                return $rank > 0 && $rank <= 100;
            } ) );
        }
        if ( $category === 'mid-cap' ) {
            return array_values( array_filter( $coins, function( $c ) {
                $rank = intval( $c['market_cap_rank'] ?? 999 );
                return $rank >= 101 && $rank <= 300;
            } ) );
        }
        if ( $category === 'small-cap' ) {
            return array_values( array_filter( $coins, function( $c ) {
                $rank = intval( $c['market_cap_rank'] ?? 999 );
                return $rank >= 301 && $rank <= 500;
            } ) );
        }
        return $coins;
    }

    private static function apply_sort( $coins, $field, $dir ) {
        $direction = ( $dir === 'asc' ) ? 1 : -1;
        $field_map = array(
            'price_change_percentage_7d' => 'price_change_percentage_7d_in_currency',
        );
        $real_field = $field_map[ $field ] ?? $field;

        usort( $coins, function( $a, $b ) use ( $real_field, $direction, $field ) {
            if ( $field === 'name' ) {
                return strcmp( strtolower( $a['name'] ?? '' ), strtolower( $b['name'] ?? '' ) ) * $direction;
            }
            $av = floatval( $a[ $real_field ] ?? 0 );
            $bv = floatval( $b[ $real_field ] ?? 0 );
            return ( $av <=> $bv ) * $direction;
        } );
        return $coins;
    }

    private static function stablecoin_symbols() {
        return array( 'usdt', 'usdc', 'dai', 'busd', 'fdusd', 'tusd', 'usdp', 'usde', 'frax', 'pyusd', 'gusd', 'lusd', 'susd', 'mim', 'usdd' );
    }

    /* ================================================================
     *  URL helpers — resolve `?run=` and `?criteria=` on incoming requests
     * ================================================================ */

    /**
     * Returns [criteria, source, name] tuple given current GET params:
     *   - ?run={id} (logged-in) → that screener's criteria, source = 'saved'
     *   - ?criteria={base64} → decoded criteria, source = 'shared'
     *   - default → defaults, source = 'default'
     */
    public static function resolve_request_criteria() {
        // Saved screener by id (requires login)
        if ( isset( $_GET['run'] ) && is_user_logged_in() ) {
            $id = sanitize_key( wp_unslash( $_GET['run'] ) );
            foreach ( self::get_user_screeners() as $s ) {
                if ( $s['id'] === $id ) {
                    return array( $s['criteria'], 'saved', $s['name'], $s['id'] );
                }
            }
        }
        // Shared criteria via base64
        if ( isset( $_GET['criteria'] ) ) {
            $raw = wp_unslash( $_GET['criteria'] );
            $json = base64_decode( $raw, true );
            if ( $json !== false ) {
                $decoded = json_decode( $json, true );
                if ( is_array( $decoded ) ) {
                    return array( self::normalize_criteria( $decoded ), 'shared', __( 'Shared screener', 'blockticker' ), '' );
                }
            }
        }
        return array( self::get_default_criteria(), 'default', __( 'Default screener', 'blockticker' ), '' );
    }

    public static function build_share_url( $criteria ) {
        $criteria = self::normalize_criteria( $criteria );
        $b64 = base64_encode( wp_json_encode( $criteria ) );
        return add_query_arg( 'criteria', $b64, home_url( '/screeners/' ) );
    }

    /* ================================================================
     *  Public shortcodes
     * ================================================================ */

    public static function sc_screeners( $atts = array() ) {
        // Resolve which criteria to render initially.
        list( $criteria, $source, $title, $active_id ) = self::resolve_request_criteria();
        $coins = self::run_screener( $criteria );

        ob_start();
        ?>
        <div class="bt-screeners"
             data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
             data-ajax-nonce="<?php echo esc_attr( wp_create_nonce( 'bt_screener' ) ); ?>"
             data-is-logged-in="<?php echo is_user_logged_in() ? '1' : '0'; ?>"
             data-active-id="<?php echo esc_attr( $active_id ); ?>"
             data-source="<?php echo esc_attr( $source ); ?>">

            <?php self::render_saved_list(); ?>

            <?php self::render_builder( $criteria, $title ); ?>

            <?php self::render_results( $coins, $criteria, $source ); ?>

            <?php self::render_client_script( $criteria ); ?>
        </div>
        <?php
        return ob_get_clean();
    }

    private static function render_saved_list() {
        if ( ! is_user_logged_in() ) {
            ?>
            <div class="bt-scr-anon-note">
                <span aria-hidden="true">&#x1F4A1;</span>
                <span><?php esc_html_e( 'Sign in to save screeners across devices. Anonymous saves are kept in this browser only.', 'blockticker' ); ?></span>
                <button type="button" class="bt-scr-anon-cta" data-bt-open-auth="login"><?php esc_html_e( 'Sign in', 'blockticker' ); ?></button>
            </div>
            <?php
            return;
        }
        $screeners = self::get_user_screeners();
        $max = self::get_max_per_user();
        ?>
        <section class="bt-scr-saved" aria-label="<?php esc_attr_e( 'Saved screeners', 'blockticker' ); ?>">
            <header class="bt-scr-saved-head">
                <h2><?php esc_html_e( 'Your saved screeners', 'blockticker' ); ?></h2>
                <span class="bt-scr-saved-count"><?php
                    printf( esc_html__( '%1$d / %2$d', 'blockticker' ), count( $screeners ), $max );
                ?></span>
            </header>
            <?php if ( empty( $screeners ) ) : ?>
                <p class="bt-scr-saved-empty"><?php esc_html_e( 'No screeners saved yet. Configure filters below and hit Save.', 'blockticker' ); ?></p>
            <?php else : ?>
                <ul class="bt-scr-saved-list" id="bt-scr-saved-list">
                    <?php foreach ( $screeners as $s ) : ?>
                        <li class="bt-scr-saved-item" data-screener-id="<?php echo esc_attr( $s['id'] ); ?>">
                            <a class="bt-scr-saved-name"
                               href="<?php echo esc_url( add_query_arg( 'run', $s['id'], home_url( '/screeners/' ) ) ); ?>">
                                <?php echo esc_html( $s['name'] ); ?>
                            </a>
                            <span class="bt-scr-saved-meta">
                                <?php echo esc_html( self::criteria_summary( $s['criteria'] ) ); ?>
                            </span>
                            <button type="button" class="bt-scr-saved-del"
                                    data-screener-id="<?php echo esc_attr( $s['id'] ); ?>"
                                    aria-label="<?php
                                        printf( esc_attr__( 'Delete screener: %s', 'blockticker' ), esc_attr( $s['name'] ) );
                                    ?>">&times;</button>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
        <?php
    }

    private static function render_builder( $criteria, $title ) {
        $cats = self::get_categories();
        ?>
        <section class="bt-scr-builder" aria-label="<?php esc_attr_e( 'Screener builder', 'blockticker' ); ?>">
            <header class="bt-scr-builder-head">
                <h2><?php esc_html_e( 'Build your screener', 'blockticker' ); ?></h2>
                <span class="bt-scr-builder-active"><?php echo esc_html( $title ); ?></span>
            </header>

            <div class="bt-scr-fields">
                <div class="bt-scr-field">
                    <label for="bt-scr-sort-field"><?php esc_html_e( 'Sort by', 'blockticker' ); ?></label>
                    <select id="bt-scr-sort-field" data-criterion="sort_field">
                        <?php
                        $sort_labels = array(
                            'market_cap'                  => __( 'Market cap', 'blockticker' ),
                            'market_cap_rank'             => __( 'Mcap rank', 'blockticker' ),
                            'current_price'               => __( 'Price', 'blockticker' ),
                            'price_change_percentage_24h' => __( '24h % change', 'blockticker' ),
                            'price_change_percentage_7d'  => __( '7d % change', 'blockticker' ),
                            'total_volume'                => __( '24h volume', 'blockticker' ),
                            'name'                        => __( 'Name (A-Z)', 'blockticker' ),
                        );
                        foreach ( $sort_labels as $val => $lbl ) {
                            printf(
                                '<option value="%s"%s>%s</option>',
                                esc_attr( $val ),
                                selected( $criteria['sort_field'], $val, false ),
                                esc_html( $lbl )
                            );
                        }
                        ?>
                    </select>
                </div>

                <div class="bt-scr-field">
                    <label for="bt-scr-sort-dir"><?php esc_html_e( 'Direction', 'blockticker' ); ?></label>
                    <select id="bt-scr-sort-dir" data-criterion="sort_dir">
                        <option value="desc" <?php selected( $criteria['sort_dir'], 'desc' ); ?>><?php esc_html_e( 'Descending', 'blockticker' ); ?></option>
                        <option value="asc"  <?php selected( $criteria['sort_dir'], 'asc' ); ?>><?php esc_html_e( 'Ascending', 'blockticker' ); ?></option>
                    </select>
                </div>

                <div class="bt-scr-field">
                    <label for="bt-scr-category"><?php esc_html_e( 'Category', 'blockticker' ); ?></label>
                    <select id="bt-scr-category" data-criterion="category">
                        <?php foreach ( $cats as $c ) : ?>
                            <option value="<?php echo esc_attr( $c ); ?>" <?php selected( $criteria['category'], $c ); ?>>
                                <?php echo esc_html( ucwords( str_replace( '-', ' ', $c ) ) ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="bt-scr-field">
                    <label for="bt-scr-mcap-min"><?php esc_html_e( 'Min mcap (USD)', 'blockticker' ); ?></label>
                    <input type="number" id="bt-scr-mcap-min" data-criterion="mcap_min"
                        value="<?php echo $criteria['mcap_min'] !== null ? esc_attr( $criteria['mcap_min'] ) : ''; ?>"
                        placeholder="<?php esc_attr_e( 'no minimum', 'blockticker' ); ?>" min="0" step="any">
                </div>

                <div class="bt-scr-field">
                    <label for="bt-scr-mcap-max"><?php esc_html_e( 'Max mcap (USD)', 'blockticker' ); ?></label>
                    <input type="number" id="bt-scr-mcap-max" data-criterion="mcap_max"
                        value="<?php echo $criteria['mcap_max'] !== null ? esc_attr( $criteria['mcap_max'] ) : ''; ?>"
                        placeholder="<?php esc_attr_e( 'no maximum', 'blockticker' ); ?>" min="0" step="any">
                </div>

                <div class="bt-scr-field">
                    <label for="bt-scr-chg-min"><?php esc_html_e( '24h % min', 'blockticker' ); ?></label>
                    <input type="number" id="bt-scr-chg-min" data-criterion="change_24h_min"
                        value="<?php echo $criteria['change_24h_min'] !== null ? esc_attr( $criteria['change_24h_min'] ) : ''; ?>"
                        placeholder="-100" step="0.1">
                </div>

                <div class="bt-scr-field">
                    <label for="bt-scr-chg-max"><?php esc_html_e( '24h % max', 'blockticker' ); ?></label>
                    <input type="number" id="bt-scr-chg-max" data-criterion="change_24h_max"
                        value="<?php echo $criteria['change_24h_max'] !== null ? esc_attr( $criteria['change_24h_max'] ) : ''; ?>"
                        placeholder="+1000" step="0.1">
                </div>

                <div class="bt-scr-field">
                    <label for="bt-scr-vol-min"><?php esc_html_e( 'Min 24h volume (USD)', 'blockticker' ); ?></label>
                    <input type="number" id="bt-scr-vol-min" data-criterion="volume_min"
                        value="<?php echo $criteria['volume_min'] !== null ? esc_attr( $criteria['volume_min'] ) : ''; ?>"
                        placeholder="<?php esc_attr_e( 'no minimum', 'blockticker' ); ?>" min="0" step="any">
                </div>

                <div class="bt-scr-field">
                    <label for="bt-scr-limit"><?php esc_html_e( 'Show top', 'blockticker' ); ?></label>
                    <select id="bt-scr-limit" data-criterion="limit">
                        <?php foreach ( array( 5, 10, 20, 30, 50 ) as $n ) : ?>
                            <option value="<?php echo (int) $n; ?>" <?php selected( $criteria['limit'], $n ); ?>><?php echo (int) $n; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="bt-scr-actions">
                <button type="button" class="bt-scr-btn bt-scr-btn-primary" id="bt-scr-run">
                    <?php esc_html_e( 'Run screener', 'blockticker' ); ?>
                </button>
                <button type="button" class="bt-scr-btn" id="bt-scr-save"
                        <?php echo is_user_logged_in() ? '' : 'disabled title="' . esc_attr__( 'Sign in to save', 'blockticker' ) . '"'; ?>>
                    <?php esc_html_e( 'Save as new…', 'blockticker' ); ?>
                </button>
                <button type="button" class="bt-scr-btn" id="bt-scr-share">
                    <?php esc_html_e( 'Copy share link', 'blockticker' ); ?>
                </button>
                <button type="button" class="bt-scr-btn bt-scr-btn-ghost" id="bt-scr-reset">
                    <?php esc_html_e( 'Reset', 'blockticker' ); ?>
                </button>
            </div>
        </section>
        <?php
    }

    private static function render_results( $coins, $criteria, $source ) {
        ?>
        <section class="bt-scr-results" id="bt-scr-results"
                 aria-live="polite"
                 aria-label="<?php esc_attr_e( 'Screener results', 'blockticker' ); ?>">
            <header class="bt-scr-results-head">
                <h2>
                    <?php
                    printf(
                        /* translators: %d: result count */
                        esc_html__( 'Results (%d)', 'blockticker' ),
                        count( $coins )
                    );
                    ?>
                </h2>
                <span class="bt-scr-results-summary"><?php echo esc_html( self::criteria_summary( $criteria ) ); ?></span>
            </header>
            <?php echo self::render_results_table( $coins ); // safe: built below ?>
        </section>
        <?php
    }

    /**
     * Pure-data result table. Re-rendered server-side via AJAX or inline on
     * initial load; both paths use this same renderer for consistency.
     */
    public static function render_results_table( $coins ) {
        if ( empty( $coins ) ) {
            return '<div class="bt-scr-empty">' .
                esc_html__( 'No coins matched the current criteria. Try widening the ranges or changing the category.', 'blockticker' ) .
                '</div>';
        }
        ob_start();
        ?>
        <table class="bt-scr-table">
            <thead>
                <tr>
                    <th class="bt-scr-th-rank">#</th>
                    <th><?php esc_html_e( 'Asset', 'blockticker' ); ?></th>
                    <th class="bt-scr-th-num"><?php esc_html_e( 'Price', 'blockticker' ); ?></th>
                    <th class="bt-scr-th-num"><?php esc_html_e( '24h %', 'blockticker' ); ?></th>
                    <th class="bt-scr-th-num bt-scr-hide-mobile"><?php esc_html_e( '7d %', 'blockticker' ); ?></th>
                    <th class="bt-scr-th-num"><?php esc_html_e( 'Mcap', 'blockticker' ); ?></th>
                    <th class="bt-scr-th-num bt-scr-hide-mobile"><?php esc_html_e( 'Volume', 'blockticker' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $coins as $i => $c ) :
                    $name   = $c['name']   ?? '';
                    $symbol = strtoupper( $c['symbol'] ?? '' );
                    $img    = $c['image']  ?? '';
                    $price  = floatval( $c['current_price'] ?? 0 );
                    $chg24  = floatval( $c['price_change_percentage_24h'] ?? 0 );
                    $chg7   = floatval( $c['price_change_percentage_7d_in_currency'] ?? $c['price_change_percentage_7d'] ?? 0 );
                    $mcap   = floatval( $c['market_cap'] ?? 0 );
                    $vol    = floatval( $c['total_volume'] ?? 0 );
                    $slug   = $c['id'] ?? '';
                    $cls24  = $chg24 >= 0 ? 'bt-scr-pos' : 'bt-scr-neg';
                    $cls7   = $chg7  >= 0 ? 'bt-scr-pos' : 'bt-scr-neg';
                ?>
                    <tr>
                        <td class="bt-scr-td-rank"><?php echo (int) ( $i + 1 ); ?></td>
                        <td>
                            <a class="bt-scr-asset" href="<?php echo esc_url( home_url( '/crypto/' . $slug . '/' ) ); ?>">
                                <?php if ( $img ) : ?>
                                    <img src="<?php echo esc_url( $img ); ?>" alt="" loading="lazy" decoding="async" width="20" height="20">
                                <?php endif; ?>
                                <span class="bt-scr-asset-name"><?php echo esc_html( $name ); ?></span>
                                <span class="bt-scr-asset-sym"><?php echo esc_html( $symbol ); ?></span>
                            </a>
                        </td>
                        <td class="bt-scr-td-num"><?php echo esc_html( self::fmt_price( $price ) ); ?></td>
                        <td class="bt-scr-td-num <?php echo esc_attr( $cls24 ); ?>"><?php echo esc_html( self::fmt_pct( $chg24 ) ); ?></td>
                        <td class="bt-scr-td-num bt-scr-hide-mobile <?php echo esc_attr( $cls7 ); ?>"><?php echo esc_html( self::fmt_pct( $chg7 ) ); ?></td>
                        <td class="bt-scr-td-num"><?php echo esc_html( self::fmt_large( $mcap ) ); ?></td>
                        <td class="bt-scr-td-num bt-scr-hide-mobile"><?php echo esc_html( self::fmt_large( $vol ) ); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
        return ob_get_clean();
    }

    public static function sc_summary_card( $atts = array() ) {
        $a = shortcode_atts( array(
            'limit' => 3,
            'cta'   => '/screeners/',
        ), $atts, 'bt_screeners_summary_card' );

        $limit = max( 1, min( 10, (int) $a['limit'] ) );

        if ( ! is_user_logged_in() ) {
            ob_start();
            ?>
            <div class="bt-scr-card bt-scr-card-anon">
                <div class="bt-scr-card-head">
                    <span class="bt-scr-card-eyebrow"><?php esc_html_e( 'SCREENERS', 'blockticker' ); ?></span>
                </div>
                <p class="bt-scr-card-blurb"><?php esc_html_e( 'Save filter combinations to quickly re-check market conditions you care about.', 'blockticker' ); ?></p>
                <a href="<?php echo esc_url( $a['cta'] ); ?>" class="bt-scr-card-cta"><?php esc_html_e( 'Try the screener →', 'blockticker' ); ?></a>
            </div>
            <?php
            return ob_get_clean();
        }

        $screeners = array_slice( self::get_user_screeners(), 0, $limit );
        ob_start();
        ?>
        <div class="bt-scr-card">
            <div class="bt-scr-card-head">
                <span class="bt-scr-card-eyebrow"><?php esc_html_e( 'YOUR SCREENERS', 'blockticker' ); ?></span>
                <span class="bt-scr-card-count"><?php echo count( self::get_user_screeners() ); ?></span>
            </div>
            <?php if ( empty( $screeners ) ) : ?>
                <p class="bt-scr-card-blurb"><?php esc_html_e( 'No screeners saved yet. Build one to track specific market conditions.', 'blockticker' ); ?></p>
            <?php else : ?>
                <ul class="bt-scr-card-list">
                    <?php foreach ( $screeners as $s ) : ?>
                        <li>
                            <a href="<?php echo esc_url( add_query_arg( 'run', $s['id'], home_url( '/screeners/' ) ) ); ?>">
                                <strong><?php echo esc_html( $s['name'] ); ?></strong>
                                <span><?php echo esc_html( self::criteria_summary( $s['criteria'] ) ); ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <a href="<?php echo esc_url( $a['cta'] ); ?>" class="bt-scr-card-cta"><?php esc_html_e( 'Open screener →', 'blockticker' ); ?></a>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ================================================================
     *  Client script
     * ================================================================ */

    private static function render_client_script( $criteria ) {
        ?>
        <script>
        (function () {
            'use strict';
            var root = document.querySelector('.bt-screeners');
            if (!root) return;
            var ajaxUrl    = root.getAttribute('data-ajax-url');
            var nonce      = root.getAttribute('data-ajax-nonce');
            var isLoggedIn = root.getAttribute('data-is-logged-in') === '1';
            var STORAGE    = 'bt_user_screeners_anon';

            // ── Read criteria from the form fields ────────────────────
            function readCriteria() {
                var c = {};
                root.querySelectorAll('[data-criterion]').forEach(function (el) {
                    var k = el.getAttribute('data-criterion');
                    var v = el.value;
                    if (v === '' || v === null) {
                        c[k] = null;
                    } else if (el.type === 'number') {
                        c[k] = (k === 'limit') ? parseInt(v, 10) : parseFloat(v);
                    } else {
                        c[k] = v;
                    }
                });
                return c;
            }

            // ── Run / re-render via AJAX ──────────────────────────────
            var runBtn = document.getElementById('bt-scr-run');
            if (runBtn) runBtn.addEventListener('click', function () {
                var criteria = readCriteria();
                var fd = new FormData();
                fd.append('action', 'bt_screener_run');
                fd.append('_ajax_nonce', nonce);
                fd.append('criteria', JSON.stringify(criteria));
                fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (j) {
                        if (j && j.success && j.data && typeof j.data.html === 'string') {
                            var box = document.getElementById('bt-scr-results');
                            if (box) {
                                // Replace contents below the header so the live region
                                // announces "Results (N)" updates cleanly.
                                box.innerHTML = j.data.header + j.data.html;
                            }
                        }
                    })
                    .catch(function () { /* swallow */ });
            });

            // ── Save (logged-in only) ─────────────────────────────────
            var saveBtn = document.getElementById('bt-scr-save');
            if (saveBtn && !saveBtn.disabled) saveBtn.addEventListener('click', function () {
                var name = window.prompt('<?php echo esc_js( __( 'Name this screener:', 'blockticker' ) ); ?>', '');
                if (!name) return;
                name = String(name).trim().slice(0, 60);
                if (!name) return;

                var criteria = readCriteria();
                var fd = new FormData();
                fd.append('action', 'bt_screener_save');
                fd.append('_ajax_nonce', nonce);
                fd.append('name', name);
                fd.append('criteria', JSON.stringify(criteria));
                fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (j) {
                        if (j && j.success) {
                            location.assign(location.pathname + '?run=' + encodeURIComponent(j.data.id));
                        } else {
                            window.alert((j && j.data) ? j.data : '<?php echo esc_js( __( 'Save failed.', 'blockticker' ) ); ?>');
                        }
                    });
            });

            // ── Delete (event-delegated on the list) ──────────────────
            var savedList = document.getElementById('bt-scr-saved-list');
            if (savedList) savedList.addEventListener('click', function (ev) {
                var btn = ev.target.closest('.bt-scr-saved-del');
                if (!btn) return;
                var id = btn.getAttribute('data-screener-id');
                if (!id) return;
                if (!window.confirm('<?php echo esc_js( __( 'Delete this screener?', 'blockticker' ) ); ?>')) return;

                var fd = new FormData();
                fd.append('action', 'bt_screener_delete');
                fd.append('_ajax_nonce', nonce);
                fd.append('id', id);
                fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (j) {
                        if (j && j.success) {
                            var li = btn.closest('.bt-scr-saved-item');
                            if (li) li.remove();
                            // If we just deleted the active screener, navigate home
                            if (root.getAttribute('data-active-id') === id) {
                                location.assign(location.pathname);
                            }
                        }
                    });
            });

            // ── Share link ────────────────────────────────────────────
            var shareBtn = document.getElementById('bt-scr-share');
            if (shareBtn) shareBtn.addEventListener('click', function () {
                var c = readCriteria();
                var b64 = btoa(JSON.stringify(c));
                var url = location.origin + location.pathname + '?criteria=' + encodeURIComponent(b64);
                var copy = function (txt) {
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        return navigator.clipboard.writeText(txt);
                    }
                    var ta = document.createElement('textarea');
                    ta.value = txt; document.body.appendChild(ta);
                    ta.select(); document.execCommand('copy'); document.body.removeChild(ta);
                    return Promise.resolve();
                };
                copy(url).then(function () {
                    shareBtn.textContent = '<?php echo esc_js( __( 'Copied!', 'blockticker' ) ); ?>';
                    setTimeout(function () { shareBtn.textContent = '<?php echo esc_js( __( 'Copy share link', 'blockticker' ) ); ?>'; }, 2000);
                });
            });

            // ── Reset ─────────────────────────────────────────────────
            var resetBtn = document.getElementById('bt-scr-reset');
            if (resetBtn) resetBtn.addEventListener('click', function () {
                location.assign(location.pathname);
            });
        })();
        </script>
        <?php
    }

    /* ================================================================
     *  AJAX handlers
     * ================================================================ */

    public static function ajax_save() {
        check_ajax_referer( 'bt_screener', '_ajax_nonce' );
        if ( ! is_user_logged_in() ) wp_send_json_error( __( 'Sign in to save screeners.', 'blockticker' ), 401 );

        $name     = isset( $_POST['name'] )     ? wp_unslash( $_POST['name'] ) : '';
        $criteria_raw = isset( $_POST['criteria'] ) ? wp_unslash( $_POST['criteria'] ) : '';
        $criteria = json_decode( $criteria_raw, true );
        if ( ! is_array( $criteria ) ) wp_send_json_error( __( 'Bad criteria payload.', 'blockticker' ), 400 );

        $existing = self::get_user_screeners();
        if ( count( $existing ) >= self::get_max_per_user() ) {
            wp_send_json_error( sprintf(
                /* translators: %d: max count */
                __( 'Maximum of %d screeners reached. Delete one first.', 'blockticker' ),
                self::get_max_per_user()
            ), 400 );
        }

        $ok = self::save_user_screener( get_current_user_id(), $name, $criteria, '' );
        if ( ! $ok ) wp_send_json_error( __( 'Save failed — name is required and must be 60 chars or less.', 'blockticker' ), 400 );

        // Return the new id (last entry).
        $all = self::get_user_screeners();
        $last = end( $all );
        wp_send_json_success( array( 'id' => $last['id'] ) );
    }

    public static function ajax_delete() {
        check_ajax_referer( 'bt_screener', '_ajax_nonce' );
        if ( ! is_user_logged_in() ) wp_send_json_error( __( 'Sign in required.', 'blockticker' ), 401 );

        $id = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
        if ( $id === '' ) wp_send_json_error( __( 'Missing id.', 'blockticker' ), 400 );

        $ok = self::delete_user_screener( get_current_user_id(), $id );
        if ( ! $ok ) wp_send_json_error( __( 'Delete failed.', 'blockticker' ), 400 );
        wp_send_json_success();
    }

    public static function ajax_run() {
        check_ajax_referer( 'bt_screener', '_ajax_nonce' );
        $criteria_raw = isset( $_POST['criteria'] ) ? wp_unslash( $_POST['criteria'] ) : '';
        $criteria     = json_decode( $criteria_raw, true );
        if ( ! is_array( $criteria ) ) $criteria = array();
        $criteria = self::normalize_criteria( $criteria );

        $coins = self::run_screener( $criteria );
        $count = count( $coins );

        $header = '<header class="bt-scr-results-head">' .
            '<h2>' . esc_html( sprintf( __( 'Results (%d)', 'blockticker' ), $count ) ) . '</h2>' .
            '<span class="bt-scr-results-summary">' . esc_html( self::criteria_summary( $criteria ) ) . '</span>' .
            '</header>';

        wp_send_json_success( array(
            'count'  => $count,
            'header' => $header,
            'html'   => self::render_results_table( $coins ),
        ) );
    }

    /* ================================================================
     *  Dashboard widget registration (v119.15 composition)
     * ================================================================ */

    public static function register_dashboard_widget( $registry ) {
        if ( ! is_array( $registry ) ) return $registry;
        $registry['screeners_summary'] = array(
            'id'            => 'screeners_summary',
            'title'         => __( 'Saved Screeners', 'blockticker' ),
            'icon'          => '&#x1F50E;', // 🔎
            'shortcode'     => 'bt_screeners_summary_card',
            'atts'          => array(),
            'width'         => 'half',
            'require_login' => false, // anon gets the upsell variant
        );
        return $registry;
    }

    /* ================================================================
     *  Formatting helpers — kept private to the screener namespace
     *  (BT_Utils has format_large but we want consistent in-class format)
     * ================================================================ */

    private static function fmt_price( $n ) {
        if ( $n >= 1 ) return '$' . number_format( $n, 2 );
        if ( $n >= 0.01 ) return '$' . number_format( $n, 4 );
        return '$' . number_format( $n, 8 );
    }

    private static function fmt_pct( $n ) {
        $sign = $n >= 0 ? '+' : '';
        return $sign . number_format( $n, 2 ) . '%';
    }

    private static function fmt_large( $n ) {
        if ( $n >= 1e12 ) return '$' . number_format( $n / 1e12, 2 ) . 'T';
        if ( $n >= 1e9 )  return '$' . number_format( $n / 1e9, 2 ) . 'B';
        if ( $n >= 1e6 )  return '$' . number_format( $n / 1e6, 2 ) . 'M';
        if ( $n >= 1e3 )  return '$' . number_format( $n / 1e3, 2 ) . 'K';
        return '$' . number_format( $n, 2 );
    }

    /** Compact one-line summary of criteria — for the saved-list and result header. */
    public static function criteria_summary( $c ) {
        $c    = self::normalize_criteria( $c );
        $bits = array();

        $cat_label = ucwords( str_replace( '-', ' ', $c['category'] ) );
        if ( $c['category'] !== 'all' ) $bits[] = $cat_label;

        $sort_labels = array(
            'market_cap'                  => 'mcap',
            'market_cap_rank'             => 'rank',
            'current_price'               => 'price',
            'price_change_percentage_24h' => '24h%',
            'price_change_percentage_7d'  => '7d%',
            'total_volume'                => 'volume',
            'name'                        => 'name',
        );
        $arrow = $c['sort_dir'] === 'asc' ? '↑' : '↓';
        $bits[] = ( $sort_labels[ $c['sort_field'] ] ?? $c['sort_field'] ) . ' ' . $arrow;

        if ( $c['mcap_min'] !== null ) $bits[] = 'mcap ≥ ' . self::fmt_large( $c['mcap_min'] );
        if ( $c['mcap_max'] !== null ) $bits[] = 'mcap ≤ ' . self::fmt_large( $c['mcap_max'] );
        if ( $c['change_24h_min'] !== null ) $bits[] = '24h ≥ ' . self::fmt_pct( $c['change_24h_min'] );
        if ( $c['change_24h_max'] !== null ) $bits[] = '24h ≤ ' . self::fmt_pct( $c['change_24h_max'] );
        if ( $c['volume_min'] !== null ) $bits[] = 'vol ≥ ' . self::fmt_large( $c['volume_min'] );
        $bits[] = 'top ' . (int) $c['limit'];

        return implode( ' · ', $bits );
    }

    /* ================================================================
     *  Admin overview
     * ================================================================ */

    public static function register_admin_menu() {
        add_submenu_page(
            'fxlm-wizard',
            __( 'Screeners', 'blockticker' ),
            __( 'Screeners', 'blockticker' ),
            'manage_options',
            'bt-screeners',
            array( __CLASS__, 'render_admin_page' )
        );
    }

    public static function render_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s",
            self::META_KEY
        ) );

        $total_users     = 0;
        $total_screeners = 0;
        $cat_counts      = array();
        $sort_counts     = array();

        foreach ( (array) $rows as $row ) {
            $list = json_decode( $row->meta_value, true );
            if ( ! is_array( $list ) ) continue;
            $total_users++;
            foreach ( $list as $s ) {
                $total_screeners++;
                $cat = $s['criteria']['category'] ?? 'all';
                $sf  = $s['criteria']['sort_field'] ?? 'market_cap';
                $cat_counts[ $cat ]  = ( $cat_counts[ $cat ]  ?? 0 ) + 1;
                $sort_counts[ $sf ]  = ( $sort_counts[ $sf ]  ?? 0 ) + 1;
            }
        }
        arsort( $cat_counts );
        arsort( $sort_counts );

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'BlockTicker — Screeners', 'blockticker' ); ?></h1>
            <p class="description">
                <?php esc_html_e( 'Saved-screener subsystem. Read-only operator overview — aggregated criteria patterns from all users (no per-user data displayed).', 'blockticker' ); ?>
                <a href="<?php echo esc_url( home_url( '/screeners/' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View public page →', 'blockticker' ); ?></a>
            </p>

            <h2><?php esc_html_e( 'Adoption', 'blockticker' ); ?></h2>
            <table class="widefat striped" style="max-width:600px">
                <tbody>
                    <tr><th><?php esc_html_e( 'Users with saved screeners', 'blockticker' ); ?></th><td><strong><?php echo esc_html( number_format( $total_users ) ); ?></strong></td></tr>
                    <tr><th><?php esc_html_e( 'Total saved screeners', 'blockticker' ); ?></th><td><strong><?php echo esc_html( number_format( $total_screeners ) ); ?></strong></td></tr>
                    <tr><th><?php esc_html_e( 'Avg per user', 'blockticker' ); ?></th><td><?php echo esc_html( $total_users > 0 ? number_format( $total_screeners / $total_users, 1 ) : '—' ); ?></td></tr>
                    <tr><th><?php esc_html_e( 'Cap per user', 'blockticker' ); ?></th><td><?php echo esc_html( self::get_max_per_user() ); ?> <code>bt_screener_max_per_user</code></td></tr>
                </tbody>
            </table>

            <h2><?php esc_html_e( 'Top categories used', 'blockticker' ); ?></h2>
            <table class="widefat striped" style="max-width:600px">
                <thead>
                    <tr><th><?php esc_html_e( 'Category', 'blockticker' ); ?></th><th><?php esc_html_e( 'Saved screeners', 'blockticker' ); ?></th></tr>
                </thead>
                <tbody>
                    <?php if ( empty( $cat_counts ) ) : ?>
                        <tr><td colspan="2"><?php esc_html_e( 'No data yet.', 'blockticker' ); ?></td></tr>
                    <?php else : foreach ( $cat_counts as $cat => $n ) : ?>
                        <tr><td><code><?php echo esc_html( $cat ); ?></code></td><td><?php echo esc_html( number_format( $n ) ); ?></td></tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>

            <h2><?php esc_html_e( 'Top sort fields', 'blockticker' ); ?></h2>
            <table class="widefat striped" style="max-width:600px">
                <thead>
                    <tr><th><?php esc_html_e( 'Sort field', 'blockticker' ); ?></th><th><?php esc_html_e( 'Saved screeners', 'blockticker' ); ?></th></tr>
                </thead>
                <tbody>
                    <?php if ( empty( $sort_counts ) ) : ?>
                        <tr><td colspan="2"><?php esc_html_e( 'No data yet.', 'blockticker' ); ?></td></tr>
                    <?php else : foreach ( $sort_counts as $sf => $n ) : ?>
                        <tr><td><code><?php echo esc_html( $sf ); ?></code></td><td><?php echo esc_html( number_format( $n ) ); ?></td></tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}

BT_Screeners::setup();
