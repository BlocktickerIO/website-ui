<?php
/**
 * BlockTicker — Following List subsystem
 *
 * Three follow types — assets, news sources, signal sources — captured into
 * one user-meta record and used to filter the news + signals feeds users are
 * already looking at. The point of "follows" is the integration, not the list:
 * a follow without a payoff is just a checkbox.
 *
 * Persistence model — mirrors v119.15/v119.16 pattern
 *   - Logged-in:  user meta `bt_user_following` (single JSON record with three sub-arrays)
 *   - Anonymous:  localStorage (client-side mirror of the same shape)
 *
 * Stored shape:
 *   {
 *     "assets":  [ "bitcoin", "ethereum", … ],          // CoinGecko slug
 *     "sources": [ "CoinDesk", "FXStreet", … ],         // matches news_items.source
 *     "signals": [ "DailyFX", "FXStreet", … ]           // matches bt_signal_items[*].source
 *   }
 *
 * Caps: 50 assets, 30 sources, 30 signal sources (all filterable)
 *
 * Public surface
 *   /following/                          — full management page
 *   [bt_following]                       — drop-in for the page above
 *   [bt_follow_button type="…" id="…"]   — drop-in toggle for any entity
 *   [bt_personalized_news]               — news feed filtered to follows
 *   [bt_following_summary_card]          — dashboard widget
 *   BlockTicker → Following (admin)      — operator overview
 *
 * AJAX
 *   wp_ajax_bt_follow_toggle             — flip a single entry on/off
 *
 * Filter hooks
 *   bt_following_max_per_type            — array of caps per type
 *   bt_following_default_sources         — discoverable news sources for the picker
 *   bt_following_default_signal_sources  — discoverable signal sources
 *
 * @since v119.17.0
 * @package BlockTicker
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BT_Following {

    const META_KEY = 'bt_user_following';
    const NONCE_ACTION = 'bt_following';

    const TYPE_ASSET   = 'asset';
    const TYPE_SOURCE  = 'source';
    const TYPE_SIGNAL  = 'signal';

    /** Default caps per type. */
    private static $default_caps = array(
        'asset'  => 50,
        'source' => 30,
        'signal' => 30,
    );

    public static function setup() {
        add_shortcode( 'bt_following',                array( __CLASS__, 'sc_following' ) );
        add_shortcode( 'bt_follow_button',            array( __CLASS__, 'sc_follow_button' ) );
        add_shortcode( 'bt_personalized_news',        array( __CLASS__, 'sc_personalized_news' ) );
        add_shortcode( 'bt_following_summary_card',   array( __CLASS__, 'sc_summary_card' ) );

        add_action( 'wp_ajax_bt_follow_toggle',        array( __CLASS__, 'ajax_toggle' ) );
        add_action( 'wp_ajax_nopriv_bt_follow_toggle', array( __CLASS__, 'ajax_toggle_anon' ) );

        add_action( 'admin_menu', array( __CLASS__, 'register_admin_menu' ), 20 );

        // Auto-register dashboard widgets via v119.15 filter — same composition
        // pattern as v119.16 screeners.
        add_filter( 'bt_dashboard_widget_registry', array( __CLASS__, 'register_dashboard_widgets' ) );
    }

    /* ================================================================
     *  Caps & type validation
     * ================================================================ */

    public static function get_caps() {
        $caps = apply_filters( 'bt_following_max_per_type', self::$default_caps );
        if ( ! is_array( $caps ) ) $caps = self::$default_caps;
        // Normalize and clamp.
        $out = self::$default_caps;
        foreach ( $out as $k => $default ) {
            if ( isset( $caps[ $k ] ) && is_numeric( $caps[ $k ] ) ) {
                $out[ $k ] = max( 1, min( 200, (int) $caps[ $k ] ) );
            }
        }
        return $out;
    }

    public static function get_valid_types() {
        return array( self::TYPE_ASSET, self::TYPE_SOURCE, self::TYPE_SIGNAL );
    }

    /** Type → JSON sub-key in stored shape. */
    private static function type_to_key( $type ) {
        switch ( $type ) {
            case self::TYPE_ASSET:  return 'assets';
            case self::TYPE_SOURCE: return 'sources';
            case self::TYPE_SIGNAL: return 'signals';
        }
        return '';
    }

    /* ================================================================
     *  Storage layer — user meta (logged-in)
     * ================================================================ */

    public static function get_following( $user_id = 0 ) {
        if ( $user_id <= 0 ) $user_id = get_current_user_id();
        $blank = array( 'assets' => array(), 'sources' => array(), 'signals' => array() );
        if ( $user_id <= 0 ) return $blank;

        $raw = get_user_meta( $user_id, self::META_KEY, true );
        if ( ! is_string( $raw ) || $raw === '' ) return $blank;
        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) ) return $blank;

        return self::normalize_shape( $decoded );
    }

    /**
     * Returns true if the user is following the given entity.
     * Identifiers are sanitized before comparison so caller doesn't have to.
     */
    public static function is_following( $type, $id, $user_id = 0 ) {
        $key = self::type_to_key( $type );
        if ( $key === '' || $id === '' ) return false;
        $follows = self::get_following( $user_id );
        return in_array( self::sanitize_id( $type, $id ), $follows[ $key ], true );
    }

    /** Returns count for a single type. */
    public static function get_count( $type, $user_id = 0 ) {
        $key = self::type_to_key( $type );
        if ( $key === '' ) return 0;
        $follows = self::get_following( $user_id );
        return count( $follows[ $key ] );
    }

    /**
     * Toggles follow status. Returns ['following' => bool, 'count' => int]
     * on success, or false on failure (cap reached, invalid type, etc.).
     */
    public static function toggle( $type, $id, $user_id = 0 ) {
        if ( $user_id <= 0 ) $user_id = get_current_user_id();
        if ( $user_id <= 0 ) return false;

        $key = self::type_to_key( $type );
        if ( $key === '' ) return false;

        $id = self::sanitize_id( $type, $id );
        if ( $id === '' ) return false;

        $follows = self::get_following( $user_id );
        $caps    = self::get_caps();
        $cap     = $caps[ $type ];

        $idx = array_search( $id, $follows[ $key ], true );
        if ( $idx !== false ) {
            // Unfollow.
            array_splice( $follows[ $key ], $idx, 1 );
            $now_following = false;
        } else {
            // Follow — enforce cap.
            if ( count( $follows[ $key ] ) >= $cap ) return false;
            $follows[ $key ][] = $id;
            $now_following = true;
        }

        update_user_meta( $user_id, self::META_KEY, wp_json_encode( $follows ) );
        return array( 'following' => $now_following, 'count' => count( $follows[ $key ] ), 'cap' => $cap );
    }

    private static function sanitize_id( $type, $id ) {
        $id = trim( (string) $id );
        if ( $id === '' ) return '';
        if ( $type === self::TYPE_ASSET ) {
            // CoinGecko slug shape: lowercase + hyphens
            return preg_replace( '/[^a-z0-9-]/', '', strtolower( $id ) );
        }
        // Source & signal source: human-readable strings (e.g. "CoinDesk", "FXStreet")
        // Keep as-is but bound length and strip control chars.
        $id = wp_strip_all_tags( $id );
        return mb_substr( $id, 0, 80 );
    }

    private static function normalize_shape( $arr ) {
        $blank = array( 'assets' => array(), 'sources' => array(), 'signals' => array() );
        foreach ( array_keys( $blank ) as $k ) {
            if ( isset( $arr[ $k ] ) && is_array( $arr[ $k ] ) ) {
                $clean = array();
                foreach ( $arr[ $k ] as $v ) {
                    $type = ( $k === 'assets' ) ? self::TYPE_ASSET : ( $k === 'sources' ? self::TYPE_SOURCE : self::TYPE_SIGNAL );
                    $sanitized = self::sanitize_id( $type, $v );
                    if ( $sanitized !== '' ) $clean[] = $sanitized;
                }
                $blank[ $k ] = array_values( array_unique( $clean ) );
            }
        }
        return $blank;
    }

    /* ================================================================
     *  Discoverable lists — for source/signal pickers
     * ================================================================ */

    /** Returns the list of news sources discovered from the bt_news_items table. */
    public static function discover_news_sources() {
        global $wpdb;
        $table = $wpdb->prefix . 'bt_news_items';
        // Only count sources active in the last 30 days — avoids surfacing
        // dead feeds in the picker.
        $rows = $wpdb->get_results( "
            SELECT source, COUNT(*) AS n
            FROM {$table}
            WHERE published_at > DATE_SUB( NOW(), INTERVAL 30 DAY )
            GROUP BY source
            ORDER BY n DESC
            LIMIT 60
        " );
        $sources = array();
        foreach ( (array) $rows as $row ) {
            $name = trim( (string) $row->source );
            if ( $name === '' ) continue;
            $sources[] = array( 'name' => $name, 'count' => (int) $row->n );
        }
        // Allow site code to add seed sources.
        $extra = apply_filters( 'bt_following_default_sources', array() );
        if ( is_array( $extra ) ) {
            $existing = array_column( $sources, 'name' );
            foreach ( $extra as $name ) {
                if ( is_string( $name ) && $name !== '' && ! in_array( $name, $existing, true ) ) {
                    $sources[] = array( 'name' => $name, 'count' => 0 );
                }
            }
        }
        return $sources;
    }

    /** Discover signal sources from bt_signal_items option. */
    public static function discover_signal_sources() {
        $items = get_option( 'bt_signal_items', array() );
        if ( ! is_array( $items ) ) $items = array();
        $tally = array();
        foreach ( $items as $item ) {
            if ( ! is_array( $item ) ) continue;
            $src = isset( $item['source'] ) ? trim( (string) $item['source'] ) : '';
            if ( $src === '' ) continue;
            $tally[ $src ] = ( $tally[ $src ] ?? 0 ) + 1;
        }
        arsort( $tally );
        $out = array();
        foreach ( $tally as $name => $n ) {
            $out[] = array( 'name' => $name, 'count' => $n );
        }
        $extra = apply_filters( 'bt_following_default_signal_sources', array() );
        if ( is_array( $extra ) ) {
            $existing = array_column( $out, 'name' );
            foreach ( $extra as $name ) {
                if ( is_string( $name ) && $name !== '' && ! in_array( $name, $existing, true ) ) {
                    $out[] = array( 'name' => $name, 'count' => 0 );
                }
            }
        }
        return $out;
    }

    /** Discover top assets (CoinGecko slugs) from bt_crypto_data. */
    public static function discover_assets() {
        $data = get_option( 'bt_crypto_data', array() );
        if ( is_string( $data ) ) $data = json_decode( $data, true );
        if ( ! is_array( $data ) || empty( $data['coins'] ) ) return array();
        $out = array();
        foreach ( array_slice( $data['coins'], 0, 100 ) as $coin ) {
            if ( empty( $coin['id'] ) ) continue;
            $out[] = array(
                'id'     => self::sanitize_id( self::TYPE_ASSET, $coin['id'] ),
                'name'   => $coin['name']   ?? $coin['id'],
                'symbol' => strtoupper( $coin['symbol'] ?? '' ),
                'image'  => $coin['image']  ?? '',
            );
        }
        return $out;
    }

    /* ================================================================
     *  Personalized news query — the actual payoff for following
     * ================================================================ */

    /**
     * Returns news items filtered by followed assets + sources.
     * Falls through to recent news (no filter) if user follows nothing.
     */
    public static function get_personalized_news( $limit = 10, $user_id = 0 ) {
        global $wpdb;
        $table  = $wpdb->prefix . 'bt_news_items';
        $limit  = max( 1, min( 50, (int) $limit ) );
        $follows = self::get_following( $user_id );

        $assets  = $follows['assets'];
        $sources = $follows['sources'];

        if ( empty( $assets ) && empty( $sources ) ) {
            // No follows — fall through to plain recent news so the widget
            // never renders empty. Better than a "follow stuff" upsell.
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, source, title, url, summary, published_at, symbols_mentioned
                 FROM {$table}
                 ORDER BY published_at DESC
                 LIMIT %d",
                $limit
            ) );
            return array_map( array( __CLASS__, 'normalize_news_row' ), (array) $rows );
        }

        // Build a WHERE clause that ORs the asset filter and source filter.
        $where = array();
        $args  = array();
        if ( ! empty( $sources ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $sources ), '%s' ) );
            $where[] = "source IN ({$placeholders})";
            $args = array_merge( $args, $sources );
        }
        if ( ! empty( $assets ) ) {
            // symbols_mentioned is a comma-or-space-delimited string; we LIKE-match on each
            // followed asset slug. There are typically < 50 assets so the OR-chain is small.
            $like_clauses = array();
            foreach ( $assets as $slug ) {
                $like_clauses[] = "symbols_mentioned LIKE %s";
                $args[] = '%' . $wpdb->esc_like( $slug ) . '%';
            }
            $where[] = '(' . implode( ' OR ', $like_clauses ) . ')';
        }

        $sql = "SELECT id, source, title, url, summary, published_at, symbols_mentioned
                FROM {$table}
                WHERE " . implode( ' OR ', $where ) . "
                ORDER BY published_at DESC
                LIMIT %d";
        $args[] = $limit;

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ) );
        return array_map( array( __CLASS__, 'normalize_news_row' ), (array) $rows );
    }

    private static function normalize_news_row( $row ) {
        if ( ! is_object( $row ) ) return null;
        return array(
            'id'         => (int) $row->id,
            'source'     => (string) $row->source,
            'title'      => (string) $row->title,
            'url'        => (string) $row->url,
            'summary'    => (string) $row->summary,
            'published'  => (string) $row->published_at,
            'symbols'    => (string) $row->symbols_mentioned,
        );
    }

    /* ================================================================
     *  Public shortcodes
     * ================================================================ */

    public static function sc_following() {
        ob_start();
        ?>
        <div class="bt-fol"
             data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
             data-ajax-nonce="<?php echo esc_attr( wp_create_nonce( self::NONCE_ACTION ) ); ?>"
             data-is-logged-in="<?php echo is_user_logged_in() ? '1' : '0'; ?>">

            <?php if ( ! is_user_logged_in() ) : ?>
                <div class="bt-fol-anon-note">
                    <span aria-hidden="true">&#x1F4CC;</span>
                    <span><?php esc_html_e( 'Sign in to save your follows across devices. Anonymous follows are kept in this browser only.', 'blockticker' ); ?></span>
                    <button type="button" class="bt-fol-anon-cta" data-bt-open-auth="login"><?php esc_html_e( 'Sign in', 'blockticker' ); ?></button>
                </div>
            <?php endif; ?>

            <div class="bt-fol-tabs" role="tablist">
                <button type="button" class="bt-fol-tab bt-fol-tab-active" data-tab="assets" role="tab" aria-selected="true">
                    <span aria-hidden="true">&#x1F4B0;</span>
                    <span><?php esc_html_e( 'Assets', 'blockticker' ); ?></span>
                </button>
                <button type="button" class="bt-fol-tab" data-tab="sources" role="tab" aria-selected="false">
                    <span aria-hidden="true">&#x1F4F0;</span>
                    <span><?php esc_html_e( 'News sources', 'blockticker' ); ?></span>
                </button>
                <button type="button" class="bt-fol-tab" data-tab="signals" role="tab" aria-selected="false">
                    <span aria-hidden="true">&#x1F4E1;</span>
                    <span><?php esc_html_e( 'Signal sources', 'blockticker' ); ?></span>
                </button>
            </div>

            <div class="bt-fol-panels">
                <section class="bt-fol-panel bt-fol-panel-active" data-panel="assets">
                    <?php self::render_assets_panel(); ?>
                </section>
                <section class="bt-fol-panel" data-panel="sources" hidden>
                    <?php self::render_sources_panel(); ?>
                </section>
                <section class="bt-fol-panel" data-panel="signals" hidden>
                    <?php self::render_signals_panel(); ?>
                </section>
            </div>

            <?php self::render_client_script(); ?>
        </div>
        <?php
        return ob_get_clean();
    }

    private static function render_assets_panel() {
        $caps     = self::get_caps();
        $follows  = self::get_following();
        $following_ids = $follows['assets'];
        $assets   = self::discover_assets();
        ?>
        <header class="bt-fol-panel-head">
            <h2><?php esc_html_e( 'Followed assets', 'blockticker' ); ?></h2>
            <span class="bt-fol-count">
                <?php printf( esc_html__( '%1$d / %2$d', 'blockticker' ), count( $following_ids ), $caps['asset'] ); ?>
            </span>
        </header>
        <p class="bt-fol-panel-blurb"><?php esc_html_e( 'Assets you follow drive your personalised news feed and dashboard widgets. Pick from the top 100 by market cap below.', 'blockticker' ); ?></p>

        <?php if ( empty( $assets ) ) : ?>
            <p class="bt-fol-empty"><?php esc_html_e( 'Asset list is warming up — check back in a moment.', 'blockticker' ); ?></p>
        <?php else : ?>
            <div class="bt-fol-grid">
                <?php foreach ( $assets as $a ) :
                    $is_following = in_array( $a['id'], $following_ids, true );
                ?>
                    <button type="button"
                            class="bt-fol-card<?php echo $is_following ? ' bt-fol-card-on' : ''; ?>"
                            data-fol-type="asset"
                            data-fol-id="<?php echo esc_attr( $a['id'] ); ?>"
                            aria-pressed="<?php echo $is_following ? 'true' : 'false'; ?>">
                        <?php if ( $a['image'] ) : ?>
                            <img src="<?php echo esc_url( $a['image'] ); ?>" alt="" loading="lazy" decoding="async" width="22" height="22">
                        <?php endif; ?>
                        <span class="bt-fol-card-name"><?php echo esc_html( $a['name'] ); ?></span>
                        <span class="bt-fol-card-sym"><?php echo esc_html( $a['symbol'] ); ?></span>
                        <span class="bt-fol-card-state" aria-hidden="true">
                            <?php echo $is_following ? '&#x2713;' : '+'; ?>
                        </span>
                    </button>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php
    }

    private static function render_sources_panel() {
        $caps    = self::get_caps();
        $follows = self::get_following();
        $following_names = $follows['sources'];
        $sources = self::discover_news_sources();
        ?>
        <header class="bt-fol-panel-head">
            <h2><?php esc_html_e( 'Followed news sources', 'blockticker' ); ?></h2>
            <span class="bt-fol-count">
                <?php printf( esc_html__( '%1$d / %2$d', 'blockticker' ), count( $following_names ), $caps['source'] ); ?>
            </span>
        </header>
        <p class="bt-fol-panel-blurb"><?php esc_html_e( 'Following a source narrows your personalised news feed to publishers you trust. Counts show items published in the last 30 days.', 'blockticker' ); ?></p>

        <?php if ( empty( $sources ) ) : ?>
            <p class="bt-fol-empty"><?php esc_html_e( 'No active news sources in the last 30 days. Check back when the news pipeline catches up.', 'blockticker' ); ?></p>
        <?php else : ?>
            <ul class="bt-fol-list">
                <?php foreach ( $sources as $s ) :
                    $is_following = in_array( $s['name'], $following_names, true );
                ?>
                    <li class="bt-fol-row<?php echo $is_following ? ' bt-fol-row-on' : ''; ?>">
                        <span class="bt-fol-row-name"><?php echo esc_html( $s['name'] ); ?></span>
                        <span class="bt-fol-row-meta"><?php
                            printf( esc_html__( '%d items in 30d', 'blockticker' ), (int) $s['count'] );
                        ?></span>
                        <button type="button"
                                class="bt-fol-row-btn"
                                data-fol-type="source"
                                data-fol-id="<?php echo esc_attr( $s['name'] ); ?>"
                                aria-pressed="<?php echo $is_following ? 'true' : 'false'; ?>">
                            <?php echo $is_following
                                ? esc_html__( 'Following', 'blockticker' )
                                : esc_html__( 'Follow', 'blockticker' ); ?>
                        </button>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php
    }

    private static function render_signals_panel() {
        $caps    = self::get_caps();
        $follows = self::get_following();
        $following_names = $follows['signals'];
        $sources = self::discover_signal_sources();
        ?>
        <header class="bt-fol-panel-head">
            <h2><?php esc_html_e( 'Followed signal sources', 'blockticker' ); ?></h2>
            <span class="bt-fol-count">
                <?php printf( esc_html__( '%1$d / %2$d', 'blockticker' ), count( $following_names ), $caps['signal'] ); ?>
            </span>
        </header>
        <p class="bt-fol-panel-blurb"><?php esc_html_e( 'Following a signal source filters the signals feed to authors whose track record you trust. See /performance/ for verified accuracy by source.', 'blockticker' ); ?></p>

        <?php if ( empty( $sources ) ) : ?>
            <p class="bt-fol-empty"><?php esc_html_e( 'No signal sources discovered yet.', 'blockticker' ); ?></p>
        <?php else : ?>
            <ul class="bt-fol-list">
                <?php foreach ( $sources as $s ) :
                    $is_following = in_array( $s['name'], $following_names, true );
                ?>
                    <li class="bt-fol-row<?php echo $is_following ? ' bt-fol-row-on' : ''; ?>">
                        <span class="bt-fol-row-name"><?php echo esc_html( $s['name'] ); ?></span>
                        <span class="bt-fol-row-meta"><?php
                            printf( esc_html__( '%d recent signals', 'blockticker' ), (int) $s['count'] );
                        ?></span>
                        <button type="button"
                                class="bt-fol-row-btn"
                                data-fol-type="signal"
                                data-fol-id="<?php echo esc_attr( $s['name'] ); ?>"
                                aria-pressed="<?php echo $is_following ? 'true' : 'false'; ?>">
                            <?php echo $is_following
                                ? esc_html__( 'Following', 'blockticker' )
                                : esc_html__( 'Follow', 'blockticker' ); ?>
                        </button>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php
    }

    /**
     * [bt_follow_button type="asset" id="bitcoin"] — drop-in toggle for
     * any asset/source/signal entity. Designed to embed inside existing
     * card templates (e.g. on /crypto/{slug}/) without needing a custom
     * AJAX layer per host.
     */
    public static function sc_follow_button( $atts ) {
        $a = shortcode_atts( array(
            'type'  => 'asset',
            'id'    => '',
            'label' => '',
        ), $atts, 'bt_follow_button' );

        if ( ! in_array( $a['type'], self::get_valid_types(), true ) ) return '';
        $clean_id = self::sanitize_id( $a['type'], $a['id'] );
        if ( $clean_id === '' ) return '';

        $is_following = is_user_logged_in() && self::is_following( $a['type'], $clean_id );
        $on_label  = $a['label'] !== '' ? $a['label'] : __( 'Following', 'blockticker' );
        $off_label = $a['label'] !== '' ? $a['label'] : __( 'Follow', 'blockticker' );

        ob_start();
        ?>
        <button type="button"
                class="bt-fol-btn<?php echo $is_following ? ' bt-fol-btn-on' : ''; ?>"
                data-fol-type="<?php echo esc_attr( $a['type'] ); ?>"
                data-fol-id="<?php echo esc_attr( $clean_id ); ?>"
                data-fol-on-label="<?php echo esc_attr( $on_label ); ?>"
                data-fol-off-label="<?php echo esc_attr( $off_label ); ?>"
                data-fol-nonce="<?php echo esc_attr( wp_create_nonce( self::NONCE_ACTION ) ); ?>"
                aria-pressed="<?php echo $is_following ? 'true' : 'false'; ?>">
            <span class="bt-fol-btn-icon" aria-hidden="true"><?php echo $is_following ? '&#x2605;' : '&#x2606;'; ?></span>
            <span class="bt-fol-btn-label"><?php echo esc_html( $is_following ? $on_label : $off_label ); ?></span>
        </button>
        <script>
        (function () {
            // One-time delegated handler for any [bt_follow_button] on the page.
            if (window.__btFollowWired) return;
            window.__btFollowWired = true;
            document.addEventListener('click', function (ev) {
                var btn = ev.target.closest('.bt-fol-btn');
                if (!btn) return;
                if (btn.dataset.folInflight === '1') return;

                var type  = btn.getAttribute('data-fol-type');
                var id    = btn.getAttribute('data-fol-id');
                var nonce = btn.getAttribute('data-fol-nonce');
                if (!type || !id || !nonce) return;

                btn.dataset.folInflight = '1';
                var fd = new FormData();
                fd.append('action', 'bt_follow_toggle');
                fd.append('_ajax_nonce', nonce);
                fd.append('type', type);
                fd.append('id', id);
                fetch('<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>', {
                    method: 'POST', body: fd, credentials: 'same-origin'
                })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    btn.dataset.folInflight = '';
                    if (!j) return;
                    if (j.success) {
                        var on = !!(j.data && j.data.following);
                        btn.classList.toggle('bt-fol-btn-on', on);
                        btn.setAttribute('aria-pressed', on ? 'true' : 'false');
                        var icon  = btn.querySelector('.bt-fol-btn-icon');
                        var label = btn.querySelector('.bt-fol-btn-label');
                        if (icon)  icon.innerHTML  = on ? '\u2605' : '\u2606';
                        if (label) label.textContent = on
                            ? btn.getAttribute('data-fol-on-label')
                            : btn.getAttribute('data-fol-off-label');
                    } else {
                        if (j.data && typeof j.data === 'string') {
                            // Login required is the most common failure for anon hits.
                            if (j.data.indexOf('Sign in') !== -1 || j.data.indexOf('login') !== -1) {
                                var openAuth = document.querySelector('[data-bt-open-auth]');
                                if (openAuth) openAuth.click();
                            }
                        }
                    }
                })
                .catch(function () { btn.dataset.folInflight = ''; });
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * [bt_personalized_news limit="6"] — news feed filtered to the user's
     * follows. Falls through to recent news if user follows nothing.
     */
    public static function sc_personalized_news( $atts ) {
        $a = shortcode_atts( array( 'limit' => 6, 'show_meta' => 'yes' ), $atts, 'bt_personalized_news' );
        $limit = max( 1, min( 20, (int) $a['limit'] ) );

        $items   = self::get_personalized_news( $limit );
        $follows = self::get_following();
        $has_follows = ( ! empty( $follows['assets'] ) || ! empty( $follows['sources'] ) );

        if ( empty( $items ) ) {
            return '<div class="bt-fol-news-empty">' .
                esc_html__( 'No news matched your follows yet. Try following a few more assets or sources.', 'blockticker' ) .
                '</div>';
        }

        ob_start();
        ?>
        <div class="bt-fol-news">
            <?php if ( ! $has_follows ) : ?>
                <div class="bt-fol-news-hint">
                    <a href="<?php echo esc_url( home_url( '/following/' ) ); ?>"><?php
                        esc_html_e( 'Follow assets and sources to personalise this feed →', 'blockticker' );
                    ?></a>
                </div>
            <?php endif; ?>
            <ul class="bt-fol-news-list">
                <?php foreach ( $items as $item ) :
                    if ( ! $item ) continue;
                    $time = strtotime( $item['published'] );
                    $rel  = $time ? human_time_diff( $time, current_time( 'timestamp' ) ) : '';
                ?>
                    <li class="bt-fol-news-item">
                        <a href="<?php echo esc_url( $item['url'] ); ?>" target="_blank" rel="noopener nofollow">
                            <span class="bt-fol-news-title"><?php echo esc_html( $item['title'] ); ?></span>
                            <?php if ( $a['show_meta'] === 'yes' ) : ?>
                                <span class="bt-fol-news-meta">
                                    <span class="bt-fol-news-source"><?php echo esc_html( $item['source'] ); ?></span>
                                    <?php if ( $rel ) : ?>
                                        <span class="bt-fol-news-time"><?php
                                            /* translators: %s: human-readable relative time */
                                            printf( esc_html__( '%s ago', 'blockticker' ), esc_html( $rel ) );
                                        ?></span>
                                    <?php endif; ?>
                                </span>
                            <?php endif; ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php
        return ob_get_clean();
    }

    /** Dashboard summary widget for the v119.15 registry. */
    public static function sc_summary_card() {
        if ( ! is_user_logged_in() ) {
            ob_start();
            ?>
            <div class="bt-fol-card-summary bt-fol-card-summary-anon">
                <span class="bt-fol-card-eyebrow"><?php esc_html_e( 'FOLLOWING', 'blockticker' ); ?></span>
                <p><?php esc_html_e( 'Follow your favourite assets, news sources and signal authors to filter your news feed and dashboard.', 'blockticker' ); ?></p>
                <a href="<?php echo esc_url( home_url( '/following/' ) ); ?>" class="bt-fol-card-cta"><?php esc_html_e( 'Set up follows →', 'blockticker' ); ?></a>
            </div>
            <?php
            return ob_get_clean();
        }
        $follows = self::get_following();
        $caps    = self::get_caps();
        ob_start();
        ?>
        <div class="bt-fol-card-summary">
            <div class="bt-fol-card-summary-row">
                <span class="bt-fol-card-eyebrow"><?php esc_html_e( 'ASSETS', 'blockticker' ); ?></span>
                <span class="bt-fol-card-num"><?php echo (int) count( $follows['assets'] ); ?></span>
                <span class="bt-fol-card-cap">/ <?php echo (int) $caps['asset']; ?></span>
            </div>
            <div class="bt-fol-card-summary-row">
                <span class="bt-fol-card-eyebrow"><?php esc_html_e( 'NEWS SOURCES', 'blockticker' ); ?></span>
                <span class="bt-fol-card-num"><?php echo (int) count( $follows['sources'] ); ?></span>
                <span class="bt-fol-card-cap">/ <?php echo (int) $caps['source']; ?></span>
            </div>
            <div class="bt-fol-card-summary-row">
                <span class="bt-fol-card-eyebrow"><?php esc_html_e( 'SIGNAL SOURCES', 'blockticker' ); ?></span>
                <span class="bt-fol-card-num"><?php echo (int) count( $follows['signals'] ); ?></span>
                <span class="bt-fol-card-cap">/ <?php echo (int) $caps['signal']; ?></span>
            </div>
            <a href="<?php echo esc_url( home_url( '/following/' ) ); ?>" class="bt-fol-card-cta"><?php esc_html_e( 'Manage follows →', 'blockticker' ); ?></a>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ================================================================
     *  Client script — main /following/ page tabs + grid clicks
     * ================================================================ */

    private static function render_client_script() {
        ?>
        <script>
        (function () {
            'use strict';
            var root = document.querySelector('.bt-fol');
            if (!root) return;
            var ajaxUrl    = root.getAttribute('data-ajax-url');
            var nonce      = root.getAttribute('data-ajax-nonce');
            var isLoggedIn = root.getAttribute('data-is-logged-in') === '1';

            // ── Tab switching ────────────────────────────────────────
            var tabs = root.querySelectorAll('.bt-fol-tab');
            tabs.forEach(function (tab) {
                tab.addEventListener('click', function () {
                    var name = tab.getAttribute('data-tab');
                    tabs.forEach(function (t) {
                        var on = ( t === tab );
                        t.classList.toggle('bt-fol-tab-active', on);
                        t.setAttribute('aria-selected', on ? 'true' : 'false');
                    });
                    root.querySelectorAll('.bt-fol-panel').forEach(function (p) {
                        var on = ( p.getAttribute('data-panel') === name );
                        p.classList.toggle('bt-fol-panel-active', on);
                        if (on) p.removeAttribute('hidden');
                        else p.setAttribute('hidden', '');
                    });
                });
            });

            // ── Toggle handler (asset cards + source rows + signal rows) ─
            root.addEventListener('click', function (ev) {
                var btn = ev.target.closest('[data-fol-type][data-fol-id]');
                if (!btn) return;
                if (btn.classList.contains('bt-fol-btn')) return; // owned by sc_follow_button delegated handler
                if (btn.dataset.folInflight === '1') return;

                if (!isLoggedIn) {
                    var openAuth = document.querySelector('[data-bt-open-auth]');
                    if (openAuth) openAuth.click();
                    return;
                }

                var type = btn.getAttribute('data-fol-type');
                var id   = btn.getAttribute('data-fol-id');
                btn.dataset.folInflight = '1';

                var fd = new FormData();
                fd.append('action', 'bt_follow_toggle');
                fd.append('_ajax_nonce', nonce);
                fd.append('type', type);
                fd.append('id', id);
                fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (j) {
                        btn.dataset.folInflight = '';
                        if (!j || !j.success) {
                            if (j && j.data && typeof j.data === 'string') {
                                // Show the cap-reached or other server message inline if possible
                                var head = btn.closest('.bt-fol-panel').querySelector('.bt-fol-count');
                                if (head) {
                                    head.classList.add('bt-fol-count-flash');
                                    setTimeout(function () { head.classList.remove('bt-fol-count-flash'); }, 1500);
                                }
                                window.alert(j.data);
                            }
                            return;
                        }
                        var on = !!(j.data && j.data.following);
                        // Update visual state
                        if (btn.classList.contains('bt-fol-card')) {
                            btn.classList.toggle('bt-fol-card-on', on);
                            btn.setAttribute('aria-pressed', on ? 'true' : 'false');
                            var st = btn.querySelector('.bt-fol-card-state');
                            if (st) st.innerHTML = on ? '\u2713' : '+';
                        } else if (btn.classList.contains('bt-fol-row-btn')) {
                            var row = btn.closest('.bt-fol-row');
                            if (row) row.classList.toggle('bt-fol-row-on', on);
                            btn.setAttribute('aria-pressed', on ? 'true' : 'false');
                            btn.textContent = on
                                ? '<?php echo esc_js( __( 'Following', 'blockticker' ) ); ?>'
                                : '<?php echo esc_js( __( 'Follow',    'blockticker' ) ); ?>';
                        }
                        // Update count
                        var count = j.data && typeof j.data.count === 'number' ? j.data.count : null;
                        var cap   = j.data && typeof j.data.cap   === 'number' ? j.data.cap   : null;
                        if (count !== null && cap !== null) {
                            var head = btn.closest('.bt-fol-panel').querySelector('.bt-fol-count');
                            if (head) head.textContent = count + ' / ' + cap;
                        }
                    })
                    .catch(function () { btn.dataset.folInflight = ''; });
            });
        })();
        </script>
        <?php
    }

    /* ================================================================
     *  AJAX
     * ================================================================ */

    public static function ajax_toggle() {
        check_ajax_referer( self::NONCE_ACTION, '_ajax_nonce' );
        if ( ! is_user_logged_in() ) wp_send_json_error( __( 'Sign in to follow.', 'blockticker' ), 401 );

        $type = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
        $id   = isset( $_POST['id'] )   ? wp_unslash( $_POST['id'] )                   : '';

        if ( ! in_array( $type, self::get_valid_types(), true ) ) {
            wp_send_json_error( __( 'Invalid type.', 'blockticker' ), 400 );
        }

        $result = self::toggle( $type, $id );
        if ( $result === false ) {
            $caps = self::get_caps();
            wp_send_json_error( sprintf(
                /* translators: 1: cap, 2: type label */
                __( 'Maximum %1$d %2$s reached. Unfollow one first.', 'blockticker' ),
                $caps[ $type ],
                self::type_to_key( $type )
            ), 400 );
        }
        wp_send_json_success( $result );
    }

    public static function ajax_toggle_anon() {
        // Anon clients use localStorage; this exists for clean 401 if a logged-out
        // request reaches the endpoint somehow.
        wp_send_json_error( __( 'Sign in to follow.', 'blockticker' ), 401 );
    }

    /* ================================================================
     *  Dashboard widget registration
     * ================================================================ */

    public static function register_dashboard_widgets( $registry ) {
        if ( ! is_array( $registry ) ) return $registry;
        $registry['following_summary'] = array(
            'id'            => 'following_summary',
            'title'         => __( 'Following', 'blockticker' ),
            'icon'          => '&#x2605;',
            'shortcode'     => 'bt_following_summary_card',
            'atts'          => array(),
            'width'         => 'half',
            'require_login' => false,
        );
        $registry['personalized_news'] = array(
            'id'            => 'personalized_news',
            'title'         => __( 'For You', 'blockticker' ),
            'icon'          => '&#x1F4E5;',
            'shortcode'     => 'bt_personalized_news',
            'atts'          => array( 'limit' => '6' ),
            'width'         => 'half',
            'require_login' => false,
        );
        return $registry;
    }

    /* ================================================================
     *  Admin overview
     * ================================================================ */

    public static function register_admin_menu() {
        add_submenu_page(
            'fxlm-wizard',
            __( 'Following', 'blockticker' ),
            __( 'Following', 'blockticker' ),
            'manage_options',
            'bt-following',
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

        $total_users = 0;
        $totals = array( 'asset' => 0, 'source' => 0, 'signal' => 0 );
        $popular = array( 'asset' => array(), 'source' => array(), 'signal' => array() );

        foreach ( (array) $rows as $row ) {
            $shape = json_decode( $row->meta_value, true );
            if ( ! is_array( $shape ) ) continue;
            $shape = self::normalize_shape( $shape );
            $any = false;
            foreach ( array( 'assets' => 'asset', 'sources' => 'source', 'signals' => 'signal' ) as $key => $type ) {
                if ( ! empty( $shape[ $key ] ) ) {
                    $any = true;
                    $totals[ $type ] += count( $shape[ $key ] );
                    foreach ( $shape[ $key ] as $entity ) {
                        $popular[ $type ][ $entity ] = ( $popular[ $type ][ $entity ] ?? 0 ) + 1;
                    }
                }
            }
            if ( $any ) $total_users++;
        }
        foreach ( array_keys( $popular ) as $k ) arsort( $popular[ $k ] );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'BlockTicker — Following', 'blockticker' ); ?></h1>
            <p class="description">
                <?php esc_html_e( 'Following list subsystem. Read-only operator overview — aggregated patterns from all users (no per-user data displayed).', 'blockticker' ); ?>
                <a href="<?php echo esc_url( home_url( '/following/' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View public page →', 'blockticker' ); ?></a>
            </p>

            <h2><?php esc_html_e( 'Adoption', 'blockticker' ); ?></h2>
            <table class="widefat striped" style="max-width:600px">
                <tbody>
                    <tr><th><?php esc_html_e( 'Users with at least one follow', 'blockticker' ); ?></th><td><strong><?php echo esc_html( number_format( $total_users ) ); ?></strong></td></tr>
                    <tr><th><?php esc_html_e( 'Total followed assets', 'blockticker' ); ?></th><td><?php echo esc_html( number_format( $totals['asset'] ) ); ?></td></tr>
                    <tr><th><?php esc_html_e( 'Total followed news sources', 'blockticker' ); ?></th><td><?php echo esc_html( number_format( $totals['source'] ) ); ?></td></tr>
                    <tr><th><?php esc_html_e( 'Total followed signal sources', 'blockticker' ); ?></th><td><?php echo esc_html( number_format( $totals['signal'] ) ); ?></td></tr>
                </tbody>
            </table>

            <?php
            $caps = self::get_caps();
            $type_labels = array(
                'asset'  => __( 'Most-followed assets', 'blockticker' ),
                'source' => __( 'Most-followed news sources', 'blockticker' ),
                'signal' => __( 'Most-followed signal sources', 'blockticker' ),
            );
            foreach ( $type_labels as $type => $label ) :
                $top = array_slice( $popular[ $type ], 0, 20, true );
            ?>
                <h2><?php echo esc_html( $label ); ?> <small style="color:#646970;font-weight:400">(cap <?php echo (int) $caps[ $type ]; ?>)</small></h2>
                <?php if ( empty( $top ) ) : ?>
                    <p><?php esc_html_e( 'No data yet.', 'blockticker' ); ?></p>
                <?php else : ?>
                    <table class="widefat striped" style="max-width:600px">
                        <thead>
                            <tr><th><?php esc_html_e( 'Entity', 'blockticker' ); ?></th><th><?php esc_html_e( 'Followers', 'blockticker' ); ?></th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $top as $entity => $n ) : ?>
                                <tr><td><code><?php echo esc_html( $entity ); ?></code></td><td><?php echo esc_html( number_format( $n ) ); ?></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <?php
    }
}

BT_Following::setup();
