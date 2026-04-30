<?php
/**
 * BlockTicker PWA — Progressive Web App support.
 *
 * Adds:
 *   - /bt-manifest.webmanifest (dynamic, site-name aware)
 *   - /bt-sw.js (service worker with smart caching strategy)
 *   - <link rel="manifest"> + apple-touch-icon + theme-color in <head>
 *   - Client-side install prompt (dismissible, 7-day re-prompt cooldown)
 *
 * v64.1 — initial PWA release
 *
 * @package BlockTicker
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BT_PWA {

    public static function init() {
        // Register URL endpoints for manifest + service worker — served through
        // a query-var handler so they work even without pretty permalinks.
        add_action( 'init',            array( __CLASS__, 'register_rewrites' ) );
        add_filter( 'query_vars',      array( __CLASS__, 'add_query_vars' ) );
        add_action( 'template_redirect', array( __CLASS__, 'maybe_serve_pwa_files' ), 1 );

        // Inject <link rel="manifest"> + theme-color + apple meta
        add_action( 'wp_head', array( __CLASS__, 'output_pwa_head' ), 2 );

        // Service worker registration + install-prompt UI
        add_action( 'wp_footer', array( __CLASS__, 'output_pwa_client_script' ), 99 );
    }

    /**
     * Register rewrites so /bt-manifest.webmanifest and /bt-sw.js resolve.
     * Flushed on plugin activation via the existing install routine.
     */
    public static function register_rewrites() {
        add_rewrite_rule( '^bt-manifest\.webmanifest$', 'index.php?bt_pwa=manifest', 'top' );
        add_rewrite_rule( '^bt-sw\.js$',                'index.php?bt_pwa=sw',       'top' );
    }

    public static function add_query_vars( $vars ) {
        $vars[] = 'bt_pwa';
        return $vars;
    }

    /**
     * Intercept the template_redirect early if the request is for our PWA file.
     * Emits JSON for the manifest, JS for the service worker, then dies.
     */
    public static function maybe_serve_pwa_files() {
        $type = get_query_var( 'bt_pwa' );
        if ( ! $type ) return;

        nocache_headers(); // Disable WP default caching headers — we set our own

        if ( $type === 'manifest' ) {
            header( 'Content-Type: application/manifest+json; charset=utf-8' );
            header( 'Cache-Control: public, max-age=3600' ); // 1h — light caching
            echo self::build_manifest_json();
            exit;
        }

        if ( $type === 'sw' ) {
            header( 'Content-Type: application/javascript; charset=utf-8' );
            // Service workers must not be cached aggressively — browser needs to
            // detect updates. 5min is the common balance.
            header( 'Cache-Control: public, max-age=300, must-revalidate' );
            header( 'Service-Worker-Allowed: /' );
            echo self::build_service_worker_js();
            exit;
        }
    }

    /**
     * Build manifest.json. Site-name aware so multisite installs get correct
     * branding. Theme color matches the main brand teal (#00FF66).
     */
    private static function build_manifest_json() {
        $site_name  = get_option( 'bt_site_name', get_bloginfo( 'name' ) ?: 'BlockTicker' );
        $short_name = mb_strlen( $site_name ) > 12 ? mb_substr( $site_name, 0, 12 ) : $site_name;
        $img_base   = BT_URL . 'assets/images/pwa/';

        $manifest = array(
            'name'             => $site_name . ' — Crypto & Forex Intelligence',
            'short_name'       => $short_name,
            'description'      => 'Live crypto and forex prices, news, signals, and AI-powered market analysis. 100% free, no account required.',
            'start_url'        => home_url( '/?utm_source=pwa' ),
            'scope'            => home_url( '/' ),
            'display'          => 'standalone',
            'orientation'      => 'any',
            'theme_color'      => '#0A0B0D',
            'background_color' => '#0A0B0D',
            'lang'             => get_bloginfo( 'language' ) ?: 'en-US',
            'categories'       => array( 'finance', 'business', 'news' ),
            'icons'            => array(
                array(
                    'src'     => $img_base . 'icon-192.png',
                    'type'    => 'image/png',
                    'sizes'   => '192x192',
                    'purpose' => 'any',
                ),
                array(
                    'src'     => $img_base . 'icon-512.png',
                    'type'    => 'image/png',
                    'sizes'   => '512x512',
                    'purpose' => 'any',
                ),
                array(
                    'src'     => $img_base . 'icon-maskable-512.png',
                    'type'    => 'image/png',
                    'sizes'   => '512x512',
                    'purpose' => 'maskable',
                ),
            ),
            'shortcuts'        => array(
                array(
                    'name'        => 'Today\'s Intelligence Brief',
                    'short_name'  => 'Brief',
                    'description' => 'Cross-market intelligence with the latest verdict',
                    'url'         => home_url( '/?utm_source=pwa&utm_medium=shortcut#brief' ),
                    'icons'       => array( array( 'src' => $img_base . 'icon-192.png', 'sizes' => '192x192' ) ),
                ),
                array(
                    'name'        => 'Crypto Markets',
                    'short_name'  => 'Crypto',
                    'description' => 'Live crypto prices',
                    'url'         => home_url( '/crypto-markets/?utm_source=pwa&utm_medium=shortcut' ),
                    'icons'       => array( array( 'src' => $img_base . 'icon-192.png', 'sizes' => '192x192' ) ),
                ),
                array(
                    'name'        => 'Forex Rates',
                    'short_name'  => 'Forex',
                    'description' => 'Live currency pairs',
                    'url'         => home_url( '/forex-charts/?utm_source=pwa&utm_medium=shortcut' ),
                    'icons'       => array( array( 'src' => $img_base . 'icon-192.png', 'sizes' => '192x192' ) ),
                ),
                array(
                    'name'        => 'Trading Signals',
                    'short_name'  => 'Signals',
                    'description' => 'Free signals, refreshed every 15 minutes',
                    'url'         => home_url( '/trading-signals/?utm_source=pwa&utm_medium=shortcut' ),
                    'icons'       => array( array( 'src' => $img_base . 'icon-192.png', 'sizes' => '192x192' ) ),
                ),
            ),
            'prefer_related_applications' => false,
        );

        return wp_json_encode( $manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    }

    /**
     * Build the service worker JS. Three-layer strategy:
     *
     *   1. Network-first for HTML (always try fresh; fall back to cache; fall back to offline shell)
     *   2. Cache-first for our static assets (CSS, JS, images, fonts) — fast repeat loads
     *   3. Network-only, no cache — for anything we must not stale: admin, ajax, REST API,
     *      TradingView/CoinGecko/Frankfurter (live data), any wp-admin URL
     *
     * The cache version is tied to BT_VERSION so plugin updates invalidate the old cache.
     */
    private static function build_service_worker_js() {
        $cache_v    = 'bt-v' . BT_VERSION;
        $home       = esc_url_raw( home_url( '/' ) );
        $asset_base = esc_url_raw( BT_URL );

        // Files to pre-cache on install. Keep minimal — too many and install fails on slow networks.
        $precache = array(
            $home,
            $asset_base . 'assets/css/frontend.css',
            $asset_base . 'assets/css/revamp-v44.css',
            $asset_base . 'assets/js/revamp-v44.js',
            $asset_base . 'assets/js/frontend.js',
            $asset_base . 'assets/images/pwa/icon-192.png',
        );

        ob_start();
        
?>
/* BlockTicker Service Worker — auto-generated by BT_PWA::build_service_worker_js() */
const CACHE_VERSION = '<?php echo esc_js( $cache_v ); ?>';
const CACHE_HTML    = CACHE_VERSION + '-html';
const CACHE_STATIC  = CACHE_VERSION + '-static';
const PRECACHE_URLS = <?php echo wp_json_encode( $precache ); ?>;

/* Hostnames we NEVER cache — live data must stay fresh */
const NO_CACHE_HOSTS = [
    's3.tradingview.com', 's.tradingview.com', 'www.tradingview.com',
    'api.coingecko.com', 'api.frankfurter.app', 'open.er-api.com',
    'cdn.jsdelivr.net', 'api.currencyfreaks.com',
    'widget.coindesk.com', 'pagead2.googlesyndication.com',
    'www.googletagmanager.com', 'www.google-analytics.com',
    'api.typefully.com', 'api.twitter.com'
];
/* URL path prefixes we NEVER cache (authenticated / dynamic) */
const NO_CACHE_PATHS = [ '/wp-admin/', '/wp-login', '/wp-json/', '/wp-cron.php', '/?p=', '/wp-content/uploads/' ];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_STATIC)
            .then((cache) => cache.addAll(PRECACHE_URLS).catch(() => null))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(keys.filter((k) => !k.startsWith(CACHE_VERSION)).map((k) => caches.delete(k)))
        ).then(() => self.clients.claim())
    );
});

