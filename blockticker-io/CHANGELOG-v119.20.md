# BlockTicker v119.20.0 — Multi-Bug Hotfix

**Date:** 2026-04-25
**Type:** Hotfix · 4 user-reported bugs
**Risk:** Low — surgical edits, no schema changes, no new files

---

## What ships

Four issues raised in the last support pass, each tracked to root cause and fixed:

### 1. User menu dropdown overflowing right edge of viewport

**Symptom:** The avatar/username dropdown (Watchlist, My Screeners, Following, Alerts, Log Out) extended past the right edge of the screen, cutting off content.

**Root cause:** `.bt-nav-avatar-wrap` had no `position:relative`, so its absolute-positioned `#bt-nav-usermenu` child anchored to the nearest positioned ancestor (the navbar itself) instead of the avatar wrap. With `right:0` that meant "right edge of navbar," which on smaller viewports pushes past the screen.

**Fix:**
- Added `position:relative` + `display:inline-flex` + `flex-shrink:0` to the avatar wrap inline style
- Added `max-width:120px` + ellipsis on the username span (long display names no longer push the avatar off-screen)
- Dropdown gets `max-width:calc(100vw - 24px)` so it can never overflow regardless of screen size
- Bumped border-radius to 6px and added `overflow:hidden` for cleaner edges
- Same fix applied in `class-userauth.php` (the JS-injected version that fires after OAuth login without page reload)

### 2. Plain-text alert emails — needed HTML rich style

**Symptom:** "[BlockTicker] Alert set: BITCOIN rises above 78,000.00" emails arrived as plain text. Looked spammy and out-of-brand.

**Fix — built one reusable email template:**
- New `BT_Portfolio::build_alert_html( $args )` helper takes structured input (kicker, headline, sub-line, key/value rows, CTA URL+label, optional `triggered` flag for fired alerts) and produces a dark-themed HTML email matching the visual language of the existing `BT_Alerts::build_digest_html()`
- Inline styles only — Gmail/Outlook strip `<style>` blocks, so every property has to be on the element
- Three existing plain-text email flows now use it:
  - Price alert **set** — confirmation when the user creates an alert (portfolio class)
  - Price alert **fired** — notification when threshold crosses (portfolio class) — uses `triggered:true` flag for warm-orange headline accent
  - News alert **set** — confirmation when the user creates a keyword alert (alerts class) — composes `BT_Portfolio::build_alert_html()` directly with a graceful plain-text fallback if the portfolio class isn't loaded
- All three now sent with `Content-Type: text/html; charset=UTF-8` headers
- Existing news-digest HTML flow (`BT_Alerts::build_digest_html`) unchanged — it already had a working pattern; the new helper is consistent with it visually

### 3. My Dashboard / My Screeners / Following appearing under Tools

**Symptom:** User questioned why personalized pages were under the Tools dropdown alongside utilities like Crypto Converter and Economic Calendar.

**Diagnosis:** The user's intuition was right. Tools is a dropdown of general-purpose utilities — these three are personalized user features. They were already in the user dropdown for logged-in users (the correct location), so Tools entries were duplicates. For anonymous users, the entries were also misleading: clicking "My Screeners" without an account just shows a sign-in upsell.

**Fix:**
- Removed My Dashboard / Screeners / Following from the Tools dropdown
- Tools dropdown now contains: Crypto Converter · Economic Calendar · Watchlist · Best Brokers (the actual general utilities)
- For **anonymous users only**, Tools now shows a single "🏠 Personalized Dashboard — sign in" entry as a sign-in conversion CTA — preserves discoverability without misleading utility framing
- Logged-in users get the personalized 3 + Alerts in their avatar dropdown as before

