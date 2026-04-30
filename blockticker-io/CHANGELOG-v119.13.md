# BlockTicker — v119.13.0

**Released:** 2026-04-25
**Theme:** Homepage trust strip + reusable compliance disclaimer (🟠 P1 from Phase 1 Trust & E-E-A-T)

## Summary

Closes three open items from the Phase 1 **Trust & E-E-A-T** track in a single
coherent release: the long-pending Trustpilot widget, the "as featured in" media
strip, and the on-page compliance disclaimer. The pieces ship as a unified
**Trust Strip** subsystem rather than three disconnected widgets — visitors see
one coordinated trust surface above the fold, configured from a single admin
screen.

This release also activates the dormant `[bt_performance_summary_card]`
introduced in v119.11 by wrapping it as the first tile of the strip — the card
was building accuracy data for two weeks but had no public placement; it now
has a permanent home alongside Trustpilot, featured-in logos, and editorial
attribution.

The conversion play behind v119.10 (forecast SEO) and v119.12 (top-N SEO) was
to bring traffic in. This release is the conversion play for what happens
**after** they land — turning that traffic into trust.

## What's new

### Three new shortcodes

| Shortcode | Purpose |
|---|---|
| `[bt_trust_strip]` | Full multi-tile strip — Performance · Trustpilot · Featured-In · Editorial |
| `[bt_trust_bar]` | Compact single-line bar — accuracy · rating · author |
| `[bt_compliance_disclaimer scope="…"]` | Reusable "not investment advice" block with 4 scope variants |

### `[bt_trust_strip]` — adaptive 4-tile grid

Each tile is independent and self-hides if its data isn't available. The strip
itself refuses to render if fewer than 2 tiles have data — a "strip" of one
tile is a card pretending to be a strip. Layout adapts to tile count
(`.bt-trust-strip-c2/c3/c4` modifiers) so the grid stays balanced regardless
of which tiles are active.

**Tile 1 — Performance.** Pulls live from `BT_Performance::get_aggregate_stats()`.
Shows verified accuracy %, total signals, and the 48h tracking window. Links
to `/performance/`. Hidden if zero verified signals.

**Tile 2 — Trustpilot.** Renders from stored config (`bt_trustpilot_url`,
`bt_trustpilot_rating`, `bt_trustpilot_count`). Includes 5-star visual with
half-star support for fractional ratings (e.g. 4.7). Hidden if URL is empty
or rating is zero. **No live API calls** — Trustpilot's Business API is paid
and adds latency for no real benefit; stored config is honest, fast, and free.

**Tile 3 — Featured-In.** Renders the configured publication logos with a
tasteful grayscale-on-rest / colorize-on-hover treatment. Logos stored as
line-delimited textarea config (`Label | image_url | link_url`), max 12 entries.
Hidden if no logos are set.

**Tile 4 — Editorial Standards.** Pulls the lead author profile from
`BT_EEAT::get_authors()`. Shows name, title, credentials. Links to `/about/`.
Always available (EEAT class has site default).

Attributes:
- `tagline="…"` — optional intro line above the grid (default: stored option)
- `show="performance,trustpilot,featured,editorial"` — restrict to specific tiles
- `layout="auto|compact"` — compact drops eyebrows + CTAs for denser placements

### `[bt_trust_bar]` — single-line variant

For high-density placements between the hero and first content fold, or above
the footer. Renders accuracy · rating · author as a single horizontal bar with
configurable separator. Each segment self-hides on missing data; bar refuses to
render with fewer than 2 segments.

### `[bt_compliance_disclaimer scope="…"]`

Reusable "not investment advice" block for explicit author placement on
landing pages and analysis content. Four scope variants with progressively
more specific disclaimer wording:

| Scope | Body |
|---|---|
| `generic` | General investment-advice disclaimer (default) |
| `trading` | Trading signal-specific — references `/performance/` for transparency |
| `forex` | FX-specific — leverage/risk language |
| `crypto` | Crypto-specific — volatility + unregulated jurisdictions |

Plus a `compact="yes"` mode for tighter placements.

**Distinct from `BT_EEAT::inject_risk_warning()`** — that auto-injects on
categorized posts (`market-analysis`, `trading-signals`, etc.). The new
shortcode is for explicit placement on landing pages, top-N pages, and forecast
templates where auto-injection would either miss (no category) or duplicate
(double warning).

