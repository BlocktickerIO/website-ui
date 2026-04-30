# Changelog — v119.28.5

## Pixel-perfect alignment with the design mock

Screenshot comparison from the live site showed the landing page was being
**squeezed inside the theme's content container** — visible dark gutters
on left and right, the ticker had rounded corners (a "card" inside a wider
wrapper instead of edge-to-edge), and the whole header chrome was contained
instead of full-bleed. Previous resets only flattened well-known WP
wrappers (`.entry-content`, `.site-main`, `#primary`); your theme uses
something else, so the design was contained.

This release applies eleven targeted fixes covering everything in the
checklist.

### 1. Container break-out (the root cause)
Added a hard reset that flattens fifteen common theme wrapper classes
(`.wrap`, `.wrapper`, `.container`, `.site`, `#page`, `#content`,
`article`, `.post-inner`, `.cp-content`, `.fxlm-content`, …) plus uses the
modern `:has(.btlp)` fallback so the rule fires regardless of body class.
The wrapper itself becomes truly full-width with `width: 100%; max-width: 100vw`.

### 2. Page chrome is full-bleed
The disclaimer strip, ticker, and nav each use the classic viewport-width
breakout trick:
```css
width: 100vw;
margin-left: calc(50% - 50vw);
margin-right: calc(50% - 50vw);
```
This makes them edge-to-edge regardless of any remaining parent
constraint. Their *inner* content (`__inner`) still has `max-width: 1400px`
and is centered.

### 3. Top warning bar (disclaimer) — proper flex alignment
Three-cell flex (`flex-shrink-0` icon + `flex: 1` text + `flex-shrink-0`
link), 8px-vertical padding, 32px-horizontal padding, 12.5px font, 1.5
line-height, link no longer underlined by default (underlines on hover
only) — matches the mock exactly.

### 4. Market ticker — normalized spacing + visual polish
- Gap between items: 28px (was inconsistent 36px)
- "● LIVE MARKETS" leading badge: 800 weight, .12em tracking, uppercase, accent color
- Symbol: 700 weight, white, .02em tracking
- Numbers: 12.5px monospace, 500 weight
- Up/down change: explicit accent (#00d97e) / danger (#ff4d6a) at 600 weight for readability
- Vertical alignment: `align-items: baseline` so symbol/price/change sit on the same baseline

### 5. Navbar — true 3-section grid layout
Switched from `flex` (which left-anchors) to `display: grid` with
`grid-template-columns: 1fr auto 1fr`. Now:
- **Left column (1fr)**: brand, justified to start
- **Center column (auto)**: nav links, naturally centered by the equal flanking 1fr columns
- **Right column (1fr)**: actions (search, login, CTA, avatar/preview), justified to end

All nav items are now exactly 36px tall, perfectly vertically aligned,
14px font for links, 13px for CTAs. `min-height: 60px` on the inner row.

### 6. Hero — more spacing, polished typography
- Vertical padding: 80px → **96px top, 110px bottom**
- NOW badge: 28px → **32px** bottom margin
- H1: clamp(40px, 6.4vw, 64px) → **clamp(42px, 6.6vw, 68px)**, 800 weight, 1.04 line-height, -.018em tracking
- "Crypto, Forex & Web3" accent: kept green, added subtle 24px text-shadow glow at .18 opacity
- Subhead: 19px, max-width 640px, 38px bottom margin

### 7. CTA button — brighter, more prominent "Sign up free"
- Brighter green base (#00d97e accent token)
- Bold 800 weight, 13px, .01em tracking, **black text** for max contrast
- 36px height, 18px horizontal padding (was 9px/18px asymmetric)
- 6px border-radius
- **Hover: filter brightness(1.08) + 3px green halo + 1px lift**
- Active: returns to baseline + slight darken

### 8. Background — subtle dot grid texture
Replaced any inherited theme background with a 24×24px dot grid at 4.5%
opacity — visible but unobtrusive, matches the mock's faint texture.

### 9. Mobile (≤980px)
- Brand + 1fr spacer + actions, hamburger appears (was missing on the
  3-section grid)
- Ticker font shrinks to 11.5px, gap to 20px
- Disclaim strip wraps at narrow widths

## Files Changed
- `fx-live-markets.php` — version bump 119.28.4 → 119.28.5
- `assets/css/landing-revamp.css` — appended ~250 lines of pixel-perfect overrides

No template, JS, or PHP class changes — pure CSS in this release.

## After deploying
1. Hard-refresh (`Ctrl+F5` / `Cmd+Shift+R`)
2. Purge `/landing-revamp` from Cloudflare / your CDN
3. The new asset URL is `landing-revamp.css?ver=119.28.5` — the version
   string forces all browsers to fetch the new file
