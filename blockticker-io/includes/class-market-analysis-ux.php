<?php
/**
 * BlockTicker — Market Analysis UX (v119.24.0)
 *
 * Implements the four concrete recommendations from the strategic-analysis
 * report for /market-analysis/:
 *
 *   1. [bt_audience_split]        — Audience-segmented landing strip with
 *                                   two CTAs (Quick Read / Deep Research) so
 *                                   retail and institutional readers self-route.
 *   2. [bt_page_nav]              — Sticky in-page scroll-spy nav for long
 *                                   analysis pages. Anchors highlight as the
 *                                   user scrolls.
 *   3. [bt_methodology_card]      — Compact "How this was built" footer block;
 *                                   surfaces sourcing transparency without
 *                                   forcing readers to navigate to /about/.
 *   4. [bt_newsletter_exit_intent] — Exit-intent newsletter modal. Fires once
 *                                   per visitor (via localStorage). Soft
 *                                   conversion mechanism — never repeats.
 *
 * @package BlockTicker
 * @since   119.24.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BT_MarketAnalysisUX {

    /** Bumped per release so a single static asset URL invalidates the cache. */
    const ASSET_VERSION = '1';

    public static function init() {
        add_shortcode( 'bt_audience_split',         array( __CLASS__, 'sc_audience_split' ) );
        add_shortcode( 'bt_page_nav',               array( __CLASS__, 'sc_page_nav' ) );
        add_shortcode( 'bt_methodology_card',       array( __CLASS__, 'sc_methodology_card' ) );
        add_shortcode( 'bt_newsletter_exit_intent', array( __CLASS__, 'sc_newsletter_exit_intent' ) );
    }

    // ── 1. Audience split ────────────────────────────────────────────────────

    /**
     * Two-card hero block that segments the audience at the top of the page.
     * Retail / quick-read on the left, institutional / deep-research on the right.
     *
     * @param array $atts {
     *     @type string $quick_url   Override for retail link target
     *     @type string $deep_url    Override for institutional link target
     * }
     */
    public static function sc_audience_split( $atts ) {
        $a = shortcode_atts( array(
            'quick_url' => home_url( '/forecast/' ),
            'deep_url'  => home_url( '/verdict-archive/' ),
        ), $atts );

        ob_start();
        ?>
        <section class="bt-aud-split" aria-label="Choose your reading mode">
            <a href="<?php echo esc_url( $a['quick_url'] ); ?>" class="bt-aud-card bt-aud-quick">
                <div class="bt-aud-eyebrow">FOR ACTIVE TRADERS</div>
                <h2 class="bt-aud-title">⚡ Quick Read</h2>
                <p class="bt-aud-desc">Today's verdicts, top movers, and the daily forecast in 60 seconds. Fresh every morning at 06:00 UTC.</p>
                <ul class="bt-aud-bullets">
                    <li>Daily forecasts for 24 tracked assets</li>
                    <li>Bullish / bearish / neutral verdict pills</li>
                    <li>24-hour price &amp; sentiment shift</li>
                </ul>
                <span class="bt-aud-cta">View today's forecasts →</span>
            </a>

            <a href="<?php echo esc_url( $a['deep_url'] ); ?>" class="bt-aud-card bt-aud-deep">
                <div class="bt-aud-eyebrow">FOR INSTITUTIONAL READERS</div>
                <h2 class="bt-aud-title">🔬 Deep Research</h2>
                <p class="bt-aud-desc">30-day verdict track record, cross-asset correlations, and methodology. Every prediction is logged and scored.</p>
                <ul class="bt-aud-bullets">
                    <li>Full 30-day desk track record</li>
                    <li>Hit-rate scoring per verdict</li>
                    <li>Sourcing &amp; methodology transparency</li>
                </ul>
                <span class="bt-aud-cta">Open the verdict archive →</span>
            </a>
        </section>

        <style>
        .bt-aud-split{
            display:grid;grid-template-columns:1fr 1fr;gap:14px;
            margin:0 0 32px;font-family:var(--bt-font-sans,system-ui,sans-serif);
        }
        .bt-aud-card{
            position:relative;display:block;padding:28px 26px;
            background:linear-gradient(180deg,var(--bt-bg-elev,#121316) 0%,rgba(15,17,22,.95) 100%);
            border:1px solid rgba(255,255,255,.08);border-radius:4px;
            text-decoration:none !important;color:inherit;overflow:hidden;
            transition:transform .25s cubic-bezier(.2,.8,.2,1),
                       border-color .25s ease,
                       box-shadow .25s ease;
        }
        .bt-aud-card::before{
            content:'';position:absolute;top:0;left:0;right:0;height:3px;
            background:transparent;transition:background .25s ease;
        }
        .bt-aud-quick::before{background:var(--bt-accent,#00ff66);}
        .bt-aud-deep::before {background:#a78bfa;}
        .bt-aud-card:hover{
            transform:translateY(-3px);
            border-color:rgba(255,255,255,.18);
            box-shadow:0 14px 32px -12px rgba(0,0,0,.6);
        }
        .bt-aud-quick:hover{box-shadow:0 14px 32px -12px rgba(0,0,0,.6),0 0 28px -10px rgba(0,255,102,.4);}
        .bt-aud-deep:hover {box-shadow:0 14px 32px -12px rgba(0,0,0,.6),0 0 28px -10px rgba(167,139,250,.4);}
        .bt-aud-eyebrow{
            font:700 10px/1 var(--bt-font-mono,monospace);letter-spacing:.14em;
            color:var(--bt-text-3,rgba(255,255,255,.45));margin-bottom:10px;
        }
        .bt-aud-quick .bt-aud-eyebrow{color:var(--bt-accent,#00ff66);}
        .bt-aud-deep  .bt-aud-eyebrow{color:#a78bfa;}
        .bt-aud-title{
            margin:0 0 10px;font:900 22px/1.2 var(--bt-font-display,inherit);
            letter-spacing:-.4px;color:var(--bt-text,#fff);
        }
        .bt-aud-desc{
            margin:0 0 14px;font-size:13px;line-height:1.55;
            color:var(--bt-text-2,rgba(255,255,255,.7));
        }
        .bt-aud-bullets{
            list-style:none;padding:0;margin:0 0 16px;
            font-size:12px;color:var(--bt-text-2,rgba(255,255,255,.7));
        }
        .bt-aud-bullets li{padding:4px 0 4px 18px;position:relative;}
        .bt-aud-bullets li::before{
            content:'';position:absolute;left:0;top:11px;width:8px;height:1px;
            background:rgba(255,255,255,.4);
        }
        .bt-aud-cta{
            display:inline-block;font:700 12px/1 var(--bt-font-mono,monospace);
            letter-spacing:.06em;padding-top:8px;border-top:1px solid rgba(255,255,255,.08);
            width:100%;
        }
        .bt-aud-quick .bt-aud-cta{color:var(--bt-accent,#00ff66);}
        .bt-aud-deep  .bt-aud-cta{color:#a78bfa;}

        @media (max-width:720px){
            .bt-aud-split{grid-template-columns:1fr;gap:10px;margin-bottom:24px;}
            .bt-aud-card{padding:22px 20px;}
            .bt-aud-title{font-size:20px;}
        }
        </style>
        <?php
        return ob_get_clean();
    }

    // ── 2. Sticky page nav ───────────────────────────────────────────────────

    /**
     * Sticky in-page scroll-spy navigation.
     *
     * @param array $atts {
     *     @type string $items  Pipe-separated "Label|anchor-id" pairs.
     *                          e.g. "Today's Verdict|verdict|Track Record|track"
     * }
     */
    public static function sc_page_nav( $atts ) {
        $a = shortcode_atts( array(
            'items' => "Today's Desk|desk|Track Record|track|30-Day Archive|archive|Newsletter|newsletter",
        ), $atts );

        $tokens = array_filter( array_map( 'trim', explode( '|', $a['items'] ) ) );
        if ( count( $tokens ) < 2 ) return '';

        // Convert flat token list → [label, anchor] pairs
        $pairs = array();
        for ( $i = 0; $i + 1 < count( $tokens ); $i += 2 ) {
            $pairs[] = array(
                'label'  => $tokens[ $i ],
                'anchor' => sanitize_title( $tokens[ $i + 1 ] ),
            );
        }
        if ( empty( $pairs ) ) return '';

        ob_start();
        ?>
        <nav class="bt-page-nav" id="bt-page-nav" aria-label="Page navigation">
            <ul>
                <?php foreach ( $pairs as $idx => $p ) : ?>
                <li>
                    <a href="#<?php echo esc_attr( $p['anchor'] ); ?>"
                       data-anchor="<?php echo esc_attr( $p['anchor'] ); ?>"
                       <?php echo $idx === 0 ? 'class="bt-pn-active"' : ''; ?>>
                        <?php echo esc_html( $p['label'] ); ?>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
        </nav>

        <style>
        .bt-page-nav{
            position:sticky;top:0;z-index:40;
            margin:0 -20px 24px;padding:0 20px;
            background:rgba(10,12,15,.92);backdrop-filter:blur(8px);
            -webkit-backdrop-filter:blur(8px);
            border-bottom:1px solid rgba(255,255,255,.08);
            font-family:var(--bt-font-mono,monospace);
        }
        .bt-page-nav ul{
            display:flex;list-style:none;margin:0;padding:0;gap:0;
            overflow-x:auto;scrollbar-width:none;
        }
        .bt-page-nav ul::-webkit-scrollbar{display:none;}
        .bt-page-nav li{flex-shrink:0;}
        .bt-page-nav a{
            display:block;padding:14px 16px;
            font-size:12px;font-weight:700;letter-spacing:.06em;
            color:var(--bt-text-3,rgba(255,255,255,.45));
            text-decoration:none !important;text-transform:uppercase;
            border-bottom:2px solid transparent;
            transition:color .2s ease,border-color .2s ease;
            white-space:nowrap;
        }
        .bt-page-nav a:hover{color:var(--bt-text-2,rgba(255,255,255,.7));}
        .bt-page-nav a.bt-pn-active{
            color:var(--bt-accent,#00ff66);
            border-bottom-color:var(--bt-accent,#00ff66);
        }
        @media (max-width:640px){
            .bt-page-nav{margin:0 -16px 18px;padding:0 16px;}
            .bt-page-nav a{padding:12px 12px;font-size:11px;}
        }
        </style>

        <script>
        (function(){
            var nav = document.getElementById('bt-page-nav');
            if (!nav) return;
            var links = nav.querySelectorAll('a[data-anchor]');
            var anchors = Array.from(links).map(function(a){
                return { link:a, id:a.dataset.anchor, el:document.getElementById(a.dataset.anchor) };
            }).filter(function(x){ return x.el; });

            // Smooth scroll with offset for the sticky nav itself
            links.forEach(function(link){
                link.addEventListener('click', function(e){
                    var id = link.dataset.anchor;
                    var t  = document.getElementById(id);
                    if (!t) return;
                    e.preventDefault();
                    var navH = nav.offsetHeight;
                    var y = t.getBoundingClientRect().top + window.scrollY - navH - 12;
                    window.scrollTo({ top:y, behavior:'smooth' });
                    history.replaceState(null, '', '#' + id);
                });
            });

            // Scroll-spy via IntersectionObserver — highlights the section
            // currently nearest the top of the viewport. Falls back to the
            // first item if none intersect (above first or below last).
            if (!('IntersectionObserver' in window)) return;
            var visible = new Set();
            var io = new IntersectionObserver(function(entries){
                entries.forEach(function(en){
                    if (en.isIntersecting) visible.add(en.target.id);
                    else                    visible.delete(en.target.id);
                });
                // Highlight first anchor whose section is visible
                var activeId = null;
                for (var i=0;i<anchors.length;i++) {
                    if (visible.has(anchors[i].id)) { activeId = anchors[i].id; break; }
                }
                if (!activeId && anchors[0]) activeId = anchors[0].id;
                links.forEach(function(l){
                    l.classList.toggle('bt-pn-active', l.dataset.anchor === activeId);
                });
            }, { rootMargin:'-20% 0px -60% 0px', threshold:0 });
            anchors.forEach(function(a){ io.observe(a.el); });
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    // ── 3. Methodology card ──────────────────────────────────────────────────

    /**
     * Compact "How this was built" footer card. Surfaces sourcing
     * transparency without forcing the reader to /about/.
     */
    public static function sc_methodology_card( $atts ) {
        $a = shortcode_atts( array(
            'about_url'  => home_url( '/about/' ),
            'sources'    => 'CoinGecko · Frankfurter · 24-source RSS pipeline · AI',
            'updated'    => 'Daily at 06:00 UTC',
        ), $atts );

        ob_start();
        ?>
        <aside class="bt-method-card" aria-label="Methodology &amp; sources">
            <div class="bt-method-icon" aria-hidden="true">
                <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                    <circle cx="10" cy="10" r="8.5" stroke="currentColor" stroke-width="1.4"/>
                    <path d="M10 5.5v5l3 2" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
                </svg>
            </div>
            <div class="bt-method-body">
                <div class="bt-method-title">How this analysis was built</div>
                <div class="bt-method-meta">
                    <span><strong>Sources:</strong> <?php echo esc_html( $a['sources'] ); ?></span>
                    <span><strong>Refresh:</strong> <?php echo esc_html( $a['updated'] ); ?></span>
                </div>
            </div>
            <a href="<?php echo esc_url( $a['about_url'] ); ?>" class="bt-method-link">Full methodology →</a>
        </aside>

        <style>
        .bt-method-card{
            display:flex;align-items:center;gap:14px;
            margin:24px 0;padding:14px 18px;
            background:rgba(255,255,255,.02);
            border:1px solid rgba(255,255,255,.06);
            border-left:2px solid var(--bt-accent,#00ff66);
            border-radius:0;
            font-family:var(--bt-font-sans,system-ui,sans-serif);
        }
        .bt-method-icon{
            flex-shrink:0;color:var(--bt-accent,#00ff66);
            width:28px;height:28px;display:flex;align-items:center;justify-content:center;
        }
        .bt-method-body{flex:1;min-width:0;}
        .bt-method-title{
            font:700 12px/1.2 var(--bt-font-mono,monospace);
            letter-spacing:.04em;color:var(--bt-text-2,rgba(255,255,255,.7));
            margin-bottom:4px;
        }
        .bt-method-meta{
            display:flex;flex-wrap:wrap;gap:14px;
            font-size:11px;color:var(--bt-text-3,rgba(255,255,255,.5));line-height:1.5;
        }
        .bt-method-meta strong{color:var(--bt-text-2,rgba(255,255,255,.7));font-weight:700;}
        .bt-method-link{
            flex-shrink:0;font:700 11px/1 var(--bt-font-mono,monospace);
            letter-spacing:.06em;color:var(--bt-accent,#00ff66);
            text-decoration:none !important;white-space:nowrap;
        }
        .bt-method-link:hover{text-decoration:underline !important;}
        @media (max-width:560px){
            .bt-method-card{flex-wrap:wrap;}
            .bt-method-link{order:3;width:100%;text-align:right;padding-top:6px;border-top:1px solid rgba(255,255,255,.05);}
        }
        </style>
        <?php
        return ob_get_clean();
    }

    // ── 4. Newsletter exit-intent modal ──────────────────────────────────────

    /**
     * Exit-intent newsletter modal.
     *
     * Shows once per browser (suppressed by localStorage flag) when the user
     * moves the cursor toward the top of the viewport (signalling they're
     * about to close the tab or hit the back button). Mobile fallback: shows
     * after a configurable scroll-depth + minimum dwell time.
     */
    public static function sc_newsletter_exit_intent( $atts ) {
        $a = shortcode_atts( array(
            'headline'      => 'Don\'t miss the next move',
            'subtext'       => 'Get tomorrow\'s desk verdict before the bell. Free, daily, takes 30 seconds.',
            'min_dwell_sec' => '20',
            'scroll_depth'  => '60',
        ), $atts );

        ob_start();
        ?>
        <div class="bt-exit-modal" id="bt-exit-modal" aria-hidden="true" role="dialog" aria-labelledby="bt-exit-title">
            <div class="bt-exit-backdrop" data-bt-close></div>
            <div class="bt-exit-card">
                <button class="bt-exit-close" type="button" aria-label="Close" data-bt-close>×</button>
                <div class="bt-exit-eyebrow">FREE · DAILY · 30 SEC SETUP</div>
                <h3 class="bt-exit-title" id="bt-exit-title"><?php echo esc_html( $a['headline'] ); ?></h3>
                <p class="bt-exit-sub"><?php echo esc_html( $a['subtext'] ); ?></p>

                <div class="bt-exit-form">
                    <input type="email" id="bt-exit-email" placeholder="your@email.com" autocomplete="email"
                           class="bt-exit-input" aria-label="Email address">
                    <button id="bt-exit-submit" class="bt-exit-submit" type="button">
                        Get the desk →
                    </button>
                </div>
                <div id="bt-exit-msg" class="bt-exit-msg"></div>
                <div class="bt-exit-trust">
                    <span>✓ No spam, ever</span>
                    <span>✓ Unsubscribe anytime</span>
                    <span>✓ One email per day</span>
                </div>
            </div>
        </div>

        <style>
        .bt-exit-modal{
            position:fixed;inset:0;z-index:9999;
            display:flex;align-items:center;justify-content:center;
            opacity:0;visibility:hidden;
            transition:opacity .3s ease,visibility .3s ease;
            font-family:var(--bt-font-sans,system-ui,sans-serif);
        }
        .bt-exit-modal.bt-open{opacity:1;visibility:visible;}
        .bt-exit-backdrop{
            position:absolute;inset:0;
            background:rgba(0,0,0,.78);backdrop-filter:blur(4px);
            -webkit-backdrop-filter:blur(4px);cursor:pointer;
        }
        .bt-exit-card{
            position:relative;width:90%;max-width:480px;
            background:linear-gradient(180deg,#0d0f12 0%,#0a0c0f 100%);
            border:1px solid rgba(0,255,102,.3);border-radius:0;
            padding:32px 28px 24px;color:#fff;
            box-shadow:0 25px 60px -10px rgba(0,0,0,.8),0 0 60px -20px rgba(0,255,102,.4);
            transform:translateY(20px) scale(.96);transition:transform .35s cubic-bezier(.2,.8,.2,1);
        }
        .bt-exit-modal.bt-open .bt-exit-card{transform:translateY(0) scale(1);}
        .bt-exit-close{
            position:absolute;top:10px;right:14px;
            background:none;border:0;cursor:pointer;
            color:rgba(255,255,255,.5);font-size:28px;line-height:1;
            padding:6px 10px;transition:color .2s;
        }
        .bt-exit-close:hover{color:#fff;}
        .bt-exit-eyebrow{
            font:700 10px/1 var(--bt-font-mono,monospace);
            letter-spacing:.16em;color:var(--bt-accent,#00ff66);
            margin-bottom:14px;
        }
        .bt-exit-title{
            margin:0 0 10px;font:900 24px/1.2 var(--bt-font-display,inherit);
            letter-spacing:-.4px;color:#fff;
        }
        .bt-exit-sub{
            margin:0 0 22px;font-size:14px;line-height:1.5;
            color:rgba(255,255,255,.7);
        }
        .bt-exit-form{
            display:flex;gap:8px;margin-bottom:8px;
        }
        .bt-exit-input{
            flex:1;min-width:0;background:rgba(255,255,255,.05);
            border:1px solid rgba(255,255,255,.15);border-radius:0;
            padding:13px 14px;color:#fff;font-size:14px;font-family:inherit;
            transition:border-color .2s;
        }
        .bt-exit-input:focus{outline:none;border-color:var(--bt-accent,#00ff66);}
        .bt-exit-submit{
            flex-shrink:0;background:var(--bt-accent,#00ff66);
            color:#000;font:900 13px/1 var(--bt-font-display,inherit);
            letter-spacing:.04em;border:0;border-radius:0;cursor:pointer;
            padding:0 20px;min-height:46px;transition:background .2s,transform .15s;
        }
        .bt-exit-submit:hover{background:#00d855;}
        .bt-exit-submit:active{transform:scale(.97);}
        .bt-exit-msg{min-height:18px;font-size:12px;margin-top:8px;}
        .bt-exit-trust{
            display:flex;flex-wrap:wrap;gap:10px;margin-top:14px;padding-top:14px;
            border-top:1px solid rgba(255,255,255,.06);
            font:600 10px/1 var(--bt-font-mono,monospace);
            letter-spacing:.04em;color:rgba(255,255,255,.45);
        }
        @media (max-width:560px){
            .bt-exit-card{padding:26px 20px 20px;}
            .bt-exit-title{font-size:21px;}
            .bt-exit-form{flex-direction:column;}
            .bt-exit-submit{width:100%;}
        }
        </style>

        <script>
        (function(){
            var modal = document.getElementById('bt-exit-modal');
            if (!modal) return;
            var KEY = 'bt_exit_seen_v1';
            var minDwell = <?php echo (int) $a['min_dwell_sec']; ?> * 1000;
            var scrollDepth = <?php echo (int) $a['scroll_depth']; ?>;
            var startTime = Date.now();
            var armed = false;
            var triggered = false;

            // Suppression: if user dismissed it before, never show again on this device
            try { if (localStorage.getItem(KEY)) return; } catch(e) {}

            // Arm the modal only after the minimum dwell time has elapsed —
            // prevents firing on visitors who bounce within the first few seconds.
            setTimeout(function(){ armed = true; }, minDwell);

            function open() {
                if (triggered) return;
                triggered = true;
                modal.classList.add('bt-open');
                modal.setAttribute('aria-hidden', 'false');
                setTimeout(function(){
                    var inp = document.getElementById('bt-exit-email');
                    if (inp) inp.focus();
                }, 350);
            }
            function close() {
                modal.classList.remove('bt-open');
                modal.setAttribute('aria-hidden', 'true');
                try { localStorage.setItem(KEY, '1'); } catch(e) {}
            }

            // Desktop: mouse leaves through the top of the viewport
            document.addEventListener('mouseout', function(e){
                if (!armed || triggered) return;
                if (e.relatedTarget || e.toElement) return; // moved to another element, not out
                if (e.clientY <= 4) open();
            });

            // Mobile / touch fallback: scroll-depth past threshold + dwell time
            var scrollChecked = false;
            window.addEventListener('scroll', function(){
                if (!armed || triggered || scrollChecked) return;
                var doc = document.documentElement;
                var scrollPct = (window.scrollY + window.innerHeight) / doc.scrollHeight * 100;
                if (scrollPct >= scrollDepth) {
                    scrollChecked = true;
                    // Only fire on touch devices to avoid double-trigger with mouseout
                    if ('ontouchstart' in window) open();
                }
            }, { passive:true });

            // Esc closes
            document.addEventListener('keydown', function(e){
                if (e.key === 'Escape' && modal.classList.contains('bt-open')) close();
            });

            // Click handlers — backdrop and X both close + remember
            modal.addEventListener('click', function(e){
                if (e.target.hasAttribute('data-bt-close')) close();
            });

            // Submit — reuse the existing /wp-admin/admin-ajax.php newsletter endpoint
            var submitBtn = document.getElementById('bt-exit-submit');
            if (submitBtn) {
                submitBtn.addEventListener('click', function(){
                    var email = document.getElementById('bt-exit-email').value.trim();
                    var msg   = document.getElementById('bt-exit-msg');
                    if (!email || email.indexOf('@') === -1) {
                        msg.style.color = '#ff6b6b';
                        msg.textContent = 'Please enter a valid email address.';
                        return;
                    }
                    submitBtn.disabled = true;
                    submitBtn.textContent = 'Subscribing…';
                    var fd = new FormData();
                    fd.append('action','fxlm_subscribe');
                    fd.append('email', email);
                    // Public-AJAX nonce — same one the inline newsletter form uses
                    fd.append('nonce','<?php echo wp_create_nonce( 'fxlm_prices' ); ?>');
                    fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', { method:'POST', body:fd })
                        .then(function(r){ return r.json(); })
                        .then(function(res){
                            submitBtn.disabled = false;
                            submitBtn.textContent = 'Get the desk →';
                            if (res.success) {
                                msg.style.color = '#00ff66';
                                msg.textContent = '✓ ' + (res.data || 'Subscribed! Check your inbox.');
                                setTimeout(close, 1800);
                            } else {
                                msg.style.color = '#ff6b6b';
                                msg.textContent = res.data || 'Subscription failed. Try again.';
                            }
                        })
                        .catch(function(){
                            submitBtn.disabled = false;
                            submitBtn.textContent = 'Get the desk →';
                            msg.style.color = '#ff6b6b';
                            msg.textContent = 'Network error. Try again.';
                        });
                });
            }
        })();
        </script>
        <?php
        return ob_get_clean();
    }
}
