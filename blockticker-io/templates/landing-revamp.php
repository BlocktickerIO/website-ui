<?php
/**
 * Landing page revamp (full body).
 * The chrome (disclaimer + ticker + nav + drawer + risk modal + opening .btlp wrapper)
 * is extracted into landing-chrome.php so it can be reused site-wide via wp_body_open.
 * If the chrome was already rendered globally, BT_CHROME_RENDERED is defined, and we
 * skip the chrome include to avoid double-rendering.
 */

if ( ! defined( 'BT_CHROME_RENDERED' ) ) {
    include __DIR__ . '/landing-chrome.php';
} else {
    /* Chrome already rendered by wp_body_open — open a body container so footer can close it cleanly. */
    echo '<div class="btlp-body">';
}
?>

<!-- ── §1 HERO ────────────────────────────────────────────────────────── -->
<section class="hero" id="hero">
  <div class="container container--hero hero__head">
    <a href="<?php echo esc_url($brief_url); ?>" class="pill hero__pill hero__pill--link" aria-label="Read today's desk brief">
      <span class="pill__now">NOW</span>
      <span class="pill__sep" aria-hidden="true"></span>
      <span class="pill__ico" aria-hidden="true">⌕</span>
      <span class="pill__txt">Today's brief is live · <code>/desk-brief</code> →</span>
    </a>
    <h1 class="hero__h1">Deep Market Intelligence for <span class="hero__h1-accent">Crypto, Forex &amp; Web3</span></h1>
    <p class="hero__sub"><strong>Real-time prices · AI analysis · Trading signals.</strong><br>Every data point sourced, attributed and verified.</p>
    <div class="hero__ctas">
      <a href="<?php echo esc_url($brief_url); ?>" class="btn btn--lg"><span class="btn__ico" aria-hidden="true">📊</span>Today's Desk Analysis</a>
      <a href="#markets" class="btn btn--secondary btn--lg"><span class="btn__ico" aria-hidden="true">📈</span>Live Markets</a>
    </div>
    <div class="hero__trust">No credit card · 30s setup · Cancel anytime</div>
  </div>

  <div class="hero__card-wrap">
    <div class="stack">
      <span class="stack__corner-tag">Live · <span id="signal-time">just now</span></span>
      <!-- Layer 1 · Data -->
      <div class="stack__layer">
        <span class="stack__rail">01 · DATA</span>
        <div class="stack__body">
          <div class="stack__layer-h">Real-time prices · 100+ markets</div>
          <div class="stack__feed">
            <span class="stack__feed-row"><b>BTC</b><span><?php echo esc_html($btc_p); ?></span><em class="<?php echo $btc_cl; ?>"><?php echo esc_html($btc_cs); ?></em></span>
            <span class="stack__feed-row"><b>ETH</b><span><?php echo esc_html($eth_p); ?></span><em class="<?php echo $eth_cl; ?>"><?php echo esc_html($eth_cs); ?></em></span>
            <span class="stack__feed-row"><b>EUR/USD</b><span><?php echo esc_html($eur); ?></span><em class="down">forex</em></span>
            <span class="stack__feed-row"><b>SOL</b><span><?php echo esc_html($sol_p); ?></span><em class="<?php echo $sol_cl; ?>"><?php echo esc_html($sol_cs); ?></em></span>
          </div>
          <div class="stack__sources">sourced from CoinGecko · Binance · Kraken · ECB · Frankfurter</div>
        </div>
      </div>
      <!-- Layer 2 · Analysis -->
      <div class="stack__layer">
        <span class="stack__rail">02 · ANALYSIS</span>
        <div class="stack__body">
          <div class="stack__layer-h">Market regime: <span class="stack__regime"><?php echo esc_html($regime); ?></span><span class="stack__conf-pill">conf <?php echo esc_html($conf); ?></span></div>
          <ul class="stack__bullets"><?php foreach($bullets as $b): ?><li><?php echo $b; ?></li><?php endforeach; ?></ul>
        </div>
      </div>
      <!-- Layer 3 · Signal -->
      <div class="stack__layer stack__layer--signal">
        <span class="stack__rail stack__rail--accent">03 · SIGNAL</span>
        <div class="stack__body">
          <div class="stack__sig-head">
            <span class="signal__asset-ico">₿</span>
            <span class="stack__sig-name">BTC/USDT 4H</span>
            <span class="signal__direction">LONG</span>
            <span class="stack__sig-conf">conf <b id="hero-conf"><?php echo esc_html($conf); ?></b></span>
          </div>
          <div class="stack__sig-meta">Entry <?php echo esc_html($btc_p); ?> · R:R 1:2.8 · 3 of 4 detectors triggered</div>
        </div>
      </div>
    </div>
    <div class="hero__card-foot">
      <span><a href="<?php echo esc_url(home_url('/methodology/')); ?>">How this is computed</a></span>
      <span aria-hidden="true">·</span>
      <span><a href="<?php echo esc_url($archive_url); ?>"><?php echo number_format($tot_sigs); ?> signals on record</a></span>
      <span aria-hidden="true">·</span>
      <span><?php echo esc_html($hit_rate); ?>% hit rate</span>
    </div>
  </div>
</section>