function shouldBypass(url) {
    if (NO_CACHE_HOSTS.some((h) => url.hostname === h || url.hostname.endsWith('.' + h))) return true;
    if (NO_CACHE_PATHS.some((p) => url.pathname.startsWith(p))) return true;
    // WordPress AJAX endpoint
    if (url.pathname.endsWith('/admin-ajax.php')) return true;
    // Preview/logged-in content
    if (url.searchParams.has('preview') || url.searchParams.has('p')) return true;
    return false;
}

function isOurAsset(url) {
    const path = url.pathname;
    if (!path.includes('/wp-content/plugins/blockticker')) return false;
    return /\.(css|js|png|jpg|jpeg|webp|svg|woff2?|ttf)$/i.test(path);
}

self.addEventListener('fetch', (event) => {
    // Only handle GET — never cache POST (admin-ajax uses POST)
    if (event.request.method !== 'GET') return;
    const url = new URL(event.request.url);

    // Same-origin different scheme: ignore
    if (!url.protocol.startsWith('http')) return;

    // Bypass list — go straight to network
    if (shouldBypass(url)) return; // don't respondWith — lets browser handle normally

    // Cache-first for our own static assets
    if (isOurAsset(url)) {
        event.respondWith(
            caches.match(event.request).then((hit) => {
                if (hit) return hit;
                return fetch(event.request).then((res) => {
                    if (res && res.ok && res.type !== 'opaque') {
                        const clone = res.clone();
                        caches.open(CACHE_STATIC).then((c) => c.put(event.request, clone));
                    }
                    return res;
                });
            })
        );
        return;
    }

    // Network-first for HTML navigation (same-origin only — no caching of 3rd-party HTML)
    const isHTML = event.request.mode === 'navigate' ||
                   (event.request.headers.get('accept') || '').includes('text/html');
    const isSameOrigin = url.origin === self.location.origin;

    if (isHTML && isSameOrigin) {
        event.respondWith(
            fetch(event.request).then((res) => {
                if (res && res.ok) {
                    const clone = res.clone();
                    caches.open(CACHE_HTML).then((c) => c.put(event.request, clone));
                }
                return res;
            }).catch(() =>
                caches.match(event.request).then((hit) => {
                    if (hit) return hit;
                    // Ultimate fallback: cached homepage
                    return caches.match('<?php echo esc_js( $home ); ?>').then((home) =>
                        home || new Response(
                            '<!doctype html><meta charset=utf-8><title>Offline</title>' +
                            '<style>body{background:#0A0B0D;color:var(--bt-text);font-family:system-ui;display:flex;' +
                            'align-items:center;justify-content:center;min-height:100vh;margin:0;text-align:center;padding:20px}' +
                            'h1{color:#00FF66;font-size:22px;margin:0 0 12px}p{color:var(--bt-text-2);max-width:380px;line-height:1.5}' +
                            'button{margin-top:20px;background:#00FF66;color:#0A0B0D;border:none;padding:12px 22px;' +
                            'border-radius:0;font-weight:700;cursor:pointer}</style>' +
                            '<div><h1>📡 You are offline</h1><p>Live market data needs an internet connection. ' +
                            'Reconnect and try again.</p><button onclick="location.reload()">Retry</button></div>',
                            { headers: { 'Content-Type': 'text/html; charset=utf-8' } }
                        )
                    );
                })
            )
        );
    }
    // All other requests: let the browser handle normally
});

