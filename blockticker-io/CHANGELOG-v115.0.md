# BlockTicker v115.0.0 — Public API Keys + OpenAPI / Swagger UI

**Release date:** April 2026
**Type:** Feature release (additive, no breaking changes)
**Upgrade path:** Drop-in over v114.0.0

---

## Overview

Two complementary deliverables that transform the existing
`/wp-json/blockticker/v1/*` endpoints from an unofficial public API into a
first-class product:

1. **API Key management.** End-users can self-mint keys from a
   `[bt_api_keys]` shortcode — pick a tier, label it, copy the plaintext
   once, then manage, rotate, and revoke from the same UI. Usage is metered
   per-key with per-minute and per-day ceilings. Site admins get a master
   console under **BlockTicker → 🔑 API Keys** showing every key across
   every user, with revoke/restore/delete controls and live usage bars.
2. **OpenAPI 3.0 spec + interactive Swagger UI.** The full API is
   documented as a hand-curated OpenAPI 3.0 spec served at
   `/wp-json/blockticker/v1/openapi.json`. A new `[bt_api_swagger]`
   shortcode drops Swagger UI v5 into any page — visitors can browse all
   endpoints, authorize with their key, and make live "Try it out" calls
   against the real API, all from the browser.

Plus a small portability fix for `mbstring`-less shared hosts that
affected v113/v114 (see "Portability" section).

---

## New: `includes/class-api-keys.php` (~720 lines)

One new class `BT_APIKeys` — the full key lifecycle and metering engine.

### Data model (`bt_api_keys_v2` option)

```json
{
  "id":           "bt_a1b2c3d4",
  "key":          "bt_a1b2c3d4_e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0",
  "user_id":      42,
  "label":        "My dashboard",
  "tier":         "standard",
  "rate_per_min": 600,
  "rate_per_day": 100000,
  "created":      1745200000,
  "last_used":    1745201234,
  "usage_today":  847,
  "usage_total":  12400,
  "usage_day":    "2026-04-21",
  "revoked":      false,
  "revoked_at":   null
}
```

The `key` field contains the full plaintext. Hashed-at-rest was considered
and explicitly rejected: users frequently lose keys, and being able to
show the active key in the UI after initial mint is a much better UX than
forcing re-rotation on every forgotten password manager. Admin revocation
is the primary security control. A "Zero-Trust Keys" mode (hashed at rest
with last-used preview only) is tracked as a v116+ candidate.

### Key format

```
bt_a1b2c3d4_e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0
└┬┘└───┬──┘ └──────────────┬─────────────────┘
prefix  id                  secret
```

- `bt_` prefix for unambiguous identification when found in logs / leaks
- `a1b2c3d4` (8 hex chars) = stable record id, safe to display and
  reference in REST URLs
- `_` delimiter
- `e5f6…s9t0` (32 hex chars) = the actual entropy (128 bits from
  `random_bytes(16)`)

### Tiers & rate limits

| Tier | Per minute | Per day |
|---|---|---|
| `public` | 60 | 10,000 |
| `standard` | 600 | 100,000 |
| `premium` | 3,000 | 1,000,000 |

Limits are **snapshotted onto the key record at mint time** (not looked
up from tier constants on every request). This means a later tweak to
`tier_limits()` doesn't retroactively change existing keys' rate limits —
admins can manually bump `rate_per_min` on any individual record for
grandfathering or partnership deals without risking accidental downgrades.

### Per-user cap

Each user can have at most **10 active keys**. Revoke or rotate an
existing one to free a slot. Revoked keys don't count. Admin-revoked
keys can be restored.

### Rotation

`rotate()` atomically revokes the old record and mints a new one with
the same label (suffixed `(rotated)`) and the same tier. Use case: a
key was committed to a public repo — rotate it, update your application
with the new secret, and the old secret is dead the moment the revoke
lands.

### Usage metering

