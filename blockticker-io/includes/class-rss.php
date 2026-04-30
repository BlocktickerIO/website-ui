<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class BT_RSS {

    /**
     * Source credibility tiers (used by news_composite signal for weighting).
     * Tier 1 = major institutions/wire services; Tier 2 = specialist; Tier 3 = blogs.
     */
    public static $source_tier = array(
        'MarketWatch Top Stories' => 1, 'CNBC'             => 1, 'MarketWatch'      => 1,
        'Federal Reserve'   => 1, 'ECB'              => 1,
        'CoinDesk'          => 2, 'The Block'        => 2, 'Blockworks'       => 2,
        'FXStreet News'     => 2, 'DailyFX'          => 2, 'Investing.com Forex' => 2,
        'BabyPips News'     => 2,
        'CoinTelegraph'     => 2, 'Decrypt'          => 2, 'BeInCrypto'       => 3,
        'CryptoSlate'       => 3, 'NewsBTC'          => 3, 'CryptoNews'       => 3,
        'Bitcoin Magazine'  => 3, 'Ambcrypto'        => 3, 'The Defiant'      => 2,
    );

    private static $feeds = array(
        // ── MACRO / INSTITUTIONAL (v86: new authority tier) ──────────────
        // v96.3: Reuters RSS dead since 2020 — replaced with MarketWatch Top Stories
        array( 'name' => 'MarketWatch Top Stories', 'url' => 'https://feeds.marketwatch.com/marketwatch/topstories/', 'category' => 'Macro & Policy', 'type' => 'news', 'signal_class' => 'macro' ),
        array( 'name' => 'CNBC',                'url' => 'https://search.cnbc.com/rs/search/combinedcombined/view/rss/tag=10001109', 'category' => 'Macro & Policy', 'type' => 'news', 'signal_class' => 'macro' ),
        array( 'name' => 'Federal Reserve',     'url' => 'https://www.federalreserve.gov/feeds/press_all.xml',                 'category' => 'Macro & Policy',  'type' => 'news',    'signal_class' => 'macro' ),
        array( 'name' => 'ECB',                 'url' => 'https://www.ecb.europa.eu/rss/press.html',                           'category' => 'Macro & Policy',  'type' => 'news',    'signal_class' => 'macro' ),

        // ── FOREX NEWS ───────────────────────────────────────────────────
        array( 'name' => 'MarketWatch',         'url' => 'https://feeds.marketwatch.com/marketwatch/topstories/',               'category' => 'Forex News',      'type' => 'news',    'signal_class' => 'macro' ),
        array( 'name' => 'FXStreet News',        'url' => 'https://www.fxstreet.com/rss/news',                                  'category' => 'Forex News',      'type' => 'news',    'signal_class' => 'macro' ),
        // v96.3: Investing.com rate-limits aggressively — replaced with ForexLive
        array( 'name' => 'ForexLive',  'url' => 'https://www.forexlive.com/feed/news', 'category' => 'Forex News', 'type' => 'news', 'signal_class' => 'macro' ),
        array( 'name' => 'DailyFX',              'url' => 'https://www.dailyfx.com/feeds/market-news',                          'category' => 'Forex News',      'type' => 'news',    'signal_class' => 'macro' ),

        // ── CRYPTO NEWS ──────────────────────────────────────────────────
        array( 'name' => 'CoinDesk',             'url' => 'https://www.coindesk.com/arc/outboundfeeds/rss/',                    'category' => 'Crypto News',     'type' => 'news',    'signal_class' => 'crypto' ),
        array( 'name' => 'CoinTelegraph',        'url' => 'https://cointelegraph.com/rss',                                      'category' => 'Crypto News',     'type' => 'news',    'signal_class' => 'crypto' ),
        array( 'name' => 'Bitcoin Magazine',      'url' => 'https://bitcoinmagazine.com/.rss/full/',                             'category' => 'Crypto News',     'type' => 'news',    'signal_class' => 'crypto' ),
        array( 'name' => 'Decrypt',              'url' => 'https://decrypt.co/feed',                                             'category' => 'Crypto News',     'type' => 'news',    'signal_class' => 'crypto' ),
        array( 'name' => 'BeInCrypto',           'url' => 'https://beincrypto.com/feed/',                                       'category' => 'Crypto News',     'type' => 'news',    'signal_class' => 'crypto' ),
        array( 'name' => 'CryptoSlate',          'url' => 'https://cryptoslate.com/feed/',                                      'category' => 'Crypto News',     'type' => 'news',    'signal_class' => 'crypto' ),
        array( 'name' => 'The Block',            'url' => 'https://www.theblock.co/rss.xml',                                    'category' => 'Crypto News',     'type' => 'news',    'signal_class' => 'crypto' ),
        array( 'name' => 'Blockworks',           'url' => 'https://blockworks.co/feed/',                                        'category' => 'Crypto News',     'type' => 'news',    'signal_class' => 'crypto' ),
        array( 'name' => 'NewsBTC',              'url' => 'https://www.newsbtc.com/feed/',                                      'category' => 'Crypto News',     'type' => 'news',    'signal_class' => 'crypto' ),
        array( 'name' => 'CryptoNews',           'url' => 'https://cryptonews.com/news/feed/',                                  'category' => 'Crypto News',     'type' => 'news',    'signal_class' => 'crypto' ),
        array( 'name' => 'Ambcrypto',            'url' => 'https://ambcrypto.com/feed/',                                        'category' => 'Crypto News',     'type' => 'news',    'signal_class' => 'crypto' ),

        // ── DeFi / WEB3 ──────────────────────────────────────────────────
        array( 'name' => 'The Defiant',          'url' => 'https://thedefiant.io/feed',                                         'category' => 'DeFi & Web3',     'type' => 'news',    'signal_class' => 'crypto' ),

        // ── TRADING SIGNALS (diversified — max 1 source per publisher) ───
        array( 'name' => 'FXStreet Analysis',   'url' => 'https://www.fxstreet.com/rss/analysis',                               'category' => 'Trading Signals', 'type' => 'signals', 'signal_class' => 'macro' ),
        array( 'name' => 'DailyFX',             'url' => 'https://www.dailyfx.com/feeds/market-news',                           'category' => 'Trading Signals', 'type' => 'signals', 'signal_class' => 'macro' ),
        array( 'name' => 'BabyPips News',       'url' => 'https://www.babypips.com/news.xml',                                   'category' => 'Trading Signals', 'type' => 'signals', 'signal_class' => 'macro' ),
        array( 'name' => 'CoinDesk Markets',    'url' => 'https://www.coindesk.com/arc/outboundfeeds/rss/category/markets/',    'category' => 'Trading Signals', 'type' => 'signals', 'signal_class' => 'crypto' ),
        // v96.3: Investing.com replaced with ForexLive Analysis in signals feed
        array( 'name' => 'ForexLive Analysis', 'url' => 'https://www.forexlive.com/feed/analysis', 'category' => 'Trading Signals', 'type' => 'signals', 'signal_class' => 'macro' ),
        array( 'name' => 'The Block',           'url' => 'https://www.theblock.co/rss.xml',                                     'category' => 'Trading Signals', 'type' => 'signals', 'signal_class' => 'crypto' ),
        array( 'name' => 'CoinTelegraph Analysis', 'url' => 'https://cointelegraph.com/rss/tag/analysis',                          'category' => 'Trading Signals', 'type' => 'signals', 'signal_class' => 'crypto' ),
        array( 'name' => 'BeInCrypto Analysis',  'url' => 'https://beincrypto.com/feed/',                                         'category' => 'Trading Signals', 'type' => 'signals', 'signal_class' => 'crypto' ),
        array( 'name' => 'NewsBTC Analysis',      'url' => 'https://www.newsbtc.com/feed/',                                        'category' => 'Trading Signals', 'type' => 'signals', 'signal_class' => 'crypto' ),
        array( 'name' => 'Blockworks',            'url' => 'https://blockworks.co/feed/',                                          'category' => 'Trading Signals', 'type' => 'signals', 'signal_class' => 'crypto' ),
    );

    public static function setup() {
        update_option( 'bt_rss_feeds', self::$feeds );
        self::configure_rss_aggregator();

        add_action( 'wp_ajax_fxlm_get_news',        array( __CLASS__, 'ajax_get_news' ) );
        add_action( 'wp_ajax_nopriv_fxlm_get_news', array( __CLASS__, 'ajax_get_news' ) );

        self::fetch_all_feeds();

        return array( 'success' => true, 'message' => 'RSS feeds configured: 24 sources across macro (MarketWatch Top Stories, CNBC, Fed, ECB), forex (FXStreet, DailyFX, ForexLive, MarketWatch), crypto (CoinDesk, The Block, Decrypt, Blockworks, BeInCrypto, CryptoSlate, NewsBTC, CryptoNews, Ambcrypto), and DeFi (The Defiant). Dead feeds replaced: Reuters (404 since 2020) → MarketWatch Top Stories; Investing.com (rate-limited) → ForexLive.' );
    }

    // Called on every page load — lightweight registration only
    public static function register_shortcodes() {
        add_shortcode( 'fxlm_news_feed',    array( __CLASS__, 'sc_news_feed' ) );
        add_shortcode( 'fxlm_signals_feed', array( __CLASS__, 'sc_signals_feed' ) );
        add_shortcode( 'fxlm_breaking_news', array( __CLASS__, 'sc_breaking_news' ) );
        add_shortcode( 'fxlm_blog_posts',   array( __CLASS__, 'sc_blog_posts' ) );

        add_action( 'wp_ajax_fxlm_get_news',        array( __CLASS__, 'ajax_get_news' ) );
        add_action( 'wp_ajax_nopriv_fxlm_get_news', array( __CLASS__, 'ajax_get_news' ) );

        /* v119.8 one-time backfill — dedupe the stored news option without
           waiting for the next hourly cron. Runs once per plugin version. */
        add_action( 'admin_init', array( __CLASS__, 'maybe_dedupe_existing_news' ) );
    }

    /**
     * One-shot dedup of the currently stored bt_news_items option.
     * Triggered once per plugin version; useful when shipping a dedup fix.
     */
    public static function maybe_dedupe_existing_news() {
        $marker = get_option( 'bt_news_dedupe_v' );
        if ( $marker === BT_VERSION ) return;

        $items = get_option( 'bt_news_items' );
        if ( is_array( $items ) && ! empty( $items ) ) {
            $deduped = self::dedupe_news_items( $items );
            if ( count( $deduped ) !== count( $items ) ) {
                update_option( 'bt_news_items', $deduped );
                if ( function_exists( 'error_log' ) ) {
                    error_log( '[BlockTicker v119.8] News dedup: ' . count( $items ) . ' -> ' . count( $deduped ) . ' items' );
                }
            }
        }
        update_option( 'bt_news_dedupe_v', BT_VERSION );
    }

    public static function sc_blog_posts( $atts ) {
        $a = shortcode_atts( array( 'count' => 12, 'layout' => 'grid', 'category' => '' ), $atts );

        // v80: if a URL ?cat= param is present and matches a known category, it overrides
        // the shortcode attribute. Enables server-side tab filtering on the blog page.
        // (sanitized + validated against registered categories below so random ?cat=foo
        // doesn't trigger a DB query on a non-existent term.)
        if ( ! empty( $_GET['cat'] ) && ( $a['layout'] === 'magazine' || empty( $a['category'] ) ) ) {
            $url_cat = sanitize_title( wp_unslash( $_GET['cat'] ) );
            if ( $url_cat && get_term_by( 'slug', $url_cat, 'category' ) ) {
                $a['category'] = $url_cat;
            }
        }

        $base_query = array(
            'numberposts' => intval( $a['count'] ),
            'post_status' => 'publish',
            'orderby'     => 'date',
            'order'       => 'DESC',
        );
        $query_args = $base_query;
        if ( ! empty( $a['category'] ) ) {
            // Support comma-separated category list: "market-analysis,crypto-news"
            $cats = array_filter( array_map( 'sanitize_title', explode( ',', $a['category'] ) ) );
            if ( count( $cats ) === 1 ) {
                $query_args['category_name'] = $cats[0];
            } elseif ( count( $cats ) > 1 ) {
                // Get category IDs for the IN query
                $cat_ids = array();
                foreach ( $cats as $cat_slug ) {
                    $term = get_term_by( 'slug', $cat_slug, 'category' );
                    if ( $term ) $cat_ids[] = $term->term_id;
                }
                if ( ! empty( $cat_ids ) ) {
                    $query_args['category__in'] = $cat_ids;
                }
            }
        }
        $posts = get_posts( $query_args );

        // v59: If a category filter was applied but returned zero posts, check whether
        // any posts exist at all. If yes, show those (the AI may have tagged posts with
        // categories that don't match the filter exactly — e.g. a post tagged only
        // "Altcoins" won't match a "market-analysis" filter). We display a soft notice
        // so the user knows why they're seeing a broader set.
        $used_fallback = false;
        if ( empty( $posts ) && ! empty( $a['category'] ) ) {
            $all_posts = get_posts( $base_query );
            if ( ! empty( $all_posts ) ) {
                $posts = $all_posts;
                $used_fallback = true;
            }
        }

        // NO blind fallback to all posts — that made Blog == Analysis on fresh sites.
        // Instead show a helpful empty state specific to the category set.

        if ( empty( $posts ) ) {
            // Contextual empty state based on category type
            $is_analysis = ! empty( $a['category'] ) && preg_match( '/analysis|news/i', $a['category'] );
            if ( $is_analysis ) {
                return '<div class="bt-blog-emptier">
                    <div class="bt-blog-emptier-icon">🤖</div>
                    <h3>AI Analysis Coming Online</h3>
                    <p>Our AI analyst publishes market reports daily at 08:00 UTC. The live data widgets on this page show the current market state used for tomorrow\'s analysis. Check back in a few hours, or explore published posts below.</p>
                    <div class="bt-blog-emptier-ctas">
                        <a href="' . esc_url( home_url( '/blog/' ) ) . '" class="bt-blog-emptier-cta bt-blog-emptier-cta-primary">Browse published posts →</a>
                        <a href="' . esc_url( home_url( '/' ) ) . '" class="bt-blog-emptier-cta bt-blog-emptier-cta-ghost">Today\'s Intelligence Brief</a>
                    </div>
                </div>';
            } else {
                return '<div class="bt-blog-emptier">
                    <div class="bt-blog-emptier-icon">📚</div>
                    <h3>Articles Publishing Soon</h3>
                    <p>Our editorial AI publishes new guides, tutorials and market explainers throughout the week. While it warms up, dive into the live dashboards or browse what we\'ve already published.</p>
                    <div class="bt-blog-emptier-ctas">
                        <a href="' . esc_url( home_url( '/crypto-markets/' ) ) . '" class="bt-blog-emptier-cta bt-blog-emptier-cta-primary">See crypto markets →</a>
                        <a href="' . esc_url( home_url( '/forex-charts/' ) ) . '" class="bt-blog-emptier-cta bt-blog-emptier-cta-ghost">See forex rates</a>
                    </div>
                </div>';
            }
        }

        // 'magazine' layout = Financial Broadsheet editorial grid — v80 revamp.
        // Serif titles, left accent bars, mono rubrics, editorial datelines.
        // Lead story spans full width; secondaries in 3-col grid below.
        if ( $a['layout'] === 'magazine' ) {
            ob_start();
            if ( $used_fallback ) {
                echo '<div class="bt-bs-fallback-notice">Showing all recent posts — none were tagged with the filter categories yet. New posts will appear here as soon as the AI autopilot publishes them.</div>';
            }
            // Category accent colors (2pt left bar + rubric color)
            $cat_colors = array(
                'market-analysis' => 'var(--bt-accent)', 'crypto-news' => '#f7931a', 'forex-news' => 'var(--bt-accent)',
                'defi-web3' => '#a78bfa', 'altcoins' => 'var(--bt-accent-warm)', 'education' => '#10b981',
                'bitcoin' => '#f7931a', 'ethereum' => '#627eea', 'news' => 'var(--bt-text-3)',
            );

            // Lead story (first post) gets full-width treatment
            $lead = array_shift( $posts );
            $issue_num = intval( get_option( 'bt_blog_issue_num', 0 ) );
            if ( $issue_num < 1 ) $issue_num = count( $posts ) + 1; // fallback when option not set
            $today_fmt = date( 'D · M j, Y', current_time( 'timestamp' ) );

            echo '<div class="bt-bs-wrap">';

            // ── Lead story (spans full width) ────────────────────────────
            if ( $lead ) {
                $img      = get_the_post_thumbnail_url( $lead->ID, 'large' );
                $url      = get_permalink( $lead->ID );
                $date_ts  = get_the_time( 'U', $lead );
                $date_fmt = date( 'D · M j', $date_ts );
                $excerpt  = wp_trim_words( strip_tags( $lead->post_content ), 36, '…' );
                $cats     = get_the_category( $lead->ID );
                $cat_name = ! empty( $cats ) ? $cats[0]->name : 'Analysis';
                $cat_slug = ! empty( $cats ) ? $cats[0]->slug : 'market-analysis';
                $col      = $cat_colors[ $cat_slug ] ?? 'var(--bt-accent)';
                $read_min = max( 1, round( str_word_count( strip_tags( $lead->post_content ) ) / 200 ) );
                $rubric   = self::cat_rubric( $cat_slug, $cat_name );

                echo '<article class="bt-bs-lead" style="--bs-accent:' . esc_attr($col) . '" itemscope itemtype="https://schema.org/Article">';
                echo '<meta itemprop="datePublished" content="' . esc_attr( date( 'c', $date_ts ) ) . '">';
                echo '<meta itemprop="author" content="BlockTicker">';

                echo '<a href="' . esc_url($url) . '" class="bt-bs-lead-thumb" aria-label="' . esc_attr($lead->post_title) . '">';
                if ( $img ) {
                    echo '<img src="' . esc_url($img) . '" alt="' . esc_attr($lead->post_title) . '" loading="eager" itemprop="image">';
                } else {
                    echo '<div class="bt-bs-thumb-fallback" style="background-color:' . esc_attr($col) . '14">';
                    echo '<span class="bt-bs-thumb-mark" style="color:' . esc_attr($col) . '">' . esc_html( self::cat_code( $cat_slug ) ) . '</span>';
                    echo '</div>';
                }
                echo '</a>';

                echo '<div class="bt-bs-lead-body">';
                echo '<div class="bt-bs-lead-tag">LEAD STORY</div>';
                echo '<div class="bt-bs-rubric" style="color:' . esc_attr($col) . '">' . esc_html( $rubric ) . '</div>';
                echo '<h2 class="bt-bs-lead-title" itemprop="headline"><a href="' . esc_url($url) . '">' . esc_html($lead->post_title) . '</a></h2>';
                echo '<p class="bt-bs-lead-excerpt" itemprop="description">' . esc_html($excerpt) . '</p>';
                echo '<div class="bt-bs-meta">';
                echo '<time datetime="' . esc_attr( date( 'c', $date_ts ) ) . '">' . esc_html( $date_fmt ) . '</time>';
                echo '<span class="bt-bs-meta-sep">—</span>';
                echo '<span>' . $read_min . ' MIN READ</span>';
                echo '</div>';
                echo '</div>';
                echo '</article>';
            }

            // ── Secondary grid (3-col at desktop) ─────────────────────────
            echo '<div class="bt-bs-grid">';
            foreach ( $posts as $post ) {
                $img      = get_the_post_thumbnail_url( $post->ID, 'medium_large' );
                $url      = get_permalink( $post->ID );
                $date_ts  = get_the_time( 'U', $post );
                $date_fmt = date( 'D · M j', $date_ts );
                $excerpt  = wp_trim_words( strip_tags( $post->post_content ), 20, '…' );
                $cats     = get_the_category( $post->ID );
                $cat_name = ! empty( $cats ) ? $cats[0]->name : 'Analysis';
                $cat_slug = ! empty( $cats ) ? $cats[0]->slug : 'market-analysis';
                $col      = $cat_colors[ $cat_slug ] ?? 'var(--bt-accent)';
                $read_min = max( 1, round( str_word_count( strip_tags( $post->post_content ) ) / 200 ) );
                $rubric   = self::cat_rubric( $cat_slug, $cat_name );

                echo '<article class="bt-bs-card" style="--bs-accent:' . esc_attr($col) . '" data-cat="' . esc_attr($cat_slug) . '" itemscope itemtype="https://schema.org/Article">';
                echo '<meta itemprop="datePublished" content="' . esc_attr( date( 'c', $date_ts ) ) . '">';
                echo '<meta itemprop="author" content="BlockTicker">';

                echo '<a href="' . esc_url($url) . '" class="bt-bs-card-thumb" aria-label="' . esc_attr($post->post_title) . '">';
                if ( $img ) {
                    echo '<img src="' . esc_url($img) . '" alt="' . esc_attr($post->post_title) . '" loading="lazy" itemprop="image">';
                } else {
                    echo '<div class="bt-bs-thumb-fallback" style="background-color:' . esc_attr($col) . '14">';
                    echo '<span class="bt-bs-thumb-mark" style="color:' . esc_attr($col) . '">' . esc_html( self::cat_code( $cat_slug ) ) . '</span>';
                    echo '</div>';
                }
                echo '</a>';

                echo '<div class="bt-bs-card-body">';
                echo '<div class="bt-bs-rubric" style="color:' . esc_attr($col) . '">' . esc_html( $rubric ) . '</div>';
                echo '<h3 class="bt-bs-card-title" itemprop="headline"><a href="' . esc_url($url) . '">' . esc_html($post->post_title) . '</a></h3>';
                echo '<p class="bt-bs-card-excerpt" itemprop="description">' . esc_html($excerpt) . '</p>';
                echo '<div class="bt-bs-meta">';
                echo '<time datetime="' . esc_attr( date( 'c', $date_ts ) ) . '">' . esc_html( $date_fmt ) . '</time>';
                echo '<span class="bt-bs-meta-sep">—</span>';
                echo '<span>' . $read_min . ' MIN</span>';
                echo '</div>';
                echo '</div>';
                echo '</article>';
            }
            echo '</div>'; // bt-bs-grid
            echo '</div>'; // bt-bs-wrap
            return ob_get_clean();
        }

        // 'cards' layout = uniform 3-col grid, no featured treatment (ideal for homepage sections)
        if ( $a['layout'] === 'cards' ) {
            ob_start();
            echo '<div class="fxlm-blog-cards-grid">';
            foreach ( $posts as $post ) {
                $img        = get_the_post_thumbnail_url( $post->ID, 'medium' );
                $url        = get_permalink( $post->ID );
                $date_ts    = get_the_time( 'U', $post );
                $time_ago   = human_time_diff( $date_ts, current_time( 'timestamp' ) ) . ' ago';
                $excerpt    = wp_trim_words( strip_tags( $post->post_content ), 18, '…' );
                $cats       = get_the_category( $post->ID );
                $cat_name   = ! empty( $cats ) ? $cats[0]->name : 'Analysis';
                $cat_slug   = ! empty( $cats ) ? $cats[0]->slug : 'market-analysis';
                $cat_colors = array( 'market-analysis'=>'var(--bt-accent)','crypto-news'=>'#f7931a','forex-news'=>'var(--bt-accent)','defi-web3'=>'#a78bfa','altcoins'=>'var(--bt-accent-warm)','education'=>'#10b981','bitcoin'=>'#f7931a','ethereum'=>'#627eea' );
                $col        = $cat_colors[ $cat_slug ] ?? 'var(--bt-accent)';
                $read_min   = max( 1, round( str_word_count( strip_tags( $post->post_content ) ) / 200 ) );

                echo '<article class="fxlm-blog-compact-card" itemscope itemtype="https://schema.org/Article">';
                echo '<meta itemprop="datePublished" content="' . esc_attr( date( 'c', $date_ts ) ) . '">';
                if ( $img ) {
                    echo '<a href="' . esc_url($url) . '" class="fxlm-bcc-img-wrap">';
                    echo '<img src="' . esc_url($img) . '" alt="' . esc_attr($post->post_title) . '" loading="lazy" itemprop="image">';
                    echo '</a>';
                } else {
                    echo '<a href="' . esc_url($url) . '" class="fxlm-bcc-img-wrap fxlm-bcc-no-img" style="background:linear-gradient(135deg,' . esc_attr($col) . '18,' . esc_attr($col) . '06)">';
                    echo '<span style="font-size:32px">' . self::cat_emoji($cat_slug) . '</span>';
                    echo '</a>';
                }
                echo '<div class="fxlm-bcc-body">';
                echo '<div class="fxlm-bcc-meta"><span style="background:' . esc_attr($col) . '20;color:' . esc_attr($col) . ';border:1px solid ' . esc_attr($col) . '30;border-radius:0;font-size:10px;font-weight:700;padding:2px 7px;text-transform:uppercase">' . esc_html($cat_name) . '</span></div>';
                echo '<h3 class="fxlm-bcc-title" itemprop="headline"><a href="' . esc_url($url) . '">' . esc_html($post->post_title) . '</a></h3>';
                echo '<p class="fxlm-bcc-excerpt">' . esc_html($excerpt) . '</p>';
                echo '<div class="fxlm-bcc-footer"><span>🕐 ' . $time_ago . '</span><span>' . $read_min . ' min read</span><a href="' . esc_url($url) . '" data-i18n="section.read_more">Read More →</a></div>';
                echo '</div>';
                echo '</article>';
            }
            echo '</div>';
            return ob_get_clean();
        }

        ob_start();
        echo '<div class="fxlm-blog-grid">';
        foreach ( $posts as $i => $post ) {
            $img        = get_the_post_thumbnail_url( $post->ID, 'medium_large' );
            $url        = get_permalink( $post->ID );
            $date_ts    = get_the_time( 'U', $post );
            $date_fmt   = get_the_date( 'M j, Y', $post->ID );
            $time_ago   = human_time_diff( $date_ts, current_time( 'timestamp' ) ) . ' ago';
            $excerpt    = wp_trim_words( strip_tags( $post->post_content ), 28, '…' );
            $cats       = get_the_category( $post->ID );
            $cat_name   = ! empty( $cats ) ? $cats[0]->name : 'Market Analysis';
            $cat_slug   = ! empty( $cats ) ? $cats[0]->slug : 'market-analysis';
            $is_featured = ( $i === 0 );
            $cat_colors = array(
                'market-analysis' => 'var(--bt-accent)',
                'crypto-news'     => '#f7931a',
                'forex-news'      => 'var(--bt-accent)',
                'defi-web3'       => '#a78bfa',
                'altcoins'        => 'var(--bt-accent-warm)',
                'education'       => '#10b981',
                'bitcoin'         => '#f7931a',
                'ethereum'        => '#627eea',
            );
            $cat_color = $cat_colors[ $cat_slug ] ?? 'var(--bt-accent)';

            // Reading time estimate
            $word_count   = str_word_count( strip_tags( $post->post_content ) );
            $reading_time = max( 1, round( $word_count / 200 ) );

            if ( $is_featured ) {
                // Featured (first post) — large card spanning 2 columns
                echo '<article class="fxlm-blog-card fxlm-blog-featured" itemscope itemtype="https://schema.org/Article">';
                echo '<meta itemprop="datePublished" content="' . esc_attr( date( 'c', $date_ts ) ) . '">';
                echo '<meta itemprop="author" content="BlockTicker">';
                if ( $img ) {
                    echo '<a href="' . esc_url($url) . '" class="fxlm-blog-img-wrap" itemprop="url">';
                    echo '<img src="' . esc_url($img) . '" alt="' . esc_attr($post->post_title) . '" class="fxlm-blog-img" loading="lazy" itemprop="image">';
                    echo '<div class="fxlm-blog-img-overlay"></div>';
                    echo '</a>';
                } else {
                    echo '<a href="' . esc_url($url) . '" class="fxlm-blog-img-wrap fxlm-blog-no-img" style="background:linear-gradient(135deg,' . esc_attr($cat_color) . '22,' . esc_attr($cat_color) . '06);min-height:160px;display:flex;align-items:center;padding:28px 24px;gap:18px">';
                    echo '<span style="font-size:56px;line-height:1;flex-shrink:0">' . self::cat_emoji($cat_slug) . '</span>';
                    echo '<div style="font-size:18px;font-weight:800;color:' . esc_attr($cat_color) . ';line-height:1.3">' . esc_html(wp_trim_words($post->post_title, 12, '…')) . '</div>';
                    echo '</a>';
                }
                echo '<div class="fxlm-blog-meta">';
                echo '<span class="fxlm-blog-cat" style="background:' . esc_attr($cat_color) . '20;color:' . esc_attr($cat_color) . ';border-color:' . esc_attr($cat_color) . '40">' . esc_html($cat_name) . '</span>';
                echo '<span class="fxlm-blog-badge">⭐ Featured</span>';
                echo '</div>';
                echo '<h2 class="fxlm-blog-title" itemprop="headline"><a href="' . esc_url($url) . '">' . esc_html($post->post_title) . '</a></h2>';
                echo '<p class="fxlm-blog-excerpt" itemprop="description">' . esc_html($excerpt) . '</p>';
                echo '<div class="fxlm-blog-footer">';
                echo '<span class="fxlm-blog-date" title="' . esc_attr($date_fmt) . '">🕐 ' . $time_ago . '</span>';
                echo '<span class="fxlm-blog-read">' . $reading_time . ' min read</span>';
                echo '<a href="' . esc_url($url) . '" class="fxlm-blog-cta">Read More →</a>';
                echo '</div>';
                echo '</div>';
                echo '</article>';
            } else {
                // Regular card
                echo '<article class="fxlm-blog-card" itemscope itemtype="https://schema.org/Article">';
                echo '<meta itemprop="datePublished" content="' . esc_attr( date( 'c', $date_ts ) ) . '">';
                echo '<meta itemprop="author" content="BlockTicker">';
                if ( $img ) {
                    echo '<a href="' . esc_url($url) . '" class="fxlm-blog-img-wrap">';
                    echo '<img src="' . esc_url($img) . '" alt="' . esc_attr($post->post_title) . '" class="fxlm-blog-img" loading="lazy" itemprop="image">';
                    echo '<div class="fxlm-blog-img-overlay"></div>';
                    echo '</a>';
                } else {
                    // No image — show styled gradient header instead
                    echo '<a href="' . esc_url($url) . '" class="fxlm-blog-img-wrap fxlm-blog-no-img" style="background:linear-gradient(135deg,' . esc_attr($cat_color) . '18,' . esc_attr($cat_color) . '08);border-bottom:1px solid ' . esc_attr($cat_color) . '20">';
                    echo '<div style="padding:24px 20px;display:flex;align-items:center;gap:12px">';
                    echo '<span style="font-size:36px;flex-shrink:0">' . self::cat_emoji($cat_slug) . '</span>';
                    echo '<div style="font-size:13px;font-weight:700;color:' . esc_attr($cat_color) . ';line-height:1.4">' . esc_html(wp_trim_words($post->post_title, 10, '…')) . '</div>';
                    echo '</div></a>';
                }
                echo '<div class="fxlm-blog-body">';
                echo '<div class="fxlm-blog-meta">';
                echo '<span class="fxlm-blog-cat" style="background:' . esc_attr($cat_color) . '20;color:' . esc_attr($cat_color) . ';border-color:' . esc_attr($cat_color) . '40">' . esc_html($cat_name) . '</span>';
                echo '</div>';
                echo '<h3 class="fxlm-blog-title" itemprop="headline"><a href="' . esc_url($url) . '">' . esc_html($post->post_title) . '</a></h3>';
                echo '<p class="fxlm-blog-excerpt" itemprop="description">' . esc_html($excerpt) . '</p>';
                echo '<div class="fxlm-blog-footer">';
                echo '<span class="fxlm-blog-date" title="' . esc_attr($date_fmt) . '">🕐 ' . $time_ago . '</span>';
                echo '<span class="fxlm-blog-read">' . $reading_time . ' min read</span>';
                echo '</div>';
                echo '</div>';
                echo '</article>';
            }
        }
        echo '</div>';
        return ob_get_clean();
    }

    public static function setup_cron() {
        // Prices — every 5 min
        if ( ! wp_next_scheduled( 'bt_refresh_prices' ) ) {
            wp_schedule_event( time(), 'bt_five_minutes', 'bt_refresh_prices' );
        }
        // Signals — every 15 min
        if ( ! wp_next_scheduled( 'bt_refresh_signals' ) ) {
            wp_schedule_event( time(), 'bt_fifteen_minutes', 'bt_refresh_signals' );
        }
        // News — every hour
        if ( ! wp_next_scheduled( 'bt_refresh_news' ) ) {
            wp_schedule_event( time(), 'bt_hourly', 'bt_refresh_news' );
        }
        // AI posts — twice daily
        if ( ! wp_next_scheduled( 'bt_ai_posts' ) ) {
            wp_schedule_event( time(), 'bt_twice_daily', 'bt_ai_posts' );
        }
        // Fear & Greed — hourly
        if ( ! wp_next_scheduled( 'bt_refresh_fng' ) ) {
            wp_schedule_event( time(), 'bt_hourly', 'bt_refresh_fng' );
        }

        return array( 'success' => true, 'message' => 'Cron jobs scheduled: prices/5min, signals/15min, news/1hr, AI posts/12hr, Fear&Greed/1hr.' );
    }

    public static function fetch_all_feeds() {
        $items = array();
        foreach ( self::$feeds as $feed ) {
            if ( $feed['type'] !== 'news' ) continue;
            $fetched = self::fetch_feed( $feed['url'], $feed['name'], $feed['category'] );
            $items   = array_merge( $items, $fetched );
        }

        /* v119.8: Deduplicate news items.
           Many feeds syndicate the same story (MarketWatch + DowJones + WSJ
           reposts; CoinDesk + The Block reprints, etc.). Without dedup the
           news grid shows the same article 2-3 times in a row.
           Strategy:
             1) Hash normalised URL (strip query strings + fragments)
             2) Fall back to fingerprint of normalised title
             3) Keep the earliest-timestamped copy (original) over a republish */
        $items = self::dedupe_news_items( $items );

        usort( $items, function( $a, $b ) { return $b['timestamp'] - $a['timestamp']; } );

        // Tag breaking news (items < 2 hours old from major sources)
        $breaking_sources = array( 'CoinDesk', 'CoinTelegraph', 'MarketWatch Top Stories', 'The Block' );
        foreach ( $items as &$item ) {
            $item['breaking'] = ( in_array( $item['source'], $breaking_sources ) && ( time() - $item['timestamp'] ) < 7200 );
        }
        unset( $item );

        update_option( 'bt_news_items', array_slice( $items, 0, 150 ) );
        update_option( 'bt_news_updated', time() );
    }

    /**
     * Deduplicate news items by URL and title fingerprint.
     * Keeps the oldest copy of each article (the original publication, not the syndication).
     *
     * @param array $items Raw news items.
     * @return array Deduped items.
     */
    private static function dedupe_news_items( $items ) {
        if ( empty( $items ) ) return $items;

        $seen_urls   = array();   // normalised URL → array index
        $seen_titles = array();   // title fingerprint → array index
        $output      = array();

        foreach ( $items as $item ) {
            if ( empty( $item['link'] ) || empty( $item['title'] ) ) continue;

            // Normalise URL: strip query string, fragment, lowercase host
            $url      = strtok( $item['link'], '?#' );  // strips ?... and #...
            $url_norm = strtolower( rtrim( $url, '/' ) );
            $url_hash = md5( $url_norm );

            // Normalise title: lowercase, strip punctuation/whitespace
            $title_norm = strtolower( $item['title'] );
            $title_norm = preg_replace( '/[^a-z0-9]+/u', '', $title_norm );
            // Truncate to first 80 chars to allow trailing "(updated)" / "[VIDEO]" suffixes
            $title_norm = substr( $title_norm, 0, 80 );
            $title_hash = md5( $title_norm );

            // Already seen by exact URL? Skip.
            if ( isset( $seen_urls[ $url_hash ] ) ) continue;

            // Already seen by title? Keep the earlier (original) one.
            if ( isset( $seen_titles[ $title_hash ] ) ) {
                $existing_idx = $seen_titles[ $title_hash ];
                $existing     = $output[ $existing_idx ];
                // Keep whichever has earlier timestamp (more likely the original)
                if ( $item['timestamp'] < $existing['timestamp'] ) {
                    $output[ $existing_idx ] = $item;
                    $seen_urls[ $url_hash ]  = $existing_idx;
                }
                continue;
            }

            // New item — keep it
            $output[]                   = $item;
            $idx                        = count( $output ) - 1;
            $seen_urls[ $url_hash ]     = $idx;
            $seen_titles[ $title_hash ] = $idx;
        }

        return $output;
    }

    public static function fetch_signal_feeds() {
        $items = array();
        foreach ( self::$feeds as $feed ) {
            if ( $feed['type'] !== 'signals' ) continue;
            $fetched = self::fetch_feed( $feed['url'], $feed['name'], $feed['category'] );
            $items   = array_merge( $items, $fetched );
        }
        update_option( 'bt_signal_items', array_slice( $items, 0, 50 ) );
    }

    private static function fetch_feed( $url, $name, $category ) {
        $response = wp_remote_get( $url, array( 'timeout' => 15, 'user-agent' => 'BlockTicker/41.0 RSS Reader' ) );
        if ( is_wp_error( $response ) ) {
            // Only log feed errors once per day per URL to avoid log spam
            $err_key = 'bt_feed_err_' . md5( $url );
            if ( ! get_transient( $err_key ) ) {
                error_log( 'BlockTicker RSS error: ' . $response->get_error_message() . ' — ' . $name );
                set_transient( $err_key, 1, DAY_IN_SECONDS );
            }
            return array();
        }
        $http_code = wp_remote_retrieve_response_code( $response );
        if ( $http_code !== 200 ) {
            return array();
        }
        $body = wp_remote_retrieve_body( $response );
        if ( empty( $body ) ) return array();

        libxml_use_internal_errors( true );
        $xml = simplexml_load_string( $body );
        if ( ! $xml ) return array();

        $items   = array();
        $channel = $xml->channel ?? $xml;

        foreach ( $channel->item as $item ) {
            $title       = (string) $item->title;
            $link        = (string) $item->link;
            $description = strip_tags( (string) $item->description );
            $pub_date    = (string) $item->pubDate;
            $timestamp   = $pub_date ? strtotime( $pub_date ) : time();
            $image       = '';

            // Try media:content or enclosure for image
            $media = $item->children( 'media', true );
            if ( $media && isset( $media->content ) ) {
                $attrs = $media->content->attributes();
                $image = (string) ( $attrs['url'] ?? '' );
            }
            if ( ! $image && isset( $item->enclosure ) ) {
                $enc   = $item->enclosure->attributes();
                $image = (string) ( $enc['url'] ?? '' );
            }
            // Try media:thumbnail
            if ( ! $image && $media && isset( $media->thumbnail ) ) {
                $attrs = $media->thumbnail->attributes();
                $image = (string) ( $attrs['url'] ?? '' );
            }

            if ( $title && $link ) {
                $items[] = array(
                    'title'       => sanitize_text_field( $title ),
                    'link'        => esc_url_raw( $link ),
                    'description' => wp_trim_words( sanitize_text_field( $description ), 30 ),
                    'source'      => $name,
                    'category'    => $category,
                    'timestamp'   => $timestamp,
                    'image'       => esc_url_raw( $image ),
                    'breaking'    => false,
                );
            }
        }
        return $items;
    }

    public static function generate_ai_post() {
        if ( class_exists( 'WPAICG_PostGenerator' ) ) {
            do_action( 'wpaicg_generate_post', array(
                'topic'    => 'Latest forex and cryptocurrency market analysis for ' . date( 'F j, Y' ),
                'category' => 'Market Analysis',
                'length'   => 1200,
            ) );
        } else {
            $news   = BT_Widgets::get_json_option( 'fxlm_news_items' );
            $recent = array_slice( $news, 0, 8 );
            if ( empty( $recent ) ) return;

            $title   = 'Market Roundup: ' . date( 'F j, Y' );
            $content = '<p>Here is today\'s automated market roundup from our financial news feeds.</p>';

            // Group by category
            $by_cat = array();
            foreach ( $recent as $item ) {
                $by_cat[ $item['category'] ][] = $item;
            }
            foreach ( $by_cat as $cat => $cat_items ) {
                $content .= '<h3>' . esc_html( $cat ) . '</h3><ul>';
                foreach ( $cat_items as $item ) {
                    $content .= '<li><a href="' . esc_url( $item['link'] ) . '" target="_blank" rel="noopener">' . esc_html( $item['title'] ) . '</a> — <small>' . esc_html( $item['source'] ) . '</small></li>';
                }
                $content .= '</ul>';
            }

            // Add Fear & Greed snapshot
            $fng = BT_Widgets::get_json_option( 'fxlm_fear_greed_data' );
            if ( ! empty( $fng['data'][0] ) ) {
                $content .= '<p><strong>Fear &amp; Greed Index:</strong> ' . intval( $fng['data'][0]['value'] ) . ' (' . esc_html( $fng['data'][0]['value_classification'] ) . ')</p>';
            }

            $existing_q = new WP_Query( array(
                'post_type'      => 'post',
                'post_status'    => 'publish',
                'title'          => $title,
                'posts_per_page' => 1,
                'no_found_rows'  => true,
                'fields'         => 'ids',
            ) );
            $existing = ! empty( $existing_q->posts ) ? get_post( $existing_q->posts[0] ) : null;
            wp_reset_postdata();
            if ( ! $existing ) {
                $cat_id  = get_cat_ID( 'Market Analysis' );
                $post_id = wp_insert_post( array(
                    'post_title'    => $title,
                    'post_content'  => $content,
                    'post_status'   => 'publish',
                    'post_type'     => 'post',
                    'post_category' => $cat_id ? array( $cat_id ) : array(),
                ) );

                if ( $post_id && ! is_wp_error( $post_id ) ) {
                    foreach ( $recent as $item ) {
                        if ( ! empty( $item['image'] ) ) {
                            $thumbnail_id = self::sideload_image( $item['image'], $post_id, $item['title'] );
                            if ( $thumbnail_id && ! is_wp_error( $thumbnail_id ) ) {
                                set_post_thumbnail( $post_id, $thumbnail_id );
                                break;
                            }
                        }
                    }
                }
            }
        }
    }

    private static function sideload_image( $url, $post_id, $title = '' ) {
        if ( empty( $url ) ) return false;

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp  = download_url( $url, 15 );
        if ( is_wp_error( $tmp ) ) return false;

        $ext  = pathinfo( parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION );
        $ext  = in_array( strtolower( $ext ), array( 'jpg', 'jpeg', 'png', 'gif', 'webp' ) ) ? $ext : 'jpg';

        $file = array(
            'name'     => sanitize_title( $title ) . '.' . $ext,
            'type'     => 'image/' . $ext,
            'tmp_name' => $tmp,
            'error'    => 0,
            'size'     => filesize( $tmp ),
        );

        $id = media_handle_sideload( $file, $post_id, $title );
        @unlink( $tmp );
        return $id;
    }

    public static function configure_rss_aggregator() {
        if ( ! function_exists( 'wprss_insert_feed_source' ) ) return;

        foreach ( self::$feeds as $feed ) {
            $existing = get_posts( array(
                'post_type'  => 'wprss_feed',
                'meta_key'   => 'wprss_url',
                'meta_value' => $feed['url'],
                'numberposts' => 1,
            ) );
            if ( ! empty( $existing ) ) continue;

            $post_id = wp_insert_post( array(
                'post_title'  => $feed['name'],
                'post_type'   => 'wprss_feed',
                'post_status' => 'publish',
            ) );
            if ( $post_id && ! is_wp_error( $post_id ) ) {
                update_post_meta( $post_id, 'wprss_url',           $feed['url'] );
                update_post_meta( $post_id, 'wprss_feed_category', $feed['category'] );
                update_post_meta( $post_id, 'wprss_limit',         20 );
            }
        }
    }

    // ── SHORTCODES ──

    public static function sc_news_feed( $atts ) {
        // v119.28.13 — accept friendly param aliases (limit/sort/show_source) and
        // alias category keys ("crypto" → "Crypto News", etc.) so the same
        // shortcode works whether called from the landing template or from the
        // landing-revamp page provisioner. Default layout is now "premium" so
        // every news page gets thumbnails + source badges by default.
        $a = shortcode_atts( array(
            'count'       => 10,
            'limit'       => '',     // alias for count
            'category'    => '',
            'show_images' => 'false',
            'show_source' => '',     // accepted (no-op — premium layout always shows source)
            'sort'        => '',     // accepted: 'popular' | 'recent' (default recent)
            'layout'      => 'premium',
        ), $atts );

        // limit takes precedence if provided
        if ( $a['limit'] !== '' ) { $a['count'] = intval( $a['limit'] ); }

        // Category aliases — landing-revamp uses "crypto", "forex", "macro",
        // "web3", "defi", "earnings", "regulation"; the stored category labels
        // on each news item are "Crypto News", "Forex News", "DeFi & Web3",
        // "Macro & Policy", "Trading Signals", "Market Analysis".
        $cat_alias = array(
            'crypto'         => array( 'crypto news' ),
            'forex'          => array( 'forex news' ),
            'macro'          => array( 'macro & policy' ),
            'macro & policy' => array( 'macro & policy' ),
            'web3'           => array( 'defi & web3' ),
            'defi'           => array( 'defi & web3' ),
            'defi & web3'    => array( 'defi & web3' ),
            'signals'        => array( 'trading signals' ),
            'analysis'       => array( 'market analysis', 'trading signals' ),
            // No exact mapping — earnings/regulation reuse the closest pool so
            // the page renders something rather than the empty-state.
            'earnings'       => array( 'macro & policy', 'trading signals' ),
            'regulation'     => array( 'macro & policy' ),
            'breaking'       => array( 'crypto news', 'forex news', 'macro & policy' ),
        );

        $items = BT_Widgets::get_json_option( 'fxlm_news_items' );

        if ( ! empty( $a['category'] ) ) {
            $needle = strtolower( trim( $a['category'] ) );
            $allowed = isset( $cat_alias[ $needle ] ) ? $cat_alias[ $needle ] : array( $needle );
            $items = array_filter( $items, function( $i ) use ( $allowed ) {
                return in_array( strtolower( $i['category'] ?? '' ), $allowed, true );
            });
        }

        // Sorting (popular = treat clicks ?? timestamp; recent = timestamp desc)
        if ( ! empty( $items ) ) {
            $items = array_values( $items );
            if ( strtolower( $a['sort'] ) === 'popular' ) {
                usort( $items, function( $x, $y ) {
                    $xc = intval( $x['clicks'] ?? 0 );
                    $yc = intval( $y['clicks'] ?? 0 );
                    if ( $xc === $yc ) {
                        return intval( $y['timestamp'] ?? 0 ) <=> intval( $x['timestamp'] ?? 0 );
                    }
                    return $yc <=> $xc;
                });
            }
        }

        if ( empty( $items ) ) {
            // v119.28.13 — fall back gracefully: rather than a useless "loading"
            // string, show top headlines from any category so the page is
            // never blank. Helpful for pages like /news/earnings/ where the
            // exact alias may not match any stored item.
            $fallback = BT_Widgets::get_json_option( 'fxlm_news_items' );
            if ( ! empty( $fallback ) ) {
                $items = array_slice( array_values( $fallback ), 0, intval( $a['count'] ) );
            } else {
                return '<div class="fxlm-loading" style="padding:32px;text-align:center;color:var(--bt-text-3,#94a3b8);background:rgba(255,255,255,.02);border:1px dashed rgba(255,255,255,.1);border-radius:0">'
                     . '<p style="margin:0 0 8px;font-weight:600;color:var(--bt-text,#e2e8f0)">News feed warming up</p>'
                     . '<p style="margin:0;font-size:13px">Headlines are fetched hourly from 24 vetted sources. Check back in a few minutes — the next refresh runs automatically.</p>'
                     . '</div>';
            }
        }

        $layout_class = $a['layout'] === 'grid' ? 'fxlm-news-feed fxlm-news-grid' : 'fxlm-news-feed';

        // v93: premium layout — Bloomberg-style hero + grid
        if ( $a['layout'] === 'premium' ) {
            // Embed ALL items (up to count) with data-cat so client-side filter works
            $all_items = array_slice( array_values( $items ), 0, intval( $a['count'] ) );
            $hero = array_shift( $all_items );
            ob_start();
            $src_colors = array( 'coindesk'=>'#f7931a','cointelegraph'=>'#2952e3','decrypt'=>'var(--bt-accent)','marketwatch'=>'#1c6dbf', 'forexlive'=>'#009688','cnbc'=>'#0066b2','the-block'=>'#a78bfa','blockworks'=>'var(--bt-accent)','fxstreet'=>'var(--bt-accent)','beincrypto'=>'var(--bt-accent)','dailyfx'=>'var(--bt-accent-warm)','investing'=>'#e74c3c','thedefiant'=>'#a78bfa','bitcoin-magazine'=>'#f7931a','newsbtc'=>'#f7931a','cryptoslate'=>'#2952e3','ambcrypto'=>'#10b981','cryptonews'=>'var(--bt-accent)' );
            $get_accent = function( $source ) use ( $src_colors ) {
                $s = sanitize_title( $source ?? '' );
                foreach ( $src_colors as $k => $v ) { if ( strpos( $s, $k ) !== false ) return $v; }
                return 'var(--bt-accent)';
            };
            // Map stored category names to filter keys
            $cat_map = array(
                'crypto news'    => 'crypto',
                'forex news'     => 'forex',
                'defi & web3'    => 'defi',
                'macro & policy' => 'macro',
                'trading signals'=> 'signals',
            );
            $get_filter_key = function( $cat ) use ( $cat_map ) {
                return $cat_map[ strtolower( $cat ?? '' ) ] ?? 'other';
            };
            echo '<div class="bt-pn-wrap" id="bt-pn-feed">';
            echo '<div class="bt-pn-updated">&#x1F4E1; Live feed · ' . ( get_option('bt_news_updated') ? human_time_diff( get_option('bt_news_updated') ) . ' ago' : 'updating' ) . '</div>';
            // Hero article
            if ( $hero ) {
                $accent = $get_accent( $hero['source'] );
                $img    = $hero['image'] ?? '';
                $fkey   = $get_filter_key( $hero['category'] ?? '' );
                echo '<div class="bt-pn-hero" data-cat="' . esc_attr($fkey) . '">';
                if ( $img ) echo '<a href="' . esc_url($hero['link']) . '" class="bt-pn-hero-img-wrap" target="_blank" rel="noopener"><img src="' . esc_url($img) . '" alt="" loading="eager" class="bt-pn-hero-img"></a>';
                else echo '<div class="bt-pn-hero-img-ph" style="--ph-c:' . esc_attr($accent) . '"><span>' . esc_html($hero['source'] ?? '') . '</span></div>';
                echo '<div class="bt-pn-hero-body">';
                echo '<div class="bt-pn-hero-meta"><span class="bt-pn-src-badge" style="--src-c:' . esc_attr($accent) . '">' . esc_html($hero['source'] ?? '') . '</span>';
                if ( !empty($hero['category']) ) echo '<span class="bt-pn-cat">' . esc_html($hero['category']) . '</span>';
                echo '<span class="bt-pn-time">' . human_time_diff( $hero['timestamp'] ) . ' ago</span></div>';
                echo '<h2 class="bt-pn-hero-title"><a href="' . esc_url($hero['link']) . '" target="_blank" rel="noopener">' . esc_html($hero['title']) . '</a></h2>';
                if ( !empty($hero['description']) ) echo '<p class="bt-pn-hero-desc">' . esc_html($hero['description']) . '</p>';
                echo '<a href="' . esc_url($hero['link']) . '" class="bt-pn-hero-read" target="_blank" rel="noopener">Read full story &#x2192;</a>';
                echo '</div></div>';
            }
            // Grid cards — ALL loaded, filter hides/shows via JS
            echo '<div class="bt-pn-grid" id="bt-pn-grid">';
            foreach ( $all_items as $i => $item ) {
                $accent = $get_accent( $item['source'] );
                $img    = $item['image'] ?? '';
                $fkey   = $get_filter_key( $item['category'] ?? '' );
                echo '<article class="bt-pn-card" data-cat="' . esc_attr($fkey) . '" style="--card-c:' . esc_attr($accent) . ';animation-delay:' . ( $i * 0.04 ) . 's">';
                echo '<a href="' . esc_url($item['link']) . '" class="bt-pn-card-img-wrap" target="_blank" rel="noopener">';
                if ( $img ) echo '<img src="' . esc_url($img) . '" alt="" loading="lazy" class="bt-pn-card-img">';
                else echo '<div class="bt-pn-card-img-ph"><span>' . esc_html(mb_substr($item['source'] ?? 'N', 0, 2)) . '</span></div>';
                echo '</a>';
                echo '<div class="bt-pn-card-body">';
                echo '<div class="bt-pn-card-meta"><span class="bt-pn-src-badge" style="--src-c:' . esc_attr($accent) . '">' . esc_html($item['source'] ?? '') . '</span>';
                if ( !empty($item['category']) ) echo '<span class="bt-pn-cat-badge">' . esc_html($item['category']) . '</span>';
                echo '<span class="bt-pn-time">' . human_time_diff($item['timestamp']) . ' ago</span></div>';
                echo '<h3 class="bt-pn-card-title"><a href="' . esc_url($item['link']) . '" target="_blank" rel="noopener">' . esc_html($item['title']) . '</a></h3>';
                if ( !empty($item['description']) ) echo '<p class="bt-pn-card-desc">' . esc_html(wp_trim_words($item['description'], 18, '…')) . '</p>';
                echo '</div></article>';
            }
            echo '</div>';
            echo '<div id="bt-pn-empty" style="display:none;text-align:center;padding:48px;color:var(--bt-text-3);font-size:14px">No articles in this category yet — check back after the next hourly update.</div>';
            echo '</div>';
            // News filter JS — wires the .fxlm-tab buttons to filter the grid + hero
            echo "<script>
(function(){
  function initNewsFilter(){
    var tabs = document.querySelectorAll('.fxlm-news-tabs .fxlm-tab');
    var grid = document.getElementById('bt-pn-grid');
    var hero = document.querySelector('.bt-pn-hero');
    var empty = document.getElementById('bt-pn-empty');
    if(!tabs.length || !grid) return;
    tabs.forEach(function(tab){
      tab.addEventListener('click', function(e){
        e.preventDefault();
        tabs.forEach(function(t){ t.classList.remove('active'); });
        tab.classList.add('active');
        var cat = tab.dataset.cat || '';
        var cards = grid.querySelectorAll('.bt-pn-card');
        var visible = 0;
        cards.forEach(function(card){
          var show = !cat || card.dataset.cat === cat;
          card.style.display = show ? '' : 'none';
          if(show) visible++;
        });
        // hero follows the filter
        if(hero){
          var heroShow = !cat || hero.dataset.cat === cat;
          hero.style.display = heroShow ? '' : 'none';
          if(heroShow) visible++;
        }
        if(empty) empty.style.display = visible === 0 ? 'block' : 'none';
      });
    });
  }
  if(document.readyState === 'loading'){
    document.addEventListener('DOMContentLoaded', initNewsFilter);
  } else {
    initNewsFilter();
  }
})();
</script>";
            return ob_get_clean();
        }

        ob_start();
        $updated = get_option( 'bt_news_updated' );
        if ( $updated ) echo '<p class="fxlm-updated" style="text-align:right;margin-bottom:12px">Last updated: ' . human_time_diff( $updated ) . ' ago</p>';
        echo '<div class="' . esc_attr( $layout_class ) . '">';

        foreach ( array_slice( array_values( $items ), 0, intval( $a['count'] ) ) as $item ) {
            $breaking_class = ! empty( $item['breaking'] ) ? ' fxlm-news-breaking' : '';
            $src_slug = sanitize_title( $item['source'] ?? '' );
            $src_colors = array( 'coindesk'=>'#f7931a','cointelegraph'=>'#2952e3','decrypt'=>'var(--bt-accent)','beincrypto'=>'var(--bt-accent)','the-block'=>'#a78bfa','theblock'=>'#a78bfa','blockworks'=>'var(--bt-accent)','fxstreet'=>'var(--bt-accent)','investing'=>'#e74c3c','marketwatch'=>'var(--bt-accent)','dailyfx'=>'var(--bt-accent-warm)' );
            $accent = 'var(--bt-text-4)';
            foreach ( $src_colors as $k => $v ) { if ( strpos( $src_slug, $k ) !== false ) { $accent = $v; break; } }

            echo '<div class="fxlm-news-item' . $breaking_class . '">';
            if ( ( $a['show_images'] === 'true' || $a['layout'] === 'grid' ) && ! empty( $item['image'] ) ) {
                echo '<img class="fxlm-news-img" src="' . esc_url( $item['image'] ) . '" alt="" loading="lazy">';
            } elseif ( $a['layout'] === 'grid' ) {
                // Source-colored placeholder so grid cards never appear blank
                echo '<div class="fxlm-news-img fxlm-news-img-placeholder" style="background:linear-gradient(135deg,' . esc_attr($accent) . '22,' . esc_attr($accent) . '08);display:flex;align-items:center;justify-content:center;gap:10px;flex-direction:column">';
                echo '<span style="font-size:11px;font-weight:700;color:' . esc_attr($accent) . ';text-transform:uppercase;letter-spacing:.5px">' . esc_html($item['source'] ?? '') . '</span>';
                echo '</div>';
            }
            echo '<div class="fxlm-news-content">';
            if ( ! empty( $item['breaking'] ) ) {
                echo '<span class="fxlm-news-breaking-badge">⚡ Breaking</span>';
            }
            echo '<span class="fxlm-news-source">' . esc_html( $item['source'] ) . '</span>';
            if ( ! empty( $item['category'] ) ) {
                echo '<span class="fxlm-news-cat">' . esc_html( $item['category'] ) . '</span>';
            }
            echo '<a href="' . esc_url( $item['link'] ) . '" class="fxlm-news-title" target="_blank" rel="noopener">' . esc_html( $item['title'] ) . '</a>';
            echo '<p class="fxlm-news-desc">' . esc_html( $item['description'] ) . '</p>';
            echo '<span class="fxlm-news-time">' . human_time_diff( $item['timestamp'] ) . ' ago</span>';
            echo '</div></div>';
        }
        echo '</div>';
        return ob_get_clean();
    }

    public static function sc_signals_feed( $atts ) {
        $a       = shortcode_atts( array( 'count' => 20 ), $atts );
        $signals = get_option( 'bt_signal_items', array() );

        // FIX SIG-01: fall back gracefully to latest market analysis posts instead of dev message
        if ( empty( $signals ) ) {
            $fallback_posts = get_posts( array(
                'numberposts' => 6,
                'category_name' => 'Trading Signals',
                'post_status' => 'publish',
            ) );
            if ( ! empty( $fallback_posts ) ) {
                ob_start();
                echo '<div class="fxlm-signals-feed">';
                foreach ( $fallback_posts as $fp ) {
                    echo '<div class="fxlm-signal-item">';
                    echo '<a href="' . esc_url( get_permalink( $fp->ID ) ) . '">' . esc_html( $fp->post_title ) . '</a>';
                    echo '<span class="fxlm-signal-source">Market Analysis</span>';
                    echo '<span class="fxlm-signal-time">' . human_time_diff( get_the_time( 'U', $fp ) ) . ' ago</span>';
                    echo '</div>';
                }
                echo '</div>';
                return ob_get_clean();
            }
            return '<div class="fxlm-signals-empty" style="padding:32px;text-align:center;background:var(--bt-bg-elev);border-radius:0;border:1px solid rgba(255,255,255,.07)">
                <p style="font-size:18px;margin:0 0 10px">📡 Signal feeds refreshing&hellip;</p>
                <p style="color:var(--bt-text-3);font-size:13px;margin:0">Live trading signals from FXStreet, DailyFX and CoinDesk are pulled every 15 minutes. Check back shortly.</p>
                <p style="margin:16px 0 0"><a href="/market-analysis/" style="color:var(--bt-accent)">View AI Market Analysis →</a></p>
            </div>';
        }

        // Detect pair/direction from title for colour coding
        $pair_patterns = array('EUR/USD'=>'#4a9eff','GBP/USD'=>'#7c5cbf','USD/JPY'=>'#e56b3e','AUD/USD'=>'#4ec9b0','GOLD'=>'var(--bt-accent-warm)','XAU'=>'var(--bt-accent-warm)','BTC'=>'#f7931a','ETH'=>'#627eea','GBP/JPY'=>'#c084fc');
        ob_start();
        echo '<div class="fxlm-signals-v2">';
        foreach ( array_slice( $signals, 0, intval( $a['count'] ) ) as $i => $s ) {
            $title = $s['title'];
            $accent = 'var(--bt-accent)';
            foreach ( $pair_patterns as $pair => $col ) {
                if ( stripos($title, $pair) !== false ) { $accent = $col; break; }
            }
            // Detect bullish/bearish from title
            $sentiment = '';
            $sent_color = '';
            if ( preg_match('/bullish|buy|upside|long|rally|rise|gain/i', $title) ) { $sentiment = '🐂 Bullish'; $sent_color = 'var(--bt-accent)'; }
            elseif ( preg_match('/bearish|sell|downside|short|fall|drop|decline/i', $title) ) { $sentiment = '🐻 Bearish'; $sent_color = 'var(--bt-danger)'; }

            $delay = min($i * 0.04, 0.4);
            // Determine asset class from source name
            $src_lower = strtolower($s['source'] ?? '');
            $is_crypto = preg_match('/coindesk|cointelegraph|bitcoin|decrypt|block|blockworks|newsbtc|cryptoslate|ambcrypto|beincrypto/i', $src_lower);
            $data_cls  = $is_crypto ? 'crypto' : 'forex';
            $data_sent = $sentiment ? (strpos($sentiment,'Bullish')!==false ? 'bullish' : 'bearish') : 'neutral';
            echo '<a href="' . esc_url( $s['link'] ) . '" target="_blank" rel="noopener" class="fxlm-signal-v2" data-cls="' . $data_cls . '" data-sent="' . $data_sent . '" style="--sig-accent:' . $accent . ';animation-delay:' . $delay . 's">';
            echo '<div class="fxlm-signal-v2-bar" style="background:' . $accent . '"></div>';
            echo '<div class="fxlm-signal-v2-body">';
            echo '<div class="fxlm-signal-v2-title">' . esc_html($title) . '</div>';
            echo '<div class="fxlm-signal-v2-meta">';
            echo '<span class="fxlm-signal-v2-source" style="color:' . $accent . ';border-color:' . $accent . '40;background:' . $accent . '12">' . esc_html( $s['source'] ) . '</span>';
            if ( $sentiment ) echo '<span class="fxlm-signal-v2-sent" style="color:' . $sent_color . '">' . $sentiment . '</span>';
            echo '<span class="fxlm-signal-v2-time">🕐 ' . human_time_diff( $s['timestamp'] ) . ' ago</span>';
            // Signal tracker badge: shows price move since first seen (>2h age)
            $tracker_hash = md5( $title );
            echo do_shortcode( '[bt_signal_tracker_badge hash="' . esc_attr( $tracker_hash ) . '" title="' . esc_attr( $title ) . '" timestamp="' . intval( $s['timestamp'] ) . '"]' );
            echo '</div>';
            echo '</div>';
            echo '<div class="fxlm-signal-v2-arrow">→</div>';
            echo '</a>';
        }
        echo '</div>';
        return ob_get_clean();
    }

    // v6: Breaking news horizontal scroller
    public static function sc_breaking_news( $atts ) {
        $items = BT_Widgets::get_json_option( 'fxlm_news_items' );
        $breaking = array_filter( $items, function( $i ) { return ! empty( $i['breaking'] ); } );

        if ( empty( $breaking ) ) {
            // Fallback: show latest 3 items
            $breaking = array_slice( $items, 0, 3 );
        }

        if ( empty( $breaking ) ) return '';

        ob_start();
        echo '<div class="fxlm-breaking-bar">';
        echo '<span class="fxlm-breaking-label">⚡ Breaking</span>';
        echo '<div class="fxlm-breaking-scroll">';
        foreach ( array_slice( array_values( $breaking ), 0, 5 ) as $item ) {
            echo '<a href="' . esc_url( $item['link'] ) . '" target="_blank" rel="noopener" class="fxlm-breaking-item">' . esc_html( $item['title'] ) . '</a>';
        }
        echo '</div></div>';
        return ob_get_clean();
    }

    public static function ajax_get_news() {
        BT_Utils::verify_public_ajax( 'fxlm_prices' );
        wp_send_json_success( array(
            'news'    => BT_Widgets::get_json_option( 'fxlm_news_items' ),
            'signals' => get_option( 'bt_signal_items', array() ),
        ) );
    }

    public static function cat_emoji( $slug ) {
        $map = [
            'market-analysis' => '📊', 'crypto-news' => '₿', 'forex-news' => '💱',
            'defi-web3' => '🔗', 'altcoins' => '🚀', 'education' => '🎓',
            'bitcoin' => '₿', 'ethereum' => '⟠', 'regulation' => '⚖️',
            'nft-news' => '🖼', 'trading-signals' => '📡', 'press-releases' => '📋',
        ];
        return $map[$slug] ?? '📰';
    }

    /**
     * v80: Typographic category code for broadsheet thumbnail fallbacks.
     * Returns a 2-4 char uppercase code — "CRY", "FX", "DEFI", "BTC", etc.
     * No emoji; pure type-based category identity.
     */
    public static function cat_code( $slug ) {
        $map = [
            'market-analysis' => 'ANLYS', 'crypto-news' => 'CRY', 'forex-news' => 'FX',
            'defi-web3' => 'DEFI', 'altcoins' => 'ALT', 'education' => 'EDU',
            'bitcoin' => 'BTC', 'ethereum' => 'ETH', 'regulation' => 'REG',
            'nft-news' => 'NFT', 'trading-signals' => 'SIG', 'press-releases' => 'PR',
        ];
        return $map[$slug] ?? 'NEWS';
    }

    /**
     * v80: Rubric — small-caps editorial category label shown above card titles.
     * e.g. "CRYPTO · ANALYSIS" or "FOREX · NEWS". Replaces the old colored pill chip.
     */
    public static function cat_rubric( $slug, $cat_name = '' ) {
        $map = [
            'market-analysis' => 'MARKETS · ANALYSIS',
            'crypto-news'     => 'CRYPTO · NEWS',
            'forex-news'      => 'FOREX · NEWS',
            'defi-web3'       => 'DEFI · WEB3',
            'altcoins'        => 'MARKETS · ALTCOINS',
            'education'       => 'LEARN · EDUCATION',
            'bitcoin'         => 'BITCOIN',
            'ethereum'        => 'ETHEREUM',
            'regulation'      => 'POLICY · REGULATION',
            'nft-news'        => 'NFT · CULTURE',
            'trading-signals' => 'SIGNALS · DESK',
            'press-releases'  => 'PRESS · RELEASE',
            'news'            => 'NEWS',
        ];
        if ( isset( $map[ $slug ] ) ) return $map[ $slug ];
        // Fallback: uppercase the category name
        return strtoupper( $cat_name ?: 'ANALYSIS' );
    }
}