Also unified inline styling on all 6 user-menu entries (some had the styling, some didn't — visible inconsistency in the dropdown).

### 4. Duplicate credentials in admin — silently broken

**Symptom:** User reported "duplicate credentials in admin dashboard in 2 places."

**Diagnosis (worse than it looked):** There were two admin forms editing API credentials:
- **Setup Wizard** (`?page=fxlm-wizard`) writes to canonical `bt_*` option keys (`bt_cmc_api_key`, `bt_claude_key`, etc.) — what the rest of the codebase reads
- **Credentials submenu** (`?page=bt-credentials`) writes to legacy `fxlm_*` option keys (`fxlm_cmc_api_key`, `fxlm_claude_api_key`, etc.) — a dead namespace that hasn't been read since v98 migration

The Credentials submenu was **silently broken**. Saves went to dead options nothing read. Each form looked "saved" individually because each rendered values from its own namespace, but the two forms were storing data in two different places. The v119.18 priority fix and v119.19 "leave blank to keep" fix both patched symptoms inside the broken namespace; the underlying mismatch went undiagnosed.

**Fix — single source of truth:**
- Rewrote `render_credentials()` to use canonical `bt_*` option keys throughout (matching wizard, matching the rest of the codebase)
- Added two missing fields that lived only in the wizard: AdSense Ad Slot ID, AI Post Review Mode (with select dropdown rendering support added to the field renderer)
- Replaced wizard's massive 70-line API Keys form with a 30-line **status-summary card**: shows per-credential SET (green dot) / not-set (grey dot) status with a primary CTA "⚙ Manage Credentials →" linking to the Credentials submenu
- Wizard now reads `bt_*` keys to compute status indicators — same source as Credentials form
- Pre-existing data saved via the wizard is now visible in Credentials (was previously stored in `bt_*`, but Credentials only read `fxlm_*` and rendered empty)
- Wizard's `ajax_run_step` save handler is left intact (run-step buttons may still pass keys); harmless but no longer reachable from any UI form

After this fix, both admin URLs (wizard and Credentials submenu) read and write the same data. The duplication is resolved structurally, not just visually.

---

## Files changed

| File | Change |
|------|--------|
| `includes/class-navbar.php` | Avatar wrap gets `position:relative`; dropdown `max-width:calc(100vw - 24px)`; Tools dropdown cleanup; user menu styling unified |
| `includes/class-userauth.php` | JS-injected dropdown style matched to PHP version (post-OAuth parity) |
| `includes/class-portfolio.php` | New `build_alert_html()` helper (~80 lines); alert-set + alert-fired emails converted to HTML |
| `includes/class-alerts.php` | News-alert-set email composes `BT_Portfolio::build_alert_html()` with plain-text fallback |
| `includes/class-admin.php` | `render_credentials()` rewritten with canonical `bt_*` keys + 2 added fields + select-type support; wizard's API Keys form replaced with status-summary card |
| `fx-live-markets.php` | Header version → 119.20.0; `BT_VERSION` → `119.20.0` |
| `docs/ROADMAP.md` | Version bump; v119.20 added to UX done list (3 entries); 5 decision-log entries |
| `CHANGELOG-v119.20.md` | NEW (this file) |

No new PHP files. No DB schema changes. No CSS module added (existing dark-email styles were already inline in PHP).

---

## Validation

- `class-navbar.php`, `class-userauth.php`, `class-alerts.php`, `class-admin.php`: phply parse OK (with PHP 7.4+ `??`/`<=>`/arrow-fn workarounds)
- `class-portfolio.php`: parses OK; arrow-fn `fn()` syntax is a phply tooling limitation, not a runtime error
- Brace/paren balance unchanged from v119.19 baseline (preexisting JS-template-literal mismatches in PHP strings, not code-level)
- All four bugs traced to specific root causes, not symptom patches

---

## What you'll see after deploy

1. **User menu** stays inside the viewport even on narrow screens; long display names truncate with ellipsis instead of pushing the avatar off-screen
2. **Alert emails** arrive with a dark-themed HTML body matching the BlockTicker brand — green accent, monospace kicker, key/value table, prominent CTA button. Same look as the existing news digest
3. **Tools dropdown** has 4 entries (Converter · Calendar · Watchlist · Best Brokers) for logged-in users; anonymous users get a 5th "Personalized Dashboard — sign in" CTA
4. **API credentials** appear consistently in both the wizard (status summary) and the Credentials submenu (full form). What you save in one place shows up in the other. The wizard's "Manage Credentials" button is the primary action

---

## Migration note for any operator who saved keys via Credentials between v119.18 and v119.19

If you entered API keys into the **Credentials** submenu during v119.18 or v119.19 (after the priority-fix shipped but before this consolidation), those keys went to `fxlm_*` options that nothing read. They're still in the database but inert.

After upgrading to v119.20:
- Open BlockTicker → Credentials
- Re-paste the keys you entered (or check the wizard's status summary first — if you also entered them via the wizard, the canonical `bt_*` versions are already saved and the Credentials form will show masked placeholders)
- Hit Save Credentials

Operators who only used the wizard's API Keys form (the more visible entry point) are unaffected — those saves always went to the canonical namespace.
