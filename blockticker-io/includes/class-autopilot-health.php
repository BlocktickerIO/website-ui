<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * BT_Autopilot_Health
 *
 * Centralised diagnostic + observability surface for the daily AI-post +
 * Twitter-publish + Typefully-queue pipeline.
 *
 * Why this class exists
 * ---------------------
 * Up to v119.26.0, when the autopilot pipeline failed (e.g. Twitter returned
 * HTTP 401, Claude key was rotated, Typefully token revoked), the only signal
 * was a single error_log() line buried in /wp-content/debug.log. The admin saw
 * "Tweet posting failed" with no actionable next step. This class:
 *
 *   1. PREFLIGHT — a one-click check that pings each integration with a cheap
 *      no-side-effect endpoint and decodes the response into plain English.
 *      Tells the admin which credential is wrong, what scope is missing, and
 *      where to fix it (with deep links to the provider dashboards).
 *
 *   2. RUN LOG — every autopilot-cron invocation is logged to the option
 *      bt_autopilot_log (ring buffer, last 30 entries). Each entry records:
 *      timestamp, topic, generated_post_id, twitter_count, errors[]. The
 *      admin can scroll through the last month of runs without leaving WP.
 *
 *   3. ERROR DECODER — translates raw HTTP responses from the Twitter v2 API,
 *      Anthropic API, and Typefully API into actionable messages. Used both
 *      by preflight and by the live publish path so the same UX appears
 *      whether the failure happens during preflight or during the real run.
 *
 *   4. STATUS WIDGET — renders a card at the top of the AI Blog Generator
 *      page showing cron status (next-fire, last-result), preflight button,
 *      and recent log. No extra page load needed — the widget hooks into the
 *      existing render_blog_generator() output.
 *
 * No external dependencies beyond classes that already exist in this plugin.
 *
 * Added in v119.27.0.
 */
class BT_Autopilot_Health {

    const LOG_OPTION    = 'bt_autopilot_log';
    const LOG_MAX_ROWS  = 30;
    const NONCE_ACTION  = 'bt_autopilot_health';

    public static function init() {
        // AJAX endpoints — admin only, nonce-protected.
        add_action( 'wp_ajax_bt_autopilot_preflight', array( __CLASS__, 'ajax_preflight' ) );
        add_action( 'wp_ajax_bt_autopilot_test_run',  array( __CLASS__, 'ajax_test_run' ) );

        // Cron logging hooks — record start + outcome of every daily run.
        // Priority 1 fires BEFORE BT_AIBlog::generate_daily_post (priority 10).
        // Priority 99 fires AFTER, so we capture whether a post was actually created.
        add_action( 'bt_daily_ai_post', array( __CLASS__, 'log_run_start' ),  1 );
        add_action( 'bt_daily_ai_post', array( __CLASS__, 'log_run_finish' ), 99 );
    }

    // ─── Preflight ────────────────────────────────────────────────────────

    /**
     * Run all integration health-checks and return a structured report.
     * Each check is independent — one failure does not block the others.
     *
     * @return array {
     *   @type array $claude     ['ok' => bool, 'msg' => string, 'fix_url' => string|null]
     *   @type array $openai     same shape (skipped if no key configured)
     *   @type array $twitter    same shape
     *   @type array $typefully  same shape
     * }
     */
    public static function run_preflight() {
        $report = array();

        // 1. AI provider — only check the *active* one to avoid wasting tokens
        $provider = get_option( 'bt_ai_provider', 'claude' );
        if ( $provider === 'openai' ) {
            $report['openai'] = self::check_openai();
        } else {
            $report['claude'] = self::check_claude();
        }

        // 2. Twitter — only if at least the consumer key is configured
        $tw_ck = get_option( 'bt_twitter_api_key', '' );
        if ( ! empty( $tw_ck ) ) {
            $report['twitter'] = self::check_twitter();
        } else {
            $report['twitter'] = array(
                'ok'      => null, // null = not configured, not "failed"
                'msg'     => 'Twitter credentials not configured. Auto-publish to X is disabled.',
                'fix_url' => 'https://developer.x.com/en/portal/dashboard',
            );
        }

        // 3. Typefully — same gating
        $tf_key = get_option( 'bt_typefully_key', '' );
        if ( ! empty( $tf_key ) ) {
            $report['typefully'] = self::check_typefully();
        } else {
            $report['typefully'] = array(
                'ok'      => null,
                'msg'     => 'Typefully not configured. Tweets 4-12 will not be queued.',
                'fix_url' => 'https://typefully.com/settings/integrations',
            );
        }

        return $report;
    }