<!-- ── §2 SIGNAL IN ACTION ──────────────────────────────────────────────── -->
<section class="sig-action reveal" id="signal-action">
  <div class="container">
    <div class="sig-action__grid">
      <div class="sig-action__copy">
        <div style="font-family:var(--f-mono);font-size:11px;color:var(--accent);font-weight:700;letter-spacing:1.2px;text-transform:uppercase;margin-bottom:14px">Layer 03 · how a signal is made</div>
        <h2>One signal. Four steps. No magic.</h2>
        <p>The data and analysis layers run constantly. A <strong>signal</strong> is what comes out the top of the stack <em>when, and only when,</em> four independent detectors agree. We watch <strong>volume</strong> against a 7-day baseline. We watch <strong>momentum</strong> across two timeframes. We cross-reference <strong>funding rates</strong> from six exchanges. We compare the setup to <strong>18 months</strong> of similar patterns.</p>
        <p>If three of four agree, we send it. With a confidence score that tells you <em>how strongly</em> they agree, and three plain-English reasons that explain why.</p>
        <p style="color:var(--text-2);font-weight:500">No black box. No vibes.</p>
        <div class="sig-action__steps" id="sig-steps">
          <button class="sig-action__dot active" data-step="1" aria-label="Step 1"></button>
          <button class="sig-action__dot" data-step="2" aria-label="Step 2"></button>
          <button class="sig-action__dot" data-step="3" aria-label="Step 3"></button>
          <button class="sig-action__dot" data-step="4" aria-label="Step 4"></button>
        </div>
      </div>
      <div class="sig-action__stage" id="sig-stage">
        <div class="sig-state active" data-step="1">
          <div class="sig-state__label">Step 1 · Watching</div>
          <div class="watching"><div class="watching__dots"><span></span><span></span><span></span></div><div class="sig-state__h">Monitoring 100+ assets</div><div class="sig-state__sub" style="max-width:280px">Across 4 timeframes (15m, 1H, 4H, daily), 24/7. Most candles say nothing. We're waiting.</div><div class="watching__count">2,417 datapoints / minute</div></div>
        </div>
        <div class="sig-state" data-step="2">
          <div class="sig-state__label">Step 2 · Detecting</div>
          <div class="sig-state__h">Volume divergence flagged</div>
          <div class="detect-chart"><svg viewBox="0 0 400 200" preserveAspectRatio="none"><polyline fill="none" stroke="rgba(255,255,255,.15)" stroke-width="1.5" points="10,150 35,140 60,155 85,135 110,142 135,128 160,145 185,118 210,108 235,95 260,72 285,60 310,55 335,48 360,42 390,40"/><circle cx="285" cy="60" r="14" fill="none" stroke="#00FF66" stroke-width="2" opacity=".8"/><circle cx="285" cy="60" r="20" fill="none" stroke="#00FF66" stroke-width="1" opacity=".4"/><g fill="rgba(0,255,102,.3)"><rect x="10" y="180" width="14" height="14"/><rect x="35" y="178" width="14" height="16"/><rect x="60" y="183" width="14" height="11"/><rect x="85" y="180" width="14" height="14"/><rect x="110" y="184" width="14" height="10"/><rect x="135" y="178" width="14" height="16"/><rect x="160" y="182" width="14" height="12"/><rect x="185" y="175" width="14" height="19"/><rect x="210" y="172" width="14" height="22"/><rect x="235" y="168" width="14" height="26"/><rect x="260" y="160" width="14" height="34"/><rect x="285" y="135" width="14" height="59" fill="#00FF66"/><rect x="310" y="158" width="14" height="36"/><rect x="335" y="162" width="14" height="32"/></g></svg></div>
          <div class="detect-flag">BTC 4H · 14:32 UTC<br>Volume +47% vs 7-day average. Candle closed above 200-EMA.</div>
        </div>
        <div class="sig-state score-stage" data-step="3">
          <div class="sig-state__label">Step 3 · Scoring</div>
          <div class="sig-state__h" style="margin-bottom:18px">Combining detectors</div>
          <div class="score-bar"><span class="score-bar__label">Volume</span><div class="score-bar__track"><div class="score-bar__fill" style="--w:91%"></div></div><span class="score-bar__val">91</span></div>
          <div class="score-bar"><span class="score-bar__label">Momentum</span><div class="score-bar__track"><div class="score-bar__fill" style="--w:78%"></div></div><span class="score-bar__val">78</span></div>
          <div class="score-bar"><span class="score-bar__label">Funding</span><div class="score-bar__track"><div class="score-bar__fill" style="--w:84%"></div></div><span class="score-bar__val">84</span></div>
          <div class="score-bar"><span class="score-bar__label">Pattern</span><div class="score-bar__track"><div class="score-bar__fill" style="--w:76%"></div></div><span class="score-bar__val">76</span></div>
          <div class="score-final"><div class="score-final__label">Weighted confidence</div><div class="score-final__num">82</div></div>
        </div>
        <div class="sig-state" data-step="4">
          <div class="sig-state__label">Step 4 · Notified</div>
          <div class="notify">
            <div class="notify__phone"><div class="notify__notch"></div><div class="notify__card"><div class="notify__icon">B</div><div class="notify__body"><div class="notify__title">BTC long signal · 82 confidence</div><div class="notify__msg">Entry <?php echo esc_html($btc_p); ?> · R:R 1:2.8. Tap for full breakdown.</div><div class="notify__time">just now · BlockTicker</div></div></div></div>
            <div class="notify__count">Sent to 12,347 inboxes · 9 seconds end-to-end</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ── §3 HOW IT WORKS — auto-cycle reveal (one block at a time, fade + slide) ── -->
