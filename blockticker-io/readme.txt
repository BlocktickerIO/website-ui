=== BlockTicker — Live Crypto & Forex Intelligence ===
Contributors: blockticker
Tags: crypto, forex, bitcoin, trading, autoblog, AI, live prices, news aggregator
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 116.0.0
License: GPL2

Complete automated setup for your Crypto & Forex autoblog site. One-click wizard: live data, 14+ news sources, AI content, newsletter, crypto tools, education hub, SEO, monetization — fully autopilot with auto-updates.

== Description ==

BlockTicker transforms any WordPress site into a professional crypto & forex news platform that competes with CoinDesk, The Block, Decrypt, and crypto.news — all running on autopilot.

**One-click setup wizard** creates 15 pages, installs plugins, configures live data feeds, sets up AI content generation, enables auto-updates, and more.

= What's Included =

* **Live Market Data** — Real-time forex rates (170+ pairs) and crypto prices (500+ coins) via ExchangeRate API & CoinGecko
* **14+ News Sources** — CoinDesk, The Block, Decrypt, CoinTelegraph, BeInCrypto, CryptoSlate, Blockworks, Reuters, FXStreet, and more
* **AI Autoblog** — Daily market roundups published automatically via AI Power plugin or built-in aggregator
* **Trading Signals** — Auto-updated every 15 minutes from professional sources
* **Crypto Tools** — Currency converter, Fear & Greed Index, interactive TradingView charts
* **Education Hub** — Learn page with glossary (25+ terms), guides, tutorials structure
* **Newsletter System** — Built-in email subscription with Mailchimp/Sendinblue hook ready
* **SEO Optimized** — Yoast configuration, article schema, breadcrumbs, Open Graph, sitemap
* **Monetization** — AdSense auto-insertion, affiliate broker cards, sponsored content zones
* **Full Autopilot** — WordPress core, plugin, and theme auto-updates enabled programmatically
* **Security Hardened** — Wordfence integration, login error masking, file edit disabled
* **Dark Financial Theme** — Professional dark UI matching Bloomberg/CoinDesk aesthetic

= Pages Created (15) =

1. Home (hero + price cards + Fear & Greed + news + forex/crypto tables + newsletter)
2. Crypto Markets (full table + charts + Fear & Greed)
3. Forex Charts (EUR/USD, GBP/USD, USD/JPY, USD/CHF)
4. Financial News (14+ sources, category tabs, breaking news bar)
5. Trading Signals (auto-updated every 15 min)
6. Market Analysis (AI autoblog posts)
7. Economic Calendar (TradingView widget)
8. Tools (converter + Fear & Greed + multi-chart)
9. Learn (education hub + glossary)
10. Recommended Brokers (affiliate cards)
11. About
12. Contact
13. Privacy Policy

= Shortcodes =

* `[fxlm_ticker_bar]` — Scrolling price ticker
* `[fxlm_forex_table]` — Live forex rates table
* `[fxlm_crypto_table]` — Top crypto prices
* `[fxlm_crypto_full_table]` — Extended crypto table with market cap & volume
* `[fxlm_tradingview_chart symbol="FX:EURUSD" height="400"]` — Interactive chart
* `[fxlm_news_feed count="10" category="Crypto News" show_images="true"]` — News feed
* `[fxlm_signals_feed]` — Trading signals
* `[fxlm_breaking_news]` — Breaking news bar
* `[fxlm_price_cards coins="bitcoin,ethereum,solana"]` — Price highlight cards
* `[fxlm_fear_greed]` — Fear & Greed Index widget
* `[fxlm_crypto_converter]` — Currency converter
* `[fxlm_newsletter style="banner"]` — Email subscription form
* `[fxlm_search_bar]` — Search form
* `[fxlm_breadcrumbs]` — Breadcrumb navigation with schema
* `[fxlm_trending_bar]` — Trending coins bar
* `[fxlm_glossary]` — Crypto/forex glossary
* `[fxlm_social]` — Social media icons (8 networks)
* `[fxlm_ai_analysis]` — AI market analysis
* `[fxlm_economic_calendar]` — Calendar widget
* `[fxlm_adsense_banner zone="top"]` — AdSense zone
* `[fxlm_affiliate name="Broker" url="..." rating="5"]` — Affiliate CTA

