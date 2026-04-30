# BlockTicker v108.0.0 — Weekly AI Newsletter Digest

**Release date:** April 2026
**Type:** Feature release (additive, no breaking changes)
**Upgrade path:** Drop-in over v107.0.0

---

## Overview

A weekly HTML email digest is now sent automatically every Sunday at 8 AM
(site timezone) to all subscribers in `bt_subscribers`. The email combines
live market data with an AI-generated 3-paragraph editorial narrative — all
rendered in a premium dark-background email template that renders correctly in
Gmail, Outlook, and Apple Mail.

---

## New: `includes/class-newsletter.php`

One new class `BT_Newsletter` (~420 lines).

### Email template design

Dark-background, table-based layout (Outlook-safe). Fully mobile-responsive via
`@media` query targeting 600 px. Colour palette:

| Element | Colour |
|---------|--------|
| Background | `#0b0f1a` (near-black) |
| Card surfaces | `#111827` / `#1e2535` |
| Accent / brand | `#00d4aa` (BlockTicker teal) |
| Body text | `#f1f5f9` |
| Muted text | `#94a3b8` |
| Positive | `#22c55e` (green) |
| Negative | `#ef4444` (red) |
| Warning | `#f59e0b` (amber) |

### Email sections (top → bottom)

1. **Header** — site logo (or text wordmark) + edition date
2. **Hero banner** — gradient strip: "Your Weekly Market Pulse"
3. **BTC / ETH price cards** — large price, 24h change with directional arrow
4. **Market Pulse row** — Fear & Greed Index (colour-coded) + News Sentiment 7d score
5. **Editor's Weekly Take** — 3-paragraph AI narrative (generated fresh each send)
6. **Top Gainers / Top Losers** — two-column grid, 5 assets each with % change
7. **Top Headlines This Week** — up to 6 articles from `wp_bt_news_items` with sentiment dot indicator
8. **Latest Trading Signals** — up to 5 signals from `bt_signal_items` with bull/bear badge + CTA button
9. **Forex Snapshot** — EUR/USD, GBP/USD, USD/JPY, USD/CHF, AUD/USD, XAU/USD
10. **CTA** — gradient strip with "Open BlockTicker Live Dashboard →" button
11. **Footer** — copyright, site URL, unsubscribe link

### AI narrative

Calls the configured provider (Claude Haiku or GPT-4o-mini) with a structured
prompt containing: BTC/ETH prices, Fear & Greed, sentiment score, top gainers/losers, and
4 recent headlines. Returns 3 plain-text paragraphs covering:

- Overall market tone and what drove it this week
- Standout movers (gainers and losers) and likely causes
- Forward-looking: key levels, upcoming events, catalysts to watch

The narrative is generated once per send and cached in the email HTML. If the
AI call fails (no key, timeout), the email still sends without the narrative
section — data sections are always present.

**Model choice:** Claude Haiku / GPT-4o-mini — fast and cheap for a ~500-token
digest; one API call per weekly send.

### Cron

`bt_send_weekly_digest` — fires every Sunday at 8 AM site time.
Scheduled at activation to the next upcoming Sunday. Uses WordPress core
`weekly` cron interval (7 days).

A 6-day double-send guard (`bt_newsletter_last_sent` option) prevents
duplicate sends if the cron is accidentally triggered twice.

### Subject line

Dynamic subject includes BTC 24h change and mood label when available:

```
[BlockTicker] Weekly Digest Apr 21 · BTC +4.2% · Mood: Greed
```

---

## `includes/class-db-admin.php`

**📨 Weekly Newsletter Digest** panel injected at the top of the right column
(above the Sentiment panel). Contains:

- Subscriber count
- Last sent timestamp
- Next scheduled send time
- AI provider label
- **✉️ Send Test** — sends to a single email address immediately (bypass double-send guard)
- **📨 Send to All** — sends to all subscribers now (with confirmation dialog)
- **👁 Preview HTML** — renders the email in an inline `<iframe>` inside the admin panel

---

## `fx-live-markets.php`

- Version → 108.0.0
- `require_once BT_DIR . 'includes/class-newsletter.php'`
- `add_action( 'plugins_loaded', ['BT_Newsletter', 'init'] )`
- `register_deactivation_hook( …, ['BT_Newsletter', 'deactivate'] )`

---

## PHP lint

All 3 touched files clean:
- `includes/class-newsletter.php` ✅ (new, ~420 lines)
- `includes/class-db-admin.php` ✅
- `fx-live-markets.php` ✅

---

## Usage

1. Ensure subscribers exist (`bt_subscribers` option, populated via `[fxlm_newsletter]`
   or `[bt_newsletter]` shortcodes).
2. Ensure an AI API key is configured in **BlockTicker → Settings**.
3. The digest sends automatically every Sunday at 8 AM.
4. To test: go to **BlockTicker → 🗄 Database**, find the **📨 Weekly Newsletter Digest**
   panel, enter your email, and click **✉️ Send Test**.
5. To preview the rendered template before sending, click **👁 Preview HTML**.

---

## What's next

- **v109.0** — Webhook event delivery: mark `wp_bt_events` rows as `delivered = 1`
  after successful POST, retry failed deliveries, expose delivery status in the
  DB admin screen.