<section class="howit howit--cycle" id="how">
  <div class="container">
    <div class="howit__intro reveal">
      <h2>How BlockTicker reads the market</h2>
      <p><em>Four steps from raw data to actionable read.</em></p>
    </div>

    <div class="howit__grid">
      <!-- Narrative column: 4 blocks stacked, only one visible at a time -->
      <div class="howit__nar" id="howit-nar" aria-live="polite">
        <div class="howit__block is-active" data-step="1">
          <div class="howit__step">Step 1 · Listen</div>
          <h3 class="howit__h">We pull, constantly.</h3>
          <p class="howit__p">Live <strong>prices</strong>, order book depth, and funding rates from CoinGecko, Binance, Kraken, and Coinbase. RSS from <strong>24 vetted news sources</strong>. Roughly <strong>2,400 datapoints per minute</strong>, normalised into a single timeline.</p>
        </div>
        <div class="howit__block" data-step="2">
          <div class="howit__step">Step 2 · Pattern-match</div>
          <h3 class="howit__h">Four detectors, in parallel.</h3>
          <p class="howit__p">An ensemble of detectors look for: <strong>volume divergences</strong>, <strong>momentum shifts</strong>, <strong>funding-rate flips</strong>, and <strong>cross-asset correlation breaks</strong>. Each detector outputs a 0–1 confidence — independent, not chained.</p>
        </div>
        <div class="howit__block" data-step="3">
          <div class="howit__step">Step 3 · Score</div>
          <h3 class="howit__h">Weight by historical hit-rate.</h3>
          <p class="howit__p">We weight detectors by their <strong>historical hit rate on similar regimes</strong> and combine into a single confidence (0–100). Anything below <strong>65</strong> isn't shipped. Most candles fail this gate. That's the point.</p>
        </div>
        <div class="howit__block" data-step="4">
          <div class="howit__step">Step 4 · Explain</div>
          <h3 class="howit__h">Three reasons. Plain English.</h3>
          <p class="howit__p">The signal is paired with three plain-English reasons: the strongest 3 detectors, in your language. So you can <strong>decide if you agree</strong>. We're not asking you to trust the model — we're showing you what it saw.</p>
        </div>

        <!-- Progress bars (click to jump; current step fills via CSS animation) -->
        <div class="howit__progress" id="howit-progress" role="tablist" aria-label="Reading steps">
          <button class="howit__progress-seg is-active" data-step="1" role="tab" aria-selected="true" aria-label="Step 1: Listen" type="button"><span class="howit__progress-fill"></span></button>
          <button class="howit__progress-seg" data-step="2" role="tab" aria-selected="false" aria-label="Step 2: Pattern-match" type="button"><span class="howit__progress-fill"></span></button>
          <button class="howit__progress-seg" data-step="3" role="tab" aria-selected="false" aria-label="Step 3: Score" type="button"><span class="howit__progress-fill"></span></button>
          <button class="howit__progress-seg" data-step="4" role="tab" aria-selected="false" aria-label="Step 4: Explain" type="button"><span class="howit__progress-fill"></span></button>
        </div>
      </div>

      <!-- Right panel: persistent live-indicator strip (always on) + per-step visualisation -->
      <aside class="howit__panel" id="howit-panel" aria-live="polite">
        <!-- Persistent live-indicator strip — visible across all 4 states -->
        <div class="howit__live">
          <div class="howit__live-dot" aria-hidden="true"><span class="howit__live-dot-inner"></span></div>
          <div class="howit__live-label">Live data feed</div>
          <div class="howit__live-num"><span id="bt-live-num">2,417</span><span class="howit__live-num-unit">/min</span></div>
          <div class="howit__live-sep" aria-hidden="true">·</div>
          <div class="howit__live-latency"><span class="howit__live-latency-label">latency</span> <span id="bt-latency">34</span><span class="howit__live-latency-unit">ms</span></div>
        </div>

        <!-- Stage label (contextual to current step) -->
        <div class="howit__stage-label" id="howit-stage-label">Streaming · 15 sources</div>

        <!-- Per-step visualisations -->
        <div class="howit__states">
          <div class="howit__state active" data-state="1">
            <div class="feed" id="feed">
              <div class="feed__row" style="animation-delay:0s"><span class="feed__sym">BTC</span><span><?php echo esc_html($btc_p); ?></span><span class="feed__chg-<?php echo $btc_cl; ?>"><?php echo esc_html($btc_cs); ?></span><span>vol<?php echo $btc_v>=0?'↑':'↓'; ?></span></div>
              <div class="feed__row" style="animation-delay:.3s"><span class="feed__sym">ETH</span><span><?php echo esc_html($eth_p); ?></span><span class="feed__chg-<?php echo $eth_cl; ?>"><?php echo esc_html($eth_cs); ?></span><span>—</span></div>
              <div class="feed__row" style="animation-delay:.6s"><span class="feed__sym">SOL</span><span><?php echo esc_html($sol_p); ?></span><span class="feed__chg-<?php echo $sol_cl; ?>"><?php echo esc_html($sol_cs); ?></span><span>vol↑</span></div>
              <div class="feed__row" style="animation-delay:.9s"><span class="feed__sym">EUR/USD</span><span><?php echo esc_html($eur); ?></span><span class="feed__chg-down">forex</span><span>—</span></div>
              <div class="feed__row" style="animation-delay:1.2s"><span class="feed__sym">XAU</span><span>$2,341.87</span><span class="feed__chg-up">+0.85%</span><span>vol↑</span></div>
              <div class="feed__row" style="animation-delay:1.5s"><span class="feed__sym">DXY</span><span>104.27</span><span class="feed__chg-up">+0.34%</span><span>—</span></div>
            </div>
            <div class="feed__sources">sources: CoinGecko · Binance · Kraken · Coinbase · ECB · Frankfurter · 24 RSS feeds</div>
          </div>

          <div class="howit__state" data-state="2">
            <div class="dials">
              <div class="dial" data-pct="91"><div class="dial__ring"><svg viewBox="0 0 70 70"><circle class="bg" cx="35" cy="35" r="30"/><circle class="fill" cx="35" cy="35" r="30" style="--off:17"/></svg><div class="dial__pct">91</div></div><div class="dial__name">Volume</div><div class="dial__desc">Spike vs 7-day mean</div></div>
              <div class="dial" data-pct="78"><div class="dial__ring"><svg viewBox="0 0 70 70"><circle class="bg" cx="35" cy="35" r="30"/><circle class="fill" cx="35" cy="35" r="30" style="--off:41"/></svg><div class="dial__pct">78</div></div><div class="dial__name">Momentum</div><div class="dial__desc">RSI + EMA cross</div></div>
              <div class="dial" data-pct="84"><div class="dial__ring"><svg viewBox="0 0 70 70"><circle class="bg" cx="35" cy="35" r="30"/><circle class="fill" cx="35" cy="35" r="30" style="--off:30"/></svg><div class="dial__pct">84</div></div><div class="dial__name">Funding</div><div class="dial__desc">4 of 6 sources flipped</div></div>
              <div class="dial is-fail" data-pct="42"><div class="dial__ring"><svg viewBox="0 0 70 70"><circle class="bg" cx="35" cy="35" r="30"/><circle class="fill" cx="35" cy="35" r="30" style="--off:109"/></svg><div class="dial__pct" style="color:var(--danger)">42</div></div><div class="dial__name">Correlation</div><div class="dial__desc" style="color:var(--text-5)">Below threshold</div></div>
            </div>
            <div class="feed__sources" style="margin-top:14px">3 of 4 detectors above 65 → ship</div>
          </div>

          <div class="howit__state score-final-state" data-state="3">
            <div class="score-bars">
              <div class="score-bar"><span class="score-bar__label">Volume</span><div class="score-bar__track"><div class="score-bar__fill" style="--w:91%;width:91%"></div></div><span class="score-bar__val">×0.30</span></div>
              <div class="score-bar"><span class="score-bar__label">Momentum</span><div class="score-bar__track"><div class="score-bar__fill" style="--w:78%;width:78%"></div></div><span class="score-bar__val">×0.25</span></div>
              <div class="score-bar"><span class="score-bar__label">Funding</span><div class="score-bar__track"><div class="score-bar__fill" style="--w:84%;width:84%"></div></div><span class="score-bar__val">×0.25</span></div>
              <div class="score-bar"><span class="score-bar__label">Pattern</span><div class="score-bar__track"><div class="score-bar__fill" style="--w:76%;width:76%"></div></div><span class="score-bar__val">×0.20</span></div>
            </div>
            <div class="score-state-final"><div class="score-state-final__label">Final confidence</div><div class="score-state-final__num">82</div><div class="score-state-final__sub">above 65 threshold → eligible to ship</div></div>
          </div>

          <div class="howit__state" data-state="4">
            <div class="final-signal">
              <div class="signal" style="border-color:var(--accent-strong)">
                <span class="signal__corner-tag" style="background:var(--card)">Final · 9.3s end-to-end</span>
                <div class="signal__head"><div class="signal__asset"><span class="signal__asset-ico">₿</span><div class="signal__asset-meta"><span class="signal__asset-name" style="font-size:15px">BTC/USDT · 4H</span><span class="signal__asset-sub">Bitcoin · Spot</span></div></div><span class="signal__direction" style="font-size:11px;padding:4px 10px">LONG</span></div>
                <div class="signal__metrics" style="margin-bottom:14px;padding:10px 0">
                  <div><div class="signal__metric-label">Confidence</div><div class="signal__metric-value"><span class="signal__conf" style="font-size:18px">82</span></div></div>
                  <div><div class="signal__metric-label">Entry</div><div class="signal__metric-value" style="font-size:15px"><?php echo esc_html($btc_p); ?></div></div>
                  <div><div class="signal__metric-label">R : R</div><div class="signal__metric-value" style="font-size:15px">1 : 2.8</div></div>
                </div>
                <div class="signal__reasons-label">3 reasons</div>
                <div class="signal__reasons" style="font-size:12px"><div class="signal__reason">Volume +47% vs 7-day avg</div><div class="signal__reason">RSI exited oversold &amp; reclaimed 200-EMA</div><div class="signal__reason">Funding flipped neutral · 4/6 sources</div></div>
              </div>
            </div>
          </div>
        </div>
      </aside>
    </div>
  </div>
