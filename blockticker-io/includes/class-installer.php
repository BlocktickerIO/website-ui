<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * BT_Installer — Plugin recommendations for BlockTicker
 *
 * REMOVED (conflicting / redundant with BlockTicker built-ins):
 *   ❌ cryptocurrency-price-ticker-widget  — BlockTicker has its own ticker
 *   ❌ gpt3-ai-content-generator           — BlockTicker uses Claude AI
 *   ❌ wp-rss-aggregator                   — BlockTicker has its own RSS engine
 *   ❌ elementor                            — Not needed; BlockTicker renders its own pages
 *   ❌ contact-form-7                       — BlockTicker has its own contact form
 *   ❌ wpforms-lite                         — Duplicate of above
 *   ❌ ad-inserter                          — BlockTicker has its own ad/affiliate system
 *   ❌ google-sitemap-generator             — Yoast already generates sitemaps
 *
 * KEPT (genuinely useful, no conflicts):
 *   ✅ wordpress-seo (Yoast)               — SEO meta, schema, sitemaps
 *   ✅ wp-super-cache                       — Page caching (performance)
 *   ✅ wordfence            — Security firewall + login protection
 *   ✅ pojo-accessibility   — A11y toolbar
 *   Note: Yoast SEO removed in v119.6 — BT_SEO handles all SEO features natively.
 */
class BT_Installer {

    /**
     * Required third-party plugins — v119.6 audited list.
     *
     * Dropped:
     *   - Yoast SEO → redundant. BT_SEO (class-seo.php) already generates
     *     meta tags, schema.org markup, OG / Twitter cards, XML sitemap,
     *     and breadcrumbs. Running Yoast on top causes duplicate tags.
     *
     * Kept:
     *   - Wordfence           → critical firewall + login protection
     *   - One Click A11y      → small a11y toolbar (contrast, keyboard nav)
     */
    private static $plugins = array(
        array(
            'name'     => 'Wordfence Security',
            'slug'     => 'wordfence',
            'file'     => 'wordfence/wordfence.php',
            'reason'   => 'Firewall, malware scanner, login protection.',
        ),
        array(
            'name'     => 'One Click Accessibility',
            'slug'     => 'pojo-accessibility',
            'file'     => 'pojo-accessibility/accessibility.php',
            'reason'   => 'Accessibility toolbar (contrast, text size, keyboard nav).',
        ),
    );

    public static function install_plugins() {
        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        // Pre-check: can this server reach wordpress.org?
        // If not, plugin download will hang — fail fast with a clear message
        // instead of stalling for 60 s per plugin.
        $ping = wp_remote_get( 'https://api.wordpress.org/', array(
            'timeout'   => 8,
            'sslverify' => false,   // some shared hosts have outdated CA bundles
            'user-agent'=> 'BlockTicker/' . BT_VERSION . '; ' . home_url(),
        ) );
        if ( is_wp_error( $ping ) ) {
            // Count how many are already installed so the message is accurate
            $already = 0;
            foreach ( self::$plugins as $p ) {
                if ( is_plugin_active( $p['file'] ) || file_exists( WP_PLUGIN_DIR . '/' . $p['file'] ) ) $already++;
            }
            $total = count( self::$plugins );
            return array(
                'success' => false,
                'message' => sprintf(
                    'Cannot reach wordpress.org (%s). %d/%d plugins already installed. ' .
                    'Install remaining plugins manually via Plugins → Add New, then re-run this step. ' .
                    'Recommended: Yoast SEO, LiteSpeed Cache, Wordfence, UpdraftPlus.',
                    $ping->get_error_message(),
                    $already,
                    $total
                ),
            );
        }

        $installed = array();
        $skipped   = array();
        $failed    = array();

        foreach ( self::$plugins as $plugin ) {
            if ( is_plugin_active( $plugin['file'] ) ) {
                $skipped[] = $plugin['name'];
                continue;
            }

            if ( file_exists( WP_PLUGIN_DIR . '/' . $plugin['file'] ) ) {
                $result = activate_plugin( $plugin['file'] );
                if ( is_wp_error( $result ) ) {
                    $failed[] = $plugin['name'];
                } else {
                    $installed[] = $plugin['name'] . ' (activated)';
                }
                continue;
            }

            $api = plugins_api( 'plugin_information', array(
                'slug'   => $plugin['slug'],
                'fields' => array( 'sections' => false ),
            ) );

            if ( is_wp_error( $api ) ) {
                $failed[] = $plugin['name'];
                continue;
            }

            $upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
            $result   = $upgrader->install( $api->download_link );

            if ( is_wp_error( $result ) || ! $result ) {
                $failed[] = $plugin['name'];
            } else {
                activate_plugin( $plugin['file'] );
                $installed[] = $plugin['name'];
            }
        }

        $parts = array();
        if ( ! empty( $installed ) ) $parts[] = 'Installed: ' . implode( ', ', $installed );
        if ( ! empty( $skipped )  ) $parts[] = 'Already active: ' . implode( ', ', $skipped );
        if ( ! empty( $failed )   ) $parts[] = 'Failed: ' . implode( ', ', $failed );

        $msg = ! empty( $parts ) ? implode( '. ', $parts ) : 'No plugins to install.';

        return array( 'success' => empty( $failed ), 'message' => $msg );
    }

