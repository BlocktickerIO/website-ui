<?php
/**
 * BT_Forecast — SEO daily forecast pages
 *
 * Adds:
 *   /forecast/                          — index of all tracked assets
 *   /forecast/{slug}/                   — evergreen (always today's) forecast
 *   /forecast/{slug}/YYYY-MM-DD/        — historical archive entry
 *
 * Generation strategy:
 *   1. Daily cron runs at 06:00 UTC, processes ONE asset at a time
 *      (each tick schedules the next tick 30s later — avoids long PHP runs +
 *      respects API rate limits).
 *   2. For each asset: build context (price, sentiment, headlines), render a
 *      deterministic template using live data, then OPTIONALLY enhance with
 *      AI if Anthropic API key is configured.
 *   3. Result stored in wp_options as bt_forecast_{slug}; previous day rotated
 *      into bt_forecast_archive (capped at 30 days).
 *
 * SEO discipline:
 *   - Evergreen URLs are canonical, indexable, high priority.
 *   - Dated URLs canonical-point to evergreen for the first 7 days, then go
 *     noindex,follow to avoid duplicate-content penalty.
 *   - Schema.org Article markup with named E-E-A-T analyst attribution.
 *
 * @since 119.10.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BT_Forecast {

    /** Storage option prefix for current-day forecast per asset. */
    const OPT_CURRENT  = 'bt_forecast_';

    /** Storage option for the rolling archive (last 30 days). */
    const OPT_ARCHIVE  = 'bt_forecast_archive';

    /** How many days of archive to keep. */
    const ARCHIVE_DAYS = 30;

    /** Days after which a dated URL becomes noindex (still discoverable). */
    const NOINDEX_AGE_DAYS = 7;

    /** Cron throttle: seconds between successive asset generations. */
    const COOLDOWN_BETWEEN = 30;

    /**
     * Tracked assets. Symbol must be the canonical CoinGecko id (crypto)
     * or the EUR/USD-style pair string (forex), so it works with
     * BT_AIBlog::assemble_asset_context().
     *
     * Slug is the URL segment ('btc' → /forecast/btc/).
     */
    public static function tracked_assets() {
        return apply_filters( 'bt_forecast_tracked_assets', array(
            // Crypto top 5 by market cap.
            array( 'slug' => 'btc',     'symbol' => 'BTC',     'name' => 'Bitcoin',  'type' => 'crypto', 'tv' => 'BINANCE:BTCUSDT' ),
            array( 'slug' => 'eth',     'symbol' => 'ETH',     'name' => 'Ethereum', 'type' => 'crypto', 'tv' => 'BINANCE:ETHUSDT' ),
            array( 'slug' => 'sol',     'symbol' => 'SOL',     'name' => 'Solana',   'type' => 'crypto', 'tv' => 'BINANCE:SOLUSDT' ),
            array( 'slug' => 'xrp',     'symbol' => 'XRP',     'name' => 'XRP',      'type' => 'crypto', 'tv' => 'BINANCE:XRPUSDT' ),
            array( 'slug' => 'bnb',     'symbol' => 'BNB',     'name' => 'BNB',      'type' => 'crypto', 'tv' => 'BINANCE:BNBUSDT' ),
            // Forex top 5 by volume.
            array( 'slug' => 'eurusd',  'symbol' => 'EUR/USD', 'name' => 'EUR/USD',  'type' => 'forex',  'tv' => 'FX:EURUSD' ),
            array( 'slug' => 'gbpusd',  'symbol' => 'GBP/USD', 'name' => 'GBP/USD',  'type' => 'forex',  'tv' => 'FX:GBPUSD' ),
            array( 'slug' => 'usdjpy',  'symbol' => 'USD/JPY', 'name' => 'USD/JPY',  'type' => 'forex',  'tv' => 'FX:USDJPY' ),
            array( 'slug' => 'audusd',  'symbol' => 'AUD/USD', 'name' => 'AUD/USD',  'type' => 'forex',  'tv' => 'FX:AUDUSD' ),
            array( 'slug' => 'usdcad',  'symbol' => 'USD/CAD', 'name' => 'USD/CAD',  'type' => 'forex',  'tv' => 'FX:USDCAD' ),
        ) );
    }

    public static function init() {
        // URL routing.
        add_action( 'init',                   array( __CLASS__, 'add_rewrite_rules' ) );
        add_filter( 'query_vars',             array( __CLASS__, 'add_query_vars' ) );
        add_action( 'wp_loaded',              array( __CLASS__, 'intercept_url' ), 1 );

        // SEO meta + schema.
        add_action( 'wp_head',                array( __CLASS__, 'output_meta' ) );
        add_filter( 'document_title_parts',   array( __CLASS__, 'filter_title' ) );

        // Sitemap integration.
        add_filter( 'bt_sitemap_extra_urls',  array( __CLASS__, 'sitemap_urls' ) );

        // Cron.
        add_action( 'init',                   array( __CLASS__, 'maybe_schedule_cron' ), 30 );
        add_action( 'bt_forecast_daily_kick', array( __CLASS__, 'cron_kick' ) );
        add_action( 'bt_forecast_one_asset',  array( __CLASS__, 'cron_process_one' ) );

        // Admin.
        add_action( 'admin_menu',             array( __CLASS__, 'register_admin_menu' ), 25 );
        add_action( 'admin_post_bt_forecast_generate_now', array( __CLASS__, 'admin_generate_now' ) );
    }

    /* ============================================================
     *  ROUTING
     * ============================================================ */

    public static function add_rewrite_rules() {
        // Index: /forecast/
        add_rewrite_rule( '^forecast/?$',
            'index.php?bt_forecast_view=index', 'top' );
        // Evergreen: /forecast/{slug}/
        add_rewrite_rule( '^forecast/([a-z0-9-]+)/?$',
            'index.php?bt_forecast_view=detail&bt_forecast_slug=$matches[1]', 'top' );
        // Dated: /forecast/{slug}/YYYY-MM-DD/
        add_rewrite_rule( '^forecast/([a-z0-9-]+)/(\d{4}-\d{2}-\d{2})/?$',
            'index.php?bt_forecast_view=detail&bt_forecast_slug=$matches[1]&bt_forecast_date=$matches[2]', 'top' );
    }

    public static function add_query_vars( $vars ) {
        $vars[] = 'bt_forecast_view';
        $vars[] = 'bt_forecast_slug';
        $vars[] = 'bt_forecast_date';
        return $vars;
    }

    /**
     * Primary route — parses REQUEST_URI directly, works regardless of
     * whether rewrite rules have been flushed (mirrors BT_AssetPages).
     */
    public static function intercept_url() {
        $uri = trim( parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ), '/' );

        if ( $uri === 'forecast' ) {
            self::render_index();
            exit;
        }
        if ( preg_match( '#^forecast/([a-z0-9-]+)/?$#', $uri, $m ) ) {
            self::render_detail( $m[1], '' );
            exit;
        }
        if ( preg_match( '#^forecast/([a-z0-9-]+)/(\d{4}-\d{2}-\d{2})/?$#', $uri, $m ) ) {
            self::render_detail( $m[1], $m[2] );
            exit;
        }
    }

    /* ============================================================
     *  RENDERING — INDEX
     * ============================================================ */

    public static function render_index() {
        global $bt_forecast_current;
        $bt_forecast_current = array( 'view' => 'index' );

        $assets = self::tracked_assets();

        // Pre-compute summary stats so we can show a top-of-page "market pulse"
        // strip — it gives the page an instant data-rich feel above the cards.
        $bull = $bear = $neutral = 0;
        $forecasts_data = array();
        foreach ( $assets as $a ) {
            $f = self::get_current_forecast( $a['slug'] );
            $forecasts_data[ $a['slug'] ] = $f;
            $v = strtolower( $f['verdict'] ?? 'neutral' );
            if ( $v === 'bullish' ) $bull++;
            elseif ( $v === 'bearish' ) $bear++;
            else $neutral++;
        }
        $total_assets = count( $assets );

        get_header();
        ?>
        <main class="bt-forecast-index">
            <div class="bt-dash-wrap" style="max-width:1400px;margin:0 auto;padding:0 20px 80px">

                <div class="fxlm-page-header">
                    <span class="bt-eyebrow bt-eyebrow-green">FORECASTS · DAILY</span>
                    <h1>Daily Market Forecasts</h1>
                    <p class="fxlm-page-sub">
                        Fresh outlook every morning at 06:00 UTC. Combines live price data, recent
                        headlines, and sentiment signals into actionable analysis. <em>Not financial
                        advice — see disclaimers below each forecast.</em>
                    </p>
                </div>

                <!-- Market pulse strip — quick aggregate read -->
                <div class="bt-pulse-strip">
                    <div class="bt-pulse-item bt-pulse-bull">
                        <div class="bt-pulse-num"><?php echo $bull; ?></div>
                        <div class="bt-pulse-lbl">▲ BULLISH</div>
                    </div>
                    <div class="bt-pulse-item bt-pulse-neutral">
                        <div class="bt-pulse-num"><?php echo $neutral; ?></div>
                        <div class="bt-pulse-lbl">◆ NEUTRAL</div>
                    </div>
                    <div class="bt-pulse-item bt-pulse-bear">
                        <div class="bt-pulse-num"><?php echo $bear; ?></div>
                        <div class="bt-pulse-lbl">▼ BEARISH</div>
                    </div>
                    <div class="bt-pulse-item bt-pulse-total">
                        <div class="bt-pulse-num"><?php echo $total_assets; ?></div>
                        <div class="bt-pulse-lbl">ASSETS · TODAY</div>
                    </div>
                </div>

                <div class="bt-forecast-grid-v2">
                    <?php $i = 0; foreach ( $assets as $a ) :
                        $f       = $forecasts_data[ $a['slug'] ];
                        $verdict = $f['verdict'] ?? 'NEUTRAL';
                        $price   = $f['price']   ?? null;
                        $chg     = isset( $f['chg_24h'] ) ? (float) $f['chg_24h'] : 0;
                        $chg_7d  = isset( $f['chg_7d']  ) ? (float) $f['chg_7d']  : 0;
                        $vcls    = strtolower( $verdict );
                        $summary = $f['summary'] ?? '';
                        // Truncate summary to a single-line teaser for the card
                        $teaser  = $summary ? wp_trim_words( wp_strip_all_tags( $summary ), 14, '…' ) : '';
                        $i++;
                    ?>
                    <a href="<?php echo esc_url( home_url( '/forecast/' . $a['slug'] . '/' ) ); ?>"
                       class="bt-fc-card bt-fc-<?php echo esc_attr( $vcls ); ?>"
                       style="--bt-fc-delay:<?php echo min( $i * 40, 600 ); ?>ms"
                       data-verdict="<?php echo esc_attr( $vcls ); ?>">

                        <!-- Subtle gradient overlay revealed on hover -->
                        <span class="bt-fc-glow" aria-hidden="true"></span>

                        <!-- Top row: type badge + verdict pill -->
                        <div class="bt-fc-top">
                            <div class="bt-fc-meta">
                                <span class="bt-fc-type"><?php echo esc_html( $a['type'] ); ?></span>
                                <h3 class="bt-fc-name"><?php echo esc_html( $a['name'] ); ?> <span class="bt-fc-symbol"><?php echo esc_html( $a['symbol'] ); ?></span></h3>
                            </div>
                            <span class="bt-fc-verdict bt-fcv-<?php echo esc_attr( $vcls ); ?>">
                                <?php echo esc_html( $verdict ); ?>
                            </span>
                        </div>

                        <!-- Price block — shimmers on hover -->
                        <?php if ( $price ) : ?>
                        <div class="bt-fc-price">
                            <span class="bt-fc-price-num"><?php echo $a['type'] === 'forex' ? esc_html( number_format( $price, 4 ) ) : '$' . esc_html( number_format( $price, $price < 1 ? 6 : 2 ) ); ?></span>
                        </div>

                        <!-- Dual-change row: 24h + 7d for context -->
                        <div class="bt-fc-changes">
                            <span class="bt-fc-chg <?php echo $chg >= 0 ? 'bt-fc-up' : 'bt-fc-dn'; ?>">
                                <?php echo $chg >= 0 ? '▲' : '▼'; ?> <?php echo esc_html( number_format( abs( $chg ), 2 ) ); ?>%
                                <span class="bt-fc-chg-lbl">24h</span>
                            </span>
                            <?php if ( $chg_7d != 0 ) : ?>
                            <span class="bt-fc-chg <?php echo $chg_7d >= 0 ? 'bt-fc-up' : 'bt-fc-dn'; ?>">
                                <?php echo $chg_7d >= 0 ? '▲' : '▼'; ?> <?php echo esc_html( number_format( abs( $chg_7d ), 2 ) ); ?>%
                                <span class="bt-fc-chg-lbl">7d</span>
                            </span>
                            <?php endif; ?>
                        </div>

                        <!-- Summary teaser — fades in on hover for hover-state info density -->
                        <?php if ( $teaser ) : ?>
                        <div class="bt-fc-teaser"><?php echo esc_html( $teaser ); ?></div>
                        <?php endif; ?>
                        <?php else : ?>
                        <div class="bt-fc-pending">⏳ Forecast generating…</div>
                        <?php endif; ?>

                        <!-- Footer: timestamp + arrow CTA -->
                        <div class="bt-fc-foot">
                            <span class="bt-fc-time">
                                <?php echo $f ? esc_html( gmdate( 'M j · H:i T', $f['generated_at'] ?? time() ) ) : 'No forecast yet'; ?>
                            </span>
                            <span class="bt-fc-cta" aria-hidden="true">View →</span>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>

                <p class="bt-fc-method">
                    <strong>How these are built:</strong> Each forecast combines real-time price data from CoinGecko/Frankfurter, news sentiment from our 24-source RSS pipeline, and recent trading-signal context. Updated every 24 hours. <a href="<?php echo esc_url( home_url( '/about/' ) ); ?>">Methodology &amp; sources →</a>
                </p>

            </div>
        </main>

        <style>
        /* ── Forecast Index v2 — animated card grid ────────────────────────── */

        /* Market pulse strip */
        .bt-pulse-strip {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1px;
            background: rgba(255,255,255,.06);
            margin: 24px 0;
            border: 1px solid rgba(255,255,255,.08);
        }
        .bt-pulse-item {
            background: var(--bt-bg-elev,#121316);
            padding: 18px 20px;
            text-align: center;
            transition: background .2s ease;
        }
        .bt-pulse-item:hover { background: rgba(255,255,255,.03); }
        .bt-pulse-num {
            font: 900 28px/1 var(--bt-font-display,inherit);
            letter-spacing: -.5px;
            font-variant-numeric: tabular-nums;
            margin-bottom: 6px;
        }
        .bt-pulse-lbl {
            font: 700 10px/1 var(--bt-font-mono,monospace);
            letter-spacing: .12em;
            color: var(--bt-text-3);
        }
        .bt-pulse-bull    .bt-pulse-num { color: var(--bt-accent,#00ff66); }
        .bt-pulse-bear    .bt-pulse-num { color: var(--bt-danger,#ff3b30); }
        .bt-pulse-neutral .bt-pulse-num { color: var(--bt-text-2,rgba(255,255,255,.7)); }
        .bt-pulse-total   .bt-pulse-num { color: var(--bt-text,#fff); }

        /* Card grid */
        .bt-forecast-grid-v2 {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(290px, 1fr));
            gap: 14px;
            margin-top: 24px;
        }

        /* Card — base */
        .bt-fc-card {
            position: relative;
            display: block;
            padding: 22px 22px 18px;
            background: linear-gradient(180deg, var(--bt-bg-elev,#121316) 0%, rgba(15,17,22,.95) 100%);
            border: 1px solid rgba(255,255,255,.08);
            border-radius: 4px;
            text-decoration: none !important;
            color: inherit;
            overflow: hidden;
            transition: transform .25s cubic-bezier(.2,.8,.2,1),
                        border-color .25s ease,
                        box-shadow .25s ease;
            opacity: 0;
            transform: translateY(8px);
            animation: bt-fc-enter .5s cubic-bezier(.2,.8,.2,1) forwards;
            animation-delay: var(--bt-fc-delay, 0ms);
            will-change: transform;
        }
        @keyframes bt-fc-enter {
            to { opacity: 1; transform: translateY(0); }
        }

        /* Card — hover state: lift + glow + accent border */
        .bt-fc-card:hover {
            transform: translateY(-4px);
            border-color: rgba(255,255,255,.18);
            box-shadow:
                0 14px 32px -12px rgba(0,0,0,.6),
                0 0 0 1px rgba(255,255,255,.04);
        }
        .bt-fc-bullish:hover {
            border-color: rgba(0,255,102,.35);
            box-shadow:
                0 14px 32px -12px rgba(0,0,0,.6),
                0 0 28px -8px rgba(0,255,102,.45);
        }
        .bt-fc-bearish:hover {
            border-color: rgba(255,59,48,.4);
            box-shadow:
                0 14px 32px -12px rgba(0,0,0,.6),
                0 0 28px -8px rgba(255,59,48,.4);
        }

        /* Glow — radial gradient that follows from top-right corner on hover */
        .bt-fc-glow {
            position: absolute;
            top: 0; right: 0;
            width: 70%; height: 100%;
            pointer-events: none;
            opacity: 0;
            transition: opacity .35s ease;
            background: radial-gradient(
                ellipse at top right,
                rgba(255,255,255,.06) 0%,
                transparent 60%
            );
        }
        .bt-fc-bullish .bt-fc-glow {
            background: radial-gradient(ellipse at top right, rgba(0,255,102,.12) 0%, transparent 60%);
        }
        .bt-fc-bearish .bt-fc-glow {
            background: radial-gradient(ellipse at top right, rgba(255,59,48,.10) 0%, transparent 60%);
        }
        .bt-fc-card:hover .bt-fc-glow { opacity: 1; }

        /* Top row */
        .bt-fc-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 14px;
            position: relative;
            z-index: 1;
        }
        .bt-fc-type {
            display: inline-block;
            font: 700 10px/1 var(--bt-font-mono,monospace);
            letter-spacing: .12em;
            color: var(--bt-text-3);
            text-transform: uppercase;
            margin-bottom: 6px;
        }
        .bt-fc-name {
            margin: 0;
            font: 900 19px/1.1 var(--bt-font-display,inherit);
            color: var(--bt-text,#fff);
            letter-spacing: -.3px;
        }
        .bt-fc-symbol {
            font-size: 12px;
            font-weight: 500;
            color: var(--bt-text-3);
            margin-left: 4px;
        }

        /* Verdict pill — animated pulse for non-neutral */
        .bt-fc-verdict {
            flex-shrink: 0;
            font: 700 10px/1 var(--bt-font-mono,monospace);
            letter-spacing: .08em;
            padding: 6px 10px;
            border: 1px solid;
            border-radius: 2px;
            transition: transform .25s ease;
        }
        .bt-fc-card:hover .bt-fc-verdict { transform: scale(1.06); }
        .bt-fcv-bullish {
            color: var(--bt-accent,#00ff66);
            border-color: rgba(0,255,102,.35);
            background: rgba(0,255,102,.08);
        }
        .bt-fcv-bearish {
            color: var(--bt-danger,#ff3b30);
            border-color: rgba(255,59,48,.4);
            background: rgba(255,59,48,.08);
        }
        .bt-fcv-neutral {
            color: var(--bt-text-2,rgba(255,255,255,.7));
            border-color: rgba(255,255,255,.18);
            background: rgba(255,255,255,.04);
        }

        /* Subtle pulse ring for high-confidence verdicts */
        .bt-fc-bullish .bt-fc-verdict::after,
        .bt-fc-bearish .bt-fc-verdict::after {
            content: '';
            position: absolute;
            inset: -2px;
            border: 1px solid currentColor;
            border-radius: 2px;
            opacity: 0;
            animation: bt-fc-pulse 2.4s ease-in-out infinite;
            pointer-events: none;
        }
        .bt-fc-verdict { position: relative; }
        @keyframes bt-fc-pulse {
            0%, 100% { opacity: 0; transform: scale(1); }
            50%      { opacity: .35; transform: scale(1.15); }
        }
        @media (prefers-reduced-motion: reduce) {
            .bt-fc-bullish .bt-fc-verdict::after,
            .bt-fc-bearish .bt-fc-verdict::after { animation: none; }
            .bt-fc-card { animation: none; opacity: 1; transform: none; }
        }

        /* Price block */
        .bt-fc-price {
            position: relative;
            z-index: 1;
            margin-bottom: 4px;
        }
        .bt-fc-price-num {
            display: inline-block;
            font: 900 26px/1 var(--bt-font-display,inherit);
            color: var(--bt-text,#fff);
            letter-spacing: -.5px;
            font-variant-numeric: tabular-nums;
            background: linear-gradient(90deg,
                var(--bt-text,#fff) 0%,
                var(--bt-text,#fff) 50%,
                rgba(255,255,255,.5) 100%);
            -webkit-background-clip: text;
            background-clip: text;
            background-size: 200% 100%;
            background-position: 0% 0%;
            transition: background-position .6s ease;
        }
        .bt-fc-card:hover .bt-fc-price-num {
            background-position: 100% 0%;
        }

        /* Change row */
        .bt-fc-changes {
            display: flex;
            gap: 14px;
            margin-top: 8px;
            position: relative;
            z-index: 1;
        }
        .bt-fc-chg {
            font: 600 12px/1 var(--bt-font-mono,monospace);
            font-variant-numeric: tabular-nums;
        }
        .bt-fc-chg-lbl {
            font-size: 10px;
            color: var(--bt-text-3);
            margin-left: 2px;
            letter-spacing: .08em;
        }
        .bt-fc-up { color: var(--bt-accent,#00ff66); }
        .bt-fc-dn { color: var(--bt-danger,#ff3b30); }

        /* Teaser — only revealed on hover */
        .bt-fc-teaser {
            margin-top: 14px;
            font-size: 12px;
            line-height: 1.5;
            color: var(--bt-text-2,rgba(255,255,255,.7));
            max-height: 0;
            opacity: 0;
            overflow: hidden;
            transition: max-height .35s ease, opacity .35s ease, margin-top .35s ease;
            position: relative;
            z-index: 1;
        }
        .bt-fc-card:hover .bt-fc-teaser {
            max-height: 80px;
            opacity: 1;
            margin-top: 14px;
        }

        /* Pending state */
        .bt-fc-pending {
            font-size: 12px;
            color: var(--bt-text-3);
            font-style: italic;
            margin: 8px 0;
            position: relative;
            z-index: 1;
        }

        /* Footer */
        .bt-fc-foot {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 16px;
            padding-top: 14px;
            border-top: 1px solid rgba(255,255,255,.06);
            font-size: 11px;
            color: var(--bt-text-3);
            font-family: var(--bt-font-mono,monospace);
            position: relative;
            z-index: 1;
        }
        .bt-fc-cta {
            color: var(--bt-accent,#00ff66);
            font-weight: 700;
            letter-spacing: .04em;
            opacity: 0;
            transform: translateX(-6px);
            transition: opacity .25s ease, transform .25s ease;
        }
        .bt-fc-card:hover .bt-fc-cta {
            opacity: 1;
            transform: translateX(0);
        }

        /* Methodology footer */
        .bt-fc-method {
            margin-top: 36px;
            font-size: 12px;
            color: var(--bt-text-3);
            max-width: 760px;
            line-height: 1.6;
        }
        .bt-fc-method strong { color: var(--bt-text-2,rgba(255,255,255,.7)); }
        .bt-fc-method a { color: var(--bt-accent,#00ff66); }

        /* Mobile tuning */
        @media (max-width: 640px) {
            .bt-pulse-strip { grid-template-columns: repeat(2, 1fr); }
            .bt-pulse-num { font-size: 24px; }
            .bt-forecast-grid-v2 { gap: 10px; }
            .bt-fc-card { padding: 18px 18px 16px; }
            .bt-fc-name { font-size: 17px; }
            .bt-fc-price-num { font-size: 22px; }
            /* On touch devices, no hover — show teaser permanently */
            .bt-fc-teaser { max-height: 60px; opacity: .85; margin-top: 12px; }
            .bt-fc-cta { opacity: 1; transform: none; }
        }
        </style>
        <?php
        get_footer();
    }

    /* ============================================================
     *  RENDERING — DETAIL
     * ============================================================ */

    public static function render_detail( $slug, $date = '' ) {
        $asset = self::find_asset_by_slug( $slug );
        if ( ! $asset ) {
            self::render_404();
            return;
        }

        // Fetch forecast: dated archive entry OR current snapshot.
        if ( $date ) {
            $forecast = self::get_archived_forecast( $slug, $date );
            if ( ! $forecast ) {
                self::render_404( sprintf( 'No %s forecast was published on %s. ', $asset['name'], $date )
                    . sprintf( '<a href="%s">View today\'s forecast →</a>',
                        esc_url( home_url( '/forecast/' . $slug . '/' ) ) ) );
                return;
            }
        } else {
            $forecast = self::get_current_forecast( $slug );
            // No forecast yet → generate on-demand for first hit.
            if ( ! $forecast ) {
                $forecast = self::generate_forecast( $asset );
            }
        }

        global $bt_forecast_current;
        $bt_forecast_current = array(
            'view'     => 'detail',
            'asset'    => $asset,
            'date'     => $date,
            'forecast' => $forecast,
        );

        $is_today  = empty( $date ) || $date === gmdate( 'Y-m-d' );
        $disp_date = $date ?: gmdate( 'Y-m-d' );

        get_header();
        ?>
        <main class="bt-forecast-detail">
            <div class="bt-dash-wrap" style="max-width:1100px;margin:0 auto;padding:0 20px 80px">

                <nav class="fxlm-breadcrumbs" style="font-size:12px;color:var(--bt-text-3);margin:16px 0;font-family:var(--bt-font-mono,monospace)">
                    <a href="<?php echo esc_url( home_url() ); ?>" style="color:var(--bt-text-3)">Home</a>
                    <span> / </span>
                    <a href="<?php echo esc_url( home_url( '/forecast/' ) ); ?>" style="color:var(--bt-text-3)">Forecasts</a>
                    <span> / </span>
                    <span style="color:var(--bt-accent)"><?php echo esc_html( $asset['name'] ); ?></span>
                    <?php if ( $date ) : ?><span style="color:var(--bt-text-3)"> · <?php echo esc_html( $date ); ?></span><?php endif; ?>
                </nav>

                <header style="margin:8px 0 32px">
                    <span class="bt-eyebrow bt-eyebrow-green" style="display:inline-block;font:700 10px/1 var(--bt-font-mono,monospace);letter-spacing:.08em;color:var(--bt-accent);text-transform:uppercase;margin-bottom:10px">FORECAST · <?php echo esc_html( strtoupper( $asset['type'] ) ); ?></span>
                    <h1 style="font:900 clamp(28px,4vw,40px)/1.05 var(--bt-font-display,inherit);color:var(--bt-text);margin:0 0 10px;letter-spacing:-1px">
                        <?php echo esc_html( $asset['name'] ); ?> Forecast <span style="font-size:.55em;color:var(--bt-text-3);font-weight:500;font-family:var(--bt-font-mono,monospace)">· <?php echo esc_html( $disp_date ); ?></span>
                    </h1>

                    <?php if ( ! empty( $forecast['verdict'] ) ) :
                        $vcls = strtolower( $forecast['verdict'] );
                    ?>
                    <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin-top:14px">
                        <span class="bt-forecast-verdict bt-fv-<?php echo esc_attr( $vcls ); ?>" style="font:700 11px/1 var(--bt-font-mono,monospace);letter-spacing:.08em;padding:7px 12px;border:1px solid"><?php echo esc_html( $forecast['verdict'] ); ?> · 24h</span>
                        <?php if ( ! empty( $forecast['price'] ) ) : ?>
                        <span style="font:900 24px/1 var(--bt-font-display,inherit);color:var(--bt-text);font-variant-numeric:tabular-nums">
                            <?php echo $asset['type'] === 'forex' ? esc_html( number_format( (float) $forecast['price'], 4 ) ) : '$' . esc_html( number_format( (float) $forecast['price'], $forecast['price'] < 1 ? 6 : 2 ) ); ?>
                        </span>
                        <?php endif; ?>
                        <?php if ( ! empty( $forecast['generated_at'] ) ) : ?>
                        <span style="font-size:11px;color:var(--bt-text-3);font-family:var(--bt-font-mono,monospace)">
                            Generated <?php echo esc_html( gmdate( 'M j H:i T', $forecast['generated_at'] ) ); ?>
                            <?php if ( ! empty( $forecast['source'] ) ) : ?>· <?php echo esc_html( $forecast['source'] ); ?><?php endif; ?>
                        </span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </header>

                <?php if ( ! empty( $forecast['html'] ) ) : ?>
                <article class="bt-forecast-body" style="background:var(--bt-bg-elev,#121316);padding:32px;border:1px solid rgba(255,255,255,.06);font-size:15px;line-height:1.7;color:var(--bt-text-2)">
                    <?php
                    // Forecast content is generated by us (template or our own AI prompt) —
                    // wp_kses_post is appropriate, allows headings, lists, paragraphs, em/strong.
                    echo wp_kses_post( $forecast['html'] );
                    ?>
                </article>
                <?php endif; ?>

                <!-- Disclaimer (always visible, per legal review) -->
                <aside style="margin-top:24px;padding:16px 20px;background:rgba(255,193,7,.04);border-left:3px solid rgba(255,193,7,.4);font-size:12px;color:var(--bt-text-3);line-height:1.6">
                    <strong style="color:var(--bt-text-2)">⚠ Disclaimer:</strong>
                    This forecast is for informational purposes only and does not constitute financial,
                    investment, trading, or other types of advice. Markets are volatile and past performance
                    does not predict future results. Always do your own research and consult a licensed
                    advisor before making investment decisions.
                </aside>

                <!-- Related links + archive nav -->
                <div style="margin-top:32px;display:grid;grid-template-columns:1fr 1fr;gap:1px;background:rgba(255,255,255,.06)">
                    <a href="<?php echo esc_url( home_url( '/' . $asset['type'] . '/' . $slug . '/' ) ); ?>"
                       style="padding:18px;background:var(--bt-bg-elev,#121316);text-decoration:none;color:inherit;display:block">
                        <div style="font:700 10px/1 var(--bt-font-mono,monospace);color:var(--bt-text-3);text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px">LIVE PAGE →</div>
                        <div style="font:900 16px/1.2 var(--bt-font-display,inherit);color:var(--bt-text)"><?php echo esc_html( $asset['name'] ); ?> live chart &amp; data</div>
                    </a>
                    <a href="<?php echo esc_url( home_url( '/forecast/' ) ); ?>"
                       style="padding:18px;background:var(--bt-bg-elev,#121316);text-decoration:none;color:inherit;display:block">
                        <div style="font:700 10px/1 var(--bt-font-mono,monospace);color:var(--bt-text-3);text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px">ALL FORECASTS →</div>
                        <div style="font:900 16px/1.2 var(--bt-font-display,inherit);color:var(--bt-text)">Browse the daily forecast index</div>
                    </a>
                </div>

                <?php
                // Archive strip — last 7 days.
                $archive_dates = self::get_archive_dates_for( $slug, 7 );
                if ( $archive_dates ) :
                ?>
                <div style="margin-top:32px">
                    <div style="font:700 10px/1 var(--bt-font-mono,monospace);color:var(--bt-text-3);text-transform:uppercase;letter-spacing:.06em;margin-bottom:10px">RECENT FORECASTS</div>
                    <div style="display:flex;gap:6px;flex-wrap:wrap">
                        <?php foreach ( $archive_dates as $d ) :
                            $is_active = ( $d === $disp_date );
                        ?>
                        <a href="<?php echo esc_url( home_url( '/forecast/' . $slug . '/' . ( $d === gmdate( 'Y-m-d' ) ? '' : $d . '/' ) ) ); ?>"
                           style="padding:6px 11px;background:<?php echo $is_active ? 'var(--bt-accent)' : 'var(--bt-bg-elev,#121316)'; ?>;color:<?php echo $is_active ? '#000' : 'var(--bt-text-2)'; ?>;font:700 11px/1 var(--bt-font-mono,monospace);text-decoration:none;border:1px solid <?php echo $is_active ? 'var(--bt-accent)' : 'rgba(255,255,255,.08)'; ?>"><?php echo esc_html( $d ); ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

            </div>
        </main>
        <?php
        get_footer();
    }

    private static function render_404( $message = '' ) {
        get_header();
        ?>
        <main style="max-width:760px;margin:60px auto;padding:0 20px;text-align:center">
            <h1 style="font:900 32px/1 var(--bt-font-display,inherit);color:var(--bt-text);margin-bottom:16px">Forecast not found</h1>
            <p style="color:var(--bt-text-3);font-size:15px;line-height:1.6"><?php echo $message ?: 'That forecast doesn\'t exist or hasn\'t been generated yet.'; // already escaped or HTML by caller ?></p>
            <p style="margin-top:20px"><a href="<?php echo esc_url( home_url( '/forecast/' ) ); ?>" style="color:var(--bt-accent);font-family:var(--bt-font-mono,monospace);text-transform:uppercase;font-size:12px">← Back to forecast index</a></p>
        </main>
        <?php
        get_footer();
    }

    /* ============================================================
     *  SEO META + SCHEMA
     * ============================================================ */

    public static function output_meta() {
        global $bt_forecast_current;
        if ( empty( $bt_forecast_current ) ) return;

        $site = get_option( 'bt_site_name', 'BlockTicker' );

        if ( $bt_forecast_current['view'] === 'index' ) {
            $title = "Daily Market Forecasts · {$site}";
            $desc  = 'Fresh BTC, ETH, forex forecasts every morning. Combines live price data, news sentiment, and trading signals.';
            echo '<link rel="canonical" href="' . esc_url( home_url( '/forecast/' ) ) . '">' . "\n";
            echo '<meta name="description" content="' . esc_attr( $desc ) . '">' . "\n";
            echo '<meta property="og:title" content="' . esc_attr( $title ) . '">' . "\n";
            echo '<meta property="og:description" content="' . esc_attr( $desc ) . '">' . "\n";
            return;
        }

        // Detail page.
        $asset    = $bt_forecast_current['asset'];
        $date     = $bt_forecast_current['date'];
        $forecast = $bt_forecast_current['forecast'] ?? array();
        $is_dated = ! empty( $date );
        $disp_d   = $date ?: gmdate( 'Y-m-d' );

        $evergreen_url = home_url( '/forecast/' . $asset['slug'] . '/' );
        $self_url      = $is_dated ? home_url( '/forecast/' . $asset['slug'] . '/' . $date . '/' ) : $evergreen_url;

        $title = sprintf( '%s Forecast · %s · %s', $asset['name'], $disp_d, $site );
        $desc  = ! empty( $forecast['summary'] )
            ? wp_strip_all_tags( $forecast['summary'] )
            : sprintf( 'Daily %s (%s) market forecast. Outlook, key levels, and supporting data updated every 24h.', $asset['name'], $asset['symbol'] );
        $desc  = mb_substr( $desc, 0, 160 );

        // Robots: today / recent → index; older than 7 days → noindex,follow.
        $age_days = $is_dated ? max( 0, ( strtotime( gmdate( 'Y-m-d' ) ) - strtotime( $date ) ) / DAY_IN_SECONDS ) : 0;
        $robots   = $age_days > self::NOINDEX_AGE_DAYS ? 'noindex,follow' : 'index,follow,max-image-preview:large';

        // Canonical: dated URLs canonical to evergreen for the first 7 days
        // (so today's same content under multiple URLs doesn't dilute), then
        // become self-canonical + noindex (the page is unique historical content).
        $canonical = ( $is_dated && $age_days <= self::NOINDEX_AGE_DAYS ) ? $evergreen_url : $self_url;

        echo '<link rel="canonical" href="' . esc_url( $canonical ) . '">' . "\n";
        echo '<meta name="robots" content="' . esc_attr( $robots ) . '">' . "\n";
        echo '<meta name="description" content="' . esc_attr( $desc ) . '">' . "\n";
        echo '<meta property="og:type" content="article">' . "\n";
        echo '<meta property="og:title" content="' . esc_attr( $title ) . '">' . "\n";
        echo '<meta property="og:description" content="' . esc_attr( $desc ) . '">' . "\n";
        echo '<meta property="og:url" content="' . esc_url( $self_url ) . '">' . "\n";
        echo '<meta property="article:published_time" content="' . esc_attr( gmdate( 'c', $forecast['generated_at'] ?? time() ) ) . '">' . "\n";
        echo '<meta property="article:modified_time" content="' . esc_attr( gmdate( 'c', $forecast['generated_at'] ?? time() ) ) . '">' . "\n";

        // Schema.org Article.
        $schema = array(
            '@context'         => 'https://schema.org',
            '@type'            => 'Article',
            'headline'         => $title,
            'description'      => $desc,
            'datePublished'    => gmdate( 'c', $forecast['generated_at'] ?? time() ),
            'dateModified'     => gmdate( 'c', $forecast['generated_at'] ?? time() ),
            'mainEntityOfPage' => array( '@type' => 'WebPage', '@id' => $self_url ),
            'author'           => array(
                '@type' => 'Organization',
                'name'  => $site . ' Research Desk',
                'url'   => home_url( '/about/' ),
            ),
            'publisher'        => array(
                '@type' => 'Organization',
                'name'  => $site,
                'url'   => home_url(),
            ),
            'about'            => array(
                '@type' => 'Thing',
                'name'  => $asset['name'],
            ),
        );
        echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES ) . '</script>' . "\n";
    }

    public static function filter_title( $parts ) {
        global $bt_forecast_current;
        if ( empty( $bt_forecast_current ) ) return $parts;

        if ( $bt_forecast_current['view'] === 'index' ) {
            $parts['title'] = 'Daily Market Forecasts';
        } elseif ( $bt_forecast_current['view'] === 'detail' && ! empty( $bt_forecast_current['asset'] ) ) {
            $a    = $bt_forecast_current['asset'];
            $date = $bt_forecast_current['date'] ?: gmdate( 'Y-m-d' );
            $parts['title'] = sprintf( '%s Forecast · %s', $a['name'], $date );
        }
        return $parts;
    }

    /* ============================================================
     *  SITEMAP
     * ============================================================ */

    public static function sitemap_urls( $urls ) {
        $today = gmdate( 'Y-m-d' );

        // Index page.
        $urls[] = array(
            'loc'        => home_url( '/forecast/' ),
            'lastmod'    => $today,
            'changefreq' => 'daily',
            'priority'   => '0.8',
        );

        // Evergreen detail pages — high priority, daily refresh.
        foreach ( self::tracked_assets() as $a ) {
            $urls[] = array(
                'loc'        => home_url( '/forecast/' . $a['slug'] . '/' ),
                'lastmod'    => $today,
                'changefreq' => 'daily',
                'priority'   => '0.9',
            );
        }

        // Last 7 days of dated archive URLs at lower priority.
        $archive = get_option( self::OPT_ARCHIVE, array() );
        if ( is_array( $archive ) ) {
            $cutoff = strtotime( '-7 days' );
            foreach ( $archive as $key => $entry ) {
                $parts = explode( '_', $key, 2 );
                if ( count( $parts ) !== 2 ) continue;
                list( $slug, $date ) = $parts;
                if ( strtotime( $date ) < $cutoff ) continue;
                $urls[] = array(
                    'loc'        => home_url( '/forecast/' . $slug . '/' . $date . '/' ),
                    'lastmod'    => $date,
                    'changefreq' => 'never',
                    'priority'   => '0.5',
                );
            }
        }

        return $urls;
    }

    /* ============================================================
     *  CRON / GENERATION
     * ============================================================ */

    public static function maybe_schedule_cron() {
        if ( ! wp_next_scheduled( 'bt_forecast_daily_kick' ) ) {
            // Schedule for next 06:00 UTC.
            $next = strtotime( 'tomorrow 06:00:00 UTC' );
            wp_schedule_event( $next, 'daily', 'bt_forecast_daily_kick' );
        }
    }

    /**
     * Kick off the daily run: rotate yesterday into archive, then schedule
     * one-asset-at-a-time generations 30s apart.
     */
    public static function cron_kick() {
        self::rotate_to_archive();

        $assets = self::tracked_assets();
        $delay  = 0;
        foreach ( $assets as $a ) {
            wp_schedule_single_event( time() + $delay, 'bt_forecast_one_asset', array( $a['slug'] ) );
            $delay += self::COOLDOWN_BETWEEN;
        }
    }

    public static function cron_process_one( $slug ) {
        $asset = self::find_asset_by_slug( $slug );
        if ( ! $asset ) return;
        self::generate_forecast( $asset );
    }

    /**
     * Move the previous day's forecast for each asset into the rolling archive.
     * Called once per day at the start of the cron run.
     */
    private static function rotate_to_archive() {
        $archive = get_option( self::OPT_ARCHIVE, array() );
        if ( ! is_array( $archive ) ) $archive = array();

        foreach ( self::tracked_assets() as $a ) {
            $cur = get_option( self::OPT_CURRENT . $a['slug'], null );
            if ( ! $cur || empty( $cur['date'] ) ) continue;
            // Only rotate if it's actually from a previous day.
            if ( $cur['date'] === gmdate( 'Y-m-d' ) ) continue;

            $key = $a['slug'] . '_' . $cur['date'];
            $archive[ $key ] = $cur;
        }

        // Trim archive to ARCHIVE_DAYS most recent per asset.
        $cutoff = strtotime( '-' . self::ARCHIVE_DAYS . ' days' );
        foreach ( $archive as $key => $entry ) {
            $parts = explode( '_', $key, 2 );
            if ( count( $parts ) !== 2 ) { unset( $archive[ $key ] ); continue; }
            if ( strtotime( $parts[1] ) < $cutoff ) unset( $archive[ $key ] );
        }

        update_option( self::OPT_ARCHIVE, $archive, 'no' );
    }

    /**
     * Generate (or regenerate) the forecast for one asset.
     * Returns the forecast array.
     */
    public static function generate_forecast( $asset ) {
        $ctx = self::build_context( $asset );

        // Always render deterministic template first — guarantees content.
        $html_template = self::render_template( $asset, $ctx );
        $verdict       = self::compute_verdict( $ctx );
        $summary       = self::compose_summary( $asset, $ctx, $verdict );
        $source        = 'template';
        $html          = $html_template;

        // Optionally enhance with AI.
        if ( self::ai_available() ) {
            $ai_html = self::ai_enhance( $asset, $ctx, $verdict );
            if ( $ai_html ) {
                $html   = $ai_html;
                $source = 'ai';
            }
        }

        $forecast = array(
            'date'         => gmdate( 'Y-m-d' ),
            'verdict'      => $verdict,
            'summary'      => $summary,
            'html'         => $html,
            'price'        => $ctx['price'],
            'chg_24h'      => $ctx['chg_24h'],
            'chg_7d'       => $ctx['chg_7d'],
            'generated_at' => time(),
            'source'       => $source,
        );

        update_option( self::OPT_CURRENT . $asset['slug'], $forecast, 'no' );

        if ( class_exists( 'BT_Database' ) ) {
            BT_Database::log_event( 'FORECAST_GEN', $asset['slug'], array(
                'verdict' => $verdict,
                'source'  => $source,
                'price'   => $ctx['price'],
            ) );
        }

        return $forecast;
    }

    /**
     * Pull live data for the asset. Best effort — anything missing falls back
     * to neutral defaults so generation always succeeds.
     */
    private static function build_context( $asset ) {
        $ctx = array(
            'name'      => $asset['name'],
            'symbol'    => $asset['symbol'],
            'type'      => $asset['type'],
            'price'     => null,
            'chg_24h'   => 0,
            'chg_7d'    => 0,
            'mcap'      => 0,
            'vol'       => 0,
            'fng'       => null,  // crypto only
            'mood'      => null,  // sentiment package
            'headlines' => array(),
            'signals'   => array(),
        );

        // Use existing assemble_asset_context for the heavy lifting.
        if ( class_exists( 'BT_AIBlog' ) && method_exists( 'BT_AIBlog', 'assemble_asset_context' ) ) {
            $sub = BT_AIBlog::assemble_asset_context( $asset['symbol'] );
            if ( is_array( $sub ) ) {
                $ctx['price']     = $sub['price_usd'] ?? $sub['price']     ?? null;
                $ctx['chg_24h']   = $sub['chg_24h']   ?? 0;
                $ctx['chg_7d']    = $sub['chg_7d']    ?? 0;
                $ctx['mcap']      = $sub['mcap']      ?? 0;
                $ctx['vol']       = $sub['vol']       ?? 0;
                $ctx['fng']       = $sub['fng']       ?? null;
                $ctx['mood']      = $sub['mood']      ?? null;
                $ctx['headlines'] = $sub['headlines'] ?? array();
                $ctx['signals']   = $sub['signals']   ?? array();
            }
        }

        return $ctx;
    }

    /**
     * Compute a coarse 24h verdict from price movement + sentiment.
     * Returns BULLISH | BEARISH | NEUTRAL.
     */
    private static function compute_verdict( $ctx ) {
        $score  = 0;
        $chg_24 = (float) ( $ctx['chg_24h'] ?? 0 );
        $chg_7  = (float) ( $ctx['chg_7d']  ?? 0 );

        if ( $chg_24 >= 1.5 )  $score += 2;
        elseif ( $chg_24 >= 0.3 ) $score += 1;
        elseif ( $chg_24 <= -1.5 ) $score -= 2;
        elseif ( $chg_24 <= -0.3 ) $score -= 1;

        if ( $chg_7 >= 5 )  $score += 1;
        elseif ( $chg_7 <= -5 ) $score -= 1;

        // Sentiment, if available.
        if ( ! empty( $ctx['mood'] ) && is_array( $ctx['mood'] ) ) {
            $label = $ctx['mood']['label'] ?? '';
            if ( in_array( $label, array( 'Bullish', 'Very Bullish' ), true ) ) $score += 1;
            elseif ( in_array( $label, array( 'Bearish', 'Very Bearish' ), true ) ) $score -= 1;
        }

        if ( $score >= 2 )  return 'BULLISH';
        if ( $score <= -2 ) return 'BEARISH';
        return 'NEUTRAL';
    }

    private static function compose_summary( $asset, $ctx, $verdict ) {
        $price_disp = $ctx['price']
            ? ( $asset['type'] === 'forex'
                ? number_format( (float) $ctx['price'], 4 )
                : '$' . number_format( (float) $ctx['price'], $ctx['price'] < 1 ? 6 : 2 ) )
            : 'price loading';
        $chg_disp = sprintf( '%s%.2f%%', $ctx['chg_24h'] >= 0 ? '+' : '', $ctx['chg_24h'] );
        return sprintf( '%s trading at %s (%s 24h). 24h verdict: %s.',
            $asset['name'], $price_disp, $chg_disp, $verdict );
    }

    /**
     * Deterministic template renderer. Always succeeds. Output is well-formed
     * HTML using semantic tags; the page wraps it with article styling.
     */
    private static function render_template( $asset, $ctx ) {
        $price = $ctx['price'];
        $chg24 = (float) $ctx['chg_24h'];
        $chg7  = (float) $ctx['chg_7d'];
        $name  = $asset['name'];
        $is_fx = $asset['type'] === 'forex';

        $price_disp = $price
            ? ( $is_fx ? number_format( (float) $price, 4 ) : '$' . number_format( (float) $price, $price < 1 ? 6 : 2 ) )
            : '—';

        // Build sections.
        $h = '';

        // Section 1: Snapshot.
        $h .= '<h2>Market Snapshot</h2>';
        $h .= '<p>' . esc_html( $name ) . ' (' . esc_html( $asset['symbol'] ) . ') is currently trading at <strong>' . esc_html( $price_disp ) . '</strong>';
        if ( $price ) {
            $h .= ', ' . ( $chg24 >= 0 ? '<span style="color:var(--bt-accent)">up ' : '<span style="color:var(--bt-danger,#ff3b30)">down ' )
                . esc_html( number_format( abs( $chg24 ), 2 ) ) . '%</span> over the last 24 hours';
            if ( abs( $chg7 ) > 0.1 ) {
                $h .= ' and ' . ( $chg7 >= 0 ? 'up ' : 'down ' ) . esc_html( number_format( abs( $chg7 ), 2 ) ) . '% over the past week';
            }
        }
        $h .= '.</p>';

        if ( ! empty( $ctx['mcap'] ) && ! $is_fx ) {
            $h .= '<p>Market cap stands at $' . esc_html( self::abbrev( $ctx['mcap'] ) )
                . ', with 24h trading volume of $' . esc_html( self::abbrev( $ctx['vol'] ) ) . '.</p>';
        }

        // Section 2: 24h outlook.
        $h .= '<h2>24-Hour Outlook</h2>';
        if ( $chg24 >= 1.5 ) {
            $h .= '<p>Momentum is firmly to the upside. ' . esc_html( $name ) . ' is showing strong intraday gains, suggesting buyer interest at current levels. Watch for continuation above the prior session high; a failure to hold that level would invite mean-reversion sellers.</p>';
        } elseif ( $chg24 <= -1.5 ) {
            $h .= '<p>Pressure is to the downside. ' . esc_html( $name ) . ' is meaningfully lower on the session, indicating sellers are in control. The first test will be whether dip buyers defend the prior session\'s low; a clean break below it opens the door to further weakness.</p>';
        } else {
            $h .= '<p>' . esc_html( $name ) . ' is consolidating with limited net movement on the session. Range traders may find opportunities at the session high and low; trend traders should wait for a decisive break in either direction before committing.</p>';
        }

        // Section 3: News context.
        if ( ! empty( $ctx['headlines'] ) ) {
            $h .= '<h2>News Context</h2>';
            $h .= '<p>Recent coverage mentioning ' . esc_html( $name ) . ' or ' . esc_html( $asset['symbol'] ) . ':</p><ul>';
            foreach ( array_slice( $ctx['headlines'], 0, 5 ) as $hl ) {
                $title = $hl['title'] ?? '';
                $src   = $hl['source'] ?? '';
                if ( ! $title ) continue;
                $h .= '<li>' . esc_html( $title );
                if ( $src ) $h .= ' <em style="color:var(--bt-text-3);font-size:.9em">— ' . esc_html( $src ) . '</em>';
                $h .= '</li>';
            }
            $h .= '</ul>';
        }

        // Section 4: 7-day context.
        $h .= '<h2>7-Day Context</h2>';
        if ( abs( $chg7 ) >= 8 ) {
            $h .= '<p>The weekly trend is ' . ( $chg7 >= 0 ? 'strongly bullish' : 'strongly bearish' ) . ', with ' . esc_html( $name ) . ' having moved more than 8% since this time last week. Trend-following strategies favour the prevailing direction; counter-trend setups carry higher risk in this regime.</p>';
        } elseif ( abs( $chg7 ) >= 3 ) {
            $h .= '<p>The 7-day picture shows a ' . ( $chg7 >= 0 ? 'modest uptrend' : 'modest downtrend' ) . '. Direction is established but not extreme — pullbacks in the trend direction may offer better risk/reward than chasing.</p>';
        } else {
            $h .= '<p>The weekly picture is essentially flat. ' . esc_html( $name ) . ' has not committed to a direction; rangebound strategies are favoured until volatility expands.</p>';
        }

        // Section 5: Methodology footer.
        $h .= '<h2>Methodology</h2>';
        $h .= '<p style="font-size:13px;color:var(--bt-text-3)">'
            . 'Data sources: ' . ( $is_fx ? 'Frankfurter / ECB / ExchangeRate-API' : 'CoinGecko' )
            . ' for price data; our 24-source RSS pipeline for news context; in-house sentiment scoring on a 48-hour window. '
            . 'Verdict combines 24h price action, 7d trend, and news sentiment into a 5-point score (BULLISH ≥ 2, BEARISH ≤ -2, otherwise NEUTRAL). '
            . 'This is a quantitative summary, not professional trading advice.</p>';

        return $h;
    }

    /* ============================================================
     *  AI ENHANCEMENT (optional)
     * ============================================================ */

    private static function ai_available() {
        if ( ! class_exists( 'BT_AIBlog' ) || ! method_exists( 'BT_AIBlog', 'call_ai' ) ) return false;
        $key = get_option( 'bt_anthropic_api_key', '' );
        return ! empty( $key );
    }

    private static function ai_enhance( $asset, $ctx, $verdict ) {
        $prompt = self::build_ai_prompt( $asset, $ctx, $verdict );
        try {
            // We can't directly call call_ai because it's private. Instead,
            // build a minimal pseudo-context and use generate_asset_analysis as
            // the public path. If that's unavailable, skip AI.
            // Cleaner long-term: expose a public BT_AIBlog::call_ai_public()
            // method. For now we stay defensive and return null on any failure.
            if ( method_exists( 'BT_AIBlog', 'generate_asset_analysis' ) ) {
                // Note: this generates an analysis, not a forecast — but reusing
                // the same pipeline keeps token usage and rate limits unified.
                $result = BT_AIBlog::generate_asset_analysis( $asset['symbol'], false );
                if ( ! empty( $result['html'] ) && empty( $result['error'] ) ) {
                    return $result['html'];
                }
            }
        } catch ( \Throwable $e ) {
            // Log but don't break — template fallback is already in place.
            if ( class_exists( 'BT_Database' ) ) {
                BT_Database::log_event( 'FORECAST_AI_FAIL', $asset['slug'], array(
                    'error' => substr( $e->getMessage(), 0, 200 ),
                ) );
            }
        }
        return null;
    }

    private static function build_ai_prompt( $asset, $ctx, $verdict ) {
        // Reserved for future direct prompt-based AI calls.
        // Returning a string keeps the architecture explicit even though
        // ai_enhance() currently goes through generate_asset_analysis.
        return sprintf(
            'Forecast for %s (%s). Current price: %s. 24h change: %.2f%%. 7d change: %.2f%%. Verdict: %s.',
            $asset['name'], $asset['symbol'],
            $ctx['price'] ?? 'unknown',
            (float) $ctx['chg_24h'], (float) $ctx['chg_7d'],
            $verdict
        );
    }

    /* ============================================================
     *  STORAGE HELPERS
     * ============================================================ */

    public static function get_current_forecast( $slug ) {
        $f = get_option( self::OPT_CURRENT . $slug, null );
        return is_array( $f ) ? $f : null;
    }

    public static function get_archived_forecast( $slug, $date ) {
        $archive = get_option( self::OPT_ARCHIVE, array() );
        if ( ! is_array( $archive ) ) return null;
        $key = $slug . '_' . $date;
        // Also handle the "today" case where archive doesn't have it yet.
        if ( ! isset( $archive[ $key ] ) ) {
            $cur = self::get_current_forecast( $slug );
            if ( $cur && ( $cur['date'] ?? '' ) === $date ) return $cur;
            return null;
        }
        return $archive[ $key ];
    }

    public static function get_archive_dates_for( $slug, $limit = 7 ) {
        $dates   = array();
        $archive = get_option( self::OPT_ARCHIVE, array() );

        if ( is_array( $archive ) ) {
            foreach ( array_keys( $archive ) as $key ) {
                $parts = explode( '_', $key, 2 );
                if ( count( $parts ) === 2 && $parts[0] === $slug ) {
                    $dates[] = $parts[1];
                }
            }
        }
        // Today (current snapshot if it exists).
        $cur = self::get_current_forecast( $slug );
        if ( $cur && ! empty( $cur['date'] ) && ! in_array( $cur['date'], $dates, true ) ) {
            $dates[] = $cur['date'];
        }

        rsort( $dates );
        return array_slice( $dates, 0, $limit );
    }

    public static function find_asset_by_slug( $slug ) {
        foreach ( self::tracked_assets() as $a ) {
            if ( $a['slug'] === $slug ) return $a;
        }
        return null;
    }

    /* ============================================================
     *  Helpers
     * ============================================================ */

    private static function abbrev( $n ) {
        $n = (float) $n;
        if ( $n >= 1e12 ) return number_format( $n / 1e12, 2 ) . 'T';
        if ( $n >= 1e9 )  return number_format( $n / 1e9, 2 )  . 'B';
        if ( $n >= 1e6 )  return number_format( $n / 1e6, 2 )  . 'M';
        if ( $n >= 1e3 )  return number_format( $n / 1e3, 2 )  . 'K';
        return number_format( $n, 2 );
    }

    /* ============================================================
     *  ADMIN
     * ============================================================ */

    public static function register_admin_menu() {
        add_submenu_page(
            'fxlm-wizard',
            __( 'Forecasts', 'blockticker' ),
            __( 'Forecasts', 'blockticker' ),
            'manage_options',
            'bt-forecasts-overview',
            array( __CLASS__, 'render_admin_page' )
        );
    }

    public static function admin_generate_now() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );
        check_admin_referer( 'bt_forecast_generate_now' );
        $slug = sanitize_text_field( $_POST['slug'] ?? '' );
        if ( $slug === 'all' ) {
            self::cron_kick();
            $msg = 'all';
        } else {
            $asset = self::find_asset_by_slug( $slug );
            if ( $asset ) {
                self::generate_forecast( $asset );
                $msg = 'one';
            } else {
                $msg = 'badslug';
            }
        }
        wp_safe_redirect( add_query_arg( array(
            'page'      => 'bt-forecasts-overview',
            'generated' => $msg,
        ), admin_url( 'admin.php' ) ) );
        exit;
    }

    public static function render_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );

        $assets    = self::tracked_assets();
        $next_run  = wp_next_scheduled( 'bt_forecast_daily_kick' );
        $generated = $_GET['generated'] ?? '';
        $ai_on     = self::ai_available();
        ?>
        <div class="wrap bt-admin-wrap">
            <h1 style="font-family:'Chivo',sans-serif;font-weight:900">📈 Forecasts</h1>
            <p>Daily SEO forecast pages at <code>/forecast/{slug}/</code>. Cron generates one asset every 30 seconds starting at 06:00 UTC, with template fallback so pages always have content. AI enhancement is <strong style="color:<?php echo $ai_on ? '#00cc55' : '#cc8800'; ?>"><?php echo $ai_on ? 'ENABLED' : 'OFF (no Anthropic API key configured)'; ?></strong>.</p>

            <?php if ( $generated === 'one' ) : ?>
            <div class="notice notice-success is-dismissible"><p>Forecast regenerated.</p></div>
            <?php elseif ( $generated === 'all' ) : ?>
            <div class="notice notice-success is-dismissible"><p>Daily generation kicked off — assets will process one every 30 seconds.</p></div>
            <?php endif; ?>

            <div style="padding:14px 18px;background:#fff;border:1px solid #c3c4c7;border-left:4px solid #2271b1;margin:20px 0">
                <strong>Next scheduled run:</strong>
                <?php echo $next_run
                    ? esc_html( gmdate( 'Y-m-d H:i:s T', $next_run ) ) . ' (' . esc_html( human_time_diff( time(), $next_run ) ) . ' from now)'
                    : '<span style="color:#cc0000">NOT SCHEDULED — check WP-Cron</span>'; ?>
            </div>

            <table class="widefat striped">
                <thead><tr>
                    <th>Asset</th><th>Slug</th><th>Last generated</th><th>Verdict</th>
                    <th>Source</th><th>Public URL</th><th></th>
                </tr></thead>
                <tbody>
                <?php foreach ( $assets as $a ) :
                    $f = self::get_current_forecast( $a['slug'] );
                ?>
                <tr>
                    <td><strong><?php echo esc_html( $a['name'] ); ?></strong> <span style="color:#666;font-size:11px">(<?php echo esc_html( $a['symbol'] ); ?>)</span></td>
                    <td><code><?php echo esc_html( $a['slug'] ); ?></code></td>
                    <td><?php echo $f && ! empty( $f['generated_at'] ) ? esc_html( human_time_diff( $f['generated_at'], time() ) ) . ' ago' : '<em style="color:#cc8800">never</em>'; ?></td>
                    <td><?php echo $f ? '<strong>' . esc_html( $f['verdict'] ?? '—' ) . '</strong>' : '—'; ?></td>
                    <td><?php echo $f ? esc_html( $f['source'] ?? '—' ) : '—'; ?></td>
                    <td><a href="<?php echo esc_url( home_url( '/forecast/' . $a['slug'] . '/' ) ); ?>" target="_blank">/forecast/<?php echo esc_html( $a['slug'] ); ?>/</a></td>
                    <td>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0">
                            <?php wp_nonce_field( 'bt_forecast_generate_now' ); ?>
                            <input type="hidden" name="action" value="bt_forecast_generate_now">
                            <input type="hidden" name="slug" value="<?php echo esc_attr( $a['slug'] ); ?>">
                            <button type="submit" class="button button-small">Generate now</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <p style="margin-top:20px">
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
                    <?php wp_nonce_field( 'bt_forecast_generate_now' ); ?>
                    <input type="hidden" name="action" value="bt_forecast_generate_now">
                    <input type="hidden" name="slug" value="all">
                    <button type="submit" class="button button-primary">Run full daily cycle now</button>
                </form>
            </p>
        </div>
        <?php
    }
}

BT_Forecast::init();
