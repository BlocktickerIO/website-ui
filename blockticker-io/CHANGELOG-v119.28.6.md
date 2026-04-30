# Changelog — v119.28.6

## Root cause fix: inner content containers were being reset by the theme-wrapper override

### The bug
In v119.28.5 the "hard container reset" block listed `.container` in its
selector list:

```css
body.bt-landing-page .container,   /* ← this was the problem */
body:has(.btlp) .container { max-width: none !important; ... }
```

That rule was intended to flatten **theme wrapper** elements that were
constraining `.btlp` from the outside. But `.container` is **also the
plugin's own inner layout class** — used on every section inside `.btlp`
(`container--hero`, `container--prose`, and the default 1100px container).

Result: every section's inner container expanded to full viewport width.
On a wide desktop this shows up as:
- Hero text / pill / CTAs spanning the full browser width instead of being
  centered at ~920px
- "How BlockTicker reads the market" sections expanding so their two-column
  grid (text left / chart right) stretched edge-to-edge
- All other sections similarly un-constrained

The ticker, nav, and disclaimer strip were *supposed* to break out to full
width (that's the viewport-width trick on `.btlp .ticker` etc.) — but all
the *content* sections got dragged along with them.

### The fix (two parts)

**Part 1 — Remove `.container` from the theme-reset selector list.**
The theme-reset only needs to flatten wrappers ABOVE `.btlp` (`.wrap`,
`.wrapper`, `.site`, `#page`, `article`, etc.). Those never have the class
name `.container`, or if they do, the `:not(.btlp *)` pattern below takes
care of it.

**Part 2 — Explicit restore block for plugin-internal containers.**
Added a new "1b" block immediately after the reset that reinstates the
correct values for `.btlp .container`, `.btlp .container--hero`, and
`.btlp .container--prose` with `!important` so they win over any theme
override while still being constrained:

```css
.btlp .container         { max-width: 1100px; margin: 0 auto; padding: 0 32px; }
.btlp .container--hero   { max-width: 920px; }
.btlp .container--prose  { max-width: 720px; }
```

### Result
- Hero section centered at ≤920px — text wraps naturally, matches mock
- All section grids (howit, outcomes, markets, meth, cta) centered at ≤1100px
- Ticker / nav / disclaimer remain full-bleed (100vw) as intended
- Background dot texture appears at correct density behind constrained content
- No change to template, JS, or PHP

## Files Changed
- `fx-live-markets.php` — version 119.28.5 → 119.28.6
- `assets/css/landing-revamp.css` — selector fix + restore block

## After deploying
1. Hard-refresh (`Ctrl+F5` / `Cmd+Shift+R`)
2. Purge `/landing-revamp` from Cloudflare / CDN
3. New asset URL: `landing-revamp.css?ver=119.28.6`