### Schema.org Organization + AggregateRating JSON-LD

Emitted on the homepage (and only the homepage) when Trustpilot is fully
configured — URL + rating > 0 + count > 0. Google penalises rating markup with
placeholder values, so the schema only ships when there's real data behind it.

```json
{
  "@context": "https://schema.org",
  "@type": "Organization",
  "name": "BlockTicker",
  "url": "https://…",
  "aggregateRating": {
    "@type": "AggregateRating",
    "ratingValue": "4.7",
    "bestRating": "5",
    "worstRating": "1",
    "ratingCount": "234",
    "reviewCount": "234"
  }
}
```

### Admin overview at `BlockTicker → Trust Strip`

Single screen for the entire subsystem:

- **Tile readiness table** — at-a-glance status for each of the four tiles
  (Active / Warming up / Not configured / Missing) with detail messaging
- **Trustpilot section** — URL, rating (0-5, step 0.1), review count
- **Featured-In logos** — line-delimited textarea, paste-from-spreadsheet friendly
- **Strip-level options** — optional tagline above the grid
- **Live preview** — renders the strip with current saved config in a dark-bg pane
- **Shortcode reference** — copy-paste examples for all three shortcodes
- **Reset all settings** — guarded delete button

### Caching

Strip HTML cached as a transient for 10 minutes per shortcode-attribute
signature (different `show=` or `layout=` calls cache independently). Auto-busts
on any of the five config option updates. Tile-level data (performance stats,
EEAT author) is read fresh from the source classes each time the strip renders
on cold cache, so changes there propagate within the cache TTL.

## Files changed

| File | Change |
|---|---|
| `includes/class-trust-strip.php` | NEW — full subsystem (~700 lines, 28 KB) |
| `fx-live-markets.php` | `require_once` after class-top-lists; version → 119.13.0 (header + `BT_VERSION`) |
| `assets/css/revamp-v44.css` | Append trust-strip CSS module (62 new rules, ~250 lines) |
| `docs/ROADMAP.md` | Trustpilot ✅ · Featured-in ✅ · Compliance disclaimer ✅ · v119.13 added to Phase 1 done list · 7 new decision-log entries |
| `CHANGELOG-v119.13.md` | NEW |

No `class-pages.php` change this release — the trust strip is shortcode-only,
designed for manual placement on the homepage and any landing/analysis page
where conversion matters. Auto-injection via `the_content` was rejected as a
design choice (see decision log) — it would fight with theme templates and
make placement unpredictable across the site.

## Validation

- `includes/class-trust-strip.php` parsed clean via phply
- All four critical bootstrap edits in `fx-live-markets.php` verified
  (header version, `BT_VERSION` constant, require_once line, top-lists still present)
- CSS brace balance: 2682/2682 (62 rules added)
- All `echo $var` sites in the new class commented as either pre-built escaped
  HTML or rendered from internal renderers (JS-quote-in-PHP-string regression risk: clean)
- Strip refuses to render with <2 tiles — tested via empty config (admin preview shows
  empty-state message rather than broken UI)
- Schema.org JSON-LD only emitted when Trustpilot fully configured — tested via
  partial-config to confirm no placeholder schema escapes

## Roadmap status

**Phase 1 — Trust & E-E-A-T track** is now substantially closed:

- ✅ Source attribution on every data point
- ✅ Editorial policy + named analysts
- ✅ Trustpilot widget on home (v119.13)
- ✅ Performance audit page for Trading Signals (v119.11)
- ✅ "As featured in" strip (v119.13)
- ✅ Compliance disclaimer on every analysis page (v119.13)
- 📋 ⚪ FINRA/SEC review of US-targeted content (icebox — out of scope for engineering)

That's 6 of 7 items done; the remaining item is a legal/compliance review,
not engineering work.

## Next-up unblocked items

🟠 **Mobile UX audit** — Phase 1 polish, closes more of Phase 1
🟠 **Custom dashboard layouts** — Phase 2 personalization, drag-and-drop on watchlist/alerts/portfolio
🟡 **Browser push notifications** — Phase 2, completes the alerts triangle (email ✅, push remaining); requires VAPID + Web Push crypto, scope carefully
🟡 **Rich snippets audit** — verify all the schema markup from v119.10–v119.13 actually wins rich-result eligibility in Google Search Console
