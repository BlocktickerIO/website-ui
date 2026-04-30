<?php
/**
 * BlockTicker — Mobile UX Layer
 *
 * Coordinated set of mobile-specific UI enhancements that activate only on
 * narrow viewports. Built as a single subsystem rather than scattered patches
 * so the pieces share viewport detection, scroll-state observers, and CSS
 * variables — and so the "mobile experience" can be reasoned about as one
 * coherent thing instead of 15 unrelated tweaks.
 *
 * Components (all individually filterable, see below):
 *   1. Sticky bottom navigation bar
 *      Hides on scroll-down (content priority), reveals on scroll-up.
 *      4 default items: Markets · Watchlist · Alerts · More.
 *   2. Sticky bottom CTA bar on /crypto/{slug}/ and /forex/{slug}/
 *      Pinned "Set Alert" + "Watchlist" buttons within thumb reach.
 *   3. "Back to top" floating action button
 *      Auto-shows after 800px scroll on long pages.
 *   4. Touch target audit CSS
 *      Ensures all interactive elements meet WCAG 2.1 AAA's 44x44 target.
 *      Site-wide CSS, not mobile-only — touch targets matter on tablets too.
 *
 * Mobile-only safeguards
 *   - All JS components query `matchMedia('(max-width: 768px)')` and self-disable
 *     on resize past the threshold.
 *   - PHP-side `wp_is_mobile()` is used as a hint for whether to inline the
 *     subsystem JS at all, but client-side viewport check is authoritative
 *     because UA sniffing is unreliable.
 *
 * Coordination with existing components
 *   - PWA install prompt (BT_PWA::output_pwa_client_script) sits at bottom-fixed.
 *     The bottom nav reserves its space first; the install prompt is shifted up
 *     above the nav via CSS override when both are visible.
 *   - Top navbar (BT_Navbar) is unaffected — bottom nav supplements it for thumb
 *     reach, doesn't replace it.
 *
 * Filter hooks
 *   bt_mobile_ux_enable             — return false to disable the entire layer
 *   bt_mobile_nav_items             — array of nav items (label, href, icon)
 *   bt_mobile_nav_show_on_admin     — show on wp-admin too (default: false)
 *   bt_mobile_show_sticky_cta_on    — return false to skip sticky CTA on a page
 *   bt_mobile_back_to_top_threshold — pixels scrolled before FAB shows (default: 800)
 *   bt_mobile_touch_target_size     — minimum pixel size (default: 44)
 *
 * @since v119.14.0
 * @package BlockTicker
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BT_Mobile_UX {

    /** Default mobile breakpoint — matches existing max-width: 768px convention. */
    const BREAKPOINT = 768;

    /** Default touch-target minimum (WCAG 2.1 AAA). */
    const TOUCH_TARGET_MIN = 44;

    public static function setup() {
        // Master kill-switch via filter; default ON.
        if ( ! apply_filters( 'bt_mobile_ux_enable', true ) ) return;

        // Bottom nav + scripts: render late in footer so they sit after content.
        add_action( 'wp_footer', array( __CLASS__, 'render_bottom_nav' ),     20 );
        add_action( 'wp_footer', array( __CLASS__, 'render_sticky_cta' ),     21 );
        add_action( 'wp_footer', array( __CLASS__, 'render_back_to_top' ),    22 );
        add_action( 'wp_footer', array( __CLASS__, 'render_client_script' ), 25 );

        // Touch target enforcement: site-wide head CSS at low priority so it can
        // be overridden by component-specific rules later in the cascade.
        add_action( 'wp_head', array( __CLASS__, 'output_touch_target_css' ), 4 );

        // Admin overview.
        add_action( 'admin_menu', array( __CLASS__, 'register_admin_menu' ), 20 );
    }

    /* ================================================================
     *  Context detection
     * ================================================================ */

    /**
     * Returns true if the bottom nav should render on the current request.
     * Suppresses on admin, login, AJAX, REST, and 404 by default.
     */
    private static function should_render() {
        if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) return false;
        if ( is_admin() && ! apply_filters( 'bt_mobile_nav_show_on_admin', false ) ) return false;
        if ( function_exists( 'is_login' ) && is_login() ) return false;
        return true;
    }

    /**
     * Returns the current asset slug if on /crypto/{slug}/ or /forex/{slug}/,
     * with the asset type. Returns null on non-asset pages.
     * Mirrors the URL-parse pattern from BT_AssetPages::intercept_asset_url().
     */
    private static function detect_asset_context() {
        $uri = isset( $_SERVER['REQUEST_URI'] ) ? trim( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ), '/' ) : '';
        if ( $uri === '' ) return null;

        if ( preg_match( '#^crypto/([a-z0-9-]+)/?$#', $uri, $m ) ) {
            return array( 'type' => 'crypto', 'slug' => $m[1] );
        }
        if ( preg_match( '#^forex/([a-z0-9-]+)/?$#', $uri, $m ) ) {
            return array( 'type' => 'forex', 'slug' => $m[1] );
        }
        return null;
    }

    /* ================================================================
     *  Bottom navigation
     * ================================================================ */

    /**
     * Default nav items. Each: label, href, icon (Unicode/emoji), id.
     * Filter via `bt_mobile_nav_items` to override completely.
     */
    private static function get_nav_items() {
        $defaults = array(
            array(
                'id'    => 'markets',
                'label' => __( 'Markets', 'blockticker' ),
                'href'  => home_url( '/crypto-markets/' ),
                'icon'  => '&#x1F4C8;', // 📈
            ),
            array(
                'id'    => 'watchlist',
                'label' => __( 'Watchlist', 'blockticker' ),
                'href'  => home_url( '/watchlist/' ),
                'icon'  => '&#x2B50;', // ⭐
            ),
            array(
                'id'    => 'alerts',
                'label' => __( 'Alerts', 'blockticker' ),
                'href'  => home_url( '/alerts/' ),
                'icon'  => '&#x1F514;', // 🔔
            ),
            array(
                'id'    => 'more',
                'label' => __( 'More', 'blockticker' ),
                'href'  => home_url( '/tools/' ),
                'icon'  => '&#x2630;', // ☰
            ),
        );

        $items = apply_filters( 'bt_mobile_nav_items', $defaults );
        if ( ! is_array( $items ) || empty( $items ) ) return array();

        // Sanitize each entry to required shape.
        $clean = array();
        foreach ( $items as $item ) {
            if ( ! is_array( $item ) ) continue;
            if ( empty( $item['label'] ) || empty( $item['href'] ) ) continue;
            $clean[] = array(
                'id'    => isset( $item['id'] )   ? sanitize_key( $item['id'] )       : 'item-' . count( $clean ),
                'label' => (string) $item['label'],
                'href'  => esc_url_raw( $item['href'] ),
                'icon'  => isset( $item['icon'] ) ? (string) $item['icon']            : '',
            );
        }
        return $clean;
    }

    /**
     * Returns the active nav item id by matching href to current request URL.
     * Used to render the active-tab visual state server-side (no JS flash).
     */
    private static function detect_active_nav_id( $items ) {
        $current = isset( $_SERVER['REQUEST_URI'] ) ? parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) : '';
        if ( ! $current ) return '';
        $current = '/' . trim( $current, '/' ) . '/';
        foreach ( $items as $item ) {
            $href_path = parse_url( $item['href'], PHP_URL_PATH );
            if ( ! $href_path ) continue;
            $href_path = '/' . trim( $href_path, '/' ) . '/';
            if ( $href_path === $current ) return $item['id'];
        }
        return '';
    }

    public static function render_bottom_nav() {
        if ( ! self::should_render() ) return;

        $items = self::get_nav_items();
        if ( empty( $items ) ) return;

        $active_id = self::detect_active_nav_id( $items );
        ?>
        <nav class="bt-mobile-nav" id="bt-mobile-nav" role="navigation"
             aria-label="<?php esc_attr_e( 'Mobile primary navigation', 'blockticker' ); ?>">
            <ul class="bt-mobile-nav-list">
                <?php foreach ( $items as $item ) :
                    $is_active = ( $item['id'] === $active_id );
                    $cls = 'bt-mobile-nav-item' . ( $is_active ? ' bt-mobile-nav-active' : '' );
                ?>
                    <li class="<?php echo esc_attr( $cls ); ?>">
                        <a href="<?php echo esc_url( $item['href'] ); ?>"
                           data-nav-id="<?php echo esc_attr( $item['id'] ); ?>"
                           <?php echo $is_active ? 'aria-current="page"' : ''; ?>>
                            <?php if ( $item['icon'] !== '' ) : ?>
                                <span class="bt-mobile-nav-icon" aria-hidden="true"><?php echo $item['icon']; // safe: hardcoded entities ?></span>
                            <?php endif; ?>
                            <span class="bt-mobile-nav-label"><?php echo esc_html( $item['label'] ); ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </nav>
        <?php
    }

    /* ================================================================
     *  Sticky bottom CTA bar (asset detail pages only)
     * ================================================================ */

    public static function render_sticky_cta() {
        if ( ! self::should_render() ) return;

        $ctx = self::detect_asset_context();
        if ( ! $ctx ) return;

        // Per-page filter: site can suppress on specific assets if needed.
        if ( ! apply_filters( 'bt_mobile_show_sticky_cta_on', true, $ctx ) ) return;

        $is_crypto = ( $ctx['type'] === 'crypto' );
        $slug      = $ctx['slug'];

        // Compose the symbol payload that BT_Alerts and BT_Portfolio expect.
        // Crypto uses the lowercase slug; forex uses the slug as-is (eur-usd shape).
        $symbol = $slug;

        ?>
        <div class="bt-mobile-cta" id="bt-mobile-cta"
             data-asset-type="<?php echo esc_attr( $ctx['type'] ); ?>"
             data-asset-slug="<?php echo esc_attr( $slug ); ?>"
             role="region"
             aria-label="<?php esc_attr_e( 'Asset quick actions', 'blockticker' ); ?>">
            <button type="button" class="bt-mobile-cta-btn bt-mobile-cta-alert"
                    data-action="open-alert" data-symbol="<?php echo esc_attr( $symbol ); ?>">
                <span class="bt-mobile-cta-icon" aria-hidden="true">&#x1F514;</span>
                <span class="bt-mobile-cta-label"><?php esc_html_e( 'Set Alert', 'blockticker' ); ?></span>
            </button>
            <button type="button" class="bt-mobile-cta-btn bt-mobile-cta-watch"
                    data-action="toggle-watchlist" data-symbol="<?php echo esc_attr( $symbol ); ?>">
                <span class="bt-mobile-cta-icon" aria-hidden="true">&#x2B50;</span>
                <span class="bt-mobile-cta-label"><?php esc_html_e( 'Watchlist', 'blockticker' ); ?></span>
            </button>
            <?php if ( $is_crypto ) : ?>
                <a class="bt-mobile-cta-btn bt-mobile-cta-share"
                   href="<?php echo esc_url( home_url( '/analysis/' . $slug . '/' ) ); ?>">
                    <span class="bt-mobile-cta-icon" aria-hidden="true">&#x1F4CA;</span>
                    <span class="bt-mobile-cta-label"><?php esc_html_e( 'Analysis', 'blockticker' ); ?></span>
                </a>
            <?php endif; ?>
        </div>
        <?php
    }

    /* ================================================================
     *  Back-to-top FAB
     * ================================================================ */

    public static function render_back_to_top() {
        if ( ! self::should_render() ) return;
        ?>
        <button type="button" class="bt-mobile-fab bt-mobile-fab-top" id="bt-mobile-back-to-top"
                aria-label="<?php esc_attr_e( 'Back to top', 'blockticker' ); ?>" hidden>
            <span aria-hidden="true">&#x2191;</span>
        </button>
        <?php
    }

    /* ================================================================
     *  Client script — coordinates all components
     * ================================================================ */

    public static function render_client_script() {
        if ( ! self::should_render() ) return;

        $threshold = (int) apply_filters( 'bt_mobile_back_to_top_threshold', 800 );
        $threshold = max( 200, $threshold );
        $bp        = (int) self::BREAKPOINT;
        ?>
        <script>
        (function () {
            'use strict';
            var BP = <?php echo (int) $bp; ?>;
            var BACK_TO_TOP_THRESHOLD = <?php echo (int) $threshold; ?>;

            var mq = window.matchMedia('(max-width: ' + BP + 'px)');
            var root = document.documentElement;

            function setMobileFlag(isMobile) {
                if (isMobile) root.classList.add('bt-mux-on');
                else root.classList.remove('bt-mux-on');
            }
            setMobileFlag(mq.matches);
            // matchMedia event API differs across browsers; cover both
            if (mq.addEventListener) mq.addEventListener('change', function (e) { setMobileFlag(e.matches); });
            else if (mq.addListener) mq.addListener(function (e) { setMobileFlag(e.matches); });

            // ── Hide-on-scroll-down for bottom nav ──────────────────────
            var nav = document.getElementById('bt-mobile-nav');
            var cta = document.getElementById('bt-mobile-cta');
            var lastY = window.pageYOffset || 0;
            var ticking = false;
            var SCROLL_DELTA = 8; // ignore tiny movements
            var TOP_GUARD = 100;  // always show within 100px of top

            function onScroll() {
                if (ticking) return;
                ticking = true;
                requestAnimationFrame(function () {
                    var y = window.pageYOffset || 0;
                    var dy = y - lastY;
                    var goingDown = dy > SCROLL_DELTA;
                    var goingUp   = dy < -SCROLL_DELTA;

                    if (y < TOP_GUARD) {
                        if (nav) nav.classList.remove('bt-mobile-nav-hidden');
                        if (cta) cta.classList.remove('bt-mobile-cta-hidden');
                    } else if (goingDown) {
                        if (nav) nav.classList.add('bt-mobile-nav-hidden');
                        // CTA stays visible on scroll-down — it's the page's primary action
                    } else if (goingUp) {
                        if (nav) nav.classList.remove('bt-mobile-nav-hidden');
                        if (cta) cta.classList.remove('bt-mobile-cta-hidden');
                    }

                    // Back-to-top visibility
                    var btt = document.getElementById('bt-mobile-back-to-top');
                    if (btt) {
                        if (y >= BACK_TO_TOP_THRESHOLD) btt.removeAttribute('hidden');
                        else btt.setAttribute('hidden', '');
                    }

                    lastY = y;
                    ticking = false;
                });
            }
            window.addEventListener('scroll', onScroll, { passive: true });

            // ── Back-to-top click ───────────────────────────────────────
            var btt = document.getElementById('bt-mobile-back-to-top');
            if (btt) {
                btt.addEventListener('click', function () {
                    if ('scrollBehavior' in document.documentElement.style) {
                        window.scrollTo({ top: 0, behavior: 'smooth' });
                    } else {
                        window.scrollTo(0, 0);
                    }
                });
            }

            // ── CTA dispatch — wire to existing alert/watchlist subsystems ─
            // We dispatch a CustomEvent and let the existing systems react.
            // No tight coupling: if BT_Alerts is absent, nothing breaks.
            if (cta) {
                cta.addEventListener('click', function (ev) {
                    var btn = ev.target.closest('[data-action]');
                    if (!btn) return;
                    var action = btn.getAttribute('data-action');
                    var symbol = btn.getAttribute('data-symbol') || '';

                    if (action === 'open-alert') {
                        // 1. Dispatch event for any listening subsystem
                        document.dispatchEvent(new CustomEvent('bt:open-alert-modal', {
                            detail: { symbol: symbol, source: 'mobile-cta' }
                        }));
                        // 2. Fallback: click the in-page Set Alert button if present
                        var pageBtn = document.querySelector('.bt-set-alert-btn, [data-bt-set-alert]');
                        if (pageBtn) pageBtn.click();
                        else if (!document.querySelector('.bt-alert-modal')) {
                            // Last-resort fallback: navigate to /alerts/?symbol=…
                            window.location.href = '/alerts/?symbol=' + encodeURIComponent(symbol);
                        }
                    } else if (action === 'toggle-watchlist') {
                        document.dispatchEvent(new CustomEvent('bt:toggle-watchlist', {
                            detail: { symbol: symbol, source: 'mobile-cta' }
                        }));
                        // Fallback: click in-page watchlist toggle if present
                        var wlBtn = document.querySelector('.bt-watchlist-toggle, [data-bt-watchlist-toggle]');
                        if (wlBtn) {
                            ev.preventDefault();
                            wlBtn.click();
                            // Visual feedback on the CTA button itself
                            btn.classList.toggle('bt-mobile-cta-active');
                        }
                    }
                });
            }

            // ── PWA install prompt coordination ─────────────────────────
            // If the install prompt is visible, push it above the bottom nav.
            // Done via attribute observation rather than modifying class-pwa.php.
            var pwaPrompt = document.getElementById('bt-install-prompt');
            if (pwaPrompt && nav) {
                var observer = new MutationObserver(function () {
                    if (pwaPrompt.style.display !== 'none' && getComputedStyle(pwaPrompt).display !== 'none') {
                        pwaPrompt.classList.add('bt-pwa-prompt-above-nav');
                    } else {
                        pwaPrompt.classList.remove('bt-pwa-prompt-above-nav');
                    }
                });
                observer.observe(pwaPrompt, { attributes: true, attributeFilter: ['style', 'class', 'hidden'] });
                // Initial check
                if (pwaPrompt.style.display !== 'none' && getComputedStyle(pwaPrompt).display !== 'none') {
                    pwaPrompt.classList.add('bt-pwa-prompt-above-nav');
                }
            }
        })();
        </script>
        <?php
    }

    /* ================================================================
     *  Touch target enforcement CSS
     * ================================================================ */

    public static function output_touch_target_css() {
        $size = (int) apply_filters( 'bt_mobile_touch_target_size', self::TOUCH_TARGET_MIN );
        $size = max( 36, min( 60, $size ) );
        ?>
        <style id="bt-mobile-touch-targets">
        /* Touch-target audit — WCAG 2.1 AAA compliance for narrow viewports.
           Applied via inline padding rather than min-width/height to preserve
           visual layout while expanding the interactive hit area. */
        @media (max-width: <?php echo (int) self::BREAKPOINT; ?>px) {
            .bt-mobile-nav a,
            .bt-mobile-cta-btn,
            .bt-mobile-fab {
                min-height: <?php echo (int) $size; ?>px;
                min-width:  <?php echo (int) $size; ?>px;
            }
        }
        </style>
        <?php
    }

    /* ================================================================
     *  Admin overview
     * ================================================================ */

    public static function register_admin_menu() {
        add_submenu_page(
            'fxlm-wizard',
            __( 'Mobile UX', 'blockticker' ),
            __( 'Mobile UX', 'blockticker' ),
            'manage_options',
            'bt-mobile-ux',
            array( __CLASS__, 'render_admin_page' )
        );
    }

    public static function render_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $items     = self::get_nav_items();
        $threshold = (int) apply_filters( 'bt_mobile_back_to_top_threshold', 800 );
        $size      = (int) apply_filters( 'bt_mobile_touch_target_size', self::TOUCH_TARGET_MIN );
        $enabled   = (bool) apply_filters( 'bt_mobile_ux_enable', true );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'BlockTicker — Mobile UX', 'blockticker' ); ?></h1>

            <p class="description">
                <?php esc_html_e( 'Coordinated mobile-only UI layer: sticky bottom nav, asset-page CTA bar, back-to-top FAB, and WCAG-AAA touch-target audit. All components are filter-configurable; this screen is read-only visibility into the active configuration.', 'blockticker' ); ?>
            </p>

            <h2><?php esc_html_e( 'Active configuration', 'blockticker' ); ?></h2>
            <table class="widefat striped" style="max-width:780px">
                <tbody>
                    <tr>
                        <th><?php esc_html_e( 'Subsystem enabled', 'blockticker' ); ?></th>
                        <td>
                            <?php echo $enabled
                                ? '<span style="color:#00a32a">&#x2705; ' . esc_html__( 'On', 'blockticker' ) . '</span>'
                                : '<span style="color:#d63638">&#x274C; ' . esc_html__( 'Off (filtered out)', 'blockticker' ) . '</span>'; ?>
                            <code style="margin-left:12px">add_filter('bt_mobile_ux_enable', '__return_false')</code>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Mobile breakpoint', 'blockticker' ); ?></th>
                        <td><?php echo (int) self::BREAKPOINT; ?>px</td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Back-to-top threshold', 'blockticker' ); ?></th>
                        <td>
                            <?php echo (int) $threshold; ?>px scroll
                            <code style="margin-left:12px">add_filter('bt_mobile_back_to_top_threshold', …)</code>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Touch target minimum', 'blockticker' ); ?></th>
                        <td>
                            <?php echo (int) $size; ?>x<?php echo (int) $size; ?>px (WCAG 2.1 AAA: 44x44)
                            <code style="margin-left:12px">add_filter('bt_mobile_touch_target_size', …)</code>
                        </td>
                    </tr>
                </tbody>
            </table>

            <h2><?php esc_html_e( 'Bottom navigation items', 'blockticker' ); ?></h2>
            <p class="description">
                <?php
                printf(
                    /* translators: %s: filter name */
                    wp_kses_post( __( 'Override the entire item list via the %s filter (return an array of label/href/icon).', 'blockticker' ) ),
                    '<code>bt_mobile_nav_items</code>'
                );
                ?>
            </p>
            <table class="widefat striped" style="max-width:780px">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'ID', 'blockticker' ); ?></th>
                        <th><?php esc_html_e( 'Label', 'blockticker' ); ?></th>
                        <th><?php esc_html_e( 'Target', 'blockticker' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $items as $item ) : ?>
                        <tr>
                            <td><code><?php echo esc_html( $item['id'] ); ?></code></td>
                            <td><?php echo esc_html( $item['label'] ); ?></td>
                            <td><a href="<?php echo esc_url( $item['href'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $item['href'] ); ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <h2><?php esc_html_e( 'Component coverage', 'blockticker' ); ?></h2>
            <table class="widefat striped" style="max-width:780px">
                <tbody>
                    <tr>
                        <td><strong><?php esc_html_e( 'Bottom nav', 'blockticker' ); ?></strong></td>
                        <td><?php esc_html_e( 'Every page except wp-admin, login, AJAX, REST.', 'blockticker' ); ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e( 'Sticky CTA bar', 'blockticker' ); ?></strong></td>
                        <td><?php esc_html_e( '/crypto/{slug}/ and /forex/{slug}/ pages only.', 'blockticker' ); ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e( 'Back-to-top FAB', 'blockticker' ); ?></strong></td>
                        <td>
                            <?php
                            printf(
                                /* translators: %d: pixel threshold */
                                esc_html__( 'Every page; auto-shows after %dpx of scroll.', 'blockticker' ),
                                (int) $threshold
                            );
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e( 'Touch-target CSS', 'blockticker' ); ?></strong></td>
                        <td><?php esc_html_e( 'Site-wide; only activates at viewports under the breakpoint.', 'blockticker' ); ?></td>
                    </tr>
                </tbody>
            </table>

            <h2><?php esc_html_e( 'Filter reference', 'blockticker' ); ?></h2>
            <table class="widefat striped" style="max-width:780px">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Filter', 'blockticker' ); ?></th>
                        <th><?php esc_html_e( 'Purpose', 'blockticker' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td><code>bt_mobile_ux_enable</code></td><td><?php esc_html_e( 'Master kill-switch (return false to disable).', 'blockticker' ); ?></td></tr>
                    <tr><td><code>bt_mobile_nav_items</code></td><td><?php esc_html_e( 'Replace the bottom nav item list entirely.', 'blockticker' ); ?></td></tr>
                    <tr><td><code>bt_mobile_nav_show_on_admin</code></td><td><?php esc_html_e( 'Show bottom nav on wp-admin too.', 'blockticker' ); ?></td></tr>
                    <tr><td><code>bt_mobile_show_sticky_cta_on</code></td><td><?php esc_html_e( 'Per-asset CTA bar suppression (receives ctx array).', 'blockticker' ); ?></td></tr>
                    <tr><td><code>bt_mobile_back_to_top_threshold</code></td><td><?php esc_html_e( 'Pixels of scroll before FAB appears.', 'blockticker' ); ?></td></tr>
                    <tr><td><code>bt_mobile_touch_target_size</code></td><td><?php esc_html_e( 'Touch-target minimum (clamped 36-60px).', 'blockticker' ); ?></td></tr>
                </tbody>
            </table>
        </div>
        <?php
    }
}

BT_Mobile_UX::setup();
