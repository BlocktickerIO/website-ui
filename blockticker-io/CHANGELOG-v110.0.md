# BlockTicker v110.0.0 — User Watchlists 2.0

**Release date:** April 2026
**Type:** Feature release (additive, fully backward-compatible)
**Upgrade path:** Drop-in over v109.0.0

---

## Overview

The existing watchlist was a flat array of coin IDs stored in `localStorage` and
synced to a single `bt_watchlist` usermeta key for logged-in users. v110 upgrades
it to named multi-watchlists, per-coin annotations, and shareable public URLs —
while keeping the original `bt_watchlist` key in sync for backward compatibility.

---

## New shortcode: `[bt_watchlist_v2]`

```
[bt_watchlist_v2 default_tab="default"]
```

A fully interactive watchlist widget. No page reload required for any action.

### Named watchlists (tabs)

- Multiple named watchlists displayed as tabs above the table
- **＋ New list** button — prompts for a name, creates a new tab
- Tab badge shows item count: `Crypto (8)`
- Active tab persists across page loads (via the REST API for logged-in users,
  localStorage for guests)

### Per-coin annotations

Each row in the table now has two editable fields:

| Field | Description |
|-------|-------------|
| **Target** | Price target — when current price ≥ target, cell turns green with a 🎯 Hit! badge |
| **Note** | Free-text note up to 120 characters (e.g. "Bought at $45k") |

Both fields auto-save 800ms after the last keystroke (debounced) with no button
press required. Changes are pushed to the server for logged-in users or to
`localStorage` for guests.

### Price data

Live prices, 24h change, are pulled from the existing
`GET /blockticker/v1/prices` REST endpoint — the same source as the main crypto
table. The coin name and symbol come from `allCoins` loaded once on mount.

### 🔗 Share button

For logged-in users, clicking **🔗 Share** calls the new
`POST /user/watchlists/share` endpoint, which returns a 16-character token
and a full share URL (e.g. `https://example.com/watchlist/?share=abc123def456789a`).
The URL is displayed in a toast with a 📋 Copy button.

### Guest experience

Guest users (not logged in) get full watchlist functionality via `localStorage`
with a sign-up prompt at the bottom. On login, the existing sync mechanism in
`sc_watchlist_page` merges localStorage into the server-side `bt_watchlist`.
The new `bt_wlv2` localStorage key is separate from the legacy `bt_watchlist`
key to avoid conflicts.

---

## Data structure

**`bt_watchlists` usermeta** (new key, v110):

```json
{
  "default": {
    "name": "My Watchlist",
    "items": [
      { "id": "bitcoin", "note": "Bought at $45k", "target": 100000, "added_at": 1745200000 },
      { "id": "ethereum", "note": "", "target": null, "added_at": 1745200100 }
    ]
  },
  "defi": {
    "name": "DeFi",
    "items": [
      { "id": "uniswap", "note": "", "target": 15.0, "added_at": 1745201000 }
    ]
  }
}
```

**`bt_watchlist` usermeta** (legacy, kept in sync):

The existing flat array of coin IDs is updated on every save to include all
unique IDs across all named watchlists — zero breakage for any code reading
the old key.

**Share tokens** (`bt_wl_share_{16-char-token}` options, autoload=no):

```json
{ "user_id": 42, "slug": "default", "created_at": 1745200000 }
```

---

## New REST endpoints (3)

### `GET /user/watchlists` (auth required)

Returns all named watchlists for the current user. On first call, migrates
the legacy `bt_watchlist` flat array into a `default` named list automatically.

### `POST /user/watchlists` (auth required)

Saves all named watchlists. Validates and sanitises all fields server-side.
Also syncs the legacy `bt_watchlist` key with the union of all item IDs.

### `POST /user/watchlists/share` (auth required)

Body: `{ "slug": "default" }`. Creates (or returns an existing) 16-char share
token and returns the full share URL.

### `GET /watchlist/shared/{token}` (public)

No authentication required. Returns the shared watchlist data plus the owner's
display name. Used by the share URL landing page.

---

## `includes/class-userauth.php`

- `META_WATCHLISTS = 'bt_watchlists'` constant added
- `SHARE_OPTION_PREFIX = 'bt_wl_share_'` constant added
- 5 new public methods: `rest_get_watchlists`, `rest_save_watchlists`,
  `rest_create_share`, `rest_get_shared`, `sc_watchlist_v2`
- 3 new REST routes registered in `register_rest_routes()`
- `bt_watchlist_v2` shortcode registered in `init()`

---

## `fx-live-markets.php`

- Version → 110.0.0

---

## PHP lint

- `includes/class-userauth.php` ✅
- `fx-live-markets.php` ✅

---

## Backward compatibility

- `[fxlm_watchlist]` and `[bt_watchlist_page]` are untouched.
- `GET/POST /user/watchlist` legacy endpoints are untouched.
- `bt_watchlist` usermeta is kept in sync on every v2 save.
- The legacy sync in `sc_watchlist_page` continues to work.

---

## What's next

- **v111.0** — DEX Token Scanner 2.0: real-time Solana memecoin discovery
  via Jupiter/Raydium APIs, filtered by liquidity and holder count, with
  trend chart from `wp_bt_price_history`.
