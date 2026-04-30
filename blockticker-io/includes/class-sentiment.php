<?php
/**
 * BT_Sentiment — News Sentiment Analysis + Market Mood.
 *
 * Scores every news item in wp_bt_news_items with a sentiment value between
 * -1.0 (extremely bearish) and +1.0 (extremely bullish) using the configured
 * AI provider (Claude or OpenAI). Also extracts symbols mentioned in headlines
 * and populates the symbols_mentioned column for filtering.
 *
 * Architecture:
 *
 *   Cron: bt_score_news_sentiment (twice-daily)
 *     → score_unscored_batch()
 *     → calls AI API with up to BATCH_SIZE headlines in one request
 *     → parses JSON array response
 *     → UPDATEs sentiment_score + symbols_mentioned in wp_bt_news_items
 *
 *   Shortcodes:
 *     [bt_sentiment_bar]          — visual market-mood gauge from recent news
 *     [bt_sentiment_ticker]       — inline text e.g. "Market Mood: Greed (0.42)"
 *
 *   REST: GET /wp-json/blockticker/v1/sentiment
 *     → returns current mood JSON
 *
 *   Filter: bt_news_card_html
 *     → appends a sentiment badge to each news card when show_sentiment is on
 *
 * @package BlockTicker
 * @since   104.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BT_Sentiment {

    /** How many unscored items to process per cron run. */
    const BATCH_SIZE = 40;

    /** Cache key for the current market mood. */
    const MOOD_CACHE_KEY = 'bt_sentiment_mood';

    /** How long to cache the mood (seconds). */
    const MOOD_TTL = 1800; // 30 minutes

    /** Mood labels keyed by threshold (lower-bound inclusive). */
    const MOOD_LABELS = array(
        0.6  => 'Extreme Greed',
        0.25 => 'Greed',
        -0.24 => 'Neutral',
        -0.59 => 'Fear',
        -1.0  => 'Extreme Fear',
    );

    /* ------------------------------------------------------------------
     * Bootstrap
     * ------------------------------------------------------------------ */

    public static function init() {
        // Cron — schedule if not already queued.
        if ( ! wp_next_scheduled( 'bt_score_news_sentiment' ) ) {
            wp_schedule_event( time() + 300, 'bt_twice_daily', 'bt_score_news_sentiment' );
        }
        add_action( 'bt_score_news_sentiment', array( __CLASS__, 'score_unscored_batch' ) );

        // Shortcodes.
        add_shortcode( 'bt_sentiment_bar',    array( __CLASS__, 'sc_sentiment_bar' ) );
        add_shortcode( 'bt_sentiment_ticker', array( __CLASS__, 'sc_sentiment_ticker' ) );

        // REST endpoint.
        add_action( 'rest_api_init', array( __CLASS__, 'register_rest_route' ) );

        // Invalidate mood cache when new news is stored.
        add_action( 'bt_news_stored', array( __CLASS__, 'invalidate_mood_cache' ) );
    }

    public static function deactivate() {
        wp_clear_scheduled_hook( 'bt_score_news_sentiment' );
    }

    /* ------------------------------------------------------------------
     * Core scoring
     * ------------------------------------------------------------------ */

    /**
     * Fetch up to BATCH_SIZE unscored rows, call the AI, save results.
     *
     * @return array  { scored: int, errors: int }
     */
    public static function score_unscored_batch() {
        global $wpdb;

        $provider = get_option( 'bt_ai_provider', 'claude' );
        $api_key  = $provider === 'openai'
            ? get_option( 'bt_openai_key', '' )
            : get_option( 'bt_claude_key', '' );

        if ( empty( $api_key ) ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log( '[BT Sentiment] No AI API key configured — skipping batch.' );
            return array( 'scored' => 0, 'errors' => 1 );
        }

        // Fetch unscored rows — oldest first so we fill history forward.
        $table = $wpdb->prefix . 'bt_news_items';
        $rows  = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, title, summary FROM {$table}
             WHERE sentiment_score IS NULL
             ORDER BY published_at ASC
             LIMIT %d",
            self::BATCH_SIZE
        ), ARRAY_A );

        if ( empty( $rows ) ) {
            return array( 'scored' => 0, 'errors' => 0 );
        }

        // Build prompt — ask for a JSON array in the same order.
        $items_json = wp_json_encode( array_map( function( $r ) {
            return array(
                'id'      => (int) $r['id'],
                'title'   => $r['title'],
                'summary' => mb_substr( $r['summary'], 0, 280 ),
            );
        }, $rows ) );

        $prompt = <<<PROMPT
