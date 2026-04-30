<?php // v119.28.3: Self-mark the body the instant the template renders. We
      // can't rely on the body_class filter (theme may strip it, or the page
      // slug may not match 'landing-revamp' on every install). The wrapper
      // .btlp is the real source of truth — wherever it appears, we want
      // .bt-landing-page on <body>. The inline script runs synchronously
      // during HTML parse, BEFORE the cp-navbar / page-title paint, so
      // there's no FOUC where the old chrome flashes for a frame.
?><script>(function(){try{document.documentElement.classList.add('bt-landing-page');document.body&&document.body.classList.add('bt-landing-page');}catch(e){}})();</script>
<div class="btlp" data-auth="<?php echo esc_attr( $auth ); ?>">

<!-- ── Disclaimer strip (top of page, above ticker — matches reference template) ── -->
<div class="disclaim-strip" role="note">
  <div class="disclaim-strip__inner">
    <span class="disclaim-strip__ico" aria-hidden="true">⚠</span>
    <span class="disclaim-strip__txt"><strong>Not financial advice.</strong> All content is educational. Trading involves risk of loss. Past performance does not guarantee future results.</span>
    <a href="<?php echo esc_url(home_url('/methodology/')); ?>" class="disclaim-strip__link">View methodology &amp; risk →</a>
  </div>
</div>

<!-- ── Live ticker bar (above nav, always visible) ────────────────────── -->
<div class="ticker" aria-label="Live market prices">
  <div class="ticker__track">
    <span class="ticker__live">● LIVE MARKETS</span>
    <?php foreach ( $ticker_rows as $r ) : ?>
      <span class="ticker__item"><span class="ticker__sym"><?php echo esc_html( $r['sym'] ); ?></span> <?php echo esc_html( $r['val'] ); ?> <span class="ticker__chg--<?php echo esc_attr( $r['cls'] ); ?>"><?php echo esc_html( $r['chg'] ); ?></span></span>
    <?php endforeach; ?>
    <!-- duplicate for seamless loop -->
    <span class="ticker__live">● LIVE MARKETS</span>
    <?php foreach ( $ticker_rows as $r ) : ?>
      <span class="ticker__item"><span class="ticker__sym"><?php echo esc_html( $r['sym'] ); ?></span> <?php echo esc_html( $r['val'] ); ?> <span class="ticker__chg--<?php echo esc_attr( $r['cls'] ); ?>"><?php echo esc_html( $r['chg'] ); ?></span></span>
    <?php endforeach; ?>
  </div>
</div>

