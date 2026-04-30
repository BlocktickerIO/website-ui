# BlockTicker — Roadmap & Backlog
**Version:** 119.22 · **Last updated:** 2026-04-25

This is the working backlog derived from the April 2026 deep-research audit
(see `research-report-2026-04.md`). Items are tagged by phase and priority.
Completed items move to a CHANGELOG entry, not deleted from here.

Legend: 🔴 P0 critical · 🟠 P1 next · 🟡 P2 nice-to-have · ⚪ icebox
✅ done · 🚧 in-progress · 📋 todo

---

## Phase 1 — Foundation (Weeks 1-4 — current)

### UX & Visual Polish
- ✅ Terminal design system (v117) — Chivo + IBM Plex Sans, neon green, zero radius
- ✅ Logo redesign (v118) — square tile + BLOCKTICKER wordmark
- ✅ News card layout polished (v118)
- ✅ Pagination on /crypto-markets/ and /crypto-category-* (v119.4)
- ✅ Page header eyebrow standardised (v119.4 — inline span, not attr)
- ✅ Animated LIVE badge in ticker bar (v119.5)
- ✅ Admin menu cleanup — 10 → 8 entries (v119.6)
- ✅ Database admin redesigned with 4 tabs (v119.6)
- ✅ TradingView chart config compatibility fix (v119.7)
- ✅ News deduplication at fetch + backfill (v119.8)
- ✅ Alerts subsystem completion — news alerts + Set Alert button + hub + admin (v119.9)
- ✅ SEO forecast pipeline — `/forecast/{slug}/` evergreen + dated archive + sitemap (v119.10)
- ✅ Performance / track-record dashboard — `/performance/` page with verified accuracy stats, methodology box, per-asset breakdown, 30-day sparkline, source leaderboard, Schema.org Dataset markup, summary card embed, admin overview (v119.11)
- ✅ Top-N commercial-intent landing pages — `/top/` hub + 8 ranked list pages with ItemList + FAQPage schema, FAQ accordions, breadcrumbs, see-also navigation (v119.12)
- ✅ Homepage trust strip — [bt_trust_strip] (4-tile adaptive grid: Performance · Trustpilot · Featured-In · Editorial Standards) + [bt_trust_bar] (compact single-line) + [bt_compliance_disclaimer] (4-scope reusable disclaimer block); each tile self-hides if its data isn't configured; Schema.org Organization + AggregateRating JSON-LD (v119.13)
- ✅ Mobile UX layer — sticky bottom nav with hide-on-scroll, asset-page sticky CTA bar, back-to-top FAB, WCAG 2.1 AAA touch-target enforcement, full filter API; coordinated subsystem rather than scattered patches (v119.14)
- ✅ Personalized dashboard (preset-based v1) — `/dashboard/` page, 3 preset layouts, 15-widget composition registry, per-user persistence (user meta + anon localStorage), no-flash preset switching, login-required widget upsell cards (v119.15)
- ✅ Saved screeners — `/screeners/` page with 6-field criteria builder, filter engine over `bt_crypto_data`, 10 categories, max-10-per-user save layer, base64 share-links, auto-registered as dashboard widget (v119.16)
- ✅ Following list — `/following/` page with tabbed picker (assets/sources/signals), drop-in `[bt_follow_button]`, `[bt_personalized_news]` feed integration, dashboard widget pair, three caps (50 assets / 30 sources / 30 signal sources) (v119.17)
- ✅ Mobile UX layer — sticky bottom nav (Markets · Watchlist · Alerts · More) with hide-on-scroll-down, sticky CTA bar (Set Alert + Watchlist + Analysis) on `/crypto/{slug}/` and `/forex/{slug}/`, back-to-top FAB on long pages, WCAG 2.1 AAA touch-target audit (44×44px min), PWA install-prompt coordination via MutationObserver, full filter API for customization (v119.14)
- ✅ HTML email templates — `BT_Portfolio::build_alert_html()` reusable helper produces dark-themed branded emails for alert-set, alert-fired (price), and news-alert-set flows. Inline styles only (Gmail/Outlook strip `<style>`). Replaces three plain-text bodies (v119.20)
- ✅ User menu dropdown overflow fix + Tools dropdown cleanup — personalized pages (Dashboard/Screeners/Following) removed from Tools; live in user menu only. Anonymous users get a single "Personalized Dashboard — sign in" CTA in Tools. Avatar dropdown given `position:relative` parent + viewport-aware `max-width` (v119.20)
- ✅ Credentials consolidation — `render_credentials()` rewritten to use canonical `bt_*` option keys (was silently writing to dead `fxlm_*` namespace). Wizard's API Keys form replaced with status-summary card + "Manage Credentials" button. Single source of truth (v119.20)
- ✅ Alerts tied to user account — `ajax_save_alert()` captures `user_id`, scopes 5-alert rate-limit by user when logged-in (was email-only, bypassable). `[bt_alert_manager]` rewritten to render owner-list without needing a token URL; token URL still works for email recipients; both paths union when a logged-in user clicks a token link. Alert email "Manage" link now points to `/alerts/` (was inconsistent: news alerts pointed there, price alerts pointed to `/tools/`) (v119.21)
- ✅ Custom outgoing-mail sender — `wp_mail_from` and `wp_mail_from_name` filters in `BT_Portfolio::init()`. Two new admin fields (`bt_mail_from_email`, `bt_mail_from_name`) in Credentials → Email Sender group. Validation: malformed addresses fall through to WP defaults rather than producing broken envelopes; display name falls back to `bt_site_name` then to WP default. Replaces "WordPress" sender on every outgoing email (v119.21)
- ✅ Admin wizard stuck on "Running…" fixed — three-part fix: (1) `ob_start()`/`ob_end_clean()` guard in `ajax_run_step()` prevents stray PHP notice/warning output from corrupting the JSON response (jQuery would silently fire the error handler, leaving the row stuck at "Running…" forever); (2) `flush_rewrite_rules(false)` soft-only flush — no .htaccess write that could hang on NFS/LiteSpeed hosts; (3) JS 30-second "Still running" warning with actionable retry instruction; (4) explicit "PHP output contaminated response" error message for non-JSON replies (v119.22)
- ✅ Credentials lost on reinstall fixed — three-part fix: (1) `BT_Admin::on_activate()` now calls `migrate_legacy_credentials()` which copies any surviving `fxlm_*` credential orphans to canonical `bt_*` keys on every activation (safe: never overwrites existing values); (2) Credentials page gains an Export button (downloads dated JSON backup) and Import textarea (paste JSON, whitelist-validated write); (3) one-click "Migrate Legacy Keys" button for manual recovery (v119.22)
- ✅ Personalized AI Brief — `[bt_personalized_brief]` shortcode. Reads each logged-in user's watchlist (user meta), saved screeners (`BT_Screeners::get_user_screeners()`), and following list (`BT_Following::get_following()`). Builds a focused market snapshot + news filter for their assets → calls Claude Sonnet → renders dark-themed card with asset chips, generated-at timestamp, and ↻ Refresh button. Per-user transient cache (1 h / daily key). Login gate for guests. Compact teaser variant `[bt_personalized_brief_compact]` for sidebar/dashboard. Graceful fallbacks: empty state if no assets tracked, admin prompt if Claude key not set (v119.22)
- ✅ Cache-control on personalized pages — new `BT_PersonalizedPages` class hooks `template_redirect` and sends `Cache-Control: private, no-cache, no-store, max-age=0, must-revalidate`, `Vary: Cookie`, `X-Robots-Tag: noindex` on `/dashboard/`, `/screeners/`, `/following/`, `/watchlist/`, `/portfolio/`, `/alerts/`. Fixes "saves not visible after navigate-away" symptoms caused by full-page caches (LiteSpeed/WP Rocket/host-level) serving anonymous HTML to logged-in users. Filterable slug list (v119.21)
- 📋 🟠 Reduce homepage information density — currently 8 sections above the fold
- 📋 🟡 Empty-state designs for every list/table
- 📋 🟡 Loading skeletons for charts and data tables (replace "Loading..." text)