You are a financial market sentiment analyst.

I will give you a JSON array of news items. For each item return a JSON array (same order, same length) where every element is an object with:
  "id"       : the same integer id
  "score"    : a float from -1.0 (extremely bearish / negative for markets) to +1.0 (extremely bullish / positive), with 0.0 being neutral
  "symbols"  : a comma-separated string of ticker symbols explicitly mentioned (e.g. "BTC,ETH,EUR/USD") — empty string if none

Rules:
- Evaluate from the perspective of crypto and forex market impact.
- Score based on the title AND the summary together.
- Be precise — use the full range, not just -1/0/+1.
- Return ONLY the JSON array. No commentary, no markdown fences.

News items:
{$items_json}
PROMPT;

        $raw = $provider === 'openai'
            ? self::call_openai( $api_key, $prompt )
            : self::call_claude( $api_key, $prompt );

        if ( ! $raw ) {
            return array( 'scored' => 0, 'errors' => 1 );
        }

        // Strip any accidental markdown fences.
        $raw = trim( preg_replace( '/^```(?:json)?\s*/i', '', preg_replace( '/\s*```$/i', '', trim( $raw ) ) ) );

        $results = json_decode( $raw, true );
        if ( ! is_array( $results ) ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log( '[BT Sentiment] Unparseable AI response: ' . substr( $raw, 0, 200 ) );
            return array( 'scored' => 0, 'errors' => 1 );
        }

        $scored = 0;
        $errors = 0;
        foreach ( $results as $result ) {
            $id      = (int) ( $result['id'] ?? 0 );
            $score   = isset( $result['score'] ) ? floatval( $result['score'] ) : null;
            $symbols = sanitize_text_field( $result['symbols'] ?? '' );

            if ( ! $id || $score === null ) {
                $errors++;
                continue;
            }

            // Clamp to [-1.0, 1.0] and round to 3 decimal places.
            $score = round( max( -1.0, min( 1.0, $score ) ), 3 );

            $updated = $wpdb->update(
                $table,
                array(
                    'sentiment_score'   => $score,
                    'symbols_mentioned' => $symbols,
                ),
                array( 'id' => $id ),
                array( '%f', '%s' ),
                array( '%d' )
            );

            if ( $updated !== false ) {
                $scored++;
            } else {
                $errors++;
            }
        }

        // Bust mood cache now that new scores are in.
        self::invalidate_mood_cache();

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log( "[BT Sentiment] Batch complete — scored: {$scored}, errors: {$errors}" );

        return compact( 'scored', 'errors' );
    }

    /* ------------------------------------------------------------------
     * Market mood calculation
     * ------------------------------------------------------------------ */

    /**
     * Calculate weighted-average sentiment from the last 24 hours of scored news.
     *
     * Recency weighting: items in the last 6 hours count 3×, last 12 hours 2×,
     * last 24 hours 1×.
     *
     * @param  int   $hours  Look-back window in hours (default 24).
     * @return array { score: float, label: string, count: int, scored_pct: float }
     */
    public static function get_market_mood( $hours = 24 ) {
        $cached = get_transient( self::MOOD_CACHE_KEY . '_' . $hours );
        if ( $cached !== false ) {
            return $cached;
        }

        global $wpdb;
        $table  = $wpdb->prefix . 'bt_news_items';
        $since  = gmdate( 'Y-m-d H:i:s', time() - ( $hours * HOUR_IN_SECONDS ) );
        $since6 = gmdate( 'Y-m-d H:i:s', time() - ( 6 * HOUR_IN_SECONDS ) );
        $since12 = gmdate( 'Y-m-d H:i:s', time() - ( 12 * HOUR_IN_SECONDS ) );

        // Total items in window (for scored% calculation).
        $total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE published_at >= %s",
            $since
        ) );

        // Scored items with recency weight.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT sentiment_score, published_at FROM {$table}
             WHERE published_at >= %s AND sentiment_score IS NOT NULL
             ORDER BY published_at DESC",
            $since
        ), ARRAY_A );

        if ( empty( $rows ) ) {
            return array( 'score' => 0.0, 'label' => 'Neutral', 'count' => 0, 'scored_pct' => 0 );
        }

        $weighted_sum    = 0.0;
        $weight_total    = 0.0;

        foreach ( $rows as $row ) {
            $pub    = $row['published_at'];
            $score  = (float) $row['sentiment_score'];
            $weight = $pub >= $since6 ? 3.0 : ( $pub >= $since12 ? 2.0 : 1.0 );

            $weighted_sum += $score * $weight;
            $weight_total += $weight;
        }

        $avg_score  = $weight_total > 0 ? round( $weighted_sum / $weight_total, 3 ) : 0.0;
        $label      = self::score_to_label( $avg_score );
        $scored_pct = $total > 0 ? round( ( count( $rows ) / $total ) * 100, 1 ) : 0;

        $result = array(
            'score'      => $avg_score,
            'label'      => $label,
            'count'      => count( $rows ),
            'total'      => $total,
            'scored_pct' => $scored_pct,
        );

        set_transient( self::MOOD_CACHE_KEY . '_' . $hours, $result, self::MOOD_TTL );

        return $result;
    }

    /**
     * Map a numeric score to a human label.
     */
    public static function score_to_label( $score ) {
        if ( $score >= 0.6 )  return 'Extreme Greed';
        if ( $score >= 0.25 ) return 'Greed';
        if ( $score >= -0.24 ) return 'Neutral';
        if ( $score >= -0.59 ) return 'Fear';
        return 'Extreme Fear';
    }

    public static function invalidate_mood_cache() {
        foreach ( array( 6, 12, 24, 48 ) as $h ) {
            delete_transient( self::MOOD_CACHE_KEY . '_' . $h );
        }
    }

    /* ------------------------------------------------------------------
     * Shortcode: [bt_sentiment_bar]
     * ------------------------------------------------------------------ */

    /**
     * [bt_sentiment_bar hours="24" show_breakdown="1" show_score="1"]
     *
     * Renders a visual sentiment gauge bar. Similar aesthetic to Fear & Greed
     * index but derived entirely from BlockTicker's own news pipeline.
     */
    public static function sc_sentiment_bar( $atts ) {
        $a = shortcode_atts( array(
            'hours'          => 24,
            'show_breakdown' => 1,
            'show_score'     => 1,
            'title'          => 'Market Mood',
        ), $atts );

        $hours = max( 1, min( 168, intval( $a['hours'] ) ) );
        $mood  = self::get_market_mood( $hours );

        $score     = $mood['score'];        // -1.0 to +1.0
        $label     = $mood['label'];
        $count     = $mood['count'];
        $pct       = $mood['scored_pct'];

        // Map -1…+1 to 0…100 for CSS bar position.
        $bar_pct   = (int) round( ( $score + 1.0 ) / 2.0 * 100 );
        $bar_pct   = max( 2, min( 98, $bar_pct ) );

        // Colour zone.
        $colour    = self::score_to_colour( $score );
        $icon      = self::score_to_icon( $score );

        ob_start(); ?>
        <div class="bt-sentiment-bar-wrap" style="--bt-sent-colour:<?php echo esc_attr( $colour ); ?>">
            <?php if ( $a['title'] ) : ?>
            <p class="bt-sentiment-title">
                <?php echo esc_html( $a['title'] ); ?>
                <span class="bt-sentiment-window">(<?php echo esc_html( $hours ); ?>h)</span>
            </p>
            <?php endif; ?>

            <div class="bt-sentiment-gauge">
                <div class="bt-sentiment-track">
                    <div class="bt-sentiment-zones">
                        <span class="bt-sz-ef">Extreme Fear</span>
                        <span class="bt-sz-f">Fear</span>
                        <span class="bt-sz-n">Neutral</span>
                        <span class="bt-sz-g">Greed</span>
                        <span class="bt-sz-eg">Extreme Greed</span>
                    </div>
                    <div class="bt-sentiment-fill" style="width:<?php echo esc_attr( $bar_pct ); ?>%"></div>
                    <div class="bt-sentiment-needle" style="left:<?php echo esc_attr( $bar_pct ); ?>%">
                        <span class="bt-sn-icon"><?php echo $icon; // phpcs:ignore ?></span>
                    </div>
                </div>
                <div class="bt-sentiment-label">
                    <span class="bt-sl-text"><?php echo esc_html( $label ); ?></span>
                    <?php if ( $a['show_score'] ) : ?>
                    <span class="bt-sl-score"><?php echo esc_html( ( $score >= 0 ? '+' : '' ) . number_format( $score, 2 ) ); ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ( $a['show_breakdown'] && $count > 0 ) : ?>
            <p class="bt-sentiment-meta">
                <?php printf(
                    /* translators: 1: count of scored articles, 2: time window in hours, 3: percentage scored */
                    esc_html__( 'Based on %1$d articles in the last %2$dh (%3$s%% scored)', 'blockticker' ),
                    esc_html( $count ),
                    esc_html( $hours ),
                    esc_html( $pct )
                ); ?>
            </p>
            <?php elseif ( $count === 0 ) : ?>
            <p class="bt-sentiment-meta bt-sentiment-pending">
                <?php esc_html_e( 'Scoring in progress — check back shortly.', 'blockticker' ); ?>
            </p>
            <?php endif; ?>
        </div>

        <style>
        .bt-sentiment-bar-wrap{font-family:inherit;margin:16px 0}
        .bt-sentiment-title{font-size:13px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#888;margin:0 0 8px}
        .bt-sentiment-window{font-weight:400;font-size:11px;margin-left:4px}
        .bt-sentiment-gauge{position:relative;padding-bottom:28px}
        .bt-sentiment-track{position:relative;height:18px;border-radius:9px;overflow:visible;
            background:linear-gradient(to right,#d63638 0%,#e6972b 25%,#c5c5c5 50%,#5bc15b 75%,#00a32a 100%)}
        .bt-sentiment-zones{display:flex;justify-content:space-between;font-size:9px;
            color:rgba(255,255,255,.8);position:absolute;inset:0;align-items:center;padding:0 6px;pointer-events:none}
        .bt-sentiment-fill{height:100%;background:rgba(0,0,0,.15);border-radius:9px 0 0 9px;pointer-events:none}
        .bt-sentiment-needle{position:absolute;top:-6px;transform:translateX(-50%);z-index:2}
        .bt-sn-icon{display:block;font-size:22px;filter:drop-shadow(0 1px 2px rgba(0,0,0,.3))}
        .bt-sentiment-label{display:flex;align-items:baseline;gap:8px;margin-top:10px}
        .bt-sl-text{font-size:18px;font-weight:700;color:var(--bt-sent-colour)}
        .bt-sl-score{font-size:14px;color:#888;font-variant-numeric:tabular-nums}
        .bt-sentiment-meta{font-size:11px;color:#888;margin:6px 0 0}
        .bt-sentiment-pending{font-style:italic}
        </style>
        <?php
        return ob_get_clean();
    }

    /* ------------------------------------------------------------------
     * Shortcode: [bt_sentiment_ticker]
     * ------------------------------------------------------------------ */

    /**
     * [bt_sentiment_ticker hours="24"]
     *
     * Inline one-liner: "Market Mood: Greed (+0.42) · 84 articles"
     * Useful in sidebars, widget areas, or inside other shortcodes.
     */
    public static function sc_sentiment_ticker( $atts ) {
        $a     = shortcode_atts( array( 'hours' => 24 ), $atts );
        $mood  = self::get_market_mood( intval( $a['hours'] ) );
        $score = $mood['score'];
        $col   = self::score_to_colour( $score );

        return sprintf(
            '<span class="bt-sentiment-ticker">Market Mood: <strong style="color:%s">%s</strong>'
            . ' <span style="color:#888;font-size:.9em">(%s%s)</span>'
            . ' <span class="bt-st-sep">·</span> <span style="color:#888">%d articles</span></span>',
            esc_attr( $col ),
            esc_html( $mood['label'] ),
            $score >= 0 ? '+' : '',
            esc_html( number_format( $score, 2 ) ),
            esc_html( $mood['count'] )
        );
    }

    /* ------------------------------------------------------------------
     * REST endpoint
     * ------------------------------------------------------------------ */

    public static function register_rest_route() {
        register_rest_route( 'blockticker/v1', '/sentiment', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'rest_sentiment' ),
            'permission_callback' => '__return_true',
        ) );
    }

    public static function rest_sentiment( WP_REST_Request $request ) {
        $hours = max( 1, min( 168, intval( $request->get_param( 'hours' ) ?: 24 ) ) );
        $mood  = self::get_market_mood( $hours );

        return new WP_REST_Response( array(
            'data'   => $mood,
            'meta'   => array( 'generated_at' => gmdate( 'c' ), 'hours' => $hours ),
            'source' => 'BlockTicker sentiment analysis',
        ), 200 );
    }

    /* ------------------------------------------------------------------
     * Admin panel HTML
     * ------------------------------------------------------------------ */

    /**
     * Render the Sentiment Scoring panel for BlockTicker → 🗄 Database.
     */
    public static function admin_panel_html() {
        global $wpdb;
        $table  = $wpdb->prefix . 'bt_news_items';
        $total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        $scored = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE sentiment_score IS NOT NULL" );
        $pct    = $total > 0 ? round( ( $scored / $total ) * 100 ) : 0;
        $mood   = self::get_market_mood( 24 );

        ob_start(); ?>
        <div class="postbox">
            <div class="postbox-header">
                <h2 class="hndle" style="padding:12px 15px;font-size:14px;">
                    🧠 Sentiment Scoring
                </h2>
            </div>
            <div class="inside">
                <table class="widefat striped" style="font-size:13px;margin-bottom:12px;">
                    <tbody>
                        <tr>
                            <th>News items scored</th>
                            <td>
                                <strong><?php echo number_format( $scored ); ?></strong>
                                / <?php echo number_format( $total ); ?>
                                (<?php echo esc_html( $pct ); ?>%)
                                <?php if ( $pct < 100 ) : ?>
                                <br><small style="color:#888"><?php echo number_format( $total - $scored ); ?> unscored — next batch runs on <code>bt_score_news_sentiment</code> cron</small>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th>Current mood (24h)</th>
                            <td>
                                <strong><?php echo esc_html( $mood['label'] ); ?></strong>
                                &nbsp;<code><?php echo esc_html( ( $mood['score'] >= 0 ? '+' : '' ) . number_format( $mood['score'], 3 ) ); ?></code>
                                &nbsp;<small style="color:#888">(<?php echo esc_html( $mood['count'] ); ?> articles)</small>
                            </td>
                        </tr>
                        <tr>
                            <th>AI provider</th>
                            <td><code><?php echo esc_html( get_option( 'bt_ai_provider', 'claude' ) ); ?></code>
                            — batch size <?php echo esc_html( self::BATCH_SIZE ); ?> items/run</td>
                        </tr>
                    </tbody>
                </table>
                <button type="button" id="bt-sentiment-run-btn" class="button button-primary"
                        data-nonce="<?php echo esc_attr( wp_create_nonce( 'bt_db_admin' ) ); ?>">
                    ▶ Score Next Batch Now
                </button>
                <span id="bt-sentiment-status" style="margin-left:10px;font-size:13px;"></span>
                <script>
                (function(){
                    var btn = document.getElementById('bt-sentiment-run-btn');
                    var st  = document.getElementById('bt-sentiment-status');
                    if (!btn) return;
                    btn.addEventListener('click', function(){
                        btn.disabled = true; st.textContent = 'Running…';
                        fetch(ajaxurl, {
                            method:'POST',
                            headers:{'Content-Type':'application/x-www-form-urlencoded'},
                            body:'action=bt_sentiment_run_batch&_nonce='+btn.dataset.nonce
                        }).then(r=>r.json()).then(function(d){
                            if (d.success) {
                                st.style.color = '#00a32a';
                                st.textContent = '✅ Scored: '+d.data.scored+' items, Errors: '+d.data.errors;
                            } else {
                                st.style.color = '#d63638';
                                st.textContent = '✗ '+(d.data||'Unknown error');
                            }
                            btn.disabled = false;
                        });
                    });
                })();
                </script>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ------------------------------------------------------------------
     * AJAX handler — manual batch trigger from admin panel
     * ------------------------------------------------------------------ */

    public static function ajax_run_batch() {
        check_ajax_referer( 'bt_db_admin', '_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions.' );
        }
        $result = self::score_unscored_batch();
        wp_send_json_success( $result );
    }

    /* ------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------ */

    private static function score_to_colour( $score ) {
        if ( $score >= 0.6 )   return '#00a32a';  // Extreme Greed — dark green
        if ( $score >= 0.25 )  return '#5bc15b';  // Greed — green
        if ( $score >= -0.24 ) return '#888888';  // Neutral — grey
        if ( $score >= -0.59 ) return '#e6972b';  // Fear — amber
        return '#d63638';                          // Extreme Fear — red
    }

    private static function score_to_icon( $score ) {
        if ( $score >= 0.6 )   return '🚀';
        if ( $score >= 0.25 )  return '😀';
        if ( $score >= -0.24 ) return '😐';
        if ( $score >= -0.59 ) return '😰';
        return '💀';
    }

    /* ------------------------------------------------------------------
     * AI API calls (self-contained — same pattern as BT_AIBlog)
     * ------------------------------------------------------------------ */

    private static function call_claude( $key, $prompt ) {
        $model    = get_option( 'bt_claude_model', 'claude-haiku-4-5-20251001' ); // Haiku: fast + cheap for batch scoring
        $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', array(
            'timeout' => 60,
            'headers' => array(
                'x-api-key'         => $key,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ),
            'body' => wp_json_encode( array(
                'model'      => $model,
                'max_tokens' => 2000,
                'messages'   => array(
                    array( 'role' => 'user', 'content' => $prompt ),
                ),
            ) ),
        ) );

        if ( is_wp_error( $response ) ) return false;
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! empty( $body['error'] ) ) return false;
        return $body['content'][0]['text'] ?? false;
    }

    private static function call_openai( $key, $prompt ) {
        $model    = 'gpt-4o-mini'; // mini: cheap for batch classification
        $response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', array(
            'timeout' => 60,
            'headers' => array(
                'Authorization' => 'Bearer ' . $key,
                'Content-Type'  => 'application/json',
            ),
            'body' => wp_json_encode( array(
                'model'       => $model,
                'max_tokens'  => 2000,
                'temperature' => 0.1, // low temp for consistent classification
                'messages'    => array(
                    array( 'role' => 'user', 'content' => $prompt ),
                ),
            ) ),
        ) );

        if ( is_wp_error( $response ) ) return false;
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! empty( $body['error'] ) ) return false;
        return $body['choices'][0]['message']['content'] ?? false;
    }
}
