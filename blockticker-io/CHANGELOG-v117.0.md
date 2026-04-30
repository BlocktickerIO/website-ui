# BlockTicker v117.0 — Terminal Design System Revamp

## Summary

Full visual identity overhaul. Structural content unchanged — every page layout,
shortcode, and data pipeline works identically. This is a pure design-layer update
that transforms the aesthetic from "SaaS landing page" to "terminal-grade intelligence platform."

---

## Design token changes (revamp-v44.css)

### Colors
- `--bt-accent` `#00d4aa` teal → `#00FF66` neon green — the single biggest identity shift
- `--bt-accent-2` `#0099ff` blue **removed** — one accent, terminal discipline
- `--bt-danger` `#ff4d6a` → `#FF3B30` (pure iOS red, more decisive)
- `--bt-accent-warm` `#f59e0b` → `#FFB800` (pure amber)
- `--bt-bg` `#0a0e1a` → `#0A0B0D` (deeper black)
- `--bt-bg-elev` `#111827` → `#121316` (card background)
- `--bt-bg-elev-2` `#1a2235` → `#1A1C20` (hover state)
- `--bt-bg-stripe` `#0d0e11` **added** (secondary marquee strip)
- `--bt-text` `#e2e8f0` → `#ffffff` (pure white headlines)
- `--bt-text-2` `#94a3b8` → `#a1a1aa` (zinc-400)
- `--bt-text-3` `#64748b` → `#71717a` (zinc-500)
- `--bt-text-4` new `#52525b` (zinc-600, very muted meta)
- `--fxlm-green` `#00d4aa` → `#00FF66` (frontend.css mirror)
- `--fxlm-red` `#ff4d6a` → `#FF3B30`
- `--fxlm-card` `#111827` → `#121316`

### Typography
- `--bt-font-display` **Plus Jakarta Sans** → **Chivo** (weight 900 for headlines — the "Bloomberg" jump)
- `--bt-font-body` Plus Jakarta Sans → **IBM Plex Sans**
- `--bt-font-mono` JetBrains Mono (unchanged, already correct)
- Google Fonts URL in `class-cwv.php` updated accordingly

### Radius — zeroed out (the terminal aesthetic)
- `--bt-radius` 14px → 0
- `--bt-radius-sm` 10px → 0
- `--bt-radius-lg` 20px → 0
- Global purge rule added — covers all `[class*="bt-"]`, `[class*="fxlm-"]` elements
- Exception: `.bt-icon-circle` / `.bt-coin-circle` for avatar/coin circles (50% preserved)

### Container
- `--bt-container` 1400px added (matches reference design)

---

## Component changes

### Hero section (`bt-h84-hero`)
- Removed: teal/blue radial gradient backdrop
- Added: CSS grid texture (60px × 60px, `rgba(255,255,255,.025)` lines)
- Added: faint neon glow bottom-left via `::after` pseudo (very subtle)
- Headline scaled up: `clamp(2rem,3.5vw,3rem)` weight-700 → `clamp(2.4rem,4.5vw,4.5rem)` weight-900 Chivo
- Line-height tightened: 1.15 → 0.95
- Added: `.bt-h84-eyebrow` mono uppercase label above headline
- Accent span: gradient text dropped → solid `var(--bt-accent)`
- Trust stats: horizontal flex → **2×2 grid with left/bottom borders** — white numbers (not teal)
- Container widened to `--bt-container` (1400px)
- `border-bottom: 1px solid var(--bt-border-2)` added as section separator

### Market state fold (`bt-h84-state`)
- Background updated to `--bt-bg-elev`
- Movers columns: individual `gap:24px` border-radius cards → `gap:1px` shared-background flush grid
- Mover accent colors updated to `var(--bt-accent)` / `var(--bt-danger)`
- All mono font refs use `var(--bt-font-mono)`

### Desk read fold (`bt-h84-desk`)
- Eyebrow color → `var(--bt-accent)`
- Title uses `var(--bt-font-display)`
- Consistent `border-top/bottom: 1px solid var(--bt-border-2)` separators