### Trust & E-E-A-T
- ✅ Source attribution on every data point
- ✅ Editorial policy + named analysts (CFA, CMT)
- ✅ Trustpilot widget on home — opt-in tile in [bt_trust_strip] reading bt_trustpilot_url/rating/count (admin-configurable, hidden if not set), Schema.org AggregateRating JSON-LD on homepage when fully configured (v119.13)
- ✅ Performance audit page for Trading Signals — `/performance/` published with verified accuracy %, transparent methodology, per-asset & per-source breakdowns, full audit table (v119.11)
- ✅ Quoted media citations / "as featured in" strip — Featured-In tile in [bt_trust_strip] with admin-configurable logo list (label + image + optional link), grayscale-on-rest / colorize-on-hover treatment, max 12 logos (v119.13)
- ✅ Compliance disclaimer on every analysis page (not just footer) — [bt_compliance_disclaimer] shortcode with 4 scope variants (generic/trading/forex/crypto) for explicit author placement, complementing the existing BT_EEAT::inject_risk_warning() auto-injection on categorized posts (v119.13)
- 📋 ⚪ FINRA/SEC review of US-targeted content

### Technical Foundation
- ✅ Class refactoring — utils, signal-tracker, dex-tokens, intelligence-brief extracted
- ✅ Public REST API + key management
- ✅ PWA support
- 📋 🔴 **Plugin folder structure cleanup** (see `cleanup-structure-plan.md`)
  - PSR-4 namespacing
  - Templates extracted from PHP into `templates/`
  - CSS modules under `assets/css/components/`
  - JS modules under `assets/js/modules/`
- 📋 🟠 Replace `revamp-v44.css` and similar version-named files with descriptive names
- 📋 🟠 Drop legacy `fxlm-` prefix in favour of unified `bt-`
- 📋 🟡 PHPStan / Psalm static analysis to CI

---

## Phase 2 — Content & Growth (Weeks 5-8)

### Alerts & Notifications
- ✅ Price alerts (v119.9) — user-defined thresholds + contextual button on every asset page + admin overview
- ✅ News alerts (v119.9) — keyword filter with hourly/daily/instant email digest
- ✅ Email delivery via wp_mail with branded HTML template (v119.9)
- 📋 🟡 Browser push notifications (PWA)
- 📋 ⚪ Native mobile push (requires app)

