<?php
/**
 * BlockTicker Personalized AI Brief — v119.22.0
 *
 * Phase 2 flagship: daily AI-generated market brief personalized to each
 * logged-in user's watchlist, saved screeners, and following list.
 *
 * Architecture:
 *   1. gather_user_context()    — pulls watchlist (user meta), screener names,
 *                                 and followed assets from the three subsystems
 *                                 shipped in v119.15–v119.17.
 *   2. build_market_snapshot()  — filters bt_crypto_data to just the user's
 *                                 assets + news items matching their follows.
 *   3. call_claude()            — sends a compact prompt to Claude Sonnet;
 *                                 falls back gracefully if key not set.
 *   4. Cache layer              — per-user transient, 1 hour TTL.
 *   5. Shortcode renderer       — [bt_personalized_brief] — dark card with
 *                                 refresh button, login gate for guests.
 *
 * Shortcodes:
 *   [bt_personalized_brief]           Full daily brief card.
 *   [bt_personalized_brief_compact]   Condensed 2-line teaser for sidebar/dashboard.
 *
 * @package BlockTicker
 * @since   119.22.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BT_PersonalizedBrief {

    /** Transient prefix — one per user_id. */
    const CACHE_PREFIX = 'bt_pb_';

    /** Cache TTL in seconds. */
    const CACHE_TTL = HOUR_IN_SECONDS;

    /** Claude model. */
    const CLAUDE_MODEL = 'claude-sonnet-4-20250514';

    /** Max assets to include in context (keeps prompt token count sane). */
    const MAX_ASSETS = 12;

    /** Max news items to include in context. */
    const MAX_NEWS = 8;

    // ── Bootstrap ─────────────────────────────────────────────────────────────

    public static function init() {
        add_shortcode( 'bt_personalized_brief',         array( __CLASS__, 'sc_brief' ) );
        add_shortcode( 'bt_personalized_brief_compact', array( __CLASS__, 'sc_brief_compact' ) );
        add_action( 'wp_ajax_bt_refresh_personalized_brief', array( __CLASS__, 'ajax_refresh' ) );
    }

    // ── User context ──────────────────────────────────────────────────────────

    /**
     * Build the user's personal asset universe from three sources.
     *
     * @param int $user_id
     * @return array {
     *   watchlist : string[]   coin IDs / symbols from bt_watchlist meta
     *   screeners : string[]   screener names (criteria labels) the user saved
     *   following : string[]   followed asset symbols from BT_Following
     *   combined  : string[]   de-duped union of all above (uppercase)
     * }
     */
    private static function gather_user_context( $user_id ) {
        // 1. Watchlist — stored as JSON array in user meta
        $wl_raw  = get_user_meta( $user_id, 'bt_watchlist', true );
        $watchlist = array();
        if ( is_string( $wl_raw ) ) {
            $decoded = json_decode( $wl_raw, true );
            if ( is_array( $decoded ) ) {
                foreach ( $decoded as $item ) {
                    $sym = is_array( $item ) ? ( $item['symbol'] ?? $item['id'] ?? '' ) : (string) $item;
                    if ( $sym ) $watchlist[] = strtoupper( trim( $sym ) );
                }
            }
        }

        // 2. Screeners — collect the asset symbols that each screener matches
        $screener_assets = array();
        $screener_names  = array();
        if ( class_exists( 'BT_Screeners' ) ) {
            $screeners = BT_Screeners::get_user_screeners( $user_id );
            foreach ( $screeners as $s ) {
                $screener_names[] = $s['name'] ?? 'Unnamed screener';
                // If the screener saved matched results, extract symbols
                if ( ! empty( $s['results'] ) && is_array( $s['results'] ) ) {
                    foreach ( array_slice( $s['results'], 0, 5 ) as $r ) {
                        $sym = $r['symbol'] ?? '';
                        if ( $sym ) $screener_assets[] = strtoupper( $sym );
                    }
                }
            }
        }

        // 3. Following — assets sub-array
        $followed_assets = array();
        if ( class_exists( 'BT_Following' ) ) {
            $follows = BT_Following::get_following( $user_id );
            $assets  = $follows['assets'] ?? array();
            foreach ( $assets as $a ) {
                $sym = is_array( $a ) ? ( $a['symbol'] ?? $a['id'] ?? '' ) : (string) $a;
                if ( $sym ) $followed_assets[] = strtoupper( trim( $sym ) );
            }
        }

        $combined = array_values( array_unique( array_merge( $watchlist, $screener_assets, $followed_assets ) ) );

        return array(
            'watchlist'       => $watchlist,
            'screener_names'  => $screener_names,
            'following'       => $followed_assets,
            'combined'        => $combined,
        );
    }

    /**
     * Slice the global crypto data to the user's asset universe.
     *
     * @param array $symbols Uppercase symbols to include.
     * @return array Filtered rows from bt_crypto_data.
     */
    private static function build_market_snapshot( array $symbols ) {
        if ( empty( $symbols ) ) return array();
        $all = BT_Utils::get_option_json( 'fxlm_crypto_data' );
        if ( ! is_array( $all ) ) $all = array();

        $upper_set = array_flip( $symbols );
        $filtered  = array();
        foreach ( $all as $coin ) {
            $sym = strtoupper( $coin['symbol'] ?? '' );
            if ( isset( $upper_set[ $sym ] ) ) {
                $filtered[] = array(
                    'symbol'        => $sym,
                    'name'          => $coin['name'] ?? $sym,
                    'price_usd'     => $coin['price'] ?? $coin['price_usd'] ?? 0,
                    'change_24h'    => $coin['change_24h'] ?? $coin['percent_change_24h'] ?? 0,
                    'change_7d'     => $coin['change_7d'] ?? $coin['percent_change_7d'] ?? 0,
                    'market_cap'    => $coin['market_cap'] ?? 0,
                    'volume_24h'    => $coin['volume'] ?? $coin['volume_24h'] ?? 0,
                );
            }
            if ( count( $filtered ) >= self::MAX_ASSETS ) break;
        }

        // Fill remaining slots from watchlist order if not yet hit
        return $filtered;
    }

    /**
     * Pull latest news items mentioning any of the user's assets.
     *
     * @param array $symbols
     * @return array
     */
    private static function build_news_snapshot( array $symbols ) {
        if ( empty( $symbols ) ) return array();
        $all_news = BT_Utils::get_option_json( 'fxlm_news_items' );
        if ( ! is_array( $all_news ) ) return array();

        $pattern = implode( '|', array_map( 'preg_quote', $symbols ) );
        $matched  = array();
        foreach ( $all_news as $item ) {
            $title = $item['title'] ?? '';
            if ( preg_match( '/\b(' . $pattern . ')\b/i', $title ) ) {
                $matched[] = array(
                    'title'  => $title,
                    'source' => $item['source'] ?? '',
                    'age'    => $item['age'] ?? '',
                );
                if ( count( $matched ) >= self::MAX_NEWS ) break;
            }
        }

        return $matched;
    }

    // ── Claude call ───────────────────────────────────────────────────────────

    /**
     * Call Claude Sonnet to produce a personalized brief paragraph.
     *
     * @param int    $user_id
     * @param array  $context   Output of gather_user_context().
     * @param array  $snapshot  Filtered market data rows.
     * @param array  $news      Filtered news items.
     * @return array { success:bool, text:string, error:string }
     */
    private static function call_claude( $user_id, array $context, array $snapshot, array $news ) {
        $api_key = get_option( 'bt_claude_key', '' );
        if ( ! $api_key ) {
            return array(
                'success' => false,
                'text'    => '',
                'error'   => 'no_key',
            );
        }

        // Build market table string
        $market_lines = array();
        foreach ( $snapshot as $c ) {
            $ch24 = round( (float) $c['change_24h'], 2 );
            $ch7d = round( (float) $c['change_7d'], 2 );
            $sign24 = $ch24 >= 0 ? '+' : '';
            $sign7d = $ch7d >= 0 ? '+' : '';
            $market_lines[] = sprintf(
                '%s (%s): $%s | 24h %s%s%% | 7d %s%s%%',
                $c['symbol'], $c['name'],
                number_format( (float) $c['price_usd'], $c['price_usd'] > 100 ? 2 : 4 ),
                $sign24, $ch24, $sign7d, $ch7d
            );
        }

        $news_lines = array();
        foreach ( $news as $n ) {
            $news_lines[] = '• ' . $n['title'] . ( $n['source'] ? ' (' . $n['source'] . ')' : '' );
        }

        $portfolio_desc = empty( $context['combined'] )
            ? 'no specific assets tracked yet'
            : implode( ', ', array_slice( $context['combined'], 0, 10 ) );

        $screener_desc = empty( $context['screener_names'] )
            ? ''
            : ' Their saved screeners are: ' . implode( ', ', $context['screener_names'] ) . '.';

        $market_block = $market_lines ? implode( "\n", $market_lines ) : 'No price data available for tracked assets.';
        $news_block   = $news_lines   ? implode( "\n", $news_lines )   : 'No recent news matching tracked assets.';

        $prompt = <<<PROMPT
You are a concise crypto & forex market analyst for BlockTicker. Write a personalized daily brief for a user who is tracking: {$portfolio_desc}.{$screener_desc}

Current data for their tracked assets:
{$market_block}

Recent headlines about their assets:
{$news_block}

Write 3 short paragraphs (2-3 sentences each) in plain English:
1. A market overview for their specific holdings (note standouts — best/worst performers).
2. Any actionable observation or risk to watch (don't give financial advice, but give a data-driven observation).
3. A brief note on relevant macro context (DXY, broad risk sentiment) if it affects their assets.

Tone: professional, direct, data-grounded. No hype. No generic boilerplate. Do NOT start sentences with "I". Do not repeat the user's asset list verbatim. Write as if this was written fresh for them today.
PROMPT;

        $response = wp_remote_post(
            'https://api.anthropic.com/v1/messages',
            array(
                'timeout' => 30,
                'headers' => array(
                    'x-api-key'         => $api_key,
                    'anthropic-version' => '2023-06-01',
                    'content-type'      => 'application/json',
                ),
                'body' => wp_json_encode( array(
                    'model'      => self::CLAUDE_MODEL,
                    'max_tokens' => 400,
                    'messages'   => array(
                        array( 'role' => 'user', 'content' => $prompt ),
                    ),
                ) ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return array( 'success' => false, 'text' => '', 'error' => $response->get_error_message() );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $text = $body['content'][0]['text'] ?? '';
        if ( ! $text ) {
            $err = $body['error']['message'] ?? 'Empty response from AI';
            return array( 'success' => false, 'text' => '', 'error' => $err );
        }

        return array( 'success' => true, 'text' => trim( $text ), 'error' => '' );
    }

    // ── Cache layer ───────────────────────────────────────────────────────────

    private static function cache_key( $user_id ) {
        return self::CACHE_PREFIX . (int) $user_id . '_' . gmdate( 'Ymd' );
    }

    /**
     * Generate (or return cached) the brief for a user.
     *
     * @param int  $user_id
     * @param bool $force   Bypass cache.
     * @return array { text, context, generated_at, cached, error }
     */
    public static function get_brief( $user_id, $force = false ) {
        $cache_key = self::cache_key( $user_id );

        if ( ! $force ) {
            $cached = get_transient( $cache_key );
            if ( $cached ) {
                $cached['cached'] = true;
                return $cached;
            }
        }

        $context  = self::gather_user_context( $user_id );
        $snapshot = self::build_market_snapshot( $context['combined'] );
        $news     = self::build_news_snapshot( $context['combined'] );

        $result = self::call_claude( $user_id, $context, $snapshot, $news );

        $payload = array(
            'text'         => $result['text'],
            'context'      => $context,
            'snapshot'     => $snapshot,
            'generated_at' => gmdate( 'Y-m-d H:i' ) . ' UTC',
            'cached'       => false,
            'error'        => $result['error'] ?? '',
        );

        if ( $result['success'] ) {
            set_transient( $cache_key, $payload, self::CACHE_TTL );
        }

        return $payload;
    }

    // ── AJAX refresh ──────────────────────────────────────────────────────────

    public static function ajax_refresh() {
        if ( ! check_ajax_referer( 'bt_pb_nonce', 'nonce', false ) ) {
            wp_send_json_error( 'Invalid nonce.' );
        }
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_error( 'Not logged in.' );
        }
        $brief = self::get_brief( $user_id, true );
        if ( ! $brief['text'] ) {
            wp_send_json_error( $brief['error'] ?: 'Could not generate brief.' );
        }
        wp_send_json_success( array( 'text' => $brief['text'], 'generated_at' => $brief['generated_at'] ) );
    }

    // ── Shortcodes ────────────────────────────────────────────────────────────

    public static function sc_brief( $atts ) {
        $user_id = get_current_user_id();

        if ( ! $user_id ) {
            return '<div class="bt-pb-card bt-pb-login-gate" style="background:#0d0f12;border:1px solid rgba(0,255,102,.2);border-radius:0;padding:32px 24px;text-align:center;font-family:\'IBM Plex Mono\',monospace">
                <div style="font-size:28px;margin-bottom:12px">📊</div>
                <div style="font-size:16px;font-weight:700;color:#fff;margin-bottom:8px">Your Personalized AI Brief</div>
                <div style="color:rgba(255,255,255,.5);font-size:13px;margin-bottom:20px">Sign in to get a daily AI-generated brief tailored to your watchlist, screeners &amp; follows.</div>
                <a href="' . esc_url( wp_login_url( get_permalink() ) ) . '" class="bt-btn-primary" style="display:inline-block;background:#00ff66;color:#000;font-weight:700;font-size:13px;padding:10px 24px;text-decoration:none">Sign In →</a>
            </div>';
        }

        $brief   = self::get_brief( $user_id );
        $nonce   = wp_create_nonce( 'bt_pb_nonce' );
        $context = $brief['context'] ?? array();
        $has_assets = ! empty( $context['combined'] );

        ob_start();
        ?>
        <div class="bt-pb-card" id="bt-pb-card" style="background:#0d0f12;border:1px solid rgba(0,255,102,.2);border-radius:0;padding:28px 24px;font-family:'IBM Plex Mono',monospace">
            <!-- Header -->
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px">
                <div>
                    <div style="display:flex;align-items:center;gap:10px">
                        <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#00ff66;box-shadow:0 0 6px #00ff66;animation:bt-blink 2s infinite"></span>
                        <span style="font-size:11px;font-weight:700;color:#00ff66;letter-spacing:.12em;text-transform:uppercase">Your Daily AI Brief</span>
                    </div>
                    <?php if ( $brief['generated_at'] ) : ?>
                        <div style="font-size:11px;color:rgba(255,255,255,.35);margin-top:4px">Generated <?php echo esc_html( $brief['generated_at'] ); ?></div>
                    <?php endif; ?>
                </div>
                <button id="bt-pb-refresh" data-nonce="<?php echo esc_attr( $nonce ); ?>"
                    style="background:rgba(0,255,102,.1);border:1px solid rgba(0,255,102,.25);color:#00ff66;font-family:inherit;font-size:12px;font-weight:700;padding:6px 14px;cursor:pointer;letter-spacing:.06em">
                    ↻ Refresh
                </button>
            </div>

            <?php if ( ! $has_assets ) : ?>
            <!-- Empty state -->
            <div style="text-align:center;padding:24px 0;color:rgba(255,255,255,.4)">
                <div style="font-size:32px;margin-bottom:12px">📋</div>
                <div style="font-size:14px;margin-bottom:8px;color:#fff">No assets tracked yet</div>
                <div style="font-size:13px">Add coins to your <a href="/watchlist/" style="color:#00ff66">Watchlist</a>, save a <a href="/screeners/" style="color:#00ff66">Screener</a>, or <a href="/following/" style="color:#00ff66">Follow</a> assets to get a personalized brief.</div>
            </div>
            <?php elseif ( $brief['error'] === 'no_key' ) : ?>
            <!-- No API key -->
            <div style="text-align:center;padding:24px 0;color:rgba(255,255,255,.4)">
                <div style="font-size:14px;color:#fff;margin-bottom:8px">⚙️ AI API key not configured</div>
                <div style="font-size:13px">Add your AI key under BlockTicker → <a href="<?php echo esc_url( admin_url('admin.php?page=bt-credentials') ); ?>" style="color:#00ff66">Credentials</a> to enable AI briefs.</div>
            </div>
            <?php elseif ( $brief['text'] ) : ?>
            <!-- Brief content -->
            <div id="bt-pb-text" style="color:rgba(255,255,255,.85);font-size:13px;line-height:1.8;font-family:'IBM Plex Sans',sans-serif">
                <?php
                $paragraphs = array_filter( array_map( 'trim', explode( "\n\n", $brief['text'] ) ) );
                foreach ( $paragraphs as $p ) {
                    echo '<p style="margin:0 0 14px">' . esc_html( $p ) . '</p>';
                }
                ?>
            </div>
            <?php else : ?>
            <!-- Error state -->
            <div style="color:rgba(255,80,80,.8);font-size:13px;padding:16px 0">
                ⚠ Could not generate brief<?php echo $brief['error'] ? ': ' . esc_html( $brief['error'] ) : ''; ?>. Click Refresh to try again.
            </div>
            <?php endif; ?>

            <!-- Asset chips -->
            <?php if ( $has_assets ) : ?>
            <div style="margin-top:20px;padding-top:16px;border-top:1px solid rgba(255,255,255,.07)">
                <span style="font-size:10px;color:rgba(255,255,255,.3);letter-spacing:.1em;text-transform:uppercase;margin-right:8px">Tracking</span>
                <?php foreach ( array_slice( $context['combined'], 0, 10 ) as $sym ) : ?>
                    <span style="display:inline-block;background:rgba(0,255,102,.08);border:1px solid rgba(0,255,102,.18);color:#00ff66;font-size:10px;font-weight:700;padding:2px 8px;margin:2px;letter-spacing:.06em"><?php echo esc_html( $sym ); ?></span>
                <?php endforeach; ?>
                <?php if ( count( $context['combined'] ) > 10 ) : ?>
                    <span style="font-size:11px;color:rgba(255,255,255,.3)">+<?php echo count( $context['combined'] ) - 10; ?> more</span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <style>
        @keyframes bt-blink { 0%,100%{opacity:1} 50%{opacity:.3} }
        #bt-pb-refresh:hover { background:rgba(0,255,102,.18) }
        </style>
        <script>
        (function(){
            var btn = document.getElementById('bt-pb-refresh');
            if (!btn) return;
            btn.addEventListener('click', function(){
                var nonce = btn.dataset.nonce;
                btn.disabled = true; btn.textContent = '↻ Generating…';
                var fd = new FormData();
                fd.append('action','bt_refresh_personalized_brief');
                fd.append('nonce', nonce);
                fetch('<?php echo esc_url( admin_url('admin-ajax.php') ); ?>', { method:'POST', body:fd })
                    .then(function(r){ return r.json(); })
                    .then(function(res){
                        btn.disabled = false; btn.textContent = '↻ Refresh';
                        var textEl = document.getElementById('bt-pb-text');
                        if (res.success) {
                            var paras = res.data.text.split(/\n\n+/).filter(Boolean);
                            if (textEl) {
                                textEl.innerHTML = paras.map(function(p){ return '<p style="margin:0 0 14px">' + p.replace(/</g,'&lt;') + '</p>'; }).join('');
                            }
                        } else {
                            if (textEl) textEl.innerHTML = '<span style="color:rgba(255,80,80,.8)">⚠ ' + (res.data||'Error') + '</span>';
                        }
                    })
                    .catch(function(){ btn.disabled=false; btn.textContent='↻ Refresh'; });
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Compact 2-line teaser for sidebar/dashboard widget.
     */
    public static function sc_brief_compact( $atts ) {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return '<div style="padding:12px;background:#0d0f12;border:1px solid rgba(0,255,102,.15);font-family:\'IBM Plex Mono\',monospace;font-size:12px;color:rgba(255,255,255,.5)">
                <span style="color:#00ff66">📊 AI Brief</span> — <a href="' . esc_url( wp_login_url( get_permalink() ) ) . '" style="color:#00ff66">Sign in</a> to personalise
            </div>';
        }

        $brief = self::get_brief( $user_id );
        if ( ! $brief['text'] ) {
            $msg = $brief['error'] === 'no_key'
                ? 'Add AI API key in Credentials to enable.'
                : ( empty( $brief['context']['combined'] )
                    ? 'Track assets on Watchlist/Screeners/Following to start.'
                    : 'Brief unavailable — click the card to refresh.' );
            return '<div style="padding:12px;background:#0d0f12;border:1px solid rgba(0,255,102,.15);font-family:\'IBM Plex Mono\',monospace;font-size:12px;color:rgba(255,255,255,.4)">📊 ' . esc_html( $msg ) . '</div>';
        }

        // First sentence only for teaser
        $first_sentence = '';
        preg_match( '/^([^.!?]+[.!?])/', $brief['text'], $m );
        $first_sentence = $m[1] ?? substr( $brief['text'], 0, 120 ) . '…';

        return '<div style="padding:12px;background:#0d0f12;border:1px solid rgba(0,255,102,.15);font-family:\'IBM Plex Mono\',monospace">
            <div style="font-size:10px;color:#00ff66;font-weight:700;letter-spacing:.1em;text-transform:uppercase;margin-bottom:6px">📊 Your AI Brief</div>
            <div style="font-size:12px;color:rgba(255,255,255,.75);line-height:1.6">' . esc_html( $first_sentence ) . '</div>
            <div style="margin-top:8px"><a href="/dashboard/" style="font-size:11px;color:#00ff66;text-decoration:none">Read full brief →</a></div>
        </div>';
    }
}
