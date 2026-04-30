# BlockTicker v119.22.0 — Changelog

**Released:** 2026-04-25
**Previous:** v119.21.0

---

## Bug fixes

### 1. Admin wizard stuck on "Running…" (Configure WordPress settings)

**Root cause (compound):**
- `BT_Settings::run()` called `flush_rewrite_rules()` (hard flush), which rewrites `.htaccess`. On hosts using NFS mounts, LiteSpeed, or WPMU with shared filesystem, this call can block for 30–120 s or hang indefinitely.
- Any PHP notice or warning emitted during the AJAX handler printed text before `wp_send_json_success()`. jQuery's `.ajax()` tries to JSON-parse the response; the contaminated string fails to parse, silently fires the `error` callback — but because the error text was generic ("parsererror"), it was easy to miss. The step row stayed at "Running…" with no visible feedback.

**Three-part fix:**
- `ob_start()` added at the top of `ajax_run_step()` + `ob_end_clean()` immediately before every `wp_send_json_*` call. Stray output is captured and discarded — the JSON response is always clean.
- `flush_rewrite_rules()` → `flush_rewrite_rules(false)` (soft flush). The soft flush updates WP's internal rewrite cache without touching `.htaccess`. WordPress core automatically queues the hard flush on the next page load when `permalink_structure` changes, so nothing is lost.
- JS timeout warning: after 30 seconds on a single step, the status cell shows *"Still running… slow on first run. Wait or retry."* — distinguishes a legitimately slow step (plugin install) from an actual hang.
- New JS error message for non-JSON replies: *"Server returned unexpected output (PHP notice or warning). Check WP_DEBUG log and retry."* — replaces the previous generic HTTP error message.

**Files:** `includes/class-admin.php`, `includes/class-settings.php`, `assets/js/admin.js`

---

### 2. Credentials not saved after plugin reinstall

**Root cause:**
WordPress `wp_options` table rows persist across plugin deactivation/reactivation but are wiped when the plugin is **deleted** (or when the host restores a DB snapshot). After a delete+reinstall cycle, all `bt_*` option rows are gone. Additionally, credentials entered in installs older than v119.20 were stored under `fxlm_*` keys (the legacy namespace); those options survive but are never read by the current codebase.

**Three-part fix:**

1. **Activation migration** — `BT_Admin::on_activate()` now calls the new `migrate_legacy_credentials()` method. It copies any surviving `fxlm_*` credential values to their canonical `bt_*` equivalents on every plugin activation. The copy is one-way and non-destructive: it only writes to `bt_*` if the target is currently empty, so it never overwrites a value the operator already entered.

2. **Export Credentials** — new button on the Credentials admin page downloads a dated JSON file (`blockticker-credentials-YYYY-MM-DD.json`) containing all currently-set `bt_*` credential options. Salah should download this before every reinstall.

3. **Import Credentials** — new textarea + Import button on the same page. Paste the exported JSON; the handler validates it against a strict whitelist (only known `bt_*` keys are written, nothing else), applies field-level sanitisation (URL fields → `esc_url_raw`, email → `is_email` + `sanitize_email`, everything else → `sanitize_text_field`), and reports how many credentials were imported.

4. **Migrate Legacy Keys button** — one-click `fxlm_*` → `bt_*` migration for manual recovery without needing to re-paste values.

**Files:** `includes/class-admin.php`

---

## New feature — Personalized AI Brief (Phase 2 flagship)

**Shortcodes:** `[bt_personalized_brief]` · `[bt_personalized_brief_compact]`

The Phase 2 flagship that turns the three personalization subsystems shipped in v119.15–v119.17 into a daily AI-generated market narrative tailored to each logged-in user.

### Architecture

**1. User context (`gather_user_context`):**
- Watchlist — reads `bt_watchlist` user meta (set by the Watchlist subsystem)
- Screeners — calls `BT_Screeners::get_user_screeners($user_id)`; extracts asset symbols from saved screener results
- Following — calls `BT_Following::get_following($user_id)`; uses the `assets` sub-array
- Produces a de-duplicated `combined` symbol list (uppercase), capped at 12 to keep Claude prompt tokens sane

**2. Market snapshot (`build_market_snapshot`):**
- Filters `fxlm_crypto_data` to rows matching the user's symbol set
- Returns: symbol, name, price_usd, change_24h, change_7d, market_cap, volume_24h

**3. News snapshot (`build_news_snapshot`):**
- Regex-filters `fxlm_news_items` for headlines mentioning any tracked symbol
- Returns up to 8 matching headlines

**4. Claude call:**
- Model: `claude-sonnet-4-20250514` · max_tokens: 400
- Prompt: market table + news + user's screener names → 3 short paragraphs: (a) holdings overview, (b) actionable observation, (c) macro context
- Falls back gracefully: no-key state shows admin Credentials link; empty-asset state prompts to add to Watchlist/Screeners/Following

**5. Cache:**
- Per-user transient keyed `bt_pb_{user_id}_{Ymd}` → one fresh brief per day per user
- ↻ Refresh button bypasses the cache on demand (calls `bt_refresh_personalized_brief` AJAX)

### Shortcode variants

| Shortcode | Use case |
|---|---|
| `[bt_personalized_brief]` | Full dark card — hero placement on /dashboard/ or /brief/ |
| `[bt_personalized_brief_compact]` | 2-line teaser — sidebar, dashboard widget column |

Both show a login gate (with wp_login_url redirect) for unauthenticated visitors.

### Cache-control
`/brief/` added to `BT_PersonalizedPages::DEFAULT_SLUGS` so the brief page gets `Cache-Control: private, no-cache` headers — prevents full-page caches from serving one user's brief to another.

**Files:** `includes/class-personalized-brief.php` (NEW), `includes/class-personalized-pages.php`, `fx-live-markets.php`

---

## Setup actions after deploy

### If wizard was stuck before this update
Re-run "Configure WordPress settings" individually — it will complete in 1–2 s now. No other steps need rerunning unless they previously showed error state.

### Credentials backup workflow (recommended permanently)
1. BlockTicker → Credentials → **⬇ Export Credentials (JSON)** → save file
2. Reinstall plugin
3. BlockTicker → Credentials → paste JSON into Import textarea → **Import**

### Personalized AI Brief
1. Ensure `bt_claude_key` is set in Credentials → AI & Content
2. Add `[bt_personalized_brief]` to the /dashboard/ page (or create a /brief/ page)
3. Optionally add `[bt_personalized_brief_compact]` to a sidebar widget area
4. Users who have items in their Watchlist, Screeners, or Following list will see a personalized brief on first visit; guests see a login gate

---

## Files changed

| File | Change |
|---|---|
| `includes/class-admin.php` | `ob_start`/`ob_end_clean` guard · activation migration + 3 new AJAX handlers · Export/Import/Migrate UI on Credentials page |
| `includes/class-settings.php` | `flush_rewrite_rules(false)` soft flush |
| `assets/js/admin.js` | 30 s "still running" warning · non-JSON error message · `clearTimeout` on success/error |
| `includes/class-personalized-brief.php` | **NEW** — full personalized brief subsystem |
| `includes/class-personalized-pages.php` | Added `brief` to DEFAULT_SLUGS |
| `fx-live-markets.php` | `require_once` for new class · `BT_PersonalizedBrief::init()` · → 119.22.0 |
| `docs/ROADMAP.md` | Version + 4 done-list entries |
| `CHANGELOG-v119.22.md` | **NEW** |

No new DB tables. No new cron hooks. No breaking changes.