### Explore cards (`bt-h84-ecard`)
- Layout changed: horizontal `align-items:center` → vertical column with icon top
- No more `translateY(-2px)` lift on hover — hover is left-border reveal only
- Background hover: `--bt-bg-elev-2` (no filled color)
- Icon container: `rgba(0,255,102,.06)` bg + `rgba(0,255,102,.15)` border
- Arrow: hidden by default, slides in with opacity on hover
- Grid: `gap:20px` individual borders → `gap:1px` shared-background flush grid

### Navbar
- Logo accent: gradient text removed → solid `var(--bt-accent)`
- Active nav link: teal/blue gradient underline → solid neon green bar (no radius)
- Dropdown hover: gradient bg → `rgba(0,255,102,.06)`
- Dropdown active bar: 3px solid `var(--bt-accent)` (no border-radius)

### Asset pages (`bt-av-*`)
- All `#00d4aa` accent references → `var(--bt-accent)`
- Panel border-top accent updated
- Font family refs use CSS variables throughout
- Breadcrumb, eyebrow, meta link colors updated

### Dashboard ticker (`bt-ticker-*`)
- Bar background `#0d1117` → `#000`
- Border `#1e2535` → `var(--bt-border-2)`
- Up/down badge backgrounds updated to new accent/danger palette
- Sym/price fonts → `var(--bt-font-mono)` + `tabular-nums`

---

## News cards (frontend.css)

Layout preserved (cards, not numbered list — intentional).
Visual treatment upgraded:

- `gap:12px` with individual `border-radius:12px` bordered cards
  → `gap:1px` flush grid (shared `--fxlm-border` background creates separator effect)
- Card background `--fxlm-card` (`#121316`), no individual border
- Hover: `translateY(-1px)` + green border → **left-border accent reveal only** (no lift)
- Source badge: filled `rgba(0,212,170,.1)` pill → **outline badge** (1px border, transparent bg)
- Timestamp: plain color → `JetBrains Mono`, `#52525b`, uppercase, letter-spaced
- Breaking badge: border-radius pill → flat with `border:1px solid`

---

## New utility classes added (`revamp-v44.css` appendix)

| Class | Purpose |
|---|---|
| `.bt-btn-primary` | Neon green CTA, hover → white |
| `.bt-btn-secondary` | Outline button, transparent bg |
| `.bt-eyebrow` / `.bt-eyebrow-green` | Mono uppercase section labels |
| `.bt-page-header` | Standard page header with eyebrow + display title |
| `.bt-section` / `.bt-section-inner` | Section wrapper with border separator |
| `.bt-newsletter-*` | Full newsletter section component |
| `.bt-live-badge` / `.fxlm-ticker-live` | Live indicator with pulsing black dot on green |
| `.bt-footer-status` | Pulsing green "DESK ONLINE" footer indicator |
| `.bt-footer-col-head` | Mono uppercase footer column headers |
| `.bt-icon-circle` | Preserved 50% radius for coin/avatar icons |

---

## Font loading (class-cwv.php)

```
Before: Plus Jakarta Sans (400–800) + JetBrains Mono (400–600) + Fraunces (variable)
After:  Chivo (400–900) + IBM Plex Sans (300–700) + JetBrains Mono (300–700)
```

`font-display: swap` preserved for CWV.

---

## Files modified

| File | Change |
|---|---|
| `assets/css/revamp-v44.css` | :root tokens, hero, sections, nav, cards, asset pages, dashboard — full sweep |
| `assets/css/frontend.css` | :root vars, news cards, newsletter form, mobile layout |
| `includes/class-cwv.php` | Google Fonts URL — Chivo + IBM Plex Sans |
| `includes/class-api.php` | Font family string refs updated |
| `includes/class-pwa.php` | Font family string ref updated |
| `fx-live-markets.php` | Version bumped to 117.0.0 |