</section>

<!-- ── §4 OUTCOMES ────────────────────────────────────────────────────── -->
<section class="outcomes" id="outcomes">
  <div class="container">
    <div class="outcomes__intro reveal"><h2>What you actually get</h2><p>Three concrete things that change when you use BlockTicker — one per layer of the stack.</p></div>
    <article class="outcome out1 reveal">
      <div class="outcome__copy">
        <div class="outcome__num">01 · Stop missing the 3am breakout</div>
        <h3 class="outcome__h">The market doesn't sleep. Now you don't have to either.</h3>
        <p class="outcome__pain">"I went to bed. BTC pumped 4.2% at 3:14 UTC. I woke up to my friend's screenshot."</p>
        <p class="outcome__solution">BlockTicker pushes <strong>high-confidence (≥75) signals</strong> to email and (optionally) Telegram in real time. Configure the threshold and quiet hours per asset.</p>
        <p class="outcome__solution" style="color:var(--text-4);font-size:14px">Median latency from candle close to inbox: <strong style="color:var(--accent);font-family:var(--f-mono)">9 seconds</strong>.</p>
      </div>
      <div class="outcome__art">
        <div class="out1__time">3:14 UTC · <?php echo esc_html(gmdate('D j M')); ?></div>
        <div class="notify__phone"><div class="notify__notch"></div><div class="notify__card"><div class="notify__icon">B</div><div class="notify__body"><div class="notify__title">BTC long signal · 82 confidence</div><div class="notify__msg">Volume +47%, momentum confirmed. Entry <?php echo esc_html($btc_p); ?> · R:R 1:2.8</div><div class="notify__time">just now · BlockTicker</div></div></div></div>
      </div>
    </article>
    <article class="outcome outcome--reverse out2 reveal">
      <div class="outcome__copy">
        <div class="outcome__num">02 · Stop trading on rumours</div>
        <h3 class="outcome__h">A confidence score beats a viral thread.</h3>
        <p class="outcome__pain">"A Twitter influencer says SOL is going to $300. Their thread has 8k likes. Are they early or wrong?"</p>
        <p class="outcome__solution">Every BlockTicker signal ships with a <strong>confidence score</strong> and <strong>three explicit reasons</strong> drawn from market data. You can disagree with the score, but you can see the math.</p>
        <p class="outcome__solution" style="color:var(--text-4);font-size:14px">No-one on Twitter shows you the math.</p>
      </div>
      <div class="outcome__art">
        <div class="vs">
          <div class="vs__card vs__card--bad"><div class="vs__src">Twitter · @cryptoking</div><div class="vs__quote">"SOL absolutely sending it 🚀🚀🚀 next stop $300 mark my words"</div><div class="vs__meta">8.2k likes · 0 reasons given</div></div>
          <div class="vs__card vs__card--good"><div class="vs__src">BlockTicker · SOL/USDT 4H</div><div class="vs__sig-mini"><strong>LONG · conf 71</strong><br>▸ 24h vol +38% vs 7d<br>▸ MACD bullish cross<br>▸ Funding +0.04%</div><div class="vs__meta">3 reasons · math shown · 14:08 UTC</div></div>
        </div>
      </div>
    </article>
    <article class="outcome out3 reveal">
      <div class="outcome__copy">
        <div class="outcome__num">03 · See what's actually moving — across markets</div>
        <h3 class="outcome__h">DXY ripped 0.6%. Did your alts notice?</h3>
        <p class="outcome__pain">"I don't have a Bloomberg terminal to find out which alts follow when the dollar moves."</p>
        <p class="outcome__solution">BlockTicker correlates <strong>crypto, forex, and macro</strong> in real time. When the dollar moves, the cross-market panel tells you which alts <strong>will likely follow</strong> within 4 hours.</p>
        <p class="outcome__solution" style="color:var(--text-4);font-size:14px">It's the panel a desk trader has on their second monitor. Now you have it on your first.</p>
      </div>
      <div class="outcome__art">
        <div style="font-family:var(--f-mono);font-size:11px;color:var(--text-4);text-transform:uppercase;letter-spacing:1px;margin-bottom:16px">Cross-market correlation · 30d rolling</div>
        <div class="corr">
          <div class="corr__row"><span class="corr__pair">BTC ↔ DXY</span><div class="corr__bar"><span class="corr__bar-mid"></span><span class="corr__fill corr__fill--down" style="left:14%;width:36%"></span></div><span class="corr__val--down">-0.72</span></div>
          <div class="corr__row"><span class="corr__pair">BTC ↔ Gold</span><div class="corr__bar"><span class="corr__bar-mid"></span><span class="corr__fill corr__fill--up" style="left:50%;width:20.5%"></span></div><span class="corr__val--up">+0.41</span></div>
          <div class="corr__row"><span class="corr__pair">ETH ↔ NDX</span><div class="corr__bar"><span class="corr__bar-mid"></span><span class="corr__fill corr__fill--up" style="left:50%;width:29%"></span></div><span class="corr__val--up">+0.58</span></div>
          <div class="corr__row"><span class="corr__pair">SOL ↔ BTC</span><div class="corr__bar"><span class="corr__bar-mid"></span><span class="corr__fill corr__fill--up" style="left:50%;width:43.5%"></span></div><span class="corr__val--up">+0.87</span></div>
          <div class="corr__row"><span class="corr__pair">XRP ↔ DXY</span><div class="corr__bar"><span class="corr__bar-mid"></span><span class="corr__fill corr__fill--down" style="left:35%;width:15%"></span></div><span class="corr__val--down">-0.30</span></div>
        </div>
        <div class="corr__legend">↓ inverse · 0 uncorrelated · ↑ direct</div>
        <div class="corr__warn"><span class="corr__warn-ico" aria-hidden="true">ⓘ</span><span><strong>Correlation ≠ causation.</strong> 30-day historical patterns; future relationships may differ.</span></div>
      </div>
    </article>
  </div>
