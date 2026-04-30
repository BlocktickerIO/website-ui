<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class BT_GDPR {

    public static function init() {
        add_action( 'wp_footer', array( __CLASS__, 'render_cookie_banner' ), 99 );
        add_action( 'wp_head', array( __CLASS__, 'security_headers' ), 1 );
        add_action( 'wp_ajax_fxlm_cookie_consent',        array( __CLASS__, 'ajax_consent' ) );
        add_action( 'wp_ajax_nopriv_fxlm_cookie_consent', array( __CLASS__, 'ajax_consent' ) );
        add_shortcode( 'fxlm_cookie_settings', array( __CLASS__, 'sc_cookie_settings' ) );
    }

    public static function setup() {
        update_option( 'bt_gdpr_enabled', true );
        return array( 'success' => true, 'message' => 'GDPR: Cookie banner, security headers, consent logging enabled.' );
    }

    public static function security_headers() {
        if ( is_admin() || headers_sent() ) return;

        // ── HSTS: Strict-Transport-Security (Report rec #1) ──────────────
        // Forces HTTPS usage, prevents downgrade attacks
        header( 'Strict-Transport-Security: max-age=31536000; includeSubDomains' );

        // ── CSP: Content-Security-Policy (Report rec #2) ──────────────────
        // Whitelists all trusted sources used by the plugin
        $csp = implode( ' ', [
            "default-src 'self';",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval'"
                . " https://s3.tradingview.com https://s.tradingview.com"
                . " https://www.googletagmanager.com https://www.google-analytics.com"
                . " https://pagead2.googlesyndication.com https://cdn.jsdelivr.net"
                . " https://static.cloudflareinsights.com;",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://s3.tradingview.com;",
            "font-src 'self' data: https://fonts.gstatic.com;",
            "img-src 'self' data: blob: https:;",
            "frame-src 'self' https://s.tradingview.com https://s3.tradingview.com"
                . " https://www.tradingview.com https://widget.coindesk.com;",
            "connect-src 'self' https://api.coingecko.com https://api.frankfurter.app"
                . " https://open.er-api.com https://www.google-analytics.com"
                . " https://stats.g.doubleclick.net https://cdn.jsdelivr.net;",
            "object-src 'none';",
            "base-uri 'self';",
            "form-action 'self';",
        ]);
        header( 'Content-Security-Policy: ' . $csp );

        // ── Additional hardening headers ───────────────────────────────────
        header( 'X-Content-Type-Options: nosniff' );
        header( 'X-Frame-Options: SAMEORIGIN' );
        header( 'Referrer-Policy: strict-origin-when-cross-origin' );
        header( 'Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()' );
        header( 'X-Permitted-Cross-Domain-Policies: none' );
    }

    public static function render_cookie_banner() {
        if ( is_admin() ) return;
        
?>
        <div class="bt-cookie-banner" id="bt-cookie-banner" style="display:none">
            <div class="bt-cookie-inner">
                <div class="bt-cookie-text">
                    <strong data-i18n="gdpr.banner_title"><?php _ebt("gdpr.banner_title"); ?></strong>
                    <p><span data-i18n="gdpr.banner_body"><?php _ebt("gdpr.banner_body"); ?></span> <a href="<?php echo home_url('/privacy-policy/'); ?>" data-i18n="gdpr.read_policy"><?php _ebt("gdpr.read_policy"); ?></a></p>
                </div>
                <div class="bt-cookie-actions">
                    <button class="bt-cookie-btn bt-cookie-accept" onclick="btCookieConsent('all')" data-i18n="gdpr.accept_all"><?php _ebt('gdpr.accept_all'); ?></button>
                    <button class="bt-cookie-btn bt-cookie-essential" onclick="btCookieConsent('essential')" data-i18n="gdpr.essential_only"><?php _ebt('gdpr.essential_only'); ?></button>
                </div>
            </div>
        </div>
        <style>
        .bt-cookie-banner{position:fixed;bottom:0;left:0;right:0;z-index:9999999;background:rgba(7,11,20,.97);backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);border-top:1px solid rgba(0,255,102,.15);padding:20px 24px;animation:btCookieSlide .5s ease}
        @keyframes btCookieSlide{from{transform:translateY(100%)}to{transform:translateY(0)}}
        .bt-cookie-inner{max-width:1200px;margin:0 auto;display:flex;align-items:center;gap:20px;flex-wrap:wrap}
        .bt-cookie-text{flex:1;min-width:280px}
        .bt-cookie-text strong{color:#fff;font-size:15px;display:block;margin-bottom:4px}
        .bt-cookie-text p{color:var(--bt-text-2);font-size:12px;margin:0;line-height:1.6}
        .bt-cookie-text a{color:var(--bt-accent);text-decoration:underline}
        .bt-cookie-actions{display:flex;gap:10px;flex-shrink:0}
        .bt-cookie-btn{padding:10px 22px;border-radius:0;font-size:13px;font-weight:700;cursor:pointer;border:none;font-family:inherit;transition:all .2s}
        .bt-cookie-accept{background:linear-gradient(135deg,var(--bt-accent),var(--bt-accent));color:#0A0B0D}
        .bt-cookie-accept:hover{box-shadow:0 4px 20px rgba(0,255,102,.3)}
        .bt-cookie-essential{background:rgba(255,255,255,.06);color:var(--bt-text-2);border:1px solid rgba(255,255,255,.1)}
        .bt-cookie-essential:hover{background:rgba(255,255,255,.1);color:#fff}
        @media(max-width:640px){.bt-cookie-inner{flex-direction:column;text-align:center}.bt-cookie-actions{width:100%;justify-content:center}}
        </style>
        <script>
        (function(){
            if(localStorage.getItem('bt_cookie_consent'))return;
            var b=document.getElementById('bt-cookie-banner');if(b)b.style.display='block';
        })();
        function btCookieConsent(level){
            localStorage.setItem('bt_cookie_consent',level);
            localStorage.setItem('bt_cookie_time',Date.now());
            document.getElementById('bt-cookie-banner').style.display='none';
            if(typeof jQuery!=='undefined'){
                jQuery.post(fxlm_data.ajax_url||'<?php echo admin_url("admin-ajax.php"); ?>',{
                    action:'fxlm_cookie_consent',level:level,nonce:fxlm_data.nonce||''
                });
            }
        }
        </script>
        <?php
    }

    public static function ajax_consent() {
        $level = sanitize_text_field( $_POST['level'] ?? 'essential' );
        $log   = get_option( 'bt_consent_log', array() );
        $log[] = array(
            'level' => $level,
            'time'  => current_time( 'mysql' ),
            'ip'    => sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' ),
        );
        $log = array_slice( $log, -500 );
        update_option( 'bt_consent_log', $log );
        wp_send_json_success();
    }

    public static function sc_cookie_settings() {
        ob_start();
        ?>
        <div class="fxlm-converter" style="text-align:center">
            <h3 style="color:#fff;margin:0 0 12px" data-i18n="gdpr.cookie_prefs"><?php _ebt("gdpr.cookie_prefs"); ?></h3>
            <p style="color:var(--bt-text-2);font-size:13px;margin:0 0 16px" data-i18n="gdpr.cookie_prefs_body"><?php _ebt("gdpr.cookie_prefs_body"); ?></p>
            <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap">
                <button onclick="btCookieConsent('all');location.reload()" class="fxlm-btn-affiliate" data-i18n="gdpr.accept_all_cookies"><?php _ebt('gdpr.accept_all_cookies'); ?></button>
                <button onclick="btCookieConsent('essential');location.reload()" class="fxlm-cta-btn fxlm-cta-btn-outline" style="padding:10px 22px;font-size:13px;border-radius:0;cursor:pointer" data-i18n="gdpr.essential_only"><?php _ebt('gdpr.essential_only'); ?></button>
                <button onclick="localStorage.removeItem('bt_cookie_consent');location.reload()" style="padding:10px 22px;font-size:13px;border-radius:0;cursor:pointer;background:rgba(255,59,48,.1);color:var(--bt-danger);border:1px solid rgba(255,59,48,.2)" data-i18n="gdpr.reset_prefs"><?php _ebt('gdpr.reset_prefs'); ?></button>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