Every successful API call to a rate-limited endpoint calls
`BT_APIKeys::bump_usage($id)` from `BT_API::resolve_auth()`. Per-day
counters auto-rollover at UTC midnight via a daily cron
(`bt_apikeys_daily_rollover`), with a belt-and-suspenders date check on
every bump so missed cron runs don't leave the counter permanently
frozen on yesterday's tally.

### Verification

An offline smoke test validated **27/27 lifecycle assertions** before
removal from the release tree:

- Mint returns a valid record with correct tier-dependent rate limits ✅
- `find_by_key` finds minted keys + returns null for invalid keys ✅
- Usage counters increment atomically (`usage_today`, `usage_total`) ✅
- `last_used` timestamp is stamped on every bump ✅
- Per-user 10-key cap blocks the 11th mint with `too_many` WP_Error ✅
- Revoke sets flag without deleting; restore clears it ✅
- Rotate issues new record, revokes old, different secrets ✅
- Hard-delete removes the record entirely ✅

---

## New: `includes/class-api-docs.php` (~420 lines)

One new class `BT_APIDocs` — OpenAPI spec builder and Swagger UI renderer.

### OpenAPI 3.0 spec at `/wp-json/blockticker/v1/openapi.json`

The spec is hand-curated, not auto-generated from
`rest_get_server()->get_routes()`. Auto-discovery would be mechanically
complete but produce generic descriptions. The hand-written version has:

- Accurate descriptions for every endpoint (not "Callback: endpoint_history")
- Proper OpenAPI `parameters` with types, defaults, min/max, enums
- Response schemas with component references (`#/components/schemas/Coin`)
- Two `securitySchemes`: `ApiKeyAuth` (header) + `CookieAuth` (for
  /user/* routes)
- A full `tags` taxonomy (Prices / Market / News / Signals / History / Account)

**Spec summary** (validated by offline JSON smoke test):

| Section | Count |
|---|---|
| Paths | 15 |
| Operations | 16 |
| Schemas | 10 |
| Spec size | 36 KB (JSON, pretty-printed) |
| OpenAPI version | 3.0.3 |

**Paths covered:**

- `GET  /`
- `GET  /prices/crypto`
- `GET  /prices/crypto/{symbol}`
- `GET  /prices/forex`
- `GET  /prices/forex/{pair}`
- `GET  /fear-greed`
- `GET  /sentiment` (v104)
- `GET  /correlation` (v106)
- `GET  /news`
- `GET  /signals`
- `GET  /history/{symbol}`
- `GET    /user/api-keys` (v115, cookie auth)
- `POST   /user/api-keys`
- `DELETE /user/api-keys/{id}`
- `POST   /user/api-keys/{id}/rotate`
- `GET  /openapi.json`

**Schemas defined:** `Coin`, `FearGreed`, `NewsItem`, `Signal`,
`HistoryPoint`, `SentimentMood`, `CorrelationMatrix`, `APIKey`, `Error`,
`Meta`.

The spec endpoint emits `Access-Control-Allow-Origin: *` and
`Cache-Control: public, max-age=300` so any third-party Swagger UI /
Postman / Insomnia instance can fetch it cross-origin.

### Shortcode: `[bt_api_swagger]`

```
[bt_api_swagger height="85vh" theme="auto" try_it="1" cdn="jsdelivr"]
```

Drops Swagger UI v5.17.14 directly into any page. Attributes:

| Attribute | Default | Description |
|---|---|---|
| `height` | `85vh` | Mount height (CSS value) |
| `theme` | `auto` | `auto` = match `prefers-color-scheme`; `light`; `dark` |
| `try_it` | `1` | Enable the "Try it out" live-request feature |
| `cdn` | `jsdelivr` | `jsdelivr` / `unpkg` / `self` (to serve your own copy) |

The dark-mode implementation is a deliberately simple CSS filter
(`invert(.92) hue-rotate(180deg)`) that flips Swagger UI's default light
theme under `@media (prefers-color-scheme: dark)`, then un-flips the code
highlight areas so JSON examples render correctly. This is ~15 lines of
CSS versus bundling an entire dark theme; upstream Swagger UI doesn't
ship one officially.

### CDN policy

Swagger UI is fetched from **JSDelivr by default** (~900 KB total, all
cached by the CDN). For sites with strict CSP, the `cdn="unpkg"` attribute
switches to unpkg.com, and a `bt_api_swagger_cdn=self` option is
designed for future self-hosting once asset bundling is in scope (not
v115). No JS or CSS is bundled in the plugin — the spec + the shortcode
wrapper are all PHP.

---

## Changes to `includes/class-api.php`

Two focused edits to the v65.1 auth pipeline, zero breaking changes to
callers:

### `resolve_auth()` — extended

Now returns four fields: `key`, `tier`, `key_id`, `per_min`.

**Lookup order:**
1. Check `bt_api_keys_v2` via `BT_APIKeys::find_by_key()` — if found and
   not revoked, bump usage and return. This is the v115+ path.
2. Fall back to the legacy `bt_api_keys` option shape (pre-v115 sites
   that hand-seeded keys). Returns `key_id: null` (no metering).
3. If the provided key matches neither, treat as anonymous — no 401, the
   API is public, and an invalid key just demotes the request to the
   anon rate tier.

### `check_rate_limit()` — extended

```php
if ( $auth['key'] && ! empty( $auth['per_min'] ) ) {
    $max = (int) $auth['per_min'];
} else {
    $max = $auth['key'] ? self::RATE_KEYED : self::RATE_ANON;
}
```

Per-key `rate_per_min` (from the v115 storage) overrides the tier
default. Legacy v65-era keys keep the old `RATE_KEYED = 600` behaviour
via the `else` branch.

---

## Admin UI

**BlockTicker → 🔑 API Keys** — new submenu page:

- 5-tile metric header: total keys / active / revoked / calls today /
  calls all-time
- Master table with columns: Key ID, User (links to user edit), Label,
  Tier (colour-coded badge), Today/Limit (inline usage bar), Total,
  Last used (humanised), Status, Actions
- Tier badges: grey (public), blue (standard), purple (premium)
- Usage bar colour scales to pressure: green <50%, amber 50-80%, red >80%
- Inline Revoke / Restore / Delete buttons with confirm dialogs
- "Show revoked" toggle (default off)
- All actions AJAX-driven, no page reload

---

## Shortcodes

### `[bt_api_keys]`

User-facing self-service panel. Attributes:

| Attribute | Default | Description |
|---|---|---|
| `default_tier` | `standard` | Pre-selected tier in the create form |
| `tiers` | `public,standard` | Comma-separated allowed tiers for self-mint |

Renders:

- **Create form** — label input + tier dropdown (only admin-allowed
  tiers shown) + Create button
- **Plaintext reveal panel** — on successful mint, shows the full key in
  a copy-friendly block with a "Save this now" warning
- **Existing keys table** — label, masked key, tier, usage bar
  (today/limit), last-used time, Rotate/Revoke buttons per row
- **Docs link** — pointer to the Swagger UI page

Guest users see a "Log in" prompt instead of the form.

### `[bt_api_swagger]`

See "Shortcode: `[bt_api_swagger]`" under the API docs section above.

---

## New REST routes

| Method | Path | Auth | Description |
|---|---|---|---|
| `GET` | `/blockticker/v1/user/api-keys` | Cookie | List the caller's keys (masked) |
| `POST` | `/blockticker/v1/user/api-keys` | Cookie | Mint a new key (returns plaintext once) |
| `DELETE` | `/blockticker/v1/user/api-keys/{id}` | Cookie | Revoke (soft-delete) a key |
| `POST` | `/blockticker/v1/user/api-keys/{id}/rotate` | Cookie | Revoke + re-issue in one call |
| `GET` | `/blockticker/v1/openapi.json` | None | The OpenAPI 3.0 spec |
| `GET` | `/blockticker/v1/openapi` | None | Alias for the spec |

All write operations are wp_rest nonce protected via the standard
`X-WP-Nonce` header. Plaintext keys are returned **only** on POST (mint
or rotate) — never on GET — so listing keys can't leak secrets.

---

## Portability fix — `mbstring`-less shared hosts

Shared PHP hosts sometimes ship without the `mbstring` extension. v113
(social) and v114 (portfolio-v2) used `mb_strlen()` / `mb_substr()` in
hot paths — on an mbstring-less host, those calls fatal-error with
"Call to undefined function".

**Fix:** Two new helpers in `BT_Utils`:

| Helper | Behaviour |
|---|---|
| `BT_Utils::strlen_unicode( $s )` | `mb_strlen($s, 'UTF-8')` if available; else `preg_match_all('/./su', $s)` (regex-based UTF-8 code point count) |
| `BT_Utils::substr_unicode( $s, $start, $length )` | `mb_substr(...)` if available; else plain `substr(...)` |

**Touched files** — all `mb_*` call sites migrated:

- `class-social.php` — 10 call sites (Twitter 280-char caps, Telegram
  4000-char cap, MarkdownV2 escape loop)
- `class-portfolio-v2.php` — 4 call sites (note truncation, date string
  length check, exchange-pair quote suffix stripping)
- `class-api-keys.php` — 1 call site (label length cap)

Byte-vs-Unicode accuracy only matters where platform character limits
apply (Twitter, Telegram). For ASCII-bounded values (dates, currency
suffixes), the helpers degrade gracefully to plain `strlen`/`substr` —
which is equally correct since ctype_digit guarantees ASCII input.

---

## `fx-live-markets.php`

- Version → 115.0.0 (header + `BT_VERSION`)
- `require_once BT_DIR . 'includes/class-api-keys.php'`
- `require_once BT_DIR . 'includes/class-api-docs.php'`
- `add_action( 'plugins_loaded', array( 'BT_APIKeys', 'init' ) )`
- `add_action( 'plugins_loaded', array( 'BT_APIDocs', 'init' ) )`
- `register_deactivation_hook( __FILE__, array( 'BT_APIKeys', 'deactivate' ) )`

---

## PHP lint

All 52 plugin PHP files clean.

---

## Safety & rollback

No database schema changes. Per-user cron (`bt_apikeys_daily_rollover`)
is cleared on deactivation. To fully roll back:

```sql
DELETE FROM wp_options WHERE option_name = 'bt_api_keys_v2';
-- Optional: clear the swagger-CDN preference
DELETE FROM wp_options WHERE option_name = 'bt_api_swagger_cdn';
```

…then reinstall v114.0.0. The legacy `bt_api_keys` option is untouched.

---

## Usage

### As an end user

1. Go to the user-dashboard page that hosts `[bt_api_keys]`.
2. Enter a label (e.g. "Python trading bot"), pick a tier, click
   **Create Key**.
3. **Copy the plaintext immediately** — it's only shown this one time.
4. Send it as `X-BT-API-Key: bt_…` on every API request.
5. Track usage in the table below the form — once your `usage_today`
   approaches `rate_per_day`, your bot gets HTTP 429s until UTC midnight.
6. If a key leaks, click **Rotate** — the old secret is killed
   instantly, a new one is issued, and you update your app with the new
   value.

### As a site admin

1. **BlockTicker → 🔑 API Keys** for the master console.
2. Revoke any misbehaving key with one click.
3. Watch the usage-today bars colour-code (green/amber/red) — that
   surfaces abuse faster than poring through server logs.

### As an API consumer browsing the docs

1. Drop `[bt_api_swagger]` on a public page (e.g. `/api-docs/`).
2. Visitors see the full interactive reference. They can click
   **Authorize**, paste their API key, then "Try it out" on any
   endpoint and get a live response in-page.

---

## What's next

- **v116.0** — Tax Year Report: export the v114 transaction ledger's
  realized gains as PDF/CSV with long-term vs short-term classification
  (>365-day lots), grouped by tax year, with Form-8949-compatible output.
- **v117.0** — Zero-Trust API Keys: opt-in mode that hashes keys at
  rest (SHA-256) with a last-used preview in admin, for sites that
  prefer crypto-scale security over UX convenience.