/* Message channel — page can ask SW to skip waiting to activate an update */
self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'SKIP_WAITING') self.skipWaiting();
});
        <?php
        return ob_get_clean();
    }

    /**
     * Inject <link rel="manifest"> and related PWA meta into <head>.
     */
    public static function output_pwa_head() {
        if ( is_admin() ) return;
        $manifest_url = home_url( '/bt-manifest.webmanifest' );
        $apple_icon   = BT_URL . 'assets/images/pwa/apple-touch-icon-180.png';
        $favicon32    = BT_URL . 'assets/images/pwa/favicon-32.png';
        ?>
        <link rel="manifest" href="<?php echo esc_url( $manifest_url ); ?>" />
        <meta name="theme-color" content="#0A0B0D" />
        <meta name="color-scheme" content="dark" />
        <meta name="apple-mobile-web-app-capable" content="yes" />
        <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent" />
        <meta name="apple-mobile-web-app-title" content="<?php echo esc_attr( get_option( 'bt_site_name', 'BlockTicker' ) ); ?>" />
        <link rel="apple-touch-icon" sizes="180x180" href="<?php echo esc_url( $apple_icon ); ?>" />
        <link rel="icon" type="image/png" sizes="32x32" href="<?php echo esc_url( $favicon32 ); ?>" />
        <?php
    }

    /**
     * Footer script: registers the service worker + renders the install-prompt UI.
     * Prompt appears when browser fires beforeinstallprompt; dismissable with
     * 7-day cooldown via localStorage.
     */
    public static function output_pwa_client_script() {
        if ( is_admin() ) return;
        $sw_url = home_url( '/bt-sw.js' );
        ?>
        <div id="bt-install-prompt" hidden role="dialog" aria-label="Install BlockTicker app">
            <div class="bt-install-icon" aria-hidden="true">📱</div>
            <div class="bt-install-text">
                <strong>Install BlockTicker</strong>
                <span>Quick access from your home screen — no app store needed.</span>
            </div>
            <button id="bt-install-accept" type="button">Install</button>
            <button id="bt-install-dismiss" type="button" aria-label="Dismiss">✕</button>
        </div>
        <style>
        #bt-install-prompt {
            position: fixed; bottom: 16px; left: 16px; right: 16px; z-index: 9998;
            max-width: 440px; margin: 0 auto;
            display: flex; align-items: center; gap: 12px;
            background: linear-gradient(135deg, rgba(10,14,26,.98), rgba(17,24,39,.98));
            border: 1px solid rgba(0,255,102,.3);
            border-radius: 14px;
            padding: 12px 14px 12px 18px;
            box-shadow: 0 20px 50px -12px rgba(0,0,0,.6), 0 0 0 1px rgba(255,255,255,.02);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            font-family: var(--bt-font-body, 'IBM Plex Sans', var(--bt-font-body), sans-serif);
            animation: btInstallSlideUp .35s cubic-bezier(.2,.8,.2,1);
        }
        /* v67: hidden attribute MUST override display:flex above —
           browser default [hidden]{display:none} loses to our display:flex specificity. */
        #bt-install-prompt[hidden] { display: none !important; }
        @keyframes btInstallSlideUp {
            from { transform: translateY(120%); opacity: 0; }
            to   { transform: translateY(0);    opacity: 1; }
        }
        .bt-install-icon { font-size: 28px; flex-shrink: 0; }
        .bt-install-text { flex: 1; min-width: 0; display: flex; flex-direction: column; }
        .bt-install-text strong { color: var(--bt-text); font-size: 14px; font-weight: 700; }
        .bt-install-text span { color: var(--bt-text-2); font-size: 12px; line-height: 1.4; margin-top: 2px; }
        #bt-install-accept {
            background: linear-gradient(135deg, #00FF66, var(--bt-accent));
            color: #0A0B0D; border: none; padding: 9px 16px;
            border-radius: 0; font-size: 13px; font-weight: 700;
            cursor: pointer; flex-shrink: 0; font-family: inherit;
            transition: transform .15s; white-space: nowrap;
        }
        #bt-install-accept:hover { transform: translateY(-1px); }
        #bt-install-dismiss {
            background: transparent; border: 1px solid rgba(255,255,255,.08);
            color: var(--bt-text-3); width: 30px; height: 30px;
            border-radius: 6px; font-size: 13px; cursor: pointer;
            flex-shrink: 0; font-family: inherit; line-height: 1;
            transition: border-color .15s, color .15s;
        }
        #bt-install-dismiss:hover { color: var(--bt-text); border-color: rgba(255,255,255,.2); }
        @media (prefers-reduced-motion: reduce) {
            #bt-install-prompt { animation: none; }
        }
        @media (max-width: 520px) {
            #bt-install-prompt { left: 12px; right: 12px; bottom: 12px; padding: 10px 12px; }
            .bt-install-text span { font-size: 11.5px; }
        }
        </style>
        <script>
        (function () {
            // Register service worker
            if ('serviceWorker' in navigator) {
                window.addEventListener('load', function () {
                    navigator.serviceWorker.register('<?php echo esc_js( $sw_url ); ?>', { scope: '/' })
                        .catch(function () { /* silent fail — PWA is progressive enhancement */ });
                });
            }

            // Install prompt — browser fires beforeinstallprompt only if PWA criteria are met
            var deferred = null;
            var KEY = 'bt_install_prompt_dismissed_until';
            var prompt = document.getElementById('bt-install-prompt');
            if (!prompt) return;

            // Respect dismissal cooldown (7 days)
            var dismissedUntil = parseInt(localStorage.getItem(KEY) || '0', 10);
            if (Date.now() < dismissedUntil) return;

            // Don't show if already running as an installed PWA
            if (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) return;
            if (window.navigator.standalone === true) return; // iOS

            window.addEventListener('beforeinstallprompt', function (e) {
                e.preventDefault();
                deferred = e;
                // Delay show by 30s so we don't interrupt first-visit exploration
                setTimeout(function () {
                    if (deferred) prompt.hidden = false;
                }, 30000);
            });

            document.getElementById('bt-install-accept').addEventListener('click', function () {
                if (!deferred) { prompt.hidden = true; return; }
                deferred.prompt();
                deferred.userChoice.then(function () {
                    deferred = null;
                    prompt.hidden = true;
                });
            });
            document.getElementById('bt-install-dismiss').addEventListener('click', function () {
                prompt.hidden = true;
                localStorage.setItem(KEY, String(Date.now() + 7 * 24 * 3600 * 1000));
            });

            // When the app is installed, hide prompt + never show again
            window.addEventListener('appinstalled', function () {
                prompt.hidden = true;
                localStorage.setItem(KEY, String(Date.now() + 365 * 24 * 3600 * 1000));
            });
        })();
        </script>
        <?php
    }
}

BT_PWA::init();
