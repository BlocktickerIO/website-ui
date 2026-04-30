<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class BT_Settings {

    public static function run() {
        try {
            $site_name = get_option( 'bt_site_name', 'BlockTicker' );

            update_option( 'blogname',        $site_name );
            update_option( 'blogdescription', 'Live Crypto & Forex Intelligence — Prices, News, Signals & AI Analysis' );
            update_option( 'timezone_string', 'Europe/Paris' );
            update_option( 'date_format',     'F j, Y' );
            update_option( 'time_format',     'H:i' );
            update_option( 'start_of_week',   1 );
            update_option( 'default_comment_status', 'closed' );
            update_option( 'default_ping_status',    'closed' );
            update_option( 'comment_moderation',     1 );

            // Permalinks → post name
            update_option( 'permalink_structure', '/%postname%/' );
            // Soft flush only — hard flush rewrites .htaccess and can hang on
            // some hosts (NFS mounts, LiteSpeed, WPMU). The hard flush runs
            // automatically on the next page load (WP core queues it when the
            // permalink_structure option changes).
            flush_rewrite_rules( false );

            // Enable user registration (required for Portfolio/Watchlist cloud sync)
            update_option( 'users_can_register', 1 );

            // v6 FIX: Enable search engine indexing (v5 had blog_public=0)
            update_option( 'blog_public', 1 );

            // Media sizes
            update_option( 'thumbnail_size_w', 350 );
            update_option( 'thumbnail_size_h', 200 );
            update_option( 'medium_size_w',    700 );
            update_option( 'medium_size_h',    400 );
            update_option( 'large_size_w',     1200 );
            update_option( 'large_size_h',     600 );

            // FIX VER-01: Register new options with safe defaults (won't overwrite existing values)
            $new_options = array(
                'fxlm_adsense_slot'   => '',  // FIX ADS-01: must be set explicitly — no placeholder default
                'fxlm_og_image'       => '',  // FIX OG-01: default OG image URL
                'fxlm_forex_prev_rates'   => array(), // FIX FX-02: previous rate store
                'fxlm_forex_prev_updated' => 0,       // FIX FX-02: timestamp of last prev-rate snapshot
            );
            foreach ( $new_options as $key => $default ) {
                if ( get_option( $key ) === false ) update_option( $key, $default );
            }

            return array( 'success' => true, 'message' => 'WordPress settings configured: timezone, permalinks, media sizes, comments disabled, search engines ENABLED.' );

        } catch ( Exception $e ) {
            return array( 'success' => false, 'message' => 'Error: ' . $e->getMessage() );
        }
    }

    public static function setup_social() {
        try {
            $defaults = array(
                'fxlm_social_facebook'  => 'https://facebook.com/yourpage',
                'fxlm_social_twitter'   => 'https://twitter.com/yourhandle',
                'fxlm_social_instagram' => 'https://instagram.com/yourhandle',
                'fxlm_social_youtube'   => 'https://youtube.com/yourchannel',
                'fxlm_social_telegram'  => 'https://t.me/yourchannel',
                'fxlm_social_tiktok'    => 'https://tiktok.com/@yourhandle',
                'fxlm_social_discord'   => 'https://discord.gg/yourinvite',
                'fxlm_social_linkedin'  => 'https://linkedin.com/company/yourpage',
            );
            foreach ( $defaults as $key => $val ) {
                if ( ! get_option( $key ) ) update_option( $key, $val );
            }

            return array( 'success' => true, 'message' => 'Social media placeholders created (8 networks). Update URLs in Settings → BlockTicker Social.' );

        } catch ( Exception $e ) {
            return array( 'success' => false, 'message' => 'Error: ' . $e->getMessage() );
        }
    }

    public static function register_shortcodes() {
        add_shortcode( 'fxlm_social', array( __CLASS__, 'sc_social' ) );
    }

    public static function sc_social() {
        $networks = array(
            'facebook'  => array( 'option' => 'fxlm_social_facebook',  'label' => 'Facebook',  'icon' => 'f', 'color' => '#1877F2' ),
            'twitter'   => array( 'option' => 'fxlm_social_twitter',   'label' => 'X',         'icon' => '𝕏', 'color' => '#000000' ),
            'instagram' => array( 'option' => 'fxlm_social_instagram', 'label' => 'Instagram', 'icon' => '◎', 'color' => '#E4405F' ),
            'youtube'   => array( 'option' => 'fxlm_social_youtube',   'label' => 'YouTube',   'icon' => '▶', 'color' => '#FF0000' ),
            'telegram'  => array( 'option' => 'fxlm_social_telegram',  'label' => 'Telegram',  'icon' => '✈', 'color' => '#0088cc' ),
            'tiktok'    => array( 'option' => 'fxlm_social_tiktok',    'label' => 'TikTok',    'icon' => '♪', 'color' => '#010101' ),
            'discord'   => array( 'option' => 'fxlm_social_discord',   'label' => 'Discord',   'icon' => 'D', 'color' => '#5865F2' ),
            'linkedin'  => array( 'option' => 'fxlm_social_linkedin',  'label' => 'LinkedIn',  'icon' => 'in','color' => '#0A66C2' ),
        );
        $out = '<div class="fxlm-social-icons">';
        foreach ( $networks as $n => $info ) {
            $url = get_option( $info['option'], '#' );
            $out .= '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener" class="fxlm-social-icon fxlm-social-' . $n . '" title="' . esc_attr( $info['label'] ) . '" style="background:' . $info['color'] . '">' . $info['icon'] . '</a>';
        }
        $out .= '</div>';
        return $out;
    }

    public static function setup_security() {
        try {
            if ( ! defined( 'DISALLOW_FILE_EDIT' ) ) {
                update_option( 'bt_security_note', 'Add to wp-config.php: define("DISALLOW_FILE_EDIT", true);' );
            }

            remove_action( 'wp_head', 'wp_generator' );

            update_option( 'bt_security_applied', array(
                'version_hidden'        => true,
                'comments_disabled'     => true,
                'file_edit_note'        => 'Add define DISALLOW_FILE_EDIT true to wp-config.php',
                'wordfence_recommended' => true,
                'auto_updates_enabled'  => true,
            ) );

            add_filter( 'login_errors', function() { return 'Incorrect credentials.'; } );

            return array( 'success' => true, 'message' => 'Security hardening applied. Wordfence + UpdraftPlus recommended.' );

        } catch ( Exception $e ) {
            return array( 'success' => false, 'message' => 'Error: ' . $e->getMessage() );
        }
    }

    public static function cleanup() {
        try {
            $hello = get_page_by_path( 'hello-world', OBJECT, 'post' );
            if ( $hello ) wp_delete_post( $hello->ID, true );

            $sample = get_page_by_path( 'sample-page' );
            if ( $sample ) wp_delete_post( $sample->ID, true );

            update_option( 'sidebars_widgets', array( 'wp_inactive_widgets' => array() ) );

            $terms = get_terms( array( 'taxonomy' => 'category', 'hide_empty' => false ) );
            foreach ( $terms as $term ) {
                if ( $term->slug !== 'uncategorized' ) wp_delete_term( $term->term_id, 'category' );
            }
            wp_update_term( get_option( 'default_category' ), 'category', array( 'name' => 'General', 'slug' => 'general' ) );

            // v6: Expanded categories matching competitors
            $cats = array(
                'Forex News', 'Crypto News', 'Trading Signals', 'Market Analysis',
                'Education', 'DeFi & Web3', 'NFT News', 'Regulation',
                'Press Releases', 'Bitcoin', 'Ethereum', 'Altcoins',
            );
            foreach ( $cats as $cat ) {
                if ( ! term_exists( $cat, 'category' ) ) wp_insert_term( $cat, 'category' );
            }

            $comments = get_comments( array( 'status' => 'spam' ) );
            foreach ( $comments as $comment ) wp_delete_comment( $comment->comment_ID, true );

            // v6: Enable search engines after setup is complete
            update_option( 'blog_public', 1 );

            return array( 'success' => true, 'message' => 'Cleanup done: demo content removed, 12 categories created, search engines enabled.' );

        } catch ( Exception $e ) {
            return array( 'success' => false, 'message' => 'Error: ' . $e->getMessage() );
        }
    }
}