== Installation ==

1. Upload `fx-live-markets-v6` folder to `/wp-content/plugins/`
2. Activate the plugin
3. Go to **BlockTicker Setup** in the admin sidebar
4. Enter your API keys (ExchangeRate, CoinGecko, AdSense, Google Analytics)
5. Click **"Run All Steps Automatically"**
6. Done! Your site is live and running on autopilot.

== Changelog ==

= 116.0.0 =
* NEW: Tax Year Report — realized-gains report from the v114 transaction
  ledger with explicit closed-lot matching. Every sell that drew from
  multiple open lots produces one row per matched partition, with
  acquire date, dispose date, holding period, and LT/ST classification.
* NEW: 3 export formats — HTML (print-to-PDF via Ctrl-P), raw CSV for
  Excel, and Form 8949-compatible CSV for TurboTax / FreeTaxUSA
  bulk-import with exact IRS column names + MM/DD/YYYY dates.
* NEW: Configurable long-term threshold (default 366 days matching IRS
  §1222); set globally via bt_tax_lt_days option or per-request.
* NEW: [bt_tax_report] shortcode with year picker (auto-populated with
  years that have sell activity), method dropdown (FIFO/HIFO),
  threshold input, live preview, and 3 download buttons.
* NEW: REST route GET /blockticker/v1/portfolio/v2/tax-report with
  ?year, ?method, ?lt_days, ?format=json|csv|csv_form8949|html.
* Browser-driven PDF rendering — no PHP PDF library bundled, keeping
  the plugin zip size unchanged and producing superior typography
  via the OS font rasterizer.
* 30-assertion smoke test validates LT/ST classification at the
  365/366-day boundary, mixed-term partial sells, year-boundary
  filtering, broken-lot handling, and all 3 export format shapes.
* ACB method intentionally excluded from tax reports — average cost
  basis has no single acquisition date per sold unit, so per-lot
  holding-period classification doesn't apply.

= 115.0.0 =
* NEW: Public API Keys — self-service key management for end users with
  per-user 10-key cap, 3 tiers (public/standard/premium with 60/600/3000
  req/min + 10K/100K/1M req/day), atomic rotate, soft-revoke with restore,
  and live usage metering. 27-assertion lifecycle smoke test passes 100%.
* NEW: [bt_api_keys] shortcode — create/rotate/revoke from any page,
  with plaintext-reveal panel shown once at mint, copy-friendly.
* NEW: Admin console at BlockTicker → 🔑 API Keys — master table across
  all users with usage bars (colour-scaled green/amber/red), tier badges,
  inline revoke/restore/delete, and show-revoked toggle.
* NEW: OpenAPI 3.0 spec served at /wp-json/blockticker/v1/openapi.json —
  hand-curated with 15 paths, 16 operations, 10 schemas, 2 security
  schemes (API key header + WP cookie), full parameter docs and response
  schemas. Cacheable, CORS-enabled, 36 KB.
* NEW: [bt_api_swagger] shortcode — drops Swagger UI v5 into any page
  with "Try it out" live calls, auto dark-mode via prefers-color-scheme,
  JSDelivr/unpkg CDN switch (no JS bundled in plugin zip).
