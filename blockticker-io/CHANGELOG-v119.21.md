# BlockTicker v119.21.0 — User Account Wiring + Email Customization

**Date:** 2026-04-25
**Type:** Hotfix · 4 user-reported issues
**Risk:** Low — additive changes; pre-existing data still works

---

## What ships

Four issues from the latest support pass, each traced to a specific root cause and fixed:

### 1. Alert email link opening `/tools/` instead of `/alerts/`

**Symptom:** "Manage your alerts: https://blockticker.io/tools/?bt_alert_token=…" — wrong destination page.

**Root cause:** `class-portfolio.php` had two URL constructions hard-coded to `/tools/`. News alerts in `class-alerts.php` had always correctly pointed to `/alerts/` (the dedicated hub), but price alerts were inconsistent — copy-paste from before the dedicated alerts page existed.

**Fix:** Both URLs (alert-set confirmation email + alert-fired notification email) now point to `/alerts/`. Two-line change.

### 2. Alerts not saved/displayed in user's account

**Symptom:** Logged-in users couldn't see their own alerts in their account — could only manage them by digging the token URL out of email.

**Root cause:** The alert array stored at `bt_price_alerts` had no `user_id` field. Alerts were keyed only by `email` and an opaque `token`. The `[bt_alert_manager]` shortcode filtered solely by `?bt_alert_token=…`, so without that token in the URL, even the alert's owner saw nothing.

**Fix:**
- `ajax_save_alert()` now captures `get_current_user_id()` (or 0 for anonymous) on creation
- For logged-in users, the email field defaults to the account address (saves a step in the flow)
- The 5-alert rate-limit is now scoped by `user_id` when logged-in instead of by `email` (prevents the cap-bypass via email change). Anonymous submissions still cap by email
- `[bt_alert_manager]` rewritten to render owner-list automatically when logged-in (no token URL needed). The token URL still works for email recipients. Both paths union when a logged-in user clicks a token link
- Authorisation for delete requires either token-match or owner-match — no privilege escalation, both old and new flows still secured by the existing nonce

**Pre-v119.21 alerts** have no `user_id` field. They remain fully manageable via the email link as before — just don't auto-appear in the user's account view. The token link still gets them.

### 3. Screeners not saved (and same for any personalized page)

**Symptom:** User reported saved screeners disappeared on navigate-away. Server logs show the saves were happening — the data was actually being persisted.

**Diagnosis:** The server-side flow is correct end-to-end (`ajax_save` → `save_user_screener` → `update_user_meta`). The actual bug was at a layer above the plugin: WordPress full-page caches (LiteSpeed Cache, WP Rocket, W3 Total Cache, hosting-provider edge caches) were serving anonymous-user HTML to logged-in users on `/screeners/`, `/dashboard/`, `/following/`, `/watchlist/`, `/portfolio/`, `/alerts/`. The user saves a screener; the server stores it correctly; the next page load returns a cached HTML version showing the empty-state.

