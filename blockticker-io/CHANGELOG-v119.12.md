# BlockTicker — v119.12.0

**Released:** 2026-04-25
**Theme:** Top-N commercial-intent landing pages (🟠 P1 from Phase 2 SEO Content Pipeline)

## Summary

Extends the SEO content pipeline shipped in v119.10 (forecast pages — informational
intent) with a new surface targeting **commercial intent**: ranked "best-of" and
"top-N" landing pages. Forecast pages capture readers searching "BTC forecast";
top-list pages capture readers searching "best DeFi tokens" or "top altcoins" —
queries with much higher conversion intent.

Eight new high-value indexable URLs ship in this release, all served from
existing data sources with zero additional API spend. The pages are fully cached,
fully self-contained, and gracefully degrade to empty-state if source data
hasn't yet been fetched.

## What's new

### URL routing

- **`/top/`** — index page listing all available rankings as cards (CollectionPage)
- **`/top/{list-slug}/`** — ranked list detail page (ItemList + FAQPage schema)

### Eight launch lists

| Slug | Source | Items | Targets |
|------|--------|------:|---------|
| `top-cryptocurrencies` | local `bt_crypto_data` | 20 | "top cryptocurrencies", "biggest crypto by market cap" |
| `top-altcoins` | local (BTC + 15 stablecoins excluded) | 20 | "top altcoins", "best altcoins to watch" |
| `top-gainers-24h` | local (top-100 mcap floor) | 10 | "biggest crypto gainers", "best performing crypto today" |
| `top-losers-24h` | local (top-100 mcap floor) | 10 | "crypto losers", "biggest crypto drops" |
| `best-defi-tokens` | CoinGecko DeFi category | 15 | "best DeFi tokens", "top DeFi coins" |
| `best-stablecoins` | CoinGecko Stablecoins category | 10 | "best stablecoins", "top stablecoin list" |
| `top-gaming-tokens` | CoinGecko Gaming category | 15 | "top gaming tokens", "best GameFi coins" |
| `top-metaverse-tokens` | CoinGecko Metaverse category | 10 | "top metaverse tokens", "best metaverse coins" |

The list registry is filterable — `add_filter('bt_toplist_definitions', …)` lets
site-specific code add, remove, or override any list without modifying core.

### Page anatomy

Each detail page renders six sections in fixed order:

1. **Breadcrumb** — Home › Top Lists › {list title}
2. **Hero** — eyebrow with last-updated UTC timestamp, H1, intro paragraph
   (~80-150 unique words per list — important for SEO, prevents duplicate-content
   penalty)
3. **Ranked table** — Rank · Asset (icon + name + symbol) · Price · 24h % ·
   7d % · Market Cap · Details link. Mobile collapses to Rank · Asset · Price ·
   24h % · Details to fit narrow viewports.
4. **Methodology** — single-paragraph callout explaining how the list is
   compiled. Trust signal carried over from the v119.11 performance dashboard.
5. **FAQ accordion** — 3-4 unique Q&As per list, first one open by default,
   `<details>`-based (no JS dependency), animated rotate-on-open `+` icon.
6. **See also** — 4 cards linking to other lists for internal-link distribution.

Closes with the standard yellow-bordered "Not investment advice" disclaimer.

### Schema.org markup

Each detail page emits two JSON-LD blocks:

- **`ItemList`** — full ranked list with `position` and link-back to each
  asset's `/crypto/{slug}/` detail page. Tells Google this page is a structured
  ranking, eligible for rich result treatment.
- **`FAQPage`** — every Q&A wrapped as a `Question` / `Answer` pair. Eligible
  for FAQ rich snippets in search results.

### Data strategy — zero new API spend

- **Local-source lists** (top-cryptocurrencies, top-altcoins, gainers, losers)
  read from the existing `bt_crypto_data` option, which is already refreshed by
  the existing cron. Filter (e.g. exclude stablecoins) and sort happens at
  render time over the in-memory array.
- **Category-source lists** (DeFi, stablecoins, gaming, metaverse) read from the
  per-category transients written by the existing `[fxlm_crypto_category]`
  shortcode (cache key `fxlm_cat_{cat}_v3`, 30-min TTL). When the category page
  is visited or the widget appears anywhere, the cache populates — and the
  top-list page reuses it for free.