### Personalization
- ✅ Watchlist (basic)
- ✅ Portfolio tracker (basic)
- ✅ Custom dashboard layouts (v1, preset-based) — `/dashboard/` page with 3 preset layouts (Markets Focus / Watchlist Focus / Signals Focus), 15-widget composition registry over existing shortcodes, per-user persistence via user meta + localStorage fallback for anon, AJAX preset switcher with no-flash navigation, login-required widget upsell cards, admin overview with per-preset user-count distribution; drag-and-drop reorder + per-widget show/hide deferred to v2 (v119.15)
- ✅ Saved screeners (filter combinations) — `/screeners/` page with 6-field criteria builder (sort/direction/mcap range/24h % range/volume/category), filter engine over `bt_crypto_data` (no new API calls), 10 categories including CoinGecko-cached defi/stablecoins/gaming/metaverse/nft, max 10 saves per user, base64 share-link without persistence, auto-registered as v119.15 dashboard widget (`screeners_summary`) (v119.16)
- ✅ "Following" list — three follow types (assets · news sources · signal sources), `/following/` management page with tabbed picker UI, drop-in `[bt_follow_button]` for any entity card, `[bt_personalized_news]` feed filtered by follows (falls through to recent news for empty follow list), auto-registered as v119.15 dashboard widget pair (`following_summary` + `personalized_news`); tags deferred to v2 (no mature tag taxonomy in current data shape) (v119.17)
- 📋 🟡 Personalized AI brief based on watchlist holdings

### SEO Content Pipeline
- ✅ Daily forecast pages — `/forecast/{slug}/` (10 assets: BTC ETH SOL XRP BNB + EURUSD GBPUSD USDJPY AUDUSD USDCAD), evergreen + dated archive, schema.org Article markup, sitemap inclusion (v119.10)
- 📋 🟠 Forex pair forecast pages: `/eurusd-forecast`, `/gbpusd-forecast`, etc. *(superseded by /forecast/{slug}/ from v119.10 — keep entry as a redirect-from-old-shape note)*
- ✅ Top-N landing pages — `/top/` index + 8 ranked list pages (`/top/top-cryptocurrencies/`, `/top/top-altcoins/`, `/top/top-gainers-24h/`, `/top/top-losers-24h/`, `/top/best-defi-tokens/`, `/top/best-stablecoins/`, `/top/top-gaming-tokens/`, `/top/top-metaverse-tokens/`) with ItemList + FAQPage schema, FAQ accordions, see-also navigation, sitemap inclusion (v119.12)
- 📋 🟠 Educational longform: `/learn/technical-analysis`, `/learn/dex-vs-cex`
- 📋 🟡 Auto-internal-linking from analysis pages to chart pages
- 📋 🟡 Expand tracked assets from 10 → 25 (next 15: ADA MATIC AVAX DOGE LINK + 10 more forex pairs)

### Charting & Analytics
- ✅ Advanced chart controls (interval/type/compare/fullscreen)
- 📋 🟠 In-platform technical indicators (MA, RSI, MACD)
- 📋 🟠 Custom drawing tools (trendlines, fib retracements)
- 📋 🟡 Multi-timeframe view (1h/4h/1D side-by-side)
- 📋 🟡 Side-by-side asset comparison view
- 📋 ⚪ Custom alerts based on TA conditions

### Social & Community
- 📋 🟠 Twitter/X auto-share on AI brief publish
- 📋 🟠 Newsletter daily digest
- 📋 🟡 Reddit post automation (top movers)
- 📋 🟡 Comments on analysis pages (moderated)
- 📋 ⚪ User trading ideas board (TradingView-style)

---

## Phase 3 — Monetization & Community (Weeks 9-12)

### Premium Tier (Beta)
- 📋 🟠 Pricing page — $10-30/mo
- 📋 🟠 Stripe integration
- 📋 🟠 Premium-only content gates (CSS feature flags exist)
- 📋 🟡 Premium features:
  - Unlimited price alerts
  - Historical data export
  - Custom screeners
  - API rate-limit upgrade
  - Ad-free experience

### Affiliate / Partnerships
- ✅ Broker comparison page exists
- 📋 🟠 Hardware wallet recommendations (Ledger, Trezor)
- 📋 🟠 Tax tool affiliate (Koinly, CoinTracker)
- 📋 🟠 Rotating "sponsored" slot in newsletter
- 📋 🟡 Commission dashboard for self-attribution

### Display Ads
- ✅ AdSense integration exists
- 📋 🟡 Ad-density audit — keep below 30% of viewport
- 📋 🟡 Direct sponsorship slots (better margin than AdSense)

### API Licensing
- ✅ Public REST API + key management
- 📋 🟠 Tiered rate limits
- 📋 🟡 Pricing page for API plans
- 📋 ⚪ Stripe metering integration

### Educational Products
- 📋 🟡 "Crypto Technical Analysis 101" course (text + video)
- 📋 🟡 Monthly market reports PDF
- 📋 ⚪ Live webinars

---

## Backlog — Cross-cutting

### Performance
- ✅ TradingView lib pre-warmed on DOMContentLoaded (v119.5)
- ✅ Eager-load first 2 charts, observer for rest (v119.5)
- 📋 🟠 Image lazy loading audit
- 📋 🟠 Critical CSS extraction
- 📋 🟡 Core Web Vitals budget enforcement (currently >85 LCP)

### Internationalization
- ✅ Translation manager admin page
- 📋 🟠 Translate UI strings (currently English-only)
- 📋 🟠 Add ES, FR, JA, KO, PT-BR
- 📋 🟡 Geo-detect default language
- 📋 🟡 hreflang tags for SEO

### Compliance & Legal
- ✅ Editorial policy page
- ✅ Disclaimer on analysis pages (footer)
- 📋 🟠 Update privacy policy for current data flows
- 📋 🟠 Cookie consent (GDPR/CCPA)
- 📋 🟡 Affiliate disclosure on every page (not just footer)
- 📋 🟡 Audit external scripts for tracking