**Fix — new `BT_PersonalizedPages` class:**
- Hooks `template_redirect` at priority 1 (before any plugin templates load)
- Detects when the queried page slug matches one of the protected slugs (default: dashboard, screeners, following, watchlist, portfolio, alerts)
- Sends three header changes:
  - `Cache-Control: private, no-cache, no-store, max-age=0, must-revalidate` (overrides WordPress's softer default and explicitly forbids any intermediary cache from storing the response)
  - `Vary: Cookie` (well-behaved CDNs will key cache entries by auth cookie — different cached versions for logged-in vs anonymous)
  - `X-Robots-Tag: noindex, nofollow` (defence-in-depth: personalized URLs shouldn't be in SERPs even if a robots.txt slip exposes them)
- Skips admin, AJAX, REST, cron, feed contexts
- Filterable: `bt_personalized_page_slugs` (extend the protected list), `bt_personalized_pages_enabled` (global kill-switch)

This fixes not just screeners but every personalized-page symptom of the same family. Single class because all six pages have the same problem and same fix.

### 4. Customise email sender from "WordPress" to e.g. "info@blockticker.io"

**Request:** Let admin override the default WordPress sender.

**Fix:**
- New `BT_Portfolio::filter_mail_from()` and `filter_mail_from_name()` registered on `wp_mail_from` and `wp_mail_from_name` filters in `BT_Portfolio::init()`
- Reads from two new options: `bt_mail_from_email` and `bt_mail_from_name`
- Validation:
  - Empty / unset → fall through to WordPress's default (preserves existing behaviour for sites that haven't configured)
  - Invalid email format → fall through (don't produce a broken envelope)
  - Display name falls back to `bt_site_name`, then to WP default
- Two new admin fields in **Credentials → Email Sender** group with examples and explanatory help text
- Per-field placeholder support added to the Credentials renderer (so `info@blockticker.io` shows as a hint)
- Bad-email submissions show a warning notice and **keep the prior value** rather than wiping it

Affects every outgoing email: alert confirmations, alert-fired notifications, news digests, password resets, OAuth notifications, anything that goes through `wp_mail()`.

---

## Files changed

| File | Change |
|------|--------|
| `includes/class-personalized-pages.php` | NEW — cache-control class (~115 lines, 5 KB) |
| `includes/class-portfolio.php` | `ajax_save_alert()` captures user_id + scoped rate-limit; `sc_alert_manager()` rewritten with owner-list path; `wp_mail_from`/`_name` filters; URL fix `/tools/` → `/alerts/` (×2 sites) |
| `includes/class-admin.php` | Added Email Sender group with 2 fields; per-field `placeholder` support; email validation in save handler |
| `fx-live-markets.php` | `require_once` BT_PersonalizedPages; version → 119.21.0 |
| `docs/ROADMAP.md` | Version bump; 3 UX done-list entries; 6 decision-log entries |
| `CHANGELOG-v119.21.md` | NEW (this file) |

No DB schema changes. No new CSS. One new PHP class, surgical edits to three existing.

---

## Validation

- All 4 modified PHP files parse clean via phply
- Brace/paren deltas match exactly: portfolio +11/+11, admin +4/+4, bootstrap 0/0
- New class brace-balanced 5/5, paren-balanced 54/54
- v119.20's portfolio parse limitation (PHP 7.4 arrow-fn) is now resolved as a side effect — replaced the affected `array_filter()` arrow-fn calls with full-form closures during the user_id scope rewrite

---

## Setup actions after deploy

**Email sender (highly recommended):**
1. BlockTicker → Credentials → Email Sender
2. From Address: `info@blockticker.io` (or your preferred sender on your own domain — using an off-domain address risks spam-flagging)
3. From Display Name: `BlockTicker` (defaults to your Site Name if blank)
4. Save Credentials

**Verify cache fix:**
1. Sign in
2. Open `/screeners/` — save a new screener
3. Navigate away (e.g. to `/`)
4. Click back to `/screeners/` — your saved screener should appear
5. If it still doesn't appear, check your hosting provider's full-page cache settings (the `Cache-Control: private` header should force a bypass on most modern caches; older Varnish setups may need an explicit rule excluding URLs with auth cookies)

**Verify alert account wiring:**
1. Sign in, set a new price alert from any asset detail page — leave the email field as your account email
2. Open `/alerts/` directly (no token in URL) — your new alert should appear
3. Click the email management link — same alert should appear (legacy token path still works)
4. Pre-existing alerts created before v119.21 won't auto-appear in the account view; they remain manageable via the email token link

---

## Backwards compatibility

- Pre-v119.21 alerts (no `user_id` field) keep working via token URL exactly as before
- Sites that haven't set `bt_mail_from_email` see no change in sender behaviour — WP defaults still apply
- Sites without page caching see no functional change from the cache headers (just slightly stricter `Cache-Control`)
- Admin Credentials form: existing fields behave identically; the new Email Sender group is appended without affecting the others
