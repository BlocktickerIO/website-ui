<?php
/**
 * BlockTicker — Page Provisioner & Landing Switcher (v119.28.8)
 *
 * Two responsibilities:
 *   1) Scans every URL referenced by the new landing page (templates/landing-revamp.php),
 *      compares against existing WP pages, and offers a one-click "create missing pages"
 *      action under Tools → BlockTicker Pages.
 *   2) Provides an option to make the new landing the actual homepage without
 *      touching theme files or Settings → Reading.
 *
 * @since 119.28.8
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class BT_Page_Provisioner {

	const OPT_HOMEPAGE   = 'bt_use_revamped_homepage';
	const OPT_GLOBAL_NAV = 'bt_use_global_nav';
	const NONCE          = 'bt_provision_nonce';

	public static function init() {
		// Admin page (kept under Tools as a backup / detailed inventory view, but the
		// main UI is integrated into the existing BlockTicker → Page Manager screen
		// in class-admin.php so users have one place for all page actions).
		add_action( 'admin_menu',       array( __CLASS__, 'register_admin_page' ) );
		add_action( 'admin_post_bt_provision_pages',     array( __CLASS__, 'handle_provision' ) );
		add_action( 'admin_post_bt_toggle_homepage',     array( __CLASS__, 'handle_homepage_toggle' ) );
		add_action( 'admin_post_bt_toggle_global_nav',   array( __CLASS__, 'handle_global_nav_toggle' ) );
		add_filter( 'the_content',       array( __CLASS__, 'inject_landing_on_homepage' ), 5 );
		// Global nav: render chrome at the very top of <body>, close .btlp wrapper at wp_footer.
		add_action( 'wp_body_open',      array( __CLASS__, 'render_global_chrome' ), 1 );
		add_action( 'wp_footer',         array( __CLASS__, 'close_global_chrome' ), 999 );
		// v119.28.22 — On every front-end page, suppress legacy chrome popups
		// (subscribe popup, install banner, cookie banner, mobile bottom nav,
		// scroll newsletter, sticky CTA) and dequeue conflicting plugin/theme
		// stylesheets. This complements the homepage takeover suppression so
		// every page (not just /) gets a clean BT-only chrome.
		add_action( 'template_redirect',     array( __CLASS__, 'suppress_legacy_chrome_global' ), 5 );
		// v119.28.34: defensive guard for /pricing/ while it's unpublished.
		// WordPress's default behavior for post_status='private' should already
		// 404 logged-out visitors — but custom routing, theme overrides, or a
		// caching layer can leak private content. This belt-and-braces guard
		// runs early and forces a 404 unless the viewer can edit pages.
		// Drop this hook (or the whole guard) when the page goes 'publish'.
		add_action( 'template_redirect',     array( __CLASS__, 'guard_unpublished_pricing' ), 1 );
		add_action( 'wp_enqueue_scripts',    array( __CLASS__, 'dequeue_legacy_styles_global' ), 9999 );
	}

	/**
	 * v119.28.34: 404 the /pricing/ page for non-admins while it's still
	 * post_status='private'. Defensive — WordPress should already 404 these,
	 * but custom routing/caching can leak private content. Cheap to run; fires
	 * on every front-end request but does almost nothing 99.99% of the time.
	 *
	 * Remove this method (and its hook in init()) when /pricing/ flips to
	 * 'publish' — by that point WP's normal visibility handling is enough.
	 */
	public static function guard_unpublished_pricing() {
		if ( is_admin() || wp_doing_ajax() ) return;

		// Cheap path-prefix check before any database work.
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		// Match /pricing or /pricing/ or /pricing/anything but not /pricing-something-else
		if ( ! preg_match( '#^/pricing(/|$|\?)#', $uri ) ) return;

		// Resolve the actual page object (does one query)
		$page = get_page_by_path( 'pricing', OBJECT, 'page' );
		if ( ! $page ) return; // page doesn't exist yet — let WP 404 normally

		// If page is already public, this guard is a no-op.
		if ( $page->post_status === 'publish' ) return;

		// Page is private/draft/pending. Allow access only to users who can
		// edit pages (admins, editors). Everyone else gets a 404.
		if ( current_user_can( 'edit_pages' ) ) return;

		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
		// Let the theme's 404 template render naturally.
	}

	/**
	 * Suppress legacy chrome wp_footer hooks on every front-end page.
	 * Runs early so other classes' hooks are removed before they fire.
	 */
	public static function suppress_legacy_chrome_global() {
		if ( is_admin() ) { return; }
		if ( wp_doing_ajax() ) { return; }
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) { return; }
		if ( is_feed() ) { return; }

		// Old plugin chrome that produces stale UI on every page
		if ( class_exists( 'BT_Mobile_UX' ) ) {
			remove_action( 'wp_footer', array( 'BT_Mobile_UX', 'render_bottom_nav' ),    20 );
			remove_action( 'wp_footer', array( 'BT_Mobile_UX', 'render_sticky_cta' ),    21 );
			remove_action( 'wp_footer', array( 'BT_Mobile_UX', 'render_back_to_top' ),   22 );
		}
		if ( class_exists( 'BT_EEAT' ) ) {
			remove_action( 'wp_footer', array( 'BT_EEAT', 'render_scroll_newsletter' ), 20 );
			remove_action( 'wp_footer', array( 'BT_EEAT', 'render_reading_progress' ),   5 );
		}
		if ( class_exists( 'BT_GDPR' ) ) {
			remove_action( 'wp_footer', array( 'BT_GDPR', 'render_cookie_banner' ),     99 );
		}
		if ( class_exists( 'BT_PWA' ) ) {
			remove_action( 'wp_footer', array( 'BT_PWA', 'output_pwa_client_script' ),  99 );
		}

		// GeneratePress / Astra theme chrome
		remove_action( 'generate_header',          'generate_construct_header' );
		remove_action( 'generate_after_header',    'generate_add_navigation_after_header', 5 );
		remove_action( 'generate_footer',          'generate_construct_footer_widgets', 5 );
		remove_action( 'generate_credits',         'generate_add_footer_info' );
	}

	/**
	 * Dequeue plugin/theme stylesheets that compete with the BT chrome on every page.
	 */
	public static function dequeue_legacy_styles_global() {
		if ( is_admin() ) { return; }
		// Old landing-revamp.css (now superseded by inline styles in the chrome / takeover)
		// Note: don't dequeue 'bt-landing-revamp' here — render_chrome_only enqueues it
		// for the chrome to be styled.

		// Old plugin sheets that pollute non-landing pages
		wp_dequeue_style( 'fxlm-frontend' );
		wp_dequeue_style( 'fxlm-patch' );
		wp_dequeue_style( 'fxlm-revamp-v44' );
		wp_dequeue_style( 'fxlm-critical' );

		// Theme sheets
		wp_dequeue_style( 'generate-style' );
		wp_dequeue_style( 'generate-style-grid' );
		wp_dequeue_style( 'generate-mobile' );
		wp_dequeue_style( 'astra-theme-css' );
		wp_dequeue_style( 'astra-google-fonts' );
		wp_dequeue_style( 'twentytwentyfour-style' );
		wp_dequeue_style( 'kadence-global' );
		wp_dequeue_style( 'oceanwp-style' );
		wp_dequeue_style( 'blocksy-styles' );
		wp_dequeue_style( 'hello-elementor' );
	}

	/**
	 * Auto-provision every page in url_map() that doesn't already exist.
	 * Called from the "Create All Pages Now" admin button via the
	 * bt_after_create_all_pages action hook (fired by BT_Pages::create_all()).
	 *
	 * @return int Number of pages created.
	 */
	public static function create_all_for_landing() {
		$created = 0;
		$updated = 0;
		foreach ( self::url_map() as $entry ) {
			$st = self::page_status( $entry['slug'] );
			if ( $st['state'] === 'missing' ) {
				if ( self::create_page( $entry ) ) { $created++; }
				continue;
			}
			// v119.28.28 — Update existing pages when their content hash has changed.
			// Without this, edits to url_map() never propagate to live pages: the
			// brokers page stayed empty across v119.28.27 because the existing post
			// in DB was untouched. We now compare a hash of the current shortcode
			// against a hash stored in post-meta on each provisioned page.
			$page = self::get_page_by_slug( $entry['slug'] );
			if ( ! $page ) { continue; }
			$want_hash = md5( $entry['shortcode'] );
			$have_hash = get_post_meta( $page->ID, '_bt_provisioned_hash', true );
			if ( $have_hash === $want_hash ) { continue; }
			// Content drift detected — refresh the page in place.
			wp_update_post( array(
				'ID'           => $page->ID,
				'post_content' => $entry['shortcode'],
				'post_status'  => 'publish',
			) );
			update_post_meta( $page->ID, '_bt_provisioned_hash', $want_hash );
			$updated++;
		}
		return $created + $updated;
	}

	/**
	 * Resolve a page by slug, including nested slugs like 'forex/eur-usd'.
	 *
	 * @since v119.28.28
	 * @param string $slug
	 * @return WP_Post|null
	 */
	private static function get_page_by_slug( $slug ) {
		$page = get_page_by_path( $slug, OBJECT, 'page' );
		if ( $page ) { return $page; }
		// Fallback: nested slugs may have been stored with dashes
		$flat = str_replace( '/', '-', $slug );
		$page = get_page_by_path( $flat, OBJECT, 'page' );
		return $page ?: null;
	}

	/* ──────────────────────────────────────────────────────────────────
	 * The URL → page map. Every URL referenced by the landing page
	 * template lives here. Each entry has:
	 *   - slug      : the URL path (without home_url(), without leading/trailing /)
	 *   - title     : human-readable title for the page
	 *   - shortcode : (optional) plugin shortcode to inject as content
	 *   - section   : grouping for the admin UI
	 * ────────────────────────────────────────────────────────────────── */
	public static function url_map() {
		return array(
			// Markets
			array( 'slug' => 'crypto-markets',         'title' => 'Crypto Markets',          'shortcode' => '[fxlm_crypto_full_table]',     'section' => 'Markets' ),
			array( 'slug' => 'forex-charts',           'title' => 'Forex Charts',            'shortcode' => '[fxlm_forex_table]',           'section' => 'Markets' ),
			array( 'slug' => 'gainers-losers',         'title' => 'Gainers & Losers',        'shortcode' => '[fxlm_gainers_losers]',        'section' => 'Markets' ),
			array( 'slug' => 'top-lists',              'title' => 'Top Lists',               'shortcode' => '<h2>Top crypto lists</h2>'."\n".'<p>Curated lists by market cap, volume, and momentum.</p>'."\n\n".'[fxlm_crypto_table sort="market_cap" limit="20"]'."\n\n".'<h3>Top by 24h volume</h3>'."\n".'[fxlm_crypto_table sort="volume" limit="20"]', 'section' => 'Markets' ),
			array( 'slug' => 'commodities',            'title' => 'Commodities',             'shortcode' => '<h2>Commodity markets</h2>'."\n".'<p>Live charts and news for the most-traded commodities. Click any name below for the dedicated page with deeper analysis.</p>'."\n\n".'[fxlm_tradingview_chart symbol="TVC:GOLD" height="420"]'."\n\n".'<h3>Top commodities</h3>'."\n".'<ul>'."\n".'<li><a href="/commodities/gold/">Gold (XAU/USD)</a> — safe-haven bid, real-rates sensitive</li>'."\n".'<li><a href="/commodities/oil/">Oil (WTI)</a> — supply, OPEC+, geopolitical</li>'."\n".'</ul>', 'section' => 'Markets' ),
			array( 'slug' => 'commodities/gold',       'title' => 'Gold (XAU/USD)',          'shortcode' => '<h2>Gold — XAU/USD</h2>'."\n".'<p>Live spot gold chart with macro news context. Gold trades inversely to real rates and the dollar — watch DXY and US 10-year TIPS yields for direction.</p>'."\n\n".'[fxlm_tradingview_chart symbol="OANDA:XAUUSD" height="500"]'."\n\n".'<h3>Macro news affecting gold</h3>'."\n".'[fxlm_news_feed category="macro" layout="premium" count="12"]', 'section' => 'Markets' ),
			array( 'slug' => 'commodities/oil',        'title' => 'Oil (WTI)',               'shortcode' => '<h2>WTI Crude Oil</h2>'."\n".'<p>Live WTI chart with macro context. Oil is sensitive to OPEC+ supply decisions, US inventory data (Wednesday EIA), and geopolitical risk — dollar correlation flips with the cycle.</p>'."\n\n".'[fxlm_tradingview_chart symbol="TVC:USOIL" height="500"]'."\n\n".'<h3>Macro news affecting oil</h3>'."\n".'[fxlm_news_feed category="macro" layout="premium" count="12"]', 'section' => 'Markets' ),
			array( 'slug' => 'indices',                'title' => 'Stock Indices',           'shortcode' => '<h2>Major global stock indices</h2>'."\n".'<p>Live S&amp;P 500 chart and news context. Major indices are the canonical risk-on / risk-off signal — watch for divergences between US, EU, and Asian sessions.</p>'."\n\n".'[fxlm_tradingview_chart symbol="TVC:SPX" height="420"]'."\n\n".'<h3>Indices we track</h3>'."\n".'<ul>'."\n".'<li><strong>US:</strong> S&amp;P 500 (SPX), Nasdaq 100 (NDX), Dow Jones (DJI), Russell 2000 (RUT)</li>'."\n".'<li><strong>Europe:</strong> FTSE 100 (UK), DAX (Germany), CAC 40 (France), Euro Stoxx 50</li>'."\n".'<li><strong>Asia:</strong> Nikkei 225 (Japan), Hang Seng (HK), Shanghai Composite (China), KOSPI (Korea)</li>'."\n".'</ul>'."\n\n".'<h3>Earnings &amp; macro news</h3>'."\n".'[fxlm_news_feed category="earnings" layout="premium" count="12"]', 'section' => 'Markets' ),
			array( 'slug' => 'dexscan',                'title' => 'DexScan',                 'shortcode' => '[bt_dex_scanner]',              'section' => 'Markets' ),
			array( 'slug' => 'defi',                   'title' => 'DeFi',                    'shortcode' => '[fxlm_crypto_category category="defi"]', 'section' => 'Markets' ),
			array( 'slug' => 'nfts',                   'title' => 'NFTs',                    'shortcode' => '[fxlm_crypto_category category="nft"]', 'section' => 'Markets' ),
			array( 'slug' => 'exchanges',              'title' => 'Exchanges',               'shortcode' => '[fxlm_exchanges]',              'section' => 'Markets' ),
			array( 'slug' => 'brokers',                'title' => 'Forex Brokers',           'shortcode' => '[fxlm_breadcrumbs]'."\n".'<div class="bt-dash-wrap">'."\n".'<div class="fxlm-page-header"><div class="bt-eyebrow bt-eyebrow-green">MARKETS / BROKERS</div><h1>Forex Brokers</h1><p>Regulated brokers we have independently reviewed across spreads, execution quality, regulation, and platform features. Updated quarterly.</p></div>'."\n".'<div class="bt-prose"><p>The right broker can save you thousands in spread costs over a year. We rank brokers across <strong>five criteria</strong>: regulation tier (FCA/ASIC/CFTC etc.), <strong>average spread</strong> on EUR/USD during London hours, <strong>execution speed</strong> measured at peak news events, <strong>platform stack</strong> (MT4/MT5/cTrader/proprietary), and <strong>customer service</strong> response time. Brokers below all hold tier-1 regulatory licenses — we do not list offshore or unregulated venues.</p></div>'."\n".'<div class="bt-panel-head"><span class="bt-panel-num">01</span><span class="bt-panel-title">Top regulated brokers</span><span class="bt-panel-live">Updated quarterly</span></div>'."\n".'[fxlm_affiliate type="broker"]'."\n".'<div class="bt-prose" style="margin-top:32px"><h2>How we rank brokers</h2><p>Our rankings reflect a weighted score across regulation (30%), spread cost on EUR/USD (25%), execution speed (20%), platform quality (15%), and customer service (10%). We do not accept paid placement — affiliate relationships are disclosed but never affect rank order. Spread figures are observed averages during London session, not marketing-claimed minimums.</p><p><strong>What we do not rank on:</strong> bonuses, leverage caps (high leverage is a regulatory matter, not a quality marker), or copy-trading features. Our audience is self-directed traders, not signal followers.</p></div>'."\n".'</div>', 'section' => 'Markets' ),

			// Forex pairs — each gets a TradingView chart + relevant news
			array( 'slug' => 'forex/eur-usd',          'title' => 'EUR/USD',                 'shortcode' => '<h2>EUR/USD — Euro vs US Dollar</h2>'."\n".'<p>The world\'s most-traded currency pair. EUR/USD is sensitive to the ECB-Fed rate-differential, US economic data (NFP, CPI), and risk-on/off flows.</p>'."\n\n".'[fxlm_tradingview_chart symbol="FX:EURUSD" height="500"]'."\n\n".'<h3>Forex news affecting EUR/USD</h3>'."\n".'[fxlm_news_feed category="forex" layout="premium" count="10"]', 'section' => 'Forex Pairs' ),
			array( 'slug' => 'forex/gbp-usd',          'title' => 'GBP/USD',                 'shortcode' => '<h2>GBP/USD — British Pound vs US Dollar</h2>'."\n".'<p>"Cable" — historically the highest-volume sterling pair. Sensitive to BoE policy, UK CPI, and broader USD strength.</p>'."\n\n".'[fxlm_tradingview_chart symbol="FX:GBPUSD" height="500"]'."\n\n".'<h3>Forex news affecting GBP/USD</h3>'."\n".'[fxlm_news_feed category="forex" layout="premium" count="10"]', 'section' => 'Forex Pairs' ),
			array( 'slug' => 'forex/usd-jpy',          'title' => 'USD/JPY',                 'shortcode' => '<h2>USD/JPY — US Dollar vs Japanese Yen</h2>'."\n".'<p>The classic carry pair — sensitive to US Treasury yields and BoJ intervention risk. Watch the 10-year UST yield for direction.</p>'."\n\n".'[fxlm_tradingview_chart symbol="FX:USDJPY" height="500"]'."\n\n".'<h3>Forex news affecting USD/JPY</h3>'."\n".'[fxlm_news_feed category="forex" layout="premium" count="10"]', 'section' => 'Forex Pairs' ),
			array( 'slug' => 'forex/usd-cad',          'title' => 'USD/CAD',                 'shortcode' => '<h2>USD/CAD — US Dollar vs Canadian Dollar</h2>'."\n".'<p>"Loonie" — the commodity-currency pair. Heavily correlated with WTI crude and BoC policy spreads against the Fed.</p>'."\n\n".'[fxlm_tradingview_chart symbol="FX:USDCAD" height="500"]'."\n\n".'<h3>Forex news affecting USD/CAD</h3>'."\n".'[fxlm_news_feed category="forex" layout="premium" count="10"]', 'section' => 'Forex Pairs' ),

			// Asset analysis
			array( 'slug' => 'analysis',               'title' => 'Market Analysis',         'shortcode' => '[bt_analysis_desk]',            'section' => 'Analysis' ),
			array( 'slug' => 'methodology',            'title' => 'Methodology & Risk',      'shortcode' => '<h2>Our methodology</h2>'."\n".'<p>BlockTicker provides market intelligence and pattern-detection signals — not financial advice. Trading carries risk of loss. Past performance does not guarantee future results.</p>'."\n\n".'<h3>How signals are generated</h3>'."\n".'<p>An ensemble of four independent detectors looks for: <strong>volume divergences</strong>, <strong>momentum shifts</strong>, <strong>funding-rate flips</strong>, and <strong>cross-asset correlation breaks</strong>. Each detector outputs a 0–1 confidence — independent, not chained.</p>'."\n".'<h3>Scoring</h3>'."\n".'<p>Detectors are weighted by their <strong>historical hit rate on similar regimes</strong> and combined into a single confidence score (0–100). Anything below 65 is not shipped.</p>'."\n".'<h3>Track record</h3>'."\n".'<p>Published <strong>64% historical hit rate</strong>, fully attributed and verifiable from the <a href="/signal-archive/">signal archive</a>.</p>'."\n\n".'[bt_methodology_card]', 'section' => 'Analysis' ),
			array( 'slug' => 'analysis/bitcoin',       'title' => 'Bitcoin Analysis',        'shortcode' => '[bt_asset_analysis symbol="BTC"]', 'section' => 'Analysis' ),
			array( 'slug' => 'analysis/ethereum',      'title' => 'Ethereum Analysis',       'shortcode' => '[bt_asset_analysis symbol="ETH"]', 'section' => 'Analysis' ),
			array( 'slug' => 'analysis/solana',        'title' => 'Solana Analysis',         'shortcode' => '[bt_asset_analysis symbol="SOL"]', 'section' => 'Analysis' ),
			array( 'slug' => 'forex-sentiment',        'title' => 'Forex Sentiment',         'shortcode' => '[bt_forex_sentiment]',          'section' => 'Analysis' ),
			array( 'slug' => 'correlations',           'title' => 'Cross-Market Correlations','shortcode' => '<h2>Cross-market correlations</h2>'."\n".'<p>Pearson correlation coefficients between top assets and currency pairs over a rolling window. Values above +0.7 indicate strong co-movement; below −0.7 indicate strong inverse moves; near zero means independence. Useful for spotting regime shifts (correlation breaks) and avoiding portfolio concentration risk.</p>'."\n\n".'[bt_correlation_heatmap symbols="BTC,ETH,SOL,XAU,EUR/USD,GBP/USD,USD/JPY" days="30"]'."\n\n".'<h3>How to read this</h3>'."\n".'<ul>'."\n".'<li><strong>BTC ↔ ETH near +0.9</strong> — typical; majors move together in risk-on/off regimes.</li>'."\n".'<li><strong>BTC ↔ XAU near 0</strong> — when zero, gold is acting independently; when positive, both are reacting to the same macro driver (USD weakness, real rates).</li>'."\n".'<li><strong>EUR/USD ↔ DXY near −1</strong> — by construction, since DXY is heavily EUR-weighted.</li>'."\n".'<li><strong>Watch for breaks</strong> — when historically-correlated pairs decouple, something has changed in the regime. That\'s the signal.</li>'."\n".'</ul>'."\n\n".'<p><em>Correlations need ≥7 days of price history to compute. New symbols populate after the daily snapshot cron (00:05 UTC) collects enough data points.</em></p>', 'section' => 'Analysis' ),

			// Signals
			array( 'slug' => 'trading-signals',        'title' => 'Trading Signals',         'shortcode' => '[fxlm_signals_feed]',           'section' => 'Signals' ),
			array( 'slug' => 'signal-archive',         'title' => 'Signal Archive',          'shortcode' => '[blockticker_signal_archive]',  'section' => 'Signals' ),
			array( 'slug' => 'desk-brief',             'title' => "Today's Desk Brief",      'shortcode' => '[blockticker_desk_brief]',      'section' => 'Signals' ),

			// News
			array( 'slug' => 'news',                   'title' => 'News',                    'shortcode' => '[fxlm_news_feed]',              'section' => 'News' ),
			array( 'slug' => 'financial-news',         'title' => 'Financial News Desk',     'shortcode' => '<h2>Financial news desk</h2>'."\n".'<p>Editorial selection across crypto, forex, and macro — Bloomberg-style. Refreshed hourly from 24 vetted sources, deduplicated and sentiment-tagged.</p>'."\n\n".'[fxlm_news_feed layout="premium" count="24"]', 'section' => 'News' ),
			array( 'slug' => 'news/breaking',          'title' => 'Breaking News',           'shortcode' => '<h2>Breaking market news</h2>'."\n".'<p>Time-sensitive headlines across crypto, forex, and macro — pulled hourly, surfaced fastest.</p>'."\n\n".'[fxlm_breaking_news]'."\n\n".'<h3>All recent breaking</h3>'."\n".'[fxlm_news_feed category="breaking" layout="premium" count="20"]', 'section' => 'News' ),
			array( 'slug' => 'news/crypto',            'title' => 'Crypto News',             'shortcode' => '<h2>Crypto news</h2>'."\n".'<p>BTC, ETH, altcoins, and on-chain developments — from CoinDesk, CoinTelegraph, Decrypt, The Block, and 8 more vetted sources. Sentiment-scored and deduplicated.</p>'."\n\n".'[fxlm_news_feed category="crypto" layout="premium" count="24"]', 'section' => 'News' ),
			array( 'slug' => 'news/forex',             'title' => 'Forex News',              'shortcode' => '<h2>Forex news</h2>'."\n".'<p>Currency pair moves, central bank commentary, and macro drivers — from FXStreet, ForexLive, DailyFX, and MarketWatch.</p>'."\n\n".'[fxlm_news_feed category="forex" layout="premium" count="24"]', 'section' => 'News' ),
			array( 'slug' => 'news/macro',             'title' => 'Macro &amp; Policy News', 'shortcode' => '<h2>Macro &amp; policy news</h2>'."\n".'<p>Central bank statements, economic data, and policy moves that move markets across asset classes.</p>'."\n\n".'[fxlm_news_feed category="macro" layout="premium" count="24"]', 'section' => 'News' ),
			array( 'slug' => 'news/regulation',        'title' => 'Regulation News',         'shortcode' => '<h2>Crypto &amp; finance regulation</h2>'."\n".'<p>SEC, CFTC, MiCA, FCA, and cross-border regulatory developments affecting digital assets and traditional markets.</p>'."\n\n".'[fxlm_news_feed category="regulation" layout="premium" count="20"]', 'section' => 'News' ),
			array( 'slug' => 'news/web3',              'title' => 'Web3 &amp; DeFi News',    'shortcode' => '<h2>Web3 &amp; DeFi news</h2>'."\n".'<p>On-chain activity, DeFi protocol updates, NFT markets, and Web3 infrastructure — curated from The Defiant and crypto-native sources.</p>'."\n\n".'[fxlm_news_feed category="web3" layout="premium" count="24"]', 'section' => 'News' ),
			array( 'slug' => 'news/earnings',          'title' => 'Earnings &amp; Reports',  'shortcode' => '<h2>Earnings &amp; reports</h2>'."\n".'<p>Corporate earnings, exchange volume reports, and crypto-firm financials that move sentiment.</p>'."\n\n".'[fxlm_news_feed category="earnings" layout="premium" count="20"]', 'section' => 'News' ),
			array( 'slug' => 'news/sentiment',         'title' => 'News Sentiment',          'shortcode' => '<h2>News with sentiment scoring</h2>'."\n".'<p>Every headline scored bullish / bearish / neutral by our NLP layer. Useful for spotting when narrative diverges from price action.</p>'."\n\n".'[bt_sentiment_bar]'."\n\n".'[fxlm_news_feed layout="premium" count="24"]', 'section' => 'News' ),
			array( 'slug' => 'news/sources',           'title' => 'News Sources',            'shortcode' => '<h2>News sources we track</h2>'."\n".'<p>BlockTicker aggregates and verifies news from 24 vetted sources across crypto, forex, and macro. Each source is fetched hourly, deduplicated against the others, sentiment-scored, and stored with full attribution.</p>'."\n\n".'<h3>Sources by category</h3>'."\n".'<ul>'."\n".'<li><strong>Crypto:</strong> CoinDesk, CoinTelegraph, Decrypt, The Block, Bitcoin Magazine, BeInCrypto, CryptoSlate, Blockworks, NewsBTC, CryptoNews, AmbCrypto</li>'."\n".'<li><strong>Forex &amp; macro:</strong> FXStreet, ForexLive, DailyFX, MarketWatch, CNBC, Federal Reserve, ECB</li>'."\n".'<li><strong>DeFi &amp; Web3:</strong> The Defiant</li>'."\n".'<li><strong>Trading signals:</strong> FXStreet Analysis, DailyFX, BabyPips, CoinDesk Markets, ForexLive Analysis</li>'."\n".'</ul>'."\n\n".'<h3>Latest from all sources</h3>'."\n".'[fxlm_news_feed layout="premium" count="30"]', 'section' => 'News' ),
			array( 'slug' => 'news/most-read',         'title' => 'Most Read',               'shortcode' => '<h2>Most-read this hour</h2>'."\n".'<p>What other BlockTicker readers are clicking on right now — useful for spotting narrative momentum before it shows in price.</p>'."\n\n".'[fxlm_news_feed sort="popular" layout="premium" count="24"]', 'section' => 'News' ),
			array( 'slug' => 'market-blog',            'title' => 'Market Blog',             'shortcode' => '[fxlm_blog_posts]',             'section' => 'News' ),
			array( 'slug' => 'economic-calendar',      'title' => 'Economic Calendar',       'shortcode' => '[fxlm_economic_calendar]',      'section' => 'News' ),

			// Tools
			array( 'slug' => 'tools',                  'title' => 'Trading Tools',           'shortcode' => '<div class="bt-dash-wrap"><div class="bt-hero"><div class="bt-hero__eyebrow">Tools &amp; Calculators</div><h1 class="bt-hero__title">Decision tools for serious traders</h1><p class="bt-hero__lede">Pip values, position sizing, P/L modelling, currency conversion — and the decision aids that surround them. Each tool is built around the math that actually matters: risk per trade, R-multiple, expectancy. Skip the spreadsheet.</p></div>'.

				'<div class="bt-section"><div class="bt-section__head"><span class="bt-section__num">01</span><h2 class="bt-section__title">Calculators &amp; Converters</h2><span class="bt-section__meta">Four core trade-math tools</span></div>'.
				'<div class="bt-grid bt-grid--2-fixed">'.

				'<a href="/tools/profit-calculator/" class="bt-tile">'.
					'<span class="bt-tile__icon">P/L</span>'.
					'<h3 class="bt-tile__title">Profit Calculator</h3>'.
					'<p class="bt-tile__desc">Model gross and net P/L on any trade — direction-aware, with fees per side, R-multiple output, and color-coded result. The fastest way to validate whether a trade is worth taking.</p>'.
					'<span class="bt-tile__arrow">Open tool</span>'.
				'</a>'.

				'<a href="/tools/currency-converter/" class="bt-tile">'.
					'<span class="bt-tile__icon">⇋</span>'.
					'<h3 class="bt-tile__title">Currency Converter</h3>'.
					'<p class="bt-tile__desc">Live-rate USD ↔ crypto conversion across 50+ coins, plus the major fiats. Pulls from the same price feed that powers the BlockTicker dashboard — no third-party redirect.</p>'.
					'<span class="bt-tile__arrow">Open tool</span>'.
				'</a>'.

				'<a href="/tools/position-size/" class="bt-tile">'.
					'<span class="bt-tile__icon">∑</span>'.
					'<h3 class="bt-tile__title">Position Size Calculator</h3>'.
					'<p class="bt-tile__desc">Equity, risk %, entry, stop → position size in units, dollar risk, risk-per-unit. Built around the 0.5–2 % per-trade cap that professional desks use. Stops blowing up.</p>'.
					'<span class="bt-tile__arrow">Open tool</span>'.
				'</a>'.

				'<a href="/tools/pip-margin/" class="bt-tile">'.
					'<span class="bt-tile__icon">%</span>'.
					'<h3 class="bt-tile__title">Pip &amp; Margin Calculator</h3>'.
					'<p class="bt-tile__desc">Pair, lot size, leverage → pip value in USD, margin required, notional contract size. Handles JPY pairs separately (0.01 vs 0.0001 pip) and USD-base vs USD-quote pairs correctly.</p>'.
					'<span class="bt-tile__arrow">Open tool</span>'.
				'</a>'.

				'</div></div>'.

				'<div class="bt-section"><div class="bt-section__head"><span class="bt-section__num">02</span><h2 class="bt-section__title">Decision Aids</h2><span class="bt-section__meta">Sentiment, calendar, watchlist, API</span></div>'.
				'<div class="bt-grid bt-grid--2-fixed">'.

				'<a href="/sentiment/" class="bt-tile">'.
					'<span class="bt-tile__icon">F&amp;G</span>'.
					'<h3 class="bt-tile__title">Fear &amp; Greed Index</h3>'.
					'<p class="bt-tile__desc">The Crypto Fear &amp; Greed gauge plus our own multi-asset sentiment composite — momentum, volatility, social, options-flow, dominance. Useful for timing entries against extremes.</p>'.
					'<span class="bt-tile__arrow">Open tool</span>'.
				'</a>'.

				'<a href="/economic-calendar/" class="bt-tile">'.
					'<span class="bt-tile__icon">📅</span>'.
					'<h3 class="bt-tile__title">Economic Calendar</h3>'.
					'<p class="bt-tile__desc">CPI, FOMC, NFP, ECB, BOJ — every macro release that moves crypto and forex, with previous / forecast / actual. Filter by impact (high / medium) and currency.</p>'.
					'<span class="bt-tile__arrow">Open tool</span>'.
				'</a>'.

				'<a href="/watchlist/" class="bt-tile">'.
					'<span class="bt-tile__icon">★</span>'.
					'<h3 class="bt-tile__title">Watchlist &amp; Portfolio</h3>'.
					'<p class="bt-tile__desc">Track up to 50 assets in a personal watchlist with live prices, 24h chg, and configurable alerts. Add a portfolio layer with position size and average cost for unrealised P/L.</p>'.
					'<span class="bt-tile__arrow">Open tool</span>'.
				'</a>'.

				'<a href="/api-docs/" class="bt-tile">'.
					'<span class="bt-tile__icon">{ }</span>'.
					'<h3 class="bt-tile__title">API &amp; Webhooks</h3>'.
					'<p class="bt-tile__desc">Pull our normalised price feed, signal events, and sentiment scores via JSON REST. Webhooks deliver new signals to your bot or Slack within ~3s of publication. Free tier available.</p>'.
					'<span class="bt-tile__arrow">Open tool</span>'.
				'</a>'.

				'</div></div></div>', 'section' => 'Tools' ),
			array( 'slug' => 'tools/currency-converter', 'title' => 'Currency Converter',    'shortcode' => '[fxlm_crypto_converter]',       'section' => 'Tools' ),
			array( 'slug' => 'tools/pip-margin',       'title' => 'Pip & Margin Calculator', 'shortcode' => '[fxlm_calculator type="pip"]',  'section' => 'Tools' ),
			array( 'slug' => 'tools/position-size',    'title' => 'Position Size Calculator','shortcode' => '[fxlm_calculator type="position"]', 'section' => 'Tools' ),
			array( 'slug' => 'tools/profit-calculator','title' => 'Profit Calculator',       'shortcode' => '[fxlm_calculator type="profit"]', 'section' => 'Tools' ),

			// Learn
			array( 'slug' => 'learn',                  'title' => 'Learn',                   'shortcode' => '<h2>Learn trading &amp; markets</h2>'."\n".'<p>Beginner guides, glossary, and educational content covering crypto, forex, and Web3.</p>'."\n\n".'<h3>Get started</h3>'."\n".'<ul>'."\n".'<li><a href="/learn/beginner-guides/">Beginner guides</a> — crypto, forex &amp; markets 101</li>'."\n".'<li><a href="/learn/glossary/">Glossary</a> — terms explained simply</li>'."\n".'<li><a href="/learn/trading-basics/">Trading basics</a> — risk, position sizing, R:R</li>'."\n".'</ul>'."\n\n".'[fxlm_blog_posts category="education" limit="9"]', 'section' => 'Learn' ),
			array( 'slug' => 'learn/beginner-guides',  'title' => 'Beginner Guides',         'shortcode' => '<h2>Beginner guides — crypto, forex &amp; markets 101</h2>'."\n".'<p>Plain-language guides to get you trading confidently. Each guide takes 10–15 minutes to read and includes worked examples.</p>'."\n\n".'<h3>Crypto fundamentals</h3>'."\n".'<ul>'."\n".'<li><strong>What is Bitcoin?</strong> — How a ledger of transactions is secured by proof-of-work, why supply is capped at 21M, and what "halving" means.</li>'."\n".'<li><strong>What is Ethereum?</strong> — Smart contracts, gas fees, the move to proof-of-stake, and why ETH is more than just a coin.</li>'."\n".'<li><strong>Reading order books</strong> — Bid, ask, spread, depth — what market makers see when you place an order.</li>'."\n".'</ul>'."\n\n".'<h3>Forex fundamentals</h3>'."\n".'<ul>'."\n".'<li><strong>What is a currency pair?</strong> — Base vs quote, majors vs minors, why pip values differ across pairs.</li>'."\n".'<li><strong>Carry, hedging, and intervention</strong> — How central banks move FX, and what to watch on calendar days.</li>'."\n".'</ul>'."\n\n".'<h3>Reading the markets</h3>'."\n".'<ul>'."\n".'<li><strong>Reading a candle chart</strong> — OHLC, body vs wick, volume context, and three patterns that actually matter.</li>'."\n".'<li><strong>What moves prices</strong> — Liquidity, news, positioning, and the difference between a real breakout and a fake-out.</li>'."\n".'</ul>'."\n\n".'<p><a href="/learn/trading-basics/">Continue to: Trading basics — risk, position sizing &amp; R:R →</a></p>', 'section' => 'Learn' ),
			array( 'slug' => 'learn/glossary',         'title' => 'Glossary',                'shortcode' => '<h2>Glossary — trading terms explained simply</h2>'."\n".'<p>Every term BlockTicker uses, defined in plain language. If something is missing, <a href="/contact/">tell us</a>.</p>'."\n\n".'[fxlm_glossary]'."\n\n".'<p><em>Don\'t see what you\'re looking for? Try the <a href="/learn/beginner-guides/">Beginner guides</a> for a wider walkthrough.</em></p>', 'section' => 'Learn' ),
			array( 'slug' => 'learn/trading-basics',   'title' => 'Trading Basics',          'shortcode' => '<h2>Trading basics — risk, position sizing &amp; R:R</h2>'."\n".'<p>The difference between traders who survive and those who don\'t isn\'t entry quality — it\'s position sizing and risk-per-trade. This guide walks through both with worked examples.</p>'."\n\n".'<h3>1. Risk per trade</h3>'."\n".'<p>Most professional traders cap risk at <strong>0.5–2 % of account equity</strong> per trade. With 1 % risk and a 50 % win-rate, you can have 10 losers in a row and still be down only ~10 % — survivable. With 10 % risk per trade, the same streak wipes you out.</p>'."\n\n".'<h3>2. Position sizing — the formula</h3>'."\n".'<p style="font-family:var(--f-mono,monospace);background:rgba(255,255,255,.04);padding:14px;border-left:3px solid var(--accent,#00d97e)">Position size = (Account equity × Risk %) ÷ (Entry − Stop)</p>'."\n".'<p>Example: $10,000 account, 1 % risk = $100 max loss. Entry $50, stop $48 (so $2 risk per share). Position size = $100 ÷ $2 = <strong>50 shares</strong>. The price gap from entry to stop is the only thing that determines size — not how confident you feel.</p>'."\n\n".'<p>Use the <a href="/tools/position-size/">Position size calculator</a> to skip the maths.</p>'."\n\n".'<h3>3. R-multiples (R:R)</h3>'."\n".'<p>"R" is the dollar amount you\'re risking on a trade. A trade that targets +3 R is risking $1 to make $3. Track every trade in R, not dollars — it makes performance comparable across position sizes and timeframes. <strong>Aim for an average winner ≥ 1.5 R</strong> if your win-rate is around 50 %.</p>'."\n\n".'<h3>4. Stop placement</h3>'."\n".'<p>Stops belong where the trade idea is invalidated, <em>not</em> at a fixed dollar amount below entry. If a level is "the line that says I was wrong," your stop goes just beyond it. Then you size the position around that distance — never the other way around.</p>'."\n\n".'<h3>5. Win-rate vs R-multiple</h3>'."\n".'<p>You don\'t need to be right most of the time. A 40 % win-rate with 2 R winners is profitable. A 70 % win-rate with 0.5 R winners and 1 R losers is not. Optimise for expectancy: <strong>(Win % × avg R win) − (Loss % × avg R loss)</strong>.</p>'."\n\n".'<p><a href="/methodology/">See how BlockTicker scores signal confidence →</a></p>', 'section' => 'Learn' ),
			array( 'slug' => 'help',                   'title' => 'Help & FAQ',              'shortcode' => '<h2>Help &amp; frequently asked questions</h2>'."\n".'<p>Everything you need to know about BlockTicker — from how signals are generated to where your data lives.</p>'."\n\n".'<h3>What is BlockTicker?</h3>'."\n".'<p>BlockTicker is a market intelligence platform that aggregates real-time prices, AI-driven analysis, and pattern-detection signals across crypto, forex, and Web3. We pull from 24 vetted RSS sources and 6+ price providers, score each signal with a 4-detector ensemble, and publish only those above a 65 % confidence threshold.</p>'."\n\n".'<h3>Are signals financial advice?</h3>'."\n".'<p>No. <strong>Nothing on BlockTicker is financial advice.</strong> All content is educational. Trading involves risk of loss. Past performance does not guarantee future results. Read the full <a href="/methodology/">methodology</a> for details on how signals are generated.</p>'."\n\n".'<h3>How is the 64 % historical hit rate measured?</h3>'."\n".'<p>Every published signal is logged with its entry, target, stop, and confidence score at the moment of publication. The <a href="/signal-archive/">signal archive</a> is fully verifiable — you can see every signal we\'ve ever shipped and how it resolved.</p>'."\n\n".'<h3>How often is data refreshed?</h3>'."\n".'<p>Crypto prices: every 2 seconds. Forex rates: every 4 seconds. News: every 60 minutes from each of the 24 sources. Signals: re-evaluated every 5 minutes against live price action.</p>'."\n\n".'<h3>Do you sell or share my data?</h3>'."\n".'<p>Never. See our <a href="/privacy-policy/">privacy policy</a>. Watchlists, portfolios, and alerts stay on your account.</p>'."\n\n".'<h3>How do I get notified of new signals?</h3>'."\n".'<p>Three ways: in-app alerts (<a href="/alerts/">Alerts hub</a>), Telegram (<a href="/integrations/telegram/">connect</a>), or webhook (<a href="/webhooks/">connect</a>). Premium users also get email digests.</p>'."\n\n".'<h3>I think there\'s a bug.</h3>'."\n".'<p>Please <a href="/contact/">send us the details</a> — URL, browser, what you expected vs what happened. We respond within one business day.</p>'."\n\n".'<h3>How do I contact support?</h3>'."\n".'[fxlm_contact_form]', 'section' => 'Learn' ),
			array( 'slug' => 'whitepaper',             'title' => 'Whitepaper',              'shortcode' => '<h2>The BlockTicker whitepaper</h2>'."\n".'<p>This whitepaper documents the BlockTicker market-intelligence stack: the data pipeline, the four-detector signal ensemble, the composite scoring model, and the editorial review layer that turns raw detector output into a daily desk brief.</p>'."\n\n".'<h3>1. Data pipeline</h3>'."\n".'<p>BlockTicker aggregates from <strong>6+ price providers</strong> (CoinGecko, Binance, Kraken, Coinbase, Frankfurter for FX, ECB reference rates) and <strong>24 vetted news RSS sources</strong> across crypto, forex, and macro. Every quote is re-keyed against a canonical symbol map; every news item is sentiment-scored, deduplicated, and assigned a category before storage.</p>'."\n\n".'<h3>2. The four-detector ensemble</h3>'."\n".'<p>Each detector outputs an independent 0–1 confidence:</p>'."\n".'<ol>'."\n".'<li><strong>Volume divergence</strong> — Looks for price moves not confirmed by exchange volume.</li>'."\n".'<li><strong>Momentum shift</strong> — RSI/MACD curvature changes flagged on the highest-volume timeframe for each asset.</li>'."\n".'<li><strong>Funding-rate flip</strong> — Perp-futures funding crossing through zero against open interest direction.</li>'."\n".'<li><strong>Cross-asset correlation break</strong> — Pairwise rolling correlations breaking historic regime norms.</li>'."\n".'</ol>'."\n".'<p>Detectors are independent, never chained — a false positive in one cannot cascade into another.</p>'."\n\n".'<h3>3. Composite scoring</h3>'."\n".'<p>Detector outputs are weighted by their <strong>historical hit rate on similar regimes</strong> and combined into a single confidence score (0–100). Anything below 65 is not published. Above 80 triggers an &quot;actionable&quot; verdict; 65–79 is &quot;monitor.&quot;</p>'."\n\n".'<h3>4. Editorial review</h3>'."\n".'<p>Every published brief passes through a single-pass review layer. The reviewer\'s only job is clarity — converting numbers into prose without altering the verdict, the score, or the underlying detector evidence. Numbers are never edited.</p>'."\n\n".'<h3>5. Track record</h3>'."\n".'<p>Published <strong>64 % historical hit rate</strong>, fully attributed and verifiable from the <a href="/signal-archive/">signal archive</a>. Every signal logs entry, target, stop, confidence, and detector breakdown at the moment of publication.</p>'."\n\n".'<h3>6. Risk &amp; disclaimers</h3>'."\n".'<p>BlockTicker provides market intelligence and pattern-detection signals — <strong>not financial advice</strong>. Trading carries risk of loss. Past performance does not guarantee future results. See the full <a href="/methodology/">methodology</a> and <a href="/privacy-policy/">privacy policy</a>.</p>'."\n\n".'<p><em>Last updated: '.gmdate('F Y').' &middot; Authored by the BlockTicker desk team.</em></p>', 'section' => 'Learn' ),
			array( 'slug' => 'api-docs',               'title' => 'API Documentation',       'shortcode' => '[bt_api_docs]',                 'section' => 'Learn' ),
			array( 'slug' => 'integrations',           'title' => 'Integrations',            'shortcode' => '<h2>Integrations</h2>'."\n".'<ul>'."\n".'<li><a href="/integrations/telegram/">Telegram</a> — get high-confidence signals delivered to a chat</li>'."\n".'<li><a href="/integrations/twitter/">Twitter/X</a> — auto-post signals to your account</li>'."\n".'<li><a href="/webhooks/">Webhooks</a> — pipe signals into your own systems</li>'."\n".'<li><a href="/api-docs/">API</a> — full REST API access</li>'."\n".'</ul>', 'section' => 'Learn' ),
			array( 'slug' => 'integrations/telegram',  'title' => 'Telegram Integration',    'shortcode' => '<h2>Telegram integration</h2>'."\n".'<p>Receive high-confidence trading signals directly in your Telegram chat. Configure threshold and asset filters from your dashboard.</p>'."\n".'<p><a href="/dashboard/?tab=integrations">Configure in dashboard</a></p>', 'section' => 'Learn' ),
			array( 'slug' => 'integrations/twitter',   'title' => 'Twitter/X Integration',   'shortcode' => '<h2>Twitter/X integration</h2>'."\n".'<p>Auto-post your highest-confidence signals to Twitter/X. Connect your account, choose a confidence threshold, and let the desk-brief publish itself.</p>'."\n".'<p><a href="/dashboard/?tab=integrations">Configure in dashboard</a></p>', 'section' => 'Learn' ),
			array( 'slug' => 'webhooks',               'title' => 'Webhooks',                'shortcode' => '<h2>Webhooks</h2>'."\n".'<p>Pipe signals and price events into your own systems. POST to a URL of your choice with JSON payloads. Configure in your <a href="/dashboard/?tab=integrations">dashboard</a>.</p>'."\n".'<p>See the <a href="/api-docs/">API documentation</a> for payload schemas.</p>', 'section' => 'Learn' ),

			// Company / footer
			array( 'slug' => 'about',                  'title' => 'About BlockTicker',       'shortcode' => '<h2>About BlockTicker</h2>'."\n".'<p>BlockTicker is a market-intelligence platform built for traders who want signal, not noise. We aggregate real-time prices and news from 30+ vetted providers, score every market move with a 4-detector ensemble, and publish only what crosses a 65 % confidence threshold — fully attributed and historically verifiable.</p>'."\n\n".'<h3>What we do</h3>'."\n".'<ul>'."\n".'<li><strong>Crypto, forex &amp; macro</strong> — one screen, every asset class.</li>'."\n".'<li><strong>AI-assisted analysis</strong> — pattern detection, regime classification, sentiment scoring.</li>'."\n".'<li><strong>Editorial transparency</strong> — every signal logged in the public <a href="/signal-archive/">archive</a> with entry, target, stop, and outcome.</li>'."\n".'</ul>'."\n\n".'<h3>What we don\'t do</h3>'."\n".'<p>We don\'t take custody of funds. We don\'t execute trades. We don\'t sell user data. We don\'t give financial advice — everything we publish is educational. See the <a href="/methodology/">full methodology</a> for how this works in practice.</p>'."\n\n".'<p>Reach the team at <a href="/contact/">/contact</a>.</p>', 'section' => 'Company' ),
			array( 'slug' => 'contact',                'title' => 'Contact',                 'shortcode' => '<h2>Contact the BlockTicker desk</h2>'."\n".'<p>Use the form below for support, partnership, or press enquiries. We respond within one business day.</p>'."\n\n".'[fxlm_contact_form]'."\n\n".'<h3>Other ways to reach us</h3>'."\n".'<ul>'."\n".'<li><strong>Bug reports:</strong> include URL, browser, and what you expected — we triage daily.</li>'."\n".'<li><strong>Press &amp; partnerships:</strong> mention &quot;Press&quot; in the subject line for routing.</li>'."\n".'<li><strong>API support:</strong> see the <a href="/api-docs/">API docs</a> first; the form routes API tickets directly to engineering.</li>'."\n".'</ul>', 'section' => 'Company' ),
			array( 'slug' => 'careers',                'title' => 'Careers',                 'shortcode' => '<h2>Careers at BlockTicker</h2>'."\n".'<p>We\'re a small team building serious tooling for serious traders. If that sounds interesting, we want to hear from you — even when no role is publicly listed.</p>'."\n\n".'<h3>How we work</h3>'."\n".'<ul>'."\n".'<li><strong>Remote-first</strong> — most of the team is distributed across EU and Americas timezones.</li>'."\n".'<li><strong>Async by default</strong> — written specs and recorded demos over standing meetings.</li>'."\n".'<li><strong>Ship-and-iterate</strong> — small PRs, fast review, weekly releases.</li>'."\n".'</ul>'."\n\n".'<h3>What we look for</h3>'."\n".'<p>Engineering: PHP/WordPress, TypeScript/React, time-series databases, public-API design. Editorial: trading experience plus the discipline to write about probability without overselling. Design: data-dense interfaces that don\'t feel cramped.</p>'."\n\n".'<p>Send a CV and a short note to <a href="/contact/">/contact</a> with &quot;Careers&quot; in the subject line.</p>', 'section' => 'Company' ),
			array( 'slug' => 'terms',                  'title' => 'Terms of Service',        'shortcode' => '<h2>Terms of service</h2>'."\n".'<p><em>Effective '.gmdate('F j, Y').'.</em> By using BlockTicker.io (the &quot;Service&quot;) you agree to these terms. If you don\'t agree, please stop using the Service.</p>'."\n\n".'<h3>1. Not financial advice</h3>'."\n".'<p>Everything published on BlockTicker — including signals, briefs, scores, and analysis — is for <strong>educational and informational purposes only</strong>. It is not investment, financial, or trading advice. Trading involves risk of loss. Past performance does not guarantee future results. Consult a licensed advisor before making investment decisions.</p>'."\n\n".'<h3>2. Account &amp; eligibility</h3>'."\n".'<p>You must be at least 18 (or the age of majority in your jurisdiction) to create an account. You\'re responsible for keeping credentials secure and for activity under your account.</p>'."\n\n".'<h3>3. Acceptable use</h3>'."\n".'<p>Don\'t scrape the site faster than the documented API limits, don\'t reverse-engineer the signal pipeline, don\'t resell paid feeds, and don\'t use the Service to harass or defraud anyone.</p>'."\n\n".'<h3>4. Data &amp; privacy</h3>'."\n".'<p>See our <a href="/privacy-policy/">privacy policy</a>. We do not sell user data.</p>'."\n\n".'<h3>5. Service availability</h3>'."\n".'<p>We aim for high uptime but do not guarantee uninterrupted service. Maintenance, third-party API outages, or force majeure events may cause downtime. We are not liable for trading losses incurred during outages.</p>'."\n\n".'<h3>6. Limitation of liability</h3>'."\n".'<p>To the maximum extent permitted by law, BlockTicker is not liable for any direct, indirect, incidental, special, or consequential damages arising from use of the Service — including, without limitation, trading losses.</p>'."\n\n".'<h3>7. Changes</h3>'."\n".'<p>We may update these terms; material changes will be announced on-site at least 14 days before they take effect. Continued use after that constitutes acceptance.</p>'."\n\n".'<p>Questions? <a href="/contact/">Contact us</a>.</p>', 'section' => 'Company' ),

			// v119.28.34: Pricing page — provisioned as PRIVATE so it's queryable
			// in admin and visible to logged-in admins, but not to logged-out
			// visitors or search engines. Flip post_status to 'publish' from
			// WP Admin → Pages → Pricing when the team is ready to launch.
			// Tier prices ($19 / $79) are recommendations from the audit (Pass 3
			// §19) — review and adjust before publishing.
			array(
				'slug'      => 'pricing',
				'title'     => 'Pricing',
				'shortcode' => self::pricing_page_content(),
				'status'    => 'private',
				'section'   => 'Company',
			),

			// Auth & user
			array( 'slug' => 'login',                  'title' => 'Log In',                  'shortcode' => '[fxlm_breadcrumbs]'."\n".'<div class="bt-dash-wrap">'."\n".'<div class="fxlm-page-header"><div class="bt-eyebrow bt-eyebrow-green">ACCOUNT / LOG IN</div><h1>Log in to BlockTicker</h1><p>Sign in with Google, GitHub, or email to sync your watchlist, portfolio, and signal alerts across devices.</p></div>'."\n".'<div class="bt-auth-card">'."\n".'<button type="button" class="bt-auth-cta-btn" onclick="if(typeof btAuthOpen===\"function\")btAuthOpen(\"login\");else window.location.href=\"/wp-login.php\"">Log in with email →</button>'."\n".'<p class="bt-auth-or">or</p>'."\n".'<button type="button" class="bt-auth-cta-btn bt-auth-cta-btn--social" onclick="window.location.href=\"/wp-json/blockticker/v1/auth/google?return_to=\"+encodeURIComponent(location.origin+\"/\")">Continue with Google</button>'."\n".'<button type="button" class="bt-auth-cta-btn bt-auth-cta-btn--social" onclick="window.location.href=\"/wp-json/blockticker/v1/auth/github?return_to=\"+encodeURIComponent(location.origin+\"/\")">Continue with GitHub</button>'."\n".'<p class="bt-auth-foot">Don\'t have an account? <a href="/register/">Sign up free</a></p>'."\n".'</div>'."\n".'</div>'."\n".'<script>if(window.location.search.indexOf("openauth")>-1&&typeof btAuthOpen==="function")setTimeout(function(){btAuthOpen("login")},300);</script>', 'section' => 'Auth' ),
			array( 'slug' => 'register',               'title' => 'Sign Up',                 'shortcode' => '[fxlm_breadcrumbs]'."\n".'<div class="bt-dash-wrap">'."\n".'<div class="fxlm-page-header"><div class="bt-eyebrow bt-eyebrow-green">ACCOUNT / SIGN UP</div><h1>Create your free BlockTicker account</h1><p>Free forever. No credit card. 30-second setup. Get high-confidence signals to email or Telegram.</p></div>'."\n".'<div class="bt-auth-card">'."\n".'<button type="button" class="bt-auth-cta-btn" onclick="if(typeof btAuthOpen===\"function\")btAuthOpen(\"register\");else window.location.href=\"/wp-login.php?action=register\"">Sign up with email →</button>'."\n".'<p class="bt-auth-or">or</p>'."\n".'<button type="button" class="bt-auth-cta-btn bt-auth-cta-btn--social" onclick="window.location.href=\"/wp-json/blockticker/v1/auth/google?return_to=\"+encodeURIComponent(location.origin+\"/\")">Continue with Google</button>'."\n".'<button type="button" class="bt-auth-cta-btn bt-auth-cta-btn--social" onclick="window.location.href=\"/wp-json/blockticker/v1/auth/github?return_to=\"+encodeURIComponent(location.origin+\"/\")">Continue with GitHub</button>'."\n".'<p class="bt-auth-foot">Already have an account? <a href="/login/">Log in</a></p>'."\n".'</div>'."\n".'<div class="bt-prose" style="margin-top:36px;text-align:center"><p style="max-width:520px;margin:0 auto;font-size:13px;color:#94a3b8">By signing up you agree to our <a href="/terms/" style="color:#00FF66">Terms</a> and <a href="/privacy-policy/" style="color:#00FF66">Privacy Policy</a>. We never sell your data.</p></div>'."\n".'</div>'."\n".'<script>if(window.location.search.indexOf("openauth")>-1&&typeof btAuthOpen==="function")setTimeout(function(){btAuthOpen("register")},300);</script>', 'section' => 'Auth' ),
			array( 'slug' => 'dashboard',              'title' => 'Dashboard',               'shortcode' => '[bt_dashboard]',                'section' => 'Auth' ),
			array( 'slug' => 'portfolio',              'title' => 'Portfolio',               'shortcode' => '[bt_portfolio_v2]',             'section' => 'Auth' ),
			array( 'slug' => 'watchlist',              'title' => 'Watchlist',               'shortcode' => '[bt_watchlist_v2]',             'section' => 'Auth' ),
			array( 'slug' => 'screeners',              'title' => 'Screeners',               'shortcode' => '[bt_screeners]',                'section' => 'Auth' ),
			array( 'slug' => 'following',              'title' => 'Following',               'shortcode' => '[bt_following]',                'section' => 'Auth' ),
			array( 'slug' => 'alerts',                 'title' => 'Alerts',                  'shortcode' => '[bt_alert_hub]',                'section' => 'Auth' ),
			array( 'slug' => 'account',                'title' => 'Account Settings',        'shortcode' => '<h2>Account Settings</h2>'."\n".'<p>Manage your profile, password, notification preferences, and connected integrations.</p>'."\n".'<p><a href="/wp-admin/profile.php" class="button">Edit profile</a></p>', 'section' => 'Auth' ),
		);
	}

	/* ──────────────────────────────────────────────────────────────────
	 * v119.28.34: Pricing page content
	 *
	 * Returns the full HTML for the /pricing/ page. Provisioned with
	 * post_status='private' — see url_map() entry. The markup uses the
	 * v31 design tokens (.bt-hero, .bt-section, .bt-card, .bt-grid) so
	 * styling inherits automatically. A small <style> block scoped to
	 * .bt-pricing-page adds the tier-card-specific rules that aren't
	 * worth promoting to bt-tokens.css until this page goes live.
	 *
	 * IMPORTANT — before publishing:
	 *   1. Confirm tier prices ($19 Pro / $79 Team) with product
	 *   2. Confirm free-trial-vs-free-forever policy
	 *   3. Wire the CTA buttons to real Stripe checkout / signup flows
	 *   4. Decide on student/educator discount policy
	 *
	 * Audit reference: Pass 3 §19.
	 * ────────────────────────────────────────────────────────────────── */
	private static function pricing_page_content() {
		$styles = '<style>
.bt-pricing-page .bt-pricing-grid { gap: 24px; display: grid; grid-template-columns: repeat(3, 1fr); }
.bt-pricing-page .bt-pricing-card { position: relative; display: flex; flex-direction: column; gap: 24px; padding: 32px; background: var(--bt-bg-elev-1, #11141A); border: 1px solid var(--bt-border-2, rgba(255,255,255,0.10)); border-radius: 16px; }
.bt-pricing-page .bt-pricing-card--featured { border-color: var(--bt-accent, #00FF66); background: linear-gradient(180deg, rgba(0,255,102,0.04) 0%, var(--bt-bg-elev-1, #11141A) 60%); transform: scale(1.02); box-shadow: 0 12px 40px rgba(0,255,102,0.08); }
.bt-pricing-page .bt-pricing-card__badge { position: absolute; top: -12px; right: 32px; background: var(--bt-accent, #00FF66); color: var(--bt-bg-page, #0A0B0D); padding: 4px 12px; border-radius: 999px; font-size: 11px; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; }
.bt-pricing-page .bt-pricing-card__name { font-size: 24px; font-weight: 600; color: var(--bt-text-1, #F8FAFC); margin: 0; }
.bt-pricing-page .bt-pricing-card__tagline { color: var(--bt-text-4, #9DAAC0); font-size: 13px; margin: 4px 0 0; }
.bt-pricing-page .bt-pricing-card__price { margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--bt-border-1, rgba(255,255,255,0.06)); }
.bt-pricing-page .bt-pricing-card__amount { font-size: 40px; font-weight: 600; color: var(--bt-text-1, #F8FAFC); font-variant-numeric: tabular-nums; letter-spacing: -0.02em; }
.bt-pricing-page .bt-pricing-card__period { display: block; margin-top: 4px; font-size: 12px; color: var(--bt-text-5, #7B8AA0); }
.bt-pricing-page .bt-pricing-card__cta { display: block; text-align: center; padding: 12px 16px; border-radius: 8px; font-weight: 600; text-decoration: none; transition: all 120ms ease; }
.bt-pricing-page .bt-pricing-card__cta--primary { background: var(--bt-accent, #00FF66); color: var(--bt-bg-page, #0A0B0D); }
.bt-pricing-page .bt-pricing-card__cta--primary:hover { background: var(--bt-bull, #22D38F); }
.bt-pricing-page .bt-pricing-card__cta--secondary { background: transparent; color: var(--bt-text-1, #F8FAFC); border: 1px solid var(--bt-border-2, rgba(255,255,255,0.10)); }
.bt-pricing-page .bt-pricing-card__cta--secondary:hover { border-color: var(--bt-accent, #00FF66); }
.bt-pricing-page .bt-pricing-card__features { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 10px; }
.bt-pricing-page .bt-pricing-card__features li { font-size: 14px; line-height: 1.5; color: var(--bt-text-3, #CBD5E1); }
.bt-pricing-page .bt-pricing-card__inherit { font-size: 11px; color: var(--bt-text-5, #7B8AA0); text-transform: uppercase; letter-spacing: 0.06em; font-weight: 600; border-bottom: 1px solid var(--bt-border-1, rgba(255,255,255,0.06)); padding-bottom: 8px; margin-bottom: 4px; }
.bt-pricing-page .bt-faq { background: var(--bt-bg-elev-1, #11141A); border: 1px solid var(--bt-border-1, rgba(255,255,255,0.06)); border-radius: 8px; padding: 16px 20px; margin-bottom: 12px; }
.bt-pricing-page .bt-faq summary { font-weight: 600; cursor: pointer; color: var(--bt-text-1, #F8FAFC); list-style: none; }
.bt-pricing-page .bt-faq summary::-webkit-details-marker { display: none; }
.bt-pricing-page .bt-faq summary::before { content: "+"; display: inline-block; width: 20px; color: var(--bt-accent, #00FF66); font-weight: 700; }
.bt-pricing-page .bt-faq[open] summary::before { content: "−"; }
.bt-pricing-page .bt-faq p { margin: 12px 0 0 20px; color: var(--bt-text-3, #CBD5E1); font-size: 14px; line-height: 1.6; }
.bt-pricing-page .bt-faq a { color: var(--bt-accent, #00FF66); }
@media (max-width: 1024px) {
  .bt-pricing-page .bt-pricing-grid { grid-template-columns: 1fr; }
  .bt-pricing-page .bt-pricing-card--featured { transform: none; }
  .bt-pricing-page .bt-pricing-card__badge { right: 16px; }
}
</style>';

		$html  = '<div class="bt-pricing-page">';
		$html .= '[fxlm_breadcrumbs]';
		$html .= $styles;

		// Hero
		$html .= '<section class="bt-hero">'
		      .  '<div class="bt-hero__eyebrow">Pricing · Free for life · No card needed</div>'
		      .  '<h1 class="bt-hero__title">Pick the plan that matches the work.</h1>'
		      .  '<p class="bt-hero__lede">Start free — every reader gets the desk brief, live prices, and the news feed. Upgrade when you need automation, alerts, or team collaboration. Cancel anytime, in two clicks.</p>'
		      .  '</section>';

		// Tier cards
		$html .= '<section class="bt-section">'
		      .  '<div class="bt-pricing-grid">';

		// Free tier
		$html .= '<div class="bt-pricing-card">'
		      .  '<div class="bt-pricing-card__head">'
		      .    '<h2 class="bt-pricing-card__name">Free</h2>'
		      .    '<p class="bt-pricing-card__tagline">For market watchers</p>'
		      .    '<div class="bt-pricing-card__price">'
		      .      '<span class="bt-pricing-card__amount">$0</span>'
		      .      '<span class="bt-pricing-card__period">forever · no card</span>'
		      .    '</div>'
		      .  '</div>'
		      .  '<a class="bt-pricing-card__cta bt-pricing-card__cta--secondary" href="/register/">Create free account</a>'
		      .  '<ul class="bt-pricing-card__features">'
		      .    '<li>✓ Live prices · 100+ markets</li>'
		      .    '<li>✓ Daily desk brief</li>'
		      .    '<li>✓ News feed · 24 sources</li>'
		      .    '<li>✓ Signal archive (read-only)</li>'
		      .    '<li>✓ Methodology, fully open</li>'
		      .  '</ul>'
		      .  '</div>';

		// Pro tier (featured)
		$html .= '<div class="bt-pricing-card bt-pricing-card--featured">'
		      .  '<div class="bt-pricing-card__badge">Most popular</div>'
		      .  '<div class="bt-pricing-card__head">'
		      .    '<h2 class="bt-pricing-card__name">Pro</h2>'
		      .    '<p class="bt-pricing-card__tagline">For serious individual traders</p>'
		      .    '<div class="bt-pricing-card__price">'
		      .      '<span class="bt-pricing-card__amount">$19</span>'
		      .      '<span class="bt-pricing-card__period">/ month · $190/yr (save 17%)</span>'
		      .    '</div>'
		      .  '</div>'
		      .  '<a class="bt-pricing-card__cta bt-pricing-card__cta--primary" href="/register/?plan=pro">Start Pro · 7-day free trial</a>'
		      .  '<ul class="bt-pricing-card__features">'
		      .    '<li class="bt-pricing-card__inherit">Everything in Free, plus:</li>'
		      .    '<li>✓ Watchlists · up to 50 assets</li>'
		      .    '<li>✓ Custom alerts · email + push</li>'
		      .    '<li>✓ Signal email digest (daily / weekly)</li>'
		      .    '<li>✓ Webhooks · 50 events/day</li>'
		      .    '<li>✓ API · 1000 requests/day</li>'
		      .    '<li>✓ Data export · CSV/JSON</li>'
		      .    '<li>✓ Telegram &amp; X integrations</li>'
		      .  '</ul>'
		      .  '</div>';

		// Team tier
		$html .= '<div class="bt-pricing-card">'
		      .  '<div class="bt-pricing-card__head">'
		      .    '<h2 class="bt-pricing-card__name">Team</h2>'
		      .    '<p class="bt-pricing-card__tagline">For trading desks &amp; prop firms</p>'
		      .    '<div class="bt-pricing-card__price">'
		      .      '<span class="bt-pricing-card__amount">$79</span>'
		      .      '<span class="bt-pricing-card__period">/ month per seat · 3 seats min.</span>'
		      .    '</div>'
		      .  '</div>'
		      .  '<a class="bt-pricing-card__cta bt-pricing-card__cta--secondary" href="/contact/?subject=team-tier">Talk to sales</a>'
		      .  '<ul class="bt-pricing-card__features">'
		      .    '<li class="bt-pricing-card__inherit">Everything in Pro, plus:</li>'
		      .    '<li>✓ Shared workspace + watchlists</li>'
		      .    '<li>✓ SSO · SAML / Google Workspace</li>'
		      .    '<li>✓ Audit log · 90 days retention</li>'
		      .    '<li>✓ Priority data feeds (sub-second)</li>'
		      .    '<li>✓ Webhooks · 5000 events/day</li>'
		      .    '<li>✓ API · 50k requests/day</li>'
		      .    '<li>✓ Slack integration</li>'
		      .    '<li>✓ Dedicated support · 4h SLA</li>'
		      .  '</ul>'
		      .  '</div>';

		$html .= '</div></section>';

		// FAQ
		$html .= '<section class="bt-section">'
		      .  '<div class="bt-section__head">'
		      .    '<span class="bt-section__num">02</span>'
		      .    '<h2 class="bt-section__title">Pricing questions</h2>'
		      .  '</div>';

		$faqs = array(
			array(
				'q' => 'Can I switch plans later?',
				'a' => 'Yes — upgrade or downgrade in your dashboard. Mid-cycle changes are pro-rated.',
			),
			array(
				'q' => 'What payment methods do you accept?',
				'a' => 'All major cards via Stripe. Annual plans also accept ACH and wire transfer.',
			),
			array(
				'q' => 'Is there a refund policy?',
				'a' => 'Pro: 30-day money-back, no questions asked. Team: pro-rated refund within 14 days of first invoice.',
			),
			array(
				'q' => 'Do you offer student or educator discounts?',
				'a' => 'Yes — 50% off Pro for verified students and educators. <a href="/contact/?subject=education">Email us</a> with proof of status.',
			),
			array(
				'q' => 'What happens if I cancel?',
				'a' => 'You keep access until the end of the billing period, then your account reverts to Free. Your watchlists, archived signals, and alert history are preserved for 90 days.',
			),
			array(
				'q' => 'Are signals financial advice?',
				'a' => '<strong>No.</strong> Everything we publish is educational. Trading involves risk of loss. Read the <a href="/methodology/">full methodology</a> and the <a href="/terms/">terms</a> for the boring legal version.',
			),
		);
		foreach ( $faqs as $faq ) {
			$html .= '<details class="bt-faq">'
			      .  '<summary>' . esc_html( $faq['q'] ) . '</summary>'
			      .  '<p>' . $faq['a'] . '</p>'
			      .  '</details>';
		}

		$html .= '</section>';

		// Closing CTA
		$html .= '<section class="bt-section">'
		      .  '<div class="bt-card" style="text-align:center; padding:48px 32px; border:1px solid var(--bt-accent, #00FF66); background:linear-gradient(180deg, rgba(0,255,102,0.04) 0%, var(--bt-bg-elev-1, #11141A) 60%);">'
		      .    '<h2 style="margin:0 0 8px; font-size:28px;">Try Pro free for 7 days.</h2>'
		      .    '<p style="margin:0 0 24px; color:var(--bt-text-3, #CBD5E1);">No card needed. Cancel in two clicks if it\'s not for you.</p>'
		      .    '<a class="bt-pricing-card__cta bt-pricing-card__cta--primary" style="display:inline-block; padding:14px 32px;" href="/register/?plan=pro">Start Pro free →</a>'
		      .  '</div>'
		      .  '</section>';

		$html .= '</div>'; // /.bt-pricing-page

		return $html;
	}

	/* ──────────────────────────────────────────────────────────────────
	 * Lookup: does a page with this slug/path exist and is it published?
	 * Handles nested slugs (parent/child) by resolving the path.
	 * ────────────────────────────────────────────────────────────────── */
	private static function page_status( $slug ) {
		$page = get_page_by_path( $slug, OBJECT, 'page' );
		// v119.28.34: 'private' counts as a successful provision — pages
		// flagged 'private' in url_map() are intentionally not-yet-public
		// (e.g. pricing). Without this, provisioning runs would loop trying
		// to recreate the same page every time.
		if ( $page && in_array( $page->post_status, array( 'publish', 'private' ), true ) ) {
			return array( 'state' => 'ok', 'page' => $page );
		}
		if ( $page ) {
			return array( 'state' => 'draft', 'page' => $page );
		}
		return array( 'state' => 'missing', 'page' => null );
	}

	/* ──────────────────────────────────────────────────────────────────
	 * Admin menu — Tools → BlockTicker Pages
	 * ────────────────────────────────────────────────────────────────── */
	public static function register_admin_page() {
		add_management_page(
			'BlockTicker Pages',
			'BlockTicker Pages',
			'manage_options',
			'bt-pages',
			array( __CLASS__, 'render_admin_page' )
		);
	}

	public static function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'No permission' ); }

		$map = self::url_map();
		$by_section = array();
		$counts = array( 'ok' => 0, 'draft' => 0, 'missing' => 0 );

		foreach ( $map as $entry ) {
			$st = self::page_status( $entry['slug'] );
			$entry['_status'] = $st['state'];
			$entry['_page']   = $st['page'];
			$counts[ $st['state'] ]++;
			$by_section[ $entry['section'] ][] = $entry;
		}

		$home_on   = get_option( self::OPT_HOMEPAGE ) === '1';
		$global_on = get_option( self::OPT_GLOBAL_NAV ) === '1';
		$nonce     = wp_create_nonce( self::NONCE );
		$action    = admin_url( 'admin-post.php' );

		// Handle flash messages
		$flash = isset( $_GET['bt_msg'] ) ? sanitize_text_field( $_GET['bt_msg'] ) : '';
		?>
		<div class="wrap">
			<h1>BlockTicker — Pages &amp; Homepage</h1>

			<?php if ( $flash ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $flash ); ?></p></div>
			<?php endif; ?>

			<div style="background:#fff;border:1px solid #c3c4c7;padding:16px 20px;margin:16px 0;border-left:4px solid #00a32a">
				<h2 style="margin-top:0">Step 1 — Use the new landing as homepage</h2>
				<p>The revamped landing template is registered at <code>[blockticker_landing]</code>.
				Enable this option to render it on the site's front page automatically (no need to touch <em>Settings → Reading</em>).</p>
				<form method="post" action="<?php echo esc_url( $action ); ?>" style="display:inline">
					<input type="hidden" name="action" value="bt_toggle_homepage">
					<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
					<input type="hidden" name="enable" value="<?php echo $home_on ? '0' : '1'; ?>">
					<button type="submit" class="button button-<?php echo $home_on ? 'secondary' : 'primary'; ?>">
						<?php echo $home_on ? '✓ Enabled — click to disable' : 'Enable revamped landing on homepage'; ?>
					</button>
				</form>
				<?php if ( $home_on ) : ?>
					<p style="margin-top:10px;color:#00a32a"><strong>✓ Active.</strong> Visiting <a href="<?php echo esc_url( home_url('/') ); ?>" target="_blank"><?php echo esc_url( home_url('/') ); ?></a> now renders the new landing.</p>
				<?php endif; ?>
			</div>

			<div style="background:#fff;border:1px solid #c3c4c7;padding:16px 20px;margin:16px 0;border-left:4px solid #8c52ff">
				<h2 style="margin-top:0">Step 2 — Use the new navigation site-wide</h2>
				<p>By default, the new BlockTicker navigation only appears on the landing page. Every other page on the site still shows the old theme/plugin navbar.</p>
				<p>Enable this option to render the new disclaimer + ticker + nav on <strong>every page</strong> (crypto-markets, signals, news, etc.). The old <code>cp-navbar</code> and theme page-title are hidden automatically.</p>
				<form method="post" action="<?php echo esc_url( $action ); ?>" style="display:inline">
					<input type="hidden" name="action" value="bt_toggle_global_nav">
					<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
					<input type="hidden" name="enable" value="<?php echo $global_on ? '0' : '1'; ?>">
					<button type="submit" class="button button-<?php echo $global_on ? 'secondary' : 'primary'; ?>">
						<?php echo $global_on ? '✓ Enabled — click to disable' : 'Enable new navigation site-wide'; ?>
					</button>
				</form>
				<?php if ( $global_on ) : ?>
					<p style="margin-top:10px;color:#00a32a"><strong>✓ Active.</strong> Every page now uses the BlockTicker navigation. Click any nav item to confirm.</p>
				<?php endif; ?>
			</div>

			<div style="background:#fff;border:1px solid #c3c4c7;padding:16px 20px;margin:16px 0;border-left:4px solid #2271b1">
				<h2 style="margin-top:0">Step 3 — Provision missing pages</h2>
				<p>The landing page links to <strong><?php echo count( $map ); ?> destinations</strong>.
					Status: <strong style="color:#00a32a"><?php echo $counts['ok']; ?> live</strong> ·
					<strong style="color:#dba617"><?php echo $counts['draft']; ?> draft</strong> ·
					<strong style="color:#d63638"><?php echo $counts['missing']; ?> missing</strong>.</p>
				<?php if ( $counts['missing'] > 0 ) : ?>
					<form method="post" action="<?php echo esc_url( $action ); ?>" style="display:inline">
						<input type="hidden" name="action" value="bt_provision_pages">
						<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
						<input type="hidden" name="mode" value="all">
						<button type="submit" class="button button-primary">Create all <?php echo $counts['missing']; ?> missing pages</button>
					</form>
					<span style="color:#646970;margin-left:8px">Pages publish immediately — every entry has a working shortcode or functional content.</span>
				<?php else : ?>
					<p style="color:#00a32a"><strong>✓ All pages exist.</strong></p>
				<?php endif; ?>
			</div>

			<h2>Page inventory</h2>

			<?php foreach ( $by_section as $section => $entries ) : ?>
				<h3 style="margin-top:24px"><?php echo esc_html( $section ); ?></h3>
				<table class="wp-list-table widefat striped" style="max-width:1100px">
					<thead>
						<tr>
							<th style="width:40px">Status</th>
							<th>URL</th>
							<th>Title</th>
							<th>Shortcode</th>
							<th style="width:160px">Action</th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $entries as $e ) :
						$badge = array(
							'ok'      => '<span style="color:#00a32a;font-weight:700">●</span>',
							'draft'   => '<span style="color:#dba617;font-weight:700">●</span>',
							'missing' => '<span style="color:#d63638;font-weight:700">●</span>',
						)[ $e['_status'] ];
						$url = home_url( '/' . $e['slug'] . '/' );
					?>
						<tr>
							<td><?php echo $badge; ?></td>
							<td><a href="<?php echo esc_url( $url ); ?>" target="_blank">/<?php echo esc_html( $e['slug'] ); ?>/</a></td>
							<td><?php echo esc_html( $e['title'] ); ?></td>
							<td><code style="font-size:11px"><?php echo $e['shortcode'] ? esc_html( $e['shortcode'] ) : '<span style="color:#646970">—</span>'; ?></code></td>
							<td>
								<?php if ( $e['_status'] === 'missing' ) : ?>
									<form method="post" action="<?php echo esc_url( $action ); ?>" style="display:inline">
										<input type="hidden" name="action" value="bt_provision_pages">
										<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
										<input type="hidden" name="mode" value="single">
										<input type="hidden" name="slug" value="<?php echo esc_attr( $e['slug'] ); ?>">
										<button class="button button-small">Create</button>
									</form>
								<?php elseif ( $e['_page'] ) : ?>
									<a href="<?php echo esc_url( get_edit_post_link( $e['_page']->ID ) ); ?>" class="button button-small">Edit</a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/* ──────────────────────────────────────────────────────────────────
	 * Provisioner — actually creates the pages
	 * ────────────────────────────────────────────────────────────────── */
	public static function handle_provision() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'No permission' ); }
		check_admin_referer( self::NONCE );

		$mode = isset( $_POST['mode'] ) ? sanitize_key( $_POST['mode'] ) : '';
		$map  = self::url_map();
		$created = 0;

		if ( $mode === 'single' ) {
			$slug = isset( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( $_POST['slug'] ) ) : '';
			foreach ( $map as $e ) {
				if ( $e['slug'] === $slug ) {
					if ( self::create_page( $e ) ) { $created++; }
					break;
				}
			}
		} elseif ( $mode === 'all' ) {
			foreach ( $map as $e ) {
				$st = self::page_status( $e['slug'] );
				if ( $st['state'] === 'missing' ) {
					if ( self::create_page( $e ) ) { $created++; }
				}
			}
		}

		$msg = sprintf( '%d page(s) created and published.', $created );
		wp_safe_redirect( add_query_arg( 'bt_msg', urlencode( $msg ), admin_url( 'admin.php?page=fxlm-page-manager' ) ) );
		exit;
	}

	private static function create_page( $entry ) {
		$slug_parts = explode( '/', $entry['slug'] );
		$child_slug = array_pop( $slug_parts );
		$parent_id  = 0;

		// Resolve parent chain (e.g. forex/eur-usd → parent is "forex")
		if ( ! empty( $slug_parts ) ) {
			$parent_path = implode( '/', $slug_parts );
			$parent = get_page_by_path( $parent_path, OBJECT, 'page' );
			if ( $parent ) {
				$parent_id = $parent->ID;
			} else {
				// Auto-create the parent if missing — give it a real index page
				$parent_title = ucwords( str_replace( array( '-', '/' ), array( ' ', ' / ' ), $parent_path ) );
				$parent_entry = array(
					'slug'      => $parent_path,
					'title'     => $parent_title,
					'shortcode' => '<h2>' . esc_html( $parent_title ) . '</h2><p>Section index.</p>',
				);
				$parent_id = self::create_page( $parent_entry );
			}
		}

		$content = $entry['shortcode']; // always populated now — verified by url_map() audit

		// v119.28.34: per-entry status override. Defaults to 'publish' (back-compat
		// for every existing entry). The pricing page uses 'private' so it's
		// queryable in admin but invisible to logged-out visitors and search engines.
		$status = ! empty( $entry['status'] ) ? sanitize_key( $entry['status'] ) : 'publish';
		if ( ! in_array( $status, array( 'publish', 'private', 'draft', 'pending' ), true ) ) {
			$status = 'publish';
		}

		$post_id = wp_insert_post( array(
			'post_title'   => $entry['title'],
			'post_name'    => $child_slug,
			'post_status'  => $status,
			'post_type'    => 'page',
			'post_parent'  => $parent_id,
			'post_content' => $content,
			'post_author'  => get_current_user_id(),
		), false );

		// Use the GeneratePress / theme's full-width template if available
		if ( $post_id && ! is_wp_error( $post_id ) ) {
			update_post_meta( $post_id, '_wp_page_template', 'default' );
			// v119.28.28 — stamp the content hash so future provisioning runs
			// can detect when the canonical shortcode in url_map() has changed.
			update_post_meta( $post_id, '_bt_provisioned_hash', md5( $content ) );
		}

		return is_wp_error( $post_id ) ? false : $post_id;
	}

	/* ──────────────────────────────────────────────────────────────────
	 * Homepage toggle + injector
	 * ────────────────────────────────────────────────────────────────── */
	public static function handle_homepage_toggle() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'No permission' ); }
		check_admin_referer( self::NONCE );

		$enable = isset( $_POST['enable'] ) && $_POST['enable'] === '1' ? '1' : '0';
		update_option( self::OPT_HOMEPAGE, $enable );

		$msg = $enable === '1' ? 'Revamped landing now active on homepage.' : 'Reverted to default homepage.';
		wp_safe_redirect( add_query_arg( 'bt_msg', urlencode( $msg ), admin_url( 'admin.php?page=fxlm-page-manager' ) ) );
		exit;
	}

	public static function handle_global_nav_toggle() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'No permission' ); }
		check_admin_referer( self::NONCE );

		$enable = isset( $_POST['enable'] ) && $_POST['enable'] === '1' ? '1' : '0';
		update_option( self::OPT_GLOBAL_NAV, $enable );

		$msg = $enable === '1' ? 'New BlockTicker navigation now active site-wide.' : 'Reverted to theme navigation.';
		wp_safe_redirect( add_query_arg( 'bt_msg', urlencode( $msg ), admin_url( 'admin.php?page=fxlm-page-manager' ) ) );
		exit;
	}

	/**
	 * Render chrome on every page (top of <body>) when global-nav toggle is on.
	 * Defines BT_CHROME_RENDERED so the [blockticker_landing] shortcode skips its own chrome.
	 */
	public static function render_global_chrome() {
		// v119.28.16 — Global chrome is now MANDATORY on every front-end page.
		// No toggle, no opt-in. The old cp-navbar/cp-footer have been fully
		// deprecated in class-navbar.php. This is the single source of chrome.
		if ( is_admin() ) { return; }
		if ( ! class_exists( 'BT_Landing_Revamp' ) ) { return; }
		if ( ! method_exists( 'BT_Landing_Revamp', 'render_chrome_only' ) ) { return; }

		// Mark chrome as rendered so the landing template skips its own chrome.
		if ( ! defined( 'BT_CHROME_RENDERED' ) ) {
			define( 'BT_CHROME_RENDERED', true );
		}

		echo BT_Landing_Revamp::render_chrome_only(); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/**
	 * Close the .btlp wrapper at the end of the page, if global chrome was rendered.
	 */
	public static function close_global_chrome() {
		// v119.28.16 — Always close the .btlp wrapper opened by render_global_chrome.
		if ( is_admin() ) { return; }
		if ( ! defined( 'BT_CHROME_RENDERED' ) ) { return; }

		echo "\n</div><!-- /.btlp (closed by BT_Page_Provisioner::close_global_chrome) -->\n";
	}

	/**
	 * When the toggle is on, replace homepage content with the landing shortcode.
	 * Hooks into `the_content` rather than the template, so it works with any theme.
	 */
	public static function inject_landing_on_homepage( $content ) {
		if ( get_option( self::OPT_HOMEPAGE ) !== '1' ) { return $content; }
		if ( ! is_front_page() && ! is_home() ) { return $content; }
		if ( ! in_the_loop() ) { return $content; }
		// Don't double-inject if the landing is already in the content
		if ( strpos( $content, 'blockticker_landing' ) !== false ) { return $content; }
		if ( strpos( $content, 'class="btlp"' ) !== false ) { return $content; }
		return do_shortcode( '[blockticker_landing]' );
	}
}

BT_Page_Provisioner::init();
