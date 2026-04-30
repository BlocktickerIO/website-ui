<?php
/**
 * BlockTicker Landing Page Revamp v3.
 * Exact mockup HTML inside .btlp wrapper. All issues fixed:
 * - Button text visible (CSS !important)
 * - Correct formula + receipts HTML (match mockup exactly)
 * - Mockup footer inside .btlp, plugin footer hidden via body class CSS
 * - Real CTA form
 * - BT_Utils for data fetching
 * - Google Fonts enqueued
 * - body class page-slug-landing-revamp for nav/footer CSS targeting
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class BT_LandingRevamp {
    private static bool $assets_enqueued = false;

    public static function init(): void {
        add_shortcode( 'blockticker_landing', array( __CLASS__, 'render' ) );
        add_filter( 'body_class', array( __CLASS__, 'add_body_class' ) );

        // v119.28.24 — Reusable widget-style shortcodes per user request:
        //   "ticker must be a separate widget"
        //   "landing page and all pages must use widget not hardcoded php/html"
        add_shortcode( 'bt_ticker',           array( __CLASS__, 'shortcode_ticker' ) );
        add_shortcode( 'bt_disclaimer_strip', array( __CLASS__, 'shortcode_disclaimer' ) );
        add_shortcode( 'bt_nav',              array( __CLASS__, 'shortcode_nav' ) );
        add_shortcode( 'bt_chrome',           array( __CLASS__, 'shortcode_chrome' ) );
        add_shortcode( 'bt_footer',           array( __CLASS__, 'shortcode_footer' ) );
    }

    /**
     * v119.28.24 — Shortcode wrappers around the chrome components.
     * These let editors place the ticker, disclaimer, nav, footer anywhere
     * on a page or in a widget area. Each shortcode enqueues the chrome
     * assets on demand so styling works regardless of where it's used.
     */
    public static function shortcode_ticker( $atts = array() ): string {
        self::enqueue_assets();
        $ticker_rows = self::default_ticker_rows();
        ob_start();
        ?>
<div class="ticker btlp" aria-label="Live market prices">
  <div class="ticker__track">
    <span class="ticker__live">● LIVE MARKETS</span>
    <?php foreach ( $ticker_rows as $r ) : ?>
      <span class="ticker__item"><span class="ticker__sym"><?php echo esc_html( $r['sym'] ); ?></span> <?php echo esc_html( $r['val'] ); ?> <span class="ticker__chg--<?php echo esc_attr( $r['cls'] ); ?>"><?php echo esc_html( $r['chg'] ); ?></span></span>
    <?php endforeach; ?>
    <span class="ticker__live">● LIVE MARKETS</span>
    <?php foreach ( $ticker_rows as $r ) : ?>
      <span class="ticker__item"><span class="ticker__sym"><?php echo esc_html( $r['sym'] ); ?></span> <?php echo esc_html( $r['val'] ); ?> <span class="ticker__chg--<?php echo esc_attr( $r['cls'] ); ?>"><?php echo esc_html( $r['chg'] ); ?></span></span>
    <?php endforeach; ?>
  </div>
</div>
        <?php
        return (string) ob_get_clean();
    }

    public static function shortcode_disclaimer( $atts = array() ): string {
        self::enqueue_assets();
        ob_start();
        ?>
<div class="disclaim-strip btlp" role="note">
  <div class="disclaim-strip__inner">
    <span class="disclaim-strip__ico" aria-hidden="true">⚠</span>
    <span class="disclaim-strip__txt"><strong>Not financial advice.</strong> All content is educational. Trading involves risk of loss. Past performance does not guarantee future results.</span>
    <a href="<?php echo esc_url( home_url('/methodology/') ); ?>" class="disclaim-strip__link">View methodology &amp; risk →</a>
  </div>
</div>
        <?php
        return (string) ob_get_clean();
    }

    public static function shortcode_nav( $atts = array() ): string {
        // Render just the nav portion of chrome by reusing render_chrome_only
        // but stripped of the disclaimer + ticker (which have their own shortcodes).
        $full = self::render_chrome_only();
        // Strip everything before <nav> and after </nav>
        if ( preg_match( '#<nav class="nav".*?</nav>#s', $full, $m ) ) {
            return '<div class="btlp">' . $m[0] . '</div>';
        }
        return $full;
    }

    public static function shortcode_chrome( $atts = array() ): string {
        return self::render_chrome_only();
    }

    public static function shortcode_footer( $atts = array() ): string {
        self::enqueue_assets();
        return self::render_footer_only();
    }

    /** Default ticker rows used by the [bt_ticker] shortcode (live data overrides via JS). */
    private static function default_ticker_rows(): array {
        // Pull live data from cache when available
        $crypto_raw = get_option( 'fxlm_crypto_data', '' );
        $forex_raw  = get_option( 'fxlm_forex_data',  '' );
        $crypto = is_string( $crypto_raw ) ? json_decode( $crypto_raw, true ) : (array) $crypto_raw;
        $forex  = is_string( $forex_raw )  ? json_decode( $forex_raw,  true ) : (array) $forex_raw;

        // Index live coins by symbol
        $coin_by_sym = array();
        if ( is_array( $crypto ) && ! empty( $crypto['coins'] ) ) {
            foreach ( (array) $crypto['coins'] as $coin ) {
                if ( empty( $coin['symbol'] ) ) { continue; }
                $coin_by_sym[ strtoupper( $coin['symbol'] ) ] = $coin;
            }
        }

        $rates = ( is_array( $forex ) && ! empty( $forex['rates'] ) ) ? (array) $forex['rates'] : array();

        $row = function( $sym, $fallback_val, $fallback_chg, $fallback_cls ) use ( $coin_by_sym, $rates ) {
            // Crypto symbols
            if ( in_array( $sym, array( 'BTC','ETH','SOL','AVAX','XRP' ), true ) && isset( $coin_by_sym[ $sym ] ) ) {
                $coin = $coin_by_sym[ $sym ];
                $price = isset( $coin['current_price'] ) ? floatval( $coin['current_price'] ) : 0;
                $chg   = isset( $coin['price_change_percentage_24h'] ) ? floatval( $coin['price_change_percentage_24h'] ) : 0;
                if ( $price > 0 ) {
                    $val = $price >= 1000 ? '$' . number_format( $price, 0 ) : ( $price >= 1 ? '$' . number_format( $price, 2 ) : '$' . number_format( $price, 4 ) );
                    return array( 'sym'=>$sym, 'val'=>$val, 'chg'=>( $chg>=0?'+':'').number_format($chg,2).'%', 'cls'=>$chg>=0?'up':'down' );
                }
            }
            // Forex
            if ( $sym === 'EUR/USD' && ! empty( $rates['EUR'] ) && $rates['EUR'] > 0 ) {
                return array( 'sym'=>'EUR/USD', 'val'=>number_format( 1 / floatval( $rates['EUR'] ), 4 ), 'chg'=>$fallback_chg, 'cls'=>$fallback_cls );
            }
            if ( $sym === 'USD/JPY' && ! empty( $rates['JPY'] ) ) {
                return array( 'sym'=>'USD/JPY', 'val'=>number_format( floatval( $rates['JPY'] ), 2 ), 'chg'=>$fallback_chg, 'cls'=>$fallback_cls );
            }
            return array( 'sym'=>$sym, 'val'=>$fallback_val, 'chg'=>$fallback_chg, 'cls'=>$fallback_cls );
        };

        return array(
            $row( 'BTC',     '$67,892',     '+2.45%', 'up' ),
            $row( 'ETH',     '$3,456',      '-1.32%', 'down' ),
            $row( 'SOL',     '$183.67',     '+4.21%', 'up' ),
            $row( 'XAU/USD', '$2,341.87',   '+0.85%', 'up' ),
            $row( 'EUR/USD', '1.0834',      '-0.12%', 'down' ),
            $row( 'DXY',     '104.27',      '+0.34%', 'up' ),
            $row( 'AVAX',    '$38.12',      '+3.07%', 'up' ),
            $row( 'USD/JPY', '152.34',      '-0.21%', 'down' ),
        );
    }


    public static function add_body_class( array $c ): array {
        // v119.28.3: previous logic relied on `is_page('landing-revamp')`,
        // which only matches when the WP page slug is *exactly*
        // "landing-revamp". On the live site the operator may have created
        // the page with a different slug (or this could be a custom post
        // type, a Cornerstone-built page, or a non-singular query that
        // contains the shortcode in a widget). Switch to detecting the
        // shortcode in the queried post content and add BOTH classes:
        //   - bt-landing-page  (the new canonical name; CSS uses this)
        //   - page-slug-landing-revamp  (legacy name, kept for back-compat)
        global $post;
        $has_shortcode = is_singular()
            && is_object( $post )
            && isset( $post->post_content )
            && has_shortcode( (string) $post->post_content, 'blockticker_landing' );

        if ( $has_shortcode || is_page( 'landing-revamp' ) ) {
            $c[] = 'bt-landing-page';
            $c[] = 'page-slug-landing-revamp';
        }
        return $c;
    }

    public static function enqueue_assets(): void {
        if ( self::$assets_enqueued ) return;
        self::$assets_enqueued = true;
        wp_enqueue_style( 'btlp-gfonts',
            'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@400;500;600;700&display=swap',
            array(), null );
        wp_enqueue_style( 'bt-landing-revamp', BT_URL.'assets/css/landing-revamp.css', array('btlp-gfonts'), BT_VERSION );
        wp_enqueue_style( 'bt-typography', BT_URL.'assets/css/typography.css', array('bt-landing-revamp'), BT_VERSION );
        // v119.28.31 — Unified design tokens & component library (loaded LAST).
        wp_enqueue_style( 'bt-tokens', BT_URL.'assets/css/bt-tokens.css', array('bt-typography'), BT_VERSION );
        wp_enqueue_script( 'bt-landing-revamp', BT_URL.'assets/js/landing-revamp.js', array(), BT_VERSION, true );
    }

    private static function coins(): array {
        $raw = class_exists('BT_Utils') ? BT_Utils::get_option_json('fxlm_crypto_data') : json_decode(get_option('fxlm_crypto_data','{}'),true);
        $out = [];
        foreach ((array)($raw['coins'] ?? []) as $c) { $s=strtoupper($c['symbol']??''); if($s) $out[$s]=$c; }
        return $out;
    }
    private static function rates(): array {
        $raw = class_exists('BT_Utils') ? BT_Utils::get_option_json('fxlm_forex_data') : json_decode(get_option('fxlm_forex_data','{}'),true);
        return (array)($raw['rates'] ?? []);
    }
    private static function fp(array $c, string $s, string $fb): string {
        if (!isset($c[$s]['current_price'])) return $fb;
        $p=floatval($c[$s]['current_price']);
        return '$'.number_format($p,$p>=1000?0:($p>=10?2:4));
    }
    private static function fc(array $c, string $s, float $fb): array {
        $v=isset($c[$s]['price_change_percentage_24h'])?floatval($c[$s]['price_change_percentage_24h']):$fb;
        return [$v,($v>=0?'+':'').number_format($v,2).'%',$v>=0?'up':'down'];
    }

    /** Compute auth payload for template (initial, name, email, login state). */
    private static function auth_payload(): array {
        $is_in = is_user_logged_in();
        $u = $is_in ? wp_get_current_user() : null;
        $name = $u ? trim( $u->display_name ?: $u->user_login ) : '';
        $email = $u ? (string) $u->user_email : '';
        $first = $u ? trim( (string) ( $u->first_name ?? '' ) ) : '';
        $short = $first ?: ( $name ? strtok( $name, ' ' ) : '' );
        $initial = $short !== '' ? strtoupper( function_exists( 'mb_substr' ) ? mb_substr( $short, 0, 1 ) : substr( $short, 0, 1 ) ) : 'A';
        return [
            'is_in'   => $is_in,
            'auth'    => $is_in ? 'logged-in' : 'logged-out',
            'name'    => $name,
            'short'   => $short,
            'email'   => $email,
            'initial' => $initial,
        ];
    }

    public static function render($atts=[]): string {
        self::enqueue_assets();
        $brief = class_exists('BT_IntelligenceBrief') ? BT_IntelligenceBrief::build_brief() : [];
        $chips = $brief['chips'] ?? [];
        $comp  = $brief['composite'] ?? [];
        $vhead = $brief['verdict']['headline'] ?? '';
        $score = floatval($comp['score'] ?? 0);
        $tot_a = max(1,intval($comp['total_active']??1));
        $conf  = round(intval($comp['align_count']??0)/$tot_a*100) ?: 78;
        $regime = $score>1.5?'Risk-on':($score<-1.5?'Risk-off':'Neutral');
        $coins = self::coins(); $rates = self::rates();
        $sig_s = class_exists('BT_SignalTracker') ? BT_SignalTracker::get_signal_track_stats() : [];
        $btc_p=self::fp($coins,'BTC','$67,892');[$btc_v,$btc_cs,$btc_cl]=self::fc($coins,'BTC',2.45);
        $eth_p=self::fp($coins,'ETH','$3,456'); [$eth_v,$eth_cs,$eth_cl]=self::fc($coins,'ETH',-1.32);
        $sol_p=self::fp($coins,'SOL','$183.67');[$sol_v,$sol_cs,$sol_cl]=self::fc($coins,'SOL',4.21);
        $eur=isset($rates['EUR'])?number_format(1/floatval($rates['EUR']),4):'1.0834';
        $bullets=array_map(fn($ch)=>esc_html($ch['text']??$ch['label']),array_slice($chips,0,3));
        if(empty($bullets)) $bullets=['DXY softening (-0.34% w/w) → dollar weakness','BTC dominance dropped 2.1% → alts catching bid','Altcoin volume +34% vs 7-day average'];
        $hit_rate=$sig_s['hit_rate']??64.0; $tot_sigs=$sig_s['total']??1247;
        $brief_url=home_url('/desk-brief/'); $archive_url=home_url('/signal-archive/'); $markets_url=home_url('/crypto-markets/');
        $mkt=[];
        foreach(['BTC','ETH','SOL','AVAX','XRP'] as $s) {
            if(isset($coins[$s])) { $p=floatval($coins[$s]['current_price']??0); $ch=floatval($coins[$s]['price_change_percentage_24h']??0);
                $mkt[]=['sym'=>$s,'name'=>$coins[$s]['name']??$s,'price'=>'$'.number_format($p,$p<1?4:($p<100?2:0)),'chg'=>($ch>=0?'+':'').number_format($ch,2).'%','cls'=>$ch>=0?'up':'down','sig'=>$ch>=2?'bull':($ch<=-2?'bear':'neut'),'conf'=>$ch>=2?78:($ch<=-2?74:52)]; }
        }
        if(empty($mkt)) $mkt=[['sym'=>'BTC','name'=>'Bitcoin','price'=>'$67,892.43','chg'=>'+2.45%','cls'=>'up','sig'=>'bull','conf'=>82],['sym'=>'ETH','name'=>'Ethereum','price'=>'$3,456.21','chg'=>'-1.32%','cls'=>'down','sig'=>'neut','conf'=>54],['sym'=>'SOL','name'=>'Solana','price'=>'$183.67','chg'=>'+4.21%','cls'=>'up','sig'=>'bull','conf'=>71],['sym'=>'AVAX','name'=>'Avalanche','price'=>'$38.12','chg'=>'+3.07%','cls'=>'up','sig'=>'bull','conf'=>68],['sym'=>'XRP','name'=>'Ripple','price'=>'$0.5234','chg'=>'-2.18%','cls'=>'down','sig'=>'bear','conf'=>76]];

        // Auth payload (logged-in user data for nav)
        $auth_p = self::auth_payload();
        $auth = $auth_p['auth']; $is_in = $auth_p['is_in']; $u_init = $auth_p['initial']; $u_name = $auth_p['name'] ?: 'Trader'; $u_short = $auth_p['short'] ?: 'trader'; $u_email = $auth_p['email'];

        // Ticker rows: 8 symbols, doubled for seamless marquee loop
        $ticker_rows = [];
        $ticker_rows[] = ['sym'=>'BTC','val'=>$btc_p,'chg'=>$btc_cs,'cls'=>$btc_cl];
        $ticker_rows[] = ['sym'=>'ETH','val'=>$eth_p,'chg'=>$eth_cs,'cls'=>$eth_cl];
        $ticker_rows[] = ['sym'=>'SOL','val'=>$sol_p,'chg'=>$sol_cs,'cls'=>$sol_cl];
        $ticker_rows[] = ['sym'=>'XAU/USD','val'=>'$2,341.87','chg'=>'+0.85%','cls'=>'up'];
        $ticker_rows[] = ['sym'=>'EUR/USD','val'=>$eur,'chg'=>'-0.12%','cls'=>'down'];
        $ticker_rows[] = ['sym'=>'DXY','val'=>'104.27','chg'=>'+0.34%','cls'=>'up'];
        $ticker_rows[] = ['sym'=>'AVAX','val'=>'$38.12','chg'=>'+3.07%','cls'=>'up'];
        $ticker_rows[] = ['sym'=>'USD/JPY','val'=>'152.34','chg'=>'-0.21%','cls'=>'down'];

        // Common URLs for nav (resolved once for the template)
        $url_crypto = home_url('/crypto-markets/');
        $url_forex  = home_url('/forex-charts/');
        $url_signals= home_url('/trading-signals/');
        $url_brief  = $brief_url;
        $url_archive= $archive_url;
        $url_blog   = home_url('/market-blog/');
        $url_api    = home_url('/api-docs/');
        $url_learn  = home_url('/learn/');
        $url_login  = home_url('/login/');
        $url_signup = home_url('/register/');
        $url_logout = wp_logout_url( home_url('/') );
        $url_dashboard = home_url('/dashboard/');
        $url_portfolio = home_url('/portfolio/');
        $url_watchlist = home_url('/watchlist/');
        $url_screeners = home_url('/screeners/');
        $url_following = home_url('/following/');
        $url_alerts    = home_url('/alerts/');
        $url_settings  = home_url('/account/');

        ob_start();
        // Include the template
        $vars = compact('btc_p','btc_cs','btc_cl','btc_v','eth_p','eth_cs','eth_cl','sol_p','sol_cs','sol_cl','sol_v','eur','conf','regime','bullets','hit_rate','tot_sigs','brief_url','archive_url','markets_url','mkt','vhead','auth','is_in','u_init','u_name','u_short','u_email','ticker_rows','url_crypto','url_forex','url_signals','url_brief','url_archive','url_blog','url_api','url_learn','url_login','url_signup','url_logout','url_dashboard','url_portfolio','url_watchlist','url_screeners','url_following','url_alerts','url_settings');
        extract($vars);
        include BT_DIR . 'templates/landing-revamp.php';
        return ob_get_clean();
    }

    /**
     * Render only the chrome (disclaimer + ticker + nav + drawer + risk modal + opening .btlp).
     * Used by BT_Page_Provisioner via wp_body_open to put the new navigation on every page.
     * The caller is responsible for closing the .btlp wrapper at the end of the page (wp_footer).
     *
     * @return string Chrome HTML.
     * @since 119.28.10
     */
    public static function render_chrome_only(): string {
        // v119.28.15 — must enqueue the CSS/JS, otherwise the chrome renders
        // unstyled on every page that isn't the landing (which is the bug
        // making the live navbar look broken on /privacy-policy/, /desk-brief/
        // and every other interior page).
        self::enqueue_assets();

        // Auth payload (logged-in user data for nav)
        $auth_p = self::auth_payload();
        $auth = $auth_p['auth']; $is_in = $auth_p['is_in']; $u_init = $auth_p['initial']; $u_name = $auth_p['name'] ?: 'Trader'; $u_short = $auth_p['short'] ?: 'trader'; $u_email = $auth_p['email'];

        // v119.28.24 — Use LIVE-data ticker rows so interior pages get real
        // prices on first paint, not demo numbers.
        $ticker_rows = self::default_ticker_rows();

        // URL map (same as render())
        $url_crypto = home_url('/crypto-markets/');
        $url_forex  = home_url('/forex-charts/');
        $url_signals= home_url('/trading-signals/');
        $url_brief  = home_url('/desk-brief/');
        $url_archive= home_url('/signal-archive/');
        $url_blog   = home_url('/market-blog/');
        $url_api    = home_url('/api-docs/');
        $url_learn  = home_url('/learn/');
        $url_login  = home_url('/login/');
        $url_signup = home_url('/register/');
        $url_logout = wp_logout_url( home_url('/') );
        $url_dashboard = home_url('/dashboard/');
        $url_portfolio = home_url('/portfolio/');
        $url_watchlist = home_url('/watchlist/');
        $url_screeners = home_url('/screeners/');
        $url_following = home_url('/following/');
        $url_alerts    = home_url('/alerts/');
        $url_settings  = home_url('/account/');
        $brief_url     = $url_brief;
        $archive_url   = $url_archive;

        ob_start();
        include BT_DIR . 'templates/landing-chrome.php';
        return ob_get_clean();
    }

    /**
     * Render JUST the new BlockTicker footer.
     *
     * Used by BT_Navbar::render_footer() when the global-nav toggle is active
     * and we're not on the homepage — the homepage already has the footer
     * via the [blockticker_landing] shortcode.
     *
     * @return string Footer HTML.
     * @since 119.28.13
     */
    public static function render_footer_only(): string {
        ob_start();
        ?>
<footer class="foot btlp">
  <div class="container">
    <div class="foot__grid">
      <div class="foot__brand-block">
        <div class="foot__brand">
          <span class="nav__logo">B</span>
          <span class="foot__brand-name">BLOCK<span>TICKER</span></span>
        </div>
        <p class="foot__tagline">Deep Market Intelligence for crypto, forex &amp; Web3. Real-time prices, AI analysis, trading signals — every data point sourced, attributed and verified.</p>
        <div class="foot__socials">
          <a href="https://x.com/blockticker_io" target="_blank" rel="noopener" aria-label="X / Twitter" title="X / Twitter">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
          </a>
          <a href="https://www.linkedin.com/company/blockticker" target="_blank" rel="noopener" aria-label="LinkedIn" title="LinkedIn">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 01-2.063-2.065 2.063 2.063 0 112.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/></svg>
          </a>
          <a href="https://github.com/blockticker" target="_blank" rel="noopener" aria-label="GitHub" title="GitHub">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 .297c-6.63 0-12 5.373-12 12 0 5.303 3.438 9.8 8.205 11.385.6.113.82-.258.82-.577 0-.285-.01-1.04-.015-2.04-3.338.724-4.042-1.61-4.042-1.61C4.422 18.07 3.633 17.7 3.633 17.7c-1.087-.744.084-.729.084-.729 1.205.084 1.838 1.236 1.838 1.236 1.07 1.835 2.809 1.305 3.495.998.108-.776.417-1.305.76-1.605-2.665-.3-5.466-1.332-5.466-5.93 0-1.31.465-2.38 1.235-3.22-.135-.303-.54-1.523.105-3.176 0 0 1.005-.322 3.3 1.23.96-.267 1.98-.4 3-.405 1.02.005 2.04.138 3 .405 2.28-1.552 3.285-1.23 3.285-1.23.645 1.653.24 2.873.12 3.176.765.84 1.23 1.91 1.23 3.22 0 4.61-2.805 5.625-5.475 5.92.42.36.81 1.096.81 2.22 0 1.606-.015 2.896-.015 3.286 0 .315.21.69.825.57C20.565 22.092 24 17.592 24 12.297c0-6.627-5.373-12-12-12"/></svg>
          </a>
          <a href="https://t.me/blockticker_io" target="_blank" rel="noopener" aria-label="Telegram" title="Telegram">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z"/></svg>
          </a>
        </div>
      </div>
      <div class="foot__col"><h4>Markets</h4><ul><li><a href="<?php echo esc_url(home_url('/crypto-markets/')); ?>">Crypto</a></li><li><a href="<?php echo esc_url(home_url('/forex-charts/')); ?>">Forex</a></li><li><a href="<?php echo esc_url(home_url('/commodities/')); ?>">Commodities</a></li><li><a href="<?php echo esc_url(home_url('/indices/')); ?>">Indices</a></li><li><a href="<?php echo esc_url(home_url('/dexscan/')); ?>">Web3</a></li></ul></div>
      <div class="foot__col"><h4>Tools</h4><ul><li><a href="<?php echo esc_url(home_url('/trading-signals/')); ?>">AI Signals</a></li><li><a href="<?php echo esc_url(home_url('/portfolio/')); ?>">Watchlists</a></li><li><a href="<?php echo esc_url(home_url('/tools/')); ?>">Economic Calendar</a></li><li><a href="<?php echo esc_url(home_url('/api-docs/')); ?>">API Docs</a></li></ul></div>
      <div class="foot__col"><h4>Resources</h4><ul><li><a href="<?php echo esc_url(home_url('/methodology/')); ?>">Methodology</a></li><li><a href="<?php echo esc_url(home_url('/signal-archive/')); ?>">Signal archive</a></li><li><a href="<?php echo esc_url(home_url('/market-blog/')); ?>">Blog</a></li><li><a href="<?php echo esc_url(home_url('/help/')); ?>">Help Center</a></li></ul></div>
      <div class="foot__col"><h4>Company</h4><ul><li><a href="<?php echo esc_url(home_url('/about/')); ?>">About</a></li><li><a href="<?php echo esc_url(home_url('/careers/')); ?>">Careers</a></li><li><a href="<?php echo esc_url(home_url('/contact/')); ?>">Contact</a></li><li><a href="<?php echo esc_url(home_url('/privacy-policy/')); ?>">Privacy</a></li><li><a href="<?php echo esc_url(home_url('/terms/')); ?>">Terms</a></li></ul></div>
    </div>
    <div class="foot__bottom"><span>© <?php echo esc_html( date('Y') ); ?> BlockTicker.io — All rights reserved.</span><span>Not financial advice. All data for informational purposes only.</span></div>
  </div>
</footer>
        <?php
        return ob_get_clean();
    }
}

// v119.28.17 — class_alias so references to BT_Landing_Revamp (with underscore)
// resolve to the actual class BT_LandingRevamp. This was the root cause of the
// global chrome not rendering on non-landing pages: page-provisioner and navbar
// both checked class_exists('BT_Landing_Revamp') which always returned false
// because the real class is named BT_LandingRevamp without the underscore.
if ( ! class_exists( 'BT_Landing_Revamp', false ) ) {
    class_alias( 'BT_LandingRevamp', 'BT_Landing_Revamp' );
}
