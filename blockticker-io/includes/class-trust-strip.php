<?php
/**
 * BlockTicker — Homepage Trust Strip + Compliance Disclaimer subsystem
 *
 * Conversion-focused E-E-A-T surface: a configurable strip of trust tiles
 * (Performance · Trustpilot · Featured-in · Editorial Standards) that adapts
 * gracefully based on available data, plus a reusable compliance disclaimer
 * shortcode for manual placement on analysis pages and landing pages.
 *
 * Design principles
 *   - Honest defaults: real data where we have it (performance ✅, editorial ✅);
 *     gracefully hidden where we don't (Trustpilot only renders if configured;
 *     Featured-in only renders if logos are set). No vaporware tiles.
 *   - Separation of concerns: this class READS from existing subsystems
 *     (BT_Performance, BT_EEAT) — never duplicates their data layer.
 *   - Layout adapts to tile count: 1 tile = empty (no point), 2 = 2-col,
 *     3 = 3-col, 4 = 4-col. Mobile always single-column.
 *   - Schema.org Organization + AggregateRating emitted only when Trustpilot
 *     data is fully configured — never with placeholder values.
 *
 * Public surface
 *   [bt_trust_strip]              — full multi-tile strip (homepage)
 *   [bt_trust_bar]                — compact single-line variant
 *   [bt_compliance_disclaimer]    — standalone disclaimer block
 *   BlockTicker → Trust Strip     — admin config page
 *
 * Stored options
 *   bt_trustpilot_url             — string URL (empty = tile hidden)
 *   bt_trustpilot_rating          — float 0-5 (step 0.1)
 *   bt_trustpilot_count           — int (review count)
 *   bt_featured_logos             — JSON array of {label, image_url, link_url}
 *   bt_trust_strip_tagline        — optional intro line above strip
 *
 * @since v119.13.0
 * @package BlockTicker
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BT_Trust_Strip {

    /** Cache key for the rendered strip (10-minute window). */
    const CACHE_KEY = 'bt_trust_strip_html_v1';
    const CACHE_TTL = 600;

    /** Hard caps on user-supplied values (defensive). */
    const MAX_LOGOS = 12;
    const MAX_RATING = 5.0;

    public static function setup() {
        add_shortcode( 'bt_trust_strip',           array( __CLASS__, 'sc_strip' ) );
        add_shortcode( 'bt_trust_bar',             array( __CLASS__, 'sc_bar' ) );
        add_shortcode( 'bt_compliance_disclaimer', array( __CLASS__, 'sc_disclaimer' ) );

        add_action( 'admin_menu',                          array( __CLASS__, 'register_admin_menu' ), 20 );
        add_action( 'admin_post_bt_trust_strip_save',      array( __CLASS__, 'admin_handle_save' ) );
        add_action( 'admin_post_bt_trust_strip_reset',     array( __CLASS__, 'admin_handle_reset' ) );

        // Cache busting whenever any of our config options change.
        add_action( 'update_option_bt_trustpilot_url',    array( __CLASS__, 'bust_cache' ), 10, 0 );
        add_action( 'update_option_bt_trustpilot_rating', array( __CLASS__, 'bust_cache' ), 10, 0 );
        add_action( 'update_option_bt_trustpilot_count',  array( __CLASS__, 'bust_cache' ), 10, 0 );
        add_action( 'update_option_bt_featured_logos',    array( __CLASS__, 'bust_cache' ), 10, 0 );
        add_action( 'update_option_bt_trust_strip_tagline', array( __CLASS__, 'bust_cache' ), 10, 0 );

        // JSON-LD emission on the homepage when Trustpilot is configured.
        add_action( 'wp_head', array( __CLASS__, 'emit_schema_jsonld' ), 25 );
    }

    public static function bust_cache() {
        delete_transient( self::CACHE_KEY );
    }

    /* ================================================================
     *  Config readers — single source of truth for tile data
     * ================================================================ */

    /**
     * Returns Trustpilot config or null if not fully configured.
     * Every field must be present and valid for the tile to render.
     */
    public static function get_trustpilot_config() {
        $url    = trim( (string) get_option( 'bt_trustpilot_url', '' ) );
        $rating = (float) get_option( 'bt_trustpilot_rating', 0 );
        $count  = (int)   get_option( 'bt_trustpilot_count', 0 );

        if ( $url === '' || $rating <= 0 || $rating > self::MAX_RATING ) return null;
        if ( ! preg_match( '#^https?://#i', $url ) ) return null;

        return array(
            'url'    => esc_url_raw( $url ),
            'rating' => round( min( $rating, self::MAX_RATING ), 1 ),
            'count'  => max( 0, $count ),
        );
    }

    /**
     * Returns array of featured-logo entries, normalized.
     * Each entry: {label, image_url, link_url}. Empty array = tile hidden.
     */
    public static function get_featured_logos() {
        $raw = get_option( 'bt_featured_logos', '' );
        if ( ! is_string( $raw ) || $raw === '' ) return array();

        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) ) return array();

        $out = array();
        foreach ( $decoded as $row ) {
            if ( ! is_array( $row ) ) continue;
            $label     = isset( $row['label'] )     ? trim( (string) $row['label'] )     : '';
            $image_url = isset( $row['image_url'] ) ? trim( (string) $row['image_url'] ) : '';
            $link_url  = isset( $row['link_url'] )  ? trim( (string) $row['link_url'] )  : '';
            if ( $label === '' || $image_url === '' ) continue;
            $out[] = array(
                'label'     => $label,
                'image_url' => esc_url_raw( $image_url ),
                'link_url'  => $link_url !== '' ? esc_url_raw( $link_url ) : '',
            );
            if ( count( $out ) >= self::MAX_LOGOS ) break;
        }
        return $out;
    }

    /**
     * Returns performance summary or null if not yet ready.
     * Pulls from BT_Performance — we never re-aggregate the tracker option here.
     */
    public static function get_performance_summary() {
        if ( ! class_exists( 'BT_Performance' ) ) return null;
        if ( ! method_exists( 'BT_Performance', 'get_aggregate_stats' ) ) return null;

        $stats = BT_Performance::get_aggregate_stats();
        if ( ! is_array( $stats ) || empty( $stats['total'] ) ) return null;
        return $stats;
    }

    /**
     * Returns lead author profile or null if EEAT class missing.
     * Always succeeds in practice — BT_EEAT::get_authors() has a site default.
     */
    public static function get_lead_author() {
        if ( ! class_exists( 'BT_EEAT' ) || ! method_exists( 'BT_EEAT', 'get_authors' ) ) return null;
        $authors = BT_EEAT::get_authors();
        if ( ! is_array( $authors ) || empty( $authors ) ) return null;
        return $authors[0];
    }

    /* ================================================================
     *  Tile renderers — each returns a fragment or '' if unavailable
     * ================================================================ */

    private static function tile_performance() {
        $stats = self::get_performance_summary();
        if ( ! $stats ) return '';

        $accuracy = $stats['accuracy'] !== null ? number_format( (float) $stats['accuracy'], 1 ) . '%' : '—';
        $total    = number_format( (int) $stats['total'] );

        ob_start(); ?>
        <a class="bt-trust-tile bt-trust-tile-performance" href="<?php echo esc_url( home_url( '/performance/' ) ); ?>">
            <div class="bt-trust-tile-icon" aria-hidden="true">&#x1F4C8;</div>
            <div class="bt-trust-tile-eyebrow"><?php esc_html_e( 'VERIFIED ACCURACY', 'blockticker' ); ?></div>
            <div class="bt-trust-tile-headline"><?php echo esc_html( $accuracy ); ?></div>
            <div class="bt-trust-tile-sub">
                <?php
                printf(
                    /* translators: %s: signal count */
                    esc_html__( '%s verified signals · 48h tracking window', 'blockticker' ),
                    esc_html( $total )
                );
                ?>
            </div>
            <div class="bt-trust-tile-cta"><?php esc_html_e( 'See full track record →', 'blockticker' ); ?></div>
        </a>
        <?php
        return ob_get_clean();
    }

    private static function tile_trustpilot() {
        $tp = self::get_trustpilot_config();
        if ( ! $tp ) return '';

        // Render 5 stars with partial fill for the fractional component.
        $full   = (int) floor( $tp['rating'] );
        $frac   = $tp['rating'] - $full;
        $stars_html = '';
        for ( $i = 1; $i <= 5; $i++ ) {
            if ( $i <= $full ) {
                $stars_html .= '<span class="bt-trust-star bt-trust-star-full" aria-hidden="true">&#9733;</span>';
            } elseif ( $i === $full + 1 && $frac >= 0.25 ) {
                $cls = $frac >= 0.75 ? 'bt-trust-star-full' : 'bt-trust-star-half';
                $stars_html .= '<span class="bt-trust-star ' . $cls . '" aria-hidden="true">&#9733;</span>';
            } else {
                $stars_html .= '<span class="bt-trust-star bt-trust-star-empty" aria-hidden="true">&#9734;</span>';
            }
        }

        ob_start(); ?>
        <a class="bt-trust-tile bt-trust-tile-trustpilot"
           href="<?php echo esc_url( $tp['url'] ); ?>"
           target="_blank" rel="noopener nofollow">
            <div class="bt-trust-tile-icon" aria-hidden="true">&#x2B50;</div>
            <div class="bt-trust-tile-eyebrow"><?php esc_html_e( 'RATED ON TRUSTPILOT', 'blockticker' ); ?></div>
            <div class="bt-trust-tile-headline">
                <span class="bt-trust-rating-num"><?php echo esc_html( number_format( $tp['rating'], 1 ) ); ?></span>
                <span class="bt-trust-rating-max">/ 5</span>
            </div>
            <div class="bt-trust-stars" aria-label="<?php
                printf(
                    /* translators: 1: rating, 2: max */
                    esc_attr__( '%1$s out of %2$s stars', 'blockticker' ),
                    esc_attr( number_format( $tp['rating'], 1 ) ),
                    '5'
                );
            ?>"><?php echo $stars_html; // already escaped above ?></div>
            <?php if ( $tp['count'] > 0 ) : ?>
                <div class="bt-trust-tile-sub">
                    <?php
                    printf(
                        /* translators: %s: review count */
                        esc_html__( 'Based on %s reviews', 'blockticker' ),
                        esc_html( number_format( $tp['count'] ) )
                    );
                    ?>
                </div>
            <?php endif; ?>
            <div class="bt-trust-tile-cta"><?php esc_html_e( 'Read reviews →', 'blockticker' ); ?></div>
        </a>
        <?php
        return ob_get_clean();
    }

    private static function tile_featured_in() {
        $logos = self::get_featured_logos();
        if ( empty( $logos ) ) return '';

        ob_start(); ?>
        <div class="bt-trust-tile bt-trust-tile-featured">
            <div class="bt-trust-tile-icon" aria-hidden="true">&#x1F4F0;</div>
            <div class="bt-trust-tile-eyebrow"><?php esc_html_e( 'AS FEATURED IN', 'blockticker' ); ?></div>
            <div class="bt-trust-logos">
                <?php foreach ( $logos as $logo ) :
                    $img_html = '<img src="' . esc_url( $logo['image_url'] ) . '" alt="' . esc_attr( $logo['label'] ) . '" loading="lazy" decoding="async">';
                    if ( $logo['link_url'] !== '' ) :
                ?>
                    <a class="bt-trust-logo-item" href="<?php echo esc_url( $logo['link_url'] ); ?>"
                       target="_blank" rel="noopener nofollow"
                       title="<?php echo esc_attr( $logo['label'] ); ?>"><?php echo $img_html; // safe: built above ?></a>
                <?php else : ?>
                    <span class="bt-trust-logo-item bt-trust-logo-static"
                          title="<?php echo esc_attr( $logo['label'] ); ?>"><?php echo $img_html; // safe: built above ?></span>
                <?php endif; endforeach; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    private static function tile_editorial() {
        $author = self::get_lead_author();
        if ( ! $author ) return '';

        $about_url  = home_url( '/about/' );
        $policy_url = home_url( '/editorial-policy/' );

        $name        = isset( $author['name'] )        ? (string) $author['name']        : '';
        $title_line  = isset( $author['title'] )       ? (string) $author['title']       : '';
        $credentials = isset( $author['credentials'] ) ? (string) $author['credentials'] : '';

        if ( $name === '' ) return '';

        ob_start(); ?>
        <a class="bt-trust-tile bt-trust-tile-editorial" href="<?php echo esc_url( $about_url ); ?>">
            <div class="bt-trust-tile-icon" aria-hidden="true">&#x1F4DD;</div>
            <div class="bt-trust-tile-eyebrow"><?php esc_html_e( 'EDITORIAL STANDARDS', 'blockticker' ); ?></div>
            <div class="bt-trust-tile-headline-sm"><?php echo esc_html( $name ); ?></div>
            <?php if ( $title_line !== '' ) : ?>
                <div class="bt-trust-tile-sub"><?php echo esc_html( $title_line ); ?></div>
            <?php endif; ?>
            <?php if ( $credentials !== '' ) : ?>
                <div class="bt-trust-tile-sub-sm"><?php echo esc_html( $credentials ); ?></div>
            <?php endif; ?>
            <div class="bt-trust-tile-cta"><?php esc_html_e( 'How we work →', 'blockticker' ); ?></div>
        </a>
        <?php
        return ob_get_clean();
    }

    /* ================================================================
     *  Public shortcodes
     * ================================================================ */

    /**
     * [bt_trust_strip]
     *
     * Attributes:
     *   tagline   — optional intro text override (default: stored option value)
     *   show      — comma list of tiles to include: performance,trustpilot,featured,editorial
     *               (default: all four; tiles still self-hide if data unavailable)
     *   layout    — auto|compact (compact = no eyebrow, no CTA, denser)
     */
    public static function sc_strip( $atts = array() ) {
        $a = shortcode_atts( array(
            'tagline' => '',
            'show'    => 'performance,trustpilot,featured,editorial',
            'layout'  => 'auto',
        ), $atts, 'bt_trust_strip' );

        $cache_signature = md5( wp_json_encode( $a ) );
        $cached = get_transient( self::CACHE_KEY . '_' . $cache_signature );
        if ( $cached !== false ) return $cached;

        $allowed = array_filter( array_map( 'trim', explode( ',', $a['show'] ) ) );
        $tiles   = array();

        if ( in_array( 'performance', $allowed, true ) ) {
            $t = self::tile_performance();
            if ( $t !== '' ) $tiles[] = $t;
        }
        if ( in_array( 'trustpilot', $allowed, true ) ) {
            $t = self::tile_trustpilot();
            if ( $t !== '' ) $tiles[] = $t;
        }
        if ( in_array( 'featured', $allowed, true ) ) {
            $t = self::tile_featured_in();
            if ( $t !== '' ) $tiles[] = $t;
        }
        if ( in_array( 'editorial', $allowed, true ) ) {
            $t = self::tile_editorial();
            if ( $t !== '' ) $tiles[] = $t;
        }

        // Refuse to render a "strip" of less than 2 tiles — visually pointless.
        if ( count( $tiles ) < 2 ) return '';

        $tagline = $a['tagline'] !== '' ? $a['tagline'] : (string) get_option( 'bt_trust_strip_tagline', '' );
        $layout_cls = $a['layout'] === 'compact' ? ' bt-trust-strip-compact' : '';
        $count_cls  = ' bt-trust-strip-c' . count( $tiles );

        ob_start(); ?>
        <section class="bt-trust-strip<?php echo esc_attr( $layout_cls . $count_cls ); ?>"
                 aria-label="<?php esc_attr_e( 'Trust signals', 'blockticker' ); ?>">
            <?php if ( $tagline !== '' ) : ?>
                <div class="bt-trust-strip-tagline"><?php echo esc_html( $tagline ); ?></div>
            <?php endif; ?>
            <div class="bt-trust-strip-grid">
                <?php foreach ( $tiles as $tile_html ) echo $tile_html; // each tile is self-escaped above ?>
            </div>
        </section>
        <?php
        $html = ob_get_clean();
        set_transient( self::CACHE_KEY . '_' . $cache_signature, $html, self::CACHE_TTL );
        return $html;
    }

    /**
     * [bt_trust_bar]
     *
     * Compact single-line trust bar — accuracy · review rating · author. No CTAs.
     * Designed for high-density placements (between hero and first content fold,
     * sticky footer, etc.). Each segment self-hides if data unavailable.
     */
    public static function sc_bar( $atts = array() ) {
        $a = shortcode_atts( array(
            'separator' => '·',
        ), $atts, 'bt_trust_bar' );

        $segments = array();

        $stats = self::get_performance_summary();
        if ( $stats && $stats['accuracy'] !== null ) {
            $segments[] = sprintf(
                '<span class="bt-trust-bar-seg"><span class="bt-trust-bar-strong">%s</span> %s</span>',
                esc_html( number_format( (float) $stats['accuracy'], 1 ) . '%' ),
                esc_html__( 'verified accuracy', 'blockticker' )
            );
        }

        $tp = self::get_trustpilot_config();
        if ( $tp ) {
            $segments[] = sprintf(
                '<a class="bt-trust-bar-seg bt-trust-bar-link" href="%s" target="_blank" rel="noopener nofollow"><span class="bt-trust-bar-strong">%s/5</span> %s</a>',
                esc_url( $tp['url'] ),
                esc_html( number_format( $tp['rating'], 1 ) ),
                esc_html__( 'on Trustpilot', 'blockticker' )
            );
        }

        $author = self::get_lead_author();
        if ( $author && ! empty( $author['name'] ) ) {
            $segments[] = sprintf(
                '<a class="bt-trust-bar-seg bt-trust-bar-link" href="%s">%s <span class="bt-trust-bar-strong">%s</span></a>',
                esc_url( home_url( '/about/' ) ),
                esc_html__( 'Researched by', 'blockticker' ),
                esc_html( $author['name'] )
            );
        }

        if ( count( $segments ) < 2 ) return '';

        $sep = '<span class="bt-trust-bar-sep" aria-hidden="true">' . esc_html( $a['separator'] ) . '</span>';

        return '<div class="bt-trust-bar" role="complementary" aria-label="' .
            esc_attr__( 'Trust signals', 'blockticker' ) . '">' .
            implode( $sep, $segments ) . '</div>';
    }

    /**
     * [bt_compliance_disclaimer scope="generic|trading|forex|crypto"]
     *
     * Reusable "not investment advice" disclaimer block — designed for manual
     * placement on landing pages and analysis content. Distinct from
     * BT_EEAT::inject_risk_warning(), which auto-injects on categorized posts;
     * this shortcode is for explicit author placement on pages and templates.
     */
    public static function sc_disclaimer( $atts = array() ) {
        $a = shortcode_atts( array(
            'scope' => 'generic',
            'compact' => 'no',
        ), $atts, 'bt_compliance_disclaimer' );

        $bodies = self::disclaimer_bodies();
        $scope  = isset( $bodies[ $a['scope'] ] ) ? $a['scope'] : 'generic';
        $body   = $bodies[ $scope ];
        $compact_cls = $a['compact'] === 'yes' ? ' bt-trust-disc-compact' : '';

        ob_start(); ?>
        <aside class="bt-trust-disc<?php echo esc_attr( $compact_cls ); ?>" role="note"
               aria-label="<?php esc_attr_e( 'Compliance disclaimer', 'blockticker' ); ?>">
            <div class="bt-trust-disc-icon" aria-hidden="true">&#x26A0;&#xFE0F;</div>
            <div class="bt-trust-disc-body">
                <strong class="bt-trust-disc-title"><?php esc_html_e( 'Not Investment Advice', 'blockticker' ); ?></strong>
                <p><?php echo esc_html( $body ); ?></p>
            </div>
        </aside>
        <?php
        return ob_get_clean();
    }

    /**
     * Per-scope disclaimer bodies. Pure data — kept separate so other features
     * (admin previews, future emails/PDFs) can reuse the exact same wording.
     */
    private static function disclaimer_bodies() {
        return array(
            'generic' => __(
                'This content is for informational purposes only and does not constitute financial, investment, legal or tax advice. Past performance is not indicative of future results. Always conduct your own research and consult a qualified advisor before making any investment decisions.',
                'blockticker'
            ),
            'trading' => __(
                'Trading signals published here are for educational and informational purposes only and do not constitute a solicitation, recommendation or offer to buy or sell any asset. Trading involves substantial risk of loss. Verified track-record data is published transparently — see /performance/ — but past results never guarantee future performance.',
                'blockticker'
            ),
            'forex' => __(
                'Foreign exchange trading carries a high level of risk and may not be suitable for all investors. Leverage can work against you as well as for you. Before deciding to trade forex you should carefully consider your investment objectives, level of experience and risk appetite. This page is for informational purposes only.',
                'blockticker'
            ),
            'crypto' => __(
                'Cryptocurrency markets are highly volatile and unregulated in many jurisdictions. The value of digital assets can fall as well as rise, and investors may lose all of the capital invested. This page is for informational purposes only and does not constitute financial advice or a recommendation to buy or sell any cryptocurrency.',
                'blockticker'
            ),
        );
    }

    /* ================================================================
     *  Schema.org JSON-LD — only emitted when fully configured
     * ================================================================ */

    public static function emit_schema_jsonld() {
        // Only on the homepage — avoids duplicating the AggregateRating across
        // every page (Google guidelines + clean SERP behaviour).
        if ( ! is_front_page() && ! is_home() ) return;

        $tp = self::get_trustpilot_config();
        if ( ! $tp ) return;
        if ( $tp['count'] <= 0 ) return; // AggregateRating requires reviewCount per schema.org

        $site_name = get_option( 'bt_site_name', get_bloginfo( 'name' ) );
        $home_url  = home_url( '/' );

        $payload = array(
            '@context'         => 'https://schema.org',
            '@type'            => 'Organization',
            'name'             => $site_name,
            'url'              => $home_url,
            'aggregateRating'  => array(
                '@type'       => 'AggregateRating',
                'ratingValue' => (string) number_format( $tp['rating'], 1 ),
                'bestRating'  => '5',
                'worstRating' => '1',
                'ratingCount' => (string) $tp['count'],
                'reviewCount' => (string) $tp['count'],
            ),
        );

        echo "\n<script type=\"application/ld+json\">" .
            wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) .
            "</script>\n";
    }

    /* ================================================================
     *  Admin overview & settings form
     * ================================================================ */

    public static function register_admin_menu() {
        add_submenu_page(
            'fxlm-wizard',
            __( 'Trust Strip', 'blockticker' ),
            __( 'Trust Strip', 'blockticker' ),
            'manage_options',
            'bt-trust-strip',
            array( __CLASS__, 'render_admin_page' )
        );
    }

    public static function admin_handle_save() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );
        check_admin_referer( 'bt_trust_strip_save' );

        // Trustpilot fields.
        $tp_url = isset( $_POST['bt_trustpilot_url'] ) ? trim( wp_unslash( $_POST['bt_trustpilot_url'] ) ) : '';
        if ( $tp_url !== '' && ! preg_match( '#^https?://#i', $tp_url ) ) $tp_url = 'https://' . $tp_url;
        update_option( 'bt_trustpilot_url', esc_url_raw( $tp_url ) );

        $tp_rating = isset( $_POST['bt_trustpilot_rating'] ) ? (float) $_POST['bt_trustpilot_rating'] : 0;
        $tp_rating = max( 0, min( self::MAX_RATING, round( $tp_rating, 1 ) ) );
        update_option( 'bt_trustpilot_rating', $tp_rating );

        $tp_count = isset( $_POST['bt_trustpilot_count'] ) ? (int) $_POST['bt_trustpilot_count'] : 0;
        update_option( 'bt_trustpilot_count', max( 0, $tp_count ) );

        // Tagline.
        $tagline = isset( $_POST['bt_trust_strip_tagline'] ) ? sanitize_text_field( wp_unslash( $_POST['bt_trust_strip_tagline'] ) ) : '';
        update_option( 'bt_trust_strip_tagline', $tagline );

        // Featured logos — parse line-delimited "label | image_url | link_url" rows.
        $logos_raw = isset( $_POST['bt_featured_logos_raw'] ) ? wp_unslash( $_POST['bt_featured_logos_raw'] ) : '';
        $logos     = self::parse_logos_textarea( (string) $logos_raw );
        update_option( 'bt_featured_logos', wp_json_encode( $logos ) );

        self::bust_cache();

        wp_safe_redirect( add_query_arg( array(
            'page'  => 'bt-trust-strip',
            'saved' => '1',
        ), admin_url( 'admin.php' ) ) );
        exit;
    }

    public static function admin_handle_reset() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );
        check_admin_referer( 'bt_trust_strip_reset' );

        delete_option( 'bt_trustpilot_url' );
        delete_option( 'bt_trustpilot_rating' );
        delete_option( 'bt_trustpilot_count' );
        delete_option( 'bt_featured_logos' );
        delete_option( 'bt_trust_strip_tagline' );
        self::bust_cache();

        wp_safe_redirect( add_query_arg( array(
            'page'  => 'bt-trust-strip',
            'reset' => '1',
        ), admin_url( 'admin.php' ) ) );
        exit;
    }

    /**
     * Parses the human-friendly line-delimited logo textarea format:
     *   "Label | https://image.url | https://link.url"   (link is optional)
     *   "Label | https://image.url"                       (static, no link)
     * Lines starting with # or empty lines are ignored.
     */
    private static function parse_logos_textarea( $raw ) {
        $out   = array();
        $lines = preg_split( '/\r\n|\r|\n/', $raw );
        if ( ! is_array( $lines ) ) return $out;

        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( $line === '' || $line[0] === '#' ) continue;

            $parts = array_map( 'trim', explode( '|', $line ) );
            $label     = isset( $parts[0] ) ? $parts[0] : '';
            $image_url = isset( $parts[1] ) ? $parts[1] : '';
            $link_url  = isset( $parts[2] ) ? $parts[2] : '';

            if ( $label === '' || $image_url === '' ) continue;
            if ( ! preg_match( '#^https?://#i', $image_url ) ) continue;
            if ( $link_url !== '' && ! preg_match( '#^https?://#i', $link_url ) ) $link_url = '';

            $out[] = array(
                'label'     => $label,
                'image_url' => $image_url,
                'link_url'  => $link_url,
            );
            if ( count( $out ) >= self::MAX_LOGOS ) break;
        }
        return $out;
    }

    /** Reverses parse_logos_textarea() — used to render the form value. */
    private static function logos_to_textarea( $logos ) {
        if ( ! is_array( $logos ) ) return '';
        $lines = array();
        foreach ( $logos as $row ) {
            if ( ! is_array( $row ) ) continue;
            $label     = isset( $row['label'] )     ? $row['label']     : '';
            $image_url = isset( $row['image_url'] ) ? $row['image_url'] : '';
            $link_url  = isset( $row['link_url'] )  ? $row['link_url']  : '';
            if ( $label === '' || $image_url === '' ) continue;
            $lines[] = $link_url !== ''
                ? $label . ' | ' . $image_url . ' | ' . $link_url
                : $label . ' | ' . $image_url;
        }
        return implode( "\n", $lines );
    }

    public static function render_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $tp_url     = (string) get_option( 'bt_trustpilot_url', '' );
        $tp_rating  = (float)  get_option( 'bt_trustpilot_rating', 0 );
        $tp_count   = (int)    get_option( 'bt_trustpilot_count', 0 );
        $tagline    = (string) get_option( 'bt_trust_strip_tagline', '' );
        $logos_json = (string) get_option( 'bt_featured_logos', '' );
        $logos_arr  = $logos_json !== '' ? json_decode( $logos_json, true ) : array();
        $logos_text = self::logos_to_textarea( is_array( $logos_arr ) ? $logos_arr : array() );

        $saved = ! empty( $_GET['saved'] );
        $reset = ! empty( $_GET['reset'] );

        $perf_stats = self::get_performance_summary();
        $tp_config  = self::get_trustpilot_config();
        $logos_live = self::get_featured_logos();
        $author     = self::get_lead_author();

        ?>
        <div class="wrap bt-trust-admin">
            <h1><?php esc_html_e( 'BlockTicker — Trust Strip', 'blockticker' ); ?></h1>

            <?php if ( $saved ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Trust strip settings saved.', 'blockticker' ); ?></p></div>
            <?php endif; ?>
            <?php if ( $reset ) : ?>
                <div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'Trust strip settings reset to defaults.', 'blockticker' ); ?></p></div>
            <?php endif; ?>

            <p class="description">
                <?php
                printf(
                    /* translators: 1: shortcode tag */
                    wp_kses_post( __( 'Configure the homepage trust strip. Drop %1$s into any page or widget once configured. Tiles with no data hide automatically — there are no placeholder values shown to visitors.', 'blockticker' ) ),
                    '<code>[bt_trust_strip]</code>'
                );
                ?>
            </p>

            <h2><?php esc_html_e( 'Tile readiness', 'blockticker' ); ?></h2>
            <table class="widefat striped" style="max-width:780px;margin-bottom:24px">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Tile', 'blockticker' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'blockticker' ); ?></th>
                        <th><?php esc_html_e( 'Detail', 'blockticker' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong><?php esc_html_e( 'Performance', 'blockticker' ); ?></strong></td>
                        <td><?php echo $perf_stats ? '<span style="color:#00a32a">&#x2705; ' . esc_html__( 'Active', 'blockticker' ) . '</span>' : '<span style="color:#646970">— ' . esc_html__( 'Warming up', 'blockticker' ) . '</span>'; ?></td>
                        <td>
                            <?php if ( $perf_stats ) : ?>
                                <?php printf( esc_html__( '%1$s accuracy across %2$s signals', 'blockticker' ),
                                    $perf_stats['accuracy'] !== null ? esc_html( number_format( (float) $perf_stats['accuracy'], 1 ) . '%' ) : '—',
                                    esc_html( number_format( (int) $perf_stats['total'] ) )
                                ); ?>
                            <?php else : ?>
                                <?php esc_html_e( 'No verified signals yet — tile auto-renders once tracker resolves first 48h window.', 'blockticker' ); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e( 'Trustpilot', 'blockticker' ); ?></strong></td>
                        <td><?php echo $tp_config ? '<span style="color:#00a32a">&#x2705; ' . esc_html__( 'Active', 'blockticker' ) . '</span>' : '<span style="color:#646970">— ' . esc_html__( 'Not configured', 'blockticker' ) . '</span>'; ?></td>
                        <td>
                            <?php if ( $tp_config ) : ?>
                                <?php printf( esc_html__( '%1$s/5 from %2$s reviews', 'blockticker' ),
                                    esc_html( number_format( $tp_config['rating'], 1 ) ),
                                    esc_html( number_format( $tp_config['count'] ) )
                                ); ?>
                            <?php else : ?>
                                <?php esc_html_e( 'Set URL + rating below; tile only renders when all fields are valid.', 'blockticker' ); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e( 'Featured-in', 'blockticker' ); ?></strong></td>
                        <td><?php echo ! empty( $logos_live ) ? '<span style="color:#00a32a">&#x2705; ' . esc_html__( 'Active', 'blockticker' ) . '</span>' : '<span style="color:#646970">— ' . esc_html__( 'No logos', 'blockticker' ) . '</span>'; ?></td>
                        <td>
                            <?php
                            if ( ! empty( $logos_live ) ) {
                                printf( esc_html__( '%d logo(s) configured', 'blockticker' ), count( $logos_live ) );
                            } else {
                                esc_html_e( 'Add publication logos below to surface third-party endorsements.', 'blockticker' );
                            }
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e( 'Editorial', 'blockticker' ); ?></strong></td>
                        <td><?php echo $author ? '<span style="color:#00a32a">&#x2705; ' . esc_html__( 'Active', 'blockticker' ) . '</span>' : '<span style="color:#d63638">&#x274C; ' . esc_html__( 'Missing', 'blockticker' ) . '</span>'; ?></td>
                        <td>
                            <?php
                            if ( $author ) {
                                printf( esc_html__( 'Lead: %s', 'blockticker' ), esc_html( $author['name'] ) );
                                echo ' &middot; <a href="' . esc_url( admin_url( 'admin.php?page=fxlm-wizard' ) ) . '">' . esc_html__( 'Edit author profiles', 'blockticker' ) . '</a>';
                            } else {
                                esc_html_e( 'BT_EEAT::get_authors() returned empty — check author profile config.', 'blockticker' );
                            }
                            ?>
                        </td>
                    </tr>
                </tbody>
            </table>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="bt_trust_strip_save">
                <?php wp_nonce_field( 'bt_trust_strip_save' ); ?>

                <h2><?php esc_html_e( 'Trustpilot', 'blockticker' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="bt_trustpilot_url"><?php esc_html_e( 'Profile URL', 'blockticker' ); ?></label></th>
                        <td>
                            <input type="url" id="bt_trustpilot_url" name="bt_trustpilot_url"
                                value="<?php echo esc_attr( $tp_url ); ?>"
                                placeholder="https://www.trustpilot.com/review/blockticker.io"
                                class="regular-text">
                            <p class="description"><?php esc_html_e( 'Public Trustpilot review page URL. Leave empty to hide the Trustpilot tile entirely.', 'blockticker' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="bt_trustpilot_rating"><?php esc_html_e( 'Rating', 'blockticker' ); ?></label></th>
                        <td>
                            <input type="number" id="bt_trustpilot_rating" name="bt_trustpilot_rating"
                                value="<?php echo esc_attr( number_format( $tp_rating, 1, '.', '' ) ); ?>"
                                min="0" max="5" step="0.1" class="small-text">
                            <span class="description"><?php esc_html_e( '0.0 — 5.0 (one decimal place).', 'blockticker' ); ?></span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="bt_trustpilot_count"><?php esc_html_e( 'Review count', 'blockticker' ); ?></label></th>
                        <td>
                            <input type="number" id="bt_trustpilot_count" name="bt_trustpilot_count"
                                value="<?php echo esc_attr( $tp_count ); ?>"
                                min="0" step="1" class="small-text">
                            <span class="description"><?php esc_html_e( 'Required for Schema.org AggregateRating output.', 'blockticker' ); ?></span>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e( 'Featured-in logos', 'blockticker' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="bt_featured_logos_raw"><?php esc_html_e( 'Logos', 'blockticker' ); ?></label></th>
                        <td>
                            <textarea id="bt_featured_logos_raw" name="bt_featured_logos_raw"
                                rows="6" cols="80" class="large-text code"
                                placeholder="CoinDesk | https://example.com/coindesk-logo.svg | https://coindesk.com&#10;The Block | https://example.com/theblock-logo.svg"><?php echo esc_textarea( $logos_text ); ?></textarea>
                            <p class="description">
                                <?php
                                printf(
                                    /* translators: 1: format spec, 2: max count */
                                    wp_kses_post( __( 'One logo per line in <code>%1$s</code> format. Link URL is optional. Maximum %2$d logos. Use HTTPS image URLs hosted on your CDN or media library. Lines starting with # are comments.', 'blockticker' ) ),
                                    'Label | image_url | link_url',
                                    self::MAX_LOGOS
                                );
                                ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e( 'Strip-level options', 'blockticker' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="bt_trust_strip_tagline"><?php esc_html_e( 'Tagline', 'blockticker' ); ?></label></th>
                        <td>
                            <input type="text" id="bt_trust_strip_tagline" name="bt_trust_strip_tagline"
                                value="<?php echo esc_attr( $tagline ); ?>"
                                placeholder="<?php esc_attr_e( 'Why traders trust us', 'blockticker' ); ?>"
                                class="regular-text">
                            <p class="description"><?php esc_html_e( 'Optional intro line shown above the tile grid. Leave empty for no header.', 'blockticker' ); ?></p>
                        </td>
                    </tr>
                </table>

                <?php submit_button( __( 'Save trust strip settings', 'blockticker' ) ); ?>
            </form>

            <h2><?php esc_html_e( 'Live preview', 'blockticker' ); ?></h2>
            <p class="description"><?php esc_html_e( 'Rendered with current saved settings. Refresh after saving to see changes.', 'blockticker' ); ?></p>
            <div style="background:#1a1a1a;padding:24px;margin:12px 0 24px;border-radius:6px">
                <?php
                $preview = self::sc_strip( array() );
                if ( $preview === '' ) {
                    echo '<div style="color:#aaa;font-style:italic;text-align:center;padding:20px">' .
                        esc_html__( 'Strip is empty — fewer than 2 tiles have data. Configure at least 2 to render publicly.', 'blockticker' ) .
                        '</div>';
                } else {
                    echo $preview; // safe: built from above renderers
                }
                ?>
            </div>

            <h2><?php esc_html_e( 'Shortcodes', 'blockticker' ); ?></h2>
            <table class="widefat striped" style="max-width:780px">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Shortcode', 'blockticker' ); ?></th>
                        <th><?php esc_html_e( 'Use case', 'blockticker' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code>[bt_trust_strip]</code></td>
                        <td><?php esc_html_e( 'Full multi-tile trust strip (homepage, landing pages).', 'blockticker' ); ?></td>
                    </tr>
                    <tr>
                        <td><code>[bt_trust_strip layout="compact"]</code></td>
                        <td><?php esc_html_e( 'Denser layout, no eyebrows or CTAs.', 'blockticker' ); ?></td>
                    </tr>
                    <tr>
                        <td><code>[bt_trust_strip show="performance,trustpilot"]</code></td>
                        <td><?php esc_html_e( 'Restrict to specific tiles only.', 'blockticker' ); ?></td>
                    </tr>
                    <tr>
                        <td><code>[bt_trust_bar]</code></td>
                        <td><?php esc_html_e( 'Compact single-line bar — accuracy · rating · author.', 'blockticker' ); ?></td>
                    </tr>
                    <tr>
                        <td><code>[bt_compliance_disclaimer scope="trading"]</code></td>
                        <td><?php esc_html_e( 'Standalone "not investment advice" block. Scopes: generic, trading, forex, crypto.', 'blockticker' ); ?></td>
                    </tr>
                </tbody>
            </table>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:32px"
                  onsubmit="return confirm('<?php echo esc_js( __( 'Reset all trust strip settings? This cannot be undone.', 'blockticker' ) ); ?>');">
                <input type="hidden" name="action" value="bt_trust_strip_reset">
                <?php wp_nonce_field( 'bt_trust_strip_reset' ); ?>
                <?php submit_button( __( 'Reset all trust strip settings', 'blockticker' ), 'delete', 'submit', false ); ?>
            </form>
        </div>
        <?php
    }
}

BT_Trust_Strip::setup();