    /**
     * Cheap Claude validation — sends a 1-token "ping" message.
     * The Anthropic API returns 401 for invalid keys without consuming credit.
     */
    private static function check_claude() {
        $key = get_option( 'bt_claude_key', '' );
        if ( empty( $key ) ) {
            return array(
                'ok'      => false,
                'msg'     => 'Anthropic API key not configured. Add it in Credentials → AI providers.',
                'fix_url' => admin_url( 'admin.php?page=bt-credentials' ),
            );
        }
        $resp = wp_remote_post( 'https://api.anthropic.com/v1/messages', array(
            'timeout' => 12,
            'headers' => array(
                'x-api-key'         => $key,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ),
            'body' => wp_json_encode( array(
                'model'      => get_option( 'bt_claude_model', 'claude-sonnet-4-5' ),
                'max_tokens' => 1,
                'messages'   => array( array( 'role' => 'user', 'content' => 'ping' ) ),
            ) ),
        ) );

        if ( is_wp_error( $resp ) ) {
            return array(
                'ok'      => false,
                'msg'     => 'Network error reaching Anthropic: ' . $resp->get_error_message(),
                'fix_url' => null,
            );
        }
        $code = wp_remote_retrieve_response_code( $resp );
        $body = json_decode( wp_remote_retrieve_body( $resp ), true );

        if ( $code === 200 ) {
            return array(
                'ok'      => true,
                'msg'     => 'Anthropic API reachable. Key valid.',
                'fix_url' => null,
            );
        }
        return self::decode_claude_error( $code, $body );
    }

    private static function check_openai() {
        $key = get_option( 'bt_openai_key', '' );
        if ( empty( $key ) ) {
            return array(
                'ok'      => false,
                'msg'     => 'OpenAI API key not configured.',
                'fix_url' => 'https://platform.openai.com/api-keys',
            );
        }
        // GET /v1/models is free and validates the key
        $resp = wp_remote_get( 'https://api.openai.com/v1/models', array(
            'timeout' => 12,
            'headers' => array( 'Authorization' => 'Bearer ' . $key ),
        ) );
        if ( is_wp_error( $resp ) ) {
            return array(
                'ok'      => false,
                'msg'     => 'Network error reaching OpenAI: ' . $resp->get_error_message(),
                'fix_url' => null,
            );
        }
        $code = wp_remote_retrieve_response_code( $resp );
        if ( $code === 200 ) {
            return array(
                'ok'      => true,
                'msg'     => 'OpenAI API reachable. Key valid.',
                'fix_url' => null,
            );
        }
        if ( $code === 401 ) {
            return array(
                'ok'      => false,
                'msg'     => 'OpenAI key rejected (401). The key has been revoked, rotated, or copied incorrectly.',
                'fix_url' => 'https://platform.openai.com/api-keys',
            );
        }
        if ( $code === 429 ) {
            return array(
                'ok'      => false,
                'msg'     => 'OpenAI rate-limited or out of quota (429). Check your usage / billing.',
                'fix_url' => 'https://platform.openai.com/usage',
            );
        }
        return array(
            'ok'      => false,
            'msg'     => 'OpenAI returned HTTP ' . $code . '.',
            'fix_url' => 'https://platform.openai.com/api-keys',
        );
    }

