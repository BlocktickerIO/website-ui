<?php
/**
 * BT_PersonalizedPages — Cache-control for user-specific pages.
 *
 * Why this exists:
 *   /dashboard/, /screeners/, /following/, /watchlist/, /portfolio/, /alerts/
 *   render different HTML for different users (saved screeners, alerts,
 *   following list, etc.). WordPress page-caching plugins (LiteSpeed Cache,
 *   WP Rocket, W3 Total Cache, hosting-provider full-page caches) will happily
 *   serve a cached anonymous version of the page to a logged-in user, making
 *   it look like nothing they save is persisting — they save a screener, the
 *   server stores it, but the next page load returns the cached HTML which
 *   shows the empty-state.
 *
 *   This class hooks template_redirect (early enough that headers haven't
 *   been sent) and sends:
 *     - nocache_headers() — WordPress's standard no-cache trio
 *     - Cache-Control: private, no-cache, no-store, max-age=0, must-revalidate
 *     - Pragma: no-cache (HTTP/1.0 compat)
 *     - Vary: Cookie (CDNs that respect Vary will serve different cached
 *       versions to logged-in vs anonymous users)
 *     - X-Robots-Tag: noindex (defence-in-depth: personalized URLs shouldn't
 *       be indexed even if a robots.txt slip-up exposes them)
 *
 * Two filters for site-specific extension:
 *   - bt_personalized_page_slugs       — array of slug strings to protect
 *   - bt_personalized_pages_enabled    — disable the whole class if false
 *
 * v119.21.0 — surgical fix for the user-reported "screeners not saved" /
 * "alerts not in account" symptoms, which were really cache-staleness, not
 * server-side persistence bugs.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class BT_PersonalizedPages {

    /**
     * Default protected slugs. Each slug matches the WordPress page slug
     * (the `post_name`), not a URL path — so subpath sites and sites with
     * different permalink configurations all work.
     *
     * Order matters only cosmetically (no early exit).
     */
    const DEFAULT_SLUGS = array(
        'dashboard',
        'screeners',
        'following',
        'watchlist',
        'portfolio',
        'alerts',
        'brief',        // v119.22: personalized AI brief page
    );

    public static function setup() {
        if ( ! apply_filters( 'bt_personalized_pages_enabled', true ) ) return;
        // template_redirect runs after page query is resolved but before
        // template loads — last safe place to manipulate response headers.
        add_action( 'template_redirect', array( __CLASS__, 'maybe_send_nocache' ), 1 );
    }

    public static function maybe_send_nocache() {
        // Don't touch admin or AJAX — admin-ajax already handles its own caching.
        if ( is_admin() || wp_doing_ajax() ) return;
        // Don't touch feed/REST/cron requests.
        if ( is_feed() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) return;
        // Only act on a singular front-end view.
        if ( ! is_singular() ) return;

        $slug = self::get_current_slug();
        if ( $slug === '' ) return;

        $protected = (array) apply_filters( 'bt_personalized_page_slugs', self::DEFAULT_SLUGS );
        $protected = array_map( 'sanitize_key', $protected );
        if ( ! in_array( $slug, $protected, true ) ) return;

        // WordPress's standard no-cache headers (Expires/Cache-Control/Pragma).
        nocache_headers();

        // Override with stronger directives. nocache_headers() emits
        // "Cache-Control: no-cache, must-revalidate, max-age=0" — we add
        // private + no-store so intermediaries (CDNs, proxies) absolutely
        // cannot share a response between users.
        if ( ! headers_sent() ) {
            header( 'Cache-Control: private, no-cache, no-store, max-age=0, must-revalidate', true );
            header( 'Pragma: no-cache', true );
            // Vary: Cookie — when a CDN does cache (e.g. for anonymous
            // visitors), it will key the cache by the auth cookie and serve
            // a fresh response to logged-in users.
            header( 'Vary: Cookie', false );
            // Defence in depth — these URLs should never be in SERPs.
            header( 'X-Robots-Tag: noindex, nofollow', true );
        }
    }

    /**
     * Resolve the current page's WordPress slug (post_name). Returns ''
     * when not on a singular page or when the queried object is malformed.
     */
    private static function get_current_slug() {
        $obj = get_queried_object();
        if ( ! $obj || ! isset( $obj->post_name ) ) return '';
        return (string) $obj->post_name;
    }
}

BT_PersonalizedPages::setup();
