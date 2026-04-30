<?php
/**
 * BT_EEAT — E-E-A-T Trust & Authority System
 * Author bylines, risk warnings, affiliate CTAs, scroll newsletter,
 * BT Score, guest writer submissions.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class BT_EEAT {

    public static function init() {
        // Register CPT directly (safe to call outside init too)
        self::register_submission_cpt();

        add_filter( 'the_content', array( __CLASS__, 'inject_share_bar' ),     15 ); // v90: social share before byline
        add_filter( 'the_content', array( __CLASS__, 'inject_author_byline' ), 20 );
        add_filter( 'the_content', array( __CLASS__, 'inject_toc' ),           22 ); // v90: TOC after byline
        add_filter( 'the_content', array( __CLASS__, 'inject_risk_warning' ),  25 );
        add_filter( 'the_content', array( __CLASS__, 'inject_affiliate_cta' ), 30 );
        add_filter( 'the_content', array( __CLASS__, 'inject_related_verdict' ), 35 ); // v90: live verdict card
        add_filter( 'the_content', array( __CLASS__, 'inject_post_nav' ),      40 ); // v90: next/prev article
        add_action( 'wp_footer',   array( __CLASS__, 'render_reading_progress' ), 5 );  // v90: progress bar
        add_action( 'wp_footer',   array( __CLASS__, 'render_scroll_newsletter' ), 20 );
        add_shortcode( 'bt_score',        array( __CLASS__, 'sc_bt_score' ) );
        add_shortcode( 'bt_write_for_us', array( __CLASS__, 'sc_write_for_us' ) );
        add_action( 'wp_ajax_bt_submit_article',        array( __CLASS__, 'ajax_submit_article' ) );
        add_action( 'wp_ajax_nopriv_bt_submit_article', array( __CLASS__, 'ajax_submit_article' ) );
    }

    // ── CPT ─────────────────────────────────────────────────────────────────
    public static function register_submission_cpt() {
        register_post_type( 'bt_submission', array(
            'labels'       => array( 'name' => 'Writer Submissions', 'singular_name' => 'Submission', 'menu_name' => 'Submissions' ),
            'public'       => false,
            'show_ui'      => true,
            'show_in_menu' => false,
            'supports'     => array( 'title', 'editor', 'custom-fields' ),
        ) );
    }

    // ── AUTHOR PROFILES ──────────────────────────────────────────────────────
    public static function get_authors() {
        $saved = get_option( 'bt_author_profiles', '' );
        if ( $saved ) {
            $decoded = json_decode( $saved, true );
            if ( is_array( $decoded ) && ! empty( $decoded ) ) return $decoded;
        }
        $site = get_option( 'bt_site_name', 'BlockTicker' );
        return array(
            array( 'name' => $site . ' Research Desk', 'title' => 'AI-Assisted · Human-Reviewed', 'credentials' => 'Independent · Built on publicly available data', 'twitter' => '', 'avatar' => '', 'categories' => array( 'crypto-news', 'market-analysis', 'bitcoin', 'altcoins', 'forex-news', 'trading-signals' ) ),
        );
    }

    private static function get_author_for_post( $post_id ) {
        $authors = self::get_authors();
        $slugs   = array_map( function( $c ) { return $c->slug; }, get_the_category( $post_id ) );
        foreach ( $authors as $author ) {
            if ( empty( $author['categories'] ) ) continue;
            foreach ( $author['categories'] as $cat ) {
                if ( in_array( $cat, $slugs, true ) ) return $author;
            }
        }
        foreach ( array_reverse( $authors ) as $author ) {
            if ( empty( $author['categories'] ) ) return $author;
        }
        return $authors[0];
    }

    // ── BYLINE ───────────────────────────────────────────────────────────────
    public static function inject_author_byline( $content ) {
        if ( ! is_single() || ! in_the_loop() || ! is_main_query() ) return $content;
        $author   = self::get_author_for_post( get_the_ID() );
        $pub_date = get_the_date( 'F j, Y' );
        $mod_date = get_the_modified_date( 'F j, Y' );
        $read_min = max( 1, round( str_word_count( strip_tags( $content ) ) / 200 ) );
        $name     = esc_html( $author['name'] );
        $title    = esc_html( $author['title'] );
        $creds    = esc_html( $author['credentials'] );

        // Avatar
        if ( ! empty( $author['avatar'] ) ) {
            $av = '<img src="' . esc_url( $author['avatar'] ) . '" alt="' . $name . '" width="40" height="40" style="border-radius:50%;object-fit:cover;flex-shrink:0">';
        } else {
            $parts    = explode( ' ', $author['name'] );
            $initials = strtoupper( ( $parts[0][0] ?? '' ) . ( $parts[1][0] ?? '' ) );
            $av = '<div style="width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,var(--bt-accent),var(--bt-accent));display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;color:#0A0B0D;flex-shrink:0">' . esc_html( $initials ) . '</div>';
        }

        $twitter_link = '';
        if ( ! empty( $author['twitter'] ) ) {
            $twitter_link = ' &middot; <a href="https://twitter.com/' . esc_attr( $author['twitter'] ) . '" target="_blank" rel="noopener nofollow" style="color:var(--bt-accent);text-decoration:none">@' . esc_html( $author['twitter'] ) . '</a>';
        }

        $updated = ( $mod_date !== $pub_date ) ? ' &middot; Updated ' . esc_html( $mod_date ) : '';

        $byline  = '<div style="display:flex;align-items:center;gap:12px;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);border-radius:0;padding:14px 18px;margin:0 0 24px">';
        $byline .= $av;
        $byline .= '<div style="flex:1;min-width:0">';
        $byline .= '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap"><span style="font-size:14px;font-weight:700;color:var(--bt-text)">' . $name . '</span><span style="font-size:11px;background:rgba(0,255,102,.12);color:var(--bt-accent);border:1px solid rgba(0,255,102,.2);padding:2px 8px;border-radius:0;font-weight:700">' . $title . '</span></div>';
        $byline .= '<div style="font-size:12px;color:var(--bt-text-3);margin-top:3px">' . $creds . $twitter_link . '</div>';
        $byline .= '<div style="font-size:11px;color:var(--bt-text-4);margin-top:4px">Published ' . esc_html( $pub_date ) . $updated . ' &middot; ' . $read_min . ' min read</div>';
        $byline .= '</div>';
        $byline .= '<div style="font-size:11px;background:rgba(0,255,102,.08);border:1px solid rgba(0,255,102,.15);color:var(--bt-accent);padding:4px 10px;border-radius:0;flex-shrink:0;text-align:center;line-height:1.4">&#x1F916; AI-Assisted<br><span style="color:var(--bt-text-3)">Human Reviewed</span></div>';
        $byline .= '</div>';

        return $byline . $content;
    }

    // ── RISK WARNING ─────────────────────────────────────────────────────────
    public static function inject_risk_warning( $content ) {
        if ( ! is_single() || ! in_the_loop() || ! is_main_query() ) return $content;
        $slugs    = array_map( function( $c ) { return $c->slug; }, get_the_category() );
        $warn_cats = array( 'market-analysis', 'trading-signals', 'forex-news', 'crypto-news', 'bitcoin', 'altcoins' );
        if ( empty( array_intersect( $slugs, $warn_cats ) ) ) return $content;

        $warn  = '<div style="background:rgba(245,158,11,.06);border:1px solid rgba(245,158,11,.2);border-radius:0;padding:18px 20px;margin:32px 0 24px">';
        $warn .= '<div style="display:flex;align-items:flex-start;gap:12px">';
        $warn .= '<span style="font-size:22px;flex-shrink:0">&#x26A0;&#xFE0F;</span>';
        $warn .= '<div><strong style="color:var(--bt-accent-warm);font-size:13px;text-transform:uppercase;letter-spacing:.5px;display:block;margin-bottom:6px">Risk Disclosure</strong>';
        $warn .= '<p style="margin:0;font-size:13px;color:var(--bt-text-2);line-height:1.7">This content is for <strong>informational purposes only</strong> and does not constitute financial advice. Cryptocurrency and forex markets are highly volatile. Always conduct your own research and consult a qualified financial advisor before making investment decisions.</p>';
        $warn .= '<p style="margin:8px 0 0;font-size:12px;color:var(--bt-text-3)">Data sourced from <a href="https://coingecko.com" target="_blank" rel="noopener" style="color:var(--bt-accent)">CoinGecko</a>, <a href="https://frankfurter.app" target="_blank" rel="noopener" style="color:var(--bt-accent)">Frankfurter</a>, and <a href="https://tradingview.com" target="_blank" rel="noopener" style="color:var(--bt-accent)">TradingView</a>. AI-assisted, human-reviewed.</p>';
        $warn .= '</div></div></div>';
        return $content . $warn;
    }

    // ── AFFILIATE CTA ────────────────────────────────────────────────────────
    public static function inject_affiliate_cta( $content ) {
        if ( ! is_single() || ! in_the_loop() || ! is_main_query() ) return $content;
        $slugs    = array_map( function( $c ) { return $c->slug; }, get_the_category() );
        $cta_cats = array( 'market-analysis', 'forex-news', 'trading-signals' );
        if ( empty( array_intersect( $slugs, $cta_cats ) ) ) return $content;

        $is_forex = in_array( 'forex-news', $slugs, true );
        $label    = $is_forex ? 'Trade Forex with a Regulated Broker' : 'Start Trading Crypto Today';
        $desc     = $is_forex ? 'Compare regulated forex brokers with tight spreads and free demo accounts.' : 'Compare trusted crypto exchanges with low fees and strong security.';
        $icon     = $is_forex ? '&#x1F4B1;' : '&#x20BF;';
        $url      = home_url( '/recommended-brokers/' );

        $cta  = '<div style="background:linear-gradient(135deg,rgba(0,255,102,.08),rgba(0,255,102,.05));border:1px solid rgba(0,255,102,.2);border-radius:0;padding:20px 24px;margin:28px 0">';
        $cta .= '<div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">';
        $cta .= '<div style="font-size:28px;flex-shrink:0">' . $icon . '</div>';
        $cta .= '<div style="flex:1;min-width:200px"><div style="font-size:14px;font-weight:700;color:var(--bt-text);margin-bottom:3px">' . esc_html( $label ) . '</div><div style="font-size:12px;color:var(--bt-text-3)">' . esc_html( $desc ) . '</div></div>';
        $cta .= '<a href="' . esc_url( $url ) . '" style="background:linear-gradient(135deg,var(--bt-accent),var(--bt-accent));color:#0A0B0D;font-weight:700;padding:10px 20px;border-radius:0;font-size:13px;text-decoration:none;white-space:nowrap;flex-shrink:0">Compare Brokers &#x2192;</a>';
        $cta .= '</div></div>';

        $paras = explode( '</p>', $content );
        if ( count( $paras ) > 3 ) {
            $paras[2] .= '</p>' . $cta;
            return implode( '</p>', $paras );
        }
        return $content . $cta;
    }

    // ── SCROLL NEWSLETTER ────────────────────────────────────────────────────
    public static function render_scroll_newsletter() {
        if ( is_admin() ) return;
        $ajax = esc_url( admin_url( 'admin-ajax.php' ) );
        $nonce_val = '';
        if ( function_exists( 'wp_create_nonce' ) ) {
            $nonce_val = wp_create_nonce( 'fxlm_prices' );
        }
        echo '<div id="bt-scroll-nl" style="display:none;position:fixed;bottom:0;left:0;right:0;z-index:9999997;background:linear-gradient(135deg,var(--bt-bg),#0f1829);border-top:1px solid rgba(0,255,102,.3);padding:18px 24px;box-shadow:0 -8px 40px rgba(0,0,0,.6)">';
        echo '<div style="max-width:700px;margin:0 auto;display:flex;align-items:center;gap:16px;flex-wrap:wrap">';
        echo '<div style="flex:1;min-width:220px"><div style="font-size:15px;font-weight:800;color:#fff;margin-bottom:2px">&#x1F4C8; Stay Ahead of the Market</div><div style="font-size:12px;color:var(--bt-text-3)">Free daily digest: top crypto &amp; forex moves, AI analysis &mdash; delivered to your inbox.</div></div>';
        echo '<form id="bt-scroll-nl-form" style="display:flex;gap:8px;flex-shrink:0;flex-wrap:wrap">';
        echo '<input type="email" id="bt-scroll-nl-email" placeholder="your@email.com" required style="background:#0A0B0D;border:1px solid rgba(255,255,255,.15);color:var(--bt-text);border-radius:0;padding:9px 14px;font-size:13px;outline:none;min-width:200px;font-family:inherit">';
        echo '<button type="submit" style="background:linear-gradient(135deg,var(--bt-accent),var(--bt-accent));color:#0A0B0D;font-weight:700;border:none;border-radius:0;padding:9px 20px;cursor:pointer;font-size:13px;font-family:inherit;white-space:nowrap">Subscribe Free &#x2192;</button>';
        echo '</form>';
        echo '<button onclick="btDismissNL()" style="background:none;border:none;color:var(--bt-text-4);cursor:pointer;font-size:18px;padding:4px;line-height:1;flex-shrink:0" title="Dismiss">&#x2715;</button>';
        echo '</div>';
        echo '<div id="bt-scroll-nl-msg" style="display:none;text-align:center;padding:8px 0 0;font-size:13px;color:var(--bt-accent)"></div>';
        echo '</div>';
        echo '<script>';
        echo '(function(){';
        echo 'if(localStorage.getItem("bt_nl_dismissed")||localStorage.getItem("bt_subscribed"))return;';
        echo 'var shown=false;';
        echo 'function showNL(){if(shown)return;shown=true;document.getElementById("bt-scroll-nl").style.display="block";}';
        echo 'window.addEventListener("scroll",function(){var pct=window.scrollY/(document.body.scrollHeight-window.innerHeight);if(pct>=0.4)showNL();},{passive:true});';
        echo 'setTimeout(showNL,30000);';
        echo 'window.btDismissNL=function(){document.getElementById("bt-scroll-nl").style.display="none";localStorage.setItem("bt_nl_dismissed",Date.now());};';
        echo 'document.getElementById("bt-scroll-nl-form").addEventListener("submit",function(e){';
        echo 'e.preventDefault();';
        echo 'var email=document.getElementById("bt-scroll-nl-email").value;';
        echo 'var msg=document.getElementById("bt-scroll-nl-msg");';
        echo 'fetch("' . $ajax . '",{method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded"},';
        echo 'body:"action=fxlm_subscribe&email="+encodeURIComponent(email)+"&nonce=' . esc_js( $nonce_val ) . '"})';
        echo '.then(function(r){return r.json();})';
        echo '.then(function(d){msg.style.display="block";';
        echo 'if(d.success){msg.textContent="You are subscribed! Check your inbox.";localStorage.setItem("bt_subscribed","1");setTimeout(btDismissNL,3000);}';
        echo 'else{msg.style.color="var(--bt-danger)";msg.textContent="Error: "+(d.data||"Please try again.");}});';
        echo '});';
        echo '})();';
        echo '</script>';
    }

    // ── BT SCORE ─────────────────────────────────────────────────────────────
    public static function sc_bt_score( $atts ) {
        $a       = shortcode_atts( array( 'coin' => 'bitcoin', 'show_label' => 'true' ), $atts );
        $coin_id = sanitize_text_field( $a['coin'] );
        $crypto  = BT_Widgets::get_json_option( 'fxlm_crypto_data' );
        $fg      = get_option( 'bt_fear_greed', array() );
        $fg_val  = intval( isset( $fg['value'] ) ? $fg['value'] : 50 );
        $coin    = null;

        foreach ( isset( $crypto['coins'] ) ? $crypto['coins'] : array() as $c ) {
            if ( $c['id'] === $coin_id ) { $coin = $c; break; }
        }
        if ( ! $coin ) return '';

        $chg7      = floatval( isset( $coin['price_change_percentage_7d_in_currency'] ) ? $coin['price_change_percentage_7d_in_currency'] : ( isset( $coin['price_change_percentage_24h'] ) ? $coin['price_change_percentage_24h'] : 0 ) );
        $mom       = min( 20, max( 0, 10 + $chg7 ) );
        $vol       = floatval( isset( $coin['total_volume'] ) ? $coin['total_volume'] : 0 );
        $mcap      = floatval( isset( $coin['market_cap'] ) ? $coin['market_cap'] : 1 );
        $ratio     = $mcap > 0 ? $vol / $mcap : 0;
        $vol_score = min( 20, $ratio * 200 );
        $fg_score  = $fg_val / 5;
        $rank      = intval( isset( $coin['market_cap_rank'] ) ? $coin['market_cap_rank'] : 100 );
        $rk_score  = max( 0, 20 - max( 0, $rank - 1 ) );
        $chg24     = floatval( isset( $coin['price_change_percentage_24h'] ) ? $coin['price_change_percentage_24h'] : 0 );
        $day_score = min( 20, max( 0, 10 + $chg24 ) );
        $score     = (int) min( 100, max( 0, round( $mom + $vol_score + $fg_score + $rk_score + $day_score ) ) );

        if ( $score >= 70 )     { $color = 'var(--bt-accent)'; $label = 'Bullish'; }
        elseif ( $score >= 55 ) { $color = '#10b981'; $label = 'Slightly Bullish'; }
        elseif ( $score >= 45 ) { $color = 'var(--bt-accent-warm)'; $label = 'Neutral'; }
        elseif ( $score >= 30 ) { $color = '#f97316'; $label = 'Slightly Bearish'; }
        else                    { $color = 'var(--bt-danger)'; $label = 'Bearish'; }

        $circ  = 2 * 3.14159 * 28;
        $dash  = $circ * ( $score / 100 );

        $out  = '<div style="display:inline-flex;flex-direction:column;align-items:center;gap:6px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:0;padding:16px 20px;text-align:center;min-width:110px">';
        $out .= '<div style="position:relative;width:72px;height:72px">';
        $out .= '<svg viewBox="0 0 72 72" width="72" height="72" style="transform:rotate(-90deg)">';
        $out .= '<circle cx="36" cy="36" r="28" fill="none" stroke="rgba(255,255,255,.06)" stroke-width="6"/>';
        $out .= '<circle cx="36" cy="36" r="28" fill="none" stroke="' . esc_attr( $color ) . '" stroke-width="6" stroke-linecap="round" stroke-dasharray="' . round( $dash, 2 ) . ' ' . round( $circ, 2 ) . '"/>';
        $out .= '</svg>';
        $out .= '<div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:18px;font-weight:900;color:' . esc_attr( $color ) . '">' . $score . '</div>';
        $out .= '</div>';
        if ( $a['show_label'] !== 'false' ) {
            $out .= '<div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:' . esc_attr( $color ) . '">' . esc_html( $label ) . '</div>';
            $out .= '<div style="font-size:9px;color:var(--bt-text-4)">BT Score</div>';
        }
        $out .= '</div>';
        return $out;
    }

    // ── GUEST WRITER FORM ────────────────────────────────────────────────────
    public static function sc_write_for_us( $atts ) {
        $nonce = wp_create_nonce( 'bt_submit_article' );
        $ajax  = esc_url( admin_url( 'admin-ajax.php' ) );

        ob_start(); 
?>
        <div class="bt-pp-wfu-form-wrap" style="max-width:660px">
          <form id="bt-wfu-form" class="bt-wfu-form" enctype="multipart/form-data">
            <input type="hidden" name="nonce" value="<?php echo esc_attr( $nonce ); ?>">

            <!-- Card 1: Identity -->
            <div class="bt-pp-form-card">
              <div class="bt-pp-form-card-label"><span class="bt-pp-form-card-label-icon">👤</span><?php _ebt("wfu.card1_label"); ?></div>
              <div class="bt-pp-form-row">
                <div class="bt-pp-field">
                  <label><?php _ebt("wfu.name"); ?> <span style="color:#ef4444">*</span></label>
                  <input type="text" name="author_name" required placeholder="<?php _ebt("wfu.name_placeholder"); ?>">
                </div>
                <div class="bt-pp-field">
                  <label><?php _ebt("wfu.email"); ?> <span style="color:#ef4444">*</span></label>
                  <input type="email" name="author_email" required placeholder="you@example.com">
                </div>
                <div class="bt-pp-field bt-pp-field-full">
                  <label><?php _ebt("wfu.credentials"); ?></label>
                  <input type="text" name="credentials" placeholder="<?php _ebt("wfu.credentials_placeholder"); ?>">
                  <span class="hint" style="font-size:11px;color:var(--bt-pp-text3,var(--bt-text-3));margin-top:4px"><?php _ebt("wfu.credentials_hint"); ?></span>
                </div>
              </div>
            </div>

            <!-- Card 2: Article details -->
            <div class="bt-pp-form-card">
              <div class="bt-pp-form-card-label"><span class="bt-pp-form-card-label-icon">📄</span><?php _ebt("wfu.card2_label"); ?></div>
              <div class="bt-pp-form-row">
                <div class="bt-pp-field">
                  <label><?php _ebt("wfu.title"); ?> <span style="color:#ef4444">*</span></label>
                  <input type="text" name="article_title" required placeholder="<?php _ebt("wfu.title_placeholder"); ?>">
                </div>
                <div class="bt-pp-field">
                  <label><?php _ebt("wfu.category"); ?> <span style="color:#ef4444">*</span></label>
                  <select name="category" required>
                    <option value=""><?php _ebt("wfu.category_select"); ?></option>
                    <option value="Crypto Analysis"><?php _ebt("wfu.cat_crypto"); ?></option>
                    <option value="Forex Analysis"><?php _ebt("wfu.cat_forex"); ?></option>
                    <option value="DeFi &amp; Web3"><?php _ebt("wfu.cat_defi"); ?></option>
                    <option value="Education &amp; Guides"><?php _ebt("wfu.cat_education"); ?></option>
                    <option value="Market Opinion"><?php _ebt("wfu.cat_opinion"); ?></option>
                    <option value="Trading Strategy"><?php _ebt("wfu.cat_strategy"); ?></option>
                  </select>
                </div>
              </div>
            </div>

            <!-- Card 3: Content -->
            <div class="bt-pp-form-card">
              <div class="bt-pp-form-card-label"><span class="bt-pp-form-card-label-icon">✏️</span><?php _ebt("wfu.card3_label"); ?></div>
              <div class="bt-pp-field">
                <label><?php _ebt("wfu.content"); ?> <span style="color:#ef4444">*</span></label>
                <textarea name="content" required rows="8" placeholder="<?php _ebt("wfu.content_placeholder"); ?>"></textarea>
                <span style="font-size:11px;color:var(--bt-pp-text3,var(--bt-text-3));margin-top:4px"><?php _ebt("wfu.content_hint"); ?></span>
              </div>
            </div>

            <!-- Card 4: Attachment -->
            <div class="bt-pp-form-card">
              <div class="bt-pp-form-card-label"><span class="bt-pp-form-card-label-icon">📎</span><?php _ebt("wfu.attach"); ?> <span style="color:var(--bt-pp-text3,var(--bt-text-3));font-weight:400;text-transform:none;letter-spacing:0;font-size:12px">(<?php _ebt("wfu.optional"); ?>)</span></div>
              <label for="bt-wfu-file" class="bt-pp-dropzone" id="bt-wfu-dropzone">
                <input type="file" id="bt-wfu-file" name="attachment" accept=".txt,.md,.pdf,.doc,.docx,.rtf,.odt" style="display:none">
                <div class="bt-pp-dz-icon">📂</div>
                <div class="bt-pp-dz-text" id="bt-wfu-dz-text"><?php _ebt("wfu.dropzone_text"); ?></div>
                <div class="bt-pp-dz-meta"><?php _ebt("wfu.dropzone_meta"); ?></div>
              </label>
            </div>

            <!-- Honeypot -->
            <div style="display:none"><input type="text" name="website" tabindex="-1" autocomplete="off"></div>

            <div class="bt-pp-form-footer">
              <div class="bt-pp-form-note"><?php _ebt("wfu.review_note"); ?></div>
              <button type="submit" id="bt-wfu-submit" class="bt-pp-submit" data-i18n="wfu.submit"><?php _ebt("wfu.submit"); ?> →</button>
            </div>
            <div id="bt-wfu-msg" style="font-size:13px;margin-top:12px;min-height:20px;text-align:center"></div>
          </form>
        </div>

        <script>
        (function(){
          var form = document.getElementById("bt-wfu-form");
          var fileIn = document.getElementById("bt-wfu-file");
          var dropzone = document.getElementById("bt-wfu-dropzone");
          var dzText = document.getElementById("bt-wfu-dz-text");
          var btn = document.getElementById("bt-wfu-submit");
          var msg = document.getElementById("bt-wfu-msg");

          function updateFileName(f){
            if (!f) { dzText.textContent = "Click or drag file here \u2014 .txt, .md, .pdf, .doc, .docx, .rtf, .odt"; return; }
            var kb = Math.round(f.size / 1024);
            dzText.textContent = "\u2714 " + f.name + " (" + kb + " KB)";
          }
          fileIn.addEventListener("change", function(){ updateFileName(fileIn.files[0]); });
          ["dragenter","dragover"].forEach(function(e){
            dropzone.addEventListener(e, function(ev){ ev.preventDefault(); dropzone.classList.add("active"); });
          });
          ["dragleave","drop"].forEach(function(e){
            dropzone.addEventListener(e, function(ev){ ev.preventDefault(); dropzone.classList.remove("active"); });
          });
          dropzone.addEventListener("drop", function(ev){
            if (ev.dataTransfer.files && ev.dataTransfer.files[0]) {
              fileIn.files = ev.dataTransfer.files;
              updateFileName(fileIn.files[0]);
            }
          });

          form.addEventListener("submit", function(e){
            e.preventDefault();
            msg.style.display = "none";
            var data = new FormData(form);
            data.append("action", "bt_submit_article");
            // Size guard
            if (fileIn.files[0] && fileIn.files[0].size > 5 * 1024 * 1024) {
              msg.className = "bt-wfu-msg bt-wfu-msg-err"; msg.style.display = "block";
              msg.textContent = "File is over 5 MB. Please compress or attach a smaller version.";
              return;
            }
            btn.disabled = true; btn.textContent = "Submitting...";
            fetch("<?php echo $ajax; ?>", { method: "POST", body: data, credentials: "same-origin" })
              .then(function(r){ return r.json(); })
              .then(function(d){
                msg.style.display = "block";
                if (d.success) {
                  msg.className = "bt-wfu-msg bt-wfu-msg-ok"; msg.textContent = d.data;
                  form.reset(); updateFileName(null);
                } else {
                  msg.className = "bt-wfu-msg bt-wfu-msg-err";
                  msg.textContent = (d.data || "Error. Please try again.");
                }
                btn.disabled = false; btn.textContent = "Submit My Article \u2192";
              })
              .catch(function(){
                msg.style.display = "block";
                msg.className = "bt-wfu-msg bt-wfu-msg-err";
                msg.textContent = "Network error. Please try again.";
                btn.disabled = false; btn.textContent = "Submit My Article \u2192";
              });
          });
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    // ── AJAX SUBMIT ──────────────────────────────────────────────────────────
    public static function ajax_submit_article() {
        if ( ! empty( $_POST['website'] ) ) { wp_send_json_success( 'Thank you for your submission.' ); return; }
        if ( ! wp_verify_nonce( isset( $_POST['nonce'] ) ? $_POST['nonce'] : '', 'bt_submit_article' ) ) {
            wp_send_json_error( 'Security check failed. Please refresh and try again.' );
        }
        $name    = sanitize_text_field( isset( $_POST['author_name'] )   ? $_POST['author_name']   : '' );
        $email   = sanitize_email(      isset( $_POST['author_email'] )  ? $_POST['author_email']  : '' );
        $creds   = sanitize_text_field( isset( $_POST['credentials'] )   ? $_POST['credentials']   : '' );
        $title   = sanitize_text_field( isset( $_POST['article_title'] ) ? $_POST['article_title'] : '' );
        $cat     = sanitize_text_field( isset( $_POST['category'] )      ? $_POST['category']      : '' );
        $content = sanitize_textarea_field( isset( $_POST['content'] )   ? $_POST['content']       : '' );

        if ( ! $name || ! is_email( $email ) || ! $title || strlen( $content ) < 100 ) {
            wp_send_json_error( 'Please fill in all required fields. Content must be at least 100 characters.' );
        }

        $key = 'bt_sub_' . md5( $email );
        $cnt = get_transient( $key );
        if ( $cnt && intval( $cnt ) >= 2 ) {
            wp_send_json_error( 'Too many submissions from this email today. Please try again tomorrow.' );
        }
        set_transient( $key, intval( $cnt ) + 1, DAY_IN_SECONDS );

        // Handle optional file attachment (txt, md, pdf, doc, docx, rtf, odt ─ max 5 MB)
        $attachment_id = 0;
        if ( ! empty( $_FILES['attachment'] ) && ! empty( $_FILES['attachment']['name'] ) && empty( $_FILES['attachment']['error'] ) ) {
            $allowed_ext = array( 'txt', 'md', 'pdf', 'doc', 'docx', 'rtf', 'odt' );
            $allowed_mime = array(
                'text/plain', 'text/markdown', 'application/pdf',
                'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/rtf', 'text/rtf', 'application/vnd.oasis.opendocument.text',
                'application/octet-stream', // some browsers for .md
            );
            $file = $_FILES['attachment'];
            $ext  = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
            if ( $file['size'] > 5 * 1024 * 1024 ) {
                wp_send_json_error( 'Attachment is over 5 MB. Please upload a smaller file.' );
            }
            if ( ! in_array( $ext, $allowed_ext, true ) ) {
                wp_send_json_error( 'File type not allowed. Accepted: ' . implode( ', ', $allowed_ext ) );
            }
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
            $upload = wp_handle_upload( $file, array(
                'test_form' => false,
                'mimes'     => array(
                    'txt'  => 'text/plain', 'md' => 'text/markdown',
                    'pdf'  => 'application/pdf', 'doc' => 'application/msword',
                    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'rtf'  => 'application/rtf', 'odt' => 'application/vnd.oasis.opendocument.text',
                ),
            ) );
            if ( empty( $upload['error'] ) && ! empty( $upload['file'] ) ) {
                $attachment = array(
                    'post_mime_type' => $upload['type'],
                    'post_title'     => sanitize_file_name( pathinfo( $file['name'], PATHINFO_FILENAME ) ),
                    'post_content'   => '',
                    'post_status'    => 'private',
                );
                $attachment_id = wp_insert_attachment( $attachment, $upload['file'] );
                if ( $attachment_id && ! is_wp_error( $attachment_id ) ) {
                    $meta = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
                    wp_update_attachment_metadata( $attachment_id, $meta );
                }
            }
        }

        $post_id = wp_insert_post( array(
            'post_title'   => $title,
            'post_content' => $content,
            'post_status'  => 'pending',
            'post_type'    => 'bt_submission',
            'post_author'  => 1,
        ) );

        if ( ! $post_id || is_wp_error( $post_id ) ) {
            wp_send_json_error( 'Could not save submission. Please try again.' );
        }

        update_post_meta( $post_id, '_bt_author_name',  $name );
        update_post_meta( $post_id, '_bt_author_email', $email );
        update_post_meta( $post_id, '_bt_credentials',  $creds );
        update_post_meta( $post_id, '_bt_category',     $cat );
        if ( $attachment_id ) {
            update_post_meta( $post_id, '_bt_attachment_id', (int) $attachment_id );
            wp_update_post( array( 'ID' => $attachment_id, 'post_parent' => $post_id ) );
        }

        wp_mail(
            get_option( 'admin_email' ),
            '[' . get_option( 'bt_site_name', 'BlockTicker' ) . '] New Article Submission: ' . $title,
            "From: {$name} ({$email})\nCredentials: {$creds}\nCategory: {$cat}\n\nReview: " . admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
            array( 'Content-Type: text/plain; charset=UTF-8' )
        );

        wp_send_json_success( 'Thank you, ' . $name . '! We will review your article within 3-5 business days.' );
    }

    // ── v90: READING PROGRESS BAR ────────────────────────────────────────────
    public static function render_reading_progress() {
        if ( ! is_single() ) return;
        echo '<div id="bt-rp-bar" aria-hidden="true" style="position:fixed;top:0;left:0;width:0%;height:3px;background:linear-gradient(90deg,var(--bt-accent),var(--bt-accent));z-index:99999;transition:width .1s linear;will-change:width;pointer-events:none"></div>';
        echo '<script>(function(){var b=document.getElementById("bt-rp-bar");if(!b)return;function u(){var s=document.documentElement,h=s.scrollHeight-s.clientHeight;b.style.width=h>0?(s.scrollTop/h*100)+"%":"0%";}window.addEventListener("scroll",u,{passive:true});u();})();</script>';
    }

    // ── v90: SOCIAL SHARE BAR ────────────────────────────────────────────────
    public static function inject_share_bar( $content ) {
        if ( ! is_single() || ! in_the_loop() || ! is_main_query() ) return $content;
        $url   = rawurlencode( get_permalink() );
        $title = rawurlencode( get_the_title() );
        $bar   = '<div class="bt-share-bar">';
        $bar  .= '<span class="bt-share-label">Share</span>';
        $bar  .= '<a class="bt-share-btn bt-share-tw" href="https://twitter.com/intent/tweet?url=' . $url . '&text=' . $title . '" target="_blank" rel="noopener noreferrer" title="Share on X">𝕏</a>';
        $bar  .= '<a class="bt-share-btn bt-share-li" href="https://www.linkedin.com/sharing/share-offsite/?url=' . $url . '" target="_blank" rel="noopener noreferrer" title="Share on LinkedIn">in</a>';
        $bar  .= '<a class="bt-share-btn bt-share-rd" href="https://reddit.com/submit?url=' . $url . '&title=' . $title . '" target="_blank" rel="noopener noreferrer" title="Share on Reddit">r/</a>';
        $bar  .= '<a class="bt-share-btn bt-share-tg" href="https://t.me/share/url?url=' . $url . '&text=' . $title . '" target="_blank" rel="noopener noreferrer" title="Share on Telegram">&#x2708;</a>';
        $bar  .= '<button class="bt-share-btn bt-share-copy" data-url="' . esc_attr( get_permalink() ) . '" title="Copy link" onclick="var u=this.dataset.url;navigator.clipboard&&navigator.clipboard.writeText(u).then((function(b){return function(){var t=b.textContent;b.textContent=\'✓\';setTimeout(function(){b.textContent=t;},2000);}})(this))">&#x1F517;</button>';
        $bar  .= '</div>';
        return $bar . $content;
    }

    // ── v90: TABLE OF CONTENTS ───────────────────────────────────────────────
    public static function inject_toc( $content ) {
        if ( ! is_single() || ! in_the_loop() || ! is_main_query() ) return $content;
        // Only inject for posts >= 600 words
        if ( str_word_count( strip_tags( $content ) ) < 600 ) return $content;
        // Parse H2 and H3 headings
        preg_match_all( '/<h([23])[^>]*>(.*?)<\/h\1>/is', $content, $m, PREG_SET_ORDER );
        if ( count( $m ) < 3 ) return $content; // need at least 3 headings to be worth a TOC
        $items = '';
        $slugs = array();
        $modified = $content;
        foreach ( $m as $hit ) {
            $level    = $hit[1];
            $raw_text = wp_strip_all_tags( $hit[2] );
            $base     = sanitize_title( $raw_text );
            // Deduplicate slugs
            $slug = $base;
            $n    = 1;
            while ( in_array( $slug, $slugs, true ) ) $slug = $base . '-' . ( ++$n );
            $slugs[] = $slug;
            // Add id to the heading in content
            $with_id = str_replace( $hit[0], '<h' . $level . ' id="' . esc_attr( $slug ) . '">' . $hit[2] . '</h' . $level . '>', $hit[0] );
            $modified = str_replace( $hit[0], $with_id, $modified );
            $indent   = $level === '3' ? ' style="padding-left:16px"' : '';
            $items   .= '<li' . $indent . '><a href="#' . esc_attr( $slug ) . '">' . esc_html( $raw_text ) . '</a></li>';
        }
        $toc  = '<nav class="bt-toc" aria-label="Table of contents">';
        $toc .= '<div class="bt-toc-header"><span class="bt-toc-title">In this article</span><button class="bt-toc-toggle" aria-expanded="true" aria-label="Toggle contents">&#x25B2;</button></div>';
        $toc .= '<ol class="bt-toc-list" id="bt-toc-list">' . $items . '</ol>';
        $toc .= '</nav>';
        $toc .= '<script>(function(){var b=document.querySelector(".bt-toc-toggle"),l=document.getElementById("bt-toc-list");if(b&&l){b.addEventListener("click",function(){var o=l.style.display!=="none";l.style.display=o?"none":"";b.textContent=o?"▼":"▲";b.setAttribute("aria-expanded",(!o).toString());});}})();</script>';
        // Inject TOC after the first closing </p>
        $pos = strpos( $modified, '</p>' );
        if ( $pos !== false ) {
            $modified = substr_replace( $modified, '</p>' . $toc, $pos, 4 );
        } else {
            $modified = $toc . $modified;
        }
        return $modified;
    }

    // ── v90: RELATED VERDICT CARD ────────────────────────────────────────────
    // Matches the post's tags + title against known asset slugs and injects
    // a live mini-verdict card linking to /analysis/{slug}/ before the risk warning.
    public static function inject_related_verdict( $content ) {
        if ( ! is_single() || ! in_the_loop() || ! is_main_query() ) return $content;
        $asset_map = array(
            'bitcoin'      => array( 'bitcoin', 'btc' ),
            'ethereum'     => array( 'ethereum', 'eth' ),
            'solana'       => array( 'solana', 'sol' ),
            'binancecoin'  => array( 'bnb', 'binance coin', 'binancecoin' ),
            'ripple'       => array( 'xrp', 'ripple' ),
            'dogecoin'     => array( 'dogecoin', 'doge' ),
            'cardano'      => array( 'cardano', 'ada' ),
            'avalanche-2'  => array( 'avalanche', 'avax' ),
            'chainlink'    => array( 'chainlink', 'link' ),
            'polkadot'     => array( 'polkadot', 'dot' ),
            'eur-usd'      => array( 'eurusd', 'eur/usd', 'eur-usd', 'euro' ),
            'gbp-usd'      => array( 'gbpusd', 'gbp/usd', 'gbp-usd', 'pound' ),
            'usd-jpy'      => array( 'usdjpy', 'usd/jpy', 'usd-jpy', 'yen' ),
        );
        // Build search corpus from post title + tags
        $tags      = get_the_tags( get_the_ID() );
        $tag_names = $tags ? implode( ' ', array_map( function( $t ) { return strtolower( $t->name ); }, $tags ) ) : '';
        $title_lc  = strtolower( get_the_title() );
        $corpus    = $title_lc . ' ' . $tag_names;
        $matched   = null;
        foreach ( $asset_map as $slug => $keywords ) {
            foreach ( $keywords as $kw ) {
                if ( strpos( $corpus, $kw ) !== false ) { $matched = $slug; break 2; }
            }
        }
        if ( ! $matched ) return $content;
        // Get snapshot data for this asset if available — otherwise show a teaser
        $crypto    = BT_Widgets::get_json_option( 'fxlm_crypto_data' );
        $coin_data = null;
        if ( ! empty( $crypto ) ) {
            foreach ( $crypto as $c ) {
                if ( isset( $c['id'] ) && $c['id'] === $matched ) { $coin_data = $c; break; }
            }
        }
        $analysis_url = home_url( '/analysis/' . $matched . '/' );
        $name         = $coin_data ? esc_html( $coin_data['name'] ) : esc_html( ucwords( str_replace( '-', ' ', $matched ) ) );
        $price        = ( $coin_data && isset( $coin_data['current_price'] ) )
            ? '$' . number_format( $coin_data['current_price'], $coin_data['current_price'] >= 100 ? 2 : 4 )
            : '—';
        $chg24        = ( $coin_data && isset( $coin_data['price_change_percentage_24h'] ) )
            ? $coin_data['price_change_percentage_24h'] : null;
        $chg_str  = ( $chg24 !== null ) ? ( ( $chg24 >= 0 ? '+' : '' ) . number_format( $chg24, 2 ) . '%' ) : '—';
        $chg_col  = ( $chg24 !== null ) ? ( $chg24 >= 0 ? 'var(--bt-accent)' : 'var(--bt-danger)' ) : 'var(--bt-text-3)';
        $card  = '<div class="bt-rv-card">';
        $card .= '<div class="bt-rv-head">';
        $card .= '<span class="bt-rv-eyebrow">&#x1F4CA; Live Desk Verdict</span>';
        $card .= '<a class="bt-rv-link" href="' . esc_url( $analysis_url ) . '">View full analysis &#x2192;</a>';
        $card .= '</div>';
        $card .= '<div class="bt-rv-body">';
        $card .= '<div class="bt-rv-asset"><span class="bt-rv-name">' . $name . '</span><span class="bt-rv-price">' . esc_html( $price ) . '</span><span class="bt-rv-chg" style="color:' . esc_attr( $chg_col ) . '">' . esc_html( $chg_str ) . '</span></div>';
        $card .= '<a href="' . esc_url( $analysis_url ) . '" class="bt-rv-cta">See AI desk verdict for ' . $name . ' &#x2192;</a>';
        $card .= '</div>';
        $card .= '</div>';
        return $content . $card;
    }

    // ── v90: NEXT / PREV ARTICLE NAVIGATION ─────────────────────────────────
    public static function inject_post_nav( $content ) {
        if ( ! is_single() || ! in_the_loop() || ! is_main_query() ) return $content;
        $cats = get_the_category( get_the_ID() );
        $cat_id = ! empty( $cats ) ? $cats[0]->term_id : 0;
        $prev = get_previous_post( true );
        $next = get_next_post( true );
        if ( ! $prev && ! $next ) return $content;
        $nav  = '<nav class="bt-pn-nav" aria-label="Article navigation">';
        $nav .= '<div class="bt-pn-inner">';
        if ( $prev ) {
            $ptitle = esc_html( wp_trim_words( $prev->post_title, 10, '…' ) );
            $nav   .= '<a class="bt-pn-item bt-pn-prev" href="' . esc_url( get_permalink( $prev->ID ) ) . '">';
            $nav   .= '<span class="bt-pn-dir">&#x2190; Previous</span>';
            $nav   .= '<span class="bt-pn-ptitle">' . $ptitle . '</span>';
            $nav   .= '</a>';
        } else {
            $nav .= '<span></span>';
        }
        if ( $next ) {
            $ntitle = esc_html( wp_trim_words( $next->post_title, 10, '…' ) );
            $nav   .= '<a class="bt-pn-item bt-pn-next" href="' . esc_url( get_permalink( $next->ID ) ) . '">';
            $nav   .= '<span class="bt-pn-dir">Next &#x2192;</span>';
            $nav   .= '<span class="bt-pn-ptitle">' . $ntitle . '</span>';
            $nav   .= '</a>';
        }
        $nav .= '</div></nav>';
        return $content . $nav;
    }

    // ── ADMIN ──────────────────────────────────────────────────────────────
    public static function render_submissions_page() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
        $submissions = get_posts( array( 'post_type' => 'bt_submission', 'post_status' => array( 'pending', 'draft', 'publish' ), 'numberposts' => 50, 'orderby' => 'date', 'order' => 'DESC' ) );
        echo '<div class="wrap" style="max-width:1000px"><h1>&#x270D;&#xFE0F; Writer Submissions</h1>';
        if ( empty( $submissions ) ) {
            echo '<div style="background:var(--bt-bg-elev);border:1px solid rgba(255,255,255,.07);border-radius:0;padding:40px;text-align:center;color:var(--bt-text-3)"><div style="font-size:32px;margin-bottom:12px">&#x1F4EC;</div>No submissions yet. Add <code>[bt_write_for_us]</code> to a page.</div>';
        } else {
            echo '<table style="width:100%;border-collapse:collapse;background:var(--bt-bg-elev);border:1px solid rgba(255,255,255,.08);border-radius:0;overflow:hidden">';
            echo '<thead><tr style="border-bottom:1px solid rgba(255,255,255,.06)"><th style="padding:12px 16px;text-align:left;font-size:11px;color:var(--bt-text-3);text-transform:uppercase">Author</th><th style="padding:12px 16px;text-align:left;font-size:11px;color:var(--bt-text-3);text-transform:uppercase">Title</th><th style="padding:12px 16px;text-align:left;font-size:11px;color:var(--bt-text-3);text-transform:uppercase">Category</th><th style="padding:12px 16px;text-align:left;font-size:11px;color:var(--bt-text-3);text-transform:uppercase">Date</th><th style="padding:12px 16px;text-align:left;font-size:11px;color:var(--bt-text-3);text-transform:uppercase">Action</th></tr></thead><tbody>';
            foreach ( $submissions as $s ) {
                $a_name  = esc_html( get_post_meta( $s->ID, '_bt_author_name',  true ) );
                $a_email = esc_html( get_post_meta( $s->ID, '_bt_author_email', true ) );
                $cat     = esc_html( get_post_meta( $s->ID, '_bt_category',     true ) );
                echo '<tr style="border-bottom:1px solid rgba(255,255,255,.04)">';
                echo '<td style="padding:12px 16px"><div style="font-weight:700;color:var(--bt-text);font-size:13px">' . $a_name . '</div><div style="font-size:11px;color:var(--bt-text-3)">' . $a_email . '</div></td>';
                echo '<td style="padding:12px 16px;font-size:13px;color:#c8d6e5">' . esc_html( $s->post_title ) . '</td>';
                echo '<td style="padding:12px 16px"><span style="font-size:11px;background:rgba(0,255,102,.1);color:var(--bt-accent);border-radius:0;padding:2px 8px">' . $cat . '</span></td>';
                echo '<td style="padding:12px 16px;font-size:12px;color:var(--bt-text-3)">' . get_the_date( 'M j, Y', $s->ID ) . '</td>';
                echo '<td style="padding:12px 16px"><a href="' . esc_url( admin_url( 'post.php?post=' . $s->ID . '&action=edit' ) ) . '" style="font-size:12px;background:rgba(0,255,102,.1);color:var(--bt-accent);border:1px solid rgba(0,255,102,.2);border-radius:0;padding:5px 12px;text-decoration:none;font-weight:600">Review &#x2192;</a></td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
    }
}
