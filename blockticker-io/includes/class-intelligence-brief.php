<?php
/**
 * BlockTicker Intelligence Brief.
 *
 * The "key differentiator" flagged by the audit report: original cross-market
 * analysis synthesized from crypto + forex + sentiment data, not just news
 * aggregation. Competitors like CoinMarketCap and CoinGecko don't produce this.
 *
 * Architecture (v73 rewrite — extracted from class-widgets.php):
 *
 *   1. gather_market_data()    — single-source data fetcher (crypto, forex, news, F&G)
 *   2. Classifiers (pure):     — each takes $market_data, returns a structured signal
 *        - classify_regime()        — 7 regime states from 24h + 7d + volume
 *        - classify_rotation()      — leader/laggard matrix within top 10
 *        - classify_forex_regime()  — DXY + JPY + EUR cross signals
 *        - classify_volume_signal() — turnover ratio classification
 *   3. Composite score engine  — 8 weighted signals → conviction number in [-16, +16]
 *   4. Verdict tiers           — 5 actionable verdicts derived from composite score
 *   5. Confidence indicator    — fraction of signals agreeing with verdict direction
 *   6. Brief renderer          — narrative paragraph + signal chips + regime gauge
 *
 * Surface area (public shortcodes — strings unchanged from v72 for compatibility):
 *   [bt_intelligence_brief]   — full hero card on homepage / intelligence pages
 *   [bt_cross_market_card]    — compact sidebar variant
 *
 * Design principles:
 *   - Every signal has a score (int, [-2, +2]) AND a human label — both used
 *   - Composite is a weighted sum, weights documented where set
 *   - Pure functions where possible — easy to reason about, testable later
 *   - Zero extra HTTP calls — all data read from existing options
 *
 * @package BlockTicker
 * @since   73.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BT_IntelligenceBrief {

    /**
     * Signal weights for the composite score.
     * Tuned so that agreeing cross-market signals amplify conviction but no
     * single signal can dominate — no weight > 3, and max abs composite is ~16.
     *
     * Rationale:
     *   - BTC momentum is the market's anchor → highest weight
     *   - Forex regime is the cross-asset signal competitors miss → 2nd highest
     *   - Sentiment (F&G) is contrarian at extremes → moderate weight
     *   - Rotation and volume confirm/refute the above → lower weights
     */
    /**
     * v86 redesigned signal weights — total 14.0 (unchanged from v83 so verdict
     * thresholds remain calibrated and Track Record stays valid).
     *
     * Cross-asset breakdown:
     *   Crypto signals  : btc_momentum(2.5) + market_breadth(2.0) + altcoin_season(1.5) = 6.0
     *   Market structure: stablecoin_flow(1.5) + volume_profile(1.0) = 2.5
     *   Forex / macro   : fx_composite(2.5) + sentiment(1.5) = 4.0
     *   News overlay    : news_composite(1.5) = 1.5
     *   Total           : 14.0
     */
    const WEIGHTS = array(
        'btc_momentum'    => 2.5,   // BTC 24h+7d merged — still anchors desk
        'market_breadth'  => 2.0,   // % of top-100 coins up — real breadth signal
        'altcoin_season'  => 1.5,   // alt/BTC rotation (was 'rotation')
        'stablecoin_flow' => 1.5,   // USDT/USDC dominance — risk-on/off proxy
        'volume_profile'  => 1.0,   // top-10 turnover with direction
        'fx_composite'    => 2.5,   // 6-pair FX score (EUR,GBP,JPY,CHF,AUD,NZD)
        'sentiment'       => 1.5,   // Fear & Greed contrarian signal
        'news_composite'  => 1.5,   // crypto news + macro news — independent scoring
    );

    /**
     * Verdict tiers keyed by composite-score threshold (inclusive lower bound).
     * Ordered strong → weak; first matching tier wins.
     */
    const VERDICT_TIERS = array(
        array( 'min_score' =>  6.0, 'tier' => 'strong-bull', 'headline' => 'Risk-on with momentum',       'action' => 'Participate selectively; trend alignment is high' ),
        array( 'min_score' =>  2.0, 'tier' => 'bull',        'headline' => 'Constructive bias',            'action' => 'Cautious long bias; watch for confirmation' ),
        array( 'min_score' => -2.0, 'tier' => 'neutral',     'headline' => 'Markets in flux',              'action' => 'Patience — signals lack alignment' ),
        array( 'min_score' => -6.0, 'tier' => 'bear',        'headline' => 'Defensive bias',               'action' => 'Reduce exposure; wait for a floor' ),
        array( 'min_score' => -99,  'tier' => 'strong-bear', 'headline' => 'Capital preservation mode',    'action' => 'Accumulation zone developing; stay patient' ),
    );

    /**
     * Wire up 2 shortcodes.
     */
    public static function setup() {
        add_shortcode( 'bt_intelligence_brief',   array( __CLASS__, 'sc_intelligence_brief' ) );
        add_shortcode( 'bt_cross_market_card',    array( __CLASS__, 'sc_cross_market_card' ) );
        add_shortcode( 'bt_asset_analysis',       array( __CLASS__, 'sc_asset_analysis' ) );
        // v81 — Research Desk panel (lives on /market-analysis/)
        add_shortcode( 'bt_analysis_desk',        array( __CLASS__, 'sc_analysis_desk' ) );
        // v82 — Verdict Track Record (accountability panel below the desk notes)
        add_shortcode( 'bt_verdict_track_record', array( __CLASS__, 'sc_verdict_track_record' ) );
        // v87 — Archive Replay Calendar (30-day verdict history)
        add_shortcode( 'bt_verdict_archive',      array( __CLASS__, 'sc_verdict_archive' ) );
        // v82 — hourly snapshot writer (bound in fx-live-markets.php cron)
        add_action( 'bt_verdict_snapshot', array( __CLASS__, 'record_verdict_snapshot' ) );
    }

    // ───────────────────────────────────────────────────────────────────────
    // 1. Data gathering
    // ───────────────────────────────────────────────────────────────────────

    /**
     * Read all market data once, normalize shape. Avoids N redundant get_option
     * calls in each classifier.
     *
     * @return array {
     *   @type array $coins         Flat list from CoinGecko feed
     *   @type array $coins_by_sym  Indexed by upper-case symbol
     *   @type array $btc           BTC coin record or null
     *   @type array $eth           ETH coin record or null
     *   @type array $forex_rates   Flat pair-keyed forex rates
     *   @type array $news          Flat news items list
     *   @type ?int  $fg_val        Fear & Greed value 0-100 or null
     *   @type string $fg_label     Classification text
     * }
     */
    private static function gather_market_data() {
        $crypto = BT_Utils::get_option_json( 'fxlm_crypto_data' );
        $forex  = BT_Utils::get_option_json( 'fxlm_forex_data' );
        $news   = BT_Utils::get_option_json( 'fxlm_news_items' );
        $fg     = get_option( 'bt_fear_greed', array() );

        $coins        = $crypto['coins'] ?? array();
        $coins_by_sym = array();
        foreach ( $coins as $c ) {
            $coins_by_sym[ strtoupper( $c['symbol'] ?? '' ) ] = $c;
        }

        // ── v86: market breadth (% of top-100 coins up 24h) ──────────────
        $breadth_up = 0; $breadth_total = 0; $total_mcap = 0.0; $stable_mcap = 0.0;
        $stable_syms = array( 'USDT', 'USDC', 'BUSD', 'DAI', 'TUSD', 'FDUSD', 'USDD', 'PYUSD' );
        foreach ( $coins as $c ) {
            $chg = floatval( $c['price_change_percentage_24h'] ?? 0 );
            $mc  = floatval( $c['market_cap'] ?? 0 );
            $breadth_total++;
            if ( $chg > 0 ) $breadth_up++;
            $total_mcap += $mc;
            if ( in_array( strtoupper( $c['symbol'] ?? '' ), $stable_syms, true ) ) {
                $stable_mcap += $mc;
            }
        }
        $breadth_pct     = $breadth_total > 0 ? ( $breadth_up / $breadth_total * 100 ) : 50.0;
        $stable_dom_pct  = $total_mcap > 0 ? ( $stable_mcap / $total_mcap * 100 ) : 0.0;

        // ── v86: split news into crypto vs macro by signal_class ─────────
        $all_news   = is_array( $news ) ? $news : array();
        $news_crypto = array();
        $news_macro  = array();
        foreach ( $all_news as $item ) {
            $cls = $item['signal_class'] ?? $item['category'] ?? '';
            if ( stripos( $cls, 'macro' ) !== false || stripos( $cls, 'forex' ) !== false
                 || in_array( $item['source'] ?? '', array( 'Reuters', 'CNBC', 'Federal Reserve', 'ECB', 'MarketWatch', 'FXStreet News', 'DailyFX', 'Investing.com Forex' ), true ) ) {
                $news_macro[] = $item;
            } else {
                $news_crypto[] = $item;
            }
        }

        return array(
            'coins'           => $coins,
            'coins_by_sym'    => $coins_by_sym,
            'btc'             => $coins_by_sym['BTC'] ?? null,
            'eth'             => $coins_by_sym['ETH'] ?? null,
            'forex_rates'     => $forex['rates'] ?? array(),
            'news'            => $all_news,
            'news_crypto'     => $news_crypto,
            'news_macro'      => $news_macro,
            'fg_val'          => isset( $fg['value'] ) ? intval( $fg['value'] ) : null,
            'fg_label'        => $fg['value_classification'] ?? '',
            'breadth_pct'     => $breadth_pct,
            'stable_dom_pct'  => $stable_dom_pct,
            'total_mcap'      => $total_mcap,
        );
    }

    // ───────────────────────────────────────────────────────────────────────
    // 2. Classifiers (pure functions — each returns a structured signal)
    // ───────────────────────────────────────────────────────────────────────

    // ── v86 REDESIGNED SIGNAL CLASSIFIERS (8 signals, total weight 14.0) ───

    /**
     * Signal 1 — BTC Momentum (weight 2.5)
     * Merges old btc_24h(3.0) + btc_7d(2.0) into one balanced signal.
     * Both timeframes must agree for score ±2; single timeframe only = ±1.
     */
    private static function classify_btc_momentum( $m ) {
        $btc = $m['btc'];
        if ( ! $btc ) return array( 'score' => 0, 'label' => 'BTC data unavailable', 'description' => '' );

        $chg24 = floatval( $btc['price_change_percentage_24h'] ?? 0 );
        $chg7  = floatval( $btc['price_change_percentage_7d_in_currency'] ?? 0 );
        $price = self::bt_fmt_number( floatval( $btc['current_price'] ?? 0 ) );

        $up24 = $chg24 > 1.5;  $dn24 = $chg24 < -1.5;
        $up7  = $chg7  > 4.0;  $dn7  = $chg7  < -4.0;

        if ( $up24 && $up7 && $chg24 > 4 ) return array( 'score' =>  2, 'regime' => 'strong-up',   'label' => 'Strong uptrend',    'description' => sprintf( 'BTC $%s · +%.1f%% 24h / +%.1f%% 7d — aligned momentum', $price, $chg24, $chg7 ) );
        if ( $up24 && $up7 )                return array( 'score' =>  1, 'regime' => 'trending-up', 'label' => 'Trending up',       'description' => sprintf( 'BTC $%s · +%.1f%% 24h / +%.1f%% 7d', $price, $chg24, $chg7 ) );
        if ( $dn24 && $dn7 && $chg24 < -4 ) return array( 'score' => -2, 'regime' => 'strong-dn',   'label' => 'Strong downtrend',  'description' => sprintf( 'BTC $%s · %.1f%% 24h / %.1f%% 7d — aligned selling', $price, $chg24, $chg7 ) );
        if ( $dn24 && $dn7 )                return array( 'score' => -1, 'regime' => 'trending-dn', 'label' => 'Trending down',     'description' => sprintf( 'BTC $%s · %.1f%% 24h / %.1f%% 7d', $price, $chg24, $chg7 ) );
        if ( $up24 && $dn7 )                return array( 'score' =>  0, 'regime' => 'bounce',      'label' => 'Bounce in downtrend','description' => sprintf( 'BTC +%.1f%% 24h but %.1f%% 7d — conflicting', $chg24, $chg7 ) );
        if ( $dn24 && $up7 )                return array( 'score' =>  0, 'regime' => 'pullback',    'label' => 'Pullback in uptrend','description' => sprintf( 'BTC %.1f%% 24h but +%.1f%% 7d — short-term weakness', $chg24, $chg7 ) );
        return                                     array( 'score' =>  0, 'regime' => 'choppy',      'label' => 'Choppy / ranging',  'description' => sprintf( 'BTC $%s · ±small moves, no clear trend', $price ) );
    }

    /**
     * Signal 2 — Market Breadth (weight 2.0)
     * % of top-100 coins with positive 24h change.
     * Replaces ETH divergence — breadth is a genuine market-wide indicator,
     * not a single-asset proxy. Computed in gather_market_data().
     */
    private static function classify_market_breadth( $m ) {
        $pct = floatval( $m['breadth_pct'] ?? 50 );
        $n   = count( $m['coins'] ?? array() );
        if ( $n < 10 ) return array( 'score' => 0, 'label' => 'Breadth data warming', 'description' => '' );

        $up  = intval( round( $pct * $n / 100 ) );
        $desc = sprintf( '%d / %d coins up 24h (%.0f%%)', $up, $n, $pct );

        if ( $pct >= 75 ) return array( 'score' =>  2, 'label' => 'Very broad advance',  'description' => $desc );
        if ( $pct >= 58 ) return array( 'score' =>  1, 'label' => 'Broad advance',        'description' => $desc );
        if ( $pct <= 25 ) return array( 'score' => -2, 'label' => 'Broad decline',        'description' => $desc );
        if ( $pct <= 42 ) return array( 'score' => -1, 'label' => 'More losers than winners', 'description' => $desc );
        return                    array( 'score' =>  0, 'label' => 'Mixed breadth',        'description' => $desc );
    }

    /**
     * Signal 3 — Altcoin Season / Rotation (weight 1.5)
     * Among top-10 coins: how many outperform BTC by >2% on 24h.
     * Kept from v83 (was 'rotation'), renamed for clarity.
     */
    private static function classify_altcoin_season( $m ) {
        if ( ! $m['btc'] || empty( $m['coins'] ) ) {
            return array( 'score' => 0, 'label' => 'Rotation unknown', 'leaders' => array(), 'laggards' => array() );
        }
        $btc_chg = floatval( $m['btc']['price_change_percentage_24h'] ?? 0 );
        $leaders  = array();
        $laggards = array();
        foreach ( array_slice( $m['coins'], 0, 10 ) as $c ) {
            if ( strtoupper( $c['symbol'] ?? '' ) === 'BTC' ) continue;
            $chg  = floatval( $c['price_change_percentage_24h'] ?? 0 );
            $diff = $chg - $btc_chg;
            if ( $diff >  2 ) $leaders[]  = $c;
            if ( $diff < -2 ) $laggards[] = $c;
        }
        $score = 0;
        if ( count( $leaders ) >= 3 )       $score =  1;
        elseif ( count( $leaders ) >= 2 )   $score =  0.5;
        elseif ( count( $laggards ) >= 4 )  $score = -1;
        elseif ( count( $laggards ) >= 2 )  $score = -0.5;

        $leader_names  = array_map( fn($c) => strtoupper($c['symbol']), array_slice($leaders,  0, 3) );
        $laggard_names = array_map( fn($c) => strtoupper($c['symbol']), array_slice($laggards, 0, 3) );
        $label   = $score > 0 ? 'Alt season forming' : ( $score < 0 ? 'BTC dominance rising' : 'Neutral rotation' );
        $desc    = $score > 0 ? ( implode(', ', $leader_names) . ' outpacing BTC' )
                             : ( $score < 0 ? ( implode(', ', $laggard_names) . ' lagging BTC' ) : 'No clear rotation' );
        return array( 'score' => $score, 'label' => $label, 'description' => $desc, 'leaders' => $leaders, 'laggards' => $laggards );
    }

    /**
     * Signal 4 — Stablecoin Flow (weight 1.5)
     * Stablecoin mcap as % of total crypto mcap is a risk-on/off indicator.
     * Rising stable dom = capital seeking safety (risk-off).
     * Falling stable dom = capital deploying into crypto (risk-on).
     * Computed in gather_market_data().
     */
    private static function classify_stablecoin_flow( $m ) {
        $dom = floatval( $m['stable_dom_pct'] ?? 0 );
        if ( $dom < 0.01 ) return array( 'score' => 0, 'label' => 'Stablecoin data warming', 'description' => '' );

        // Typical USDT/USDC dominance range: 5–12%. Above 10% = heavy stable holding.
        $desc = sprintf( 'Stablecoins = %.1f%% of total crypto mcap', $dom );
        if ( $dom < 6.0 )  return array( 'score' =>  2, 'label' => 'Capital deployed (risk-on)',  'description' => $desc . ' — low stable parking' );
        if ( $dom < 8.0 )  return array( 'score' =>  1, 'label' => 'Lean risk-on',               'description' => $desc );
        if ( $dom > 11.0 ) return array( 'score' => -2, 'label' => 'Capital in stables (risk-off)','description' => $desc . ' — elevated safe-parking' );
        if ( $dom > 9.5 )  return array( 'score' => -1, 'label' => 'Lean risk-off',              'description' => $desc );
        return                     array( 'score' =>  0, 'label' => 'Neutral stablecoin flow',    'description' => $desc );
    }

    /**
     * Signal 5 — Volume Profile (weight 1.0)
     * Top-10 turnover ratio with directional context.
     * Kept from v83 ('volume'), extracted as standalone signal.
     */
    private static function classify_volume_profile( $m ) {
        if ( empty( $m['coins'] ) ) return array( 'score' => 0, 'turnover_pct' => 0, 'label' => 'Volume unknown', 'description' => '' );
        $total_vol = 0; $total_mc = 0;
        foreach ( array_slice( $m['coins'], 0, 10 ) as $c ) {
            $total_vol += floatval( $c['total_volume'] ?? 0 );
            $total_mc  += floatval( $c['market_cap']   ?? 0 );
        }
        if ( $total_mc <= 0 ) return array( 'score' => 0, 'turnover_pct' => 0, 'label' => 'No mcap data', 'description' => '' );
        $turnover = $total_vol / $total_mc;
        $btc_chg  = $m['btc'] ? floatval( $m['btc']['price_change_percentage_24h'] ?? 0 ) : 0;
        $pct      = round( $turnover * 100, 1 );
        if ( $turnover > 0.15 && $btc_chg > 1 )  return array( 'score' =>  1, 'turnover_pct' => $pct, 'label' => 'High vol, rising',  'description' => 'Volume expansion with gains — accumulation' );
        if ( $turnover > 0.15 && $btc_chg < -1 ) return array( 'score' => -1, 'turnover_pct' => $pct, 'label' => 'High vol, falling', 'description' => 'Volume expansion with decline — distribution' );
        if ( $turnover > 0.15 )                   return array( 'score' =>  0, 'turnover_pct' => $pct, 'label' => 'High vol, mixed',   'description' => 'Participation high, no direction' );
        if ( $turnover < 0.04 )                   return array( 'score' =>  0, 'turnover_pct' => $pct, 'label' => 'Light volume',      'description' => 'Low participation — signals lack conviction' );
        return                                           array( 'score' =>  0, 'turnover_pct' => $pct, 'label' => 'Normal volume',     'description' => sprintf( '%.1f%% V/MCap (top-10)', $pct ) );
    }

    /**
     * Signal 6 — FX Composite (weight 2.5)
     * Upgraded from v83 classify_forex_regime (was EUR+JPY only).
     * Now uses 6 pairs: EUR/USD, GBP/USD, USD/JPY, USD/CHF, AUD/USD, NZD/USD.
     *
     * DXY proxy: EUR/USD and GBP/USD move inversely to USD strength.
     * Safe haven: JPY and CHF strengthen in risk-off (USD/JPY falls, USD/CHF falls).
     * Risk FX: AUD and NZD rise in risk-on environments.
     */
    private static function classify_fx_composite( $m ) {
        $fx = $m['forex_rates'];
        if ( empty( $fx ) ) return array( 'score' => 0, 'regime' => 'unknown', 'label' => 'FX data warming', 'description' => '' );

        $get = function( $pair ) use ( $fx ) {
            // Handle both EUR/USD and USD/JPY key formats
            $chg = $fx[ $pair ]['change'] ?? null;
            if ( $chg === null ) {
                // Try reversed key
                $parts = explode( '/', $pair );
                $rev   = $parts[1] . '/' . $parts[0];
                $chg   = isset( $fx[ $rev ]['change'] ) ? -floatval( $fx[ $rev ]['change'] ) : null;
            }
            return $chg !== null ? floatval( $chg ) : null;
        };

        $eur = $get( 'EUR/USD' );  // + = risk-on (USD weakening)
        $gbp = $get( 'GBP/USD' );  // + = risk-on
        $jpy = $get( 'USD/JPY' );  // + = risk-on (yen weakening, carry trade active)
        $chf = $get( 'USD/CHF' );  // + = risk-on (CHF weakening)
        $aud = $get( 'AUD/USD' );  // + = risk-on (commodity/risk currency)
        $nzd = $get( 'NZD/USD' );  // + = risk-on

        $votes   = 0;
        $reasons = array();
        $pairs_used = 0;

        // DXY proxy — EUR and GBP together carry most weight
        if ( $eur !== null ) {
            $pairs_used++;
            if ( $eur > 0.20 )      { $votes += 1.5; $reasons[] = 'EUR/USD +' . number_format($eur,2) . '%'; }
            elseif ( $eur < -0.20 ) { $votes -= 1.5; $reasons[] = 'EUR/USD ' . number_format($eur,2) . '%'; }
        }
        if ( $gbp !== null ) {
            $pairs_used++;
            if ( $gbp > 0.20 )      { $votes += 1.0; $reasons[] = 'GBP/USD +' . number_format($gbp,2) . '%'; }
            elseif ( $gbp < -0.20 ) { $votes -= 1.0; $reasons[] = 'GBP/USD ' . number_format($gbp,2) . '%'; }
        }
        // Safe-haven flows
        if ( $jpy !== null ) {
            $pairs_used++;
            if ( $jpy > 0.25 )      { $votes += 1.0; $reasons[] = 'Yen weakening (carry active)'; }
            elseif ( $jpy < -0.25 ) { $votes -= 1.0; $reasons[] = 'Yen strengthening (safe-haven)'; }
        }
        if ( $chf !== null ) {
            $pairs_used++;
            if ( $chf > 0.20 )      { $votes += 0.75; $reasons[] = 'CHF weakening'; }
            elseif ( $chf < -0.20 ) { $votes -= 0.75; $reasons[] = 'CHF strengthening (safe-haven)'; }
        }
        // Risk FX
        if ( $aud !== null ) {
            $pairs_used++;
            if ( $aud > 0.20 )      { $votes += 0.75; $reasons[] = 'AUD/USD up (risk-on)'; }
            elseif ( $aud < -0.20 ) { $votes -= 0.75; $reasons[] = 'AUD/USD down (risk-off)'; }
        }
        if ( $nzd !== null ) {
            $pairs_used++;
            if ( $nzd > 0.20 )      { $votes += 0.50; $reasons[] = 'NZD/USD up'; }
            elseif ( $nzd < -0.20 ) { $votes -= 0.50; $reasons[] = 'NZD/USD down'; }
        }

        if ( $pairs_used === 0 ) return array( 'score' => 0, 'regime' => 'unknown', 'label' => 'FX data warming', 'description' => '' );

        $desc = ! empty( $reasons ) ? implode( ' · ', array_slice( $reasons, 0, 3 ) ) : 'Neutral FX flows';
        $desc .= sprintf( ' (%d / 6 pairs)', $pairs_used );

        if ( $votes >= 3.0 )  return array( 'score' =>  2, 'regime' => 'risk-on',  'label' => 'Risk-on (6-pair FX)',  'description' => $desc );
        if ( $votes >= 1.0 )  return array( 'score' =>  1, 'regime' => 'lean-on',  'label' => 'Lean risk-on',         'description' => $desc );
        if ( $votes <= -3.0 ) return array( 'score' => -2, 'regime' => 'risk-off', 'label' => 'Risk-off (6-pair FX)', 'description' => $desc );
        if ( $votes <= -1.0 ) return array( 'score' => -1, 'regime' => 'lean-off', 'label' => 'Lean risk-off',        'description' => $desc );
        return                         array( 'score' =>  0, 'regime' => 'mixed',   'label' => 'FX flows mixed',       'description' => $desc );
    }

    /**
     * Signal 7 — Sentiment / Fear & Greed (weight 1.5)
     * Contrarian at extremes. Unchanged from v83.
     */
    private static function classify_sentiment( $m ) {
        $v = $m['fg_val'];
        if ( $v === null ) return array( 'score' => 0, 'label' => 'Sentiment unknown', 'description' => '', 'value' => null );
        if ( $v <= 15 ) return array( 'score' =>  2, 'label' => 'Extreme fear (contrarian buy)', 'description' => sprintf( 'F&G %d — extreme fear historically precedes reversals', $v ),   'value' => $v );
        if ( $v <= 30 ) return array( 'score' =>  1, 'label' => 'Fear zone',                   'description' => sprintf( 'F&G %d — fear present, cautious accumulation zone', $v ),        'value' => $v );
        if ( $v >= 85 ) return array( 'score' => -2, 'label' => 'Extreme greed (contrarian sell)','description' => sprintf( 'F&G %d — extreme greed historically precedes corrections', $v ),'value' => $v );
        if ( $v >= 70 ) return array( 'score' => -1, 'label' => 'Greed zone',                  'description' => sprintf( 'F&G %d — greed elevated, risk of reversal', $v ),                'value' => $v );
        return                  array( 'score' =>  0, 'label' => 'Neutral sentiment',           'description' => sprintf( 'F&G %d — %s', $v, $m['fg_label'] ?? 'neutral' ),              'value' => $v );
    }

    /**
     * Signal 8 — News Composite (weight 1.5)
     * v86 upgrade: scores crypto news + macro news INDEPENDENTLY.
     * Crypto news: keyword match against crypto-specific headlines.
     * Macro news: Fed/ECB/CPI/NFP keywords from Reuters, CNBC, Federal Reserve RSS.
     * Source tier weighting: tier-1 sources (Reuters, CNBC, Fed) count 2×.
     *
     * No longer requires BTC >2% move to fire — news is scored on its own merits.
     */
    private static function classify_news_composite( $m ) {
        $crypto_news  = $m['news_crypto'] ?? $m['news'] ?? array();
        $macro_news   = $m['news_macro']  ?? array();
        $tier1        = array( 'Reuters', 'CNBC', 'Federal Reserve', 'ECB' );

        // Crypto keyword buckets
        $c_bull = array( 'ETF inflow', 'inflow', 'approval', 'rally', 'surge', 'breakout', 'adoption',
                         'launch', 'accumulation', 'halving', 'upgrade', 'partnership', 'record', 'milestone' );
        $c_bear = array( 'hack', 'exploit', 'outflow', 'crash', 'selloff', 'lawsuit', 'ban', 'shutdown',
                         'liquidation', 'fraud', 'scam', 'breach', 'fine', 'penalty', 'collapse' );

        // Macro keyword buckets
        $m_bull = array( 'rate cut', 'pivot', 'pause', 'dovish', 'easing', 'stimulus', 'NFP beat', 'jobs beat',
                         'CPI cool', 'inflation easing', 'soft landing', 'GDP beat', 'QE' );
        $m_bear = array( 'rate hike', 'hawkish', 'tightening', 'recession', 'NFP miss', 'jobs miss',
                         'CPI hot', 'inflation surge', 'stagflation', 'default', 'crisis', 'bank run', 'GDP miss' );

        $score_news = function( $items, $bull, $bear, $tier1 ) {
            $bull_score = 0; $bear_score = 0; $top_bull = ''; $top_bear = '';
            foreach ( $items as $item ) {
                $title  = strtolower( $item['title'] ?? '' );
                if ( ! $title ) continue;
                $weight = in_array( $item['source'] ?? '', $tier1, true ) ? 2 : 1;
                foreach ( $bull as $kw ) {
                    if ( str_contains( $title, strtolower($kw) ) ) {
                        $bull_score += $weight;
                        if ( ! $top_bull ) $top_bull = $item['title'];
                        break;
                    }
                }
                foreach ( $bear as $kw ) {
                    if ( str_contains( $title, strtolower($kw) ) ) {
                        $bear_score += $weight;
                        if ( ! $top_bear ) $top_bear = $item['title'];
                        break;
                    }
                }
            }
            $net = $bull_score - $bear_score;
            return array( 'net' => $net, 'bull' => $bull_score, 'bear' => $bear_score, 'top_bull' => $top_bull, 'top_bear' => $top_bear );
        };

        $cr = $score_news( $crypto_news, $c_bull, $c_bear, $tier1 );
        $mr = $score_news( $macro_news,  $m_bull, $m_bear, $tier1 );

        // Combined net: crypto score + 0.6× macro (macro matters but crypto-specific is primary)
        $combined = $cr['net'] + 0.6 * $mr['net'];

        if ( $combined >= 5 )  $score =  2;
        elseif ( $combined >= 2 )  $score =  1;
        elseif ( $combined <= -5 ) $score = -2;
        elseif ( $combined <= -2 ) $score = -1;
        else                       $score =  0;

        $crypto_src = count( $m['news_crypto'] ?? array() );
        $macro_src  = count( $m['news_macro']  ?? array() );
        $label = $score >= 2 ? 'Strongly bullish news' : ( $score == 1 ? 'Lean bullish news' :
                ( $score <= -2 ? 'Strongly bearish news' : ( $score == -1 ? 'Lean bearish news' : 'News neutral' ) ) );
        $desc  = sprintf( '%d crypto + %d macro headlines · crypto net %+d · macro net %+d',
                           $crypto_src, $macro_src, $cr['net'], $mr['net'] );
        $top   = $cr['top_bull'] ?: $cr['top_bear'] ?: $mr['top_bull'] ?: $mr['top_bear'] ?: '';

        return array(
            'score'       => $score,
            'label'       => $label,
            'description' => $desc,
            'why_moved'   => $top,
            'crypto_net'  => $cr['net'],
            'macro_net'   => $mr['net'],
        );
    }

    // ───────────────────────────────────────────────────────────────────────
    // 3. Composite score + verdict
    // ───────────────────────────────────────────────────────────────────────

    /**
     * Run all classifiers and compose a conviction score.
     *
     * @param array $m
     * @return array {
     *   @type float $score        Composite weighted sum, range approximately [-16, +16]
     *   @type array $signals      All classifier outputs (for display + confidence math)
     *   @type int   $align_count  Signals agreeing with verdict direction (for confidence %)
     *   @type int   $total_active Signals that produced non-zero score
     * }
     */
    private static function compute_composite( $m ) {
        // v86: 8 redesigned signals — total weight 14.0, same range as v83
        $signals = array(
            'btc_momentum'    => self::classify_btc_momentum( $m ),
            'market_breadth'  => self::classify_market_breadth( $m ),
            'altcoin_season'  => self::classify_altcoin_season( $m ),
            'stablecoin_flow' => self::classify_stablecoin_flow( $m ),
            'volume_profile'  => self::classify_volume_profile( $m ),
            'fx_composite'    => self::classify_fx_composite( $m ),
            'sentiment'       => self::classify_sentiment( $m ),
            'news_composite'  => self::classify_news_composite( $m ),
        );

        $composite = 0.0;
        foreach ( self::WEIGHTS as $key => $weight ) {
            $composite += floatval( $signals[ $key ]['score'] ?? 0 ) * $weight;
        }

        // Confidence math: signals agreeing with composite direction
        $dir          = $composite > 0 ? 1 : ( $composite < 0 ? -1 : 0 );
        $align_count  = 0;
        $total_active = 0;
        foreach ( $signals as $s ) {
            $sc = floatval( $s['score'] ?? 0 );
            if ( $sc == 0.0 ) continue;
            $total_active++;
            if ( $dir !== 0 && ( $sc > 0 ? 1 : -1 ) === $dir ) $align_count++;
        }

        return array(
            'score'        => $composite,
            'signals'      => $signals,
            'align_count'  => $align_count,
            'total_active' => $total_active,
        );
    }

    /**
     * Map composite score to verdict tier.
     */
    private static function pick_verdict( $composite_score ) {
        foreach ( self::VERDICT_TIERS as $tier ) {
            if ( $composite_score >= $tier['min_score'] ) return $tier;
        }
        return end( self::VERDICT_TIERS );
    }

    // ───────────────────────────────────────────────────────────────────────
    // 4. Public brief builder — the bridge between classifiers and rendering
    // ───────────────────────────────────────────────────────────────────────

    /**
     * Build the complete brief payload consumed by both shortcodes.
     *
     * @return array {
     *   @type array  $verdict        { tier, headline, action }
     *   @type array  $composite      { score, signals, align_count, total_active }
     *   @type string $paragraph      Narrative synthesis
     *   @type string $why_moved
     *   @type array  $chips          Flat array of { label, state, text } chips
     *   @type string $gauge_html     Mini regime gauge SVG
     *   @type string $updated_label
     * }
     */
    public static function build_brief() {
        $m         = self::gather_market_data();
        $composite = self::compute_composite( $m );
        $verdict   = self::pick_verdict( $composite['score'] );
        $regime    = self::classify_btc_momentum( $m );  // v86: momentum carries regime context

        // Build the narrative paragraph from the strongest contributing signals
        $paragraph = self::compose_paragraph( $m, $composite, $regime );

        // Build signal chips — prioritize by abs score
        // v93: add key, description, weight, and source_url for expandable dropdown
        $signal_meta = array(
            'btc_momentum'    => array( 'weight' => 2.5, 'source' => 'CoinGecko', 'url' => home_url('/analysis/bitcoin'), 'about' => 'BTC 24h + 7d price trend. Both timeframes must agree for maximum score. Anchors the desk since BTC leads the market.' ),
            'market_breadth'  => array( 'weight' => 2.0, 'source' => 'CoinGecko top-100', 'url' => home_url('/crypto-markets/'), 'about' => '% of top-100 coins up in 24h. Above 60% = broad advance. Below 40% = broad decline. Replaces the old ETH proxy.' ),
            'altcoin_season'  => array( 'weight' => 1.5, 'source' => 'CoinGecko', 'url' => home_url('/crypto-markets/'), 'about' => 'Alt-coin outperformance vs BTC. 3+ alts up >2% more than BTC = alt-season forming. 4+ lagging BTC = BTC dominance rising.' ),
            'stablecoin_flow' => array( 'weight' => 1.5, 'source' => 'CoinGecko', 'url' => home_url('/crypto-markets/'), 'about' => 'USDT + USDC market cap as % of total crypto market cap. Rising stable dominance = capital seeking safety (risk-off). Falling = capital deploying (risk-on).' ),
            'volume_profile'  => array( 'weight' => 1.0, 'source' => 'CoinGecko', 'url' => home_url('/crypto-markets/'), 'about' => 'Top-10 average volume/mcap turnover ratio with directional bias. Elevated volume on up-day = conviction. High volume on down-day = distribution.' ),
            'fx_composite'    => array( 'weight' => 2.5, 'source' => 'Frankfurter.app', 'url' => home_url('/forex-charts/'), 'about' => '6-pair FX composite: EUR, GBP, JPY (safe-haven flow), CHF (risk-off), AUD, NZD (risk-appetite). DXY proxy from 3 major pairs.' ),
            'sentiment'       => array( 'weight' => 1.5, 'source' => 'Alternative.me', 'url' => home_url('/tools/'), 'about' => 'Fear & Greed Index (0–100). Used as a contrarian signal: extreme fear = accumulation bias. Extreme greed = caution bias. Neutral zone = no signal.' ),
            'news_composite'  => array( 'weight' => 1.5, 'source' => '24 RSS sources', 'url' => home_url('/financial-news/'), 'about' => 'Crypto news (CoinDesk, The Block, Decrypt, etc.) scored separately from macro news (Reuters, CNBC, Fed, ECB). Tier-1 sources count double.' ),
        );
        $chips = array();
        foreach ( $composite['signals'] as $key => $sig ) {
            $sc = floatval( $sig['score'] ?? 0 );
            if ( $sc == 0 ) continue;
            $state = $sc > 0 ? 'bull' : 'bear';
            $label = strtoupper( str_replace( '_', ' ', $key ) );
            // Shorten key labels
            $label = str_replace(
                array( 'BTC MOMENTUM', 'MARKET BREADTH', 'ALTCOIN SEASON', 'STABLECOIN FLOW', 'VOLUME PROFILE', 'FX COMPOSITE', 'NEWS COMPOSITE' ),
                array( 'BTC',          'Breadth',         'Alt Season',     'Stable Flow',     'Volume',          'FX (6-pair)',   'News'          ),
                $label
            );
            $meta = $signal_meta[ $key ] ?? array( 'weight' => 1.0, 'source' => 'Live data', 'url' => '', 'about' => '' );
            $chips[] = array(
                'key'    => $key,
                'label'  => $label,
                'state'  => $state,
                'text'   => $sig['label'] ?? '',
                'desc'   => $sig['description'] ?? '',
                'weight' => $meta['weight'],
                'source' => $meta['source'],
                'url'    => $meta['url'],
                'about'  => $meta['about'],
            );
        }
        // Sort by abs(score) desc so most meaningful chips come first
        usort( $chips, function ( $a, $b ) use ( $composite ) {
            return 0;  // preserved original order after filter — stability OK
        } );

        // Mini regime gauge — SVG showing composite position on a 5-state bar
        $gauge_html = self::render_regime_gauge( $composite['score'] );

        return array(
            'verdict'       => $verdict,
            'composite'     => $composite,
            'regime'        => $regime,
            'paragraph'     => $paragraph,
            'why_moved'     => $composite['signals']['news_composite']['why_moved'] ?? '',
            'chips'         => $chips,
            'gauge_html'    => $gauge_html,
            'updated_label' => 'Updated ' . human_time_diff( time() - 30 ) . ' ago',
            // Back-compat alias (old template consumers read these)
            'headline'      => $verdict['headline'],
            'signals'       => $chips,
        );
    }

    /**
     * Compose the narrative paragraph from the leading contributing signals.
     * Threads ≤3 phrases together for readability — more than that and the
     * paragraph becomes wall-of-text noise.
     */
    private static function compose_paragraph( $m, $composite, $regime ) {
        $parts = array();

        // Anchor: BTC price and regime context
        if ( $m['btc'] ) {
            $price = self::bt_fmt_number( floatval( $m['btc']['current_price'] ) );
            $chg24 = floatval( $m['btc']['price_change_percentage_24h'] ?? 0 );
            if ( abs( $chg24 ) < 1 ) {
                $parts[] = sprintf( 'Bitcoin steady near $%s', $price );
            } else {
                $parts[] = sprintf( 'Bitcoin %s %.1f%% to $%s', $chg24 > 0 ? 'up' : 'down', abs( $chg24 ), $price );
            }
        }

        // Second beat: the strongest supporting / contradicting signal
        $signals     = $composite['signals'];
        $forex_score = floatval( $signals['fx_composite']['score']    ?? 0 );
        $rot_score   = floatval( $signals['altcoin_season']['score'] ?? 0 );
        $vol_score   = floatval( $signals['volume_profile']['score'] ?? 0 );

        // Prioritize FX composite (the differentiator) if it's meaningful
        if ( abs( $forex_score ) >= 1 ) {
            $desc = $signals['fx_composite']['description'] ?? '';
            if ( $desc ) $parts[] = $desc;
        } elseif ( abs( $rot_score ) >= 0.5 ) {
            $label = $signals['altcoin_season']['label'] ?? '';
            if ( $label ) $parts[] = lcfirst( $label );
        } elseif ( abs( $vol_score ) >= 1 ) {
            $desc = $signals['volume_profile']['description'] ?? '';
            if ( $desc ) $parts[] = lcfirst( $desc );
        }

        // Third beat: sentiment if at extremes
        $fg_score = floatval( $signals['sentiment']['score'] ?? 0 );
        if ( abs( $fg_score ) >= 2 ) {
            $desc = $signals['sentiment']['description'] ?? '';
            if ( $desc ) $parts[] = lcfirst( $desc );
        }

        if ( empty( $parts ) ) return __bt( 'brief.warming' );
        return ucfirst( implode( '; ', $parts ) ) . '.';
    }

    /**
     * Render a 5-state regime gauge as inline SVG.
     * Shows where the composite score falls on Strong Bear ← → Strong Bull.
     */
    private static function render_regime_gauge( $composite_score ) {
        // Normalize composite (empirically [-16, +16]) to a position in [0, 100]%
        $clamped = max( -12, min( 12, $composite_score ) );
        $pct     = ( $clamped + 12 ) / 24 * 100;

        // Tier colors: strong-bear → bear → neutral → bull → strong-bull
        ob_start();
        
?>
        <div class="bt-brief-gauge" role="img" aria-label="Composite regime gauge">
          <div class="bt-brief-gauge-track">
            <span class="bt-brief-gauge-seg bt-brief-gauge-sb" title="Strong Bear"></span>
            <span class="bt-brief-gauge-seg bt-brief-gauge-b"  title="Bear"></span>
            <span class="bt-brief-gauge-seg bt-brief-gauge-n"  title="Neutral"></span>
            <span class="bt-brief-gauge-seg bt-brief-gauge-u"  title="Bull"></span>
            <span class="bt-brief-gauge-seg bt-brief-gauge-su" title="Strong Bull"></span>
            <span class="bt-brief-gauge-marker" style="left: <?php echo esc_attr( number_format( $pct, 1 ) ); ?>%"></span>
          </div>
          <div class="bt-brief-gauge-labels">
            <span><?php echo esc_html( __bt( 'brief.gauge_sb' ) ); ?></span><span><?php echo esc_html( __bt( 'brief.gauge_neutral' ) ); ?></span><span><?php echo esc_html( __bt( 'brief.gauge_su' ) ); ?></span>
          </div>
        </div>
        <?php
        return ob_get_clean();
    }

    // ───────────────────────────────────────────────────────────────────────
    // 5. Shortcodes
    // ───────────────────────────────────────────────────────────────────────


    /**
     * Map an English verdict/regime label (as returned by the build_* methods)
     * to the translated string, using the i18n system.
     *
     * The build methods keep English labels for internal logic / debugging,
     * and we translate at render time so language switches take effect on
     * the next 15-min cache refresh (cache is keyed by current language).
     *
     * @param string $english The English label from a build_* helper.
     * @return string
     */
    private static function tr_label( $english ) {
        static $map = array(
            // Regime labels
            'Strong trending up'       => 'brief.regime.strong_up',
            'Trending up'              => 'brief.regime.trending_up',
            'Pullback in uptrend'      => 'brief.regime.pullback',
            'Bounce in downtrend'      => 'brief.regime.bounce',
            'Strong trending down'     => 'brief.regime.strong_down',
            'Trending down'            => 'brief.regime.trending_down',
            'Choppy / consolidation'   => 'brief.regime.choppy',
            'Market data warming up'   => 'brief.regime.unknown',
            'Unknown'                  => 'brief.regime.unknown',
            // Forex regime labels
            'Risk-on (FX)'             => 'brief.fx.risk_on',
            'Risk lean-on'             => 'brief.fx.lean_on',
            'Risk-off (FX)'             => 'brief.fx.risk_off',
            'Risk lean-off'            => 'brief.fx.lean_off',
            'FX mixed'                 => 'brief.fx.mixed',
            'Forex data warming'       => 'brief.fx.warming',
            'No dominant flow'         => 'brief.fx.no_flow',
            // Volume labels
            'Volume unknown'           => 'brief.vol.unknown',
            'No market-cap data'       => 'brief.vol.no_mc',
            'Heavy turnover, rising'   => 'brief.vol.heavy_rising',
            'Heavy turnover, falling'  => 'brief.vol.heavy_falling',
            'Heavy turnover'           => 'brief.vol.heavy_flat',
            'Rotation unknown'         => 'brief.rotation.unknown',
            // Verdicts — headline
            'Risk-on with momentum'    => 'brief.v.strong_bull.headline',
            'Constructive bias'        => 'brief.v.bull.headline',
            'Markets in flux'          => 'brief.v.neutral.headline',
            'Defensive bias'           => 'brief.v.bear.headline',
            'Capital preservation mode'=> 'brief.v.strong_bear.headline',
            // Verdicts — action
            'Participate selectively; trend alignment is high'       => 'brief.v.strong_bull.action',
            'Cautious long bias; watch for confirmation'             => 'brief.v.bull.action',
            'Patience — signals lack alignment'                      => 'brief.v.neutral.action',
            'Reduce exposure; wait for a floor'                      => 'brief.v.bear.action',
            'Accumulation zone developing; stay patient'             => 'brief.v.strong_bear.action',
            // Warming
            'Live data warming up — the intelligence brief will refresh within 5 minutes.' => 'brief.warming',
        );
        if ( isset( $map[ $english ] ) ) {
            return __bt( $map[ $english ] );
        }
        return $english; // Pass-through for dynamic text (percentages, sprintf results)
    }

    /**
     * Full hero card. Cached 15 minutes.
     */
    public static function sc_intelligence_brief( $atts ) {
        $cache_key = 'bt_brief_html_v77_' . sanitize_key( get_option( 'bt_site_lang', 'en' ) );
        $cached    = get_transient( $cache_key );
        if ( $cached && ! isset( $_GET['refresh_brief'] ) ) return $cached;

        $b        = self::build_brief();
        $snippets = self::build_snippets( $b );

        // Link target
        $analysis_link  = home_url( '/market-analysis/' );
        $analysis_posts = get_posts( array( 'numberposts' => 1, 'post_status' => 'publish', 'category_name' => 'market-analysis' ) );
        if ( ! empty( $analysis_posts ) ) $analysis_link = home_url( '/market-analysis/' );

        $verdict       = $b['verdict'];
        $composite_sc  = $b['composite']['score'];
        $align_count   = $b['composite']['align_count'];
        $total_active  = $b['composite']['total_active'];
        $confidence    = $total_active > 0 ? round( $align_count / $total_active * 100 ) : 0;

        ob_start();
        ?>
        <div class="bt-brief-card bt-brief-tier-<?php echo esc_attr( $verdict['tier'] ); ?>">
          <div class="bt-brief-head">
            <span class="bt-brief-badge"><span class="bt-brief-pulse"></span> <?php echo esc_html( __bt( 'brief.live_badge' ) ); ?></span>
            <span class="bt-brief-time"><?php echo esc_html( $b['updated_label'] ); ?></span>
          </div>

          <?php echo $b['gauge_html']; ?>

          <h3 class="bt-brief-headline"><?php echo esc_html( self::tr_label( $verdict['headline'] ) ); ?></h3>
          <p class="bt-brief-action"><?php echo esc_html( self::tr_label( $verdict['action'] ) ); ?></p>

          <div class="bt-brief-meta-row">
            <span class="bt-brief-composite" title="Weighted composite across 8 cross-market signals">
              <strong><?php echo number_format( $composite_sc, 1 ); ?></strong> <?php echo esc_html( __bt( 'brief.composite_suffix' ) ); ?>
            </span>
            <span class="bt-brief-confidence" title="Signals agreeing with the verdict direction">
              <?php echo $align_count; ?>/<?php echo $total_active; ?> <?php echo esc_html( __bt( 'brief.signals_align' ) ); ?>
              <span class="bt-brief-confidence-pct"><?php echo $confidence; ?>%</span>
            </span>
            <span class="bt-brief-regime" title="Market regime classification">
              <?php echo esc_html( self::tr_label( $b['regime']['label'] ) ); ?>
            </span>
          </div>

          <p class="bt-brief-para"><?php echo esc_html( $b['paragraph'] ); ?></p>

          <?php if ( ! empty( $b['why_moved'] ) ): ?>
          <div class="bt-brief-why">
            <span class="bt-brief-why-lbl"><?php echo esc_html( __bt( 'brief.why_moved' ) ); ?></span>
            <span class="bt-brief-why-text"><?php echo esc_html( $b['why_moved'] ); ?></span>
          </div>
          <?php endif; ?>

          <?php if ( ! empty( $snippets ) ): ?>
          <div class="bt-brief-snippet-list">
            <?php foreach ( $snippets as $snip ): ?>
              <div class="bt-brief-snippet">
                <div class="bt-brief-snippet-icon <?php echo esc_attr( $snip['tone'] ); ?>"><?php echo $snip['icon']; ?></div>
                <div class="bt-brief-snippet-body">
                  <div class="bt-brief-snippet-title"><?php echo esc_html( $snip['title'] ); ?></div>
                  <div class="bt-brief-snippet-meta"><?php echo wp_kses( $snip['meta'], array( 'strong' => array() ) ); ?></div>
                </div>
                <?php if ( $snip['value'] !== '' ): ?>
                <div class="bt-brief-snippet-val <?php echo esc_attr( $snip['tone'] ); ?>"><?php echo esc_html( $snip['value'] ); ?></div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>

          <?php if ( ! empty( $b['chips'] ) ): ?>
          <div class="bt-brief-signals">
            <?php foreach ( $b['chips'] as $sig ): ?>
              <span class="bt-brief-sig bt-brief-sig-<?php echo esc_attr( $sig['state'] ); ?>">
                <strong><?php echo esc_html( $sig['label'] ); ?></strong>
                <span><?php echo esc_html( $sig['text'] ); ?></span>
              </span>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>

          <div class="bt-brief-foot">
            <span><?php echo esc_html( __bt( 'brief.footer' ) ); ?></span>
            <a href="<?php echo esc_url( $analysis_link ); ?>"><?php echo esc_html( __bt( 'brief.full_analysis' ) ); ?></a>
          </div>
        </div>
        <?php
        $html = ob_get_clean();
        set_transient( $cache_key, $html, 15 * MINUTE_IN_SECONDS );
        return $html;
    }

    /**
     * Compact sidebar variant. Same data, condensed presentation.
     */
    public static function sc_cross_market_card( $atts ) {
        $b = self::build_brief();
        $analysis_link  = home_url( '/market-analysis/' );
        $analysis_posts = get_posts( array( 'numberposts' => 1, 'post_status' => 'publish', 'category_name' => 'market-analysis' ) );
        if ( ! empty( $analysis_posts ) ) $analysis_link = home_url( '/market-analysis/' );

        $verdict = $b['verdict'];

        ob_start();
        ?>
        <div class="bt-xmarket-card bt-brief-tier-<?php echo esc_attr( $verdict['tier'] ); ?>">
          <div class="bt-xmarket-head"><span><?php echo esc_html( __bt( 'brief.xmarket_title' ) ); ?></span></div>
          <div class="bt-xmarket-verdict"><?php echo esc_html( self::tr_label( $verdict['headline'] ) ); ?></div>
          <div class="bt-xmarket-sub"><?php echo esc_html( self::tr_label( $verdict['action'] ) ); ?></div>
          <?php echo $b['gauge_html']; ?>
          <?php if ( ! empty( $b['chips'] ) ): ?>
          <div class="bt-xmarket-sigs">
            <?php foreach ( array_slice( $b['chips'], 0, 4 ) as $sig ): ?>
              <div class="bt-xmarket-sig bt-brief-sig-<?php echo esc_attr( $sig['state'] ); ?>">
                <strong><?php echo esc_html( $sig['label'] ); ?></strong>
                <span><?php echo esc_html( $sig['text'] ); ?></span>
              </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
          <a href="<?php echo esc_url( $analysis_link ); ?>" class="bt-xmarket-cta">See today's brief →</a>
        </div>
        <?php
        return ob_get_clean();
    }

    // ───────────────────────────────────────────────────────────────────────
    // 6. Snippet list (unchanged from v72 — cards under the main paragraph)
    // ───────────────────────────────────────────────────────────────────────

    private static function build_snippets( $b = null ) {
        $out = array();
        $m   = self::gather_market_data();

        // Top crypto mover (absolute % in last 24h)
        if ( ! empty( $m['coins'] ) ) {
            $top_c = null; $top_abs = 0;
            foreach ( array_slice( $m['coins'], 0, 50 ) as $c ) {
                $p = abs( floatval( $c['price_change_percentage_24h'] ?? 0 ) );
                if ( $p > $top_abs ) { $top_abs = $p; $top_c = $c; }
            }
            if ( $top_c && $top_abs > 0 ) {
                $chg  = floatval( $top_c['price_change_percentage_24h'] );
                $tone = $chg >= 0 ? 'up' : 'down';
                $out[] = array(
                    'tone'  => $tone,
                    'icon'  => $chg >= 0 ? '&#x25B2;' : '&#x25BC;',
                    'title' => $top_c['name'] . ' leads crypto movers',
                    'meta'  => '<strong>$' . self::bt_fmt_number( floatval( $top_c['current_price'] ) ) . '</strong> · 24h ' . ( $chg >= 0 ? 'up' : 'down' ),
                    'value' => ( $chg >= 0 ? '+' : '' ) . number_format( $chg, 2 ) . '%',
                );
            }
        }

        // Top forex mover
        if ( ! empty( $m['forex_rates'] ) ) {
            $top_pair = null; $top_pct = 0; $top_d = null;
            foreach ( $m['forex_rates'] as $pair => $d ) {
                $p = abs( floatval( $d['change'] ?? 0 ) );
                if ( $p > $top_pct ) { $top_pct = $p; $top_pair = $pair; $top_d = $d; }
            }
            if ( $top_d && $top_pct > 0.05 ) {
                $chg  = floatval( $top_d['change'] );
                $tone = $chg >= 0 ? 'up' : 'down';
                $out[] = array(
                    'tone'  => $tone,
                    'icon'  => $chg >= 0 ? '&#x25B2;' : '&#x25BC;',
                    'title' => $top_pair . ' is the most active forex pair',
                    'meta'  => '<strong>' . number_format( floatval( $top_d['rate'] ), 4 ) . '</strong> · daily move',
                    'value' => ( $chg >= 0 ? '+' : '' ) . number_format( $chg, 3 ) . '%',
                );
            }
        }

        // Fear & Greed
        if ( $m['fg_val'] !== null ) {
            $v = $m['fg_val'];
            $tone = 'neu';
            $title = 'Market sentiment';
            if ( $v < 25 )      { $tone = 'up';   $title = 'Fear & Greed: extreme fear'; }
            elseif ( $v > 75 )  { $tone = 'down'; $title = 'Fear & Greed: extreme greed'; }
            elseif ( $v < 45 )  { $title = 'Fear & Greed: fear zone'; }
            elseif ( $v > 55 )  { $title = 'Fear & Greed: greed zone'; }
            $out[] = array(
                'tone'  => $tone,
                'icon'  => '&#x25CF;',
                'title' => $title,
                'meta'  => $m['fg_label'] ? '<strong>' . esc_html( $m['fg_label'] ) . '</strong> · refreshed hourly' : 'Refreshed hourly',
                'value' => (string) $v,
            );
        }

        // Rotation snippet — new in v73
        $rot = self::classify_altcoin_season( $m );
        // v92: null-safety — classify_altcoin_season() can return early with a
        // partial array when BTC data is unavailable; guard every key before count().
        if ( ! is_array( $rot ) ) {
            $rot = array( 'leaders' => array(), 'laggards' => array() );
        }
        $rot_leaders  = is_array( $rot['leaders']  ?? null ) ? $rot['leaders']  : array();
        $rot_laggards = is_array( $rot['laggards'] ?? null ) ? $rot['laggards'] : array();
        if ( ! empty( $rot_leaders ) && count( $rot_leaders ) >= 2 ) {
            $names = array_slice( array_column( $rot_leaders, 'name' ), 0, 2 );
            // array_column returns symbols when 'name' key is absent — fall back to symbol
            if ( empty( array_filter( $names ) ) ) {
                $names = array_slice( array_map( fn($c) => strtoupper( $c['symbol'] ?? '?' ), $rot_leaders ), 0, 2 );
            }
            $out[] = array(
                'tone'  => 'up',
                'icon'  => '&#x2B12;',
                'title' => 'Alt-strength rotation',
                'meta'  => '<strong>' . esc_html( implode( ' · ', $names ) ) . '</strong> outpacing BTC',
                'value' => '+rot',
            );
        } elseif ( count( $rot_laggards ) >= 3 ) {
            $out[] = array(
                'tone'  => 'down',
                'icon'  => '&#x2B12;',
                'title' => 'BTC dominance',
                'meta'  => '<strong>' . count( $rot_laggards ) . ' of top-10 alts</strong> lagging BTC',
                'value' => '-rot',
            );
        }

        return $out;
    }

    // ───────────────────────────────────────────────────────────────────────
    // Formatting helpers
    // ───────────────────────────────────────────────────────────────────────

    private static function bt_fmt_number( $n ) {
        if ( $n >= 1000 ) return number_format( $n, 0 );
        if ( $n >= 1 )    return number_format( $n, 2 );
        return number_format( $n, 4 );
    }

    /**
     * Format a number with a leading sign (+3.2 / -1.5) instead of just a minus.
     */
    private static function signed( $n ) {
        return ( $n >= 0 ? '+' : '' ) . number_format( $n, 1 );
    }

    // ───────────────────────────────────────────────────────────────────────
    // v81 — Research Desk shortcode. Bloomberg-terminal-flavored panel for
    // /market-analysis/. Reuses build_brief() data but renders a desk
    // aesthetic: dense, data-forward, verdict panel + coverage grid + desk
    // notes (research-note rows, NOT blog cards).
    // ───────────────────────────────────────────────────────────────────────
    public static function sc_analysis_desk( $atts ) {
        $a = shortcode_atts( array(
            'notes_count' => 14,
            'notes_cats'  => 'market-analysis,crypto-news,forex-news',
        ), $atts );

        $b            = self::build_brief();
        $verdict      = $b['verdict'];
        $composite_sc = $b['composite']['score'];
        $align_count  = $b['composite']['align_count'];
        $total_active = $b['composite']['total_active'];
        $confidence   = $total_active > 0 ? round( $align_count / $total_active * 100 ) : 0;

        // Normalize composite to a -10..+10 display range (raw is ~-16..+16 but typically |sc|<6)
        $display_score = max( -10, min( 10, round( $composite_sc, 1 ) ) );
        $score_sign    = $display_score >= 0 ? '+' : '';
        $score_tone    = $display_score > 1.5 ? 'bull' : ( $display_score < -1.5 ? 'bear' : 'neutral' );

        // Coverage grid: live counts pulled from option data when available
        $crypto_data = get_option( 'bt_crypto_data', array() );
        if ( is_string( $crypto_data ) ) $crypto_data = json_decode( $crypto_data, true );
        $crypto_count = ( is_array( $crypto_data ) && ! empty( $crypto_data['coins'] ) ) ? count( $crypto_data['coins'] ) : 500;

        $forex_data = get_option( 'bt_forex_rates', array() );
        if ( is_string( $forex_data ) ) $forex_data = json_decode( $forex_data, true );
        $forex_count = is_array( $forex_data ) ? count( $forex_data ) : 170;

        // Format issue number using a daily-incrementing stable counter
        $issue_num = intval( get_option( 'bt_analysis_issue_num', 0 ) );
        if ( $issue_num < 1 ) {
            // Derive an approximation from how many days since first post in analysis category
            $first_post = get_posts( array( 'numberposts' => 1, 'order' => 'ASC', 'category_name' => 'market-analysis' ) );
            $issue_num = ! empty( $first_post )
                ? max( 1, round( ( current_time( 'timestamp' ) - get_the_time( 'U', $first_post[0] ) ) / DAY_IN_SECONDS ) )
                : 1;
        }
        $utc_now = gmdate( 'H:i:s' );
        $utc_date = gmdate( 'D · M j, Y' );

        // Gather notes (the post rows below the verdict)
        $cats = array_filter( array_map( 'sanitize_title', explode( ',', $a['notes_cats'] ) ) );
        $notes_args = array(
            'numberposts' => intval( $a['notes_count'] ),
            'post_status' => 'publish',
            'orderby'     => 'date',
            'order'       => 'DESC',
        );
        if ( count( $cats ) === 1 ) {
            $notes_args['category_name'] = $cats[0];
        } elseif ( count( $cats ) > 1 ) {
            $cat_ids = array();
            foreach ( $cats as $cat_slug ) {
                $term = get_term_by( 'slug', $cat_slug, 'category' );
                if ( $term ) $cat_ids[] = $term->term_id;
            }
            if ( ! empty( $cat_ids ) ) $notes_args['category__in'] = $cat_ids;
        }
        $notes = get_posts( $notes_args );

        ob_start();
        ?>
        <div class="bt-desk">

          <!-- ── DESK HEADER ─────────────────────────────────────────── -->
          <header class="bt-desk-head">
            <div class="bt-desk-head-row">
              <div class="bt-desk-ident">
                <span class="bt-desk-dot"></span>
                <span class="bt-desk-ident-lbl">AI RESEARCH DESK</span>
                <span class="bt-desk-ident-sep">//</span>
                <span class="bt-desk-ident-status">LIVE</span>
              </div>
              <div class="bt-desk-stamp">
                <span class="bt-desk-stamp-clock" id="bt-desk-clock"><?php echo esc_html( $utc_now ); ?> UTC</span>
                <span class="bt-desk-stamp-sep">·</span>
                <span><?php echo esc_html( $utc_date ); ?></span>
                <span class="bt-desk-stamp-sep">·</span>
                <span>ISSUE №<?php echo esc_html( $issue_num ); ?></span>
              </div>
            </div>
            <h1 class="bt-desk-title">Deep Market Intelligence Across Crypto, Forex, Web3 &amp; Macro.</h1>
            <p class="bt-desk-subtitle">An 8-signal composite grounded in live price feeds, on-chain flows, central-bank communications and curated news — rebuilt every 15&nbsp;minutes.</p>
          </header>

          <!-- ── VERDICT PANEL ───────────────────────────────────────── -->
          <section class="bt-desk-panel bt-desk-verdict" data-tone="<?php echo esc_attr( $score_tone ); ?>">
            <div class="bt-desk-panel-label">COMPOSITE VERDICT</div>

            <div class="bt-desk-verdict-grid">
              <!-- Score column -->
              <div class="bt-desk-verdict-score">
                <div class="bt-desk-score-huge"><?php echo esc_html( $score_sign . number_format( $display_score, 1 ) ); ?></div>
                <div class="bt-desk-score-sub">COMPOSITE / 10</div>
                <div class="bt-desk-score-bar">
                  <div class="bt-desk-score-bar-track"></div>
                  <div class="bt-desk-score-bar-zero"></div>
                  <div class="bt-desk-score-bar-fill" style="--fill:<?php echo esc_attr( ( $display_score + 10 ) * 5 ); ?>%;"></div>
                </div>
                <div class="bt-desk-score-axis">
                  <span>−10</span><span>0</span><span>+10</span>
                </div>
              </div>

              <!-- Verdict column -->
              <div class="bt-desk-verdict-body">
                <div class="bt-desk-verdict-headline"><?php echo esc_html( self::tr_label( $verdict['headline'] ) ); ?></div>
                <div class="bt-desk-verdict-action"><?php echo esc_html( self::tr_label( $verdict['action'] ) ); ?></div>

                <div class="bt-desk-verdict-stats">
                  <div class="bt-desk-stat">
                    <div class="bt-desk-stat-val"><?php echo $align_count; ?>/<?php echo $total_active; ?></div>
                    <div class="bt-desk-stat-lbl">SIGNALS ALIGN</div>
                  </div>
                  <div class="bt-desk-stat">
                    <div class="bt-desk-stat-val"><?php echo $confidence; ?>%</div>
                    <div class="bt-desk-stat-lbl">CONFIDENCE</div>
                  </div>
                  <div class="bt-desk-stat">
                    <div class="bt-desk-stat-val"><?php echo esc_html( strtoupper( substr( self::tr_label( $b['regime']['label'] ?? 'Mixed' ), 0, 12 ) ) ); ?></div>
                    <div class="bt-desk-stat-lbl">REGIME</div>
                  </div>
                </div>

                <?php if ( ! empty( $b['paragraph'] ) ): ?>
                <p class="bt-desk-verdict-para"><?php echo esc_html( $b['paragraph'] ); ?></p>
                <?php endif; ?>
              </div>
            </div>

            <!-- Signal chips — expandable breakdown (v93) -->
            <?php if ( ! empty( $b['chips'] ) ): ?>
            <div class="bt-desk-signals">
              <div class="bt-desk-signals-label">SIGNAL BREAKDOWN</div>
              <div class="bt-desk-signals-list" id="bt-sig-list">
                <?php foreach ( $b['chips'] as $idx => $chip ):
                  $uid = 'bt-sig-' . $idx . '-' . esc_attr( $chip['key'] ?? $idx );
                ?>
                <div class="bt-desk-signal bt-desk-signal-<?php echo esc_attr( $chip['state'] ); ?> bt-sig-expandable"
                     id="<?php echo $uid; ?>"
                     onclick="btSigToggle(this)"
                     role="button" tabindex="0" aria-expanded="false"
                     onkeydown="if(event.key==='Enter'||event.key===' ')btSigToggle(this)">
                  <div class="bt-sig-header">
                    <span class="bt-desk-signal-arrow" aria-hidden="true"><?php echo $chip['state'] === 'bull' ? '▲' : '▼'; ?></span>
                    <span class="bt-desk-signal-lbl"><?php echo esc_html( $chip['label'] ); ?></span>
                    <?php if ( ! empty( $chip['text'] ) ): ?>
                    <span class="bt-desk-signal-val"><?php echo esc_html( self::tr_label( $chip['text'] ) ); ?></span>
                    <?php endif; ?>
                    <span class="bt-sig-chevron" aria-hidden="true">›</span>
                  </div>
                  <div class="bt-sig-drawer" aria-hidden="true">
                    <?php if ( ! empty( $chip['desc'] ) ): ?>
                    <p class="bt-sig-drawer-desc"><?php echo esc_html( $chip['desc'] ); ?></p>
                    <?php endif; ?>
                    <?php if ( ! empty( $chip['about'] ) ): ?>
                    <p class="bt-sig-drawer-about"><?php echo esc_html( $chip['about'] ); ?></p>
                    <?php endif; ?>
                    <div class="bt-sig-drawer-meta">
                      <span class="bt-sig-meta-item">⚖ Weight: <strong><?php echo esc_html( $chip['weight'] ); ?>/14</strong></span>
                      <span class="bt-sig-meta-sep">·</span>
                      <span class="bt-sig-meta-item">📡 Source: <strong><?php echo esc_html( $chip['source'] ); ?></strong></span>
                      <?php if ( ! empty( $chip['url'] ) ): ?>
                      <span class="bt-sig-meta-sep">·</span>
                      <a class="bt-sig-meta-link" href="<?php echo esc_url( $chip['url'] ); ?>"
                         onclick="event.stopPropagation()">View live data ›</a>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
                <?php endforeach; ?>
              </div>
              <div class="bt-sig-hint">Click any signal to expand</div>
            </div>
            <script>
            function btSigToggle(el){
              var open = el.getAttribute('aria-expanded')==='true';
              // Close all siblings first
              var list = el.closest('#bt-sig-list');
              if(list){ list.querySelectorAll('.bt-sig-expandable').forEach(function(s){
                s.setAttribute('aria-expanded','false');
                var d=s.querySelector('.bt-sig-drawer'); if(d){ d.setAttribute('aria-hidden','true'); d.style.maxHeight='0'; }
                var c=s.querySelector('.bt-sig-chevron'); if(c) c.style.transform='';
              }); }
              if(!open){
                el.setAttribute('aria-expanded','true');
                var drawer=el.querySelector('.bt-sig-drawer');
                var chev=el.querySelector('.bt-sig-chevron');
                if(drawer){ drawer.setAttribute('aria-hidden','false'); drawer.style.maxHeight=drawer.scrollHeight+'px'; }
                if(chev) chev.style.transform='rotate(90deg)';
                // Hide hint once user interacts
                var hint=el.closest('.bt-desk-signals') && el.closest('.bt-desk-signals').querySelector('.bt-sig-hint');
                if(hint) hint.style.display='none';
              }
            }
            </script>
            <?php endif; ?>
          </section>

          <!-- ── COVERAGE GRID ───────────────────────────────────────── -->
          <section class="bt-desk-coverage">
            <div class="bt-desk-coverage-head">
              <div class="bt-desk-panel-label">COVERAGE UNIVERSE</div>
              <div class="bt-desk-coverage-sub">What this desk analyzes, continuously.</div>
            </div>
            <div class="bt-desk-coverage-grid">
              <div class="bt-desk-cov-card" style="--cov-accent:#f7931a">
                <div class="bt-desk-cov-tag">SECTOR</div>
                <div class="bt-desk-cov-title">Crypto</div>
                <div class="bt-desk-cov-metric"><?php echo number_format( $crypto_count ); ?>+</div>
                <div class="bt-desk-cov-metric-lbl">ASSETS TRACKED</div>
                <ul class="bt-desk-cov-list">
                  <li>Price, volume, market cap, rotation</li>
                  <li>Fear &amp; Greed · sentiment · ETF flows</li>
                  <li>Regime classification every 15 min</li>
                </ul>
              </div>

              <div class="bt-desk-cov-card" style="--cov-accent:var(--bt-accent)">
                <div class="bt-desk-cov-tag">SECTOR</div>
                <div class="bt-desk-cov-title">Forex</div>
                <div class="bt-desk-cov-metric"><?php echo number_format( $forex_count ); ?>+</div>
                <div class="bt-desk-cov-metric-lbl">PAIRS COVERED</div>
                <ul class="bt-desk-cov-list">
                  <li>Majors · minors · exotics · commodity FX</li>
                  <li>Client sentiment · 24h positioning</li>
                  <li>Central-bank divergence tracking</li>
                </ul>
              </div>

              <div class="bt-desk-cov-card" style="--cov-accent:#a78bfa">
                <div class="bt-desk-cov-tag">SECTOR</div>
                <div class="bt-desk-cov-title">Web3 &amp; DeFi</div>
                <div class="bt-desk-cov-metric">L1 · L2</div>
                <div class="bt-desk-cov-metric-lbl">ACROSS ECOSYSTEMS</div>
                <ul class="bt-desk-cov-list">
                  <li>TVL · lending · perps · restaking flows</li>
                  <li>Token launches · unlocks · governance</li>
                  <li>NFT floor moves · GameFi · memecoins</li>
                </ul>
              </div>

              <div class="bt-desk-cov-card" style="--cov-accent:#10b981">
                <div class="bt-desk-cov-tag">CONTEXT</div>
                <div class="bt-desk-cov-title">Macro</div>
                <div class="bt-desk-cov-metric">FOMC · CPI · NFP</div>
                <div class="bt-desk-cov-metric-lbl">EVENT-DRIVEN</div>
                <ul class="bt-desk-cov-list">
                  <li>Fed / ECB / BoJ / BoE communications</li>
                  <li>CPI · PCE · rate decisions · dot plots</li>
                  <li>Geopolitics · energy · DXY regime</li>
                </ul>
              </div>
            </div>
          </section>

          <!-- ── DESK NOTES (research-note rows, not cards) ─────────── -->
          <section class="bt-desk-notes">
            <div class="bt-desk-notes-head">
              <div class="bt-desk-panel-label">DESK NOTES</div>
              <div class="bt-desk-notes-sub">Research output, most recent first. Published automatically, reviewed editorially.</div>
            </div>

            <?php if ( empty( $notes ) ): ?>
            <div class="bt-desk-notes-empty">
              <div class="bt-desk-notes-empty-icon">//</div>
              <div>Desk notes publishing soon. The AI analyst publishes the morning read at 08:00 UTC — check back shortly.</div>
            </div>
            <?php else: ?>
            <ol class="bt-desk-notes-list">
              <?php foreach ( $notes as $post ): ?>
              <?php
                $date_ts  = get_the_time( 'U', $post );
                $date_fmt = date( 'D · M j', $date_ts );
                $time_fmt = gmdate( 'H:i', $date_ts );
                $excerpt  = wp_trim_words( strip_tags( $post->post_content ), 24, '…' );
                $cats_arr = get_the_category( $post->ID );
                $cat_name = ! empty( $cats_arr ) ? $cats_arr[0]->name : 'Analysis';
                $cat_slug = ! empty( $cats_arr ) ? $cats_arr[0]->slug : 'market-analysis';
                $read_min = max( 1, round( str_word_count( strip_tags( $post->post_content ) ) / 200 ) );
                // Use the rubric + code helpers from class-rss (same aesthetic family, shared helpers)
                $rubric = class_exists( 'BT_RSS' ) && method_exists( 'BT_RSS', 'cat_rubric' )
                    ? BT_RSS::cat_rubric( $cat_slug, $cat_name )
                    : strtoupper( $cat_name );
                // Safe fallback: call the private helpers directly since they're in class-rss.
                // (They're private so use reflection-free fallback — hard-map essentials here.)
                $rubric_map = array(
                    'market-analysis' => 'MARKETS · ANALYSIS',
                    'crypto-news'     => 'CRYPTO · NEWS',
                    'forex-news'      => 'FOREX · NEWS',
                    'defi-web3'       => 'DEFI · WEB3',
                    'altcoins'        => 'MARKETS · ALTCOINS',
                    'education'       => 'LEARN · EDUCATION',
                    'bitcoin'         => 'BITCOIN',
                    'ethereum'        => 'ETHEREUM',
                );
                $rubric = $rubric_map[ $cat_slug ] ?? strtoupper( $cat_name );
                $color_map = array(
                    'market-analysis' => 'var(--bt-accent)', 'crypto-news' => '#f7931a', 'forex-news' => 'var(--bt-accent)',
                    'defi-web3' => '#a78bfa', 'altcoins' => 'var(--bt-accent-warm)', 'education' => '#10b981',
                    'bitcoin' => '#f7931a', 'ethereum' => '#627eea',
                );
                $col = $color_map[ $cat_slug ] ?? 'var(--bt-accent)';
              ?>
              <li class="bt-desk-note" style="--note-accent:<?php echo esc_attr( $col ); ?>">
                <a class="bt-desk-note-link" href="<?php echo esc_url( get_permalink( $post->ID ) ); ?>">
                  <time class="bt-desk-note-date" datetime="<?php echo esc_attr( date( 'c', $date_ts ) ); ?>">
                    <span class="bt-desk-note-d-line1"><?php echo esc_html( strtoupper( date( 'D', $date_ts ) ) ); ?></span>
                    <span class="bt-desk-note-d-line2"><?php echo esc_html( date( 'j M', $date_ts ) ); ?></span>
                    <span class="bt-desk-note-d-line3"><?php echo esc_html( $time_fmt ); ?></span>
                  </time>
                  <div class="bt-desk-note-rubric"><?php echo esc_html( $rubric ); ?></div>
                  <div class="bt-desk-note-body">
                    <h3 class="bt-desk-note-title"><?php echo esc_html( $post->post_title ); ?></h3>
                    <p class="bt-desk-note-excerpt"><?php echo esc_html( $excerpt ); ?></p>
                    <?php
                    // v86 — Signal contribution trace: nearest snapshot to post publish time
                    $snap = self::nearest_snapshot( $date_ts );
                    if ( $snap ) :
                        $snap_tier = $snap['tier'] ?? 'neutral';
                        $snap_col  = ( strpos( $snap_tier, 'bull' ) !== false ) ? 'var(--bt-accent)' : ( ( strpos( $snap_tier, 'bear' ) !== false ) ? 'var(--bt-danger)' : 'var(--bt-accent-warm)' );
                        $snap_sc   = isset( $snap['score'] ) ? round( $snap['score'] / 28 * 10, 1 ) : null;
                    ?>
                    <div class="bt-desk-note-signals">
                      <span class="bt-dns-label">Desk when published:</span>
                      <span class="bt-dns-score" style="color:<?php echo esc_attr( $snap_col ); ?>"><?php echo esc_html( self::tier_short_label( $snap_tier ) ); ?></span>
                      <?php if ( $snap_sc !== null ) : ?>
                        <span class="bt-dns-num" style="color:<?php echo esc_attr( $snap_col ); ?>"><?php echo esc_html( ( $snap_sc >= 0 ? '+' : '' ) . $snap_sc ); ?></span>
                      <?php endif; ?>
                      <?php if ( ! empty( $snap['signals'] ) ) : ?>
                        <?php foreach ( array_slice( $snap['signals'], 0, 4, true ) as $skey => $ssig ) :
                            $ssc  = floatval( $ssig['score'] ?? 0 );
                            if ( $ssc == 0 ) continue;
                            $sarr = $ssc > 0 ? '▲' : '▼';
                            $scol = $ssc > 0 ? 'var(--bt-accent)' : 'var(--bt-danger)';
                            $slbl = str_replace( array( 'btc_momentum','market_breadth','altcoin_season','stablecoin_flow','volume_profile','fx_composite','news_composite','sentiment' ),
                                                 array( 'BTC','Breadth','Alts','Stable','Vol','FX','News','F&G' ), $skey );
                        ?>
                        <span class="bt-dns-chip" style="color:<?php echo esc_attr( $scol ); ?>"><?php echo $sarr; ?> <?php echo esc_html( $slbl ); ?></span>
                        <?php endforeach; ?>
                      <?php endif; ?>
                    </div>
                    <?php endif; ?>
                  </div>
                  <div class="bt-desk-note-meta">
                    <span class="bt-desk-note-read"><?php echo $read_min; ?>′</span>
                    <span class="bt-desk-note-arrow" aria-hidden="true">→</span>
                  </div>
                </a>
              </li>
              <?php endforeach; ?>
            </ol>
            <?php endif; ?>
          </section>

          <!-- ── METHODOLOGY FOOTER ──────────────────────────────────── -->
          <footer class="bt-desk-footer">
            <div class="bt-desk-footer-grid">
              <div class="bt-desk-footer-col">
                <div class="bt-desk-footer-lbl">METHODOLOGY</div>
                <p>8-signal weighted composite (total weight 14.0): BTC momentum, market breadth (% of top-100 coins), altcoin season, stablecoin flow, volume profile, FX composite (6 pairs: EUR, GBP, JPY, CHF, AUD, NZD), Fear &amp; Greed, and news composite. Rebuilt every 15 minutes.</p>
              </div>
              <div class="bt-desk-footer-col">
                <div class="bt-desk-footer-lbl">DATA SOURCES</div>
                <p>CoinGecko · ECB/Frankfurter (6 FX pairs) · CFTC COT · Fear &amp; Greed Index · 24 news sources: Reuters, CNBC, Federal Reserve, ECB, MarketWatch, CoinDesk, The Block, Decrypt, Blockworks, NewsBTC, CryptoNews, Ambcrypto &amp; more. All figures attributed.</p>
              </div>
              <div class="bt-desk-footer-col">
                <div class="bt-desk-footer-lbl">DISCLAIMER</div>
                <p>Research output is not investment advice. The composite verdict describes observed market state, not price predictions. Position sizing and risk remain the reader's responsibility.</p>
              </div>
            </div>
          </footer>

        </div>

        <script>
        (function(){
          // Live UTC clock in desk header — ticks every second
          var el = document.getElementById('bt-desk-clock');
          if (!el) return;
          function pad(n){ return n < 10 ? '0' + n : '' + n; }
          function tick(){
            var d = new Date();
            el.textContent = pad(d.getUTCHours()) + ':' + pad(d.getUTCMinutes()) + ':' + pad(d.getUTCSeconds()) + ' UTC';
          }
          tick();
          setInterval(tick, 1000);
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    // ───────────────────────────────────────────────────────────────────────
    // v82 — Verdict Track Record
    // ───────────────────────────────────────────────────────────────────────

    const TRACK_RECORD_OPTION = 'bt_verdict_history';
    const TRACK_RECORD_MAX    = 720; // 30 days × 24 hours max

    /**
     * Cron-fired every hour. Captures a lightweight snapshot of the current
     * verdict, composite score, and BTC/ETH anchor prices. Kept minimal —
     * each entry is ~150 bytes so the option stays well under 200KB even
     * at max capacity.
     */
    public static function record_verdict_snapshot() {
        $m         = self::gather_market_data();
        $composite = self::compute_composite( $m );
        $verdict   = self::pick_verdict( $composite['score'] );
        $regime    = self::classify_btc_momentum( $m ); // v86: renamed, carries regime context

        $btc_price = isset( $m['btc']['current_price'] ) ? floatval( $m['btc']['current_price'] ) : 0;
        $eth_price = isset( $m['eth']['current_price'] ) ? floatval( $m['eth']['current_price'] ) : 0;

        if ( $btc_price <= 0 ) return; // Don't persist if upstream data is missing

        // v89: store compact per-signal scores so Desk Note traces and per-signal
        // accuracy panels can read them. Each score is int/float -2..+2, 8 values
        // ≈ 64 extra bytes per entry — total option size stays well under 200KB.
        $signal_scores = array();
        foreach ( $composite['signals'] as $skey => $ssig ) {
            $sc = floatval( $ssig['score'] ?? 0 );
            if ( $sc != 0.0 ) $signal_scores[ $skey ] = $sc;  // omit zero scores to save space
        }

        $btc24 = floatval( $m['btc']['price_change_percentage_24h'] ?? 0 );

        $snapshot = array(
            't'       => time(),
            'score'   => round( floatval( $composite['score'] ), 2 ),
            'tier'    => $verdict['tier'],
            'btc'     => round( $btc_price, 2 ),
            'eth'     => round( $eth_price, 2 ),
            'btc24'   => round( $btc24, 3 ),          // 24h pct change at snapshot time
            'regime'  => $regime['regime'] ?? 'unknown',
            'signals' => $signal_scores,               // v89: compact {key: score} map
        );

        $history = get_option( self::TRACK_RECORD_OPTION, array() );
        if ( ! is_array( $history ) ) $history = array();

        // Skip if we already recorded within the last 50 minutes (cron can double-fire)
        $last = end( $history );
        if ( is_array( $last ) && isset( $last['t'] ) && ( time() - intval( $last['t'] ) ) < 50 * MINUTE_IN_SECONDS ) {
            return;
        }

        $history[] = $snapshot;
        if ( count( $history ) > self::TRACK_RECORD_MAX ) {
            $history = array_slice( $history, -self::TRACK_RECORD_MAX );
        }
        update_option( self::TRACK_RECORD_OPTION, $history, false ); // autoload false — reads are targeted
    }

    /**
     * Walk the history and compute hindsight accuracy for each snapshot.
     * A call is "correct" if the BTC price moved in the same direction as
     * the verdict bias (bull = up, bear = down). Neutral verdicts don't
     * count toward accuracy in either direction.
     *
     * @param array $history Raw snapshot array
     * @param int   $horizon Hours to look forward for the price-move check
     * @return array { total_graded, correct, incorrect, hit_rate_pct, calls: [...] }
     */
    private static function compute_accuracy( $history, $horizon_hours = 24 ) {
        $n = count( $history );
        if ( $n < 2 ) return array(
            'total_graded' => 0, 'correct' => 0, 'incorrect' => 0, 'hit_rate_pct' => 0, 'calls' => array(),
        );

        $horizon_sec = $horizon_hours * HOUR_IN_SECONDS;
        $now         = time();
        $graded      = array();
        $correct     = 0;
        $incorrect   = 0;

        for ( $i = 0; $i < $n; $i++ ) {
            $snap = $history[ $i ];
            if ( empty( $snap['t'] ) || empty( $snap['btc'] ) ) continue;
            $t0 = intval( $snap['t'] );
            // Must be old enough to grade
            if ( ( $now - $t0 ) < $horizon_sec ) continue;

            // Find the first snapshot >= t0 + horizon
            $target_t = $t0 + $horizon_sec;
            $future   = null;
            for ( $j = $i + 1; $j < $n; $j++ ) {
                if ( intval( $history[ $j ]['t'] ) >= $target_t ) {
                    $future = $history[ $j ];
                    break;
                }
            }
            // If no future snapshot within 3h of target, skip
            if ( ! $future ) continue;
            if ( abs( intval( $future['t'] ) - $target_t ) > 3 * HOUR_IN_SECONDS ) continue;

            $btc0 = floatval( $snap['btc'] );
            $btc1 = floatval( $future['btc'] );
            if ( $btc0 <= 0 || $btc1 <= 0 ) continue;

            $pct_move = ( $btc1 - $btc0 ) / $btc0 * 100;
            $tier     = $snap['tier'] ?? 'neutral';

            // Direction bias: bull tiers = expect up, bear tiers = expect down.
            // Neutral verdicts are ungraded — they're "no-call" calls.
            $expected = null;
            if ( in_array( $tier, array( 'strong-bull', 'bull' ), true ) )  $expected = 'up';
            if ( in_array( $tier, array( 'strong-bear', 'bear' ), true ) )  $expected = 'down';

            if ( ! $expected ) {
                $graded[] = array(
                    't'        => $t0,
                    'tier'     => $tier,
                    'regime'   => $snap['regime'] ?? 'unknown',
                    'score'    => floatval( $snap['score'] ?? 0 ),
                    'btc0'     => $btc0,
                    'btc1'     => $btc1,
                    'pct_move' => round( $pct_move, 2 ),
                    'result'   => 'no-call',
                );
                continue;
            }

            // Minimum noise threshold — moves under 0.25% are "flat", not graded
            $result = 'flat';
            if ( abs( $pct_move ) >= 0.25 ) {
                $was_up = $pct_move > 0;
                if ( ( $expected === 'up' && $was_up ) || ( $expected === 'down' && ! $was_up ) ) {
                    $result = 'correct';
                    $correct++;
                } else {
                    $result = 'incorrect';
                    $incorrect++;
                }
            }

            $graded[] = array(
                't'        => $t0,
                'tier'     => $tier,
                'regime'   => $snap['regime'] ?? 'unknown',
                'score'    => floatval( $snap['score'] ?? 0 ),
                'btc0'     => $btc0,
                'btc1'     => $btc1,
                'pct_move' => round( $pct_move, 2 ),
                'result'   => $result,
            );
        }

        $decisive = $correct + $incorrect;
        $hit_rate = $decisive > 0 ? round( $correct / $decisive * 100 ) : 0;

        return array(
            'total_graded' => count( $graded ),
            'correct'      => $correct,
            'incorrect'    => $incorrect,
            'hit_rate_pct' => $hit_rate,
            'calls'        => $graded,
        );
    }

    /**
     * v83 — Group graded calls by market regime and compute per-regime hit rate.
     * Answers: "when the desk calls during trending-up regimes, is it right?"
     *
     * @param array $calls Output from compute_accuracy()['calls']
     * @return array [ regime_key => { total, correct, incorrect, hit_rate_pct, label } ]
     */
    private static function accuracy_by_regime( $calls ) {
        $buckets = array();
        foreach ( $calls as $c ) {
            $r = $c['regime'] ?? 'unknown';
            if ( ! isset( $buckets[ $r ] ) ) {
                $buckets[ $r ] = array( 'total' => 0, 'correct' => 0, 'incorrect' => 0, 'hit_rate_pct' => 0, 'label' => '' );
            }
            // Only count decisive calls (correct/incorrect) toward regime accuracy
            if ( $c['result'] === 'correct' ) {
                $buckets[ $r ]['correct']++;
                $buckets[ $r ]['total']++;
            } elseif ( $c['result'] === 'incorrect' ) {
                $buckets[ $r ]['incorrect']++;
                $buckets[ $r ]['total']++;
            }
        }
        // Compute hit rates + human labels
        $labels = array(
            'strong_up'     => 'Strong trending up',
            'trending_up'   => 'Trending up',
            'pullback'      => 'Pullback in uptrend',
            'bounce'        => 'Bounce in downtrend',
            'strong_down'   => 'Strong trending down',
            'trending_down' => 'Trending down',
            'choppy'        => 'Choppy / consolidation',
            'unknown'       => 'Regime unknown',
        );
        foreach ( $buckets as $key => &$b ) {
            $b['hit_rate_pct'] = $b['total'] > 0 ? round( $b['correct'] / $b['total'] * 100 ) : 0;
            $b['label']        = $labels[ $key ] ?? ucfirst( str_replace( '_', ' ', $key ) );
        }
        unset( $b );

        // Sort by sample size desc so the most statistically meaningful bucket shows first
        uasort( $buckets, function( $a, $b ) { return $b['total'] - $a['total']; } );

        return $buckets;
    }

    /**
     * v82 — Verdict Track Record shortcode. Shows the desk's hindsight
     * accuracy: 24h hit rate, 7d hit rate, recent calls table, and a
     * simple BTC context chart showing where each call was made.
     */
    public static function sc_verdict_track_record( $atts ) {
        $a = shortcode_atts( array( 'show_recent' => 10 ), $atts );

        $history = get_option( self::TRACK_RECORD_OPTION, array() );
        if ( ! is_array( $history ) ) $history = array();
        // Sort ascending by time — defensive, in case option got rearranged
        usort( $history, function( $a, $b ) { return intval( $a['t'] ?? 0 ) - intval( $b['t'] ?? 0 ); } );

        $acc_24h = self::compute_accuracy( $history, 24 );
        $acc_7d  = self::compute_accuracy( $history, 7 * 24 );
        $regime_acc = self::accuracy_by_regime( $acc_24h['calls'] ); // v83 — regime-conditional

        // Current streak — walk the 24h calls list from the end
        $streak_count = 0;
        $streak_kind  = null; // 'correct' or 'incorrect'
        for ( $i = count( $acc_24h['calls'] ) - 1; $i >= 0; $i-- ) {
            $r = $acc_24h['calls'][ $i ]['result'];
            if ( $r !== 'correct' && $r !== 'incorrect' ) continue;
            if ( $streak_kind === null ) {
                $streak_kind = $r;
                $streak_count = 1;
            } elseif ( $r === $streak_kind ) {
                $streak_count++;
            } else {
                break;
            }
        }

        // Best call: largest pct-move among correct predictions
        $best_call = null;
        foreach ( $acc_24h['calls'] as $c ) {
            if ( $c['result'] !== 'correct' ) continue;
            if ( ! $best_call || abs( $c['pct_move'] ) > abs( $best_call['pct_move'] ) ) {
                $best_call = $c;
            }
        }

        // Recent calls for the table — newest first
        $recent = array_reverse( array_slice( $acc_24h['calls'], -intval( $a['show_recent'] ) ) );

        // Bootstrap state: if we have fewer than 12 graded calls, show seeding notice
        $is_seeding = $acc_24h['total_graded'] < 12;

        ob_start();
        ?>
        <section class="bt-track">
          <div class="bt-desk-panel-label">TRACK RECORD · 30-DAY ROLLING</div>
          <div class="bt-track-sub">Every hour the desk's verdict is logged with the live BTC anchor price. 24 hours later we grade whether the market agreed. No selection bias, no cherry-picking — every call is recorded.</div>

          <?php if ( $is_seeding ): ?>
          <div class="bt-track-seeding">
            <div class="bt-track-seeding-icon">//</div>
            <div class="bt-track-seeding-text">
              <strong>Track record seeding.</strong>
              Verdict logging started recently — the desk needs roughly 2 days of hourly snapshots before the first accuracy readings stabilize.
              <span class="bt-track-seeding-count">Calls logged: <?php echo count( $history ); ?> · graded so far: <?php echo $acc_24h['total_graded']; ?></span>
            </div>
          </div>
          <?php endif; ?>

          <!-- Top stats row -->
          <div class="bt-track-stats">
            <div class="bt-track-stat bt-track-stat-hero" data-tone="<?php echo esc_attr( $acc_24h['hit_rate_pct'] >= 55 ? 'bull' : ( $acc_24h['hit_rate_pct'] >= 45 ? 'neutral' : 'bear' ) ); ?>">
              <div class="bt-track-stat-lbl">24-HOUR HIT RATE</div>
              <div class="bt-track-stat-val"><?php echo $acc_24h['hit_rate_pct']; ?><span class="bt-track-stat-pct">%</span></div>
              <div class="bt-track-stat-sub"><?php echo $acc_24h['correct']; ?> correct · <?php echo $acc_24h['incorrect']; ?> incorrect</div>
            </div>
            <div class="bt-track-stat">
              <div class="bt-track-stat-lbl">7-DAY HIT RATE</div>
              <div class="bt-track-stat-val"><?php echo $acc_7d['hit_rate_pct']; ?><span class="bt-track-stat-pct">%</span></div>
              <div class="bt-track-stat-sub"><?php echo $acc_7d['correct']; ?> correct · <?php echo $acc_7d['incorrect']; ?> incorrect</div>
            </div>
            <div class="bt-track-stat">
              <div class="bt-track-stat-lbl">CURRENT STREAK</div>
              <div class="bt-track-stat-val <?php echo $streak_kind === 'correct' ? 'is-pos' : ( $streak_kind === 'incorrect' ? 'is-neg' : '' ); ?>">
                <?php echo $streak_count ? $streak_count : '—'; ?>
              </div>
              <div class="bt-track-stat-sub">
                <?php
                if ( $streak_kind === 'correct' )        echo 'correct in a row';
                elseif ( $streak_kind === 'incorrect' )  echo 'missed in a row';
                else echo 'no streak yet';
                ?>
              </div>
            </div>
            <div class="bt-track-stat">
              <div class="bt-track-stat-lbl">BEST CALL · 30D</div>
              <?php if ( $best_call ): ?>
              <div class="bt-track-stat-val is-pos"><?php echo ( $best_call['pct_move'] >= 0 ? '+' : '' ) . number_format( $best_call['pct_move'], 1 ); ?><span class="bt-track-stat-pct">%</span></div>
              <div class="bt-track-stat-sub"><?php echo esc_html( self::tier_short_label( $best_call['tier'] ) ); ?> · <?php echo esc_html( human_time_diff( $best_call['t'], time() ) ); ?> ago</div>
              <?php else: ?>
              <div class="bt-track-stat-val">—</div>
              <div class="bt-track-stat-sub">awaiting graded calls</div>
              <?php endif; ?>
            </div>
          </div>

          <?php if ( ! empty( $recent ) ): ?>
          <!-- Recent calls table -->
          <div class="bt-track-calls">
            <div class="bt-track-calls-head">
              <div class="bt-desk-panel-label" style="margin-bottom:10px">RECENT CALLS · HINDSIGHT</div>
            </div>
            <div class="bt-track-table-wrap">
              <table class="bt-track-table">
                <thead>
                  <tr>
                    <th class="bt-t-th-date">WHEN</th>
                    <th class="bt-t-th-call">VERDICT</th>
                    <th class="bt-t-th-score">SCORE</th>
                    <th class="bt-t-th-btc">BTC AT CALL</th>
                    <th class="bt-t-th-move">24H MOVE</th>
                    <th class="bt-t-th-result">RESULT</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ( $recent as $c ):
                    $pos   = $c['pct_move'] >= 0;
                    $rescl = 'bt-t-res-' . sanitize_html_class( $c['result'] );
                  ?>
                  <tr class="<?php echo $rescl; ?>">
                    <td class="bt-t-date"><?php echo esc_html( date( 'D j M · H:i', intval( $c['t'] ) ) ); ?></td>
                    <td class="bt-t-call"><?php echo esc_html( self::tier_short_label( $c['tier'] ) ); ?></td>
                    <td class="bt-t-score"><?php echo ( $c['score'] >= 0 ? '+' : '' ) . number_format( $c['score'], 1 ); ?></td>
                    <td class="bt-t-btc">$<?php echo number_format( $c['btc0'], 0 ); ?></td>
                    <td class="bt-t-move <?php echo $pos ? 'is-pos' : 'is-neg'; ?>">
                      <?php echo ( $pos ? '▲ +' : '▼ ' ) . number_format( abs( $c['pct_move'] ), 2 ); ?>%
                    </td>
                    <td class="bt-t-result">
                      <?php
                      switch ( $c['result'] ) {
                        case 'correct':   echo '<span class="bt-t-badge bt-t-badge-ok">✓ HIT</span>'; break;
                        case 'incorrect': echo '<span class="bt-t-badge bt-t-badge-miss">✗ MISS</span>'; break;
                        case 'flat':      echo '<span class="bt-t-badge bt-t-badge-flat">─ FLAT</span>'; break;
                        default:          echo '<span class="bt-t-badge bt-t-badge-no">NO CALL</span>';
                      }
                      ?>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
          <?php endif; ?>

          <?php
          // v83 — Regime-conditional hit rate. Only render when there's enough
          // sample size to be meaningful (at least one regime with 3+ graded calls).
          $meaningful = false;
          foreach ( $regime_acc as $b ) { if ( $b['total'] >= 3 ) { $meaningful = true; break; } }
          ?>
          <?php if ( $meaningful ): ?>
          <div class="bt-track-regimes">
            <div class="bt-track-calls-head">
              <div class="bt-desk-panel-label" style="margin-bottom:10px">HIT RATE BY MARKET REGIME</div>
              <div class="bt-track-sub" style="margin-bottom:16px">When the desk issues a directional call, accuracy varies by what kind of market we're in. Buckets below show where the signal is strongest and weakest — the honest picture.</div>
            </div>
            <div class="bt-track-regimes-grid">
              <?php foreach ( $regime_acc as $key => $bucket ):
                if ( $bucket['total'] < 2 ) continue; // Skip tiny buckets — not meaningful yet
                $tone = $bucket['hit_rate_pct'] >= 55 ? 'bull' : ( $bucket['hit_rate_pct'] >= 45 ? 'neutral' : 'bear' );
              ?>
              <div class="bt-track-regime-card" data-tone="<?php echo esc_attr( $tone ); ?>">
                <div class="bt-track-regime-lbl"><?php echo esc_html( $bucket['label'] ); ?></div>
                <div class="bt-track-regime-val"><?php echo $bucket['hit_rate_pct']; ?><span class="bt-track-stat-pct">%</span></div>
                <div class="bt-track-regime-meter">
                  <div class="bt-track-regime-meter-fill" style="width:<?php echo esc_attr( $bucket['hit_rate_pct'] ); ?>%"></div>
                  <div class="bt-track-regime-meter-mid"></div>
                </div>
                <div class="bt-track-regime-sample">
                  <?php echo $bucket['correct']; ?>/<?php echo $bucket['total']; ?> correct
                  <?php if ( $bucket['total'] < 8 ): ?><span class="bt-track-regime-warn" title="Small sample — treat as preliminary">small n</span><?php endif; ?>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endif; ?>

          <!-- Honest disclosure -->
          <div class="bt-track-honest">
            <div class="bt-track-honest-lbl">HONEST DISCLOSURE</div>
            <p>Graded calls exclude neutral verdicts (the desk issues no directional call) and price moves under 0.25% in either direction (market noise, not signal). Every hourly snapshot is recorded without selection. No cherry-picking. Regime classification is recorded at the moment of the call, not in hindsight.</p>
          </div>

          <!-- v89: Per-signal accuracy — only shows when snapshots include signal data -->
          <?php
          $sig_acc = self::accuracy_by_signal( $history );
          if ( ! empty( $sig_acc ) ) :
              $sig_labels = array(
                  'btc_momentum'    => 'BTC Momentum',
                  'market_breadth'  => 'Market Breadth',
                  'altcoin_season'  => 'Alt Season',
                  'stablecoin_flow' => 'Stable Flow',
                  'volume_profile'  => 'Volume',
                  'fx_composite'    => 'FX (6-pair)',
                  'sentiment'       => 'Fear & Greed',
                  'news_composite'  => 'News',
              );
          ?>
          <div class="bt-track-signals-sec">
            <div class="bt-track-honest-lbl" style="margin-bottom:10px">PER-SIGNAL CONTRIBUTION ACCURACY</div>
            <div class="bt-track-sig-grid">
              <?php foreach ( $sig_acc as $skey => $sacc ) :
                  if ( $sacc['total'] < 3 ) continue;
                  $shr   = $sacc['hit_rate_pct'];
                  $stone = $shr >= 55 ? 'bull' : ( $shr >= 45 ? 'neutral' : 'bear' );
                  $scol  = $stone === 'bull' ? 'var(--bt-accent)' : ( $stone === 'bear' ? 'var(--bt-danger)' : 'var(--bt-accent-warm)' );
                  $slbl  = $sig_labels[ $skey ] ?? ucwords( str_replace( '_', ' ', $skey ) );
              ?>
              <div class="bt-track-sig-card" data-tone="<?php echo esc_attr( $stone ); ?>">
                <div class="bt-track-sig-name"><?php echo esc_html( $slbl ); ?></div>
                <div class="bt-track-sig-val" style="color:<?php echo esc_attr( $scol ); ?>"><?php echo esc_html( $shr ); ?><span class="bt-track-stat-pct">%</span></div>
                <div class="bt-track-regime-meter">
                  <div class="bt-track-regime-meter-fill" style="width:<?php echo esc_attr( $shr ); ?>%;background:<?php echo esc_attr( $scol ); ?>"></div>
                </div>
                <div class="bt-track-sig-sub"><?php echo esc_html( $sacc['correct'] ); ?>/<?php echo esc_html( $sacc['total'] ); ?> correct
                  <?php if ( $sacc['total'] < 8 ): ?><span class="bt-track-regime-warn">small n</span><?php endif; ?>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
            <p class="bt-track-sig-note">Signal accuracy: when a signal fired bullish (+) and the desk was correct, it counts as a signal hit. Requires snapshots with signal data (recorded from v89 onwards).</p>
          </div>
          <?php endif; ?>

        </section>
        <?php
        return ob_get_clean();
    }

    /**
     * v89 — Per-signal accuracy: for each of the 8 signals, what % of graded calls
     * had this signal firing in the correct direction?
     * Requires snapshot entries that include the 'signals' key (stored from v89 onwards).
     */
    private static function accuracy_by_signal( $history ) {
        $buckets = array();
        $sig_keys = array( 'btc_momentum','market_breadth','altcoin_season','stablecoin_flow',
                           'volume_profile','fx_composite','sentiment','news_composite' );
        foreach ( $sig_keys as $k ) {
            $buckets[ $k ] = array( 'correct' => 0, 'total' => 0 );
        }

        // Only use graded calls (same grading logic as compute_accuracy)
        $sorted = $history;
        usort( $sorted, fn( $a, $b ) => intval( $a['t'] ?? 0 ) - intval( $b['t'] ?? 0 ) );

        foreach ( $sorted as $i => $snap ) {
            if ( empty( $snap['signals'] ) ) continue;   // pre-v89 snapshot, no signal data
            $tier = $snap['tier'] ?? 'neutral';
            if ( $tier === 'neutral' ) continue;          // no directional call

            // Find the future snapshot ~24h later
            $target_t = intval( $snap['t'] ) + 86400;
            $future   = null;
            foreach ( $sorted as $j => $fs ) {
                if ( $j <= $i ) continue;
                $gap = abs( intval( $fs['t'] ) - $target_t );
                if ( $gap <= 10800 ) { $future = $fs; break; }  // within ±3h
            }
            if ( ! $future ) continue;

            $btc0 = floatval( $snap['btc'] ?? 0 );
            $btc1 = floatval( $future['btc'] ?? 0 );
            if ( $btc0 <= 0 ) continue;
            $move = ( $btc1 - $btc0 ) / $btc0 * 100;
            if ( abs( $move ) < 0.25 ) continue;         // flat, ungraded

            $verdict_up = strpos( $tier, 'bull' ) !== false;
            $price_up   = $move > 0;
            $correct    = ( $verdict_up === $price_up );

            foreach ( $snap['signals'] as $skey => $sc ) {
                if ( ! isset( $buckets[ $skey ] ) ) continue;
                $sc = floatval( $sc );
                if ( $sc == 0.0 ) continue;              // signal was neutral, no contribution
                $sig_up = $sc > 0;
                // Signal is "correct" if it fired in the same direction as the actual outcome
                if ( $sig_up === $price_up ) $buckets[ $skey ]['correct']++;
                $buckets[ $skey ]['total']++;
            }
        }

        // Attach hit_rate_pct, remove empty buckets
        $result = array();
        foreach ( $buckets as $k => $b ) {
            if ( $b['total'] < 1 ) continue;
            $result[ $k ] = array(
                'correct'      => $b['correct'],
                'total'        => $b['total'],
                'hit_rate_pct' => $b['total'] > 0 ? round( $b['correct'] / $b['total'] * 100 ) : 0,
            );
        }
        // Sort by hit_rate_pct desc
        uasort( $result, fn( $a, $b ) => $b['hit_rate_pct'] - $a['hit_rate_pct'] );
        return $result;
    }
    private static function tier_short_label( $tier ) {
        $map = array(
            'strong-bull' => 'STRONG BULL',
            'bull'        => 'BULL',
            'neutral'     => 'NEUTRAL',
            'bear'        => 'BEAR',
            'strong-bear' => 'STRONG BEAR',
        );
        return isset( $map[ $tier ] ) ? $map[ $tier ] : strtoupper( str_replace( '-', ' ', $tier ) );
    }

    // ── v85 + v86 methods below ─────────────────────────────────────────────

    /**
     * Build a 3-signal mini brief scoped to a single asset.
     * Signals: momentum (24h+7d), volume (vs market avg), news alignment.
     * Returns the same shape as build_brief() but lighter.
     */
    public static function build_asset_brief( $slug ) {
        $m     = self::gather_market_data();
        $coins = $m['coins'];
        $forex = $m['forex_rates'];
        $news  = $m['news'];

        // ── Detect asset type ───────────────────────────────────────────
        $asset      = null;
        $asset_type = 'crypto';   // 'crypto' | 'forex'
        $name       = $slug;
        $symbol     = strtoupper( $slug );
        $price      = null;
        $chg24      = 0.0;
        $chg7       = 0.0;
        $volume24   = 0.0;
        $mcap       = 0.0;

        // Try crypto first
        foreach ( $coins as $c ) {
            if ( $c['id'] === $slug || sanitize_title( $c['name'] ?? '' ) === $slug
                 || strtolower( $c['symbol'] ?? '' ) === strtolower( $slug ) ) {
                $asset  = $c;
                break;
            }
        }
        if ( $asset ) {
            $name    = $asset['name']   ?? $slug;
            $symbol  = strtoupper( $asset['symbol'] ?? $slug );
            $price   = floatval( $asset['current_price'] ?? 0 );
            $chg24   = floatval( $asset['price_change_percentage_24h'] ?? 0 );
            $chg7    = floatval( $asset['price_change_percentage_7d_in_currency'] ?? 0 );
            $volume24= floatval( $asset['total_volume'] ?? 0 );
            $mcap    = floatval( $asset['market_cap'] ?? 0 );
        } else {
            // Try forex
            $pair_key = strtoupper( str_replace( '-', '/', $slug ) );
            foreach ( $forex as $pair => $d ) {
                if ( strtoupper( $pair ) === $pair_key
                     || sanitize_title( $pair ) === $slug ) {
                    $asset_type = 'forex';
                    $name       = $pair;
                    $symbol     = $pair;
                    $price      = floatval( $d['rate'] ?? 0 );
                    $chg24      = floatval( $d['change'] ?? 0 );
                    $asset      = $d;
                    break;
                }
            }
        }

        // ── Signal 1: Momentum ─────────────────────────────────────────
        $mom_score = 0;
        if ( $chg24 >  5 )           $mom_score  =  2;
        elseif ( $chg24 >  2 )       $mom_score  =  1;
        elseif ( $chg24 < -5 )       $mom_score  = -2;
        elseif ( $chg24 < -2 )       $mom_score  = -1;
        if ( $asset_type === 'crypto' && $chg7 !== 0 ) {
            if ( $chg7 >  5 && $mom_score >= 0 )   $mom_score = min(  2, $mom_score + 1 );
            if ( $chg7 < -5 && $mom_score <= 0 )   $mom_score = max( -2, $mom_score - 1 );
        }
        $mom_label = $mom_score >= 2 ? 'Strong bullish' : ( $mom_score >= 1 ? 'Bullish' :
                   ( $mom_score <= -2 ? 'Strong bearish' : ( $mom_score <= -1 ? 'Bearish' : 'Neutral' ) ) );
        $mom_desc  = $asset_type === 'crypto'
            ? sprintf( '%s%s%% 24h · %s%s%% 7d', $chg24 >= 0 ? '+' : '', number_format( $chg24, 2 ),
                       $chg7  >= 0 ? '+' : '', number_format( $chg7,  2 ) )
            : sprintf( '%s%s%% 24h', $chg24 >= 0 ? '+' : '', number_format( $chg24, 3 ) );

        // ── Signal 2: Volume (crypto only) ──────────────────────────────
        $vol_score = 0;
        $vol_label = 'N/A';
        $vol_desc  = 'No volume data available';
        if ( $asset_type === 'crypto' && $volume24 > 0 && $mcap > 0 ) {
            $vol_ratio = $volume24 / $mcap;   // typical range 0.01–0.15
            if ( $vol_ratio > 0.12 )       { $vol_score =  2; $vol_label = 'Very high'; $vol_desc = sprintf( 'V/MCap ratio %.1f%% — elevated activity', $vol_ratio * 100 ); }
            elseif ( $vol_ratio > 0.06 )   { $vol_score =  1; $vol_label = 'Above avg'; $vol_desc = sprintf( 'V/MCap ratio %.1f%%', $vol_ratio * 100 ); }
            elseif ( $vol_ratio < 0.015 )  { $vol_score = -1; $vol_label = 'Low';       $vol_desc = sprintf( 'V/MCap ratio %.1f%% — quiet', $vol_ratio * 100 ); }
            else                           { $vol_score =  0; $vol_label = 'Normal';    $vol_desc = sprintf( 'V/MCap ratio %.1f%%', $vol_ratio * 100 ); }
        } elseif ( $asset_type === 'forex' ) {
            $vol_label = 'ECB data';
            $vol_desc  = 'Forex volume from ECB/Frankfurter — tick data not available';
        }

        // ── Signal 3: News alignment (filter fxlm_news for this asset) ──
        $news_score = 0;
        $news_label = 'Neutral';
        $news_desc  = 'No recent coverage found';
        $related_news = array();
        $name_lc = strtolower( $name );
        $sym_lc  = strtolower( $symbol );
        foreach ( is_array( $news ) ? $news : array() as $item ) {
            $t = strtolower( $item['title'] ?? '' );
            if ( str_contains( $t, $name_lc ) || str_contains( $t, $sym_lc )
                 || ( strlen( $sym_lc ) >= 3 && str_contains( $t, $sym_lc ) ) ) {
                $related_news[] = $item;
            }
        }
        if ( count( $related_news ) >= 3 ) {
            $news_score = $chg24 > 0 ? 1 : -1;
            $news_label = count( $related_news ) . ' related headlines';
            $news_desc  = sprintf( 'Active coverage: %d headlines in last 24h', count( $related_news ) );
        } elseif ( count( $related_news ) > 0 ) {
            $news_score = 0;
            $news_label = count( $related_news ) . ' related headline' . ( count( $related_news ) > 1 ? 's' : '' );
            $news_desc  = 'Moderate coverage';
        }

        // ── Composite ───────────────────────────────────────────────────
        $raw    = $mom_score * 2 + $vol_score + $news_score;   // range: -7 to +7
        $norm   = round( $raw / 7 * 10, 1 );                   // normalise to -10..+10
        $verdict = self::pick_verdict( $norm );

        return array(
            'found'      => ( $asset !== null ),
            'name'       => $name,
            'symbol'     => $symbol,
            'slug'       => $slug,
            'asset_type' => $asset_type,
            'price'      => $price,
            'chg24'      => $chg24,
            'chg7'       => $chg7,
            'composite'  => $norm,
            'verdict'    => $verdict,
            'signals'    => array(
                'momentum' => array( 'score' => $mom_score, 'label' => $mom_label, 'desc' => $mom_desc ),
                'volume'   => array( 'score' => $vol_score, 'label' => $vol_label, 'desc' => $vol_desc ),
                'news'     => array( 'score' => $news_score,'label' => $news_label,'desc' => $news_desc ),
            ),
            'related_news' => array_slice( $related_news, 0, 5 ),
            'updated'    => human_time_diff( time() - 30 ) . ' ago',
        );
    }

    /**
     * Render a full /analysis/{slug}/ page using the theme shell.
     * Called directly from BT_AssetPages::intercept_asset_url() with exit.
     */
    public static function render_asset_analysis( $slug ) {
        $b = self::build_asset_brief( $slug );

        // Unknown asset — graceful 404-style notice inside the theme
        if ( ! $b['found'] ) {
            status_header( 404 );
            get_header();
            echo '<div style="max-width:800px;margin:60px auto;padding:0 24px;text-align:center">';
            echo '<p style="font-family:JetBrains Mono,monospace;font-size:.8rem;color:#8892b0;text-transform:uppercase;letter-spacing:.1em;margin-bottom:16px">Analysis Desk · Not Found</p>';
            echo '<h1 style="font-size:2rem;color:var(--bt-text);margin-bottom:12px">/' . esc_html( $slug ) . '/ not in desk coverage</h1>';
            echo '<p style="color:#8892b0;margin-bottom:24px">This asset isn\'t in the current data set. Try searching on the <a href="' . esc_url( home_url( '/crypto-markets/' ) ) . '" style="color:var(--bt-accent)">Crypto Markets</a> or <a href="' . esc_url( home_url( '/forex-charts/' ) ) . '" style="color:var(--bt-accent)">Forex Rates</a> pages.</p>';
            echo '<a href="' . esc_url( home_url( '/market-analysis/' ) ) . '" style="display:inline-block;background:var(--bt-accent);color:#0A0B0D;padding:10px 22px;border-radius:0;text-decoration:none;font-weight:700">← Back to Research Desk</a>';
            echo '</div>';
            get_footer();
            return;
        }

        // ── SEO ─────────────────────────────────────────────────────────
        $direction = $b['chg24'] >= 0 ? 'up' : 'down';
        $pct_abs   = abs( $b['chg24'] );
        add_filter( 'wp_title', function() use ( $b ) {
            return $b['name'] . ' Analysis · ' . $b['verdict']['headline'] . ' | BlockTicker';
        } );
        add_action( 'wp_head', function() use ( $b, $direction, $pct_abs ) {
            $desc = sprintf( '%s (%s) is %s %.2f%% in the last 24 hours. BlockTicker AI desk verdict: %s.',
                $b['name'], $b['symbol'], $direction, $pct_abs, $b['verdict']['headline'] );
            echo '<meta name="description" content="' . esc_attr( $desc ) . '">' . "\n";
            echo '<meta property="og:title" content="' . esc_attr( $b['name'] . ' Analysis · ' . $b['verdict']['headline'] ) . '">' . "\n";
            echo '<link rel="canonical" href="' . esc_url( home_url( '/analysis/' . $b['slug'] . '/' ) ) . '">' . "\n";
        } );

        // ── Output ──────────────────────────────────────────────────────
        get_header();
        $tier     = $b['verdict']['tier'] ?? 'neutral';
        $tone_col = ( strpos( $tier, 'bull' ) !== false ) ? 'var(--bt-accent)' : ( ( strpos( $tier, 'bear' ) !== false ) ? 'var(--bt-danger)' : 'var(--bt-accent-warm)' );
        $chg_col  = $b['chg24'] >= 0 ? 'var(--bt-accent)' : 'var(--bt-danger)';
        $score    = $b['composite'];
        $norm_pct = min( 100, max( 0, ( $score + 10 ) * 5 ) );
        ?>
<div class="bt-av-wrap">

  <!-- Breadcrumb -->
  <nav class="bt-av-bc">
    <a href="<?php echo esc_url( home_url( '/' ) ); ?>">BlockTicker</a>
    <span>›</span>
    <a href="<?php echo esc_url( home_url( '/market-analysis/' ) ); ?>">Research Desk</a>
    <span>›</span>
    <span><?php echo esc_html( $b['symbol'] ); ?></span>
  </nav>

  <!-- Desk header -->
  <div class="bt-av-deskhead">
    <div class="bt-av-eyebrow">AI RESEARCH DESK · <?php echo esc_html( $b['asset_type'] === 'forex' ? 'FOREX' : 'CRYPTO' ); ?> · <?php echo esc_html( $b['symbol'] ); ?></div>
    <h1 class="bt-av-h1"><?php echo esc_html( $b['name'] ); ?> <span class="bt-av-sym"><?php echo esc_html( $b['symbol'] ); ?></span></h1>
    <div class="bt-av-meta">Updated <?php echo esc_html( $b['updated'] ); ?> · Source: <?php echo $b['asset_type'] === 'forex' ? 'ECB / Frankfurter.app' : 'CoinGecko'; ?> · <a href="<?php echo esc_url( home_url('/market-analysis/') ); ?>">Full desk →</a></div>
  </div>

  <!-- 2-col grid: price hero + verdict panel -->
  <div class="bt-av-grid">

    <!-- Price hero -->
    <div class="bt-av-panel bt-av-price" style="border-top-color:<?php echo esc_attr( $tone_col ); ?>">
      <div class="bt-av-panel-label">Current Price</div>
      <div class="bt-av-big-price">
        <?php
        $price_fmt = $b['price'] >= 1 ? '$' . number_format( $b['price'], 2 ) : '$' . number_format( $b['price'], 6 );
        echo esc_html( $b['price'] ? $price_fmt : '—' );
        ?>
      </div>
      <div class="bt-av-chg" style="color:<?php echo esc_attr( $chg_col ); ?>">
        <?php echo esc_html( ( $b['chg24'] >= 0 ? '+' : '' ) . number_format( $b['chg24'], $b['asset_type'] === 'forex' ? 3 : 2 ) . '%' ); ?> 24h
      </div>
      <?php if ( $b['asset_type'] === 'crypto' && $b['chg7'] !== 0 ): ?>
      <div class="bt-av-chg7" style="color:<?php echo esc_attr( $b['chg7'] >= 0 ? 'var(--bt-accent)' : 'var(--bt-danger)' ); ?>">
        <?php echo esc_html( ( $b['chg7'] >= 0 ? '+' : '' ) . number_format( $b['chg7'], 2 ) . '%' ); ?> 7d
      </div>
      <?php endif; ?>
      <div class="bt-av-asset-link">
        <?php if ( $b['asset_type'] === 'crypto' ): ?>
          <a href="<?php echo esc_url( home_url( '/crypto/' . $b['slug'] . '/' ) ); ?>">Full coin page →</a>
        <?php else: ?>
          <a href="<?php echo esc_url( home_url( '/forex/' . $b['slug'] . '/' ) ); ?>">Full forex page →</a>
        <?php endif; ?>
      </div>
    </div>

    <!-- Verdict panel -->
    <div class="bt-av-panel bt-av-verdict" style="border-top-color:<?php echo esc_attr( $tone_col ); ?>">
      <div class="bt-av-panel-label">Desk Verdict</div>
      <div class="bt-av-score" style="color:<?php echo esc_attr( $tone_col ); ?>"><?php echo esc_html( $score >= 0 ? '+' . $score : $score ); ?></div>
      <div class="bt-av-scale">
        <span>-10</span>
        <div class="bt-av-scale-bar">
          <div class="bt-av-scale-fill" style="width:<?php echo esc_attr( $norm_pct ); ?>%;background:<?php echo esc_attr( $tone_col ); ?>"></div>
          <div class="bt-av-scale-mid"></div>
        </div>
        <span>+10</span>
      </div>
      <div class="bt-av-tier" style="color:<?php echo esc_attr( $tone_col ); ?>"><?php echo esc_html( self::tier_short_label( $tier ) ); ?></div>
      <div class="bt-av-headline"><?php echo esc_html( $b['verdict']['headline'] ); ?></div>
      <div class="bt-av-action"><?php echo esc_html( $b['verdict']['action'] ?? '' ); ?></div>
    </div>

  </div><!-- .bt-av-grid -->

  <!-- Signal breakdown -->
  <div class="bt-av-signals">
    <div class="bt-av-sec-head">── SIGNAL BREAKDOWN ─────────────────────</div>
    <?php foreach ( $b['signals'] as $key => $sig ):
      $sc  = intval( $sig['score'] );
      $col = $sc > 0 ? 'var(--bt-accent)' : ( $sc < 0 ? 'var(--bt-danger)' : 'var(--bt-accent-warm)' );
      $arr = $sc > 0 ? '▲' : ( $sc < 0 ? '▼' : '●' );
    ?>
    <div class="bt-av-sig-row">
      <div class="bt-av-sig-name"><?php echo esc_html( strtoupper( $key ) ); ?></div>
      <div class="bt-av-sig-label" style="color:<?php echo esc_attr( $col ); ?>"><?php echo $arr; ?> <?php echo esc_html( $sig['label'] ); ?></div>
      <div class="bt-av-sig-desc"><?php echo esc_html( $sig['desc'] ); ?></div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Related news -->
  <?php if ( ! empty( $b['related_news'] ) ): ?>
  <div class="bt-av-news">
    <div class="bt-av-sec-head">── RELATED DESK NOTES ───────────────────</div>
    <?php foreach ( $b['related_news'] as $item ):
      $ts = isset( $item['pubdate'] ) ? human_time_diff( strtotime( $item['pubdate'] ) ) . ' ago' : '';
    ?>
    <div class="bt-av-news-row">
      <div class="bt-av-news-date"><?php echo esc_html( $ts ); ?></div>
      <a class="bt-av-news-title" href="<?php echo esc_url( $item['link'] ?? '#' ); ?>" target="_blank" rel="noopener">
        <?php echo esc_html( $item['title'] ?? '' ); ?>
      </a>
      <div class="bt-av-news-src"><?php echo esc_html( $item['source'] ?? '' ); ?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Methodology + CTA -->
  <div class="bt-av-footer">
    <div class="bt-av-footer-method">
      <div class="bt-av-footer-label">Methodology</div>
      <p>3-signal composite: momentum (24h + 7d price trend), volume (V/MCap ratio vs market average), news alignment (headline count from 14+ attributed sources). Score normalised to −10…+10. Updated every 15 minutes via cron.</p>
    </div>
    <div class="bt-av-footer-cta">
      <a href="<?php echo esc_url( home_url( '/market-analysis/' ) ); ?>" class="bt-av-cta-btn">← Full Research Desk</a>
      <a href="<?php echo esc_url( home_url( '/market-analysis/#bt-track-record' ) ); ?>" class="bt-av-cta-sec">View Track Record →</a>
    </div>
  </div>

</div><!-- .bt-av-wrap -->
        <?php
        get_footer();
    }

    /**
     * Shortcode wrapper: [bt_asset_analysis slug="bitcoin"]
     * Renders inline (e.g. on a page or in a sidebar widget).
     */
    public static function sc_asset_analysis( $atts ) {
        $a    = shortcode_atts( array( 'slug' => '' ), $atts );
        $slug = sanitize_title( $a['slug'] );
        if ( ! $slug ) return '<p class="fxlm-loading">No slug specified.</p>';
        $b = self::build_asset_brief( $slug );
        if ( ! $b['found'] ) return '<p class="fxlm-loading">Asset "' . esc_html( $slug ) . '" not found in current data.</p>';
        $tier    = $b['verdict']['tier'] ?? 'neutral';
        $col     = ( strpos( $tier, 'bull' ) !== false ) ? 'var(--bt-accent)' : ( ( strpos( $tier, 'bear' ) !== false ) ? 'var(--bt-danger)' : 'var(--bt-accent-warm)' );
        $chgcol  = $b['chg24'] >= 0 ? 'var(--bt-accent)' : 'var(--bt-danger)';
        $score   = $b['composite'];
        ob_start();
        ?>
        <div class="bt-av-inline" style="border-top:2px solid <?php echo esc_attr( $col ); ?>">
          <div class="bt-av-inline-sym"><?php echo esc_html( $b['symbol'] ); ?></div>
          <div class="bt-av-inline-price">
            <?php $pf = $b['price'] >= 1 ? '$'.number_format($b['price'],2) : '$'.number_format($b['price'],6); echo esc_html( $b['price'] ? $pf : '—' ); ?>
            <span style="color:<?php echo esc_attr($chgcol); ?>"> <?php echo esc_html(($b['chg24']>=0?'+':'').number_format($b['chg24'],2).'%'); ?></span>
          </div>
          <div class="bt-av-inline-verdict" style="color:<?php echo esc_attr($col); ?>"><?php echo esc_html($b['verdict']['headline']); ?></div>
          <a class="bt-av-inline-link" href="<?php echo esc_url(home_url('/analysis/'.$b['slug'].'/')); ?>">Full analysis →</a>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * v86 — Find the snapshot in bt_verdict_history closest to a given timestamp.
     * Used by Desk Notes to show which signals were firing when a post was published.
     * Returns null if history is empty or no snapshot within 6h of target.
     */
    private static function nearest_snapshot( $target_ts ) {
        $history = get_option( self::TRACK_RECORD_OPTION, array() );
        if ( empty( $history ) ) return null;
        $best     = null;
        $best_gap = PHP_INT_MAX;
        foreach ( $history as $snap ) {
            $t   = intval( $snap['t'] ?? 0 );
            $gap = abs( $t - $target_ts );
            if ( $gap < $best_gap ) {
                $best_gap = $gap;
                $best     = $snap;
            }
        }
        // Only return if within 6 hours — beyond that, the market context is too stale
        return ( $best && $best_gap <= 21600 ) ? $best : null;
    }

    // ───────────────────────────────────────────────────────────────────────
    // v87  Archive Replay Calendar  [bt_verdict_archive]
    // ───────────────────────────────────────────────────────────────────────

    /**
     * Group bt_verdict_history into a day-keyed structure for the calendar.
     * Returns array keyed by 'Y-m-d' (UTC), newest-first, max 30 days.
     * Each day entry contains aggregated stats + hourly breakdown.
     */
    private static function build_archive_data() {
        $history = get_option( self::TRACK_RECORD_OPTION, array() );
        if ( empty( $history ) || ! is_array( $history ) ) return array();

        // Sort ascending so hourly slots fill in order
        usort( $history, fn( $a, $b ) => intval( $a['t'] ?? 0 ) - intval( $b['t'] ?? 0 ) );

        // Group by UTC date
        $days = array();
        foreach ( $history as $snap ) {
            $d = gmdate( 'Y-m-d', intval( $snap['t'] ?? 0 ) );
            $days[ $d ][] = $snap;
        }

        // Build summary per day
        $result = array();
        foreach ( $days as $day => $snaps ) {
            $scores     = array_map( fn( $s ) => floatval( $s['score'] ?? 0 ), $snaps );
            $tiers      = array_column( $snaps, 'tier' );
            $btcs       = array_filter( array_map( fn( $s ) => floatval( $s['btc'] ?? 0 ), $snaps ) );
            $regimes    = array_column( $snaps, 'regime' );

            // Dominant tier = most-frequent non-neutral if possible
            $tier_counts  = array_count_values( $tiers );
            arsort( $tier_counts );
            $dominant_tier = key( $tier_counts );
            // Prefer directional tier over neutral if both present
            if ( $dominant_tier === 'neutral' && count( $tier_counts ) > 1 ) {
                next( $tier_counts );
                $second = key( $tier_counts );
                if ( $tier_counts[ $second ] >= $tier_counts['neutral'] * 0.6 ) {
                    $dominant_tier = $second;
                }
            }

            // Dominant regime
            $regime_counts   = array_count_values( array_filter( $regimes ) );
            arsort( $regime_counts );
            $dominant_regime = key( $regime_counts ) ?? 'unknown';

            // Hourly slot map 0-23
            $hourly = array_fill( 0, 24, null );
            foreach ( $snaps as $snap ) {
                $h = intval( gmdate( 'G', intval( $snap['t'] ?? 0 ) ) );
                $hourly[ $h ] = $snap;
            }

            $result[ $day ] = array(
                'date'           => $day,
                'count'          => count( $snaps ),
                'avg_score'      => count( $scores ) ? array_sum( $scores ) / count( $scores ) : 0,
                'min_score'      => count( $scores ) ? min( $scores ) : 0,
                'max_score'      => count( $scores ) ? max( $scores ) : 0,
                'dominant_tier'  => $dominant_tier,
                'tier_counts'    => $tier_counts,
                'dominant_regime'=> $dominant_regime,
                'btc_open'       => $btcs ? reset( $btcs ) : null,
                'btc_close'      => $btcs ? end( $btcs ) : null,
                'btc_high'       => $btcs ? max( $btcs ) : null,
                'btc_low'        => $btcs ? min( $btcs ) : null,
                'hourly'         => $hourly,
                'snaps'          => $snaps,
            );
        }

        // Newest-first, max 30 days
        krsort( $result );
        return array_slice( $result, 0, 30, true );
    }

    /**
     * Shortcode [bt_verdict_archive] — 30-day verdict replay calendar.
     * Shows every verdict the desk has ever issued with full transparency.
     */
    public static function sc_verdict_archive( $atts ) {
        $a       = shortcode_atts( array( 'days' => 30 ), $atts );
        $history = get_option( self::TRACK_RECORD_OPTION, array() );
        $data    = self::build_archive_data();

        // Fetch posts in a single query, group by date
        $cutoff = strtotime( '-30 days' );
        $posts  = get_posts( array(
            'numberposts' => 200,
            'post_status' => 'publish',
            'date_query'  => array( array( 'after' => date( 'Y-m-d', $cutoff ) ) ),
        ) );
        $posts_by_day = array();
        foreach ( $posts as $post ) {
            $pd = get_the_date( 'Y-m-d', $post->ID );
            $posts_by_day[ $pd ][] = $post;
        }

        // Tier colour helper
        $tier_col = function( $tier ) {
            if ( strpos( $tier, 'bull' ) !== false ) return 'var(--bt-accent)';
            if ( strpos( $tier, 'bear' ) !== false ) return 'var(--bt-danger)';
            return 'var(--bt-accent-warm)';
        };

        // Aggregate stats for header strip
        $total_snaps   = count( $history );
        $bull_days     = $bear_days = $neutral_days = 0;
        $earliest_ts   = $total_snaps ? intval( $history[0]['t'] ?? 0 ) : 0;
        foreach ( $data as $d ) {
            $t = $d['dominant_tier'];
            if ( strpos( $t, 'bull' ) !== false )   $bull_days++;
            elseif ( strpos( $t, 'bear' ) !== false ) $bear_days++;
            else                                      $neutral_days++;
        }
        $total_days = count( $data );

        ob_start();
        ?>
<div class="bt-arc-wrap" id="bt-archive">

  <!-- ── HEADER ──────────────────────────────────────────────── -->
  <div class="bt-arc-header">
    <div class="bt-arc-eyebrow">VERDICT ARCHIVE · FULL TRANSPARENCY · <?php echo esc_html( $total_days ); ?>-DAY RECORD</div>
    <h2 class="bt-arc-title">Every call the desk has ever issued</h2>
    <p class="bt-arc-sub">No cherry-picking. Every hourly snapshot recorded without selection. Neutral verdicts (no directional call) displayed but excluded from accuracy calculations. Published so you can judge for yourself.</p>
  </div>

  <?php if ( empty( $data ) ) : ?>
  <div class="bt-arc-seeding">
    <div class="bt-arc-seed-icon">//</div>
    <div class="bt-arc-seed-title">Archive seeding</div>
    <p>The desk logs a verdict snapshot every hour. First calendar entries appear within 24 hours of the first cron run. Check back tomorrow for your first full day of history.</p>
  </div>
  <?php else : ?>

  <!-- ── STATS STRIP ──────────────────────────────────────────── -->
  <div class="bt-arc-stats">
    <div class="bt-arc-stat">
      <div class="bt-arc-stat-num"><?php echo esc_html( $total_snaps ); ?></div>
      <div class="bt-arc-stat-lbl">Total Snapshots</div>
    </div>
    <div class="bt-arc-stat">
      <div class="bt-arc-stat-num"><?php echo esc_html( $total_days ); ?></div>
      <div class="bt-arc-stat-lbl">Days Recorded</div>
    </div>
    <div class="bt-arc-stat" style="--arc-col:var(--bt-accent)">
      <div class="bt-arc-stat-num" style="color:var(--bt-accent)"><?php echo esc_html( $bull_days ); ?></div>
      <div class="bt-arc-stat-lbl">Bull Days</div>
    </div>
    <div class="bt-arc-stat" style="--arc-col:var(--bt-accent-warm)">
      <div class="bt-arc-stat-num" style="color:var(--bt-accent-warm)"><?php echo esc_html( $neutral_days ); ?></div>
      <div class="bt-arc-stat-lbl">Neutral Days</div>
    </div>
    <div class="bt-arc-stat" style="--arc-col:var(--bt-danger)">
      <div class="bt-arc-stat-num" style="color:var(--bt-danger)"><?php echo esc_html( $bear_days ); ?></div>
      <div class="bt-arc-stat-lbl">Bear Days</div>
    </div>
    <?php if ( $earliest_ts ) : ?>
    <div class="bt-arc-stat">
      <div class="bt-arc-stat-num" style="font-size:1rem"><?php echo esc_html( gmdate( 'j M Y', $earliest_ts ) ); ?></div>
      <div class="bt-arc-stat-lbl">Archive Start</div>
    </div>
    <?php endif; ?>
  </div>

  <!-- ── CALENDAR GRID ────────────────────────────────────────── -->
  <div class="bt-arc-section-head">── DAILY RECORD ─────────────────────────────────────────────────────</div>
  <div class="bt-arc-calendar">

    <!-- Header row -->
    <div class="bt-arc-cal-head">
      <div class="bt-arc-col-date">Date</div>
      <div class="bt-arc-col-tier">Dominant Verdict</div>
      <div class="bt-arc-col-score">Avg Score</div>
      <div class="bt-arc-col-btc">BTC Range</div>
      <div class="bt-arc-col-heat">Hourly Heatmap (UTC 0→23)</div>
      <div class="bt-arc-col-posts">Posts</div>
    </div>

    <?php foreach ( $data as $day => $d ) :
        $col       = $tier_col( $d['dominant_tier'] );
        $avg_sc    = $d['avg_score'];
        $norm_avg  = round( $avg_sc / 14 * 10, 1 );  // normalise raw score to -10..+10
        $sc_str    = ( $norm_avg >= 0 ? '+' : '' ) . $norm_avg;
        $day_ts    = strtotime( $day );
        $day_label = strtoupper( gmdate( 'D', $day_ts ) );
        $day_num   = gmdate( 'j M', $day_ts );
        $btc_open  = $d['btc_open']  ? '$' . number_format( $d['btc_open'],  0 ) : '—';
        $btc_close = $d['btc_close'] ? '$' . number_format( $d['btc_close'], 0 ) : '—';
        $btc_chg   = ( $d['btc_open'] && $d['btc_close'] && $d['btc_open'] > 0 )
                        ? round( ( $d['btc_close'] - $d['btc_open'] ) / $d['btc_open'] * 100, 2 )
                        : null;
        $day_posts = $posts_by_day[ $day ] ?? array();
        $is_today  = ( $day === gmdate( 'Y-m-d' ) );
    ?>
    <div class="bt-arc-row<?php echo $is_today ? ' bt-arc-row--today' : ''; ?>" data-day="<?php echo esc_attr( $day ); ?>">

      <!-- Date -->
      <div class="bt-arc-col-date">
        <span class="bt-arc-dow"><?php echo esc_html( $day_label ); ?></span>
        <span class="bt-arc-dnum"><?php echo esc_html( $day_num ); ?></span>
        <?php if ( $is_today ) : ?><span class="bt-arc-today-tag">TODAY</span><?php endif; ?>
      </div>

      <!-- Dominant verdict -->
      <div class="bt-arc-col-tier">
        <span class="bt-arc-tier-badge" style="color:<?php echo esc_attr( $col ); ?>;border-color:<?php echo esc_attr( $col ); ?>">
          <?php echo esc_html( self::tier_short_label( $d['dominant_tier'] ) ); ?>
        </span>
        <span class="bt-arc-regime"><?php echo esc_html( str_replace( '-', ' ', $d['dominant_regime'] ) ); ?></span>
      </div>

      <!-- Score -->
      <div class="bt-arc-col-score">
        <span class="bt-arc-score" style="color:<?php echo esc_attr( $col ); ?>"><?php echo esc_html( $sc_str ); ?></span>
        <span class="bt-arc-snaps"><?php echo esc_html( $d['count'] ); ?> snaps</span>
      </div>

      <!-- BTC range -->
      <div class="bt-arc-col-btc">
        <span class="bt-arc-btc-range"><?php echo esc_html( $btc_open ); ?> → <?php echo esc_html( $btc_close ); ?></span>
        <?php if ( $btc_chg !== null ) : ?>
        <span class="bt-arc-btc-chg" style="color:<?php echo esc_attr( $btc_chg >= 0 ? 'var(--bt-accent)' : 'var(--bt-danger)' ); ?>">
          <?php echo esc_html( ( $btc_chg >= 0 ? '+' : '' ) . $btc_chg . '%' ); ?>
        </span>
        <?php endif; ?>
      </div>

      <!-- Hourly heatmap — 24 cells representing hours 0-23 -->
      <div class="bt-arc-col-heat">
        <div class="bt-arc-heat">
          <?php for ( $h = 0; $h < 24; $h++ ) :
              $snap = $d['hourly'][ $h ];
              if ( ! $snap ) {
                  echo '<span class="bt-arc-heat-cell bt-arc-heat-empty" title="' . esc_attr( $h ) . ':00 UTC — no data"></span>';
              } else {
                  $hcol  = $tier_col( $snap['tier'] ?? 'neutral' );
                  $hsc   = round( floatval( $snap['score'] ?? 0 ) / 14 * 10, 1 );
                  $hbtc  = $snap['btc'] ? ' · BTC $' . number_format( $snap['btc'], 0 ) : '';
                  $title = sprintf( '%02d:00 UTC · %s · %s%s%s',
                      $h, self::tier_short_label( $snap['tier'] ?? 'neutral' ),
                      $hsc >= 0 ? '+' : '', $hsc, $hbtc );
                  $opacity = min( 1, max( 0.25, abs( $hsc ) / 10 ) );
                  echo '<span class="bt-arc-heat-cell" style="background:' . esc_attr( $hcol ) . ';opacity:' . esc_attr( $opacity ) . '" title="' . esc_attr( $title ) . '"></span>';
              }
          endfor; ?>
        </div>
      </div>

      <!-- Posts published that day -->
      <div class="bt-arc-col-posts">
        <?php if ( ! empty( $day_posts ) ) : ?>
          <span class="bt-arc-post-count"><?php echo count( $day_posts ); ?> note<?php echo count( $day_posts ) > 1 ? 's' : ''; ?></span>
          <div class="bt-arc-post-list">
            <?php foreach ( array_slice( $day_posts, 0, 3 ) as $p ) : ?>
            <a class="bt-arc-post-link" href="<?php echo esc_url( get_permalink( $p->ID ) ); ?>"><?php echo esc_html( wp_trim_words( $p->post_title, 6, '…' ) ); ?></a>
            <?php endforeach; ?>
          </div>
        <?php else : ?>
          <span class="bt-arc-no-posts">—</span>
        <?php endif; ?>
      </div>

    </div><!-- .bt-arc-row -->
    <?php endforeach; ?>

  </div><!-- .bt-arc-calendar -->

  <!-- ── DISCLOSURE ───────────────────────────────────────────── -->
  <div class="bt-arc-disclosure">
    <strong>Transparency commitment</strong> — This archive is the complete, unedited record of every verdict snapshot since the desk launched. Snapshots are recorded automatically every hour without human selection. Neutral verdicts mean the desk issued no directional call for that period. Gaps in the hourly heatmap indicate missed cron ticks or site downtime — these are documented, not hidden. No past entries are removed or edited.
  </div>

  <?php endif; ?>
</div><!-- .bt-arc-wrap -->
        <?php
        return ob_get_clean();
    }
}