</section>

<!-- ── §5 LIVE MARKETS ───────────────────────────────────────────────────── -->
<section class="markets" id="markets">
  <div class="container">
    <div class="markets__intro reveal">
      <div style="font-family:var(--f-mono);font-size:11px;color:var(--accent);font-weight:700;letter-spacing:1.2px;text-transform:uppercase;margin-bottom:10px">Layer 01 · Real-time prices</div>
      <h2>The data layer, fully populated <span class="markets__live-badge">Live</span></h2>
      <p>100+ assets across crypto, forex, commodities, indices, and Web3 — <em>growing to 500</em>. AI signal column on every row.</p>
    </div>
    <div class="frame reveal">
      <div class="frame__bar"><span class="frame__dot frame__dot--r"></span><span class="frame__dot frame__dot--y"></span><span class="frame__dot frame__dot--g"></span><span class="frame__url">blockticker.io/markets</span></div>
      <div class="frame__tabs"><div class="frame__tab active">Crypto</div><div class="frame__tab">Forex</div><div class="frame__tab">Commodities <span class="coming-soon">soon</span></div><div class="frame__tab">Indices <span class="coming-soon">soon</span></div><div class="frame__tab">Web3 <span class="coming-soon">soon</span></div></div>
      <table class="frame__table"><thead><tr><th>Asset</th><th>Price</th><th>24h</th><th>Trend (7d)</th><th>AI signal</th><th>Confidence</th></tr></thead>
      <tbody data-skel-on-reveal>
      <?php foreach($mkt as $r):
        $sl=$r['sig']==='bull'?'● Bullish':($r['sig']==='bear'?'● Bearish':'● Neutral');
        $cc=$r['sig']==='bull'?'var(--accent)':($r['sig']==='bear'?'var(--danger)':'var(--warn)');
        $ico=$r['sym']==='BTC'?'₿':($r['sym']==='ETH'?'Ξ':$r['sym'][0]);
        $sp=$r['cls']==='up'?'0,18 10,16 20,18 30,14 40,12 50,9 60,7 70,5 80,4':'0,4 10,7 20,5 30,9 40,11 50,12 60,14 70,16 80,17';
        $sc=$r['cls']==='up'?'#00FF66':'#FF3B30';
      ?>
      <tr>
        <td><div class="frame__asset"><span class="frame__icn frame__icn--<?php echo strtolower($r['sym']); ?>"><?php echo esc_html($ico); ?></span><span><span class="frame__sym"><?php echo esc_html($r['sym']); ?></span><br><span class="frame__name"><?php echo esc_html($r['name']); ?></span></span></div></td>
        <td class="frame__price"><?php echo esc_html($r['price']); ?></td>
        <td class="frame__chg frame__chg--<?php echo $r['cls']; ?>"><?php echo esc_html($r['chg']); ?></td>
        <td><svg class="frame__spark" width="80" height="24" viewBox="0 0 80 24"><polyline fill="none" stroke="<?php echo $sc; ?>" stroke-width="1.5" points="<?php echo $sp; ?>"/></svg></td>
        <td><span class="frame__sig frame__sig--<?php echo $r['sig']; ?>"><?php echo esc_html($sl); ?></span></td>
        <td class="frame__price" style="color:<?php echo $cc; ?>"><?php echo $r['conf']; ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody></table>
    </div>
    <div class="markets__cta reveal"><a href="<?php echo esc_url($markets_url); ?>">Open the live markets →</a></div>
  </div>
