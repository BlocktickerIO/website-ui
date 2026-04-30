<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class BT_SEO_Legacy {

    public static function register_shortcodes() {
        add_action( 'wp_head', array( __CLASS__, 'output_article_schema' ) );
        add_action( 'wp_head', array( __CLASS__, 'output_canonical' ) );           // SEO-03
        add_action( 'wp_head', array( __CLASS__, 'output_price_table_schema' ) );  // SEO-05
        // v96.5: additional schema + meta
        add_action( 'wp_head', array( __CLASS__, 'output_faq_schema' ) );          // FAQPage rich results
        add_action( 'wp_head', array( __CLASS__, 'output_data_feed_schema' ) );    // DataFeed for price pages
        add_action( 'wp_head', array( __CLASS__, 'output_news_meta' ) );           // Google News keywords

        // v96.5: AJAX handler for sitemap admin rebuild button
        add_action( 'wp_ajax_bt_rebuild_sitemap', array( 'BT_SEO_Sitemap', 'ajax_rebuild' ) );
    }

    public static function setup() {
        $site_name = get_option( 'bt_site_name', 'BlockTicker' );

        // Yoast SEO defaults if plugin active
        if ( defined( 'WPSEO_VERSION' ) ) {
            $wpseo_options = get_option( 'wpseo', array() );
            $wpseo_options['website_name']       = $site_name;
            $wpseo_options['og_default_image']   = '';
            $wpseo_options['twitter_card_type']  = 'summary_large_image';
            $wpseo_options['enable_xml_sitemap'] = true;
            update_option( 'wpseo', $wpseo_options );

            $wpseo_titles = get_option( 'wpseo_titles', array() );
            $wpseo_titles['title-home-wpseo']    = $site_name . ' — Live Crypto & Forex Intelligence %%sep%% %%sitename%%';
            $wpseo_titles['metadesc-home-wpseo'] = 'Free live crypto prices, forex rates, trading signals, AI market analysis and financial news — updated automatically 24/7.';
            $wpseo_titles['noindex-tax-post_tag'] = true;
            $wpseo_titles['breadcrumbs-enable']   = true;
            update_option( 'wpseo_titles', $wpseo_titles );
        }

        self::add_page_seo_meta();

        add_action( 'wp_head', array( __CLASS__, 'output_og_tags' ) );
        add_action( 'wp_head', array( __CLASS__, 'output_schema' ) );
        add_action( 'wp_head', array( __CLASS__, 'output_robots' ) );
        add_action( 'wp_head', array( __CLASS__, 'output_analytics' ) );
        add_action( 'wp_head', array( __CLASS__, 'output_article_schema' ) );
        // v63.1: Resource hints for faster third-party widget loads
        add_action( 'wp_head', array( __CLASS__, 'output_resource_hints' ), 1 );

        return array( 'success' => true, 'message' => 'SEO configured: Yoast, OG tags, Schema (website + article + breadcrumb), robots, sitemap enabled. Add Google Analytics ID in API Keys.' );
    }

    private static function add_page_seo_meta() {
        $site_name = get_option( 'bt_site_name', 'BlockTicker' );
        $pages_seo = array(
            'home' => array(
                'title' => $site_name . ' — Live Crypto & Forex Rates, Signals & AI Analysis',
                'desc'  => 'Free real-time cryptocurrency prices, forex rates, trading signals, AI-powered market analysis and financial news. Auto-updated 24/7.',
                'kw'    => 'crypto prices, forex rates, trading signals, bitcoin price, market analysis, cryptocurrency news',
            ),
            'forex-charts' => array(
                'title' => 'Live Forex Charts — EUR/USD, GBP/USD & More | ' . $site_name,
                'desc'  => 'Interactive live forex charts for all major currency pairs. Powered by TradingView. Free.',
                'kw'    => 'forex charts, EUR USD chart, live currency charts, forex trading charts',
            ),
            'crypto-markets' => array(
                'title' => 'Live Cryptocurrency Prices & Market Cap | ' . $site_name,
                'desc'  => 'Real-time crypto prices, market cap, volume and 24h change for 500+ coins including Bitcoin, Ethereum, Solana.',
                'kw'    => 'crypto prices, bitcoin price, ethereum price, cryptocurrency market cap, altcoin prices',
            ),
            'trading-signals' => array(
                'title' => 'Free Forex & Crypto Trading Signals | ' . $site_name,
                'desc'  => 'Free trading signals for forex and crypto. Updated every 15 minutes from professional sources.',
                'kw'    => 'forex signals, crypto signals, free trading signals, buy sell signals',
            ),
            'financial-news' => array(
                'title' => 'Crypto & Forex News — 14+ Sources | ' . $site_name,
                'desc'  => 'Latest crypto and forex news aggregated from CoinDesk, The Block, Decrypt, CoinTelegraph, Reuters and more.',
                'kw'    => 'crypto news, forex news, bitcoin news, financial news, market news',
            ),
            'tools' => array(
                'title' => 'Crypto Tools — Converter, Fear & Greed Index | ' . $site_name,
                'desc'  => 'Free crypto tools: currency converter, Fear & Greed Index, interactive charts. Everything you need to trade smarter.',
                'kw'    => 'crypto converter, fear greed index, crypto tools, bitcoin calculator',
            ),
            'learn' => array(
                'title' => 'Learn Crypto & Forex — Free Education Hub | ' . $site_name,
                'desc'  => 'Free crypto and forex education: glossary, beginner guides, tutorials. Start your trading journey here.',
                'kw'    => 'learn crypto, forex education, crypto glossary, trading guide, beginner crypto',
            ),
            'market-analysis' => array(
                'title' => 'AI Market Analysis — Daily Automated Insights | ' . $site_name,
                'desc'  => 'AI-generated daily market analysis for crypto and forex. Automated insights published without human intervention.',
                'kw'    => 'market analysis, crypto analysis, forex analysis, AI insights, daily market roundup',
            ),
        );

        foreach ( $pages_seo as $slug => $meta ) {
            $page = get_page_by_path( $slug );
            if ( $page ) {
                update_post_meta( $page->ID, '_yoast_wpseo_title',    $meta['title'] );
                update_post_meta( $page->ID, '_yoast_wpseo_metadesc', $meta['desc'] );
                update_post_meta( $page->ID, '_yoast_wpseo_focuskw',  $meta['kw'] );
            }
        }
    }

    public static function output_og_tags() {
        $site     = get_option( 'bt_site_name', 'BlockTicker' );
        $og_image = get_option( 'bt_og_image', '' );
        if ( empty( $og_image ) ) {
            $icon_id = get_option( 'site_icon' );
            if ( $icon_id ) $og_image = wp_get_attachment_image_url( $icon_id, 'large' );
        }
        $default_desc = 'Real-time crypto prices, forex rates, trading signals, AI analysis and financial news — updated 24/7.';

        if ( is_front_page() ) {
            $title = $site . ' — Live Crypto & Forex Intelligence';
            $desc  = $default_desc;
            $url   = home_url( '/' );
            $type  = 'website';
            $img   = $og_image;
        } elseif ( is_single() ) {
            $title = get_the_title();
            $desc  = wp_trim_words( get_the_excerpt() ?: strip_tags( get_the_content() ), 30, '…' );
            $url   = get_permalink();
            $type  = 'article';
            $img   = get_the_post_thumbnail_url( get_the_ID(), 'large' ) ?: $og_image;
        } elseif ( is_page() ) {
            global $post;
            $title = get_the_title() . ' | ' . $site;
            // Per-page descriptions
            $slug_descs = [
                'crypto-markets'   => 'Real-time cryptocurrency prices for 500+ coins. Market cap, volume, 24h change.',
                'forex-charts'     => 'Live forex charts for all major pairs. EUR/USD, GBP/USD, USD/JPY and more.',
                'financial-news'   => 'Latest crypto and forex news from 14+ sources. Updated every hour.',
                'market-analysis'  => 'AI-powered daily market analysis. Automated crypto and forex insights.',
                'trading-signals'  => 'Free forex and crypto trading signals. Updated every 15 minutes.',
                'exchanges'        => 'Top cryptocurrency exchanges ranked by volume, liquidity and trust score.',
                'tools'            => 'Free crypto tools: currency converter, Fear & Greed Index, economic calendar.',
                'learn'            => 'Learn crypto and forex trading. Glossary, guides and tutorials — all free.',
            ];
            $desc = $slug_descs[ $post->post_name ?? '' ] ?? $default_desc;
            $url  = get_permalink();
            $type = 'website';
            $img  = $og_image;
        } else {
            $title = get_bloginfo( 'name' ) . ' | ' . $site;
            $desc  = $default_desc;
            $url   = esc_url( get_pagenum_link() );
            $type  = 'website';
            $img   = $og_image;
        }

        // v63: Default OG image — falls back to branded placeholder if no page image
        if ( ! $img && is_singular( 'post' ) ) {
            $cats = get_the_category( get_the_ID() );
            $slug = ! empty( $cats ) ? $cats[0]->slug : 'default';
            $p    = BT_DIR . 'assets/images/placeholders/placeholder-' . sanitize_file_name( $slug ) . '.png';
            if ( ! file_exists( $p ) ) {
                $slug = 'default';
            }
            $img = BT_URL . 'assets/images/placeholders/placeholder-' . $slug . '.png';
        }

        $locale      = function_exists( 'get_locale' ) ? str_replace( '-', '_', get_locale() ) : 'en_US';
        $tw_handle   = ltrim( get_option( 'bt_twitter_handle', '@blocktickerIO' ), '@' );

        // Output OG tags
        echo '<meta property="og:title" content="' . esc_attr( $title ) . '" />' . "\n";
        echo '<meta property="og:description" content="' . esc_attr( $desc ) . '" />' . "\n";
        echo '<meta property="og:type" content="' . esc_attr( $type ) . '" />' . "\n";
        echo '<meta property="og:url" content="' . esc_url( $url ) . '" />' . "\n";
        echo '<meta property="og:site_name" content="' . esc_attr( $site ) . '" />' . "\n";
        echo '<meta property="og:locale" content="' . esc_attr( $locale ) . '" />' . "\n";
        if ( $img ) {
            echo '<meta property="og:image" content="' . esc_url( $img ) . '" />' . "\n";
            // v63: dimension hints — all our placeholders are 1200×630; featured images pass through WP so size varies
            //  but LinkedIn/FB render the card faster when dimensions are declared.
            echo '<meta property="og:image:width" content="1200" />' . "\n";
            echo '<meta property="og:image:height" content="630" />' . "\n";
            echo '<meta property="og:image:alt" content="' . esc_attr( $title ) . '" />' . "\n";
        }

        // v63: Article-specific OG tags for richer post cards
        if ( is_single() ) {
            $pub_time = get_the_date( 'c' );
            $mod_time = get_the_modified_date( 'c' );
            $author   = get_the_author_meta( 'display_name', get_post_field( 'post_author', get_the_ID() ) );
            echo '<meta property="article:published_time" content="' . esc_attr( $pub_time ) . '" />' . "\n";
            echo '<meta property="article:modified_time" content="' . esc_attr( $mod_time ) . '" />' . "\n";
            if ( $author ) echo '<meta property="article:author" content="' . esc_attr( $author ) . '" />' . "\n";
            $tag_list = get_the_tags( get_the_ID() );
            if ( $tag_list ) {
                foreach ( array_slice( $tag_list, 0, 6 ) as $tag ) {
                    echo '<meta property="article:tag" content="' . esc_attr( $tag->name ) . '" />' . "\n";
                }
            }
            $cat_list = get_the_category( get_the_ID() );
            if ( ! empty( $cat_list ) ) {
                echo '<meta property="article:section" content="' . esc_attr( $cat_list[0]->name ) . '" />' . "\n";
            }
        }

        // Twitter Card
        echo '<meta name="twitter:card" content="summary_large_image" />' . "\n";
        echo '<meta name="twitter:site" content="@' . esc_attr( $tw_handle ) . '" />' . "\n";
        echo '<meta name="twitter:creator" content="@' . esc_attr( $tw_handle ) . '" />' . "\n";
        echo '<meta name="twitter:title" content="' . esc_attr( $title ) . '" />' . "\n";
        echo '<meta name="twitter:description" content="' . esc_attr( $desc ) . '" />' . "\n";
        if ( $img ) {
            echo '<meta name="twitter:image" content="' . esc_url( $img ) . '" />' . "\n";
            echo '<meta name="twitter:image:alt" content="' . esc_attr( $title ) . '" />' . "\n";
        }

        // Meta description for non-Yoast pages
        if ( ! defined( 'WPSEO_VERSION' ) && ! is_singular() ) {
            echo '<meta name="description" content="' . esc_attr( $desc ) . '" />' . "\n";
        }
    }

    public static function output_schema() {
        $site = get_option( 'bt_site_name', 'BlockTicker' );
        $logo = get_option( 'bt_og_image', '' );

        // ── Organization schema (on every page) ─────────────────────────
        $org = [
            '@context' => 'https://schema.org',
            '@type'    => 'Organization',
            'name'     => $site,
            'url'      => home_url(),
            'logo'     => $logo ?: home_url( '/wp-content/plugins/blockticker-io/assets/img/logo.png' ),
            'sameAs'   => [],
            'description' => 'Live crypto prices, forex rates, trading signals, AI analysis and financial news.',
        ];
        echo '<script type="application/ld+json">' . wp_json_encode( $org ) . '</script>' . "\n";

        if ( ! is_front_page() ) return;

        // ── WebSite schema with SearchAction ─────────────────────────────
        $schema = [
            '@context'    => 'https://schema.org',
            '@type'       => 'WebSite',
            'name'        => $site,
            'url'         => home_url(),
            'description' => 'Live crypto prices, forex rates, trading signals, AI analysis and financial news.',
            'potentialAction' => [
                '@type'       => 'SearchAction',
                'target'      => home_url( '/?s={search_term_string}' ),
                'query-input' => 'required name=search_term_string',
            ],
        ];
        echo '<script type="application/ld+json">' . wp_json_encode( $schema ) . '</script>' . "\n";
    }

    // v6: Article schema for every single post
    public static function output_article_schema() {
        $site = get_option( 'bt_site_name', 'BlockTicker' );
        $logo = get_option( 'bt_og_image', '' );

        if ( is_single() ) {
            $thumb  = get_the_post_thumbnail_url( get_the_ID(), 'large' );
            $schema = [
                '@context'         => 'https://schema.org',
                '@type'            => 'NewsArticle',
                'headline'         => get_the_title(),
                'datePublished'    => get_the_date( 'c' ),
                'dateModified'     => get_the_modified_date( 'c' ),
                'description'      => wp_trim_words( get_the_excerpt() ?: strip_tags( get_the_content() ), 30, '…' ),
                'url'              => get_permalink(),
                'inLanguage'       => 'en',
                'author'           => [ '@type' => 'Organization', 'name' => $site, 'url' => home_url() ],
                'publisher'        => [
                    '@type' => 'Organization',
                    'name'  => $site,
                    'url'   => home_url(),
                    'logo'  => [ '@type' => 'ImageObject', 'url' => $logo ?: home_url() ],
                ],
                'mainEntityOfPage' => [ '@type' => 'WebPage', '@id' => get_permalink() ],
            ];
            if ( $thumb ) $schema['image'] = [ '@type' => 'ImageObject', 'url' => $thumb ];

            // Breadcrumb for posts
            $cats = get_the_category();
            $cat  = $cats ? $cats[0] : null;
            $schema['breadcrumb'] = [
                '@type' => 'BreadcrumbList',
                'itemListElement' => array_filter( [
                    [ '@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => home_url('/') ],
                    $cat ? [ '@type' => 'ListItem', 'position' => 2, 'name' => $cat->name, 'item' => get_category_link( $cat->term_id ) ] : null,
                    [ '@type' => 'ListItem', 'position' => $cat ? 3 : 2, 'name' => get_the_title(), 'item' => get_permalink() ],
                ]),
            ];
            echo '<script type="application/ld+json">' . wp_json_encode( $schema ) . '</script>' . "\n";
            return;
        }

        // Coin page schema (/crypto/{slug}/)
        if ( isset( $_SERVER['REQUEST_URI'] ) ) {
            $uri = trim( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ), '/' );
            if ( preg_match( '#^crypto/([a-z0-9-]+)/?$#', $uri, $m ) ) {
                $slug   = $m[1];
                $crypto = BT_Widgets::get_json_option( 'fxlm_crypto_data' );
                $coin   = null;
                foreach ( ($crypto['coins'] ?? []) as $c ) {
                    if ( $c['id'] === $slug ) { $coin = $c; break; }
                }
                if ( $coin ) {
                    $schema = [
                        '@context'    => 'https://schema.org',
                        '@type'       => 'WebPage',
                        'name'        => $coin['name'] . ' (' . strtoupper($coin['symbol']) . ') Price, Charts & Market Data',
                        'description' => 'Live ' . $coin['name'] . ' price, market cap, charts, historical data and latest news.',
                        'url'         => home_url( '/crypto/' . $slug . '/' ),
                        'breadcrumb'  => [
                            '@type' => 'BreadcrumbList',
                            'itemListElement' => [
                                [ '@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => home_url('/') ],
                                [ '@type' => 'ListItem', 'position' => 2, 'name' => 'Crypto Markets', 'item' => home_url('/crypto-markets/') ],
                                [ '@type' => 'ListItem', 'position' => 3, 'name' => $coin['name'], 'item' => home_url('/crypto/'.$slug.'/') ],
                            ],
                        ],
                    ];
                    if ( ! empty($coin['current_price']) ) {
                        $schema['mainEntity'] = [
                            '@type'         => 'Product',
                            'name'          => $coin['name'],
                            'description'   => $coin['name'] . ' cryptocurrency',
                            'offers'        => [
                                '@type'         => 'Offer',
                                'price'         => (string) round($coin['current_price'], 8),
                                'priceCurrency' => 'USD',
                                'availability'  => 'https://schema.org/InStock',
                            ],
                        ];
                    }
                    echo '<script type="application/ld+json">' . wp_json_encode( $schema ) . '</script>' . "\n";
                }
            }

            // Forex pair schema (/forex/{slug}/)
            if ( preg_match( '#^forex/([a-z-]+)/?$#', $uri, $m ) ) {
                $slug  = $m[1];
                $pair  = strtoupper( str_replace('-', '/', $slug) );
                $schema = [
                    '@context'   => 'https://schema.org',
                    '@type'      => 'WebPage',
                    'name'       => $pair . ' Live Exchange Rate, Charts & Analysis | ' . $site,
                    'description'=> 'Live ' . $pair . ' exchange rate, historical charts, central bank info and trading analysis.',
                    'url'        => home_url( '/forex/' . $slug . '/' ),
                    'breadcrumb' => [
                        '@type' => 'BreadcrumbList',
                        'itemListElement' => [
                            [ '@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => home_url('/') ],
                            [ '@type' => 'ListItem', 'position' => 2, 'name' => 'Forex Charts', 'item' => home_url('/forex-charts/') ],
                            [ '@type' => 'ListItem', 'position' => 3, 'name' => $pair, 'item' => home_url('/forex/'.$slug.'/') ],
                        ],
                    ],
                ];
                echo '<script type="application/ld+json">' . wp_json_encode( $schema ) . '</script>' . "\n";
            }
        }
    }

    public static function output_robots() {
        echo '<meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1" />' . "\n";
    }

    /**
     * v63.1: Resource hints — preconnect to third-party origins that we know we'll hit.
     * preconnect does DNS + TCP + TLS upfront (~100-300ms saved on first request).
     * dns-prefetch is cheaper, used for origins we *might* hit.
     * Fired at priority 1 on wp_head so the hints land near the top of <head>.
     */
    public static function output_resource_hints() {
        // Preconnect to TradingView (primary chart + event-calendar widget CDN)
        echo '<link rel="preconnect" href="https://s3.tradingview.com" crossorigin />' . "\n";
        echo '<link rel="preconnect" href="https://s.tradingview.com" crossorigin />' . "\n";
        // Google Fonts (if enqueued elsewhere)
        echo '<link rel="preconnect" href="https://fonts.googleapis.com" crossorigin />' . "\n";
        echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />' . "\n";
        // DNS-prefetch for sources we hit on some pages but not all
        echo '<link rel="dns-prefetch" href="https://api.coingecko.com" />' . "\n";
        echo '<link rel="dns-prefetch" href="https://api.frankfurter.app" />' . "\n";
        echo '<link rel="dns-prefetch" href="https://widget.coindesk.com" />' . "\n";
    }

    // SEO-03: Canonical tags — prevents duplicate content on daily roundup posts
    public static function output_canonical() {
        if ( is_single() ) {
            // Self-referencing canonical on every post
            echo '<link rel="canonical" href="' . esc_url( get_permalink() ) . '" />' . "\n";
        } elseif ( is_front_page() ) {
            echo '<link rel="canonical" href="' . esc_url( home_url('/') ) . '" />' . "\n";
        } elseif ( is_page() ) {
            echo '<link rel="canonical" href="' . esc_url( get_permalink() ) . '" />' . "\n";
        } elseif ( is_category() || is_tag() || is_archive() ) {
            echo '<link rel="canonical" href="' . esc_url( get_pagenum_link() ) . '" />' . "\n";
        }
    }

    // SEO-05: ItemList schema for homepage crypto price table
    public static function output_price_table_schema() {
        global $post;

        // Exchanges page schema
        if ( is_a($post,'WP_Post') && $post->post_name === 'exchanges' ) {
            $schema = array(
                '@context'    => 'https://schema.org',
                '@type'       => 'WebPage',
                'name'        => 'Top Cryptocurrency Spot Exchanges',
                'description' => 'Ranked list of the best cryptocurrency exchanges by trading volume, liquidity, trust score, and weekly visits.',
                'url'         => home_url('/exchanges/'),
                'breadcrumb'  => array(
                    '@type' => 'BreadcrumbList',
                    'itemListElement' => array(
                        array('@type'=>'ListItem','position'=>1,'name'=>'Home','item'=>home_url('/')),
                        array('@type'=>'ListItem','position'=>2,'name'=>'Exchanges','item'=>home_url('/exchanges/')),
                    ),
                ),
            );
            echo '<script type="application/ld+json">' . wp_json_encode($schema) . '</script>' . "\n";
        }

        // Forex page schema
        if ( is_a($post,'WP_Post') && $post->post_name === 'forex-charts' ) {
            $forex  = BT_Widgets::get_json_option('fxlm_forex_data');
            $items  = [];
            $pos    = 1;
            foreach ( ($forex['rates'] ?? []) as $pair => $data ) {
                $items[] = array(
                    '@type'    => 'ListItem',
                    'position' => $pos++,
                    'name'     => $pair . ' Exchange Rate',
                    'description' => $pair . ' live rate: ' . number_format($data['rate'],4),
                );
            }
            if ($items) {
                $schema = array(
                    '@context' => 'https://schema.org',
                    '@type'    => 'ItemList',
                    'name'     => 'Live Forex Exchange Rates',
                    'url'      => home_url('/forex-charts/'),
                    'itemListElement' => $items,
                );
                echo '<script type="application/ld+json">' . wp_json_encode($schema) . '</script>' . "\n";
            }
        }

        if ( ! is_front_page() ) return;

        $crypto = BT_Widgets::get_json_option( 'fxlm_crypto_data' );
        if ( empty( $crypto['coins'] ) ) return;

        $items = array();
        foreach ( array_slice( $crypto['coins'], 0, 10 ) as $i => $coin ) {
            $items[] = array(
                '@type'    => 'ListItem',
                'position' => $i + 1,
                'name'     => $coin['name'] . ' (' . strtoupper( $coin['symbol'] ) . ')',
                'url'      => home_url( '/crypto/' . sanitize_title( $coin['id'] ?? $coin['name'] ) . '/' ),
                'description' => $coin['name'] . ' live price: $' . number_format( $coin['current_price'], 2 ) . ' USD',
            );
        }

        $schema = array(
            '@context'        => 'https://schema.org',
            '@type'           => 'ItemList',
            'name'            => 'Live Cryptocurrency Prices',
            'description'     => 'Real-time cryptocurrency prices updated every 5 minutes',
            'url'             => home_url( '/crypto-markets/' ),
            'numberOfItems'   => count( $items ),
            'itemListElement' => $items,
        );

        echo '<script type="application/ld+json">' . wp_json_encode( $schema ) . '</script>' . "\n";
    }

    public static function output_analytics() {
        $ga_id = trim( get_option( 'bt_ga_id', '' ) );
        if ( empty( $ga_id ) ) return;
        // Accept GA4 (G-), Universal Analytics (UA-), and GTM Server Container (GT-) IDs
        // GT- IDs are valid but use a different GTM endpoint
        if ( preg_match( '/^GT-[A-Z0-9]+$/', $ga_id ) ) {
            // GTM Server-side container: use gtm.js not gtag.js
            echo "<!-- Google Tag Manager (Server) -->\n<script async src='https://www.googletagmanager.com/gtm.js?id=" . esc_attr($ga_id) . "'></script>\n";
            echo "<script>window.dataLayer=window.dataLayer||[];window.dataLayer.push({'gtm.start':new Date().getTime(),'event':'gtm.js'});</script>\n";
            return;
        }
        if ( ! preg_match( '/^(G-[A-Z0-9]+|UA-[0-9]+-[0-9]+)$/', $ga_id ) ) {
            // Unknown format — still inject but add a comment
            echo "<!-- BlockTicker: GA ID format '$ga_id' unrecognised — injecting anyway -->\n";
        }
        echo "<!-- Google Analytics -->\n<script async src='https://www.googletagmanager.com/gtag/js?id=" . esc_attr( $ga_id ) . "'></script>\n<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','" . esc_js( $ga_id ) . "');</script>\n";
    }

    public static function setup_monetization() {
        $pub_id = get_option( 'bt_adsense_id', '' );

        if ( $pub_id ) {
            add_filter( 'the_content', array( __CLASS__, 'insert_adsense' ) );
        }

        add_shortcode( 'fxlm_affiliate', array( __CLASS__, 'sc_affiliate' ) );

        if ( $pub_id ) {
            add_action( 'wp_head', function() use ( $pub_id ) {
                echo '<script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=' . esc_attr( $pub_id ) . '" crossorigin="anonymous"></script>' . "\n";
            });
        }

        $msg = 'Monetization configured.';
        if ( $pub_id ) $msg .= ' AdSense publisher ID set.';
        else $msg .= ' Add your AdSense ID in the wizard to enable ads.';
        $msg .= ' Affiliate shortcode: [fxlm_affiliate name="Broker" url="https://..."]';

        return array( 'success' => true, 'message' => $msg );
    }

    public static function insert_adsense( $content ) {
        $pub_id = get_option( 'bt_adsense_id', '' );
        if ( ! $pub_id || ! is_single() ) return $content;

        // FIX ADS-01: only inject if real slot ID is configured
        $slot = get_option( 'bt_adsense_slot', '' );
        if ( empty( $slot ) || strpos( $slot, 'YOUR_' ) === 0 ) return $content;
        $ad = '<div class="fxlm-ad-in-content"><ins class="adsbygoogle" style="display:block;text-align:center" data-ad-layout="in-article" data-ad-format="fluid" data-ad-client="' . esc_attr( $pub_id ) . '" data-ad-slot="' . esc_attr( $slot ) . '"></ins><script>(adsbygoogle=window.adsbygoogle||[]).push({});</script></div>';

        $paras = explode( '</p>', $content );
        if ( count( $paras ) > 3 ) {
            $paras[2] .= '</p>' . $ad;
            return implode( '</p>', $paras );
        }
        return $content . $ad;
    }

    /**
     * Affiliate shortcode — supports two modes:
     *
     *   1. Single broker:  [fxlm_affiliate name="X" url="..." rating="5"]
     *      Renders one CTA card (legacy behavior).
     *
     *   2. Curated list:   [fxlm_affiliate type="broker"]   |   type="exchange"
     *      Renders a grid of cards from `bt_broker_list` / `bt_exchange_list`
     *      options. If the option is empty, falls back to a built-in default
     *      list of well-known regulated providers so the page is never blank.
     *
     * @since v119.28.27 (type=broker support)
     */
    public static function sc_affiliate( $atts ) {
        $a = shortcode_atts( array(
            'name'   => 'Broker',
            'url'    => '#',
            'label'  => 'Open Account',
            'rating' => '5',
            'type'   => '',
        ), $atts );

        // ── Mode 2: list mode ──────────────────────────────────────────
        $type = sanitize_key( $a['type'] );
        if ( $type === 'broker' || $type === 'exchange' ) {
            return self::render_affiliate_list( $type );
        }

        // ── Mode 1: single CTA (legacy) ────────────────────────────────
        return '<div class="fxlm-affiliate-cta"><strong>' . esc_html( $a['name'] ) . '</strong><span class="fxlm-stars">' . str_repeat( '★', intval( $a['rating'] ) ) . '</span><a href="' . esc_url( $a['url'] ) . '" class="fxlm-btn-affiliate" target="_blank" rel="sponsored noopener">' . esc_html( $a['label'] ) . ' →</a></div>';
    }

    /**
     * Render a card grid + comparison table for brokers or exchanges.
     *
     * @param string $type Either 'broker' or 'exchange'.
     * @return string HTML.
     */
    private static function render_affiliate_list( $type ) {
        $option_key = ( $type === 'exchange' ) ? 'bt_exchange_list' : 'bt_broker_list';
        $list = get_option( $option_key, array() );
        if ( ! is_array( $list ) || empty( $list ) ) {
            $list = self::default_affiliate_list( $type );
        }

        $disclosure_text = ( $type === 'exchange' )
            ? 'We only feature regulated exchanges with transparent reserve attestations and a track record of secure custody. Some links are affiliate links — we may earn a commission at no additional cost to you. This never affects our ratings.'
            : 'We only feature brokers regulated by FCA, ASIC, CySEC, NFA, or equivalent tier-1 authorities. Some links are affiliate links — we may earn a commission at no additional cost to you. This never affects our ratings.';

        $stat_labels = ( $type === 'exchange' )
            ? array( 'fees' => 'Spot fees', 'min' => 'Min deposit' )
            : array( 'fees' => 'Min spread', 'min' => 'Min deposit' );

        ob_start();
        ?>
        <div class="bt-broker-disclosure">
            <strong>Affiliate disclosure.</strong> <?php echo esc_html( $disclosure_text ); ?>
        </div>

        <div class="bt-broker-grid">
            <?php foreach ( $list as $b ) :
                $name    = isset( $b['name'] )    ? $b['name']                         : 'Provider';
                $tagline = isset( $b['tagline'] ) ? $b['tagline']                      : '';
                $url     = isset( $b['url'] )     ? $b['url']                          : '#';
                $rating  = isset( $b['rating'] )  ? min( 5, max( 1, intval($b['rating']) ) ) : 5;
                $fees    = isset( $b['fees'] )    ? $b['fees']                         : '—';
                $min     = isset( $b['min'] )     ? $b['min']                          : '—';
                $tags    = isset( $b['tags'] ) && is_array($b['tags']) ? $b['tags']    : array();
            ?>
                <div class="bt-broker-card">
                    <div class="bt-broker-head">
                        <h3 class="bt-broker-name"><?php echo esc_html( $name ); ?></h3>
                        <span class="bt-broker-rating" aria-label="<?php echo esc_attr( $rating . ' out of 5 stars' ); ?>"><?php echo str_repeat( '★', $rating ) . str_repeat( '☆', 5 - $rating ); ?></span>
                    </div>
                    <?php if ( $tagline ) : ?>
                    <p class="bt-broker-tagline"><?php echo esc_html( $tagline ); ?></p>
                    <?php endif; ?>
                    <div class="bt-broker-stats">
                        <div>
                            <div class="bt-broker-stat-label"><?php echo esc_html( $stat_labels['fees'] ); ?></div>
                            <div class="bt-broker-stat-val"><?php echo esc_html( $fees ); ?></div>
                        </div>
                        <div>
                            <div class="bt-broker-stat-label"><?php echo esc_html( $stat_labels['min'] ); ?></div>
                            <div class="bt-broker-stat-val"><?php echo esc_html( $min ); ?></div>
                        </div>
                    </div>
                    <?php if ( ! empty( $tags ) ) : ?>
                    <div class="bt-broker-tags">
                        <?php foreach ( $tags as $tag ) : ?>
                            <span class="bt-broker-tag"><?php echo esc_html( $tag ); ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <a href="<?php echo esc_url( $url ); ?>" class="bt-broker-cta" target="_blank" rel="sponsored noopener">
                        Visit <?php echo esc_html( $name ); ?> →
                    </a>
                </div>
            <?php endforeach; ?>
        </div>

        <h2 style="margin-top:32px">At-a-glance comparison</h2>
        <div class="bt-broker-compare-wrap">
            <table class="bt-broker-compare">
                <thead>
                    <tr>
                        <th><?php echo esc_html( $type === 'exchange' ? 'Exchange' : 'Broker' ); ?></th>
                        <th>Rating</th>
                        <th><?php echo esc_html( $stat_labels['fees'] ); ?></th>
                        <th><?php echo esc_html( $stat_labels['min'] ); ?></th>
                        <th>Highlights</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $list as $b ) :
                        $name    = isset( $b['name'] )   ? $b['name']                          : 'Provider';
                        $url     = isset( $b['url'] )    ? $b['url']                           : '#';
                        $rating  = isset( $b['rating'] ) ? min(5, max(1, intval($b['rating']))) : 5;
                        $fees    = isset( $b['fees'] )   ? $b['fees']                          : '—';
                        $min     = isset( $b['min'] )    ? $b['min']                           : '—';
                        $tags    = isset( $b['tags'] ) && is_array($b['tags']) ? $b['tags']    : array();
                    ?>
                    <tr>
                        <td class="bt-broker-compare-name"><?php echo esc_html( $name ); ?></td>
                        <td><span style="color:#FFB840"><?php echo str_repeat( '★', $rating ); ?></span></td>
                        <td><?php echo esc_html( $fees ); ?></td>
                        <td><?php echo esc_html( $min ); ?></td>
                        <td style="color:#94a3b8;font-size:0.75rem"><?php echo esc_html( implode( ' · ', array_slice( $tags, 0, 3 ) ) ); ?></td>
                        <td><a href="<?php echo esc_url( $url ); ?>" class="bt-broker-cta" target="_blank" rel="sponsored noopener" style="padding:6px 12px;font-size:0.75rem">Visit →</a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Default broker / exchange list — used when site option is empty.
     * These are placeholder entries; site owner should replace via admin.
     *
     * @param string $type 'broker' | 'exchange'
     * @return array
     */
    private static function default_affiliate_list( $type ) {
        if ( $type === 'exchange' ) {
            return array(
                array( 'name' => 'Coinbase',     'url' => 'https://www.coinbase.com',  'rating' => 5, 'fees' => '0.50%',  'min' => '$1',   'tagline' => 'Largest US-listed exchange. SEC-registered and publicly traded.',                'tags' => array( 'NYSE-listed', 'US-regulated', 'Insured custody' ) ),
                array( 'name' => 'Kraken',       'url' => 'https://www.kraken.com',    'rating' => 5, 'fees' => '0.26%',  'min' => '$10',  'tagline' => 'Veteran exchange known for strong security and proof-of-reserves.',                'tags' => array( 'PoR', 'Margin', 'Staking' ) ),
                array( 'name' => 'Binance',      'url' => 'https://www.binance.com',   'rating' => 4, 'fees' => '0.10%',  'min' => '$10',  'tagline' => 'Highest liquidity globally. Deep order books across most pairs.',                  'tags' => array( 'Deep liquidity', '350+ pairs', 'Futures' ) ),
                array( 'name' => 'Bybit',        'url' => 'https://www.bybit.com',     'rating' => 4, 'fees' => '0.10%',  'min' => '$10',  'tagline' => 'Derivatives-first venue with strong perpetual swaps liquidity.',                   'tags' => array( 'Perpetuals', 'Copy-trading', 'Low fees' ) ),
                array( 'name' => 'Gemini',       'url' => 'https://www.gemini.com',    'rating' => 5, 'fees' => '0.40%',  'min' => '$0',   'tagline' => 'NYDFS-regulated trust company. Institutional-grade compliance.',                  'tags' => array( 'NYDFS', 'SOC 2', 'Insured custody' ) ),
                array( 'name' => 'OKX',          'url' => 'https://www.okx.com',       'rating' => 4, 'fees' => '0.08%',  'min' => '$10',  'tagline' => 'Hong Kong-based with deep liquidity and an integrated Web3 wallet.',               'tags' => array( 'Web3 wallet', 'Copy-trading', 'Low fees' ) ),
            );
        }
        return array(
            array( 'name' => 'IG Markets',          'url' => 'https://www.ig.com',                'rating' => 5, 'fees' => '0.6 pips',  'min' => '$0',     'tagline' => '50-year track record. FTSE 250-listed and FCA-regulated.',                                  'tags' => array( 'FCA', 'ASIC', 'NFA', 'LSE-listed' ) ),
            array( 'name' => 'Saxo Bank',           'url' => 'https://www.home.saxo',             'rating' => 5, 'fees' => '0.4 pips',  'min' => '$2,000', 'tagline' => 'Danish bank-grade broker. Ideal for serious multi-asset traders.',                          'tags' => array( 'FCA', 'FINMA', 'Bank-tier' ) ),
            array( 'name' => 'Interactive Brokers', 'url' => 'https://www.interactivebrokers.com','rating' => 5, 'fees' => '0.2 pips',  'min' => '$0',     'tagline' => 'Lowest commissions in the industry. Used by professional and institutional traders.',         'tags' => array( 'SEC', 'FINRA', 'NASDAQ-listed' ) ),
            array( 'name' => 'OANDA',               'url' => 'https://www.oanda.com',             'rating' => 4, 'fees' => '1.0 pips',  'min' => '$0',     'tagline' => 'US-based with strong API access. Popular with algo traders.',                                'tags' => array( 'CFTC', 'NFA', 'API-first' ) ),
            array( 'name' => 'Pepperstone',         'url' => 'https://www.pepperstone.com',       'rating' => 4, 'fees' => '0.0 pips',  'min' => '$200',   'tagline' => 'Fast execution and tight raw spreads. Razor account is industry-competitive.',               'tags' => array( 'FCA', 'ASIC', 'Raw spreads' ) ),
            array( 'name' => 'IC Markets',          'url' => 'https://www.icmarkets.com',         'rating' => 4, 'fees' => '0.0 pips',  'min' => '$200',   'tagline' => 'Australia-based ECN broker known for tight spreads and MetaTrader stack.',                  'tags' => array( 'ASIC', 'CySEC', 'MT4/MT5' ) ),
        );
    }

    // -------------------------------------------------------------------------
    // v96.5 additions
    // -------------------------------------------------------------------------

    /**
     * FAQPage schema for key content pages.
     *
     * Google shows FAQ rich results (expandable Q&A) directly in the SERP.
     * Each page has 4–6 pre-written Q&A pairs relevant to its content.
     * Only outputs on pages with a matching slug — zero output on all other pages.
     *
     * @since 96.5.0
     */
    public static function output_faq_schema() {
        if ( ! is_page() ) return;
        global $post;
        if ( ! is_a( $post, 'WP_Post' ) ) return;

        $site = get_option( 'bt_site_name', 'BlockTicker' );

        $faq_map = array(

            'crypto-markets' => array(
                array(
                    'q' => 'Where can I see live cryptocurrency prices?',
                    'a' => $site . ' shows live prices for 500+ cryptocurrencies updated every 5 minutes, including Bitcoin, Ethereum, Solana and all major altcoins.',
                ),
                array(
                    'q' => 'What data does the crypto markets page show?',
                    'a' => 'Each coin shows current price in USD, 24-hour percentage change, trading volume, market capitalisation, and a 7-day mini-chart.',
                ),
                array(
                    'q' => 'How often is cryptocurrency price data updated?',
                    'a' => 'Price data refreshes automatically every 5 minutes via the CoinGecko API.',
                ),
                array(
                    'q' => 'Can I see historical cryptocurrency prices?',
                    'a' => 'Yes — click any coin to open its detail page, which includes an interactive chart with 1D, 7D, 30D and 90D history.',
                ),
            ),

            'forex-charts' => array(
                array(
                    'q' => 'What forex pairs are available?',
                    'a' => $site . ' covers 170+ currency pairs including all majors (EUR/USD, GBP/USD, USD/JPY), minors and select exotic pairs, updated in real time.',
                ),
                array(
                    'q' => 'Are the forex charts free?',
                    'a' => 'Yes — all forex charts and exchange rate data on ' . $site . ' are completely free with no registration required.',
                ),
                array(
                    'q' => 'What is a pip in forex trading?',
                    'a' => 'A pip (Percentage in Point) is the smallest standard price increment in a forex quote. For most pairs it is 0.0001; for JPY pairs it is 0.01.',
                ),
                array(
                    'q' => 'How do I read a forex chart?',
                    'a' => 'A forex chart plots the exchange rate of one currency against another over time. Rising lines mean the base currency is strengthening; falling lines mean it is weakening.',
                ),
            ),

            'trading-signals' => array(
                array(
                    'q' => 'What are trading signals?',
                    'a' => 'Trading signals are buy or sell recommendations for a specific asset at a specific price level, produced by analysts or automated systems.',
                ),
                array(
                    'q' => 'How often are signals updated?',
                    'a' => 'Signals on ' . $site . ' are fetched from multiple professional sources every 15 minutes.',
                ),
                array(
                    'q' => 'Are the trading signals free?',
                    'a' => 'Yes — all signals displayed on ' . $site . ' are free. No subscription is required.',
                ),
                array(
                    'q' => 'Should I follow every trading signal?',
                    'a' => 'No. Trading signals are for informational purposes only and do not constitute financial advice. Always conduct your own research and risk management.',
                ),
            ),

            'financial-news' => array(
                array(
                    'q' => 'Where does the crypto and forex news come from?',
                    'a' => $site . ' aggregates headlines from 14+ professional sources including CoinDesk, The Block, Decrypt, CoinTelegraph, MarketWatch and ForexLive.',
                ),
                array(
                    'q' => 'How often is financial news updated?',
                    'a' => 'The news feed refreshes every hour, pulling the latest headlines from all sources automatically.',
                ),
                array(
                    'q' => 'Can I filter news by category?',
                    'a' => 'Yes — news is organised by category including Crypto News, Forex News, DeFi & Web3, Market Analysis and Education.',
                ),
            ),

            'market-analysis' => array(
                array(
                    'q' => 'What is AI market analysis?',
                    'a' => $site . ' publishes daily market analysis generated by AI and reviewed by editorial staff, covering crypto and forex price action, trends and macro catalysts.',
                ),
                array(
                    'q' => 'How often is new market analysis published?',
                    'a' => 'A new daily market roundup is published every morning, with intraday updates when major market events occur.',
                ),
                array(
                    'q' => 'Is the market analysis financial advice?',
                    'a' => 'No. All analysis is for informational purposes only and does not constitute investment or financial advice.',
                ),
            ),

            'learn' => array(
                array(
                    'q' => 'Is the crypto and forex education content free?',
                    'a' => 'Yes — all guides, tutorials and glossary content on ' . $site . ' are completely free.',
                ),
                array(
                    'q' => 'What topics are covered in the education section?',
                    'a' => 'Topics include blockchain basics, how to read crypto charts, forex fundamentals, risk management, DeFi protocols, trading psychology and more.',
                ),
                array(
                    'q' => 'Is the content suitable for complete beginners?',
                    'a' => 'Yes — content is written at multiple levels, from absolute beginner guides to intermediate and advanced analysis.',
                ),
            ),

            'tools' => array(
                array(
                    'q' => 'What crypto tools are available?',
                    'a' => $site . ' offers a cryptocurrency converter, Fear & Greed Index, economic calendar, profit/loss calculator and interactive price charts — all free.',
                ),
                array(
                    'q' => 'What is the Crypto Fear & Greed Index?',
                    'a' => 'The Fear & Greed Index scores market sentiment from 0 (Extreme Fear) to 100 (Extreme Greed) based on volatility, market momentum, social media and dominance data.',
                ),
                array(
                    'q' => 'How does the crypto converter work?',
                    'a' => 'Enter any amount in any currency — crypto or fiat — and the converter instantly calculates the equivalent using live exchange rates.',
                ),
            ),
        );

        $slug = $post->post_name ?? '';
        if ( ! isset( $faq_map[ $slug ] ) ) return;

        $qa_list = array_map( function( $pair ) {
            return array(
                '@type'          => 'Question',
                'name'           => $pair['q'],
                'acceptedAnswer' => array(
                    '@type' => 'Answer',
                    'text'  => $pair['a'],
                ),
            );
        }, $faq_map[ $slug ] );

        $schema = array(
            '@context'   => 'https://schema.org',
            '@type'      => 'FAQPage',
            'mainEntity' => $qa_list,
        );

        echo '<script type="application/ld+json">' . wp_json_encode( $schema ) . '</script>' . "\n";
    }

    /**
     * DataFeed schema for live-data pages.
     *
     * Signals to Google that the page contains machine-readable financial data —
     * helpful for Knowledge Graph association and data-rich result eligibility.
     *
     * @since 96.5.0
     */
    public static function output_data_feed_schema() {
        if ( ! is_page() ) return;
        global $post;
        if ( ! is_a( $post, 'WP_Post' ) ) return;

        $site = get_option( 'bt_site_name', 'BlockTicker' );
        $slug = $post->post_name ?? '';

        $feed_map = array(
            'crypto-markets' => array(
                'name'        => 'Live Cryptocurrency Prices — ' . $site,
                'description' => 'Real-time prices, market cap, volume and 24h change for 500+ cryptocurrencies.',
                'about'       => 'Cryptocurrency',
            ),
            'forex-charts' => array(
                'name'        => 'Live Forex Exchange Rates — ' . $site,
                'description' => 'Real-time exchange rates for 170+ currency pairs updated every 5 minutes.',
                'about'       => 'ForeignExchangeMarket',
            ),
        );

        if ( ! isset( $feed_map[ $slug ] ) ) return;

        $def    = $feed_map[ $slug ];
        $schema = array(
            '@context'       => 'https://schema.org',
            '@type'          => 'Dataset',
            'name'           => $def['name'],
            'description'    => $def['description'],
            'url'            => get_permalink(),
            'isAccessibleForFree' => true,
            'temporalCoverage'    => 'Real-time',
            'updateFrequency'     => 'PT5M',  // ISO 8601 duration: every 5 minutes
            'publisher'      => array(
                '@type' => 'Organization',
                'name'  => $site,
                'url'   => home_url(),
            ),
            'license' => 'https://creativecommons.org/licenses/by/4.0/',
        );

        echo '<script type="application/ld+json">' . wp_json_encode( $schema ) . '</script>' . "\n";
    }

    /**
     * Google News eligibility meta tags on single posts.
     *
     * `news_keywords` helps Google News categorise articles correctly.
     * `standout` is reserved for exceptional editorial content (use sparingly).
     *
     * @since 96.5.0
     */
    public static function output_news_meta() {
        if ( ! is_single() ) return;

        // Build keyword string from post tags + categories (max 10 terms).
        $terms = array();

        $tags = get_the_tags( get_the_ID() );
        if ( $tags && ! is_wp_error( $tags ) ) {
            foreach ( array_slice( $tags, 0, 6 ) as $tag ) {
                $terms[] = $tag->name;
            }
        }

        $cats = get_the_category( get_the_ID() );
        if ( $cats && ! is_wp_error( $cats ) ) {
            foreach ( array_slice( $cats, 0, 4 ) as $cat ) {
                if ( 'Uncategorized' === $cat->name ) continue;
                $terms[] = $cat->name;
            }
        }

        if ( $terms ) {
            echo '<meta name="news_keywords" content="' . esc_attr( implode( ', ', array_unique( $terms ) ) ) . '" />' . "\n";
        }

        // Article:modified_time refresh hint — tells crawlers to re-index soon.
        $modified = get_the_modified_date( 'c' );
        if ( $modified ) {
            echo '<meta name="revisit-after" content="1 day" />' . "\n";
        }
    }
}