<!-- ── Sticky navigation (full mega-menus, both auth states, mobile drawer) ── -->
<nav class="nav" id="bt-nav" aria-label="Primary">
  <div class="nav__inner">
    <a href="<?php echo esc_url( home_url('/') ); ?>" class="nav__brand" aria-label="BlockTicker home">
      <span class="nav__logo">B</span>
      <span class="nav__name">BLOCK<span>TICKER</span></span>
    </a>

    <div class="nav__links">
      <!-- Markets mega-menu -->
      <div class="nav__group" data-mega="markets">
        <button class="nav__link nav__link--has-mega" aria-expanded="false" aria-controls="mega-markets" type="button">
          Markets <span class="nav__caret" aria-hidden="true">▾</span>
        </button>
        <div class="mega" id="mega-markets" hidden>
          <div class="mega__col">
            <div class="mega__col-h">Crypto</div>
            <a href="<?php echo esc_url( $url_crypto ); ?>"><span class="mega__ico">●</span> All crypto prices</a>
            <a href="<?php echo esc_url( home_url('/gainers-losers/') ); ?>"><span class="mega__ico">↑</span> Gainers &amp; losers</a>
            <a href="<?php echo esc_url( home_url('/top-lists/') ); ?>"><span class="mega__ico">⚐</span> Top lists</a>
            <a href="<?php echo esc_url( $url_watchlist ); ?>"><span class="mega__ico">⌖</span> Watchlist</a>
            <div class="mega__divider"></div>
            <div class="mega__col-h mega__col-h--sub">Top assets</div>
            <a href="<?php echo esc_url( home_url('/analysis/bitcoin/') ); ?>"><span class="mega__ico mega__ico--btc">₿</span> Bitcoin (BTC)</a>
            <a href="<?php echo esc_url( home_url('/analysis/ethereum/') ); ?>"><span class="mega__ico mega__ico--eth">Ξ</span> Ethereum (ETH)</a>
            <a href="<?php echo esc_url( home_url('/analysis/solana/') ); ?>"><span class="mega__ico mega__ico--sol">◎</span> Solana (SOL)</a>
            <a href="<?php echo esc_url( $url_crypto ); ?>" class="mega__more">All 100+ coins →</a>
          </div>
          <div class="mega__col">
            <div class="mega__col-h">Forex</div>
            <a href="<?php echo esc_url( $url_forex ); ?>"><span class="mega__ico">$</span> All forex charts</a>
            <a href="<?php echo esc_url( home_url('/forex-sentiment/') ); ?>"><span class="mega__ico">📊</span> Client sentiment</a>
            <a href="<?php echo esc_url( home_url('/economic-calendar/') ); ?>"><span class="mega__ico">📅</span> Economic calendar</a>
            <div class="mega__divider"></div>
            <div class="mega__col-h mega__col-h--sub">Major pairs</div>
            <a href="<?php echo esc_url( home_url('/forex/eur-usd/') ); ?>">EUR/USD</a>
            <a href="<?php echo esc_url( home_url('/forex/gbp-usd/') ); ?>">GBP/USD</a>
            <a href="<?php echo esc_url( home_url('/forex/usd-jpy/') ); ?>">USD/JPY</a>
            <a href="<?php echo esc_url( home_url('/forex/usd-cad/') ); ?>">USD/CAD</a>
            <a href="<?php echo esc_url( $url_forex ); ?>" class="mega__more">All forex pairs →</a>
          </div>
          <div class="mega__col">
            <div class="mega__col-h">Web3 &amp; on-chain</div>
            <a href="<?php echo esc_url( home_url('/dexscan/') ); ?>"><span class="mega__ico">⌬</span> DexScan</a>
            <a href="<?php echo esc_url( home_url('/exchanges/') ); ?>"><span class="mega__ico">⛁</span> Exchanges</a>
            <a href="<?php echo esc_url( home_url('/defi/') ); ?>"><span class="mega__ico">⚙</span> DeFi protocols</a>
            <a href="<?php echo esc_url( home_url('/nfts/') ); ?>"><span class="mega__ico">◆</span> NFT collections</a>
            <div class="mega__divider"></div>
            <div class="mega__col-h mega__col-h--sub">Commodities &amp; indices</div>
            <a href="<?php echo esc_url( home_url('/commodities/gold/') ); ?>">Gold (XAU)</a>
            <a href="<?php echo esc_url( home_url('/commodities/oil/') ); ?>">Oil (WTI)</a>
            <a href="<?php echo esc_url( home_url('/indices/') ); ?>">DXY · S&amp;P · NDX</a>
          </div>
        </div>
      </div>

      <!-- Analysis mega-menu -->
      <div class="nav__group" data-mega="analysis">
        <button class="nav__link nav__link--has-mega" aria-expanded="false" aria-controls="mega-analysis" type="button">
          Analysis <span class="nav__caret" aria-hidden="true">▾</span>
        </button>
        <div class="mega mega--1col" id="mega-analysis" hidden>
          <div class="mega__col">
            <div class="mega__col-h">Market Intelligence</div>
            <a href="<?php echo esc_url( $url_brief ); ?>"><span class="mega__ico">📈</span> <strong>Today's Desk Brief</strong><br><span class="mega__sub">Daily institutional report</span></a>
            <a href="<?php echo esc_url( $url_signals ); ?>"><span class="mega__ico">⚡</span> <strong>Trading signals</strong><br><span class="mega__sub">Live, with confidence scores</span></a>
            <a href="<?php echo esc_url( home_url('/analysis/') ); ?>"><span class="mega__ico">⌕</span> <strong>Per-asset analysis <span class="coming-soon">soon</span></strong><br><span class="mega__sub">/analysis/bitcoin · /analysis/ethereum</span></a>
            <a href="<?php echo esc_url( home_url('/correlations/') ); ?>"><span class="mega__ico">⇄</span> <strong>Cross-market correlations</strong><br><span class="mega__sub">Crypto × forex × macro</span></a>
            <a href="<?php echo esc_url( home_url('/news/') ); ?>"><span class="mega__ico">📰</span> <strong>News &amp; sentiment</strong><br><span class="mega__sub">24 vetted RSS sources</span></a>
            <div class="mega__divider"></div>
            <a href="<?php echo esc_url( $url_archive ); ?>" class="mega__more">View signal archive →</a>
          </div>
        </div>
      </div>

      <!-- Tools mega-menu -->
      <div class="nav__group" data-mega="tools">
        <button class="nav__link nav__link--has-mega" aria-expanded="false" aria-controls="mega-tools" type="button">
          Tools <span class="nav__caret" aria-hidden="true">▾</span>
        </button>
        <div class="mega mega--2col" id="mega-tools" hidden>
          <div class="mega__col">
            <div class="mega__col-h">Calculators &amp; converters</div>
            <a href="<?php echo esc_url( home_url('/tools/profit-calculator/') ); ?>"><span class="mega__ico">🧮</span> Crypto profit calculator</a>
            <a href="<?php echo esc_url( home_url('/tools/currency-converter/') ); ?>"><span class="mega__ico">⇋</span> Currency converter</a>
            <a href="<?php echo esc_url( home_url('/tools/position-size/') ); ?>"><span class="mega__ico">⚖</span> Position size calculator</a>
            <a href="<?php echo esc_url( home_url('/tools/pip-margin/') ); ?>"><span class="mega__ico">%</span> Pip &amp; margin calculator</a>
            <div class="mega__divider"></div>
            <a href="<?php echo esc_url( $url_watchlist ); ?>"><span class="mega__ico">⭐</span> Watchlist</a>
            <a href="<?php echo esc_url( home_url('/economic-calendar/') ); ?>"><span class="mega__ico">📅</span> Economic calendar</a>
          </div>
          <div class="mega__col">
            <div class="mega__col-h">Alerts &amp; integrations</div>
            <a href="<?php echo esc_url( $url_alerts ); ?>"><span class="mega__ico">🔔</span> Price alerts</a>
            <a href="<?php echo esc_url( home_url('/webhooks/') ); ?>"><span class="mega__ico">🪝</span> Webhooks</a>
            <a href="<?php echo esc_url( home_url('/integrations/telegram/') ); ?>"><span class="mega__ico">✈</span> Telegram bot</a>
            <a href="<?php echo esc_url( home_url('/integrations/twitter/') ); ?>"><span class="mega__ico">𝕏</span> X / Twitter publish</a>
            <div class="mega__divider"></div>
            <a href="<?php echo esc_url( $url_api ); ?>" class="mega__more">API &amp; documentation →</a>
            <?php if ( ! $is_in ) : ?>
            <a href="<?php echo esc_url( $url_login ); ?>" class="mega__teaser" data-show-when="logged-out">
              🏠 Personalized dashboard <span class="mega__teaser-tag">sign in</span>
            </a>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Learn mega-menu -->
      <div class="nav__group" data-mega="learn">
        <button class="nav__link nav__link--has-mega" aria-expanded="false" aria-controls="mega-learn" type="button">
          Learn <span class="nav__caret" aria-hidden="true">▾</span>
        </button>
        <div class="mega mega--2col" id="mega-learn" hidden>
          <div class="mega__col">
            <div class="mega__col-h">Get started</div>
            <a href="<?php echo esc_url( $url_learn . 'beginner-guides/' ); ?>"><span class="mega__ico">📚</span> <strong>Beginner guides</strong><br><span class="mega__sub">Crypto, forex &amp; markets 101</span></a>
            <a href="<?php echo esc_url( $url_learn . 'glossary/' ); ?>"><span class="mega__ico">🛟</span> <strong>Glossary</strong><br><span class="mega__sub">Terms explained simply</span></a>
            <a href="<?php echo esc_url( $url_learn . 'trading-basics/' ); ?>"><span class="mega__ico">🎓</span> <strong>Trading basics</strong><br><span class="mega__sub">Risk, position sizing, R:R</span></a>
          </div>
          <div class="mega__col">
            <div class="mega__col-h">Read &amp; research</div>
            <a href="<?php echo esc_url( $url_blog ); ?>"><span class="mega__ico">📰</span> Blog &amp; insights</a>
            <a href="<?php echo esc_url( home_url('/brokers/') ); ?>"><span class="mega__ico">🏛</span> Recommended brokers</a>
            <a href="<?php echo esc_url( home_url('/whitepaper/') ); ?>"><span class="mega__ico">📑</span> Whitepaper &amp; methodology</a>
            <a href="<?php echo esc_url( home_url('/help/') ); ?>"><span class="mega__ico">❓</span> Help center / FAQ</a>
            <div class="mega__divider"></div>
            <a href="<?php echo esc_url( $url_blog ); ?>" class="mega__more">All articles →</a>
          </div>
        </div>
      </div>

      <!-- News mega-menu -->
      <div class="nav__group" data-mega="news">
        <button class="nav__link nav__link--has-mega" aria-expanded="false" aria-controls="mega-news" type="button">
          News <span class="nav__caret" aria-hidden="true">▾</span>
        </button>
        <div class="mega mega--2col" id="mega-news" hidden>
          <div class="mega__col">
            <div class="mega__col-h">By market</div>
            <a href="<?php echo esc_url( home_url('/news/crypto/') ); ?>"><span class="mega__ico">●</span> Crypto news</a>
            <a href="<?php echo esc_url( home_url('/news/forex/') ); ?>"><span class="mega__ico">$</span> Forex news</a>
            <a href="<?php echo esc_url( home_url('/news/web3/') ); ?>"><span class="mega__ico">⌬</span> Web3 &amp; DeFi</a>
            <a href="<?php echo esc_url( home_url('/news/macro/') ); ?>"><span class="mega__ico">🏛</span> Macro &amp; central banks</a>
            <div class="mega__divider"></div>
            <div class="mega__col-h mega__col-h--sub">By signal</div>
            <a href="<?php echo esc_url( home_url('/news/breaking/') ); ?>"><span class="mega__ico">⚡</span> Breaking</a>
            <a href="<?php echo esc_url( home_url('/news/earnings/') ); ?>"><span class="mega__ico">📊</span> Earnings &amp; reports</a>
            <a href="<?php echo esc_url( home_url('/news/regulation/') ); ?>"><span class="mega__ico">⚖</span> Regulation</a>
          </div>
          <div class="mega__col">
            <div class="mega__col-h">Curated streams</div>
            <a href="<?php echo esc_url( home_url('/news/') ); ?>"><span class="mega__ico">📰</span> <strong>Top headlines</strong><br><span class="mega__sub">Across all 24 vetted sources</span></a>
            <a href="<?php echo esc_url( home_url('/financial-news/') ); ?>"><span class="mega__ico">📊</span> <strong>Financial news desk</strong><br><span class="mega__sub">Editorial selection &middot; Bloomberg-style</span></a>
            <a href="<?php echo esc_url( home_url('/news/most-read/') ); ?>"><span class="mega__ico">🔥</span> <strong>Most-read this hour</strong><br><span class="mega__sub">What traders are reading right now</span></a>
            <a href="<?php echo esc_url( home_url('/news/sentiment/') ); ?>"><span class="mega__ico">🎯</span> <strong>Sentiment-tagged</strong><br><span class="mega__sub">News scored bullish / bearish / neutral</span></a>
            <a href="<?php echo esc_url( home_url('/economic-calendar/') ); ?>"><span class="mega__ico">📅</span> <strong>Economic calendar</strong><br><span class="mega__sub">Releases that move markets</span></a>
            <div class="mega__divider"></div>
            <a href="<?php echo esc_url( home_url('/news/sources/') ); ?>" class="mega__more">All news sources →</a>
          </div>
        </div>
      </div>

      <a href="<?php echo esc_url( home_url('/methodology/') ); ?>" class="nav__link">Methodology</a>
    </div>

    <div class="nav__actions">
      <button class="nav__icon-btn" aria-label="Search markets" title="Search markets" type="button" id="nav-search-btn">
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
          <circle cx="6.5" cy="6.5" r="5" stroke="currentColor" stroke-width="1.5"/>
          <path d="M10.5 10.5L14 14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
        </svg>
      </button>

      <?php if ( ! $is_in ) : ?>
      <!-- Logged-out: Login + Sign up free -->
      <span class="nav__cta-default" data-show-when="logged-out">
        <a class="nav__login" href="<?php echo esc_url( $url_login ); ?>" onclick="if(window.btAuthOpen){btAuthOpen('login');return false;}">Login</a>
        <a class="btn btn--sm nav__signup" href="<?php echo esc_url( $url_signup ); ?>" onclick="if(window.btAuthOpen){btAuthOpen('register');return false;}">Sign up free</a>
      </span>
      <a class="btn btn--sm nav__cta-sticky" href="<?php echo esc_url( $url_signup ); ?>" data-show-when="logged-out" onclick="if(window.btAuthOpen){btAuthOpen('register');return false;}">Sign up free →</a>
      <?php else : ?>
      <!-- Logged-in: Avatar + workspace dropdown -->
      <div class="nav__avatar-wrap" data-show-when="logged-in">
        <button class="nav__avatar-btn" type="button" id="nav-avatar-btn" aria-haspopup="true" aria-expanded="false">
          <span class="nav__avatar"><?php echo esc_html( $u_init ); ?></span>
          <span class="nav__avatar-name"><?php echo esc_html( $u_short ); ?></span>
          <span class="nav__avatar-caret" aria-hidden="true">▾</span>
        </button>
        <div class="nav__usermenu" id="nav-usermenu" hidden>
          <div class="nav__usermenu-head">
            <div class="nav__usermenu-name"><?php echo esc_html( $u_name ); ?></div>
            <div class="nav__usermenu-email"><?php echo esc_html( $u_email ); ?></div>
          </div>
          <div class="nav__usermenu-section">My Workspace</div>
          <a href="<?php echo esc_url( $url_dashboard ); ?>" class="nav__usermenu-item">🏠 Dashboard</a>
          <a href="<?php echo esc_url( $url_portfolio ); ?>" class="nav__usermenu-item">📊 Portfolio</a>
          <a href="<?php echo esc_url( $url_watchlist ); ?>" class="nav__usermenu-item">⭐ Watchlist</a>
          <a href="<?php echo esc_url( $url_screeners ); ?>" class="nav__usermenu-item">🔎 Screeners</a>
          <a href="<?php echo esc_url( $url_following ); ?>" class="nav__usermenu-item">★ Following</a>
          <a href="<?php echo esc_url( $url_alerts ); ?>" class="nav__usermenu-item">🔔 Alerts</a>
          <div class="nav__usermenu-divider"></div>
          <a href="<?php echo esc_url( $url_settings ); ?>" class="nav__usermenu-item">⚙ Account settings</a>
          <a href="<?php echo esc_url( $url_logout ); ?>" class="nav__usermenu-item nav__usermenu-logout">Logout</a>
        </div>
      </div>
      <?php endif; ?>

      <?php // v119.28.4: Admin-only "Preview as" toggle. Only renders for
            // users with manage_options. Lets admins see exactly what
            // logged-in vs logged-out users see, without logging in/out.
            // Wired in JS to flip data-auth on .btlp + show/hide the
            // [data-show-when="logged-in/out"] pieces in real time.
      if ( current_user_can( 'manage_options' ) ) : ?>
      <span class="nav__preview" role="group" aria-label="Preview auth state (admin only)">
        <span class="nav__preview-label">PREVIEW:</span>
        <button type="button" class="nav__preview-btn<?php echo $is_in ? '' : ' is-active'; ?>" data-preview-as="logged-out">Logged out</button>
        <button type="button" class="nav__preview-btn<?php echo $is_in ? ' is-active' : ''; ?>" data-preview-as="logged-in">Logged in</button>
      </span>
      <?php endif; ?>

      <button class="nav__hamburger" id="nav-hamburger" aria-label="Toggle menu" aria-expanded="false" aria-controls="nav-drawer" type="button">
        <span></span><span></span><span></span>
      </button>
    </div>
  </div>

  <!-- Drawer backdrop (click to close) -->
  <div class="nav__drawer-backdrop" id="nav-drawer-backdrop" aria-hidden="true"></div>

  <!-- Mobile bottom-sheet drawer -->
  <div class="nav__drawer" id="nav-drawer" hidden role="dialog" aria-modal="true" aria-label="Site menu">
    <button class="nav__drawer-close" type="button" id="nav-drawer-close" aria-label="Close menu">×</button>
    <div class="nav__drawer-inner">
      <?php if ( ! $is_in ) : ?>
      <div class="nav__drawer-auth" data-show-when="logged-out">
        <a class="btn btn--sm nav__signup" style="flex:1;text-align:center" href="<?php echo esc_url( $url_signup ); ?>" onclick="if(window.btAuthOpen){btAuthOpen('register');return false;}">Sign up free</a>
        <a class="nav__login" style="flex:1;text-align:center" href="<?php echo esc_url( $url_login ); ?>" onclick="if(window.btAuthOpen){btAuthOpen('login');return false;}">Login</a>
      </div>
      <?php else : ?>
      <div class="nav__drawer-account" data-show-when="logged-in">
        <span class="nav__avatar"><?php echo esc_html( $u_init ); ?></span>
        <div>
          <div class="nav__usermenu-name"><?php echo esc_html( $u_name ); ?></div>
          <div class="nav__usermenu-email"><?php echo esc_html( $u_email ); ?></div>
        </div>
      </div>

      <div data-show-when="logged-in">
        <div class="nav__drawer-section">My Workspace</div>
        <a href="<?php echo esc_url( $url_dashboard ); ?>">🏠 Dashboard</a>
        <a href="<?php echo esc_url( $url_portfolio ); ?>">📊 Portfolio</a>
        <a href="<?php echo esc_url( $url_watchlist ); ?>">⭐ Watchlist</a>
        <a href="<?php echo esc_url( $url_screeners ); ?>">🔎 Screeners</a>
        <a href="<?php echo esc_url( $url_following ); ?>">★ Following</a>
        <a href="<?php echo esc_url( $url_alerts ); ?>">🔔 Alerts</a>
      </div>
      <?php endif; ?>

      <div class="nav__drawer-section">Markets</div>
      <a href="<?php echo esc_url( $url_crypto ); ?>">Crypto · 100+ coins</a>
      <a href="<?php echo esc_url( $url_forex ); ?>">Forex · all pairs</a>
      <a href="<?php echo esc_url( home_url('/dexscan/') ); ?>">Web3 · DexScan, DeFi, NFTs</a>
      <a href="<?php echo esc_url( home_url('/commodities/') ); ?>">Commodities &amp; indices</a>

      <div class="nav__drawer-section">Analysis</div>
      <a href="<?php echo esc_url( $url_brief ); ?>">Today's Desk Brief</a>
      <a href="<?php echo esc_url( $url_signals ); ?>">Trading signals</a>
      <a href="<?php echo esc_url( home_url('/analysis/') ); ?>">Per-asset analysis</a>
      <a href="<?php echo esc_url( home_url('/correlations/') ); ?>">Cross-market correlations</a>
      <a href="<?php echo esc_url( home_url('/news/') ); ?>">News &amp; sentiment</a>

      <div class="nav__drawer-section">Tools</div>
      <a href="<?php echo esc_url( home_url('/tools/') ); ?>">Calculators &amp; converters</a>
      <a href="<?php echo esc_url( $url_watchlist ); ?>">Watchlist</a>
      <a href="<?php echo esc_url( $url_alerts ); ?>">Alerts &amp; webhooks</a>
      <a href="<?php echo esc_url( home_url('/integrations/') ); ?>">Telegram &amp; X integrations</a>
      <a href="<?php echo esc_url( home_url('/economic-calendar/') ); ?>">Economic calendar</a>

      <div class="nav__drawer-section">Learn</div>
      <a href="<?php echo esc_url( $url_learn . 'beginner-guides/' ); ?>">Beginner guides</a>
      <a href="<?php echo esc_url( $url_learn . 'glossary/' ); ?>">Glossary</a>
      <a href="<?php echo esc_url( home_url('/brokers/') ); ?>">Recommended brokers</a>
      <a href="<?php echo esc_url( $url_blog ); ?>">Blog &amp; insights</a>
      <a href="<?php echo esc_url( home_url('/help/') ); ?>">Help center / FAQ</a>

      <div class="nav__drawer-section">News</div>
      <a href="<?php echo esc_url( home_url('/news/') ); ?>">Top headlines</a>
      <a href="<?php echo esc_url( home_url('/news/crypto/') ); ?>">Crypto · Forex · Web3</a>
      <a href="<?php echo esc_url( home_url('/news/macro/') ); ?>">Macro &amp; central banks</a>
      <a href="<?php echo esc_url( home_url('/news/sentiment/') ); ?>">Sentiment-tagged feed</a>
      <a href="<?php echo esc_url( home_url('/economic-calendar/') ); ?>">Economic calendar</a>

      <div class="nav__drawer-section">More</div>
      <a href="<?php echo esc_url( home_url('/methodology/') ); ?>">Methodology</a>
      <a href="<?php echo esc_url( $url_api ); ?>">API &amp; docs</a>
      <a href="<?php echo esc_url( home_url('/about/') ); ?>">About</a>
      <a href="<?php echo esc_url( home_url('/contact/') ); ?>">Contact</a>
      <?php if ( $is_in ) : ?>
      <a href="<?php echo esc_url( $url_settings ); ?>" data-show-when="logged-in">⚙ Account settings</a>
      <a href="<?php echo esc_url( $url_logout ); ?>" data-show-when="logged-in" style="color:var(--danger)">Logout</a>
      <?php endif; ?>
    </div>
  </div>
