# BlockTicker v96.7.0 — WCAG 2.1 AA Accessibility

**Release date:** April 2026
**Type:** Accessibility release (additive + one targeted CSS patch, no breaking changes)
**Upgrade path:** Drop-in over v96.6.0

---

## Overview

The ChatGPT audit scored BlockTicker's accessibility at **40/100** — the
worst score of any audit category.  v96.7 targets all AA-level failures:

| Failure | WCAG criterion | Fix |
|---------|---------------|-----|
| `--bt-text-3: #64748b` fails AA on all backgrounds | 1.4.3 Contrast (Minimum) | Promoted to `#7a8aaa` (5.54:1) |
| `#475569` as text fails AA (2.54:1) | 1.4.3 | Overridden to `#7a8aaa` throughout |
| `outline: none !important` on 3 form element groups | 2.4.7 Focus Visible | Overridden via `:focus-visible` specificity |
| No skip-to-content link | 2.4.1 Bypass Blocks | Injected at `wp_body_open` |
| Price updates not announced to screen readers | 4.1.3 Status Messages | `aria-live="polite"` region + MutationObserver |
| Market tables not keyboard-navigable | 2.1.1 Keyboard | Arrow-key grid nav via `role="grid"` |
| No table captions | 1.3.1 Info and Relationships | SR-only captions injected by JS |
| Animations cannot be stopped | 2.3.3 / 2.2.2 | `prefers-reduced-motion` CSS + JS kill switch |
| No `lang` attribute guarantee | 3.1.1 Language of Page | `language_attributes` filter as fallback |
| No Windows High Contrast support | Best practice | `forced-colors: active` block |

---

## New: `assets/css/a11y.css`

Loaded last (`wp_enqueue_style` priority 99999, depends on `fxlm-revamp-v44`).
All rules are additive overrides — no existing stylesheet is modified.

### Contrast fixes (§3 of a11y.css)

```css
:root {
    --bt-text-3:    #7a8aaa !important;  /* was #64748b — 4.05:1 fail */
    --bt-text-muted:#7a8aaa !important;  /* was #475569 — 2.54:1 fail */
}
```

Verified contrast ratios for `#7a8aaa`:

| Background | Contrast | Result |
|-----------|----------|--------|
| `#0a0e1a` (base bg)   | 5.54:1 | ✅ AA |
| `#111827` (elevated)  | 5.11:1 | ✅ AA |
| `#1a2235` (elevated 2)| 4.56:1 | ✅ AA |

Hardcoded `#475569` instances (10 distinct selectors: placeholders,
`.bt-star`, `.bt-tok-addr-icons`, vol display cells, etc.) are overridden
by targeted rules.

### Focus-visible ring (§2 of a11y.css)

```css
a:focus-visible,
button:focus-visible,
input:focus-visible,
/* … 11 selectors total … */
[role="menuitem"]:focus-visible {
    outline: 3px solid #00d4aa !important;  /* 10.08:1 on dark bg ✅ */
    outline-offset: 3px !important;
}
```

The three `outline: none !important` blocks in `revamp-v44.css` are
defeated by adding `:focus-visible` to the selector chain (pseudo-class
specificity +0,1,0 beats the blanket rule).

### prefers-reduced-motion (§6)

```css
@media (prefers-reduced-motion: reduce) {
    *, *::before, *::after {
        animation-duration: 0.001ms !important;
        transition-duration: 0.001ms !important;
    }
}
```

All plugin animations (hero ticker, card reveals, price flash, fade-up)
stop instantly when the OS reports reduced-motion preference.

### prefers-contrast: more (§7)

Full dark-palette override with boosted contrast when the browser signals
a high-contrast user preference.

### forced-colors: active (§8)

Windows High Contrast Mode handled — custom colours yield to the system
palette, focus rings use the `Highlight` system colour.

---

## New: `assets/js/a11y.js`

Deferred, no jQuery dependency (~200 lines).

### Keyboard-navigable market tables

Every `.fxlm-table`, `.bt-cat-table` and `.fxlm-forex-table` is upgraded
to `role="grid"` and receives:

- `scope="col"` on every `<th>` (info-and-relationships, 1.3.1)
- `tabindex="0"` on `<th>` cells (keyboard access)
- `tabindex="-1"` on all `<td>` cells (roving tabindex pattern)
- Arrow-key navigation: Up/Down moves rows, Left/Right moves columns,
  Home/End jump to first/last cell, Ctrl+Home/End jump to table corners
- `.bt-sort-icon` span injected into sortable headers (visual + SR sort indicator)

### aria-live price announcements

`MutationObserver` watches price cells (`#fxlm-btc-price`, `#fxlm-eth-price`,
`.bcp-price`, `[data-live-price]`) and posts debounced (3-second) updates
to the `#bt-a11y-live` region. Screen readers announce e.g.
*"BTC updated to $67,234"* on idle without interrupting user flow.

### Escape key

Single global listener closes any open navbar dropdown, mobile menu,
or `[data-bt-modal]` element when Escape is pressed, returning focus
to the triggering element.

### Focus trap

Tab-cycle is contained within the language dropdown and mobile menu
when they are open (prevents keyboard focus from escaping to behind-panel
content that isn't visible).

### Table captions

SR-only `<caption>` elements injected on the three main tables:
"Live Cryptocurrency Prices", "Live Forex Exchange Rates",
"Market Sentiment Scores".

### Reduced-motion JS guard

Hero ticker rotation stopped; `data-count-up` counters set to final
value immediately when `prefers-reduced-motion: reduce` is active.

---

## New: `includes/class-a11y.php`

`BT_A11y` handles the PHP side.

### Skip-to-content link

```html
<a href="#bt-main-content" id="bt-skip-link" class="bt-sr-only bt-sr-only-focusable">
  Skip to main content
</a>
```

Injected at `wp_body_open` (priority 1) — before the navbar, before
everything else. Visually hidden off-screen until keyboard-focused, at
which point it slides into view (`top: 0`) with a 3px outline.
Fallback JS path for themes that don't call `wp_body_open`.

The skip-link target `#bt-main-content` is assigned in `wp_footer` (priority 5)
via a tiny inline script that finds the active theme's main content wrapper
by probing common IDs (`content`, `primary`, `main`, `site-content`) and
falls back to the first `<main>` element.

### aria-live region

```html
<div id="bt-a11y-live" role="status" aria-live="polite"
     aria-atomic="true" aria-relevant="additions text"></div>
```

Injected at `wp_body_open` (priority 2). Visually hidden via `a11y.css`.

### lang attribute filter

`language_attributes` filter re-adds `lang="en"` (or site locale) if
the active theme has stripped it.

### Admin panel

`BT_A11y::admin_panel_html()` renders a ♿ WCAG 2.1 AA Status panel in
the BlockTicker → 🗄 Database screen.

---

## `fx-live-markets.php`

- Version → 96.7.0
- `require_once FXLM_DIR . 'includes/class-a11y.php'`
- `add_action( 'plugins_loaded', array( 'BT_A11y', 'init' ) )`
- `wp_footer` (priority 5) skip-link target ID assignment script

---

## Safety & rollback

1. **Additive only.** No existing PHP, JS or CSS is deleted.
2. `a11y.css` loaded at priority 99999 — only overrides when an element
   matches one of its selectors.  Custom child-theme CSS at equal or
   higher priority still wins.
3. No new DB tables, cron events, or option keys.

### Rollback

Reinstall v96.6.0 — no data to clean up.

---

## PHP lint

- `includes/class-a11y.php` ✅
- `fx-live-markets.php` ✅

---

## Audit scorecard (estimated post-v96.7)

| Category | Before | After |
|----------|--------|-------|
| SEO | 50/100 | ~80/100 (v96.5) |
| Core Web Vitals | 60/100 | ~80/100 (v96.6) |
| Accessibility | 40/100 | ~85/100 (v96.7) |

Remaining accessibility gaps (AAA / edge cases):
- Live chart canvas elements need `<title>` + `<desc>` for non-sighted users
  (addressed in v97.x with the canvas-to-table data export feature)
- PDF report downloads need tagged PDF structure
- User-auth flows need automated WCAG audit with axe-core

---

## What's next

- **v97.0** — `FXLM_` → `BT_` constant and class rename. Breaking change;
  all existing options keys kept as aliases for 6 months. Own session.
