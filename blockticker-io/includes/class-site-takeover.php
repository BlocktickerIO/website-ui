<?php
/**
 * BlockTicker Site-Wide Takeover (v119.28.23)
 *
 * Extends the homepage takeover concept to ALL non-admin front-end pages.
 * Bypasses the WordPress theme entirely on every page, rendering instead:
 *
 *   1. BT chrome (disclaimer + ticker + nav + drawer)
 *   2. WordPress page content (the_title + the_content with shortcodes)
 *   3. BT footer (with proper SVG social icons)
 *
 * This eliminates ALL theme-induced issues:
 *   - empty space above disclaimer (was theme's empty <header>)
 *   - theme typography overrides
 *   - theme footer leaking through
 *   - mobile bottom nav, subscribe popup, install banner, cookie banner
 *
 * The homepage continues to use BT_Landing_Takeover (priority 1, runs first).
 *
 * Escape hatch: bt_disable_site_takeover='1' in wp_options
 *
 * @since 119.28.23
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class BT_Site_Takeover {

    const OPT_DISABLE = 'bt_disable_site_takeover';

    public static function init() {
        // Run after BT_Landing_Takeover (priority 1) so the homepage gets handled first.
        add_action( 'template_redirect', array( __CLASS__, 'maybe_takeover' ), 2 );
    }

    public static function maybe_takeover() {
        // Skip if disabled
        if ( get_option( self::OPT_DISABLE ) === '1' ) { return; }

        // Skip homepage — BT_Landing_Takeover handles it
        if ( is_front_page() || is_home() ) { return; }

        // Skip non-front-end requests
        if ( is_admin() ) { return; }
        if ( wp_doing_ajax() ) { return; }
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) { return; }
        if ( is_feed() ) { return; }
        if ( is_robots() ) { return; }
        if ( is_trackback() ) { return; }

        // Skip on non-GET requests (form posts)
        if ( ! empty( $_POST ) ) { return; } // phpcs:ignore WordPress.Security.NonceVerification

        // Skip if landing-takeover is disabled (rollback signal — keep theme rendering)
        if ( get_option( BT_Landing_Takeover::OPT_DISABLE ) === '1' ) { return; }

        // Skip on certain query types where we want WP's default rendering
        // (login pages handled by WP themselves, etc.)
        if ( is_404() ) {
            // We DO want our chrome on 404s, but with the WP 404 message
        }

        self::render();
        exit;
    }

    /**
     * Render full page: head + chrome + page content + footer
     */
    private static function render() {
        // Suppress chrome hooks BEFORE wp_head/footer fire
        self::suppress_chrome_hooks();

        // Dequeue competing stylesheets
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'dequeue_competing_styles' ), 9999 );

        // ============================================================
        // v119.28.24 CRITICAL FIX — enqueue chrome assets BEFORE wp_head().
        // Previously render_chrome_only() was called AFTER wp_head() captured
        // head extras, so bt-landing-revamp.css was never in <head> and the
        // chrome rendered totally unstyled (screenshots 1 + 2 in user report).
        // ============================================================
        self::enqueue_chrome_assets();

        // v119.28.26 — define BT_CHROME_RENDERED early so shortcodes (e.g.
        // [blockticker_desk_brief]) that have their own disclaimer+ticker
        // skip them — preventing duplicates on desk-brief and other pages.
        if ( ! defined( 'BT_CHROME_RENDERED' ) ) {
            define( 'BT_CHROME_RENDERED', true );
        }

        status_header( 200 );
        nocache_headers();
        header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );

        // ---------- Build page content (wp main) FIRST so shortcodes run ----------
        $page_content = self::build_page_content();

        // ---------- Capture wp_head extras (now includes our chrome CSS link) ----------
        ob_start();
        wp_head();
        $head_extras = ob_get_clean();

        // ---------- Capture wp_footer extras ----------
        ob_start();
        wp_footer();
        $footer_extras = ob_get_clean();

        // ---------- Capture chrome HTML ----------
        $chrome = '';
        if ( class_exists( 'BT_Landing_Revamp' ) && method_exists( 'BT_Landing_Revamp', 'render_chrome_only' ) ) {
            $chrome = BT_Landing_Revamp::render_chrome_only();
        }

        // ---------- Capture footer HTML ----------
        $footer = '';
        if ( class_exists( 'BT_Landing_Revamp' ) && method_exists( 'BT_Landing_Revamp', 'render_footer_only' ) ) {
            $footer = BT_Landing_Revamp::render_footer_only();
        }

        // ---------- Body classes ----------
        $body_classes = implode( ' ', array_filter( get_body_class( 'bt-site-takeover bt-landing-page' ) ) );

        // ---------- Page title ----------
        $page_title = wp_get_document_title();

        // ---------- Defensive design tokens CSS ----------
        $defensive_css = self::defensive_design_tokens();

        // ---------- Output the complete HTML document ----------
        $charset = get_bloginfo( 'charset' );
        $lang_attr = get_bloginfo( 'language' );

        echo '<!DOCTYPE html>' . "\n";
        echo '<html lang="' . esc_attr( $lang_attr ) . '">' . "\n";
        echo '<head>' . "\n";
        echo '<meta charset="' . esc_attr( $charset ) . '">' . "\n";
        echo '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">' . "\n";
        echo '<title>' . esc_html( $page_title ) . '</title>' . "\n";

        // wp_head() — analytics, tracking, plugin styles, theme styles (all of which we override below)
        echo $head_extras . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput

        // Defensive CSS — LAST in <head> so it wins cascade
        echo $defensive_css . "\n";

        echo '</head>' . "\n";
        echo '<body class="' . esc_attr( $body_classes ) . '">' . "\n";

        // wp_body_open() for analytics that need to be near top of body
        // (we already removed BT chrome hooks via suppress_chrome_hooks)
        if ( function_exists( 'wp_body_open' ) ) {
            wp_body_open();
        }

        // ---------- Chrome ----------
        echo $chrome; // phpcs:ignore WordPress.Security.EscapeOutput

        // ---------- Main content ----------
        echo '<main id="main-content" class="bt-site-main">' . "\n";
        echo '<div class="container bt-site-content-wrap">' . "\n";
        echo $page_content; // phpcs:ignore WordPress.Security.EscapeOutput
        echo '</div>' . "\n";
        echo '</main>' . "\n";

        // ---------- Footer ----------
        echo $footer; // phpcs:ignore WordPress.Security.EscapeOutput

        // ---------- Close .btlp wrapper opened by chrome ----------
        echo '</div><!-- /.btlp -->' . "\n";

        // wp_footer() — scripts, analytics
        echo $footer_extras . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput

        echo '</body>' . "\n";
        echo '</html>';
    }

    /**
     * Build the page main content area.
     * Renders the page title (h1) + the content (with shortcodes processed).
     */
    private static function build_page_content() {
        ob_start();

        if ( is_404() ) {
            ?>
            <article class="bt-page bt-404">
                <h1 class="bt-page-title">Page not found</h1>
                <p class="bt-page-lead">The page you're looking for doesn't exist or has been moved.</p>
                <p><a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="btn">← Back to home</a></p>
            </article>
            <?php
        } elseif ( is_search() ) {
            ?>
            <article class="bt-page bt-search">
                <h1 class="bt-page-title">Search results</h1>
                <p class="bt-page-lead">Showing results for <strong>"<?php echo esc_html( get_search_query() ); ?>"</strong></p>
                <?php
                if ( have_posts() ) {
                    echo '<div class="bt-search-results">';
                    while ( have_posts() ) {
                        the_post();
                        ?>
                        <div class="bt-search-result">
                            <h3><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
                            <p><?php echo wp_kses_post( get_the_excerpt() ); ?></p>
                        </div>
                        <?php
                    }
                    echo '</div>';
                } else {
                    echo '<p>No results found.</p>';
                }
                ?>
            </article>
            <?php
        } elseif ( is_archive() || is_category() || is_tag() ) {
            ?>
            <article class="bt-page bt-archive">
                <h1 class="bt-page-title"><?php echo esc_html( get_the_archive_title() ); ?></h1>
                <?php
                if ( get_the_archive_description() ) {
                    echo '<div class="bt-page-lead">' . wp_kses_post( get_the_archive_description() ) . '</div>';
                }
                if ( have_posts() ) {
                    echo '<div class="bt-archive-results">';
                    while ( have_posts() ) {
                        the_post();
                        ?>
                        <div class="bt-archive-item">
                            <h3><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
                            <?php if ( has_excerpt() ) : ?>
                                <p><?php echo wp_kses_post( get_the_excerpt() ); ?></p>
                            <?php endif; ?>
                        </div>
                        <?php
                    }
                    echo '</div>';
                }
                ?>
            </article>
            <?php
        } elseif ( is_singular() ) {
            // Single post or page — render title + content (allows shortcodes)
            global $post;
            if ( have_posts() ) {
                while ( have_posts() ) {
                    the_post();
                    // v119.28.28 — Suppress the auto page title <h1> when the
                    // page content already has its own page header (the .fxlm-page-header
                    // div that BlockTicker pages use) or when the shortcode renders its
                    // own masthead (like [blockticker_desk_brief]). Avoids the double-H1
                    // pattern that made every dashboard page render with two huge titles.
                    $raw_content = isset( $post->post_content ) ? (string) $post->post_content : '';
                    $has_own_header = (
                        strpos( $raw_content, 'fxlm-page-header' ) !== false ||
                        strpos( $raw_content, 'bt-brief__masthead' ) !== false ||
                        strpos( $raw_content, 'bt-brief-page' ) !== false ||
                        strpos( $raw_content, '[blockticker_desk_brief' ) !== false ||
                        strpos( $raw_content, '[blockticker_signal_archive' ) !== false ||
                        strpos( $raw_content, '[bt_intelligence_brief' ) !== false ||
                        strpos( $raw_content, '[bt_asset_analysis' ) !== false ||
                        strpos( $raw_content, '[fxlm_breadcrumbs' ) !== false ||
                        // v119.28.30 — news pages start with <h2>...</h2><p>...</p>
                        // (a sub-title + lede). The auto WP page title was rendering
                        // a near-duplicate above it. Treat any page whose content
                        // starts with <h2> within the first 80 chars (after <!-- wp blocks-->)
                        // as having its own header.
                        preg_match( '#^\s*(?:<!--[^>]*-->\s*)?<h2[\s>]#', $raw_content ) === 1 ||
                        // News pages specifically
                        strpos( $raw_content, '[fxlm_news_feed' ) !== false ||
                        strpos( $raw_content, '[fxlm_breaking_news' ) !== false ||
                        strpos( $raw_content, '[bt_sentiment_bar' ) !== false ||
                        // Auth pages we rebuilt in v28
                        strpos( $raw_content, 'bt-auth-card' ) !== false
                    );
                    ?>
                    <article class="bt-page bt-singular bt-singular-<?php echo esc_attr( get_post_type() ); ?>">
                        <?php if ( ! is_front_page() && ! is_home() && ! $has_own_header ) : ?>
                            <h1 class="bt-page-title"><?php the_title(); ?></h1>
                        <?php endif; ?>
                        <div class="bt-page-content entry-content">
                            <?php the_content(); ?>
                        </div>
                    </article>
                    <?php
                }
                wp_reset_postdata();
            } else {
                ?>
                <article class="bt-page">
                    <h1 class="bt-page-title">No content</h1>
                    <p>This page has no content yet.</p>
                </article>
                <?php
            }
        } else {
            // Generic fallback
            ?>
            <article class="bt-page">
                <?php if ( have_posts() ) : while ( have_posts() ) : the_post(); ?>
                    <h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
                    <div class="bt-page-content entry-content"><?php the_content(); ?></div>
                <?php endwhile; endif; ?>
            </article>
            <?php
        }

        return ob_get_clean();
    }

    /**
     * Final-cascade design tokens: locked in so theme/plugin CSS can't override.
     */
    private static function defensive_design_tokens() {
        return <<<'CSS'
<style id="bt-site-takeover-defensive">
/* v119.28.23 — final-cascade lock: this <style> sits LAST in <head> so it
   wins on the cascade against any wp_head-injected theme/plugin CSS. */

/* Body baseline — kill theme background, font, padding */
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
  overflow-x: hidden !important;
  font-feature-settings: 'cv11' 1, 'ss01' 1;
}
body * { box-sizing: border-box; }

