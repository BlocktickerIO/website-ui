# BlockTicker v96.1.0 — Audit v97 Preview

**Release date:** April 2026
**Type:** Safe patch release (no breaking changes)
**Upgrade path:** Drop-in replacement for v96.0.0

---

## What this release is

This is the first of four planned patch releases implementing recommendations
from the deep architectural audit. It ships only the **reversible, low-risk
quick wins** — the items that tighten the codebase without changing any
public surface (constants, REST endpoints, database schema, shortcodes).

If you are running v96.0.0 in production, you can install this release with
zero migration work. No database changes. No config changes. No cache rebuild.

## What it fixes (5 items)

### F-01 · Version constant mismatch — **Critical**
The plugin header declared `Version: 96.0.0` while the PHP constant
`FXLM_VERSION` was stuck at `'89.0.0'`. WordPress used the header for the
update manager, but the plugin's own code — cache-bust keys on enqueued
assets, upgrade-routine thresholds, transient names — used the constant.
This caused asset caches to persist across "upgrades" and some upgrade
routines to re-trigger on every version check.

**Fix:** Both now declared at `96.1.0` with a comment documenting that
future bumps must update both values together.

**File:** `fx-live-markets.php`, lines 5 and 14.

### F-03 + F-18 · init-hook overload — **Critical**
The rewrite-rules verification callback on the `init` hook was running on
every single WordPress request, including REST API calls, cron ticks,
XML-RPC requests, and Heartbeat AJAX polls. Each run read the full
`rewrite_rules` option blob (often >50 KB serialized) and iterated its
keys looking for a `/crypto` rule.

**Fix:** Two layers of guard.
1. Early bail if `REST_REQUEST`, `DOING_CRON`, `DOING_AJAX`, or
   `XMLRPC_REQUEST` is set — these contexts never need rewrite checking.
2. Result cached in a 1-hour transient (`fxlm_rewrite_check_ok`). Cache
   miss → run the check; cache hit → bail without touching the options
   table.

Expected impact: measurable TTFB reduction on frontend (one fewer option
read per page load); larger reduction on REST endpoints (which skip the
check entirely).

**File:** `fx-live-markets.php`, lines 82–104 of the original → rewritten
with guards and caching.

### F-17 · Duplicate demo-forex-seeding — **Medium**
The demo forex rates were seeded by both a `register_activation_hook`
callback (correct, one-shot) AND an `add_action('init', ...)` callback
that re-checked on every request as a "fallback for non-activation
scenarios." Since the activation hook fires on every plugin upgrade too,
the fallback was never actually needed — it just added an options read
(and sometimes a write) to every single request.

**Fix:** Removed the `init`-hooked duplicate. Activation hook remains as
the single seeding path.

**File:** `fx-live-markets.php`, lines 323–339 of the original → deleted.

### F-08 · Stale `.bak` file shipped in plugin — **High**
`includes/class-pages.php.bak` (168 KB — a full backup of the pages class)
was included in the plugin zip. This is a security and professionalism
issue: `.bak` files accumulate in git, inflate the update payload, and can
expose old/vulnerable code paths if webserver config ever serves `.bak`
as text.

**Fix:** File deleted. Scanned for other `.bak`/`.old`/`.tmp`/`.orig`
cruft — none found.

### F-11 · Emoji in navigation labels — **High**
The audit flagged the emoji-heavy nav (🚀 🐸 💎 🔥 🏆) as projecting a
"crypto Twitter" aesthetic that damages credibility with institutional
and serious retail users. 64 nav-label lines contained decorative emoji.

**Fix:** Surgical strip that removes decoration while preserving
semantically-meaningful glyphs:

| Kept                   | Why                                     |
|------------------------|-----------------------------------------|
| ₿ Bitcoin, ⟠ Ethereum, ◎ Solana, ◈ BNB | Official currency marks, not emoji     |
| 𝕏 (threads link)       | Official X/Twitter brand identifier     |
| ⭐ My Watchlist         | Universal "favorite" UI affordance      |
| 🇺🇸 (language selector) | Dynamic, functional — indicates current language |
| Removed                | All decorative 🚀 🐸 💎 🔥 🏆 📊 📈 etc. |

**File:** `includes/class-navbar.php`. 64 nav lines touched.

## Diff stats

| File                              | Before    | After     | Δ        |
|-----------------------------------|-----------|-----------|----------|
| fx-live-markets.php               | 17,459 B  | ~17,900 B | +~440 B  |
| includes/class-pages.php.bak      | 168,766 B | (deleted) | −168,766 B |
| includes/class-navbar.php         | 63,313 B  | ~62,611 B | −702 B   |
| readme.txt                        | 7,249 B   | ~9,800 B  | +~2,600 B |
| **Net package size**              |           |           | **−166 KB** |

The +440 bytes in `fx-live-markets.php` is from the added inline documentation
comments. All inline comments are `// v96.1 audit fix F-XX: ...` so they're
searchable and removable in the future v97 refactor.

## How to roll back

Since this is a drop-in release with no schema changes, rollback is trivial:

1. In `/wp-content/plugins/`, delete the `blockticker-io/` directory.
2. Upload the v96.0.0 zip and extract.
3. WordPress options and content remain untouched; no data migration
   needed in either direction.

## What's explicitly **not** in this release

These were deferred to future releases because they are breaking changes
or major refactors:

- Renaming `FXLM_` constants to `BT_` (v97 — touches 1000+ call sites)
- Custom database tables for price/news/signals history (v97)
- REST namespace consolidation (v98)
- Native charting replacing TradingView embeds (v98)
- Precision Terminal design system (v99)
- Signal Accountability Leaderboard (v100)

See the full audit report for the complete v97 → v100 roadmap.

## Contact

Questions about this patch: reply on the original audit thread with
NestaConnect so the context stays together.