- If a category transient is empty (cold cache, no visitor has hit the category
  page yet), the top-list page renders an explanatory empty-state — never makes
  a surprise API call on a public page.

### Caching

Each rendered list page (HTML body) is cached as a 15-minute WordPress transient
keyed by slug (`bt_toplist_html_{slug}`). The index page is similarly cached
(`bt_toplist_html__index`). Local-source caches auto-bust whenever
`bt_crypto_data` updates; category-source caches expire on their own 15-minute
TTL. An admin "Clear all caches" button forces a full refresh.

### Sitemap integration

Hooks the v119.10 `bt_sitemap_extra_urls` filter — `/top/` registers at priority
0.8 (daily changefreq), each detail at 0.7. **Nine new high-priority indexable
URLs** added to the sitemap with this release.

### Admin overview

New submenu **BlockTicker → Top Lists** with:
- Status table per list (slug, title, source, item count, cache state, public URL)
- "Clear all rendered-page caches" button for forced refresh
- Direct links to each public list

## Files changed

| File                                  | Change                                          |
|---------------------------------------|-------------------------------------------------|
| `includes/class-top-lists.php`        | NEW — full subsystem (~970 lines)               |
| `fx-live-markets.php`                 | `require_once` + version → 119.12.0             |
| `assets/css/revamp-v44.css`           | Append top-list CSS module (~385 lines)         |
| `docs/ROADMAP.md`                     | Top-N landing pages ✅; 6 decision-log entries   |
| `CHANGELOG-v119.12.md`                | NEW                                             |

Note: no `class-pages.php` change this release — the top-list URLs are served
via rewrite-rule + URI interception (same pattern as `/forecast/`), not the
page-registry approach used for `/alerts/` and `/performance/`. Rewrite + virtual
post is the correct pattern when the URL set is dynamic and config-driven.

## Validation

- 57 PHP files all `php -l` clean (was 56 before this release)
- CSS braces 2618/2618 balanced (was 2526)
- URL regex sanity-tested: 7 cases, all pass (uppercase rejected, deep paths rejected)
- JS-quote-in-PHP-string regression scan clean
- No URL collision with existing routes (`/top/` namespace previously unused)
- No cross-class dependencies that load after this class

## Decision log highlights (full entries in ROADMAP.md)

- **Reuse local + existing transients, never call CoinGecko directly** — public
  pages should never make uncached external API calls; surprise spend is a
  reliability risk.
- **Per-list FAQ copy hard-coded in PHP, not in DB** — stable text is what FAQ
  schema needs; in-code copy is version-controlled and overridable via filter
  for site-specific tweaks.
- **`/top/` is separate from `/crypto-category-{x}/`** — different intent,
  different keyword targets. Category pages are explainer-style with charts;
  top-list pages are commercial-intent ranked lists.
- **Stablecoin exclusion via hard-coded symbol allowlist** — CoinGecko
  categorisation isn't reliable enough for this filter; the 15-symbol list is
  small, stable, and easy to extend.

## Roadmap status after v119.12

Phase 2 → SEO Content Pipeline is now substantially complete:
- ✅ Daily forecast pages (v119.10)
- ✅ Top-N landing pages (v119.12)

Plus four 🔴/🟠 audit items closed across the run:
- ✅ Price alerts (v119.9)
- ✅ News alerts (v119.9)
- ✅ Daily forecast pages (v119.10)
- ✅ Performance audit page (v119.11)
- ✅ Top-N landing pages (v119.12)

Indexable URL count added across these releases: **40+ new evergreen URLs**
(11 forecast + 9 top-list + ~20 historical archive URLs accumulating daily),
all linked from the sitemap and contextually cross-linked between sections.

**Next-up unblocked items** in priority order:
- 🟠 **Trustpilot widget on home** (Phase 1 trust strip — pairs with the v119.11
  `[bt_performance_summary_card]` for unified social proof above the fold)
- 🟠 **Mobile UX audit** (Phase 1 polish — closes more of Phase 1)
- 🟠 **Custom dashboard layouts** (Phase 2 personalization — drag-and-drop widgets
  on top of the watchlist/alerts/portfolio user surface)
- 🟡 **Browser push notifications** (Phase 2 — completes the alerts triangle:
  email ✅, SMS deferred, push remaining)
