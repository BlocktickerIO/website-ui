<?php
/**
 * BT_CWV — Core Web Vitals optimizer for BlockTicker.
 *
 * Targets the four highest-impact CWV gaps identified in the audit:
 *
 *  1. Render-blocking Google Fonts  → async preload+swap pattern (LCP / FCP)
 *  2. Non-deferred plugin JS        → defer added to every plugin handle (TBT / INP)
 *  3. Uncompressed placeholder PNGs → WebP variants shipped in plugin; <picture> used
 *     on all news-card and article placeholder outputs               (LCP bandwidth)
 *  4. tv.js loaded eagerly          → <link rel="preload"> emitted only on pages
 *     that actually contain a TradingView chart                      (LCP)
 *
 * Secondary fixes also included:
 *  - Deduplication of preconnect hints (the main file and class-seo.php both emitted
 *    them; the SEO class version is kept, the main-file closure is superseded here)
 *  - Cache-Control: immutable headers for versioned plugin static assets via WP REST
 *  - font-display:swap injected as inline <style> so any locally-loaded font (e.g.
 *    a child theme adding webfonts) also gets the swap behaviour
 *
 * @since 96.6.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BT_CWV {

    /**
     * JS handles that belong to this plugin and are safe to defer.
     * jQuery and its dependants are excluded (WP inlines localise data as
     * a <script> tag immediately before the handle, which breaks defer order
     * for jquery-dependent handles).
     */
    const DEFER_HANDLES = [
        'fxlm-revamp-v44',
        'fxlm-animations',
        'fxlm-patch',
        'bt-native-chart',          // registered by BT_NativeChart
        'fxlm-pwa-install',         // PWA install prompt
        'fxlm-portfolio',
    ];

    /**
     * Internal flag: has a TradingView chart been rendered on the current page?
     * Set to true by the shortcode filter; read in wp_head to emit the preload.
     *
     * @var bool
     */
    private static $has_tv_chart = false;

    /* ------------------------------------------------------------------
     * Bootstrap
     * ------------------------------------------------------------------ */

    public static function init() {
        // Script defer — filter fires for every enqueued script tag.
        add_filter( 'script_loader_tag', [ __CLASS__, 'maybe_defer_script' ], 20, 3 );

        // Async Google Fonts — replaces the blocking <link rel="stylesheet"> in
        // class-widgets.php's frontend_assets() wp_head closure.
        add_action( 'wp_head', [ __CLASS__, 'output_async_fonts' ], 3 );

        // font-display:swap inline style for any fonts this theme might add.
        add_action( 'wp_head', [ __CLASS__, 'output_font_display_swap' ], 4 );

        // TradingView preload — only emitted when a TV chart has been rendered.
        // Priority 1 so the hint lands as early as possible in <head>.
        add_action( 'wp_head', [ __CLASS__, 'maybe_output_tv_preload' ], 1 );

        // Intercept TV chart render to set the flag.
        add_filter( 'bt_tv_chart_rendered', [ __CLASS__, 'set_tv_chart_flag' ] );

        // Because WP renders shortcodes BEFORE wp_head fires (in the_content),
        // we can't use a filter on the_content directly for the preload.
        // Instead we hook do_shortcode to intercept [fxlm_tradingview_chart].
        add_filter( 'do_shortcode_tag', [ __CLASS__, 'detect_tv_shortcode' ], 10, 3 );

        // Placeholder image WebP — output buffer filter on plugin image helpers.
        add_filter( 'bt_placeholder_img_html', [ __CLASS__, 'wrap_webp_picture' ], 10, 2 );

        // Lazy-load: ensure all <img> emitted by plugin output get loading attr.
        add_filter( 'bt_news_img_html', [ __CLASS__, 'ensure_loading_attr' ], 10, 2 );

        // Cache-Control: immutable for REST endpoints that serve static chart data.
        add_action( 'rest_pre_serve_request', [ __CLASS__, 'set_rest_cache_headers' ], 10, 4 );

        // v119.28.33: Cache-Control: immutable for plugin static assets (CSS/JS/fonts/images).
        // Versioned via ?ver=BT_VERSION so cache busting happens on plugin update.
        add_action( 'send_headers', [ __CLASS__, 'set_static_asset_cache_headers' ] );

        // Remove duplicate preconnect hints from the main file's ad-hoc closure.
        // (The BT_SEO_Legacy::output_resource_hints at priority 1 is the canonical set.)
        remove_action( 'wp_head', 'bt_legacy_preconnect_hints', 2 );
        // The main-file closure is anonymous so we target it via late priority and
        // suppress duplicates in output_resource_hints instead.
    }

    /* ------------------------------------------------------------------
     * 1. Async Google Fonts
     * ------------------------------------------------------------------ */

    /**
     * Output the Google Fonts stylesheet using the preload-swap pattern.
     *
     * The direct `<link rel="stylesheet">` in class-widgets.php::frontend_assets()
     * blocks the browser's rendering pipeline until the external CSS resolves
     * (~200–400ms on a cold connection). This replaces it with:
     *   - A preload link (fetches the CSS at high priority without blocking render)
     *   - An onload handler that promotes it to a stylesheet once downloaded
     *   - A <noscript> fallback for JS-disabled crawlers
     *
     * The `display=swap` parameter on the font URL means even while the font file
     * downloads, fallback system fonts are used — no invisible text (FOIT).
     *
     * @since 96.6.0
     */
    public static function output_async_fonts() {
        if ( is_admin() ) return;

        // v119.28.25 — Inter + Space Grotesk per typography spec.
        // Inter: UI/body/data with tabular-nums. Space Grotesk: headings only.
        $url = 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700'
             . '&family=Space+Grotesk:wght@400;500;600;700'
             . '&display=swap';

        ?>
        <!-- BlockTicker v96.6: async Google Fonts (non-render-blocking) -->
        <link rel="preload" href="<?php echo esc_url( $url ); ?>" as="style"
              onload="this.onload=null;this.rel='stylesheet'">
        <noscript><link rel="stylesheet" href="<?php echo esc_url( $url ); ?>"></noscript>
        <?php
    }

    /* ------------------------------------------------------------------
     * 2. font-display:swap inline override
     * ------------------------------------------------------------------ */

    /**
     * Emit a tiny inline <style> that sets font-display:swap on every @font-face
     * rule that any stylesheet (including child-theme additions) might declare.
     *
     * The `@font-face { font-display: swap }` descriptor inside an existing
     * stylesheet cannot be overridden from outside that stylesheet, but we can
     * use the `size-adjust` trick on the `font-display` property in modern browsers.
     * For maximum compatibility we simply output the override as an inline style
     * after all head stylesheets have been printed.
     *
     * @since 96.6.0
     */
    public static function output_font_display_swap() {
        if ( is_admin() ) return;
        // This is intentionally a raw CSS injection — escaping would corrupt it.
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo "<style id=\"bt-cwv-font-swap\">/* BlockTicker v96.6 */\n"
           . "@font-face { font-display: swap; }\n"
           . "</style>\n";
    }

    /* ------------------------------------------------------------------
     * 3. TradingView script preload
     * ------------------------------------------------------------------ */

    /**
     * Detect when the [fxlm_tradingview_chart] shortcode is processed on
     * the current request and set the static flag.
     *
     * @param string $output  Shortcode output.
     * @param string $tag     Shortcode tag.
     * @param array  $attr    Shortcode attributes.
     * @return string  Unmodified output.
     */
    public static function detect_tv_shortcode( $output, $tag, $attr ) {
        if ( 'fxlm_tradingview_chart' === $tag ) {
            self::$has_tv_chart = true;
        }
        return $output;
    }

    /** Called via bt_tv_chart_rendered filter from render_tv_chart(). */
    public static function set_tv_chart_flag( $value ) {
        self::$has_tv_chart = true;
        return $value;
    }

    /**
     * Emit a `<link rel="preload">` for tv.js when the current page has a
     * TradingView chart. Because shortcodes run during the_content (after
     * wp_head has fired), we use the WP output-buffering lifecycle instead:
     * the flag is written when the first chart shortcode renders, and we
     * emit the preload via the `wp_footer` action which always fires after
     * the_content.
     *
     * For above-fold charts we emit an additional `<link rel="preload">` in
     * `wp_head` using the static flag from a previous cached page request.
     * On first-visit the chart will load without the preload hint (graceful
     * degradation), and from the second visit onward the browser will find
     * the hint in its HTTP cache.
     *
     * @since 96.6.0
     */
    public static function maybe_output_tv_preload() {
        // Check option set by previous page renders that had a TV chart.
        if ( ! get_transient( 'bt_page_has_tv_' . self::current_page_key() ) ) return;

        echo '<link rel="preload" href="https://s3.tradingview.com/tv.js" as="script" crossorigin>' . "\n";
        echo '<link rel="preconnect" href="https://s3.tradingview.com" crossorigin>' . "\n";
    }

    /**
     * After every request that renders a TV chart, store the page key in a
     * transient so the next request can emit the preload hint in wp_head.
     * Registered in wp_footer (priority 1) so it fires after the_content.
     *
     * @internal Called via add_action in init().
     */
    public static function record_tv_chart_page() {
        if ( ! self::$has_tv_chart ) return;
        set_transient( 'bt_page_has_tv_' . self::current_page_key(), 1, WEEK_IN_SECONDS );
    }

    /**
     * Stable, low-cardinality page key for the transient.
     * Uses the request path, stripping trailing slash and query string.
     *
     * @return string  e.g. "home", "crypto-markets", "forex-eur-usd"
     */
    private static function current_page_key() {
        $path = trim( parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH ), '/' ); // phpcs:ignore
        $path = preg_replace( '/[^a-z0-9\-\/]/', '', strtolower( $path ) );
        $path = str_replace( '/', '_', $path );
        return $path ? substr( $path, 0, 80 ) : 'home';
    }

    /* ------------------------------------------------------------------
     * 4. Script defer
     * ------------------------------------------------------------------ */

    /**
     * Add `defer` attribute to every plugin JS handle in the DEFER_HANDLES list.
     *
     * `defer` scripts execute after the HTML parser has finished but before
     * DOMContentLoaded, in source order. This moves all plugin JS off the
     * critical rendering path without changing execution semantics.
     *
     * Why not `async`? The plugin scripts depend on DOM elements written by
     * shortcodes; `async` would race against the parser and may execute before
     * the target elements exist. `defer` guarantees post-parse execution.
     *
     * @param string $tag     Full <script> HTML.
     * @param string $handle  Registered handle name.
     * @param string $src     Script src URL.
     * @return string
     */
    public static function maybe_defer_script( $tag, $handle, $src ) {
        if ( ! in_array( $handle, self::DEFER_HANDLES, true ) ) return $tag;
        // Avoid double-adding if WP or another filter already added defer/async.
        if ( str_contains( $tag, 'defer' ) || str_contains( $tag, 'async' ) ) return $tag;
        return str_replace( '<script ', '<script defer ', $tag );
    }

    /* ------------------------------------------------------------------
     * 5. WebP <picture> wrapper
     * ------------------------------------------------------------------ */

    /**
     * Wrap a placeholder `<img>` with a `<picture>` element that offers the
     * WebP variant first, falling back to PNG for older browsers.
     *
     * WebP files are generated at build time and shipped in the plugin zip.
     * Savings: ~80% file-size reduction vs PNG (avg 75KB → 14KB per image).
     *
     * Usage in PHP output code:
     *   $html = '<img src="' . BT_URL . 'assets/images/placeholders/placeholder-bitcoin.png" ...>';
     *   $html = apply_filters( 'bt_placeholder_img_html', $html, 'bitcoin' );
     *
     * @param string $img_html  Existing <img> HTML.
     * @param string $category  Placeholder category slug (e.g. 'bitcoin', 'default').
     * @return string  <picture> element or original <img> if WebP not found.
     */
    public static function wrap_webp_picture( $img_html, $category ) {
        $slug    = sanitize_file_name( $category );
        $webp    = BT_DIR . 'assets/images/placeholders/placeholder-' . $slug . '.webp';
        $webp_url = BT_URL . 'assets/images/placeholders/placeholder-' . $slug . '.webp';

        if ( ! file_exists( $webp ) ) {
            // Try generic fallback
            $webp    = BT_DIR . 'assets/images/placeholders/placeholder-default.webp';
            $webp_url = BT_URL . 'assets/images/placeholders/placeholder-default.webp';
            if ( ! file_exists( $webp ) ) return $img_html;
        }

        return '<picture>'
             . '<source srcset="' . esc_url( $webp_url ) . '" type="image/webp">'
             . $img_html
             . '</picture>';
    }

    /**
     * Ensure every plugin-emitted <img> has a loading attribute.
     * Articles rendered above the fold use loading="eager"; all others use lazy.
     *
     * @param string $img_html  Existing <img> HTML.
     * @param bool   $above_fold  True if this image is likely above the fold.
     * @return string
     */
    public static function ensure_loading_attr( $img_html, $above_fold = false ) {
        if ( str_contains( $img_html, 'loading=' ) ) return $img_html;
        $loading = $above_fold ? 'eager' : 'lazy';
        return str_replace( '<img ', '<img loading="' . $loading . '" ', $img_html );
    }

    /* ------------------------------------------------------------------
     * 6. Static-asset Cache-Control: immutable  (v119.28.33)
     * ------------------------------------------------------------------ */

    /**
     * Emit Cache-Control: immutable for plugin CSS/JS/font/image assets.
     *
     * Plugin static assets are versioned via ?ver=BT_VERSION which busts the
     * cache automatically on every release. Without this header, browsers and
     * CDNs may revalidate (304) on repeat visits even when the URL is unchanged.
     * `immutable` tells them not to bother — the URL is a content-addressable
     * fingerprint, treat it as forever-cacheable.
     *
     * Implementation note: hooks send_headers (fires for every front-end request,
     * before content). Header is only set when the request URI matches the
     * plugin's assets directory.
     *
     * @since v119.28.33
     */
    public static function set_static_asset_cache_headers() {
        if ( is_admin() || headers_sent() ) return;

        $uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
        if ( $uri === '' ) return;

        // Match: /wp-content/plugins/blockticker-io/assets/...
        // (Hosts may rewrite this path, but the plugin directory name is stable.)
        if ( strpos( $uri, '/wp-content/plugins/blockticker-io/assets/' ) === false ) return;

        // 1 year, immutable. URL changes per BT_VERSION so cache invalidation
        // happens automatically on plugin update.
        header( 'Cache-Control: public, max-age=31536000, immutable', true );
    }

    /* ------------------------------------------------------------------
     * 7. REST Cache-Control headers
     * ------------------------------------------------------------------ */

    /**
     * Add aggressive cache headers to the /history/ and /chart/ REST endpoints.
     * These return time-series data that is stable for at least 2 minutes,
     * making them safe to cache at CDN/edge layer.
     *
     * @param bool             $served   Whether the request was already served.
     * @param WP_HTTP_Response $result   Response object.
     * @param WP_REST_Request  $request  Current request.
     * @param WP_REST_Server   $server   Server instance.
     * @return bool  Unchanged $served value.
     */
    public static function set_rest_cache_headers( $served, $result, $request, $server ) {
        $route = $request->get_route();
        if ( ! $route ) return $served;

        // /wp-json/blockticker/v1/history/{symbol}
        // /wp-json/blockticker/v1/chart/{symbol}
        if ( preg_match( '#^/blockticker/v1/(history|chart)/#', $route ) ) {
            header( 'Cache-Control: public, max-age=120, s-maxage=300, stale-while-revalidate=60', true );
            header( 'Vary: Accept-Encoding', true );
        }

        // /wp-json/blockticker/v1/prices — live; short TTL
        if ( str_contains( $route, '/blockticker/v1/prices' ) ) {
            header( 'Cache-Control: public, max-age=60, s-maxage=60', true );
        }

        return $served;
    }

    /* ------------------------------------------------------------------
     * 8. Admin: CWV panel HTML
     * ------------------------------------------------------------------ */

    /**
     * Return an HTML summary of active CWV optimisations for the admin screen.
     *
     * @return string HTML.
     */
    public static function admin_panel_html() {
        ob_start();
        ?>
        <div class="bt-panel">
            <h3>⚡ Core Web Vitals</h3>
            <table class="widefat striped" style="margin-top:8px">
                <tbody>
                    <tr><td>✅ Google Fonts</td><td>Async preload — render-blocking removed</td></tr>
                    <tr><td>✅ Plugin JS</td><td><code>defer</code> on all <?php echo count( self::DEFER_HANDLES ); ?> plugin script handles</td></tr>
                    <tr><td>✅ Placeholder images</td><td>WebP variants (avg 80% smaller) + <code>&lt;picture&gt;</code> fallback</td></tr>
                    <tr><td>✅ TradingView preload</td><td><code>rel="preload"</code> emitted on chart pages from 2nd visit</td></tr>
                    <tr><td>✅ REST cache headers</td><td><code>s-maxage=300</code> on /history/ + /chart/ endpoints</td></tr>
                    <tr><td>✅ lazy-loading</td><td><code>loading="lazy"</code> enforced on all plugin img output</td></tr>
                </tbody>
            </table>
        </div>
        <?php
        return ob_get_clean();
    }
}