    /**
     * Twitter preflight — calls GET /2/users/me with the same OAuth1 signature
     * that the publisher uses. This is the cheapest way to validate that all
     * four credentials line up AND that the app has Read permission. It does
     * NOT validate Write permission — only a real POST can do that, and we
     * don't want preflight to actually post a tweet. Write is checked
     * inferentially: if the access token was generated for a Read-only app,
     * the publish path will return 403, which decode_twitter_error() handles.
     */
    private static function check_twitter() {
        $ck = get_option( 'bt_twitter_api_key', '' );
        $cs = get_option( 'bt_twitter_api_secret', '' );
        $tk = get_option( 'bt_twitter_access_token', '' );
        $ts = get_option( 'bt_twitter_access_token_secret', '' );

        $missing = array();
        if ( empty( $ck ) ) $missing[] = 'API Key';
        if ( empty( $cs ) ) $missing[] = 'API Secret';
        if ( empty( $tk ) ) $missing[] = 'Access Token';
        if ( empty( $ts ) ) $missing[] = 'Access Token Secret';
        if ( ! empty( $missing ) ) {
            return array(
                'ok'      => false,
                'msg'     => 'Twitter creds incomplete. Missing: ' . implode( ', ', $missing ),
                'fix_url' => 'https://developer.x.com/en/portal/dashboard',
            );
        }

        // Reuse BT_AIBlog::twitter_oauth1_header() if exposed; otherwise inline.
        // It IS private, so build the header here using the same algorithm.
        $url    = 'https://api.twitter.com/2/users/me';
        $auth   = self::oauth1_header( $url, 'GET', array(), $ck, $cs, $tk, $ts );
        $resp   = wp_remote_get( $url, array(
            'timeout' => 12,
            'headers' => array( 'Authorization' => $auth ),
        ) );
        if ( is_wp_error( $resp ) ) {
            return array(
                'ok'      => false,
                'msg'     => 'Network error reaching X (Twitter): ' . $resp->get_error_message(),
                'fix_url' => null,
            );
        }
        $code = wp_remote_retrieve_response_code( $resp );
        $body = json_decode( wp_remote_retrieve_body( $resp ), true );
        if ( $code === 200 ) {
            $handle = $body['data']['username'] ?? '';
            return array(
                'ok'      => true,
                'msg'     => 'X (Twitter) auth valid' . ( $handle ? ' — connected as @' . $handle : '' ) . '. Read permission confirmed.',
                'fix_url' => null,
            );
        }
        return self::decode_twitter_error( $code, $body );
    }

    private static function check_typefully() {
        $key = get_option( 'bt_typefully_key', '' );
        if ( empty( $key ) ) {
            return array( 'ok' => null, 'msg' => 'Not configured.', 'fix_url' => null );
        }
        // GET /v1/drafts/recently-scheduled returns 200 even with no drafts
        $resp = wp_remote_get( 'https://api.typefully.com/v1/drafts/recently-scheduled/', array(
            'timeout' => 12,
            'headers' => array( 'X-API-KEY' => $key ),
        ) );
        if ( is_wp_error( $resp ) ) {
            return array(
                'ok'      => false,
                'msg'     => 'Network error reaching Typefully: ' . $resp->get_error_message(),
                'fix_url' => null,
            );
        }
        $code = wp_remote_retrieve_response_code( $resp );
        if ( $code === 200 ) {
            return array(
                'ok'      => true,
                'msg'     => 'Typefully API reachable. Key valid.',
                'fix_url' => null,
            );
        }
        if ( $code === 401 || $code === 403 ) {
            return array(
                'ok'      => false,
                'msg'     => 'Typefully key rejected (HTTP ' . $code . '). Regenerate it.',
                'fix_url' => 'https://typefully.com/settings/integrations',
            );
        }
        return array(
            'ok'      => false,
            'msg'     => 'Typefully returned HTTP ' . $code . '.',
            'fix_url' => 'https://typefully.com/settings/integrations',
        );
    }

    // ─── Error decoders (also reusable from live publish path) ────────────

