# BlockTicker v113.0.0 — Social Auto-Share (X + Telegram)

**Release date:** April 2026
**Type:** Feature release (additive, no breaking changes)
**Upgrade path:** Drop-in over v112.0.0

---

## Overview

When a per-asset AI analysis post is saved by `BT_AIBlog::upsert_asset_analysis_post()`
(v112), a new `bt_asset_analysis_saved` action fires. A new `BT_Social` class
listens on that hook and:

1. Generates a compact 5-tweet X/Twitter thread specific to the asset using live
   price, sentiment, and signal data — stored in post meta as
   `_bt_asset_twitter_thread`.
2. Generates a MarkdownV2-safe Telegram channel message with the same context —
   stored in post meta as `_bt_asset_telegram_msg`.
3. Auto-publishes both — subject to per-platform toggles and a per-symbol
   cooldown window (default 6 hours) — via the existing Twitter OAuth 1.0a
   helpers (`BT_AIBlog::publish_first_tweets_to_twitter`) and the Telegram
   Bot API.
4. Logs every attempt to post meta (`_bt_social_log`) with timestamps, status,
   and delivery IDs for auditing and retry.

An admin panel at the top of the right column in **BlockTicker → 🗄 Database**
exposes the full configuration plus test-send buttons and a recent-deliveries log.

---

## New: `includes/class-social.php`

One new class `BT_Social` (~650 lines).

### Event pipeline

**Listener:** `BT_Social::on_asset_analysis_saved( $post_id, $sym, $name )`
Triggered by `do_action('bt_asset_analysis_saved', $post_id, $sym, $name)`,
which fires from `BT_AIBlog::upsert_asset_analysis_post()` after the post meta
is written.

**Flow per invocation:**

1. **Cooldown check** — `bt_social_cooldown_{SYM}` option tracks the last
   successful publish timestamp per symbol. If under `bt_social_cooldown_hours`
   (default 6), the run logs a `skip` entry and returns without posting.
2. **Toggle check** — if both `bt_social_x_enabled` and `bt_social_tg_enabled`
   are off, the listener returns immediately.
3. **Context assembly** — calls `BT_AIBlog::assemble_asset_context($sym)` to
   get the same live-data bundle used for the analysis itself (price, 7d range,
   Fear & Greed, 48h news sentiment, recent symbol-filtered headlines, active
   signals).
4. **Thread generation** — if X is enabled, prompts the configured AI provider
   (Claude Haiku / GPT-4o-mini) for a 5-tweet thread in a specific shape
   (hook → technicals → sentiment → catalyst → outlook).
5. **Telegram message generation** — if Telegram is enabled, builds a
   MarkdownV2 message with emoji-led data rows, up to 3 headlines, up to 2
   signals, and a UTM-tagged "Read full analysis" deep-link.
6. **Publish** — posts to each platform in turn, logging OK/error per platform.
7. **Cooldown stamp** — updated only if at least one platform succeeded.

### Twitter thread (X) generator

The thread has a deliberately tight shape: exactly 5 tweets, 250-char hard cap
each, with a cashtag (`$BTC`, forex pairs collapsed to the base currency
`$EUR`) used once and no hashtags by default. The prompt forbids retail slang
("moon", "pump", "wagmi") and limits emoji to one per tweet.

The post URL is appended to the final tweet with UTM parameters (default:
`utm_source=social&utm_medium=auto&utm_campaign=asset_analysis`). If adding
the URL would push that tweet over 280 chars (t.co collapses every link to
23 characters + 1 leading space = 24), a 6th "Full analysis: {url}" tweet is
appended instead.

### Telegram message generator

Structured MarkdownV2 with proper escaping of all 18 reserved characters
(`_ * [ ] ( ) ~ \` > # + - = | { } . !`) — critical because Telegram returns
`400 Bad Request` on unescaped punctuation in MarkdownV2 mode. Message
layout:

```
📊 <Asset Name> (<SYM>) — Market Analysis

💰 Price: $X  (+N.NN% / 24h)  📈
📐 7d range: $low → $high  (+N.NN% / 7d)
😨 Fear & Greed: <Label> (<value>)
🧠 News sentiment: <Greed|Fear|Neutral> (+0.NN)

📰 Top headlines
• <headline 1>
• <headline 2>
• <headline 3>

🎯 Active signals
🟢 [LONG] <signal 1>
🔴 [SHORT] <signal 2>

[Read full analysis on <site>](deep-link)
```