/* Headings: force Space Grotesk display font */
h1, h2, h3, h4, h5, h6 {
  font-family: 'Space Grotesk', 'SF Pro Display', -apple-system, sans-serif !important;
  letter-spacing: -.02em !important;
  color: #fff !important;
}

/* Mono / numeric — Inter with tabular-nums, NOT a separate mono font.
   This is the Coinbase / TradingView / Glassnode approach. */
.ticker, .ticker__sym, .ticker__chg--up, .ticker__chg--down,
.feed, .feed__sym,
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

/* Anchor reset — but EXCLUDE styled buttons */
a:not(.btn):not(.nav__signup):not([class*="btn--"]):not([class*="nav__"]):not([class*="cta__"]):not([class*="hero__"]) {
  text-decoration: none;
}

/* Mega menu links — explicit WHITE per mockup */
.mega__col a,
.mega__col a:link,
.mega__col a:visited {
  color: #e4e4e7 !important;
  background: transparent !important;
}
.mega__col a:hover { color: #ffffff !important; background: rgba(0,255,102,.06) !important; }
.mega__col a strong { color: #ffffff !important; }
.mega__col a .mega__ico { color: #00FF66 !important; }
.mega__col a.mega__more,
.mega__col a.mega__more:link,
.mega__col a.mega__more:visited { color: #00FF66 !important; }

/* Footer column links — gray/white per mockup */
.foot__col a,
.foot__col a:link,
.foot__col a:visited,
.foot__col li a {
  color: #a1a1aa !important;
  background: transparent !important;
  border-bottom: none !important;
  text-decoration: none !important;
}
.foot__col a:hover { color: #ffffff !important; }

/* v119.28.26 — Footer social icons: WHITE default with subtle border, green only on hover.
   Matches mockup; prevents theme/plugin overrides. */
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

/* Buttons — primary green, BLACK text (visible) */
.btn:not(.btn--secondary):not(.btn--ghost),
a.btn:not(.btn--secondary):not(.btn--ghost),
button.btn:not(.btn--secondary):not(.btn--ghost) {
  background: #00FF66 !important;
  color: #0A0B0D !important;
  border: none !important;
  text-decoration: none !important;
  cursor: pointer;
}
.nav__signup,
a.nav__signup,
button.nav__signup {
  background: #00FF66 !important;
  color: #0A0B0D !important;
  border: none !important;
  cursor: pointer;
  font-weight: 800 !important;
}

/* Buttons — secondary outlined */
.btn--secondary, a.btn--secondary, button.btn--secondary {
  background: #1A1C20 !important;
  color: #fff !important;
  border: 1px solid rgba(255,255,255,.10) !important;
}
.btn--ghost, a.btn--ghost, button.btn--ghost {
  background: transparent !important;
  color: #fff !important;
}

/* Pills */
.hero__pill, a.hero__pill, .pill {
  background: rgba(0,255,102,.06) !important;
  color: #00FF66 !important;
  border: 1px solid rgba(0,255,102,.30) !important;
}

/* Hero accent */
.hero__h1-accent { color: #00FF66 !important; }

/* Disclaimer strip — make sure it shows correctly */
.disclaim-strip {
  background: linear-gradient(90deg,#1a1410,#241a13,#1a1410) !important;
  border-top: 1px solid #ffb840 !important;
  border-bottom: 1px solid #ffb840 !important;
  padding: 9px 0 !important;
  position: relative !important;
  z-index: 50 !important;
}
.disclaim-strip__txt strong { color: #ffb840 !important; }
.disclaim-strip__link { color: #ffb840 !important; }

/* Ticker — ensure proper colors */
.ticker { background: #000 !important; border-bottom: 1px solid rgba(255,255,255,.05) !important; }
.ticker__chg--up   { color: #00FF66 !important; }
.ticker__chg--down { color: #FF3B30 !important; }
.ticker__live      { color: #00FF66 !important; }
.ticker__sym       { color: #fff !important; }

/* Nav — ensure background, sticky */
.nav { background: rgba(10,11,13,.92) !important; backdrop-filter: blur(12px); border-bottom: 1px solid rgba(255,255,255,.05) !important; position: sticky !important; top: 0 !important; z-index: 40 !important; }

/* Brand logo — green B box */
.nav__logo {
  background: #00FF66 !important;
  color: #000 !important;
  font-weight: 900 !important;
}
/* v119.28.26 — Brand text per mockup: BLOCK white + TICKER green
   (the green B logo tile is also retained in nav__logo) */
.nav__name { color: #fff !important; }
.nav__name span { color: #00FF66 !important; }
.foot__brand-name { color: #fff !important; }
.foot__brand-name span { color: #00FF66 !important; }
.nav__logo, .foot__brand .nav__logo {
  background: #00FF66 !important;
  color: #0A0B0D !important;
  font-weight: 900 !important;
}

/* Nuke theme content wrappers — they cause empty space and wrong widths */
#page, .site, .site-content, #content, #primary, main:not(.bt-site-main), main#main:not(.bt-site-main),
.site-main, .entry-content:not(.bt-page-content), article.post:not(.bt-page), article.page:not(.bt-page),
.content-area, #wrapper, .site-wrapper, #main-content:not(#bt-site-main),
.elementor-section-wrap, .ast-container,
.generate-content-wrap, .container.grid-container {
  max-width: none !important;
  width: 100% !important;
  padding: 0 !important;
  margin: 0 !important;
  background: transparent !important;
  float: none !important;
}

/* Hide theme headers (the empty black space at top) */
body > header,
#masthead,
.site-header,
.main-navigation,
#site-navigation,
.menu-toggle,
.navigation-search,
.mobile-menu-control-wrapper,
.inside-header,
.site-branding,
body > .skip-link,
.ast-primary-header-bar,
.ast-above-header-bar,
.ast-below-header-bar,
.elementor-location-header,
.entry-header:not(.bt-page-header),
.page-header,
header.entry-header { display: none !important; }

/* Hide theme footer */
body > footer:not(.foot),
#colophon,
.site-footer,
.site-info,
.copyright-bar,
.ast-small-footer,
.elementor-location-footer { display: none !important; }

/* Hide WP page-title (we render our own h1) */
.entry-title,
.page-title,
.post-title,
header.entry-header,
header.page-header,
.wp-block-post-title,
h1.wp-block-post-title { display: none !important; }

/* Legacy plugin chrome — hide ALL */
.cp-navbar, #cp-navbar, .cp-navbar-spacer,
.cp-footer, #cp-footer, .cp-footer-bottom,
.fxlm-bottom-ticker, #fxlm-bottom-ticker,
.bt-mobile-nav, #bt-mobile-nav,
.bt-sticky-cta, .bt-back-to-top,
.bt-install-banner, #bt-install-banner,
.bt-subscribe-popup, .bt-cookie-banner,
.gdpr-cookie-banner,
.bt-scroll-newsletter,
#bt-reading-progress { display: none !important; }

/* WP admin bar offset */
body.admin-bar { margin-top: 0 !important; padding-top: 32px !important; }
@media (max-width: 782px) {
  body.admin-bar { padding-top: 46px !important; }
}

/* ---- Site main content area (interior pages) ---- */
.bt-site-main {
  display: block !important;
  padding: 48px 0 96px !important;
  background: #0A0B0D !important;
  min-height: 60vh;
}
.bt-site-content-wrap {
  max-width: 920px !important;
  margin: 0 auto !important;
  padding: 0 24px !important;
}
.bt-page-title {
  font-family: 'Space Grotesk', 'SF Pro Display', sans-serif !important;
  font-size: clamp(32px, 5vw, 56px) !important;
  font-weight: 600 !important;
  letter-spacing: -.022em !important;
  margin: 0 0 28px !important;
  color: #fff !important;
  line-height: 1.10 !important;
}
.bt-page-lead {
  font-size: 19px !important;
  color: #a1a1aa !important;
  margin: 0 0 36px !important;
  line-height: 1.55 !important;
}
.bt-page-content {
  font-size: 17px !important;
  line-height: 1.7 !important;
  color: #e4e4e7 !important;
}
.bt-page-content h2 {
  font-size: 28px !important;
  font-weight: 700 !important;
  margin: 40px 0 16px !important;
  color: #fff !important;
}
.bt-page-content h3 {
  font-size: 22px !important;
  font-weight: 700 !important;
  margin: 32px 0 14px !important;
  color: #fff !important;
}
.bt-page-content h4 {
  font-size: 18px !important;
  font-weight: 700 !important;
  margin: 24px 0 12px !important;
  color: #fff !important;
}
.bt-page-content p {
  margin: 0 0 18px !important;
}
.bt-page-content a {
  color: #00FF66 !important;
  text-decoration: none !important;
  border-bottom: 1px solid rgba(0,255,102,.30) !important;
}
.bt-page-content a:hover { border-bottom-color: #00FF66 !important; }
.bt-page-content ul, .bt-page-content ol {
  margin: 0 0 18px !important;
  padding-left: 24px !important;
}
.bt-page-content li { margin-bottom: 8px !important; }
.bt-page-content code {
  background: #1A1C20 !important;
  padding: 2px 6px !important;
  border-radius: 3px !important;
  font-family: 'Inter', 'SF Mono', monospace !important;
  font-feature-settings: 'tnum' 1, 'zero' 1, 'ss02' 1 !important;
  font-variant-ligatures: none !important;
  font-size: .9em !important;
  color: #00FF66 !important;
}
.bt-page-content pre {
  background: #1A1C20 !important;
  padding: 16px !important;
  border-radius: 6px !important;
  overflow-x: auto !important;
  margin: 0 0 18px !important;
  font-family: 'Inter', 'SF Mono', monospace !important;
  font-feature-settings: 'tnum' 1, 'zero' 1, 'ss02' 1 !important;
  font-variant-ligatures: none !important;
}
.bt-page-content blockquote {
  border-left: 3px solid #00FF66 !important;
  padding: 8px 0 8px 20px !important;
  margin: 24px 0 !important;
  color: #a1a1aa !important;
  font-style: italic !important;
}
.bt-page-content table {
  width: 100% !important;
  border-collapse: collapse !important;
  margin: 24px 0 !important;
}
.bt-page-content th, .bt-page-content td {
  padding: 12px !important;
  border-bottom: 1px solid rgba(255,255,255,.05) !important;
  text-align: left !important;
}
.bt-page-content td {
  font-variant-numeric: tabular-nums slashed-zero !important;
  font-feature-settings: 'tnum' 1, 'zero' 1 !important;
}
.bt-page-content th {
  font-family: 'Inter', sans-serif !important;
  font-size: 11px !important;
  font-weight: 600 !important;
  color: #71717a !important;
  text-transform: uppercase !important;
  letter-spacing: 0.08em !important;
}
.bt-page-content img {
  max-width: 100% !important;
  height: auto !important;
  display: block !important;
  margin: 24px 0 !important;
}
.bt-page-content form {
  background: #121316 !important;
  border: 1px solid rgba(255,255,255,.05) !important;
  padding: 24px !important;
  border-radius: 6px !important;
  margin: 24px 0 !important;
}
.bt-page-content input[type="text"],
.bt-page-content input[type="email"],
.bt-page-content input[type="password"],
.bt-page-content input[type="search"],
.bt-page-content textarea,
.bt-page-content select {
  background: #0A0B0D !important;
  border: 1px solid rgba(255,255,255,.10) !important;
  color: #fff !important;
  padding: 10px 14px !important;
  font-family: inherit !important;
  font-size: 15px !important;
  border-radius: 4px !important;
  width: 100%;
  margin-bottom: 12px;
}
.bt-page-content input[type="text"]:focus,
.bt-page-content input[type="email"]:focus,
.bt-page-content input[type="password"]:focus,
.bt-page-content input[type="search"]:focus,
.bt-page-content textarea:focus,
.bt-page-content select:focus {
  outline: 2px solid #00FF66 !important;
  outline-offset: -1px !important;
}
.bt-page-content button[type="submit"],
.bt-page-content input[type="submit"] {
  background: #00FF66 !important;
  color: #0A0B0D !important;
  border: none !important;
  padding: 12px 24px !important;
  font-weight: 800 !important;
  font-size: 14px !important;
  cursor: pointer !important;
  border-radius: 4px !important;
}

/* 404 / search */
.bt-404 .bt-page-title, .bt-search .bt-page-title { color: #fff !important; }
.bt-search-result, .bt-archive-item {
  background: #121316 !important;
  border: 1px solid rgba(255,255,255,.05) !important;
  padding: 20px !important;
  margin-bottom: 14px !important;
  border-radius: 4px !important;
}
.bt-search-result h3 a, .bt-archive-item h3 a {
  color: #fff !important;
  font-size: 18px !important;
  text-decoration: none !important;
}
.bt-search-result h3 a:hover, .bt-archive-item h3 a:hover { color: #00FF66 !important; }
</style>
CSS;
    }

    /**
     * v119.28.24 — Enqueue chrome CSS/JS BEFORE wp_head() runs so the
     * <link> and <script> tags appear in the captured head extras.
     * Mirrors what BT_LandingRevamp::enqueue_assets() does, but is callable
     * from outside the class without reflection.
     */
    private static function enqueue_chrome_assets() {
        if ( ! defined( 'BT_URL' ) || ! defined( 'BT_VERSION' ) ) { return; }

        // v119.28.25 — Google Fonts: Inter + Space Grotesk per typography spec.
        // Inter handles UI/body/data with tabular-nums + slashed-zero for financial readability.
        // Space Grotesk for display/headings — institutional grotesk, not crypto-cliché Poppins/Montserrat.
        if ( ! wp_style_is( 'btlp-gfonts', 'enqueued' ) ) {
            wp_enqueue_style(
                'btlp-gfonts',
                'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@400;500;600;700&display=swap',
                array(), null
            );
        }

        // Main chrome CSS
        if ( ! wp_style_is( 'bt-landing-revamp', 'enqueued' ) ) {
            wp_enqueue_style(
                'bt-landing-revamp',
                BT_URL . 'assets/css/landing-revamp.css',
                array( 'btlp-gfonts' ),
                BT_VERSION
            );
        }

        // v119.28.25 — Typography system overlay (Inter + Space Grotesk).
        // Loaded AFTER landing-revamp.css so its design tokens win the cascade.
        if ( ! wp_style_is( 'bt-typography', 'enqueued' ) ) {
            wp_enqueue_style(
                'bt-typography',
                BT_URL . 'assets/css/typography.css',
                array( 'bt-landing-revamp' ),
                BT_VERSION
            );
        }

        // v119.28.31 — Unified design tokens & component library.
        // Loaded LAST so its tokens (colors, typography, spacing, radii) and
        // component classes (.bt-card, .bt-stat, .bt-badge, .bt-hero, .bt-section,
        // .bt-tile, .bt-grid, .bt-empty) win every cascade conflict.
        if ( ! wp_style_is( 'bt-tokens', 'enqueued' ) ) {
            wp_enqueue_style(
                'bt-tokens',
                BT_URL . 'assets/css/bt-tokens.css',
                array( 'bt-typography' ),
                BT_VERSION
            );
        }

        // Chrome JS (drawer toggles, mega-menu, search, etc.)
        if ( ! wp_script_is( 'bt-landing-revamp', 'enqueued' ) ) {
            wp_enqueue_script(
                'bt-landing-revamp',
                BT_URL . 'assets/js/landing-revamp.js',
                array(),
                BT_VERSION,
                true
            );
        }
    }

    /**
     * Dequeue plugin/theme styles that fight the BT chrome.
     */
    public static function dequeue_competing_styles() {
        wp_dequeue_style( 'fxlm-frontend' );
        wp_dequeue_style( 'fxlm-patch' );
        wp_dequeue_style( 'fxlm-revamp-v44' );
        wp_dequeue_style( 'fxlm-critical' );
        wp_dequeue_style( 'fxlm-admin' );

        // Common WP theme handles
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

        // WP block library
        wp_dequeue_style( 'wp-block-library' );
        wp_dequeue_style( 'wp-block-library-theme' );
        wp_dequeue_style( 'global-styles' );
        wp_dequeue_style( 'classic-theme-styles' );
    }

    /**
     * Remove every hook that would render duplicate chrome / theme chrome.
     * Identical to BT_Landing_Takeover::suppress_chrome_hooks except we DO
     * still want render_global_chrome to not double-render (we render chrome
     * ourselves directly).
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

        if ( class_exists( 'BT_EEAT' ) ) {
            remove_action( 'wp_footer', array( 'BT_EEAT', 'render_scroll_newsletter' ), 20 );
            remove_action( 'wp_footer', array( 'BT_EEAT', 'render_reading_progress' ),   5 );
        }

        if ( class_exists( 'BT_GDPR' ) ) {
            remove_action( 'wp_footer', array( 'BT_GDPR', 'render_cookie_banner' ),     99 );
        }

        if ( class_exists( 'BT_PWA' ) ) {
            remove_action( 'wp_footer', array( 'BT_PWA', 'output_pwa_client_script' ),  99 );
        }

        // BT global chrome — we render chrome ourselves
        if ( class_exists( 'BT_Page_Provisioner' ) ) {
            remove_action( 'wp_body_open', array( 'BT_Page_Provisioner', 'render_global_chrome' ), 1 );
            remove_action( 'wp_footer',    array( 'BT_Page_Provisioner', 'close_global_chrome' ), 999 );
        }

        // GeneratePress / Astra theme chrome
        remove_action( 'generate_header',          'generate_construct_header' );
        remove_action( 'generate_after_header',    'generate_add_navigation_after_header', 5 );
        remove_action( 'generate_footer',          'generate_construct_footer_widgets', 5 );
        remove_action( 'generate_credits',         'generate_add_footer_info' );
    }
}

BT_Site_Takeover::init();