    /**
     * Translate a Twitter v2 API failure response into actionable English.
     * Inputs are wp_remote_retrieve_response_code() and the parsed JSON body.
     */
    public static function decode_twitter_error( $code, $body ) {
        $api_msg = '';
        if ( is_array( $body ) ) {
            $api_msg = $body['errors'][0]['message']
                ?? $body['detail']
                ?? $body['title']
                ?? '';
        }

        $fix_url = 'https://developer.x.com/en/portal/dashboard';
        switch ( (int) $code ) {
            case 401:
                return array(
                    'ok'      => false,
                    'msg'     => 'X (Twitter) rejected the credentials (401). The Access Token and/or Access Token Secret are expired, revoked, or copied wrong. Fix: developer.x.com → your app → Keys and tokens → regenerate the Access Token & Secret pair (NOT the API Key). Paste both fresh values into Credentials.',
                    'fix_url' => $fix_url,
                );
            case 403:
                return array(
                    'ok'      => false,
                    'msg'     => 'X (Twitter) refused the post (403). Almost always: your app is set to Read-only. Fix: developer.x.com → app → User authentication settings → set permissions to Read and Write → Save → then go back to Keys and tokens → regenerate Access Token & Secret (the old ones still have Read-only scope baked in). Paste the new tokens.',
                    'fix_url' => $fix_url,
                );
            case 429:
                return array(
                    'ok'      => false,
                    'msg'     => 'X (Twitter) rate-limited (429). Free tier = 500 posts/month. Wait or upgrade your plan.',
                    'fix_url' => 'https://developer.x.com/en/portal/products',
                );
            case 400:
                return array(
                    'ok'      => false,
                    'msg'     => 'X (Twitter) rejected the request (400): ' . ( $api_msg ?: 'malformed body' ) . '. Usually a duplicate tweet or oversized media.',
                    'fix_url' => null,
                );
            default:
                return array(
                    'ok'      => false,
                    'msg'     => 'X (Twitter) returned HTTP ' . $code . ( $api_msg ? ' — ' . $api_msg : '' ),
                    'fix_url' => $fix_url,
                );
        }
    }

    public static function decode_claude_error( $code, $body ) {
        $api_msg = '';
        if ( is_array( $body ) && ! empty( $body['error']['message'] ) ) {
            $api_msg = $body['error']['message'];
        }
        switch ( (int) $code ) {
            case 401:
                return array(
                    'ok'      => false,
                    'msg'     => 'Anthropic rejected the API key (401). The key has been rotated or copied incorrectly. Generate a new one at console.anthropic.com → Settings → API keys, then paste it into Credentials.',
                    'fix_url' => 'https://console.anthropic.com/settings/keys',
                );
            case 403:
                return array(
                    'ok'      => false,
                    'msg'     => 'Anthropic refused the request (403). Your account may lack access to the configured model. Try claude-sonnet-4-5 in Credentials.',
                    'fix_url' => 'https://console.anthropic.com/settings/keys',
                );
            case 429:
                return array(
                    'ok'      => false,
                    'msg'     => 'Anthropic rate-limited or out of quota (429). Check your usage and billing.',
                    'fix_url' => 'https://console.anthropic.com/settings/billing',
                );
            default:
                return array(
                    'ok'      => false,
                    'msg'     => 'Anthropic returned HTTP ' . $code . ( $api_msg ? ' — ' . $api_msg : '' ),
                    'fix_url' => 'https://console.anthropic.com/settings/keys',
                );
        }
    }

    // ─── Run logging ──────────────────────────────────────────────────────

    public static function log_run_start() {
        $log = get_option( self::LOG_OPTION, array() );
        if ( ! is_array( $log ) ) $log = array();
        // Push a "running" placeholder; log_run_finish() will mutate the last entry.
        $log[] = array(
            'started_at' => time(),
            'finished_at'=> 0,
            'status'     => 'running',
            'topic'      => get_option( 'bt_ai_autopilot_topic', '' ) ?: 'rotated',
            'post_id'    => 0,
            'errors'     => array(),
        );
        if ( count( $log ) > self::LOG_MAX_ROWS ) {
            $log = array_slice( $log, -self::LOG_MAX_ROWS );
        }
        update_option( self::LOG_OPTION, $log, false );
    }

