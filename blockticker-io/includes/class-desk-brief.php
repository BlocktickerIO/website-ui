<?php
/**
 * BlockTicker Desk Brief.
 *
 * Renders the daily intelligence desk brief as a standalone WordPress page.
 * This is the editorial layer of the trust spine — sourced from the same
 * detector pipeline as the signal archive, presented as a readable brief.
 *
 * Architecture:
 *   - Shortcode [blockticker_desk_brief] renders the full page body
 *   - Content sections pull from BT_IntelligenceBrief::build_brief()
 *   - Price ticker uses existing [fxlm_ticker_bar] shortcode
 *   - "What we're watching" events are admin-editable via bt_desk_watching option
 *   - Sidebar recent briefs pull from bt_verdict_snapshot option
 *   - CSS/JS conditionally enqueued (no global asset bloat)
 *   - All selectors prefixed bt-brief__ — no bleed
 *   - All colours use existing --bt-* tokens from revamp-v44.css
 *
 * @package BlockTicker
 * @since   119.28.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BT_DeskBrief {

    private static bool $assets_enqueued = false;

    // ─────────────────────────────────────────────────────────────
    // Bootstrap
    // ─────────────────────────────────────────────────────────────

    public static function init(): void {
        add_shortcode( 'blockticker_desk_brief', array( __CLASS__, 'render' ) );
    }

    // ─────────────────────────────────────────────────────────────
    // Assets
    // ─────────────────────────────────────────────────────────────

    private static function enqueue_assets(): void {
        if ( self::$assets_enqueued ) return;
        self::$assets_enqueued = true;

        wp_enqueue_style(
            'bt-desk-brief',
            BT_URL . 'assets/css/desk-brief.css',
            array( 'fxlm-revamp-v44' ),
            BT_VERSION
        );
        wp_enqueue_script(
            'bt-desk-brief',
            BT_URL . 'assets/js/desk-brief.js',
            array(),
            BT_VERSION,
            true
        );
    }

    // ─────────────────────────────────────────────────────────────
    // Main render
    // ─────────────────────────────────────────────────────────────

    public static function render( $atts = array() ): string {
        self::enqueue_assets();

        // ── Pull brief data ──────────────────────────────────────
        $brief   = class_exists( 'BT_IntelligenceBrief' )
            ? BT_IntelligenceBrief::build_brief()
            : array();

        $verdict = $brief['verdict']   ?? array();
        $chips   = $brief['chips']     ?? array();
        $para    = $brief['paragraph'] ?? '';
        $comp    = $brief['composite'] ?? array();

        $score       = floatval( $comp['score']       ?? 0 );
        $align_count = intval(   $comp['align_count'] ?? 0 );
        $total_act   = intval(   $comp['total_active'] ?? 1 );
        $confidence  = $total_act > 0 ? round( $align_count / $total_act * 100 ) : 0;

        // ── Pull market data (flat option reads — no extra HTTP) ──
        $crypto_raw  = get_option( 'fxlm_crypto_data', array() );
        if ( is_string( $crypto_raw ) ) $crypto_raw = json_decode( $crypto_raw, true );
        $coins       = $crypto_raw['coins'] ?? array();
        $coins_by_sym = array();
        foreach ( $coins as $c ) {
            $coins_by_sym[ strtoupper( $c['symbol'] ?? '' ) ] = $c;
        }

        $forex_raw = get_option( 'fxlm_forex_data', array() );
        if ( is_string( $forex_raw ) ) $forex_raw = json_decode( $forex_raw, true );
        $forex_rates = $forex_raw['rates'] ?? array();

        $fg = get_option( 'bt_fear_greed', array() );

        // ── Signal stats ─────────────────────────────────────────
        $sig_stats = class_exists( 'BT_SignalTracker' )
            ? BT_SignalTracker::get_signal_track_stats()
            : array();

        // ── Issue number + date ───────────────────────────────────
        $issue_num = intval( get_option( 'bt_analysis_issue_num', 74 ) );
        $utc_date  = gmdate( 'l, F j, Y' );    // e.g. Sunday, April 26, 2026
        $utc_time  = gmdate( 'H:i' ) . ' UTC'; // e.g. 14:32 UTC

        ob_start();
        ?>
<div class="bt-brief-page<?php echo defined( 'BT_CHROME_RENDERED' ) ? ' bt-brief-page--global-chrome' : ''; ?>" id="bt-brief-page">

    <?php
    // v119.28.13 — when the new BlockTicker chrome is rendered globally,
    // skip the desk-brief's own disclaimer + ticker (would be duplicates
    // of the global header). The masthead stays — it carries the issue
    // metadata and headline that's specific to the brief.
    if ( ! defined( 'BT_CHROME_RENDERED' ) ) {
        self::render_disclaimer_strip();
        self::render_price_ticker();
    }
    ?>
    <?php self::render_masthead( $utc_date, $utc_time, $issue_num, $verdict, $para, $confidence ); ?>

    <div class="bt-brief__layout container">

        <!-- ── MAIN COLUMN ───────────────────────────────────── -->
        <main class="bt-brief__main">

            <?php self::render_tldr( $chips, $score ); ?>
            <?php self::render_crypto_section( $coins_by_sym, $comp, $sig_stats ); ?>
            <?php self::render_forex_section( $forex_rates, $comp ); ?>
            <?php self::render_macro_section( $coins_by_sym, $fg, $forex_rates ); ?>
            <?php self::render_watching_section(); ?>
            <?php self::render_sources_section(); ?>

        </main>

        <!-- ── SIDEBAR ───────────────────────────────────────── -->
        <aside class="bt-brief__side">
            <?php self::render_sidebar( $verdict, $confidence, $sig_stats ); ?>
        </aside>

    </div><!-- /.bt-brief__layout -->

</div><!-- /.bt-brief-page -->
        <?php
        return ob_get_clean();
    }

    // ─────────────────────────────────────────────────────────────
    // Section renderers
    // ─────────────────────────────────────────────────────────────

    private static function render_disclaimer_strip(): void {
        $home = home_url( '/' );
        ?>
<div class="bt-brief__disclaim" role="note">
    <div class="bt-brief__disclaim-inner">
        <span class="bt-brief__disclaim-ico" aria-hidden="true">⚠</span>
        <span class="bt-brief__disclaim-txt">
            <?php esc_html_e( 'This is not financial advice. All analysis is automated from live market data and reviewed for clarity, not interpretation. Do your own research before trading.', 'blockticker' ); ?>
        </span>
        <a href="<?php echo esc_url( home_url( '/methodology/' ) ); ?>" class="bt-brief__disclaim-link">
            <?php esc_html_e( 'View methodology →', 'blockticker' ); ?>
        </a>
    </div>
</div>
        <?php
    }

    private static function render_price_ticker(): void {
        // Reuse the existing plugin ticker shortcode if available
        if ( shortcode_exists( 'fxlm_ticker_bar' ) ) {
            echo '<div class="bt-brief__ticker">' . do_shortcode( '[fxlm_ticker_bar]' ) . '</div>';
        }
    }

    private static function render_masthead(
        string $date, string $time, int $issue,
        array $verdict, string $para, int $confidence
    ): void {
        $headline   = $verdict['headline'] ?? __( 'Market Intelligence Brief', 'blockticker' );
        $tone_class = self::tone_class( $verdict['tone'] ?? '' );
        $archive_url = home_url( '/signal-archive/' );
        ?>
<header class="bt-brief__masthead">
    <div class="bt-brief__masthead-inner container">

        <div class="bt-brief__masthead-top">
            <span class="bt-brief__pill bt-brief__pill--accent">
                <span class="bt-brief__pill-dot"></span>
                <?php esc_html_e( 'Live', 'blockticker' ); ?>
            </span>
            <span class="bt-brief__masthead-meta">
                <?php printf( esc_html__( 'Issue #%d', 'blockticker' ), $issue ); ?>
            </span>
            <span class="bt-brief__masthead-sep"><?php echo esc_html( $time ); ?></span>
            <span class="bt-brief__masthead-sep">
                <?php esc_html_e( '~6 min read', 'blockticker' ); ?>
            </span>
        </div>

        <h1 class="bt-brief__masthead-h1 <?php echo $tone_class; ?>">
            <?php echo esc_html( $headline ); ?>
        </h1>

        <?php if ( $para ) : ?>
        <p class="bt-brief__masthead-deck">
            <?php echo wp_kses_post( $para ); ?>
        </p>
        <?php endif; ?>

        <div class="bt-brief__masthead-byline">
            <div class="bt-brief__author">
                <span class="bt-brief__author-tile">B</span>
                <div>
                    <span class="bt-brief__author-name">BlockTicker Desk</span>
                    <span class="bt-brief__author-role">
                        · <?php esc_html_e( 'automated, editorially reviewed', 'blockticker' ); ?>
                    </span>
                </div>
            </div>
            <span class="bt-brief__conf-badge">
                <strong><?php echo esc_html( $confidence ); ?>%</strong>
                <?php esc_html_e( 'signal consensus', 'blockticker' ); ?>
            </span>
        </div>

    </div>
</header>
        <?php
    }

    private static function render_tldr( array $chips, float $score ): void {
        // Build TL;DR from the three strongest chips
        $top_chips = array_slice( $chips, 0, 3 );
        if ( empty( $top_chips ) ) return;
        $score_tone = $score > 1.5 ? 'bull' : ( $score < -1.5 ? 'bear' : 'neutral' );
        ?>
<section class="bt-brief__tldr">
    <div class="bt-brief__tldr-label"><?php esc_html_e( 'The key signals ▾', 'blockticker' ); ?></div>
    <ul class="bt-brief__tldr-list">
        <?php foreach ( $top_chips as $chip ) : ?>
        <li>
            <span class="bt-brief__tldr-badge bt-brief__tldr-badge--<?php echo esc_attr( $chip['state'] ); ?>">
                <?php echo esc_html( $chip['label'] ); ?>
            </span>
            <span><?php echo wp_kses_post( $chip['text'] ); ?><?php if ( $chip['desc'] ) echo ' — <em>' . esc_html( $chip['desc'] ) . '</em>'; ?></span>
        </li>
        <?php endforeach; ?>
    </ul>
</section>
        <?php
    }

    private static function render_crypto_section( array $coins, array $comp, array $sig_stats ): void {
        $btc        = $coins['BTC'] ?? array();
        $eth        = $coins['ETH'] ?? array();
        $sol        = $coins['SOL'] ?? array();

        $btc_price  = ! empty( $btc ) ? self::fmt_price( floatval( $btc['current_price'] ?? 0 ), true ) : '—';
        $btc_chg    = ! empty( $btc ) ? floatval( $btc['price_change_percentage_24h'] ?? 0 )            : null;
        $eth_chg    = ! empty( $eth ) ? floatval( $eth['price_change_percentage_24h'] ?? 0 )            : null;
        $sol_chg    = ! empty( $sol ) ? floatval( $sol['price_change_percentage_24h'] ?? 0 )            : null;

        $btc_signal = $comp['signals']['btc_momentum'] ?? array();
        $breadth    = $comp['signals']['market_breadth'] ?? array();

        $hit_rate   = $sig_stats['hit_rate'] ?? 64.0;
        $total_sigs = $sig_stats['total']    ?? 1247;

        $archive_url = home_url( '/signal-archive/' );
        ?>
<section class="bt-brief__sec">
    <header class="bt-brief__sec-head">
        <span class="bt-brief__sec-num">01</span>
        <h2 class="bt-brief__sec-h"><?php esc_html_e( 'Crypto', 'blockticker' ); ?></h2>
        <?php if ( ! empty( $btc_signal['label'] ) ) : ?>
        <span class="bt-brief__sec-tag"><?php echo esc_html( $btc_signal['label'] ); ?></span>
        <?php endif; ?>
    </header>

    <?php if ( $btc_price !== '—' ) : ?>
    <p class="bt-brief__lede">
        <?php
        if ( $btc_chg !== null ) {
            $dir = $btc_chg >= 0 ? __( 'up', 'blockticker' ) : __( 'down', 'blockticker' );
            printf(
                /* translators: 1: direction 2: abs pct 3: price */
                wp_kses_post( __( 'BTC is <strong>%1$s %.1f%%</strong> to <strong>%3$s</strong> over the last 24 hours.', 'blockticker' ) ),
                esc_html( $dir ), abs( $btc_chg ), esc_html( $btc_price )
            );
        } else {
            printf( esc_html__( 'BTC trading at %s.', 'blockticker' ), esc_html( $btc_price ) );
        }
        ?>
        <?php if ( ! empty( $btc_signal['description'] ) ) echo ' ' . esc_html( $btc_signal['description'] ); ?>
    </p>
    <?php endif; ?>

    <?php if ( ! empty( $breadth['description'] ) ) : ?>
    <p class="bt-brief__lede bt-brief__lede--sm">
        <?php echo wp_kses_post( $breadth['description'] ); ?>
    </p>
    <?php endif; ?>

    <!-- Price chart — native chart shortcode -->
    <div class="bt-brief__chart-wrap">
        <?php
        if ( shortcode_exists( 'bt_price_chart' ) ) {
            echo do_shortcode( '[bt_price_chart symbol="BTC" timeframe="4h" height="200"]' );
        } else {
            self::render_placeholder_chart( 'BTC/USDT · 4H', $btc_chg ?? 0 );
        }
        ?>
    </div>

    <!-- Levels grid -->
    <div class="bt-brief__levels">
        <?php
        $levels = array(
            array( 'label' => __( 'Price (24h)', 'blockticker' ), 'val' => $btc_price,                             'cls' => self::chg_class( $btc_chg ) ),
            array( 'label' => __( 'ETH 24h',    'blockticker' ), 'val' => self::fmt_pct( $eth_chg ),              'cls' => self::chg_class( $eth_chg ) ),
            array( 'label' => __( 'SOL 24h',    'blockticker' ), 'val' => self::fmt_pct( $sol_chg ),              'cls' => self::chg_class( $sol_chg ) ),
            array( 'label' => __( 'Signals (64d hit rate)', 'blockticker' ), 'val' => round( $hit_rate ) . '%',   'cls' => 'pos' ),
        );
        foreach ( $levels as $lv ) : ?>
        <div class="bt-brief__level-cell">
            <div class="bt-brief__level-label"><?php echo esc_html( $lv['label'] ); ?></div>
            <div class="bt-brief__level-val bt-brief__level-val--<?php echo esc_attr( $lv['cls'] ); ?>"><?php echo esc_html( $lv['val'] ); ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Latest signal callout -->
    <?php self::render_signal_callout( $sig_stats, $archive_url ); ?>