### Security
- 📋 🔴 Force-delete `blockticker-diag.php` on production (now has UI button — v119.6)
- 📋 🟠 2FA for admin login
- 📋 🟠 Rate-limit AJAX endpoints per-IP
- 📋 🟡 CSP headers
- 📋 🟡 SRI for external scripts

### Observability
- ✅ Source health dashboard (DB admin)
- ✅ Forex API diagnostic
- 📋 🟠 Error log surface in admin (instead of digging into hosting panel)
- 📋 🟡 GA4 event tracking inventory

---

## Naming & Code-Style Rules (going forward)

### File naming
- PHP classes: `PascalCase.php` matching the class (PSR-4)
- CSS files: `kebab-case.css` — descriptive, no version numbers
- JS files: `kebab-case.js` — same rule
- Templates: `kebab-case.php`

### CSS classes
- Prefix: `bt-` (drop legacy `fxlm-`, `cp-`)
- Component: `bt-button`, `bt-card`, `bt-table`
- Modifier: `bt-button--primary`, `bt-card--hover`
- State: `is-active`, `is-loading`, `is-disabled` (no prefix)
- Page-specific: `bt-page-home`, `bt-page-crypto-markets`

### PHP classes
- Namespace: `BlockTicker\{Domain}\{ClassName}`
- Methods: `camelCase()`
- Constants: `UPPER_SNAKE`
- Hooks: `blockticker_{action|filter}_{name}`

### CSS variables
- Tokens: `--bt-color-accent`, `--bt-color-bg`, `--bt-font-display`
- Spacing: `--bt-space-1` through `--bt-space-12` (4px scale)
- Drop the dual `--bt-` + `--fxlm-` system

### Anti-patterns to remove
- ❌ Version numbers in filenames (`revamp-v44.css`, `patch-animations.css`)
- ❌ Inline styles in PHP — extract to CSS classes
- ❌ Inline `<script>` blocks in shortcode output — move to enqueued JS
- ❌ Mixed `fxlm-` / `bt-` / `cp-` prefixes — unify on `bt-`
- ❌ Files >500 lines without a clear single responsibility
- ❌ Methods >100 lines — extract sub-helpers

---

## Risks & Open Questions

- **API rate limits**: Free CoinGecko tier (50 req/min) hit ceiling at ~5K daily users. Need paid tier or self-hosted price store.
- **TradingView dependency**: Embed widget can change behaviour without notice. Consider adding lightweight-charts as fallback (already partially done in `class-native-chart.php`).
- **AI cost scaling**: Anthropic API spend grows linearly with traffic. Cache AI briefs aggressively, regenerate only when market state shifts >X%.
- **Plugin folder restructure (Phase 1 P0)** is high-risk — touches every PHP file. Needs the phased migration plan, NOT a one-pass rename.
- **Yoast SEO removal** could affect ranking briefly — measure organic traffic 7 days before/after.

---

## Decision Log