    public static function log_run_finish() {
        $log = get_option( self::LOG_OPTION, array() );
        if ( ! is_array( $log ) || empty( $log ) ) return;
        $idx = count( $log ) - 1;
        if ( $log[ $idx ]['status'] !== 'running' ) return; // Out-of-order; bail.

        // Find the most recent post created in the last 5 minutes — likely ours.
        $recent_posts = get_posts( array(
            'numberposts' => 1,
            'orderby'     => 'date',
            'order'       => 'DESC',
            'post_status' => array( 'publish', 'pending' ),
            'date_query'  => array(
                array( 'after' => '5 minutes ago' ),
            ),
        ) );

        if ( ! empty( $recent_posts ) ) {
            $log[ $idx ]['status']      = 'success';
            $log[ $idx ]['post_id']     = (int) $recent_posts[0]->ID;
            $log[ $idx ]['post_title']  = get_the_title( $recent_posts[0]->ID );
        } else {
            $log[ $idx ]['status']  = 'no_post';
            $log[ $idx ]['errors'][] = 'Cron fired but no post was created (Claude/OpenAI API may have failed — check the WordPress error log).';
        }
        $log[ $idx ]['finished_at'] = time();
        update_option( self::LOG_OPTION, $log, false );
    }

    public static function get_log() {
        $log = get_option( self::LOG_OPTION, array() );
        return is_array( $log ) ? array_reverse( $log ) : array(); // newest first
    }

    public static function clear_log() {
        delete_option( self::LOG_OPTION );
    }

    // ─── AJAX handlers ────────────────────────────────────────────────────

    public static function ajax_preflight() {
        check_ajax_referer( self::NONCE_ACTION, '_ajax_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized.' );

        @set_time_limit( 60 );
        wp_send_json_success( self::run_preflight() );
    }

    public static function ajax_test_run() {
        check_ajax_referer( self::NONCE_ACTION, '_ajax_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized.' );

        @set_time_limit( 120 );
        // Fire the same cron action the daily schedule fires. log_run_start /
        // log_run_finish wrap it transparently — the test run is logged just
        // like a real one, with status='manual_test' marker.
        $log = get_option( self::LOG_OPTION, array() );
        if ( ! is_array( $log ) ) $log = array();
        $log[] = array(
            'started_at' => time(),
            'finished_at'=> 0,
            'status'     => 'running',
            'topic'      => 'manual-test',
            'post_id'    => 0,
            'errors'     => array(),
            'manual'     => true,
        );
        update_option( self::LOG_OPTION, $log, false );

        do_action( 'bt_daily_ai_post' );

        // Read back the log to report to the caller.
        $log_after = self::get_log();
        $latest    = $log_after[0] ?? null;
        wp_send_json_success( array( 'latest' => $latest ) );
    }

    // ─── Status widget ────────────────────────────────────────────────────

