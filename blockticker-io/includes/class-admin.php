<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class BT_Admin {

    public static function init() {
        add_action( 'admin_menu',    array( __CLASS__, 'add_menu' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
        add_action( 'wp_ajax_fxlm_run_step', array( __CLASS__, 'ajax_run_step' ) );
        add_action( 'wp_ajax_fxlm_generate_post', array( __CLASS__, 'ajax_generate_post' ) );
        add_action( 'wp_ajax_fxlm_toggle_autopilot', array( __CLASS__, 'ajax_toggle_autopilot' ) );
        add_action( 'wp_ajax_fxlm_save_ai_pref', array( __CLASS__, 'ajax_save_ai_pref' ) );
        add_action( 'wp_ajax_fxlm_clear_ga_id', array( __CLASS__, 'ajax_clear_ga_id' ) );
        add_action( 'wp_ajax_fxlm_reset_forex_prev', array( __CLASS__, 'ajax_reset_forex_prev' ) );
        add_action( 'wp_ajax_fxlm_clear_cache',       array( __CLASS__, 'ajax_clear_cache' ) );
        add_action( 'wp_ajax_fxlm_save_menu_config',  array( __CLASS__, 'ajax_save_menu_config' ) );
        // v60
        add_action( 'wp_ajax_bt_twitter_publish_now', array( __CLASS__, 'ajax_twitter_publish_now' ) );
        add_action( 'wp_ajax_bt_forex_force_fetch',   array( __CLASS__, 'ajax_forex_force_fetch' ) );
        // v61
        add_action( 'wp_ajax_bt_refix_ai_images',     array( __CLASS__, 'ajax_refix_ai_images' ) );
        // v63
        add_action( 'wp_ajax_bt_typefully_queue',     array( __CLASS__, 'ajax_typefully_queue' ) );
        add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
        // v119.22: Credentials export / import / legacy-migrate
        add_action( 'wp_ajax_bt_export_credentials',         array( __CLASS__, 'ajax_export_credentials' ) );
        add_action( 'wp_ajax_bt_import_credentials',         array( __CLASS__, 'ajax_import_credentials' ) );
        add_action( 'wp_ajax_bt_migrate_legacy_credentials', array( __CLASS__, 'ajax_migrate_legacy_credentials' ) );
        // v119.22.1: AJAX connectivity test — returns immediately so admin can
        // confirm admin-ajax.php is reachable and nonce is valid before blaming
        // step handlers for the "stuck on Running" symptom.
        add_action( 'wp_ajax_bt_ping_ajax',                  array( __CLASS__, 'ajax_ping' ) );
        add_action( 'wp_ajax_bt_test_wporg_connectivity',    array( __CLASS__, 'ajax_test_wporg' ) );
    }

    /**
     * v61: Iterate recent AI-generated posts and re-run set_featured_image()
     * on each. Useful after switching image modes or fixing the drone-on-crypto
     * problem for already-published posts.
     */
    public static function ajax_refix_ai_images() {
        BT_Utils::verify_admin_ajax( 'fxlm_blog_gen' );

        // Target AI-generated posts: authored by user 1 (the AI poster) within last 60 days.
        // Limit to 50 to keep the request snappy (<30s even on slow hosts).
        $posts = get_posts( array(
            'numberposts' => 50,
            'post_status' => array( 'publish', 'pending' ),
            'author'      => 1,
            'date_query'  => array( array( 'after' => '60 days ago' ) ),
            'orderby'     => 'date',
            'order'       => 'DESC',
        ) );

        $processed   = 0;
        $relinked    = 0;
        $placeholder = 0;
        $news_smart  = 0;

        foreach ( $posts as $p ) {
            $processed++;
            // Unset current thumbnail — the new set_featured_image will assign a better one.
            // We intentionally don't delete the attachment itself (safer — user may want the old image back)
            delete_post_thumbnail( $p->ID );
            delete_post_meta( $p->ID, '_bt_featured_source' );
            delete_post_meta( $p->ID, '_bt_featured_score' );

            BT_AIBlog::set_featured_image( $p->ID );

            $src = get_post_meta( $p->ID, '_bt_featured_source', true );
            if ( $src === 'branded-placeholder' )  $placeholder++;
            elseif ( $src === 'news-smart' )       $news_smart++;
            if ( get_post_thumbnail_id( $p->ID ) ) $relinked++;
        }

        wp_send_json_success( array(
            'processed'   => $processed,
            'relinked'    => $relinked,
            'placeholder' => $placeholder,
            'news_smart'  => $news_smart,
        ) );
    }

    /**
     * v63: Queue remaining thread tweets (4+) to Typefully as a draft.
     */
    public static function ajax_typefully_queue() {
        BT_Utils::verify_admin_ajax( 'fxlm_blog_gen' );
        $post_id = intval( $_POST['post_id'] ?? 0 );
        if ( ! $post_id ) wp_send_json_error( 'Missing post_id' );
        $result = BT_AIBlog::queue_remaining_tweets_to_typefully( $post_id );
        if ( $result['success'] ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( $result['error'] );
        }
    }

    /**
     * v60: AJAX — publish first 3 tweets of a given post to X.
     */
    public static function ajax_twitter_publish_now() {
        BT_Utils::verify_admin_ajax( 'fxlm_blog_gen' );
        $post_id = intval( $_POST['post_id'] ?? 0 );
        if ( ! $post_id ) wp_send_json_error( 'Missing post_id' );
        $result = BT_AIBlog::publish_first_tweets_to_twitter( $post_id, 3 );
        if ( $result['success'] ) {
            wp_send_json_success( array(
                'count' => $result['count'],
                'ids'   => $result['ids'],
                'first_url' => 'https://twitter.com/i/web/status/' . ( $result['ids'][0] ?? '' ),
            ) );
        } else {
            wp_send_json_error( $result['error'] );
        }
    }

    /**
     * v60: AJAX — force a fresh forex fetch bypassing the 5-minute transient.
     */
    public static function ajax_forex_force_fetch() {
        BT_Utils::verify_admin_ajax( 'fxlm_flush', 'manage_options', '_ajax_nonce' );
        delete_transient( 'bt_forex_fresh' );
        BT_Widgets::fetch_forex_prices();
        $forex = BT_Widgets::get_json_option( 'fxlm_forex_data' );
        $is_demo = ( $forex['source'] ?? '' ) === 'demo' || empty( $forex['rates'] );
        if ( $is_demo ) {
            wp_send_json_error( 'All 5 forex APIs returned no data. Run BlockTicker → 🔍 Forex Diagnostic to see which ones reached the server.' );
        }
        $pair_count = count( $forex['rates'] );
        wp_send_json_success( "Live forex data restored — {$pair_count} pairs cached." );
    }

    public static function ajax_clear_ga_id() {
        BT_Utils::verify_admin_ajax( 'fxlm_flush', 'manage_options', '_ajax_nonce' );
        delete_option( 'bt_ga_id' );
        wp_send_json_success( 'GA ID cleared.' );
    }

    public static function ajax_clear_cache() {
        BT_Utils::verify_admin_ajax( 'fxlm_flush', 'manage_options', '_ajax_nonce' );
        // Delete all cached data — will be re-fetched on next cron run
        $options_to_clear = array(
            'fxlm_crypto_data', 'fxlm_forex_data', 'fxlm_news_items',
            'fxlm_fear_greed_data', 'fxlm_forex_prev_rates', 'fxlm_forex_prev_updated',
            'fxlm_signals_data',
        );
        foreach ( $options_to_clear as $opt ) delete_option( $opt );
        delete_transient( 'fxlm_exchanges_data' );
        // Trigger fresh fetch immediately
        if ( class_exists('BT_Widgets') ) {
            BT_Widgets::fetch_forex_prices();
            BT_Widgets::fetch_crypto_prices();
        }
        if ( class_exists('BT_Tools') ) {
            BT_Tools::fetch_fear_greed();
        }
        wp_send_json_success( 'All data cache cleared and refreshed. Fresh data will appear in 1–2 minutes.' );
    }

    public static function ajax_reset_forex_prev() {
        BT_Utils::verify_admin_ajax( 'fxlm_flush', 'manage_options', '_ajax_nonce' );
        delete_option( 'bt_forex_prev_rates' );
        delete_option( 'bt_forex_prev_updated' );
        // Trigger an immediate forex fetch to re-seed with yesterday's data
        BT_Widgets::fetch_forex_prices();
        wp_send_json_success( 'Forex baseline reset. Real 24h % change will now show.' );
    }

    public static function on_activate() {
        update_option( 'bt_setup_progress', array() );
        update_option( 'bt_activated', 1 );
        // Enable WP user registration for portfolio/watchlist accounts
        if ( ! get_option( 'users_can_register' ) ) {
            update_option( 'users_can_register', 1 );
        }
        // Migrate any legacy fxlm_* credential keys that were left behind by
        // old installs. The v98 migration ran once but is not re-run after a
        // delete+reinstall (new install = clean options, but hosts that use
        // DB snapshots or keep options across reinstalls may still have fxlm_*
        // data while bt_* rows are empty). Only copies when the bt_* target is
        // empty — never overwrites a value the user already entered.
        self::migrate_legacy_credentials();
    }

    /**
     * One-way copy of legacy fxlm_* credential options → canonical bt_* options.
     * Safe to call repeatedly (no-op if bt_* already set). Called on activate
     * and exposed as a manual "Restore from backup" action in the Credentials UI.
     *
     * @return int Number of options actually migrated.
     */
    public static function migrate_legacy_credentials() {
        $map = array(
            'fxlm_cg_api_key'             => 'bt_cg_api_key',
            'fxlm_fx_api_key'             => 'bt_fx_api_key',
            'fxlm_claude_api_key'         => 'bt_claude_key',
            'fxlm_adsense_publisher_id'   => 'bt_adsense_id',
            'fxlm_ga_id'                  => 'bt_ga_id',
            'fxlm_google_client_id'       => 'bt_google_client_id',
            'fxlm_google_client_secret'   => 'bt_google_client_secret',
            'fxlm_github_client_id'       => 'bt_github_client_id',
            'fxlm_github_client_secret'   => 'bt_github_client_secret',
            'fxlm_site_name'              => 'bt_site_name',
            'fxlm_og_image'               => 'bt_og_image',
        );
        $migrated = 0;
        foreach ( $map as $old => $new ) {
            $legacy = get_option( $old );
            if ( $legacy !== false && $legacy !== '' && ! get_option( $new ) ) {
                update_option( $new, $legacy );
                $migrated++;
            }
        }
        return $migrated;
    }

    /**
     * AJAX: Export all bt_* credentials as a JSON file the admin can download
     * and use to restore after a reinstall.
     */
    public static function ajax_export_credentials() {
        BT_Utils::verify_admin_ajax( 'fxlm_nonce' );
        $cred_keys = array(
            'bt_cmc_api_key', 'bt_cg_api_key', 'bt_fx_api_key', 'bt_claude_key',
            'bt_adsense_id', 'bt_adsense_slot', 'bt_ga_id', 'bt_ai_review_mode',
            'bt_google_client_id', 'bt_google_client_secret',
            'bt_github_client_id', 'bt_github_client_secret',
            'bt_site_name', 'bt_og_image',
            'bt_mail_from_email', 'bt_mail_from_name',
        );
        $data = array( '_version' => BT_VERSION, '_exported' => gmdate( 'Y-m-d H:i:s' ) . ' UTC' );
        foreach ( $cred_keys as $k ) {
            $v = get_option( $k, '' );
            if ( $v !== '' && $v !== false ) $data[ $k ] = $v;
        }
        wp_send_json_success( $data );
    }

    /**
     * AJAX: Import credentials from a JSON payload (uploaded via the browser).
     * Only writes keys that are in the known whitelist — never allows arbitrary
     * option writes.
     */
    public static function ajax_import_credentials() {
        BT_Utils::verify_admin_ajax( 'fxlm_nonce' );
        $allowed = array(
            'bt_cmc_api_key', 'bt_cg_api_key', 'bt_fx_api_key', 'bt_claude_key',
            'bt_adsense_id', 'bt_adsense_slot', 'bt_ga_id', 'bt_ai_review_mode',
            'bt_google_client_id', 'bt_google_client_secret',
            'bt_github_client_id', 'bt_github_client_secret',
            'bt_site_name', 'bt_og_image',
            'bt_mail_from_email', 'bt_mail_from_name',
        );
        $raw = sanitize_text_field( wp_unslash( $_POST['payload'] ?? '' ) );
        $data = json_decode( $raw, true );
        if ( ! is_array( $data ) ) {
            wp_send_json_error( 'Invalid JSON payload.' );
        }
        $imported = 0;
        foreach ( $allowed as $k ) {
            if ( isset( $data[ $k ] ) && $data[ $k ] !== '' ) {
                // URL fields get esc_url_raw; email field validated; rest get sanitize_text_field.
                if ( $k === 'bt_og_image' ) {
                    update_option( $k, esc_url_raw( $data[ $k ] ) );
                } elseif ( $k === 'bt_mail_from_email' ) {
                    if ( is_email( $data[ $k ] ) ) { update_option( $k, sanitize_email( $data[ $k ] ) ); }
                } else {
                    update_option( $k, sanitize_text_field( $data[ $k ] ) );
                }
                $imported++;
            }
        }
        wp_send_json_success( "Imported {$imported} credential(s) successfully." );
    }

    /**
     * AJAX: One-click migration of any surviving fxlm_* legacy credentials.
     */
    public static function ajax_migrate_legacy_credentials() {
        BT_Utils::verify_admin_ajax( 'fxlm_nonce' );
        $count = self::migrate_legacy_credentials();
        if ( $count > 0 ) {
            wp_send_json_success( "Migrated {$count} legacy credential(s) from fxlm_* namespace." );
        } else {
            wp_send_json_success( 'No legacy credentials found — all already in canonical bt_* namespace.' );
        }
    }

    /**
     * AJAX: trivial ping — returns success immediately.
     * Used by the JS "Ping AJAX" button to verify admin-ajax.php is reachable
     * and the nonce is valid before blaming step handlers for connectivity issues.
     */
    /**
     * AJAX: test whether this server can reach wordpress.org.
     * Explains why step_plugins fails and why step_settings used to hang.
     */
    public static function ajax_test_wporg() {
        ob_start();
        BT_Utils::verify_admin_ajax( 'fxlm_nonce' );
        ob_end_clean();

        $start    = microtime( true );
        $response = wp_remote_get( 'https://api.wordpress.org/', array(
            'timeout'    => 10,
            'sslverify'  => false,
            'user-agent' => 'BlockTicker/' . BT_VERSION . '; ' . home_url(),
        ) );
        $elapsed = round( ( microtime( true ) - $start ) * 1000 ) . ' ms';

        if ( is_wp_error( $response ) ) {
            wp_send_json_success( array(
                'reachable' => false,
                'message'   => '❌ Cannot reach wordpress.org (' . $elapsed . '): ' . $response->get_error_message() .
                               '. This is WHY step 1 was hanging — WP core update-check hooks were making outbound calls ' .
                               'that stalled for 60 s. v119.22.2 blocks those calls during step execution. ' .
                               'step_plugins (install from wp.org) will also fail — install those manually.',
            ) );
        } else {
            $code = wp_remote_retrieve_response_code( $response );
            wp_send_json_success( array(
                'reachable' => true,
                'message'   => '✅ wordpress.org reachable (' . $elapsed . ', HTTP ' . $code . '). ' .
                               'step_plugins should work. If steps still hang, the issue is something else — ' .
                               'check WP_DEBUG log for the exact error.',
            ) );
        }
    }

    public static function ajax_ping() {
        ob_start();
        BT_Utils::verify_admin_ajax( 'fxlm_nonce' );
        ob_end_clean();
        wp_send_json_success( array(
            'pong'    => true,
            'user'    => wp_get_current_user()->user_login,
            'version' => BT_VERSION,
            'time'    => gmdate( 'Y-m-d H:i:s' ) . ' UTC',
        ) );
    }

    public static function on_deactivate() {
        wp_clear_scheduled_hook( 'bt_refresh_prices' );
        wp_clear_scheduled_hook( 'bt_refresh_news' );
        wp_clear_scheduled_hook( 'bt_refresh_signals' );
        wp_clear_scheduled_hook( 'bt_ai_posts' );
        wp_clear_scheduled_hook( 'bt_refresh_fng' );
        wp_clear_scheduled_hook( 'bt_health_check' );
        wp_clear_scheduled_hook( 'bt_daily_ai_post' );
        wp_clear_scheduled_hook( 'bt_purge_old_data' ); // v96.2: retention cron for custom tables
    }

    /**
     * Admin menu — v119.6 consolidated
     *
     * Removed:
     *   • File Editor (WP core Plugin/Theme Editor covers this)
     *   • Forex Diagnostic (merged into Database → Diagnostics tab)
     *   • Duplicate API Keys entry (moved to class-api-keys.php, single source of truth)
     *
     * Renamed:
     *   • "Setup Wizard" → "Dashboard" (single entry, not a one-time wizard)
     *   • Inline 3rd-party credentials now under "Credentials" submenu (not mixed with consumer API keys)
     */
    public static function add_menu() {
        add_menu_page(
            'BlockTicker',
            'BlockTicker',
            'manage_options',
            'fxlm-wizard',
            array( __CLASS__, 'render_page' ),
            'dashicons-chart-line',
            2
        );
        add_submenu_page(
            'fxlm-wizard',
            'BlockTicker Dashboard',
            'Dashboard',
            'manage_options',
            'fxlm-wizard',
            array( __CLASS__, 'render_page' )
        );
        add_submenu_page(
            'fxlm-wizard',
            'Pages',
            'Pages',
            'manage_options',
            'fxlm-page-manager',
            array( __CLASS__, 'render_page_manager' )
        );
        add_submenu_page(
            'fxlm-wizard',
            'AI Blog Generator',
            'AI Blog',
            'manage_options',
            'fxlm-blog-generator',
            array( __CLASS__, 'render_blog_generator' )
        );
        add_submenu_page(
            'fxlm-wizard',
            'Site Menus',
            'Menus',
            'manage_options',
            'fxlm-menu-config',
            array( __CLASS__, 'render_menu_configurator' )
        );
        add_submenu_page(
            'fxlm-wizard',
            'Feature Toggles',
            'Features',
            'manage_options',
            'fxlm-features',
            array( __CLASS__, 'render_feature_toggles' )
        );
        add_submenu_page(
            'fxlm-wizard',
            'Translations',
            'Translations',
            'manage_options',
            'bt-i18n',
            array( 'BT_I18N', 'render_admin_page' )
        );
        add_submenu_page(
            'fxlm-wizard',
            'Writer Submissions',
            'Submissions',
            'manage_options',
            'bt-submissions',
            array( 'BT_EEAT', 'render_submissions_page' )
        );
        /* Credentials = third-party API keys YOU enter (CoinGecko, CMC, Anthropic, etc.).
           Uses slug 'bt-credentials' so it doesn't collide with the public-API keys page
           registered by class-api-keys.php under 'bt-api-keys'. */
        add_submenu_page(
            'fxlm-wizard',
            'API Credentials',
            'Credentials',
            'manage_options',
            'bt-credentials',
            array( __CLASS__, 'render_credentials' )
        );
        /* Public API keys page registered separately by BT_APIKeys::register_admin_menu() */
    }

    /**
     * Credentials page — third-party API keys.
     * Currently lives in the Setup Wizard's "API Keys" section; this is a
     * direct-link alias for admins who know they just want to update keys.
     */
    /**
     * Credentials page — third-party API keys.
     * Direct-access form for admins who just want to update keys without
     * scrolling the whole Setup Wizard.
     *
     * Key inventory:
     *   - CoinMarketCap        fxlm_cmc_api_key         Fear & Greed, backup market data
     *   - CoinGecko Pro        fxlm_coingecko_api_key   Higher rate limits (optional)
     *   - ExchangeRate-API     fxlm_exchangerate_api_key Forex data backup (optional)
     *   - Anthropic Claude     fxlm_claude_api_key      AI blog generation
     *   - Google AdSense ID    fxlm_adsense_publisher   Monetisation (pub-XXX)
     *   - Google Analytics ID  fxlm_ga_measurement_id   Tracking
     *   - OAuth (Google)       fxlm_google_oauth_client_id / _secret
     *   - OAuth (GitHub)       fxlm_github_oauth_client_id / _secret
     */
    /**
     * Credentials page — third-party API keys.
     * Direct-access form for admins who want to update keys without scrolling
     * the whole Setup Wizard.
     *
     * v119.20: This is now the SINGLE SOURCE OF TRUTH for API credentials.
     * The wizard's Credentials section was replaced with a summary card linking
     * here. All fields use the canonical bt_* option keys (matching what the
     * rest of the codebase reads); previous fxlm_* keys were dead aliases that
     * silently dropped saves.
     *
     * Key inventory (canonical bt_* keys read by the rest of the code):
     *   - CoinMarketCap        bt_cmc_api_key           Fear & Greed, backup market data
     *   - CoinGecko            bt_cg_api_key            Higher rate limits (optional)
     *   - ExchangeRate-API     bt_fx_api_key            Forex backup (optional)
     *   - Anthropic Claude     bt_claude_key            AI blog generation
     *   - Google AdSense ID    bt_adsense_id            Monetisation publisher (pub-X)
     *   - AdSense Slot         bt_adsense_slot          AdSense ad slot ID
     *   - Google Analytics ID  bt_ga_id                 Tracking (G-X)
     *   - AI Review Mode       bt_ai_review_mode        0 = auto-publish, 1 = pending
     *   - OAuth (Google)       bt_google_client_id / _secret
     *   - OAuth (GitHub)       bt_github_client_id / _secret
     *   - Site Name            bt_site_name
     *   - Default OG Image     bt_og_image
     */
    public static function render_credentials() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        /* Save handler */
        if ( isset( $_POST['bt_save_credentials'] ) && check_admin_referer( 'bt_credentials' ) ) {
            // Field map: form field name → option key.
            // All option keys are canonical bt_* (the namespace the rest of the
            // codebase actually reads — class-migration.php migrated fxlm_* → bt_*
            // back in v98).
            $fields = array(
                'bt_cmc_api_key',
                'bt_cg_api_key',
                'bt_fx_api_key',
                'bt_claude_key',
                'bt_adsense_id',
                'bt_adsense_slot',
                'bt_ga_id',
                'bt_ai_review_mode',
                'bt_google_client_id',
                'bt_google_client_secret',
                'bt_github_client_id',
                'bt_github_client_secret',
                'bt_site_name',
                'bt_og_image',
                // v119.21: outgoing email sender (wp_mail_from / wp_mail_from_name)
                'bt_mail_from_email',
                'bt_mail_from_name',
            );
            // Secret fields render with empty value="" + masked placeholder so
            // the saved secret never leaks into the HTML. The placeholder
            // promises "leave blank to keep" — the save handler must honour
            // that promise too, otherwise hitting Save without re-typing wipes
            // every saved secret.
            //
            // v119.28.2: removed `client_id` from the secret detector. OAuth
            // Client IDs are PUBLIC values (they appear in the OAuth URL and
            // in any HTML that bootstraps the OAuth flow). Treating them as
            // secrets meant the input rendered with value="" — so users who
            // edited any other field and clicked Save would see their Client
            // IDs apparently disappear from the form (they were retained in
            // the DB, but the UX strongly implied they were wiped). Now
            // Client IDs render normally with their value visible, only true
            // secrets (`_secret`, `_api_key`, Claude key) get the masked
            // placeholder + leave-blank-to-keep behaviour.
            $is_secret_field = function( $key ) {
                return strpos( $key, '_secret' )    !== false
                    || strpos( $key, '_api_key' )   !== false
                    || $key === 'bt_claude_key';
            };
            $kept = 0; $updated = 0;
            foreach ( $fields as $f ) {
                if ( ! array_key_exists( $f, $_POST ) ) continue;
                $val = sanitize_text_field( wp_unslash( $_POST[ $f ] ) );
                // Empty submission on a secret-rendered field = "keep existing".
                if ( $val === '' && $is_secret_field( $f ) ) { $kept++; continue; }
                // OG image is a URL — apply esc_url_raw instead of plain sanitize.
                if ( $f === 'bt_og_image' ) $val = esc_url_raw( $val );
                // v119.21: validate email address — keep prior value (don't
                // wipe) if the operator typed a bad address. Empty is fine
                // (means "use WP default sender").
                if ( $f === 'bt_mail_from_email' && $val !== '' && ! is_email( $val ) ) {
                    echo '<div class="notice notice-warning is-dismissible"><p>'
                        . esc_html__( 'From-email looked invalid — kept previous value.', 'blockticker' )
                        . '</p></div>';
                    continue;
                }
                update_option( $f, $val );
                $updated++;
            }
            $msg = sprintf(
                'Credentials saved. %d updated%s.',
                $updated,
                $kept ? ", {$kept} kept (left blank)" : ''
            );
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
        }

        $mask = function( $val ) {
            $val = (string) $val;
            if ( strlen( $val ) <= 8 ) return $val;
            return substr( $val, 0, 4 ) . str_repeat( '•', max( 8, strlen( $val ) - 8 ) ) . substr( $val, -4 );
        };

        $groups = array(
            'Market Data' => array(
                array( 'key' => 'bt_cmc_api_key', 'label' => 'CoinMarketCap API Key', 'help' => 'For CMC Fear &amp; Greed Index and backup market data', 'link' => 'https://coinmarketcap.com/api/' ),
                array( 'key' => 'bt_cg_api_key',  'label' => 'CoinGecko API Key',     'help' => 'Optional. Raises rate limits. Free tier works without.', 'link' => 'https://www.coingecko.com/en/api/pricing' ),
                array( 'key' => 'bt_fx_api_key',  'label' => 'ExchangeRate-API Key',  'help' => 'Optional backup forex source. Frankfurter.app used by default (no key).', 'link' => 'https://www.exchangerate-api.com/' ),
            ),
            'AI &amp; Content' => array(
                array( 'key' => 'bt_claude_key', 'label' => 'Anthropic Claude API Key', 'help' => 'For AI blog post generation. Uses Claude Sonnet 4.5. Free tier available.', 'link' => 'https://console.anthropic.com/' ),
                array( 'key' => 'bt_ai_review_mode', 'label' => 'AI Post Review Mode', 'help' => 'When ON, AI posts go to Pending instead of publishing immediately',
                       'type' => 'select', 'options' => array( '0' => 'Auto-publish (default)', '1' => 'Require human review (Pending)' ) ),
            ),
            'Monetisation &amp; Analytics' => array(
                array( 'key' => 'bt_adsense_id',   'label' => 'Google AdSense Publisher ID', 'help' => 'e.g. pub-1234567890', 'link' => 'https://www.google.com/adsense/', 'type' => 'text' ),
                array( 'key' => 'bt_adsense_slot', 'label' => 'AdSense Ad Slot ID',          'help' => 'From your AdSense dashboard (e.g. 1234567890)',                'type' => 'text' ),
                array( 'key' => 'bt_ga_id',        'label' => 'Google Analytics ID',         'help' => 'e.g. G-XXXXXXXXXX', 'link' => 'https://analytics.google.com/', 'type' => 'text' ),
            ),
            'Social Login (OAuth)' => array(
                array( 'key' => 'bt_google_client_id',     'label' => 'Google OAuth Client ID',     'help' => 'Callback: <code>' . esc_html( rest_url( 'blockticker/v1/auth/google/callback' ) ) . '</code>' ),
                array( 'key' => 'bt_google_client_secret', 'label' => 'Google OAuth Client Secret', 'help' => '',  'secret' => true ),
                array( 'key' => 'bt_github_client_id',     'label' => 'GitHub OAuth Client ID',     'help' => 'Callback: <code>' . esc_html( rest_url( 'blockticker/v1/auth/github/callback' ) ) . '</code>' ),
                array( 'key' => 'bt_github_client_secret', 'label' => 'GitHub OAuth Client Secret', 'help' => '', 'secret' => true ),
            ),
            'Email Sender' => array(
                array( 'key' => 'bt_mail_from_email', 'label' => 'From Address',
                       'help' => 'Sets the From: header on every outgoing email (alerts, news digests, password resets). Defaults to <code>wordpress@&lt;your-domain&gt;</code>. Use an address on your own domain (e.g. <code>info@blockticker.io</code>) to avoid spam-flagging.',
                       'type' => 'email', 'placeholder' => 'info@blockticker.io' ),
                array( 'key' => 'bt_mail_from_name',  'label' => 'From Display Name',
                       'help' => 'Friendly sender name shown in the recipient\'s inbox. Defaults to your Site Name.',
                       'type' => 'text', 'placeholder' => 'BlockTicker' ),
            ),
            'Site Info' => array(
                array( 'key' => 'bt_site_name', 'label' => 'Site Name',        'help' => 'Used in meta tags, OG cards, and email templates', 'type' => 'text' ),
                array( 'key' => 'bt_og_image',  'label' => 'Default OG Image', 'help' => '1200×630 image URL for social sharing',           'type' => 'url' ),
            ),
        );
        ?>
        <div class="wrap" style="max-width:900px">
            <h1 style="margin:20px 0 4px">API Credentials</h1>
            <p style="color:#646970;font-size:13px;margin:0 0 24px;max-width:700px">
                Third-party keys used by BlockTicker for live market data, AI content generation, monetisation, analytics, and social login.
                Stored in WordPress options. Secrets are masked after save — leave a field blank to keep the current value.
            </p>

            <?php
            // v119.25.0: Site Branding panel — logo + favicon at the top of the page
            // because users typically configure these before API keys.
            if ( class_exists( 'BT_Branding' ) ) BT_Branding::render_panel();

            // v119.25.0: Save-verification status table — shows immediate
            // confirmation that each credential saved correctly. Builds the
            // table from the LIVE database state via get_option(), so what
            // the operator sees here is exactly what the rest of the codebase
            // reads. Rebuilds on every page load — never cached.
            $verify_keys = array(
                'CoinMarketCap'         => 'bt_cmc_api_key',
                'CoinGecko'             => 'bt_cg_api_key',
                'ExchangeRate-API'      => 'bt_fx_api_key',
                'Anthropic Claude'      => 'bt_claude_key',
                'AdSense Publisher ID'  => 'bt_adsense_id',
                'AdSense Slot ID'       => 'bt_adsense_slot',
                'Google Analytics ID'   => 'bt_ga_id',
                'AI Review Mode'        => 'bt_ai_review_mode',
                'Google OAuth ID'       => 'bt_google_client_id',
                'Google OAuth Secret'   => 'bt_google_client_secret',
                'GitHub OAuth ID'       => 'bt_github_client_id',
                'GitHub OAuth Secret'   => 'bt_github_client_secret',
                'Site Name'             => 'bt_site_name',
                'OG Image URL'          => 'bt_og_image',
                'From Email'            => 'bt_mail_from_email',
                'From Display Name'     => 'bt_mail_from_name',
            );
            ?>
            <div style="background:#fff;border:1px solid #e0e0e0;border-radius:4px;padding:16px 20px;margin-bottom:16px">
                <h2 style="margin:0 0 4px;font-size:14px;text-transform:uppercase;letter-spacing:.08em;color:#1d2327">📋 Saved Credentials Status</h2>
                <p style="margin:0 0 12px;font-size:12px;color:#646970">Live status from the database. After clicking Save, refresh this page and verify the indicators below — this is the source of truth for what the rest of the plugin reads.</p>
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:6px 16px;font-size:13px">
                    <?php foreach ( $verify_keys as $label => $key ) :
                        $val = get_option( $key, '' );
                        // v119.28.2: stricter "is set" detection.
                        // The select-style fields (bt_ai_review_mode) store
                        // "0" / "1" — "0" is a *valid saved value* meaning
                        // "auto-publish (default)", not an empty/unset row.
                        // Earlier logic treated any "0" as empty, which made
                        // bt_ai_review_mode permanently display as "empty"
                        // even after the operator explicitly chose
                        // auto-publish and saved.
                        if ( $key === 'bt_ai_review_mode' ) {
                            // The option exists and is "0" or "1" → SET.
                            // Only false (= row never written) is empty.
                            $is_set = ( get_option( $key, false ) !== false );
                        } else {
                            $is_set = ! ( $val === '' || $val === false );
                        }
                    ?>
                        <div style="display:flex;align-items:center;gap:8px">
                            <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:<?php echo $is_set ? '#22c55e' : '#cbd5e1'; ?>" aria-hidden="true"></span>
                            <span style="color:<?php echo $is_set ? '#1d2327' : '#888'; ?>"><?php echo esc_html( $label ); ?></span>
                            <span style="margin-left:auto;font-size:11px;font-weight:600;color:<?php echo $is_set ? '#22c55e' : '#94a3b8'; ?>"><?php echo $is_set ? 'SET' : 'empty'; ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <form method="post" action="">
                <?php wp_nonce_field( 'bt_credentials' ); ?>
                <?php foreach ( $groups as $group_name => $fields ) : ?>
                    <div class="bt-cred-group" style="background:#fff;border:1px solid #e0e0e0;border-radius:4px;padding:18px 22px;margin-bottom:16px">
                        <h2 style="margin:0 0 12px;font-size:14px;text-transform:uppercase;letter-spacing:.08em;color:#1d2327"><?php echo $group_name; ?></h2>
                        <?php foreach ( $fields as $f ) :
                            $current = get_option( $f['key'], '' );
                            $is_secret = ! empty( $f['secret'] )
                                || strpos( $f['key'], '_secret' )   !== false
                                || strpos( $f['key'], '_api_key' )  !== false
                                || $f['key'] === 'bt_claude_key';
                            // Explicit type wins over auto-detection.
                            $type = $f['type'] ?? ( $is_secret ? 'password' : 'text' );
                            // Placeholder priority:
                            //   1. masked-value placeholder for saved secrets (security UX)
                            //   2. explicit per-field placeholder from $f['placeholder']
                            //   3. empty
                            if ( $is_secret && $current ) {
                                $placeholder = $mask( $current ) . '   (saved — leave blank to keep)';
                            } else {
                                $placeholder = $f['placeholder'] ?? '';
                            }
                        ?>
                            <div style="display:grid;grid-template-columns:220px 1fr;gap:18px;align-items:start;padding:10px 0;border-top:1px solid #f0f0f0">
                                <label for="<?php echo esc_attr( $f['key'] ); ?>" style="font-weight:600;font-size:13px;padding-top:7px">
                                    <?php echo esc_html( $f['label'] ); ?>
                                    <?php if ( ! empty( $f['link'] ) ) : ?>
                                        <a href="<?php echo esc_url( $f['link'] ); ?>" target="_blank" rel="noopener" style="display:block;font-weight:400;font-size:11px;color:#2271b1;margin-top:3px">Get key &rarr;</a>
                                    <?php endif; ?>
                                </label>
                                <div>
                                    <?php if ( $type === 'select' && ! empty( $f['options'] ) ) : ?>
                                        <select id="<?php echo esc_attr( $f['key'] ); ?>" name="<?php echo esc_attr( $f['key'] ); ?>" class="regular-text" style="width:100%;max-width:500px">
                                            <?php foreach ( $f['options'] as $val => $lbl ) : ?>
                                                <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $current, (string) $val ); ?>><?php echo esc_html( $lbl ); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php else : ?>
                                        <input type="<?php echo esc_attr( $type ); ?>"
                                               id="<?php echo esc_attr( $f['key'] ); ?>"
                                               name="<?php echo esc_attr( $f['key'] ); ?>"
                                               value="<?php echo $is_secret ? '' : esc_attr( $current ); ?>"
                                               placeholder="<?php echo esc_attr( $placeholder ); ?>"
                                               class="regular-text"
                                               style="width:100%;max-width:500px">
                                    <?php endif; ?>
                                    <?php if ( ! empty( $f['help'] ) ) : ?>
                                        <p class="description" style="margin:5px 0 0;font-size:12px;color:#646970"><?php echo $f['help']; ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
                <p style="margin-top:20px">
                    <button type="submit" name="bt_save_credentials" class="button button-primary button-large">Save Credentials</button>
                </p>
            </form>

            <!-- Backup / Restore ─────────────────────────────────────────── -->
            <div style="margin-top:32px;padding:20px 22px;border:1px solid #e0e0e0;border-radius:4px;background:#f9f9f9">
                <h2 style="margin:0 0 4px;font-size:14px;text-transform:uppercase;letter-spacing:.08em;color:#1d2327">🔐 Backup &amp; Restore Credentials</h2>
                <p style="margin:0 0 16px;font-size:13px;color:#646970">Export your credentials to a JSON file before reinstalling the plugin, then import them after. Credentials are stored in the WordPress database and are lost when the plugin is deleted.</p>
                <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-start">
                    <!-- Export -->
                    <div>
                        <button id="bt-cred-export" class="button button-secondary">⬇ Export Credentials (JSON)</button>
                        <p class="description" style="margin:4px 0 0;font-size:12px">Downloads a JSON backup of all saved credentials.</p>
                    </div>
                    <!-- Migrate legacy -->
                    <div>
                        <button id="bt-cred-migrate" class="button button-secondary">↺ Migrate Legacy Keys (fxlm_*→bt_*)</button>
                        <p class="description" style="margin:4px 0 0;font-size:12px">Run once if credentials were saved in a pre-v119.20 install.</p>
                    </div>
                </div>
                <!-- Import -->
                <div style="margin-top:16px;padding:14px 16px;border:1px dashed #ccc;border-radius:4px;background:#fff">
                    <label style="font-weight:600;font-size:13px;display:block;margin-bottom:6px">⬆ Import Credentials from JSON</label>
                    <textarea id="bt-cred-import-json" rows="4" style="width:100%;max-width:640px;font-family:monospace;font-size:12px;border:1px solid #ccc;border-radius:3px;padding:8px" placeholder='Paste exported JSON here, e.g. {"bt_cg_api_key":"abc123",...}'></textarea>
                    <div style="margin-top:8px">
                        <button id="bt-cred-import" class="button button-primary">Import</button>
                        <span id="bt-cred-import-msg" style="margin-left:12px;font-size:13px"></span>
                    </div>
                </div>
                <div id="bt-cred-backup-msg" style="margin-top:10px;font-size:13px"></div>
            </div>
            <script>
            (function($){
                var nonce = '<?php echo wp_create_nonce( 'fxlm_nonce' ); ?>';
                // Export
                $('#bt-cred-export').on('click', function(){
                    var $btn = $(this).prop('disabled', true).text('Exporting…');
                    $.post(ajaxurl, { action:'bt_export_credentials', nonce:nonce }, function(res){
                        $btn.prop('disabled', false).text('⬇ Export Credentials (JSON)');
                        if (res.success) {
                            var blob = new Blob([JSON.stringify(res.data, null, 2)], {type:'application/json'});
                            var url  = URL.createObjectURL(blob);
                            var a    = document.createElement('a');
                            a.href  = url;
                            a.download = 'blockticker-credentials-' + new Date().toISOString().slice(0,10) + '.json';
                            document.body.appendChild(a); a.click();
                            document.body.removeChild(a); URL.revokeObjectURL(url);
                            $('#bt-cred-backup-msg').css('color','#22c55e').text('✅ Credentials exported. Save this file somewhere safe before reinstalling.');
                        } else {
                            $('#bt-cred-backup-msg').css('color','#dc2626').text('❌ Export failed: ' + (res.data||'unknown error'));
                        }
                    }).fail(function(){ $btn.prop('disabled',false).text('⬇ Export Credentials (JSON)'); $('#bt-cred-backup-msg').css('color','#dc2626').text('❌ Request failed.'); });
                });
                // Migrate legacy
                $('#bt-cred-migrate').on('click', function(){
                    var $btn = $(this).prop('disabled', true).text('Migrating…');
                    $.post(ajaxurl, { action:'bt_migrate_legacy_credentials', nonce:nonce }, function(res){
                        $btn.prop('disabled', false).text('↺ Migrate Legacy Keys (fxlm_*→bt_*)');
                        var color = res.success ? '#22c55e' : '#dc2626';
                        var prefix = res.success ? '✅ ' : '❌ ';
                        $('#bt-cred-backup-msg').css('color', color).text(prefix + (res.data||''));
                    }).fail(function(){ $btn.prop('disabled',false); $('#bt-cred-backup-msg').css('color','#dc2626').text('❌ Request failed.'); });
                });
                // Import
                $('#bt-cred-import').on('click', function(){
                    var json = $('#bt-cred-import-json').val().trim();
                    if (!json) { $('#bt-cred-import-msg').css('color','#dc2626').text('Paste JSON first.'); return; }
                    try { JSON.parse(json); } catch(e) { $('#bt-cred-import-msg').css('color','#dc2626').text('Invalid JSON.'); return; }
                    var $btn = $(this).prop('disabled',true).text('Importing…');
                    $.post(ajaxurl, { action:'bt_import_credentials', nonce:nonce, payload:json }, function(res){
                        $btn.prop('disabled',false).text('Import');
                        if (res.success) {
                            $('#bt-cred-import-msg').css('color','#22c55e').text('✅ ' + res.data + ' — reload page to see updated status.');
                        } else {
                            $('#bt-cred-import-msg').css('color','#dc2626').text('❌ ' + (res.data||'Import failed'));
                        }
                    }).fail(function(){ $btn.prop('disabled',false).text('Import'); $('#bt-cred-import-msg').css('color','#dc2626').text('❌ Request failed.'); });
                });
            })(jQuery);
            </script>
        </div>
        <?php
    }

    // ── FEATURE TOGGLES ─────────────────────────────────────────────────────
    public static function render_feature_toggles() {
        if ( ! current_user_can('manage_options') ) wp_die('Unauthorized');
        $nonce = wp_create_nonce('fxlm_features');

        $features = array(
            'show_fear_greed'       => array('label'=>'Fear & Greed Widget',    'desc'=>'Show Fear & Greed Index on pages'),
            'show_ticker_bar'       => array('label'=>'Price Ticker Bar',       'desc'=>'Scrolling ticker bar at top of pages'),
            'show_breaking_news'    => array('label'=>'Breaking News Bar',      'desc'=>'Breaking news strip below ticker'),
            'show_trending_bar'     => array('label'=>'Trending Coins Bar',     'desc'=>'Trending coins strip on homepage'),
            'show_newsletter'       => array('label'=>'Newsletter Form',        'desc'=>'Email subscription form/banner'),
            'show_bottom_ticker'    => array('label'=>'Bottom Ticker Bar',      'desc'=>'Fixed ticker bar at bottom of page'),
            'show_gainers_losers'   => array('label'=>'Gainers & Losers',      'desc'=>'Top gainers/losers widget'),
            'show_tradingview'      => array('label'=>'TradingView Charts',     'desc'=>'Embedded TradingView interactive charts'),
            'show_ai_analysis'      => array('label'=>'AI Analysis Badge',     'desc'=>'AI Autopilot Active badge on analysis page'),
            'enable_watchlist'      => array('label'=>'Watchlist Feature',      'desc'=>'Allow users to save coin watchlists in browser'),
            'enable_vote_widget'    => array('label'=>'Bullish/Bearish Votes',  'desc'=>'Community voting widget on coin pages'),
            'enable_dark_mode'      => array('label'=>'Dark Theme (default)',   'desc'=>'Force dark theme (recommended)'),
            'show_adsense'          => array('label'=>'AdSense Ads',            'desc'=>'Show AdSense ad units (requires publisher ID)'),
            'show_affiliate'        => array('label'=>'Affiliate Broker Cards', 'desc'=>'Show broker affiliate cards'),
            'enable_crypto_pages'   => array('label'=>'Individual Coin Pages',  'desc'=>'e.g. /crypto/bitcoin/ — CMC-style detail pages'),
            'enable_forex_pages'    => array('label'=>'Individual Forex Pages', 'desc'=>'e.g. /forex/eur-usd/ — pair detail pages'),
            'show_exchange_dex_tab' => array('label'=>'DEX Tab on Exchanges',  'desc'=>'Show Derivatives/DEX tabs on exchanges page'),
        );

        if ( isset($_POST['bt_save_features']) && check_admin_referer('fxlm_features','fxlm_feat_nonce') ) {
            foreach ( $features as $key => $info ) {
                update_option('bt_feat_'.$key, isset($_POST['feat_'.$key]) ? 1 : 0);
            }
            echo '<div class="notice notice-success"><p>✅ Feature settings saved.</p></div>';
        }
        
?>
        <div class="wrap" style="max-width:860px">
            <h1 style="display:flex;align-items:center;gap:10px;margin-bottom:6px">
                <span style="background:linear-gradient(135deg,#00FF66,#00FF66);border-radius:0;width:32px;height:32px;display:inline-flex;align-items:center;justify-content:center;font-size:18px">🎛</span>
                Feature Toggles
            </h1>
            <p style="color:var(--bt-text-3);margin-bottom:24px">Enable or disable any feature instantly. Changes take effect immediately on save — no code editing needed.</p>
            <form method="post">
                <?php wp_nonce_field('fxlm_features','fxlm_feat_nonce'); ?>
                <div style="background:#121316;border:1px solid rgba(0,255,102,.2);border-radius:0;padding:22px;margin-bottom:20px">
                    <?php foreach ($features as $key => $info):
                        $val = get_option('bt_feat_'.$key, 1); // default ON
                    ?>
                    <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 0;border-bottom:1px solid rgba(255,255,255,.05)">
                        <div>
                            <div style="font-size:14px;font-weight:700;color:var(--bt-text)"><?php echo esc_html($info['label']); ?></div>
                            <div style="font-size:12px;color:var(--bt-text-3);margin-top:2px"><?php echo esc_html($info['desc']); ?></div>
                        </div>
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;flex-shrink:0;margin-left:20px">
                            <input type="checkbox" name="feat_<?php echo esc_attr($key); ?>" <?php checked($val,1); ?> style="width:18px;height:18px;accent-color:#00FF66">
                            <span style="font-size:12px;color:var(--bt-text-3)"><?php echo $val ? 'ON' : 'OFF'; ?></span>
                        </label>
                    </div>
                    <?php endforeach; ?>
                </div>
                <input type="hidden" name="bt_save_features" value="1">
                <button type="submit" style="background:linear-gradient(135deg,#00FF66,#00FF66);color:#0A0B0D;font-weight:700;padding:12px 28px;border:none;border-radius:0;cursor:pointer;font-size:14px">💾 Save Feature Settings</button>
            </form>
        </div>
        <?php
    }

    // ── FILE EDITOR ─────────────────────────────────────────────────────────
    // ── MENU CONFIGURATOR ───────────────────────────────────────────────────
    public static function render_menu_configurator() {
        $nonce    = wp_create_nonce( 'fxlm_menu_config' );
        $home_url = trailingslashit( home_url() );

        // Default nav items config
        $defaults = [
            ['label'=>'Home',       'url'=>'/',              'type'=>'link',     'enabled'=>true],
            ['label'=>'Crypto',     'url'=>'/crypto-markets/','type'=>'dropdown','enabled'=>true],
            ['label'=>'Forex',      'url'=>'/forex-charts/','type'=>'dropdown', 'enabled'=>true],
            ['label'=>'Exchanges',  'url'=>'/exchanges/',    'type'=>'link',     'enabled'=>true],
            ['label'=>'News',       'url'=>'/financial-news/','type'=>'link',    'enabled'=>true],
            ['label'=>'Analysis',   'url'=>'/market-analysis/','type'=>'dropdown','enabled'=>true],
            ['label'=>'Blog',       'url'=>'/market-blog/',  'type'=>'link',     'enabled'=>true],
            ['label'=>'Tools',      'url'=>'/tools/',        'type'=>'dropdown', 'enabled'=>true],
        ];
        $saved   = get_option( 'bt_menu_config', [] );
        $items   = ! empty( $saved ) ? $saved : $defaults;
        $site_name = get_option( 'bt_site_name', 'BlockTicker' );
        $logo_url  = get_option( 'bt_logo_url', '' );
        ?>
        <div class="wrap" style="max-width:900px">
            <h1 style="display:flex;align-items:center;gap:10px;margin-bottom:6px">
                <span style="background:linear-gradient(135deg,#00FF66,#00FF66);border-radius:0;width:32px;height:32px;display:inline-flex;align-items:center;justify-content:center;font-size:18px">🧭</span>
                Menu Configurator
            </h1>
            <p style="color:var(--bt-text-3);margin-bottom:24px">Configure your navbar menu items, order, labels and URLs. Changes apply immediately on save.</p>

            <!-- Brand settings -->
            <div style="background:#121316;border:1px solid rgba(0,255,102,.2);border-radius:0;padding:22px;margin-bottom:20px">
                <h3 style="color:var(--bt-text);margin-top:0">Brand Settings</h3>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
                    <div>
                        <label style="display:block;font-size:12px;color:var(--bt-text-3);margin-bottom:6px">Site Name (shown in navbar)</label>
                        <input type="text" id="bt-site-name" value="<?php echo esc_attr($site_name); ?>" placeholder="BlockTicker" style="width:100%;background:#0A0B0D;border:1px solid #1e2940;border-radius:0;padding:10px 14px;color:var(--bt-text);font-size:14px">
                    </div>
                    <div>
                        <label style="display:block;font-size:12px;color:var(--bt-text-3);margin-bottom:6px">Logo URL (leave empty for SVG icon)</label>
                        <input type="url" id="bt-logo-url" value="<?php echo esc_attr($logo_url); ?>" placeholder="https://example.com/logo.png" style="width:100%;background:#0A0B0D;border:1px solid #1e2940;border-radius:0;padding:10px 14px;color:var(--bt-text);font-size:14px">
                    </div>
                </div>
            </div>

            <!-- Menu items -->
            <div style="background:#121316;border:1px solid rgba(0,255,102,.2);border-radius:0;padding:22px;margin-bottom:20px">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
                    <h3 style="color:var(--bt-text);margin:0">Navigation Items</h3>
                    <button onclick="btMenuAddItem()" style="background:rgba(0,255,102,.1);border:1px solid rgba(0,255,102,.3);color:#00FF66;padding:6px 16px;border-radius:0;cursor:pointer;font-size:13px">+ Add Item</button>
                </div>
                <p style="color:var(--bt-text-3);font-size:12px;margin-top:0">Drag to reorder · Toggle to show/hide · Edit labels and URLs directly</p>
                <div id="bt-menu-items" style="display:flex;flex-direction:column;gap:8px">
                    <?php foreach ( $items as $idx => $item ) : ?>
                    <div class="bt-menu-row" data-idx="<?php echo $idx; ?>" style="display:grid;grid-template-columns:24px 1fr 1fr 120px 80px 32px;gap:10px;align-items:center;background:#0A0B0D;border:1px solid #1e2940;border-radius:0;padding:12px 16px">
                        <span style="color:var(--bt-text-4);cursor:grab;font-size:18px" title="Drag to reorder">⠿</span>
                        <input type="text" class="bt-item-label" value="<?php echo esc_attr($item['label']??''); ?>" placeholder="Label" style="background:#121316;border:1px solid #1e2940;border-radius:0;padding:7px 10px;color:var(--bt-text);font-size:13px;width:100%">
                        <input type="text" class="bt-item-url"   value="<?php echo esc_attr($item['url']??''); ?>"   placeholder="/slug/"  style="background:#121316;border:1px solid #1e2940;border-radius:0;padding:7px 10px;color:var(--bt-text-2);font-size:13px;width:100%">
                        <select class="bt-item-type" style="background:#121316;border:1px solid #1e2940;border-radius:0;padding:7px 10px;color:var(--bt-text-2);font-size:12px">
                            <option value="link" <?php selected($item['type']??'link','link'); ?>>Direct Link</option>
                            <option value="dropdown" <?php selected($item['type']??'link','dropdown'); ?>>Has Dropdown</option>
                        </select>
                        <label style="display:flex;align-items:center;gap:6px;font-size:12px;color:var(--bt-text-3);cursor:pointer">
                            <input type="checkbox" class="bt-item-enabled" <?php checked($item['enabled']??true,true); ?> style="width:16px;height:16px;accent-color:#00FF66">
                            Visible
                        </label>
                        <button onclick="this.closest('.bt-menu-row').remove()" style="background:rgba(255,59,48,.1);border:1px solid rgba(255,59,48,.3);color:#FF3B30;width:28px;height:28px;border-radius:0;cursor:pointer;font-size:16px;line-height:1">×</button>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div style="display:flex;gap:12px;align-items:center">
                <button id="bt-save-menu" style="background:linear-gradient(135deg,#00FF66,#00FF66);color:#0A0B0D;font-weight:700;padding:12px 28px;border:none;border-radius:0;cursor:pointer;font-size:14px">💾 Save Menu Configuration</button>
                <span id="bt-menu-msg" style="font-size:13px"></span>
            </div>
        </div>
        <script>
        function btMenuAddItem() {
            var idx = document.querySelectorAll('.bt-menu-row').length;
            var html = '<div class="bt-menu-row" data-idx="'+idx+'" style="display:grid;grid-template-columns:24px 1fr 1fr 120px 80px 32px;gap:10px;align-items:center;background:#0A0B0D;border:1px solid #1e2940;border-radius:0;padding:12px 16px">'
                + '<span style="color:var(--bt-text-4);cursor:grab;font-size:18px">⠿</span>'
                + '<input type="text" class="bt-item-label" value="" placeholder="Label" style="background:#121316;border:1px solid #1e2940;border-radius:0;padding:7px 10px;color:var(--bt-text);font-size:13px;width:100%">'
                + '<input type="text" class="bt-item-url"   value="" placeholder="/slug/" style="background:#121316;border:1px solid #1e2940;border-radius:0;padding:7px 10px;color:var(--bt-text-2);font-size:13px;width:100%">'
                + '<select class="bt-item-type" style="background:#121316;border:1px solid #1e2940;border-radius:0;padding:7px 10px;color:var(--bt-text-2);font-size:12px"><option value="link">Direct Link</option><option value="dropdown">Has Dropdown</option></select>'
                + '<label style="display:flex;align-items:center;gap:6px;font-size:12px;color:var(--bt-text-3);cursor:pointer"><input type="checkbox" class="bt-item-enabled" checked style="width:16px;height:16px;accent-color:#00FF66"> Visible</label>'
                + '<button onclick="this.closest(\'.bt-menu-row\').remove()" style="background:rgba(255,59,48,.1);border:1px solid rgba(255,59,48,.3);color:#FF3B30;width:28px;height:28px;border-radius:0;cursor:pointer;font-size:16px;line-height:1">×</button>'
                + '</div>';
            document.getElementById('bt-menu-items').insertAdjacentHTML('beforeend', html);
        }

        document.getElementById('bt-save-menu').addEventListener('click', function(){
            var btn = this;
            btn.disabled = true; btn.textContent = 'Saving…';
            var rows = document.querySelectorAll('.bt-menu-row');
            var items = [];
            rows.forEach(function(r){
                items.push({
                    label:   r.querySelector('.bt-item-label').value.trim(),
                    url:     r.querySelector('.bt-item-url').value.trim(),
                    type:    r.querySelector('.bt-item-type').value,
                    enabled: r.querySelector('.bt-item-enabled').checked
                });
            });
            var data = new FormData();
            data.append('action',     'fxlm_save_menu_config');
            data.append('nonce',      '<?php echo $nonce; ?>');
            data.append('menu_items', JSON.stringify(items));
            data.append('site_name',  document.getElementById('bt-site-name').value.trim());
            data.append('logo_url',   document.getElementById('bt-logo-url').value.trim());
            fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {method:'POST', body:data})
                .then(r=>r.json())
                .then(function(d){
                    var msg = document.getElementById('bt-menu-msg');
                    msg.textContent = d.success ? '✓ Saved! Reload your site to see changes.' : '✗ Error saving.';
                    msg.style.color = d.success ? '#00FF66' : '#FF3B30';
                    btn.disabled = false; btn.textContent = '💾 Save Menu Configuration';
                    setTimeout(function(){ msg.textContent=''; }, 5000);
                });
        });
        </script>
        <?php
    }

    public static function ajax_save_menu_config() {
        BT_Utils::verify_admin_ajax( 'fxlm_menu_config' );

        $raw   = wp_unslash( $_POST['menu_items'] ?? '[]' );
        $items = json_decode( $raw, true );
        if ( ! is_array( $items ) ) wp_send_json_error( 'Invalid data' );

        $clean = [];
        foreach ( $items as $item ) {
            $clean[] = [
                'label'   => sanitize_text_field( $item['label'] ?? '' ),
                'url'     => sanitize_text_field( $item['url'] ?? '/' ),
                'type'    => in_array($item['type']??'', ['link','dropdown']) ? $item['type'] : 'link',
                'enabled' => (bool)($item['enabled'] ?? true),
            ];
        }
        update_option( 'bt_menu_config', $clean );

        if ( ! empty( $_POST['site_name'] ) ) {
            update_option( 'bt_site_name', sanitize_text_field( $_POST['site_name'] ) );
            update_option( 'blogname', sanitize_text_field( $_POST['site_name'] ) );
        }
        if ( isset( $_POST['logo_url'] ) ) {
            update_option( 'bt_logo_url', esc_url_raw( $_POST['logo_url'] ) );
        }

        wp_send_json_success( 'Menu configuration saved.' );
    }

    public static function enqueue( $hook ) {
        // v119.26.0: admin-theme.css must load on EVERY BlockTicker admin screen
        // (not only the wizard) — it provides the light-theme CSS variables and
        // overrides the legacy hardcoded dark inline backgrounds.
        $is_bt_screen = (
               strpos( $hook, 'fxlm-' )       !== false
            || strpos( $hook, 'bt-' )         !== false
            || strpos( $hook, 'blockticker' ) !== false
        );
        if ( ! $is_bt_screen ) return;

        // Light admin theme — loaded on all BT screens.
        wp_enqueue_style( 'bt-admin-theme', BT_URL . 'assets/css/admin-theme.css', array(), BT_VERSION );

        // Wizard-only stylesheet + JS (only those screens have the step UI).
        $is_wizard_family = (
               strpos( $hook, 'fxlm-wizard' )         !== false
            || strpos( $hook, 'fxlm-page-manager' )   !== false
            || strpos( $hook, 'fxlm-blog-generator' ) !== false
            || strpos( $hook, 'fxlm-menu-config' )    !== false
            || strpos( $hook, 'fxlm-file-editor' )    !== false
            || strpos( $hook, 'fxlm-features' )       !== false
        );
        if ( $is_wizard_family ) {
            wp_enqueue_style( 'fxlm-admin', BT_URL . 'assets/css/admin.css', array( 'bt-admin-theme' ), BT_VERSION );
            wp_enqueue_script( 'fxlm-admin', BT_URL . 'assets/js/admin.js', array( 'jquery' ), BT_VERSION, true );
            wp_localize_script( 'fxlm-admin', 'fxlm', array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'fxlm_nonce' ),
            ) );
        }
    }

    /**
     * Admin notices — v119.6 cleaned up
     *
     * Removed:
     *   • Stale v97 namespace migration notice (migration long complete)
     *   • Hardcoded conflict list (plugins change over time; out of date)
     *
     * Kept (all actionable):
     *   • Diagnostic file present → one-click delete button
     *   • Page content updates → link to wizard
     *   • Fresh activation → open dashboard
     */
    public static function admin_notice() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        /* 1-click delete for the diagnostic file */
        if ( file_exists( BT_DIR . 'blockticker-diag.php' ) ) {
            // Handle delete action
            if ( isset( $_GET['bt_delete_diag'] ) && wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'bt_delete_diag' ) ) {
                @unlink( BT_DIR . 'blockticker-diag.php' );
                wp_safe_redirect( remove_query_arg( array( 'bt_delete_diag', '_wpnonce' ) ) );
                exit;
            }
            $delete_url = wp_nonce_url( add_query_arg( 'bt_delete_diag', '1' ), 'bt_delete_diag' );
            echo '<div class="notice notice-warning"><p>';
            echo '🔒 <strong>BlockTicker Security:</strong> The diagnostic file <code>blockticker-diag.php</code> is present on production. ';
            echo '<a href="' . esc_url( $delete_url ) . '" class="button button-small" style="margin-left:8px">Delete Now</a>';
            echo '</p></div>';
        }

        // Pages need updating notice
        if ( get_option( 'bt_pages_need_update' ) ) {
            $url = admin_url( 'admin.php?page=fxlm-wizard' );
            echo '<div class="notice notice-warning" style="border-left-color:#00FF66"><p>';
            echo '📄 <strong>BlockTicker:</strong> Page layouts were updated in this version. ';
            echo '<a href="' . esc_url($url) . '" class="button button-small">Re-generate pages</a>';
            echo '</p></div>';
        }

        if ( get_option( 'bt_activated' ) ) {
            $url = admin_url( 'admin.php?page=fxlm-wizard' );
            echo '<div class="notice notice-info is-dismissible"><p>';
            echo '<strong>BlockTicker:</strong> Welcome! Finish setup in the dashboard. ';
            echo '<a href="' . esc_url( $url ) . '" class="button button-primary button-small">Open Dashboard &rarr;</a>';
            echo '</p></div>';
            delete_option( 'bt_activated' );
        }

        // Health warnings
        $issues = get_option( 'bt_health_issues', array() );
        if ( ! empty( $issues ) && current_user_can( 'manage_options' ) ) {
            echo '<div class="notice notice-warning"><p><strong>BlockTicker Health:</strong> ' . esc_html( implode( ' | ', $issues ) ) . '</p></div>';
        }
    }

    public static function get_steps() {
        return array(
            'step_settings'      => array( 'label' => 'Configure WordPress settings',           'icon' => '⚙️',  'phase' => 1 ),
            'step_plugins'       => array( 'label' => 'Install recommended plugins (Yoast, Cache, Security, Backups)',  'icon' => '🔌',  'phase' => 2 ),
            'step_theme'         => array( 'label' => 'Install & activate GeneratePress theme',    'icon' => '🎨',  'phase' => 2 ),
            'step_pages'         => array( 'label' => 'Create all 15 pages',                    'icon' => '📄',  'phase' => 3 ),
            'step_menus'         => array( 'label' => 'Build navigation menus',                  'icon' => '🧭',  'phase' => 3 ),
            'step_widgets'       => array( 'label' => 'Set up live Forex & Crypto widgets',      'icon' => '📊',  'phase' => 4 ),
            'step_tools'         => array( 'label' => 'Set up converter, Fear & Greed, newsletter', 'icon' => '🛠️', 'phase' => 4 ),
            'step_rss'           => array( 'label' => 'Configure RSS news feeds (14+ sources)',  'icon' => '📰',  'phase' => 5 ),
            'step_cron'          => array( 'label' => 'Set up auto-refresh cron jobs',           'icon' => '🔄',  'phase' => 5 ),
            'step_seo'           => array( 'label' => 'Configure SEO, schema & sitemap',         'icon' => '🔍',  'phase' => 6 ),
            'step_monetization'  => array( 'label' => 'Set up AdSense & affiliate zones',        'icon' => '💰',  'phase' => 6 ),
            'step_social'        => array( 'label' => 'Add social media icons (8 networks)',      'icon' => '📱',  'phase' => 6 ),
            'step_security'      => array( 'label' => 'Apply security hardening',                'icon' => '🔒',  'phase' => 7 ),
            'step_autopilot'     => array( 'label' => 'Enable auto-updates (autopilot mode)',    'icon' => '🚀',  'phase' => 7 ),
            'step_gdpr'          => array( 'label' => 'Enable GDPR cookie consent & security',  'icon' => '🛡️',  'phase' => 8 ),
            'step_aiblog'        => array( 'label' => 'Set up daily AI blog (1 post/day auto)',  'icon' => '🤖',  'phase' => 8 ),
            'step_ads'           => array( 'label' => 'Configure ad zones & affiliate system',   'icon' => '💎',  'phase' => 8 ),
            'step_cleanup'       => array( 'label' => 'Delete demo content & go live',           'icon' => '✨',  'phase' => 8 ),
        );
    }

    public static function render_page() {
        $progress = get_option( 'bt_setup_progress', array() );
        $steps    = self::get_steps();
        $done     = min( count( $steps ), count( array_filter( $progress, function($v) { return $v === true; } ) ) );
        $total    = count( $steps );
        $pct      = $total > 0 ? min( 100, round( ( $done / $total ) * 100 ) ) : 0;
        $phases   = array( 1 => 'Foundation', 2 => 'Theme & Plugins', 3 => 'Pages & Menus', 4 => 'Live Data & Tools', 5 => 'Autoblog', 6 => 'Monetise & SEO', 7 => 'Security & Launch', 8 => 'Compliance & AI' );
        ?>
        <div class="fxlm-wrap">
            <div class="fxlm-header">
                <div class="fxlm-logo">
                    <span style="display:inline-block;width:28px;height:28px;background:linear-gradient(135deg,#00FF66,#00FF66);border-radius:0;text-align:center;line-height:28px;color:#fff;font-weight:bold;margin-right:8px;font-size:14px">CP</span>
                    BlockTicker — Setup Wizard
                </div>
                <div class="fxlm-version">v<?php echo BT_VERSION; ?></div>
            </div>

            <div class="fxlm-progress-bar-wrap">
                <div class="fxlm-progress-bar" style="width:<?php echo $pct; ?>%"></div>
            </div>
            <div class="fxlm-progress-label"><?php echo $done; ?> of <?php echo $total; ?> steps complete (<?php echo $pct; ?>%)</div>

            <?php if ( $done === $total ) : ?>
            <div class="fxlm-success-banner">
                🎉 <strong>Setup complete!</strong> Your BlockTicker site is fully configured and running on autopilot.
                <a href="<?php echo home_url(); ?>" target="_blank">View your site →</a>
            </div>
            <?php endif; ?>

            <!-- Maintenance Actions — clean, no obsolete buttons -->
            <div style="background:#0f1322;border:1px solid rgba(0,255,102,.2);border-radius:0;padding:16px 20px;margin:12px 0">
                <div style="font-size:13px;font-weight:700;color:var(--bt-text);margin-bottom:12px">🔧 Maintenance</div>
                <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center">
                    <button onclick="fxlmClearCache(this)" class="button" style="background:rgba(167,139,250,.08);border-color:rgba(167,139,250,.25);color:#a78bfa;font-weight:700">
                        🗑 Clear All Data Cache
                    </button>
                    <button onclick="fxlmResetForex(this)" class="button" style="background:rgba(0,153,255,.08);border-color:rgba(0,153,255,.25);color:#60a5fa;font-weight:700">
                        📊 Fix Forex 0% Change
                    </button>
                    <button onclick="fxlmFlushRewrites(this)" class="button" style="background:rgba(0,255,102,.06);border-color:rgba(0,255,102,.2);color:#00FF66;font-weight:600;font-size:12px">
                        🔗 Flush Permalinks
                    </button>
                </div>
                <div id="fxlm-quickfix-msg" style="margin-top:8px;font-size:12px;color:#00FF66;display:none"></div>
            </div>
            <script>
            function fxlmQuickMsg(text, color) {
                var msg = document.getElementById('fxlm-quickfix-msg');
                msg.style.display = 'block';
                msg.style.color = color || '#00FF66';
                msg.textContent = text;
            }
            function fxlmFlushRewrites(btn) {
                btn.disabled = true; btn.textContent = '⏳ Flushing...';
                jQuery.post(ajaxurl, {action:'fxlm_flush_rewrites', _ajax_nonce: '<?php echo wp_create_nonce("fxlm_flush"); ?>'}, function(r){
                    btn.disabled = false; btn.textContent = '🔗 Flush Permalinks';
                    fxlmQuickMsg(r.success ? '✅ Done — ' + r.data : '❌ Failed', r.success ? '#00FF66' : '#FF3B30');
                });
            }
            function fxlmResetForex(btn) {
                btn.disabled = true; btn.textContent = '⏳ Resetting...';
                jQuery.post(ajaxurl, {action:'fxlm_reset_forex_prev', _ajax_nonce: '<?php echo wp_create_nonce("fxlm_flush"); ?>'}, function(r){
                    btn.disabled = false; btn.textContent = '📊 Fix Forex 0% Change';
                    fxlmQuickMsg(r.success ? '✅ ' + r.data : '❌ Failed', r.success ? '#60a5fa' : '#FF3B30');
                });
            }
            </script>

            <?php
            // Health status
            $last_check = get_option( 'bt_last_health_check', 0 );
            $issues     = get_option( 'bt_health_issues', array() );
            if ( $last_check ) :
            ?>
            <div class="fxlm-health-status" style="margin:12px 0;padding:12px 16px;background:<?php echo empty($issues) ? 'rgba(0,255,102,.1)' : 'rgba(245,158,11,.1)'; ?>;border-radius:0;font-size:13px;">
                <?php if ( empty( $issues ) ) : ?>
                    ✅ <strong>Autopilot healthy</strong> — last check <?php echo human_time_diff( $last_check ); ?> ago. All systems running.
                <?php else : ?>
                    ⚠️ <strong>Issues detected:</strong> <?php echo esc_html( implode( ' | ', $issues ) ); ?>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php
            /* v119.20: API Keys form moved to Credentials submenu (single source
             * of truth — same option-key namespace as the rest of the codebase).
             * The wizard now shows a compact configuration-status summary instead
             * of duplicating ~70 lines of form. The AJAX endpoint ajax_run_step()
             * still accepts and saves any keys POSTed alongside Run-step calls
             * for backcompat, but no UI form here writes to it any more. */
            $cred_url = admin_url( 'admin.php?page=bt-credentials' );
            $cred_status = array(
                array( 'CoinMarketCap',          (bool) get_option( 'bt_cmc_api_key' ),       'optional' ),
                array( 'CoinGecko',              (bool) get_option( 'bt_cg_api_key' ),        'optional' ),
                array( 'ExchangeRate-API',       (bool) get_option( 'bt_fx_api_key' ),        'optional' ),
                array( 'Anthropic Claude',       (bool) get_option( 'bt_claude_key' ),        'optional' ),
                array( 'Google AdSense ID',      (bool) get_option( 'bt_adsense_id' ),        'optional' ),
                array( 'Google Analytics ID',    (bool) get_option( 'bt_ga_id' ),             'optional' ),
                array( 'Google OAuth (login)',   (bool) get_option( 'bt_google_client_id' ) && (bool) get_option( 'bt_google_client_secret' ), 'optional' ),
                array( 'GitHub OAuth (login)',   (bool) get_option( 'bt_github_client_id' ) && (bool) get_option( 'bt_github_client_secret' ), 'optional' ),
            );
            $set_count = count( array_filter( $cred_status, function( $r ) { return $r[1]; } ) );
            $tot_count = count( $cred_status );
            ?>
            <div class="fxlm-api-section">
                <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px;margin-bottom:10px">
                    <div>
                        <h3 style="margin:0">🔑 API Credentials <span style="font-size:13px;font-weight:400;color:#666;margin-left:8px"><?php echo $set_count; ?> of <?php echo $tot_count; ?> configured</span></h3>
                        <p style="margin:4px 0 0;font-size:13px;color:#646970">Manage all third-party keys (market data, AI, OAuth, monetisation) in one place.</p>
                    </div>
                    <a href="<?php echo esc_url( $cred_url ); ?>" class="fxlm-btn fxlm-btn-primary" style="white-space:nowrap">⚙ Manage Credentials →</a>
                </div>
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:8px 16px;margin-top:14px;padding:14px;background:#f6f7f7;border:1px solid #e0e0e0;border-radius:4px">
                    <?php foreach ( $cred_status as $row ) :
                        $name = $row[0]; $set = $row[1];
                    ?>
                        <div style="display:flex;align-items:center;gap:8px;font-size:13px;color:#1d2327">
                            <?php if ( $set ) : ?>
                                <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#22c55e" aria-hidden="true"></span>
                                <span><?php echo esc_html( $name ); ?></span>
                                <span style="margin-left:auto;font-size:11px;color:#22c55e;font-weight:600">SET</span>
                            <?php else : ?>
                                <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#cbd5e1" aria-hidden="true"></span>
                                <span style="color:#666"><?php echo esc_html( $name ); ?></span>
                                <span style="margin-left:auto;font-size:11px;color:#94a3b8">not set</span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="fxlm-run-all-wrap">
                <!-- Create Pages — most commonly needed action, shown first -->
                <div style="background:rgba(0,255,102,.06);border:1px solid rgba(0,255,102,.2);border-radius:0;padding:18px 20px;margin-bottom:16px">
                    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
                        <div>
                            <div style="font-size:14px;font-weight:700;color:var(--bt-text);margin-bottom:4px">📄 Create / Update All Pages</div>
                            <div style="font-size:12px;color:var(--bt-text-3)">21 pages total · Safe to run multiple times — only updates content, never deletes</div>
                        </div>
                        <button id="fxlm-create-pages-btn" class="fxlm-btn fxlm-btn-primary" style="flex-shrink:0;font-size:14px;padding:10px 22px">
                            📄 Create All Pages Now
                        </button>
                    </div>
                    <div id="fxlm-pages-msg" style="display:none;margin-top:10px;padding:8px 14px;border-radius:0;font-size:13px"></div>
                </div>

                <!-- Delete Duplicates — fix for pages created multiple times -->
                <div style="background:rgba(255,59,48,.06);border:1px solid rgba(255,59,48,.2);border-radius:0;padding:18px 20px;margin-bottom:16px">
                    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
                        <div>
                            <div style="font-size:14px;font-weight:700;color:var(--bt-text);margin-bottom:4px">🗑 Delete Duplicate Pages</div>
                            <div style="font-size:12px;color:var(--bt-text-3)">Permanently removes duplicate pages (DeFi Tokens, NFT etc.). Keeps oldest, deletes the rest.</div>
                        </div>
                        <button id="fxlm-del-dupes-btn" class="fxlm-btn" style="flex-shrink:0;font-size:14px;padding:10px 22px;background:rgba(255,59,48,.12);border:1px solid rgba(255,59,48,.3);color:#FF3B30;border-radius:0;cursor:pointer;font-family:inherit;font-weight:700">
                            🗑 Delete Duplicates Now
                        </button>
                    </div>
                    <div id="fxlm-dupes-msg" style="display:none;margin-top:10px;padding:8px 14px;border-radius:0;font-size:13px"></div>
                </div>

                <!-- Exchange Enrichment -->
                <div style="background:rgba(0,153,255,.06);border:1px solid rgba(0,153,255,.2);border-radius:0;padding:18px 20px;margin-bottom:16px">
                    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
                        <div>
                            <div style="font-size:14px;font-weight:700;color:var(--bt-text);margin-bottom:4px">🏛 Fetch Exchange Details (Markets / Coins / Fiat)</div>
                            <div style="font-size:12px;color:var(--bt-text-3)">Fetches #Markets, #Coins and Fiat Supported data for top 30 exchanges from CoinGecko. Runs automatically every hour — click to force refresh now.</div>
                        </div>
                        <button id="fxlm-enrich-exc-btn" class="fxlm-btn fxlm-btn-secondary" style="flex-shrink:0;font-size:14px;padding:10px 22px">
                            🏛 Fetch Exchange Data Now
                        </button>
                    </div>
                    <div id="fxlm-enrich-exc-msg" style="display:none;margin-top:10px;padding:8px 14px;border-radius:0;font-size:13px"></div>
                </div>

                <!-- Force Forex Refresh -->
                <div style="background:rgba(0,255,102,.04);border:1px solid rgba(0,255,102,.15);border-radius:0;padding:18px 20px;margin-bottom:16px">
                    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
                        <div>
                            <div style="font-size:14px;font-weight:700;color:var(--bt-text);margin-bottom:4px">💱 Force Fetch Live Forex Rates</div>
                            <div style="font-size:12px;color:var(--bt-text-3)">If forex shows "Demo data", click this to force a fresh live fetch from all 5 fallback sources (Frankfurter → open.er-api → fawazahmed0 → exchangerate.host → currencyfreaks). Also resets the 24h baseline.</div>
                        </div>
                        <button id="fxlm-force-forex-btn" class="fxlm-btn fxlm-btn-primary" style="flex-shrink:0;font-size:14px;padding:10px 22px">
                            💱 Fetch Forex Now
                        </button>
                    </div>
                    <div id="fxlm-force-forex-msg" style="display:none;margin-top:10px;padding:8px 14px;border-radius:0;font-size:13px"></div>
                </div>
                <script>
                document.getElementById('fxlm-enrich-exc-btn') && document.getElementById('fxlm-enrich-exc-btn').addEventListener('click', function(){
                    var btn = this;
                    var msg = document.getElementById('fxlm-enrich-exc-msg');
                    btn.disabled = true; btn.textContent = '⏳ Fetching…';
                    msg.style.display = 'none';
                    jQuery.post(fxlm.ajax_url, {action:'fxlm_run_step', step:'step_exchange_enrich', nonce:fxlm.nonce}, function(res){
                        btn.disabled = false; btn.textContent = '🏛 Fetch Exchange Data Now';
                        msg.style.display = 'block';
                        msg.style.background = res.success ? 'rgba(0,255,102,.1)' : 'rgba(255,59,48,.1)';
                        msg.style.color = res.success ? '#00FF66' : '#FF3B30';
                        msg.textContent = res.success ? '✅ ' + res.data : '❌ ' + res.data;
                    });
                });
                document.getElementById('fxlm-force-forex-btn') && document.getElementById('fxlm-force-forex-btn').addEventListener('click', function(){
                    var btn = this;
                    var msg = document.getElementById('fxlm-force-forex-msg');
                    btn.disabled = true; btn.textContent = '⏳ Fetching forex…';
                    msg.style.display = 'none';
                    jQuery.post(fxlm.ajax_url, {action:'fxlm_run_step', step:'step_force_forex', nonce:fxlm.nonce}, function(res){
                        btn.disabled = false; btn.textContent = '💱 Fetch Forex Now';
                        msg.style.display = 'block';
                        msg.style.background = res.success ? 'rgba(0,255,102,.1)' : 'rgba(255,59,48,.1)';
                        msg.style.color = res.success ? '#00FF66' : '#FF3B30';
                        msg.textContent = res.success ? '✅ ' + res.data : '❌ ' + res.data;
                    });
                });
                </script>
                <script>
                document.getElementById('fxlm-create-pages-btn').addEventListener('click', function(){
                    var btn = this;
                    var msg = document.getElementById('fxlm-pages-msg');
                    btn.disabled = true;
                    btn.textContent = '⏳ Creating pages…';
                    msg.style.display = 'none';
                    jQuery.post(fxlm.ajax_url, {
                        action: 'fxlm_run_step',
                        step: 'step_pages',
                        nonce: fxlm.nonce
                    }, function(res){
                        btn.disabled = false;
                        btn.textContent = '📄 Create / Update Pages Now';
                        msg.style.display = 'block';
                        if(res.success){
                            msg.style.background = 'rgba(0,255,102,.1)';
                            msg.style.border = '1px solid rgba(0,255,102,.2)';
                            msg.style.color = '#00FF66';
                            msg.textContent = '✅ ' + res.data;
                        } else {
                            msg.style.background = 'rgba(255,59,48,.08)';
                            msg.style.border = '1px solid rgba(255,59,48,.2)';
                            msg.style.color = '#FF3B30';
                            msg.textContent = '❌ ' + (res.data || 'Error creating pages');
                        }
                    }).fail(function(){
                        btn.disabled = false;
                        btn.textContent = '📄 Create / Update Pages Now';
                        msg.style.display = 'block';
                        msg.style.color = '#FF3B30';
                        msg.textContent = '❌ Request failed. Check your browser console.';
                    });
                });
                </script>
                <script>
                document.getElementById('fxlm-del-dupes-btn').addEventListener('click', function(){
                    var btn = this;
                    var msg = document.getElementById('fxlm-dupes-msg');
                    if(!confirm('This will permanently delete all duplicate pages. Continue?')) return;
                    btn.disabled = true;
                    btn.textContent = '⏳ Deleting duplicates…';
                    msg.style.display = 'none';
                    jQuery.post(fxlm.ajax_url, {
                        action: 'fxlm_run_step',
                        step: 'step_delete_dupes',
                        nonce: fxlm.nonce
                    }, function(res){
                        btn.disabled = false;
                        btn.textContent = '🗑 Delete Duplicates Now';
                        msg.style.display = 'block';
                        if(res.success){
                            msg.style.background = 'rgba(0,255,102,.1)';
                            msg.style.border = '1px solid rgba(0,255,102,.2)';
                            msg.style.color = '#00FF66';
                            msg.textContent = '✅ ' + res.data;
                        } else {
                            msg.style.background = 'rgba(255,59,48,.08)';
                            msg.style.border = '1px solid rgba(255,59,48,.2)';
                            msg.style.color = '#FF3B30';
                            msg.textContent = '❌ ' + (res.data || 'Error');
                        }
                    }).fail(function(){
                        btn.disabled = false;
                        btn.textContent = '🗑 Delete Duplicates Now';
                        msg.style.display = 'block';
                        msg.style.color = '#FF3B30';
                        msg.textContent = '❌ Request failed.';
                    });
                });
                </script>

                <button class="fxlm-btn fxlm-btn-primary fxlm-btn-large" id="fxlm-run-all">
                    🚀 Run All Steps Automatically
                </button>
                <p class="fxlm-hint">Or run each step individually below</p>

                <!-- Diagnostics — collapsed by default, open if steps seem stuck -->
                <div id="bt-wizard-debug-wrap" style="margin-top:14px;display:none">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px">
                        <span style="font-size:12px;font-weight:700;color:rgba(255,255,255,.5);letter-spacing:.08em;text-transform:uppercase">AJAX Debug Log</span>
                        <div style="display:flex;gap:8px">
                            <button id="bt-test-wporg" style="background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.3);color:#f59e0b;font-size:11px;font-weight:700;padding:4px 12px;cursor:pointer;font-family:inherit">🌐 Test wordpress.org</button>
                            <button id="bt-ping-ajax" style="background:rgba(0,153,255,.12);border:1px solid rgba(0,153,255,.3);color:#60a5fa;font-size:11px;font-weight:700;padding:4px 12px;cursor:pointer;font-family:inherit">🏓 Ping AJAX</button>
                        </div>
                    </div>
                    <div id="bt-ping-result" style="font-size:12px;margin-bottom:6px"></div>
                    <textarea id="bt-wizard-debug-log" readonly rows="6"
                        style="width:100%;background:#0a0c0f;border:1px solid rgba(255,255,255,.1);color:#00ff66;font-family:'IBM Plex Mono',monospace;font-size:11px;padding:8px;resize:vertical;white-space:pre"></textarea>
                </div>
                <p style="margin-top:6px">
                    <a href="#" id="bt-show-debug" style="font-size:11px;color:rgba(255,255,255,.3);text-decoration:none">▸ Show diagnostics</a>
                </p>
                <script>
                document.getElementById('bt-show-debug').addEventListener('click', function(e){
                    e.preventDefault();
                    var wrap = document.getElementById('bt-wizard-debug-wrap');
                    var link = this;
                    if (wrap.style.display === 'none') {
                        wrap.style.display = 'block';
                        link.textContent = '▾ Hide diagnostics';
                    } else {
                        wrap.style.display = 'none';
                        link.textContent = '▸ Show diagnostics';
                    }
                });
                </script>
            </div>

            <?php
            $current_phase = 0;
            foreach ( $steps as $step_key => $step ) :
                if ( $step['phase'] !== $current_phase ) :
                    if ( $current_phase > 0 ) echo '</div>';
                    $current_phase = $step['phase'];
                    echo '<div class="fxlm-phase">';
                    echo '<div class="fxlm-phase-title">Phase ' . $current_phase . ' — ' . esc_html( $phases[ $current_phase ] ) . '</div>';
                endif;
                $status = isset( $progress[ $step_key ] ) ? $progress[ $step_key ] : 'pending';
                $status_class = $status === true ? 'done' : ( $status === 'error' ? 'error' : 'pending' );
                $status_icon  = $status === true ? '✅' : ( $status === 'error' ? '❌' : '⬜' );
            ?>
            <div class="fxlm-step <?php echo $status_class; ?>" id="step-row-<?php echo esc_attr( $step_key ); ?>">
                <div class="fxlm-step-left">
                    <span class="fxlm-step-status"><?php echo $status_icon; ?></span>
                    <span class="fxlm-step-icon"><?php echo $step['icon']; ?></span>
                    <span class="fxlm-step-label"><?php echo esc_html( $step['label'] ); ?></span>
                </div>
                <div class="fxlm-step-right">
                    <span class="fxlm-step-msg" id="msg-<?php echo esc_attr( $step_key ); ?>">
                        <?php
                        if ( $status === true ) echo 'Completed';
                        elseif ( $status === 'error' ) echo 'Error — click to retry';
                        else echo 'Pending';
                        ?>
                    </span>
                    <button class="fxlm-btn fxlm-btn-run" data-step="<?php echo esc_attr( $step_key ); ?>">
                        <?php echo $status === true ? '↺ Re-run' : '▶ Run'; ?>
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
            </div>

            <div class="fxlm-footer">
                <p>✅ Search engines are automatically enabled after setup.</p>
                <p>✅ Auto-updates are enabled — your site runs on autopilot.</p>
                <p>Need help? Check <a href="https://blockticker.io/docs" target="_blank">the documentation</a>.</p>
            </div>
        </div>
        <?php
    }

    public static function ajax_run_step() {
        // Capture any stray PHP output (notices, warnings) that would corrupt
        // the JSON response and cause jQuery to silently fire the error handler,
        // leaving the UI stuck on "Running...".
        ob_start();

        BT_Utils::verify_admin_ajax( 'fxlm_nonce' );

        // Extend PHP execution time for slow steps (plugin installs, etc.).
        // Safe to call even on hosts that disable set_time_limit() — it's a
        // no-op there rather than a fatal error.
        @set_time_limit( 300 );  // 5 minutes max per step

        // ── THE ROOT CAUSE OF "STUCK ON RUNNING" ─────────────────────────────
        // update_option('permalink_structure') fires WP core hooks that call
        // wp_update_plugins() and wp_version_check(), which make outbound SSL
        // connections to api.wordpress.org.  On blockticker.io (and many shared
        // hosts) the server CANNOT reach wordpress.org — the SSL handshake hangs
        // for ~60 seconds then fails.  That 60 s stall eats the entire PHP
        // execution budget, the response is empty/truncated, jQuery never gets
        // JSON, and the step row stays on "Running..." forever.
        //
        // Fix: block all wordpress.org HTTP for the duration of this AJAX
        // handler using the pre_http_request filter (priority 1 = fires first),
        // and detach the specific hooks that would trigger the connections.
        $bt_block_wporg = static function( $preempt, $parsed_args, $url ) {
            if ( strpos( $url, 'wordpress.org' ) !== false ||
                 strpos( $url, 'api.w.org' )     !== false ) {
                return new WP_Error(
                    'bt_wporg_blocked',
                    'Blocked during wizard step to prevent 60 s hang (server cannot reach wordpress.org).'
                );
            }
            return $preempt;
        };
        add_filter( 'pre_http_request', $bt_block_wporg, 1, 3 );

        // Belt-and-braces: directly remove the cron-update hooks that fire
        // on every update_option() call for several common option keys.
        remove_action( 'update_option_permalink_structure', 'wp_clean_plugins_cache', 10 );
        $update_hooks = array( 'wp_update_plugins', 'wp_update_themes', 'wp_version_check' );
        foreach ( $update_hooks as $hook ) {
            remove_action( 'update_option_permalink_structure', $hook );
            remove_action( 'updating_option', $hook );
            remove_action( 'updated_option',  $hook );
        }

        // Suspend object-cache invalidation during the bulk option writes to
        // avoid expensive per-write cache flushes.
        wp_suspend_cache_invalidation( true );

        $step = sanitize_text_field( $_POST['step'] ?? '' );

        // Save API keys if sent
        if ( ! empty( $_POST['fx_api_key'] ) )  update_option( 'bt_fx_api_key',  sanitize_text_field( $_POST['fx_api_key'] ) );
        if ( ! empty( $_POST['cg_api_key'] ) )  update_option( 'bt_cg_api_key',  sanitize_text_field( $_POST['cg_api_key'] ) );
        if ( isset( $_POST['cmc_api_key'] ) )   update_option( 'bt_cmc_api_key', sanitize_text_field( $_POST['cmc_api_key'] ) );
        if ( ! empty( $_POST['adsense_id'] ) )  update_option( 'bt_adsense_id',  sanitize_text_field( $_POST['adsense_id'] ) );
        if ( ! empty( $_POST['ga_id'] ) )       update_option( 'bt_ga_id',       sanitize_text_field( $_POST['ga_id'] ) );
        if ( isset( $_POST['claude_key'] ) )     update_option( 'bt_claude_key',   sanitize_text_field( $_POST['claude_key'] ) );
        if ( ! empty( $_POST['site_name'] ) )   update_option( 'bt_site_name',   sanitize_text_field( $_POST['site_name'] ) );
        if ( isset( $_POST['adsense_slot'] ) )  update_option( 'bt_adsense_slot', sanitize_text_field( $_POST['adsense_slot'] ) ); // FIX ADS-01
        if ( isset( $_POST['og_image'] ) )      update_option( 'bt_og_image',     esc_url_raw( $_POST['og_image'] ) );            // FIX OG-01
        if ( isset( $_POST['bt_google_client_id'] ) )     update_option( 'bt_google_client_id',     sanitize_text_field( $_POST['bt_google_client_id'] ) );
        if ( isset( $_POST['bt_google_client_secret'] ) ) update_option( 'bt_google_client_secret', sanitize_text_field( $_POST['bt_google_client_secret'] ) );
        if ( isset( $_POST['bt_github_client_id'] ) )     update_option( 'bt_github_client_id',     sanitize_text_field( $_POST['bt_github_client_id'] ) );
        if ( isset( $_POST['bt_github_client_secret'] ) ) update_option( 'bt_github_client_secret', sanitize_text_field( $_POST['bt_github_client_secret'] ) );
        if ( isset( $_POST['ai_review_mode'] ) ) update_option( 'bt_ai_review_mode', sanitize_text_field( $_POST['ai_review_mode'] ) ); // AI-02

        $result = array( 'success' => false, 'message' => 'Unknown step' );

        try {
            switch ( $step ) {
                case 'step_settings':     $result = BT_Settings::run(); break;
                case 'step_plugins':      $result = BT_Installer::install_plugins(); break;
                case 'step_theme':        $result = BT_Installer::install_theme(); break;
                case 'step_pages':
                    $result = BT_Pages::create_all();
                    delete_option( 'bt_pages_need_update' );
                    break;
                case 'step_delete_dupes':
                    $deleted = BT_Pages::delete_duplicates();
                    $result  = array( 'success' => true, 'message' => $deleted > 0
                        ? "Deleted {$deleted} duplicate page(s). Reload this page to see updated status."
                        : 'No duplicates found — all pages are unique.' );
                    break;
                case 'step_menus':        $result = BT_Pages::create_menus(); break;
                case 'step_widgets':      $result = BT_Widgets::setup(); break;
                case 'step_exchange_enrich':
                    BT_Exchanges::fetch_exchange_details();
                    $count = count( get_option('bt_exchange_details', []) );
                    $result = ['success'=>true,'message'=>"Exchange details fetched. {$count} exchanges enriched with Markets/Coins/Fiat data."];
                    break;
                case 'step_force_forex':
                    delete_option('bt_forex_prev_rates');
                    delete_option('bt_forex_prev_updated');
                    BT_Widgets::fetch_forex_prices();
                    $forex = BT_Widgets::get_json_option('fxlm_forex_data');
                    $src   = $forex['source'] ?? 'unknown';
                    $count = count($forex['rates'] ?? []);
                    $result = ['success' => $src !== 'demo', 'message' => $src !== 'demo'
                        ? "Live forex fetched via {$src}. {$count} pairs updated."
                        : 'All 5 API sources failed. Check server outbound HTTP. Demo data shown temporarily.'];
                    break;
                case 'step_tools':        $result = BT_Tools::setup(); break;
                case 'step_rss':          $result = BT_RSS::setup(); break;
                case 'step_cron':         $result = BT_RSS::setup_cron(); break;
                case 'step_seo':          $result = BT_SEO_Legacy::setup(); break;
                case 'step_monetization': $result = BT_SEO_Legacy::setup_monetization(); break;
                case 'step_social':       $result = BT_Settings::setup_social(); break;
                case 'step_security':     $result = BT_Settings::setup_security(); break;
                case 'step_autopilot':    $result = BT_AutoUpdate::setup(); break;
                case 'step_gdpr':         $result = BT_GDPR::setup(); break;
                case 'step_aiblog':       $result = BT_AIBlog::setup(); break;
                case 'step_ads':          $result = BT_Monetize::setup(); break;
                case 'step_cleanup':      $result = BT_Settings::cleanup(); break;
            }
        } catch ( Throwable $e ) {
            // Catch any PHP exception/error from a step handler so the AJAX
            // always returns clean JSON — never a blank/HTML fatal error page.
            $result = array(
                'success' => false,
                'message' => 'PHP error in ' . $step . ': ' . $e->getMessage()
                             . ' (line ' . $e->getLine() . ' of ' . basename( $e->getFile() ) . ')',
            );
            error_log( '[BlockTicker] ajax_run_step ' . $step . ' threw: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
        }

        // Restore cache invalidation and remove the wordpress.org HTTP block.
        wp_suspend_cache_invalidation( false );
        remove_filter( 'pre_http_request', $bt_block_wporg, 1 );

        $progress = get_option( 'bt_setup_progress', array() );
        $progress[ $step ] = $result['success'] ? true : 'error';
        update_option( 'bt_setup_progress', $progress );

        // Discard any stray output (PHP notices/warnings) so the JSON response
        // is not corrupted — a corrupted response makes jQuery fire the error
        // handler silently, leaving the UI stuck on "Running..." forever.
        ob_end_clean();

        if ( $result['success'] ) {
            wp_send_json_success( $result['message'] );
        } else {
            wp_send_json_error( $result['message'] );
        }
    }

    // ── AI BLOG GENERATOR PAGE ────────────────────────────────────────
    // ── PAGE MANAGER ─────────────────────────────────────────────────────────
    public static function render_page_manager() {
        $pages_config = BT_Pages::get_pages_config();
        $nonce = wp_create_nonce( 'fxlm_nonce' );
        ?>
        <div class="wrap" style="max-width:860px">
            <h1 style="display:flex;align-items:center;gap:10px;margin-bottom:6px">
                <span style="background:linear-gradient(135deg,#00FF66,#00FF66);border-radius:0;width:32px;height:32px;display:inline-flex;align-items:center;justify-content:center;font-size:18px">📄</span>
                Page Manager
            </h1>
            <p style="color:var(--bt-text-3);margin-bottom:24px">Create, update or view all BlockTicker pages. Run this after every plugin update to ensure new pages exist.</p>

            <!-- Create All Pages -->
            <div style="background:#121316;border:1px solid rgba(0,255,102,.2);border-radius:0;padding:22px;margin-bottom:20px">
                <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:14px">
                    <div>
                        <div style="font-size:15px;font-weight:700;color:var(--bt-text);margin-bottom:4px">📄 Create / Update All Pages</div>
                        <div style="font-size:12px;color:var(--bt-text-3)"><?php echo count($pages_config); ?> pages total · Safe to run multiple times — only updates content, never deletes</div>
                    </div>
                    <button id="pm-create-all" class="button button-primary" style="padding:8px 22px;font-size:14px;font-weight:700;height:auto">
                        📄 Create All Pages Now
                    </button>
                </div>
                <div id="pm-create-msg" style="display:none;padding:10px 14px;border-radius:0;font-size:13px;margin-top:8px"></div>
            </div>

            <?php
            // ── v119.28.13: BlockTicker landing-revamp toggles ─────────────────
            // Surface the homepage-switch and global-nav toggles inline here in
            // the existing Page Manager so users don't have to hunt for a
            // separate Tools screen.
            if ( class_exists( 'BT_Page_Provisioner' ) ) {
                $home_on   = get_option( 'bt_use_revamped_homepage' ) === '1';
                $global_on = get_option( 'bt_use_global_nav' )       === '1';
                $bt_nonce  = wp_create_nonce( 'bt_provision_nonce' );
                $bt_action = admin_url( 'admin-post.php' );
                ?>
                <!-- BlockTicker Landing Toggles -->
                <?php if ( isset( $_GET['bt_msg'] ) ) : ?>
                <div style="background:rgba(0,217,126,.08);border:1px solid rgba(0,217,126,.3);border-left:3px solid #00d97e;padding:12px 16px;margin-bottom:16px;color:#00d97e;font-size:13px">
                    ✓ <?php echo esc_html( wp_unslash( $_GET['bt_msg'] ) ); ?>
                </div>
                <?php endif; ?>
                <div style="background:#121316;border:1px solid rgba(0,217,126,.25);border-left:3px solid #00d97e;border-radius:0;padding:22px;margin-bottom:20px">
                    <h2 style="margin:0 0 4px;font-size:15px;color:var(--bt-text)">🎨 New BlockTicker Landing &amp; Navigation</h2>
                    <p style="margin:0 0 18px;font-size:12px;color:var(--bt-text-3)">Activate the revamped landing page and the new site-wide navigation introduced in v119.28.</p>

                    <!-- Toggle 1: homepage -->
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:14px;padding:12px 0;border-bottom:1px solid rgba(255,255,255,.06)">
                        <div style="flex:1;min-width:0">
                            <div style="font-size:14px;font-weight:700;color:var(--bt-text);margin-bottom:2px">Use revamped landing as homepage</div>
                            <div style="font-size:12px;color:var(--bt-text-3)">Renders the new landing template (hero, signal-in-action, methodology, etc.) on <code><?php echo esc_url( home_url('/') ); ?></code> without touching <em>Settings → Reading</em>.</div>
                        </div>
                        <form method="post" action="<?php echo esc_url( $bt_action ); ?>" style="margin:0;flex-shrink:0">
                            <input type="hidden" name="action" value="bt_toggle_homepage">
                            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $bt_nonce ); ?>">
                            <input type="hidden" name="enable" value="<?php echo $home_on ? '0' : '1'; ?>">
                            <button type="submit" class="button button-<?php echo $home_on ? 'secondary' : 'primary'; ?>" style="padding:6px 18px">
                                <?php echo $home_on ? '✓ ON — disable' : 'Enable'; ?>
                            </button>
                        </form>
                    </div>

                    <!-- Toggle 2: global nav -->
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:14px;padding:12px 0">
                        <div style="flex:1;min-width:0">
                            <div style="font-size:14px;font-weight:700;color:var(--bt-text);margin-bottom:2px">Use new navigation site-wide</div>
                            <div style="font-size:12px;color:var(--bt-text-3)">Renders the new disclaimer + ticker + nav on <strong>every page</strong> (crypto-markets, signals, news, etc.). Hides the old <code>cp-navbar</code> automatically.</div>
                        </div>
                        <form method="post" action="<?php echo esc_url( $bt_action ); ?>" style="margin:0;flex-shrink:0">
                            <input type="hidden" name="action" value="bt_toggle_global_nav">
                            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $bt_nonce ); ?>">
                            <input type="hidden" name="enable" value="<?php echo $global_on ? '0' : '1'; ?>">
                            <button type="submit" class="button button-<?php echo $global_on ? 'secondary' : 'primary'; ?>" style="padding:6px 18px">
                                <?php echo $global_on ? '✓ ON — disable' : 'Enable'; ?>
                            </button>
                        </form>
                    </div>

                    <?php if ( $home_on || $global_on ) : ?>
                        <p style="margin:14px 0 0;font-size:12px;color:#00d97e">
                            ✓ Active — <a href="<?php echo esc_url( home_url('/') ); ?>" target="_blank" style="color:#00d97e;text-decoration:underline">visit the homepage</a> to verify.
                        </p>
                    <?php endif; ?>
                </div>
                <?php
            }
            ?>

            <!-- Page Status Table -->
            <div style="background:#121316;border:1px solid rgba(255,255,255,.07);border-radius:0;padding:22px">
                <h2 style="margin:0 0 16px;font-size:15px;color:var(--bt-text)">Current Page Status</h2>
                <table style="width:100%;border-collapse:collapse;font-size:13px">
                    <thead>
                        <tr style="border-bottom:1px solid rgba(255,255,255,.07)">
                            <th style="text-align:left;padding:8px 12px;color:var(--bt-text-3);font-weight:600">Page</th>
                            <th style="text-align:left;padding:8px 12px;color:var(--bt-text-3);font-weight:600">Slug</th>
                            <th style="text-align:left;padding:8px 12px;color:var(--bt-text-3);font-weight:600">Status</th>
                            <th style="padding:8px 12px;color:var(--bt-text-3);font-weight:600">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $pages_config as $key => $page ) :
                        $existing = get_page_by_path( $page['slug'] );
                        $exists   = ! is_null( $existing );
                        $status_color = $exists ? '#00FF66' : '#FF3B30';
                        $status_label = $exists ? '✅ Published' : '❌ Missing';
                        $view_url = $exists ? get_permalink( $existing->ID ) : null;
                        $edit_url = $exists ? get_edit_post_link( $existing->ID ) : null;
                    ?>
                    <tr style="border-bottom:1px solid rgba(255,255,255,.04)">
                        <td style="padding:10px 12px;color:var(--bt-text);font-weight:600"><?php echo esc_html($page['title']); ?></td>
                        <td style="padding:10px 12px;color:var(--bt-text-3)"><code>/<?php echo esc_html($page['slug']); ?>/</code></td>
                        <td style="padding:10px 12px;color:<?php echo $status_color; ?>;font-size:12px;font-weight:700"><?php echo $status_label; ?></td>
                        <td style="padding:10px 12px;text-align:right;white-space:nowrap">
                            <?php if ( $view_url ) : ?>
                            <a href="<?php echo esc_url($view_url); ?>" target="_blank" style="color:#00FF66;font-size:12px;text-decoration:none;margin-right:10px">View →</a>
                            <?php endif; ?>
                            <?php if ( $edit_url ) : ?>
                            <a href="<?php echo esc_url($edit_url); ?>" style="color:var(--bt-text-3);font-size:12px;text-decoration:none">Edit</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <script>
        document.getElementById('pm-create-all').addEventListener('click', function(){
            var btn = this;
            var msg = document.getElementById('pm-create-msg');
            btn.disabled = true; btn.textContent = '⏳ Creating pages…';
            jQuery.post(ajaxurl, {
                action: 'fxlm_run_step',
                step: 'step_pages',
                nonce: '<?php echo $nonce; ?>'
            }, function(res){
                btn.disabled = false; btn.textContent = '📄 Create All Pages Now';
                msg.style.display = 'block';
                if(res.success){
                    msg.style.cssText = 'display:block;padding:10px 14px;border-radius:0;font-size:13px;background:rgba(0,255,102,.08);border:1px solid rgba(0,255,102,.2);color:#00FF66';
                    msg.textContent = '✅ ' + res.data + ' — Reload this page to see updated status.';
                } else {
                    msg.style.cssText = 'display:block;padding:10px 14px;border-radius:0;font-size:13px;background:rgba(255,59,48,.08);border:1px solid rgba(255,59,48,.2);color:#FF3B30';
                    msg.textContent = '❌ ' + (res.data || 'Error');
                }
            });
        });
        </script>
        <?php
    }

    public static function render_blog_generator() {
        // v54: Autopilot topics (scheduled daily rotation) vs AI Analysis topics (on-demand, structured reports)
        $autopilot_topics = array(
            'bitcoin-analysis'    => '₿ Bitcoin Price Analysis',
            'ethereum-update'     => '⟠ Ethereum Market Update',
            'forex-weekly'        => '💱 Forex Weekly Review',
            'crypto-roundup'      => '📰 Daily Crypto Roundup',
            'altcoin-spotlight'   => '🚀 Altcoin Spotlight',
            'defi-update'         => '🔗 DeFi & Web3 Update',
            'cross-market-brief'  => '⇄ Cross-Market Brief',
            'education'           => '📚 Beginner Education Guide',
        );
        $analysis_topics = array(
            'ai-daily-report'     => '🧠 Daily Intelligence Report (Institutional)',
        );
        $topics = array_merge( $autopilot_topics, $analysis_topics );

        $autopilot_on = (bool) wp_next_scheduled( 'bt_daily_ai_post' );
        $claude_key   = get_option( 'bt_claude_key', '' );
        $review_mode  = get_option( 'bt_ai_review_mode', '0' );
        $autopilot_topic_pref = get_option( 'bt_ai_autopilot_topic', '' );   // v54: which topic autopilot picks
        $analysis_topic_pref  = get_option( 'bt_ai_analysis_topic', 'ai-daily-report' );
        $ai_provider          = get_option( 'bt_ai_provider', 'claude' );
        $openai_key           = get_option( 'bt_openai_key', '' );
        $active_tab   = isset( $_GET['tab'] ) && $_GET['tab'] === 'analysis' ? 'analysis' : 'autopilot';
        $recent_posts = get_posts( array( 'numberposts' => 8, 'post_status' => array( 'publish', 'pending' ), 'orderby' => 'date', 'order' => 'DESC' ) );
        $base_url     = admin_url( 'admin.php?page=fxlm-blog-generator' );
        ?>
        <div class="wrap" style="max-width:900px">
            <h1 style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
                <span style="background:linear-gradient(135deg,#00FF66,#00FF66);border-radius:0;width:32px;height:32px;display:inline-flex;align-items:center;justify-content:center;font-size:18px">✍️</span>
                AI Content Generator
            </h1>
            <p style="color:var(--bt-text-3);font-size:13px;margin:0 0 18px">Two separate pipelines: scheduled blog posts (Autopilot) and on-demand institutional reports (AI Analysis).</p>

            <!-- Tab bar (v54) -->
            <nav class="nav-tab-wrapper" style="margin-bottom:22px">
                <a href="<?php echo esc_url( $base_url . '&tab=autopilot' ); ?>" class="nav-tab<?php echo $active_tab === 'autopilot' ? ' nav-tab-active' : ''; ?>">🤖 Autopilot Blog</a>
                <a href="<?php echo esc_url( $base_url . '&tab=analysis' ); ?>" class="nav-tab<?php echo $active_tab === 'analysis' ? ' nav-tab-active' : ''; ?>">🧠 AI Analysis</a>
            </nav>

            <?php
            /* v119.27.0 — Autopilot Health card.
             * Shows next-run countdown, last-run result, recent log, and provides
             * a one-click preflight that pings every API integration with cheap
             * no-side-effect calls. Replaces the previous "blind" UX where
             * autopilot failures only surfaced in /wp-content/debug.log. */
            if ( class_exists( 'BT_Autopilot_Health' ) ) {
                BT_Autopilot_Health::render_card();
            }
            ?>

            <?php
            $has_active_key = ( $ai_provider === 'openai' && ! empty( $openai_key ) ) || ( $ai_provider === 'claude' && ! empty( $claude_key ) );
            if ( ! $has_active_key ) : ?>
            <div style="background:rgba(0,255,102,.06);border:1px solid rgba(0,255,102,.15);border-radius:0;padding:14px 18px;margin-bottom:20px;font-size:13px;color:var(--bt-text-2)">
                💡 <strong>No API key for active provider (<?php echo esc_html( $ai_provider ); ?>).</strong>
                Posts will be generated from live data aggregation (no AI).
                <?php if ( $ai_provider === 'claude' ) : ?>
                    Add your Claude key in the Setup Wizard, or switch provider below.
                <?php else : ?>
                    Add your OpenAI key in the AI Analysis tab below, or switch provider to Claude.
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ( $active_tab === 'autopilot' ) : ?>
            <!-- ═══════════════════════════════════════════════════
                 TAB 1 — AUTOPILOT: Scheduled blog posts
            ═══════════════════════════════════════════════════ -->

            <!-- Status bar -->
            <div style="background:#1a2035;border:1px solid rgba(255,255,255,.08);border-radius:0;padding:18px 22px;margin-bottom:24px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
                <div>
                    <strong style="color:var(--bt-text)">Autopilot Status:</strong>
                    <span id="fxlm-autopilot-status" style="margin-left:8px;font-weight:700;color:<?php echo $autopilot_on ? '#00FF66' : '#FF3B30'; ?>">
                        <?php echo $autopilot_on ? '🟢 ON — 1 post/day at 8:00 UTC' : '🔴 OFF'; ?>
                    </span>
                </div>
                <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                    <label style="color:var(--bt-text-2);font-size:13px">Review mode:
                        <select id="fxlm-review-mode" style="margin-left:6px;background:#0b0f1a;border:1px solid var(--bt-text-4);color:var(--bt-text);border-radius:0;padding:4px 8px">
                            <option value="0" <?php selected($review_mode,'0'); ?>>Auto-publish</option>
                            <option value="1" <?php selected($review_mode,'1'); ?>>Pending review</option>
                        </select>
                    </label>
                    <button id="fxlm-toggle-autopilot" class="button button-<?php echo $autopilot_on ? 'secondary' : 'primary'; ?>"
                        data-on="<?php echo $autopilot_on ? 1 : 0; ?>">
                        <?php echo $autopilot_on ? '⏸ Pause Autopilot' : '▶ Enable Autopilot'; ?>
                    </button>
                </div>
            </div>

            <!-- Autopilot topic preference -->
            <div style="background:#121316;border:1px solid rgba(255,255,255,.07);border-radius:0;padding:20px 24px;margin-bottom:24px">
                <h2 style="margin:0 0 6px;font-size:15px;color:var(--bt-text)">Scheduled Topic</h2>
                <p style="color:var(--bt-text-3);font-size:12px;margin:0 0 12px">Choose a fixed topic, or leave blank to rotate through all Autopilot topics (one per day).</p>
                <select id="fxlm-autopilot-topic" style="width:100%;background:#0b0f1a;border:1px solid var(--bt-text-4);color:var(--bt-text);border-radius:0;padding:10px 14px;font-size:14px">
                    <option value="">Rotate daily (recommended)</option>
                    <?php foreach ( $autopilot_topics as $key => $label ) : ?>
                    <option value="<?php echo esc_attr($key); ?>" <?php selected( $autopilot_topic_pref, $key ); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Manual Generator (Autopilot topics only) -->
            <div style="background:#121316;border:1px solid rgba(255,255,255,.07);border-radius:0;padding:24px;margin-bottom:24px">
                <h2 style="margin:0 0 18px;font-size:16px;color:var(--bt-text)">Generate a Blog Post Now</h2>
                <div style="display:grid;grid-template-columns:1fr auto;gap:12px;align-items:end">
                    <div>
                        <label style="display:block;font-size:12px;color:var(--bt-text-3);margin-bottom:6px;text-transform:uppercase;letter-spacing:.6px">Topic</label>
                        <select id="fxlm-topic-select" style="width:100%;background:#0b0f1a;border:1px solid var(--bt-text-4);color:var(--bt-text);border-radius:0;padding:10px 14px;font-size:14px">
                            <option value="">— Pick a topic or leave blank for today's rotation —</option>
                            <?php foreach ( $autopilot_topics as $key => $label ) : ?>
                            <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button id="fxlm-generate-btn" class="button button-primary" style="height:42px;padding:0 22px;font-size:14px;font-weight:700">
                        ✨ Generate Post
                    </button>
                </div>
                <div id="fxlm-gen-status" style="margin-top:14px;display:none;padding:12px 16px;border-radius:0;font-size:13px"></div>
            </div>

            <!-- Bulk generator -->
            <div style="background:#121316;border:1px solid rgba(255,255,255,.07);border-radius:0;padding:24px;margin-bottom:24px">
                <h2 style="margin:0 0 6px;font-size:16px;color:var(--bt-text)">Bulk Generate Seed Articles</h2>
                <p style="color:var(--bt-text-3);font-size:13px;margin:0 0 16px">Generates all Autopilot topic types at once. Useful to populate the blog on first setup. Backdated over the past 8 days.</p>
                <button id="fxlm-bulk-btn" class="button" style="height:38px;padding:0 20px;font-size:13px">
                    📦 Generate All Autopilot Topics
                </button>
                <div id="fxlm-bulk-status" style="margin-top:12px;display:none;padding:12px 16px;border-radius:0;font-size:13px"></div>
            </div>

            <?php else : ?>
            <!-- ═══════════════════════════════════════════════════
                 TAB 2 — AI ANALYSIS: On-demand institutional report
            ═══════════════════════════════════════════════════ -->

            <div style="background:linear-gradient(135deg,rgba(0,255,102,.08),rgba(99,102,241,.06));border:1px solid rgba(0,255,102,.2);border-radius:0;padding:22px 26px;margin-bottom:24px">
                <h2 style="margin:0 0 8px;font-size:17px;color:var(--bt-text);display:flex;align-items:center;gap:10px">
                    <span style="font-size:22px">🧠</span>
                    Daily Intelligence Report
                </h2>
                <p style="color:var(--bt-text-2);font-size:13px;margin:0 0 6px;line-height:1.6">Produces an institutional-style report covering top 5 coins, top 5 gainers, liquidity, support/resistance, cross-market signals, 24–72h outlook and key risks.</p>
                <p style="color:var(--bt-text-3);font-size:12px;margin:0">Uses live market data (CoinGecko + Frankfurter + Fear & Greed + news feed) as the only input. No invented figures. Hedge-fund tone.</p>
            </div>

            <!-- AI Provider Selector (v57) -->
            <div style="background:#121316;border:1px solid rgba(255,255,255,.07);border-radius:0;padding:20px 24px;margin-bottom:24px">
                <h2 style="margin:0 0 14px;font-size:15px;color:var(--bt-text)">AI Provider</h2>
                <p style="color:var(--bt-text-3);font-size:12px;margin:0 0 16px">Same prompts work with either provider. Switch to compare output quality or manage API budget.</p>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px">
                    <label style="cursor:pointer;display:flex;gap:10px;align-items:flex-start;padding:14px 16px;border:2px solid <?php echo $ai_provider === 'claude' ? '#00FF66' : 'var(--bt-text-4)'; ?>;border-radius:0;background:<?php echo $ai_provider === 'claude' ? 'rgba(0,255,102,.06)' : 'transparent'; ?>">
                        <input type="radio" name="fxlm_ai_provider_radio" value="claude" <?php checked( $ai_provider, 'claude' ); ?> style="margin-top:3px;accent-color:#00FF66">
                        <div>
                            <div style="font-weight:700;color:var(--bt-text);font-size:13.5px;display:flex;gap:6px;align-items:center">Anthropic Claude <?php if ( ! empty( $claude_key ) ) : ?><span style="background:rgba(0,255,102,.12);color:#00FF66;padding:1px 7px;border-radius:0;font-size:10px;font-weight:800">KEY SET</span><?php endif; ?></div>
                            <div style="color:var(--bt-text-2);font-size:11.5px;margin-top:4px;line-height:1.45">Deep analytical writing, strong structured output, excels at financial nuance</div>
                        </div>
                    </label>
                    <label style="cursor:pointer;display:flex;gap:10px;align-items:flex-start;padding:14px 16px;border:2px solid <?php echo $ai_provider === 'openai' ? '#10a37f' : 'var(--bt-text-4)'; ?>;border-radius:0;background:<?php echo $ai_provider === 'openai' ? 'rgba(16,163,127,.06)' : 'transparent'; ?>">
                        <input type="radio" name="fxlm_ai_provider_radio" value="openai" <?php checked( $ai_provider, 'openai' ); ?> style="margin-top:3px;accent-color:#10a37f">
                        <div>
                            <div style="font-weight:700;color:var(--bt-text);font-size:13.5px;display:flex;gap:6px;align-items:center">OpenAI ChatGPT <?php if ( ! empty( $openai_key ) ) : ?><span style="background:rgba(16,163,127,.12);color:#10a37f;padding:1px 7px;border-radius:0;font-size:10px;font-weight:800">KEY SET</span><?php endif; ?></div>
                            <div style="color:var(--bt-text-2);font-size:11.5px;margin-top:4px;line-height:1.45">Fast, punchy prose, strong for social thread generation</div>
                        </div>
                    </label>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                    <label style="display:flex;flex-direction:column;gap:6px">
                        <span style="font-size:11px;color:var(--bt-text-3);text-transform:uppercase;letter-spacing:.6px">OpenAI API key (<a href="https://platform.openai.com/api-keys" target="_blank" style="color:#10a37f">get one</a>)</span>
                        <input id="fxlm-openai-key" type="password" value="<?php echo esc_attr( $openai_key ); ?>" placeholder="sk-proj-..." style="background:#0b0f1a;border:1px solid var(--bt-text-4);color:var(--bt-text);border-radius:7px;padding:9px 12px;font-size:12px;font-family:'SF Mono',Menlo,monospace">
                    </label>
                    <label style="display:flex;flex-direction:column;gap:6px">
                        <span style="font-size:11px;color:var(--bt-text-3);text-transform:uppercase;letter-spacing:.6px">OpenAI model</span>
                        <select id="fxlm-openai-model" style="background:#0b0f1a;border:1px solid var(--bt-text-4);color:var(--bt-text);border-radius:7px;padding:9px 12px;font-size:13px">
                            <?php $om = get_option( 'bt_openai_model', 'gpt-4o' ); ?>
                            <option value="gpt-4o"      <?php selected( $om, 'gpt-4o' ); ?>>gpt-4o (flagship)</option>
                            <option value="gpt-4o-mini" <?php selected( $om, 'gpt-4o-mini' ); ?>>gpt-4o-mini (cheap, fast)</option>
                            <option value="gpt-4-turbo" <?php selected( $om, 'gpt-4-turbo' ); ?>>gpt-4-turbo (legacy)</option>
                        </select>
                    </label>
                </div>
                <p id="fxlm-provider-status" style="margin-top:10px;color:var(--bt-text-3);font-size:11px;display:none"></p>
            </div>

            <!-- Multi-format settings (v55) -->
            <div style="background:#121316;border:1px solid rgba(255,255,255,.07);border-radius:0;padding:20px 24px;margin-bottom:24px">
                <h2 style="margin:0 0 14px;font-size:15px;color:var(--bt-text)">Multi-Format Output Settings</h2>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
                    <label style="display:flex;flex-direction:column;gap:6px">
                        <span style="font-size:12px;color:var(--bt-text-3);text-transform:uppercase;letter-spacing:.6px">Twitter/X handle</span>
                        <input id="fxlm-twitter-handle" type="text" value="<?php echo esc_attr( get_option( 'bt_twitter_handle', '@blocktickerIO' ) ); ?>" placeholder="@blocktickerIO" style="background:#0b0f1a;border:1px solid var(--bt-text-4);color:var(--bt-text);border-radius:7px;padding:9px 12px;font-size:13px;font-family:'SF Mono',Menlo,monospace">
                    </label>
                    <label style="display:flex;align-items:center;gap:10px;padding:8px 0">
                        <input type="checkbox" id="fxlm-multiformat-toggle" <?php checked( get_option( 'bt_ai_multiformat', '1' ), '1' ); ?> style="width:18px;height:18px;accent-color:#00FF66">
                        <span style="color:var(--bt-text);font-size:13px">Also generate <strong style="color:#00FF66">Twitter thread</strong> + <strong style="color:#00FF66">newsletter</strong> (3× API calls)</span>
                    </label>
                </div>
                <p style="color:var(--bt-text-3);font-size:11px;margin:12px 0 14px">When enabled, generating an AI Analysis report also produces a ready-to-post X thread and a newsletter body, attached to the post as hidden meta fields. They appear below with copy &amp; share buttons.</p>

                <!-- v61: Featured image mode -->
                <div style="border-top:1px solid rgba(255,255,255,.05);padding-top:14px;margin-top:4px">
                    <label style="display:block;font-size:12px;color:var(--bt-text-3);margin-bottom:8px;text-transform:uppercase;letter-spacing:.6px">Featured Image Source <span style="color:#00FF66;text-transform:none;letter-spacing:0">(v61)</span></label>
                    <?php $img_mode = get_option( 'bt_ai_image_mode', 'smart' ); ?>
                    <select id="bt-ai-image-mode" style="width:100%;max-width:520px;background:#0b0f1a;border:1px solid var(--bt-text-4);color:var(--bt-text);border-radius:0;padding:9px 12px;font-size:13px">
                        <option value="smart"   <?php selected( $img_mode, 'smart' ); ?>>⚡ Smart (default) — match news image to post content, use branded fallback</option>
                        <option value="branded" <?php selected( $img_mode, 'branded' ); ?>>🎨 Branded — always use category placeholder (safest)</option>
                        <option value="news"    <?php selected( $img_mode, 'news' ); ?>>📰 News (legacy) — random news thumbnail (may mismatch)</option>
                        <option value="off"     <?php selected( $img_mode, 'off' ); ?>>✕ Off — no featured image</option>
                    </select>
                    <p style="color:var(--bt-text-3);font-size:11px;margin:8px 0 14px;line-height:1.55">
                        <strong style="color:#00FF66">Smart mode</strong> scores each news image by topic match (Bitcoin post → Bitcoin news) and blocks war/conflict imagery that used to appear on crypto posts. If no news image scores high enough, a branded category placeholder is used instead.
                    </p>

                    <!-- v61: Retroactive fix for existing posts -->
                    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding:12px 0 2px;border-top:1px solid rgba(255,255,255,.04);margin-top:4px">
                        <div style="flex:1;min-width:220px">
                            <strong style="display:block;color:var(--bt-text);font-size:13px">Fix existing AI post thumbnails</strong>
                            <span style="color:var(--bt-text-3);font-size:11px">Re-runs the selector on posts from the last 60 days. Unrelated war/drone images get replaced with branded placeholders.</span>
                        </div>
                        <button id="bt-refix-images-btn" type="button" class="button" style="background:linear-gradient(135deg,#a78bfa,#8b5cf6);border:none;color:#fff;font-weight:700">🔧 Fix thumbnails now</button>
                    </div>
                    <div id="bt-refix-status" style="display:none;margin-top:10px;padding:10px 14px;border-radius:0;font-size:12px"></div>
                </div>
            </div>

            <!-- v60: X (Twitter) Auto-Publish -->
            <div style="background:#121316;border:1px solid rgba(255,255,255,.07);border-radius:0;padding:20px 24px;margin-bottom:24px">
                <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px">
                    <h2 style="margin:0;font-size:15px;color:var(--bt-text)">𝕏 Auto-Publish to X (first 3 tweets)</h2>
                    <?php
                    $has_tw_creds = get_option( 'bt_twitter_api_key', '' ) && get_option( 'bt_twitter_api_secret', '' )
                                 && get_option( 'bt_twitter_access_token', '' ) && get_option( 'bt_twitter_access_token_secret', '' );
                    ?>
                    <span style="font-size:10px;font-weight:700;letter-spacing:.8px;padding:3px 10px;border-radius:0;<?php echo $has_tw_creds ? 'background:rgba(0,255,102,.15);color:#00FF66;border:1px solid rgba(0,255,102,.3)' : 'background:rgba(239,68,68,.1);color:#ef4444;border:1px solid rgba(239,68,68,.3)'; ?>">
                        <?php echo $has_tw_creds ? '● KEYS SET' : '○ KEYS MISSING'; ?>
                    </span>
                </div>

                <label style="display:flex;align-items:center;gap:10px;padding:10px 0;margin-bottom:6px;border-bottom:1px solid rgba(255,255,255,.05)">
                    <input type="checkbox" id="bt-tw-autopublish" <?php checked( get_option( 'bt_twitter_autopublish', '0' ), '1' ); ?> style="width:18px;height:18px;accent-color:#00FF66">
                    <span style="color:var(--bt-text);font-size:13px">Automatically post <strong style="color:#00FF66">first 3 tweets</strong> to X when a Daily Report is generated</span>
                </label>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:14px">
                    <label style="display:flex;flex-direction:column;gap:6px">
                        <span style="font-size:11px;color:var(--bt-text-3);text-transform:uppercase;letter-spacing:.6px">API Key (Consumer Key)</span>
                        <input id="bt-tw-ck" type="password" value="<?php echo esc_attr( get_option( 'bt_twitter_api_key', '' ) ); ?>" placeholder="paste from developer.x.com" autocomplete="new-password" style="background:#0b0f1a;border:1px solid var(--bt-text-4);color:var(--bt-text);border-radius:7px;padding:9px 12px;font-size:12px;font-family:'SF Mono',Menlo,monospace">
                    </label>
                    <label style="display:flex;flex-direction:column;gap:6px">
                        <span style="font-size:11px;color:var(--bt-text-3);text-transform:uppercase;letter-spacing:.6px">API Secret (Consumer Secret)</span>
                        <input id="bt-tw-cs" type="password" value="<?php echo esc_attr( get_option( 'bt_twitter_api_secret', '' ) ); ?>" placeholder="paste from developer.x.com" autocomplete="new-password" style="background:#0b0f1a;border:1px solid var(--bt-text-4);color:var(--bt-text);border-radius:7px;padding:9px 12px;font-size:12px;font-family:'SF Mono',Menlo,monospace">
                    </label>
                    <label style="display:flex;flex-direction:column;gap:6px">
                        <span style="font-size:11px;color:var(--bt-text-3);text-transform:uppercase;letter-spacing:.6px">Access Token</span>
                        <input id="bt-tw-tk" type="password" value="<?php echo esc_attr( get_option( 'bt_twitter_access_token', '' ) ); ?>" placeholder="user-context token with Write permission" autocomplete="new-password" style="background:#0b0f1a;border:1px solid var(--bt-text-4);color:var(--bt-text);border-radius:7px;padding:9px 12px;font-size:12px;font-family:'SF Mono',Menlo,monospace">
                    </label>
                    <label style="display:flex;flex-direction:column;gap:6px">
                        <span style="font-size:11px;color:var(--bt-text-3);text-transform:uppercase;letter-spacing:.6px">Access Token Secret</span>
                        <input id="bt-tw-ts" type="password" value="<?php echo esc_attr( get_option( 'bt_twitter_access_token_secret', '' ) ); ?>" placeholder="partner to the access token" autocomplete="new-password" style="background:#0b0f1a;border:1px solid var(--bt-text-4);color:var(--bt-text);border-radius:7px;padding:9px 12px;font-size:12px;font-family:'SF Mono',Menlo,monospace">
                    </label>
                </div>
                <p style="color:var(--bt-text-3);font-size:11px;margin:14px 0 0;line-height:1.55">
                    Create a Twitter app at <a href="https://developer.x.com/en/portal/dashboard" target="_blank" style="color:#00FF66;text-decoration:none">developer.x.com</a> → set app permissions to <strong>Read and Write</strong> → generate OAuth 1.0a <strong>User Context</strong> tokens. Free tier = 500 posts/month (plenty for 3× daily).
                </p>
            </div>

            <!-- v63: Typefully auto-queue -->
            <div style="background:#121316;border:1px solid rgba(255,255,255,.07);border-radius:0;padding:20px 24px;margin-bottom:24px">
                <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px">
                    <div>
                        <h2 style="margin:0;font-size:15px;color:var(--bt-text)">📅 Typefully — Queue Tweets 4-12 <span style="font-size:10px;color:#a78bfa;margin-left:6px">(v63)</span></h2>
                        <p style="margin:4px 0 0;font-size:11.5px;color:var(--bt-text-3)">Auto-publish hits tweets 1-3. Typefully handles the rest as a schedulable draft.</p>
                    </div>
                    <?php $has_tf_key = get_option( 'bt_typefully_key', '' ); ?>
                    <span style="font-size:10px;font-weight:700;letter-spacing:.8px;padding:3px 10px;border-radius:0;<?php echo $has_tf_key ? 'background:rgba(167,139,250,.15);color:#c4b5fd;border:1px solid rgba(167,139,250,.3)' : 'background:rgba(71,85,105,.2);color:var(--bt-text-3);border:1px solid rgba(71,85,105,.4)'; ?>">
                        <?php echo $has_tf_key ? '● KEY SET' : '○ NOT CONFIGURED'; ?>
                    </span>
                </div>

                <label style="display:flex;align-items:center;gap:10px;padding:10px 0;margin-bottom:6px;border-bottom:1px solid rgba(255,255,255,.05)">
                    <input type="checkbox" id="bt-tf-autoqueue" <?php checked( get_option( 'bt_typefully_autoqueue', '0' ), '1' ); ?> style="width:18px;height:18px;accent-color:#a78bfa">
                    <span style="color:var(--bt-text);font-size:13px">Automatically queue <strong style="color:#a78bfa">tweets 4-12</strong> to Typefully after X auto-publish succeeds</span>
                </label>

                <label style="display:flex;flex-direction:column;gap:6px;margin-top:14px">
                    <span style="font-size:11px;color:var(--bt-text-3);text-transform:uppercase;letter-spacing:.6px">Typefully API Key</span>
                    <input id="bt-tf-key" type="password" value="<?php echo esc_attr( get_option( 'bt_typefully_key', '' ) ); ?>" placeholder="paste your Typefully API key" autocomplete="new-password" style="background:#0b0f1a;border:1px solid var(--bt-text-4);color:var(--bt-text);border-radius:7px;padding:9px 12px;font-size:12px;font-family:'SF Mono',Menlo,monospace">
                </label>
                <p style="color:var(--bt-text-3);font-size:11px;margin:14px 0 0;line-height:1.55">
                    Get your key at <a href="https://typefully.com/settings/integrations" target="_blank" style="color:#a78bfa;text-decoration:none">typefully.com/settings/integrations</a>. The draft goes into your Typefully dashboard where you can review, edit, and schedule it — or leave as draft to review later.
                </p>
            </div>

            <div style="background:#121316;border:1px solid rgba(255,255,255,.07);border-radius:0;padding:24px;margin-bottom:24px">
                <h2 style="margin:0 0 18px;font-size:16px;color:var(--bt-text)">Generate Report</h2>
                <div style="display:grid;grid-template-columns:1fr auto;gap:12px;align-items:end">
                    <div>
                        <label style="display:block;font-size:12px;color:var(--bt-text-3);margin-bottom:6px;text-transform:uppercase;letter-spacing:.6px">Report Type</label>
                        <select id="fxlm-analysis-topic" style="width:100%;background:#0b0f1a;border:1px solid var(--bt-text-4);color:var(--bt-text);border-radius:0;padding:10px 14px;font-size:14px">
                            <?php foreach ( $analysis_topics as $key => $label ) : ?>
                            <option value="<?php echo esc_attr($key); ?>" <?php selected( $analysis_topic_pref, $key ); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button id="fxlm-generate-analysis-btn" class="button button-primary" style="height:42px;padding:0 22px;font-size:14px;font-weight:700;background:linear-gradient(135deg,#00FF66,#00FF66);border:none">
                        🧠 Generate Report
                    </button>
                </div>
                <div id="fxlm-analysis-status" style="margin-top:14px;display:none;padding:12px 16px;border-radius:0;font-size:13px"></div>
                <p style="margin-top:14px;color:var(--bt-text-3);font-size:11.5px;line-height:1.55">
                    ⏱ Typically takes 30–90 seconds (longer with multi-format enabled). Published under <strong>Market Analysis</strong> category unless Review Mode is on.
                </p>
            </div>

            <?php
            // v55: Surface the most recent ai-daily-report's attached thread + newsletter
            $latest_reports = get_posts( array(
                'numberposts' => 1,
                'post_status' => array( 'publish', 'pending' ),
                'meta_query'  => array(
                    array( 'key' => '_bt_twitter_thread', 'compare' => 'EXISTS' ),
                ),
                'orderby' => 'date', 'order' => 'DESC',
            ) );
            if ( ! empty( $latest_reports ) ) :
                $lr      = $latest_reports[0];
                $thread  = get_post_meta( $lr->ID, '_bt_twitter_thread', true );
                $newsltr = get_post_meta( $lr->ID, '_bt_newsletter_body', true );
                $handle  = get_option( 'bt_twitter_handle', '@blocktickerIO' );
                $handle_clean = ltrim( $handle, '@' );
            ?>
            <div style="background:#121316;border:1px solid rgba(0,255,102,.18);border-radius:0;padding:22px 24px;margin-bottom:20px">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:10px">
                    <h2 style="margin:0;font-size:15px;color:var(--bt-text)">📡 Latest Multi-Format Outputs</h2>
                    <a href="<?php echo esc_url( get_permalink( $lr->ID ) ); ?>" target="_blank" style="font-size:12px;color:#00FF66">View published post →</a>
                </div>
                <p style="color:var(--bt-text-3);font-size:11.5px;margin:0 0 16px"><strong style="color:var(--bt-text-2)"><?php echo esc_html( $lr->post_title ); ?></strong> · <?php echo esc_html( human_time_diff( get_the_time( 'U', $lr ) ) ); ?> ago</p>

                <?php if ( is_array( $thread ) && ! empty( $thread ) ) : ?>
                <?php
                    $published_ids   = get_post_meta( $lr->ID, '_bt_twitter_published_ids', true );
                    $published_err   = get_post_meta( $lr->ID, '_bt_twitter_autopublish_error', true );
                    $already_posted  = is_array( $published_ids ) && ! empty( $published_ids );
                    $tf_share_url    = get_post_meta( $lr->ID, '_bt_typefully_share_url', true );
                    $tf_queued       = ! empty( $tf_share_url );
                    $remaining_count = max( 0, count( $thread ) - 3 );
                ?>
                <div style="margin-bottom:22px">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;flex-wrap:wrap;gap:10px">
                        <strong style="color:var(--bt-text);font-size:13px">𝕏 Twitter Thread (<?php echo count( $thread ); ?> tweets)</strong>
                        <div style="display:flex;gap:8px;flex-wrap:wrap">
                            <button class="button button-small bt-copy-thread" data-tweets="<?php echo esc_attr( wp_json_encode( $thread ) ); ?>" type="button">📋 Copy thread</button>
                            <a class="button button-small" target="_blank" rel="noopener" href="https://twitter.com/intent/tweet?text=<?php echo rawurlencode( $thread[0] ); ?>">𝕏 Share first tweet</a>
                            <button id="bt-tw-publish-now" class="button button-small button-primary" data-post-id="<?php echo (int) $lr->ID; ?>" type="button" <?php echo $already_posted ? 'disabled' : ''; ?> style="background:<?php echo $already_posted ? 'var(--bt-text-4)' : 'linear-gradient(135deg,#00FF66,#00FF66)'; ?>;border:none;color:#0A0B0D;font-weight:700">
                                <?php echo $already_posted ? '✓ Published to X' : '▶ Post first 3 to X now'; ?>
                            </button>
                            <?php if ( $remaining_count > 0 ) : ?>
                            <button id="bt-tf-queue-now" class="button button-small" data-post-id="<?php echo (int) $lr->ID; ?>" type="button" <?php echo $tf_queued ? 'disabled' : ''; ?> style="background:<?php echo $tf_queued ? 'var(--bt-text-4)' : 'rgba(167,139,250,.15)'; ?>;border:1px solid <?php echo $tf_queued ? 'var(--bt-text-4)' : 'rgba(167,139,250,.4)'; ?>;color:<?php echo $tf_queued ? 'var(--bt-text-3)' : '#c4b5fd'; ?>;font-weight:700">
                                <?php echo $tf_queued ? '✓ Queued on Typefully' : '📅 Queue 4-' . count( $thread ) . ' on Typefully'; ?>
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ( $already_posted ) : ?>
                    <div style="background:rgba(0,255,102,.06);border:1px solid rgba(0,255,102,.2);border-radius:0;padding:10px 14px;margin-bottom:10px;font-size:12px;color:#00FF66">
                        ✅ First <?php echo count( $published_ids ); ?> tweets posted to X.
                        <a href="https://twitter.com/<?php echo esc_attr( ltrim( $handle, '@' ) ); ?>/status/<?php echo esc_attr( $published_ids[0] ); ?>" target="_blank" style="color:#00FF66;margin-left:6px">View thread on X →</a>
                    </div>
                    <?php elseif ( ! empty( $published_err ) ) : ?>
                    <div style="background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.25);border-radius:0;padding:10px 14px;margin-bottom:10px;font-size:12px;color:#fca5a5">
                        ⚠️ Auto-publish failed: <?php echo esc_html( $published_err ); ?>
                    </div>
                    <?php endif; ?>
                    <?php if ( $tf_queued ) : ?>
                    <div style="background:rgba(167,139,250,.06);border:1px solid rgba(167,139,250,.2);border-radius:0;padding:10px 14px;margin-bottom:10px;font-size:12px;color:#c4b5fd">
                        ✅ Tweets 4-<?php echo count( $thread ); ?> queued on Typefully.
                        <a href="<?php echo esc_url( $tf_share_url ); ?>" target="_blank" style="color:#c4b5fd;margin-left:6px">Open draft on Typefully →</a>
                    </div>
                    <?php endif; ?>
                    <div id="bt-tw-publish-status" style="display:none;margin-bottom:10px;padding:10px 14px;border-radius:0;font-size:12px"></div>
                    <div id="bt-tf-queue-status" style="display:none;margin-bottom:10px;padding:10px 14px;border-radius:0;font-size:12px"></div>
                    <div style="background:#0b0f1a;border:1px solid rgba(255,255,255,.06);border-radius:0;padding:4px 0;max-height:380px;overflow-y:auto">
                        <?php foreach ( $thread as $i => $t ): $len = mb_strlen( $t ); $in_first_3 = $i < 3; ?>
                        <div style="padding:10px 14px;border-bottom:1px solid rgba(255,255,255,.04);display:grid;grid-template-columns:34px 1fr auto;gap:10px;align-items:start;<?php echo $in_first_3 ? 'background:rgba(0,255,102,.03)' : ''; ?>">
                            <div style="background:<?php echo $in_first_3 ? 'rgba(0,255,102,.15);color:#00FF66;border:1px solid rgba(0,255,102,.3)' : 'rgba(0,153,255,.12);color:#00FF66;border:1px solid rgba(0,153,255,.25)'; ?>;border-radius:0;padding:3px 0;text-align:center;font-size:11px;font-weight:700;font-family:monospace">#<?php echo $i + 1; ?></div>
                            <div style="font-size:13px;color:#cbd5e1;line-height:1.55;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif"><?php echo esc_html( $t ); ?></div>
                            <span style="font-size:10.5px;font-family:monospace;color:<?php echo $len > 260 ? '#FFB800' : 'var(--bt-text-3)'; ?>"><?php echo $len; ?>/280</span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <p style="color:var(--bt-text-3);font-size:11px;margin:8px 0 0">Tweets <strong style="color:#00FF66">#1–3</strong> are the auto-publish set. Remaining tweets: paste manually or queue via Typefully/Hypefury.</p>
                </div>
                <?php endif; ?>

                <?php if ( ! empty( $newsltr ) ) : ?>
                <div>
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
                        <strong style="color:var(--bt-text);font-size:13px">📧 Newsletter Body</strong>
                        <button class="button button-small bt-copy-newsletter" type="button">📋 Copy newsletter</button>
                    </div>
                    <textarea id="bt-newsletter-text" readonly style="width:100%;min-height:240px;background:#0b0f1a;border:1px solid rgba(255,255,255,.06);color:#cbd5e1;border-radius:0;padding:14px;font-family:'SF Mono',Menlo,monospace;font-size:12px;line-height:1.55;resize:vertical"><?php echo esc_textarea( $newsltr ); ?></textarea>
                    <p style="color:var(--bt-text-3);font-size:11px;margin:8px 0 0">Paste into Mailchimp / ConvertKit / Substack. The first line starting with SUBJECT_LINE: is your email subject.</p>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div style="background:rgba(167,139,250,.05);border:1px solid rgba(167,139,250,.15);border-radius:0;padding:14px 18px;margin-bottom:24px;font-size:12.5px;color:var(--bt-text-2)">
                <strong style="color:#a78bfa">Roadmap:</strong> Next sessions will add OpenAI/ChatGPT as a second provider (choose per topic) and a public /threads/ archive page for published X threads.
            </div>

            <?php endif; ?>

            <!-- Recent posts (shown on both tabs) -->
            <div style="background:#121316;border:1px solid rgba(255,255,255,.07);border-radius:0;padding:24px">
                <h2 style="margin:0 0 16px;font-size:16px;color:var(--bt-text)">Recent AI Posts</h2>
                <?php if ( empty($recent_posts) ) : ?>
                <p style="color:var(--bt-text-3);font-size:13px">No posts yet. Generate your first one above.</p>
                <?php else : ?>
                <table style="width:100%;border-collapse:collapse;font-size:13px">
                    <thead>
                        <tr style="border-bottom:1px solid rgba(255,255,255,.07)">
                            <th style="text-align:left;padding:8px 12px;color:var(--bt-text-3);font-weight:600">Title</th>
                            <th style="text-align:left;padding:8px 12px;color:var(--bt-text-3);font-weight:600">Status</th>
                            <th style="text-align:left;padding:8px 12px;color:var(--bt-text-3);font-weight:600">Date</th>
                            <th style="padding:8px 12px"></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $recent_posts as $p ) :
                        $status_color = $p->post_status === 'publish' ? '#00FF66' : '#FFB800';
                        $status_label = $p->post_status === 'publish' ? '✅ Published' : '⏳ Pending';
                    ?>
                    <tr style="border-bottom:1px solid rgba(255,255,255,.04)">
                        <td style="padding:10px 12px;color:var(--bt-text)"><?php echo esc_html( wp_trim_words($p->post_title, 10) ); ?></td>
                        <td style="padding:10px 12px;color:<?php echo $status_color; ?>;font-size:12px"><?php echo $status_label; ?></td>
                        <td style="padding:10px 12px;color:var(--bt-text-3)"><?php echo get_the_date('M j, Y', $p->ID); ?></td>
                        <td style="padding:10px 12px;text-align:right">
                            <a href="<?php echo get_edit_post_link($p->ID); ?>" style="color:#00FF66;font-size:12px;text-decoration:none">Edit →</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>

        <script>
        (function($){
            var ajaxUrl = '<?php echo esc_url(admin_url('admin-ajax.php')); ?>';
            var nonce   = '<?php echo wp_create_nonce('fxlm_blog_gen'); ?>';

            // Save review mode on change
            $('#fxlm-review-mode').on('change', function(){
                $.post(ajaxUrl, { action:'fxlm_toggle_autopilot', mode: $(this).val(), nonce: nonce, only_mode: 1 });
            });

            // Toggle autopilot
            $('#fxlm-toggle-autopilot').on('click', function(){
                var btn = $(this);
                var isOn = parseInt(btn.data('on'));
                btn.prop('disabled', true).text('Working...');
                $.post(ajaxUrl, { action:'fxlm_toggle_autopilot', enable: isOn ? 0 : 1, mode: $('#fxlm-review-mode').val(), nonce: nonce }, function(res){
                    if (res.success) {
                        var nowOn = !isOn;
                        $('#fxlm-autopilot-status').css('color', nowOn ? '#00FF66' : '#FF3B30')
                            .text(nowOn ? '🟢 ON — 1 post/day at 8:00 UTC' : '🔴 OFF');
                        btn.data('on', nowOn ? 1 : 0)
                           .removeClass('button-primary button-secondary')
                           .addClass(nowOn ? 'button-secondary' : 'button-primary')
                           .text(nowOn ? '⏸ Pause Autopilot' : '▶ Enable Autopilot')
                           .prop('disabled', false);
                    }
                });
            });

            // Generate single post
            $('#fxlm-generate-btn').on('click', function(){
                var btn = $(this);
                var topic = $('#fxlm-topic-select').val();
                var status = $('#fxlm-gen-status');
                btn.prop('disabled', true).text('⏳ Generating…');
                status.show().css({'background':'rgba(0,153,255,.08)','border':'1px solid rgba(0,153,255,.2)','color':'#93c5fd'}).text('Calling AI… this may take 30–60 seconds.');
                $.post(ajaxUrl, { action:'fxlm_generate_post', topic: topic, nonce: nonce }, function(res){
                    btn.prop('disabled', false).text('✨ Generate Post');
                    if (res.success) {
                        status.css({'background':'rgba(0,255,102,.08)','border':'1px solid rgba(0,255,102,.2)','color':'#00FF66'})
                            .html('✅ ' + res.data + ' — <a href="' + '<?php echo admin_url('edit.php'); ?>' + '" style="color:#00FF66">View all posts →</a>');
                        setTimeout(function(){ location.reload(); }, 3000);
                    } else {
                        status.css({'background':'rgba(255,59,48,.08)','border':'1px solid rgba(255,59,48,.2)','color':'#FF3B30'})
                            .text('❌ ' + (res.responseJSON ? res.responseJSON.data : 'Error generating post. Check your Claude API key in Setup Wizard.'));
                    }
                }).fail(function(){ btn.prop('disabled', false).text('✨ Generate Post'); status.css('color','#FF3B30').text('❌ Request failed.'); });
            });

            // v54: Generate AI Analysis report (on-demand institutional report)
            $('#fxlm-generate-analysis-btn').on('click', function(){
                var btn = $(this);
                var topic = $('#fxlm-analysis-topic').val() || 'ai-daily-report';
                var status = $('#fxlm-analysis-status');
                btn.prop('disabled', true).text('⏳ Generating…');
                status.show().css({'background':'rgba(0,153,255,.08)','border':'1px solid rgba(0,153,255,.2)','color':'#93c5fd'}).text('Building institutional report… 30–60 seconds.');
                $.post(ajaxUrl, { action:'fxlm_generate_post', topic: topic, nonce: nonce }, function(res){
                    btn.prop('disabled', false).text('🧠 Generate Report');
                    if (res.success) {
                        status.css({'background':'rgba(0,255,102,.08)','border':'1px solid rgba(0,255,102,.2)','color':'#00FF66'})
                            .html('✅ ' + res.data + ' — <a href="<?php echo admin_url('edit.php'); ?>" style="color:#00FF66">View →</a>');
                        setTimeout(function(){ location.reload(); }, 3000);
                    } else {
                        status.css({'background':'rgba(255,59,48,.08)','border':'1px solid rgba(255,59,48,.2)','color':'#FF3B30'})
                            .text('❌ ' + (res.responseJSON ? res.responseJSON.data : 'Error generating report. Check your Claude API key.'));
                    }
                }).fail(function(){ btn.prop('disabled', false).text('🧠 Generate Report'); status.css('color','#FF3B30').text('❌ Request failed.'); });
            });

            // v54: Save autopilot topic preference on change
            $('#fxlm-autopilot-topic').on('change', function(){
                $.post(ajaxUrl, { action:'fxlm_save_ai_pref', key: 'fxlm_ai_autopilot_topic', value: $(this).val(), nonce: nonce });
            });
            $('#fxlm-analysis-topic').on('change', function(){
                $.post(ajaxUrl, { action:'fxlm_save_ai_pref', key: 'fxlm_ai_analysis_topic', value: $(this).val(), nonce: nonce });
            });

            // v55: Multi-format settings (Twitter handle + multiformat toggle)
            var handleTimer;
            $('#fxlm-twitter-handle').on('input', function(){
                var val = $(this).val().trim();
                clearTimeout(handleTimer);
                handleTimer = setTimeout(function(){
                    $.post(ajaxUrl, { action:'fxlm_save_ai_pref', key: 'fxlm_twitter_handle', value: val, nonce: nonce });
                }, 500);
            });
            $('#fxlm-multiformat-toggle').on('change', function(){
                $.post(ajaxUrl, { action:'fxlm_save_ai_pref', key: 'fxlm_ai_multiformat', value: this.checked ? '1' : '0', nonce: nonce });
            });

            // v61: Featured image mode selector
            $('#bt-ai-image-mode').on('change', function(){
                $.post(ajaxUrl, { action:'fxlm_save_ai_pref', key: 'bt_ai_image_mode', value: $(this).val(), nonce: nonce });
            });

            // v63: Typefully integration (key field + toggle + manual queue button)
            var tfKeyTimer;
            $('#bt-tf-key').on('input', function(){
                var val = $(this).val().trim();
                clearTimeout(tfKeyTimer);
                tfKeyTimer = setTimeout(function(){
                    $.post(ajaxUrl, { action:'fxlm_save_ai_pref', key: 'bt_typefully_key', value: val, nonce: nonce });
                }, 600);
            });
            $('#bt-tf-autoqueue').on('change', function(){
                $.post(ajaxUrl, { action:'fxlm_save_ai_pref', key: 'bt_typefully_autoqueue', value: this.checked ? '1' : '0', nonce: nonce });
            });
            $(document).on('click', '#bt-tf-queue-now', function(){
                var btn = $(this);
                var pid = btn.data('post-id');
                var status = $('#bt-tf-queue-status');
                if (!pid) return;
                btn.prop('disabled', true).text('⏳ Queueing…');
                status.show().css({'background':'rgba(167,139,250,.08)','border':'1px solid rgba(167,139,250,.2)','color':'#c4b5fd'}).text('Sending draft to Typefully…');
                $.post(ajaxUrl, { action:'bt_typefully_queue', post_id: pid, nonce: nonce }, function(res){
                    if (res.success) {
                        btn.text('✓ Queued on Typefully').css({'background':'var(--bt-text-4)','color':'var(--bt-text-3)','border-color':'var(--bt-text-4)'});
                        status.css({'background':'rgba(167,139,250,.08)','border':'1px solid rgba(167,139,250,.2)','color':'#c4b5fd'})
                              .html('✅ ' + res.data.count + ' tweets queued. <a href="' + (res.data.share_url || '#') + '" target="_blank" style="color:#c4b5fd">Open draft on Typefully →</a>');
                    } else {
                        btn.prop('disabled', false).text('📅 Queue on Typefully');
                        status.css({'background':'rgba(239,68,68,.08)','border':'1px solid rgba(239,68,68,.25)','color':'#fca5a5'})
                              .text('❌ ' + (res.responseJSON ? res.responseJSON.data : res.data || 'Request failed.'));
                    }
                }).fail(function(){
                    btn.prop('disabled', false).text('📅 Queue on Typefully');
                    status.css('color','#fca5a5').text('❌ Network error.');
                });
            });

            // v61: Retroactive image fix for existing AI posts
            $('#bt-refix-images-btn').on('click', function(){
                var btn = $(this);
                var status = $('#bt-refix-status');
                if (!confirm('Re-run the featured-image selector on up to 50 AI posts from the last 60 days? Existing thumbnails will be replaced.')) return;
                btn.prop('disabled', true).text('⏳ Processing…');
                status.show().css({'background':'rgba(167,139,250,.08)','border':'1px solid rgba(167,139,250,.2)','color':'#c4b5fd'}).text('Scanning posts and re-running image selection…');
                $.post(ajaxUrl, { action:'bt_refix_ai_images', nonce: nonce }, function(res){
                    btn.prop('disabled', false).text('🔧 Fix thumbnails now');
                    if (res.success) {
                        var d = res.data;
                        status.css({'background':'rgba(0,255,102,.08)','border':'1px solid rgba(0,255,102,.2)','color':'#00FF66'})
                              .html('✅ Processed <strong>' + d.processed + '</strong> posts · ' + d.news_smart + ' got matched news images · ' + d.placeholder + ' got branded placeholders · ' + d.relinked + ' now have thumbnails.');
                    } else {
                        status.css({'background':'rgba(239,68,68,.08)','border':'1px solid rgba(239,68,68,.25)','color':'#fca5a5'})
                              .text('❌ ' + (res.responseJSON ? res.responseJSON.data : 'Retry failed.'));
                    }
                }).fail(function(){
                    btn.prop('disabled', false).text('🔧 Fix thumbnails now');
                    status.css('color','#fca5a5').text('❌ Network error.');
                });
            });

            // v60: X credentials (debounced auto-save on input) + auto-publish toggle + manual publish
            var twTimers = {};
            function saveTwField(id, key) {
                $('#' + id).on('input', function(){
                    var val = $(this).val().trim();
                    clearTimeout(twTimers[id]);
                    twTimers[id] = setTimeout(function(){
                        $.post(ajaxUrl, { action:'fxlm_save_ai_pref', key: key, value: val, nonce: nonce });
                    }, 600);
                });
            }
            saveTwField('bt-tw-ck', 'bt_twitter_api_key');
            saveTwField('bt-tw-cs', 'bt_twitter_api_secret');
            saveTwField('bt-tw-tk', 'bt_twitter_access_token');
            saveTwField('bt-tw-ts', 'bt_twitter_access_token_secret');
            $('#bt-tw-autopublish').on('change', function(){
                $.post(ajaxUrl, { action:'fxlm_save_ai_pref', key: 'bt_twitter_autopublish', value: this.checked ? '1' : '0', nonce: nonce });
            });
            $(document).on('click', '#bt-tw-publish-now', function(){
                var btn = $(this);
                var pid = btn.data('post-id');
                var status = $('#bt-tw-publish-status');
                if (!pid) return;
                if (!confirm('Publish the first 3 tweets to X right now? This cannot be undone.')) return;
                btn.prop('disabled', true).text('⏳ Publishing…');
                status.show().css({'background':'rgba(0,153,255,.08)','border':'1px solid rgba(0,153,255,.2)','color':'#93c5fd'}).text('Sending 3 tweets to X…');
                $.post(ajaxUrl, { action:'bt_twitter_publish_now', post_id: pid, nonce: nonce }, function(res){
                    if (res.success) {
                        btn.text('✓ Published to X').css('background', 'var(--bt-text-4)');
                        var url = res.data.first_url || '#';
                        status.css({'background':'rgba(0,255,102,.08)','border':'1px solid rgba(0,255,102,.2)','color':'#00FF66'})
                              .html('✅ ' + res.data.count + ' tweets posted. <a href="' + url + '" target="_blank" style="color:#00FF66">View on X →</a>');
                    } else {
                        btn.prop('disabled', false).text('▶ Post first 3 to X now');
                        status.css({'background':'rgba(239,68,68,.08)','border':'1px solid rgba(239,68,68,.25)','color':'#fca5a5'})
                              .text('❌ ' + (res.responseJSON ? res.responseJSON.data : res.data || 'Request failed.'));
                    }
                }).fail(function(){
                    btn.prop('disabled', false).text('▶ Post first 3 to X now');
                    status.css('color','#fca5a5').text('❌ Network error.');
                });
            });

            // v57: AI provider selector + OpenAI credentials
            $('input[name=fxlm_ai_provider_radio]').on('change', function(){
                var v = $(this).val();
                $.post(ajaxUrl, { action:'fxlm_save_ai_pref', key: 'fxlm_ai_provider', value: v, nonce: nonce }, function(){
                    $('#fxlm-provider-status').show().css('color', '#00FF66').text('✓ Provider set to ' + v + '. Reload page to see highlight update.');
                });
            });
            var oaTimer;
            $('#fxlm-openai-key').on('input', function(){
                var val = $(this).val().trim();
                clearTimeout(oaTimer);
                oaTimer = setTimeout(function(){
                    $.post(ajaxUrl, { action:'fxlm_save_ai_pref', key: 'fxlm_openai_key', value: val, nonce: nonce }, function(){
                        $('#fxlm-provider-status').show().css('color', '#00FF66').text('✓ OpenAI key saved.');
                    });
                }, 700);
            });
            $('#fxlm-openai-model').on('change', function(){
                $.post(ajaxUrl, { action:'fxlm_save_ai_pref', key: 'fxlm_openai_model', value: $(this).val(), nonce: nonce });
            });

            // v55: Copy Twitter thread (joins with double-newlines for clean paste)
            $(document).on('click', '.bt-copy-thread', function(){
                var btn = $(this);
                try {
                    var tweets = JSON.parse(btn.attr('data-tweets'));
                    var joined = tweets.map(function(t, i){ return (i+1) + '/ ' + t; }).join('\n\n');
                    navigator.clipboard.writeText(joined).then(function(){
                        var orig = btn.text();
                        btn.text('✓ Copied!');
                        setTimeout(function(){ btn.text(orig); }, 2000);
                    });
                } catch(e) { alert('Copy failed: ' + e.message); }
            });
            $(document).on('click', '.bt-copy-newsletter', function(){
                var btn = $(this);
                var ta = document.getElementById('bt-newsletter-text');
                if (!ta) return;
                navigator.clipboard.writeText(ta.value).then(function(){
                    var orig = btn.text();
                    btn.text('✓ Copied!');
                    setTimeout(function(){ btn.text(orig); }, 2000);
                });
            });

            // Bulk generate
            $('#fxlm-bulk-btn').on('click', function(){
                var btn = $(this);
                var status = $('#fxlm-bulk-status');
                // v54: only bulk-generate autopilot topics (analysis reports are on-demand)
                var topics = <?php echo json_encode( array_keys( $autopilot_topics ) ); ?>;
                var total = topics.length;
                if (!confirm('Generate all ' + total + ' autopilot topic posts now? This may take several minutes.')) return;
                btn.prop('disabled', true).text('⏳ Generating all topics…');
                status.show().css({'background':'rgba(0,153,255,.08)','border':'1px solid rgba(0,153,255,.2)','color':'#93c5fd'}).text('Generating ' + total + ' posts…');
                var done = 0;
                function genNext() {
                    if (done >= total) {
                        btn.prop('disabled', false).text('📦 Generate All Autopilot Topics');
                        status.css({'background':'rgba(0,255,102,.08)','border':'1px solid rgba(0,255,102,.2)','color':'#00FF66'})
                            .html('✅ All ' + total + ' posts generated! <a href="<?php echo admin_url('edit.php'); ?>" style="color:#00FF66">View posts →</a>');
                        return;
                    }
                    status.text('Generating ' + (done+1) + '/' + total + ': ' + topics[done] + '…');
                    $.post(ajaxUrl, { action:'fxlm_generate_post', topic: topics[done], nonce: nonce }, function(){ done++; genNext(); }).fail(function(){ done++; genNext(); });
                }
                genNext();
            });
        })(jQuery);
        </script>
        <?php
    }

    // ── AJAX: Generate a single blog post ────────────────────────────
    public static function ajax_generate_post() {
        BT_Utils::verify_admin_ajax( 'fxlm_blog_gen' );

        $topic_key = sanitize_key( $_POST['topic'] ?? '' );

        // Use FXLM_AIBlog to generate (it handles OpenAI + fallback)
        if ( $topic_key ) {
            // Temporarily override the topic selection by day-index with a forced key
            add_filter( 'fxlm_force_blog_topic', function() use ( $topic_key ) { return $topic_key; } );
        }

        BT_AIBlog::generate_daily_post_forced( $topic_key );

        // Count posts created today
        $today = date('Y-m-d');
        $count = wp_count_posts('post');
        wp_send_json_success( 'Post generated successfully! Check your posts list.' );
    }

    // ── AJAX: Toggle autopilot on/off ────────────────────────────────
    public static function ajax_toggle_autopilot() {
        BT_Utils::verify_admin_ajax( 'fxlm_blog_gen' );

        $mode = sanitize_text_field( $_POST['mode'] ?? '0' );
        update_option( 'bt_ai_review_mode', $mode );

        // If only updating mode, return early
        if ( ! empty( $_POST['only_mode'] ) ) {
            wp_send_json_success( 'Review mode updated.' );
        }

        $enable = intval( $_POST['enable'] ?? 0 );

        if ( $enable ) {
            // Schedule autopilot if not already running
            if ( ! wp_next_scheduled( 'bt_daily_ai_post' ) ) {
                $next_8am = strtotime( 'tomorrow 08:00:00 UTC' );
                wp_schedule_event( $next_8am, 'daily', 'bt_daily_ai_post' );
            }
            wp_send_json_success( 'Autopilot enabled — 1 post/day at 8:00 UTC.' );
        } else {
            wp_clear_scheduled_hook( 'bt_daily_ai_post' );
            wp_send_json_success( 'Autopilot paused.' );
        }
    }

    /**
     * v54: Save AI generator preferences (autopilot topic, analysis topic, twitter handle, multiformat toggle).
     * Whitelist keys to avoid arbitrary option writes.
     */
    public static function ajax_save_ai_pref() {
        BT_Utils::verify_admin_ajax( 'fxlm_blog_gen' );

        $allowed = array(
            'fxlm_ai_autopilot_topic',
            'fxlm_ai_analysis_topic',
            'fxlm_twitter_handle',
            'fxlm_ai_multiformat',
            // v57: provider config
            'fxlm_ai_provider',
            'fxlm_openai_key',
            'fxlm_openai_model',
            'fxlm_claude_model',
            // v60: X auto-publish credentials + toggle
            'bt_twitter_api_key',
            'bt_twitter_api_secret',
            'bt_twitter_access_token',
            'bt_twitter_access_token_secret',
            'bt_twitter_autopublish',
            // v61: featured image mode
            'bt_ai_image_mode',
            // v63: Typefully auto-queue
            'bt_typefully_key',
            'bt_typefully_autoqueue',
        );
        $key     = sanitize_key( $_POST['key'] ?? '' );
        if ( ! in_array( $key, $allowed, true ) ) wp_send_json_error( 'Invalid key' );
        $value = sanitize_text_field( $_POST['value'] ?? '' );
        // Twitter handle normalize: ensure leading @
        if ( $key === 'fxlm_twitter_handle' && ! empty( $value ) && $value[0] !== '@' ) {
            $value = '@' . ltrim( $value, '@' );
        }
        update_option( $key, $value, false );
        wp_send_json_success( 'Preference saved' );
    }

}
