<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * BT_AssetPages — P2-2 Individual Asset Landing Pages
 *
 * Registers two custom URL structures:
 *   /crypto/{slug}/   e.g. /crypto/bitcoin, /crypto/ethereum
 *   /forex/{pair}/    e.g. /forex/eur-usd, /forex/gbp-usd
 *
 * Each page contains:
 *   - Live price + 24h change (from stored data)
 *   - TradingView chart (lazy-loaded)
 *   - Key metrics (market cap, volume, rank)
 *   - Related news filtered by asset name
 *   - Related AI analysis posts
 *   - Full JSON-LD schema (FinancialProduct + BreadcrumbList)
 *   - Yoast-compatible meta title + description via wp_head filter
 */
class BT_AssetPages {

    // Supported crypto slugs → display data
    private static $crypto_map = array(
        'bitcoin'       => array( 'symbol' => 'BTC',  'name' => 'Bitcoin',       'tv' => 'BINANCE:BTCUSDT',  'color' => '#f7931a' ),
        'ethereum'      => array( 'symbol' => 'ETH',  'name' => 'Ethereum',       'tv' => 'BINANCE:ETHUSDT',  'color' => '#627eea' ),
        'solana'        => array( 'symbol' => 'SOL',  'name' => 'Solana',         'tv' => 'BINANCE:SOLUSDT',  'color' => '#9945ff' ),
        'xrp'           => array( 'symbol' => 'XRP',  'name' => 'XRP',            'tv' => 'BINANCE:XRPUSDT',  'color' => '#346aa9' ),
        'binance-coin'  => array( 'symbol' => 'BNB',  'name' => 'BNB',            'tv' => 'BINANCE:BNBUSDT',  'color' => '#f3ba2f' ),
        'dogecoin'      => array( 'symbol' => 'DOGE', 'name' => 'Dogecoin',       'tv' => 'BINANCE:DOGEUSDT', 'color' => '#c2a633' ),
        'cardano'       => array( 'symbol' => 'ADA',  'name' => 'Cardano',        'tv' => 'BINANCE:ADAUSDT',  'color' => '#0d1e2d' ),
        'avalanche'     => array( 'symbol' => 'AVAX', 'name' => 'Avalanche',      'tv' => 'BINANCE:AVAXUSDT', 'color' => '#e84142' ),
        'chainlink'     => array( 'symbol' => 'LINK', 'name' => 'Chainlink',      'tv' => 'BINANCE:LINKUSDT', 'color' => '#2a5ada' ),
        'polkadot'      => array( 'symbol' => 'DOT',  'name' => 'Polkadot',       'tv' => 'BINANCE:DOTUSDT',  'color' => '#e6007a' ),
        'tron'          => array( 'symbol' => 'TRX',  'name' => 'TRON',           'tv' => 'BINANCE:TRXUSDT',  'color' => '#ff0013' ),
        'monero'        => array( 'symbol' => 'XMR',  'name' => 'Monero',         'tv' => 'BINANCE:XMRUSDT',  'color' => '#ff6600' ),
        'litecoin'      => array( 'symbol' => 'LTC',  'name' => 'Litecoin',       'tv' => 'BINANCE:LTCUSDT',  'color' => '#bfbbbb' ),
        'stellar'       => array( 'symbol' => 'XLM',  'name' => 'Stellar',        'tv' => 'BINANCE:XLMUSDT',  'color' => '#000000' ),
        'hyperliquid'   => array( 'symbol' => 'HYPE', 'name' => 'Hyperliquid',    'tv' => 'BINANCE:HYPEUSDT', 'color' => 'var(--bt-accent)' ),
        'uniswap'       => array( 'symbol' => 'UNI',  'name' => 'Uniswap',        'tv' => 'BINANCE:UNIUSDT',  'color' => '#ff007a' ),
        'near-protocol' => array( 'symbol' => 'NEAR', 'name' => 'NEAR Protocol',  'tv' => 'BINANCE:NEARUSDT', 'color' => '#00c08b' ),
        'bitcoin-cash'  => array( 'symbol' => 'BCH',  'name' => 'Bitcoin Cash',   'tv' => 'BINANCE:BCHUSDT',  'color' => '#8dc351' ),
        'internet-computer' => array( 'symbol' => 'ICP', 'name' => 'Internet Computer', 'tv' => 'BINANCE:ICPUSDT', 'color' => '#29abe2' ),
        'aptos'         => array( 'symbol' => 'APT',  'name' => 'Aptos',          'tv' => 'BINANCE:APTUSDT',  'color' => '#2dd8a3' ),
    );

    // Supported forex pairs → display data
    private static $forex_map = array(
        'eur-usd' => array( 'pair' => 'EUR/USD', 'name' => 'Euro / US Dollar',           'tv' => 'FX:EURUSD',  'base' => 'Euro',       'quote' => 'US Dollar'   ),
        'gbp-usd' => array( 'pair' => 'GBP/USD', 'name' => 'British Pound / US Dollar',  'tv' => 'FX:GBPUSD',  'base' => 'Pound',      'quote' => 'US Dollar'   ),
        'usd-jpy' => array( 'pair' => 'USD/JPY', 'name' => 'US Dollar / Japanese Yen',   'tv' => 'FX:USDJPY',  'base' => 'US Dollar',  'quote' => 'Japanese Yen'),
        'usd-chf' => array( 'pair' => 'USD/CHF', 'name' => 'US Dollar / Swiss Franc',    'tv' => 'FX:USDCHF',  'base' => 'US Dollar',  'quote' => 'Swiss Franc' ),
        'aud-usd' => array( 'pair' => 'AUD/USD', 'name' => 'Australian Dollar / US Dollar','tv'=> 'FX:AUDUSD', 'base' => 'Aussie',     'quote' => 'US Dollar'   ),
        'usd-cad' => array( 'pair' => 'USD/CAD', 'name' => 'US Dollar / Canadian Dollar', 'tv' => 'FX:USDCAD',  'base' => 'US Dollar',  'quote' => 'CAD'         ),
        'nzd-usd' => array( 'pair' => 'NZD/USD', 'name' => 'New Zealand Dollar / US Dollar','tv'=>'FX:NZDUSD', 'base' => 'Kiwi',       'quote' => 'US Dollar'   ),
        'eur-gbp' => array( 'pair' => 'EUR/GBP', 'name' => 'Euro / British Pound',        'tv' => 'FX:EURGBP',  'base' => 'Euro',       'quote' => 'Pound'       ),
        'eur-jpy' => array( 'pair' => 'EUR/JPY', 'name' => 'Euro / Japanese Yen',         'tv' => 'FX:EURJPY',  'base' => 'Euro',       'quote' => 'Japanese Yen'),
        'gbp-jpy' => array( 'pair' => 'GBP/JPY', 'name' => 'British Pound / Japanese Yen','tv'=> 'FX:GBPJPY',  'base' => 'Pound',      'quote' => 'Japanese Yen'),
        'usd-mxn' => array( 'pair' => 'USD/MXN', 'name' => 'US Dollar / Mexican Peso',   'tv' => 'OANDA:USDMXN','base' => 'US Dollar',  'quote' => 'Mexican Peso'),
        'usd-sgd' => array( 'pair' => 'USD/SGD', 'name' => 'US Dollar / Singapore Dollar','tv'=> 'OANDA:USDSGD','base' => 'US Dollar',  'quote' => 'SGD'         ),
        // Exotic pairs
        'usd-thb' => array( 'pair' => 'USD/THB', 'name' => 'US Dollar / Thai Baht',       'tv' => 'OANDA:USDTHB','base' => 'US Dollar',  'quote' => 'Thai Baht'   ),
        'usd-try' => array( 'pair' => 'USD/TRY', 'name' => 'US Dollar / Turkish Lira',    'tv' => 'OANDA:USDTRY','base' => 'US Dollar',  'quote' => 'Turkish Lira'),
        'usd-hkd' => array( 'pair' => 'USD/HKD', 'name' => 'US Dollar / Hong Kong Dollar','tv' => 'OANDA:USDHKD','base' => 'US Dollar',  'quote' => 'HK Dollar'   ),
        'usd-ils' => array( 'pair' => 'USD/ILS', 'name' => 'US Dollar / Israeli Shekel',  'tv' => 'OANDA:USDILS','base' => 'US Dollar',  'quote' => 'Shekel'      ),
    );