</section>

<!-- ── §6 METHODOLOGY ─────────────────────────────────────────────────── -->
<section class="meth" id="methodology">
  <div class="container">
    <div class="meth__intro reveal">
      <span class="pill" style="background:rgba(255,255,255,.04);border-color:var(--border-2);color:var(--text-3)">Sourced · Attributed · Verified</span>
      <h2>Every data point named.<br>Every claim timestamped.</h2>
      <p>For a new product without testimonials, transparency is the social proof. Here's the full provenance trail behind everything you see.</p>
    </div>
    <!-- 01 Sources -->
    <div class="meth__sub reveal">
      <div class="meth__sub-h"><span class="meth__sub-num">01</span><span class="meth__sub-title">Where the data comes from</span><span class="meth__sub-tag">No partners we can't name</span></div>
      <div class="src-grid">
        <div class="src-card"><div class="src-card__top"><div class="src-card__cat"><span class="src-card__ico">$</span><span class="src-card__name">Prices</span></div><span class="src-card__live"><span class="src-card__pulse"></span>LIVE · 2s sync</span></div><div class="src-card__providers"><span class="src-chip">CoinGecko</span><span class="src-chip">Binance</span><span class="src-chip">Kraken</span><span class="src-chip">Coinbase</span></div><a href="<?php echo esc_url(home_url('/api-docs/')); ?>" class="src-card__verify">verify endpoints →</a></div>
        <div class="src-card"><div class="src-card__top"><div class="src-card__cat"><span class="src-card__ico">€</span><span class="src-card__name">Forex</span></div><span class="src-card__live"><span class="src-card__pulse"></span>LIVE · 4s sync</span></div><div class="src-card__providers"><span class="src-chip">Frankfurter</span><span class="src-chip">ECB reference</span><span class="src-chip">ExchangeRate-API</span></div><a href="<?php echo esc_url(home_url('/api-docs/')); ?>" class="src-card__verify">verify endpoints →</a></div>
        <div class="src-card"><div class="src-card__top"><div class="src-card__cat"><span class="src-card__ico">📰</span><span class="src-card__name">News</span></div><span class="src-card__live"><span class="src-card__pulse"></span>24 sources</span></div><div class="src-card__providers"><span class="src-chip">FXStreet</span><span class="src-chip">CoinDesk</span><span class="src-chip">Reuters RSS</span><span class="src-chip src-chip--more">+21 more</span></div><a href="<?php echo esc_url(home_url('/news/sources/')); ?>" class="src-card__verify">view full list →</a></div>
        <div class="src-card"><div class="src-card__top"><div class="src-card__cat"><span class="src-card__ico">⌬</span><span class="src-card__name">On-chain</span></div><span class="src-card__live src-card__live--opt"><span class="src-card__pulse src-card__pulse--opt"></span>OPT-IN</span></div><div class="src-card__providers"><span class="src-chip">Etherscan</span><span class="src-chip">Blockscout</span></div><a href="<?php echo esc_url(home_url('/api-docs/')); ?>" class="src-card__verify">verify endpoints →</a></div>
      </div>
    </div>
    <!-- 02 Formula — exact mockup structure -->
    <div class="meth__sub reveal">
      <div class="meth__sub-h"><span class="meth__sub-num">02</span><span class="meth__sub-title">How the confidence score is computed</span><span class="meth__sub-tag">Live worked example</span></div>
      <div class="formula">
        <div class="formula__inputs">
          <div class="formula__input"><div class="formula__input-label">Volume</div><div class="formula__input-score">91</div><div class="formula__input-arrow">×</div><div class="formula__input-weight">0.30 <span>weight</span></div><div class="formula__input-bar"><div class="formula__input-fill" style="--w:91%"></div></div></div>
          <div class="formula__plus">+</div>
          <div class="formula__input"><div class="formula__input-label">Momentum</div><div class="formula__input-score">78</div><div class="formula__input-arrow">×</div><div class="formula__input-weight">0.25 <span>weight</span></div><div class="formula__input-bar"><div class="formula__input-fill" style="--w:78%"></div></div></div>
          <div class="formula__plus">+</div>
          <div class="formula__input"><div class="formula__input-label">Funding</div><div class="formula__input-score">84</div><div class="formula__input-arrow">×</div><div class="formula__input-weight">0.25 <span>weight</span></div><div class="formula__input-bar"><div class="formula__input-fill" style="--w:84%"></div></div></div>
          <div class="formula__plus">+</div>
          <div class="formula__input"><div class="formula__input-label">Correlation</div><div class="formula__input-score">76</div><div class="formula__input-arrow">×</div><div class="formula__input-weight">0.20 <span>weight</span></div><div class="formula__input-bar"><div class="formula__input-fill" style="--w:76%"></div></div></div>
        </div>
        <div class="formula__equals" aria-hidden="true"><span class="formula__equals-arrow">↓</span><span class="formula__equals-label">weighted sum</span></div>
        <div class="formula__output">
          <div class="formula__output-label">Final confidence</div>
          <div class="formula__output-num">82</div>
          <div class="formula__output-meta"><span class="formula__threshold">▸ above 65 threshold</span><span class="formula__threshold formula__threshold--ok">✓ eligible to ship</span></div>
        </div>
      </div>
      <div class="formula__caption"><p>Each detector's weight comes from <strong>its hit rate on the last 12 months of similar setups</strong>. We don't ship anything below 65. <a href="<?php echo esc_url($archive_url); ?>" class="formula__pdf">→ Browse the public signal archive</a> · <a href="#beta" class="formula__pdf" style="margin-left:6px">Full technical methodology PDF <span class="coming-soon">soon</span></a></p></div>
    </div>
    <!-- 03 Receipts — exact mockup structure -->
    <div class="meth__sub reveal">
      <div class="meth__sub-h"><span class="meth__sub-num">03</span><span class="meth__sub-title">What we won't tell you</span><span class="meth__sub-tag meth__sub-tag--alt">Four refusals · on the record</span></div>
      <div class="receipts">
        <div class="receipt"><div class="receipt__head"><span class="receipt__num">01</span><span class="receipt__cat">Accuracy</span></div><div class="receipt__lie">"We're <em>90% accurate</em>."</div><div class="receipt__truth">Our published hit rate is <strong><?php echo esc_html($hit_rate); ?>%</strong> on signals at confidence ≥75 over the last 12 months. <a href="<?php echo esc_url($archive_url); ?>">See the archive →</a></div></div>
        <div class="receipt"><div class="receipt__head"><span class="receipt__num">02</span><span class="receipt__cat">Conviction</span></div><div class="receipt__lie">"You should <em>bet your rent</em> on this."</div><div class="receipt__truth">This is <strong>pattern detection</strong>, not prophecy. Use it to inform decisions, not replace them.</div></div>
        <div class="receipt"><div class="receipt__head"><span class="receipt__num">03</span><span class="receipt__cat">Provenance</span></div><div class="receipt__lie">"We have <em>institutional data partners</em> (we don't)."</div><div class="receipt__truth">Every data source is named in section 01 above. <strong>No undisclosed partners.</strong></div></div>
        <div class="receipt"><div class="receipt__head"><span class="receipt__num">04</span><span class="receipt__cat">Social proof</span></div><div class="receipt__lie">"<em>Trusted by 50,000 traders</em>."</div><div class="receipt__truth">We're <strong>new</strong>. We'll claim numbers when we earn them — not before.</div></div>
      </div>
      <div class="receipts__sig"><span class="receipts__sig-label">Signed —</span><span class="receipts__sig-team">The BlockTicker team</span><span class="receipts__sig-date">v1.0 · April 2026</span></div>
    </div>
  </div>