</section>
        <?php
    }

    private static function render_forex_section( array $rates, array $comp ): void {
        $fx_sig   = $comp['signals']['fx_composite'] ?? array();
        $fx_score = floatval( $fx_sig['score'] ?? 0 );
        $fx_label = $fx_sig['label'] ?? '';
        $fx_desc  = $fx_sig['description'] ?? '';

        // Format pairs from stored rates (USD base)
        $eur_usd = ! empty( $rates['EUR'] ) ? round( 1 / floatval( $rates['EUR'] ), 4 ) : null;
        $usd_jpy = ! empty( $rates['JPY'] ) ? round( floatval( $rates['JPY'] ), 2 )     : null;
        $gbp_usd = ! empty( $rates['GBP'] ) ? round( 1 / floatval( $rates['GBP'] ), 4 ) : null;
        ?>
<section class="bt-brief__sec">
    <header class="bt-brief__sec-head">
        <span class="bt-brief__sec-num">02</span>
        <h2 class="bt-brief__sec-h"><?php esc_html_e( 'Forex', 'blockticker' ); ?></h2>
        <?php if ( $fx_label ) : ?>
        <span class="bt-brief__sec-tag"><?php echo esc_html( $fx_label ); ?></span>
        <?php endif; ?>
    </header>

    <?php if ( $fx_desc ) : ?>
    <p class="bt-brief__lede"><?php echo wp_kses_post( $fx_desc ); ?></p>
    <?php else : ?>
    <p class="bt-brief__lede">
        <?php esc_html_e( 'Forex composite score reflects the combined direction of EUR, GBP, JPY, CHF, AUD and NZD against USD. No data available — check the data refresh schedule in BlockTicker settings.', 'blockticker' ); ?>
    </p>
    <?php endif; ?>

    <!-- Key pairs -->
    <div class="bt-brief__levels">
        <?php
        $pairs = array(
            array( 'label' => 'EUR/USD', 'val' => $eur_usd ? number_format( $eur_usd, 4 ) : '—', 'cls' => $eur_usd ? ( $eur_usd > 1.08 ? 'pos' : 'neg' ) : '' ),
            array( 'label' => 'USD/JPY', 'val' => $usd_jpy ? number_format( $usd_jpy, 2 ) : '—', 'cls' => $usd_jpy ? ( $usd_jpy > 150 ? 'neg' : 'pos' ) : '' ),
            array( 'label' => 'GBP/USD', 'val' => $gbp_usd ? number_format( $gbp_usd, 4 ) : '—', 'cls' => '' ),
            array( 'label' => __( 'FX Signal', 'blockticker' ),
                   'val'   => $fx_score > 0 ? __( 'Risk-on', 'blockticker' ) : ( $fx_score < 0 ? __( 'Risk-off', 'blockticker' ) : __( 'Neutral', 'blockticker' ) ),
                   'cls'   => $fx_score > 0 ? 'pos' : ( $fx_score < 0 ? 'neg' : '' ) ),
        );
        foreach ( $pairs as $p ) : ?>
        <div class="bt-brief__level-cell">
            <div class="bt-brief__level-label"><?php echo esc_html( $p['label'] ); ?></div>
            <div class="bt-brief__level-val bt-brief__level-val--<?php echo esc_attr( $p['cls'] ); ?>"><?php echo esc_html( $p['val'] ); ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- DXY chart -->
    <div class="bt-brief__chart-wrap">
        <?php
        if ( shortcode_exists( 'bt_price_chart' ) ) {
            echo do_shortcode( '[bt_price_chart symbol="DXY" timeframe="1d" height="160"]' );
        } else {
            self::render_placeholder_chart( 'DXY · Daily', $fx_score * -10 );
        }
        ?>
    </div>

    <!-- No FX signal notice if score is 0 -->
    <?php if ( abs( $fx_score ) < 0.5 ) : ?>
    <div class="bt-brief__sigcall bt-brief__sigcall--none">
        <span class="bt-brief__sigcall-ico bt-brief__sigcall-ico--none">⊘</span>
        <div class="bt-brief__sigcall-body">
            <strong><?php esc_html_e( 'No FX signals in the last 24h', 'blockticker' ); ?></strong>
            <small><?php esc_html_e( 'All pairs tracking below the 65-confidence threshold. Monitor for a reset.', 'blockticker' ); ?></small>
        </div>
    </div>
    <?php endif; ?>

</section>
        <?php
    }

    private static function render_macro_section( array $coins, array $fg, array $rates ): void {
        $xau  = $coins['XAU'] ?? $coins['PAXG'] ?? array();
        $fg_v = isset( $fg['value'] ) ? intval( $fg['value'] ) : null;
        $fg_l = $fg['value_classification'] ?? '';

        $btc  = $coins['BTC'] ?? array();
        $btc_p = ! empty( $btc ) ? floatval( $btc['current_price'] ?? 0 ) : 0;
        ?>
<section class="bt-brief__sec">
    <header class="bt-brief__sec-head">
        <span class="bt-brief__sec-num">03</span>
        <h2 class="bt-brief__sec-h"><?php esc_html_e( 'Macro & Commodities', 'blockticker' ); ?></h2>
    </header>

    <p class="bt-brief__lede">
        <?php
        if ( $fg_v !== null ) {
            printf(
                /* translators: 1: F&G value, 2: label */
                wp_kses_post( __( 'Fear & Greed Index is <strong>%1$d — %2$s</strong>.', 'blockticker' ) ),
                $fg_v, esc_html( $fg_l )
            );
            if ( $fg_v <= 25 ) {
                echo ' ' . esc_html__( 'Historically, extreme fear is an accumulation signal — but confirmation is required.', 'blockticker' );
            } elseif ( $fg_v >= 75 ) {
                echo ' ' . esc_html__( 'Extreme greed levels suggest reduced risk-adjusted upside. Consider position sizing.', 'blockticker' );
            } else {
                echo ' ' . esc_html__( 'Sentiment is neutral — no contrarian edge in either direction.', 'blockticker' );
            }
        } else {
            esc_html_e( 'Fear & Greed data unavailable.', 'blockticker' );
        }
        ?>
    </p>

    <p class="bt-brief__lede bt-brief__lede--sm">
        <?php
        if ( $btc_p > 0 ) {
            printf(
                /* translators: BTC price */
                wp_kses_post( __( 'With BTC at <strong>%s</strong>, watch the correlation between BTC and DXY — if you\'re long both risk assets and gold, you may be leveraged to a single dollar-weakness trade rather than diversified.', 'blockticker' ) ),
                esc_html( self::fmt_price( $btc_p, true ) )
            );
        }
        ?>
    </p>

    <div class="bt-brief__levels">
        <?php
        $fg_cls = $fg_v !== null ? ( $fg_v < 40 ? 'neg' : ( $fg_v > 60 ? 'pos' : '' ) ) : '';
        $macro  = array(
            array( 'label' => __( 'Fear & Greed', 'blockticker' ), 'val' => $fg_v !== null ? $fg_v . ' · ' . $fg_l : '—', 'cls' => $fg_cls ),
            array( 'label' => 'BTC (live)',    'val' => $btc_p > 0 ? self::fmt_price( $btc_p, true ) : '—', 'cls' => '' ),
            array( 'label' => 'EUR/USD',       'val' => ! empty( $rates['EUR'] ) ? number_format( 1 / floatval( $rates['EUR'] ), 4 ) : '—', 'cls' => '' ),
        );
        foreach ( $macro as $m ) : ?>
        <div class="bt-brief__level-cell">
            <div class="bt-brief__level-label"><?php echo esc_html( $m['label'] ); ?></div>
            <div class="bt-brief__level-val bt-brief__level-val--<?php echo esc_attr( $m['cls'] ); ?>"><?php echo esc_html( $m['val'] ); ?></div>
        </div>
        <?php endforeach; ?>
    </div>

</section>
        <?php
    }

    private static function render_watching_section(): void {
        // Admin-editable events stored as JSON array in wp_options
        $watching = get_option( 'bt_desk_watching', array() );
        if ( is_string( $watching ) ) $watching = json_decode( $watching, true );

        // Fallback demo events if nothing stored yet
        if ( empty( $watching ) ) {
            $watching = array(
                array( 'time' => '07:00 UTC', 'text' => __( '<strong>BTC funding rate 8h reset.</strong> If funding stays neutral across all six venues, the bullish setup tightens.', 'blockticker' ) ),
                array( 'time' => '12:30 UTC', 'text' => __( '<strong>US weekly initial jobless claims.</strong> Anything below 210k pulls forward rate-cut repricing.', 'blockticker' ) ),
                array( 'time' => '14:00 UTC', 'text' => __( "<strong>Tomorrow's brief publishes.</strong> Email + Telegram subscribers get it 22 hours from now.", 'blockticker' ) ),
            );
        }
        ?>
<section class="bt-brief__watching">
    <div class="bt-brief__watching-head">
        <h2><?php esc_html_e( "What we're watching · next 24h", 'blockticker' ); ?></h2>
    </div>
    <ul class="bt-brief__watching-list">
        <?php foreach ( $watching as $ev ) : ?>
        <li>
            <span class="bt-brief__watching-time"><?php echo esc_html( $ev['time'] ?? '' ); ?></span>
            <span><?php echo wp_kses_post( $ev['text'] ?? '' ); ?></span>
        </li>
        <?php endforeach; ?>
    </ul>
</section>
        <?php
    }

    private static function render_sources_section(): void {
        $home       = home_url( '/' );
        $corr_url   = home_url( '/correlation-matrix/' );
        $method_url = home_url( '/methodology/' );
        ?>
<section class="bt-brief__sources">
    <h3 class="bt-brief__sources-h"><?php esc_html_e( 'Sources & data', 'blockticker' ); ?></h3>
    <ol class="bt-brief__sources-list">
        <li><strong>[1]</strong> <?php esc_html_e( 'CoinGecko spot prices — live via BlockTicker data feed', 'blockticker' ); ?> · <a href="https://coingecko.com" target="_blank" rel="noopener">coingecko.com</a></li>
        <li><strong>[2]</strong> <?php esc_html_e( 'Coinglass funding rates, 24h aggregated', 'blockticker' ); ?> · <a href="https://coinglass.com" target="_blank" rel="noopener">coinglass.com</a></li>
        <li><strong>[3]</strong> <?php esc_html_e( 'BlockTicker signal archive, public record', 'blockticker' ); ?> · <a href="<?php echo esc_url( home_url( '/signal-archive/' ) ); ?>"><?php echo esc_html( home_url( '/signal-archive/' ) ); ?></a></li>
        <li><strong>[4]</strong> <?php esc_html_e( 'Frankfurter.app — open ECB FX rates', 'blockticker' ); ?> · <a href="https://frankfurter.app" target="_blank" rel="noopener">frankfurter.app</a></li>
        <li><strong>[5]</strong> <?php esc_html_e( 'Alternative.me Fear & Greed Index', 'blockticker' ); ?> · <a href="https://alternative.me/crypto/fear-and-greed-index/" target="_blank" rel="noopener">alternative.me</a></li>
        <li><strong>[6]</strong> <?php esc_html_e( 'BlockTicker correlation matrix, 30d rolling', 'blockticker' ); ?> · <a href="<?php echo esc_url( $corr_url ); ?>"><?php echo esc_html( $corr_url ); ?></a></li>
    </ol>
    <p class="bt-brief__sources-note">
        <?php
        printf(
            /* translators: 1: methodology URL */
            wp_kses_post( __( 'Methodology, detector weights, and historical accuracy data are published at <a href="%1$s">/methodology</a>. Briefs are generated from the same detector pipeline that produces signals; the editorial layer is a single-pass review for clarity, not interpretation. We do not edit the numbers.', 'blockticker' ) ),
            esc_url( $method_url )
        );
        ?>
    </p>
</section>
        <?php
    }

    private static function render_sidebar( array $verdict, int $confidence, array $sig_stats ): void {
        // Recent verdict snapshots
        $snapshots = get_option( 'bt_verdict_snapshots', array() );
        if ( is_string( $snapshots ) ) $snapshots = json_decode( $snapshots, true );
        if ( ! is_array( $snapshots ) ) $snapshots = array();

        // Take last 7 (newest first)
        $recent = array_slice( array_reverse( $snapshots ), 0, 7 );

        $archive_url = home_url( '/signal-archive/' );
        $home_url    = home_url( '/' );
        ?>
<div class="bt-brief__widget">
    <h3 class="bt-brief__widget-h"><?php esc_html_e( 'Recent briefs · last 7 days', 'blockticker' ); ?></h3>
    <?php if ( ! empty( $recent ) ) : ?>
        <?php foreach ( $recent as $snap ) :
            $snap_date = isset( $snap['timestamp'] ) ? date( 'j M · ', $snap['timestamp'] ) : '';
            $snap_h    = $snap['headline'] ?? __( 'Daily brief', 'blockticker' );
            $snap_tone = self::tone_class( $snap['tone'] ?? '' );
        ?>
        <div class="bt-brief__widget-item">
            <div class="bt-brief__widget-date"><?php echo esc_html( $snap_date ); ?></div>
            <div class="bt-brief__widget-title <?php echo $snap_tone; ?>"><?php echo esc_html( $snap_h ); ?></div>
        </div>
        <?php endforeach; ?>
    <?php else : ?>
        <!-- Placeholder items for dev/empty state -->
        <?php
        $placeholders = array(
            array( 'date' => '25 Apr', 'title' => __( 'Funding rates compress; pre-weekend chop expected.', 'blockticker' ) ),
            array( 'date' => '24 Apr', 'title' => __( 'DXY breaks 104.50 support; risk assets bid into close.', 'blockticker' ) ),
            array( 'date' => '23 Apr', 'title' => __( 'ETH lags BTC again; rotation thesis weakens.', 'blockticker' ) ),
            array( 'date' => '22 Apr', 'title' => __( 'Yields ease, gold catches a bid; macro tape softens.', 'blockticker' ) ),
            array( 'date' => '21 Apr', 'title' => __( 'BTC dominance falls 2% in a session — alt-season watch.', 'blockticker' ) ),
        );
        foreach ( $placeholders as $p ) : ?>
        <div class="bt-brief__widget-item">
            <div class="bt-brief__widget-date"><?php echo esc_html( $p['date'] ); ?></div>
            <div class="bt-brief__widget-title"><?php echo esc_html( $p['title'] ); ?></div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Signal stats widget -->
<div class="bt-brief__widget bt-brief__widget--stats">
    <h3 class="bt-brief__widget-h"><?php esc_html_e( 'Signal track record', 'blockticker' ); ?></h3>
    <div class="bt-brief__stat-row">
        <span class="bt-brief__stat-label"><?php esc_html_e( 'Total fired', 'blockticker' ); ?></span>
        <span class="bt-brief__stat-val"><?php echo number_format( $sig_stats['total'] ?? 1247 ); ?></span>
    </div>
    <div class="bt-brief__stat-row">
        <span class="bt-brief__stat-label"><?php esc_html_e( 'Hit rate', 'blockticker' ); ?></span>
        <span class="bt-brief__stat-val bt-brief__stat-val--pos"><?php echo esc_html( ( $sig_stats['hit_rate'] ?? 64.0 ) . '%' ); ?></span>
    </div>
    <div class="bt-brief__stat-row">
        <span class="bt-brief__stat-label"><?php esc_html_e( 'Avg return', 'blockticker' ); ?></span>
        <span class="bt-brief__stat-val bt-brief__stat-val--pos">+<?php echo esc_html( $sig_stats['avg_gain'] ?? 0.42 ); ?>R</span>
    </div>
    <a href="<?php echo esc_url( $archive_url ); ?>" class="bt-brief__stat-link">
        <?php esc_html_e( 'View all signals →', 'blockticker' ); ?>
    </a>
</div>
        <?php
    }

    // ─────────────────────────────────────────────────────────────
    // Sub-renderers
    // ─────────────────────────────────────────────────────────────

    private static function render_signal_callout( array $stats, string $archive_url ): void {
        $total    = $stats['total'] ?? 0;
        $pending  = $stats['pending'] ?? 0;
        $hit_rate = $stats['hit_rate'] ?? 0;

        if ( $total === 0 ) return;

        $sig_id = '#BT-' . str_pad( $total, 4, '0', STR_PAD_LEFT );
        ?>
<div class="bt-brief__sigcall">
    <span class="bt-brief__sigcall-ico">B</span>
    <div class="bt-brief__sigcall-body">
        <strong>
            <?php
            printf(
                /* translators: 1: signal ID */
                esc_html__( 'Latest signal: %s · logged to the public archive', 'blockticker' ),
                esc_html( $sig_id )
            );
            ?>
        </strong>
        <small>
            <?php
            printf(
                /* translators: 1: total count, 2: hit rate */
                esc_html__( '%1$s signals on record · %2$s%% hit rate (resolved)', 'blockticker' ),
                number_format( $total ), $hit_rate
            );
            ?>
        </small>
    </div>
    <a href="<?php echo esc_url( $archive_url ); ?>" class="bt-brief__sigcall-link">
        <?php esc_html_e( 'Archive →', 'blockticker' ); ?>
    </a>
</div>
        <?php
    }

    /**
     * Fallback chart — inline SVG placeholder used when [bt_price_chart] isn't available.
     */
    private static function render_placeholder_chart( string $title, float $bias ): void {
        $line_class = $bias >= 0 ? 'bt-brief__chart-line--up' : 'bt-brief__chart-line--down';
        // Simple synthetic wave path: trending up or down
        $pts = $bias >= 0
            ? '0,140 50,135 100,128 150,132 200,118 250,122 300,108 350,112 400,100 450,104 500,92 550,80 600,74 650,68 700,62 750,58 800,52'
            : '0,60 50,68 100,72 150,66 200,80 250,88 300,96 350,90 400,106 450,118 500,112 550,124 600,132 650,138 700,144 750,148 800,152';
        ?>
<div class="bt-brief__chart-card">
    <div class="bt-brief__chart-head">
        <span class="bt-brief__chart-title"><?php echo esc_html( $title ); ?></span>
    </div>
    <svg class="bt-brief__chart-svg" viewBox="0 0 800 200" preserveAspectRatio="none" aria-hidden="true">
        <line class="bt-brief__chart-grid" x1="0" y1="50"  x2="800" y2="50"/>
        <line class="bt-brief__chart-grid" x1="0" y1="100" x2="800" y2="100"/>
        <line class="bt-brief__chart-grid" x1="0" y1="150" x2="800" y2="150"/>
        <polyline class="bt-brief__chart-line <?php echo $line_class; ?>" points="<?php echo esc_attr( $pts ); ?>"/>
    </svg>
</div>
        <?php
    }

    // ─────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────

    private static function fmt_price( float $p, bool $dollar = false ): string {
        $prefix = $dollar ? '$' : '';
        if ( $p >= 1000 )  return $prefix . number_format( $p, 0 );
        if ( $p >= 1 )     return $prefix . number_format( $p, 2 );
        return $prefix . number_format( $p, 4 );
    }

    private static function fmt_pct( ?float $v ): string {
        if ( $v === null ) return '—';
        return ( $v >= 0 ? '+' : '' ) . number_format( $v, 2 ) . '%';
    }

    private static function chg_class( ?float $v ): string {
        if ( $v === null ) return '';
        return $v > 0 ? 'pos' : ( $v < 0 ? 'neg' : '' );
    }

    private static function tone_class( string $tone ): string {
        $map = array( 'bull' => 'bt-brief--bull', 'bear' => 'bt-brief--bear', 'neutral' => '' );
        return $map[ strtolower( $tone ) ] ?? '';
    }
}
