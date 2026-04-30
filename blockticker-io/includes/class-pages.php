<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class BT_Pages {

    public static function init_hero_script() {
        // Register hero particle animation via wp_footer (avoids PHP string escaping issues)
        if ( is_front_page() ) {
            add_action( 'wp_footer', array( __CLASS__, 'output_hero_script' ), 20 );
        }
    }

    public static function output_hero_script() {
        
?>
        <script>
        (function(){
            var canvas=document.getElementById('fxlm-hero-canvas');
            if(!canvas)return;
            var ctx=canvas.getContext('2d'),W,H,pts=[];
            function rs(){W=canvas.width=canvas.offsetWidth;H=canvas.height=canvas.offsetHeight;}
            rs();
            window.addEventListener('resize',rs,{passive:true});
            for(var i=0;i<55;i++){pts.push({x:Math.random()*2000,y:Math.random()*800,vx:(Math.random()-.5)*.3,vy:(Math.random()-.5)*.3,r:Math.random()*1.5+.3,c:Math.random()>.5?'rgba(0,255,102,':'rgba(0,153,255,',o:Math.random()*.5+.1});}
            function draw(){
                if(!W||!H)return;
                ctx.clearRect(0,0,W,H);
                for(var i=0;i<pts.length;i++){var p=pts[i];p.x+=p.vx;p.y+=p.vy;if(p.x<0)p.x=W;if(p.x>W)p.x=0;if(p.y<0)p.y=H;if(p.y>H)p.y=0;ctx.beginPath();ctx.arc(p.x,p.y,p.r,0,Math.PI*2);ctx.fillStyle=p.c+p.o+')';ctx.fill();}
                for(var i=0;i<pts.length;i++){for(var j=i+1;j<pts.length;j++){var dx=pts[i].x-pts[j].x,dy=pts[i].y-pts[j].y,d=Math.sqrt(dx*dx+dy*dy);if(d<120){ctx.beginPath();ctx.moveTo(pts[i].x,pts[i].y);ctx.lineTo(pts[j].x,pts[j].y);ctx.strokeStyle='rgba(0,255,102,'+(0.06*(1-d/120))+')';ctx.lineWidth=.6;ctx.stroke();}}}
                requestAnimationFrame(draw);
            }
            draw();
        })();
        </script>
        <?php
    }

    public static function get_pages_config() {
        $site_name = get_option( 'bt_site_name', 'BlockTicker' );
        $year = date( 'Y' );

        return array(

            // ── HOME ──
            'home' => array(
                'title' => 'Home',
                'slug'  => 'home',
                'content' => '
<!-- wp:html -->
<div class="fxlm-ticker-wrap">[fxlm_ticker_bar]</div>

<!-- ── GLOBAL STATS BAR (slim) ── -->
<div class="bt-stats-bar" id="bt-stats-bar">
  <div class="bt-stats-inner">
    <span class="bt-stat-item">Cryptos: <strong id="bts-coins">—</strong></span>
    <span class="bt-stat-sep">|</span>
    <span class="bt-stat-item">Market Cap: <strong id="bts-mcap" class="up">—</strong></span>
    <span class="bt-stat-sep">|</span>
    <span class="bt-stat-item">BTC Dom: <strong id="bts-btc">—</strong></span>
    <span class="bt-stat-sep">|</span>
    <span class="bt-stat-item bt-stat-fg">Fear &amp; Greed: <strong id="bts-fg" class="up">—</strong></span>
    <span class="bt-stat-sep">|</span>
    <span class="bt-stat-item">Exchanges: <strong id="bts-exc">—</strong></span>
  </div>
</div>
<script>
(function(){
  var rest = (typeof fxlm_data !== "undefined") ? fxlm_data.rest_url : "/wp-json/blockticker/v1/prices";
  fetch(rest).then(r=>r.json()).then(function(d){
    var coins = d.crypto && d.crypto.coins ? d.crypto.coins : [];
    if(!coins.length) return;
    var totalMcap=0,totalVol=0,btcMcap=0;
    coins.forEach(function(c){
      var mc=parseFloat(c.market_cap||0);
      totalMcap+=mc; totalVol+=parseFloat(c.total_volume||0);
      if(c.id==="bitcoin") btcMcap=mc;
    });
    function fmt(n){
      if(n>=1e12) return "$"+(n/1e12).toFixed(2)+"T";
      if(n>=1e9)  return "$"+(n/1e9).toFixed(2)+"B";
      return "$"+n.toFixed(0);
    }
    var el=function(id){return document.getElementById(id);};
    if(el("bts-coins")) el("bts-coins").textContent=coins.length.toLocaleString();
    if(el("bts-mcap"))  el("bts-mcap").textContent=fmt(totalMcap);
    if(el("bts-btc")&&totalMcap>0) el("bts-btc").textContent=(btcMcap/totalMcap*100).toFixed(1)+"%";
    if(d.crypto&&d.crypto.exchanges&&el("bts-exc")) el("bts-exc").textContent=d.crypto.exchanges;
  }).catch(function(){});
  var fgRest = (typeof fxlm_data !== "undefined") ? fxlm_data.rest_url.replace("prices","fear-greed") : "/wp-json/blockticker/v1/fear-greed";
  fetch(fgRest).then(r=>r.json()).then(function(d){
    if(d && d.value) {
      var el=document.getElementById("bts-fg");
      if(el) { el.textContent=d.value+" · "+d.label; el.className=parseInt(d.value)>50?"up":"down"; }
    }
  }).catch(function(){});
})();
</script>

<!-- ════════════════════════════════════════════════
     FOLD 1 — SPLIT HERO
     Left: headline + trust proof + CTAs
     Right: Intelligence Brief verdict card (the differentiator)
════════════════════════════════════════════════ -->
<div class="bt-h84-hero">
  <div class="bt-h84-hero-inner">

    <!-- LEFT COLUMN — Messaging (v91: centered + animated + rolling ticker) -->
    <div class="bt-h84-hero-left">

      <!-- Live badge -->
      <div class="bt-hero-badge bt-anim-fade-up" style="--d:0s">
        <span class="bt-pulse"></span>
        <span data-i18n="section.live_data">Live Data · Auto-Updated 24/7</span>
      </div>

      <!-- Rolling curiosity ticker -->
      <div class="bt-hero-ticker bt-anim-fade-up" style="--d:.1s" aria-live="polite">
        <span class="bt-hero-ticker-label">NOW</span>
        <div class="bt-hero-ticker-track" id="bt-hero-ticker">
          <span class="bt-hero-ticker-msg active">&#x26A1; BTC dominance shifting — altcoin season signal firing</span>
          <span class="bt-hero-ticker-msg">&#x1F4CA; 8-signal composite updated every 15 minutes</span>
          <span class="bt-hero-ticker-msg">&#x1F30D; EUR/USD, GBP/USD, USD/JPY — live desk verdict ready</span>
          <span class="bt-hero-ticker-msg">&#x1F916; AI desk: reading macro, crypto &amp; FX news 24/7</span>
          <span class="bt-hero-ticker-msg">&#x1F4C5; 30-day verdict archive — every call, fully transparent</span>
          <span class="bt-hero-ticker-msg">&#x1F50D; Per-asset analysis: /analysis/bitcoin · /analysis/ethereum</span>
        </div>
      </div>

      <!-- Headline -->
      <h1 class="bt-h84-headline bt-anim-fade-up" style="--d:.18s">
        Deep Market Intelligence<br>
        for <span class="bt-h84-accent">Crypto</span>,
        <span class="bt-h84-accent">Forex</span> &amp; <span class="bt-h84-accent">Web3</span>
      </h1>

      <p class="bt-h84-sub bt-anim-fade-up" style="--d:.28s">Real-time prices · AI analysis · Trading signals.<br>Every data point sourced, attributed and verified.</p>

      <div class="bt-h84-ctas bt-anim-fade-up" style="--d:.36s">
        <a href="/market-analysis/" class="bt-btn-primary">&#x1F4E1; Today\'s Desk Analysis</a>
        <a href="/crypto-markets/" class="bt-btn-secondary">&#x1F4CA; Live Markets</a>
      </div>

      <div class="bt-h84-trust bt-anim-fade-up" style="--d:.44s">
        <div class="bt-h84-trust-item"><strong>500+</strong><span data-i18n="home.stat_coins">Coins Tracked</span></div>
        <div class="bt-h84-trust-div"></div>
        <div class="bt-h84-trust-item"><strong>170+</strong><span data-i18n="home.stat_forex">Forex Pairs</span></div>
        <div class="bt-h84-trust-div"></div>
        <div class="bt-h84-trust-item"><strong>14+</strong><span data-i18n="home.stat_sources">News Sources</span></div>
        <div class="bt-h84-trust-div"></div>
        <div class="bt-h84-trust-item"><strong>100%</strong><span data-i18n="home.stat_free">Free Forever</span></div>
      </div>

    </div>

    <script>
    (function(){
      var msgs = document.querySelectorAll(\'#bt-hero-ticker .bt-hero-ticker-msg\');
      if(!msgs.length) return;
      var cur = 0;
      setInterval(function(){
        msgs[cur].classList.remove(\'active\');
        msgs[cur].classList.add(\'exit\');
        var prev = cur;
        cur = (cur+1) % msgs.length;
        setTimeout(function(){ msgs[prev].classList.remove(\'exit\'); msgs[cur].classList.add(\'active\'); }, 420);
      }, 3800);
    })();
    </script>

    <!-- RIGHT COLUMN — Intelligence Brief (the differentiator, inline) -->
    <div class="bt-h84-hero-right">
      [bt_intelligence_brief]
    </div>

  </div>
</div>

<!-- ════════════════════════════════════════════════
     FOLD 2 — MARKET STATE
     Market Pulse strip · Slim Movers · 3 Headlines
════════════════════════════════════════════════ -->
<div class="bt-h84-state">
  <div class="bt-h84-state-inner">

    <!-- Market Pulse (4 live tiles) -->
    <div class="bt-h84-pulse-wrap">
      [bt_market_pulse]
    </div>

    <!-- Slim Movers: Top 5 Gainers + Top 5 Losers -->
    <div class="bt-h84-movers">
      <div class="bt-h84-movers-col bt-card-reveal" style="--ri:0">
        <div class="bt-h84-movers-head">
          <span class="bt-h84-movers-label up">▲ Top Gainers</span>
          <a href="/gainers-losers/" class="bt-h84-movers-more">Full list →</a>
        </div>
        <div class="bt-h84-movers-list" id="bt-h84-gainers"></div>
      </div>
      <div class="bt-h84-movers-col">
        <div class="bt-h84-movers-head">
          <span class="bt-h84-movers-label down">▼ Top Losers</span>
          <a href="/gainers-losers/" class="bt-h84-movers-more">Full list →</a>
        </div>
        <div class="bt-h84-movers-list" id="bt-h84-losers"></div>
      </div>
    </div>
    <script>
    (function(){
      var rest=(typeof fxlm_data!=="undefined")?fxlm_data.rest_url:"/wp-json/blockticker/v1/prices";
      function fmtP(n){return(n>=0?"+":"")+parseFloat(n).toFixed(2)+"%";}
      function fmtPrice(n){return n>=1?"$"+parseFloat(n).toLocaleString("en-US",{maximumFractionDigits:2}):"$"+parseFloat(n).toPrecision(4);}
      function row(c,up){
        var chg=parseFloat(c.price_change_percentage_24h||0);
        return\'<div class="bt-h84-mrow"><span class="bt-h84-mname">\'+c.symbol.toUpperCase()+\'</span>\'+
               \'<span class="bt-h84-mprice">\'+fmtPrice(c.current_price||0)+\'</span>\'+
               \'<span class="bt-h84-mchg \'+( chg>=0?"up":"down")+\'">\'+fmtP(chg)+\'</span></div>\';
      }
      fetch(rest).then(r=>r.json()).then(function(d){
        var coins=(d.crypto&&d.crypto.coins)?d.crypto.coins:[];
        if(!coins.length)return;
        var sorted=coins.slice().sort(function(a,b){return parseFloat(b.price_change_percentage_24h||0)-parseFloat(a.price_change_percentage_24h||0);});
        var gainers=sorted.slice(0,5);
        var losers=sorted.slice().reverse().slice(0,5);
        var gEl=document.getElementById("bt-h84-gainers");
        var lEl=document.getElementById("bt-h84-losers");
        if(gEl) gEl.innerHTML=gainers.map(function(c){return row(c,true);}).join("");
        if(lEl) lEl.innerHTML=losers.map(function(c){return row(c,false);}).join("");
      }).catch(function(){});
    })();
    </script>

    <!-- 3 Breaking Headlines -->
    <div class="bt-h84-headlines">
      <div class="bt-h84-sec-head">
        <span>📰 Breaking News</span>
        <a href="/financial-news/">All news →</a>
      </div>
      [fxlm_news_feed count="3" layout="list"]
    </div>

  </div>
</div>

<!-- ════════════════════════════════════════════════
     FOLD 3 — DESK READ
     Cross-market signal + CTA to /market-analysis/
════════════════════════════════════════════════ -->
<div class="bt-h84-desk">
  <div class="bt-h84-desk-inner">
    <div class="bt-h84-desk-head">
      <div>
        <div class="bt-h84-desk-eyebrow">AI Research Desk</div>
        <h2 class="bt-h84-desk-title">Cross-Market Signal</h2>
      </div>
      <a href="/market-analysis/" class="bt-btn-primary">Full Research Desk →</a>
    </div>
    [bt_cross_market_card]
  </div>
</div>

<!-- ════════════════════════════════════════════════
     FOLD 4 — EXPLORE
     3 Entry cards + Broker CTA strip
════════════════════════════════════════════════ -->
<div class="bt-h84-explore">
  <div class="bt-h84-explore-inner">
    <div class="bt-h84-explore-head">
      <h2>Explore BlockTicker</h2>
      <p>Deep data on every asset class — sourced, attributed, updated around the clock.</p>
    </div>
    <div class="bt-h84-explore-grid">

      <a href="/crypto-markets/" class="bt-h84-ecard bt-h84-ecard--crypto bt-card-reveal" style="--ri:0">
        <div class="bt-h84-ecard-icon">₿</div>
        <div class="bt-h84-ecard-body">
          <div class="bt-h84-ecard-title">Crypto Markets</div>
          <div class="bt-h84-ecard-desc">500+ coins · Live prices · Sparklines · Market cap · 24h/7d performance</div>
          <div class="bt-h84-ecard-sub">CoinGecko · Real-time</div>
        </div>
        <div class="bt-h84-ecard-arrow">→</div>
      </a>

      <a href="/forex-charts/" class="bt-h84-ecard bt-h84-ecard--forex bt-card-reveal" style="--ri:1">
        <div class="bt-h84-ecard-icon">💱</div>
        <div class="bt-h84-ecard-body">
          <div class="bt-h84-ecard-title">Forex Rates</div>
          <div class="bt-h84-ecard-desc">170+ currency pairs · ECB/Frankfurter · COT positioning · Sentiment data</div>
          <div class="bt-h84-ecard-sub">ECB · Frankfurter.app · CFTC COT</div>
        </div>
        <div class="bt-h84-ecard-arrow">→</div>
      </a>

      <a href="/dexscan/" class="bt-h84-ecard bt-h84-ecard--web3 bt-card-reveal" style="--ri:2">
        <div class="bt-h84-ecard-icon">✦</div>
        <div class="bt-h84-ecard-body">
          <div class="bt-h84-ecard-title">Web3 &amp; DeFi</div>
          <div class="bt-h84-ecard-desc">Live DEX pools · Token signals · L1/L2 ecosystems · DeFi TVL</div>
          <div class="bt-h84-ecard-sub">GeckoTerminal · Real-time</div>
        </div>
        <div class="bt-h84-ecard-arrow">→</div>
      </a>

    </div>
  </div>
</div>

<!-- Broker CTA Strip -->
<div class="fxlm-cta-strip">
  <div class="fxlm-cta-text"><strong>Start trading with a trusted broker</strong><span>Regulated. Instant account access. Commission-free on many assets.</span></div>
  <a href="/recommended-brokers/" class="fxlm-cta-btn">View Top Brokers →</a>
</div>

<!-- /wp:html -->

',
            ),

            // ── FOREX CHARTSOREX CHARTS ──
            'forex-charts' => array(
                'title' => 'Forex Charts',
                'slug'  => 'forex-charts',
                'content' => '
<!-- wp:html -->
[fxlm_breadcrumbs]
<div class="fxlm-page-header">
    <div class="bt-eyebrow bt-eyebrow-green">MARKETS / FOREX</div>
    <h1>Live Forex Charts</h1>
    <p>Real-time TradingView charts · Major pairs · Trading session guide · Institutional sentiment</p>
  </div>
[fxlm_search_bar]

<!-- Forex Session Clock -->
<div class="bt-fxsess-wrap">
  <div class="bt-fxsess-head">🕐 Active Trading Sessions</div>
  <div class="bt-fxsess-grid" id="bt-fxsess-grid">
    <div class="bt-fxsess-card" data-open="22" data-close="7" data-tz="Sydney">
      <div class="bt-fxsess-city">Sydney</div>
      <div class="bt-fxsess-time">22:00 – 07:00 UTC</div>
      <div class="bt-fxsess-pairs">AUD/USD · NZD/USD · AUD/JPY</div>
      <div class="bt-fxsess-status"></div>
    </div>
    <div class="bt-fxsess-card" data-open="0" data-close="9" data-tz="Tokyo">
      <div class="bt-fxsess-city">Tokyo</div>
      <div class="bt-fxsess-time">00:00 – 09:00 UTC</div>
      <div class="bt-fxsess-pairs">USD/JPY · EUR/JPY · AUD/JPY</div>
      <div class="bt-fxsess-status"></div>
    </div>
    <div class="bt-fxsess-card" data-open="7" data-close="16" data-tz="London">
      <div class="bt-fxsess-city">London</div>
      <div class="bt-fxsess-time">07:00 – 16:00 UTC</div>
      <div class="bt-fxsess-pairs">EUR/USD · GBP/USD · EUR/GBP · USD/CHF</div>
      <div class="bt-fxsess-status"></div>
    </div>
    <div class="bt-fxsess-card" data-open="12" data-close="21" data-tz="New York">
      <div class="bt-fxsess-city">New York</div>
      <div class="bt-fxsess-time">12:00 – 21:00 UTC</div>
      <div class="bt-fxsess-pairs">EUR/USD · USD/CAD · USD/JPY · GBP/USD</div>
      <div class="bt-fxsess-status"></div>
    </div>
  </div>
</div>

<!-- Key Levels Context -->
<div class="bt-fxlevels-wrap">
  <div class="bt-fxlevels-head">
    <span>📌 What to Watch Today</span>
    <span class="bt-fxlevels-note">Based on desk signals · Updated with each brief</span>
  </div>
  <div class="bt-fxlevels-grid">
    <div class="bt-fxlevel-card" style="--lc:var(--bt-accent)">
      <div class="bt-fxlevel-pair">EUR/USD</div>
      <div class="bt-fxlevel-context">ECB/Fed rate divergence pair. Watch 1.0800 support and 1.1000 resistance. Volatility spikes on CPI, NFP and FOMC days.</div>
      <div class="bt-fxlevel-tag">Major · Most Liquid</div>
    </div>
    <div class="bt-fxlevel-card" style="--lc:#10b981">
      <div class="bt-fxlevel-pair">GBP/USD</div>
      <div class="bt-fxlevel-context">BoE vs Fed divergence. Sensitive to UK inflation data and PMIs. Cable tends to trend strongly once it breaks key weekly levels.</div>
      <div class="bt-fxlevel-tag">Major · High Volatility</div>
    </div>
    <div class="bt-fxlevel-card" style="--lc:var(--bt-accent-warm)">
      <div class="bt-fxlevel-pair">USD/JPY</div>
      <div class="bt-fxlevel-context">BoJ intervention risk above 155. Tracks US 10yr yields closely. Safe-haven flows drive sharp reversals during risk-off events.</div>
      <div class="bt-fxlevel-tag">Major · Carry Trade</div>
    </div>
    <div class="bt-fxlevel-card" style="--lc:#a78bfa">
      <div class="bt-fxlevel-pair">USD/CHF</div>
      <div class="bt-fxlevel-context">Swiss franc is a safe-haven — USD/CHF falls during crises. SNB actively manages the franc; interventions are unannounced.</div>
      <div class="bt-fxlevel-tag">Major · Safe Haven</div>
    </div>
  </div>
</div>

<style>
.bt-fxsess-wrap{background:#0b0f1a;border:1px solid #1e2535;border-radius:0;padding:18px 20px;margin:20px 0}
.bt-fxsess-head{font-size:13px;font-weight:700;color:var(--bt-text);margin-bottom:12px;letter-spacing:.02em}
.bt-fxsess-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px}
.bt-fxsess-card{background:var(--bt-bg-elev);border:1px solid #1e2535;border-radius:0;padding:12px 14px;position:relative;transition:border-color .2s}
.bt-fxsess-card.active{border-color:var(--bt-accent);background:rgba(0,255,102,.04)}
.bt-fxsess-city{font-weight:700;font-size:14px;color:var(--bt-text);margin-bottom:3px}
.bt-fxsess-time{font-size:11px;color:var(--bt-text-3);font-family:monospace;margin-bottom:5px}
.bt-fxsess-pairs{font-size:11px;color:var(--bt-text-2)}
.bt-fxsess-status{position:absolute;top:10px;right:12px;width:8px;height:8px;border-radius:50%;background:var(--bt-text-4)}
.bt-fxsess-card.active .bt-fxsess-status{background:var(--bt-accent);box-shadow:0 0 6px var(--bt-accent)}
.bt-fxlevels-wrap{background:#0b0f1a;border:1px solid #1e2535;border-radius:0;padding:18px 20px;margin:16px 0}
.bt-fxlevels-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:6px}
.bt-fxlevels-head span:first-child{font-size:13px;font-weight:700;color:var(--bt-text)}
.bt-fxlevels-note{font-size:11px;color:var(--bt-text-3)}
.bt-fxlevels-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px}
.bt-fxlevel-card{background:var(--bt-bg-elev);border:1px solid #1e2535;border-left:3px solid var(--lc,var(--bt-accent));border-radius:0;padding:12px 14px}
.bt-fxlevel-pair{font-size:15px;font-weight:700;color:var(--lc,var(--bt-accent));margin-bottom:6px;font-family:monospace}
.bt-fxlevel-context{font-size:12px;color:var(--bt-text-2);line-height:1.6;margin-bottom:8px}
.bt-fxlevel-tag{font-size:10px;color:var(--bt-text-3);text-transform:uppercase;letter-spacing:.04em}
</style>
<script>
(function(){
  function updateSessions(){
    var now = new Date();
    var utcH = now.getUTCHours() + now.getUTCMinutes()/60;
    document.querySelectorAll(".bt-fxsess-card").forEach(function(c){
      var o = parseInt(c.dataset.open,10), cl = parseInt(c.dataset.close,10);
      var active = o < cl ? (utcH >= o && utcH < cl) : (utcH >= o || utcH < cl);
      c.classList.toggle("active", active);
    });
  }
  updateSessions();
  setInterval(updateSessions, 60000);
})();
</script>

<!-- Charts -->
<div class="bt-fxchart-block">
  <div class="bt-fxchart-meta">
    <div>
      <h2 class="bt-fxchart-title">EUR/USD</h2>
      <p class="bt-fxchart-desc">Euro / US Dollar — most traded forex pair globally (~28% of daily volume). Driven by ECB vs Fed policy divergence.</p>
    </div>
    <span class="fxlm-section-badge" style="align-self:flex-start">Live</span>
  </div>
<!-- /wp:html -->
<!-- wp:shortcode -->[fxlm_tradingview_chart symbol="FX:EURUSD" height="480"]<!-- /wp:shortcode -->
<!-- wp:html -->
</div>

<div class="bt-fxchart-block">
  <div class="bt-fxchart-meta">
    <div>
      <h2 class="bt-fxchart-title">GBP/USD</h2>
      <p class="bt-fxchart-desc">British Pound / US Dollar — "Cable". High volatility pair, sensitive to UK economic data. Tracks EUR/USD but diverges on BoE policy.</p>
    </div>
  </div>
<!-- /wp:html -->
<!-- wp:shortcode -->[fxlm_tradingview_chart symbol="FX:GBPUSD" height="420"]<!-- /wp:shortcode -->
<!-- wp:html -->
</div>

<div class="bt-fxchart-block">
  <div class="bt-fxchart-meta">
    <div>
      <h2 class="bt-fxchart-title">USD/JPY</h2>
      <p class="bt-fxchart-desc">US Dollar / Japanese Yen — key carry trade pair. Closely tracks US 10yr Treasury yields. BoJ intervention risk above 155.</p>
    </div>
  </div>
<!-- /wp:html -->
<!-- wp:shortcode -->[fxlm_tradingview_chart symbol="FX:USDJPY" height="420"]<!-- /wp:shortcode -->
<!-- wp:html -->
</div>

<div class="bt-fxchart-block">
  <div class="bt-fxchart-meta">
    <div>
      <h2 class="bt-fxchart-title">USD/CHF</h2>
      <p class="bt-fxchart-desc">US Dollar / Swiss Franc — safe-haven pair. Inverse correlation with risk appetite. SNB manages the franc aggressively at extremes.</p>
    </div>
  </div>
<!-- /wp:html -->
<!-- wp:shortcode -->[fxlm_tradingview_chart symbol="FX:USDCHF" height="420"]<!-- /wp:shortcode -->
<!-- wp:html -->
</div>

<!-- Sentiment teaser -->
<div class="bt-fxchart-sentiment-link">
  <div>
    <strong style="color:var(--bt-text)">📊 Institutional Positioning Data</strong>
    <p style="color:var(--bt-text-2) !important;margin:4px 0 0;font-size:13px">See how large speculators are positioned across all major pairs using CFTC Commitments of Traders data.</p>
  </div>
  <a href="' . home_url('/forex-sentiment/') . '" class="bt-fxchart-sent-btn">View Sentiment →</a>
</div>

<style>
.bt-fxchart-block{margin:28px 0}
.bt-fxchart-meta{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px;gap:12px}
.bt-fxchart-title{margin:0 0 4px;font-size:20px;color:var(--bt-text)}
.bt-fxchart-desc{margin:0;font-size:13px;color:var(--bt-text-3);line-height:1.5;max-width:600px}
.bt-fxchart-sentiment-link{display:flex;justify-content:space-between;align-items:center;background:#0f1117 !important;border:1px solid #1e2535;border-radius:0;padding:18px 20px;margin:28px 0;gap:16px;flex-wrap:wrap;color:#e4e8f1 !important}
.bt-fxchart-sent-btn{background:var(--bt-accent);color:#0b0f1a;padding:9px 18px;border-radius:0;font-weight:700;font-size:13px;text-decoration:none;white-space:nowrap}
.bt-fxchart-sent-btn:hover{background:var(--bt-accent);color:#0b0f1a}
</style>
<!-- /wp:html -->
<!-- wp:shortcode -->[fxlm_newsletter]<!-- /wp:shortcode -->
',
            ),

            // ── FOREX CLIENT SENTIMENT ──
            'forex-sentiment' => array(
                'title' => 'Forex Client Sentiment',
                'slug'  => 'forex-sentiment',
                'content' => '
<!-- wp:html -->
[fxlm_breadcrumbs]
<div class="fxlm-page-header">
    <h1>📊 Forex Client Sentiment</h1>
    <p>See how retail traders are positioned across major currency pairs. Sentiment is a proven contrarian indicator — when positioning reaches extremes, reversals often follow.</p>
</div>
<!-- /wp:html -->
<!-- wp:shortcode -->[bt_forex_sentiment]<!-- /wp:shortcode -->
<!-- wp:html -->
<div class="fxlm-section-header" style="margin-top:40px"><h2>📈 Live Charts</h2><span class="fxlm-section-badge">TradingView</span></div>
<!-- /wp:html -->
<!-- wp:shortcode -->[fxlm_tradingview_chart symbol="FX:EURUSD" height="450"]<!-- /wp:shortcode -->
<!-- wp:html -->
<h3 style="margin-top:32px;color:var(--bt-text)">Understanding Sentiment</h3>
<p style="color:var(--bt-text-2);line-height:1.7">Client sentiment data reveals the <strong>percentage of retail traders</strong> currently long or short on a pair. It is widely used by institutional traders as a contrarian signal — when 80%+ of retail are long on EUR/USD, professionals often look for shorts, expecting a squeeze. Conversely, extreme short positioning can signal a bounce is coming.</p>
<p style="color:var(--bt-text-2);line-height:1.7">Sentiment alone should never drive trades. Combine it with technical analysis, fundamental catalysts, and macroeconomic context for higher-probability setups.</p>
<!-- /wp:html -->
<!-- wp:shortcode -->[fxlm_newsletter]<!-- /wp:shortcode -->
',
            ),

            // ── FOREX GUIDE (What is Forex?) ──
            'forex-guide' => array(
                'title' => 'Forex Trading Guide',
                'slug'  => 'forex-guide',
                'content' => '
<!-- wp:html -->
[fxlm_breadcrumbs]
<div class="fxlm-page-header">
    <h1>📚 Forex Trading Guide</h1>
    <p>Everything you need to know about the world’s largest financial market — $7.5 trillion traded daily.</p>
</div>

<div class="bt-fxguide-wrap">

<div class="bt-fxguide-cards">
    <a href="#what-is-forex" class="bt-fxguide-card" style="text-decoration:none">
        <div class="bt-fxguide-icon">💱</div>
        <h3>What is forex trading?</h3>
        <p>The buying and selling of currency pairs — betting that one currency will strengthen against another. It is the largest, most liquid market in the world, open 24 hours a day, five days a week.</p>
        <span class="bt-fxguide-btn">Read more ▸</span>
    </a>
    <a href="#why-forex" class="bt-fxguide-card" style="text-decoration:none">
        <div class="bt-fxguide-icon">🚀</div>
        <h3>Why trade forex?</h3>
        <p>Forex offers 24/5 market hours, tight spreads, high liquidity, and the ability to trade both rising and falling markets. Leverage lets traders control larger positions with smaller capital — though it amplifies risk.</p>
        <span class="bt-fxguide-btn">Read more ▸</span>
    </a>
    <a href="#how-to-trade" class="bt-fxguide-card" style="text-decoration:none">
        <div class="bt-fxguide-icon">📈</div>
        <h3>How to trade forex</h3>
        <p>Start with a regulated broker and a demo account. Learn to read charts, understand the economic calendar, and apply strict risk management — never risk more than 1–2% per trade.</p>
        <span class="bt-fxguide-btn">Read more ▸</span>
    </a>
</div>

<section id="what-is-forex" class="bt-fxguide-section">
    <h2>💱 What is forex trading?</h2>
    <p>Foreign exchange (forex or FX) is a decentralised global marketplace for trading national currencies. Unlike stock exchanges, there is no central location — trades happen electronically between banks, brokers, and traders worldwide. Currency pairs like <strong>EUR/USD</strong> quote the value of one currency relative to another. When EUR/USD is quoted at 1.0850, it means 1 Euro equals 1.0850 US Dollars.</p>
    <p>The forex market operates 24 hours a day from Sunday evening to Friday evening, following the sun across major financial centres — Sydney, Tokyo, London, New York. Daily trading volume exceeds <strong>$7.5 trillion</strong>, dwarfing every other market combined.</p>
</section>

<section id="why-forex" class="bt-fxguide-section">
    <h2>🚀 Why trade forex?</h2>
    <ul>
        <li><strong>24-hour market:</strong> Trade around your schedule, not Wall Street’s.</li>
        <li><strong>Deep liquidity:</strong> Enter and exit positions at the price you expect, even in size.</li>
        <li><strong>Low transaction costs:</strong> Tight spreads on major pairs, often under 1 pip.</li>
        <li><strong>Leverage:</strong> Control large positions with small capital (amplifies losses too).</li>
        <li><strong>Bidirectional:</strong> Profit from both rising and falling markets.</li>
    </ul>
</section>

<section id="how-to-trade" class="bt-fxguide-section">
    <h2>📈 How to trade forex — a step-by-step guide</h2>
    <ol>
        <li><strong>Choose a regulated broker:</strong> Look for FCA, ASIC, CySEC, or NFA regulation. Never trade with unregulated brokers.</li>
        <li><strong>Open a demo account:</strong> Practice for at least 3 months before risking real money.</li>
        <li><strong>Learn technical analysis:</strong> Study chart patterns, support/resistance, moving averages, and RSI.</li>
        <li><strong>Follow the economic calendar:</strong> Central bank meetings, CPI, and employment data drive major moves.</li>
        <li><strong>Develop a risk management plan:</strong> Risk 1–2% per trade, set stop-losses before entry, never move stops against you.</li>
        <li><strong>Keep a trading journal:</strong> Track every trade — entry, exit, reasoning, result — and review weekly.</li>
    </ol>
</section>

<div class="fxlm-signals-disclaimer" style="margin-top:24px">
    <strong>⚠ Risk warning:</strong> Forex trading involves substantial risk of loss. The majority of retail traders lose money. Only trade with capital you can afford to lose, and seek independent financial advice if uncertain.
</div>

</div>
<!-- /wp:html -->
<!-- wp:shortcode -->[bt_forex_sentiment]<!-- /wp:shortcode -->
<!-- wp:shortcode -->[fxlm_newsletter]<!-- /wp:shortcode -->
',
            ),

            // ── CRYPTO MARKETS ──
            'crypto-markets' => array(
                'title' => 'Crypto Markets',
                'slug'  => 'crypto-markets',
                'content' => '
<!-- wp:html -->
[fxlm_breadcrumbs]
<div class="bt-dash-wrap">

  <div class="bt-panel-head">
    <span class="bt-panel-num">01</span>
    <span class="bt-panel-title">Crypto Markets</span>
    <span class="bt-panel-live">Live · CoinGecko</span>
  </div>

  <!-- Price tiles row -->
<!-- /wp:html -->
<!-- wp:shortcode -->[fxlm_price_cards coins="bitcoin,ethereum,solana,ripple,cardano,dogecoin,binancecoin,polkadot,chainlink,avalanche-2"]<!-- /wp:shortcode -->
<!-- wp:html -->

  <!-- v119.28.26 — Removed Fear & Greed and Gainers/Losers widgets here.
       They have dedicated pages (/fear-greed/ and /gainers-losers/). -->

  <!-- Full table panel -->
  <div class="bt-panel-head" style="margin-top:16px">
    <span class="bt-panel-num">02</span>
    <span class="bt-panel-title">All Cryptocurrencies</span>
    <span class="bt-panel-live">500+ coins</span>
  </div>
  <div class="bt-widget">
    <div class="bt-widget-head">
      <span class="bt-widget-title">Live Prices</span>
      <div class="bt-widget-filter">
        <button class="bt-wf-btn active" data-filter="all">All Coins</button>
        <button class="bt-wf-btn" data-filter="top100">Top 100</button>
        <button class="bt-wf-btn" data-filter="defi">DeFi</button>
        <button class="bt-wf-btn" data-filter="layer1">Layer 1</button>
      </div>
    </div>
<!-- /wp:html -->
<!-- wp:shortcode -->[fxlm_crypto_full_table]<!-- /wp:shortcode -->
<!-- wp:html -->
  </div>

  <!-- Charts panel -->
  <div class="bt-panel-head" style="margin-top:24px">
    <span class="bt-panel-num">03</span>
    <span class="bt-panel-title">Price Charts</span>
    <span class="bt-panel-live">TradingView</span>
  </div>
  <div class="bt-panel-2col">
    <div class="bt-widget">
      <div class="bt-widget-head"><span class="bt-widget-title">Bitcoin / USD</span></div>
<!-- /wp:html -->
<!-- wp:shortcode -->[fxlm_tradingview_chart symbol="BINANCE:BTCUSDT" height="360"]<!-- /wp:shortcode -->
<!-- wp:html -->
    </div>
    <div class="bt-widget">
      <div class="bt-widget-head"><span class="bt-widget-title">Ethereum / USD</span></div>
<!-- /wp:html -->
<!-- wp:shortcode -->[fxlm_tradingview_chart symbol="BINANCE:ETHUSDT" height="360"]<!-- /wp:shortcode -->
<!-- wp:html -->
    </div>
  </div>

  <!-- Correlation heatmap -->
  <div class="bt-panel-head" style="margin-top:24px">
    <span class="bt-panel-num">04</span>
    <span class="bt-panel-title">Correlation Matrix</span>
  </div>
  <div class="bt-widget">
    <div class="bt-widget-head"><span class="bt-widget-title">Asset Correlation · 30 Days</span></div>
    <div class="bt-widget-body">
<!-- /wp:html -->
<!-- wp:shortcode -->[bt_correlation_heatmap]<!-- /wp:shortcode -->
<!-- wp:html -->
    </div>
  </div>

</div>
<!-- /wp:html -->
<!-- wp:shortcode -->[fxlm_newsletter style="banner"]<!-- /wp:shortcode -->
',
            ),

            // ── LANDING REVAMP ──
            // TODO: Once approved, set as front page via Settings → Reading.
            // Until then, preview at /landing-revamp/ without touching live home page.
            'landing-revamp' => array(
                'title'   => 'Landing Revamp',
                'slug'    => 'landing-revamp',
                'content' => '<!-- wp:shortcode -->[blockticker_landing]<!-- /wp:shortcode -->',
            ),

            // ── SIGNAL ARCHIVE ──
            'signal-archive' => array(
                'title'   => 'Signal Archive',
                'slug'    => 'signal-archive',
                'content' => '<!-- wp:shortcode -->[blockticker_signal_archive]<!-- /wp:shortcode -->',
            ),

            // ── DESK BRIEF ──
            'desk-brief' => array(
                'title'   => 'Desk Brief',
                'slug'    => 'desk-brief',
                'content' => '<!-- wp:shortcode -->[blockticker_desk_brief]<!-- /wp:shortcode -->',
            ),

            // ── TRADING SIGNALS ──
            'trading-signals' => array(
                'title' => 'Trading Signals',
                'slug'  => 'trading-signals',
                'content' => '
<!-- wp:html -->
[fxlm_breadcrumbs]
<div class="bt-dash-wrap">

  <!-- v119.28.31 — Hero header (token-driven) -->
  <div class="bt-hero">
    <div class="bt-hero__eyebrow">Intelligence / Signals</div>
    <h1 class="bt-hero__title">Cross-Market Signals</h1>
    <p class="bt-hero__lede">Editorial commentary curated from FXStreet, DailyFX, ForexLive, and CoinDesk &mdash; not algorithmic. Every signal is sentiment-tagged, time-stamped, and scored against a rolling 30-day track record so you can see exactly which sources have been right.</p>
  </div>

  <!-- Source disclosure -->
  <div class="bt-sig-source-banner">
    <strong>About these signals:</strong> Curated from editorial analysis published by
    <strong>FXStreet</strong>, <strong>DailyFX</strong>, <strong>ForexLive</strong>, and
    <strong>CoinDesk</strong> &mdash; established financial media. These are editorial commentary and
    analysis, not proprietary algorithmic signals. Always do your own research before trading.
    <a href="' . home_url('/editorial-policy/') . '">Editorial Policy &rarr;</a>
  </div>

  <!-- Section 01 — Track record -->
  <div class="bt-section">
    <div class="bt-section__head">
      <span class="bt-section__num">01</span>
      <h2 class="bt-section__title">Signal Track Record</h2>
      <span class="bt-section__meta bt-section__meta--live">30-day rolling window</span>
    </div>
<!-- /wp:html -->
<!-- wp:shortcode -->[bt_signal_track_record window="30"]<!-- /wp:shortcode -->
<!-- wp:html -->
  </div>

  <!-- Section 02 — Live signal feed (2-col layout) -->
  <div class="bt-section">
    <div class="bt-section__head">
      <span class="bt-section__num">02</span>
      <h2 class="bt-section__title">Live Signal Feed</h2>
      <span class="bt-section__meta bt-section__meta--live">Updated hourly</span>
    </div>

    <div class="bt-sig-filters">
      <button class="bt-sig-filter-btn active" data-cls="all" data-sent="all">All Assets</button>
      <button class="bt-sig-filter-btn" data-cls="forex" data-sent="all">Forex</button>
      <button class="bt-sig-filter-btn" data-cls="crypto" data-sent="all">Crypto</button>
      <button class="bt-sig-filter-btn" data-cls="all" data-sent="bullish">Bullish</button>
      <button class="bt-sig-filter-btn" data-cls="all" data-sent="bearish">Bearish</button>
    </div>

    <div class="bt-signals-layout">
      <div class="bt-signals-layout__feed">
<!-- /wp:html -->
<!-- wp:shortcode -->[fxlm_signals_feed count="30"]<!-- /wp:shortcode -->
<!-- wp:html -->
      </div>

      <aside class="bt-signals-layout__side">
        <div class="bt-side-card">
          <h4 class="bt-side-card__title">Sources Tracked</h4>
          <ul>
            <li>FXStreet &mdash; FX analysis</li>
            <li>DailyFX &mdash; macro &amp; FX</li>
            <li>ForexLive &mdash; intraday FX</li>
            <li>CoinDesk Markets &mdash; crypto</li>
            <li>BabyPips &mdash; education</li>
          </ul>
        </div>

        <div class="bt-side-card">
          <h4 class="bt-side-card__title">How to read these</h4>
          <p>Each card shows direction (bullish / bearish), source, timestamp, and the editorial rationale in one paragraph. The 4px sidebar on the left of each card is colored by sentiment.</p>
          <p>Use the filter row above to narrow by asset class or sentiment. Track-record stats apply to the same source set.</p>
        </div>

        <div class="bt-side-card">
          <h4 class="bt-side-card__title">Want history?</h4>
          <p>Browse every signal we have ever published, with outcome (win / loss / open) and R-multiple where applicable.</p>
          <a href="/signal-archive/" class="bt-side-card__cta">Browse signal archive &rarr;</a>
        </div>
      </aside>
    </div>
  </div>

  <!-- Section 03 — AI Market Analysis -->
  <div class="bt-section">
    <div class="bt-section__head">
      <span class="bt-section__num">03</span>
      <h2 class="bt-section__title">AI Market Analysis</h2>
      <span class="bt-section__meta">Updated daily &middot; 08:00 UTC</span>
    </div>
    <div class="bt-grid bt-grid--2-fixed">
      <div>
<!-- /wp:html -->
<!-- wp:shortcode -->[bt_cross_market_card]<!-- /wp:shortcode -->
<!-- wp:html -->
      </div>
      <div>
<!-- /wp:html -->
<!-- wp:shortcode -->[bt_intelligence_brief compact="true"]<!-- /wp:shortcode -->
<!-- wp:html -->
      </div>
    </div>
  </div>

  <!-- Section 04 — Signal Charts -->
  <div class="bt-section">
    <div class="bt-section__head">
      <span class="bt-section__num">04</span>
      <h2 class="bt-section__title">Signal Charts</h2>
      <span class="bt-section__meta bt-section__meta--live">TradingView &middot; Live</span>
    </div>
    <div class="bt-panel-3col">
      <div class="bt-widget">
        <div class="bt-widget-head">
          <span class="bt-widget-title">EUR/USD</span>
          <div class="bt-widget-filter">
            <button class="bt-wf-btn active">D</button>
            <button class="bt-wf-btn">4H</button>
            <button class="bt-wf-btn">1H</button>
          </div>
        </div>
<!-- /wp:html -->
<!-- wp:shortcode -->[fxlm_tradingview_chart symbol="FX:EURUSD" height="320" hide_side="1" hide_top="1"]<!-- /wp:shortcode -->
<!-- wp:html -->
      </div>
      <div class="bt-widget">
        <div class="bt-widget-head">
          <span class="bt-widget-title">BTC/USD</span>
          <div class="bt-widget-filter">
            <button class="bt-wf-btn active">D</button>
            <button class="bt-wf-btn">4H</button>
            <button class="bt-wf-btn">1H</button>
          </div>
        </div>
<!-- /wp:html -->
<!-- wp:shortcode -->[fxlm_tradingview_chart symbol="BINANCE:BTCUSDT" height="320" hide_side="1" hide_top="1"]<!-- /wp:shortcode -->
<!-- wp:html -->
      </div>
      <div class="bt-widget">
        <div class="bt-widget-head">
          <span class="bt-widget-title">GBP/USD</span>
          <div class="bt-widget-filter">
            <button class="bt-wf-btn active">D</button>
            <button class="bt-wf-btn">4H</button>
            <button class="bt-wf-btn">1H</button>
          </div>
        </div>
<!-- /wp:html -->
<!-- wp:shortcode -->[fxlm_tradingview_chart symbol="FX:GBPUSD" height="320" hide_side="1" hide_top="1"]<!-- /wp:shortcode -->
<!-- wp:html -->
      </div>
    </div>
  </div>

  <!-- v119.28.26 — Signal filter: uses data-cls + data-sent attributes -->
  <script>
  (function(){
    var btns  = document.querySelectorAll(".bt-sig-filter-btn");
    if (!btns.length) return;
    var apply = function(activeBtn){
      var wantCls  = activeBtn.getAttribute("data-cls")  || "all";
      var wantSent = activeBtn.getAttribute("data-sent") || "all";
      var items = document.querySelectorAll(".fxlm-signal-v2, .fxlm-signal-item, .fxlm-signal-card, .bt-sig-card, [data-cls][data-sent]");
      items.forEach(function(item){
        var cls  = (item.getAttribute("data-cls")  || "all").toLowerCase();
        var sent = (item.getAttribute("data-sent") || "all").toLowerCase();
        if (!item.hasAttribute("data-cls")) {
          var t = (item.textContent || "").toLowerCase();
          if (/eur|gbp|usd|jpy|chf|aud|cad|nzd|forex/.test(t)) cls = "forex";
          else if (/btc|eth|sol|crypto|bitcoin|ethereum/.test(t)) cls = "crypto";
          if (/bullish|buy|long|rally|upside/.test(t)) sent = "bullish";
          else if (/bearish|sell|short|drop|downside/.test(t)) sent = "bearish";
        }
        var clsOk  = wantCls  === "all" || cls === wantCls  || cls === "all";
        var sentOk = wantSent === "all" || sent === wantSent;
        item.style.display = (clsOk && sentOk) ? "" : "none";
      });
      btns.forEach(function(b){ b.classList.remove("active"); });
      activeBtn.classList.add("active");
    };
    btns.forEach(function(btn){
      btn.addEventListener("click", function(){ apply(btn); });
    });
  })();
  </script>

</div>
<!-- /wp:html -->
<!-- wp:shortcode -->[fxlm_newsletter style="banner"]<!-- /wp:shortcode -->
',
            ),

            

            // ── FINANCIAL NEWS ──
            'financial-news' => array(
                'title' => 'Financial News',
                'slug'  => 'financial-news',
                'content' => '
<!-- wp:html -->
[fxlm_breadcrumbs]
<div class="bt-dash-wrap">

  <div class="fxlm-page-header">
    <div class="bt-eyebrow bt-eyebrow-green">INTELLIGENCE / NEWS</div>
    <h1>Financial News</h1>
    <p>14+ curated sources · Crypto, Forex, DeFi &amp; Markets · Hourly updates</p>
  </div>

  <div class="bt-panel-head">
    <span class="bt-panel-num">01</span>
    <span class="bt-panel-title">News &amp; Analysis</span>
    <span class="bt-panel-live">14+ sources · Hourly</span>
  </div>

  [fxlm_search_bar]
  [fxlm_breaking_news]

  <div style="display:flex;justify-content:space-between;align-items:center;margin:16px 0 12px">
    <div class="fxlm-news-tabs" id="fxlm-news-tabs" style="margin:0">
      <button class="fxlm-tab active" data-cat="">All News</button>
      <button class="fxlm-tab" data-cat="Crypto News">Crypto</button>
      <button class="fxlm-tab" data-cat="Forex News">Forex</button>
      <button class="fxlm-tab" data-cat="DeFi & Web3">DeFi &amp; Web3</button>
    </div>
    <span style="font-size:10px;font-family:var(--bt-font-mono);color:var(--bt-text-3);letter-spacing:.12em;text-transform:uppercase">HOURLY UPDATE</span>
  </div>

  <div class="bt-widget">
    <div class="bt-widget-head">
      <span class="bt-widget-title">Latest News</span>
      <span style="font-size:11px;color:var(--bt-text-3)">Auto-aggregated from CoinDesk, The Block, FXStreet &amp; more</span>
    </div>
<!-- /wp:html -->
<!-- wp:shortcode -->[fxlm_news_feed count="31" layout="premium"]<!-- /wp:shortcode -->
<!-- wp:html -->
  </div>

</div>
<!-- /wp:html -->
<!-- wp:shortcode -->[fxlm_newsletter]<!-- /wp:shortcode -->
',
            ),

            // ── MARKET THREADS (v56) ──
            'threads' => array(
                'title' => 'Daily Market Threads',
                'slug'  => 'threads',
                'content' => '
<!-- wp:html -->
[fxlm_breadcrumbs]
<div class="fxlm-page-header">
  <h1>𝕏 Daily Market Threads</h1>
  <p>Ready-to-read Twitter threads distilled from our AI daily intelligence reports. Institutional analysis, live data, no filler — updated daily.</p>
</div>
<!-- /wp:html -->
<!-- wp:shortcode -->[bt_threads_archive limit="30"]<!-- /wp:shortcode -->
',
            ),

            // ── MARKET ANALYSIS / AUTOBLOG ──
            'market-analysis' => array(
                'title' => 'AI Market Analysis',
                'slug'  => 'market-analysis',
                'content' => '
<!-- wp:html -->
[fxlm_breadcrumbs]
<!-- /wp:html -->
<!-- wp:shortcode -->[bt_audience_split]<!-- /wp:shortcode -->
<!-- wp:shortcode -->[bt_page_nav items="Today\'s Desk|desk|Track Record|track|30-Day Archive|archive|Newsletter|newsletter"]<!-- /wp:shortcode -->
<!-- wp:html -->
<section id="desk">
<!-- /wp:html -->
<!-- wp:shortcode -->[bt_analysis_desk notes_count="14" notes_cats="market-analysis,crypto-news,forex-news"]<!-- /wp:shortcode -->
<!-- wp:html -->
</section>
<section id="track" class="bt-desk-track-band">
<!-- /wp:html -->
<!-- wp:shortcode -->[bt_verdict_track_record show_recent="10"]<!-- /wp:shortcode -->
<!-- wp:html -->
</section>
<section id="archive" class="bt-desk-archive-link-band">
  <a href="/verdict-archive/" class="bt-arc-entry-cta">View full 30-day verdict archive →</a>
</section>
<!-- /wp:html -->
<!-- wp:shortcode -->[bt_methodology_card]<!-- /wp:shortcode -->
<!-- wp:html -->
<section id="newsletter" class="bt-desk-newsletter-band">
<!-- /wp:html -->
<!-- wp:shortcode -->[fxlm_newsletter style="banner"]<!-- /wp:shortcode -->
<!-- wp:html -->
</section>
<!-- /wp:html -->
<!-- wp:shortcode -->[bt_newsletter_exit_intent]<!-- /wp:shortcode -->
',
            ),

            // ── VERDICT ARCHIVE (v87) ──
            'verdict-archive' => array(
                'title'   => 'Verdict Archive — 30-Day Desk Record',
                'slug'    => 'verdict-archive',
                'content' => '
<!-- wp:html -->
[fxlm_breadcrumbs]
<!-- /wp:html -->
<!-- wp:shortcode -->[bt_verdict_archive days="30"]<!-- /wp:shortcode -->
',
            ),

            // ── ECONOMIC CALENDAR ──
            'economic-calendar' => array(
                'title' => 'Economic Calendar',
                'slug'  => 'economic-calendar',
                'content' => '
<!-- wp:html -->
[fxlm_breadcrumbs]
<div class="bt-dash-wrap">

  <div class="bt-panel-head">
    <span class="bt-panel-num">01</span>
    <span class="bt-panel-title">Economic Calendar</span>
    <span class="bt-panel-live">TradingView · Live</span>
  </div>

  <div class="bt-widget">
    <div class="bt-widget-head">
      <span class="bt-widget-title">Upcoming Events</span>
      <div class="bt-widget-filter">
        <button class="bt-wf-btn active">All Countries</button>
        <button class="bt-wf-btn">All Impact</button>
      </div>
    </div>
    <div class="bt-tv-dark-wrap">
      <div class="tradingview-widget-container">
        <div class="tradingview-widget-container__widget"></div>
        <script type="text/javascript" src="https://s3.tradingview.com/external-embedding/embed-widget-events.js" async>
        {"colorTheme":"dark","isTransparent":false,"width":"100%","height":"640","locale":"en","importanceFilter":"-1,0,1","currencyFilter":"USD,EUR,GBP,JPY,CHF,AUD,CAD,NZD,CNY,BTC"}
        </script>
      </div>
    </div>
  </div>

  <!-- Impact guide -->
  <div class="bt-panel-head" style="margin-top:24px">
    <span class="bt-panel-num">02</span>
    <span class="bt-panel-title">How to Read the Calendar</span>
  </div>
  <div class="bt-panel-3col">
    <div class="bt-widget">
      <div class="bt-widget-body">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
          <span class="bt-cal-impact high" style="width:12px;height:12px;border-radius:50%;background:#ef4444;display:inline-block"></span>
          <strong style="color:#e4e8f1;font-size:14px">High Impact</strong>
        </div>
        <p style="font-size:13px;color:var(--bt-text-3);line-height:1.6;margin:0">Events likely to move markets significantly: NFP, CPI, FOMC decisions, GDP prints. Watch these closely — price can move 50+ pips in seconds.</p>
      </div>
    </div>
    <div class="bt-widget">
      <div class="bt-widget-body">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
          <span style="width:12px;height:12px;border-radius:50%;background:var(--bt-accent-warm);display:inline-block"></span>
          <strong style="color:#e4e8f1;font-size:14px">Medium Impact</strong>
        </div>
        <p style="font-size:13px;color:var(--bt-text-3);line-height:1.6;margin:0">Can cause noticeable volatility: PMI, retail sales, building permits. Usually move markets 10–30 pips depending on deviation from forecast.</p>
      </div>
    </div>
    <div class="bt-widget">
      <div class="bt-widget-body">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
          <span style="width:12px;height:12px;border-radius:50%;background:var(--bt-text-3);display:inline-block"></span>
          <strong style="color:#e4e8f1;font-size:14px">Low Impact</strong>
        </div>
        <p style="font-size:13px;color:var(--bt-text-3);line-height:1.6;margin:0">Minor events unlikely to move markets unless they deviate significantly from forecasts. Safe to trade around in most conditions.</p>
      </div>
    </div>
  </div>

</div>
<!-- /wp:html -->
',
            ),

            // ── ALERTS HUB (v119.9) ── unified price + news alert manager
            'alerts' => array(
                'title' => 'Alerts',
                'slug'  => 'alerts',
                'content' => '
<!-- wp:html -->
[fxlm_breadcrumbs]
<div class="bt-dash-wrap">

  <div class="bt-panel-head">
    <span class="bt-panel-num">01</span>
    <span class="bt-panel-title">Alerts</span>
    <span class="bt-panel-live">Real-time</span>
  </div>

  <div style="max-width:760px;margin:24px 0 32px">
    <p style="font-size:14px;line-height:1.6;color:var(--bt-text-2);margin:0">Get notified the moment markets move or news breaks. Set <strong>price alerts</strong> on any crypto or forex pair, or <strong>news alerts</strong> by keyword — delivered by email, instantly or as a digest.</p>
  </div>
<!-- /wp:html -->
<!-- wp:shortcode -->[bt_alert_hub]<!-- /wp:shortcode -->
<!-- wp:html -->

</div>
<!-- /wp:html -->
',
            ),

            // ── PERFORMANCE / TRACK RECORD (v119.11) ── trust & E-E-A-T
            'performance' => array(
                'title' => 'Performance',
                'slug'  => 'performance',
                'content' => '
<!-- wp:html -->
[fxlm_breadcrumbs]
<!-- /wp:html -->
<!-- wp:shortcode -->[bt_performance_dashboard]<!-- /wp:shortcode -->
',
            ),

            // ── DASHBOARD / PERSONALIZED LAYOUTS (v119.15) ── 3 preset layouts
            'dashboard' => array(
                'title' => 'My Dashboard',
                'slug'  => 'dashboard',
                'content' => '
<!-- wp:html -->
[fxlm_breadcrumbs]
<div class="bt-dash-wrap">
  <div class="bt-panel-head">
    <span class="bt-panel-num">01</span>
    <span class="bt-panel-title">My Dashboard</span>
    <span class="bt-panel-live">Personalized</span>
  </div>
  <div style="max-width:760px;margin:24px 0 24px">
    <p style="font-size:14px;line-height:1.6;color:var(--bt-text-2);margin:0">Pick a layout that matches how you trade. <strong>Markets Focus</strong> for cross-market overview, <strong>Watchlist Focus</strong> for your tracked assets, <strong>Signals Focus</strong> for active traders. Your choice is saved automatically when signed in.</p>
  </div>
<!-- /wp:html -->
<!-- wp:shortcode -->[bt_dashboard]<!-- /wp:shortcode -->
<!-- wp:html -->
</div>
<!-- /wp:html -->
',
            ),

            // ── SCREENERS (v119.16) ── saved filter combinations
            'screeners' => array(
                'title' => 'Screeners',
                'slug'  => 'screeners',
                'content' => '
<!-- wp:html -->
[fxlm_breadcrumbs]
<div class="bt-dash-wrap">
  <div class="bt-panel-head">
    <span class="bt-panel-num">01</span>
    <span class="bt-panel-title">Screeners</span>
    <span class="bt-panel-live">Saved filters</span>
  </div>
  <div style="max-width:760px;margin:24px 0 24px">
    <p style="font-size:14px;line-height:1.6;color:var(--bt-text-2);margin:0">Build and save filter combinations to quickly re-check market conditions you care about. Filter the top-500 by market cap, 24h move, volume and category — save up to 10 named screeners and re-run them anytime.</p>
  </div>
<!-- /wp:html -->
<!-- wp:shortcode -->[bt_screeners]<!-- /wp:shortcode -->
<!-- wp:html -->
</div>
<!-- /wp:html -->
',
            ),

            // ── FOLLOWING (v119.17) ── follow list + personalized news
            'following' => array(
                'title' => 'Following',
                'slug'  => 'following',
                'content' => '
<!-- wp:html -->
[fxlm_breadcrumbs]
<div class="bt-dash-wrap">
  <div class="bt-panel-head">
    <span class="bt-panel-num">01</span>
    <span class="bt-panel-title">Following</span>
    <span class="bt-panel-live">Personalised feed</span>
  </div>
  <div style="max-width:760px;margin:24px 0 24px">
    <p style="font-size:14px;line-height:1.6;color:var(--bt-text-2);margin:0">Follow the assets, news sources and signal authors you care about. Your follows drive the <strong>Personalised News</strong> widget on your dashboard and can be reused anywhere with the <code>[bt_personalized_news]</code> shortcode.</p>
  </div>
<!-- /wp:html -->
<!-- wp:shortcode -->[bt_following]<!-- /wp:shortcode -->
<!-- wp:html -->
</div>
<!-- /wp:html -->
',
            ),




            // ── TOOLS (NEW in v6) ──
            'tools' => array(
                'title' => 'Tools',
                'slug'  => 'tools',
                'content' => '
<!-- wp:html -->
[fxlm_breadcrumbs]
<div class="bt-dash-wrap">

  <div class="bt-panel-head">
    <span class="bt-panel-num">01</span>
    <span class="bt-panel-title">Tools &amp; Converter</span>
    <span class="bt-panel-live">Real-time</span>
  </div>

  <!-- Converter + F&G -->
  <div class="bt-panel-2col" style="margin-bottom:24px">
    <div class="bt-widget">
      <div class="bt-widget-head">
        <span class="bt-widget-title">💱 Currency Converter</span>
        <span style="font-size:11px;color:var(--bt-text-3)">Crypto &amp; Forex</span>
      </div>
      <div class="bt-widget-body">
<!-- /wp:html -->
<!-- wp:shortcode -->[fxlm_crypto_converter]<!-- /wp:shortcode -->
<!-- wp:html -->
      </div>
    </div>
    <div class="bt-widget">
      <div class="bt-widget-head">
        <span class="bt-widget-title">😨 Fear &amp; Greed Index</span>
        <span style="font-size:11px;color:var(--bt-accent)">● LIVE</span>
      </div>
      <div class="bt-widget-body">
<!-- /wp:html -->
<!-- wp:shortcode -->[fxlm_fear_greed]<!-- /wp:shortcode -->
<!-- wp:html -->
      </div>
    </div>
  </div>

  <!-- Charts -->
  <div class="bt-panel-head">
    <span class="bt-panel-num">02</span>
    <span class="bt-panel-title">Price Charts</span>
    <span class="bt-panel-live">TradingView · Live</span>
  </div>
  <div class="bt-panel-2col">
    <div class="bt-widget">
      <div class="bt-widget-head">
        <span class="bt-widget-title">BTC / USD</span>
      </div>
<!-- /wp:html -->
<!-- wp:shortcode -->[fxlm_tradingview_chart symbol="BINANCE:BTCUSDT" height="340"]<!-- /wp:shortcode -->
<!-- wp:html -->
    </div>
    <div class="bt-widget">
      <div class="bt-widget-head">
        <span class="bt-widget-title">EUR / USD</span>
      </div>
<!-- /wp:html -->
<!-- wp:shortcode -->[fxlm_tradingview_chart symbol="FX:EURUSD" height="340"]<!-- /wp:shortcode -->
<!-- wp:html -->
    </div>
  </div>

  <!-- Portfolio + Alerts -->
  <div class="bt-panel-head" style="margin-top:24px">
    <span class="bt-panel-num">03</span>
    <span class="bt-panel-title">Portfolio &amp; Alerts</span>
  </div>
  <div class="bt-panel-2col">
    <div class="bt-widget">
      <div class="bt-widget-head"><span class="bt-widget-title">My Portfolio</span><span style="font-size:11px;color:var(--bt-text-3)">Browser storage</span></div>
      <div class="bt-widget-body">
<!-- /wp:html -->
<!-- wp:shortcode -->[fxlm_portfolio]<!-- /wp:shortcode -->
<!-- wp:html -->
      </div>
    </div>
    <div class="bt-widget">
      <div class="bt-widget-head"><span class="bt-widget-title">Price Alerts</span><span style="font-size:11px;color:var(--bt-text-3)">Email</span></div>
      <div class="bt-widget-body">
<!-- /wp:html -->
<!-- wp:shortcode -->[fxlm_price_alerts]<!-- /wp:shortcode -->
<!-- wp:html -->
      </div>
    </div>
  </div>

</div>
<!-- /wp:html -->
<!-- wp:shortcode -->[fxlm_newsletter style="banner"]<!-- /wp:shortcode -->
',
            ),

            // ── LEARN (NEW in v6) ──
            'learn' => array(
                'title' => 'Learn',
                'slug'  => 'learn',
                'content' => '
<!-- wp:html -->
[fxlm_breadcrumbs]
<div class="fxlm-page-header"><h1>Learn Crypto &amp; Forex</h1><p>Free educational resources for beginners and advanced traders. Start your journey here.</p></div>

<div class="fxlm-guide-grid" id="guides">

  <a href="/learn/#glossary" class="fxlm-guide-card" style="--card-accent:var(--bt-accent);--card-glow:rgba(0,255,102,.08);--card-gradient:linear-gradient(90deg,var(--bt-accent),var(--bt-accent))">
    <div class="fxlm-guide-card-accent"></div>
    <div class="fxlm-guide-icon">📖</div>
    <h3 class="fxlm-guide-title">Crypto Glossary</h3>
    <p class="fxlm-guide-desc">Master the language of crypto and forex. 25+ essential terms explained clearly — from Altcoin to Zero-Knowledge Proof.</p>
    <span class="fxlm-guide-tag">Browse terms →</span>
  </a>

  <a href="/market-analysis/" class="fxlm-guide-card" style="--card-accent:#a78bfa;--card-glow:rgba(167,139,250,.08);--card-gradient:linear-gradient(90deg,#a78bfa,#7c3aed)">
    <div class="fxlm-guide-card-accent"></div>
    <div class="fxlm-guide-icon">🤖</div>
    <h3 class="fxlm-guide-title">AI Market Analysis</h3>
    <p class="fxlm-guide-desc">Daily AI-generated market roundups for Bitcoin, Ethereum, forex majors, and DeFi. New analysis published every morning.</p>
    <span class="fxlm-guide-tag">Read analysis →</span>
  </a>

  <a href="/crypto-markets/" class="fxlm-guide-card" style="--card-accent:#f7931a;--card-glow:rgba(247,147,26,.08);--card-gradient:linear-gradient(90deg,#f7931a,#e55d00)">
    <div class="fxlm-guide-card-accent"></div>
    <div class="fxlm-guide-icon">📊</div>
    <h3 class="fxlm-guide-title">Live Crypto Prices</h3>
    <p class="fxlm-guide-desc">Real-time prices, market cap and 24h change for 500+ coins. Updated automatically every 5 minutes, 24/7.</p>
    <span class="fxlm-guide-tag">View markets →</span>
  </a>

  <a href="/forex-charts/" class="fxlm-guide-card" style="--card-accent:var(--bt-accent);--card-glow:rgba(0,153,255,.08);--card-gradient:linear-gradient(90deg,var(--bt-accent),#0055cc)">
    <div class="fxlm-guide-card-accent"></div>
    <div class="fxlm-guide-icon">💱</div>
    <h3 class="fxlm-guide-title">Forex Charts</h3>
    <p class="fxlm-guide-desc">Interactive TradingView charts for all major currency pairs. EUR/USD, GBP/USD, USD/JPY and more — live and free.</p>
    <span class="fxlm-guide-tag">Open charts →</span>
  </a>

  <a href="/tools/" class="fxlm-guide-card" style="--card-accent:#10b981;--card-glow:rgba(16,185,129,.08);--card-gradient:linear-gradient(90deg,#10b981,#059669)">
    <div class="fxlm-guide-card-accent"></div>
    <div class="fxlm-guide-icon">🛠️</div>
    <h3 class="fxlm-guide-title">Trader Toolkit</h3>
    <p class="fxlm-guide-desc">Crypto converter, Fear &amp; Greed Index, and Economic Calendar — all the tools you need in one place, free.</p>
    <span class="fxlm-guide-tag">Open tools →</span>
  </a>

  <a href="/trading-signals/" class="fxlm-guide-card" style="--card-accent:var(--bt-accent-warm);--card-glow:rgba(245,158,11,.08);--card-gradient:linear-gradient(90deg,var(--bt-accent-warm),#d97706)">
    <div class="fxlm-guide-card-accent"></div>
    <div class="fxlm-guide-icon">📡</div>
    <h3 class="fxlm-guide-title">Trading Signals</h3>
    <p class="fxlm-guide-desc">Free forex and crypto signals from FXStreet, DailyFX and CoinDesk. Curated and refreshed every 15 minutes.</p>
    <span class="fxlm-guide-tag">View signals →</span>
  </a>

</div>

<div class="fxlm-section-header" id="glossary"><h2>📖 Crypto &amp; Forex Glossary</h2></div>
<!-- /wp:html -->
<!-- wp:shortcode -->[fxlm_glossary]<!-- /wp:shortcode -->
<!-- wp:shortcode -->[fxlm_newsletter]<!-- /wp:shortcode -->
',
            ),

            // ── AFFILIATE / RECOMMENDED BROKERS ──
            'affiliate' => array(
                'title' => 'Recommended Brokers',
                'slug'  => 'recommended-brokers',
                'content' => '
<!-- wp:html -->
[fxlm_breadcrumbs]
<div class="fxlm-page-header">
  <h1>Best Forex &amp; Crypto Brokers ' . $year . '</h1>
  <p>We only recommend regulated, trusted brokers. Some links earn us a commission — at zero cost to you.</p>
</div>

<div class="fxlm-affiliate-disclosure">
  <strong>📢 Affiliate Disclosure:</strong> Some links on this page are affiliate links. We may earn a small commission if you sign up. This does not affect our reviews or rankings.
</div>

<div class="fxlm-top-picks">
  <div class="fxlm-top-pick fxlm-pick-gold">
    <div class="fxlm-pick-rank">🥇 #1</div>
    <div class="fxlm-pick-name">Pepperstone</div>
    <div class="fxlm-pick-tags"><span>FCA / ASIC</span><span>From 0.0 pips</span><span>MT4 / MT5 / cTrader</span></div>
    <a href="https://pepperstone.com" class="fxlm-btn-affiliate" target="_blank" rel="sponsored noopener">Open Account →</a>
  </div>
  <div class="fxlm-top-pick fxlm-pick-silver">
    <div class="fxlm-pick-rank">🥈 #2</div>
    <div class="fxlm-pick-name">eToro</div>
    <div class="fxlm-pick-tags"><span>FCA / CySEC / ASIC</span><span>Min $50</span><span>Copy Trading</span></div>
    <a href="https://etoro.com" class="fxlm-btn-affiliate" target="_blank" rel="sponsored noopener">Open Account →</a>
  </div>
  <div class="fxlm-top-pick fxlm-pick-bronze">
    <div class="fxlm-pick-rank">🥉 #3</div>
    <div class="fxlm-pick-name">IC Markets</div>
    <div class="fxlm-pick-tags"><span>ASIC / CySEC</span><span>Min $200</span><span>Raw ECN Spreads</span></div>
    <a href="https://icmarkets.com" class="fxlm-btn-affiliate" target="_blank" rel="sponsored noopener">Open Account →</a>
  </div>
</div>

<div class="fxlm-section-header"><h2>🏦 Forex Brokers</h2></div>
<div class="fxlm-broker-cards">
  <div class="fxlm-broker-card">
    <div class="fxlm-broker-header"><span class="fxlm-broker-badge fxlm-badge-top">Top Rated</span><div class="fxlm-stars">★★★★★</div></div>
    <h3>Pepperstone</h3>
    <p>Multi-regulated broker (FCA, ASIC, CySEC, DFSA, CMA) with razor-thin spreads and institutional-grade execution. Best pick for serious forex traders.</p>
    <ul class="fxlm-broker-pros"><li>✅ FCA + ASIC regulated</li><li>✅ Raw spreads from 0.0 pips</li><li>✅ MT4, MT5, cTrader, TradingView</li><li>✅ No dealing desk — ECN execution</li></ul>
    <a href="https://pepperstone.com" class="fxlm-btn-affiliate" target="_blank" rel="sponsored noopener">Open Account →</a>
    <small class="fxlm-risk-warning">CFDs carry risk. 74-89% of retail accounts lose money.</small>
  </div>
  <div class="fxlm-broker-card">
    <div class="fxlm-broker-header"><span class="fxlm-broker-badge fxlm-badge-new">Best Crypto</span><div class="fxlm-stars">★★★★★</div></div>
    <h3>Coinbase</h3>
    <p>SEC/FCA/FinCEN regulated, publicly traded (NASDAQ:COIN), insured custody. The safest on-ramp for crypto beginners and institutions alike.</p>
    <ul class="fxlm-broker-pros"><li>✅ 250+ cryptocurrencies</li><li>✅ FDIC-insured USD balances</li><li>✅ Earn interest on staking</li><li>✅ Learn &amp; Earn rewards</li></ul>
    <a href="https://coinbase.com" class="fxlm-btn-affiliate" target="_blank" rel="sponsored noopener">Start Trading →</a>
    <small class="fxlm-risk-warning">Crypto is highly volatile. Only invest what you can afford to lose.</small>
  </div>
</div>
<!-- /wp:html -->

<!-- wp:html -->
<div class="fxlm-section-header"><h2>🏆 Featured Crypto Platforms</h2><span class="fxlm-section-badge">Affiliate</span></div>
<!-- /wp:html -->
<!-- wp:shortcode -->[bt_affiliate name="eToro" url="#your-etoro-link" rating="5" min_deposit="$50" regulation="FCA, CySEC, ASIC" features="Copy Trading, 0% Commission, Social Trading, 3000+ Assets" badge="Editor\'s Choice" label="Open Account"]<!-- /wp:shortcode -->
<!-- wp:shortcode -->[bt_affiliate name="Binance" url="#your-binance-link" rating="5" min_deposit="$10" regulation="Multiple Jurisdictions" features="600+ Coins, Lowest Fees, Staking, Futures, Earn" badge="Best for Crypto" label="Start Trading"]<!-- /wp:shortcode -->
<!-- wp:shortcode -->[bt_affiliate name="Coinbase" url="#your-coinbase-link" rating="4" min_deposit="$2" regulation="SEC, FCA, FinCEN" features="250+ Coins, Insured Custody, Learn & Earn, Staking" badge="Best for Beginners" label="Get Started"]<!-- /wp:shortcode -->
<!-- wp:shortcode -->[bt_affiliate name="Kraken" url="#your-kraken-link" rating="4" min_deposit="$10" regulation="FinCEN, FCA" features="200+ Coins, Pro Trading, Futures, Staking, 24/7 Support" label="Trade Now"]<!-- /wp:shortcode -->
<!-- wp:shortcode -->[bt_affiliate name="Bybit" url="#your-bybit-link" rating="4" min_deposit="$1" regulation="VARA Dubai" features="600+ Coins, Derivatives, Copy Trading, Launchpad, Bot Trading" label="Start Trading"]<!-- /wp:shortcode -->

<!-- wp:html -->
<div class="fxlm-section-header"><h2>💱 Top Forex Brokers</h2><span class="fxlm-section-badge">Regulated</span></div>
<!-- /wp:html -->
<!-- wp:shortcode -->[bt_affiliate name="IC Markets" url="#your-icmarkets-link" rating="5" min_deposit="$200" regulation="ASIC, CySEC, FSA" features="Raw Spreads from 0.0, MT4/MT5, cTrader, ECN" badge="Best Forex Broker" label="Open Account"]<!-- /wp:shortcode -->
<!-- wp:shortcode -->[bt_affiliate name="Pepperstone" url="#your-pepperstone-link" rating="5" min_deposit="$200" regulation="FCA, ASIC, CySEC, DFSA" features="60+ Pairs, Fast Execution, TradingView, No Dealing Desk" label="Start Trading"]<!-- /wp:shortcode -->
<!-- wp:shortcode -->[bt_affiliate name="XM" url="#your-xm-link" rating="4" min_deposit="$5" regulation="CySEC, ASIC, DFSA" features="1000+ Instruments, $30 No Deposit Bonus, MT4/MT5, Copy Trading" label="Claim Bonus"]<!-- /wp:shortcode -->

<!-- wp:html -->
<p style="text-align:center;font-size:11px;color:#3d4660;margin:24px 0">⚠️ <strong>Risk Disclosure:</strong> CFDs are complex instruments with a high risk of losing money. 74-89% of retail investor accounts lose money when trading CFDs. Some links are affiliate links — we may earn a commission at no extra cost to you. We only recommend platforms we\'ve thoroughly reviewed.</p>
<!-- /wp:html -->
',
            ),

            // ── BLOG (AI-generated articles display) — v59 magazine revamp ──
            'blog' => array(
                'title' => 'Blog',
                'slug'  => 'market-blog',
                'content' => '
<!-- wp:html -->
[fxlm_breadcrumbs]

<!-- v80 Broadsheet Masthead -->
<header class="bt-bs-masthead">
  <div class="bt-bs-masthead-rule"></div>
  <div class="bt-bs-masthead-inner">
    <div class="bt-bs-masthead-eyebrow">
      <span class="bt-bs-dot"></span>
      <span data-i18n="blog.badge">AI desk · updated daily</span>
    </div>
    <h1 class="bt-bs-masthead-title" data-i18n="blog.title">The BlockTicker Journal</h1>
    <p class="bt-bs-masthead-sub" data-i18n="blog.subtitle">Daily market intelligence, crypto explainers and forex analysis — edited for traders.</p>
  </div>
  <div class="bt-bs-masthead-rule"></div>
</header>

<!-- v80 Editorial tabs — underline bar, not pills, and each is a real link to ?cat= -->
<nav class="bt-bs-tabs-wrap" aria-label="Filter posts by category">
  <div class="bt-bs-tabs" id="bt-bs-tabs" role="tablist">
    <a class="bt-bs-tab" data-cat=""                role="tab" href="' . esc_url(home_url('/market-blog/')) . '">All</a>
    <a class="bt-bs-tab" data-cat="crypto-news"     role="tab" href="' . esc_url(home_url('/market-blog/?cat=crypto-news')) . '">Crypto</a>
    <a class="bt-bs-tab" data-cat="forex-news"      role="tab" href="' . esc_url(home_url('/market-blog/?cat=forex-news')) . '">Forex</a>
    <a class="bt-bs-tab" data-cat="market-analysis" role="tab" href="' . esc_url(home_url('/market-blog/?cat=market-analysis')) . '">Analysis</a>
    <a class="bt-bs-tab" data-cat="education"       role="tab" href="' . esc_url(home_url('/market-blog/?cat=education')) . '">Education</a>
    <a class="bt-bs-tab" data-cat="defi-web3"       role="tab" href="' . esc_url(home_url('/market-blog/?cat=defi-web3')) . '">DeFi</a>
    <a class="bt-bs-tab" data-cat="bitcoin"         role="tab" href="' . esc_url(home_url('/market-blog/?cat=bitcoin')) . '">Bitcoin</a>
    <a class="bt-bs-tab" data-cat="altcoins"        role="tab" href="' . esc_url(home_url('/market-blog/?cat=altcoins')) . '">Altcoins</a>
  </div>
</nav>
<!-- /wp:html -->
<!-- wp:shortcode -->[fxlm_blog_posts count="18" layout="magazine"]<!-- /wp:shortcode -->
<!-- wp:html -->

<script>
(function(){
  // Mark the active tab based on the URL ?cat= param.
  function currentCat(){
    var m = location.search.match(/[?&]cat=([^&]+)/);
    return m ? decodeURIComponent(m[1]) : "";
  }
  function markActive(){
    var cur = currentCat();
    document.querySelectorAll(".bt-bs-tab").forEach(function(t){
      var active = (t.dataset.cat || "") === cur;
      t.classList.toggle("active", active);
      t.setAttribute("aria-selected", active ? "true" : "false");
    });
  }
  markActive();

  // Progressive enhancement: intercept tab clicks, fetch the new grid, swap it in.
  // If anything fails, the normal <a href> navigation takes over.
  var wrapSelector = ".bt-bs-wrap";
  document.querySelectorAll(".bt-bs-tab").forEach(function(tab){
    tab.addEventListener("click", function(e){
      // Let modifier-clicks (ctrl, cmd, middle-click) work normally
      if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button === 1) return;
      e.preventDefault();
      var url = tab.href;
      var cur = document.querySelector(wrapSelector);
      if (!cur) { location.href = url; return; }
      cur.classList.add("bt-bs-loading");
      fetch(url, {credentials:"same-origin"}).then(function(r){return r.text();}).then(function(html){
        var doc = new DOMParser().parseFromString(html, "text/html");
        var fresh = doc.querySelector(wrapSelector);
        if (fresh && cur.parentNode) {
          cur.parentNode.replaceChild(fresh, cur);
          history.pushState({}, "", url);
          markActive();
          window.scrollTo({top: document.querySelector(".bt-bs-tabs-wrap").offsetTop - 20, behavior:"smooth"});
        } else {
          location.href = url;
        }
      }).catch(function(){ location.href = url; });
    });
  });
  window.addEventListener("popstate", function(){ location.reload(); });
})();
</script>
<!-- /wp:html -->

<!-- Newsletter band at end -->
<!-- wp:shortcode -->[fxlm_newsletter style="banner"]<!-- /wp:shortcode -->
',
            ),

            // ── ABOUT ──
            'about' => array(
                'title' => 'About',
                'slug'  => 'about',
                'content' => '
<!-- wp:html -->
[fxlm_breadcrumbs]

<div class="bt-ab-wrap">

  <!-- ── HERO ── -->
  <div class="bt-ab-hero">
    <div class="bt-ab-hero-left">
      <div class="bt-ab-eyebrow">About ' . esc_html( $site_name ) . '</div>
      <h1 class="bt-ab-title">Your trusted source for crypto and forex intelligence</h1>
      <p class="bt-ab-subtitle">Live data, structured analysis, and market insights in one place.</p>
    </div>
    <div class="bt-ab-hero-right" aria-hidden="true">
      <div class="bt-ab-hero-graphic">
        <div class="bt-ab-hg-bar" style="height:60%;background:var(--bt-accent);opacity:.9"></div>
        <div class="bt-ab-hg-bar" style="height:80%;background:var(--bt-accent);opacity:.85"></div>
        <div class="bt-ab-hg-bar" style="height:45%;background:#a78bfa;opacity:.8"></div>
        <div class="bt-ab-hg-bar" style="height:95%;background:var(--bt-accent);opacity:.95"></div>
        <div class="bt-ab-hg-bar" style="height:70%;background:var(--bt-accent);opacity:.8"></div>
        <div class="bt-ab-hg-line"></div>
      </div>
    </div>
  </div>

  <!-- ── MISSION ── -->
  <div class="bt-ab-mission">
    <div class="bt-ab-mission-icon">
      <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="var(--bt-accent)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="3"/><line x1="12" y1="2" x2="12" y2="5"/><line x1="12" y1="19" x2="12" y2="22"/><line x1="2" y1="12" x2="5" y2="12"/><line x1="19" y1="12" x2="22" y2="12"/></svg>
    </div>
    <div class="bt-ab-mission-body">
      <h2 class="bt-ab-mission-title">Our Mission</h2>
      <p>' . esc_html( $site_name ) . ' is built to give independent traders and investors access to the same level of market intelligence used by professionals.</p>
      <p>We combine AI-assisted research with structured human review to deliver clear, timely, and actionable insights across crypto and global markets.</p>
    </div>
  </div>

  <!-- ── HOW CONTENT IS CREATED ── -->
  <div class="bt-ab-section">
    <div class="bt-ab-section-label">
      <h2 class="bt-ab-section-title">How Content Is Created</h2>
      <div class="bt-ab-section-tags">
        <span class="bt-ab-tag">Independent</span>
        <span class="bt-ab-tag">AI-Assisted</span>
        <span class="bt-ab-tag">Human-Reviewed</span>
      </div>
    </div>
    <div class="bt-ab-process-grid">

      <div class="bt-ab-process-card">
        <div class="bt-ab-process-icon" style="--pc:var(--bt-accent)">
          <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="var(--bt-accent)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a10 10 0 1 0 10 10"/><path d="M12 6v6l4 2"/><circle cx="18" cy="5" r="3"/></svg>
        </div>
        <h3 class="bt-ab-process-title">AI Research Engine</h3>
        <p class="bt-ab-process-desc">Continuously analyzes live market data, news flows, and on-chain metrics to generate structured insights and initial drafts.</p>
      </div>

      <div class="bt-ab-process-card">
        <div class="bt-ab-process-icon" style="--pc:var(--bt-accent)">
          <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="var(--bt-accent)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/></svg>
        </div>
        <h3 class="bt-ab-process-title">Human Review &amp; Validation</h3>
        <p class="bt-ab-process-desc">All content is reviewed, edited, and verified before publication to ensure clarity, accuracy, and relevance.</p>
      </div>

      <div class="bt-ab-process-card">
        <div class="bt-ab-process-icon" style="--pc:#a78bfa">
          <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#a78bfa" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
        </div>
        <h3 class="bt-ab-process-title">Data-Driven Methodology</h3>
        <p class="bt-ab-process-desc">Insights are based on publicly available data, macroeconomic trends, and market structure analysis — not opinions or speculation.</p>
      </div>

    </div>

    <!-- Transparency statement -->
    <div class="bt-ab-transparency">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--bt-text-3)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
      <span>This platform is operated independently. Content is AI-assisted and human-reviewed. No fictional authors or personas are used.</span>
    </div>
  </div>

  <!-- ── DATA SOURCES ── -->
  <div class="bt-ab-section">
    <h2 class="bt-ab-section-title">Data Sources &amp; Transparency</h2>
    <div class="bt-ab-data-grid">
      <div class="bt-ab-data-card">
        <div class="bt-ab-data-icon" style="--dc:#f7931a">₿</div>
        <strong>Crypto Prices</strong>
        <span>Aggregated from major market data providers. Updated in real-time.</span>
      </div>
      <div class="bt-ab-data-card">
        <div class="bt-ab-data-icon" style="--dc:var(--bt-accent)">$</div>
        <strong>Forex Rates</strong>
        <span>Institutional-grade pricing feeds with frequent updates.</span>
      </div>
      <div class="bt-ab-data-card">
        <div class="bt-ab-data-icon" style="--dc:var(--bt-accent)">📊</div>
        <strong>Charts</strong>
        <span>Powered by TradingView for accurate, real-time visualization.</span>
      </div>
      <div class="bt-ab-data-card">
        <div class="bt-ab-data-icon" style="--dc:#a78bfa">📰</div>
        <strong>News</strong>
        <span>Curated from trusted financial and crypto news sources.</span>
      </div>
      <div class="bt-ab-data-card">
        <div class="bt-ab-data-icon" style="--dc:var(--bt-accent-warm)">📡</div>
        <strong>Fear &amp; Greed Index</strong>
        <span>Market sentiment indicators updated regularly.</span>
      </div>
      <div class="bt-ab-data-card">
        <div class="bt-ab-data-icon" style="--dc:#10b981">✦</div>
        <strong>AI Analysis</strong>
        <span>Generated daily and reviewed before publication.</span>
      </div>
    </div>
  </div>

  <!-- ── WHAT WE PROVIDE ── -->
  <div class="bt-ab-section">
    <h2 class="bt-ab-section-title">What We Provide — Free, Forever</h2>
    <div class="bt-ab-provide-grid">
      <div class="bt-ab-provide-card">
        <div class="bt-ab-provide-icon" style="--pic:var(--bt-accent)">🌐</div>
        <div><strong>Live Forex Rates</strong><span>170+ currency pairs</span></div>
      </div>
      <div class="bt-ab-provide-card">
        <div class="bt-ab-provide-icon" style="--pic:#f7931a">₿</div>
        <div><strong>Crypto Prices</strong><span>500+ assets tracked</span></div>
      </div>
      <div class="bt-ab-provide-card">
        <div class="bt-ab-provide-icon" style="--pic:var(--bt-accent)">📶</div>
        <div><strong>Trading Signals</strong><span>Updated every 15 minutes</span></div>
      </div>
      <div class="bt-ab-provide-card">
        <div class="bt-ab-provide-icon" style="--pic:#a78bfa">📋</div>
        <div><strong>Market News</strong><span>Aggregated and filtered</span></div>
      </div>
      <div class="bt-ab-provide-card">
        <div class="bt-ab-provide-icon" style="--pic:#10b981">📅</div>
        <div><strong>Daily Market Analysis</strong><span>Published at 08:00 UTC</span></div>
      </div>
      <div class="bt-ab-provide-card">
        <div class="bt-ab-provide-icon" style="--pic:var(--bt-accent)">📈</div>
        <div><strong>Interactive Charts</strong><span>Powered by TradingView</span></div>
      </div>
      <div class="bt-ab-provide-card">
        <div class="bt-ab-provide-icon" style="--pic:var(--bt-accent-warm)">⇄</div>
        <div><strong>Crypto Converter</strong><span>Real-time calculations</span></div>
      </div>
      <div class="bt-ab-provide-card">
        <div class="bt-ab-provide-icon" style="--pic:#a78bfa">🎓</div>
        <div><strong>Education Hub</strong><span>Guides, glossary, tutorials</span></div>
      </div>
    </div>
  </div>

</div>

<style>
.bt-ab-wrap{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:var(--bt-text);max-width:960px;margin:0 auto}

/* Hero */
.bt-ab-hero{display:grid;grid-template-columns:1fr auto;gap:32px;align-items:center;background:#0d1117;border:1px solid #1e2535;border-radius:0;padding:36px 40px;margin-bottom:20px;overflow:hidden}
.bt-ab-eyebrow{font-size:11px;text-transform:uppercase;letter-spacing:.1em;color:var(--bt-accent);font-weight:700;margin-bottom:10px}
.bt-ab-title{font-size:clamp(1.5rem,2.5vw,2.1rem);font-weight:800;color:var(--bt-text);margin:0 0 12px;line-height:1.2;max-width:480px}
.bt-ab-subtitle{color:var(--bt-text-3);font-size:15px;margin:0;line-height:1.6}
.bt-ab-hero-right{flex-shrink:0}
.bt-ab-hero-graphic{display:flex;align-items:flex-end;gap:8px;height:100px;position:relative;padding-bottom:2px}
.bt-ab-hg-bar{width:18px;border-radius:0 4px 0 0;transition:opacity .3s}
.bt-ab-hg-line{position:absolute;bottom:2px;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--bt-accent)44,var(--bt-accent),var(--bt-accent)44);border-radius:1px}
@media(max-width:600px){.bt-ab-hero{grid-template-columns:1fr}.bt-ab-hero-right{display:none}}

/* Mission */
.bt-ab-mission{display:flex;gap:20px;align-items:flex-start;background:var(--bt-bg-elev);border:1px solid #1e2535;border-radius:0;padding:24px 28px;margin-bottom:20px}
.bt-ab-mission-icon{width:52px;height:52px;background:rgba(0,255,102,.1);border:1px solid rgba(0,255,102,.2);border-radius:0;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.bt-ab-mission-title{font-size:16px;font-weight:700;color:var(--bt-accent);margin:0 0 10px;letter-spacing:.02em}
.bt-ab-mission-body p{font-size:14px;color:var(--bt-text-2);line-height:1.65;margin:0 0 8px}
.bt-ab-mission-body p:last-child{margin:0}
@media(max-width:500px){.bt-ab-mission{flex-direction:column}}

/* Sections */
.bt-ab-section{background:var(--bt-bg-elev);border:1px solid #1e2535;border-radius:0;padding:28px 28px;margin-bottom:20px}
.bt-ab-section-label{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:20px}
.bt-ab-section-title{font-size:18px;font-weight:700;color:var(--bt-text);margin:0 0 20px}
.bt-ab-section-label .bt-ab-section-title{margin:0}
.bt-ab-section-tags{display:flex;gap:6px;flex-wrap:wrap}
.bt-ab-tag{font-size:11px;padding:3px 10px;border:1px solid var(--bt-text-4);border-radius:20px;color:var(--bt-text-2);font-weight:500}

/* Process cards */
.bt-ab-process-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;margin-bottom:16px}
.bt-ab-process-card{background:#0d1117;border:1px solid #1e2535;border-top:3px solid var(--pc,var(--bt-accent));border-radius:0;padding:18px 20px}
.bt-ab-process-icon{width:44px;height:44px;background:color-mix(in srgb,var(--pc) 12%,transparent);border:1px solid color-mix(in srgb,var(--pc) 25%,transparent);border-radius:0;display:flex;align-items:center;justify-content:center;margin-bottom:14px}
.bt-ab-process-title{font-size:14px;font-weight:700;color:var(--bt-text);margin:0 0 8px;line-height:1.3}
.bt-ab-process-desc{font-size:13px;color:var(--bt-text-3);line-height:1.6;margin:0}

/* Transparency bar */
.bt-ab-transparency{display:flex;align-items:center;gap:10px;background:#0d1117;border:1px solid #1e2535;border-radius:0;padding:12px 16px;font-size:13px;color:var(--bt-text-3);line-height:1.5}

/* Data sources */
.bt-ab-data-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:12px}
.bt-ab-data-card{background:#0d1117;border:1px solid #1e2535;border-radius:0;padding:14px;display:flex;flex-direction:column;align-items:center;text-align:center;gap:6px}
.bt-ab-data-icon{width:44px;height:44px;background:color-mix(in srgb,var(--dc,var(--bt-accent)) 12%,transparent);border:1px solid color-mix(in srgb,var(--dc,var(--bt-accent)) 25%,transparent);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:18px;margin-bottom:2px}
.bt-ab-data-card strong{font-size:13px;color:var(--bt-text);font-weight:700}
.bt-ab-data-card span{font-size:11px;color:var(--bt-text-3);line-height:1.4}

/* Provide grid */
.bt-ab-provide-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:10px}
.bt-ab-provide-card{display:flex;align-items:center;gap:12px;background:#0d1117;border:1px solid #1e2535;border-radius:0;padding:12px 14px}
.bt-ab-provide-icon{width:36px;height:36px;background:color-mix(in srgb,var(--pic,var(--bt-accent)) 12%,transparent);border:1px solid color-mix(in srgb,var(--pic,var(--bt-accent)) 20%,transparent);border-radius:0;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0}
.bt-ab-provide-card strong{display:block;font-size:13px;font-weight:700;color:var(--bt-text);margin-bottom:2px}
.bt-ab-provide-card span{font-size:11px;color:var(--bt-text-3)}
</style>
<!-- /wp:html -->
<!-- wp:shortcode -->[fxlm_newsletter style="banner"]<!-- /wp:shortcode -->
',
            ),

            // ── CONTACT ──
            'contact' => array(
                'title' => 'Contact',
                'slug'  => 'contact',
                'content' => '
<!-- wp:html -->
[fxlm_breadcrumbs]
<div class="bt-pp-shell">

  <aside class="bt-pp-sidebar">
    <div class="bt-pp-sidebar-inner">
      <div class="bt-pp-sidebar-logo">Block<span>Ticker</span></div>
      <div class="bt-pp-sidebar-label">' . __bt("contact.on_this_page") . '</div>
      <a href="#contact-types" class="bt-pp-nav-link active"><span class="bt-pp-nav-dot"></span>' . __bt("contact.nav_types") . '</a>
      <a href="#contact-form" class="bt-pp-nav-link"><span class="bt-pp-nav-dot"></span>' . __bt("contact.nav_form") . '</a>
      <a href="#contact-response" class="bt-pp-nav-link"><span class="bt-pp-nav-dot"></span>' . __bt("contact.nav_response") . '</a>
    </div>
  </aside>

  <main class="bt-pp-main">
    <div class="bt-pp-eyebrow">BlockTicker</div>
    <h1 class="bt-pp-title">' . __bt("contact.title") . '</h1>
    <p class="bt-pp-subtitle">' . __bt("contact.subtitle") . '</p>

    <section class="bt-pp-section" id="contact-types">
      <div class="bt-pp-section-label">' . __bt("contact.types_label") . '</div>
      <div class="bt-pp-card-grid">
        <div class="bt-pp-card">
          <div class="bt-pp-card-icon" style="background:rgba(0,255,102,.1)">📝</div>
          <h3>' . __bt("contact.type1_title") . '</h3>
          <p>' . __bt("contact.type1_desc") . '</p>
        </div>
        <div class="bt-pp-card">
          <div class="bt-pp-card-icon" style="background:rgba(59,130,246,.1)">🤝</div>
          <h3>' . __bt("contact.type2_title") . '</h3>
          <p>' . __bt("contact.type2_desc") . '</p>
        </div>
        <div class="bt-pp-card">
          <div class="bt-pp-card-icon" style="background:rgba(167,139,250,.1)">✍️</div>
          <h3>' . __bt("contact.type3_title") . '</h3>
          <p>' . __bt("contact.type3_desc") . '</p>
        </div>
      </div>
    </section>

    <section class="bt-pp-section" id="contact-form">
      <div class="bt-pp-section-label">' . __bt("contact.form_label") . '</div>
<!-- /wp:html -->
<!-- wp:shortcode -->[fxlm_contact_form]<!-- /wp:shortcode -->
<!-- wp:html -->
    </section>

    <section class="bt-pp-section" id="contact-response">
      <div class="bt-pp-section-label">' . __bt("contact.response_label") . '</div>
      <div class="bt-pp-stat-row">
        <div class="bt-pp-stat"><div class="bt-pp-stat-num">&lt;24h</div><div class="bt-pp-stat-lbl">' . __bt("contact.stat1") . '</div></div>
        <div class="bt-pp-stat"><div class="bt-pp-stat-num">3-5d</div><div class="bt-pp-stat-lbl">' . __bt("contact.stat2") . '</div></div>
        <div class="bt-pp-stat"><div class="bt-pp-stat-num">48h</div><div class="bt-pp-stat-lbl">' . __bt("contact.stat3") . '</div></div>
        <div class="bt-pp-stat"><div class="bt-pp-stat-num">30d</div><div class="bt-pp-stat-lbl">' . __bt("contact.stat4") . '</div></div>
      </div>
      <div class="bt-pp-notice">
        <span class="bt-pp-notice-icon">📬</span>
        <span>' . __bt("contact.response_note") . '</span>
      </div>
    </section>

  </main>
</div>
<!-- /wp:html -->
',
            ),

            // ── GAINERS & LOSERS ──
            'gainers-losers' => array(
                'title' => 'Gainers & Losers',
                'slug'  => 'gainers-losers',
                'content' => '
<!-- wp:html -->
[fxlm_breadcrumbs]
<div class="bt-dash-wrap">
  <div class="fxlm-page-header">
    <div class="bt-eyebrow bt-eyebrow-green">CRYPTO / MOVERS</div>
    <h1>Top Gainers &amp; Losers</h1>
    <p>Best and worst performing cryptocurrencies in the last 24 hours · Live CoinGecko data</p>
  </div>

  <div class="bt-panel-head">
    <span class="bt-panel-num">01</span>
    <span class="bt-panel-title">Market Movers</span>
    <span class="bt-panel-live">24h performance · Live</span>
  </div>
  <div class="bt-widget">
    <div class="bt-widget-head">
      <span class="bt-widget-title">Market Movers</span>
      <div class="bt-widget-filter">
        <button class="bt-wf-btn active">Top 20</button>
        <button class="bt-wf-btn">24h</button>
        <button class="bt-wf-btn">7d</button>
      </div>
    </div>
<!-- /wp:html -->
<!-- wp:shortcode -->[fxlm_gainers_losers]<!-- /wp:shortcode -->
<!-- wp:html -->
  </div>
</div>
<!-- /wp:html -->
<!-- wp:shortcode -->[fxlm_newsletter]<!-- /wp:shortcode -->
',
            ),

            // ── EXCHANGES ──
            'exchanges' => array(
                'title' => 'Exchanges',
                'slug'  => 'exchanges',
                'content' => '
<!-- wp:html -->
[fxlm_breadcrumbs]
<div class="bt-dash-wrap">
  <div class="bt-panel-head">
    <span class="bt-panel-num">01</span>
    <span class="bt-panel-title">Crypto Exchanges</span>
    <span class="bt-panel-live">CoinGecko · Live</span>
  </div>
  <div class="bt-widget">
    <div class="bt-widget-head">
      <span class="bt-widget-title">Exchange Rankings</span>
      <div class="bt-widget-filter"><button class="bt-wf-btn active">All</button><a href="' . home_url('/exchanges/spot/') . '" class="bt-wf-btn">Spot</a><a href="' . home_url('/exchanges/derivatives/') . '" class="bt-wf-btn">Derivatives</a><a href="' . home_url('/exchanges/dex-spot/') . '" class="bt-wf-btn">DEX</a></div>
    </div>
<!-- /wp:html -->
<!-- wp:shortcode -->[fxlm_exchanges per_page="20"]<!-- /wp:shortcode -->
<!-- wp:html -->
  </div>
</div>
<!-- /wp:html -->
<!-- wp:shortcode -->[fxlm_newsletter]<!-- /wp:shortcode -->
',
            ),

            // ── EXCHANGES → CEX SPOT ──
            'exchanges-spot' => array(
                'title' => 'Centralized Spot Exchanges',
                'slug'  => 'exchanges/spot',
                'content' => '
<!-- wp:html -->
[fxlm_breadcrumbs]
<div class="fxlm-page-header">
  <h1>💎 Centralized Spot Exchanges</h1>
  <p>Top centralized exchanges ranked by spot trading volume, liquidity, and trust score — updated hourly from CoinGecko.</p>
</div>

<div class="bt-exch-explainer">
  <div class="bt-exch-icon">💎</div>
  <div>
    <h3>What is a centralized spot exchange?</h3>
    <p>A centralized spot exchange (CEX) matches buyers and sellers for immediate delivery of crypto — you send fiat or crypto, the exchange holds your funds in a custodial account, and trades settle in real time. Binance, Coinbase, Kraken and OKX are among the largest. The trade-off: you trust the exchange to custody your assets.</p>
  </div>
</div>
<!-- /wp:html -->
<!-- wp:shortcode -->[fxlm_exchanges per_page="50" type="spot"]<!-- /wp:shortcode -->
<!-- wp:shortcode -->[fxlm_newsletter]<!-- /wp:shortcode -->
',
            ),

            // ── EXCHANGES → CEX DERIVATIVES ──
            'exchanges-derivatives' => array(
                'title' => 'Centralized Derivatives Exchanges',
                'slug'  => 'exchanges/derivatives',
                'content' => '
<!-- wp:html -->
[fxlm_breadcrumbs]
<div class="fxlm-page-header">
  <h1>📊 Centralized Derivatives Exchanges</h1>
  <p>Top centralized derivatives venues ranked by open interest and 24h volume. Perpetual swaps, dated futures, options — updated hourly.</p>
</div>

<div class="bt-exch-explainer">
  <div class="bt-exch-icon" style="background:rgba(247,147,26,.1);color:#f7931a">📊</div>
  <div>
    <h3>What is a derivatives exchange?</h3>
    <p>Derivatives exchanges let you trade contracts that <em>derive</em> their value from an underlying crypto — without holding the asset. Perpetual swaps are the dominant product, offering leverage up to 100×. Binance Futures, Bybit, OKX and Deribit lead the space. Derivatives volume regularly eclipses spot volume 4–5×.</p>
  </div>
</div>

<div class="fxlm-signals-disclaimer" style="margin-top:20px">
  <strong>⚠ Risk warning:</strong> Trading leveraged derivatives carries substantial risk of loss. The majority of retail traders lose money. Only trade with capital you can afford to lose.
</div>
<!-- /wp:html -->
<!-- wp:shortcode -->[fxlm_exchanges per_page="50" type="derivatives"]<!-- /wp:shortcode -->
<!-- wp:shortcode -->[fxlm_newsletter]<!-- /wp:shortcode -->
',
            ),

            // ── EXCHANGES → DEX SPOT ──
            'exchanges-dex-spot' => array(
                'title' => 'Decentralized Spot Exchanges',
                'slug'  => 'exchanges/dex-spot',
                'content' => '
<!-- wp:html -->
[fxlm_breadcrumbs]
<div class="fxlm-page-header">
  <h1>💠 Decentralized Spot Exchanges (DEXes)</h1>
  <p>Non-custodial spot trading venues — Uniswap, PancakeSwap, Curve, Raydium and more. Ranked by 24h volume and liquidity.</p>
</div>

<div class="bt-exch-explainer">
  <div class="bt-exch-icon" style="background:rgba(167,139,250,.1);color:#a78bfa">💠</div>
  <div>
    <h3>What is a decentralized exchange?</h3>
    <p>A DEX uses smart contracts to match trades without ever taking custody of your funds. You connect a wallet (MetaMask, Phantom, Rabby), approve the token, and swap. AMM (Automated Market Maker) DEXes like Uniswap use liquidity pools; orderbook DEXes like dYdX match bids and asks on-chain. <strong>You stay in full control of your keys — but you pay gas fees and accept MEV risk.</strong></p>
  </div>
</div>
<!-- /wp:html -->
<!-- wp:shortcode -->[fxlm_exchanges per_page="50" type="dex"]<!-- /wp:shortcode -->
<!-- wp:shortcode -->[fxlm_newsletter]<!-- /wp:shortcode -->
',
            ),

            // ── EXCHANGES → DEX DERIVATIVES ──
            'exchanges-dex-derivatives' => array(
                'title' => 'Decentralized Derivatives Exchanges',
                'slug'  => 'exchanges/dex-derivatives',
                'content' => '
<!-- wp:html -->
[fxlm_breadcrumbs]
<div class="fxlm-page-header">
  <h1>🔻 Decentralized Derivatives Exchanges</h1>
  <p>On-chain perpetual swaps and futures — dYdX, GMX, Hyperliquid, Jupiter Perps. Trustless leverage, fully on-chain settlement.</p>
</div>

<div class="bt-exch-explainer">
  <div class="bt-exch-icon" style="background:rgba(245,158,11,.1);color:var(--bt-accent-warm)">🔻</div>
  <div>
    <h3>What is a decentralized derivatives exchange?</h3>
    <p>Perp DEXes let you trade leveraged positions without depositing to a centralized venue. Liquidity comes from on-chain pools (GMX) or off-chain orderbooks with on-chain settlement (dYdX). You self-custody collateral, funding rates replace borrow fees, and liquidations are executed by keeper bots or smart contracts.</p>
  </div>
</div>

<div class="bt-exch-placeholder">
  <div class="bt-exch-placeholder-icon">🔻</div>
  <h3>Perp DEX Rankings</h3>
  <p>Detailed ranking table for decentralized perpetual exchanges is being indexed. In the meantime, explore the centralized leaderboard or browse the full exchange directory.</p>
  <div class="bt-exch-placeholder-cta">
    <a href="' . home_url('/exchanges/derivatives/') . '" class="bt-exch-btn">Centralized Perps</a>
    <a href="' . home_url('/exchanges/') . '" class="bt-exch-btn bt-exch-btn-ghost">All Exchanges</a>
  </div>
</div>
<!-- /wp:html -->
<!-- wp:shortcode -->[fxlm_newsletter]<!-- /wp:shortcode -->
',
            ),

            // ── DEXSCAN LANDING ──
            'dexscan' => array(
                'title' => 'DexScan',
                'slug'  => 'dexscan',
                'content' => '
<!-- wp:html -->
[fxlm_breadcrumbs]
<div class="fxlm-page-header">
    <div class="bt-eyebrow bt-eyebrow-green">WEB3 / ON-CHAIN</div>
    <h1>DexScan — On-Chain Explorer</h1>
    <p>Trending tokens · Fresh launches · Top-performing plays · Ethereum, BNB Chain, Solana and more</p>
  </div>

<div class="bt-dexscan-grid">
  <a href="' . home_url('/dexscan/signals/') . '" class="bt-dex-card" style="--dex-c:#a78bfa">
    <span class="bt-dex-ico">📡</span>
    <h3>On-Chain Signals <span class="bt-dex-new">New</span></h3>
    <p>Smart-money wallets, whale flows, and technical alerts surfaced from on-chain activity.</p>
    <span class="bt-dex-cta">Explore signals →</span>
  </a>
  <a href="' . home_url('/dexscan/trending/') . '" class="bt-dex-card" style="--dex-c:var(--bt-accent-warm)">
    <span class="bt-dex-ico">🔥</span>
    <h3>Trending</h3>
    <p>Tokens with the biggest surge in trading activity over the last 1h / 6h / 24h.</p>
    <span class="bt-dex-cta">View trending →</span>
  </a>
  <a href="' . home_url('/dexscan/new/') . '" class="bt-dex-card" style="--dex-c:var(--bt-accent)">
    <span class="bt-dex-ico">✨</span>
    <h3>New Listings</h3>
    <p>Freshly deployed tokens — filtered by liquidity, holder count and contract safety.</p>
    <span class="bt-dex-cta">Browse new →</span>
  </a>
  <a href="' . home_url('/dexscan/gainers/') . '" class="bt-dex-card" style="--dex-c:#10b981">
    <span class="bt-dex-ico">📈</span>
    <h3>Top Gainers</h3>
    <p>Biggest price moves across DEXes — filtered to exclude honeypots and rug-prone contracts.</p>
    <span class="bt-dex-cta">See gainers →</span>
  </a>
  <a href="' . home_url('/dexscan/meme/') . '" class="bt-dex-card" style="--dex-c:var(--bt-danger)">
    <span class="bt-dex-ico">🐸</span>
    <h3>Meme Explorer</h3>
    <p>Curated feed of meme tokens with real volume — Solana, Base, BNB Chain.</p>
    <span class="bt-dex-cta">Open explorer →</span>
  </a>
  <a href="' . home_url('/dexscan/top-traders/') . '" class="bt-dex-card" style="--dex-c:var(--bt-accent)">
    <span class="bt-dex-ico">🏆</span>
    <h3>Top Traders</h3>
    <p>Leaderboard of wallets ranked by realised PnL, win rate and position size.</p>
    <span class="bt-dex-cta">View leaderboard →</span>
  </a>
</div>

<div class="bt-dex-about">
  <h2>What is DexScan?</h2>
  <p>DexScan is BlockTicker\'s on-chain discovery layer. Whereas centralized exchanges tell you what is already traded, DexScan surfaces what is <em>being</em> traded — right now — on decentralized venues. That means earlier detection of momentum, easier filtering against rug-prone contracts, and direct visibility into wallet-level flows that move markets before price.</p>
  <p>Data is indexed from major EVM and Solana DEXes, then scored against a safety rubric (liquidity lock, contract verification, ownership status, holder concentration). Results refresh every 60 seconds during market hours.</p>
</div>
<!-- /wp:html -->
<!-- wp:shortcode -->[fxlm_newsletter]<!-- /wp:shortcode -->
',
            ),

            // ── DEXSCAN → SIGNALS ──
            'dexscan-signals' => array(
                'title' => 'DexScan — On-Chain Signals',
                'slug'  => 'dexscan/signals',
                'content' => '
<!-- DexScan shared tab bar -->
<div class="bt-dextab-wrap">
  <div class="bt-dextabs">
    <a href="' . home_url('/dexscan/trending/') . '" class="bt-dextab">✦ DexScan Tokens</a>
    <a href="' . home_url('/dexscan/signals/') . '" class="bt-dextab active">Signals <span class="bt-dextab-badge">NEW</span></a>
    <a href="' . home_url('/dexscan/meme/') . '" class="bt-dextab">Meme Explorer</a>
    <a href="' . home_url('/dexscan/top-traders/') . '" class="bt-dextab">Top Traders</a>
  </div>
  <div class="bt-dex-icons">
    <a href="#" class="bt-dex-iconbtn" title="Telegram">✈</a>
    <a href="#" class="bt-dex-iconbtn" title="RSS">📡</a>
    <a href="#" class="bt-dex-iconbtn" title="More">⋯</a>
  </div>
</div>

<div class="bt-dextokens-wrap">

<div class="bt-live-toggle">
  <button class="bt-live-toggle-btn active"><span class="dot"></span> Live</button>
  <button class="bt-live-toggle-btn">⏱ History</button>
</div>

<div style="display:flex;gap:12px;align-items:flex-start;margin-bottom:16px;flex-wrap:wrap">
  <div class="bt-gems-box">
    <span class="bt-gems-lbl">24h Gems</span>
    <span style="color:var(--bt-accent-warm)">👑 2</span>
    <span>🪙 0</span>
    <span>🟢 0</span>
  </div>
  <div class="bt-timeline-band" style="flex:1;min-width:300px">
    <div class="bt-timeline-head">
      <span>3:15AM</span><span>7:15AM</span><span>11:15AM</span><span>3:15PM</span><span>7:15PM</span><span>11:15PM</span><span>Now</span>
    </div>
    <div class="bt-timeline-bar"><div class="bt-timeline-tick"></div><div class="bt-timeline-tick has-signal"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick has-signal"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick has-signal"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick has-signal"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick major-signal"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick has-signal"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick has-signal"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick has-signal"></div><div class="bt-timeline-tick has-signal"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick major-signal"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick has-signal"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick has-signal"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick has-signal"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick has-signal"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick has-signal"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick major-signal"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick has-signal"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick major-signal"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick"></div><div class="bt-timeline-tick has-signal"></div><div class="bt-timeline-tick has-signal"></div><div class="bt-timeline-tick has-signal"></div><div class="bt-timeline-tick"></div></div>
  </div>
</div>

<div class="bt-signals-top">
  <div class="bt-signal-top-card special">
    <div class="bt-tok-ico" style="background:var(--bt-accent-warm)">T</div>
    <div class="bt-signal-top-body">
      <div class="bt-signal-top-title">Top Alphas</div>
      <div class="bt-signal-top-meta">Past 24h</div>
    </div>
  </div>
  <div class="bt-signal-top-card">
    <div class="bt-tok-ico" style="background:var(--bt-accent-warm)">P</div>
    <div class="bt-signal-top-body">
      <div class="bt-signal-top-title">PALU <span style="color:var(--bt-text-3);font-size:10px">6mo</span></div>
      <div class="bt-signal-top-meta">👑 21st · Vol $6.56M · FDV $2.04M · Smart 19</div>
    </div>
    <div class="bt-signal-top-gain">👑 45X</div>
  </div>
  <div class="bt-signal-top-card">
    <div class="bt-tok-ico" style="background:#ef4444">A</div>
    <div class="bt-signal-top-body">
      <div class="bt-signal-top-title">ASTEROID <span style="color:var(--bt-text-3);font-size:10px">1y</span></div>
      <div class="bt-signal-top-meta">👑 99th · Vol $57.27M · FDV $21.94M · Smart 18</div>
    </div>
    <div class="bt-signal-top-gain">👑 71X</div>
  </div>
  <div class="bt-signal-top-card">
    <div class="bt-tok-ico" style="background:#10b981">A</div>
    <div class="bt-signal-top-body">
      <div class="bt-signal-top-title">ASTEROID <span style="color:var(--bt-text-3);font-size:10px">21h</span></div>
      <div class="bt-signal-top-meta">👑 52nd · Vol $52.7M · FDV $3.52M · Smart 7</div>
    </div>
    <div class="bt-signal-top-gain">👑 10X</div>
  </div>
  <div class="bt-signal-top-card">
    <div class="bt-tok-ico" style="background:#a78bfa">E</div>
    <div class="bt-signal-top-body">
      <div class="bt-signal-top-title">ELON <span style="color:var(--bt-text-3);font-size:10px">2mo</span></div>
      <div class="bt-signal-top-meta">👑 177th · Vol $328.35K · FDV $592.81K · Smart 15</div>
    </div>
    <div class="bt-signal-top-gain">👑 5X</div>
  </div>
</div>

<div class="bt-signal-cats">
  <button class="bt-signal-cat active">⬚ All Signals</button>
  <button class="bt-signal-cat">⚡ Fresh Signals</button>
  <button class="bt-signal-cat">♻ Revived Signals</button>
</div>

<div class="bt-signals-grid">

  <div class="bt-signal-card">
    <div class="bt-signal-card-tag">⚡ Fresh Signal</div>
    <div class="bt-signal-card-head">
      <div class="bt-signal-card-head-left">
        <div class="bt-tok-ico" style="background:#a78bfa">2</div>
        <div>
          <div class="bt-tok-name">2027 <span style="color:var(--bt-text-3);font-size:11px">4d</span></div>
          <div class="bt-tok-addr">Vol $1.37M · FDV $5.09M · +257.24%</div>
        </div>
      </div>
      <div class="bt-signal-card-head-right">
        <span class="bt-signal-rank-pill">👑 7th</span>
        <span style="color:var(--bt-text-3);font-size:10.5px;font-family:var(--bt-font-mono)">6h ago</span>
        <span class="bt-signal-gain-pill">&lt;1X</span>
      </div>
    </div>
    <div class="bt-signal-body"><strong>3 Smart Traders</strong> bought $27.93K 2027 in total</div>
    <div class="bt-signal-tf-row"><span class="bt-signal-tf">1h</span><span class="bt-signal-tf">4h</span><span class="bt-signal-tf active">24h</span><span class="bt-signal-tf">7d</span><span class="bt-signal-tf">All</span></div>
    <div class="bt-signal-chart"><svg viewBox="0 0 400 80" preserveAspectRatio="none"><path d="M0,60 L30,55 L60,48 L90,52 L120,35 L150,28 L180,32 L210,20 L240,25 L270,18 L300,22 L330,15 L360,12 L400,18" fill="none" stroke="var(--bt-accent)" stroke-width="2"/><path d="M0,60 L30,55 L60,48 L90,52 L120,35 L150,28 L180,32 L210,20 L240,25 L270,18 L300,22 L330,15 L360,12 L400,18 L400,80 L0,80 Z" fill="url(#g1)"/><defs><linearGradient id="g1" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="var(--bt-accent)" stop-opacity=".3"/><stop offset="1" stop-color="var(--bt-accent)" stop-opacity="0"/></linearGradient></defs></svg></div>
    <div class="bt-signal-stats">
      <div><div class="bt-signal-stat-lbl">Last Signal</div><div class="bt-signal-stat-val">$0.004083</div></div>
      <div><div class="bt-signal-stat-lbl">Price</div><div class="bt-signal-stat-val">$4.08M</div></div>
      <div><div class="bt-signal-stat-lbl">MC</div><div class="bt-signal-stat-val">$356.79K</div></div>
      <div><div class="bt-signal-stat-lbl">Liq</div><div class="bt-signal-stat-val pos">▲ 24.82%</div></div>
    </div>
  </div>

  <div class="bt-signal-card">
    <div class="bt-signal-card-tag">⚡ Fresh Signal</div>
    <div class="bt-signal-card-head">
      <div class="bt-signal-card-head-left">
        <div class="bt-tok-ico" style="background:#ef4444">A</div>
        <div>
          <div class="bt-tok-name">ASTEROID <span style="color:var(--bt-text-3);font-size:11px">1y</span></div>
          <div class="bt-tok-addr">Vol $2.3M · FDV $627.15K · +17.32K%</div>
        </div>
      </div>
      <div class="bt-signal-card-head-right">
        <span class="bt-signal-rank-pill">👑 2nd</span>
        <span style="color:var(--bt-text-3);font-size:10.5px;font-family:var(--bt-font-mono)">8h ago</span>
        <span class="bt-signal-gain-pill">&lt;1X</span>
      </div>
    </div>
    <div class="bt-signal-body"><strong>3 Smart Traders</strong> bought $5.54K ASTEROID in total</div>
    <div class="bt-signal-tf-row"><span class="bt-signal-tf">1h</span><span class="bt-signal-tf">4h</span><span class="bt-signal-tf active">24h</span><span class="bt-signal-tf">7d</span><span class="bt-signal-tf">All</span></div>
    <div class="bt-signal-chart"><svg viewBox="0 0 400 80" preserveAspectRatio="none"><path d="M0,60 L30,55 L60,48 L90,52 L120,35 L150,28 L180,32 L210,20 L240,25 L270,18 L300,22 L330,15 L360,12 L400,18" fill="none" stroke="var(--bt-accent)" stroke-width="2"/><path d="M0,60 L30,55 L60,48 L90,52 L120,35 L150,28 L180,32 L210,20 L240,25 L270,18 L300,22 L330,15 L360,12 L400,18 L400,80 L0,80 Z" fill="url(#g1)"/><defs><linearGradient id="g1" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="var(--bt-accent)" stop-opacity=".3"/><stop offset="1" stop-color="var(--bt-accent)" stop-opacity="0"/></linearGradient></defs></svg></div>
    <div class="bt-signal-stats">
      <div><div class="bt-signal-stat-lbl">Last Signal</div><div class="bt-signal-stat-val">$0.001101</div></div>
      <div><div class="bt-signal-stat-lbl">Price</div><div class="bt-signal-stat-val">$1.1M</div></div>
      <div><div class="bt-signal-stat-lbl">MC</div><div class="bt-signal-stat-val">$111.77K</div></div>
      <div><div class="bt-signal-stat-lbl">Liq</div><div class="bt-signal-stat-val neg">▼ 43.08%</div></div>
    </div>
  </div>

  <div class="bt-signal-card">
    <div class="bt-signal-card-tag">⚡ Fresh Signal</div>
    <div class="bt-signal-card-head">
      <div class="bt-signal-card-head-left">
        <div class="bt-tok-ico" style="background:var(--bt-danger)">D</div>
        <div>
          <div class="bt-tok-name">DANGER</div>
          <div class="bt-tok-addr">Vol $901.22K · FDV $135.7K · +2.69K%</div>
        </div>
      </div>
      <div class="bt-signal-card-head-right">
        <span class="bt-signal-rank-pill">👑 3rd</span>
        <span style="color:var(--bt-text-3);font-size:10.5px;font-family:var(--bt-font-mono)">11h ago</span>
        <span class="bt-signal-gain-pill">&lt;1X</span>
      </div>
    </div>
    <div class="bt-signal-body"><strong>5 Smart Traders</strong> bought $7.85K DANGER in total</div>
    <div class="bt-signal-tf-row"><span class="bt-signal-tf">1h</span><span class="bt-signal-tf">4h</span><span class="bt-signal-tf active">24h</span><span class="bt-signal-tf">7d</span><span class="bt-signal-tf">All</span></div>
    <div class="bt-signal-chart"><svg viewBox="0 0 400 80" preserveAspectRatio="none"><path d="M0,60 L30,55 L60,48 L90,52 L120,35 L150,28 L180,32 L210,20 L240,25 L270,18 L300,22 L330,15 L360,12 L400,18" fill="none" stroke="var(--bt-accent)" stroke-width="2"/><path d="M0,60 L30,55 L60,48 L90,52 L120,35 L150,28 L180,32 L210,20 L240,25 L270,18 L300,22 L330,15 L360,12 L400,18 L400,80 L0,80 Z" fill="url(#g1)"/><defs><linearGradient id="g1" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="var(--bt-accent)" stop-opacity=".3"/><stop offset="1" stop-color="var(--bt-accent)" stop-opacity="0"/></linearGradient></defs></svg></div>
    <div class="bt-signal-stats">
      <div><div class="bt-signal-stat-lbl">Last Signal</div><div class="bt-signal-stat-val">$0.0004636</div></div>
      <div><div class="bt-signal-stat-lbl">Price</div><div class="bt-signal-stat-val">$463.82K</div></div>
      <div><div class="bt-signal-stat-lbl">MC</div><div class="bt-signal-stat-val">$70.95K</div></div>
      <div><div class="bt-signal-stat-lbl">Liq</div><div class="bt-signal-stat-val neg">▼ 70.72%</div></div>
    </div>
  </div>

  <div class="bt-signal-card">
    <div class="bt-signal-card-tag revived">♻ Revived Signal</div>
    <div class="bt-signal-card-head">
      <div class="bt-signal-card-head-left">
        <div class="bt-tok-ico" style="background:var(--bt-accent-warm)">P</div>
        <div>
          <div class="bt-tok-name">PALU <span style="color:var(--bt-text-3);font-size:11px">6mo</span></div>
          <div class="bt-tok-addr">Vol $6.56M · FDV $2.05M · +175.94%</div>
        </div>
      </div>
      <div class="bt-signal-card-head-right">
        <span class="bt-signal-rank-pill">👑 21st</span>
        <span style="color:var(--bt-text-3);font-size:10.5px;font-family:var(--bt-font-mono)">11h ago</span>
        <span class="bt-signal-gain-pill" style="background:rgba(245,158,11,.2);color:var(--bt-accent-warm)">👑 45X</span>
      </div>
    </div>
    <div class="bt-signal-body"><strong>19 Smart Traders</strong> bought $1.06M PALU in total</div>
    <div class="bt-signal-tf-row"><span class="bt-signal-tf">1h</span><span class="bt-signal-tf">4h</span><span class="bt-signal-tf active">24h</span><span class="bt-signal-tf">7d</span><span class="bt-signal-tf">All</span></div>
    <div class="bt-signal-chart"><svg viewBox="0 0 400 80" preserveAspectRatio="none"><path d="M0,60 L30,55 L60,48 L90,52 L120,35 L150,28 L180,32 L210,20 L240,25 L270,18 L300,22 L330,15 L360,12 L400,18" fill="none" stroke="var(--bt-accent)" stroke-width="2"/><path d="M0,60 L30,55 L60,48 L90,52 L120,35 L150,28 L180,32 L210,20 L240,25 L270,18 L300,22 L330,15 L360,12 L400,18 L400,80 L0,80 Z" fill="url(#g1)"/><defs><linearGradient id="g1" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="var(--bt-accent)" stop-opacity=".3"/><stop offset="1" stop-color="var(--bt-accent)" stop-opacity="0"/></linearGradient></defs></svg></div>
    <div class="bt-signal-stats">
      <div><div class="bt-signal-stat-lbl">Last Signal</div><div class="bt-signal-stat-val">$0.002553</div></div>
      <div><div class="bt-signal-stat-lbl">Price</div><div class="bt-signal-stat-val">$2.55M</div></div>
      <div><div class="bt-signal-stat-lbl">MC</div><div class="bt-signal-stat-val">$472.23K</div></div>
      <div><div class="bt-signal-stat-lbl">Liq</div><div class="bt-signal-stat-val neg">▼ 19.46%</div></div>
    </div>
  </div>

  <div class="bt-signal-card">
    <div class="bt-signal-card-tag">⚡ Fresh Signal</div>
    <div class="bt-signal-card-head">
      <div class="bt-signal-card-head-left">
        <div class="bt-tok-ico" style="background:var(--bt-text-3)">A</div>
        <div>
          <div class="bt-tok-name">ASTROID <span style="color:var(--bt-text-3);font-size:11px">19h</span></div>
          <div class="bt-tok-addr">Vol $1.48M · FDV $187.52K · +9.51K%</div>
        </div>
      </div>
      <div class="bt-signal-card-head-right">
        <span class="bt-signal-rank-pill">👑 5th</span>
        <span style="color:var(--bt-text-3);font-size:10.5px;font-family:var(--bt-font-mono)">14h ago</span>
        <span class="bt-signal-gain-pill">&lt;1X</span>
      </div>
    </div>
    <div class="bt-signal-body"><strong>5 Smart Traders</strong> bought $11.56K ASTROID in total</div>
    <div class="bt-signal-tf-row"><span class="bt-signal-tf">1h</span><span class="bt-signal-tf">4h</span><span class="bt-signal-tf active">24h</span><span class="bt-signal-tf">7d</span><span class="bt-signal-tf">All</span></div>
    <div class="bt-signal-chart"><svg viewBox="0 0 400 80" preserveAspectRatio="none"><path d="M0,60 L30,55 L60,48 L90,52 L120,35 L150,28 L180,32 L210,20 L240,25 L270,18 L300,22 L330,15 L360,12 L400,18" fill="none" stroke="var(--bt-accent)" stroke-width="2"/><path d="M0,60 L30,55 L60,48 L90,52 L120,35 L150,28 L180,32 L210,20 L240,25 L270,18 L300,22 L330,15 L360,12 L400,18 L400,80 L0,80 Z" fill="url(#g1)"/><defs><linearGradient id="g1" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="var(--bt-accent)" stop-opacity=".3"/><stop offset="1" stop-color="var(--bt-accent)" stop-opacity="0"/></linearGradient></defs></svg></div>
    <div class="bt-signal-stats">
      <div><div class="bt-signal-stat-lbl">Last Signal</div><div class="bt-signal-stat-val">$0.0003243</div></div>
      <div><div class="bt-signal-stat-lbl">Price</div><div class="bt-signal-stat-val">$324.31K</div></div>
      <div><div class="bt-signal-stat-lbl">MC</div><div class="bt-signal-stat-val">$32.94K</div></div>
      <div><div class="bt-signal-stat-lbl">Liq</div><div class="bt-signal-stat-val pos">▲ 2.05%</div></div>
    </div>
  </div>

  <div class="bt-signal-card">
    <div class="bt-signal-card-tag">⚡ Fresh Signal</div>
    <div class="bt-signal-card-head">
      <div class="bt-signal-card-head-left">
        <div class="bt-tok-ico" style="background:#ef4444">A</div>
        <div>
          <div class="bt-tok-name">ASTEROID <span style="color:var(--bt-text-3);font-size:11px">1y</span></div>
          <div class="bt-tok-addr">Vol $57.27M · FDV $21.94M · +89.07K%</div>
        </div>
      </div>
      <div class="bt-signal-card-head-right">
        <span class="bt-signal-rank-pill">👑 99th</span>
        <span style="color:var(--bt-text-3);font-size:10.5px;font-family:var(--bt-font-mono)">15h ago</span>
        <span class="bt-signal-gain-pill" style="background:rgba(245,158,11,.2);color:var(--bt-accent-warm)">👑 71X</span>
      </div>
    </div>
    <div class="bt-signal-body"><strong>1 Smart Trader</strong> bought $7.11K ASTEROID in 2h</div>
    <div class="bt-signal-tf-row"><span class="bt-signal-tf">1h</span><span class="bt-signal-tf">4h</span><span class="bt-signal-tf active">24h</span><span class="bt-signal-tf">7d</span><span class="bt-signal-tf">All</span></div>
    <div class="bt-signal-chart"><svg viewBox="0 0 400 80" preserveAspectRatio="none"><path d="M0,60 L30,55 L60,48 L90,52 L120,35 L150,28 L180,32 L210,20 L240,25 L270,18 L300,22 L330,15 L360,12 L400,18" fill="none" stroke="var(--bt-accent)" stroke-width="2"/><path d="M0,60 L30,55 L60,48 L90,52 L120,35 L150,28 L180,32 L210,20 L240,25 L270,18 L300,22 L330,15 L360,12 L400,18 L400,80 L0,80 Z" fill="url(#g1)"/><defs><linearGradient id="g1" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="var(--bt-accent)" stop-opacity=".3"/><stop offset="1" stop-color="var(--bt-accent)" stop-opacity="0"/></linearGradient></defs></svg></div>
    <div class="bt-signal-stats">
      <div><div class="bt-signal-stat-lbl">Last Signal</div><div class="bt-signal-stat-val">$0.00003275</div></div>
      <div><div class="bt-signal-stat-lbl">Price</div><div class="bt-signal-stat-val">$13.77M</div></div>
      <div><div class="bt-signal-stat-lbl">MC</div><div class="bt-signal-stat-val">$1.06M</div></div>
      <div><div class="bt-signal-stat-lbl">Liq</div><div class="bt-signal-stat-val pos">▲ 59.26%</div></div>
    </div>
  </div>

</div>



</div>

',
            ),

            // ── DEXSCAN → TRENDING ──
            'dexscan-trending' => array(
                'title' => 'DexScan — Trending Tokens',
                'slug'  => 'dexscan/trending',
                'content' => '
<!-- DexScan shared tab bar -->
<div class="bt-dextab-wrap">
  <div class="bt-dextabs">
    <a href="' . home_url('/dexscan/trending/') . '" class="bt-dextab active">✦ DexScan Tokens</a>
    <a href="' . home_url('/dexscan/signals/') . '" class="bt-dextab">Signals <span class="bt-dextab-badge">NEW</span></a>
    <a href="' . home_url('/dexscan/meme/') . '" class="bt-dextab">Meme Explorer</a>
    <a href="' . home_url('/dexscan/top-traders/') . '" class="bt-dextab">Top Traders</a>
  </div>
  <div class="bt-dex-icons">
    <a href="#" class="bt-dex-iconbtn" title="Telegram">✈</a>
    <a href="#" class="bt-dex-iconbtn" title="RSS">📡</a>
    <a href="#" class="bt-dex-iconbtn" title="More">⋯</a>
  </div>
</div>
<div class="bt-dextokens-wrap">

<div class="bt-cats-grid">
  <div class="bt-cat-card">
    <div class="bt-cat-head">BSC</div>
    <div class="bt-cat-row"><span class="bt-cat-row-rank">1</span><span class="bt-cat-row-ico" style="background:#f7931a">A</span><span class="bt-cat-row-sym">ASTER</span><span><span class="bt-cat-row-price">$0.6827</span><span class="bt-cat-row-chg dn">▼ 1.28%</span></span></div>
    <div class="bt-cat-row"><span class="bt-cat-row-rank">2</span><span class="bt-cat-row-ico" style="background:var(--bt-accent-warm)">4</span><span class="bt-cat-row-sym">4</span><span><span class="bt-cat-row-price">$0.01238</span><span class="bt-cat-row-chg up">▲ 4.08%</span></span></div>
    <div class="bt-cat-row"><span class="bt-cat-row-rank">3</span><span class="bt-cat-row-ico" style="background:#a78bfa">哈</span><span class="bt-cat-row-sym">哈基米</span><span><span class="bt-cat-row-price">$0.01269</span><span class="bt-cat-row-chg up">▲ 20.3%</span></span></div>
  </div>
  <div class="bt-cat-card">
    <div class="bt-cat-head">Chinese</div>
    <div class="bt-cat-row"><span class="bt-cat-row-rank">1</span><span class="bt-cat-row-ico" style="background:#ef4444">币</span><span class="bt-cat-row-sym">币安人生</span><span><span class="bt-cat-row-price">$0.4174</span><span class="bt-cat-row-chg up">▲ 23.53%</span></span></div>
    <div class="bt-cat-row"><span class="bt-cat-row-rank">2</span><span class="bt-cat-row-ico" style="background:#f97316">我</span><span class="bt-cat-row-sym">我踏马来了</span><span><span class="bt-cat-row-price">$0.01094</span><span class="bt-cat-row-chg up">▲ 14.74%</span></span></div>
    <div class="bt-cat-row"><span class="bt-cat-row-rank">3</span><span class="bt-cat-row-ico" style="background:#a78bfa">哈</span><span class="bt-cat-row-sym">哈基米</span><span><span class="bt-cat-row-price">$0.01269</span><span class="bt-cat-row-chg up">▲ 20.3%</span></span></div>
  </div>
  <div class="bt-cat-card">
    <div class="bt-cat-head">perpDEX</div>
    <div class="bt-cat-row"><span class="bt-cat-row-rank">1</span><span class="bt-cat-row-ico" style="background:#f7931a">A</span><span class="bt-cat-row-sym">ASTER</span><span><span class="bt-cat-row-price">$0.6827</span><span class="bt-cat-row-chg dn">▼ 1.28%</span></span></div>
    <div class="bt-cat-row"><span class="bt-cat-row-rank">2</span><span class="bt-cat-row-ico" style="background:var(--bt-accent)">A</span><span class="bt-cat-row-sym">AVNT</span><span><span class="bt-cat-row-price">$0.1472</span><span class="bt-cat-row-chg dn">▼ 3.7%</span></span></div>
    <div class="bt-cat-row"><span class="bt-cat-row-rank">3</span><span class="bt-cat-row-ico" style="background:var(--bt-danger)">M</span><span class="bt-cat-row-sym">MYX</span><span><span class="bt-cat-row-price">$0.272</span><span class="bt-cat-row-chg dn">▼ 8.51%</span></span></div>
  </div>
  <div class="bt-cat-card">
    <div class="bt-cat-head">Solana</div>
    <div class="bt-cat-row"><span class="bt-cat-row-rank">1</span><span class="bt-cat-row-ico" style="background:#ef4444">T</span><span class="bt-cat-row-sym">TRUMP</span><span><span class="bt-cat-row-price">$3.03</span><span class="bt-cat-row-chg dn">▼ 1.79%</span></span></div>
    <div class="bt-cat-row"><span class="bt-cat-row-rank">2</span><span class="bt-cat-row-ico" style="background:#f97316">U</span><span class="bt-cat-row-sym">USELESS</span><span><span class="bt-cat-row-price">$0.04759</span><span class="bt-cat-row-chg dn">▼ 3.73%</span></span></div>
    <div class="bt-cat-row"><span class="bt-cat-row-rank">3</span><span class="bt-cat-row-ico" style="background:#10b981">P</span><span class="bt-cat-row-sym">PUMP</span><span><span class="bt-cat-row-price">$0.002014</span><span class="bt-cat-row-chg up">▲ 0.13%</span></span></div>
  </div>
  <div class="bt-cat-card">
    <div class="bt-cat-head">Base</div>
    <div class="bt-cat-row"><span class="bt-cat-row-rank">1</span><span class="bt-cat-row-ico" style="background:var(--bt-accent)">A</span><span class="bt-cat-row-sym">AERO</span><span><span class="bt-cat-row-price">—</span><span class="bt-cat-row-chg neu"> </span></span></div>
    <div class="bt-cat-row"><span class="bt-cat-row-rank">2</span><span class="bt-cat-row-ico" style="background:#a78bfa">Z</span><span class="bt-cat-row-sym">ZORA</span><span><span class="bt-cat-row-price">—</span><span class="bt-cat-row-chg neu"> </span></span></div>
    <div class="bt-cat-row"><span class="bt-cat-row-rank">3</span><span class="bt-cat-row-ico" style="background:var(--bt-accent)">A</span><span class="bt-cat-row-sym">AVNT</span><span><span class="bt-cat-row-price">$0.1472</span><span class="bt-cat-row-chg dn">▼ 3.7%</span></span></div>
  </div>
</div>

<div class="bt-netfilter" id="bt-netfilter">
  <button class="bt-netchip active" data-net="">🌐 All Networks</button>
  <button class="bt-netchip" data-net="eth">⟠ Ethereum</button>
  <button class="bt-netchip" data-net="solana">◎ Solana</button>
  <button class="bt-netchip" data-net="base">🔵 Base</button>
  <button class="bt-netchip" data-net="bsc" style="color:#f7931a">🟡 BSC</button>
  <button class="bt-netchip" data-net="arbitrum">🔷 Arbitrum</button>
  <button class="bt-netchip" data-net="avalanche">🔺 Avalanche</button>
  <button class="bt-netchip" data-net="polygon_pos">🟣 Polygon</button>
</div>
<script>
(function(){
  function initNetFilter(){
    var chips = document.querySelectorAll("#bt-netfilter .bt-netchip");
    if(!chips.length) return;
    var params = new URLSearchParams(window.location.search);
    var curNet = params.get("network") || "";
    chips.forEach(function(c){
      var net = c.dataset.net || "";
      c.classList.toggle("active", net === curNet);
      c.addEventListener("click", function(){
        var newNet = c.dataset.net || "";
        var url = new URL(window.location.href);
        if(newNet){ url.searchParams.set("network", newNet); }
        else { url.searchParams.delete("network"); }
        window.location.href = url.toString();
      });
    });
  }
  if(document.readyState==="loading"){ document.addEventListener("DOMContentLoaded",initNetFilter); }
  else { initNetFilter(); }
})();
</script>

<div class="bt-subfilter">
  <div class="bt-subfilter-left">
    <button class="bt-subf-btn active">🔥 Trending</button>
    <button class="bt-timef-btn" data-period="5m">5m</button>
    <button class="bt-timef-btn" data-period="1h">1h</button>
    <button class="bt-timef-btn" data-period="4h">4h</button>
    <button class="bt-timef-btn active" data-period="24h">24h</button>
    <span class="bt-subf-sep"></span>
    <a href="' . home_url('/dexscan/new/') . '" class="bt-subf-btn">✨ New</a>
    <a href="' . home_url('/dexscan/gainers/') . '" class="bt-subf-btn">📈 Gainers</a>
  </div>
  <div class="bt-subfilter-right">
    <label><input type="checkbox"> Audit result pass</label>
    <button class="bt-filter-pill">▾ Liq</button>
    <button class="bt-filter-pill">▾ FDV</button>
    <button class="bt-filter-pill">▾ Age</button>
    <button class="bt-filter-pill bt-filter-pill-active">▾ Filters</button>
  </div>
</div>

<div id="bt-dextok-live-trending" class="bt-toktable-live" data-filter="trending">
<!-- /wp:html -->
<!-- wp:shortcode -->[bt_dexscan_tokens filter="trending" network="eth" limit="20"]<!-- /wp:shortcode -->
<!-- wp:html -->
</div>



</div>

',
            ),

            // ── DEXSCAN → NEW LISTINGS ──
            'dexscan-new' => array(
                'title' => 'DexScan — New Listings',
                'slug'  => 'dexscan/new',
                'content' => '
<!-- DexScan shared tab bar -->
<div class="bt-dextab-wrap">
  <div class="bt-dextabs">
    <a href="' . home_url('/dexscan/trending/') . '" class="bt-dextab active">✦ DexScan Tokens</a>
    <a href="' . home_url('/dexscan/signals/') . '" class="bt-dextab">Signals <span class="bt-dextab-badge">NEW</span></a>
    <a href="' . home_url('/dexscan/meme/') . '" class="bt-dextab">Meme Explorer</a>
    <a href="' . home_url('/dexscan/top-traders/') . '" class="bt-dextab">Top Traders</a>
  </div>
  <div class="bt-dex-icons">
    <a href="#" class="bt-dex-iconbtn" title="Telegram">✈</a>
    <a href="#" class="bt-dex-iconbtn" title="RSS">📡</a>
    <a href="#" class="bt-dex-iconbtn" title="More">⋯</a>
  </div>
</div>
<div class="bt-dextokens-wrap">

<div class="bt-cats-grid">
  <div class="bt-cat-card">
    <div class="bt-cat-head">BSC</div>
    <div class="bt-cat-row"><span class="bt-cat-row-rank">1</span><span class="bt-cat-row-ico" style="background:#f7931a">A</span><span class="bt-cat-row-sym">ASTER</span><span><span class="bt-cat-row-price">$0.6827</span><span class="bt-cat-row-chg dn">▼ 1.28%</span></span></div>
    <div class="bt-cat-row"><span class="bt-cat-row-rank">2</span><span class="bt-cat-row-ico" style="background:var(--bt-accent-warm)">4</span><span class="bt-cat-row-sym">4</span><span><span class="bt-cat-row-price">$0.01238</span><span class="bt-cat-row-chg up">▲ 4.08%</span></span></div>
    <div class="bt-cat-row"><span class="bt-cat-row-rank">3</span><span class="bt-cat-row-ico" style="background:#a78bfa">哈</span><span class="bt-cat-row-sym">哈基米</span><span><span class="bt-cat-row-price">$0.01269</span><span class="bt-cat-row-chg up">▲ 20.3%</span></span></div>
  </div>
  <div class="bt-cat-card">
    <div class="bt-cat-head">Chinese</div>
    <div class="bt-cat-row"><span class="bt-cat-row-rank">1</span><span class="bt-cat-row-ico" style="background:#ef4444">币</span><span class="bt-cat-row-sym">币安人生</span><span><span class="bt-cat-row-price">$0.4174</span><span class="bt-cat-row-chg up">▲ 23.53%</span></span></div>
    <div class="bt-cat-row"><span class="bt-cat-row-rank">2</span><span class="bt-cat-row-ico" style="background:#f97316">我</span><span class="bt-cat-row-sym">我踏马来了</span><span><span class="bt-cat-row-price">$0.01094</span><span class="bt-cat-row-chg up">▲ 14.74%</span></span></div>
    <div class="bt-cat-row"><span class="bt-cat-row-rank">3</span><span class="bt-cat-row-ico" style="background:#a78bfa">哈</span><span class="bt-cat-row-sym">哈基米</span><span><span class="bt-cat-row-price">$0.01269</span><span class="bt-cat-row-chg up">▲ 20.3%</span></span></div>
  </div>
  <div class="bt-cat-card">
    <div class="bt-cat-head">perpDEX</div>
    <div class="bt-cat-row"><span class="bt-cat-row-rank">1</span><span class="bt-cat-row-ico" style="background:#f7931a">A</span><span class="bt-cat-row-sym">ASTER</span><span><span class="bt-cat-row-price">$0.6827</span><span class="bt-cat-row-chg dn">▼ 1.28%</span></span></div>
    <div class="bt-cat-row"><span class="bt-cat-row-rank">2</span><span class="bt-cat-row-ico" style="background:var(--bt-accent)">A</span><span class="bt-cat-row-sym">AVNT</span><span><span class="bt-cat-row-price">$0.1472</span><span class="bt-cat-row-chg dn">▼ 3.7%</span></span></div>
    <div class="bt-cat-row"><span class="bt-cat-row-rank">3</span><span class="bt-cat-row-ico" style="background:var(--bt-danger)">M</span><span class="bt-cat-row-sym">MYX</span><span><span class="bt-cat-row-price">$0.272</span><span class="bt-cat-row-chg dn">▼ 8.51%</span></span></div>
  </div>
  <div class="bt-cat-card">
    <div class="bt-cat-head">Solana</div>
    <div class="bt-cat-row"><span class="bt-cat-row-rank">1</span><span class="bt-cat-row-ico" style="background:#ef4444">T</span><span class="bt-cat-row-sym">TRUMP</span><span><span class="bt-cat-row-price">$3.03</span><span class="bt-cat-row-chg dn">▼ 1.79%</span></span></div>
    <div class="bt-cat-row"><span class="bt-cat-row-rank">2</span><span class="bt-cat-row-ico" style="background:#f97316">U</span><span class="bt-cat-row-sym">USELESS</span><span><span class="bt-cat-row-price">$0.04759</span><span class="bt-cat-row-chg dn">▼ 3.73%</span></span></div>
    <div class="bt-cat-row"><span class="bt-cat-row-rank">3</span><span class="bt-cat-row-ico" style="background:#10b981">P</span><span class="bt-cat-row-sym">PUMP</span><span><span class="bt-cat-row-price">$0.002014</span><span class="bt-cat-row-chg up">▲ 0.13%</span></span></div>
  </div>
  <div class="bt-cat-card">
    <div class="bt-cat-head">Base</div>
    <div class="bt-cat-row"><span class="bt-cat-row-rank">1</span><span class="bt-cat-row-ico" style="background:var(--bt-accent)">A</span><span class="bt-cat-row-sym">AERO</span><span><span class="bt-cat-row-price">—</span><span class="bt-cat-row-chg neu"> </span></span></div>
    <div class="bt-cat-row"><span class="bt-cat-row-rank">2</span><span class="bt-cat-row-ico" style="background:#a78bfa">Z</span><span class="bt-cat-row-sym">ZORA</span><span><span class="bt-cat-row-price">—</span><span class="bt-cat-row-chg neu"> </span></span></div>
    <div class="bt-cat-row"><span class="bt-cat-row-rank">3</span><span class="bt-cat-row-ico" style="background:var(--bt-accent)">A</span><span class="bt-cat-row-sym">AVNT</span><span><span class="bt-cat-row-price">$0.1472</span><span class="bt-cat-row-chg dn">▼ 3.7%</span></span></div>
  </div>
</div>

<div class="bt-netfilter" id="bt-netfilter">
  <button class="bt-netchip active" data-net="">🌐 All Networks</button>
  <button class="bt-netchip" data-net="eth">⟠ Ethereum</button>
  <button class="bt-netchip" data-net="solana">◎ Solana</button>
  <button class="bt-netchip" data-net="base">🔵 Base</button>
  <button class="bt-netchip" data-net="bsc" style="color:#f7931a">🟡 BSC</button>
  <button class="bt-netchip" data-net="arbitrum">🔷 Arbitrum</button>
  <button class="bt-netchip" data-net="avalanche">🔺 Avalanche</button>
  <button class="bt-netchip" data-net="polygon_pos">🟣 Polygon</button>
</div>
<script>
(function(){
  function initNetFilter(){
    var chips = document.querySelectorAll("#bt-netfilter .bt-netchip");
    if(!chips.length) return;
    var params = new URLSearchParams(window.location.search);
    var curNet = params.get("network") || "";
    chips.forEach(function(c){
      var net = c.dataset.net || "";
      c.classList.toggle("active", net === curNet);
      c.addEventListener("click", function(){
        var newNet = c.dataset.net || "";
        var url = new URL(window.location.href);
        if(newNet){ url.searchParams.set("network", newNet); }
        else { url.searchParams.delete("network"); }
        window.location.href = url.toString();
      });
    });
  }
  if(document.readyState==="loading"){ document.addEventListener("DOMContentLoaded",initNetFilter); }
  else { initNetFilter(); }
})();
</script>

<div class="bt-subfilter">
  <div class="bt-subfilter-left">
    <a href="' . home_url('/dexscan/trending/') . '" class="bt-subf-btn">🔥 Trending</a>
    <button class="bt-subf-btn active">✨ New</button>
    <a href="' . home_url('/dexscan/gainers/') . '" class="bt-subf-btn">📈 Gainers</a>
    <span class="bt-subf-sep"></span>
    <button class="bt-timef-btn" data-period="5m">5m</button>
    <button class="bt-timef-btn" data-period="1h">1h</button>
    <button class="bt-timef-btn" data-period="4h">4h</button>
    <button class="bt-timef-btn active" data-period="24h">24h</button>
  </div>
  <div class="bt-subfilter-right">
    <label><input type="checkbox"> Audit result pass</label>
    <button class="bt-filter-pill">▾ Liq</button>
    <button class="bt-filter-pill">▾ FDV</button>
    <button class="bt-filter-pill">▾ Age</button>
    <button class="bt-filter-pill">▾ Filters</button>
  </div>
</div>

<div id="bt-dextok-live-new" class="bt-toktable-live" data-filter="new">
<!-- /wp:html -->
<!-- wp:shortcode -->[bt_dexscan_tokens filter="new" network="eth" limit="20"]<!-- /wp:shortcode -->
<!-- wp:html -->
</div>

<div class="fxlm-signals-disclaimer" style="margin-top:24px">
  <strong>⚠ Extreme risk:</strong> Newly deployed tokens are the highest-risk category. Expect many to go to zero. Never invest more than you can afford to lose, always verify the contract, and watch for honeypots and rug pulls.
</div>

</div>

',
            ),

            // ── DEXSCAN → GAINERS ──
            'dexscan-gainers' => array(
                'title' => 'DexScan — Top Gainers',
                'slug'  => 'dexscan/gainers',
                'content' => '
<!-- DexScan shared tab bar -->
<div class="bt-dextab-wrap">
  <div class="bt-dextabs">
    <a href="' . home_url('/dexscan/trending/') . '" class="bt-dextab active">✦ DexScan Tokens</a>
    <a href="' . home_url('/dexscan/signals/') . '" class="bt-dextab">Signals <span class="bt-dextab-badge">NEW</span></a>
    <a href="' . home_url('/dexscan/meme/') . '" class="bt-dextab">Meme Explorer</a>
    <a href="' . home_url('/dexscan/top-traders/') . '" class="bt-dextab">Top Traders</a>
  </div>
  <div class="bt-dex-icons">
    <a href="#" class="bt-dex-iconbtn" title="Telegram">✈</a>
    <a href="#" class="bt-dex-iconbtn" title="RSS">📡</a>
    <a href="#" class="bt-dex-iconbtn" title="More">⋯</a>
  </div>
</div>
<div class="bt-dextokens-wrap">

<div class="bt-cats-grid">
  <div class="bt-cat-card">
    <div class="bt-cat-head">BSC</div>
    <div class="bt-cat-row"><span class="bt-cat-row-rank">1</span><span class="bt-cat-row-ico" style="background:#f7931a">A</span><span class="bt-cat-row-sym">ASTER</span><span><span class="bt-cat-row-price">$0.6827</span><span class="bt-cat-row-chg dn">▼ 1.28%</span></span></div>
    <div class="bt-cat-row"><span class="bt-cat-row-rank">2</span><span class="bt-cat-row-ico" style="background:var(--bt-accent-warm)">4</span><span class="bt-cat-row-sym">4</span><span><span class="bt-cat-row-price">$0.01238</span><span class="bt-cat-row-chg up">▲ 4.08%</span></span></div>
    <div class="bt-cat-row"><span class="bt-cat-row-rank">3</span><span class="bt-cat-row-ico" style="background:#a78bfa">哈</span><span class="bt-cat-row-sym">哈基米</span><span><span class="bt-cat-row-price">$0.01269</span><span class="bt-cat-row-chg up">▲ 20.3%</span></span></div>
  </div>
  <div class="bt-cat-card">
    <div class="bt-cat-head">Chinese</div>
    <div class="bt-cat-row"><span class="bt-cat-row-rank">1</span><span class="bt-cat-row-ico" style="background:#ef4444">币</span><span class="bt-cat-row-sym">币安人生</span><span><span class="bt-cat-row-price">$0.4174</span><span class="bt-cat-row-chg up">▲ 23.53%</span></span></div>
    <div class="bt-cat-row"><span class="bt-cat-row-rank">2</span><span class="bt-cat-row-ico" style="background:#f97316">我</span><span class="bt-cat-row-sym">我踏马来了</span><span><span class="bt-cat-row-price">$0.01094</span><span class="bt-cat-row-chg up">▲ 14.74%</span></span></div>
    <div class="bt-cat-row"><span class="bt-cat-row-rank">3</span><span class="bt-cat-row-ico" style="background:#a78bfa">哈</span><span class="bt-cat-row-sym">哈基米</span><span><span class="bt-cat-row-price">$0.01269</span><span class="bt-cat-row-chg up">▲ 20.3%</span></span></div>
  </div>
  <div class="bt-cat-card">
    <div class="bt-cat-head">perpDEX</div>
    <div class="bt-cat-row"><span class="bt-cat-row-rank">1</span><span class="bt-cat-row-ico" style="background:#f7931a">A</span><span class="bt-cat-row-sym">ASTER</span><span><span class="bt-cat-row-price">$0.6827</span><span class="bt-cat-row-chg dn">▼ 1.28%</span></span></div>
    <div class="bt-cat-row"><span class="bt-cat-row-rank">2</span><span class="bt-cat-row-ico" style="background:var(--bt-accent)">A</span><span class="bt-cat-row-sym">AVNT</span><span><span class="bt-cat-row-price">$0.1472</span><span class="bt-cat-row-chg dn">▼ 3.7%</span></span></div>
    <div class="bt-cat-row"><span class="bt-cat-row-rank">3</span><span class="bt-cat-row-ico" style="background:var(--bt-danger)">M</span><span class="bt-cat-row-sym">MYX</span><span><span class="bt-cat-row-price">$0.272</span><span class="bt-cat-row-chg dn">▼ 8.51%</span></span></div>
  </div>
  <div class="bt-cat-card">
    <div class="bt-cat-head">Solana</div>
    <div class="bt-cat-row"><span class="bt-cat-row-rank">1</span><span class="bt-cat-row-ico" style="background:#ef4444">T</span><span class="bt-cat-row-sym">TRUMP</span><span><span class="bt-cat-row-price">$3.03</span><span class="bt-cat-row-chg dn">▼ 1.79%</span></span></div>
    <div class="bt-cat-row"><span class="bt-cat-row-rank">2</span><span class="bt-cat-row-ico" style="background:#f97316">U</span><span class="bt-cat-row-sym">USELESS</span><span><span class="bt-cat-row-price">$0.04759</span><span class="bt-cat-row-chg dn">▼ 3.73%</span></span></div>
    <div class="bt-cat-row"><span class="bt-cat-row-rank">3</span><span class="bt-cat-row-ico" style="background:#10b981">P</span><span class="bt-cat-row-sym">PUMP</span><span><span class="bt-cat-row-price">$0.002014</span><span class="bt-cat-row-chg up">▲ 0.13%</span></span></div>
  </div>
  <div class="bt-cat-card">
    <div class="bt-cat-head">Base</div>
    <div class="bt-cat-row"><span class="bt-cat-row-rank">1</span><span class="bt-cat-row-ico" style="background:var(--bt-accent)">A</span><span class="bt-cat-row-sym">AERO</span><span><span class="bt-cat-row-price">—</span><span class="bt-cat-row-chg neu"> </span></span></div>
    <div class="bt-cat-row"><span class="bt-cat-row-rank">2</span><span class="bt-cat-row-ico" style="background:#a78bfa">Z</span><span class="bt-cat-row-sym">ZORA</span><span><span class="bt-cat-row-price">—</span><span class="bt-cat-row-chg neu"> </span></span></div>
    <div class="bt-cat-row"><span class="bt-cat-row-rank">3</span><span class="bt-cat-row-ico" style="background:var(--bt-accent)">A</span><span class="bt-cat-row-sym">AVNT</span><span><span class="bt-cat-row-price">$0.1472</span><span class="bt-cat-row-chg dn">▼ 3.7%</span></span></div>
  </div>
</div>

<div class="bt-netfilter" id="bt-netfilter">
  <button class="bt-netchip active" data-net="">🌐 All Networks</button>
  <button class="bt-netchip" data-net="eth">⟠ Ethereum</button>
  <button class="bt-netchip" data-net="solana">◎ Solana</button>
  <button class="bt-netchip" data-net="base">🔵 Base</button>
  <button class="bt-netchip" data-net="bsc" style="color:#f7931a">🟡 BSC</button>
  <button class="bt-netchip" data-net="arbitrum">🔷 Arbitrum</button>
  <button class="bt-netchip" data-net="avalanche">🔺 Avalanche</button>
  <button class="bt-netchip" data-net="polygon_pos">🟣 Polygon</button>
</div>
<script>
(function(){
  function initNetFilter(){
    var chips = document.querySelectorAll("#bt-netfilter .bt-netchip");
    if(!chips.length) return;
    var params = new URLSearchParams(window.location.search);
    var curNet = params.get("network") || "";
    chips.forEach(function(c){
      var net = c.dataset.net || "";
      c.classList.toggle("active", net === curNet);
      c.addEventListener("click", function(){
        var newNet = c.dataset.net || "";
        var url = new URL(window.location.href);
        if(newNet){ url.searchParams.set("network", newNet); }
        else { url.searchParams.delete("network"); }
        window.location.href = url.toString();
      });
    });
  }
  if(document.readyState==="loading"){ document.addEventListener("DOMContentLoaded",initNetFilter); }
  else { initNetFilter(); }
})();
</script>

<div class="bt-subfilter">
  <div class="bt-subfilter-left">
    <a href="' . home_url('/dexscan/trending/') . '" class="bt-subf-btn">🔥 Trending</a>
    <a href="' . home_url('/dexscan/new/') . '" class="bt-subf-btn">✨ New</a>
    <button class="bt-subf-btn active">📈 Gainers</button>
    <span class="bt-subf-sep"></span>
    <button class="bt-timef-btn" data-period="5m">5m</button>
    <button class="bt-timef-btn" data-period="1h">1h</button>
    <button class="bt-timef-btn" data-period="4h">4h</button>
    <button class="bt-timef-btn active" data-period="24h">24h</button>
  </div>
  <div class="bt-subfilter-right">
    <label><input type="checkbox"> Audit result pass</label>
    <button class="bt-filter-pill bt-filter-pill-active">▾ Liq: ≥100K ✕</button>
    <button class="bt-filter-pill">▾ FDV</button>
    <button class="bt-filter-pill">▾ Age</button>
    <button class="bt-filter-pill bt-filter-pill-active">▾ Filters <span class="bt-filter-pill-badge">2</span></button>
  </div>
</div>

<div id="bt-dextok-live-gainers" class="bt-toktable-live" data-filter="gainers">
<!-- /wp:html -->
<!-- wp:shortcode -->[bt_dexscan_tokens filter="gainers" network="eth" limit="20"]<!-- /wp:shortcode -->
<!-- wp:html -->
</div>

</div>

',
            ),

            // ── DEXSCAN → MEME ──
            'dexscan-meme' => array(
                'title' => 'DexScan — Meme Explorer',
                'slug'  => 'dexscan/meme',
                'content' => '
<!-- DexScan shared tab bar -->
<div class="bt-dextab-wrap">
  <div class="bt-dextabs">
    <a href="' . home_url('/dexscan/trending/') . '" class="bt-dextab">✦ DexScan Tokens</a>
    <a href="' . home_url('/dexscan/signals/') . '" class="bt-dextab">Signals <span class="bt-dextab-badge">NEW</span></a>
    <a href="' . home_url('/dexscan/meme/') . '" class="bt-dextab active">Meme Explorer</a>
    <a href="' . home_url('/dexscan/top-traders/') . '" class="bt-dextab">Top Traders</a>
  </div>
  <div class="bt-dex-icons">
    <a href="#" class="bt-dex-iconbtn" title="Telegram">✈</a>
    <a href="#" class="bt-dex-iconbtn" title="RSS">📡</a>
    <a href="#" class="bt-dex-iconbtn" title="More">⋯</a>
  </div>
</div>

<div class="bt-dextokens-wrap">

<div class="bt-meme-platforms">
  <button class="bt-meme-plat active">🪄 Four.meme</button>
  <button class="bt-meme-plat">🗲 Flap</button>
  <button class="bt-meme-plat" style="color:var(--bt-accent-warm)">🎈 Pump.fun</button>
  <button class="bt-meme-plat">🔔 Bonk</button>
  <button class="bt-meme-plat">🚀 Launchlab</button>
  <button class="bt-meme-plat">🌙 Moonshot</button>
  <button class="bt-meme-plat">🎒 Bags</button>
  <button class="bt-meme-plat">🔄 Dynamic BC</button>
  <button class="bt-meme-plat">🪐 Jupiter Studio</button>
  <button class="bt-meme-plat">🔮 Believe</button>
  <button class="bt-meme-plat">🔶 Four.Meme — X Mode</button>
</div>

<!-- /wp:html -->
<!-- wp:shortcode -->[bt_meme_explorer]<!-- /wp:shortcode -->
<!-- wp:html -->
<div class="bt-meme-item">
      <div class="bt-meme-ico" style="background:#f97316;color:#fff">无</div>
      <div class="bt-meme-main">
        <div class="bt-meme-name">无法被骗局选中 <span style="color:var(--bt-text-3);font-size:10px">无法被骗局选中</span></div>
        <div class="bt-meme-meta">1m · 👤 1 · —<span class="bt-meme-prog"><span style="width:1%"></span></span></div>
        <div class="bt-meme-tx">TX 12</div>
      </div>
      <div class="bt-meme-right">
        <span class="bt-meme-right-line">V <strong>$1.5K</strong></span>
        <span class="bt-meme-right-line"><small>FDV $3.7K</small></span>
        <span class="bt-meme-right-line" style="margin-top:3px"><span class="pos">▲ &lt;0.1%</span> · Run 💥 · 0%</span>
      </div>
    </div><div class="bt-meme-item">
      <div class="bt-meme-ico" style="background:#10b981;color:#fff">无</div>
      <div class="bt-meme-main">
        <div class="bt-meme-name">无法被骗局选中 <span style="color:var(--bt-text-3);font-size:10px">无法被骗局选中</span></div>
        <div class="bt-meme-meta">1m · 👤 1 · —<span class="bt-meme-prog"><span style="width:1%"></span></span></div>
        <div class="bt-meme-tx">TX 9</div>
      </div>
      <div class="bt-meme-right">
        <span class="bt-meme-right-line">V <strong>$1.5K</strong></span>
        <span class="bt-meme-right-line"><small>FDV $3.8K</small></span>
        <span class="bt-meme-right-line" style="margin-top:3px"><span class="pos">▲ &lt;0.1%</span> · Run 💥 · 0%</span>
      </div>
    </div><div class="bt-meme-item">
      <div class="bt-meme-ico" style="background:var(--bt-accent);color:#fff">无</div>
      <div class="bt-meme-main">
        <div class="bt-meme-name">无法被骗局选中 <span style="color:var(--bt-text-3);font-size:10px">无法被骗局选中</span></div>
        <div class="bt-meme-meta">1m · 👤 1 · 4<span class="bt-meme-prog"><span style="width:5%"></span></span></div>
        <div class="bt-meme-tx">TX 2</div>
      </div>
      <div class="bt-meme-right">
        <span class="bt-meme-right-line">V <strong>$3.1K</strong></span>
        <span class="bt-meme-right-line"><small>FDV $5.1K</small></span>
        <span class="bt-meme-right-line" style="margin-top:3px"><span class="neu">0%</span> · Run 🏁 · 0%</span>
      </div>
    </div><div class="bt-meme-item">
      <div class="bt-meme-ico" style="background:#a78bfa;color:#fff">无</div>
      <div class="bt-meme-main">
        <div class="bt-meme-name">无法被骗局选中 <span style="color:var(--bt-text-3);font-size:10px">无法被骗局选中</span></div>
        <div class="bt-meme-meta">1m · 👤 1 · 1<span class="bt-meme-prog"><span style="width:2%"></span></span></div>
        <div class="bt-meme-tx">TX 6</div>
      </div>
      <div class="bt-meme-right">
        <span class="bt-meme-right-line">V <strong>$3.1K</strong></span>
        <span class="bt-meme-right-line"><small>FDV $5.1K</small></span>
        <span class="bt-meme-right-line" style="margin-top:3px"><span class="neu">0%</span> · Run 🏁 · 0%</span>
      </div>
    </div><div class="bt-meme-item">
      <div class="bt-meme-ico" style="background:var(--bt-accent-warm);color:#fff">A</div>
      <div class="bt-meme-main">
        <div class="bt-meme-name">Asterfund <span style="color:var(--bt-text-3);font-size:10px">Asterfund基金</span></div>
        <div class="bt-meme-meta">4m · 👤 30 · 5<span class="bt-meme-prog"><span style="width:25%"></span></span></div>
        <div class="bt-meme-tx">TX 77</div>
      </div>
      <div class="bt-meme-right">
        <span class="bt-meme-right-line">V <strong>$3.9K</strong></span>
        <span class="bt-meme-right-line"><small>FDV $6.7K</small></span>
        <span class="bt-meme-right-line" style="margin-top:3px"><span class="pos">▲ 21.9%</span> · Run 💥 · 0%</span>
      </div>
    </div></div>
  </div>
  <div class="bt-meme-col">
    <div class="bt-meme-col-head">
      <div class="bt-meme-col-title" data-i18n="meme.graduating">About to Graduate</div>
      <button class="bt-filter-pill">▾ Filter</button>
    </div>
    <div class="bt-meme-col-body">
<!-- /wp:html -->
<!-- wp:shortcode -->[bt_meme_tokens filter="about_to_graduate" limit="12"]<!-- /wp:shortcode -->
<!-- wp:html -->
</div>
      </div>
      <div class="bt-meme-right">
        <span class="bt-meme-right-line">V <strong>$10.8K</strong></span>
        <span class="bt-meme-right-line"><small>FDV $44.2K</small></span>
        <span class="bt-meme-right-line" style="margin-top:3px"><span class="pos">▲ 75.6%</span> · 44.9% · 0% · <span style="color:var(--bt-accent)">44.9%</span></span>
      </div>
    </div><div class="bt-meme-item">
      <div class="bt-meme-ico" style="background:var(--bt-text-3);color:#fff">A</div>
      <div class="bt-meme-main">
        <div class="bt-meme-name">ASTEROID <span style="color:var(--bt-text-3);font-size:10px">Asteroid</span></div>
        <div class="bt-meme-meta">22m · 👤 86 · —<span class="bt-meme-prog"><span style="width:30%"></span></span></div>
        <div class="bt-meme-tx">TX 813</div>
      </div>
      <div class="bt-meme-right">
        <span class="bt-meme-right-line">V <strong>$53.3K</strong></span>
        <span class="bt-meme-right-line"><small>FDV $13.5K</small></span>
        <span class="bt-meme-right-line" style="margin-top:3px"><span class="pos">▲ 26%</span> · 0.9% · 0% · 0.9%</span>
      </div>
    </div><div class="bt-meme-item">
      <div class="bt-meme-ico" style="background:var(--bt-accent-warm);color:#fff">从</div>
      <div class="bt-meme-main">
        <div class="bt-meme-name">从跨越到重构 <span style="color:var(--bt-text-3);font-size:10px">未来金融正在"重新编程"</span></div>
        <div class="bt-meme-meta">2d · 👤 70 · 26<span class="bt-meme-prog"><span style="width:25%"></span></span></div>
        <div class="bt-meme-tx">TX 76</div>
      </div>
      <div class="bt-meme-right">
        <span class="bt-meme-right-line">V <strong>$5K</strong></span>
        <span class="bt-meme-right-line"><small>FDV $13.2K</small></span>
        <span class="bt-meme-right-line" style="margin-top:3px"><span class="pos">▲ 24.6%</span> · Run 0% · 0%</span>
      </div>
    </div><div class="bt-meme-item">
      <div class="bt-meme-ico" style="background:#ef4444;color:#fff">B</div>
      <div class="bt-meme-main">
        <div class="bt-meme-name">BUILDERS <span style="color:var(--bt-text-3);font-size:10px">Builders</span></div>
        <div class="bt-meme-meta">10h · 👤 2 · 1<span class="bt-meme-prog"><span style="width:45%"></span></span></div>
        <div class="bt-meme-tx">TX 46</div>
      </div>
      <div class="bt-meme-right">
        <span class="bt-meme-right-line">V <strong>$7.8K</strong></span>
        <span class="bt-meme-right-line"><small>FDV $6.6K</small></span>
        <span class="bt-meme-right-line" style="margin-top:3px"><span class="pos">▲ &lt;0.1%</span> · Run 🏁 · 0%</span>
      </div>
    </div><div class="bt-meme-item">
      <div class="bt-meme-ico" style="background:#10b981;color:#fff">小</div>
      <div class="bt-meme-main">
        <div class="bt-meme-name">小行星 ASTEROID</div>
        <div class="bt-meme-meta">21h · 👤 156 · 2<span class="bt-meme-prog"><span style="width:85%"></span></span></div>
        <div class="bt-meme-tx">TX 4.2K</div>
      </div>
      <div class="bt-meme-right">
        <span class="bt-meme-right-line">V <strong>$268.3K</strong></span>
        <span class="bt-meme-right-line"><small>FDV $9.8K</small></span>
        <span class="bt-meme-right-line" style="margin-top:3px"><span class="pos">▲ 18.8%</span> · Run 💥 · 0%</span>
      </div>
    </div><div class="bt-meme-item">
      <div class="bt-meme-ico" style="background:#a78bfa;color:#fff">A</div>
      <div class="bt-meme-main">
        <div class="bt-meme-name">ASTROID <span style="color:var(--bt-text-3);font-size:10px">TheSpaceShibalnu</span></div>
        <div class="bt-meme-meta">19h · 👤 72 · 3<span class="bt-meme-prog"><span style="width:80%"></span></span></div>
        <div class="bt-meme-tx">TX 2.8K</div>
      </div>
      <div class="bt-meme-right">
        <span class="bt-meme-right-line">V <strong>$200.2K</strong></span>
        <span class="bt-meme-right-line"><small>FDV $9.7K</small></span>
        <span class="bt-meme-right-line" style="margin-top:3px"><span class="pos">▲ 25.1%</span> · Run 💥 · 0%</span>
      </div>
    </div></div>
  </div>
  <div class="bt-meme-col">
    <div class="bt-meme-col-head">
      <div class="bt-meme-col-title" data-i18n="meme.graduated">Graduated</div>
      <button class="bt-filter-pill">▾ Filter</button>
    </div>
    <div class="bt-meme-col-body">
<!-- /wp:html -->
<!-- wp:shortcode -->[bt_meme_tokens filter="graduated" limit="12"]<!-- /wp:shortcode -->
<!-- wp:html -->
</div>
      </div>
      <div class="bt-meme-right">
        <span class="bt-meme-right-line">V <strong>$206.4K</strong></span>
        <span class="bt-meme-right-line"><small>FDV $3.1K</small></span>
        <span class="bt-meme-right-line" style="margin-top:3px"><span class="pos">▲ 9.8%</span> · Run 🏁 · 0%</span>
      </div>
    </div><div class="bt-meme-item">
      <div class="bt-meme-ico" style="background:var(--bt-accent-warm);color:#fff">币</div>
      <div class="bt-meme-main">
        <div class="bt-meme-name">币安空投 <span style="color:var(--bt-text-3);font-size:10px">首发空投代币</span></div>
        <div class="bt-meme-meta">10h · 👤 161 · —</div>
        <div class="bt-meme-tx">TX 5.7K</div>
      </div>
      <div class="bt-meme-right">
        <span class="bt-meme-right-line">V <strong>$366K</strong></span>
        <span class="bt-meme-right-line"><small>FDV $4.3K</small></span>
        <span class="bt-meme-right-line" style="margin-top:3px"><span class="pos">▲ 19.9%</span> · Run -- · 0%</span>
      </div>
    </div><div class="bt-meme-item">
      <div class="bt-meme-ico" style="background:#10b981;color:#fff">金</div>
      <div class="bt-meme-main">
        <div class="bt-meme-name">金狗 Dogecoin Gold</div>
        <div class="bt-meme-meta">10h · 👤 153 · —</div>
        <div class="bt-meme-tx">TX 6.3K</div>
      </div>
      <div class="bt-meme-right">
        <span class="bt-meme-right-line">V <strong>$429K</strong></span>
        <span class="bt-meme-right-line"><small>FDV $4.5K</small></span>
        <span class="bt-meme-right-line" style="margin-top:3px"><span class="pos">▲ 16.4%</span> · Run 💥 · 0%</span>
      </div>
    </div><div class="bt-meme-item">
      <div class="bt-meme-ico" style="background:#a78bfa;color:#fff">A</div>
      <div class="bt-meme-main">
        <div class="bt-meme-name">AICZ <span style="color:var(--bt-text-3);font-size:10px">AICZ</span></div>
        <div class="bt-meme-meta">17h · 👤 3.7K · —</div>
        <div class="bt-meme-tx">TX 1.7K</div>
      </div>
      <div class="bt-meme-right">
        <span class="bt-meme-right-line">V <strong>$282K</strong></span>
        <span class="bt-meme-right-line"><small>FDV $408.7K</small></span>
        <span class="bt-meme-right-line" style="margin-top:3px"><span class="pos">▲ 4.2%</span> · Run 💥 · 1.4% · <span class="pos">▲ 1.1%</span></span>
      </div>
    </div><div class="bt-meme-item">
      <div class="bt-meme-ico" style="background:#ef4444;color:#fff">A</div>
      <div class="bt-meme-main">
        <div class="bt-meme-name">ASTEROID <span style="color:var(--bt-text-3);font-size:10px">ASTEROID</span></div>
        <div class="bt-meme-meta">21h · 👤 377 · 3</div>
        <div class="bt-meme-tx">TX 20.2K</div>
      </div>
      <div class="bt-meme-right">
        <span class="bt-meme-right-line">V <strong>$1.8M</strong></span>
        <span class="bt-meme-right-line"><small>FDV $16K</small></span>
        <span class="bt-meme-right-line" style="margin-top:3px"><span class="pos">▲ 19.4%</span> · Run 💥 · 0.2% · 0%</span>
      </div>
    </div><div class="bt-meme-item">
      <div class="bt-meme-ico" style="background:var(--bt-accent-warm);color:#fff">B</div>
      <div class="bt-meme-main">
        <div class="bt-meme-name">BNB6900 <span style="color:var(--bt-text-3);font-size:10px">BNB 6900</span></div>
        <div class="bt-meme-meta">1d · 👤 2.8K · —</div>
        <div class="bt-meme-tx">TX 106</div>
      </div>
      <div class="bt-meme-right">
        <span class="bt-meme-right-line">V <strong>$1.2K</strong></span>
        <span class="bt-meme-right-line"><small>FDV $3.2K</small></span>
        <span class="bt-meme-right-line" style="margin-top:3px"><span class="pos">▲ 8.4%</span> · Run 💥 · 0% · 0%</span>
      </div>
    </div></div>
  </div>
</div>

<div class="fxlm-signals-disclaimer" style="margin-top:24px">
  <strong>⚠ Meme coins are entertainment, not investment.</strong> Most go to zero. Treat any position as a full-risk bet and size it accordingly.
</div>

</div>

',
            ),

            // ── DEXSCAN → TOP TRADERS ──
            'dexscan-top-traders' => array(
                'title' => 'DexScan — Top Traders',
                'slug'  => 'dexscan/top-traders',
                'content' => '
<!-- DexScan shared tab bar -->
<div class="bt-dextab-wrap">
  <div class="bt-dextabs">
    <a href="' . home_url('/dexscan/trending/') . '" class="bt-dextab">✦ DexScan Tokens</a>
    <a href="' . home_url('/dexscan/signals/') . '" class="bt-dextab">Signals <span class="bt-dextab-badge">NEW</span></a>
    <a href="' . home_url('/dexscan/meme/') . '" class="bt-dextab">Meme Explorer</a>
    <a href="' . home_url('/dexscan/top-traders/') . '" class="bt-dextab active">Top Traders</a>
  </div>
  <div class="bt-dex-icons">
    <a href="#" class="bt-dex-iconbtn" title="Telegram">✈</a>
    <a href="#" class="bt-dex-iconbtn" title="RSS">📡</a>
    <a href="#" class="bt-dex-iconbtn" title="More">⋯</a>
  </div>
</div>

<div class="bt-traders-wrap">

<div class="bt-traders-controls">
  <div style="display:flex;gap:12px;align-items:center">
    <button class="bt-filter-pill" style="padding:8px 14px">🌐 All Networks ▾</button>
    <div class="bt-traders-time">
      <button>1d</button>
      <button>3d</button>
      <button class="active">7d</button>
      <button>1mo</button>
      <button>3mo</button>
    </div>
  </div>
  <button class="bt-filter-pill">▾ Filters</button>
</div>

<div class="bt-toktable-wrap">
  <table class="bt-traders-table">
    <thead>
      <tr>
        <th class="l">Wallet Address</th>
        <th>7d Realized PnL ⇅</th>
        <th>7d Realized Profit % ⇅</th>
        <th class="l">7d Realized Profit Trend</th>
        <th>7d Win Rate ⇅</th>
        <th>Avg Buy</th>
        <th class="l">Top Earning Tokens</th>
        <th>Volume ⇅</th>
        <th>Txn ⇅</th>
        <th>Last Activity</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td class="l"><div class="bt-trader-wallet"><div class="bt-trader-avatar" style="background:linear-gradient(135deg,var(--bt-accent-warm),#f97316)"></div><div><div class="bt-trader-addr">0×39…db0a</div></div><button class="bt-trader-copy">📋</button></div></td>
        <td class="bt-trader-pnl">$4.64M</td>
        <td class="bt-trader-pnl">▲ 18.23K%</td>
        <td class="l"><svg class="bt-trader-spark" viewBox="0 0 90 32" preserveAspectRatio="none"><polyline fill="none" stroke="var(--bt-accent)" stroke-width="1.5" points="0,24 10,22 20,18 30,14 40,16 50,10 60,8 70,12 80,6 90,4"/></svg></td>
        <td>66.66%</td>
        <td>$2.82K</td>
        <td class="l"><div class="bt-trader-chips"><span class="bt-trader-chip" style="background:#a78bfa">P</span><span class="bt-trader-chip" style="background:var(--bt-accent)">A</span></div></td>
        <td>$51.36K</td><td>16</td><td style="color:var(--bt-text-3);font-family:var(--bt-font-mono);font-size:12px">3d ago</td>
      </tr>
      <tr>
        <td class="l"><div class="bt-trader-wallet"><div class="bt-trader-avatar" style="background:linear-gradient(135deg,#10b981,var(--bt-accent))"></div><div><div class="bt-trader-addr">0×70…3192</div></div><button class="bt-trader-copy">📋</button></div></td>
        <td class="bt-trader-pnl">$1.64M</td>
        <td class="bt-trader-pnl">▲ 45.96%</td>
        <td class="l"><svg class="bt-trader-spark" viewBox="0 0 90 32" preserveAspectRatio="none"><polyline fill="none" stroke="var(--bt-accent)" stroke-width="1.5" points="0,20 10,18 20,22 30,8 40,12 50,16 60,14 70,18 80,22 90,20"/></svg></td>
        <td>100%</td>
        <td>$24.53K</td>
        <td class="l"><div class="bt-trader-chips"><span class="bt-trader-chip" style="background:#ef4444">V</span></div></td>
        <td>$3.81M</td><td>156</td><td style="color:var(--bt-text-3);font-family:var(--bt-font-mono);font-size:12px">2h ago</td>
      </tr>
      <tr>
        <td class="l"><div class="bt-trader-wallet"><div class="bt-trader-avatar" style="background:linear-gradient(135deg,#ef4444,#a78bfa)"></div><div><div class="bt-trader-addr">4jSF…cCGM</div></div><button class="bt-trader-copy">📋</button></div></td>
        <td class="bt-trader-pnl">$1.41M</td>
        <td class="bt-trader-pnl">▲ 66.48K%</td>
        <td class="l"><svg class="bt-trader-spark" viewBox="0 0 90 32" preserveAspectRatio="none"><polyline fill="none" stroke="var(--bt-accent)" stroke-width="1.5" points="0,24 10,20 20,14 30,10 40,8 50,6 60,14 70,20 80,22 90,20"/></svg></td>
        <td>26.66%</td>
        <td>$81.96</td>
        <td class="l"><div class="bt-trader-chips"><span class="bt-trader-chip" style="background:#a78bfa">P</span><span class="bt-trader-chip" style="background:var(--bt-accent)">A</span><span class="bt-trader-chip" style="background:var(--bt-text-3)">N</span></div></td>
        <td>$8.75M</td><td>68</td><td style="color:var(--bt-text-3);font-family:var(--bt-font-mono);font-size:12px">4h ago</td>
      </tr>
      <tr>
        <td class="l"><div class="bt-trader-wallet"><div class="bt-trader-avatar" style="background:linear-gradient(135deg,#f97316,#ef4444)"></div><div><div class="bt-trader-addr">0×3d…4766</div></div><button class="bt-trader-copy">📋</button></div></td>
        <td class="bt-trader-pnl">$1.1M</td>
        <td class="bt-trader-pnl">▲ 209.88%</td>
        <td class="l"><svg class="bt-trader-spark" viewBox="0 0 90 32" preserveAspectRatio="none"><polyline fill="none" stroke="var(--bt-accent)" stroke-width="1.5" points="0,20 10,18 20,22 30,8 40,12 50,16 60,14 70,18 80,22 90,20"/></svg></td>
        <td>15.38%</td>
        <td>$12.79K</td>
        <td class="l"><div class="bt-trader-chips"><span class="bt-trader-chip" style="background:var(--bt-text-3)">S</span><span class="bt-trader-chip" style="background:var(--bt-accent)">A</span></div></td>
        <td>$2.96M</td><td>97</td><td style="color:var(--bt-text-3);font-family:var(--bt-font-mono);font-size:12px">2d ago</td>
      </tr>
      <tr>
        <td class="l"><div class="bt-trader-wallet"><div class="bt-trader-avatar" style="background:linear-gradient(135deg,#a78bfa,#ef4444)"></div><div><div class="bt-trader-addr">0xe5…4a59</div></div><button class="bt-trader-copy">📋</button></div></td>
        <td class="bt-trader-pnl">$948.41K</td>
        <td class="bt-trader-pnl">▲ 21.28%</td>
        <td class="l"><svg class="bt-trader-spark" viewBox="0 0 90 32" preserveAspectRatio="none"><polyline fill="none" stroke="var(--bt-accent)" stroke-width="1.5" points="0,24 10,22 20,18 30,14 40,16 50,10 60,8 70,12 80,6 90,4"/></svg></td>
        <td>100%</td>
        <td>$48.96K</td>
        <td class="l"><div class="bt-trader-chips"><span class="bt-trader-chip" style="background:#a78bfa">P</span></div></td>
        <td>$10.14M</td><td>279</td><td style="color:var(--bt-text-3);font-family:var(--bt-font-mono);font-size:12px">4h ago</td>
      </tr>
      <tr>
        <td class="l"><div class="bt-trader-wallet"><div class="bt-trader-avatar" style="background:linear-gradient(135deg,#10b981,var(--bt-accent))"></div><div><div class="bt-trader-addr">0×00…3b49</div></div><button class="bt-trader-copy">📋</button></div></td>
        <td class="bt-trader-pnl">$772.45K</td>
        <td class="bt-trader-pnl">▲ 1.31K%</td>
        <td class="l"><svg class="bt-trader-spark" viewBox="0 0 90 32" preserveAspectRatio="none"><polyline fill="none" stroke="var(--bt-accent)" stroke-width="1.5" points="0,24 10,20 20,14 30,10 40,8 50,6 60,14 70,20 80,22 90,20"/></svg></td>
        <td>68.31%</td>
        <td>$200.9</td>
        <td class="l"><div class="bt-trader-chips"><span class="bt-trader-chip" style="background:#f7931a">B</span><span class="bt-trader-chip" style="background:var(--bt-accent)">A</span><span class="bt-trader-chip" style="background:#a78bfa">P</span></div></td>
        <td>$116.72K</td><td>601</td><td style="color:var(--bt-text-3);font-family:var(--bt-font-mono);font-size:12px">1h ago</td>
      </tr>
      <tr>
        <td class="l"><div class="bt-trader-wallet"><div class="bt-trader-avatar" style="background:linear-gradient(135deg,var(--bt-accent-warm),#f97316)"></div><div><div class="bt-trader-addr">88Md…bcQM</div></div><button class="bt-trader-copy">📋</button></div></td>
        <td class="bt-trader-pnl">$609.44K</td>
        <td class="bt-trader-pnl">▲ 125.51%</td>
        <td class="l"><svg class="bt-trader-spark" viewBox="0 0 90 32" preserveAspectRatio="none"><polyline fill="none" stroke="var(--bt-accent)" stroke-width="1.5" points="0,20 10,18 20,22 30,8 40,12 50,16 60,14 70,18 80,22 90,20"/></svg></td>
        <td>48.07%</td>
        <td>$1.91K</td>
        <td class="l"><div class="bt-trader-chips"><span class="bt-trader-chip" style="background:var(--bt-text-3)">S</span><span class="bt-trader-chip" style="background:var(--bt-accent-warm)">D</span><span class="bt-trader-chip" style="background:#a78bfa">P</span></div></td>
        <td>$1.35M</td><td>297</td><td style="color:var(--bt-text-3);font-family:var(--bt-font-mono);font-size:12px">1h ago</td>
      </tr>
      <tr>
        <td class="l"><div class="bt-trader-wallet"><div class="bt-trader-avatar" style="background:linear-gradient(135deg,#a78bfa,#6366f1)"></div><div><div class="bt-trader-addr">EakU…gFJy</div></div><button class="bt-trader-copy">📋</button></div></td>
        <td class="bt-trader-pnl">$479.09K</td>
        <td class="bt-trader-pnl">▲ 20.21K%</td>
        <td class="l"><svg class="bt-trader-spark" viewBox="0 0 90 32" preserveAspectRatio="none"><polyline fill="none" stroke="var(--bt-accent)" stroke-width="1.5" points="0,24 10,22 20,18 30,14 40,16 50,10 60,8 70,12 80,6 90,4"/></svg></td>
        <td>23.52%</td>
        <td>$124.73</td>
        <td class="l"><div class="bt-trader-chips"><span class="bt-trader-chip" style="background:#10b981">G</span><span class="bt-trader-chip" style="background:var(--bt-text-3)">N</span><span class="bt-trader-chip" style="background:var(--bt-accent)">A</span></div></td>
        <td>$483.83K</td><td>38</td><td style="color:var(--bt-text-3);font-family:var(--bt-font-mono);font-size:12px">2d ago</td>
      </tr>
      <tr>
        <td class="l"><div class="bt-trader-wallet"><div class="bt-trader-avatar" style="background:linear-gradient(135deg,var(--bt-accent),#a78bfa)"></div><div><div class="bt-trader-addr">0×8e…9c36</div></div><button class="bt-trader-copy">📋</button></div></td>
        <td class="bt-trader-pnl">$388.77K</td>
        <td class="bt-trader-pnl">▲ 23.3%</td>
        <td class="l"><svg class="bt-trader-spark" viewBox="0 0 90 32" preserveAspectRatio="none"><polyline fill="none" stroke="var(--bt-accent)" stroke-width="1.5" points="0,24 10,22 20,18 30,14 40,16 50,10 60,8 70,12 80,6 90,4"/></svg></td>
        <td>27.27%</td>
        <td>$10.83K</td>
        <td class="l"><div class="bt-trader-chips"><span class="bt-trader-chip" style="background:#ef4444">V</span><span class="bt-trader-chip" style="background:var(--bt-accent)">A</span><span class="bt-trader-chip" style="background:#f7931a">B</span></div></td>
        <td>$1.96M</td><td>250</td><td style="color:var(--bt-text-3);font-family:var(--bt-font-mono);font-size:12px">2h ago</td>
      </tr>
    </tbody>
  </table>
</div>

<!-- /wp:html -->
<!-- wp:shortcode -->[bt_top_traders limit="25"]<!-- /wp:shortcode -->
<!-- wp:html -->

</div>

',
            ),

            // ── WATCHLIST ──
            'watchlist' => array(
                'title' => 'Watchlist',
                'slug'  => 'watchlist',
                'content' => '
<!-- wp:html -->
[fxlm_breadcrumbs]
<div class="bt-dash-wrap">
  <div class="bt-panel-head">
    <span class="bt-panel-num">01</span>
    <span class="bt-panel-title">My Watchlist</span>
    <span class="bt-panel-live">Prices update every 60s</span>
  </div>
  <div class="bt-widget">
    <div class="bt-widget-head">
      <span class="bt-widget-title">⭐ Tracked Assets</span>
      <span style="font-size:11px;color:var(--bt-text-3)">Sign in to sync across devices</span>
    </div>
    <div class="bt-widget-body">
<!-- /wp:html -->
<!-- wp:shortcode -->[bt_watchlist_page]<!-- /wp:shortcode -->
<!-- wp:html -->
    </div>
  </div>

  <div class="bt-panel-head" style="margin-top:24px">
    <span class="bt-panel-num">02</span>
    <span class="bt-panel-title">Full Market</span>
  </div>
<!-- /wp:html -->
<!-- wp:shortcode -->[fxlm_crypto_full_table]<!-- /wp:shortcode -->
<!-- wp:html -->
</div>
<!-- /wp:html -->
',
            ),

            'portfolio' => array(
                'title' => 'Portfolio Tracker',
                'slug'  => 'portfolio',
                'content' => '
<!-- wp:html -->
[fxlm_breadcrumbs]
<div class="fxlm-page-header"><h1>📊 Portfolio Tracker</h1><p>Track your crypto holdings, P&amp;L, and returns. Free — no account required. Sign in to sync across devices.</p></div>
<!-- /wp:html -->
<!-- wp:shortcode -->[bt_portfolio_page]<!-- /wp:shortcode -->
',
            ),

            // ── CRYPTO CATEGORY PAGES ──
            'crypto-category-defi' => array(
                'title' => 'DeFi Tokens',
                'slug'  => 'crypto-category-defi',
                'content' => '
<!-- wp:html -->
[fxlm_breadcrumbs]
<!-- /wp:html -->
<!-- wp:shortcode -->[fxlm_crypto_category cat="defi" show_charts="1"]<!-- /wp:shortcode -->
<!-- wp:shortcode -->[fxlm_newsletter]<!-- /wp:shortcode -->
',
            ),
            'crypto-category-nft' => array(
                'title' => 'NFT Tokens',
                'slug'  => 'crypto-category-nft',
                'content' => '
<!-- wp:html -->
[fxlm_breadcrumbs]
<!-- /wp:html -->
<!-- wp:shortcode -->[fxlm_crypto_category cat="nft" show_charts="1"]<!-- /wp:shortcode -->
<!-- wp:shortcode -->[fxlm_newsletter]<!-- /wp:shortcode -->
',
            ),
            'crypto-category-stablecoins' => array(
                'title' => 'Stablecoins',
                'slug'  => 'crypto-category-stablecoins',
                'content' => '
<!-- wp:html -->
[fxlm_breadcrumbs]
<!-- /wp:html -->
<!-- wp:shortcode -->[fxlm_crypto_category cat="stablecoins" show_charts="1"]<!-- /wp:shortcode -->
<!-- wp:shortcode -->[fxlm_newsletter]<!-- /wp:shortcode -->
',
            ),
            'crypto-category-metaverse' => array(
                'title' => 'Metaverse Tokens',
                'slug'  => 'crypto-category-metaverse',
                'content' => '
<!-- wp:html -->
[fxlm_breadcrumbs]
<!-- /wp:html -->
<!-- wp:shortcode -->[fxlm_crypto_category cat="metaverse" show_charts="1"]<!-- /wp:shortcode -->
<!-- wp:shortcode -->[fxlm_newsletter]<!-- /wp:shortcode -->
',
            ),

            // ── EDITORIAL POLICY ──
            'editorial-policy' => array(
                'title' => 'Editorial Policy',
                'slug'  => 'editorial-policy',
                'content' => '
<!-- wp:html -->
[fxlm_breadcrumbs]
<div class="bt-pp-shell">

  <aside class="bt-pp-sidebar">
    <div class="bt-pp-sidebar-inner">
      <div class="bt-pp-sidebar-logo">Block<span>Ticker</span></div>
      <div class="bt-pp-sidebar-label">' . __bt("editorial.on_this_page") . '</div>
      <a href="#ed-workflow"    class="bt-pp-nav-link active"><span class="bt-pp-nav-dot"></span>' . __bt("editorial.nav_workflow") . '</a>
      <a href="#ed-corrections" class="bt-pp-nav-link"><span class="bt-pp-nav-dot"></span>' . __bt("editorial.nav_corrections") . '</a>
      <a href="#ed-affiliate"   class="bt-pp-nav-link"><span class="bt-pp-nav-dot"></span>' . __bt("editorial.nav_affiliate") . '</a>
      <a href="#ed-disclaimer"  class="bt-pp-nav-link"><span class="bt-pp-nav-dot"></span>' . __bt("editorial.nav_disclaimer") . '</a>
      <a href="#ed-contact"     class="bt-pp-nav-link"><span class="bt-pp-nav-dot"></span>' . __bt("editorial.nav_contact") . '</a>
    </div>
  </aside>

  <main class="bt-pp-main">
    <div class="bt-pp-eyebrow">BlockTicker</div>
    <h1 class="bt-pp-title">' . __bt("editorial.title") . '</h1>
    <p class="bt-pp-subtitle">' . __bt("editorial.subtitle") . '</p>
    <div class="bt-pp-updated">' . __bt("editorial.updated") . '</div>

    <div class="bt-pp-notice">
      <span class="bt-pp-notice-icon">🛡</span>
      <span>' . __bt("editorial.accuracy_notice") . '</span>
    </div>

    <section class="bt-pp-section" id="ed-workflow">
      <div class="bt-pp-section-label">' . __bt("editorial.workflow_label") . '</div>
      <div class="bt-pp-card-grid">
        <div class="bt-pp-card" style="border-top:3px solid var(--bt-accent)">
          <div class="bt-pp-card-icon" style="background:rgba(0,255,102,.1)">📡</div>
          <h3>' . __bt("editorial.card1_title") . '</h3>
          <p>' . __bt("editorial.card1_desc") . '</p>
        </div>
        <div class="bt-pp-card" style="border-top:3px solid #a78bfa">
          <div class="bt-pp-card-icon" style="background:rgba(167,139,250,.1)">🤖</div>
          <h3>' . __bt("editorial.card2_title") . '</h3>
          <p>' . __bt("editorial.card2_desc") . '</p>
        </div>
        <div class="bt-pp-card" style="border-top:3px solid #3b82f6">
          <div class="bt-pp-card-icon" style="background:rgba(59,130,246,.1)">🔍</div>
          <h3>' . __bt("editorial.card3_title") . '</h3>
          <p>' . __bt("editorial.card3_desc") . '</p>
        </div>
      </div>
    </section>

    <section class="bt-pp-section" id="ed-corrections">
      <div class="bt-pp-section-label">' . __bt("editorial.corrections_label") . '</div>
      <div class="bt-pp-prose">
        <p>' . __bt("editorial.corrections_intro") . ' <a href="' . home_url("/contact/") . '">' . __bt("editorial.corrections_link") . '</a>.</p>
        <ul>
          <li><strong>' . __bt("editorial.corr_minor_title") . '</strong> — ' . __bt("editorial.corr_minor_desc") . '</li>
          <li><strong>' . __bt("editorial.corr_major_title") . '</strong> — ' . __bt("editorial.corr_major_desc") . '</li>
          <li><strong>' . __bt("editorial.corr_serious_title") . '</strong> — ' . __bt("editorial.corr_serious_desc") . '</li>
        </ul>
      </div>
    </section>

    <section class="bt-pp-section" id="ed-affiliate">
      <div class="bt-pp-section-label">' . __bt("editorial.affiliate_label") . '</div>
      <div class="bt-pp-notice blue">
        <span class="bt-pp-notice-icon">💼</span>
        <span>' . __bt("editorial.affiliate_notice") . ' <a href="' . home_url("/recommended-brokers/") . '">' . __bt("editorial.affiliate_link") . '</a>. ' . __bt("editorial.affiliate_disclaimer") . '</span>
      </div>
    </section>

    <section class="bt-pp-section" id="ed-disclaimer">
      <div class="bt-pp-section-label">' . __bt("editorial.disclaimer_label") . '</div>
      <div class="bt-pp-notice amber">
        <span class="bt-pp-notice-icon">⚠️</span>
        <span>' . __bt("editorial.disclaimer_text") . '</span>
      </div>
    </section>

    <section class="bt-pp-section" id="ed-contact">
      <div class="bt-pp-section-label">' . __bt("editorial.contact_label") . '</div>
      <div class="bt-pp-notice">
        <span class="bt-pp-notice-icon">📬</span>
        <span>' . __bt("editorial.contact_text") . ' <a href="' . home_url("/contact/") . '">' . __bt("editorial.contact_link") . '</a>. ' . __bt("editorial.contact_wfu") . ' <a href="' . home_url("/write-for-us/") . '">' . __bt("editorial.wfu_link") . '</a>.</span>
      </div>
    </section>

  </main>
</div>
<!-- /wp:html -->
',
            ),

            // ── WRITE FOR US ──
            'write-for-us' => array(
                'title' => 'Write for Us',
                'slug'  => 'write-for-us',
                'content' => '
<!-- wp:html -->
[fxlm_breadcrumbs]
<div class="bt-pp-shell">

  <aside class="bt-pp-sidebar">
    <div class="bt-pp-sidebar-inner">
      <div class="bt-pp-sidebar-logo">Block<span>Ticker</span></div>
      <div class="bt-pp-sidebar-label">' . __bt("wfu.on_this_page") . '</div>
      <a href="#wfu-looking-for" class="bt-pp-nav-link active"><span class="bt-pp-nav-dot"></span>' . __bt("wfu.nav_looking_for") . '</a>
      <a href="#wfu-process"     class="bt-pp-nav-link"><span class="bt-pp-nav-dot"></span>' . __bt("wfu.nav_process") . '</a>
      <a href="#wfu-form"        class="bt-pp-nav-link"><span class="bt-pp-nav-dot"></span>' . __bt("wfu.nav_form") . '</a>
    </div>
  </aside>

  <main class="bt-pp-main">
    <div class="bt-pp-eyebrow">BlockTicker</div>
    <h1 class="bt-pp-title">' . __bt("wfu.page_title") . '</h1>
    <p class="bt-pp-subtitle">' . __bt("wfu.page_subtitle") . '</p>

    <div class="bt-pp-stat-row">
      <div class="bt-pp-stat"><div class="bt-pp-stat-num">14+</div><div class="bt-pp-stat-lbl">' . __bt("wfu.stat1") . '</div></div>
      <div class="bt-pp-stat"><div class="bt-pp-stat-num">500+</div><div class="bt-pp-stat-lbl">' . __bt("wfu.stat2") . '</div></div>
      <div class="bt-pp-stat"><div class="bt-pp-stat-num">3–5d</div><div class="bt-pp-stat-lbl">' . __bt("wfu.stat3") . '</div></div>
      <div class="bt-pp-stat"><div class="bt-pp-stat-num">Free</div><div class="bt-pp-stat-lbl">' . __bt("wfu.stat4") . '</div></div>
    </div>

    <section class="bt-pp-section" id="wfu-looking-for">
      <div class="bt-pp-section-label">' . __bt("wfu.looking_for_label") . '</div>
      <div class="bt-pp-criteria">
        <div class="bt-pp-criterion"><span class="bt-pp-criterion-tick">✓</span><span>' . __bt("wfu.crit1") . '</span></div>
        <div class="bt-pp-criterion"><span class="bt-pp-criterion-tick">✓</span><span>' . __bt("wfu.crit2") . '</span></div>
        <div class="bt-pp-criterion"><span class="bt-pp-criterion-tick">✓</span><span>' . __bt("wfu.crit3") . '</span></div>
        <div class="bt-pp-criterion"><span class="bt-pp-criterion-tick">✓</span><span>' . __bt("wfu.crit4") . '</span></div>
        <div class="bt-pp-criterion"><span class="bt-pp-criterion-tick">✓</span><span>' . __bt("wfu.crit5") . '</span></div>
        <div class="bt-pp-criterion"><span class="bt-pp-criterion-tick">✓</span><span>' . __bt("wfu.crit6") . '</span></div>
      </div>
      <div class="bt-pp-notice red" style="margin-top:12px">
        <span class="bt-pp-notice-icon">✗</span>
        <span>' . __bt("wfu.no_promo") . '</span>
      </div>
    </section>

    <section class="bt-pp-section" id="wfu-process">
      <div class="bt-pp-section-label">' . __bt("wfu.process_label") . '</div>
      <div class="bt-pp-steps">
        <div class="bt-pp-step"><div class="bt-pp-step-num">1</div><div><h4>' . __bt("wfu.step1_title") . '</h4><p>' . __bt("wfu.step1_desc") . '</p></div></div>
        <div class="bt-pp-step"><div class="bt-pp-step-num">2</div><div><h4>' . __bt("wfu.step2_title") . '</h4><p>' . __bt("wfu.step2_desc") . '</p></div></div>
        <div class="bt-pp-step"><div class="bt-pp-step-num">3</div><div><h4>' . __bt("wfu.step3_title") . '</h4><p>' . __bt("wfu.step3_desc") . '</p></div></div>
        <div class="bt-pp-step"><div class="bt-pp-step-num">4</div><div><h4>' . __bt("wfu.step4_title") . '</h4><p>' . __bt("wfu.step4_desc") . '</p></div></div>
      </div>
    </section>

    <section class="bt-pp-section" id="wfu-form">
      <div class="bt-pp-section-label">' . __bt("wfu.form_label") . '</div>
<!-- /wp:html -->
<!-- wp:shortcode -->[bt_write_for_us]<!-- /wp:shortcode -->
<!-- wp:html -->
    </section>

  </main>
</div>
<!-- /wp:html -->
',
            ),

            // ── API DOCS — v67 ──
            'api-docs' => array(
                'title' => 'API Documentation',
                'slug'  => 'api-docs',
                'content' => '<!-- wp:shortcode -->[bt_api_docs]<!-- /wp:shortcode -->',
            ),

            // ── WIDGETS GALLERY — v68 ──
            'widgets' => array(
                'title' => 'Embeddable Widgets',
                'slug'  => 'widgets',
                'content' => '<!-- wp:shortcode -->[bt_widget_gallery]<!-- /wp:shortcode -->',
            ),

            // ── PRIVACY ──
            'privacy-policy' => array(
                'title' => 'Privacy Policy',
                'slug'  => 'privacy-policy',
                'content' => '
<!-- wp:html -->
[fxlm_breadcrumbs]
<div class="bt-pp-shell">

  <aside class="bt-pp-sidebar">
    <div class="bt-pp-sidebar-inner">
      <div class="bt-pp-sidebar-logo">Block<span>Ticker</span></div>
      <div class="bt-pp-sidebar-label">' . __bt("privacy.contents") . '</div>
      <a href="#pp-1"  class="bt-pp-nav-link active"><span class="bt-pp-nav-num">01</span>' . __bt("privacy.nav1") . '</a>
      <a href="#pp-2"  class="bt-pp-nav-link"><span class="bt-pp-nav-num">02</span>' . __bt("privacy.nav2") . '</a>
      <a href="#pp-3"  class="bt-pp-nav-link"><span class="bt-pp-nav-num">03</span>' . __bt("privacy.nav3") . '</a>
      <a href="#pp-4"  class="bt-pp-nav-link"><span class="bt-pp-nav-num">04</span>' . __bt("privacy.nav4") . '</a>
      <a href="#pp-5"  class="bt-pp-nav-link"><span class="bt-pp-nav-num">05</span>' . __bt("privacy.nav5") . '</a>
      <a href="#pp-6"  class="bt-pp-nav-link"><span class="bt-pp-nav-num">06</span>' . __bt("privacy.nav6") . '</a>
      <a href="#pp-7"  class="bt-pp-nav-link"><span class="bt-pp-nav-num">07</span>' . __bt("privacy.nav7") . '</a>
      <a href="#pp-8"  class="bt-pp-nav-link"><span class="bt-pp-nav-num">08</span>' . __bt("privacy.nav8") . '</a>
      <a href="#pp-9"  class="bt-pp-nav-link"><span class="bt-pp-nav-num">09</span>' . __bt("privacy.nav9") . '</a>
      <a href="#pp-10" class="bt-pp-nav-link"><span class="bt-pp-nav-num">10</span>' . __bt("privacy.nav10") . '</a>
      <a href="#pp-11" class="bt-pp-nav-link"><span class="bt-pp-nav-num">11</span>' . __bt("privacy.nav11") . '</a>
    </div>
  </aside>

  <main class="bt-pp-main">
    <div class="bt-pp-eyebrow">BlockTicker</div>
    <h1 class="bt-pp-title">' . __bt("privacy.title") . '</h1>
    <p class="bt-pp-subtitle">' . __bt("privacy.subtitle") . '</p>
    <div class="bt-pp-updated">' . __bt("privacy.updated") . ' ' . date("F j, Y") . '</div>

    <div class="bt-pp-notice">
      <span class="bt-pp-notice-icon">🔒</span>
      <span>' . __bt("privacy.intro_notice") . '</span>
    </div>

    <section class="bt-pp-section" id="pp-1">
      <div class="bt-pp-section-head"><div class="bt-pp-section-num">01</div><h2 class="bt-pp-section-title">' . __bt("privacy.s1_title") . '</h2></div>
      <div class="bt-pp-prose">
        <h3>' . __bt("privacy.s1_direct_h") . '</h3>
        <ul>
          <li><strong>' . __bt("privacy.s1_newsletter") . '</strong> — ' . __bt("privacy.s1_newsletter_desc") . '</li>
          <li><strong>' . __bt("privacy.s1_contact") . '</strong> — ' . __bt("privacy.s1_contact_desc") . '</li>
        </ul>
        <h3>' . __bt("privacy.s1_auto_h") . '</h3>
        <ul>
          <li><strong>' . __bt("privacy.s1_usage") . '</strong> — ' . __bt("privacy.s1_usage_desc") . '</li>
          <li><strong>' . __bt("privacy.s1_ip") . '</strong> — ' . __bt("privacy.s1_ip_desc") . '</li>
          <li><strong>' . __bt("privacy.s1_cookies") . '</strong> — ' . __bt("privacy.s1_cookies_desc") . '</li>
        </ul>
        <h3>' . __bt("privacy.s1_not_h") . '</h3>
        <p>' . __bt("privacy.s1_not_desc") . '</p>
      </div>
    </section>

    <section class="bt-pp-section" id="pp-2">
      <div class="bt-pp-section-head"><div class="bt-pp-section-num">02</div><h2 class="bt-pp-section-title">' . __bt("privacy.s2_title") . '</h2></div>
      <div class="bt-pp-table-wrap">
        <table class="bt-pp-table">
          <thead><tr><th>' . __bt("privacy.col_purpose") . '</th><th>' . __bt("privacy.col_data") . '</th><th>' . __bt("privacy.col_basis") . '</th></tr></thead>
          <tbody>
            <tr><td><strong>' . __bt("privacy.s2_r1_purpose") . '</strong></td><td>' . __bt("privacy.s2_r1_data") . '</td><td><span class="bt-pp-badge bt-pp-badge-green">' . __bt("privacy.basis_consent") . '</span></td></tr>
            <tr><td><strong>' . __bt("privacy.s2_r2_purpose") . '</strong></td><td>' . __bt("privacy.s2_r2_data") . '</td><td><span class="bt-pp-badge bt-pp-badge-blue">' . __bt("privacy.basis_legit") . '</span></td></tr>
            <tr><td><strong>' . __bt("privacy.s2_r3_purpose") . '</strong></td><td>' . __bt("privacy.s2_r3_data") . '</td><td><span class="bt-pp-badge bt-pp-badge-blue">' . __bt("privacy.basis_legit") . '</span></td></tr>
            <tr><td><strong>' . __bt("privacy.s2_r4_purpose") . '</strong></td><td>' . __bt("privacy.s2_r4_data") . '</td><td><span class="bt-pp-badge bt-pp-badge-green">' . __bt("privacy.basis_consent") . '</span></td></tr>
            <tr><td><strong>' . __bt("privacy.s2_r5_purpose") . '</strong></td><td>' . __bt("privacy.s2_r5_data") . '</td><td><span class="bt-pp-badge bt-pp-badge-blue">' . __bt("privacy.basis_legit") . '</span></td></tr>
          </tbody>
        </table>
      </div>
    </section>

    <section class="bt-pp-section" id="pp-3">
      <div class="bt-pp-section-head"><div class="bt-pp-section-num">03</div><h2 class="bt-pp-section-title">' . __bt("privacy.s3_title") . '</h2></div>
      <div class="bt-pp-prose">
        <ul>
          <li><strong>' . __bt("privacy.s3_consent") . '</strong> — ' . __bt("privacy.s3_consent_desc") . '</li>
          <li><strong>' . __bt("privacy.s3_legit") . '</strong> — ' . __bt("privacy.s3_legit_desc") . '</li>
          <li><strong>' . __bt("privacy.s3_legal") . '</strong> — ' . __bt("privacy.s3_legal_desc") . '</li>
        </ul>
        <p>' . __bt("privacy.s3_withdraw") . '</p>
      </div>
    </section>

    <section class="bt-pp-section" id="pp-4">
      <div class="bt-pp-section-head"><div class="bt-pp-section-num">04</div><h2 class="bt-pp-section-title">' . __bt("privacy.s4_title") . '</h2></div>
      <div class="bt-pp-table-wrap">
        <table class="bt-pp-table">
          <thead><tr><th>' . __bt("privacy.col_cookie") . '</th><th>' . __bt("privacy.col_provider") . '</th><th>' . __bt("privacy.col_cookie_purpose") . '</th><th>' . __bt("privacy.col_duration") . '</th></tr></thead>
          <tbody>
            <tr><td><code>_ga, _gid, _gat</code></td><td>Google Analytics</td><td>' . __bt("privacy.s4_ga_purpose") . '</td><td>' . __bt("privacy.s4_ga_duration") . '</td></tr>
            <tr><td><code>__gads, IDE</code></td><td>Google AdSense</td><td>' . __bt("privacy.s4_ads_purpose") . '</td><td>' . __bt("privacy.s4_ads_duration") . '</td></tr>
            <tr><td><code>bt_watchlist</code></td><td>BlockTicker</td><td>' . __bt("privacy.s4_watch_purpose") . '</td><td>' . __bt("privacy.s4_watch_duration") . '</td></tr>
            <tr><td><code>gdpr_consent</code></td><td>BlockTicker</td><td>' . __bt("privacy.s4_consent_purpose") . '</td><td>' . __bt("privacy.s4_consent_duration") . '</td></tr>
          </tbody>
        </table>
      </div>
      <p style="font-size:13px;color:var(--bt-pp-text2);margin-top:10px">' . __bt("privacy.s4_essential") . '</p>
    </section>

    <section class="bt-pp-section" id="pp-5">
      <div class="bt-pp-section-head"><div class="bt-pp-section-num">05</div><h2 class="bt-pp-section-title">' . __bt("privacy.s5_title") . '</h2></div>
      <div class="bt-pp-prose">
        <ul>
          <li><strong>Google Analytics</strong> — <a href="https://policies.google.com/privacy" target="_blank" rel="noopener">' . __bt("privacy.s5_privacy_policy") . ' ↗</a></li>
          <li><strong>Google AdSense</strong> — <a href="https://policies.google.com/technologies/ads" target="_blank" rel="noopener">' . __bt("privacy.s5_ads_policy") . ' ↗</a></li>
          <li><strong>CoinGecko API</strong> — ' . __bt("privacy.s5_coingecko") . '</li>
          <li><strong>Frankfurter.app</strong> — ' . __bt("privacy.s5_frankfurter") . '</li>
          <li><strong>Alternative.me</strong> — ' . __bt("privacy.s5_altme") . '</li>
          <li><strong>TradingView</strong> — ' . __bt("privacy.s5_tv_desc") . ' <a href="https://www.tradingview.com/policies/" target="_blank" rel="noopener">' . __bt("privacy.s5_tv_link") . ' ↗</a></li>
        </ul>
        <p>' . __bt("privacy.s5_no_sell") . '</p>
      </div>
    </section>

    <section class="bt-pp-section" id="pp-6">
      <div class="bt-pp-section-head"><div class="bt-pp-section-num">06</div><h2 class="bt-pp-section-title">' . __bt("privacy.s6_title") . '</h2></div>
      <div class="bt-pp-prose">
        <ul>
          <li><strong>' . __bt("privacy.s6_newsletter") . '</strong> — ' . __bt("privacy.s6_newsletter_desc") . '</li>
          <li><strong>' . __bt("privacy.s6_contact") . '</strong> — ' . __bt("privacy.s6_contact_desc") . '</li>
          <li><strong>' . __bt("privacy.s6_logs") . '</strong> — ' . __bt("privacy.s6_logs_desc") . '</li>
          <li><strong>' . __bt("privacy.s6_analytics") . '</strong> — ' . __bt("privacy.s6_analytics_desc") . '</li>
        </ul>
      </div>
    </section>

    <section class="bt-pp-section" id="pp-7">
      <div class="bt-pp-section-head"><div class="bt-pp-section-num">07</div><h2 class="bt-pp-section-title">' . __bt("privacy.s7_title") . '</h2></div>
      <div class="bt-pp-rights">
        <div class="bt-pp-right"><strong>' . __bt("privacy.s7_access") . '</strong>' . __bt("privacy.s7_access_desc") . '</div>
        <div class="bt-pp-right"><strong>' . __bt("privacy.s7_rectify") . '</strong>' . __bt("privacy.s7_rectify_desc") . '</div>
        <div class="bt-pp-right"><strong>' . __bt("privacy.s7_erasure") . '</strong>' . __bt("privacy.s7_erasure_desc") . '</div>
        <div class="bt-pp-right"><strong>' . __bt("privacy.s7_restrict") . '</strong>' . __bt("privacy.s7_restrict_desc") . '</div>
        <div class="bt-pp-right"><strong>' . __bt("privacy.s7_portability") . '</strong>' . __bt("privacy.s7_portability_desc") . '</div>
        <div class="bt-pp-right"><strong>' . __bt("privacy.s7_object") . '</strong>' . __bt("privacy.s7_object_desc") . '</div>
        <div class="bt-pp-right"><strong>' . __bt("privacy.s7_withdraw") . '</strong>' . __bt("privacy.s7_withdraw_desc") . '</div>
        <div class="bt-pp-right"><strong>' . __bt("privacy.s7_complaint") . '</strong>' . __bt("privacy.s7_complaint_desc") . '</div>
      </div>
      <p style="font-size:13px;color:var(--bt-pp-text2);margin-top:12px">' . __bt("privacy.s7_how") . ' <a href="' . home_url("/contact/") . '">' . __bt("privacy.s7_contact_link") . '</a>. ' . __bt("privacy.s7_reference") . '</p>
    </section>

    <section class="bt-pp-section" id="pp-8">
      <div class="bt-pp-section-head"><div class="bt-pp-section-num">08</div><h2 class="bt-pp-section-title">' . __bt("privacy.s8_title") . '</h2></div>
      <div class="bt-pp-prose">
        <ul>
          <li>' . __bt("privacy.s8_https") . '</li>
          <li>' . __bt("privacy.s8_wp") . '</li>
          <li>' . __bt("privacy.s8_access") . '</li>
          <li>' . __bt("privacy.s8_backups") . '</li>
        </ul>
        <p>' . __bt("privacy.s8_no_guarantee") . '</p>
      </div>
    </section>

    <section class="bt-pp-section" id="pp-9">
      <div class="bt-pp-section-head"><div class="bt-pp-section-num">09</div><h2 class="bt-pp-section-title">' . __bt("privacy.s9_title") . '</h2></div>
      <div class="bt-pp-notice amber">
        <span class="bt-pp-notice-icon">⚠️</span>
        <span>' . __bt("privacy.s9_disclaimer") . '</span>
      </div>
    </section>

    <section class="bt-pp-section" id="pp-10">
      <div class="bt-pp-section-head"><div class="bt-pp-section-num">10</div><h2 class="bt-pp-section-title">' . __bt("privacy.s10_title") . '</h2></div>
      <div class="bt-pp-prose"><p>' . __bt("privacy.s10_text") . '</p></div>
    </section>

    <section class="bt-pp-section" id="pp-11">
      <div class="bt-pp-section-head"><div class="bt-pp-section-num">11</div><h2 class="bt-pp-section-title">' . __bt("privacy.s11_title") . '</h2></div>
      <div class="bt-pp-notice">
        <span class="bt-pp-notice-icon">📬</span>
        <span>' . __bt("privacy.s11_text") . ' <a href="' . home_url("/contact/") . '">' . __bt("privacy.s11_link") . '</a>. ' . __bt("privacy.s11_reference") . '</span>
      </div>
    </section>

  </main>
</div>
<!-- /wp:html -->
',
            ),
        );
    }

    /**
     * Find a page reliably by post_name (slug), ignoring path hierarchy.
     * get_page_by_path() fails for slugs containing slashes when parent doesn't exist.
     */
    private static function get_page_by_slug( $slug ) {
        // Strip any path prefix — use only the final segment as post_name
        $post_name = sanitize_title( basename( $slug ) );
        // Also try the full slug sanitized (replaces / with -)
        $slug_flat = sanitize_title( str_replace( '/', '-', $slug ) );

        $q = new WP_Query( array(
            'post_type'      => 'page',
            'post_status'    => array( 'publish', 'draft' ),
            'posts_per_page' => 1,
            'no_found_rows'  => true,
            'post_name__in'  => array( $post_name, $slug_flat, $slug ),
            'orderby'        => 'date',
            'order'          => 'ASC',
        ) );
        return $q->have_posts() ? $q->posts[0] : null;
    }

    /**
     * Delete duplicate pages — keeps the OLDEST matching each title/slug,
     * trashes all others. Safe to run multiple times.
     */
    public static function delete_duplicates() {
        $pages = self::get_pages_config();
        $deleted = 0;

        foreach ( $pages as $page ) {
            $post_name = sanitize_title( basename( $page['slug'] ) );
            $slug_flat = sanitize_title( str_replace( '/', '-', $page['slug'] ) );

            $q = new WP_Query( array(
                'post_type'      => 'page',
                'post_status'    => 'any',
                'posts_per_page' => -1,
                'no_found_rows'  => true,
                'post_name__in'  => array( $post_name, $slug_flat ),
                'orderby'        => 'ID',
                'order'          => 'ASC',
            ) );

            if ( ! $q->have_posts() || count( $q->posts ) <= 1 ) continue;

            // Keep oldest (first), trash the rest
            $keep = true;
            foreach ( $q->posts as $p ) {
                if ( $keep ) { $keep = false; continue; }
                wp_delete_post( $p->ID, true ); // true = force delete (skip trash)
                $deleted++;
            }
        }
        return $deleted;
    }

    /**
     * Remove only Yoast's Indexable_Post_Watcher from wp_insert_post.
     * Returns the removed callbacks so we can re-add them after.
     * This prevents the count(false) fatal without breaking MySQL state.
     */
    private static function remove_yoast_watcher() {
        $removed = array();
        global $wp_filter;
        $hooks_to_check = array( 'wp_insert_post', 'save_post', 'edit_post' );
        foreach ( $hooks_to_check as $hook ) {
            if ( ! isset( $wp_filter[ $hook ] ) ) continue;
            foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
                foreach ( $callbacks as $key => $cb ) {
                    $fn = $cb['function'];
                    $is_yoast = false;
                    if ( is_array( $fn ) && is_object( $fn[0] ) ) {
                        $class = get_class( $fn[0] );
                        if ( strpos( $class, 'Yoast' ) !== false || strpos( $class, 'Indexable' ) !== false ) {
                            $is_yoast = true;
                        }
                    } elseif ( is_string( $fn ) && strpos( $fn, 'yoast' ) !== false ) {
                        $is_yoast = true;
                    }
                    if ( $is_yoast ) {
                        $removed[] = array( 'hook' => $hook, 'priority' => $priority, 'key' => $key, 'cb' => $cb );
                        unset( $wp_filter[ $hook ]->callbacks[ $priority ][ $key ] );
                    }
                }
            }
        }
        return $removed;
    }

    private static function restore_yoast_watcher( $removed ) {
        global $wp_filter;
        foreach ( $removed as $item ) {
            if ( ! isset( $wp_filter[ $item['hook'] ] ) ) continue;
            $wp_filter[ $item['hook'] ]->callbacks[ $item['priority'] ][ $item['key'] ] = $item['cb'];
        }
    }

    public static function create_all() {
        // Remove Yoast indexable watcher hooks during page creation.
        // Yoast hooks into wp_insert_post/save_post and calls do_shortcode()
        // which triggers count(false) fatal on PHP 8+ in sc_crypto_category.
        // We surgically remove ONLY Yoast callbacks (not all hooks) and restore after.
        $yoast_removed = self::remove_yoast_watcher();

        // Also suppress shortcode execution as a secondary safety net
        $sc_suppressed = false;
        if ( ! has_filter( 'do_shortcode_tag', '__return_empty_string' ) ) {
            add_filter( 'do_shortcode_tag', '__return_empty_string', 999 );
            $sc_suppressed = true;
        }
        $pages   = self::get_pages_config();
        $created = array();
        $skipped = array();

        foreach ( $pages as $key => $page ) {
            // Use reliable slug lookup (handles slugs with slashes)
            $existing = self::get_page_by_slug( $page['slug'] );
            // Compute the flat post_name (no slashes — WP can't store slashes in post_name)
            $post_name = sanitize_title( str_replace( '/', '-', $page['slug'] ) );

            if ( $existing ) {
                $skipped[] = $page['title'];
                wp_update_post( array(
                    'ID'           => $existing->ID,
                    'post_content' => $page['content'],
                    'post_status'  => 'publish',
                    'post_name'    => $post_name,
                ) );
                continue;
            }

            $id = wp_insert_post( array(
                'post_title'   => $page['title'],
                'post_name'    => $post_name,
                'post_content' => $page['content'],
                'post_status'  => 'publish',
                'post_type'    => 'page',
                'post_author'  => 1,
            ) );

            if ( $id && ! is_wp_error( $id ) ) {
                $created[] = $page['title'];
                if ( $key === 'home' ) {
                    update_option( 'show_on_front', 'page' );
                    update_option( 'page_on_front', $id );
                }
            }
        }

        // Restore Yoast hooks and shortcode filter
        self::restore_yoast_watcher( $yoast_removed );
        if ( $sc_suppressed ) {
            remove_filter( 'do_shortcode_tag', '__return_empty_string', 999 );
        }

        // Set pages to default template (GeneratePress handles full-width automatically)
        $all_pages = get_pages();
        foreach ( $all_pages as $p ) {
            update_post_meta( $p->ID, '_wp_page_template', 'default' );
        }

        $msg = '';
        if ( ! empty( $created ) ) $msg .= 'Created: ' . implode( ', ', $created ) . '. ';
        if ( ! empty( $skipped ) ) $msg .= 'Updated: ' . implode( ', ', $skipped ) . '.';

        // v119.28.11: Also create all 62 landing-revamp destinations
        // (gainers-losers, methodology, top-lists, commodities, indices, every news
        // category, every forex pair, etc.) so a single click of "Create All Pages Now"
        // makes every menu link in the new BlockTicker navigation work.
        $extra = 0;
        if ( class_exists( 'BT_Page_Provisioner' ) ) {
            $extra = BT_Page_Provisioner::create_all_for_landing();
        }
        if ( $extra > 0 ) {
            $msg .= " Plus {$extra} navigation page(s) from the new landing menu.";
        }

        return array( 'success' => true, 'message' => $msg ?: 'All pages processed.' );
    }

    public static function create_menus() {
        $menu_name = 'Main Navigation';
        $menu_id   = wp_create_nav_menu( $menu_name );
        if ( is_wp_error( $menu_id ) ) {
            $menu    = get_term_by( 'name', $menu_name, 'nav_menu' );
            $menu_id = $menu ? $menu->term_id : 0;
        }

        // Clear existing menu items first (fix v5 duplication)
        $existing_items = wp_get_nav_menu_items( $menu_id );
        if ( $existing_items ) {
            foreach ( $existing_items as $item ) {
                wp_delete_post( $item->ID, true );
            }
        }

        $pages = array(
            'Home'      => 'home',
            'Markets'   => 'crypto-markets',
            'Forex'     => 'forex-charts',
            'News'      => 'financial-news',
            'Signals'   => 'trading-signals',
            'Analysis'  => 'market-analysis',
            'Calendar'  => 'economic-calendar',
            'Tools'     => 'tools',
            'Learn'     => 'learn',
            'Blog'      => 'market-blog',
            'Brokers'   => 'recommended-brokers',
        );
        foreach ( $pages as $title => $slug ) {
            $page = get_page_by_path( $slug );
            if ( $page ) {
                wp_update_nav_menu_item( $menu_id, 0, array(
                    'menu-item-title'     => $title,
                    'menu-item-object'    => 'page',
                    'menu-item-object-id' => $page->ID,
                    'menu-item-type'      => 'post_type',
                    'menu-item-status'    => 'publish',
                ) );
            }
        }

        // Assign to ALL registered menu locations (works for GeneratePress, Astra, and others)
        $locations = array();
        $registered = get_registered_nav_menus();
        foreach ( array_keys( $registered ) as $loc ) {
            $locations[ $loc ] = $menu_id;
        }
        // Set common GeneratePress and Astra locations explicitly
        $locations['primary']   = $menu_id;
        $locations['main_menu'] = $menu_id;
        set_theme_mod( 'nav_menu_locations', $locations );

        // Footer menu
        $footer_id = wp_create_nav_menu( 'Footer Menu' );
        if ( is_wp_error( $footer_id ) ) {
            $m = get_term_by( 'name', 'Footer Menu', 'nav_menu' );
            $footer_id = $m ? $m->term_id : 0;
        }

        // Clear existing footer items
        $existing_footer = wp_get_nav_menu_items( $footer_id );
        if ( $existing_footer ) {
            foreach ( $existing_footer as $item ) {
                wp_delete_post( $item->ID, true );
            }
        }

        foreach ( array( 'About' => 'about', 'Contact' => 'contact', 'Privacy Policy' => 'privacy-policy', 'Brokers' => 'recommended-brokers', 'Learn' => 'learn' ) as $t => $s ) {
            $page = get_page_by_path( $s );
            if ( $page ) wp_update_nav_menu_item( $footer_id, 0, array( 'menu-item-title' => $t, 'menu-item-object' => 'page', 'menu-item-object-id' => $page->ID, 'menu-item-type' => 'post_type', 'menu-item-status' => 'publish' ) );
        }
        $locations['footer'] = $footer_id;
        set_theme_mod( 'nav_menu_locations', $locations );

        return array( 'success' => true, 'message' => 'Menus created and assigned to all theme locations (fixes duplicate menu issue).' );
    }
}