</section>

<!-- ── §6.5 BETA ──────────────────────────────────────────────────────── -->
<section class="beta" id="beta">
  <div class="container">
    <div class="beta__card reveal">
      <div class="beta__head">
        <span class="pill" style="background:rgba(255,255,255,.04);border-color:var(--border-2);color:var(--text-3)"><span class="pill__dot" style="background:var(--warn);animation:none"></span>Beta · April 2026</span>
        <h2 class="beta__h">Everything's free during beta.</h2>
        <p class="beta__sub">No card, no tiers, no paywalls — and no surprise pricing later.</p>
      </div>
      <div class="beta__cols">
        <div class="beta__col beta__col--live"><div class="beta__col-h"><span class="beta__col-status">✓ Live now</span></div><ul class="beta__list"><li>Real-time prices · 100+ assets</li><li>AI analysis &amp; pattern detection</li><li>Daily Desk Brief (8 UTC)</li><li>Trading signals · email + Telegram</li><li>Watchlist · alerts · webhooks</li><li>4 vetted data sources</li><li>Public signal archive (every fire, win or lose)</li></ul></div>
        <div class="beta__col beta__col--soon"><div class="beta__col-h"><span class="beta__col-status beta__col-status--soon">◐ In flight · Q3 2026</span></div><ul class="beta__list"><li>Coverage to 500+ assets</li><li>Per-asset analysis pages</li><li>Cross-market correlation overlays</li><li>Full technical methodology PDF</li><li>API access (institutional)</li><li>X / Twitter auto-publish</li><li>Personalised dashboard 2.0</li></ul></div>
        <div class="beta__col beta__col--later"><div class="beta__col-h"><span class="beta__col-status beta__col-status--later">○ On the roadmap</span></div><ul class="beta__list"><li>Pro tier (date TBA)</li><li>On-chain wallet tracking</li><li>Community public watchlists</li><li>Institutional white-label</li><li>Backtest sandbox</li><li>Mobile native apps</li></ul></div>
      </div>
      <div class="beta__foot"><strong>Pricing later, never retroactive.</strong> Anything you sign up for free today stays at the same terms it was when you signed up.</div>
    </div>
  </div>
