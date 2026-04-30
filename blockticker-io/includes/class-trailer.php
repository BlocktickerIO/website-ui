<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class BT_Trailer {

    public static function register_shortcodes() {
        add_shortcode( 'blockticker_trailer', array( __CLASS__, 'sc_trailer' ) );
    }

    public static function sc_trailer( $atts ) {
        $a = shortcode_atts( array( 'height' => '580', 'autoplay' => 'true' ), $atts );
        $h = intval( $a['height'] );
        $id = 'bt-trailer-' . wp_rand( 1000, 9999 );

        ob_start();
        
?>
        <div class="bt-trailer-wrap" id="<?php echo $id; ?>" style="max-width:480px;width:100%;box-sizing:border-box;margin:24px auto;border-radius:22px;overflow:hidden;border:1px solid rgba(255,255,255,.04)">
        <div style="background:#070b14;color:var(--bt-text);min-height:<?php echo $h; ?>px;position:relative;display:flex;flex-direction:column;font-family:'Outfit','DM Sans',system-ui,sans-serif">
        <style>
        @import url('https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&display=swap');
        #<?php echo $id; ?> .bt-bg{position:absolute;inset:0;overflow:hidden;pointer-events:none}
        #<?php echo $id; ?> .bt-orb{position:absolute;border-radius:50%}
        #<?php echo $id; ?> .bt-o1{width:500px;height:500px;top:-200px;right:-200px;background:radial-gradient(circle,rgba(0,255,102,.06),transparent 60%);animation:btSpin 25s linear infinite}
        #<?php echo $id; ?> .bt-o2{width:400px;height:400px;bottom:-150px;left:-150px;background:radial-gradient(circle,rgba(0,255,102,.05),transparent 60%)}
        @keyframes btSpin{to{transform:rotate(360deg)}}
        #<?php echo $id; ?> .bt-dot{position:absolute;border-radius:50%;opacity:0;animation:btDp 5s ease infinite}
        @keyframes btDp{0%,100%{opacity:0;transform:scale(0)}50%{opacity:.5;transform:scale(1)}}
        #<?php echo $id; ?> .bt-scene{width:100%;text-align:center;display:none}
        #<?php echo $id; ?> .bt-scene.active{display:block}
        #<?php echo $id; ?> .bt-anim{opacity:0;transform:translateY(28px);transition:all .7s ease}
        #<?php echo $id; ?> .bt-scene.active .bt-anim{opacity:1;transform:translateY(0)}
        #<?php echo $id; ?> .bt-as{opacity:0;transform:scale(.5);transition:all .6s cubic-bezier(.34,1.56,.64,1)}
        #<?php echo $id; ?> .bt-scene.active .bt-as{opacity:1;transform:scale(1)}
        #<?php echo $id; ?> .bt-al{opacity:0;transform:translateX(-30px);transition:all .5s ease}
        #<?php echo $id; ?> .bt-scene.active .bt-al{opacity:1;transform:translateX(0)}
        #<?php echo $id; ?> .bt-bar-anim{width:0%;transition:width 1.2s ease .5s}
        #<?php echo $id; ?> .bt-scene.active .bt-bar-anim{width:var(--w)}
        #<?php echo $id; ?> .bt-d1{transition-delay:.1s}#<?php echo $id; ?> .bt-d2{transition-delay:.2s}
        #<?php echo $id; ?> .bt-d3{transition-delay:.3s}#<?php echo $id; ?> .bt-d4{transition-delay:.4s}
        #<?php echo $id; ?> .bt-d5{transition-delay:.5s}#<?php echo $id; ?> .bt-d6{transition-delay:.6s}
        #<?php echo $id; ?> .bt-d7{transition-delay:.7s}#<?php echo $id; ?> .bt-d8{transition-delay:.8s}
        #<?php echo $id; ?> .bt-d9{transition-delay:.9s}#<?php echo $id; ?> .bt-d10{transition-delay:1s}
        #<?php echo $id; ?> .bt-tag{font-size:10px;font-weight:700;padding:4px 14px;border-radius:20px;letter-spacing:2.5px;text-transform:uppercase;display:inline-block}
        #<?php echo $id; ?> .bt-h{font-size:28px;font-weight:800;margin:12px 0 0;letter-spacing:-.5px;line-height:1.2}
        #<?php echo $id; ?> .bt-sub{font-size:13px;color:var(--bt-text-3);margin:8px 0 0;line-height:1.7}
        #<?php echo $id; ?> .bt-card{background:rgba(255,255,255,.025);border:1px solid rgba(255,255,255,.06);border-radius:0;padding:14px 16px;text-align:left}
        #<?php echo $id; ?> .bt-grad{background:linear-gradient(135deg,var(--bt-accent),var(--bt-accent));-webkit-background-clip:text;-webkit-text-fill-color:transparent}
        #<?php echo $id; ?> .bt-logo{width:48px;height:48px;border-radius:11px;background:linear-gradient(135deg,var(--bt-accent),var(--bt-accent));display:inline-flex;align-items:center;justify-content:center;box-shadow:0 8px 32px rgba(0,255,102,.3)}
        #<?php echo $id; ?> .bt-dt{width:6px;height:6px;border-radius:3px;border:none;cursor:pointer;padding:0;background:rgba(255,255,255,.08);transition:all .3s}
        #<?php echo $id; ?> .bt-dt.on{width:22px;background:linear-gradient(90deg,var(--bt-accent),var(--bt-accent))}
        #<?php echo $id; ?> .bt-dt.past{background:rgba(0,255,102,.25)}
        #<?php echo $id; ?> .bt-replay{display:block;margin:12px auto 0;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);color:var(--bt-text-3);padding:7px 22px;border-radius:0;cursor:pointer;font-size:12px;font-weight:600;font-family:inherit}
        #<?php echo $id; ?> .bt-cta{display:inline-block;margin-top:20px;padding:12px 32px;border-radius:0;background:linear-gradient(135deg,var(--bt-accent),var(--bt-accent));color:#0A0B0D;font-weight:700;font-size:14px;text-decoration:none;box-shadow:0 8px 32px rgba(0,255,102,.25)}
        </style>

        <div class="bt-bg"><div class="bt-orb bt-o1"></div><div class="bt-orb bt-o2"></div><div id="<?php echo $id; ?>-dots"></div></div>

        <div style="flex:1;display:flex;align-items:center;justify-content:center;position:relative;z-index:1;padding:32px 20px">
          <!-- S0: INTRO -->
          <div class="bt-scene" id="<?php echo $id; ?>-s0">
            <div class="bt-anim bt-as bt-d1"><div class="bt-logo"><svg viewBox="0 0 40 40" width="28" height="28"><polygon points="20,4 35,12 35,28 20,36 5,28 5,12" fill="rgba(255,255,255,.15)"/><path d="M8,20 L14,20 L17,12 L20,29 L23,15 L26,20 L32,20" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg></div></div>
            <div class="bt-anim bt-d3" style="font-size:42px;font-weight:800;margin:14px 0 4px;letter-spacing:-1.5px">Block<span class="bt-grad">Ticker</span></div>
            <div class="bt-anim bt-d5" style="font-size:12px;color:var(--bt-accent);letter-spacing:4px;text-transform:uppercase;font-weight:700">Live Crypto & Forex Intelligence</div>
            <div class="bt-anim bt-d7" style="width:60px;height:2px;margin:16px auto 0;border-radius:2px;background:linear-gradient(90deg,var(--bt-accent),var(--bt-accent))"></div>
          </div>
          <!-- S1: PRICES -->
          <div class="bt-scene" id="<?php echo $id; ?>-s1">
            <div class="bt-anim bt-d1 bt-tag" style="background:rgba(0,255,102,.1);border:1px solid rgba(0,255,102,.2);color:var(--bt-accent)">Real-Time Data</div>
            <div class="bt-anim bt-d2 bt-h">500+ Assets. <span style="color:var(--bt-accent)">Live.</span></div>
            <div style="display:flex;flex-direction:column;gap:10px;margin-top:16px">
              <div class="bt-card bt-anim bt-al bt-d3"><div style="display:flex;justify-content:space-between;align-items:center"><div style="display:flex;align-items:center;gap:10px"><div style="width:32px;height:32px;border-radius:50%;background:rgba(247,147,26,.15);border:2px solid rgba(247,147,26,.3);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;color:#f7931a">B</div><div><div style="font-weight:700;font-size:13px">Bitcoin</div><div style="font-size:10px;color:var(--bt-text-3)">BTC/USD</div></div></div><div style="text-align:right"><div style="font-weight:700;font-size:16px">$70,879</div><div style="font-size:11px;color:var(--bt-accent);font-weight:700">+1.3%</div></div></div></div>
              <div class="bt-card bt-anim bt-al bt-d4"><div style="display:flex;justify-content:space-between;align-items:center"><div style="display:flex;align-items:center;gap:10px"><div style="width:32px;height:32px;border-radius:50%;background:rgba(98,126,234,.15);border:2px solid rgba(98,126,234,.3);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;color:#627eea">E</div><div><div style="font-weight:700;font-size:13px">Ethereum</div><div style="font-size:10px;color:var(--bt-text-3)">ETH/USD</div></div></div><div style="text-align:right"><div style="font-weight:700;font-size:16px">$2,164</div><div style="font-size:11px;color:var(--bt-accent);font-weight:700">+0.9%</div></div></div></div>
              <div class="bt-card bt-anim bt-al bt-d5"><div style="display:flex;justify-content:space-between;align-items:center"><div style="display:flex;align-items:center;gap:10px"><div style="width:32px;height:32px;border-radius:50%;background:rgba(0,255,102,.15);border:2px solid rgba(0,255,102,.3);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;color:var(--bt-accent)">S</div><div><div style="font-weight:700;font-size:13px">Solana</div><div style="font-size:10px;color:var(--bt-text-3)">SOL/USD</div></div></div><div style="text-align:right"><div style="font-weight:700;font-size:16px">$89.08</div><div style="font-size:11px;color:var(--bt-accent);font-weight:700">+5.2%</div></div></div></div>
            </div>
          </div>
          <!-- S2-S7 same pattern, simplified for shortcode -->
          <div class="bt-scene" id="<?php echo $id; ?>-s2"><div class="bt-anim bt-d1 bt-tag" style="background:rgba(0,255,102,.1);border:1px solid rgba(0,255,102,.2);color:var(--bt-accent)">Auto-Aggregated</div><div class="bt-anim bt-d2 bt-h"><span style="color:var(--bt-accent)">14</span> Premium Sources</div><div class="bt-anim bt-d4 bt-sub">CoinDesk · The Block · Decrypt · Reuters · CoinTelegraph · BeInCrypto · Blockworks · FXStreet · CryptoSlate · Bitcoin Mag · The Defiant · DailyFX · Investing.com · ForexFactory</div></div>
          <div class="bt-scene" id="<?php echo $id; ?>-s3"><div class="bt-anim bt-d1 bt-tag" style="background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.2);color:var(--bt-accent-warm)">Trading Signals</div><div class="bt-anim bt-d2 bt-h">Every <span style="color:var(--bt-accent-warm)">15 Minutes</span></div><div class="bt-anim bt-d4 bt-sub">Free auto-updated forex & crypto signals from professional sources. EUR/USD, BTC/USD, GBP/JPY and more.</div></div>
          <div class="bt-scene" id="<?php echo $id; ?>-s4"><div class="bt-anim bt-d1 bt-tag" style="background:rgba(167,139,250,.1);border:1px solid rgba(167,139,250,.2);color:#a78bfa">AI-Powered</div><div class="bt-anim bt-d2 bt-h">Zero Effort. <span style="color:#a78bfa">Daily.</span></div><div class="bt-anim bt-d4 bt-sub">AI-generated market roundups published automatically twice daily. No writing, no editing, no effort.</div></div>
          <div class="bt-scene" id="<?php echo $id; ?>-s5"><div class="bt-anim bt-d1 bt-tag" style="background:rgba(0,255,102,.1);border:1px solid rgba(0,255,102,.2);color:var(--bt-accent)">Trader Toolkit</div><div class="bt-anim bt-d2 bt-h">Everything. <span style="color:var(--bt-accent)">Built In.</span></div><div class="bt-anim bt-d4 bt-sub">Crypto converter · Fear & Greed Index · TradingView charts · Learn Hub with 25+ glossary terms</div></div>
          <div class="bt-scene" id="<?php echo $id; ?>-s6"><div class="bt-anim bt-d1 bt-tag" style="background:rgba(255,59,48,.1);border:1px solid rgba(255,59,48,.2);color:var(--bt-danger)">Fully Automated</div><div class="bt-anim bt-d2 bt-h">Set It. <span style="color:var(--bt-danger)">Forget It.</span></div><div class="bt-anim bt-d4 bt-sub">Prices every 5 min · News every hour · Signals every 15 min · AI posts twice daily · WordPress + plugins auto-update</div></div>
          <div class="bt-scene" id="<?php echo $id; ?>-s7">
            <div class="bt-anim bt-as bt-d1"><div class="bt-logo"><svg viewBox="0 0 40 40" width="28" height="28"><polygon points="20,4 35,12 35,28 20,36 5,28 5,12" fill="rgba(255,255,255,.15)"/><path d="M8,20 L14,20 L17,12 L20,29 L23,15 L26,20 L32,20" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg></div></div>
            <div class="bt-anim bt-d3" style="font-size:34px;font-weight:800;margin:12px 0 4px;letter-spacing:-1px">Block<span class="bt-grad">Ticker</span><span style="color:var(--bt-text-4);font-size:14px;font-weight:500;vertical-align:super;margin-left:3px">.io</span></div>
            <div class="bt-anim bt-d5 bt-sub">Your crypto & forex site. On autopilot.</div>
            <div class="bt-anim bt-d7"><a href="/" class="bt-cta">Explore Now</a></div>
            <div class="bt-anim bt-d9" style="display:flex;justify-content:center;gap:28px;margin-top:22px"><div><div style="font-size:22px;font-weight:800;color:var(--bt-accent)">500+</div><div style="font-size:9px;color:var(--bt-text-3);text-transform:uppercase;letter-spacing:1.5px;margin-top:2px">Assets</div></div><div><div style="font-size:22px;font-weight:800;color:var(--bt-accent)">14</div><div style="font-size:9px;color:var(--bt-text-3);text-transform:uppercase;letter-spacing:1.5px;margin-top:2px">Sources</div></div><div><div style="font-size:22px;font-weight:800;color:var(--bt-accent)">24/7</div><div style="font-size:9px;color:var(--bt-text-3);text-transform:uppercase;letter-spacing:1.5px;margin-top:2px">Autopilot</div></div></div>
          </div>
        </div>

        <div style="position:relative;z-index:2;padding:0 24px 18px">
          <div style="display:flex;justify-content:center;gap:5px;margin-bottom:10px" id="<?php echo $id; ?>-dots-nav"></div>
          <div style="height:3px;background:rgba(255,255,255,.04);border-radius:2px;overflow:hidden"><div style="height:100%;background:linear-gradient(90deg,var(--bt-accent),var(--bt-accent));border-radius:2px;width:0%;transition:width .1s linear" id="<?php echo $id; ?>-bar"></div></div>
          <button class="bt-replay" id="<?php echo $id; ?>-btn">Scene 1 / 8</button>
        </div>
        </div>
        </div>

        <script>
        (function(){
          var id="<?php echo $id; ?>";
          var S=[3200,3500,3200,3000,3000,3000,3200,4500],T=S.reduce(function(a,b){return a+b},0);
          var cur=0,play=true,st=Date.now(),raf;
          var dc=document.getElementById(id+"-dots");
          for(var i=0;i<16;i++){var d=document.createElement("div");d.className="bt-dot";d.style.cssText="left:"+Math.random()*100+"%;top:"+Math.random()*100+"%;width:"+(0.5+Math.random()*1.5)+"px;height:"+(0.5+Math.random()*1.5)+"px;background:"+(Math.random()>.5?"var(--bt-accent)":"var(--bt-accent)")+";animation-delay:"+Math.random()*6+"s";dc.appendChild(d)}
          var dn=document.getElementById(id+"-dots-nav");
          for(var i=0;i<8;i++){var b=document.createElement("button");b.className="bt-dt";b.dataset.i=i;b.onclick=function(){jump(+this.dataset.i)};dn.appendChild(b)}
          document.getElementById(id+"-btn").onclick=function(){if(!play)restart()};
          function show(n){for(var i=0;i<8;i++){var e=document.getElementById(id+"-s"+i);if(i===n)e.classList.add("active");else e.classList.remove("active")}var ds=dn.children;for(var i=0;i<8;i++){ds[i].classList.remove("on","past");if(i===n)ds[i].classList.add("on");else if(i<n)ds[i].classList.add("past")}cur=n}
          function tick(){if(!play)return;var el=Date.now()-st,p=Math.min(el/T,1);document.getElementById(id+"-bar").style.width=(p*100)+"%";document.getElementById(id+"-btn").textContent="Scene "+(cur+1)+" / 8";var acc=0;for(var i=0;i<8;i++){acc+=S[i];if(el<acc){show(i);break}if(i===7&&el>=acc){show(7);play=false;document.getElementById(id+"-btn").textContent="Replay Trailer";return}}raf=requestAnimationFrame(tick)}
          function jump(n){var o=0;for(var i=0;i<n;i++)o+=S[i];st=Date.now()-o;play=true;show(n);cancelAnimationFrame(raf);tick()}
          function restart(){st=Date.now();play=true;show(0);cancelAnimationFrame(raf);tick()}
          tick();
        })();
        </script>
        <?php
        return ob_get_clean();
    }
}
