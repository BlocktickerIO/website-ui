<?php
/**
 * BT_SEO_Sitemap — Dynamic XML sitemap for BlockTicker asset pages.
 *
 * Virtual /crypto/{slug}/ and /forex/{slug}/ routes are not WP posts,
 * so Yoast and WP core sitemaps skip them entirely.  This class adds them
 * through three complementary paths:
 *
 *  1. Standalone endpoint  →  /blockticker-assets-sitemap.xml  (always available)
 *  2. WP 5.5+ core sitemap →  registered as a custom provider under /wp-sitemap.xml
 *  3. Yoast sub-sitemap    →  added as a named module when WPSEO_VERSION is defined
 *
 * A daily cron + a hook on fxlm_refresh_prices keeps the sitemap current.
 * After each rebuild the class pings Google Search Console and Bing Webmaster.
 *
 * @since  96.5.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BT_SEO_Sitemap {

    /** Slug used for the standalone XML endpoint. */
    const URL_SLUG      = 'blockticker-assets-sitemap.xml';
    /** WordPress option that caches the last-generated XML blob. */
    const OPTION_KEY    = 'bt_sitemap_xml_cache';
    /** Transient key storing generated-at timestamp. */
    const TIMESTAMP_KEY = 'bt_sitemap_last_built';
    /** Rebuild interval in seconds (6 hours). */
    const CACHE_TTL     = 21600;

    /* ------------------------------------------------------------------
     * Bootstrap
     * ------------------------------------------------------------------ */

    public static function init() {
        // 1. Standalone XML endpoint
        add_action( 'init',              [ __CLASS__, 'add_rewrite_rule' ] );
        add_action( 'template_redirect', [ __CLASS__, 'maybe_serve_sitemap' ] );

        // 2. WP 5.5+ core sitemap (wp_sitemaps_*).
        //    Only registers when Yoast has NOT disabled core sitemaps.
        add_filter( 'wp_sitemaps_add_provider', [ __CLASS__, 'register_core_provider' ], 10, 2 );

        // 3. Yoast sub-sitemap (requires WPSEO 14+).
        //    Declared at init priority 15 so Yoast's own init (priority 1) runs first.
        add_action( 'init', [ __CLASS__, 'maybe_register_yoast_sitemap' ], 15 );

        // 4. Rebuild cron (daily; also triggered immediately after price refresh).
        add_action( 'init',                  [ __CLASS__, 'schedule_cron' ] );
        add_action( 'bt_regenerate_sitemap', [ __CLASS__, 'regenerate_and_ping' ] );
        add_action( 'fxlm_refresh_prices',   [ __CLASS__, 'regenerate_and_ping' ], 99 );

        // 5. Append Sitemap: directive to robots.txt.
        add_filter( 'robots_txt', [ __CLASS__, 'append_robots_txt' ], 10, 2 );
    }

    /* ------------------------------------------------------------------
     * Cron
     * ------------------------------------------------------------------ */

    public static function schedule_cron() {
        if ( ! wp_next_scheduled( 'bt_regenerate_sitemap' ) ) {
            wp_schedule_event( time() + 600, 'daily', 'bt_regenerate_sitemap' );
        }
    }

    /* ------------------------------------------------------------------
     * 1. Standalone XML endpoint
     * ------------------------------------------------------------------ */

    public static function add_rewrite_rule() {
        add_rewrite_rule(
            '^' . preg_quote( self::URL_SLUG, '#' ) . '$',
            'index.php?bt_assets_sitemap=1',
            'top'
        );
        add_rewrite_tag( '%bt_assets_sitemap%', '([0-9]{1})' );
    }

    public static function maybe_serve_sitemap() {
        if ( ! get_query_var( 'bt_assets_sitemap' ) ) return;

        $xml = self::get_or_build_xml();

        header( 'Content-Type: application/xml; charset=UTF-8', true );
        header( 'Cache-Control: public, max-age=' . self::CACHE_TTL, true );
        // Search engines should not index the sitemap itself.
        header( 'X-Robots-Tag: noindex, follow', true );

        echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput
        exit;
    }

    /* ------------------------------------------------------------------
     * 2. WP 5.5+ core sitemap provider
     * ------------------------------------------------------------------ */

    /**
     * Register a custom provider so our asset URLs appear in the native
     * /wp-sitemap.xml index under the "bt-assets" key.
     *
     * @param WP_Sitemaps_Provider $provider  Existing provider (passed through).
     * @param string               $name      Provider identifier.
     * @return WP_Sitemaps_Provider
     */
    public static function register_core_provider( $provider, $name ) {
        // Only register once; the filter fires per-provider, not once.
        static $registered = false;
        if ( $registered ) return $provider;

        // Guard: WP_Sitemaps_Provider class may not exist on WP < 5.5.
        if ( ! class_exists( 'WP_Sitemaps_Provider' ) ) return $provider;

        // Guard: if Yoast is active it disables WP core sitemaps entirely.
        // apply_filters('wp_sitemaps_enabled', true) returns false in that case.
        if ( false === apply_filters( 'wp_sitemaps_enabled', true ) ) return $provider;

        $registered = true;

        global $wp_sitemaps;
        if ( ! $wp_sitemaps ) return $provider;

        // Anonymous class avoids the "nested class declaration" PHP parse error.
        // PHP 7.0+ (WP requires 7.2+) so anonymous classes are always available.
        $bt_provider = new class() extends WP_Sitemaps_Provider {

            public function __construct() {
                $this->name        = 'bt-assets';
                $this->object_type = 'bt_asset';
            }

            public function get_url_list( $page_num, $object_subtype = '' ) {
                $per_page = defined( 'WP_Sitemaps::SITEMAP_MAX_URLS' )
                    ? WP_Sitemaps::SITEMAP_MAX_URLS
                    : 2000;

                $all  = BT_SEO_Sitemap::get_all_asset_urls();
                $page = array_slice( $all, ( $page_num - 1 ) * $per_page, $per_page );
                $list = [];
                foreach ( $page as $u ) {
                    $list[] = [
                        'loc'        => $u['loc'],
                        'lastmod'    => $u['lastmod'],
                        'priority'   => (float) $u['priority'],
                        'changefreq' => $u['changefreq'],
                    ];
                }
                return $list;
            }

            public function get_max_num_pages( $object_subtype = '' ) {
                $per_page = defined( 'WP_Sitemaps::SITEMAP_MAX_URLS' )
                    ? WP_Sitemaps::SITEMAP_MAX_URLS
                    : 2000;
                $count = count( BT_SEO_Sitemap::get_all_asset_urls() );
                return max( 1, (int) ceil( $count / $per_page ) );
            }
        };

        $wp_sitemaps->registry->add_provider( $bt_provider );

        return $provider;
    }

    /* ------------------------------------------------------------------
     * 3. Yoast sub-sitemap
     * ------------------------------------------------------------------ */

    public static function maybe_register_yoast_sitemap() {
        if ( ! defined( 'WPSEO_VERSION' ) ) return;
        // WPSEO_Sitemaps class lives in the sitemaps module; check it exists.
        if ( ! class_exists( 'WPSEO_Sitemaps' ) ) return;

        add_filter( 'wpseo_sitemap_index', [ __CLASS__, 'yoast_sitemap_index_entry' ] );
        add_action( 'wpseo_do_sitemap_bt-assets', [ __CLASS__, 'yoast_serve_sitemap' ] );
    }

    /**
     * Inject our sub-sitemap URL into the Yoast sitemap index.
     *
     * @param string $index Existing XML string from Yoast.
     * @return string
     */
    public static function yoast_sitemap_index_entry( $index ) {
        $entry = sprintf(
            '<sitemap><loc>%s</loc><lastmod>%s</lastmod></sitemap>',
            esc_url( home_url( '/blockticker-assets-sitemap.xml' ) ),
            gmdate( 'Y-m-d\TH:i:s+00:00' )
        );
        return $index . $entry;
    }

    /** Called by Yoast when it serves /bt-assets-sitemap.xml. */
    public static function yoast_serve_sitemap() {
        echo self::get_or_build_xml(); // phpcs:ignore WordPress.Security.EscapeOutput
    }

    /* ------------------------------------------------------------------
     * 4. robots.txt
     * ------------------------------------------------------------------ */

    /**
     * Append a Sitemap: directive so crawlers discover the asset sitemap
     * even without following the Yoast/core sitemap index.
     *
     * @param string $output  Existing robots.txt content.
     * @param bool   $public  Whether the site is public.
     * @return string
     */
    public static function append_robots_txt( $output, $public ) {
        if ( '1' !== (string) $public ) return $output;
        $url = home_url( '/' . self::URL_SLUG );
        // Don't duplicate if already present (e.g., after two activations).
        if ( strpos( $output, $url ) !== false ) return $output;
        return $output . "\nSitemap: " . esc_url( $url ) . "\n";
    }

    /* ------------------------------------------------------------------
     * Data: collect all asset URLs
     * ------------------------------------------------------------------ */

    /**
     * Build the canonical list of URLs to include in the sitemap.
     * Reads from wp_options — same source the frontend widgets use.
     *
     * @return array[]  Each element: [ loc, lastmod, changefreq, priority ]
     */
    public static function get_all_asset_urls() {
        $today = gmdate( 'Y-m-d' );
        $urls  = [];

        // --- Crypto coins ---
        $crypto = self::safe_option( 'fxlm_crypto_data' );
        foreach ( $crypto['coins'] ?? [] as $coin ) {
            $id = $coin['id'] ?? '';
            if ( ! $id ) continue;
            $slug = sanitize_title( $id );
            if ( ! $slug ) continue;

            // Higher priority for top-10 by market-cap rank.
            $rank     = (int) ( $coin['market_cap_rank'] ?? 999 );
            $priority = $rank <= 10 ? '0.9' : ( $rank <= 50 ? '0.8' : '0.7' );

            $urls[] = [
                'loc'        => home_url( '/crypto/' . $slug . '/' ),
                'lastmod'    => $today,
                'changefreq' => 'hourly',
                'priority'   => $priority,
            ];
        }

        // --- Forex pairs ---
        $forex = self::safe_option( 'fxlm_forex_data' );
        foreach ( array_keys( $forex['rates'] ?? [] ) as $pair ) {
            $slug = strtolower( str_replace( '/', '-', $pair ) );
            if ( ! $slug ) continue;
            $urls[] = [
                'loc'        => home_url( '/forex/' . $slug . '/' ),
                'lastmod'    => $today,
                'changefreq' => 'hourly',
                'priority'   => '0.7',
            ];
        }

        // v119.10: third-party / sibling URL providers (e.g. forecast pages)
        // can append their own entries via this filter.
        $urls = apply_filters( 'bt_sitemap_extra_urls', $urls );

        return $urls;
    }

    /* ------------------------------------------------------------------
     * XML generation + caching
     * ------------------------------------------------------------------ */

    /**
     * Return cached XML or regenerate if stale/missing.
     *
     * @return string  Raw XML string.
     */
    public static function get_or_build_xml() {
        $last_built = (int) get_option( self::TIMESTAMP_KEY, 0 );
        if ( ( time() - $last_built ) < self::CACHE_TTL ) {
            $cached = get_option( self::OPTION_KEY, '' );
            if ( $cached ) return $cached;
        }
        return self::regenerate_and_ping();
    }

    /**
     * Regenerate the sitemap XML, store it in wp_options, and ping search engines.
     *
     * @return string  The freshly built XML.
     */
    public static function regenerate_and_ping() {
        $xml = self::build_xml( self::get_all_asset_urls() );

        // Persist to wp_options (autoload=no — only read on sitemap requests).
        update_option( self::OPTION_KEY, $xml, 'no' );
        update_option( self::TIMESTAMP_KEY, time(), 'no' );

        // Ping search engines (fire-and-forget; silently ignore errors).
        self::ping_search_engines();

        return $xml;
    }

    /**
     * Build the raw XML for an array of URL entries.
     *
     * @param array[] $urls  Output of get_all_asset_urls().
     * @return string
     */
    public static function build_xml( array $urls ) {
        $lines   = [];
        $lines[] = '<?xml version="1.0" encoding="UTF-8"?>';
        $lines[] = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"';
        $lines[] = '        xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"';
        $lines[] = '        xsi:schemaLocation="http://www.sitemaps.org/schemas/sitemap/0.9';
        $lines[] = '          http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd">';
        $lines[] = '<!-- BlockTicker asset sitemap — ' . count( $urls ) . ' URLs — generated ' . gmdate( 'Y-m-d H:i:s' ) . ' UTC -->';

        foreach ( $urls as $u ) {
            $lines[] = "\t<url>";
            $lines[] = "\t\t<loc>" . esc_url( $u['loc'] ) . "</loc>";
            $lines[] = "\t\t<lastmod>" . esc_html( $u['lastmod'] ) . "</lastmod>";
            $lines[] = "\t\t<changefreq>" . esc_html( $u['changefreq'] ) . "</changefreq>";
            $lines[] = "\t\t<priority>" . esc_html( $u['priority'] ) . "</priority>";
            $lines[] = "\t</url>";
        }

        $lines[] = '</urlset>';
        return implode( "\n", $lines );
    }

    /* ------------------------------------------------------------------
     * Search engine pings
     * ------------------------------------------------------------------ */

    /**
     * Notify Google and Bing that the sitemap has been updated.
     * Uses wp_remote_get with a 5-second timeout; errors are silently swallowed
     * so a network hiccup never breaks the cron job.
     */
    private static function ping_search_engines() {
        $sitemap_url = rawurlencode( home_url( '/' . self::URL_SLUG ) );
        $endpoints   = [
            'Google' => 'https://www.google.com/ping?sitemap=' . $sitemap_url,
            'Bing'   => 'https://www.bing.com/ping?sitemap='   . $sitemap_url,
        ];
        foreach ( $endpoints as $engine => $url ) {
            wp_remote_get( $url, [
                'timeout'    => 5,
                'blocking'   => false, // fire-and-forget
                'user-agent' => 'BlockTicker/' . BT_VERSION . ' (sitemap ping)',
            ] );
        }
    }

    /* ------------------------------------------------------------------
     * Admin: sitemap info panel (injected into BT_DB_Admin screen)
     * ------------------------------------------------------------------ */

    /**
     * Return an HTML snippet showing sitemap health for the admin screen.
     * Called by BT_DB_Admin if it detects BT_SEO_Sitemap exists.
     *
     * @return string  HTML.
     */
    public static function admin_panel_html() {
        $last_built  = (int) get_option( self::TIMESTAMP_KEY, 0 );
        $url_count   = count( self::get_all_asset_urls() );
        $sitemap_url = home_url( '/' . self::URL_SLUG );
        $age         = $last_built ? human_time_diff( $last_built ) . ' ago' : 'Never';
        $next_cron   = wp_next_scheduled( 'bt_regenerate_sitemap' );
        $next_str    = $next_cron ? human_time_diff( $next_cron ) . ' from now' : 'Not scheduled';

        ob_start(); ?>
        <div class="bt-panel">
            <h3>🗺 Asset Sitemap</h3>
            <table class="widefat striped" style="margin-top:8px">
                <tbody>
                    <tr><th>URL</th><td><a href="<?php echo esc_url( $sitemap_url ); ?>" target="_blank"><?php echo esc_html( $sitemap_url ); ?></a></td></tr>
                    <tr><th>URLs indexed</th><td><?php echo number_format( $url_count ); ?></td></tr>
                    <tr><th>Last built</th><td><?php echo esc_html( $age ); ?></td></tr>
                    <tr><th>Next rebuild</th><td><?php echo esc_html( $next_str ); ?></td></tr>
                </tbody>
            </table>
            <p style="margin-top:8px">
                <button type="button" class="button button-secondary" id="bt-rebuild-sitemap">↻ Rebuild &amp; Ping Now</button>
                <span id="bt-sitemap-msg" style="margin-left:10px;display:none"></span>
            </p>
        </div>
        <script>
        (function(){
            const btn = document.getElementById('bt-rebuild-sitemap');
            if(!btn) return;
            btn.addEventListener('click', function(){
                btn.disabled = true;
                document.getElementById('bt-sitemap-msg').style.display='';
                document.getElementById('bt-sitemap-msg').textContent = 'Rebuilding…';
                fetch(ajaxurl, {
                    method:'POST',
                    headers:{'Content-Type':'application/x-www-form-urlencoded'},
                    body:'action=bt_rebuild_sitemap&_ajax_nonce=<?php echo esc_js( wp_create_nonce('bt_rebuild_sitemap') ); ?>'
                }).then(r=>r.json()).then(d=>{
                    document.getElementById('bt-sitemap-msg').textContent = d.success ? '✓ Rebuilt — '+d.data.count+' URLs, Google+Bing pinged.' : '✗ '+d.data;
                    btn.disabled = false;
                }).catch(()=>{ document.getElementById('bt-sitemap-msg').textContent='Error'; btn.disabled=false; });
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * AJAX handler for the admin rebuild button.
     * Requires manage_options + nonce.
     */
    public static function ajax_rebuild() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied', 403 );
        }
        check_ajax_referer( 'bt_rebuild_sitemap' );

        self::regenerate_and_ping();
        $count = count( self::get_all_asset_urls() );
        wp_send_json_success( [ 'count' => $count ] );
    }

    /* ------------------------------------------------------------------
     * Deactivation cleanup
     * ------------------------------------------------------------------ */

    public static function deactivate() {
        wp_clear_scheduled_hook( 'bt_regenerate_sitemap' );
        // Leave option data intact — reinstalling recovers the cached sitemap.
    }

    /* ------------------------------------------------------------------
     * Helper
     * ------------------------------------------------------------------ */

    /**
     * Read a serialised JSON option safely.
     *
     * @param string $key  Option name.
     * @return array
     */
    private static function safe_option( $key ) {
        $raw = get_option( $key );
        if ( is_array( $raw ) ) return $raw;
        if ( is_string( $raw ) ) {
            $decoded = json_decode( $raw, true );
            return is_array( $decoded ) ? $decoded : [];
        }
        return [];
    }
}