| Date | Decision | Rationale |
|---|---|---|
| 2026-04-24 | Drop Yoast SEO from required plugins | `BT_SEO` covers all features natively, duplicate tags hurt SEO |
| 2026-04-24 | Keep Wordfence + One Click A11y | Security + accessibility are non-negotiable |
| 2026-04-24 | Single accent colour `#00FF66` (no blue secondary) | Terminal aesthetic discipline |
| 2026-04-24 | All radius → 0 site-wide | Same |
| 2026-04-24 | News dedup at fetch + one-shot backfill | Multiple feeds syndicate same article |
| 2026-04-24 | Phase 1 of folder cleanup deferred | Too risky for a single session — needs phased migration |
| 2026-04-25 | News alerts kept in `wp_options` array (not new DB table) | Mirrors existing price-alerts shape; migrate together when either crosses ~500 entries |
| 2026-04-25 | Alerts modal injected via `wp_footer`, not React/AJAX | Zero new build steps, no JS framework dependency |
| 2026-04-25 | Set Alert button added to asset pages, NOT to top-100 list rows | Avoids button-spam in dense tables; users browse to detail page first anyway |
| 2026-04-25 | Forecast URLs use `/forecast/{slug}/` evergreen, NOT `/btc-forecast-YYYY-MM-DD` from research report | Cleaner permalinks, easier internal linking, dated archive lives at sub-path; canonical points to evergreen for first 7 days to avoid duplicate-content |
| 2026-04-25 | Forecast generation: deterministic template ALWAYS, AI optional on top | Pages must never be empty; AI failure or no-API-key is a degraded-but-functional state |
| 2026-04-25 | Cron processes one asset every 30s (not all in parallel) | Respects API rate limits, avoids long-running PHP processes that hit max_execution_time |
| 2026-04-25 | Performance dashboard reads `bt_signal_tracker` (verified outcomes), NOT `bt_signal_track` (older series) | The two systems are independent — tracker has direction-aligned wins/losses we need; track is just hit/miss/pending |
| 2026-04-25 | Methodology box is a top-level section, not a tooltip or footer note | This page IS the trust play — hiding the rules undermines the whole point |
| 2026-04-25 | Schema.org Dataset markup (not just Article) on `/performance/` | Tells Google this is a verifiable factual dataset, not opinion content — stronger E-E-A-T signal |
| 2026-04-25 | Dashboard cached as transient for 5 min; auto-busted on tracker option update | Page renders 5 sub-tables; aggregation across the option is cheap but tablgen isn't free |
| 2026-04-25 | Flats (±0.3% moves) excluded from accuracy denominator, shown in tables | Including them would let noise dilute the rate; counting them honestly is more transparent than hiding them |
| 2026-04-25 | Top-N pages reuse existing `bt_crypto_data` + per-category transients; do NOT call CoinGecko directly | Avoids surprise API spend on a public page; degrades gracefully (empty-state on cold cache) |
| 2026-04-25 | Top-N category lists rely on `[fxlm_crypto_category]` to populate the source transient | Existing widget already does this with 30-min cache; sharing the cache key (`fxlm_cat_{cat}_v3`) means one fetch serves both surfaces |
| 2026-04-25 | Per-list FAQ copy hard-coded in PHP, not in DB | Stable content for SEO (FAQ schema requires unchanging Q&A), version-controlled, one-line filter override (`bt_toplist_definitions`) for site-specific edits |
| 2026-04-25 | `/top/` and `/crypto-category-{x}/` are kept as separate page types | The category pages are explainer-style with charts and learn-more content; `/top/` pages are commercial-intent ranked lists. Different intent, different keyword targets |
| 2026-04-25 | Stablecoins excluded from "altcoins" by hard-coded symbol allowlist (15 symbols) | CoinGecko categories are not always reliable for this filter; symbol list is small and stable |
| 2026-04-25 | Trust strip refuses to render if fewer than 2 tiles have data | A "strip" with one tile is visually a card pretending to be a strip — better to render nothing than something broken |
| 2026-04-25 | Trustpilot tile reads stored config (URL/rating/count), NOT live API | Trustpilot's Business API requires a paid tier + per-fetch latency; stored config is honest, fast, free, and updateable in 30s by the operator. Tile self-hides if any field is missing |
| 2026-04-25 | Schema.org `AggregateRating` only emitted when Trustpilot is FULLY configured (URL + rating > 0 + count > 0) | Google penalises rating markup with placeholder values; emitting only with real data keeps SERP integrity intact |
| 2026-04-25 | `[bt_compliance_disclaimer]` is manual-placement only; does NOT auto-inject | `BT_EEAT::inject_risk_warning()` already auto-injects on categorized posts; a second auto-injector would duplicate the warning. Shortcode complements rather than competes |
| 2026-04-25 | Featured-In logos stored as line-delimited textarea (`Label \| image_url \| link_url`), NOT a repeater field with media library picker | Repeater UI would require a JS dependency or a heavy admin framework; textarea is one input, validates server-side, supports paste-from-spreadsheet, and ships in zero JS |
| 2026-04-25 | Trust strip is shortcode-only; no auto-inject into homepage content | The home page is a saved WP page with content authored in `class-pages.php`; auto-injecting via `the_content` filter would be unpredictable across themes and would fight with theme-specific page templates. Salah pastes `[bt_trust_strip]` exactly where it should appear |
| 2026-04-25 | Mobile UX layer ships as one coherent class, not scattered patches | The pieces share viewport detection, scroll observers, and CSS coordination — splitting them across files would duplicate the matchMedia setup and the hide-on-scroll observer, and would make it harder to keep z-index ordering sane (nav 9990 / CTA 9989 / FAB 9988 / install-prompt above-nav 9991) |
| 2026-04-25 | Bottom nav uses `viewport ≤ 768px` + JS `bt-mux-on` flag, NOT `wp_is_mobile()` for visibility | UA sniffing is unreliable (tablets, desktop responsive previews, devtools); viewport width is what actually matters for thumb-reach UX. PHP `wp_is_mobile()` is used only as a hint for whether to inline the JS block at all |
| 2026-04-25 | CTA bar dispatches CustomEvents + falls back to clicking in-page buttons | Tight coupling to `BT_Alerts` would mean the CTA breaks if alerts subsystem is filtered out; loose coupling via events lets any subsystem listen, and the click-fallback handles the common case where the in-page Set Alert button already exists |
| 2026-04-25 | PWA install prompt coordination via MutationObserver in client script, NOT by editing `class-pwa.php` | Keeps the mobile-UX subsystem self-contained — uninstalling it removes all of its behaviour without leaving stranded references in PWA. The observer is cheap (one element, attribute changes only) |
| 2026-04-25 | Mobile UX configuration via WordPress filters, NOT an admin form | Salah is the developer and the values (nav items, scroll thresholds) change rarely; filters are version-controllable, testable, and avoid an admin form that would need its own validation+sanitization layer for marginal benefit. Admin page exists but is read-only visibility into the active config |
| 2026-04-25 | Asset CTA bar detects context via REQUEST_URI parsing, mirrors `BT_AssetPages::intercept_asset_url()` | Works regardless of rewrite-rule flush state; same pattern already proven in production for routing |
| 2026-04-25 | Dashboard v1 ships preset-only; drag-drop reorder + per-widget show/hide deferred to v2 | Drag-drop in one session is high-risk (pointer/touch handling, persistence, conflict resolution, undo path); a v1 with 3 well-designed presets is meaningful personalization (different starting experiences for different intents); a v1 with broken drag-drop is worse than no drag-drop. The v2 layer can add finer controls without touching the underlying widget registry |
| 2026-04-25 | Dashboard widgets are pure shortcode composition, not new visual components | Stays in lockstep with underlying widgets automatically; adding a preset is a single array addition; testing surface stays small. Trade-off: less visual cohesion than purpose-built cards, but the existing widgets already have card-like framing so the result reads as a unified dashboard |
| 2026-04-25 | Anon preset persistence via localStorage + soft URL redirect to `?preset=…`, NOT client-side render swap | Avoids flash-of-wrong-preset on initial paint — server renders the default, client checks localStorage, navigates if mismatch. The redirect is one extra request but it's cached HTML; the alternative (render server-side as default, then swap client-side) flashes wrong content for 100-300ms which is worse UX than a navigation |
| 2026-04-25 | `/dashboard/` page is `noindex,nofollow` | Personalized content shouldn't be in SERPs — the same URL serves different layouts to different users, and Google can't index a single canonical version. The marketing landing surfaces (homepage, /forecast/, /top/) remain indexable |
| 2026-04-25 | Logged-in users persist via user meta; AJAX endpoint returns 401 for anon | Anon clients are expected to use localStorage exclusively; the no-auth handler exists only so requests fail with a real 401 rather than WP's default `0` response — easier to debug if a client somehow reaches the AJAX endpoint without being logged in |
| 2026-04-25 | `render_shortcode_safe()` returns empty string when shortcode tag isn't registered | Default WP behaviour leaves the literal `[shortcode]` marker on the page if the tag isn't found; if a widget references a shortcode whose class has been filtered out, the dashboard would show raw markup. Empty fallback keeps the card frame intact and shows the "Data warming up" empty-state instead |
| 2026-04-25 | Dashboard registers 3 preset URLs but only 1 page (`/dashboard/`) — preset is selected via `?preset=…` query param | Three URLs (`/dashboard/markets/`, `/dashboard/watchlist/`, `/dashboard/signals/`) would mean three sitemap entries with the same canonical content (just different starting widgets). Keeping it one URL with a query param keeps SEO signal clean and matches how WordPress page caching plugins handle parameterized URLs |
| 2026-04-25 | Screener criteria stored as a JSON schema, not URL params or `meta_*` rows | Centralised validation in one `normalize_criteria()` function; forward-compatible (adding a `chain` filter is a schema bump not a URL-format change); compact for storage. Trade-off: adds a small serialization layer on top of user meta but makes admin aggregation queries trivial (decode JSON, inspect `criteria.category`) |
| 2026-04-25 | Screener cap is 10 per user (mirrors watchlist cap) | Same trade-off as watchlists — enough for a power user's typical investigation patterns, low enough that the UI fits without pagination, low enough that aggregation queries scan a small JSON blob per user |
| 2026-04-25 | Share-links use base64-encoded criteria, NOT a per-share DB record | Stateless: no DB write per share, no garbage-collection problem, recipient can save a copy themselves. The criteria JSON is ~150 bytes; base64 is 200 bytes; URL stays under 250 chars even with realistic filter combinations. The trade-off is no analytics on shared screeners, which is fine — usage analytics are an icebox feature |
| 2026-04-25 | CoinGecko-category screeners reuse `fxlm_cat_{cat}_v3` transients populated by `[fxlm_crypto_category]` | Same pattern as v119.12 top-N pages — avoids surprise API spend; cold-cache state falls through to the general universe rather than empty (better UX than "no results" when the cache is just warming up) |
| 2026-04-25 | Screeners auto-register as a v119.15 dashboard widget via `bt_dashboard_widget_registry` filter | Composition over coupling — class-screeners.php hooks into the dashboard's filter to add itself, rather than dashboard hardcoding screeners. If `BT_Screeners` is filtered out, the widget simply doesn't appear; dashboard preset configs can still reference `screeners_summary` and the safe-fail rendering handles the missing-shortcode case |
| 2026-04-25 | Screener result rendering uses ARIA live-region for AJAX updates | `aria-live="polite"` on `#bt-scr-results` means screen readers announce "Results (N)" updates after AJAX runs without grabbing focus from the builder controls. Polite (not assertive) so it doesn't interrupt typing |
| 2026-04-25 | `<=>` and `??` operators flagged as phply false-positives in lint sweeps | Both are PHP 7+ syntax (spaceship + null-coalesce); production runs PHP 8+ and supports them. Lint sweeps that fail on these in this codebase should be considered tooling limitation, not a code defect — every release v119.x uses both heavily |
| 2026-04-25 | Following v1 ships capture + integration; tags deferred to v2 | Following without integration is just a checkbox. v1's value is the integration: `[bt_personalized_news]` filters the news feed, drop-in follow button extends to existing card templates. Tags would add a fourth entity type but the news/signals data shape doesn't have a mature tag taxonomy yet — adding tags first would require schema work on news pipeline first |
| 2026-04-25 | Three follow types stored in ONE user-meta record (`bt_user_following`), not three separate keys | Atomic reads: a single `get_user_meta()` call returns the full follow shape; saves are one `update_user_meta()`. Trade-off: every flip rewrites all three arrays as JSON, but JSON blob is < 5KB even at full caps so I/O is negligible. Three separate keys would mean three reads on every page render that uses follows |
| 2026-04-25 | `[bt_follow_button]` self-wires its own delegated click handler with a `window.__btFollowWired` guard | Drop-in friendly: one shortcode invocation does everything; no requirement to enqueue a script first. Delegated handler means N follow buttons on a page (e.g. one per asset card) cost one event listener total. The window-flag prevents duplicate wiring if the shortcode renders twice on a page |
| 2026-04-25 | Personalized news falls through to recent news when user follows nothing | An empty "you have no follows" upsell on a news widget is worse UX than just showing news. The widget renders a soft hint at the top inviting follow setup; the actual news stays informative. New users get value immediately; returning users with follows get the personalised view. No state where the widget is empty unless the news pipeline is empty |
| 2026-04-25 | News query: `source IN (...)` ORed with `symbols_mentioned LIKE` chain — single SQL, both filters | Could implement as PHP-side filter over a wider SELECT, but the LIKE chain on a text column is the right shape for the existing schema (symbols_mentioned is comma-or-space-delimited; no FULLTEXT index). With realistic follow caps (50 assets max), the OR chain has at most 50 terms — well within MySQL's index-touching budget |
| 2026-04-25 | Signal sources discovered from `bt_signal_items` option, not `bt_signals_history` table | The option is what the public `[fxlm_signals_feed]` actually displays; the table is the historical archive. Picker shows what's currently surfaced to users — if a source has dropped off the live feed, it dropped off the picker too |
| 2026-04-25 | Adopting v119.16's auto-registration pattern for dashboard composition | `class-following.php` adds itself to `bt_dashboard_widget_registry` rather than dashboard hardcoding it. Same architectural choice as screeners: composition over coupling. If `BT_Following` is filtered out, dashboard preset configs that reference `following_summary` simply render the safe-fail empty-state — no runtime errors, no dependencies to manage |
| 2026-04-25 | v119.18 hotfix: bumped `admin_menu` priority from 10 to 20 in 6 classes (performance/trust-strip/mobile-ux/dashboard/screeners/following) | Bug: all 6 admin submenus generated wrong URLs (`/wp-admin/bt-{slug}` instead of `/wp-admin/admin.php?page=bt-{slug}`). Root cause: `BT_Admin::add_menu` registers via `plugins_loaded → init → admin_menu`, while my classes registered `admin_menu` listeners directly at file-include time. At default priority 10, my callbacks fired in insertion order BEFORE `BT_Admin::add_menu`, meaning `add_submenu_page('fxlm-wizard',…)` ran when `$admin_page_hooks['fxlm-wizard']` didn't exist yet — `get_plugin_page_hookname` returned `admin_page_*` instead of `toplevel_page_*`, breaking later URL resolution. Future admin menu registrations should default to priority ≥ 20 to stay safely after parent registration |
| 2026-04-25 | v119.19 hotfix: credentials save handler skips empty submissions for secret fields | Bug: every "Save Credentials" click without re-typing wiped saved API keys. The form rendered secret fields with empty `value=""` + masked placeholder ("leave blank to keep"), but the save handler called `update_option()` unconditionally on every field in `$_POST` including empty strings. Fixed by detecting secret fields (substring match on `_secret`/`_api_key`/`client_id`) and skipping when submission is empty. Now reports `"X updated, Y kept (left blank)"` so the operator sees confirmation that empty fields were preserved |
| 2026-04-25 | v119.19: forecast detail body styled via adjacent-sibling CSS over wp_kses_post output | The forecast body is HTML generated either by template or by AI; we don't control its exact structure (paragraphs, lists, h2/h3). Used CSS adjacent-sibling selectors (`h2 + p`, `h2 + ul`, etc.) to give every section a card-frame visually without requiring the renderer to wrap each section in a div. Trade-off: relies on heading-led structure (every section starts with h2/h3); gracefully degrades if the body is just paragraphs |
| 2026-04-25 | v119.19: live ticker timestamp moved from `--bt-text-3` to `--bt-text-2` + weight 600 | Original `color: var(--bt-text-3)` at `font-weight: 400` was below WCAG contrast minimum on the dark ticker bar — the "1 minute ago" indicator that signals data freshness was invisible. Freshness signals must be readable; if you can't see when the last refresh was you can't trust the data |
| 2026-04-25 | v119.19: new pages discoverable via existing dropdowns + user menu, NOT new top-level nav items | Adding 6 new top-level nav items (forecasts, top, performance, dashboard, screeners, following) would have made the navbar look cluttered. Existing dropdowns are the natural homes: Crypto → Top Lists; Analysis → Forecasts + Track Record; Tools → Dashboard + Screeners + Following. User menu (logged-in) gets the personalized 3 (Dashboard/Screeners/Following) plus Alerts for completeness |
| 2026-04-25 | v119.19: nav additions are inline edits to `class-navbar.php`, NOT to the `bt_menu_config` admin-configurable system | The admin Menu Configurator only controls the simple flat menu list (mobile, fallback). Top-level dropdowns are hardcoded in `class-navbar.php` for design control. Long-term: dropdowns should also be admin-configurable, but that's a v120 refactor not a one-line nav fix |
| 2026-04-25 | v119.20 hotfix: credentials submenu was silently broken — wrong option-key namespace (`fxlm_*` instead of canonical `bt_*`) | Critical follow-up to v119.19. The Credentials page read and wrote `fxlm_cmc_api_key`, `fxlm_claude_api_key`, etc. — but the rest of the codebase migrated to `bt_*` keys back in v98 (see `class-migration.php`). Saves went to dead options that nothing read. The wizard's AJAX endpoint correctly wrote to `bt_*` so the wizard worked, which is why the bug went undetected: each form looked "saved" individually, but they wrote to two different namespaces. Fix: rewrote `render_credentials()` to use canonical `bt_*` keys throughout. Pre-existing data in `bt_*` (saved via wizard) now appears correctly in Credentials and the v119.19 "leave blank to keep" pattern works as intended |
| 2026-04-25 | v119.20: wizard's API Keys form replaced with status-summary card + "Manage Credentials" button — single source of truth | The user reported "duplicate credentials in admin dashboard in 2 places" — and they were right. Two forms, two namespaces, divergent state. Fix collapses the wizard's ~70-line form into a ~30-line summary showing per-key SET/not-set status with green/grey indicators, plus a primary CTA to the Credentials submenu. Both URLs (wizard + credentials submenu) now point at the same data. The wizard's `ajax_run_step` save handler is left intact for backcompat (run-step buttons may pass keys), but no UI form writes to it any more |
| 2026-04-25 | v119.20: alert emails converted from plain-text to HTML via reusable `BT_Portfolio::build_alert_html()` helper | The user feedback "how improve the notification email template html rich style" pointed to two plain-text emails (alert-set confirmation + alert-fired notification) that looked spammy and out-of-brand. Built a single helper that takes structured input (kicker, headline, sub, key/value rows, CTA) and produces a dark-themed HTML email matching the visual language of the existing `BT_Alerts::build_digest_html()`. Reused from both the price-alert flow (portfolio class) and the news-alert-set flow (alerts class) for consistency. Inline styles only — Gmail/Outlook strip `<style>` blocks |
| 2026-04-25 | v119.20: My Dashboard / Screeners / Following removed from Tools dropdown; kept in user menu only | The user reported "is it normal My Dashboard My screeners and Following under Tools?" — the intuition was correct: Tools is for general utilities (Crypto Converter, Economic Calendar, Watchlist, Best Brokers), while these are personalized user features. They were duplicates: already in user dropdown for logged-in users. Anonymous users still get a single "Personalized Dashboard — sign in" entry in Tools as a sign-in CTA, so discoverability is preserved without the duplication |
| 2026-04-25 | v119.20: user menu dropdown given `position:relative` parent and `max-width:calc(100vw - 24px)` to prevent right-edge overflow | The user-menu dropdown was anchoring to `right:0` of the navbar (nearest positioned ancestor) instead of the avatar wrap, pushing content past the viewport edge on narrower screens. Added `position:relative` to `.bt-nav-avatar-wrap` so absolute child anchors correctly, plus a viewport-aware `max-width` so the dropdown never overflows. Same fix applied to the JS-injected version in `class-userauth.php` for OAuth post-login parity |
| 2026-04-25 | v119.21: alert "Manage" link standardised to `/alerts/` across both price- and news-alert flows | The user reported the alert email link opened `/tools/`. News alerts had always pointed to `/alerts/` (the dedicated hub) but price alerts in `class-portfolio.php` pointed to `/tools/` from inception — a copy-paste from when there was no dedicated alerts page. Both flows now use `/alerts/`. Single line change × 2 sites |
| 2026-04-25 | v119.21: alerts captured `user_id` on creation; sc_alert_manager renders owner-list without token | The user reported "the alert is not saved/displayed in the user's account". Diagnosis: the alert array had no `user_id` field — alerts were keyed only by `email` + an opaque `token`. Even logged-in users had to dig the token URL out of email to manage their own alerts. Fix captures `get_current_user_id()` on creation; `[bt_alert_manager]` shortcode now unions owner-match (logged-in user) and token-match (legacy email link) sets, so alerts appear in the user's account view immediately. Pre-v119.21 alerts have no `user_id`; they remain manageable via the email link as before. Authorisation for delete still requires either token-match or owner-match (no privilege escalation) |
| 2026-04-25 | v119.21: rate-limit scoped by user_id when logged-in (was email-only) | The 5-active-alert cap was previously enforced on `$alert['email'] === $email`. A logged-in user could bypass this by changing the email on each new alert (the form takes any address). Fix: when `user_id > 0`, scope by user_id. When anonymous, fall back to email scoping but exclude entries with non-zero user_id (so a user's account-owned alerts don't count against an anon submission with the same email). Side effect: the system is now correct for the case where a user creates an alert before signing in, then signs in — the anon alert remains anon, and the user starts with a clean 5-cap |
| 2026-04-25 | v119.21: customisable wp_mail_from/wp_mail_from_name with admin-configurable bt_mail_from_email/bt_mail_from_name | The user asked "can I customize sender instead of WordPress, I set info@blockticker.io?" — yes, but it required filter hooks the plugin didn't have. Added two filters in `BT_Portfolio::init()` (alongside the cron registration and other email-adjacent setup, since BT_Portfolio is the largest mail caller). Both validate input (invalid email falls through to WP default rather than producing a broken envelope; empty display name falls back to `bt_site_name` then to WP). Two new admin fields under Credentials → Email Sender. Deliberately did NOT default `from_email` to `noreply@<domain>` automatically — that would silently change behaviour on upgrade for sites that had been relying on the WP default; the operator must opt in by setting the field |
| 2026-04-25 | v119.21: BT_PersonalizedPages cache-control class — fix for "saves not visible after navigate-away" | The user reported "My screeners also not saved" — but the server-side flow was correct end-to-end. Actual bug: WordPress full-page cache (LiteSpeed Cache, WP Rocket, host-level cache) serving anonymous HTML to logged-in users on `/screeners/`, `/dashboard/`, `/following/`, `/watchlist/`, `/portfolio/`, `/alerts/`. User saves a screener, server stores it, but the next page load returns the cached HTML showing the empty-state. Built a small class hooking `template_redirect` priority 1 (before any plugin templates load) that sends `Cache-Control: private, no-cache, no-store, max-age=0, must-revalidate` + `Vary: Cookie` + `X-Robots-Tag: noindex`. Vary: Cookie is the key — well-behaved CDNs will key cache entries by auth cookie. Filterable slug list via `bt_personalized_page_slugs` and global kill-switch via `bt_personalized_pages_enabled`. Why a separate class instead of inlining into each page-rendering class: ALL these pages have the same problem and the same fix; one class is better than six per-class header sends, and it survives independent of the underlying classes (e.g. if a user doesn't enable BT_Following, the slug protection still works for the other 5 pages) |