</nav>
<!-- ── End ticker + nav ──────────────────────────────────────────────── -->

<?php // v119.28.22: Risk modal only on homepage — not every interior page ?>
<?php if ( is_front_page() || is_home() ) : ?>
<!-- ── Risk-acknowledgment modal (first-visit gate; localStorage-remembered) ── -->
<div class="risk-modal" id="bt-risk-modal" hidden role="dialog" aria-modal="true" aria-labelledby="bt-risk-title" aria-describedby="bt-risk-body">
  <div class="risk-modal__backdrop" data-risk-action="dismiss" aria-hidden="true"></div>
  <div class="risk-modal__card">
    <div class="risk-modal__head">
      <span class="risk-modal__ico" aria-hidden="true">⚠</span>
      <h2 class="risk-modal__title" id="bt-risk-title">Before you continue</h2>
    </div>
    <p class="risk-modal__body" id="bt-risk-body">
      BlockTicker provides <strong>market intelligence</strong> and <strong>pattern-detection signals</strong>. <strong>It is not financial advice.</strong> Trading carries risk of loss. Past performance does not guarantee future results. Signals are <strong>educational, not instructions to trade</strong>.
    </p>
    <p class="risk-modal__fine">
      By continuing, you acknowledge you understand this. You can review the full methodology, statistical assumptions, and the published 64% historical hit rate at any time.
    </p>
    <div class="risk-modal__actions">
      <a href="<?php echo esc_url(home_url('/methodology/')); ?>" class="risk-modal__btn risk-modal__btn--ghost">Read methodology first</a>
      <button type="button" class="risk-modal__btn risk-modal__btn--primary" data-risk-action="accept">I understand &amp; continue</button>
    </div>
  </div>
</div>
<?php endif; ?>