Hard-capped at 4000 chars (below Telegram's 4096 ceiling). Signal direction is
inferred from title text (`long`/`buy`/`bullish` → LONG; `short`/`sell`/`bearish` → SHORT)
since `bt_signal_items` doesn't carry an explicit direction field.

### Twitter publisher

`publish_to_twitter($post_id, $thread)` reuses
`BT_AIBlog::publish_first_tweets_to_twitter()` (v60) by temporarily swapping
the `_bt_twitter_thread` post meta to the asset thread, calling the helper,
and restoring the previous value. This means all the tested OAuth 1.0a
signature logic, 500ms inter-tweet delay, and reply-chain wiring is reused
verbatim — no new code path for the actual X API handshake.

On success, `_bt_social_twitter_ids` and `_bt_social_twitter_posted_at` are
stamped on the post.

### Telegram publisher

`publish_to_telegram($post_id, $markdown)` POSTs to
`https://api.telegram.org/bot{token}/sendMessage` with `parse_mode=MarkdownV2`.
Request timeout 15 s. On success, `_bt_social_telegram_msg_id` and
`_bt_social_telegram_posted_at` are stamped on the post.

Errors surface the Telegram API `description` field (e.g. "Bad Request:
chat not found", "Forbidden: bot was kicked from the channel chat") directly
into the admin log.

### Inline shortcode: `[bt_social_share_buttons]`

```
[bt_social_share_buttons symbol="BTC"]
```

Renders a compact horizontal button bar for end users to share the current
page:

| Button | Action |
|---|---|
| 🕊 **Post $BTC** | Opens `twitter.com/intent/tweet` pre-filled with cashtag + URL |
| ✈ **Telegram** | Opens `t.me/share/url` with page title |
| 💼 **LinkedIn** | Opens LinkedIn share dialog |
| 📋 **Copy link** | Clipboard copy via `navigator.clipboard` (with secure-context fallback) |

Pure CSS, no external JS libraries. Safe inside Core Web Vitals budget —
zero third-party requests until the user actually clicks. Inline SVG icons
avoid the SVG sprite overhead.

---

## Hook injection in `class-aiblog.php`

Two one-line changes:

1. `assemble_asset_context()` visibility changed `private` → `public` so
   `BT_Social` can reuse the exact context bundle (keeps the data shown on
   the site and shared to social in perfect sync).

2. At the end of `upsert_asset_analysis_post()`, immediately after the
   `_bt_asset_analysis_html` / `_bt_asset_analysis_ts` post meta write:

```php
/**
 * v113.0: Fire after the post is saved so BT_Social (or any other listener)
 * can generate and auto-publish X threads and Telegram messages.
 */
do_action( 'bt_asset_analysis_saved', $post_id, $sym, $name );
```

The hook has 3 arguments (`$post_id`, `$sym`, `$name`), so third-party code
can listen for custom delivery pipelines without touching `BT_Social`.

---

## Admin panel — `class-db-admin.php`

**🔀 Social Auto-Share** panel injected at the very top of the right column
(above the Webhook panel). Contains:

**Platform toggles:**
- ✅ "Auto-publish thread" (X / Twitter)
- ✅ "Auto-publish message" (Telegram)
- Live green/red credential-status indicator for Twitter API creds
  (falls back to `"Set them in BlockTicker → AI Blog Generator → AI Analysis tab"`)

**Telegram credentials:**
- Password-masked **Bot token** field (placeholder hides existing value)
- **Chat ID** field with dual format help (`@channel_handle` or `-100…`)

**Cooldown:**
- Numeric input, 1–168 hours, default 6

**Buttons:**
- 💾 **Save** — persists all settings
- 🧪 **Test X** — creates a draft post, posts a single test tweet through
  the real API, deletes the draft on failure (kept on success for reference)
- 🧪 **Test Telegram** — posts a minimal test message directly

**📜 Recent deliveries** (collapsible, last 10 analyses):
- Status dot: ✅ ok / ❌ error / ⏸ skip / ⏳ pending
- Latest log entry summary
- Attempt count + age
- **view** link (opens the analysis post)
- **↻ retry** button — clears the cooldown for that symbol and re-fires the
  entire pipeline via `on_asset_analysis_saved()`

### New AJAX handlers

| Action | Handler |
|---|---|
| `wp_ajax_bt_social_save_config` | Persists all 5 options |
| `wp_ajax_bt_social_test_x` | One-tweet live test |
| `wp_ajax_bt_social_test_tg` | One-message live test |
| `wp_ajax_bt_social_retry_post` | Clears cooldown + re-fires pipeline |
| `wp_ajax_bt_social_publish_now` | Regenerates analysis (→ fires the hook) |

All handlers verify the existing `bt_db_admin` nonce and `manage_options`
capability.

---

## Options introduced

| Option | Type | Default | Purpose |
|---|---|---|---|
| `bt_social_x_enabled` | `'0'\|'1'` | `'0'` | Toggle X auto-publish |
| `bt_social_tg_enabled` | `'0'\|'1'` | `'0'` | Toggle Telegram auto-publish |
| `bt_telegram_bot_token` | string | `''` | Bot token from @BotFather |
| `bt_telegram_chat_id` | string | `''` | `@handle` or `-100…` numeric ID |
| `bt_social_cooldown_hours` | int | `6` | Per-symbol cooldown |
| `bt_social_utm` | string | `utm_source=social&utm_medium=auto&utm_campaign=asset_analysis` | Appended to shared URLs |
| `bt_social_cooldown_{SYM}` | int | — | Per-symbol last-post timestamp (autoload=no) |

Twitter credentials (`bt_twitter_api_key`, `bt_twitter_api_secret`,
`bt_twitter_access_token`, `bt_twitter_access_token_secret`) were already
introduced in v60 and are reused as-is.

---

## Post meta introduced

| Meta key | Type | Purpose |
|---|---|---|
| `_bt_asset_twitter_thread` | array<string> | The generated 5-tweet thread |
| `_bt_asset_telegram_msg` | string | Generated MarkdownV2 message |
| `_bt_social_log` | array<row> | Up to 40 recent delivery attempts (ts, status, platform, detail, ids) |
| `_bt_social_twitter_ids` | array<string> | Tweet IDs from the last successful post |
| `_bt_social_telegram_msg_id` | int | Telegram `message_id` from the last successful post |
| `_bt_social_twitter_posted_at` | int | Unix ts of last successful X post |
| `_bt_social_telegram_posted_at` | int | Unix ts of last successful Telegram post |

---

## `fx-live-markets.php`

- Version → 113.0.0 (plugin header + `BT_VERSION`)
- `require_once BT_DIR . 'includes/class-social.php'`
- `add_action( 'plugins_loaded', array( 'BT_Social', 'init' ) )`
- `register_deactivation_hook( __FILE__, array( 'BT_Social', 'deactivate' ) )`

---

## PHP lint

All 4 touched files clean:
- `includes/class-social.php` ✅ (new)
- `includes/class-aiblog.php` ✅ (hook fire + visibility change)
- `includes/class-db-admin.php` ✅ (panel injection)
- `fx-live-markets.php` ✅ (version + wiring)

---

## Safety & rollback

No database schema changes. No cron events added. Listener defaults to
**off** on fresh install — site operators have to explicitly toggle either
platform in the admin before anything goes out. Cooldown defaults to 6h so
multiple refreshes of the same asset page don't trigger multiple posts.

All Twitter API work reuses the already-battle-tested v60 helpers — no new
OAuth signing code was written, reducing the surface area for signature bugs.

**Rollback:** Reinstall v112.0.0 — the option rows and post meta written by
v113 are left in place (harmless if unused) but can be cleaned with:

```sql
DELETE FROM wp_options WHERE option_name LIKE 'bt_social_%';
DELETE FROM wp_options WHERE option_name = 'bt_telegram_bot_token';
DELETE FROM wp_options WHERE option_name = 'bt_telegram_chat_id';
DELETE FROM wp_postmeta WHERE meta_key LIKE '_bt_social_%' OR meta_key IN ('_bt_asset_twitter_thread','_bt_asset_telegram_msg');
```

---

## Usage

1. **Twitter (optional):** If not already configured, add Twitter API v2
   credentials in **BlockTicker → AI Blog Generator → AI Analysis tab**
   (`bt_twitter_api_key`, `bt_twitter_api_secret`, `bt_twitter_access_token`,
   `bt_twitter_access_token_secret`).
2. **Telegram (optional):** Create a bot with @BotFather, copy the token.
   Add the bot as an admin in your target channel. Copy the channel handle
   (`@myhandle`) or the numeric chat ID (`-100...`).
3. Open **BlockTicker → 🗄 Database** and scroll to the **🔀 Social Auto-Share**
   panel at the top of the right column. Paste the bot token, chat ID, flip
   the toggles, click **Save**.
4. Click **🧪 Test X** and **🧪 Test Telegram** to verify credentials work.
5. Visit any asset page that has `[bt_ai_analysis_v2 symbol="BTC" autoload="1"]`
   embedded, or wait for an existing analysis to be refreshed. The social
   pipeline fires automatically. Check the **📜 Recent deliveries** section
   for status.
6. Optionally embed `[bt_social_share_buttons symbol="BTC"]` on asset pages
   to give readers a one-click share bar.

---

## What's next

- **v114.0** — Portfolio Tracker 3.0: transaction-level cost-basis accounting,
  realized/unrealized P&L, CSV import, and tax-lot FIFO/HIFO selection.
- **v115.0** — Public API Keys: user-owned API tokens for the existing
  `/wp-json/blockticker/v1/*` endpoints with per-key rate limits and usage
  metering.
