<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class BT_Monetize {

    public static function init() {
        // Auto-insert ads into post content
        add_filter( 'the_content', array( __CLASS__, 'auto_insert_ads' ), 20 );
        // AdSense head script
        add_action( 'wp_head', array( __CLASS__, 'adsense_head' ) );
        // Register shortcodes
        add_shortcode( 'bt_ad',        array( __CLASS__, 'sc_ad_zone' ) );
        add_shortcode( 'bt_affiliate', array( __CLASS__, 'sc_affiliate_card' ) );
        add_shortcode( 'bt_sponsored', array( __CLASS__, 'sc_sponsored_banner' ) );
    }

    public static function setup() {
        $defaults = array(
            'fxlm_ad_slots' => array(
                'header'     => '',
                'sidebar'    => '',
                'in_article' => '',
                'after_post' => '',
                'footer'     => '',
            ),
            'fxlm_affiliate_links' => array(),
            'fxlm_ad_frequency'    => 3, // Insert ad after every N paragraphs
        );
        foreach ( $defaults as $key => $val ) {
            if ( ! get_option( $key ) ) update_option( $key, $val );
        }
        return array( 'success' => true, 'message' => 'Monetization: AdSense auto-insertion (every 3 paragraphs), affiliate cards, sponsored banners, 5 ad zones configured.' );
    }

    public static function adsense_head() {
        $pub_id = get_option( 'bt_adsense_id', '' );
        if ( empty( $pub_id ) ) return;
        echo '<script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=' . esc_attr( $pub_id ) . '" crossorigin="anonymous"></script>' . "\n";
    }

    public static function auto_insert_ads( $content ) {
        if ( ! is_single() && ! is_page() ) return $content;
        if ( is_admin() ) return $content;

        $pub_id = get_option( 'bt_adsense_id', '' );
        // Only insert ads if AdSense ID is configured
        if ( empty( $pub_id ) ) return $content;

        $freq = intval( get_option( 'bt_ad_frequency', 3 ) );
        if ( $freq < 1 ) $freq = 3;

        $ad_code = '<div class="bt-ad-zone bt-ad-inline" style="margin:24px 0;text-align:center;min-height:90px;background:rgba(255,255,255,.02);border:1px dashed rgba(255,255,255,.06);border-radius:0;padding:12px;display:flex;align-items:center;justify-content:center">
<ins class="adsbygoogle" style="display:block;text-align:center" data-ad-layout="in-article" data-ad-format="fluid" data-ad-client="' . esc_attr( $pub_id ) . '" data-ad-slot="auto"></ins>
<script>(adsbygoogle=window.adsbygoogle||[]).push({});</script>
</div>';

        $paras = explode( '</p>', $content );
        $output = '';
        $count  = 0;

        foreach ( $paras as $i => $para ) {
            $output .= $para;
            if ( ! empty( trim( strip_tags( $para ) ) ) ) {
                $count++;
                if ( $count % $freq === 0 && $i < count( $paras ) - 1 ) {
                    $output .= '</p>' . $ad_code;
                    continue;
                }
            }
            if ( $i < count( $paras ) - 1 ) $output .= '</p>';
        }

        // Ad after post
        $output .= $ad_code;

        return $output;
    }

    // [bt_ad zone="header"]
    public static function sc_ad_zone( $atts ) {
        $a = shortcode_atts( array( 'zone' => 'header', 'format' => 'auto' ), $atts );
        $pub_id = get_option( 'bt_adsense_id', '' );

        if ( empty( $pub_id ) ) {
            return ''; // Show nothing when no AdSense ID configured
        }

        return '<div class="bt-ad-zone bt-ad-' . esc_attr( $a['zone'] ) . '" style="margin:20px 0;text-align:center">
<ins class="adsbygoogle" style="display:block" data-ad-client="' . esc_attr( $pub_id ) . '" data-ad-slot="auto" data-ad-format="' . esc_attr( $a['format'] ) . '" data-full-width-responsive="true"></ins>
<script>(adsbygoogle=window.adsbygoogle||[]).push({});</script>
</div>';
    }

    // [bt_affiliate name="eToro" url="https://..." rating="5" min_deposit="$50" regulation="FCA" features="Copy Trading, 0% Commission"]
    public static function sc_affiliate_card( $atts ) {
        $a = shortcode_atts( array(
            'name'        => 'Broker',
            'url'         => '#',
            'rating'      => '5',
            'min_deposit' => '$100',
            'regulation'  => 'FCA',
            'features'    => '',
            'label'       => 'Open Account',
            'badge'       => '',
        ), $atts );

        $stars = str_repeat( '★', intval( $a['rating'] ) ) . str_repeat( '☆', 5 - intval( $a['rating'] ) );
        $features = array_filter( array_map( 'trim', explode( ',', $a['features'] ) ) );

        ob_start();
        
?>
        <div class="bt-affiliate-card" style="background:linear-gradient(135deg,rgba(17,24,39,.95),rgba(10,14,26,.95));border:1px solid rgba(255,255,255,.06);border-radius:0;padding:22px;margin:16px 0;transition:all .3s">
            <?php if ( $a['badge'] ) : ?>
                <span style="display:inline-block;font-size:10px;font-weight:700;padding:3px 12px;border-radius:0;background:rgba(245,158,11,.12);color:var(--bt-accent-warm);border:1px solid rgba(245,158,11,.2);margin-bottom:10px;text-transform:uppercase;letter-spacing:.5px"><?php echo esc_html( $a['badge'] ); ?></span>
            <?php endif; ?>
            <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px">
                <div>
                    <h3 style="margin:0 0 4px;font-size:18px;color:#fff"><?php echo esc_html( $a['name'] ); ?></h3>
                    <div style="color:var(--bt-accent-warm);font-size:14px;letter-spacing:1px"><?php echo $stars; ?></div>
                </div>
                <a href="<?php echo esc_url( $a['url'] ); ?>" target="_blank" rel="sponsored noopener" style="display:inline-block;padding:11px 24px;border-radius:0;background:linear-gradient(135deg,var(--bt-accent),var(--bt-accent));color:#0A0B0D;font-weight:700;font-size:14px;text-decoration:none;white-space:nowrap;box-shadow:0 4px 16px rgba(0,255,102,.2)"><?php echo esc_html( $a['label'] ); ?> →</a>
            </div>
            <div style="display:flex;gap:16px;margin-top:14px;flex-wrap:wrap">
                <span style="font-size:12px;color:var(--bt-text-2)">💰 Min: <strong style="color:var(--bt-text)"><?php echo esc_html( $a['min_deposit'] ); ?></strong></span>
                <span style="font-size:12px;color:var(--bt-text-2)">🛡️ <strong style="color:var(--bt-text)"><?php echo esc_html( $a['regulation'] ); ?></strong> Regulated</span>
            </div>
            <?php if ( $features ) : ?>
            <div style="display:flex;gap:6px;margin-top:10px;flex-wrap:wrap">
                <?php foreach ( $features as $f ) : ?>
                    <span style="font-size:10px;padding:3px 10px;border-radius:0;background:rgba(0,255,102,.06);color:var(--bt-accent);border:1px solid rgba(0,255,102,.1)">✅ <?php echo esc_html( $f ); ?></span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <small style="display:block;margin-top:12px;font-size:10px;color:var(--bt-text-4);line-height:1.5">CFDs are complex instruments. 74-89% of retail investor accounts lose money when trading CFDs. Affiliate link — we may earn a commission at no cost to you.</small>
        </div>
        <?php
        return ob_get_clean();
    }

    // [bt_sponsored text="Trade Bitcoin with 0% commission" url="https://..." label="Start Now"]
    public static function sc_sponsored_banner( $atts ) {
        $a = shortcode_atts( array(
            'text'  => 'Sponsored content',
            'url'   => '#',
            'label' => 'Learn More',
        ), $atts );

        return '<div style="background:linear-gradient(135deg,rgba(0,255,102,.06),rgba(0,255,102,.04));border:1px solid rgba(0,255,102,.1);border-radius:0;padding:16px 22px;margin:20px 0;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
            <div><span style="font-size:9px;color:var(--bt-text-3);text-transform:uppercase;letter-spacing:1px;display:block;margin-bottom:2px">Sponsored</span><span style="font-size:14px;color:var(--bt-text);font-weight:600">' . esc_html( $a['text'] ) . '</span></div>
            <a href="' . esc_url( $a['url'] ) . '" target="_blank" rel="sponsored noopener" style="padding:9px 20px;border-radius:0;background:linear-gradient(135deg,var(--bt-accent),var(--bt-accent));color:#0A0B0D;font-weight:700;font-size:13px;text-decoration:none;white-space:nowrap">' . esc_html( $a['label'] ) . ' →</a>
        </div>';
    }
}
