# BlockTicker — v119.9.0

**Released:** 2026-04-25
**Theme:** Alerts system completion (🔴 P0 from Phase 2 of roadmap)

## Summary

Closes the two 🔴 P0 alerts items in the roadmap that the v119.8 deep-research audit
flagged as the single biggest engagement gap. Price-alert plumbing already existed in
`class-portfolio.php` but was buried; news alerts didn't exist at all.

This release adds the missing news-alerts feature, surfaces price alerts contextually
on every asset page via a one-click button, and gives operators a clean admin overview.

## What's new

### News alerts (NEW)
- `[bt_news_alerts]` shortcode — keyword-based subscription form
- Three frequencies: **instant** (max once / 30 min), **hourly digest**, **daily digest**
- Two match modes: **ANY** keyword or **ALL** keywords must hit
- Optional source restriction (checkboxes auto-populated from configured RSS feeds)
- Rate limit: 5 active alerts per email; min 3 chars per keyword; max 5 keywords per alert
- Hourly cron (`bt_check_news_alerts`) finds matches since each subscriber's `last_seen`,
  builds a per-recipient digest, and sends an HTML+text email
- HTML email template uses the site's terminal palette (Chivo headings, mono timestamps,
  neon-green source badges) so digests look like the site rather than a generic mailer
- Every send logged to `wp_bt_events` with type `NEWS_ALERT` (keywords, recipient,
  match count, frequency)
- Confirmation email on signup with a token-based management link

### Set Alert button on asset pages
- New `[bt_set_alert_btn coin_id="..." symbol="..." price="..."]` shortcode
- Dropped onto every `/crypto/{slug}/` page (next to the rank pill) and every
  `/forex/{slug}/` page (next to the nickname)
- Click opens a modal pre-filled with the asset and current price, with the same
  direction / target / repeat / email fields as `[bt_price_alerts]`
- Modal: ESC and backdrop close, focus management, success auto-dismiss after 2.5s,
  enters/exits with a subtle scale animation
- Reuses the existing `bt_save_alert` AJAX endpoint and `bt_check_price_alerts` cron —
  no duplicate plumbing

### Unified alerts hub at `/alerts/`
- New `[bt_alert_hub]` shortcode renders three tabs:
  - **Price Alerts** — embeds `[bt_price_alerts]`
  - **News Alerts** — embeds `[bt_news_alerts]`
  - **Manage** — embeds `[bt_alert_manager]` (works with the token URL `?bt_alert_token=...`)
- New page registered at `/alerts/` automatically on plugin activation
- Confirmation emails for both alert types now point to `/alerts/?bt_alert_token=...`

### Admin overview (BlockTicker → Alerts)
- Two stat cards: active price alerts (with fired count) and active news alerts
- Shows when each cron will next fire (`human_time_diff` from now)
- Two tables listing every active alert with delete actions
- Useful for ops triage — e.g. "I have a runaway sender" or "is the cron running"

## Files changed

| File | Change |
|---|---|
| `includes/class-alerts.php` | **NEW** — full alerts subsystem (~600 lines) |
| `fx-live-markets.php` | `require_once` new class; version → 119.9.0 |
| `includes/class-pages.php` | Register `/alerts/` page with `[bt_alert_hub]` |
| `includes/class-asset-pages.php` | Inject `[bt_set_alert_btn]` on crypto + forex pages |
| `assets/css/revamp-v44.css` | Append alerts module (~230 lines) — modal, tabs, button, source picker |
| `docs/ROADMAP.md` | Mark price + news alerts ✅ done |
| `CHANGELOG-v119.9.md` | This file |

## Validation

- All 54 PHP files: `php -l` clean
- CSS braces balanced: 2421 / 2421
- Single `blockticker-io/` root folder in zip — no nested wrapper

## Cron schedule

| Hook | Frequency | Handler |
|---|---|---|
| `bt_check_price_alerts` | every 15 min | `BT_Portfolio::check_and_send_alerts` *(unchanged)* |
| `bt_check_news_alerts`  | hourly       | `BT_Alerts::check_and_send_news_digests` *(new)* |

The news-alerts cron is auto-scheduled by `BT_Alerts::maybe_schedule_cron` on `init`.
No manual setup required.

## Storage

Two WordPress options drive the system. Both are arrays of alert objects.

- `bt_price_alerts` — managed by `BT_Portfolio` *(unchanged shape)*
- `bt_news_alerts` — managed by `BT_Alerts` *(new)*. Object shape:
  ```
  {
    id, keywords[], match_mode, sources[], email, frequency,
    token, created, last_sent, last_seen, match_count
  }
  ```

For now this stays in `wp_options` (consistent with the existing price alerts). When
either array exceeds ~500 entries we should migrate to a custom DB table — see the
roadmap's "Plugin folder structure cleanup" P0 item.

## Roadmap impact

Phase 2 — Alerts & Notifications
- ✅ Price alerts — surfaced contextually + hub
- ✅ News alerts — implemented from scratch
- ✅ Email delivery via existing wp_mail / newsletter infra
- 📋 🟡 Browser push notifications (PWA) — still todo, deferred

## Known follow-ups (not in this release)

- Logged-in users still don't get alerts auto-bound to their account email — alerts
  remain anonymous (email-keyed). Cloud-sync to `BT_UserAuth` is a separate piece of
  work and lives in the roadmap as part of Personalization.
- News digest currently uses plain CSS-in-string in the email template; a future
  refactor should move it to `templates/email/news-digest.php` as part of the
  folder cleanup P0.
- Search-criteria input is comma-separated rather than chip-input UI. Acceptable for
  v1; chip UI is a P2 polish item.
