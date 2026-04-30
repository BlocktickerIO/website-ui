<?php
/**
 * BlockTicker Landing Takeover (v119.28.19)
 *
 * Serves the clean mockup HTML directly at the homepage URL, bypassing all
 * WordPress theme rendering and all legacy plugin chrome (cp-navbar,
 * cp-footer, fxlm-bottom-ticker, mobile bottom nav, subscribe popup, install
 * banner, cookie banner, etc.).
 *
 * The mockup file `templates/clean/landing.php` is the single source of
 * truth for the homepage design. PHP variables are interpolated at render
 * time to point links to real WordPress URLs.
 *
 * Result: pixel-identical match to the mockup, with zero possibility of
 * theme/plugin chrome leaking into the rendered HTML.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class BT_Landing_Takeover {

    const OPT_DISABLE = 'bt_disable_landing_takeover';

    public static function init() {
        add_action( 'template_redirect', array( __CLASS__, 'maybe_takeover' ), 1 );
    }

    public static function maybe_takeover() {
        if ( get_option( self::OPT_DISABLE ) === '1' ) { return; }
        if ( ! is_front_page() && ! is_home() ) { return; }
        if ( is_admin() ) { return; }
        if ( wp_doing_ajax() ) { return; }
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) { return; }
        if ( is_feed() ) { return; }
        if ( ! empty( $_POST ) ) { return; } // phpcs:ignore WordPress.Security.NonceVerification

        self::render_clean_mockup();
        exit;
    }

    /**
     * Output the mockup HTML with PHP variables interpolated.
     * Includes wp_head() and wp_footer() for analytics, tracking, etc.,
     * but suppresses all hooks that would render legacy chrome.
     * Just the clean designer-specified HTML.
     */
    private static function render_clean_mockup() {
        $template = BT_DIR . 'templates/clean/landing.php';
        if ( ! file_exists( $template ) ) {
            // Defensive fallback — shouldn't happen, but if the file is missing
            // we don't want a fatal error. Disable the takeover and let WP render.
            return;
        }

        // Suppress every hook that renders legacy chrome BEFORE wp_head/footer fire.
        self::suppress_chrome_hooks();

        // Dequeue plugin & theme CSS that would override the mockup's inline styles.
        // The mockup is the single source of truth for design — nothing else competes.
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'dequeue_competing_styles' ), 9999 );

        // Status header
        status_header( 200 );
        nocache_headers();
        header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );

        // Capture wp_head() output so we can inject it into the mockup's <head>
        ob_start();
        wp_head();
        $head_extras = ob_get_clean();

        // Capture wp_footer() output so we can inject before </body>
        ob_start();
        wp_footer();
        $footer_extras = ob_get_clean();

        // Read the template into a buffer, run PHP includes
        ob_start();
        include $template;
        $html = ob_get_clean();

        // ============================================================
        // v119.28.29 — DISABLED: inject_live_prices() was producing an
        // orphan text node (e.g. ",287.53") between </frame__tabs> and
        // <table class="frame__table"> on certain HTML inputs. The JS
        // liveMarkets() and liveTicker() functions already fetch real
        // prices from /wp-json/blockticker/v1/prices within ~500ms of
        // first paint, so the demo numbers only show for half a second
        // before being replaced. The trade-off is worth it — the leak
        // was visible to every user, the demo flash is barely visible.
        // ============================================================
        // $html = self::inject_live_prices( $html );  // intentionally disabled

        // Inject wp_head extras BEFORE the mockup's <style> block, so the mockup's
        // inline CSS wins on equal-specificity rules (later rules win in CSS cascade).
        // Strategy: insert wp_head extras right after <head> opens, before any styles.
        $html = preg_replace(
            '/(<head[^>]*>)/i',
            "$1\n" . $head_extras . "\n",
            $html,
            1
        );

        // Inject a final defensive style block AT THE END of <head> that re-asserts
        // the critical mockup design tokens. This wins because it's the LAST
        // <style> in <head>, beating any plugin/theme CSS that wp_head injected.
        $defensive_css = self::defensive_design_tokens();
        $html = str_replace( '</head>', $defensive_css . "\n</head>", $html );

        // Inject wp_footer extras just before </body>
        $html = str_replace( '</body>', $footer_extras . "\n</body>", $html );

        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput
    }

    /**
     * v119.28.24 — Replace demo prices in the rendered mockup HTML with live
     * prices from the BT options cache (fxlm_crypto_data, fxlm_forex_data).
     *
     * If live data isn't available yet (fresh install), the demo numbers stay
     * and the JS update kicks in within seconds. This guarantees no flash of
     * demo numbers when live data IS available.
     */
    private static function inject_live_prices( $html ) {
        // Pull live data from option cache
        $crypto_raw = get_option( 'fxlm_crypto_data', '' );
        $forex_raw  = get_option( 'fxlm_forex_data',  '' );
        $crypto = is_string( $crypto_raw ) ? json_decode( $crypto_raw, true ) : (array) $crypto_raw;
        $forex  = is_string( $forex_raw )  ? json_decode( $forex_raw,  true ) : (array) $forex_raw;
        if ( ! is_array( $crypto ) || empty( $crypto['coins'] ) ) { return $html; }

        // Build symbol -> {price, chg_pct, chg_str, cls} map for the symbols we care about
        $by_sym = array();
        foreach ( (array) $crypto['coins'] as $coin ) {
            if ( empty( $coin['symbol'] ) ) { continue; }
            $sym = strtoupper( $coin['symbol'] );
            $price = isset( $coin['current_price'] ) ? floatval( $coin['current_price'] ) : 0;
            $chg   = isset( $coin['price_change_percentage_24h'] ) ? floatval( $coin['price_change_percentage_24h'] ) : 0;
            if ( $price <= 0 ) { continue; }
            $by_sym[ $sym ] = array(
                'price'   => $price,
                'chg_pct' => $chg,
                'price_s' => self::fmt_price( $price ),
                'chg_s'   => ( $chg >= 0 ? '+' : '' ) . number_format( $chg, 2 ) . '%',
                'cls'     => $chg >= 0 ? 'up' : 'down',
            );
        }

        // ---- Replace ticker bar items (top of page) ----
        // Pattern: <span class="ticker__item"><span class="ticker__sym">BTC</span> $67,892.43 <span class="ticker__chg--up">+2.45%</span></span>
        foreach ( array( 'BTC', 'ETH', 'SOL', 'AVAX', 'XRP' ) as $sym ) {
            if ( ! isset( $by_sym[ $sym ] ) ) { continue; }
            $d = $by_sym[ $sym ];
            $html = preg_replace(
                '#(<span class="ticker__item"><span class="ticker__sym">' . preg_quote( $sym, '#' ) . '</span>\s*)[^<]+(\s*<span class="ticker__chg--)(?:up|down)(">)[^<]+(</span>)#u',
                '$1' . esc_html( $d['price_s'] ) . ' $2' . $d['cls'] . '$3' . esc_html( $d['chg_s'] ) . '$4',
                $html
            );
        }

        // ---- Replace markets table rows (data layer section) ----
        // Each row: <tr data-symbol="BTC" ...><td class="frame__price" data-cell="price">$67,892.43</td><td class="frame__chg frame__chg--up" data-cell="chg">+2.45%</td>...
        foreach ( array( 'BTC', 'ETH', 'SOL', 'AVAX', 'XRP' ) as $sym ) {
            if ( ! isset( $by_sym[ $sym ] ) ) { continue; }
            $d = $by_sym[ $sym ];
            // price cell
            $html = preg_replace(
                '#(<tr data-symbol="' . preg_quote( $sym, '#' ) . '"[^>]*>.*?<td class="frame__price" data-cell="price">)[^<]+(</td>)#us',
                '$1' . esc_html( $d['price_s'] ) . '$2',
                $html
            );
            // change cell — also flip the up/down class
            $html = preg_replace(
                '#(<tr data-symbol="' . preg_quote( $sym, '#' ) . '"[^>]*>.*?<td class="frame__chg frame__chg--)(?:up|down)(" data-cell="chg">)[^<]+(</td>)#us',
                '$1' . $d['cls'] . '$2' . esc_html( $d['chg_s'] ) . '$3',
                $html
            );
        }

        // ---- Replace EUR/USD, DXY, USD/JPY in ticker bar (forex section) ----
        if ( ! empty( $forex['rates'] ) && is_array( $forex['rates'] ) ) {
            $rates = $forex['rates'];
            $eur_usd = ! empty( $rates['EUR'] ) && $rates['EUR'] > 0 ? 1 / floatval( $rates['EUR'] ) : 0;
            if ( $eur_usd > 0 ) {
                $html = preg_replace(
                    '#(<span class="ticker__item"><span class="ticker__sym">EUR/USD</span>\s*)[^<]+(\s*<span)#u',
                    '$1' . number_format( $eur_usd, 4 ) . ' $2',
                    $html
                );
            }
            $usd_jpy = ! empty( $rates['JPY'] ) ? floatval( $rates['JPY'] ) : 0;
            if ( $usd_jpy > 0 ) {
                $html = preg_replace(
                    '#(<span class="ticker__item"><span class="ticker__sym">USD/JPY</span>\s*)[^<]+(\s*<span)#u',
                    '$1' . number_format( $usd_jpy, 2 ) . ' $2',
                    $html
                );
            }
        }

        return $html;
    }

    private static function fmt_price( $n ) {
        if ( $n >= 1000 ) { return '$' . number_format( $n, 2 ); }
        if ( $n >= 1 )    { return '$' . number_format( $n, 2 ); }
        return '$' . number_format( $n, 4 );
    }

    /**
     * Final-cascade CSS that locks in the mockup's design tokens against
     * any theme/plugin overrides.
     */
    private static function defensive_design_tokens() {
        return <<<CSS
<style id="bt-takeover-defensive">
/* v119.28.21 — final-cascade lock for mockup design tokens.
   These rules sit LAST in <head> so they win over any wp_head-injected CSS.
   IMPORTANT: keep this MINIMAL. The mockup's own <style> is the source of
   truth for colors/typography. We only override what theme/plugin CSS would
   otherwise break. */

/* Body baseline — kill theme background and font */
html, body {
  margin: 0 !important;
  padding: 0 !important;
  background: #0A0B0D !important;
  color: #fff !important;
  font-family: 'Inter', 'SF Pro Text', -apple-system, BlinkMacSystemFont, sans-serif !important;
  font-size: 15px !important;
  line-height: 1.55 !important;
  -webkit-font-smoothing: antialiased !important;
  -moz-osx-font-smoothing: grayscale !important;
  font-feature-settings: 'cv11' 1, 'ss01' 1;
}
body * { box-sizing: border-box; }

/* Headings: force Space Grotesk display font (per typography spec) */
h1, h2, h3, h4, h5, h6 {
  font-family: 'Space Grotesk', 'SF Pro Display', -apple-system, sans-serif !important;
  letter-spacing: -.02em !important;
}

/* Numeric / data — Inter with tabular-nums + slashed-zero (Coinbase/TradingView pattern).
   No separate mono font — Inter's tabular-nums handles numeric alignment. */
.ticker, .ticker__sym, .ticker__chg--up, .ticker__chg--down, .feed, .feed__sym,
.frame__price, .frame__chg,
.stack__corner-tag, .stack__rail,
.src-card__live, .nav__demo-label,
.howit__step, .howit__state-label,
.outcome__num, .corr__pair, .corr__val--up, .corr__val--down,
.num, .price, .pct, .score,
[data-cell="price"], [data-cell="chg"],
[class*="__mono"] {
  font-family: 'Inter', 'SF Mono', Menlo, Consolas, monospace !important;
  font-variant-numeric: tabular-nums slashed-zero !important;
  font-feature-settings: 'tnum' 1, 'zero' 1, 'cv11' 1, 'ss02' 1 !important;
  font-variant-ligatures: none !important;
}

/* Theme link/anchor reset — prevent theme from coloring all anchors green/blue */
a { color: inherit; text-decoration: none; }

/* Mega menu links — explicit WHITE per mockup (not green) */
.mega__col a,
.mega__col a:link,
.mega__col a:visited {
  color: #e4e4e7 !important; /* var(--text-2) */
  background: transparent !important;
}
.mega__col a:hover {
  color: #ffffff !important;
  background: rgba(0,255,102,.06) !important;
}
.mega__col a strong {
  color: #ffffff !important;
}
/* Only the icon prefix is green */
.mega__col a .mega__ico {
  color: #00FF66 !important;
}
/* "→" arrow links (mega__more) ARE green per mockup */
.mega__col a.mega__more,
.mega__col a.mega__more:link,
.mega__col a.mega__more:visited {
  color: #00FF66 !important;
}

/* Footer column links — WHITE per mockup, not green */
.foot__col a,
.foot__col a:link,
.foot__col a:visited,
.foot__col li a {
  color: #a1a1aa !important; /* var(--text-3) */
  background: transparent !important;
  border-bottom: none !important;
}
.foot__col a:hover { color: #ffffff !important; }

/* Hero accent words: green */
.hero__h1-accent { color: #00FF66 !important; }

/* Buttons — primary green */
.btn:not(.btn--secondary):not(.btn--ghost),
a.btn:not(.btn--secondary):not(.btn--ghost) {
  background: #00FF66 !important;
  color: #0A0B0D !important;
  border: none !important;
  text-decoration: none !important;
}
.nav__signup, a.nav__signup {
  background: #00FF66 !important;
  color: #0A0B0D !important;
}

/* Buttons — secondary outlined */
.btn--secondary, a.btn--secondary {
  background: #1A1C20 !important;
  color: #fff !important;
  border: 1px solid rgba(255,255,255,.10) !important;
}
.btn--ghost, a.btn--ghost {
  background: transparent !important;
  color: #fff !important;
}

/* Pills */
.hero__pill, a.hero__pill, .pill {
  background: rgba(0,255,102,.06) !important;
  color: #00FF66 !important;
  border: 1px solid rgba(0,255,102,.30) !important;
}

/* Remove theme-injected page wrappers */
#page, .site, .site-content, #content, #primary, main, main#main,
.site-main, .entry-content, article.post, article.page,
.content-area, #wrapper, .site-wrapper, #main-content {
  max-width: none !important;
  width: 100% !important;
  padding: 0 !important;
  margin: 0 !important;
  background: transparent !important;
  float: none !important;
}
/* Hide theme headers */
body > header, #masthead, .site-header, .main-navigation, #site-navigation,
.menu-toggle, .navigation-search, .mobile-menu-control-wrapper,
.inside-header, .site-branding, body > .skip-link {
  display: none !important;
}
/* WordPress page-title */
.entry-title, .page-title, .post-title,
header.entry-header, header.page-header,
.wp-block-post-title, h1.wp-block-post-title { display: none !important; }
/* Legacy plugin chrome */
.cp-navbar, #cp-navbar, .cp-navbar-spacer,
.cp-footer, #cp-footer, .cp-footer-bottom,
.fxlm-bottom-ticker, #fxlm-bottom-ticker,
.bt-mobile-nav, #bt-mobile-nav,
.bt-sticky-cta, .bt-back-to-top,
.bt-install-banner, #bt-install-banner,
.bt-subscribe-popup, .gdpr-cookie-banner, .bt-cookie-banner {
  display: none !important;
}

/* v119.28.24 — Ticker explicit colors (some plugin/theme CSS was overriding the mockup) */
.ticker { background: #000 !important; }
.ticker__chg--up   { color: #00FF66 !important; }
.ticker__chg--down { color: #FF3B30 !important; }
.ticker__live      { color: #00FF66 !important; }
.ticker__sym       { color: #ffffff !important; font-weight: 600 !important; }

/* v119.28.26 — Brand text per mockup: BLOCK white + TICKER green */
.nav__name { color: #ffffff !important; }
.nav__name span { color: #00FF66 !important; }
.foot__brand-name { color: #ffffff !important; }
.foot__brand-name span { color: #00FF66 !important; }
.nav__logo {
  background: #00FF66 !important;
  color: #0A0B0D !important;
  font-weight: 900 !important;
}

/* v119.28.26 — Footer social icons: WHITE default with subtle border, green only on hover */
.foot__socials a,
.foot__socials a:link,
.foot__socials a:visited {
  color: #ffffff !important;
  border: 1px solid rgba(255,255,255,.20) !important;
  background: transparent !important;
}
.foot__socials a:hover {
  color: #00FF66 !important;
  border-color: rgba(0,255,102,.50) !important;
}
.foot__socials a svg,
.foot__socials a svg path { fill: currentColor !important; }

/* v119.28.26 — Footer column links: gray default, white on hover (no green) */
.foot__col a,
.foot__col li a,
.foot__col a:link,
.foot__col a:visited {
  color: #a1a1aa !important;
  background: transparent !important;
  border-bottom: none !important;
  text-decoration: none !important;
}
.foot__col a:hover { color: #ffffff !important; }

/* WP admin bar offset */
body.admin-bar { margin-top: 0 !important; }
body.admin-bar .nav { top: 32px !important; }
@media (max-width: 782px) {
  body.admin-bar .nav { top: 46px !important; }
}

/* v119.28.21: Fix sticky panel in howit section.
   Some themes set overflow:hidden on body/main wrappers which breaks
   position:sticky. Force overflow visible on all ancestors of .howit__panel. */
html, body { overflow-x: hidden; overflow-y: visible !important; }
.howit, .howit__grid, .howit > .container,
section.howit, section.howit > * {
  overflow: visible !important;
  contain: none !important;
}
.howit__panel {
  position: sticky !important;
  top: 12vh !important;
  height: 76vh !important;
  align-self: flex-start !important;
}
@media (max-width: 880px) {
  .howit__panel {
    position: relative !important;
    top: 0 !important;
    height: 380px !important;
  }
}
</style>
CSS;
    }

    /**
     * Dequeue plugin and theme styles that would override the mockup's design.
     * Runs at priority 9999 so it fires AFTER all other plugins/themes have
     * registered their styles. Called only on the homepage takeover request.
     */
    public static function dequeue_competing_styles() {
        // Plugin's own old landing CSS — not needed (mockup has its own inline <style>)
        wp_dequeue_style( 'bt-landing-revamp' );
        wp_dequeue_style( 'btlp-gfonts' );          // mockup loads its own Google Fonts

        // Plugin chrome CSS we don't want competing
        wp_dequeue_style( 'fxlm-frontend' );
        wp_dequeue_style( 'fxlm-patch' );
        wp_dequeue_style( 'fxlm-revamp-v44' );
        wp_dequeue_style( 'fxlm-critical' );
        wp_dequeue_style( 'fxlm-admin' );
        // v119.28.32 — DO NOT dequeue 'bt-a11y'. It carries the focus-visible
        // outline, skip link, .bt-sr-only utility, and prefers-reduced-motion
        // overrides. Dequeueing it on the highest-traffic page was a WCAG 2.1
        // AA regression. If it visually conflicts with takeover styles, scope
        // those overrides higher rather than dropping a11y.

        // Common WP theme CSS handles — dequeue if present
        wp_dequeue_style( 'generate-style' );
        wp_dequeue_style( 'generate-style-grid' );
        wp_dequeue_style( 'generate-mobile' );
        wp_dequeue_style( 'astra-theme-css' );
        wp_dequeue_style( 'astra-google-fonts' );
        wp_dequeue_style( 'twentytwentyfour-style' );
        wp_dequeue_style( 'twentytwentythree-style' );
        wp_dequeue_style( 'kadence-global' );
        wp_dequeue_style( 'oceanwp-style' );
        wp_dequeue_style( 'blocksy-styles' );
        wp_dequeue_style( 'hello-elementor' );

        // WP block library — used by Gutenberg, can leak default styles
        wp_dequeue_style( 'wp-block-library' );
        wp_dequeue_style( 'wp-block-library-theme' );
        wp_dequeue_style( 'global-styles' );
        wp_dequeue_style( 'classic-theme-styles' );
    }

    /**
     * Remove every hook that would render duplicate chrome (old plugin nav,
     * old plugin footer, mobile bottom nav, sticky CTA, scroll newsletter,
     * theme header, theme footer).
     */
    private static function suppress_chrome_hooks() {
        // Old plugin chrome
        remove_action( 'wp_body_open', array( 'BT_Navbar', 'render_navbar' ), 1 );
        remove_action( 'wp_footer',    array( 'BT_Navbar', 'render_footer' ), 5 );

        // Old mobile UX
        if ( class_exists( 'BT_Mobile_UX' ) ) {
            remove_action( 'wp_footer', array( 'BT_Mobile_UX', 'render_bottom_nav' ),    20 );
            remove_action( 'wp_footer', array( 'BT_Mobile_UX', 'render_sticky_cta' ),    21 );
            remove_action( 'wp_footer', array( 'BT_Mobile_UX', 'render_back_to_top' ),   22 );
        }

        // EEAT scroll newsletter
        if ( class_exists( 'BT_EEAT' ) ) {
            remove_action( 'wp_footer', array( 'BT_EEAT', 'render_scroll_newsletter' ), 20 );
            remove_action( 'wp_footer', array( 'BT_EEAT', 'render_reading_progress' ), 5 );
        }

        // BT global chrome (page provisioner) — we serve our own complete page
        if ( class_exists( 'BT_Page_Provisioner' ) ) {
            remove_action( 'wp_body_open', array( 'BT_Page_Provisioner', 'render_global_chrome' ), 1 );
            remove_action( 'wp_footer',    array( 'BT_Page_Provisioner', 'close_global_chrome' ), 999 );
        }

        // GeneratePress / Astra
        remove_action( 'generate_header',          'generate_construct_header' );
        remove_action( 'generate_after_header',    'generate_add_navigation_after_header', 5 );
        remove_action( 'generate_footer',          'generate_construct_footer_widgets', 5 );
        remove_action( 'generate_credits',         'generate_add_footer_info' );

        // Avoid the bottom ticker (it gets rendered via shortcode in render_footer)
        // — already handled by removing BT_Navbar::render_footer above.

        // Suppress the [blockticker_landing] shortcode-based asset enqueue
        // for THIS request — it queues the old landing-revamp.css which would
        // collide with the inline styles in our mockup.
        if ( class_exists( 'BT_LandingRevamp' ) ) {
            // Nothing to remove — its enqueue runs only via the shortcode path
            // which we don't invoke here. Safe.
        }
    }
}

BT_Landing_Takeover::init();
