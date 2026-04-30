# BlockTicker v111.0.0 — DEX Token Scanner 2.0

**Release date:** April 2026
**Type:** Feature release (additive, no breaking changes)
**Upgrade path:** Drop-in over v110.0.0

---

## Overview

Three new APIs, one new shortcode, and auto-integration with the price history
database. The existing `[bt_meme_explorer]` (GeckoTerminal Solana buckets)
is untouched — this release adds a complementary, more data-rich scanner
powered by DexScreener.

---

## New shortcode: `[bt_dex_scanner]`

```
[bt_dex_scanner chain="solana" min_liq="10000" min_vol="5000"
                max_age_h="0" min_buy_pct="0" show_safety="1" limit="20"
                title="DEX Token Scanner"]
```

### Chain support

| `chain` value | Description |
|---------------|-------------|
| `solana` | Solana (default) |
| `ethereum` | Ethereum mainnet |
| `base` | Coinbase Base |
| `bsc` | BNB Smart Chain |
| `arbitrum` | Arbitrum One |
| `all` | All chains mixed |

### Filter attributes

| Attribute | Default | Description |
|-----------|---------|-------------|
| `min_liq` | 10000 | Minimum pool liquidity in USD |
| `min_vol` | 5000 | Minimum 24h volume in USD |
| `max_age_h` | 0 | Maximum pool age in hours (0 = no limit) |
| `min_buy_pct` | 0 | Minimum buy pressure % (0–100) |
| `show_safety` | 1 | Show RugCheck badge (Solana only) |
| `limit` | 20 | Max rows shown |

### Columns

| Column | Description |
|--------|-------------|
| **Token** | Symbol, full name, pool age |
| **Price** | Current price in USD (adaptive decimal precision) |
| **5m / 1h / 24h** | Price change % colour-coded green/red |
| **Volume 24h** | Formatted (K/M/B) |
| **Liquidity** | Pool liquidity USD |
| **Buy / Sell** | Buy pressure bar (gradient red→green) + raw B/S count |
| **Safety** | RugCheck score badge (Solana only): ✅ Low / ⚠️ Medium / 🔴 High / 💀 Critical |
| **DEX** | Exchange label (Raydium, Uniswap, PancakeSwap etc.) |

**Buy pressure bar** — a horizontal bar that fills left-to-right based on what
percentage of 24h transactions are buys. 100% buys = solid green; 0% = solid
red. Shows raw buy/sell counts beneath the bar.

---

## New API integrations

### DexScreener (free, no key)

Three fetch methods added:

**`fetch_dexscreener_trending( $chain, $limit )`**
Hits `https://api.dexscreener.com/token-profiles/latest/v1` for token profiles,
then enriches each with live pair data. Falls back to `fetch_dexscreener_search`
on API failure. 2-minute transient cache per chain.

**`fetch_dexscreener_search( $query, $limit )`**
Searches `https://api.dexscreener.com/latest/dex/search?q={query}`.
Useful for embedding a specific token set. 2-minute cache.

**`normalise_dexscreener_pair( $pair )`**
Converts raw DexScreener pair objects to the internal schema, adding:
- `buys_24h`, `sells_24h`, `buy_pressure` (% buys of total txns)
- `fdv`, `market_cap`
- `price_change_{5m,1h,6h,24h}`
- `base_token_addr` (needed for RugCheck)

### RugCheck (free, no key — Solana only)

**`fetch_rugcheck_score( $mint )`**
Calls `https://api.rugcheck.xyz/v1/tokens/{mint}/report/summary`.
Returns `{ score, risk, risks[] }` where score is 0–1000 (lower = safer):

| Score | Risk label |
|-------|-----------|
| 0–199 | Low |
| 200–499 | Medium |
| 500–799 | High |
| 800–1000 | Critical |

Cached for 1 hour per token mint address.
Called for the first 10 tokens in each render to keep page load fast.

---

## Auto-write to `wp_bt_price_history`

**`write_token_to_history( $token )`** — new method.

On every `[bt_dex_scanner]` render, the top 10 tokens (by volume) are
snapshot-written to `wp_bt_price_history` using `BT_Database::insert_price_snapshot()`.

Symbol format: `DSX:{SYMBOL}` (e.g. `DSX:BONK`, `DSX:WIF`).

This means:
- After a few renders, `[bt_price_chart symbol="DSX:BONK"]` will display a
  real price history chart for tracked DEX tokens
- `[bt_correlation_heatmap symbols="BTC,ETH,DSX:BONK"]` will include DEX tokens
  in correlation analysis once enough history accumulates
- The price history table serves as a free tier-1 DEX price archive

---

## `includes/class-dex-tokens.php`

- `bt_dex_scanner` shortcode registered in `setup()`
- 5 new public/private static methods appended:
  `fetch_dexscreener_trending`, `fetch_dexscreener_search`,
  `fetch_dexscreener_pairs_for_token`, `fetch_rugcheck_score`,
  `write_token_to_history`, `normalise_dexscreener_pair`, `sc_dex_scanner`

---

## `fx-live-markets.php`

- Version → 111.0.0

---

## PHP lint

- `includes/class-dex-tokens.php` ✅
- `fx-live-markets.php` ✅

---

## What's next

- **v112.0** — AI Market Analysis 2.0: on-demand deep-dive reports per asset
  (not just daily AI post) triggered from any asset page, with auto-formatted
  WordPress post creation and structured JSON-LD Article schema.