    public static function setup() {
        return self::install_plugins();
    }

    public static function get_plugin_list() {
        return self::$plugins;
    }

    /**
     * Deactivate known conflicting plugins if present.
     * Called once on plugin activation.
     */
    public static function deactivate_conflicts() {
        $conflicts = array(
            'cryptocurrency-price-ticker-widget/cryptocurrency-price-ticker-widget.php',
            'gpt3-ai-content-generator/gpt3-ai-content-generator.php',
            'wp-rss-aggregator/wp-rss-aggregator.php',
        );
        $deactivated = array();
        foreach ( $conflicts as $file ) {
            if ( is_plugin_active( $file ) ) {
                deactivate_plugins( $file );
                $deactivated[] = dirname( $file );
            }
        }
        if ( ! empty( $deactivated ) ) {
            update_option( 'bt_deactivated_conflicts', $deactivated );
        }
        return $deactivated;
    }

    public static function install_theme() {
        $theme_slug = 'generatepress';

        // Already active — nothing to do
        $current = wp_get_theme();
        if ( $current->get_stylesheet() === $theme_slug || $current->get_template() === $theme_slug ) {
            return array( 'success' => true, 'message' => 'GeneratePress is already active.' );
        }

        // Already installed but not active — just activate it
        $installed = wp_get_themes();
        if ( isset( $installed[ $theme_slug ] ) ) {
            switch_theme( $theme_slug );
            return array( 'success' => true, 'message' => 'GeneratePress activated.' );
        }

        // BlockTicker works with most themes — if a theme is already active, skip
        // rather than fail with a confusing "Network error"
        if ( $current->exists() && $current->get_stylesheet() !== 'twentytwentyfour' ) {
            return array(
                'success' => true,
                'message' => 'Theme "' . $current->get('Name') . '" is active. BlockTicker works with any theme. '
                           . 'If you want GeneratePress, install it from Appearance → Themes → Add New.',
            );
        }

        // Try to install from wordpress.org
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/theme-installer.php';
        require_once ABSPATH . 'wp-admin/includes/theme.php';

        $api = themes_api( 'theme_information', array(
            'slug'   => $theme_slug,
            'fields' => array( 'sections' => false ),
        ) );

        if ( is_wp_error( $api ) ) {
            return array(
                'success' => false,
                'message' => 'Could not reach wordpress.org to download GeneratePress. '
                           . 'Install it manually: Appearance → Themes → Add New → search "GeneratePress". '
                           . 'BlockTicker will work once it\'s active.',
            );
        }

        $upgrader = new Theme_Upgrader( new Automatic_Upgrader_Skin() );
        $result   = $upgrader->install( $api->download_link );

        if ( is_wp_error( $result ) || ! $result ) {
            return array(
                'success' => false,
                'message' => 'Auto-install failed. Go to Appearance → Themes → Add New and search "GeneratePress" to install manually.',
            );
        }

        switch_theme( $theme_slug );
        return array( 'success' => true, 'message' => 'GeneratePress installed and activated.' );
    }
}

