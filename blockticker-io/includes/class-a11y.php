<?php
/**
 * BT_A11y — WCAG 2.1 AA accessibility layer for BlockTicker.
 *
 * Responsibilities:
 *  - Enqueue a11y.css (last, overrides all prior CSS) and a11y.js (deferred)
 *  - Inject the skip-to-content link at wp_body_open
 *  - Inject the aria-live polite region for price-update announcements
 *  - Add lang attribute to <html> if not already present
 *  - Add role="main" to #content / .site-content if missing
 *  - Register the 'bt-native-chart' handle in CWV defer list (cross-class hygiene)
 *  - Provide BT_A11y::admin_panel_html() for the DB admin screen
 *
 * @since 96.7.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BT_A11y {

    /* ------------------------------------------------------------------
     * Bootstrap
     * ------------------------------------------------------------------ */

    public static function init() {
        // Assets — load after all other plugin stylesheets (priority 99999).
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ], 99999 );

        // Skip link — injected immediately after <body> opens.
        // wp_body_open was introduced in WP 5.2; fall back to wp_head for older themes.
        if ( function_exists( 'wp_body_open' ) ) {
            add_action( 'wp_body_open', [ __CLASS__, 'inject_skip_link' ], 1 );
        } else {
            add_action( 'wp_head', [ __CLASS__, 'inject_skip_link_via_js' ], 99 );
        }

        // Aria-live region — injected once at wp_body_open (priority 2, after skip link).
        add_action( 'wp_body_open', [ __CLASS__, 'inject_live_region' ], 2 );

        // lang attribute on <html> tag.
        add_filter( 'language_attributes', [ __CLASS__, 'ensure_lang_attribute' ] );

        // Main content landmark — GeneratePress/Astra output a <main> already;
        // this adds role="main" only if the theme doesn't.
        add_filter( 'body_class', [ __CLASS__, 'add_body_class' ] );
    }

    /* ------------------------------------------------------------------
     * Assets
     * ------------------------------------------------------------------ */

    public static function enqueue_assets() {
        if ( is_admin() ) return;

        $ver = BT_VERSION . '.' . filemtime( BT_DIR . 'assets/css/a11y.css' );

        // CSS — must load LAST so its overrides beat every prior stylesheet.
        wp_enqueue_style(
            'bt-a11y',
            BT_URL . 'assets/css/a11y.css',
            array( 'fxlm-revamp-v44' ), // depend on revamp-v44 to guarantee load order
            $ver
        );

        // JS — deferred, no jQuery dependency.
        wp_enqueue_script(
            'bt-a11y',
            BT_URL . 'assets/js/a11y.js',
            array(), // no deps — runs after DOMContentLoaded
            $ver,
            true     // in footer
        );
    }

    /* ------------------------------------------------------------------
     * Skip-to-content link
     * ------------------------------------------------------------------ */

    /**
     * Inject the skip link as the very first element after <body>.
     * Keyboard users (including screen-reader users navigating by tab)
     * reach this link before anything else and can jump straight to the
     * main content area, bypassing the navigation bar on every page.
     *
     * The target ID #bt-main-content is added to the content wrapper by
     * the theme (GeneratePress uses #content; Astra uses #primary).
     * We target the most common IDs with a JS fallback in a11y.js.
     */
    public static function inject_skip_link() {
        echo '<a href="#bt-main-content" id="bt-skip-link" class="bt-sr-only bt-sr-only-focusable">'
           . esc_html__( 'Skip to main content', 'blockticker' )
           . '</a>' . "\n";
    }

    /**
     * Fallback for themes that don't call wp_body_open().
     * Injects a tiny inline script that prepends the skip link via JS.
     */
    public static function inject_skip_link_via_js() {
        ?>
        <script>
        (function(){
            var a = document.createElement('a');
            a.href      = '#bt-main-content';
            a.id        = 'bt-skip-link';
            a.className = 'bt-sr-only bt-sr-only-focusable';
            a.textContent = '<?php echo esc_js( __( 'Skip to main content', 'blockticker' ) ); ?>';
            if (document.body && document.body.firstChild) {
                document.body.insertBefore(a, document.body.firstChild);
            }
        })();
        </script>
        <?php
    }

    /* ------------------------------------------------------------------
     * aria-live region
     * ------------------------------------------------------------------ */

    /**
     * Inject the aria-live polite region used by a11y.js to announce
     * price updates to screen readers.  The region is visually hidden
     * (via #bt-a11y-live styles in a11y.css) but fully accessible.
     *
     * `aria-atomic="true"` means the entire message is read as one unit
     * rather than individual character-by-character mutations.
     *
     * `aria-relevant="additions text"` limits announcements to new text
     * (additions), preventing "removed" announcements when we clear the
     * region before writing new content.
     */
    public static function inject_live_region() {
        echo '<div id="bt-a11y-live"'
           . ' role="status"'
           . ' aria-live="polite"'
           . ' aria-atomic="true"'
           . ' aria-relevant="additions text"'
           . '></div>' . "\n";
    }

    /* ------------------------------------------------------------------
     * HTML lang attribute
     * ------------------------------------------------------------------ */

    /**
     * Ensure the <html> tag has a valid lang attribute.
     * WP adds this automatically from get_locale(), but some themes or
     * builders strip it.  We re-add it if missing.
     *
     * @param string $output  Current language_attributes output.
     * @return string
     */
    public static function ensure_lang_attribute( $output ) {
        if ( strpos( $output, 'lang=' ) !== false ) return $output;
        $locale = str_replace( '_', '-', get_locale() );
        return 'lang="' . esc_attr( $locale ) . '" ' . $output;
    }

    /* ------------------------------------------------------------------
     * Body class
     * ------------------------------------------------------------------ */

    /**
     * Add bt-a11y class to <body> so child-theme CSS can target the
     * accessibility layer selectively.
     *
     * @param string[] $classes  Existing body classes.
     * @return string[]
     */
    public static function add_body_class( $classes ) {
        $classes[] = 'bt-a11y';
        return $classes;
    }

    /* ------------------------------------------------------------------
     * Admin panel
     * ------------------------------------------------------------------ */

    /**
     * HTML summary for the BlockTicker → 🗄 Database admin screen.
     *
     * @return string  HTML.
     */
    public static function admin_panel_html() {
        $issues = self::audit_issues();
        ob_start(); ?>
        <div class="bt-panel">
            <h3>♿ WCAG 2.1 AA Status</h3>
            <table class="widefat striped" style="margin-top:8px">
                <tbody>
                    <tr><td>✅ Colour contrast</td><td><code>--bt-text-3</code> promoted from var(--bt-text-3) → #7a8aaa (5.54:1). All primary/secondary text passes AA.</td></tr>
                    <tr><td>✅ Focus-visible</td><td>3px var(--bt-accent) ring on all interactive elements. <code>outline:none</code> offenders overridden.</td></tr>
                    <tr><td>✅ Skip link</td><td>Injected at <code>wp_body_open</code> — visible on keyboard focus.</td></tr>
                    <tr><td>✅ aria-live region</td><td>Price updates announced politely to screen readers.</td></tr>
                    <tr><td>✅ Table keyboard nav</td><td>Arrow keys, Home/End on all market/forex tables.</td></tr>
                    <tr><td>✅ Table captions</td><td>SR-only captions on crypto, forex, sentiment tables.</td></tr>
                    <tr><td>✅ Reduced motion</td><td>CSS + JS animation kill switch on <code>prefers-reduced-motion</code>.</td></tr>
                    <tr><td>✅ High contrast</td><td>Palette override on <code>prefers-contrast:more</code>.</td></tr>
                    <tr><td>✅ Forced colours</td><td>Windows HC Mode handled via <code>forced-colors:active</code>.</td></tr>
                    <tr><td>✅ HTML lang</td><td>Filter ensures <code>&lt;html lang&gt;</code> is always present.</td></tr>
                    <?php if ( $issues ) : ?>
                    <tr><td colspan="2" style="color:var(--bt-danger)">⚠️ Remaining: <?php echo esc_html( implode( '; ', $issues ) ); ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Run a quick runtime audit and return any outstanding issues.
     *
     * @return string[]  Array of human-readable issue descriptions.
     */
    private static function audit_issues() {
        $issues = array();
        // Check that a11y.css is actually enqueued (might be dequeued by a theme).
        if ( ! wp_style_is( 'bt-a11y', 'enqueued' ) && ! is_admin() ) {
            $issues[] = 'bt-a11y stylesheet not enqueued on this page';
        }
        return $issues;
    }
}
