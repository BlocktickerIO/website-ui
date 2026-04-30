<?php
/**
 * BT_Newsletter — Weekly AI Market Digest Email.
 *
 * Sends a richly styled HTML email every week to all subscribers in
 * bt_subscribers. The digest is AI-generated and pulls live data from:
 *   - bt_crypto_data       — top movers + prices
 *   - bt_forex_data        — major pair rates
 *   - bt_signal_items      — latest trading signals
 *   - bt_fear_greed_data   — Fear & Greed index
 *   - BT_Sentiment         — 7-day market mood (if available)
 *   - wp_bt_news_items     — top headlines
 *
 * Email design: dark-background premium financial newsletter,
 * mobile-responsive, tested for Gmail / Outlook / Apple Mail.
 *
 * Cron: bt_send_weekly_digest — fires every Sunday at ~8 AM site time.
 *
 * Admin: manual send + preview from BlockTicker → Settings.
 * AJAX: bt_newsletter_preview  — returns rendered HTML
 *       bt_newsletter_send_now — sends immediately
 *
 * @package BlockTicker
 * @since   108.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BT_Newsletter {

    const CRON_HOOK    = 'bt_send_weekly_digest';
    const LAST_SENT_KEY = 'bt_newsletter_last_sent';

    /* ------------------------------------------------------------------
     * Bootstrap
     * ------------------------------------------------------------------ */

    public static function init() {
        // Schedule weekly Sunday cron if not already queued.
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            $next_sunday = self::next_sunday_8am();
            wp_schedule_event( $next_sunday, 'weekly', self::CRON_HOOK );
        }
        add_action( self::CRON_HOOK, array( __CLASS__, 'send_digest' ) );

        add_action( 'wp_ajax_bt_newsletter_preview',  array( __CLASS__, 'ajax_preview' ) );
        add_action( 'wp_ajax_bt_newsletter_send_now', array( __CLASS__, 'ajax_send_now' ) );
    }

    public static function deactivate() {
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }

    /* ------------------------------------------------------------------
     * Data assembly
     * ------------------------------------------------------------------ */

    /**
     * Gather all data needed for the digest.
     *
     * @return array
     */
    private static function assemble_data(): array {
        // Crypto top movers.
        $crypto    = BT_Widgets::get_json_option( 'bt_crypto_data' );
        $coins     = array_slice( $crypto['coins'] ?? array(), 0, 50 );

        // Sort for gainers / losers.
        $gainers = $losers = array();
        foreach ( $coins as $c ) {
            $pct = floatval( $c['price_change_percentage_24h'] ?? 0 );
            if ( $pct > 0 ) $gainers[] = $c;
            else            $losers[]  = $c;
        }
        usort( $gainers, fn( $a, $b ) => ( $b['price_change_percentage_24h'] ?? 0 ) <=> ( $a['price_change_percentage_24h'] ?? 0 ) );
        usort( $losers,  fn( $a, $b ) => ( $a['price_change_percentage_24h'] ?? 0 ) <=> ( $b['price_change_percentage_24h'] ?? 0 ) );
        $top_gainers = array_slice( $gainers, 0, 5 );
        $top_losers  = array_slice( $losers, 0, 5 );

        // BTC + ETH highlight prices.
        $btc = current( array_filter( $coins, fn( $c ) => strtolower( $c['id'] ?? '' ) === 'bitcoin' ) ) ?: null;
        $eth = current( array_filter( $coins, fn( $c ) => strtolower( $c['id'] ?? '' ) === 'ethereum' ) ) ?: null;

        // Forex rates.
        $forex = BT_Widgets::get_json_option( 'bt_forex_data' );
        $fx_rates = $forex['rates'] ?? $forex['pairs'] ?? array();

        // Fear & Greed.
        $fng = get_option( 'bt_fear_greed_data', array() );

        // Sentiment (7-day).
        $mood = class_exists( 'BT_Sentiment' ) ? BT_Sentiment::get_market_mood( 168 ) : null;

        // Top headlines from DB.
        $headlines = self::get_top_headlines( 6 );

        // Signals.
        $signals = array_slice( get_option( 'bt_signal_items', array() ), 0, 5 );

        return compact( 'top_gainers', 'top_losers', 'btc', 'eth', 'fx_rates', 'fng', 'mood', 'headlines', 'signals', 'coins' );
    }

    /**
     * Fetch top 6 news headlines from the last 7 days.
     */
    private static function get_top_headlines( int $limit ): array {
        global $wpdb;
        if ( ! class_exists( 'BT_Database' ) ) return array();
        $table = $wpdb->prefix . 'bt_news_items';
        $since = gmdate( 'Y-m-d H:i:s', strtotime( '-7 days' ) );
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT title, url, source, published_at, sentiment_score
             FROM {$table}
             WHERE published_at >= %s
             ORDER BY published_at DESC LIMIT %d",
            $since, $limit
        ), ARRAY_A ) ?: array();
    }

    /* ------------------------------------------------------------------
     * AI narrative generation
     * ------------------------------------------------------------------ */

    /**
     * Call the configured AI provider to generate the weekly narrative.
     * Returns a 3–5 paragraph HTML-safe plain text string, or empty on failure.
     */
    private static function generate_narrative( array $data ): string {
        $provider = get_option( 'bt_ai_provider', 'claude' );
        $api_key  = $provider === 'openai'
            ? get_option( 'bt_openai_key', '' )
            : get_option( 'bt_claude_key', '' );

        if ( empty( $api_key ) ) return '';

        // Summarise key data for the prompt.
        $btc_price = $data['btc'] ? '$' . number_format( floatval( $data['btc']['current_price'] ?? 0 ), 0 ) : 'N/A';
        $btc_chg   = $data['btc'] ? round( floatval( $data['btc']['price_change_percentage_24h'] ?? 0 ), 1 ) : 0;
        $eth_price = $data['eth'] ? '$' . number_format( floatval( $data['eth']['current_price'] ?? 0 ), 0 ) : 'N/A';
        $fng_val   = $data['fng']['value'] ?? 'N/A';
        $fng_lbl   = $data['fng']['value_classification'] ?? '';
        $mood_lbl  = $data['mood']['label'] ?? 'N/A';
        $mood_scr  = $data['mood'] ? number_format( $data['mood']['score'], 2 ) : '';
        $gainer_str = implode( ', ', array_map( fn( $c ) => $c['symbol'] . ' +' . round( $c['price_change_percentage_24h'], 1 ) . '%', $data['top_gainers'] ) );
        $loser_str  = implode( ', ', array_map( fn( $c ) => $c['symbol'] . ' ' . round( $c['price_change_percentage_24h'], 1 ) . '%', $data['top_losers'] ) );
        $headlines_str = implode( "\n", array_map( fn( $h ) => '• ' . $h['title'], array_slice( $data['headlines'], 0, 4 ) ) );

        $prompt = <<<PROMPT
You are the editor of BlockTicker, a premium financial market newsletter.

Write a concise 3-paragraph weekly market digest for the past 7 days. Use plain text only (no markdown, no bullet points in the main paragraphs, no headers). Write in a professional but engaging tone — like a Bloomberg newsletter.

Data summary:
- BTC: {$btc_price} (24h: {$btc_chg}%)
- ETH: {$eth_price}
- Fear & Greed Index: {$fng_val} ({$fng_lbl})
- News sentiment (7d): {$mood_lbl} ({$mood_scr})
- Top gainers this week: {$gainer_str}
- Top losers this week: {$loser_str}
- Key headlines:
{$headlines_str}

Paragraph 1: Overall market tone and what drove it this week.
Paragraph 2: The standout movers — both gainers and losers — and why they moved.
Paragraph 3: Forward-looking: what to watch next week (key levels, events, catalysts).

Keep each paragraph under 80 words. Do not use markdown. No bullet points. Return only the three paragraphs separated by a blank line.
PROMPT;

        $response = self::call_ai( $provider, $api_key, $prompt );
        return is_string( $response ) ? wp_strip_all_tags( $response ) : '';
    }

    /* ------------------------------------------------------------------
     * Send digest
     * ------------------------------------------------------------------ */

    /**
     * Main send routine — called by cron and AJAX.
     *
     * @param  bool   $force   Send even if already sent this week.
     * @param  string $to_override  If set, send only to this single address (preview/test).
     * @return array  { sent: int, skipped: int, errors: string[] }
     */
    public static function send_digest( bool $force = false, string $to_override = '' ): array {
        // Prevent double-send within 6 days.
        if ( ! $force && ! $to_override ) {
            $last = intval( get_option( self::LAST_SENT_KEY, 0 ) );
            if ( $last && ( time() - $last ) < 6 * DAY_IN_SECONDS ) {
                return array( 'sent' => 0, 'skipped' => 1, 'errors' => array( 'Already sent within 6 days.' ) );
            }
        }

        $data      = self::assemble_data();
        $narrative = self::generate_narrative( $data );
        $html      = self::render_email( $data, $narrative );
        $subject   = self::build_subject( $data );
        $headers   = array( 'Content-Type: text/html; charset=UTF-8' );

        $from_name  = get_option( 'bt_site_name', 'BlockTicker' );
        $from_email = get_option( 'admin_email' );
        $headers[]  = "From: {$from_name} <{$from_email}>";
        $headers[]  = "Reply-To: {$from_email}";

        $result = array( 'sent' => 0, 'skipped' => 0, 'errors' => array() );

        if ( $to_override ) {
            // Single address (test/preview send).
            $ok = wp_mail( $to_override, '[Test] ' . $subject, $html, $headers );
            $ok ? $result['sent']++ : $result['errors'][] = "Failed to send to {$to_override}";
            return $result;
        }

        $subscribers = get_option( 'bt_subscribers', array() );
        if ( empty( $subscribers ) ) {
            return array( 'sent' => 0, 'skipped' => 0, 'errors' => array( 'No subscribers.' ) );
        }

        foreach ( $subscribers as $sub ) {
            $email = sanitize_email( $sub['email'] ?? '' );
            if ( ! is_email( $email ) ) { $result['skipped']++; continue; }
            $ok = wp_mail( $email, $subject, $html, $headers );
            $ok ? $result['sent']++ : $result['errors'][] = "Failed: {$email}";
        }

        if ( ! $to_override ) {
            update_option( self::LAST_SENT_KEY, time() );
        }

        return $result;
    }

    /* ------------------------------------------------------------------
     * Email HTML template
     * ------------------------------------------------------------------ */

    /**
     * Render the full HTML email.
     * Dark-background, table-based layout (Gmail-safe), mobile-responsive.
     *
     * @param  array  $data      Assembled market data.
     * @param  string $narrative AI-generated intro paragraphs.
     * @return string  Full HTML document string.
     */
    public static function render_email( array $data, string $narrative = '' ): string {
        $site      = get_option( 'bt_site_name', 'BlockTicker' );
        $logo_url  = get_option( 'bt_logo_url', '' );
        $home_url  = home_url( '/' );
        $date_str  = gmdate( 'F j, Y' );
        $year      = gmdate( 'Y' );
        $unsub_url = home_url( '/?bt_unsub=1' ); // placeholder

        // Colour palette.
        $teal   = 'var(--bt-accent)';
        $dark1  = '#0b0f1a';
        $dark2  = 'var(--bt-bg-elev)';
        $dark3  = '#1e2535';
        $border = '#1e293b';
        $text1  = 'var(--bt-text)';
        $text2  = 'var(--bt-text-2)';
        $green  = '#22c55e';
        $red    = '#ef4444';
        $amber  = 'var(--bt-accent-warm)';

        // BTC / ETH hero data.
        $btc       = $data['btc'];
        $eth       = $data['eth'];
        $btc_price = $btc ? '$' . number_format( floatval( $btc['current_price'] ?? 0 ), 0 ) : '—';
        $btc_chg   = $btc ? floatval( $btc['price_change_percentage_24h'] ?? 0 ) : 0;
        $eth_price = $eth ? '$' . number_format( floatval( $eth['current_price'] ?? 0 ), 0 ) : '—';
        $eth_chg   = $eth ? floatval( $eth['price_change_percentage_24h'] ?? 0 ) : 0;
        $btc_col   = $btc_chg >= 0 ? $green : $red;
        $eth_col   = $eth_chg >= 0 ? $green : $red;
        $btc_arr   = $btc_chg >= 0 ? '▲' : '▼';
        $eth_arr   = $eth_chg >= 0 ? '▲' : '▼';

        // Fear & Greed.
        $fng_val   = intval( $data['fng']['value'] ?? 0 );
        $fng_lbl   = esc_html( $data['fng']['value_classification'] ?? 'N/A' );
        $fng_col   = $fng_val >= 60 ? $green : ( $fng_val >= 40 ? $amber : $red );

        // Sentiment.
        $mood_lbl  = esc_html( $data['mood']['label'] ?? '' );
        $mood_scr  = $data['mood'] ? number_format( $data['mood']['score'], 2 ) : '';
        $mood_col  = ( $data['mood']['score'] ?? 0 ) >= 0.25 ? $green : ( ( $data['mood']['score'] ?? 0 ) >= -0.24 ? $amber : $red );

        // Narrative paragraphs.
        $paragraphs = $narrative ? array_map( 'trim', explode( "\n\n", trim( $narrative ) ) ) : array();

        ob_start();
        echo '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">' . "\n";
        echo '<html xmlns="http://www.w3.org/1999/xhtml"><head>';
        echo '<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">';
        echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<title>' . esc_html( $site ) . ' Weekly Market Digest — ' . esc_html( $date_str ) . '</title>';
        echo '<style type="text/css">';
        echo "@media only screen and (max-width:600px){
            .email-wrap{width:100%!important;padding:0!important}
            .col-half{display:block!important;width:100%!important}
            .mover-price{font-size:13px!important}
            h1.hero-title{font-size:22px!important}
        }";
        echo '</style></head>';
        echo '<body style="margin:0;padding:0;background-color:' . $dark1 . ';font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;">';

        // ── Outer wrapper ──
        echo '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:' . $dark1 . ';">';
        echo '<tr><td align="center" style="padding:24px 16px 40px;">';
        echo '<table class="email-wrap" width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;">';

        // ── HEADER ──
        echo '<tr><td style="background:' . $dark2 . ';border-radius:0 16px 0 0;padding:28px 32px 20px;border-bottom:1px solid ' . $border . ';">';
        echo '<table width="100%" cellpadding="0" cellspacing="0" border="0"><tr>';
        echo '<td><a href="' . esc_url( $home_url ) . '" style="text-decoration:none;">';
        if ( $logo_url ) {
            echo '<img src="' . esc_url( $logo_url ) . '" alt="' . esc_attr( $site ) . '" height="36" style="display:block;height:36px;">';
        } else {
            echo '<span style="font-size:22px;font-weight:800;color:' . $teal . ';letter-spacing:-0.5px;">' . esc_html( $site ) . '</span>';
        }
        echo '</a></td>';
        echo '<td align="right" style="font-size:12px;color:' . $text2 . ';">';
        echo 'Weekly Market Digest<br><strong style="color:' . $text1 . ';">' . esc_html( $date_str ) . '</strong>';
        echo '</td></tr></table></td></tr>';

        // ── HERO GRADIENT BANNER ──
        echo '<tr><td style="background:linear-gradient(135deg,' . $dark2 . ' 0%,#0f1d32 50%,' . $dark2 . ' 100%);padding:32px 32px 28px;border-bottom:1px solid ' . $border . ';">';
        echo '<h1 class="hero-title" style="margin:0 0 8px;font-size:26px;font-weight:800;color:' . $text1 . ';letter-spacing:-0.5px;">Your Weekly<br><span style="color:' . $teal . ';">Market Pulse</span></h1>';
        echo '<p style="margin:0;font-size:13px;color:' . $text2 . ';">7-day recap · Signals · Sentiment · Top movers</p>';
        echo '</td></tr>';

        // ── BTC / ETH HERO PRICES ──
        echo '<tr><td style="background:' . $dark3 . ';padding:0;border-bottom:1px solid ' . $border . ';">';
        echo '<table width="100%" cellpadding="0" cellspacing="0" border="0"><tr>';

        foreach ( array(
            array( 'Bitcoin', 'BTC', $btc_price, $btc_chg, $btc_col, $btc_arr, home_url('/crypto/bitcoin/') ),
            array( 'Ethereum', 'ETH', $eth_price, $eth_chg, $eth_col, $eth_arr, home_url('/crypto/ethereum/') ),
        ) as $coin ) :
            echo '<td class="col-half" width="50%" style="padding:20px 24px;border-right:1px solid ' . $border . ';">';
            echo '<p style="margin:0 0 4px;font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:' . $text2 . ';">' . esc_html( $coin[0] ) . ' <span style="color:' . $teal . ';">' . esc_html( $coin[1] ) . '</span></p>';
            echo '<p style="margin:0;font-size:28px;font-weight:800;color:' . $text1 . ';letter-spacing:-1px;">' . esc_html( $coin[2] ) . '</p>';
            echo '<p style="margin:4px 0 0;font-size:14px;font-weight:700;color:' . esc_attr( $coin[4] ) . ';">' . esc_html( $coin[5] ) . ' ' . esc_html( abs( round( $coin[3], 2 ) ) ) . '%<span style="color:' . $text2 . ';font-weight:400;font-size:11px;"> 24h</span></p>';
            echo '</td>';
        endforeach;

        // Fear & Greed + Mood in same row.
        echo '<td class="col-half" width="0" style="display:none;"></td>';
        echo '</tr></table></td></tr>';

        // ── MARKET PULSE ROW (F&G + Sentiment) ──
        echo '<tr><td style="background:' . $dark2 . ';padding:0;border-bottom:1px solid ' . $border . ';">';
        echo '<table width="100%" cellpadding="0" cellspacing="0" border="0"><tr>';

        // Fear & Greed.
        echo '<td class="col-half" width="50%" style="padding:18px 24px;border-right:1px solid ' . $border . ';">';
        echo '<p style="margin:0 0 2px;font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:' . $text2 . ';">Fear & Greed Index</p>';
        echo '<p style="margin:0;font-size:24px;font-weight:800;color:' . esc_attr( $fng_col ) . ';">' . esc_html( $fng_val > 0 ? $fng_val : 'N/A' ) . '</p>';
        echo '<p style="margin:2px 0 0;font-size:12px;color:' . $text2 . ';">' . $fng_lbl . '</p>';
        echo '</td>';

        // News sentiment.
        if ( $mood_lbl ) {
            echo '<td class="col-half" width="50%" style="padding:18px 24px;">';
            echo '<p style="margin:0 0 2px;font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:' . $text2 . ';">News Sentiment (7d)</p>';
            echo '<p style="margin:0;font-size:24px;font-weight:800;color:' . esc_attr( $mood_col ) . ';">' . $mood_lbl . '</p>';
            echo '<p style="margin:2px 0 0;font-size:12px;color:' . $text2 . ';">score ' . esc_html( $mood_scr ) . '</p>';
            echo '</td>';
        } else {
            echo '<td width="50%" style="padding:18px 24px;">&nbsp;</td>';
        }

        echo '</tr></table></td></tr>';

        // ── AI NARRATIVE ──
        if ( ! empty( $paragraphs ) ) {
            echo '<tr><td style="background:' . $dark2 . ';padding:24px 32px;border-bottom:1px solid ' . $border . ';">';
            echo '<p style="margin:0 0 14px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:' . $teal . ';">Editor\'s Weekly Take</p>';
            foreach ( $paragraphs as $para ) {
                if ( $para ) {
                    echo '<p style="margin:0 0 14px;font-size:15px;line-height:1.7;color:' . $text1 . ';">' . esc_html( $para ) . '</p>';
                }
            }
            echo '</td></tr>';
        }

        // ── TOP GAINERS / LOSERS ──
        echo '<tr><td style="background:' . $dark3 . ';padding:24px 32px;border-bottom:1px solid ' . $border . ';">';
        echo '<table width="100%" cellpadding="0" cellspacing="0" border="0"><tr>';

        foreach ( array(
            array( '🚀 Top Gainers', $data['top_gainers'], $green, '+' ),
            array( '📉 Top Losers',  $data['top_losers'],  $red,   '' ),
        ) as $col ) :
            echo '<td class="col-half" width="50%" style="vertical-align:top;padding-right:16px;">';
            echo '<p style="margin:0 0 12px;font-size:12px;font-weight:700;color:' . esc_attr( $col[2] ) . ';">' . $col[0] . '</p>';
            if ( empty( $col[1] ) ) {
                echo '<p style="font-size:12px;color:' . $text2 . ';">No data yet</p>';
            } else {
                foreach ( $col[1] as $c ) {
                    $pct  = floatval( $c['price_change_percentage_24h'] ?? 0 );
                    $sign = $pct >= 0 ? '+' : '';
                    echo '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:8px;">';
                    echo '<tr>';
                    echo '<td style="font-size:13px;font-weight:600;color:' . $text1 . ';">' . esc_html( strtoupper( $c['symbol'] ?? '' ) ) . '</td>';
                    echo '<td align="right" class="mover-price" style="font-size:13px;font-weight:700;color:' . esc_attr( $col[2] ) . ';">' . esc_html( $sign . round( $pct, 1 ) ) . '%</td>';
                    echo '</tr>';
                    echo '<tr><td colspan="2" style="font-size:11px;color:' . $text2 . ';">$' . esc_html( number_format( floatval( $c['current_price'] ?? 0 ), 2 ) ) . '</td></tr>';
                    echo '</table>';
                }
            }
            echo '</td>';
        endforeach;

        echo '</tr></table></td></tr>';

        // ── TOP HEADLINES ──
        if ( ! empty( $data['headlines'] ) ) {
            echo '<tr><td style="background:' . $dark2 . ';padding:24px 32px;border-bottom:1px solid ' . $border . ';">';
            echo '<p style="margin:0 0 16px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:' . $teal . ';">Top Headlines This Week</p>';

            foreach ( $data['headlines'] as $h ) {
                $scr = $h['sentiment_score'] !== null ? floatval( $h['sentiment_score'] ) : null;
                $dot_col = $scr === null ? $text2 : ( $scr >= 0.2 ? $green : ( $scr <= -0.2 ? $red : $amber ) );

                echo '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:14px;">';
                echo '<tr>';
                echo '<td width="8" valign="top" style="padding-top:5px;"><span style="font-size:8px;color:' . esc_attr( $dot_col ) . ';">●</span></td>';
                echo '<td style="padding-left:8px;">';
                echo '<a href="' . esc_url( $h['url'] ?? '#' ) . '" style="font-size:14px;font-weight:600;color:' . $text1 . ';text-decoration:none;line-height:1.4;">' . esc_html( $h['title'] ) . '</a>';
                echo '<p style="margin:3px 0 0;font-size:11px;color:' . $text2 . ';">' . esc_html( $h['source'] ?? '' ) . ' · ' . esc_html( human_time_diff( strtotime( $h['published_at'] ?? '' ) ) ) . ' ago</p>';
                echo '</td></tr></table>';
            }
            echo '</td></tr>';
        }

        // ── LATEST SIGNALS ──
        if ( ! empty( $data['signals'] ) ) {
            echo '<tr><td style="background:' . $dark3 . ';padding:24px 32px;border-bottom:1px solid ' . $border . ';">';
            echo '<p style="margin:0 0 16px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:' . $teal . ';">Latest Trading Signals</p>';

            foreach ( $data['signals'] as $s ) {
                $is_bull = preg_match( '/bullish|buy|long|upside|rally/i', $s['title'] ?? '' );
                $is_bear = preg_match( '/bearish|sell|short|downside|drop/i', $s['title'] ?? '' );
                $sig_col = $is_bull ? $green : ( $is_bear ? $red : $text2 );
                $sig_lbl = $is_bull ? '🐂 Bullish' : ( $is_bear ? '🐻 Bearish' : '—' );

                echo '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:12px;background:' . $dark2 . ';border-radius:0;overflow:hidden;">';
                echo '<tr><td style="border-left:3px solid ' . esc_attr( $sig_col ) . ';padding:12px 14px;">';
                echo '<a href="' . esc_url( $s['link'] ?? '#' ) . '" style="font-size:13px;font-weight:600;color:' . $text1 . ';text-decoration:none;">' . esc_html( $s['title'] ?? '' ) . '</a>';
                echo '<table width="100%" cellpadding="0" cellspacing="0" border="0"><tr>';
                echo '<td style="font-size:11px;color:' . $text2 . ';padding-top:4px;">' . esc_html( $s['source'] ?? '' ) . '</td>';
                echo '<td align="right" style="font-size:11px;font-weight:700;color:' . esc_attr( $sig_col ) . ';">' . $sig_lbl . '</td>';
                echo '</tr></table>';
                echo '</td></tr></table>';
            }

            echo '<p style="margin:12px 0 0;text-align:center;">';
            echo '<a href="' . esc_url( home_url( '/trading-signals/' ) ) . '" style="display:inline-block;padding:10px 24px;background:' . $teal . ';color:#0b0f1a;font-size:13px;font-weight:700;border-radius:0;text-decoration:none;">View All Signals →</a>';
            echo '</p>';
            echo '</td></tr>';
        }

        // ── FOREX SNAPSHOT ──
        $key_pairs = array( 'EUR/USD', 'GBP/USD', 'USD/JPY', 'USD/CHF', 'AUD/USD', 'XAU/USD' );
        $fx_rows   = array();
        foreach ( $key_pairs as $pair ) {
            if ( isset( $data['fx_rates'][ $pair ] ) ) {
                $fx_rows[ $pair ] = floatval( $data['fx_rates'][ $pair ] );
            }
        }

        if ( ! empty( $fx_rows ) ) {
            echo '<tr><td style="background:' . $dark2 . ';padding:24px 32px;border-bottom:1px solid ' . $border . ';">';
            echo '<p style="margin:0 0 14px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:' . $teal . ';">Forex Snapshot</p>';
            echo '<table width="100%" cellpadding="0" cellspacing="0" border="0">';

            $fx_chunks = array_chunk( array_keys( $fx_rows ), 3 );
            foreach ( $fx_chunks as $chunk ) {
                echo '<tr>';
                foreach ( $chunk as $pair ) {
                    $rate = $fx_rows[ $pair ];
                    $dec  = ( strpos( $pair, 'JPY' ) !== false ) ? 3 : 5;
                    echo '<td width="33%" style="padding:6px 0;">';
                    echo '<p style="margin:0;font-size:12px;color:' . $text2 . ';">' . esc_html( $pair ) . '</p>';
                    echo '<p style="margin:1px 0 0;font-size:15px;font-weight:700;color:' . $text1 . ';">' . esc_html( number_format( $rate, $dec ) ) . '</p>';
                    echo '</td>';
                }
                // Pad row if needed.
                for ( $pad = count( $chunk ); $pad < 3; $pad++ ) echo '<td width="33%"></td>';
                echo '</tr>';
            }

            echo '</table></td></tr>';
        }

        // ── CTA BUTTON ──
        echo '<tr><td style="background:linear-gradient(135deg,#0f1d32 0%,' . $dark2 . ' 100%);padding:28px 32px;text-align:center;border-bottom:1px solid ' . $border . ';">';
        echo '<p style="margin:0 0 16px;font-size:16px;font-weight:700;color:' . $text1 . ';">Want more live data?</p>';
        echo '<a href="' . esc_url( $home_url ) . '" style="display:inline-block;padding:14px 32px;background:' . $teal . ';color:#0b0f1a;font-size:14px;font-weight:800;border-radius:0;text-decoration:none;letter-spacing:.02em;">Open BlockTicker Live Dashboard →</a>';
        echo '</td></tr>';

        // ── FOOTER ──
        echo '<tr><td style="background:#07090f;border-radius:0 0 16px 16px;padding:20px 32px;text-align:center;">';
        echo '<p style="margin:0 0 6px;font-size:12px;color:' . $text2 . ';">© ' . esc_html( $year ) . ' ' . esc_html( $site ) . ' · <a href="' . esc_url( $home_url ) . '" style="color:' . $teal . ';text-decoration:none;">' . esc_url( $home_url ) . '</a></p>';
        echo '<p style="margin:0;font-size:11px;color:var(--bt-text-3);">You\'re receiving this because you subscribed to ' . esc_html( $site ) . '. ';
        echo '<a href="' . esc_url( $unsub_url ) . '" style="color:var(--bt-text-3);text-decoration:underline;">Unsubscribe</a></p>';
        echo '</td></tr>';

        echo '</table>'; // email-wrap
        echo '</td></tr></table>'; // outer
        echo '</body></html>';

        return ob_get_clean();
    }

    /* ------------------------------------------------------------------
     * Subject line
     * ------------------------------------------------------------------ */

    private static function build_subject( array $data ): string {
        $site    = get_option( 'bt_site_name', 'BlockTicker' );
        $date    = gmdate( 'M j' );
        $btc_chg = $data['btc'] ? round( floatval( $data['btc']['price_change_percentage_24h'] ?? 0 ), 1 ) : null;
        $mood    = $data['mood']['label'] ?? '';

        if ( $btc_chg !== null && $mood ) {
            $btc_str = ( $btc_chg >= 0 ? '+' : '' ) . $btc_chg . '%';
            return "[{$site}] Weekly Digest {$date} · BTC {$btc_str} · Mood: {$mood}";
        }
        return "[{$site}] Weekly Market Digest — {$date}";
    }

    /* ------------------------------------------------------------------
     * AJAX handlers
     * ------------------------------------------------------------------ */

    public static function ajax_preview() {
        check_ajax_referer( 'bt_db_admin', '_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Insufficient permissions.' );

        $data      = self::assemble_data();
        $narrative = self::generate_narrative( $data );
        $html      = self::render_email( $data, $narrative );
        wp_send_json_success( array( 'html' => $html ) );
    }

    public static function ajax_send_now() {
        check_ajax_referer( 'bt_db_admin', '_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Insufficient permissions.' );

        $to_override = sanitize_email( $_POST['test_email'] ?? '' );
        $result = self::send_digest( true, $to_override );
        wp_send_json_success( $result );
    }

    /* ------------------------------------------------------------------
     * Admin panel
     * ------------------------------------------------------------------ */

    public static function admin_panel_html(): string {
        $last_sent  = intval( get_option( self::LAST_SENT_KEY, 0 ) );
        $next_cron  = wp_next_scheduled( self::CRON_HOOK );
        $sub_count  = count( get_option( 'bt_subscribers', array() ) );
        $nonce      = wp_create_nonce( 'bt_db_admin' );

        ob_start(); ?>
        <div class="postbox">
            <div class="postbox-header">
                <h2 class="hndle" style="padding:12px 15px;font-size:14px;">
                    📨 Weekly Newsletter Digest
                </h2>
            </div>
            <div class="inside">
                <table class="widefat striped" style="font-size:13px;margin-bottom:14px;">
                    <tbody>
                        <tr>
                            <th>Subscribers</th>
                            <td><strong><?php echo number_format( $sub_count ); ?></strong></td>
                        </tr>
                        <tr>
                            <th>Last sent</th>
                            <td><?php echo $last_sent ? esc_html( human_time_diff( $last_sent ) . ' ago' ) : '<em>Never</em>'; ?></td>
                        </tr>
                        <tr>
                            <th>Next scheduled</th>
                            <td><?php echo $next_cron ? esc_html( human_time_diff( $next_cron ) . ' from now (Sunday 8 AM)' ) : '<em>Not scheduled</em>'; ?></td>
                        </tr>
                        <tr>
                            <th>AI provider</th>
                            <td><code><?php echo esc_html( get_option( 'bt_ai_provider', 'claude' ) ); ?></code></td>
                        </tr>
                    </tbody>
                </table>

                <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:10px;">
                    <input type="email" id="bt-nl-test-email" placeholder="test@example.com"
                           style="padding:6px 10px;border-radius:0;border:1px solid #ddd;font-size:13px;">
                    <button type="button" class="button" id="bt-nl-send-test"
                            data-nonce="<?php echo esc_attr( $nonce ); ?>">
                        ✉️ Send Test
                    </button>
                    <button type="button" class="button button-primary" id="bt-nl-send-all"
                            data-nonce="<?php echo esc_attr( $nonce ); ?>">
                        📨 Send to All (<?php echo esc_html( $sub_count ); ?> subscribers)
                    </button>
                    <button type="button" class="button" id="bt-nl-preview"
                            data-nonce="<?php echo esc_attr( $nonce ); ?>">
                        👁 Preview HTML
                    </button>
                </div>

                <span id="bt-nl-status" style="font-size:13px;"></span>

                <div id="bt-nl-preview-frame" style="display:none;margin-top:14px;border:1px solid #ddd;border-radius:0;overflow:hidden;">
                    <iframe id="bt-nl-iframe" style="width:100%;height:600px;border:none;background:#0b0f1a;"></iframe>
                </div>

                <script>
                (function(){
                    var nonce   = document.querySelector('#bt-nl-send-test').dataset.nonce;
                    var status  = document.getElementById('bt-nl-status');
                    function setStatus(msg, ok){ status.style.color = ok ? '#00a32a' : '#d63638'; status.textContent = msg; }

                    document.getElementById('bt-nl-send-test').addEventListener('click', function(){
                        var email = document.getElementById('bt-nl-test-email').value;
                        if (!email){ setStatus('Enter a test email address.', false); return; }
                        setStatus('Sending…', true);
                        post({ action:'bt_newsletter_send_now', _nonce:nonce, test_email:email })
                        .then(function(d){ d.success ? setStatus('✅ Sent to ' + email, true) : setStatus('✗ ' + (d.data||'Error'), false); });
                    });

                    document.getElementById('bt-nl-send-all').addEventListener('click', function(){
                        if (!confirm('Send digest to ALL subscribers now?')) return;
                        setStatus('Sending…', true);
                        post({ action:'bt_newsletter_send_now', _nonce:nonce, test_email:'' })
                        .then(function(d){ d.success ? setStatus('✅ Sent: ' + d.data.sent + ', Errors: ' + (d.data.errors||[]).length, true) : setStatus('✗ ' + (d.data||'Error'), false); });
                    });

                    document.getElementById('bt-nl-preview').addEventListener('click', function(){
                        setStatus('Generating preview…', true);
                        post({ action:'bt_newsletter_preview', _nonce:nonce })
                        .then(function(d){
                            if (d.success){
                                var frame = document.getElementById('bt-nl-preview-frame');
                                var iframe = document.getElementById('bt-nl-iframe');
                                frame.style.display = 'block';
                                iframe.contentDocument.open();
                                iframe.contentDocument.write(d.data.html);
                                iframe.contentDocument.close();
                                setStatus('', true);
                            } else {
                                setStatus('✗ ' + (d.data||'Error'), false);
                            }
                        });
                    });

                    function post(data){
                        var fd = new FormData();
                        Object.keys(data).forEach(function(k){ fd.append(k, data[k]); });
                        return fetch(ajaxurl, {method:'POST', body:fd}).then(function(r){ return r.json(); });
                    }
                })();
                </script>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------ */

    private static function next_sunday_8am(): int {
        $tz_offset = intval( get_option( 'gmt_offset', 0 ) ) * HOUR_IN_SECONDS;
        $now       = time() + $tz_offset;
        $dow       = intval( gmdate( 'w', $now ) ); // 0 = Sunday
        $days_ahead = $dow === 0 ? 7 : ( 7 - $dow );
        $next_sun   = mktime( 8, 0, 0, intval( gmdate( 'm', $now ) ), intval( gmdate( 'd', $now ) ) + $days_ahead, intval( gmdate( 'Y', $now ) ) );
        return $next_sun - $tz_offset;
    }

    private static function call_ai( string $provider, string $key, string $prompt ): ?string {
        if ( $provider === 'openai' ) {
            $response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', array(
                'timeout' => 60,
                'headers' => array( 'Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json' ),
                'body'    => wp_json_encode( array(
                    'model'       => 'gpt-4o-mini',
                    'max_tokens'  => 500,
                    'temperature' => 0.65,
                    'messages'    => array( array( 'role' => 'user', 'content' => $prompt ) ),
                ) ),
            ) );
            if ( is_wp_error( $response ) ) return null;
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            return $body['choices'][0]['message']['content'] ?? null;
        }

        // Claude (default).
        $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', array(
            'timeout' => 60,
            'headers' => array(
                'x-api-key'         => $key,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ),
            'body' => wp_json_encode( array(
                'model'      => get_option( 'bt_claude_model', 'claude-haiku-4-5-20251001' ),
                'max_tokens' => 500,
                'messages'   => array( array( 'role' => 'user', 'content' => $prompt ) ),
            ) ),
        ) );
        if ( is_wp_error( $response ) ) return null;
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return $body['content'][0]['text'] ?? null;
    }
}