    /**
     * Render the autopilot health card. Called from class-admin.php's
     * render_blog_generator() at the top of the page.
     */
    public static function render_card() {
        $next   = wp_next_scheduled( 'bt_daily_ai_post' );
        $log    = self::get_log();
        $latest = $log[0] ?? null;
        $nonce  = wp_create_nonce( self::NONCE_ACTION );

        $next_human = $next ? human_time_diff( time(), $next ) : '—';
        $next_iso   = $next ? wp_date( 'M j, Y g:i a', $next ) : '—';

        // Latest run summary
        $latest_label = '—';
        $latest_color = '#646970';
        if ( $latest ) {
            $when = human_time_diff( $latest['started_at'], time() ) . ' ago';
            switch ( $latest['status'] ) {
                case 'success':
                    $latest_label = '✓ ' . $when . ' — post created';
                    $latest_color = '#00875a';
                    break;
                case 'no_post':
                    $latest_label = '⚠ ' . $when . ' — fired, no post';
                    $latest_color = '#b45309';
                    break;
                case 'running':
                    $latest_label = '⏳ in progress';
                    $latest_color = '#2271b1';
                    break;
                default:
                    $latest_label = '· ' . $when;
            }
        }
        ?>
        <div class="bt-autopilot-health" style="background:#fff;border:1px solid #dcdcde;border-radius:4px;padding:18px 22px;margin-bottom:20px;box-shadow:0 1px 2px rgba(0,0,0,.04)">
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px;margin-bottom:10px">
                <div>
                    <h2 style="margin:0;font-size:14px;text-transform:uppercase;letter-spacing:.05em;color:#1d2327">⚙ Autopilot Health</h2>
                    <p style="margin:4px 0 0;font-size:12px;color:#646970">Test every API integration before the next scheduled run.</p>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap">
                    <button type="button" class="button button-primary" id="bt-ah-preflight" data-nonce="<?php echo esc_attr( $nonce ); ?>">🩺 Run preflight</button>
                    <button type="button" class="button" id="bt-ah-test-run" data-nonce="<?php echo esc_attr( $nonce ); ?>">▶ Test full run now</button>
                </div>
            </div>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin-top:12px">
                <div style="background:#f6f7f7;border:1px solid #dcdcde;padding:12px 14px;border-radius:4px">
                    <div style="font-size:11px;color:#646970;text-transform:uppercase;letter-spacing:.05em">Next run</div>
                    <div style="font-size:13px;color:#1d2327;font-weight:600;margin-top:4px"><?php echo esc_html( $next_iso ); ?></div>
                    <div style="font-size:11px;color:#646970"><?php echo $next ? 'in ' . esc_html( $next_human ) : 'autopilot disabled'; ?></div>
                </div>
                <div style="background:#f6f7f7;border:1px solid #dcdcde;padding:12px 14px;border-radius:4px">
                    <div style="font-size:11px;color:#646970;text-transform:uppercase;letter-spacing:.05em">Last run</div>
                    <div style="font-size:13px;font-weight:600;margin-top:4px;color:<?php echo esc_attr( $latest_color ); ?>"><?php echo esc_html( $latest_label ); ?></div>
                    <?php if ( $latest && ! empty( $latest['post_title'] ) ) : ?>
                        <div style="font-size:11px;color:#646970;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%"><?php echo esc_html( $latest['post_title'] ); ?></div>
                    <?php endif; ?>
                </div>
                <div style="background:#f6f7f7;border:1px solid #dcdcde;padding:12px 14px;border-radius:4px">
                    <div style="font-size:11px;color:#646970;text-transform:uppercase;letter-spacing:.05em">Recent log</div>
                    <div style="font-size:13px;color:#1d2327;font-weight:600;margin-top:4px"><?php echo count( $log ); ?> entries</div>
                    <div style="font-size:11px;color:#646970">last <?php echo self::LOG_MAX_ROWS; ?> kept</div>
                </div>
            </div>

            <div id="bt-ah-output" style="margin-top:14px;display:none"></div>

            <details style="margin-top:14px">
                <summary style="cursor:pointer;font-size:12px;color:#2271b1">▸ Show recent run log (<?php echo count( $log ); ?>)</summary>
                <div style="margin-top:10px;border-top:1px solid #f0f0f1;padding-top:10px">
                    <?php if ( empty( $log ) ) : ?>
                        <p style="font-size:12px;color:#646970;margin:0">No autopilot runs logged yet. They'll appear here after the first scheduled or manual run.</p>
                    <?php else : ?>
                        <table style="width:100%;border-collapse:collapse;font-size:12px">
                            <thead>
                                <tr style="background:#f6f7f7">
                                    <th style="text-align:left;padding:6px 10px;border:1px solid #dcdcde;font-weight:600">When</th>
                                    <th style="text-align:left;padding:6px 10px;border:1px solid #dcdcde;font-weight:600">Status</th>
                                    <th style="text-align:left;padding:6px 10px;border:1px solid #dcdcde;font-weight:600">Topic</th>
                                    <th style="text-align:left;padding:6px 10px;border:1px solid #dcdcde;font-weight:600">Result</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ( array_slice( $log, 0, 15 ) as $row ) :
                                $when = wp_date( 'M j g:i a', $row['started_at'] );
                                $st   = $row['status'];
                                $color = ( $st === 'success' ) ? '#00875a' : ( ( $st === 'running' ) ? '#2271b1' : '#b45309' );
                                $icon  = ( $st === 'success' ) ? '✓' : ( ( $st === 'running' ) ? '⏳' : '⚠' );
                                $result = ( $st === 'success' && ! empty( $row['post_id'] ) )
                                    ? '<a href="' . esc_url( get_edit_post_link( $row['post_id'] ) ) . '">' . esc_html( $row['post_title'] ?? 'View post' ) . '</a>'
                                    : ( ! empty( $row['errors'] ) ? esc_html( implode( ' · ', $row['errors'] ) ) : '—' );
                            ?>
                                <tr>
                                    <td style="padding:6px 10px;border:1px solid #f0f0f1;color:#3c434a"><?php echo esc_html( $when ); ?></td>
                                    <td style="padding:6px 10px;border:1px solid #f0f0f1;color:<?php echo esc_attr( $color ); ?>;font-weight:600"><?php echo esc_html( $icon . ' ' . $st ); ?></td>
                                    <td style="padding:6px 10px;border:1px solid #f0f0f1;color:#3c434a"><?php echo esc_html( $row['topic'] ?? '—' ); ?></td>
                                    <td style="padding:6px 10px;border:1px solid #f0f0f1;color:#3c434a"><?php echo $result; ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </details>
        </div>

        <script>
        (function(){
            var out = document.getElementById('bt-ah-output');
            function showStatus(html){ out.style.display='block'; out.innerHTML = html; }
            function pillFor(check){
                if (check.ok === true)  return '<span style="display:inline-block;padding:2px 8px;font-size:11px;font-weight:700;border-radius:999px;background:#e7f9f0;color:#00875a;border:1px solid #b5e3cd">PASS</span>';
                if (check.ok === false) return '<span style="display:inline-block;padding:2px 8px;font-size:11px;font-weight:700;border-radius:999px;background:#fcebec;color:#d63638;border:1px solid #f0c5c8">FAIL</span>';
                return '<span style="display:inline-block;padding:2px 8px;font-size:11px;font-weight:700;border-radius:999px;background:#f0f0f1;color:#646970;border:1px solid #dcdcde">SKIP</span>';
            }
            document.getElementById('bt-ah-preflight').addEventListener('click', function(e){
                var btn = e.currentTarget;
                btn.disabled = true; btn.textContent = '⏳ Running…';
                showStatus('<div style="font-size:13px;color:#646970">Pinging providers…</div>');
                var data = new FormData();
                data.append('action', 'bt_autopilot_preflight');
                data.append('_ajax_nonce', btn.dataset.nonce);
                fetch(ajaxurl, {method:'POST', body:data, credentials:'same-origin'})
                    .then(function(r){ return r.json(); })
                    .then(function(d){
                        btn.disabled = false; btn.textContent = '🩺 Run preflight';
                        if (!d || !d.success) {
                            showStatus('<div style="background:#fcebec;border:1px solid #f0c5c8;padding:10px 14px;color:#d63638;font-size:13px">Preflight failed: ' + (d && d.data ? d.data : 'unknown error') + '</div>');
                            return;
                        }
                        var rows = '';
                        var labels = { claude:'Anthropic Claude', openai:'OpenAI ChatGPT', twitter:'X (Twitter)', typefully:'Typefully' };
                        for (var k in d.data) {
                            var c = d.data[k];
                            rows += '<tr>'
                                +   '<td style="padding:8px 10px;border:1px solid #f0f0f1;font-weight:600;color:#1d2327">'+labels[k]+'</td>'
                                +   '<td style="padding:8px 10px;border:1px solid #f0f0f1;text-align:center;width:70px">'+pillFor(c)+'</td>'
                                +   '<td style="padding:8px 10px;border:1px solid #f0f0f1;color:#3c434a;font-size:12px;line-height:1.5">'
                                +     escapeHtml(c.msg || '')
                                +     (c.fix_url ? ' <a href="'+escapeHtml(c.fix_url)+'" target="_blank" rel="noopener" style="color:#2271b1">Open fix page →</a>' : '')
                                +   '</td>'
                                + '</tr>';
                        }
                        showStatus('<table style="width:100%;border-collapse:collapse;font-size:13px;background:#fff;border:1px solid #dcdcde"><tbody>'+rows+'</tbody></table>');
                    })
                    .catch(function(err){
                        btn.disabled = false; btn.textContent = '🩺 Run preflight';
                        showStatus('<div style="background:#fcebec;border:1px solid #f0c5c8;padding:10px 14px;color:#d63638;font-size:13px">Network error: '+err.message+'</div>');
                    });
            });
            document.getElementById('bt-ah-test-run').addEventListener('click', function(e){
                if (!confirm('Run a real autopilot pass now? This will create a post and (if enabled) actually post to X. Continue?')) return;
                var btn = e.currentTarget;
                btn.disabled = true; btn.textContent = '⏳ Running…';
                showStatus('<div style="font-size:13px;color:#646970">Running daily autopilot pipeline (may take 30-90s)…</div>');
                var data = new FormData();
                data.append('action', 'bt_autopilot_test_run');
                data.append('_ajax_nonce', btn.dataset.nonce);
                fetch(ajaxurl, {method:'POST', body:data, credentials:'same-origin'})
                    .then(function(r){ return r.json(); })
                    .then(function(d){
                        btn.disabled = false; btn.textContent = '▶ Test full run now';
                        if (d && d.success) {
                            showStatus('<div style="background:#e7f9f0;border:1px solid #b5e3cd;padding:10px 14px;color:#00875a;font-size:13px">✓ Test run complete. Reload the page to see updated log.</div>');
                        } else {
                            showStatus('<div style="background:#fcebec;border:1px solid #f0c5c8;padding:10px 14px;color:#d63638;font-size:13px">Test run failed: ' + (d && d.data ? d.data : 'unknown') + '</div>');
                        }
                    })
                    .catch(function(err){
                        btn.disabled = false; btn.textContent = '▶ Test full run now';
                        showStatus('<div style="background:#fcebec;border:1px solid #f0c5c8;padding:10px 14px;color:#d63638;font-size:13px">Network error: '+err.message+'</div>');
                    });
            });
            function escapeHtml(s){
                return String(s).replace(/[&<>"']/g, function(m){
                    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m];
                });
            }
        })();
        </script>
        <?php
    }

    // ─── OAuth1 helper (duplicated from class-aiblog.php which keeps it private) ──

    private static function oauth1_header( $url, $method, $query_params, $ck, $cs, $tk, $ts ) {
        $oauth = array(
            'oauth_consumer_key'     => $ck,
            'oauth_nonce'            => bin2hex( random_bytes( 16 ) ),
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp'        => (string) time(),
            'oauth_token'            => $tk,
            'oauth_version'          => '1.0',
        );
        $signing = array_merge( $oauth, is_array( $query_params ) ? $query_params : array() );
        ksort( $signing );
        $pairs = array();
        foreach ( $signing as $k => $v ) {
            $pairs[] = rawurlencode( $k ) . '=' . rawurlencode( $v );
        }
        $base = strtoupper( $method ) . '&' . rawurlencode( $url ) . '&' . rawurlencode( implode( '&', $pairs ) );
        $key  = rawurlencode( $cs ) . '&' . rawurlencode( $ts );
        $oauth['oauth_signature'] = base64_encode( hash_hmac( 'sha1', $base, $key, true ) );
        $parts = array();
        foreach ( $oauth as $k => $v ) {
            $parts[] = rawurlencode( $k ) . '="' . rawurlencode( $v ) . '"';
        }
        return 'OAuth ' . implode( ', ', $parts );
    }
}