    public static function init() {
        // Keep rewrite rules registered (for when permalinks ARE flushed)
        add_action( 'init', array( __CLASS__, 'add_rewrite_rules' ) );
        add_filter( 'query_vars', array( __CLASS__, 'add_query_vars' ) );

        // PRIMARY route: parse URL directly - works EVEN when rewrite rules aren't flushed
        // This fires very early and bypasses WP's routing entirely
        add_action( 'wp_loaded', array( __CLASS__, 'intercept_asset_url' ), 1 );

        // SECONDARY route: template_redirect fallback (when rewrite rules DO work)
        add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), 1 );

        add_action( 'wp_head', array( __CLASS__, 'output_asset_meta' ) );
        add_filter( 'document_title_parts', array( __CLASS__, 'filter_title' ) );
        add_action( 'wp_ajax_fxlm_asset_vote',        array( __CLASS__, 'ajax_vote' ) );
        add_action( 'wp_ajax_nopriv_fxlm_asset_vote', array( __CLASS__, 'ajax_vote' ) );
        add_filter( 'template_include', array( __CLASS__, 'block_theme_template' ), 999 );
    }

    /**
     * Intercept /crypto/{slug}/ and /forex/{slug}/ by parsing the raw REQUEST_URI.
     * This works regardless of whether WordPress rewrite rules have been flushed.
     * Fires on wp_loaded before any template selection.
     */
    public static function intercept_asset_url() {
        $uri = trim( parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ), '/' );

        if ( preg_match( '#^crypto/([a-z0-9-]+)/?$#', $uri, $m ) ) {
            $slug = $m[1];
            // Known coins: use static map for speed
            if ( isset( self::$crypto_map[ $slug ] ) ) {
                self::render_crypto_page( $slug, self::$crypto_map[ $slug ] );
                exit;
            }
            // Unknown coin: build asset data dynamically from stored top-500 or CoinGecko
            $asset = self::build_dynamic_asset( $slug );
            if ( $asset ) {
                self::render_crypto_page( $slug, $asset );
                exit;
            }
        }
        if ( preg_match( '#^forex/([a-z0-9-]+)/?$#', $uri, $m ) ) {
            $slug = $m[1];
            if ( isset( self::$forex_map[ $slug ] ) ) {
                self::render_forex_page( $slug, self::$forex_map[ $slug ] );
                exit;
            }
        }
        // /analysis/{slug}/ — per-asset micro-verdict pages (v85)
        if ( preg_match( '#^analysis/([a-z0-9-]+)/?$#', $uri, $m ) ) {
            $slug = sanitize_title( $m[1] );
            BT_IntelligenceBrief::render_asset_analysis( $slug );
            exit;
        }
        // Redirect /crypto-category/X/ to flat page /crypto-category-X/
        if ( preg_match( '#^crypto-category/([a-z0-9-]+)/?$#', $uri, $m ) ) {
            $cat  = sanitize_title( $m[1] );
            $dest = home_url( '/crypto-category-' . $cat . '/' );
            wp_redirect( $dest, 301 );
            exit;
        }
        // Nested /exchanges/{spot|derivatives|dex-spot|dex-derivatives}/ — hard redirect to flat slug.
        // This works even before rewrite rules are flushed after install/update.
        if ( preg_match( '#^exchanges/(spot|derivatives|dex-spot|dex-derivatives)/?$#', $uri, $m ) ) {
            $dest = home_url( '/exchanges-' . $m[1] . '/' );
            wp_redirect( $dest, 301 );
            exit;
        }
        // Nested /dexscan/{slug}/ — same pattern.
        if ( preg_match( '#^dexscan/(signals|trending|new|gainers|meme|top-traders)/?$#', $uri, $m ) ) {
            $dest = home_url( '/dexscan-' . $m[1] . '/' );
            wp_redirect( $dest, 301 );
            exit;
        }
    }

    /**
     * Build asset metadata dynamically for any coin by slug.
     * Searches stored CoinGecko data first, then falls back to API.
     */
    private static function build_dynamic_asset( $slug ) {
        // Search in stored top-500 coins
        $stored  = BT_Widgets::get_json_option( 'fxlm_crypto_data' );
        $coins   = $stored['coins'] ?? [];
        $matched = null;

        foreach ( $coins as $c ) {
            // Match by CoinGecko ID (slug) or sanitized name
            if ( $c['id'] === $slug || sanitize_title($c['name']) === $slug ) {
                $matched = $c; break;
            }
        }

        // If not in stored data, fetch from CoinGecko directly
        if ( ! $matched ) {
            $api_key = get_option( 'bt_cg_api_key', '' );
            $args    = array( 'timeout' => 10 );
            if ( $api_key ) $args['headers'] = array( 'x-cg-demo-api-key' => $api_key );
            $data = BT_Utils::http_get_json(
                "https://api.coingecko.com/api/v3/coins/{$slug}?localization=false&tickers=false&market_data=true&community_data=false&developer_data=false",
                $args
            );
            if ( ! is_wp_error( $data ) && ! empty( $data['id'] ) ) {
                $matched = [
                    'id'     => $data['id'],
                    'name'   => $data['name'],
                    'symbol' => strtoupper( $data['symbol'] ?? '' ),
                    'image'  => $data['image']['large'] ?? '',
                ];
            }
        }

        if ( ! $matched ) return null;

        $sym  = strtoupper($matched['symbol'] ?? '');
        $name = $matched['name'] ?? '';

        // Build TradingView symbol — try BINANCE first, fall back to generic
        $tv_sym = 'BINANCE:' . $sym . 'USDT';

        return [
            'symbol' => $sym,
            'name'   => $name,
            'tv'     => $tv_sym,
            'color'  => 'var(--bt-accent)', // default color for unknown coins
            'image'  => $matched['image'] ?? '',
            'dynamic'=> true,
        ];
    }
    /**
     * Prevent theme template_include from overriding asset pages that we render ourselves.
     * This is needed because GeneratePress hooks template_include at priority 10.
     */
    public static function block_theme_template( $template ) {
        $crypto_slug = get_query_var( 'fxlm_crypto_slug' );
        $forex_slug  = get_query_var( 'fxlm_forex_slug' );
        if (
            ( $crypto_slug && isset( self::$crypto_map[ $crypto_slug ] ) ) ||
            ( $forex_slug  && isset( self::$forex_map[ $forex_slug ] ) )
        ) {
            // Return a minimal passthrough so WP doesn't 404-template-redirect
            // Our template_redirect already called exit, so this path is never actually loaded.
            return $template;
        }
        return $template;
    }

    public static function add_rewrite_rules() {
        add_rewrite_rule( '^crypto/([a-z0-9-]+)/?$',    'index.php?fxlm_crypto_slug=$matches[1]',    'top' );
        add_rewrite_rule( '^forex/([a-z0-9-]+)/?$',     'index.php?fxlm_forex_slug=$matches[1]',     'top' );
        add_rewrite_rule( '^analysis/([a-z0-9-]+)/?$',  'index.php?fxlm_analysis_slug=$matches[1]',  'top' );
        // Redirect old /crypto-category/X/ URLs to flat page slugs
        add_rewrite_rule( '^crypto-category/([a-z0-9-]+)/?$', 'index.php?fxlm_cat_slug=$matches[1]', 'top' );
        add_rewrite_tag( '%fxlm_cat_slug%', '([a-z0-9-]+)' );
        // Nested pages created by class-pages.php with slugs like "exchanges/spot"
        // get stored in WP as flat slugs "exchanges-spot" — map the pretty URL to the flat page.
        add_rewrite_rule( '^exchanges/(spot|derivatives|dex-spot|dex-derivatives)/?$', 'index.php?pagename=exchanges-$matches[1]', 'top' );
        add_rewrite_rule( '^dexscan/(signals|trending|new|gainers|meme|top-traders)/?$', 'index.php?pagename=dexscan-$matches[1]', 'top' );
    }

    public static function add_query_vars( $vars ) {
        $vars[] = 'fxlm_analysis_slug';
        $vars[] = 'fxlm_crypto_slug';
        $vars[] = 'fxlm_forex_slug';
        return $vars;
    }

    public static function maybe_render() {
        $crypto_slug = get_query_var( 'fxlm_crypto_slug' );
        $forex_slug  = get_query_var( 'fxlm_forex_slug' );

        if ( $crypto_slug ) {
            if ( isset( self::$crypto_map[ $crypto_slug ] ) ) {
                self::render_crypto_page( $crypto_slug, self::$crypto_map[ $crypto_slug ] );
                exit;
            }
            // Try dynamic lookup for any coin
            $asset = self::build_dynamic_asset( $crypto_slug );
            if ( $asset ) {
                self::render_crypto_page( $crypto_slug, $asset );
                exit;
            }
            global $wp_query;
            $wp_query->set_404();
            status_header( 404 );
            return;
        }

        if ( $forex_slug ) {
            if ( isset( self::$forex_map[ $forex_slug ] ) ) {
                self::render_forex_page( $forex_slug, self::$forex_map[ $forex_slug ] );
                exit;
            } else {
                global $wp_query;
                $wp_query->set_404();
                status_header( 404 );
                return;
            }
        }
    }

    // ── SEO META for asset pages ──────────────────────────────
    public static function output_asset_meta() {
        $crypto_slug = get_query_var( 'fxlm_crypto_slug' );
        $forex_slug  = get_query_var( 'fxlm_forex_slug' );
        $site        = get_option( 'bt_site_name', 'BlockTicker' );
        $og_image    = get_option( 'bt_og_image', '' );

        if ( $crypto_slug && isset( self::$crypto_map[ $crypto_slug ] ) ) {
            $a      = self::$crypto_map[ $crypto_slug ];
            $data   = self::get_coin_data( $a['symbol'] );
            $price  = $data ? '$' . number_format( $data['current_price'], 2 ) : 'Live price';
            $title  = $a['name'] . ' (' . $a['symbol'] . ') Price Today — ' . $price . ' | ' . $site;
            $desc   = 'Live ' . $a['name'] . ' (' . $a['symbol'] . ') price, market cap, 24h change, chart and latest news. Updated in real time on ' . $site . '.';
            $url    = home_url( '/crypto/' . $crypto_slug . '/' );

            // JSON-LD FinancialProduct schema
            $schema = array(
                '@context'    => 'https://schema.org',
                '@type'       => 'FinancialProduct',
                'name'        => $a['name'] . ' (' . $a['symbol'] . ')',
                'description' => $desc,
                'url'         => $url,
                'category'    => 'Cryptocurrency',
                'breadcrumb'  => array(
                    '@type'           => 'BreadcrumbList',
                    'itemListElement' => array(
                        array( '@type' => 'ListItem', 'position' => 1, 'name' => 'Home',           'item' => home_url('/') ),
                        array( '@type' => 'ListItem', 'position' => 2, 'name' => 'Crypto Markets', 'item' => home_url('/crypto-markets/') ),
                        array( '@type' => 'ListItem', 'position' => 3, 'name' => $a['name'],       'item' => $url ),
                    ),
                ),
            );
            self::output_meta_tags( $title, $desc, $url, $og_image, $schema );
        }

        if ( $forex_slug && isset( self::$forex_map[ $forex_slug ] ) ) {
            $a     = self::$forex_map[ $forex_slug ];
            $data  = self::get_forex_data( $a['pair'] );
            $rate  = $data ? number_format( $data['rate'], 4 ) : 'Live rate';
            $title = $a['pair'] . ' — ' . $a['name'] . ' Live Rate ' . $rate . ' | ' . $site;
            $desc  = 'Live ' . $a['pair'] . ' exchange rate, interactive chart, 24h change and latest forex news. Real-time data on ' . $site . '.';
            $url   = home_url( '/forex/' . $forex_slug . '/' );

            $schema = array(
                '@context'    => 'https://schema.org',
                '@type'       => 'FinancialProduct',
                'name'        => $a['pair'] . ' — ' . $a['name'],
                'description' => $desc,
                'url'         => $url,
                'category'    => 'Foreign Exchange',
                'breadcrumb'  => array(
                    '@type'           => 'BreadcrumbList',
                    'itemListElement' => array(
                        array( '@type' => 'ListItem', 'position' => 1, 'name' => 'Home',         'item' => home_url('/') ),
                        array( '@type' => 'ListItem', 'position' => 2, 'name' => 'Forex Charts', 'item' => home_url('/forex-charts/') ),
                        array( '@type' => 'ListItem', 'position' => 3, 'name' => $a['pair'],     'item' => $url ),
                    ),
                ),
            );
            self::output_meta_tags( $title, $desc, $url, $og_image, $schema );
        }
    }

    private static function output_meta_tags( $title, $desc, $url, $og_image, $schema ) {
        echo '<meta name="description" content="' . esc_attr( $desc ) . '" />' . "\n";
        echo '<meta property="og:title" content="' . esc_attr( $title ) . '" />' . "\n";
        echo '<meta property="og:description" content="' . esc_attr( $desc ) . '" />' . "\n";
        echo '<meta property="og:url" content="' . esc_url( $url ) . '" />' . "\n";
        echo '<meta property="og:type" content="website" />' . "\n";
        if ( $og_image ) echo '<meta property="og:image" content="' . esc_url( $og_image ) . '" />' . "\n";
        echo '<meta name="twitter:card" content="summary_large_image" />' . "\n";
        echo '<meta name="twitter:title" content="' . esc_attr( $title ) . '" />' . "\n";
        echo '<meta name="twitter:description" content="' . esc_attr( $desc ) . '" />' . "\n";
        if ( $og_image ) echo '<meta name="twitter:image" content="' . esc_url( $og_image ) . '" />' . "\n";
        echo '<script type="application/ld+json">' . wp_json_encode( $schema ) . '</script>' . "\n";
    }

    public static function filter_title( $parts ) {
        $crypto_slug = get_query_var( 'fxlm_crypto_slug' );
        $forex_slug  = get_query_var( 'fxlm_forex_slug' );
        $site        = get_option( 'bt_site_name', 'BlockTicker' );

        if ( $crypto_slug && isset( self::$crypto_map[ $crypto_slug ] ) ) {
            $a = self::$crypto_map[ $crypto_slug ];
            $data  = self::get_coin_data( $a['symbol'] );
            $price = $data ? '$' . number_format( $data['current_price'], 2 ) : '';
            $parts['title'] = $a['name'] . ' (' . $a['symbol'] . ') Price Today' . ( $price ? ' — ' . $price : '' );
            $parts['site']  = $site;
        }
        if ( $forex_slug && isset( self::$forex_map[ $forex_slug ] ) ) {
            $a = self::$forex_map[ $forex_slug ];
            $data = self::get_forex_data( $a['pair'] );
            $rate = $data ? number_format( $data['rate'], 4 ) : '';
            $parts['title'] = $a['pair'] . ' Live Rate' . ( $rate ? ' — ' . $rate : '' );
            $parts['site']  = $site;
        }
        return $parts;
    }

    // ── DATA HELPERS ──────────────────────────────────────────
    private static function get_coin_data( $symbol ) {
        $crypto = BT_Widgets::get_json_option( 'fxlm_crypto_data' );
        if ( empty( $crypto['coins'] ) ) return null;
        foreach ( $crypto['coins'] as $coin ) {
            if ( strtoupper( $coin['symbol'] ) === strtoupper( $symbol ) ) return $coin;
        }
        return null;
    }

    private static function get_forex_data( $pair ) {
        $forex = BT_Widgets::get_json_option( 'fxlm_forex_data' );
        if ( empty( $forex['rates'] ) ) return null;
        return $forex['rates'][ $pair ] ?? null;
    }

    private static function get_related_news( $keywords, $limit = 6 ) {
        $all  = BT_Widgets::get_json_option( 'fxlm_news_items' );
        $hits = array();
        foreach ( $all as $item ) {
            foreach ( $keywords as $kw ) {
                if ( stripos( $item['title'], $kw ) !== false || stripos( $item['category'], $kw ) !== false ) {
                    $hits[] = $item;
                    break;
                }
            }
            if ( count( $hits ) >= $limit ) break;
        }
        return $hits;
    }

    private static function get_related_posts( $keywords, $limit = 4 ) {
        $args = array(
            'numberposts' => $limit * 3,
            'post_status' => 'publish',
            'orderby'     => 'date',
            'order'       => 'DESC',
        );
        $posts = get_posts( $args );
        $hits  = array();
        foreach ( $posts as $p ) {
            foreach ( $keywords as $kw ) {
                if ( stripos( $p->post_title, $kw ) !== false ) {
                    $hits[] = $p;
                    break;
                }
            }
            if ( count( $hits ) >= $limit ) break;
        }
        return $hits;
    }

    // ── RENDER HELPERS ────────────────────────────────────────
    private static function page_open( $title ) {
        // Enqueue plugin assets before wp_head fires
        $ver = defined('BT_VERSION') ? BT_VERSION : '17';
        $url = defined('BT_URL') ? BT_URL : '';
        if ( $url ) {
            wp_enqueue_style( 'fxlm-frontend', $url . 'assets/css/frontend.css', array(), $ver );
            wp_enqueue_style( 'fxlm-patch',    $url . 'assets/css/patch-animations.css', array(), $ver );
            if ( defined( 'BT_DIR' ) && file_exists( BT_DIR . 'assets/css/revamp-v44.css' ) ) {
                wp_enqueue_style( 'fxlm-revamp-v44', $url . 'assets/css/revamp-v44.css', array('fxlm-patch'), $ver );
            }
            // v119.28.29 — also load BT chrome CSS + typography on asset pages so
            // footer link colors, fonts, and dashboard styling match the rest of
            // the site. Without these, asset pages inherited theme-style green
            // links and the wrong typography stack.
            if ( defined( 'BT_DIR' ) && file_exists( BT_DIR . 'assets/css/landing-revamp.css' ) ) {
                wp_enqueue_style(
                    'btlp-gfonts',
                    'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@400;500;600;700&display=swap',
                    array(),
                    null
                );
                wp_enqueue_style( 'bt-landing-revamp', $url . 'assets/css/landing-revamp.css', array( 'btlp-gfonts' ), $ver );
            }
            if ( defined( 'BT_DIR' ) && file_exists( BT_DIR . 'assets/css/typography.css' ) ) {
                wp_enqueue_style( 'bt-typography', $url . 'assets/css/typography.css', array( 'bt-landing-revamp' ), $ver );
            }
            // v119.28.31 — Unified design tokens & component library (loaded LAST).
            if ( defined( 'BT_DIR' ) && file_exists( BT_DIR . 'assets/css/bt-tokens.css' ) ) {
                wp_enqueue_style( 'bt-tokens', $url . 'assets/css/bt-tokens.css', array( 'bt-typography' ), $ver );
            }
            wp_enqueue_script( 'jquery' );
            wp_enqueue_script( 'fxlm-frontend', $url . 'assets/js/frontend.js', array('jquery'), $ver, true );
            wp_enqueue_script( 'fxlm-patch',    $url . 'assets/js/patch-animations.js', array('jquery'), $ver, true );
            if ( defined( 'BT_DIR' ) && file_exists( BT_DIR . 'assets/js/revamp-v44.js' ) ) {
                wp_enqueue_script( 'fxlm-revamp-v44', $url . 'assets/js/revamp-v44.js', array(), $ver, true );
            }
            wp_localize_script( 'fxlm-frontend', 'fxlm_data', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'rest_url' => rest_url('blockticker/v1/prices'),
                'nonce'    => wp_create_nonce('fxlm_prices'),
                'refresh'  => 60,
            ) );
        }

        // v119.28.29 — add body class so CSS can target asset pages
        // (which run through the theme path, not site-takeover, and so
        // miss many of the `body.bt-site-takeover` defensive rules).
        add_filter( 'body_class', function( $classes ){
            $classes[] = 'fxlm-asset-page';
            $classes[] = 'bt-asset-page';
            return $classes;
        });

        // Use the theme's header (GeneratePress or Astra) so the navbar renders correctly.
        // get_header() outputs everything up to and including the opening <body> tag.
        get_header();
        
