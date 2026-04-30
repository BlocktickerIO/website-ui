# BlockTicker v109.0.0 — Webhook Event Delivery

**Release date:** April 2026
**Type:** Feature release + schema migration (additive, no breaking changes)
**Upgrade path:** Drop-in over v108.0.0 — schema migration runs automatically on first admin load

---

## Overview

`wp_bt_events` has stored every triggered price alert and other platform events
since v96.2, with a `delivered` column that was always written as `0` and never
changed. v109.0 completes the picture: a delivery engine runs every 15 minutes,
POSTs undelivered events to configured HTTPS endpoints, retries failures with
exponential back-off, and dead-letters exhausted events.

A full delivery dashboard in **BlockTicker → 🗄 Database** shows live metrics,
per-event status, retry buttons, and endpoint connectivity tests.

---

## Schema migration: `wp_bt_events` — two new columns

`DB_VERSION` bumped from `1.0.0` → `1.1.0`. dbDelta adds the columns
automatically on the first admin load after upgrade (idempotent — safe to
re-run).

| Column | Type | Default | Purpose |
|--------|------|---------|---------|
| `attempts` | `tinyint(3) unsigned` | `0` | How many delivery attempts have been made |
| `last_attempt_at` | `datetime` | `NULL` | Timestamp of last attempt (drives back-off) |

The existing `delivered` column gains a third semantic value:

| Value | Meaning |
|-------|---------|
| `0` | Pending — not yet delivered |
| `1` | Delivered successfully |
| `-1` | Dead-lettered — max retries exhausted |

---

## New: `includes/class-webhook.php`

One new class `BT_Webhook` (~360 lines).

### Configuration (`bt_webhook_config` option)

```json
{
  "endpoints": [
    {
      "id":          "abc123",
      "label":       "My Zapier Zap",
      "url":         "https://hooks.zapier.com/...",
      "secret":      "my-hmac-secret",
      "event_types": ["PRICE_ALERT", "*"],
      "enabled":     true
    }
  ],
  "max_retries": 3,
  "timeout":     10
}
```

Each endpoint can subscribe to specific event types or `*` for all events.
Multiple endpoints are supported — all matching endpoints receive each event.

### Cron: `bt_deliver_webhooks` (every 15 minutes)

`deliver_pending_batch()` fetches up to 50 undelivered events per run,
respecting the back-off schedule:

| Attempt | Wait before retry |
|---------|------------------|
| 1st failure | 15 minutes |
| 2nd failure | 30 minutes |
| 3rd failure | 60 minutes |
| 4th+ failure | Dead-lettered (`delivered = -1`) |

The query filters by `last_attempt_at <= now - backoff` so only events
that have waited long enough are retried on each cycle.

### Webhook payload

Every delivery POSTs a JSON envelope:

```json
{
  "id":         42,
  "event_type": "PRICE_ALERT",
  "symbol":     "BTC",
  "payload": {
    "email":     "user@example.com",
    "direction": "above",
    "target":    70000,
    "current":   70234.5
  },
  "attempt":  1,
  "sent_at":  "2026-04-21T14:00:00+00:00",
  "source":   "BlockTicker"
}
```

### HMAC signature

When a secret is configured, the request includes:

```
X-BT-Signature: sha256=<HMAC-SHA256(secret, raw_body)>
```

This matches the GitHub/Stripe webhook signature pattern, so standard
verification middleware works out of the box.

### Dead-letter behaviour

Events dead-lettered at `delivered = -1` are excluded from the retry loop
but are shown in the admin dashboard with a 💀 status. They can be manually
reset to `delivered = 0` via the **Retry** button, which resets `attempts`
to 0 so the full retry cycle begins again.

Dead-lettered events are **not** purged by the daily retention job — only
`delivered = 1` rows are cleaned up after the retention window.

---

## `includes/class-db-admin.php`

**🔗 Webhook Event Delivery** panel injected at the top of the right column.
Contains:

**Metrics row:** Pending / Delivered / Dead-lettered counts + endpoint count,
max retries, timeout.

**⚙️ Configure Endpoints** (collapsible `<details>`):
- Lists all configured endpoints with URL, event types, secret indicator
- **Test** button per endpoint — fires `BT_WEBHOOK_TEST` event and delivers
  immediately; shows ✓ OK or ✗ Failed
- **Add Endpoint** form: label, URL, HMAC secret, event type filter
- Changes saved via `bt_webhook_save_config` AJAX

**▶ Deliver Pending Now** — triggers `deliver_pending_batch()` immediately;
shows delivered / failed / dead-lettered counts.

**Recent Events log (last 20):**
- Event ID, type, symbol, status (⏳/✅/💀), attempt count, age
- **Retry** button — resets `delivered = 0, attempts = 0` for re-delivery
- **✕** button — deletes the event row immediately

---

## `includes/class-database.php`

- `DB_VERSION` → `'1.1.0'`
- `wp_bt_events` schema: `attempts tinyint(3) unsigned NOT NULL DEFAULT 0` and
  `last_attempt_at datetime DEFAULT NULL` added

---

## `fx-live-markets.php`

- Version → 109.0.0
- `require_once BT_DIR . 'includes/class-webhook.php'`
- `add_action( 'plugins_loaded', ['BT_Webhook', 'init'] )`
- `register_deactivation_hook( …, ['BT_Webhook', 'deactivate'] )`

---

## PHP lint

All 4 touched files clean:
- `includes/class-webhook.php` ✅ (new, ~360 lines)
- `includes/class-database.php` ✅
- `includes/class-db-admin.php` ✅
- `fx-live-markets.php` ✅

---

## What's next

- **v110.0** — User Watchlists 2.0: persistent server-side watchlists stored in
  `wp_usermeta`, shareable via URL token, with price change annotations.
