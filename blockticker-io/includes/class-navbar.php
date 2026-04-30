<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class BT_Navbar {

    public static function init() {
        // Inject custom navbar at the top of every page
        add_action( 'wp_body_open', array( __CLASS__, 'render_navbar' ), 1 );

        // Inject custom footer before theme's footer
        add_action( 'wp_footer', array( __CLASS__, 'render_footer' ), 5 );

        // Hide theme's default header via CSS (backup)
        add_action( 'wp_head', array( __CLASS__, 'hide_astra_header' ), 99 );
        // Ensure proper viewport meta tag for mobile responsive CSS
        add_action( 'wp_head', function() {
            echo '<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">' . "\n";
        }, 1 );

        // REMOVE GeneratePress header at PHP level — not just CSS
        remove_action( 'generate_header', 'generate_construct_header' );
        remove_action( 'generate_after_header', 'generate_add_navigation_after_header', 5 );
        add_filter( 'generate_navigation_location', '__return_false' );
        add_filter( 'generate_header_display', '__return_false' );

        // Also remove GP footer info
        remove_action( 'generate_footer', 'generate_construct_footer_widgets', 5 );
        remove_action( 'generate_credits', 'generate_add_footer_info' );
        add_filter( 'generate_footer_entry_content', '__return_false' );
        add_filter( 'generate_show_footer', '__return_true' );

        // Remove WP admin bar inline CSS that can interfere
        add_action( 'get_header', function() {
            remove_action( 'wp_head', '_admin_bar_bump_cb' );
        });
    }

    public static function hide_astra_header() {
        
?>
        <style>
        /* Hide GeneratePress default header/nav — we use our own */
        .site-header, #masthead, .main-navigation, .site-branding,
        header#masthead, nav.main-navigation, nav#site-navigation,
        .menu-toggle, .navigation-search, .gen-sidebar-nav,
        .mobile-menu-control-wrapper, .main-nav, .menu-bar-items,
        .inside-header, .site-header-section,
        a.screen-reader-text[href="#content"] {
            display: none !important; height: 0 !important; overflow: hidden !important;
        }
        /* Hide any default page title */
        .entry-title, h1.entry-title, .page-title { display: none; }
        /* Hide comments */
        .comments-area, #comments, .comment-respond { display: none; }
        /* Full width containers */
        body { padding-top: 64px; margin: 0; background: #0b0f1a; color: #c8cdd8; }
        @media (max-width: 768px) { body { padding-top: 56px; } }
        .site-content, .inside-article, .entry-content,
        #primary, #content { padding: 0; margin: 0; max-width: 100%; background: #0b0f1a; }
        /* Hero animations */
        .fxlm-hero-badge { opacity:0; animation: btSD .6s ease .1s both; }
        .fxlm-hero-title { opacity:0; animation: btSU .8s ease .2s both; }
        .fxlm-hero-tagline { opacity:0; animation: btSU .8s ease .35s both; }
        .fxlm-hero-sub { opacity:0; animation: btSU .8s ease .45s both; }
        .fxlm-hero-btns { opacity:0; animation: btSU .8s ease .6s both; }
        .fxlm-hero-stats { opacity:0; animation: btSU .8s ease .75s both; }
        @keyframes btSU { from{opacity:0;transform:translateY(30px)} to{opacity:1;transform:translateY(0)} }
        @keyframes btSD { from{opacity:0;transform:translateY(-20px)} to{opacity:1;transform:translateY(0)} }
        /* Footer dark */
        .site-footer, footer, #colophon { background: #080c16; }
        </style>
        <script>
        /* BlockTicker Dropdown — single authoritative handler (FIX UX nav dropdowns) */
        (function(){
            function initDropdowns(){
                // Find all dropdown triggers (cp-has-dropdown class on the <a>)
                var triggers = document.querySelectorAll('.cp-has-dropdown');
                if (!triggers.length) return;

                triggers.forEach(function(trigger){
                    var parent = trigger.parentElement; // .cp-nav-dropdown div
                    var menu   = parent ? parent.querySelector('.cp-dropdown-menu') : null;
                    if (!menu) return;

                    // Desktop: show on hover
                    parent.addEventListener('mouseenter', function(){
                        if (window.innerWidth > 900) showMenu(menu);
                    });
                    parent.addEventListener('mouseleave', function(){
                        if (window.innerWidth > 900) hideMenu(menu);
                    });

                    // All devices: toggle on click/tap
                    trigger.addEventListener('click', function(e){
                        e.preventDefault();
                        e.stopPropagation();
                        var isOpen = menu.style.display === 'block';
                        closeAll();
                        if (!isOpen) showMenu(menu);
                    });

                    // Hover highlight on items
                    menu.querySelectorAll('a').forEach(function(a){
                        a.addEventListener('mouseenter', function(){ this.style.background='rgba(0,255,102,.08)'; this.style.color='var(--bt-accent)'; });
                        a.addEventListener('mouseleave', function(){ this.style.background=''; this.style.color=''; });
                    });
                });

                // Close on outside click
                document.addEventListener('click', function(e){
                    if (!e.target.closest('.cp-nav-dropdown')) closeAll();
                });
            }

            function showMenu(m){ m.style.display='block'; }
            function hideMenu(m){ m.style.display='none'; }
            function closeAll(){ document.querySelectorAll('.cp-dropdown-menu').forEach(function(m){ m.style.display='none'; }); }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initDropdowns);
            } else {
                initDropdowns();
            }
        })();
        </script>
        <?php
    }

    public static function render_navbar() {
        // v119.28.16 — The old cp-navbar is fully deprecated and replaced by the
        // new BlockTicker chrome (BT_Page_Provisioner::render_global_chrome).
        // Always return early — no toggle, no condition. The old navbar and its
        // Home/Crypto/Forex/DexScan/etc. menu HTML is preserved below for
        // archive/rollback purposes only, but is never rendered on the live site.
        return;

        // Output emergency CSS hide FIRST before any GP elements render
        echo '<style>.site-header,.main-navigation,.site-branding,#masthead,nav#site-navigation,.menu-toggle,.navigation-search,.mobile-menu-control-wrapper,.inside-header,a[href="#content"].screen-reader-text,.skip-link{display:none!important;height:0!important;overflow:hidden!important;visibility:hidden!important;position:absolute!important;left:-9999px!important}</style>';

        /* Page loader removed */
        
        $site_name = get_option( 'bt_site_name', 'BlockTicker' );
        $logo_url  = get_option( 'bt_logo_url', '' );
        $home_url  = home_url( '/' );

        // Menu items — configurable from admin panel (BlockTicker → Menu Configurator)
        $saved_menu = get_option( 'bt_menu_config', [] );
        $default_menu_items = array(
            'Home'      => $home_url,
            'Markets'   => $home_url . 'crypto-markets/',
            'Forex'     => $home_url . 'forex-charts/',
            'News'      => $home_url . 'financial-news/',
            'Signals'   => $home_url . 'trading-signals/',
            'Analysis'  => $home_url . 'market-analysis/',
            'Calendar'  => $home_url . 'economic-calendar/',
            'Tools'     => $home_url . 'tools/',
            'Learn'     => $home_url . 'learn/',
            'Blog'      => $home_url . 'market-blog/',
            'Brokers'   => $home_url . 'recommended-brokers/',
        );
        // Build mobile menu from saved config or defaults
        if ( ! empty( $saved_menu ) ) {
            $menu_items = [];
            foreach ( $saved_menu as $item ) {
                if ( ! empty( $item['enabled'] ) && ! empty( $item['label'] ) ) {
                    $url = $item['url'] ?? '/';
                    $menu_items[ $item['label'] ] = ( strpos($url,'http') === 0 ) ? $url : $home_url . ltrim($url,'/');
                }
            }
        } else {
            $menu_items = $default_menu_items;
        }

        $current_url = home_url( add_query_arg( array(), '' ) );
        ?>
        <nav class="cp-navbar" id="cp-navbar">
            <div class="cp-nav-inner">
                <!-- Logo -->
                <a href="<?php echo esc_url( $home_url ); ?>" class="cp-nav-logo">
                    <?php if ( $logo_url ) : ?>
                        <img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( $site_name ); ?>" height="32">
                    <?php else : ?>
                        <span class="cp-logo-tile">B</span>
                        <span class="cp-logo-text">BLOCK<span class="cp-logo-accent">TICKER</span></span>
                    <?php endif; ?>
                </a>

                <!-- Desktop Menu — clean, no duplicates -->
                <div class="cp-nav-links" id="cp-nav-links">
                    <a href="<?php echo esc_url( $home_url ); ?>" class="cp-nav-link<?php echo is_front_page() ? ' active' : ''; ?>"><span data-i18n="nav.home">Home</span></a>

                    <!-- CRYPTO ▾ -->
                    <div class="cp-nav-dropdown">
                        <a href="<?php echo esc_url($home_url.'crypto-markets/'); ?>" class="cp-nav-link cp-has-dropdown"><span data-i18n="nav.crypto">Crypto</span> ▾</a>
                        <div class="cp-dropdown-menu" style="display:none">
                            <a href="<?php echo esc_url($home_url.'crypto-markets/'); ?>">All Crypto Prices</a>
                            <a href="<?php echo esc_url($home_url.'gainers-losers/'); ?>"><span data-i18n="nav.gainers_losers"><?php _ebt('nav.gainers_losers'); ?></span></a>
                            <a href="<?php echo esc_url($home_url.'top/'); ?>">📊 Top Lists</a>
                            <a href="<?php echo esc_url($home_url.'watchlist/'); ?>">⭐ My Watchlist</a>
                            <a href="<?php echo esc_url($home_url.'exchanges/'); ?>"><span data-i18n="nav.exchanges_all"><?php _ebt('nav.exchanges_all'); ?></span></a>
                            <div class="cp-dd-divider"></div>
                            <div class="cp-dd-section" data-i18n="navdd.top_coins"><?php _ebt('navdd.top_coins'); ?></div>
                            <a href="<?php echo esc_url($home_url.'crypto/bitcoin/'); ?>">₿ Bitcoin (BTC)</a>
                            <a href="<?php echo esc_url($home_url.'crypto/ethereum/'); ?>">⟠ Ethereum (ETH)</a>
                            <a href="<?php echo esc_url($home_url.'crypto/solana/'); ?>">◎ Solana (SOL)</a>
                            <a href="<?php echo esc_url($home_url.'crypto/xrp/'); ?>">XRP</a>
                            <a href="<?php echo esc_url($home_url.'crypto/dogecoin/'); ?>">Dogecoin</a>
                            <a href="<?php echo esc_url($home_url.'crypto/binance-coin/'); ?>">◈ BNB</a>
                            <div class="cp-dd-divider"></div>
                            <div class="cp-dd-section" data-i18n="navdd.categories"><?php _ebt('navdd.categories'); ?></div>
                            <a href="<?php echo esc_url($home_url.'crypto-category/defi/'); ?>">DeFi Tokens</a>
                            <a href="<?php echo esc_url($home_url.'crypto-category/nft/'); ?>">NFT</a>
                            <a href="<?php echo esc_url($home_url.'crypto-category/stablecoins/'); ?>">Stablecoins</a>
                            <a href="<?php echo esc_url($home_url.'crypto-category/metaverse/'); ?>">Metaverse</a>
                        </div>
                    </div>

                    <!-- FOREX ▾ -->
                    <div class="cp-nav-dropdown">
                        <a href="<?php echo esc_url($home_url.'forex-charts/'); ?>" class="cp-nav-link cp-has-dropdown"><span data-i18n="nav.forex">Forex</span> ▾</a>
                        <div class="cp-dropdown-menu" style="display:none">
                            <a href="<?php echo esc_url($home_url.'forex-charts/'); ?>"><span data-i18n="nav.all_forex_charts"><?php _ebt('nav.all_forex_charts'); ?></span></a>
                            <a href="<?php echo esc_url($home_url.'forex-sentiment/'); ?>"><span data-i18n="nav.client_sentiment"><?php _ebt('nav.client_sentiment'); ?></span></a>
                            <a href="<?php echo esc_url($home_url.'economic-calendar/'); ?>"><span data-i18n="nav.economic_calendar"><?php _ebt('nav.economic_calendar'); ?></span></a>
                            <div class="cp-dd-divider"></div>
                            <div class="cp-dd-section" data-i18n="navdd.popular_markets"><?php _ebt('navdd.popular_markets'); ?></div>
                            <a href="<?php echo esc_url($home_url.'forex/eur-usd/'); ?>">EUR/USD</a>
                            <a href="<?php echo esc_url($home_url.'forex/usd-cad/'); ?>">USD/CAD</a>
                            <a href="<?php echo esc_url($home_url.'forex/gbp-usd/'); ?>">GBP/USD</a>
                            <a href="<?php echo esc_url($home_url.'forex/aud-usd/'); ?>">AUD/USD</a>
                            <a href="<?php echo esc_url($home_url.'forex/usd-jpy/'); ?>">USD/JPY</a>
                            <a href="<?php echo esc_url($home_url.'forex/nzd-usd/'); ?>">NZD/USD</a>
                            <div class="cp-dd-divider"></div>
                            <div class="cp-dd-section" data-i18n="navdd.exotic_markets"><?php _ebt('navdd.exotic_markets'); ?></div>
                            <a href="<?php echo esc_url($home_url.'forex/usd-thb/'); ?>">USD/THB</a>
                            <a href="<?php echo esc_url($home_url.'forex/usd-try/'); ?>">USD/TRY</a>
                            <a href="<?php echo esc_url($home_url.'forex/usd-mxn/'); ?>">USD/MXN</a>
                            <a href="<?php echo esc_url($home_url.'forex/usd-sgd/'); ?>">USD/SGD</a>
                            <a href="<?php echo esc_url($home_url.'forex/usd-hkd/'); ?>">USD/HKD</a>
                            <a href="<?php echo esc_url($home_url.'forex/usd-ils/'); ?>">USD/ILS</a>
                        </div>
                    </div>

                    <!-- DEXSCAN ▾ -->
                    <div class="cp-nav-dropdown">
                        <a href="<?php echo esc_url($home_url.'dexscan/'); ?>" class="cp-nav-link cp-has-dropdown"><span data-i18n="nav.dexscan">DexScan</span> ▾</a>
                        <div class="cp-dropdown-menu" style="display:none">
                            <a href="<?php echo esc_url($home_url.'dexscan/signals/'); ?>"><span data-i18n="nav.signals"><?php _ebt('nav.signals'); ?></span> <span class="cp-dd-new" data-i18n="navdd.new_badge"><?php _ebt('navdd.new_badge'); ?></span></a>
                            <a href="<?php echo esc_url($home_url.'dexscan/trending/'); ?>"><span data-i18n="nav.trending"><?php _ebt('nav.trending'); ?></span></a>
                            <a href="<?php echo esc_url($home_url.'dexscan/new/'); ?>"><span data-i18n="nav.new_listings"><?php _ebt('nav.new_listings'); ?></span></a>
                            <a href="<?php echo esc_url($home_url.'dexscan/gainers/'); ?>"><span data-i18n="nav.gainers_only"><?php _ebt('nav.gainers_only'); ?></span></a>
                            <a href="<?php echo esc_url($home_url.'dexscan/meme/'); ?>"><span data-i18n="nav.meme_explorer"><?php _ebt('nav.meme_explorer'); ?></span></a>
                            <a href="<?php echo esc_url($home_url.'dexscan/top-traders/'); ?>"><span data-i18n="nav.top_traders"><?php _ebt('nav.top_traders'); ?></span></a>
                        </div>
                    </div>

                    <!-- EXCHANGES ▾ -->
                    <div class="cp-nav-dropdown">
                        <a href="<?php echo esc_url($home_url.'exchanges/'); ?>" class="cp-nav-link cp-has-dropdown"><span data-i18n="nav.exchanges">Exchanges</span> ▾</a>
                        <div class="cp-dropdown-menu" style="display:none">
                            <div class="cp-dd-section" data-i18n="navdd.cex"><?php _ebt('navdd.cex'); ?></div>
                            <a href="<?php echo esc_url($home_url.'exchanges/spot/'); ?>"><span data-i18n="nav.spot"><?php _ebt('nav.spot'); ?></span></a>
                            <a href="<?php echo esc_url($home_url.'exchanges/derivatives/'); ?>"><span data-i18n="nav.derivatives"><?php _ebt('nav.derivatives'); ?></span></a>
                            <div class="cp-dd-divider"></div>
                            <div class="cp-dd-section" data-i18n="navdd.dex"><?php _ebt('navdd.dex'); ?></div>
                            <a href="<?php echo esc_url($home_url.'exchanges/dex-spot/'); ?>"><span data-i18n="nav.spot"><?php _ebt('nav.spot'); ?></span></a>
                            <a href="<?php echo esc_url($home_url.'exchanges/dex-derivatives/'); ?>"><span data-i18n="nav.derivatives"><?php _ebt('nav.derivatives'); ?></span></a>
                            <div class="cp-dd-divider"></div>
                            <a href="<?php echo esc_url($home_url.'exchanges/'); ?>" data-i18n="navdd.view_all_exchanges"><?php _ebt('navdd.view_all_exchanges'); ?></a>
                        </div>
                    </div>

                    <!-- NEWS (direct link) -->
                    <a href="<?php echo esc_url($home_url.'financial-news/'); ?>" class="cp-nav-link"><span data-i18n="nav.news">News</span></a>

                    <!-- ANALYSIS ▾ -->
                    <div class="cp-nav-dropdown">
                        <a href="<?php echo esc_url($home_url.'market-analysis/'); ?>" class="cp-nav-link cp-has-dropdown"><span data-i18n="nav.analysis">Analysis</span> ▾</a>
                        <div class="cp-dropdown-menu" style="display:none">
                            <a href="<?php echo esc_url($home_url.'market-analysis/'); ?>"><span data-i18n="nav.ai_analysis"><?php _ebt('nav.ai_analysis'); ?></span></a>
                            <a href="<?php echo esc_url($home_url.'forecast/'); ?>">🔮 Forecasts</a>
                            <a href="<?php echo esc_url($home_url.'performance/'); ?>">📈 Track Record</a>
                            <a href="<?php echo esc_url($home_url.'threads/'); ?>">𝕏 <span data-i18n="nav.daily_threads"><?php _ebt('nav.daily_threads'); ?></span></a>
                            <a href="<?php echo esc_url($home_url.'trading-signals/'); ?>"><span data-i18n="nav.trading_signals"><?php _ebt('nav.trading_signals'); ?></span></a>
                        </div>
                    </div>

                    <!-- BLOG (direct, separate) -->
                    <a href="<?php echo esc_url($home_url.'market-blog/'); ?>" class="cp-nav-link"><span data-i18n="nav.blog">Blog</span></a>

                    <!-- LEARN ▾ (educational content hub — includes "What is Forex Trading?" moved from Forex menu) -->
                    <div class="cp-nav-dropdown">
                        <a href="<?php echo esc_url($home_url.'learn/'); ?>" class="cp-nav-link cp-has-dropdown"><span data-i18n="nav.learn">Learn</span> ▾</a>
                        <div class="cp-dropdown-menu" style="display:none">
                            <a href="<?php echo esc_url($home_url.'learn/'); ?>"><span data-i18n="nav.learn_hub"><?php _ebt('nav.learn_hub'); ?></span></a>
                            <a href="<?php echo esc_url($home_url.'forex-guide/'); ?>"><span data-i18n="nav.forex_guide"><?php _ebt('nav.forex_guide'); ?></span></a>
                            <a href="<?php echo esc_url($home_url.'learn/#glossary'); ?>"><span data-i18n="nav.glossary"><?php _ebt('nav.glossary'); ?></span></a>
                            <div class="cp-dd-divider"></div>
                            <a href="<?php echo esc_url($home_url.'editorial-policy/'); ?>"><span data-i18n="nav.editorial_policy"><?php _ebt('nav.editorial_policy'); ?></span></a>
                            <a href="<?php echo esc_url($home_url.'write-for-us/'); ?>"><span data-i18n="nav.write_for_us"><?php _ebt('nav.write_for_us'); ?></span></a>
                        </div>
                    </div>

                    <!-- TOOLS ▾ — general utilities (personalized pages live in user menu) -->
                    <div class="cp-nav-dropdown">
                        <a href="<?php echo esc_url($home_url.'tools/'); ?>" class="cp-nav-link cp-has-dropdown"><span data-i18n="nav.tools">Tools</span> ▾</a>
                        <div class="cp-dropdown-menu" style="display:none">
                            <a href="<?php echo esc_url($home_url.'tools/#converter'); ?>"><span data-i18n="nav.crypto_converter"><?php _ebt('nav.crypto_converter'); ?></span></a>
                            <a href="<?php echo esc_url($home_url.'economic-calendar/'); ?>"><span data-i18n="nav.economic_calendar"><?php _ebt('nav.economic_calendar'); ?></span></a>
                            <a href="<?php echo esc_url($home_url.'watchlist/'); ?>">⭐ <span data-i18n="nav.watchlist"><?php _ebt('nav.watchlist'); ?></span></a>
                            <a href="<?php echo esc_url($home_url.'recommended-brokers/'); ?>"><span data-i18n="nav.best_brokers"><?php _ebt('nav.best_brokers'); ?></span></a>
                            <?php if ( ! is_user_logged_in() ) : ?>
                            <div class="cp-dd-divider"></div>
                            <a href="<?php echo esc_url($home_url.'dashboard/'); ?>" style="opacity:.85">🏠 Personalized Dashboard <span style="font-size:10px;color:var(--bt-text-3);margin-left:4px">— sign in</span></a>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>

                <!-- Portfolio + Watchlist quicklinks (like CMC screenshot) -->
                <div class="bt-nav-user-actions" id="bt-nav-user-actions">
                    <a href="<?php echo esc_url( home_url('/portfolio/') ); ?>" class="bt-nav-quick-link" title="Portfolio Tracker">
                        <svg width="14" height="14" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="1" y="4" width="4" height="9" rx="1" stroke="currentColor" stroke-width="1.3"/><rect x="5.5" y="2" width="3" height="11" rx="1" stroke="currentColor" stroke-width="1.3"/><rect x="9" y="5.5" width="4" height="7.5" rx="1" stroke="currentColor" stroke-width="1.3"/></svg>
                        <span data-i18n="nav.portfolio"><?php _ebt('nav.portfolio'); ?></span>
                    </a>
                    <a href="<?php echo esc_url( home_url('/watchlist/') ); ?>" class="bt-nav-quick-link" title="My Watchlist">
                        <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M7 1l1.6 3.3 3.6.5-2.6 2.5.6 3.6L7 9.3l-3.2 1.6.6-3.6L1.8 4.8l3.6-.5z" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/></svg>
                        <span data-i18n="nav.watchlist"><?php _ebt('nav.watchlist'); ?></span>
                    </a>

                    <!-- Auth: show avatar when logged in, Login btn when not -->
                    <?php if ( is_user_logged_in() ):
                        $user = wp_get_current_user();
                        $avatar = get_avatar_url( $user->ID, array('size'=>32) );
                    ?>
                    <div class="bt-nav-avatar-wrap" id="bt-nav-avatar-wrap" style="position:relative;display:inline-flex;align-items:center;flex-shrink:0">
                        <img src="<?php echo esc_url($avatar); ?>" alt="" id="bt-nav-avatar" width="28" height="28"
                            style="border-radius:50%;cursor:pointer;border:2px solid rgba(0,255,102,.4)" onclick="btNavUserMenu()">
                        <span id="bt-nav-username" style="font-size:12px;font-weight:600;color:var(--bt-text-2);margin-left:6px;cursor:pointer;max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" onclick="btNavUserMenu()"><?php echo esc_html($user->display_name); ?></span>
                        <div id="bt-nav-usermenu" style="display:none;position:absolute;top:calc(100% + 8px);right:0;background:var(--bt-bg-elev,#16181d);border:1px solid rgba(255,255,255,.1);border-radius:6px;min-width:220px;max-width:calc(100vw - 24px);box-shadow:0 12px 40px rgba(0,0,0,.6);z-index:9999;padding:8px 0;overflow:hidden">
                            <div style="padding:12px 16px;border-bottom:1px solid rgba(255,255,255,.06)">
                                <div style="font-size:13px;font-weight:700;color:var(--bt-text)"><?php echo esc_html($user->display_name); ?></div>
                                <div style="font-size:11px;color:var(--bt-text-3)"><?php echo esc_html($user->user_email); ?></div>
                            </div>
                            <a href="<?php echo esc_url(home_url('/dashboard/')); ?>" class="bt-um-item" onmouseover="this.style.background='rgba(255,255,255,.05)'" onmouseout="this.style.background='transparent'" style="display:block;padding:10px 16px;color:var(--bt-text-2);text-decoration:none;font-size:13px">🏠 My Dashboard</a>
                            <a href="<?php echo esc_url(home_url('/portfolio/')); ?>" class="bt-um-item" onmouseover="this.style.background='rgba(255,255,255,.05)'" onmouseout="this.style.background='transparent'" style="display:block;padding:10px 16px;color:var(--bt-text-2);text-decoration:none;font-size:13px">📊 <span data-i18n="auth.my_portfolio"><?php _ebt('auth.my_portfolio'); ?></span></a>
                            <a href="<?php echo esc_url(home_url('/watchlist/')); ?>" class="bt-um-item" onmouseover="this.style.background='rgba(255,255,255,.05)'" onmouseout="this.style.background='transparent'" style="display:block;padding:10px 16px;color:var(--bt-text-2);text-decoration:none;font-size:13px">⭐ <span data-i18n="nav.watchlist"><?php _ebt('nav.watchlist'); ?></span></a>
                            <a href="<?php echo esc_url(home_url('/screeners/')); ?>" class="bt-um-item" onmouseover="this.style.background='rgba(255,255,255,.05)'" onmouseout="this.style.background='transparent'" style="display:block;padding:10px 16px;color:var(--bt-text-2);text-decoration:none;font-size:13px">🔎 My Screeners</a>
                            <a href="<?php echo esc_url(home_url('/following/')); ?>" class="bt-um-item" onmouseover="this.style.background='rgba(255,255,255,.05)'" onmouseout="this.style.background='transparent'" style="display:block;padding:10px 16px;color:var(--bt-text-2);text-decoration:none;font-size:13px">★ Following</a>
                            <a href="<?php echo esc_url(home_url('/alerts/')); ?>" class="bt-um-item" onmouseover="this.style.background='rgba(255,255,255,.05)'" onmouseout="this.style.background='transparent'" style="display:block;padding:10px 16px;color:var(--bt-text-2);text-decoration:none;font-size:13px">🔔 Alerts</a>
                            <div style="border-top:1px solid rgba(255,255,255,.06);margin:6px 0"></div>
                            <button onclick="btAuthLogout()" style="display:block;width:100%;text-align:left;padding:10px 16px;background:none;border:none;color:var(--bt-danger);font-size:13px;cursor:pointer;font-family:inherit" data-i18n="auth.logout"><?php _ebt('auth.logout'); ?></button>
                        </div>
                    </div>
                    <?php else: ?>
                    <button id="bt-nav-login-btn" onclick="btAuthOpen('login')" class="bt-nav-login-btn-ghost" data-i18n="auth.login"><?php _ebt('auth.login'); ?></button>
                    <button id="bt-nav-signup-btn" onclick="btAuthOpen('register')" class="bt-nav-login-btn" data-i18n="auth.signup"><?php _ebt('auth.signup'); ?></button>
                    <?php endif; ?>
                </div>

                <!-- Global Search -->
                <div class="bt-nav-search" id="bt-nav-search">
                    <button class="bt-search-btn" id="bt-search-open" aria-label="Search coins and currencies">
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><circle cx="6.5" cy="6.5" r="5" stroke="currentColor" stroke-width="1.5"/><path d="M10.5 10.5L14 14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                    </button>
                    <div class="bt-search-overlay" id="bt-search-modal" style="display:none">
                        <div class="bt-search-box">
                            <div class="bt-search-row">
                                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" style="flex-shrink:0;color:var(--bt-text-3)"><circle cx="6.5" cy="6.5" r="5" stroke="currentColor" stroke-width="1.5"/><path d="M10.5 10.5L14 14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                                <input type="text" id="bt-search-input" data-i18n-ph="search.placeholder" placeholder="<?php echo esc_attr(__bt('search.placeholder')); ?>" autocomplete="off" spellcheck="false">
                                <button id="bt-search-close" class="bt-search-esc">ESC</button>
                            </div>
                            <div id="bt-search-results"></div>
                            <div class="bt-search-hint">
                                <span data-i18n="search.enter_hint"><?php _ebt('search.enter_hint'); ?></span><span data-i18n="search.esc_hint"><?php _ebt('search.esc_hint'); ?></span><span data-i18n="search.ctrlk_hint"><?php _ebt('search.ctrlk_hint'); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Language Switcher -->
                <div class="bt-lang-switcher" id="bt-lang-switcher">
                    <button class="bt-lang-btn" id="bt-lang-btn" aria-label="Select language" aria-expanded="false">
                        <span class="bt-lang-flag" id="bt-lang-flag">🇺🇸</span>
                        <span class="bt-lang-label" id="bt-lang-label">EN</span>
                        <svg class="bt-lang-chevron" width="10" height="6" viewBox="0 0 10 6"><path d="M1 1l4 4 4-4" stroke="currentColor" stroke-width="1.5" fill="none" stroke-linecap="round"/></svg>
                    </button>
                    <div class="bt-lang-dropdown" id="bt-lang-dropdown" role="listbox">
                        <div class="bt-lang-search-wrap">
                            <svg width="13" height="13" viewBox="0 0 13 13" fill="none"><circle cx="5.5" cy="5.5" r="4.5" stroke="var(--bt-text-3)" stroke-width="1.5"/><path d="M9 9l3 3" stroke="var(--bt-text-3)" stroke-width="1.5" stroke-linecap="round"/></svg>
                            <input class="bt-lang-search" id="bt-lang-search" type="text" data-i18n-ph="search.lang_placeholder" placeholder="<?php echo esc_attr(__bt('search.lang_placeholder')); ?>" autocomplete="off" spellcheck="false">
                        </div>
                        <div class="bt-lang-list" id="bt-lang-list" role="listbox"></div>
                    </div>
                </div>

                <!-- Mobile hamburger -->
                <button class="cp-hamburger" id="cp-hamburger" aria-label="Toggle menu" onclick="document.getElementById('cp-mobile-menu').classList.toggle('open');this.classList.toggle('open');">
                    <span></span><span></span><span></span>
                </button>
            </div>

            <!-- Mobile Slide-out Menu -->
            <div class="cp-mobile-menu" id="cp-mobile-menu">
                <!-- Auth section at top of mobile menu -->
                <?php if ( ! is_user_logged_in() ): ?>
                <div class="cp-mobile-auth">
                    <button onclick="btAuthOpen('login'); document.getElementById('cp-mobile-menu').classList.remove('open'); document.getElementById('cp-hamburger').classList.remove('open');" class="cp-mobile-auth-login" data-i18n="auth.login"><?php _ebt('auth.login'); ?></button>
                    <button onclick="btAuthOpen('register'); document.getElementById('cp-mobile-menu').classList.remove('open'); document.getElementById('cp-hamburger').classList.remove('open');" class="cp-mobile-auth-signup" data-i18n="auth.signup_free"><?php _ebt('auth.signup_free'); ?></button>
                </div>
                <?php else:
                    $mu = wp_get_current_user(); ?>
                <div style="padding:14px 24px;display:flex;align-items:center;gap:10px;border-bottom:1px solid rgba(255,255,255,.06)">
                    <?php echo get_avatar($mu->ID,32,'','',array('style'=>'border-radius:50%')); ?>
                    <div>
                        <div style="font-size:14px;font-weight:700;color:var(--bt-text)"><?php echo esc_html($mu->display_name); ?></div>
                        <div style="font-size:11px;color:var(--bt-text-3)"> <span data-i18n="auth.cloud_sync"><?php _ebt('auth.cloud_sync'); ?></span></div>
                    </div>
                </div>
                <a href="<?php echo esc_url($home_url.'portfolio/'); ?>" class="cp-mobile-link"><span data-i18n="auth.my_portfolio"><?php _ebt('auth.my_portfolio'); ?></span></a>
                <a href="<?php echo esc_url($home_url.'watchlist/'); ?>" class="cp-mobile-link">⭐ <span data-i18n="auth.my_watchlist"><?php _ebt('auth.my_watchlist'); ?></span></a>
                <?php endif; ?>

                <div class="cp-mobile-section" data-i18n="navdd.markets"><?php _ebt('navdd.markets'); ?></div>
                <a href="<?php echo esc_url($home_url.'crypto-markets/'); ?>" class="cp-mobile-link<?php echo (strpos($current_url,'crypto-markets')!==false)?' active':''; ?>"><span data-i18n="nav.crypto_prices"><?php _ebt('nav.crypto_prices'); ?></span></a>
                <a href="<?php echo esc_url($home_url.'forex-charts/'); ?>" class="cp-mobile-link<?php echo (strpos($current_url,'forex-charts')!==false)?' active':''; ?>"><span data-i18n="nav.forex_charts"><?php _ebt('nav.forex_charts'); ?></span></a>
                <a href="<?php echo esc_url($home_url.'exchanges/'); ?>" class="cp-mobile-link<?php echo (strpos($current_url,'exchanges')!==false)?' active':''; ?>"><span data-i18n="nav.exchanges_all"><?php _ebt('nav.exchanges_all'); ?></span></a>
                <a href="<?php echo esc_url($home_url.'gainers-losers/'); ?>" class="cp-mobile-link"><span data-i18n="nav.gainers_losers"><?php _ebt('nav.gainers_losers'); ?></span></a>

                <div class="cp-mobile-section" data-i18n="navdd.analysis_news"><?php _ebt('navdd.analysis_news'); ?></div>
                <a href="<?php echo esc_url($home_url.'market-analysis/'); ?>" class="cp-mobile-link<?php echo (strpos($current_url,'market-analysis')!==false)?' active':''; ?>"><span data-i18n="nav.ai_analysis"><?php _ebt('nav.ai_analysis'); ?></span></a>
                <a href="<?php echo esc_url($home_url.'financial-news/'); ?>" class="cp-mobile-link<?php echo (strpos($current_url,'financial-news')!==false)?' active':''; ?>"><span data-i18n="nav.latest_news"><?php _ebt('nav.latest_news'); ?></span></a>
                <a href="<?php echo esc_url($home_url.'market-blog/'); ?>" class="cp-mobile-link<?php echo (strpos($current_url,'blog')!==false)?' active':''; ?>"><span data-i18n="nav.blog_short"><?php _ebt('nav.blog_short'); ?></span></a>
                <a href="<?php echo esc_url($home_url.'trading-signals/'); ?>" class="cp-mobile-link"><span data-i18n="nav.trading_signals"><?php _ebt('nav.trading_signals'); ?></span></a>
                <a href="<?php echo esc_url($home_url.'economic-calendar/'); ?>" class="cp-mobile-link"><span data-i18n="nav.economic_calendar"><?php _ebt('nav.economic_calendar'); ?></span></a>

                <div class="cp-mobile-section" data-i18n="navdd.tools"><?php _ebt('navdd.tools'); ?></div>
                <a href="<?php echo esc_url($home_url.'tools/'); ?>" class="cp-mobile-link"><span data-i18n="nav.all_tools"><?php _ebt('nav.all_tools'); ?></span></a>
                <a href="<?php echo esc_url($home_url.'portfolio/'); ?>" class="cp-mobile-link"><span data-i18n="nav.portfolio_tracker"><?php _ebt('nav.portfolio_tracker'); ?></span></a>
                <a href="<?php echo esc_url($home_url.'watchlist/'); ?>" class="cp-mobile-link">⭐ <span data-i18n="nav.watchlist"><?php _ebt('nav.watchlist'); ?></span></a>
                <a href="<?php echo esc_url($home_url.'recommended-brokers/'); ?>" class="cp-mobile-link"><span data-i18n="nav.best_brokers"><?php _ebt('nav.best_brokers'); ?></span></a>

                <div class="cp-mobile-section" data-i18n="navdd.more"><?php _ebt('navdd.more'); ?></div>
                <a href="<?php echo esc_url($home_url.'about/'); ?>" class="cp-mobile-link"><span data-i18n="nav.about"><?php _ebt('nav.about'); ?></span></a>
                <a href="<?php echo esc_url($home_url.'contact/'); ?>" class="cp-mobile-link"><span data-i18n="nav.contact"><?php _ebt('nav.contact'); ?></span></a>
                <?php if (is_user_logged_in()): ?>
                <button onclick="btAuthLogout ? btAuthLogout() : (fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>',{method:'POST',body:new URLSearchParams({action:'bt_auth_logout'})}).then(function(){window.location.reload();}))" style="display:block;width:100%;text-align:left;padding:13px 24px;background:none;border:none;border-top:1px solid rgba(255,255,255,.06);color:var(--bt-danger);font-size:15px;font-weight:500;cursor:pointer;font-family:inherit;margin-top:8px"><span data-i18n="auth.logout"><?php _ebt('auth.logout'); ?></span></button>
                <?php endif; ?>
            </div>
        </nav>
        <?php
    }

    public static function render_footer() {
        // v119.28.16 — Old cp-footer fully deprecated. Always render the new
        // BT footer on every non-homepage page (the homepage gets its footer
        // from the [blockticker_landing] shortcode) and return.
        if ( ! is_front_page() && ! is_home() && class_exists( 'BT_Landing_Revamp' ) && method_exists( 'BT_Landing_Revamp', 'render_footer_only' ) ) {
            echo BT_Landing_Revamp::render_footer_only();
        }
        return;
        $site_name = get_option( 'bt_site_name', 'BlockTicker' );
        $home_url  = home_url( '/' );
        $year      = date( 'Y' );
        ?>
        <footer class="cp-footer">
            <div class="cp-footer-inner">
                <div class="cp-footer-grid">
                    <!-- Brand column -->
                    <div class="cp-footer-col cp-footer-brand">
                        <div class="cp-footer-logo">
                            <span class="cp-logo-tile">B</span>
                            <span class="cp-logo-text">BLOCK<span class="cp-logo-accent">TICKER</span></span>
                        </div>
                        <p class="cp-footer-desc" data-i18n="footer.tagline"><?php _ebt('footer.tagline'); ?></p>
                        <!-- Data sources -->
                        <div style="margin-top:14px;font-size:11px;color:var(--bt-text-4);line-height:2">
                            <span style="color:var(--bt-text-3)" data-i18n="footer.data_label"><?php _ebt('footer.data_label'); ?></span>
                            <a href="https://coingecko.com" target="_blank" rel="noopener" style="color:var(--bt-text-4);text-decoration:none;margin-left:4px">CoinGecko</a> ·
                            <a href="https://frankfurter.app" target="_blank" rel="noopener" style="color:var(--bt-text-4);text-decoration:none">Frankfurter/ECB</a> ·
                            <a href="https://tradingview.com" target="_blank" rel="noopener" style="color:var(--bt-text-4);text-decoration:none">TradingView</a>
                        </div>
                    </div>

                    <!-- Markets column -->
                    <div class="cp-footer-col">
                        <h4 data-i18n="footer.markets_col"><?php _ebt('footer.markets_col'); ?></h4>
                        <a href="<?php echo $home_url; ?>crypto-markets/" data-i18n="nav.crypto_prices"><?php _ebt('nav.crypto_prices'); ?></a>
                        <a href="<?php echo $home_url; ?>forex-charts/" data-i18n="nav.forex_charts"><?php _ebt('nav.forex_charts'); ?></a>
                        <a href="<?php echo $home_url; ?>exchanges/" data-i18n="nav.exchanges_all"><?php _ebt('nav.exchanges_all'); ?></a>
                        <a href="<?php echo $home_url; ?>gainers-losers/" data-i18n="nav.gainers_losers"><?php _ebt('nav.gainers_losers'); ?></a>
                        <a href="<?php echo $home_url; ?>trading-signals/" data-i18n="nav.trading_signals"><?php _ebt('nav.trading_signals'); ?></a>
                        <a href="<?php echo $home_url; ?>economic-calendar/" data-i18n="nav.economic_calendar"><?php _ebt('nav.economic_calendar'); ?></a>
                        <a href="<?php echo $home_url; ?>tools/" data-i18n="footer.tools_converter"><?php _ebt('footer.tools_converter'); ?></a>
                    </div>

                    <!-- Content column -->
                    <div class="cp-footer-col">
                        <h4 data-i18n="footer.analysis_col"><?php _ebt('footer.analysis_col'); ?></h4>
                        <a href="<?php echo $home_url; ?>financial-news/" data-i18n="nav.latest_news"><?php _ebt('nav.latest_news'); ?></a>
                        <a href="<?php echo $home_url; ?>market-analysis/" data-i18n="nav.ai_analysis"><?php _ebt('nav.ai_analysis'); ?></a>
                        <a href="<?php echo $home_url; ?>market-blog/" data-i18n="nav.blog_short"><?php _ebt('nav.blog_short'); ?></a>
                        <a href="<?php echo $home_url; ?>learn/" data-i18n="nav.learn_hub"><?php _ebt('nav.learn_hub'); ?></a>
                        <a href="<?php echo $home_url; ?>recommended-brokers/" data-i18n="nav.best_brokers"><?php _ebt('nav.best_brokers'); ?></a>
                    </div>

                    <!-- Company column -->
                    <div class="cp-footer-col">
                        <h4 data-i18n="footer.company_col"><?php _ebt('footer.company_col'); ?></h4>
                        <a href="<?php echo $home_url; ?>about/" data-i18n="nav.about"><?php _ebt('nav.about'); ?></a>
                        <a href="<?php echo $home_url; ?>contact/" data-i18n="nav.contact"><?php _ebt('nav.contact'); ?></a>
                        <a href="<?php echo $home_url; ?>editorial-policy/" data-i18n="nav.editorial_policy"><?php _ebt('nav.editorial_policy'); ?></a>
                        <a href="<?php echo $home_url; ?>write-for-us/" data-i18n="nav.write_for_us"><?php _ebt('nav.write_for_us'); ?></a>
                        <a href="<?php echo $home_url; ?>api-docs/"> API Docs</a>
                        <a href="<?php echo $home_url; ?>widgets/"> Widgets</a>
                        <a href="<?php echo $home_url; ?>privacy-policy/">Privacy Policy</a>
                    </div>
                </div>

                <!-- v63: Data Sources Trust Band — surfaces credibility signals -->
                <div class="bt-trust-band" style="margin-top:32px;margin-bottom:0;padding:24px 0;border-radius:0">
                    <div class="bt-trust-inner">
                        <div>
                            <p class="bt-trust-title"><span data-i18n="footer.powered_by"><?php _ebt('footer.powered_by'); ?></span><strong data-i18n="footer.live_data"><?php _ebt('footer.live_data'); ?></strong></p>
                        </div>
                        <div class="bt-trust-sources">
                            <a class="bt-trust-source" href="https://www.coingecko.com" target="_blank" rel="noopener" title="CoinGecko — crypto prices, volumes, market caps (17,000+ tokens indexed)"><span class="bt-trust-source-dot"></span>CoinGecko</a>
                            <a class="bt-trust-source" href="https://www.ecb.europa.eu/stats/policy_and_exchange_rates/euro_reference_exchange_rates" target="_blank" rel="noopener" title="European Central Bank reference rates via Frankfurter.app"><span class="bt-trust-source-dot"></span>ECB · Frankfurter</a>
                            <a class="bt-trust-source" href="https://alternative.me/crypto/fear-and-greed-index/" target="_blank" rel="noopener" title="Crypto Fear & Greed Index"><span class="bt-trust-source-dot"></span>Fear &amp; Greed</a>
                            <a class="bt-trust-source" href="https://www.coindesk.com" target="_blank" rel="noopener" title="CoinDesk — crypto news &amp; analysis"><span class="bt-trust-source-dot"></span>CoinDesk</a>
                            <a class="bt-trust-source" href="https://www.reuters.com/markets/" target="_blank" rel="noopener" title="Reuters financial news"><span class="bt-trust-source-dot"></span>Reuters</a>
                            <a class="bt-trust-source" href="https://www.fxstreet.com" target="_blank" rel="noopener" title="FXStreet — forex signals &amp; analysis"><span class="bt-trust-source-dot"></span>FXStreet</a>
                            <a class="bt-trust-source" href="https://www.tradingview.com" target="_blank" rel="noopener" title="TradingView — interactive charts"><span class="bt-trust-source-dot"></span>TradingView</a>
                        </div>
                        <div class="bt-trust-policies">
                            <a href="<?php echo $home_url; ?>editorial-policy/" data-i18n="footer.editorial_link"><?php _ebt('footer.editorial_link'); ?></a>
                            <a href="<?php echo $home_url; ?>privacy-policy/" data-i18n="footer.privacy_gdpr"><?php _ebt('footer.privacy_gdpr'); ?></a>
                            <a href="<?php echo $home_url; ?>about/" data-i18n="footer.meet_analysts"><?php _ebt('footer.meet_analysts'); ?></a>
                        </div>
                    </div>
                </div>

                <div class="cp-footer-bottom">
                    <p>&copy; <?php echo $year; ?> <?php echo esc_html( $site_name ); ?>. <span data-i18n="footer.all_rights"><?php _ebt('footer.all_rights'); ?></span> <span data-i18n="footer.disclaimer"><?php _ebt('footer.disclaimer'); ?></span></p>
                </div>
            </div>
        </footer>
        <!-- Dropdown JS handled in page head -->
        <!-- Bottom ticker on every page (singleton — only renders once) -->
        <?php if ( ! defined('BT_BOTTOM_TICKER_RENDERED') ) {
            define('BT_BOTTOM_TICKER_RENDERED', true);
            echo do_shortcode('[fxlm_bottom_ticker]');
        } ?>

        <!-- Global chart fullscreen modal -->
        <div class="bt-chart-modal-overlay" id="bt-chart-modal" onclick="if(event.target===this)btCloseChart()">
            <div class="bt-chart-modal-box">
                <div class="bt-chart-modal-header">
                    <span class="bt-chart-modal-title" id="bt-chart-modal-title">Chart</span>
                    <button class="bt-chart-modal-close" onclick="btCloseChart()"></button>
                </div>
                <div class="bt-chart-modal-body" id="bt-chart-modal-body"></div>
            </div>
        </div>
        <script>
        function btOpenChart(symbol, title) {
            document.getElementById('bt-chart-modal-title').textContent = (title || symbol).replace(/^[A-Z]+:/,'');
            var body = document.getElementById('bt-chart-modal-body');
            body.innerHTML = '';

            /* Create a wrapper that btBuildControls + btRebuildWidget can use */
            var uid = 'tv_modal_' + Date.now();
            var wrap = document.createElement('div');
            wrap.className = 'fxlm-chart-wrap fxlm-tv-lazy';
            wrap.dataset.symbol   = symbol;
            wrap.dataset.interval = 'D';
            wrap.dataset.height   = Math.max(400, window.innerHeight - 160);
            wrap.style.height     = wrap.dataset.height + 'px';
            wrap.style.display    = 'flex';
            wrap.style.flexDirection = 'column';

            var host = document.createElement('div');
            host.id = uid;
            host.setAttribute('data-tv-host','');
            host.style.flex = '1';
            wrap.appendChild(host);
            body.appendChild(wrap);

            /* Use advanced init if available, otherwise fallback */
            if (typeof btBuildControls === 'function' && typeof btRebuildWidget === 'function') {
                var state = { symbol: symbol, interval: 'D', style: '1', height: parseInt(wrap.dataset.height), compare: null };
                window.btLoadTV().then(function(){
                    btBuildControls(wrap, state);
                    btRebuildWidget(wrap, state);
                });
            } else if (typeof window.btInitTvWidget === 'function') {
                window.btLoadTV && window.btLoadTV().then(function(){ window.btInitTvWidget(wrap); });
            } else {
                /* Bare iframe fallback */
                host.innerHTML = '<iframe src="https://s.tradingview.com/widgetembed/?symbol=' + encodeURIComponent(symbol) +
                    '&interval=D&theme=dark&style=1&timezone=Etc%2FUTC&locale=en&hidetoptoolbar=0&saveimage=1&withdateranges=1" allowtransparency="true" allowfullscreen="" frameborder="0" style="width:100%;height:100%"></iframe>';
            }

            document.getElementById('bt-chart-modal').classList.add('open');
            document.body.style.overflow = 'hidden';
        }
        function btCloseChart() {
            document.getElementById('bt-chart-modal').classList.remove('open');
            document.getElementById('bt-chart-modal-body').innerHTML = '';
            document.body.style.overflow = '';
        }
        document.addEventListener('keydown', function(e){ if(e.key==='Escape') btCloseChart(); });
        </script>

        <!-- ── Language Switcher CSS ─────────────────────────── -->
        <style>
        .bt-lang-switcher{position:relative;display:inline-flex;align-items:center;margin-left:12px;flex-shrink:0}
        .bt-lang-btn{display:inline-flex;align-items:center;gap:6px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);border-radius:0;padding:5px 10px;cursor:pointer;font-size:12px;font-weight:600;color:#c8d6e5;transition:all .2s;font-family:inherit;white-space:nowrap}
        .bt-lang-btn:hover{background:rgba(0,255,102,.08);border-color:rgba(0,255,102,.25);color:var(--bt-accent)}
        .bt-lang-flag{font-size:15px;line-height:1}
        .bt-lang-label{font-size:12px;font-weight:700;letter-spacing:.3px}
        .bt-lang-chevron{transition:transform .2s;color:var(--bt-text-3);flex-shrink:0}
        .bt-lang-btn[aria-expanded="true"] .bt-lang-chevron{transform:rotate(180deg)}
        .bt-lang-dropdown{display:none;position:absolute;top:calc(100% + 6px);right:0;background:#111628;border:1px solid rgba(255,255,255,.1);border-radius:0;box-shadow:0 16px 48px rgba(0,0,0,.7);min-width:200px;z-index:9999999;overflow:hidden;animation:btLangSlide .15s ease}
        @keyframes btLangSlide{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:translateY(0)}}
        .bt-lang-dropdown.open{display:block}
        .bt-lang-search-wrap{display:flex;align-items:center;gap:8px;padding:10px 12px;border-bottom:1px solid rgba(255,255,255,.06)}
        .bt-lang-search{background:none;border:none;outline:none;color:var(--bt-text);font-size:13px;width:100%;font-family:inherit}
        .bt-lang-search::placeholder{color:var(--bt-text-4)}
        .bt-lang-list{max-height:240px;overflow-y:auto;padding:6px 0}
        .bt-lang-list::-webkit-scrollbar{width:4px}
        .bt-lang-list::-webkit-scrollbar-track{background:transparent}
        .bt-lang-list::-webkit-scrollbar-thumb{background:#1e2440;border-radius:0}
        .bt-lang-item{display:flex;align-items:center;gap:10px;padding:9px 14px;cursor:pointer;font-size:13px;color:var(--bt-text-2);transition:background .15s}
        .bt-lang-item:hover{background:rgba(255,255,255,.05);color:var(--bt-text)}
        .bt-lang-item.active{color:var(--bt-accent)}
        .bt-lang-item.active .bt-lang-item-check{opacity:1}
        .bt-lang-item-flag{font-size:16px;flex-shrink:0;width:22px;text-align:center}
        .bt-lang-item-name{flex:1}
        .bt-lang-item-check{opacity:0;color:var(--bt-accent);font-size:13px;font-weight:700}
        .bt-lang-item.hidden{display:none}
        @media(max-width:768px){.bt-lang-label{display:none}.bt-lang-btn{padding:5px 8px}}
        /* Global Search */
        .bt-search-btn{background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);border-radius:0;padding:6px 10px;cursor:pointer;color:#c8d6e5;display:inline-flex;align-items:center;transition:all .2s;line-height:1}
        .bt-search-btn:hover{background:rgba(0,255,102,.08);border-color:rgba(0,255,102,.3);color:var(--bt-accent)}
        .bt-search-overlay{position:fixed;inset:0;z-index:99998;background:rgba(0,0,0,.7);backdrop-filter:blur(4px);display:flex;align-items:flex-start;justify-content:center;padding-top:70px}
        .bt-search-box{background:var(--bt-bg-elev);border:1px solid rgba(255,255,255,.12);border-radius:0;width:100%;max-width:560px;overflow:hidden;box-shadow:0 24px 80px rgba(0,0,0,.6);margin:0 16px}
        .bt-search-row{display:flex;align-items:center;gap:10px;padding:14px 18px;border-bottom:1px solid rgba(255,255,255,.07)}
        #bt-search-input{flex:1;background:none;border:none;outline:none;color:var(--bt-text);font-size:16px;font-family:inherit;min-width:0}
        #bt-search-input::placeholder{color:var(--bt-text-4)}
        .bt-search-esc{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);color:var(--bt-text-3);border-radius:0;padding:2px 7px;font-size:11px;cursor:pointer;font-family:inherit;flex-shrink:0}
        #bt-search-results{max-height:380px;overflow-y:auto}
        .bt-sr-section{padding:8px 18px 4px;font-size:10px;font-weight:700;color:var(--bt-text-4);text-transform:uppercase;letter-spacing:.6px}
        .bt-sr-item{display:flex;align-items:center;gap:12px;padding:10px 18px;cursor:pointer;text-decoration:none;border-bottom:1px solid rgba(255,255,255,.03);transition:background .12s;color:inherit}
        .bt-sr-item:hover{background:rgba(0,255,102,.06)}
        .bt-sr-avatar{width:30px;height:30px;border-radius:50%;flex-shrink:0;object-fit:cover}
        .bt-sr-ph{width:30px;height:30px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:800;color:#0A0B0D}
        .bt-sr-label{flex:1;min-width:0}
        .bt-sr-name{font-size:13px;font-weight:600;color:var(--bt-text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .bt-sr-sub{font-size:11px;color:var(--bt-text-3)}
        .bt-sr-right{text-align:right;flex-shrink:0}
        .bt-sr-price{font-size:12px;font-weight:700;color:var(--bt-text)}
        .bt-sr-chg-up{font-size:11px;color:var(--bt-accent)}
        .bt-sr-chg-dn{font-size:11px;color:var(--bt-danger)}
        .bt-sr-empty{padding:32px;text-align:center;color:var(--bt-text-3);font-size:14px}
        .bt-search-hint{padding:10px 18px;font-size:11px;color:var(--bt-text-4);display:flex;gap:18px;background:rgba(0,0,0,.2)}
        </style>

        <!-- ── Language Switcher + i18n JS ───────────────────── -->
        <script>
        (function(){
        /* Language + string data from WP options (injected by BT_I18N::inject_js_strings) */
        var BT_LANGS    = (window.BT_LANGS   || []);
        var BT_STRINGS  = (window.BT_STRINGS || {});

        var BT_CURRENT_LANG = localStorage.getItem('bt_lang') || 'en';

        function btApplyLang(code) {
            BT_CURRENT_LANG = code;
            localStorage.setItem('bt_lang', code);
            document.querySelectorAll('[data-i18n]').forEach(function(el) {
                var key = el.getAttribute('data-i18n');
                if (BT_STRINGS[key] && BT_STRINGS[key][code]) el.textContent = BT_STRINGS[key][code];
            });
            document.querySelectorAll('[data-i18n-ph]').forEach(function(el) {
                var key = el.getAttribute('data-i18n-ph');
                if (BT_STRINGS[key] && BT_STRINGS[key][code]) el.setAttribute('placeholder', BT_STRINGS[key][code]);
            });
            document.querySelectorAll('[data-i18n-html]').forEach(function(el) {
                var key = el.getAttribute('data-i18n-html');
                if (BT_STRINGS[key] && BT_STRINGS[key][code]) el.innerHTML = BT_STRINGS[key][code];
            });
            document.documentElement.setAttribute('lang', code);
        }

        function btBuildLangList(filter) {
            var list = document.getElementById('bt-lang-list');
            if (!list) return;
            list.innerHTML = '';
            filter = (filter || '').toLowerCase();
            BT_LANGS.forEach(function(lang) {
                if (filter && lang.name.toLowerCase().indexOf(filter) === -1 && lang.code.indexOf(filter) === -1) return;
                var item = document.createElement('div');
                item.className = 'bt-lang-item' + (lang.code === BT_CURRENT_LANG ? ' active' : '');
                item.setAttribute('role', 'option');
                item.innerHTML = '<span class="bt-lang-item-flag">' + lang.flag + '</span><span class="bt-lang-item-name">' + lang.name + '</span><span class="bt-lang-item-check">&#x2713;</span>';
                item.addEventListener('click', function() { btSelectLang(lang.code, lang.flag, lang.label); });
                list.appendChild(item);
            });
        }

        function btSelectLang(code, flag, label) {
            document.getElementById('bt-lang-flag').textContent  = flag;
            document.getElementById('bt-lang-label').textContent = label;
            btCloseLangDropdown();
            btApplyLang(code);
            btBuildLangList(''); // rebuild to update active state
        }

        /* ── 5. Dropdown open/close ─────────────────────────── */
        function btOpenLangDropdown() {
            var dd = document.getElementById('bt-lang-dropdown');
            var btn = document.getElementById('bt-lang-btn');
            if (!dd) return;
            dd.classList.add('open');
            btn.setAttribute('aria-expanded','true');
            btBuildLangList('');
            setTimeout(function(){ document.getElementById('bt-lang-search').focus(); }, 80);
        }
        function btCloseLangDropdown() {
            var dd = document.getElementById('bt-lang-dropdown');
            var btn = document.getElementById('bt-lang-btn');
            if (!dd) return;
            dd.classList.remove('open');
            btn.setAttribute('aria-expanded','false');
            document.getElementById('bt-lang-search').value = '';
        }

        /* ── 6. Wire up events ──────────────────────────────── */
        document.addEventListener('DOMContentLoaded', function() {
            var btn    = document.getElementById('bt-lang-btn');
            var search = document.getElementById('bt-lang-search');
            var dd     = document.getElementById('bt-lang-dropdown');

            if (!btn) return;

            // Set initial flag/label from saved lang
            var saved = BT_LANGS.filter(function(l){ return l.code === BT_CURRENT_LANG; })[0] || BT_LANGS[0];
            document.getElementById('bt-lang-flag').textContent  = saved.flag;
            document.getElementById('bt-lang-label').textContent = saved.label;

            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                dd.classList.contains('open') ? btCloseLangDropdown() : btOpenLangDropdown();
            });
            if (search) {
                search.addEventListener('input', function() {
                    btBuildLangList(this.value);
                });
                search.addEventListener('click', function(e){ e.stopPropagation(); });
            }
            document.addEventListener('click', function(e) {
                if (!document.getElementById('bt-lang-switcher').contains(e.target)) {
                    btCloseLangDropdown();
                }
            });
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') btCloseLangDropdown();
            });

            // Apply saved language on load
            if (BT_CURRENT_LANG !== 'en') btApplyLang(BT_CURRENT_LANG);
        });
        })();
        </script>

        <!-- ── Global Search JS ── -->
        <script>
        (function(){
            var overlay = document.getElementById('bt-search-modal');
            var openBtn = document.getElementById('bt-search-open');
            var input   = document.getElementById('bt-search-input');
            var results = document.getElementById('bt-search-results');
            if (!overlay || !openBtn) return;

            var homeUrl = '<?php echo esc_js( trailingslashit( home_url() ) ); ?>';

            function openSearch() {
                overlay.style.display = 'flex';
                setTimeout(function(){ if(input) input.focus(); }, 60);
            }
            function closeSearch() {
                overlay.style.display = 'none';
                if(input){ input.value = ''; }
                if(results){ results.innerHTML = ''; }
            }

            openBtn.addEventListener('click', openSearch);
            overlay.addEventListener('click', function(e){ if(e.target === overlay) closeSearch(); });
            document.getElementById('bt-search-close').addEventListener('click', closeSearch);
            document.addEventListener('keydown', function(e){
                if((e.ctrlKey||e.metaKey) && e.key === 'k'){ e.preventDefault(); openSearch(); }
                if(e.key === 'Escape' && overlay.style.display === 'flex') closeSearch();
            });

            function fmtPrice(p){
                if(!p||p===0) return '';
                if(p<0.001) return '$'+p.toFixed(6);
                if(p<1) return '$'+p.toFixed(4);
                return '$'+p.toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});
            }
            function fmtChg(c){
                var v=parseFloat(c)||0;
                return v>=0
                    ? '<span class="bt-sr-chg-up">▲'+Math.abs(v).toFixed(2)+'%</span>'
                    : '<span class="bt-sr-chg-dn">▼'+Math.abs(v).toFixed(2)+'%</span>';
            }

            var FOREX_PAIRS = ['EUR/USD','GBP/USD','USD/JPY','AUD/USD','USD/CAD','USD/CHF','NZD/USD','EUR/GBP','EUR/JPY','GBP/JPY','USD/CNY','USD/INR','USD/MXN','USD/BRL'];

            input.addEventListener('input', function(){
                var q = this.value.trim().toLowerCase();
                if(q.length < 1){ results.innerHTML=''; return; }

                var coins = window.BT_CONV_COINS || {};
                var html = '', count = 0;

                // Search cryptos
                var matches = Object.entries(coins).filter(function(kv){
                    return kv[1].name.toLowerCase().includes(q) || kv[1].symbol.toLowerCase().includes(q) || kv[0].includes(q);
                }).slice(0,6);

                if(matches.length){
                    html += '<div class="bt-sr-section">Cryptocurrencies</div>';
                    matches.forEach(function(kv){
                        var id=kv[0], c=kv[1];
                        var init = c.symbol.slice(0,2).toUpperCase();
                        html += '<a class="bt-sr-item" href="'+homeUrl+'crypto/'+id+'/">'
                            + '<div class="bt-sr-ph" style="background:linear-gradient(135deg,var(--bt-accent),var(--bt-accent))">'+init+'</div>'
                            + '<div class="bt-sr-label"><div class="bt-sr-name">'+c.name+'</div><div class="bt-sr-sub">'+c.symbol+'</div></div>'
                            + '<div class="bt-sr-right"><div class="bt-sr-price">'+fmtPrice(c.price)+'</div>'
                            + (c.change ? fmtChg(c.change) : '') + '</div></a>';
                        count++;
                    });
                }

                // Search forex pairs
                var fxMatches = FOREX_PAIRS.filter(function(p){
                    return p.toLowerCase().replace('/','').includes(q) || p.toLowerCase().includes(q);
                }).slice(0,4);

                if(fxMatches.length){
                    html += '<div class="bt-sr-section">Forex Pairs</div>';
                    fxMatches.forEach(function(p){
                        var slug = p.toLowerCase().replace('/','-');
                        html += '<a class="bt-sr-item" href="'+homeUrl+'forex/'+slug+'/">'
                            + '<div class="bt-sr-ph" style="background:linear-gradient(135deg,var(--bt-accent),#6366f1)">FX</div>'
                            + '<div class="bt-sr-label"><div class="bt-sr-name">'+p+'</div><div class="bt-sr-sub">Forex Pair</div></div>'
                            + '</a>';
                        count++;
                    });
                }

                if(count===0){
                    html = '<div class="bt-sr-empty">No results for "'+q.replace(/</g,'&lt;')+'"<br><small style="color:var(--bt-text-4)">Try: bitcoin, ETH, EUR/USD</small></div>';
                }
                results.innerHTML = html;
            });

            // Keyboard nav in results
            input.addEventListener('keydown', function(e){
                if(e.key === 'Enter'){
                    var first = results.querySelector('.bt-sr-item');
                    if(first){ window.location.href = first.href; }
                }
            });
        })();
        </script>
        <script>
        /* User account menu dropdown */
        window.btNavUserMenu = function() {
            var menu = document.getElementById('bt-nav-usermenu');
            if(!menu) return;
            menu.style.display = menu.style.display === 'none' ? 'block' : 'none';
        };
        window.btAuthLogout = function(){
            fetch('<?php echo esc_url(admin_url("admin-ajax.php")); ?>', {
                method:'POST', body: new URLSearchParams({action:'bt_auth_logout'})
            }).then(function(){ window.location.reload(); });
        };
        // Close user menu on outside click
        document.addEventListener('click', function(e){
            var wrap = document.getElementById('bt-nav-avatar-wrap');
            var menu = document.getElementById('bt-nav-usermenu');
            if(wrap && menu && !wrap.contains(e.target)){
                menu.style.display = 'none';
            }
        });
        </script>
        <?php
    }
}