?>
<div id="fxlm-asset-wrap" style="max-width:1440px;margin:0 auto;padding:0 32px 60px">
<?php
    }

    private static function page_close() {
        ?>
</div><!-- #fxlm-asset-wrap -->
<?php
        // Use the theme's footer so widgets/scripts/navbar close correctly
        get_footer();
    }

    private static function render_breadcrumb( $items ) {
        echo '<div class="fxlm-breadcrumbs" style="padding:16px 0 8px">';
        $total = count( $items );
        foreach ( $items as $i => $item ) {
            if ( $i < $total - 1 ) {
                echo '<a href="' . esc_url( $item[1] ) . '">' . esc_html( $item[0] ) . '</a><span class="fxlm-bc-sep">›</span>';
            } else {
                echo '<span>' . esc_html( $item[0] ) . '</span>';
            }
        }
        echo '</div>';
    }

    /**
     * Render a TradingView chart inline on a coin/forex page.
     *
     * Thin adapter — actual rendering lives in BT_Utils::render_tv_chart().
     * Different call signature from the shortcode version (positional args) is
     * preserved for backward compatibility with the ~8 call sites in this class.
     *
     * @param string $symbol TradingView symbol.
     * @param int    $height Chart height in px.
     * @return void Echoes directly.
     */
    private static function tv_chart( $symbol, $height = 450 ) {
        echo BT_Utils::render_tv_chart( array(
            'symbol'          => $symbol,
            'height'          => $height,
            'interval'        => 'D',
            'hide_top'        => false,
            'hide_side'       => false,
            'show_fullscreen' => false,  // coin pages already have context; no overlay needed
        ) );
    }

    private static function render_news_items( $items ) {
        if ( empty( $items ) ) {
            echo '<p style="color:var(--bt-text-3);font-size:13px">No related news found yet. Check back shortly.</p>';
            return;
        }
        echo '<div style="display:flex;flex-direction:column;gap:10px">';
        foreach ( $items as $item ) {
            $ts    = isset( $item['timestamp'] ) && $item['timestamp'] > 0 ? human_time_diff( $item['timestamp'] ) . ' ago' : '';
            $img   = isset( $item['image'] ) && $item['image'] ? $item['image'] : '';
            $src   = esc_html( $item['source'] ?? '' );
            $title = esc_html( $item['title'] ?? '' );
            $url   = esc_url( $item['link'] ?? '#' );
            echo '<a href="' . $url . '" target="_blank" rel="noopener nofollow" class="bcp-news-card-link">';
            echo '<div class="bcp-news-card">';
            if ( $img ) {
                echo '<img src="' . esc_url($img) . '" class="bcp-news-card-img" loading="lazy" alt="">';
            }
            echo '<div class="bcp-news-card-body">';
            if ( $src ) echo '<span class="bcp-news-card-src">' . $src . '</span>';
            echo '<p class="bcp-news-card-title">' . $title . '</p>';
            if ( $ts ) echo '<span class="bcp-news-card-time">' . esc_html($ts) . '</span>';
            echo '</div></div></a>';
        }
        echo '</div>';
    }

    private static function render_post_cards( $posts ) {
        if ( empty( $posts ) ) return;
        echo '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:16px">';
        foreach ( $posts as $p ) {
            $img     = get_the_post_thumbnail_url( $p->ID, 'medium' );
            $excerpt = wp_trim_words( strip_tags( $p->post_content ), 18, '…' );
            $date    = get_the_date( 'M j, Y', $p->ID );
            $cats    = get_the_category( $p->ID );
            $cat     = ! empty( $cats ) ? $cats[0]->name : 'Analysis';
            $url     = esc_url( get_permalink( $p->ID ) );
            echo '<a href="' . $url . '" class="bcp-post-card-link">';
            echo '<article class="bcp-post-card">';
            if ( $img ) {
                echo '<div class="bcp-post-card-img" style="background-image:url(' . esc_url($img) . ')"></div>';
            } else {
                echo '<div class="bcp-post-card-img bcp-post-card-placeholder">📊</div>';
            }
            echo '<div class="bcp-post-card-body">';
            echo '<span class="bcp-post-card-cat">' . esc_html($cat) . '</span>';
            echo '<p class="bcp-post-card-title">' . esc_html( $p->post_title ) . '</p>';
            echo '<p class="bcp-post-card-excerpt">' . esc_html($excerpt) . '</p>';
            echo '<span class="bcp-post-card-date">' . $date . '</span>';
            echo '</div></article></a>';
        }
        echo '</div>';
    }

    // ── CRYPTO ASSET PAGE ─────────────────────────────────────
    public static function render_crypto_page( $slug, $asset ) {
        $coin = self::get_coin_data( $asset['symbol'] ?? '' );
        if ( ! $coin && !empty($asset['dynamic']) ) {
            // Dynamic asset: search by ID in stored data
            $stored = BT_Widgets::get_json_option('fxlm_crypto_data');
            foreach ( ($stored['coins'] ?? []) as $c ) {
                if ( $c['id'] === $slug || sanitize_title($c['name']) === $slug ) {
                    $coin = $c; break;
                }
            }
        }
        if ( ! $coin ) {
            BT_Widgets::fetch_crypto_prices();
            $coin = self::get_coin_data( $asset['symbol'] ?? '' );
        }

        // Fetch CoinGecko detail: description, links, tickers, sentiment, developer data
        $detail_key = 'bt_coin_detail_' . $slug;
        $detail     = get_transient( $detail_key );
        if ( ! $detail ) {
            $api_key = get_option( 'bt_cg_api_key', '' );
            $args    = array( 'timeout' => 20 );
            if ( $api_key ) $args['headers'] = array( 'x-cg-demo-api-key' => $api_key );
            $cg_id   = $coin['id'] ?? $slug;
            $url     = "https://api.coingecko.com/api/v3/coins/{$cg_id}?localization=false&tickers=true&market_data=true&community_data=true&developer_data=true";
            $data    = BT_Utils::http_get_json( $url, $args );
            if ( ! is_wp_error( $data ) && is_array( $data ) ) {
                $detail = $data;
                set_transient( $detail_key, $detail, 15 * MINUTE_IN_SECONDS );
            }
        }

        // ── Core market data ──────────────────────────────────
        $price      = $coin ? floatval($coin['current_price'] ?? 0) : null;
        $chg24      = $coin ? floatval($coin['price_change_percentage_24h'] ?? 0) : 0;
        $chg7       = $coin ? floatval($coin['price_change_percentage_7d_in_currency'] ?? 0) : 0;
        $chg30      = $coin ? floatval($coin['price_change_percentage_30d_in_currency'] ?? 0) : 0;
        $mcap       = $coin ? floatval($coin['market_cap'] ?? 0) : 0;
        $vol        = $coin ? floatval($coin['total_volume'] ?? 0) : 0;
        $rank       = $coin ? ($coin['market_cap_rank'] ?? '—') : '—';
        $img_url    = $coin ? ($coin['image'] ?? '') : ($asset['image'] ?? '');
        $circ_sup   = $coin ? floatval($coin['circulating_supply'] ?? 0) : 0;
        $total_sup  = $coin ? floatval($coin['total_supply'] ?? 0) : 0;
        $max_sup    = $coin ? floatval($coin['max_supply'] ?? 0) : 0;
        $ath        = $coin ? floatval($coin['ath'] ?? 0) : 0;
        $ath_chg    = $coin ? floatval($coin['ath_change_percentage'] ?? 0) : 0;
        $ath_date   = $coin ? ($coin['ath_date'] ?? '') : '';
        $atl        = $coin ? floatval($coin['atl'] ?? 0) : 0;
        $atl_chg    = $coin ? floatval($coin['atl_change_percentage'] ?? 0) : 0;
        $cls        = $chg24 >= 0 ? 'up' : 'down';
        $color      = $asset['color'] ?? 'var(--bt-accent)';
        $sym        = $asset['symbol'] ?? strtoupper($coin['symbol'] ?? '');
        $name       = $asset['name'] ?? ($coin['name'] ?? $slug);

        // ── Detail data ───────────────────────────────────────
        $desc_full  = strip_tags($detail['description']['en'] ?? '');
        $desc_short = wp_trim_words( $desc_full, 80, '…' );
        $website    = $detail['links']['homepage'][0] ?? '';
        $whitepaper = $detail['links']['whitepaper'] ?? '';
        $reddit     = $detail['links']['subreddit_url'] ?? '';
        $github     = $detail['links']['repos_url']['github'][0] ?? '';
        $twitter    = $detail['links']['twitter_screen_name'] ?? '';
        $telegram   = $detail['links']['telegram_channel_identifier'] ?? '';
        $tickers    = array_slice($detail['tickers'] ?? [], 0, 10);
        $sentiment_up   = $detail['sentiment_votes_up_percentage'] ?? null;
        $sentiment_down = $detail['sentiment_votes_down_percentage'] ?? null;
        $genesis_date   = $detail['genesis_date'] ?? '';
        $hashing_algo   = $detail['hashing_algorithm'] ?? '';
        $block_time     = $detail['block_time_in_minutes'] ?? '';
        $categories     = $detail['categories'] ?? [];
        $market_cap_fdv = $detail['market_data']['fully_diluted_valuation']['usd'] ?? 0;
        $market_cap_rank_fdv = $detail['market_cap_fdv_ratio'] ?? 0;
        // Developer data
        $dev_forks   = $detail['developer_data']['forks'] ?? 0;
        $dev_stars   = $detail['developer_data']['stars'] ?? 0;
        $dev_commits = $detail['developer_data']['commit_count_4_weeks'] ?? 0;
        // Community
        $reddit_subs = $detail['community_data']['reddit_subscribers'] ?? 0;
        $twitter_fol = $detail['community_data']['twitter_followers'] ?? 0;

        $keywords   = [$name, $sym, strtolower($name)];
        $news       = self::get_related_news( $keywords, 6 );
        $posts      = self::get_related_posts( $keywords, 4 );
        $site_name  = get_option( 'bt_site_name', 'BlockTicker' );

        // v62: Detect cold-data state. True when we have no price or no coin record at all —
        // this is the "huge empty landing section" case reported since v43. When cold, the
        // page renders skeleton placeholders + a friendly warming-up banner instead of
        // empty gaps where metrics should be.
        $is_cold = ( ! $coin || ! $price || $price <= 0 );

        self::page_open( $name );
        ?>
        <style>
        /* ── Coin Page Styles ── */
        .bcp { max-width:1400px; margin:0 auto; padding:0 20px 80px; }
        .bcp-hero { display:grid; grid-template-columns:1fr 340px; gap:28px; align-items:start; margin:16px 0 24px; }
        @media(max-width:1000px){ .bcp-hero { grid-template-columns:1fr; } }
        /* Fix empty sidebar space: sidebar stretches and stays sticky */
        .bcp-sidebar { display:flex; flex-direction:column; gap:14px; position:sticky; top:80px; }
        .bcp-name-row { display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:8px; }
        .bcp-logo { border-radius:50%; flex-shrink:0; }
        .bcp-h1 { font-size:clamp(22px,3.5vw,34px)!important; font-weight:900!important; color:var(--bt-text)!important; margin:0!important; }
        .bcp-sym { font-size:.55em; color:var(--bt-text-3); font-weight:500; }
        .bcp-rank { background:rgba(255,255,255,.07); border:1px solid rgba(255,255,255,.1); color:var(--bt-text-2); font-size:11px; font-weight:700; padding:3px 9px; border-radius:0; }
        .bcp-cats { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:12px; }
        .bcp-cat { background:rgba(0,255,102,.08); border:1px solid rgba(0,255,102,.2); color:var(--bt-accent); font-size:10px; font-weight:700; padding:2px 8px; border-radius:0; }
        .bcp-price { font-size:clamp(28px,4.5vw,46px)!important; font-weight:900!important; color:var(--bt-text)!important; line-height:1.1!important; margin:0 0 8px!important; letter-spacing:-1px; }
        .bcp-chg-row { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:16px; }
        .bcp-chg-pill { font-size:13px!important; font-weight:700!important; padding:4px 10px; border-radius:0; }
        .bcp-chg-pill.up { background:rgba(0,255,102,.12); color:var(--bt-accent); }
        .bcp-chg-pill.down { background:rgba(255,59,48,.12); color:var(--bt-danger); }
        .bcp-metrics { display:grid; grid-template-columns:repeat(2,1fr); gap:10px; margin-bottom:16px; }
        .bcp-metric { background:var(--bt-bg-elev); border:1px solid rgba(255,255,255,.07); border-radius:0; padding:12px 14px; }
        .bcp-metric-lbl { font-size:10px!important; color:var(--bt-text-3)!important; text-transform:uppercase; letter-spacing:.6px; margin-bottom:4px; }
        .bcp-metric-val { font-size:15px!important; font-weight:700!important; color:var(--bt-text)!important; }
        .bcp-metric-sub { font-size:10px!important; color:var(--bt-text-4)!important; margin-top:2px; }
        .bcp-sidebar { display:flex; flex-direction:column; gap:14px; }
        .bcp-card { background:var(--bt-bg-elev); border:1px solid rgba(255,255,255,.07); border-radius:0; padding:18px; }
        .bcp-card h3 { font-size:11px!important; font-weight:700!important; color:var(--bt-text-3)!important; text-transform:uppercase; letter-spacing:.6px; margin:0 0 12px!important; }
        .bcp-stat-row { display:flex; justify-content:space-between; align-items:center; padding:7px 0; border-bottom:1px solid rgba(255,255,255,.04); font-size:12px!important; }
        .bcp-stat-row:last-child { border:none; }
        .bcp-stat-lbl { color:var(--bt-text-3)!important; }
        .bcp-stat-val { color:var(--bt-text)!important; font-weight:600!important; text-align:right; }
        .bcp-supply-bar-wrap { background:rgba(255,255,255,.06); border-radius:0; height:6px; margin:6px 0; overflow:hidden; }
        .bcp-supply-bar { height:6px; border-radius:0; }
        .bcp-links { display:flex; flex-wrap:wrap; gap:6px; }
        .bcp-link { display:inline-flex; align-items:center; gap:5px; background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.08); color:var(--bt-text-2)!important; font-size:11px!important; padding:4px 10px; border-radius:7px; text-decoration:none!important; transition:all .15s; }
        .bcp-link:hover { border-color:rgba(0,255,102,.3); color:var(--bt-accent)!important; }
        /* Single-column body — no ghost right column */
        .bcp-body { display:block; margin-top:28px; }
        .bcp-body-right { display:none!important; }
        /* Accordion FAQ */
        .bcp-faq { margin-bottom:28px; }
        .bcp-faq-title { font-size:20px!important; font-weight:800!important; color:var(--bt-text)!important; margin:0 0 16px!important; }
        .bcp-acc-item { border-bottom:1px solid rgba(255,255,255,.07); }
        .bcp-acc-btn { width:100%; text-align:left; background:none; border:none; padding:14px 0; display:flex; justify-content:space-between; align-items:center; cursor:pointer; font-size:14px!important; font-weight:600!important; color:#c8cdd8!important; transition:color .15s; }
        .bcp-acc-btn:hover { color:var(--bt-accent)!important; }
        .bcp-acc-icon { font-size:18px; color:var(--bt-text-3); transition:transform .2s; flex-shrink:0; }
        .bcp-acc-icon.open { transform:rotate(45deg); }
        .bcp-acc-body { display:none; padding:0 0 16px; font-size:13px!important; color:var(--bt-text-3)!important; line-height:1.7!important; }
        .bcp-acc-body.open { display:block; }
        /* Markets table */
        .bcp-mkt-table { width:100%; border-collapse:collapse; font-size:12px!important; }
        .bcp-mkt-table th { padding:9px 12px; color:var(--bt-text-3)!important; font-weight:700!important; font-size:10px!important; text-transform:uppercase; letter-spacing:.5px; border-bottom:1px solid rgba(255,255,255,.07); text-align:left; white-space:nowrap; }
        .bcp-mkt-table td { padding:10px 12px; color:#c8cdd8!important; border-bottom:1px solid rgba(255,255,255,.04); }
        .bcp-mkt-table tr:hover td { background:rgba(255,255,255,.02); }
        /* Sentiment */
        .bcp-sent-bars { display:flex; height:10px; border-radius:0; overflow:hidden; margin:10px 0; }
        .bcp-sent-bull { background:var(--bt-accent); }
        .bcp-sent-bear { background:var(--bt-danger); flex:1; }
        /* Related news */
        .bcp-news-item { display:flex; gap:12px; padding:12px 0; border-bottom:1px solid rgba(255,255,255,.05); }
        .bcp-news-item:last-child { border:none; }
        .bcp-news-img { width:60px; height:60px; object-fit:cover; border-radius:0; flex-shrink:0; }
        .bcp-news-title { font-size:13px!important; font-weight:600!important; color:#c8cdd8!important; line-height:1.4!important; margin:0 0 4px!important; }
        .bcp-news-title a { color:#c8cdd8!important; text-decoration:none!important; }
        .bcp-news-title a:hover { color:var(--bt-accent)!important; }
        .bcp-news-meta { font-size:11px!important; color:var(--bt-text-3)!important; }
        /* Tokenomics chart */
        .bcp-token-chart { display:flex; flex-direction:column; gap:8px; }
        .bcp-token-row { display:grid; grid-template-columns:120px 1fr 60px; gap:8px; align-items:center; font-size:12px!important; }
        .bcp-token-lbl { color:var(--bt-text-2)!important; }
        .bcp-token-bar { background:rgba(255,255,255,.06); border-radius:3px; height:6px; }
        .bcp-token-fill { height:6px; border-radius:3px; }
        .bcp-token-pct { text-align:right; color:var(--bt-text)!important; font-weight:700!important; }
        /* News cards (new design) */
        .bcp-news-card-link { text-decoration:none!important; display:block; margin-bottom:8px; }
        .bcp-news-card { display:flex; gap:12px; align-items:flex-start; background:rgba(255,255,255,.03); border:1px solid rgba(255,255,255,.07); border-radius:0; padding:11px 13px; transition:border-color .18s,transform .18s; }
        .bcp-news-card:hover { border-color:rgba(0,255,102,.25); transform:translateX(3px); }
        .bcp-news-card-img { width:68px; height:52px; object-fit:cover; border-radius:7px; flex-shrink:0; }
        .bcp-news-card-body { flex:1; min-width:0; }
        .bcp-news-card-src { display:inline-block; font-size:10px!important; font-weight:700!important; text-transform:uppercase; letter-spacing:.5px; color:var(--bt-accent)!important; margin-bottom:3px; }
        .bcp-news-card-title { font-size:13px!important; font-weight:600!important; color:#c8d6e5!important; line-height:1.45!important; margin:0 0 5px!important; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
        .bcp-news-card-time { font-size:11px!important; color:var(--bt-text-4)!important; }
        /* Post cards (new design) */
        .bcp-post-card-link { text-decoration:none!important; display:block; }
        .bcp-post-card { background:var(--bt-bg-elev); border:1px solid rgba(255,255,255,.07); border-radius:0; overflow:hidden; transition:transform .2s,border-color .2s,box-shadow .2s; display:flex; flex-direction:column; }
        .bcp-post-card:hover { transform:translateY(-4px); border-color:rgba(0,255,102,.2); box-shadow:0 12px 32px rgba(0,0,0,.4); }
        .bcp-post-card-img { height:130px; background-size:cover; background-position:center; flex-shrink:0; }
        .bcp-post-card-placeholder { background:linear-gradient(135deg,#0d1a2e,#091520); display:flex; align-items:center; justify-content:center; font-size:30px; }
        .bcp-post-card-body { padding:13px 15px; flex:1; display:flex; flex-direction:column; gap:5px; }
        .bcp-post-card-cat { font-size:10px!important; font-weight:700!important; text-transform:uppercase; letter-spacing:.5px; color:var(--bt-accent)!important; }
        .bcp-post-card-title { font-size:13px!important; font-weight:700!important; color:var(--bt-text)!important; line-height:1.4!important; margin:0!important; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
        .bcp-post-card-excerpt { font-size:12px!important; color:var(--bt-text-3)!important; line-height:1.5!important; margin:0!important; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
        .bcp-post-card-date { font-size:11px!important; color:var(--bt-text-4)!important; margin-top:auto; padding-top:6px; }
        /* Vote buttons */
        .bcp-vote-btn { flex:1; padding:11px; border-radius:0; font-size:14px; font-weight:700; cursor:pointer; transition:all .2s; font-family:inherit; }
        .bcp-vote-bull { border:1px solid rgba(0,255,102,.25); background:rgba(0,255,102,.06); color:var(--bt-accent); }
        .bcp-vote-bull:hover { background:rgba(0,255,102,.18); }
        .bcp-vote-bear { border:1px solid rgba(255,59,48,.25); background:rgba(255,59,48,.06); color:var(--bt-danger); }
        .bcp-vote-bear:hover { background:rgba(255,59,48,.18); }

        /* ═══ v62: Warming-up banner + skeletons (kills the "huge empty landing") ═══ */
        .bcp-logo-fallback {
            width: 40px; height: 40px; border-radius: 50%;
            display: inline-flex; align-items: center; justify-content: center;
            font-family: 'SF Mono', Menlo, Consolas, monospace;
            font-size: 14px; font-weight: 800; letter-spacing: .5px;
        }
        .bcp-warming {
            display: flex; align-items: center; gap: 16px;
            background: linear-gradient(135deg, rgba(0,255,102,.06), rgba(0,255,102,.04));
            border: 1px solid rgba(0,255,102,.22);
            border-radius: 14px;
            padding: 16px 20px;
            margin: 14px 0 22px;
            animation: bcp-warming-in .4s cubic-bezier(.2,.8,.2,1);
        }
        @keyframes bcp-warming-in {
            from { opacity: 0; transform: translateY(6px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .bcp-warming-icon {
            width: 40px; height: 40px; flex-shrink: 0;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            position: relative;
        }
        .bcp-warming-pulse {
            width: 10px; height: 10px;
            border-radius: 50%;
            position: relative;
        }
        .bcp-warming-pulse::before {
            content: ''; position: absolute; inset: -3px;
            border-radius: 50%;
            background: inherit;
            opacity: .5;
            animation: bcp-pulse 1.6s cubic-bezier(.4,0,.2,1) infinite;
        }
        @keyframes bcp-pulse {
            0%   { transform: scale(.8); opacity: .55; }
            70%  { transform: scale(2.4); opacity: 0; }
            100% { transform: scale(2.4); opacity: 0; }
        }
        .bcp-warming-body { flex: 1; min-width: 0; }
        .bcp-warming-title { font-size: 14px; font-weight: 700; color: var(--bt-text); margin-bottom: 4px; }
        .bcp-warming-sub { font-size: 12.5px; color: var(--bt-text-2); line-height: 1.55; }
        .bcp-warming-countdown { display: inline-block; margin-left: 6px; color: var(--bt-accent); font-family: 'SF Mono', Menlo, monospace; }
        .bcp-warming-btn {
            background: rgba(0,255,102,.12);
            border: 1px solid rgba(0,255,102,.3);
            color: var(--bt-accent);
            padding: 8px 14px;
            border-radius: 0;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            flex-shrink: 0;
            transition: background .15s;
            font-family: inherit;
        }
        .bcp-warming-btn:hover { background: rgba(0,255,102,.22); }

        /* Shimmer base animation (applied to all skeleton elements) */
        @keyframes bcp-shimmer {
            0%   { background-position: -400px 0; }
            100% { background-position: 400px 0; }
        }
        .bcp-skeleton-price {
            width: 240px; height: 52px;
            border-radius: 10px;
            background: linear-gradient(90deg, rgba(255,255,255,.04) 8%, rgba(255,255,255,.08) 18%, rgba(255,255,255,.04) 33%);
            background-size: 800px 52px;
            animation: bcp-shimmer 1.4s linear infinite;
            margin: 8px 0 12px;
        }
        .bcp-skeleton-pill {
            width: 96px; height: 26px;
            display: inline-block;
            border-radius: 99px;
            background: linear-gradient(90deg, rgba(255,255,255,.04) 8%, rgba(255,255,255,.08) 18%, rgba(255,255,255,.04) 33%);
            background-size: 600px 26px;
            animation: bcp-shimmer 1.4s linear infinite;
            vertical-align: middle;
        }
        .bcp-skel-line {
            display: inline-block;
            width: 72%;
            height: 18px;
            border-radius: 5px;
            background: linear-gradient(90deg, rgba(255,255,255,.05) 8%, rgba(255,255,255,.1) 18%, rgba(255,255,255,.05) 33%);
            background-size: 500px 18px;
            animation: bcp-shimmer 1.4s linear infinite;
            color: transparent;
            vertical-align: middle;
        }
        .bcp-metrics-cold .bcp-metric { position: relative; }
        .bcp-metrics-cold .bcp-metric::before {
            content: '';
            position: absolute;
            top: 0; right: 10px;
            width: 6px; height: 6px;
            border-radius: 50%;
            background: var(--bt-accent-warm);
            opacity: .7;
            animation: bcp-pulse 1.6s cubic-bezier(.4,0,.2,1) infinite;
        }
        @media (max-width: 640px) {
            .bcp-warming { flex-direction: column; align-items: flex-start; padding: 14px 16px; }
            .bcp-warming-btn { align-self: stretch; }
        }
        @media (prefers-reduced-motion: reduce) {
            .bcp-skeleton-price, .bcp-skeleton-pill, .bcp-skel-line { animation: none; background: rgba(255,255,255,.06); }
            .bcp-warming-pulse::before, .bcp-metrics-cold .bcp-metric::before { animation: none; opacity: .4; }
        }
        </style>

        <?php if ( $is_cold ) : ?>
        <script>
        // v62: Auto-refresh countdown for warming-up state
        (function(){
          var el = document.querySelector('.bcp-warming-countdown');
          if (!el) return;
          var s = parseInt(el.getAttribute('data-s'), 10) || 12;
          var strong = el.querySelector('strong');
          var iv = setInterval(function(){
            s--;
            if (s <= 0) { clearInterval(iv); location.reload(); return; }
            if (strong) strong.textContent = s + 's';
          }, 1000);
        })();
        </script>
        <?php endif; ?>

        <div class="bcp">

        <?php self::render_breadcrumb([
            ['Home', home_url('/')],
            ['Crypto Markets', home_url('/crypto-markets/')],
            [$name . ' (' . $sym . ')', ''],
        ]); ?>

        <!-- ═══ HERO ═══════════════════════════════════════════ -->
        <div class="bcp-hero">
            <!-- Left: Name + Price + Metrics -->
            <div>
                <div class="bcp-name-row">
                    <?php if ($img_url): ?>
                    <img src="<?php echo esc_url($img_url); ?>" width="40" height="40" class="bcp-logo" loading="lazy" alt="<?php echo esc_attr($name); ?>">
                    <?php else: ?>
                    <div class="bcp-logo bcp-logo-fallback" style="background:<?php echo esc_attr($color); ?>22;color:<?php echo esc_attr($color); ?>;border:1px solid <?php echo esc_attr($color); ?>44"><?php echo esc_html(mb_substr($sym ?: $name, 0, 2)); ?></div>
                    <?php endif; ?>
                    <h1 class="bcp-h1"><?php echo esc_html($name); ?> <span class="bcp-sym"><?php echo esc_html($sym); ?></span></h1>
                    <span class="bcp-rank">#<?php echo esc_html($rank); ?></span>
                    <?php
                    // v119.9: contextual price-alert button with modal
                    echo do_shortcode( sprintf(
                        '[bt_set_alert_btn coin_id="%s" symbol="%s" name="%s" price="%s" type="crypto"]',
                        esc_attr( $coin['id'] ?? $slug ),
                        esc_attr( $sym ),
                        esc_attr( $name ),
                        esc_attr( $price ?: '' )
                    ) );
                    ?>
                </div>

                <?php if (!empty($categories)): ?>
                <div class="bcp-cats">
                    <?php foreach (array_slice($categories, 0, 4) as $cat): ?>
                    <span class="bcp-cat"><?php echo esc_html($cat); ?></span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php if ($is_cold): ?>
                <!-- v62: Warming-up banner — replaces the empty gap when price data hasn't loaded -->
                <div class="bcp-warming" role="status" aria-live="polite">
                    <div class="bcp-warming-icon" style="background:<?php echo esc_attr($color); ?>22;color:<?php echo esc_attr($color); ?>">
                        <span class="bcp-warming-pulse" style="background:<?php echo esc_attr($color); ?>"></span>
                    </div>
                    <div class="bcp-warming-body">
                        <div class="bcp-warming-title">Live price data warming up</div>
                        <div class="bcp-warming-sub">We're fetching <?php echo esc_html($name); ?> market data from CoinGecko. The chart below is live — price, market cap and supply figures will appear within seconds. <span class="bcp-warming-countdown" data-s="12">Auto-refresh in <strong>12s</strong></span></div>
                    </div>
                    <button class="bcp-warming-btn" onclick="location.reload()" type="button">↻ Refresh now</button>
                </div>
                <?php endif; ?>

                <?php if ($price): ?>
                <div class="bcp-price">$<?php echo number_format($price, $price >= 1 ? 2 : 8); ?></div>
                <div class="bcp-chg-row">
                    <span class="bcp-chg-pill <?php echo $cls; ?>"><?php echo ($chg24>=0?'▲ ':' ▼ ').number_format(abs($chg24),2); ?>% 24h</span>
                    <?php if ($chg7): ?><span class="bcp-chg-pill <?php echo $chg7>=0?'up':'down'; ?>"><?php echo ($chg7>=0?'▲ ':'▼ ').number_format(abs($chg7),2); ?>% 7d</span><?php endif; ?>
                    <?php if ($chg30): ?><span class="bcp-chg-pill <?php echo $chg30>=0?'up':'down'; ?>"><?php echo ($chg30>=0?'▲ ':'▼ ').number_format(abs($chg30),2); ?>% 30d</span><?php endif; ?>
                </div>
                <?php else: ?>
                <!-- v62: Skeleton price row (shimmers while cold) -->
                <div class="bcp-price bcp-skeleton-price" aria-hidden="true"></div>
                <div class="bcp-chg-row">
                    <span class="bcp-chg-pill bcp-skeleton-pill"></span>
                    <span class="bcp-chg-pill bcp-skeleton-pill"></span>
                    <span class="bcp-chg-pill bcp-skeleton-pill"></span>
                </div>
                <?php endif; ?>

                <!-- Key Metrics Grid — v62: always renders all 6 slots, placeholders for missing values -->
                <div class="bcp-metrics<?php echo $is_cold ? ' bcp-metrics-cold' : ''; ?>">
                    <div class="bcp-metric">
                        <div class="bcp-metric-lbl">Market Cap</div>
                        <div class="bcp-metric-val"><?php echo $mcap > 0 ? '$' . self::fmt_large($mcap) : '<span class="bcp-skel-line">—</span>'; ?></div>
                        <div class="bcp-metric-sub">Rank #<?php echo esc_html($rank); ?></div>
                    </div>
                    <div class="bcp-metric">
                        <div class="bcp-metric-lbl">24h Volume</div>
                        <div class="bcp-metric-val"><?php echo $vol > 0 ? '$' . self::fmt_large($vol) : '<span class="bcp-skel-line">—</span>'; ?></div>
                        <div class="bcp-metric-sub">Vol/MCap: <?php echo $mcap>0 ? number_format($vol/$mcap*100,2).'%' : '—'; ?></div>
                    </div>
                    <div class="bcp-metric">
                        <div class="bcp-metric-lbl" data-i18n="asset.stat.fdv"><?php _ebt("asset.stat.fdv"); ?></div>
                        <div class="bcp-metric-val"><?php echo $market_cap_fdv > 0 ? '$' . self::fmt_large($market_cap_fdv) : '<span class="bcp-skel-line">—</span>'; ?></div>
                        <div class="bcp-metric-sub"><?php echo $market_cap_fdv > 0 && $mcap > 0 ? 'MCap/FDV: '.number_format($mcap/$market_cap_fdv*100,1).'%' : '&nbsp;'; ?></div>
                    </div>
                    <div class="bcp-metric">
                        <div class="bcp-metric-lbl" data-i18n="asset.stat.circ_sup"><?php _ebt("asset.stat.circ_sup"); ?></div>
                        <div class="bcp-metric-val"><?php echo $circ_sup > 0 ? number_format($circ_sup/1e6,2).'M '.esc_html($sym) : '<span class="bcp-skel-line">—</span>'; ?></div>
                        <?php if ($circ_sup > 0 && $max_sup > 0): $pct=min(100,round($circ_sup/$max_sup*100,1)); ?>
                        <div class="bcp-supply-bar-wrap"><div class="bcp-supply-bar" style="width:<?php echo $pct; ?>%;background:<?php echo esc_attr($color); ?>"></div></div>
                        <div class="bcp-metric-sub"><?php echo $pct; ?>% of max <?php echo number_format($max_sup/1e6,2); ?>M</div>
                        <?php else: ?>
                        <div class="bcp-metric-sub"><?php echo $max_sup > 0 ? 'Max '.number_format($max_sup/1e6,2).'M' : ($circ_sup > 0 ? 'No hard cap' : '&nbsp;'); ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="bcp-metric">
                        <div class="bcp-metric-lbl" data-i18n="asset.stat.ath"><?php _ebt("asset.stat.ath"); ?></div>
                        <div class="bcp-metric-val"><?php echo $ath > 0 ? '$'.number_format($ath, $ath>=1?2:6) : '<span class="bcp-skel-line">—</span>'; ?></div>
                        <?php if ($ath > 0): ?>
                        <div class="bcp-metric-sub" style="color:var(--bt-danger)"><?php echo number_format($ath_chg,1); ?>% from ATH<?php echo $ath_date ? ' · '.date('M Y',strtotime($ath_date)) : ''; ?></div>
                        <?php else: ?>
                        <div class="bcp-metric-sub">&nbsp;</div>
                        <?php endif; ?>
                    </div>
                    <div class="bcp-metric">
                        <div class="bcp-metric-lbl" data-i18n="asset.stat.atl"><?php _ebt("asset.stat.atl"); ?></div>
                        <div class="bcp-metric-val"><?php echo $atl > 0 ? '$'.number_format($atl, $atl>=1?2:8) : '<span class="bcp-skel-line">—</span>'; ?></div>
                        <?php if ($atl > 0): ?>
                        <div class="bcp-metric-sub" style="color:var(--bt-accent)">+<?php echo number_format(abs($atl_chg),0); ?>% from ATL</div>
                        <?php else: ?>
                        <div class="bcp-metric-sub">&nbsp;</div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- External Links -->
                <?php if ($website || $whitepaper || $reddit || $github || $twitter || $telegram): ?>
                <div class="bcp-links">
                    <?php if ($website): ?><a href="<?php echo esc_url($website); ?>" target="_blank" rel="noopener nofollow" class="bcp-link">🌐 Website</a><?php endif; ?>
                    <?php if ($whitepaper): ?><a href="<?php echo esc_url($whitepaper); ?>" target="_blank" rel="noopener nofollow" class="bcp-link">📄 Whitepaper</a><?php endif; ?>
                    <?php if ($reddit): ?><a href="<?php echo esc_url($reddit); ?>" target="_blank" rel="noopener nofollow" class="bcp-link">💬 Reddit</a><?php endif; ?>
                    <?php if ($github): ?><a href="<?php echo esc_url($github); ?>" target="_blank" rel="noopener nofollow" class="bcp-link">💻 GitHub</a><?php endif; ?>
                    <?php if ($twitter): ?><a href="https://twitter.com/<?php echo esc_attr($twitter); ?>" target="_blank" rel="noopener nofollow" class="bcp-link">𝕏 Twitter</a><?php endif; ?>
                    <?php if ($telegram): ?><a href="https://t.me/<?php echo esc_attr($telegram); ?>" target="_blank" rel="noopener nofollow" class="bcp-link">✈ Telegram</a><?php endif; ?>
                </div>
                <?php endif; ?>
                <!-- Chart inside left column (v46.1: fills empty gutter below metrics when sidebar is tall) -->
                <div class="bcp-chart-inline" style="margin-top:22px">
                    <div class="fxlm-section-header" style="margin:0 0 12px!important;padding:0 0 10px!important"><h2 style="font-size:17px!important"><?php echo esc_html( sprintf( __bt( "asset.price_chart" ), $name ) ); ?></h2><span class="fxlm-section-badge">TradingView · Live</span></div>
                    <?php self::tv_chart( $asset['tv'] ?? 'BINANCE:'.esc_attr($sym).'USDT', 420 ); ?>
                </div>
            </div>

            <!-- Right: Sidebar Cards -->
            <div class="bcp-sidebar">
                <!-- Quick Converter -->
                <div class="bcp-card">
                    <?php
                    // BT Score widget
                    if ( class_exists('BT_EEAT') ) {
                        echo '<h3 style="font-size:11px!important;font-weight:700!important;color:var(--bt-text-3)!important;text-transform:uppercase;letter-spacing:.6px;margin:0 0 12px!important">' . esc_html( __bt( 'asset.bt_score' ) ) . '</h3>';
                        echo '<div style="display:flex;align-items:center;gap:16px">';
                        echo do_shortcode('[bt_score coin="' . esc_attr($slug) . '" show_label="true"]');
                        echo '<div style="font-size:12px;color:var(--bt-text-3);line-height:1.6">Proprietary score combining momentum, volume, sentiment &amp; market cap rank. Updated every 5 min.</div>';
                        echo '</div>';
                    }
                    ?>
                </div>
                <div class="bcp-card">
                    <h3><?php echo esc_html( sprintf( __bt( "asset.converter" ), $sym ) ); ?></h3>
                    <div style="display:flex;gap:8px;align-items:center;margin-bottom:8px">
                        <input type="number" id="bcp-conv-in" value="1" min="0" step="any" oninput="bcpCalc()" style="flex:1;background:var(--bt-bg);border:1px solid rgba(255,255,255,.1);color:var(--bt-text);border-radius:0;padding:8px 10px;font-size:14px;outline:none">
                        <span style="color:var(--bt-text-3);font-size:12px;font-weight:700;flex-shrink:0"><?php echo esc_html($sym); ?></span>
                    </div>
                    <div id="bcp-conv-out" style="background:rgba(0,255,102,.06);border:1px solid rgba(0,255,102,.15);border-radius:0;padding:10px 12px;font-size:16px;font-weight:700;color:var(--bt-accent);text-align:center">
                        $<?php echo $price ? number_format($price,2) : '—'; ?>
                    </div>
                    <div style="font-size:10px;color:var(--bt-text-4);margin-top:6px;text-align:center">1 <?php echo esc_html($sym); ?> = $<?php echo $price ? number_format($price,$price>=1?2:8) : '—'; ?></div>
                </div>

                <!-- Community Sentiment -->
                <?php if ($sentiment_up !== null): ?>
                <div class="bcp-card">
                    <h3><?php echo esc_html( __bt( 'asset.community_sentiment' ) ); ?></h3>
                    <div style="display:flex;justify-content:space-between;font-size:13px;font-weight:700">
                        <span style="color:var(--bt-accent)">🚀 <?php echo number_format($sentiment_up,1); ?>% <?php echo esc_html( __bt( "asset.bullish" ) ); ?></span>
                        <span style="color:var(--bt-danger)">🐻 <?php echo number_format($sentiment_down,1); ?>% <?php echo esc_html( __bt( "asset.bearish" ) ); ?></span>
                    </div>
                    <div class="bcp-sent-bars">
                        <div class="bcp-sent-bull" style="width:<?php echo $sentiment_up; ?>%"></div>
                        <div class="bcp-sent-bear"></div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Price Statistics -->
                <div class="bcp-card">
                    <h3><?php echo esc_html( sprintf( __bt( "asset.price_stats" ), $name ) ); ?></h3>
                    <?php
                    $stats = [
                        [__bt('asset.stat.price'), '$'.number_format($price??0,$price>=1?2:8)],
                        [__bt('asset.stat.chg_24h'), ($chg24>=0?'+':'').number_format($chg24,2).'%'],
                        [__bt('asset.stat.chg_7d'), ($chg7>=0?'+':'').number_format($chg7,2).'%'],
                        [__bt('asset.stat.chg_30d'), ($chg30>=0?'+':'').number_format($chg30,2).'%'],
                        [__bt('asset.stat.mcap_rank'), '#'.esc_html($rank)],
                        [__bt('asset.stat.mcap'), '$'.self::fmt_large($mcap)],
                        [__bt('asset.stat.vol_24h'), '$'.self::fmt_large($vol)],
                        [__bt('asset.stat.ath'), $ath>0?'$'.number_format($ath,$ath>=1?2:6):'—'],
                        [__bt('asset.stat.atl'), $atl>0?'$'.number_format($atl,$atl>=1?2:8):'—'],
                        [__bt('asset.stat.circ_sup'), $circ_sup>0?number_format($circ_sup/1e6,2).'M '.esc_html($sym):'—'],
                        [__bt('asset.stat.total_sup'), $total_sup>0?number_format($total_sup/1e6,2).'M '.esc_html($sym):'—'],
                        [__bt('asset.stat.max_sup'), $max_sup>0?number_format($max_sup/1e6,2).'M '.esc_html($sym):'∞'],
                    ];
                    if ($genesis_date) $stats[] = [__bt('asset.stat.launch_date'), date('M j, Y', strtotime($genesis_date))];
                    if ($hashing_algo) $stats[] = [__bt('asset.stat.algo'), $hashing_algo];
                    if ($block_time)   $stats[] = [__bt('asset.stat.block_time'), $block_time.' min'];
                    foreach ($stats as $s):
                    ?>
                    <div class="bcp-stat-row">
                        <span class="bcp-stat-lbl"><?php echo esc_html($s[0]); ?></span>
                        <span class="bcp-stat-val"><?php echo esc_html($s[1]); ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Developer Activity -->
                <?php if ($dev_stars || $dev_commits): ?>
                <div class="bcp-card">
                    <h3><?php echo esc_html( __bt( 'asset.developer_activity' ) ); ?></h3>
                    <?php if ($dev_stars): ?>
                    <div class="bcp-stat-row"><span class="bcp-stat-lbl">GitHub Stars</span><span class="bcp-stat-val">⭐ <?php echo number_format($dev_stars); ?></span></div>
                    <?php endif; ?>
                    <?php if ($dev_forks): ?>
                    <div class="bcp-stat-row"><span class="bcp-stat-lbl">Forks</span><span class="bcp-stat-val"><?php echo number_format($dev_forks); ?></span></div>
                    <?php endif; ?>
                    <?php if ($dev_commits): ?>
                    <div class="bcp-stat-row"><span class="bcp-stat-lbl">Commits (4 weeks)</span><span class="bcp-stat-val"><?php echo number_format($dev_commits); ?></span></div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Social Stats -->
                <?php if ($reddit_subs || $twitter_fol): ?>
                <div class="bcp-card">
                    <h3><?php echo esc_html( __bt( 'asset.community' ) ); ?></h3>
                    <?php if ($reddit_subs): ?>
                    <div class="bcp-stat-row"><span class="bcp-stat-lbl">Reddit Subscribers</span><span class="bcp-stat-val"><?php echo number_format($reddit_subs); ?></span></div>
                    <?php endif; ?>
                    <?php if ($twitter_fol): ?>
                    <div class="bcp-stat-row"><span class="bcp-stat-lbl">Twitter Followers</span><span class="bcp-stat-val"><?php echo number_format($twitter_fol); ?></span></div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Vote Widget -->
                <?php self::render_vote_widget( 'crypto_' . $slug ); ?>
            </div>
        </div>

        <!-- ═══ MAIN BODY ════════════════════════════════════════ -->
        <div class="bcp-body">
            <!-- LEFT COLUMN -->
            <div>

                <!-- About Section with Accordion FAQs -->
                <?php if ($desc_full): ?>
                <div class="bcp-faq" id="bcp-about">
                    <h2 class="bcp-faq-title"><?php echo esc_html( sprintf( __bt( "asset.about_x" ), $name ) ); ?></h2>
                    <p style="color:var(--bt-text-2);font-size:14px;line-height:1.8;margin-bottom:20px"><?php echo esc_html($desc_short); ?></p>

                    <?php
                    // Build FAQ from description paragraphs + standard questions
                    $faq_items = [];

                    // Parse full description into sections if it has multiple paragraphs
                    $paras = array_filter(array_map('trim', preg_split('/\n{2,}/', $desc_full)));
                    if (count($paras) > 1) {
                        $faq_items[] = ['What Is ' . $name . ' (' . $sym . ')?', implode("\n\n", array_slice($paras, 0, 3))];
                        if (count($paras) > 3) {
                            $faq_items[] = ['History & Background', implode("\n\n", array_slice($paras, 3, 2))];
                        }
                    }

                    // Standard technical FAQ
                    if ($hashing_algo || $block_time || $genesis_date) {
                        $tech = '';
                        if ($hashing_algo) $tech .= $name . ' uses the ' . $hashing_algo . ' hashing algorithm. ';
                        if ($block_time)   $tech .= 'Average block time is ' . $block_time . ' minutes. ';
                        if ($genesis_date) $tech .= 'The genesis date was ' . date('F j, Y', strtotime($genesis_date)) . '. ';
                        $faq_items[] = ['How Is the ' . $name . ' Network Secured?', $tech];
                    }

                    if ($circ_sup > 0) {
                        $supply_txt = 'The current circulating supply of ' . $name . ' is ' . number_format($circ_sup/1e6, 2) . 'M ' . $sym . '.';
                        if ($max_sup > 0) $supply_txt .= ' The maximum supply is capped at ' . number_format($max_sup/1e6, 2) . 'M ' . $sym . ', of which ' . number_format($circ_sup/$max_sup*100, 1) . '% is currently in circulation.';
                        if ($total_sup > 0 && $total_sup != $max_sup) $supply_txt .= ' Total supply (including locked/staked) is ' . number_format($total_sup/1e6, 2) . 'M ' . $sym . '.';
                        $faq_items[] = ['How Much ' . $name . ' Is in Circulation?', $supply_txt];
                    }

                    if (!empty($categories)) {
                        $cat_txt = $name . ' is categorized as: ' . implode(', ', array_slice($categories, 0, 6)) . '. ';
                        $cat_txt .= 'It can be traded on major exchanges including ' . (!empty($tickers) ? implode(', ', array_slice(array_column(array_column($tickers, 'market'), 'name'), 0, 5)) : 'Binance, Coinbase, and others') . '.';
                        $faq_items[] = ['Where Can You Buy ' . $name . ' (' . $sym . ')?', $cat_txt];
                    }

                    if ($dev_stars > 0 || $dev_commits > 0) {
                        $dev_txt = $name . '\'s GitHub repository has ' . number_format($dev_stars) . ' stars and ' . number_format($dev_forks) . ' forks. ';
                        if ($dev_commits) $dev_txt .= 'Developers have made ' . number_format($dev_commits) . ' commits in the last 4 weeks, indicating active development.';
                        $faq_items[] = ['How Is ' . $name . '\'s Development Activity?', $dev_txt];
                    }

                    foreach ($faq_items as $fi => $faq): ?>
                    <div class="bcp-acc-item">
                        <button class="bcp-acc-btn" onclick="bcpToggleFaq(this)">
                            <?php echo esc_html($faq[0]); ?>
                            <span class="bcp-acc-icon">+</span>
                        </button>
                        <div class="bcp-acc-body">
                            <p style="margin:0;white-space:pre-line"><?php echo esc_html($faq[1]); ?></p>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <!-- Tokenomics / Supply Breakdown -->
                <?php if ($circ_sup > 0 && ($max_sup > 0 || $total_sup > 0)): ?>
                <div class="bcp-card" style="margin-bottom:24px">
                    <h3><?php echo esc_html( __bt( 'asset.supply' ) ); ?></h3>
                    <div class="bcp-token-chart">
                        <?php
                        $max_ref = $max_sup ?: $total_sup ?: $circ_sup;
                        $segments = [];
                        if ($circ_sup > 0)               $segments[] = ['Circulating', $circ_sup, 'var(--bt-accent)'];
                        if ($total_sup > $circ_sup)      $segments[] = ['Non-circulating', $total_sup - $circ_sup, 'var(--bt-accent)'];
                        if ($max_sup > $total_sup)       $segments[] = ['Not yet issued', $max_sup - $total_sup, 'var(--bt-text-4)'];
                        foreach ($segments as $seg):
                            $pct = $max_ref > 0 ? min(100, round($seg[1]/$max_ref*100, 1)) : 0;
                        ?>
                        <div class="bcp-token-row">
                            <span class="bcp-token-lbl"><?php echo esc_html($seg[0]); ?></span>
                            <div class="bcp-token-bar"><div class="bcp-token-fill" style="width:<?php echo $pct; ?>%;background:<?php echo esc_attr($seg[2]); ?>"></div></div>
                            <span class="bcp-token-pct"><?php echo $pct; ?>%</span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div style="margin-top:10px;font-size:11px;color:var(--bt-text-4)">
                        Circulating: <?php echo number_format($circ_sup/1e6,2); ?>M
                        <?php if ($total_sup > 0): ?> · Total: <?php echo number_format($total_sup/1e6,2); ?>M<?php endif; ?>
                        <?php if ($max_sup > 0): ?> · Max: <?php echo number_format($max_sup/1e6,2); ?>M<?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Markets / Where to Buy -->
                <?php if (!empty($tickers)): ?>
                <div class="bcp-card" style="margin-bottom:24px">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
                        <h3 style="margin:0!important"><?php echo esc_html($name); ?> Markets</h3>
                        <span style="font-size:11px;color:var(--bt-text-4)">Where to buy <?php echo esc_html($sym); ?></span>
                    </div>
                    <div style="overflow-x:auto">
                        <table class="bcp-mkt-table">
                            <thead><tr>
                                <th>#</th><th><?php echo esc_html( __bt( 'col.exchange' ) ); ?></th><th><?php echo esc_html( __bt( 'col.pair' ) ); ?></th><th><?php echo esc_html( __bt( 'col.price' ) ); ?></th>
                                <th>+2% Depth</th><th>-2% Depth</th><th>Volume 24h</th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($tickers as $ti => $t):
                                $tp  = floatval($t['converted_last']['usd'] ?? $t['last'] ?? 0);
                                $tv  = floatval($t['converted_volume']['usd'] ?? $t['volume'] ?? 0);
                                $dp  = floatval($t['cost_to_move_up_usd'] ?? 0);
                                $dn  = floatval($t['cost_to_move_down_usd'] ?? 0);
                            ?>
                            <tr>
                                <td style="color:var(--bt-text-3)"><?php echo $ti+1; ?></td>
                                <td>
                                    <?php if (!empty($t['market']['logo'])): ?>
                                    <img src="<?php echo esc_url($t['market']['logo']); ?>" width="16" height="16" style="border-radius:50%;margin-right:5px;vertical-align:middle" loading="lazy" alt="">
                                    <?php endif; ?>
                                    <strong style="color:var(--bt-text)"><?php echo esc_html($t['market']['name'] ?? ''); ?></strong>
                                </td>
                                <td style="font-family:monospace;color:var(--bt-text-2)"><?php echo esc_html(($t['base']??'').'/'.$t['target']??''); ?></td>
                                <td style="color:var(--bt-text);font-weight:700">$<?php echo $tp>=1?number_format($tp,2):number_format($tp,6); ?></td>
                                <td style="color:var(--bt-accent)"><?php echo $dp>0?'$'.self::fmt_large($dp):'—'; ?></td>
                                <td style="color:var(--bt-danger)"><?php echo $dn>0?'$'.self::fmt_large($dn):'—'; ?></td>
                                <td>$<?php echo self::fmt_large($tv); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Related News -->
                <?php if (!empty($news)): ?>
                <div class="bcp-card" style="margin-bottom:24px">
                    <h3><?php echo esc_html( sprintf( __bt( "asset.latest_x_news" ), $name ) ); ?></h3>
                    <?php self::render_news_items($news); ?>
                </div>
                <?php endif; ?>

                <!-- Related Posts -->
                <?php if (!empty($posts)): ?>
                <div style="margin-bottom:24px">
                    <div class="fxlm-section-header"><h2>✍️ <?php echo esc_html( __bt( "asset.analysis_guides" ) ); ?></h2></div>
                    <?php self::render_post_cards($posts); ?>
                </div>
                <?php endif; ?>

                <!-- Related Pages -->
                <div class="bcp-card">
                    <h3><?php echo esc_html( __bt( 'asset.related_pages' ) ); ?></h3>
                    <div style="display:flex;flex-direction:column;gap:8px">
                        <a href="<?php echo home_url('/crypto-markets/'); ?>" style="color:var(--bt-accent);font-size:13px;text-decoration:none">📊 All Cryptocurrency Prices →</a>
                        <a href="<?php echo home_url('/trading-signals/'); ?>" style="color:var(--bt-accent);font-size:13px;text-decoration:none">📡 Trading Signals →</a>
                        <a href="<?php echo home_url('/exchanges/'); ?>" style="color:var(--bt-accent);font-size:13px;text-decoration:none">🏛 Top Exchanges →</a>
                        <a href="<?php echo home_url('/gainers-losers/'); ?>" style="color:var(--bt-accent);font-size:13px;text-decoration:none">🚀 Gainers &amp; Losers →</a>
                        <a href="<?php echo home_url('/market-analysis/'); ?>" style="color:var(--bt-accent);font-size:13px;text-decoration:none">🤖 AI Market Analysis →</a>
                        <a href="<?php echo home_url('/tools/#converter'); ?>" style="color:var(--bt-accent);font-size:13px;text-decoration:none">🔄 Crypto Converter →</a>
                        <?php if (!empty($categories)): ?>
                        <?php $cat_slug = sanitize_title($categories[0]); ?>
                        <a href="<?php echo home_url('/crypto-category/'.$cat_slug.'/'); ?>" style="color:var(--bt-accent);font-size:13px;text-decoration:none">🔗 <?php echo esc_html($categories[0]); ?> Tokens →</a>
                        <?php endif; ?>
                    </div>
                </div>

            </div>

            <!-- RIGHT COLUMN (empty on mobile, shown on desktop as overflow from hero sidebar) -->
            <div style="display:none" class="bcp-body-right">
                <!-- This column intentionally empty — sidebar content is in hero grid -->
            </div>
        </div>

        </div><!-- .bcp -->

        <script>
        function bcpToggleFaq(btn){
            var icon = btn.querySelector('.bcp-acc-icon');
            var body = btn.nextElementSibling;
            var isOpen = body.classList.contains('open');
            // Close all
            document.querySelectorAll('.bcp-acc-body').forEach(function(b){ b.classList.remove('open'); });
            document.querySelectorAll('.bcp-acc-icon').forEach(function(i){ i.classList.remove('open'); i.textContent='+'; });
            if(!isOpen){ body.classList.add('open'); icon.classList.add('open'); icon.textContent='×'; }
        }
        (function(){
            var price = <?php echo floatval($price ?? 0); ?>;
            window.bcpCalc = function(){
                var amt = parseFloat(document.getElementById('bcp-conv-in').value)||0;
                var res = amt * price;
                document.getElementById('bcp-conv-out').textContent = '$'+(res>=0.01?res.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}):res.toFixed(8));
            };
            bcpCalc();
        })();
        </script>
        <?php
        self::page_close();
    }

    // ── FOREX PAIR PAGE ───────────────────────────────────────
    public static function render_forex_page( $slug, $asset ) {
        $data  = self::get_forex_data( $asset['pair'] );
        $rate  = $data ? floatval($data['rate']) : null;
        $chg   = $data ? floatval($data['change']) : null;
        $cls   = $chg !== null ? ($chg >= 0 ? 'up' : 'down') : '';
        $arrow = $chg !== null ? ($chg >= 0 ? '▲' : '▼') : '';
        $base  = $asset['base'];
        $quote = $asset['quote'];
        $pair  = $asset['pair'];
        $name  = $asset['name'];
        $tv    = $asset['tv'];

        // Derived pricing
        $bid   = $rate ? round($rate - ($rate * 0.0002), 4) : null;
        $ask   = $rate ? round($rate + ($rate * 0.0002), 4) : null;

        $keywords = [$pair, str_replace('/', '', $pair), $base, $quote, strtolower($base), strtolower($quote)];
        $news  = self::get_related_news($keywords, 8);
        $posts = self::get_related_posts($keywords, 4);

        // Central bank / economic metadata per pair
        $pair_meta = [
            'EUR/USD' => [
                'nickname'   => 'The Fibre',
                'central_banks' => ['European Central Bank (ECB)', 'US Federal Reserve (Fed)'],
                'factors'    => ['ECB interest rate decisions', 'US CPI/NFP data', 'Eurozone GDP', 'Fed FOMC meetings'],
                'desc'       => 'EUR/USD is the world\'s most traded currency pair, representing the exchange rate between the Euro and the US Dollar. It accounts for roughly 24% of global daily forex volume. The pair is heavily influenced by monetary policy divergence between the European Central Bank and the US Federal Reserve.',
                'history'    => 'The Euro was introduced on January 1, 1999 at $1.17. It reached its all-time high of ~$1.60 in July 2008 and its all-time low of ~$0.82 in October 2000.',
                'session'    => 'Most active during London and New York sessions (08:00–17:00 EST)',
                'pip'        => '0.0001',
                'spread'     => '0.1–0.6 pips (typical)',
                'category'   => 'Major',
            ],
            'GBP/USD' => [
                'nickname'   => 'Cable',
                'central_banks' => ['Bank of England (BoE)', 'US Federal Reserve (Fed)'],
                'factors'    => ['BoE rate decisions', 'UK CPI', 'US NFP', 'Brexit aftereffects', 'Trade balance'],
                'desc'       => 'GBP/USD, nicknamed "Cable" after the transatlantic telegraph cable used to transmit rates in the 19th century, is the third most traded forex pair globally. It measures the British Pound against the US Dollar.',
                'history'    => 'GBP/USD reached historic highs above $2.00 pre-2008. Post-Brexit referendum in 2016 caused a 10% flash crash. It hit a record low of ~$1.03 in September 2022.',
                'session'    => 'Most active during London session (08:00–12:00 EST)',
                'pip'        => '0.0001',
                'spread'     => '0.3–1.2 pips (typical)',
                'category'   => 'Major',
            ],
            'USD/JPY' => [
                'nickname'   => 'Gopher / The Ninja',
                'central_banks' => ['US Federal Reserve (Fed)', 'Bank of Japan (BoJ)'],
                'factors'    => ['BoJ yield curve control', 'US-Japan rate differential', 'Risk sentiment', 'Safe haven flows'],
                'desc'       => 'USD/JPY is the second most traded currency pair globally. The Japanese Yen is considered a safe-haven currency, meaning it tends to strengthen during global risk-off events. The pair is highly sensitive to US-Japan interest rate differentials.',
                'history'    => 'USD/JPY hit a historic low of ~76 in 2011 after the Tōhoku earthquake. The Bank of Japan\'s ultra-loose monetary policy drove the pair above 150 in 2022-2024.',
                'session'    => 'Most active during Tokyo and New York sessions',
                'pip'        => '0.01',
                'spread'     => '0.2–0.8 pips (typical)',
                'category'   => 'Major',
            ],
            'AUD/USD' => [
                'nickname'   => 'Aussie',
                'central_banks' => ['Reserve Bank of Australia (RBA)', 'US Federal Reserve (Fed)'],
                'factors'    => ['Iron ore / commodity prices', 'RBA rate decisions', 'China economic data', 'Risk appetite'],
                'desc'       => 'AUD/USD, known as the "Aussie", is a commodity-linked currency pair. Australia\'s economy is highly dependent on commodity exports to China, making the pair sensitive to Chinese economic data and commodity prices.',
                'history'    => 'The Aussie achieved parity with the USD ($1.00) in 2010 for the first time. It hit a 17-year low near $0.57 during the COVID-19 pandemic in 2020.',
                'session'    => 'Most active during Sydney and Tokyo sessions',
                'pip'        => '0.0001',
                'spread'     => '0.3–1.0 pips (typical)',
                'category'   => 'Major',
            ],
            'USD/CHF' => [
                'nickname'   => 'Swissie',
                'central_banks' => ['US Federal Reserve (Fed)', 'Swiss National Bank (SNB)'],
                'factors'    => ['SNB interventions', 'Safe haven demand', 'US-Swiss rate differential', 'Geopolitical risk'],
                'desc'       => 'USD/CHF, the "Swissie", pairs the US Dollar with the Swiss Franc. The CHF is considered one of the world\'s premier safe-haven currencies due to Switzerland\'s political neutrality, strong banking sector and low inflation.',
                'history'    => 'The SNB\'s removal of the EUR/CHF floor in January 2015 caused the CHF to surge 30% in minutes, one of the most extreme forex moves in history.',
                'session'    => 'Most active during London and New York sessions',
                'pip'        => '0.0001',
                'spread'     => '0.4–1.5 pips (typical)',
                'category'   => 'Major',
            ],
            'USD/CAD' => [
                'nickname'   => 'Loonie',
                'central_banks' => ['US Federal Reserve (Fed)', 'Bank of Canada (BoC)'],
                'factors'    => ['Crude oil prices', 'BoC rate decisions', 'Canadian trade balance', 'US-Canada trade'],
                'desc'       => 'USD/CAD is known as the "Loonie" after the Canadian loon on the $1 coin. Canada is the world\'s 4th largest oil exporter, making this pair highly sensitive to crude oil price movements.',
                'history'    => 'USD/CAD reached parity several times between 2007-2015. Oil price collapses pushed it above 1.46 in 2016 and 2020.',
                'session'    => 'Most active during New York session (09:00–17:00 EST)',
                'pip'        => '0.0001',
                'spread'     => '0.3–0.9 pips (typical)',
                'category'   => 'Major',
            ],
            'NZD/USD' => [
                'nickname'   => 'Kiwi',
                'central_banks' => ['Reserve Bank of New Zealand (RBNZ)', 'US Federal Reserve (Fed)'],
                'factors'    => ['RBNZ rate decisions', 'Dairy prices', 'China demand', 'Risk sentiment'],
                'desc'       => 'NZD/USD, the "Kiwi", is a commodity-linked pair. New Zealand\'s economy relies heavily on agricultural exports, particularly dairy, making the pair sensitive to global dairy prices and Chinese demand.',
                'history'    => 'The Kiwi reached an all-time high near $0.88 in 2011. It fell below $0.55 during the COVID-19 pandemic in 2020.',
                'session'    => 'Most active during Sydney and Tokyo sessions',
                'pip'        => '0.0001',
                'spread'     => '0.4–1.2 pips (typical)',
                'category'   => 'Major',
            ],
            'EUR/GBP' => [
                'nickname'   => 'Chunnel',
                'central_banks' => ['European Central Bank (ECB)', 'Bank of England (BoE)'],
                'factors'    => ['Brexit trade relations', 'UK vs Eurozone growth', 'ECB and BoE divergence'],
                'desc'       => 'EUR/GBP measures the value of the Euro against the British Pound. Post-Brexit, this pair is highly sensitive to UK-EU trade negotiations and economic divergence between the two regions.',
                'history'    => 'EUR/GBP reached parity near 0.98 in August 2022 during the UK energy crisis. It typically trades in a range of 0.84-0.93.',
                'session'    => 'Most active during London session',
                'pip'        => '0.0001',
                'spread'     => '0.5–1.5 pips (typical)',
                'category'   => 'Major cross',
            ],
        ];

        $meta  = $pair_meta[$pair] ?? [
            'nickname'      => '',
            'central_banks' => [],
            'factors'       => [],
            'desc'          => 'Live exchange rate for ' . $name . '. Real-time data updated every 5 minutes.',
            'history'       => '',
            'session'       => 'Most active during major trading sessions',
            'pip'           => '0.0001',
            'spread'        => '0.5–2.0 pips (typical)',
            'category'      => 'Forex Pair',
        ];

        self::page_open($pair . ' — ' . $name);
        ?>
        <style>
        .bfp { max-width:1400px; margin:0 auto; padding:0 20px 80px; }
        .bfp-hero { display:grid; grid-template-columns:1fr 320px; gap:28px; align-items:start; margin:16px 0 24px; }
        @media(max-width:1000px){ .bfp-hero { grid-template-columns:1fr; } }
        .bfp-card { background:var(--bt-bg-elev); border:1px solid rgba(255,255,255,.07); border-radius:0; padding:18px; margin-bottom:16px; }
        .bfp-card h3 { font-size:11px!important; font-weight:700!important; color:var(--bt-text-3)!important; text-transform:uppercase; letter-spacing:.6px; margin:0 0 12px!important; }
        .bfp-stat-row { display:flex; justify-content:space-between; align-items:center; padding:7px 0; border-bottom:1px solid rgba(255,255,255,.04); font-size:12px!important; }
        .bfp-stat-row:last-child { border:none; }
        .bfp-stat-lbl { color:var(--bt-text-3)!important; }
        .bfp-stat-val { color:var(--bt-text)!important; font-weight:600!important; }
        .bfp-factors { display:flex; flex-wrap:wrap; gap:6px; margin-top:4px; }
        .bfp-factor-tag { background:rgba(0,255,102,.08); border:1px solid rgba(0,255,102,.2); color:var(--bt-accent)!important; font-size:11px!important; padding:3px 9px; border-radius:0; }
        .bfp-acc-item { border-bottom:1px solid rgba(255,255,255,.07); }
        .bfp-acc-btn { width:100%; text-align:left; background:none; border:none; padding:14px 0; display:flex; justify-content:space-between; align-items:center; cursor:pointer; font-size:14px!important; font-weight:600!important; color:#c8cdd8!important; }
        .bfp-acc-btn:hover { color:var(--bt-accent)!important; }
        .bfp-acc-icon { font-size:18px; color:var(--bt-text-3); transition:transform .2s; }
        .bfp-acc-body { display:none; padding:0 0 16px; font-size:13px!important; color:var(--bt-text-3)!important; line-height:1.8!important; }
        .bfp-acc-body.open { display:block; }
        .bfp-news-item { display:flex; gap:12px; padding:12px 0; border-bottom:1px solid rgba(255,255,255,.05); }
        .bfp-news-item:last-child { border:none; }
        .bfp-news-img { width:60px; height:60px; object-fit:cover; border-radius:0; flex-shrink:0; }
        .bfp-news-title a { font-size:13px!important; font-weight:600!important; color:#c8cdd8!important; text-decoration:none!important; line-height:1.4!important; }
        .bfp-news-title a:hover { color:var(--bt-accent)!important; }
        .bfp-news-meta { font-size:11px!important; color:var(--bt-text-3)!important; margin-top:4px; }
        </style>

        <div class="bfp">

        <?php self::render_breadcrumb([
            ['Home', home_url('/')],
            ['Forex Charts', home_url('/forex-charts/')],
            [$pair, ''],
        ]); ?>

        <!-- ═══ HERO ═══ -->
        <div class="bfp-hero">
            <!-- Left -->
            <div>
                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:10px">
                    <div style="background:linear-gradient(135deg,rgba(0,255,102,.15),rgba(0,255,102,.1));border:1px solid rgba(0,255,102,.25);border-radius:0;padding:6px 14px;font-size:14px;font-weight:800;color:var(--bt-accent);letter-spacing:1px"><?php echo esc_html($pair); ?></div>
                    <span style="background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.08);color:var(--bt-text-3);font-size:11px;font-weight:700;padding:3px 9px;border-radius:0"><?php echo esc_html($meta['category']); ?></span>
                    <?php if ($meta['nickname']): ?><span style="color:var(--bt-text-3);font-size:13px">"<?php echo esc_html($meta['nickname']); ?>"</span><?php endif; ?>
                    <?php
                    // v119.9: contextual price-alert button with modal
                    echo do_shortcode( sprintf(
                        '[bt_set_alert_btn coin_id="%s" symbol="%s" name="%s" price="%s" type="forex"]',
                        esc_attr( $pair ),
                        esc_attr( $pair ),
                        esc_attr( $name ),
                        esc_attr( $rate ?: '' )
                    ) );
                    ?>
                </div>
                <h1 style="font-size:clamp(22px,3.5vw,34px)!important;font-weight:900!important;color:var(--bt-text)!important;margin:0 0 6px!important"><?php echo esc_html($name); ?></h1>
                <p style="color:var(--bt-text-3);font-size:13px;margin:0 0 20px">Live exchange rate · Updated every 5 minutes · Frankfurter.app + ExchangeRate-API</p>

                <!-- Rate + change -->
                <?php if ($rate !== null): ?>
                <div style="display:flex;align-items:flex-end;gap:16px;flex-wrap:wrap;margin-bottom:16px">
                    <div style="font-size:clamp(36px,6vw,56px);font-weight:900;color:var(--bt-text);letter-spacing:-2px;line-height:1"><?php echo number_format($rate, 4); ?></div>
                    <div>
                        <div style="font-size:16px;font-weight:700" class="<?php echo $cls; ?>"><?php echo $arrow . ' ' . number_format(abs($chg), 3); ?>% <span style="font-size:12px;color:var(--bt-text-3);font-weight:400">(24h)</span></div>
                        <div style="font-size:12px;color:var(--bt-text-3);margin-top:3px">1 <?php echo esc_html($base); ?> = <?php echo number_format($rate, 4); ?> <?php echo esc_html($quote); ?></div>
                    </div>
                </div>

                <!-- Bid / Ask / Pip -->
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;margin-bottom:20px">
                    <?php
                    $quick = [
                        ['Bid', number_format($bid,4), 'var(--bt-accent)'],
                        ['Ask', number_format($ask,4), 'var(--bt-danger)'],
                        ['Spread', $meta['spread'], 'var(--bt-accent)'],
                        ['Pip Size', $meta['pip'], 'var(--bt-accent-warm)'],
                    ];
                    foreach ($quick as $q): ?>
                    <div style="background:var(--bt-bg-elev);border:1px solid rgba(255,255,255,.07);border-radius:0;padding:12px 14px">
                        <div style="font-size:10px;color:var(--bt-text-3);text-transform:uppercase;letter-spacing:.6px;margin-bottom:4px"><?php echo esc_html($q[0]); ?></div>
                        <div style="font-size:16px;font-weight:700;color:<?php echo esc_attr($q[2]); ?>"><?php echo esc_html($q[1]); ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <!-- Key Drivers -->
                <?php if (!empty($meta['factors'])): ?>
                <div style="margin-bottom:20px">
                    <div style="font-size:11px;color:var(--bt-text-3);text-transform:uppercase;letter-spacing:.6px;margin-bottom:8px">Key Market Drivers</div>
                    <div class="bfp-factors">
                        <?php foreach ($meta['factors'] as $f): ?>
                        <span class="bfp-factor-tag"><?php echo esc_html($f); ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Chart inside left column (v52: fills empty space next to tall sidebar) -->
                <div class="bcp-chart-inline" style="margin-top:8px">
                    <div class="fxlm-section-header" style="margin:0 0 12px!important;padding:0 0 10px!important">
                        <h2 style="font-size:17px!important"><?php echo esc_html($pair); ?> Live Chart</h2>
                        <span class="fxlm-section-badge">TradingView · Live</span>
                    </div>
                    <?php self::tv_chart($tv, 420); ?>
                </div>
            </div>

            <!-- Right sidebar -->
            <div>
                <!-- Quick converter -->
                <div class="bfp-card">
                    <h3><?php echo esc_html($pair); ?> Converter</h3>
                    <div style="display:flex;gap:8px;margin-bottom:8px">
                        <input type="number" id="bfp-in" value="1" min="0" step="any" oninput="bfpCalc()" style="flex:1;background:var(--bt-bg);border:1px solid rgba(255,255,255,.1);color:var(--bt-text);border-radius:0;padding:8px 10px;font-size:14px;outline:none">
                        <span style="color:var(--bt-text-3);font-size:12px;font-weight:700;display:flex;align-items:center;flex-shrink:0"><?php echo esc_html($base); ?></span>
                    </div>
                    <div id="bfp-out" style="background:rgba(0,255,102,.06);border:1px solid rgba(0,255,102,.15);border-radius:0;padding:10px 12px;font-size:16px;font-weight:700;color:var(--bt-accent);text-align:center">
                        <?php echo $rate ? number_format($rate, 4) . ' ' . esc_html($quote) : '—'; ?>
                    </div>
                    <div style="font-size:10px;color:var(--bt-text-4);margin-top:6px;text-align:center">Rate updates every 5 minutes</div>
                </div>

                <!-- Pair Stats -->
                <div class="bfp-card">
                    <h3><?php echo esc_html($pair); ?> Statistics</h3>
                    <?php
                    $stats = [
                        ['Current Rate', $rate ? number_format($rate, 4) : '—'],
                        ['24h Change', $chg !== null ? ($chg>=0?'+':'').number_format($chg,3).'%' : '—'],
                        ['Bid', $bid ? number_format($bid,4) : '—'],
                        ['Ask', $ask ? number_format($ask,4) : '—'],
                        ['Spread', $meta['spread']],
                        ['Pip Size', $meta['pip']],
                        ['Base Currency', $base],
                        ['Quote Currency', $quote],
                        ['Category', $meta['category']],
                        ['Trading Session', $meta['session']],
                    ];
                    if (!empty($meta['central_banks'])) {
                        $stats[] = ['Central Banks', implode(', ', $meta['central_banks'])];
                    }
                    foreach ($stats as $s): ?>
                    <div class="bfp-stat-row">
                        <span class="bfp-stat-lbl"><?php echo esc_html($s[0]); ?></span>
                        <span class="bfp-stat-val" style="text-align:right;max-width:60%"><?php echo esc_html($s[1]); ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Related Pairs -->
                <div class="bfp-card">
                    <h3><?php echo esc_html( __bt( 'asset.related_pairs' ) ); ?></h3>
                    <?php
                    $related = [
                        'eur-usd' => 'EUR/USD', 'gbp-usd' => 'GBP/USD', 'usd-jpy' => 'USD/JPY',
                        'aud-usd' => 'AUD/USD', 'usd-chf' => 'USD/CHF', 'usd-cad' => 'USD/CAD',
                        'nzd-usd' => 'NZD/USD', 'eur-gbp' => 'EUR/GBP',
                    ];
                    foreach ($related as $rel_slug => $rel_pair):
                        if ($rel_pair === $pair) continue; ?>
                    <div class="bfp-stat-row">
                        <a href="<?php echo esc_url(home_url('/forex/'.$rel_slug.'/')); ?>" style="color:var(--bt-accent)!important;text-decoration:none;font-size:12px;font-weight:600"><?php echo esc_html($rel_pair); ?></a>
                        <span style="font-size:11px;color:var(--bt-text-4)">View →</span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>


        <!-- ═══ ACCORDION: About / History / How to trade ═══ -->
        <div style="margin-bottom:28px">
            <h2 style="font-size:22px!important;font-weight:800!important;color:var(--bt-text)!important;margin:0 0 16px!important"><?php echo esc_html( sprintf( __bt( "asset.about_x" ), $pair ) ); ?></h2>
            <?php
            $faqs = [];
            if ($meta['desc'])    $faqs[] = ['What Is ' . $pair . '?', $meta['desc']];
            if ($meta['history']) $faqs[] = ['Historical Context', $meta['history']];
            if (!empty($meta['central_banks'])) {
                $cb_text = 'The ' . $pair . ' pair is governed by two central banks: ' . implode(' and ', $meta['central_banks']) . '. ';
                $cb_text .= 'Key factors that move this pair include: ' . implode(', ', $meta['factors'] ?? []) . '.';
                $faqs[] = ['What Moves ' . $pair . '?', $cb_text];
            }
            $faqs[] = ['How to Trade ' . $pair, 'To trade ' . $pair . ', you need a forex broker account. The pair is available 24 hours a day, 5 days a week. ' . $meta['session'] . '. Typical spread is ' . $meta['spread'] . '. Always use proper risk management including stop-loss orders.'];
            $faqs[] = ['What Are Pips in ' . $pair . '?', 'A pip (percentage in point) in ' . $pair . ' is ' . $meta['pip'] . '. For most major pairs, this represents a $10 gain/loss per standard lot (100,000 units). Mini lots (10,000 units) = $1 per pip.'];

            foreach ($faqs as $fi => $faq): ?>
            <div class="bfp-acc-item">
                <button class="bfp-acc-btn" onclick="bfpToggle(this)">
                    <?php echo esc_html($faq[0]); ?>
                    <span class="bfp-acc-icon" style="font-size:18px;color:var(--bt-text-3);transition:transform .2s;flex-shrink:0">+</span>
                </button>
                <div class="bfp-acc-body"><?php echo esc_html($faq[1]); ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- ═══ NEWS + POSTS ═══ -->
        <div style="display:grid;grid-template-columns:1.4fr 1fr;gap:28px;margin-bottom:28px">
            <div>
                <?php if (!empty($news)): ?>
                <div class="bfp-card">
                    <h3><?php echo esc_html( sprintf( __bt( "asset.latest_x_news" ), $pair ) ); ?></h3>
                    <?php self::render_news_items($news); ?>
                </div>
                <?php endif; ?>
            </div>
            <div>
                <?php if (!empty($posts)): ?>
                <div class="fxlm-section-header"><h2><?php echo esc_html( __bt( "asset.analysis_guides" ) ); ?></h2></div>
                <?php self::render_post_cards($posts); ?>
                <?php endif; ?>
                <div class="bfp-card" style="margin-top:16px">
                    <h3><?php echo esc_html( __bt( 'asset.quick_links' ) ); ?></h3>
                    <div style="display:flex;flex-direction:column;gap:8px">
                        <a href="<?php echo home_url('/forex-charts/'); ?>" style="color:var(--bt-accent);font-size:13px;text-decoration:none">📈 All Forex Charts →</a>
                        <a href="<?php echo home_url('/trading-signals/'); ?>" style="color:var(--bt-accent);font-size:13px;text-decoration:none">📡 Trading Signals →</a>
                        <a href="<?php echo home_url('/economic-calendar/'); ?>" style="color:var(--bt-accent);font-size:13px;text-decoration:none">📅 Economic Calendar →</a>
                        <a href="<?php echo home_url('/recommended-brokers/'); ?>" style="color:var(--bt-accent);font-size:13px;text-decoration:none">🏦 Recommended Brokers →</a>
                        <a href="<?php echo home_url('/tools/'); ?>" style="color:var(--bt-accent);font-size:13px;text-decoration:none">🔄 Currency Converter →</a>
                    </div>
                </div>
                <!-- Vote widget -->
                <?php self::render_vote_widget('forex_' . $slug); ?>
            </div>
        </div>

        </div><!-- .bfp -->

        <script>
        (function(){
            var rate = <?php echo floatval($rate ?? 0); ?>;
            window.bfpCalc = function(){
                var amt = parseFloat(document.getElementById('bfp-in').value)||0;
                var res = amt * rate;
                document.getElementById('bfp-out').textContent = res.toFixed(4) + ' <?php echo esc_js($quote); ?>';
            };
            bfpCalc();
        })();
        function bfpToggle(btn){
            var body = btn.nextElementSibling;
            var icon = btn.querySelector('.bfp-acc-icon');
            var isOpen = body.classList.contains('open');
            document.querySelectorAll('.bfp-acc-body').forEach(function(b){b.classList.remove('open');});
            document.querySelectorAll('.bfp-acc-icon').forEach(function(i){i.textContent='+';i.style.transform='';});
            if(!isOpen){ body.classList.add('open'); icon.textContent='×'; icon.style.transform='rotate(45deg)'; }
        }
        </script>
        <?php
        self::page_close();
    }

    /**
     * Format a large number with SI suffix (K/M/B/T), no decimals for <1000.
     *
     * @deprecated 69.0.0 Use BT_Utils::fmt_large() directly.
     * @param float $n
     * @return string
     */
    private static function fmt_large( $n ) {
        return BT_Utils::fmt_large( $n, 2, 0 );
    }

    // ── COMM-01: Bullish/Bearish vote ────────────────────────
    public static function render_vote_widget( $asset_key ) {
        $votes   = get_option( 'bt_votes_' . sanitize_key( $asset_key ), array( 'bull' => 0, 'bear' => 0 ) );
        $bull    = intval( $votes['bull'] );
        $bear    = intval( $votes['bear'] );
        $total   = $bull + $bear;
        $bull_pct = $total > 0 ? round( ( $bull / $total ) * 100 ) : 50;
        $bear_pct = 100 - $bull_pct;
        $nonce   = wp_create_nonce( 'bt_vote_' . $asset_key );
        ?>
        <div class="fxlm-vote-widget" id="fxlm-vote-<?php echo esc_attr( $asset_key ); ?>" style="background:var(--bt-bg-elev);border:1px solid rgba(255,255,255,.07);border-radius:0;padding:20px 22px;margin:24px 0">
            <div style="font-size:12px;color:var(--bt-text-3);text-transform:uppercase;letter-spacing:.8px;margin-bottom:14px;font-weight:600">Community Sentiment</div>
            <div style="display:flex;gap:10px;margin-bottom:16px">
                <button onclick="fxlmVote('<?php echo esc_js($asset_key); ?>','bull','<?php echo esc_js($nonce); ?>')"
                    class="bcp-vote-btn bcp-vote-bull">
                    🐂 Bullish
                </button>
                <button onclick="fxlmVote('<?php echo esc_js($asset_key); ?>','bear','<?php echo esc_js($nonce); ?>')"
                    class="bcp-vote-btn bcp-vote-bear">
                    🐻 Bearish
                </button>
            </div>
            <div id="fxlm-vote-bar-<?php echo esc_attr($asset_key); ?>">
                <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:6px">
                    <span style="color:var(--bt-accent);font-weight:700"><?php echo $bull_pct; ?>% Bullish</span>
                    <span style="color:var(--bt-text-3)"><?php echo $total; ?> votes</span>
                    <span style="color:var(--bt-danger);font-weight:700"><?php echo $bear_pct; ?>% Bearish</span>
                </div>
                <div style="height:6px;background:rgba(255,59,48,.3);border-radius:0;overflow:hidden">
                    <div style="height:100%;width:<?php echo $bull_pct; ?>%;background:linear-gradient(90deg,var(--bt-accent),var(--bt-accent));border-radius:0;transition:width .5s ease"></div>
                </div>
            </div>
        </div>
        <script>
        function fxlmVote(asset, dir, nonce) {
            var btn = event.target;
            btn.disabled = true;
            btn.textContent = 'Voting...';
            fetch('<?php echo esc_url( admin_url('admin-ajax.php') ); ?>', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=fxlm_asset_vote&asset=' + encodeURIComponent(asset) + '&dir=' + dir + '&nonce=' + encodeURIComponent(nonce)
            })
            .then(function(r){ return r.json(); })
            .then(function(d){
                if (d.success) {
                    var bar = document.getElementById('fxlm-vote-bar-' + asset);
                    if (bar) {
                        bar.innerHTML = '<div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:6px">'
                            + '<span style="color:var(--bt-accent);font-weight:700">' + d.data.bull_pct + '% Bullish</span>'
                            + '<span style="color:var(--bt-text-3)">' + d.data.total + ' votes</span>'
                            + '<span style="color:var(--bt-danger);font-weight:700">' + d.data.bear_pct + '% Bearish</span>'
                            + '</div>'
                            + '<div style="height:6px;background:rgba(255,59,48,.3);border-radius:0;overflow:hidden">'
                            + '<div style="height:100%;width:' + d.data.bull_pct + '%;background:linear-gradient(90deg,var(--bt-accent),var(--bt-accent));border-radius:0;transition:width .5s ease"></div>'
                            + '</div>';
                    }
                    btn.textContent = '✓ Voted!';
                    btn.style.opacity = '.5';
                }
            })
            .catch(function(){ btn.disabled = false; btn.textContent = dir === 'bull' ? '🐂 Bullish' : '🐻 Bearish'; });
        }
        </script>
        <?php
    }

    public static function ajax_vote() {
        $asset = sanitize_key( $_POST['asset'] ?? '' );
        $dir   = in_array( $_POST['dir'] ?? '', array('bull','bear') ) ? $_POST['dir'] : '';
        if ( ! $asset || ! $dir ) wp_send_json_error('Invalid');
        if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'bt_vote_' . $asset ) ) wp_send_json_error('Nonce failed');

        // Rate limit: one vote per IP per asset per 24h (stored in transient)
        $ip_key = 'bt_vote_' . $asset . '_' . md5( $_SERVER['REMOTE_ADDR'] ?? 'anon' );
        if ( get_transient( $ip_key ) ) wp_send_json_error('Already voted');
        set_transient( $ip_key, 1, DAY_IN_SECONDS );

        $votes = get_option( 'bt_votes_' . $asset, array( 'bull' => 0, 'bear' => 0 ) );
        $votes[ $dir ] = intval( $votes[ $dir ] ) + 1;
        update_option( 'bt_votes_' . $asset, $votes );

        $total    = $votes['bull'] + $votes['bear'];
        $bull_pct = $total > 0 ? round( ( $votes['bull'] / $total ) * 100 ) : 50;
        wp_send_json_success( array(
            'bull_pct' => $bull_pct,
            'bear_pct' => 100 - $bull_pct,
            'total'    => $total,
        ) );
    }

    // ── SITEMAP HELPER — call from an admin action to list all URLs ──
    public static function get_all_asset_urls() {
        $urls = array();
        foreach ( array_keys( self::$crypto_map ) as $slug ) {
            $urls[] = home_url( '/crypto/' . $slug . '/' );
        }
        foreach ( array_keys( self::$forex_map ) as $slug ) {
            $urls[] = home_url( '/forex/' . $slug . '/' );
        }
        return $urls;
    }
}