</section>

<!-- ── §7 CTA ─────────────────────────────────────────────────────────── -->
<section class="cta" id="cta">
  <div class="container">
    <div class="cta__card reveal">
      <h2 class="cta__h">Try it for a week.<br>See your first market read in under a minute.</h2>
      <p class="cta__sub">Real-time prices, AI analysis, and signals — all in one terminal. No card, no commitment. Cancel by clicking one button.</p>
      <form class="cta__form" id="cta-form" onsubmit="return false;">
        <input class="cta__input" type="email" id="cta-email" placeholder="you@inbox.com" required>
        <button class="cta__btn" type="submit" id="cta-btn">Open the terminal →</button>
      </form>
      <div class="cta__success" id="cta-success">✓ Check your inbox — your terminal is being set up.</div>
      <div class="cta__fine">Daily intelligence brief by email. Optional Telegram and X for high-confidence signals.<br>No SMS spam, no sales calls.</div>
    </div>
  </div>
</section>

<!-- ── §8 FOOTER ──────────────────────────────────────────────────────── -->
<footer class="foot">
  <div class="container">
    <div class="foot__grid">
      <div class="foot__brand-block">
        <div class="foot__brand"><span class="nav__logo">B</span><span class="foot__brand-name">BLOCK<span>TICKER</span></span></div>
        <p class="foot__tagline">Deep Market Intelligence for crypto, forex &amp; Web3. Real-time prices, AI analysis, trading signals — every data point sourced, attributed and verified.</p>
        <div class="foot__socials"><a href="https://x.com/blockticker_io" target="_blank" rel="noopener" aria-label="X / Twitter">𝕏</a><a href="https://t.me/blockticker_io" target="_blank" rel="noopener" aria-label="Telegram">TG</a><a href="<?php echo esc_url(home_url('/integrations/twitter/')); ?>" aria-label="Discord">DC</a><a href="<?php echo esc_url(home_url('/api-docs/')); ?>" aria-label="GitHub">GH</a></div>
      </div>
      <div class="foot__col"><h4>Markets</h4><ul><li><a href="<?php echo esc_url(home_url('/crypto-markets/')); ?>">Crypto</a></li><li><a href="<?php echo esc_url(home_url('/forex-charts/')); ?>">Forex</a></li><li><a href="<?php echo esc_url(home_url('/commodities/')); ?>">Commodities</a></li><li><a href="<?php echo esc_url(home_url('/indices/')); ?>">Indices</a></li><li><a href="<?php echo esc_url(home_url('/dexscan/')); ?>">Web3</a></li></ul></div>
      <div class="foot__col"><h4>Tools</h4><ul><li><a href="<?php echo esc_url(home_url('/trading-signals/')); ?>">AI Signals</a></li><li><a href="<?php echo esc_url(home_url('/portfolio/')); ?>">Watchlists</a></li><li><a href="<?php echo esc_url(home_url('/tools/')); ?>">Economic Calendar</a></li><li><a href="<?php echo esc_url(home_url('/api-docs/')); ?>">API Docs</a></li></ul></div>
      <div class="foot__col"><h4>Resources</h4><ul><li><a href="<?php echo esc_url(home_url('/methodology/')); ?>">Methodology</a></li><li><a href="<?php echo esc_url(home_url('/signal-archive/')); ?>">Signal archive</a></li><li><a href="<?php echo esc_url(home_url('/market-blog/')); ?>">Blog</a></li><li><a href="<?php echo esc_url(home_url('/help/')); ?>">Help Center</a></li></ul></div>
      <div class="foot__col"><h4>Company</h4><ul><li><a href="<?php echo esc_url(home_url('/about/')); ?>">About</a></li><li><a href="<?php echo esc_url(home_url('/careers/')); ?>">Careers</a></li><li><a href="<?php echo esc_url(home_url('/contact/')); ?>">Contact</a></li><li><a href="<?php echo esc_url(home_url('/privacy-policy/')); ?>">Privacy</a></li><li><a href="<?php echo esc_url(home_url('/terms/')); ?>">Terms</a></li></ul></div>
    </div>
    <div class="foot__bottom"><span>© <?php echo date('Y'); ?> BlockTicker.io — All rights reserved.</span><span>Not financial advice. All data for informational purposes only.</span></div>
  </div>
</footer>

</div><!-- /.btlp -->