* NEW: 4 REST routes /user/api-keys[/{id}[/rotate]] for programmatic
  key management (cookie-auth'd).
* NEW: Per-key rate limits override tier defaults — admins can bump
  individual records for grandfathering or partnership deals.
* NEW: Daily rollover cron (bt_apikeys_daily_rollover) resets usage_today
  at UTC midnight, with belt-and-suspenders date check on every bump.
* FIX: mbstring-optional — new BT_Utils::strlen_unicode() and
  substr_unicode() helpers with regex-based UTF-8 fallback. Touched 15
  call sites across class-social.php, class-portfolio-v2.php,
  class-api-keys.php so shared hosts without mbstring no longer
  fatal-error on Twitter/Telegram message generation or CSV import.

= 114.0.0 =
* NEW: Portfolio Tracker 3.0 — transaction-level ledger (buys AND sells),
  proper cost-basis accounting with 3 engines: FIFO (default), HIFO
  (tax-minimising), ACB (average cost, Canada/UK standard).
* NEW: Realized P&L from closed lots + unrealized P&L on open lots,
  computed server-side in pure PHP, validated by a 14-assertion smoke
  test against hand-computed expected values.
* NEW: Fee-aware accounting — buy fees roll into effective unit cost,
  sell fees deduct from realized proceeds (matches every major tax
  jurisdiction's treatment).
* NEW: CSV import with 4-dialect auto-detection (Coinbase / Binance /
  Kraken / generic), flexible header aliases, delimiter detection (, ; \\t),
  currency-symbol stripping, and dedupe-on-append so re-imports are safe.
* NEW: [bt_portfolio_v2] full dashboard, [bt_portfolio_summary] one-liner,
  [bt_portfolio_import] standalone CSV upload shortcodes.
* NEW: 4 REST endpoints under /blockticker/v1/portfolio/v2/ for
  transaction CRUD + CSV import + method selection.
* NEW: Server-side storage in wp_usermeta for logged-in users;
  localStorage fallback for guests — same UI, different storage layer.
* NEW: Overshoot detection — sells exceeding buys flag a position as
  "broken" (typical cause: incomplete CSV import of old buy history)
  with a ⚠ icon and tooltip guidance.
* Legacy BT_Portfolio class + [bt_portfolio] / [fxlm_portfolio] /
  [bt_price_alerts] shortcodes entirely untouched — zero breakage for
  existing pages.

= 113.0.0 =
* NEW: Social Auto-Share (X/Twitter + Telegram) — when a per-asset AI analysis
  post is saved, automatically generate and publish a compact 5-tweet X thread
  AND a MarkdownV2 Telegram channel message, both driven from the same live
  context (price, 7d range, Fear & Greed, 48h news sentiment, symbol-filtered
  headlines, active signals).
* NEW: `BT_Social` class handles the full pipeline via a new
  `bt_asset_analysis_saved` action hook.
* NEW: `[bt_social_share_buttons symbol="BTC"]` shortcode — inline X /
  Telegram / LinkedIn / Copy-link button bar for readers.
* NEW: Admin panel under BlockTicker → 🗄 Database → 🔀 Social Auto-Share with
  platform toggles, Telegram bot-token + chat-ID config, cooldown slider,
  live test-send buttons for both platforms, and a per-post delivery log
  with retry buttons.
* NEW: Per-symbol cooldown (default 6h) prevents duplicate posts when the
  same asset analysis is refreshed multiple times in a short window.
* REUSE: Twitter publishing goes through the existing OAuth 1.0a helpers in
  BT_AIBlog (v60) — no new signature code, minimal new API surface.
* `BT_AIBlog::assemble_asset_context()` visibility changed private → public
  so external listeners can reuse the exact asset-context bundle used by
  the analysis generator.

= 112.0.0 =
* New: [bt_ai_analysis_v2] shortcode — on-demand per-asset AI deep-dive reports served from 1-hour cache
* 5-section structured analysis: Market Overview, Technical, Sentiment, Signals, Outlook & Key Levels
* Data-enriched prompts: live price, 24h/7d change, 7d range, F&G, news sentiment, headlines, signals
* Auto-creates/updates WordPress posts (bt-analysis-{sym}) with Yoast meta + FinancialProduct JSON-LD
* AJAX ↻ Refresh button, autoload attribute for asset pages, admin force-refresh bypass
* PHP lint: both touched files clean

= 111.0.0 =
* New: [bt_dex_scanner] shortcode — multi-chain DEX scanner with DexScreener live data and advanced filters
* New: DexScreener API integration — buys/sells pressure bar, 5m/1h/6h/24h price changes, FDV, buy pressure %
* New: RugCheck safety scores for Solana tokens — Low/Medium/High/Critical badge per token
* Auto-write top 10 tokens to wp_bt_price_history (DSX:SYMBOL format) enabling price charts + correlation heatmap
* Chain support: Solana, Ethereum, Base, BSC, Arbitrum, All; filter by min_liq, min_vol, max_age_h, min_buy_pct
* PHP lint: both touched files clean

= 110.0.0 =
* New: [bt_watchlist_v2] shortcode — named multi-watchlists with tabs, per-coin notes, target prices with hit detection
* New: 3 REST endpoints — GET/POST /user/watchlists, POST /user/watchlists/share, GET /watchlist/shared/{token}
* Share button generates 16-char token URL; shareable watchlists readable publicly without login
* Backward-compatible: legacy bt_watchlist usermeta kept in sync; all existing shortcodes untouched
* Auto-migrates existing flat watchlist into default named list on first v2 load
* PHP lint: both touched files clean

= 109.0.0 =
* New: BT_Webhook — delivery engine for wp_bt_events with retry, exponential back-off, and dead-lettering
* Schema migration: bt_db_version 1.0.0 → 1.1.0 adds attempts + last_attempt_at columns to wp_bt_events (auto via dbDelta)
* HMAC-SHA256 request signing (X-BT-Signature header) matches GitHub/Stripe webhook pattern
* Full delivery dashboard in DB admin: metrics, endpoint config, recent event log with retry/delete buttons, per-endpoint connectivity test
* delivered = -1 dead-letter state; ▶ Deliver Pending Now button for immediate dispatch
* PHP lint: all 4 touched files clean

= 108.0.0 =
* New: BT_Newsletter — weekly AI market digest sent every Sunday 8 AM to all subscribers
* Rich dark-background HTML email: BTC/ETH hero prices, F&G Index, sentiment score, top gainers/losers, headlines, signals, forex snapshot
* AI-generated 3-paragraph editorial narrative (Claude Haiku or GPT-4o-mini) covering market tone, movers, and next-week outlook
* Admin panel: preview in iframe, test send to single address, broadcast to all subscribers — all from BlockTicker → Database screen
* 6-day double-send guard; mobile-responsive table-based layout (Gmail/Outlook/Apple Mail safe)
* PHP lint: all 3 touched files clean

= 107.0.0 =
* Price Alerts 2.0: webhook delivery, forex pair alerts, repeating alerts (2x/5x/always), token-based management UI
* New [bt_alert_manager] shortcode — lets users view/delete their alerts via secure token link in confirmation email
* All triggered alerts now logged to wp_bt_events table (PRICE_ALERT event type, designed for this since v96.2)
* Bugfix: fxlm_fifteen_minutes → bt_fifteen_minutes in bt_check_price_alerts cron schedule
* Rate limit changed from transient-based to active-alert count (prevents blocking legitimate repeat users)
* PHP lint: both touched files clean

= 106.0.0 =
* New: [bt_correlation_heatmap] shortcode — colour-coded Pearson correlation matrix for any mix of crypto and forex assets
* New: BT_Correlation::compute() — daily log-return Pearson computation with 6h transient cache busted on price refresh
* New: GET /wp-json/blockticker/v1/correlation REST endpoint
* Two colour scales: rg (red-grey-green) and bwr (blue-white-red); min_r threshold to highlight strong pairs
* Max 12 symbols, horizontally scrollable on mobile, accessible cell titles for screen readers
* PHP lint: both touched files clean

= 105.0.0 =
* New: [bt_signal_leaderboard] shortcode — ranks signal providers by verified win rate (zero API cost)
* New: BT_SignalTracker::get_leaderboard() — groups resolved outcomes by source, computes win rate / avg move / best call
* Leaderboard reads bt_signal_tracker + bt_signal_items options; no new DB tables or API calls
* Rank badges (gold/silver/bronze), colour-coded win rate bars, Best Call column with symbol + direction
* PHP lint: both touched files clean

= 104.0.0 =
* New: BT_Sentiment class — twice-daily cron scores up to 40 news items per run using Claude Haiku or GPT-4o-mini
* New: [bt_sentiment_bar] shortcode — gradient gauge bar (Extreme Fear → Extreme Greed) with needle + label
* New: [bt_sentiment_ticker] shortcode — inline mood one-liner for sidebars/widgets
* New: GET /wp-json/blockticker/v1/sentiment REST endpoint
* New: Sentiment Scoring panel in BlockTicker → Database admin screen with manual batch trigger
* Populates sentiment_score and symbols_mentioned columns in wp_bt_news_items (empty since v96.2)
* PHP lint: all 3 touched files clean

= 103.0.0 =
* FXLM_VERSION / FXLM_DIR / FXLM_URL constant shims removed — BT_* are sole primary constants
* FXLM_BOTTOM_TICKER_RENDERED render-guard renamed to BT_BOTTOM_TICKER_RENDERED
* class-compat.php §1 simplified — FXLM_* fallbacks removed from BT_* guards
* Doc comments updated in 11 files; zero FXLM_* identifiers in active code
* PHP lint: all 44 plugin PHP files clean

= 102.0.0 =
* BT_VERSION / BT_DIR / BT_URL are now the primary plugin constants; FXLM_* kept as one-release shims
* 268 internal FXLM_ constant and class references patched across 35 files to canonical BT_* names
* 28 in-file reverse class_alias blocks removed — FXLM_* class names no longer supported
* class-compat.php: §1 updated, §2 fully retired, §9 self-test simplified to BT_* only
* PHP lint: all 44 plugin PHP files clean

= 101.0.0 =
* Physical class renames: 28 class files updated from FXLM_ to BT_ declarations with reverse class_alias() for backward compat
* 54 callback arrays and static calls in fx-live-markets.php and blockticker-cron.php updated to BT_* 
* class-compat.php §2: alias direction reversed from FXLM_→BT_ to BT_→FXLM_ to match new primary declarations
* class-deprecation.php: pre_option shims removed (safety-net period complete); class simplified to helpers only
* PHP lint: all 32 touched files clean

= 100.0.0 =
* Final cleanup: all fxlm_* option rows deleted on upgrade; 8 WP-Cron events migrated to bt_* names
* New class-v100-upgrade.php: idempotent upgrade engine with admin panel and AJAX trigger
* class-compat.php §3 (cron schedule aliases) and §4 (hook aliases) retired — both now handled natively
* 7 class files patched: all wp_schedule_event / wp_next_scheduled calls updated to bt_* hooks + schedules
* uninstall.php §13: bt_* cron event cleanup on plugin deletion
* PHP lint: all 11 touched files clean

= 99.0.0 =
* New: class-deprecation.php — pre_option shims + _doing_it_wrong() for all 58 fxlm_* option keys
* Patch: 216 internal get_option/update_option calls across 26 files updated from fxlm_* to bt_* keys
* New: Deprecation Notices panel in BlockTicker → Database admin screen
* New: BT_Deprecation::delete_legacy_rows() helper for v100.0 upgrade
* New: BT_Deprecation::count_legacy_rows() preview helper
* Zero remaining fxlm_* option reads in plugin core (verified by regex scan)
* Bumped: FXLM_VERSION and plugin header to 99.0.0

= 98.0.0 =
* New: class-migration.php — BT_Migration option key migration engine
* New: 58 named fxlm_*→bt_* key copies with correct autoload values
* New: 8 wildcard prefix bulk-copy via INSERT IGNORE ... SELECT SQL
* New: 58x forward-sync update_option hooks keep both key sets in sync after migration
* New: Option Key Migration panel in BlockTicker → Database admin screen
* New: Run / Force Re-migrate / Rollback AJAX actions
* New: Automatic migration on plugin activation (register_activation_hook)
* Updated: uninstall.php Section 12 — full bt_* option cleanup on plugin deletion
* Bumped: FXLM_VERSION and plugin header to 98.0.0

= 97.0.0 =
* New: class-compat.php — BT_ alias layer over all 29 FXLM_ classes, 33 shortcodes, 7 hooks, 4 cron schedules
* New: BT_VERSION / BT_DIR / BT_URL constants (aliases of FXLM_ equivalents)
* New: bt_get_option() / bt_update_option() helper functions with fxlm_ fallthrough
* New: Admin migration notice (dismissible per-user)
* New: Debug mode deprecation log via bt_deprecated_hook_used action
* New: Self-test on admin_init verifying all 28 BT_ class aliases
* Note: Option keys in wp_options unchanged (fxlm_*) — migration in v98.0
* Bumped: FXLM_VERSION and plugin header to 97.0.0

= 96.7.0 =
* New: class-a11y.php — WCAG 2.1 AA accessibility layer
* New: assets/css/a11y.css — contrast fixes, focus-visible ring, reduced-motion, high-contrast, forced-colors
* New: assets/js/a11y.js — arrow-key table nav, aria-live price announcements, escape+focus-trap, table captions
* Fix: --bt-text-3 promoted #64748b→#7a8aaa (5.54:1 passes AA on all backgrounds)
* Fix: #475569 overridden to #7a8aaa throughout (was 2.54:1 fail)
* Fix: outline:none !important defeated on 3 form element groups via :focus-visible
* New: skip-to-content link injected at wp_body_open
* New: aria-live polite region for live price update announcements
* New: prefers-reduced-motion CSS + JS kill switch
* New: prefers-contrast:more and forced-colors:active blocks
* New: HTML lang attribute filter fallback
* Bumped: FXLM_VERSION and plugin header to 96.7.0

= 96.6.0 =
* New: class-cwv.php — Core Web Vitals optimizer (async fonts, defer JS, WebP, TV preload, REST cache headers)
* Fix: Google Fonts render-blocking stylesheet replaced with async preload+swap pattern
* Fix: revamp-v44.js + frontend.js now deferred (TBT / INP improvement)
* New: 9x WebP placeholder images (avg 81% smaller than PNG)
* New: tv.js preload hint on chart pages from 2nd visit onward
* New: font-display:swap inline override
* New: REST Cache-Control headers (s-maxage=300) on history + chart endpoints
* Removed: duplicate preconnect closure in fx-live-markets.php
* Bumped: FXLM_VERSION and plugin header to 96.6.0

= 96.5.0 =
* New: class-seo-sitemap.php — dynamic XML sitemap for all virtual asset pages
* New: Standalone /blockticker-assets-sitemap.xml endpoint (rewrite rule)
* New: WP 5.5+ core sitemap provider (bt-assets) via anonymous class extension
* New: Yoast sub-sitemap module auto-registered when WPSEO_VERSION detected
* New: Sitemap: directive auto-appended to robots.txt
* New: Google + Bing ping after every sitemap rebuild (fire-and-forget)
* New: Daily cron (bt_regenerate_sitemap) + regenerate on fxlm_refresh_prices
* New: Admin rebuild button with AJAX handler (bt_rebuild_sitemap)
* New: FAQPage JSON-LD schema on 7 key pages (crypto-markets, forex-charts, trading-signals, financial-news, market-analysis, learn, tools)
* New: Dataset JSON-LD schema on crypto-markets and forex-charts pages
* New: news_keywords + revisit-after meta on single posts (Google News eligibility)
* Bumped: FXLM_VERSION and plugin header to 96.5.0

= 96.4.0 =
* Added: `class-native-chart.php` — native [bt_price_chart] shortcode using lightweight-charts (MIT, ~45 KB)
* Added: Chart types: area (gradient fill + volume), line, signal (buy/sell markers with outcome colors from signals_history)
* Added: GET /wp-json/blockticker/v1/chart/{symbol} REST endpoint with price_series + volume_series + signal markers
* Added: 1D/7D/30D/90D range buttons (AJAX re-fetch, no page reload); crosshair tooltip; ResizeObserver; time-scale sync between price/volume panels
* Added: Graceful TradingView fallback when <10 history rows exist for symbol
* Bumped: FXLM_VERSION and plugin header to 96.4.0

= 96.3.0 =
* Added: `class-source-validator.php` — hourly health checks for all 27 RSS feeds and data APIs (HTTP status, body, XML parse, item count, freshness, latency)
* Added: `class-db-admin.php` — WP Admin screen "BlockTicker → Database" with table status, migration, backfill, retention purge, and live source health dashboard
* Fixed: Dead Reuters RSS feed (404 since 2020) replaced with MarketWatch Top Stories
* Fixed: Rate-limited Investing.com Forex feed (used twice) replaced with ForexLive (news) and ForexLive Analysis (signals)
* Improved: `purge_old_data()` now returns deleted row counts for display in admin screen
* Bumped: FXLM_VERSION and plugin header to 96.3.0


= 96.2.0 — DB History Foundation =

This release implements the audit's #1 architectural recommendation:
BlockTicker now has its own database schema for time-series data. The
plugin no longer relies exclusively on `wp_options` blobs that get
overwritten every 5 minutes — it accumulates real historical data.

**New custom tables**
* `wp_bt_price_history` — per-symbol price/volume/mcap snapshots captured
  every 5 minutes by the existing price refresh cron (crypto + forex).
* `wp_bt_news_items` — deduplicated news archive with SHA-256 URL hash
  as the unique key (same story from 3 aggregators = 1 row).
* `wp_bt_signals_history` — trading signals with entry/target/stop,
  ready for accountability grading in v98.
* `wp_bt_events` — unified event stream, seed of the v98 webhook system.

**New REST endpoint**
* `GET /wp-json/blockticker/v1/history/{symbol}?days=7&resolution=1h`
  — reads from the new price_history table, supports 5-minute, hourly,
  and daily aggregation buckets. Rate-limited and CORS-enabled like
  every other endpoint in the namespace.

**How this is wired in (non-invasive)**
* The existing `FXLM_Widgets::fetch_and_store_all()` / `FXLM_RSS` cron
  handlers are completely unchanged.
* A second handler attaches to each of those crons at priority 20 and
  writes the same data into the custom tables after the existing options
  write. If the new handler fails, the plugin behaves exactly like v96.1.
* Readers of `wp_options` continue to work. The custom tables are
  additive; no existing code migrated away from options in this release.

**Retention & uninstall**
* Daily `bt_purge_old_data` cron: price history >90 days and delivered
  events >30 days are deleted automatically. News and signals are kept
  indefinitely (signal history is the competitive moat).
* On uninstall the tables are dropped. Site owners who want to preserve
  history across reinstalls can set in wp-config.php:
  `define( 'BT_KEEP_DATA_ON_UNINSTALL', true );`

**Activation behavior**
* First activation after upgrade: tables created via dbDelta, and the
  current `fxlm_crypto_data` option is backfilled as seed rows so
  charts have a data point immediately instead of "no data yet."

**No breaking changes**
* No renamed constants, functions, or REST endpoints.
* No options removed or restructured.
* No shortcode changes.

= 96.1.0 — Audit v97 Preview (Safe Foundation Fixes) =

This is a drop-in patch applying the reversible, low-risk items from the
deep architectural audit ahead of the full v97 Foundation release. No
breaking changes: no renamed constants, no new database tables yet, no
REST endpoint changes. Existing shortcodes, widgets, and third-party
integrations continue to work unchanged.

**Audit Fixes**
* FIXED (F-01): Version constant mismatch. `FXLM_VERSION` was stuck at
  `89.0.0` while the plugin header read `96.0.0`, breaking cache-bust
  keys and upgrade-routine thresholds. Both now aligned at `96.1.0`
  from a single declared source.
* FIXED (F-03 + F-18): init-hook overload. The rewrite-rules verification
  pass was reading the full `rewrite_rules` option blob on every single
  request — including REST, cron, heartbeat, and XML-RPC. Now:
    - Skipped entirely during REST / cron / ajax / xmlrpc contexts.
    - Cached in a 1-hour transient so frontend pageloads bail without
      hitting the options table.
* FIXED (F-17): Duplicate demo-forex seeding. The `add_action('init', ...)`
  seeder was firing on every request as a belt-and-suspenders backup to
  the activation hook — the activation hook alone is sufficient. Removed.
* REMOVED (F-08): Stale `includes/class-pages.php.bak` (168 KB) shipped
  inside the plugin package. Confirmed no other `.bak/.old/.tmp` cruft.
* IMPROVED (F-11): Decorative emoji removed from navigation labels. The
  audit flagged this as damaging credibility with institutional users.
  Semantic glyphs retained: ₿ Bitcoin, ⟠ Ethereum, ◎ Solana, ◈ BNB
  (official currency symbols), 𝕏 (X/Twitter brand mark), ⭐ (favorite
  affordance on watchlist), and the dynamic language-selector flag.

**Performance Impact**
* Expected TTFB reduction on frontend pages: ~5–15 ms depending on host
  (one fewer `rewrite_rules` option read per request).
* Expected REST API response latency reduction: larger — REST requests
  now bail entirely from the rewrite check and forex seeder.
* wp_options write-pressure: reduced (forex seeder no longer triggers
  a conditional write on every init).

**Coming in v97 Foundation (not shipped in this patch)**
* Custom DB tables (`wp_bt_price_history`, `wp_bt_news_items`,
  `wp_bt_signals_history`, `wp_bt_events`) replacing wp_options blobs.
* `FXLM_` → `BT_` constant rename (breaking change — requires migration).
* REST namespace consolidation (three namespaces → one).
* Source validation framework + dead-feed replacement (Reuters RSS,
  Investing.com rate-limiting).

= 6.0.0 — Major Upgrade =

**Brand**
* Rebranded from "FX Live Markets" to "BlockTicker — Crypto & Forex Intelligence"
* New logo, color palette, and typography (Space Grotesk + DM Sans)

**Bug Fixes**
* FIXED: Navigation menu rendered 6+ times due to Astra theme location assignment
* FIXED: "Home" page title showing above hero content
* FIXED: Forex table showing "Loading..." due to empty API key fallback
* FIXED: Cron schedules registered 3 times (main file + widgets + RSS)
* FIXED: Duplicate add_action hooks for cron events in RSS class
* FIXED: blog_public set to 0 blocking search engine indexing
* FIXED: CSS dependency on exact Astra stylesheet handle name
* FIXED: Menu items cleared before re-creation to prevent duplicates

**New Features**
* Newsletter subscription system with AJAX + Mailchimp/Sendinblue hook
* Fear & Greed Index widget (Alternative.me API, 7-day history chart)
* Crypto converter with 30+ coins + fiat currencies
* Breaking news bar with auto-detection from major sources
* Trending coins bar (sorted by 24h volatility)
* Price highlight cards for hero sections
* Search bar shortcode
* Breadcrumb navigation with schema markup
* News category tabs (All/Crypto/Forex/DeFi)
* Learn/Education hub with glossary (25+ terms)
* Tools page (converter + Fear & Greed + charts)
* Article-level NewsArticle schema for every post
* Full autopilot system (WP core + plugin + theme auto-updates)
* Health check system (daily cron, stale data detection)
* 12 content categories (was 5) — added DeFi, NFT, Regulation, Bitcoin, etc.

**Expanded News Sources (14 total, was 7)**
* Added: Decrypt, BeInCrypto, CryptoSlate, The Block, Blockworks, The Defiant, DailyFX

**New Wizard Steps (15 total, was 13)**
* Step: Set up Tools (converter, Fear & Greed, newsletter)
* Step: Enable Autopilot (auto-updates for everything)

**SEO Improvements**
* Article schema markup on every single post
* Breadcrumb schema with structured data
* Expanded page-level SEO meta for 8 pages (was 5)
* Yoast sitemap auto-enabled
* robots meta with max-snippet:-1 for rich results
* blog_public=1 enabled automatically after setup

**Social Media**
* 8 networks (was 5) — added Discord, LinkedIn, TikTok icon fix

= 5.0.0 =
* Initial release with 10 pages, 7 RSS sources, basic widgets

== Frequently Asked Questions ==

= Do I need API keys? =
The plugin works with demo data out of the box. For live data, get free API keys from ExchangeRate-API.com and CoinGecko.

= Is it really autopilot? =
Yes. After setup, the site auto-updates WordPress core, all plugins, and themes. News feeds refresh hourly, prices every 5 minutes, signals every 15 minutes, and AI posts publish twice daily.

= Can I customize the brand name? =
Yes. Enter your preferred site name in the wizard's API Keys section before running the setup.

= How does it compete with CoinDesk/The Block? =
BlockTicker aggregates from the same sources (14+), adds live market data, trading tools, AI analysis, and runs without any manual content creation needed.
