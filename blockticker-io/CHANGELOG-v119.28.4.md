# Changelog — v119.28.4

## Five visual + UX fixes to match the reference template

The v119.28.3 fix successfully removed the old `cp-navbar` from the landing
page (verified by fetching the live HTML). But the result still didn't
match the reference template in five ways — fixed in this release.

### 1. Disclaimer strip is now ABOVE the ticker (was below the nav)

The reference template puts the "Not financial advice" strip at the very
top of the page, then the ticker, then the nav. v119.28.1 had the order
inverted. **Reordered** in `templates/landing-revamp.php`.

### 2. Disclaimer text matches the reference

| | Before | After |
|---|---|---|
| **Body** | "All analysis is automated from live market data and reviewed for clarity, not interpretation. Do your own research before trading." | "All content is educational. Trading involves risk of loss. Past performance does not guarantee future results." |
| **Link** | "View methodology →" | "View methodology & risk →" |

### 3. NEW: Risk-acknowledgment modal ("Before you continue")

A first-visit gate that surfaces the risk disclosure prominently. Uses
`localStorage['btlp.riskAck.v1']` to remember acceptance — never
re-triggers for the same browser. Three exits:

- **"I understand & continue"** (primary button) → dismiss + persist
- **"Read methodology first"** (ghost link) → navigate to /#methodology
- **ESC key or backdrop click** → dismiss without persisting

Accessibility: proper `role="dialog"`, `aria-modal="true"`,
`aria-labelledby` + `aria-describedby`, and focus moves into the modal on
open. Reduced-motion users get instant transitions.

### 4. NEW: Admin-only "PREVIEW: Logged out / Logged in" toggle

The reference template includes this dev-tool pill at the right end of
the navbar, used to flip between the two auth-state designs without
logging in/out. **Now PHP-gated by `current_user_can('manage_options')`**
so it only renders for admins — regular users never see it. Hidden on
mobile (≤980px) to make room for the hamburger.

When clicked, JS flips `data-auth` on `.btlp` and toggles
`[data-show-when="logged-in/out"]` elements in real time.

### 5. Killed the cursor-trail dot leak near the BLOCKTICKER logo

The "blueprint dots" / floating particles visible in screenshot 1 near
the logo were the global `#fxlm-cursor-trail` canvas
(`assets/js/patch-animations.js`) — a sparkle-cursor effect that follows
the mouse at z-index 9999997. Other plugin pages keep it; the landing
page hides it with:

```css
body.bt-landing-page         #fxlm-cursor-trail,
body.page-slug-landing-revamp #fxlm-cursor-trail,
body:has(.btlp)              #fxlm-cursor-trail { display: none !important; }
```

## Files Changed
- `fx-live-markets.php` — version bump 119.28.3 → 119.28.4
- `templates/landing-revamp.php` — disclaimer reordered + new text + risk-modal markup + admin preview-toggle markup
- `assets/css/landing-revamp.css` — modal styles, preview-toggle styles, cursor-trail hide rules
- `assets/js/landing-revamp.js` — `riskModal()` controller (localStorage, ESC, backdrop) + `previewToggle()` controller

## After deploying

1. Hard-refresh (`Ctrl+F5` / `Cmd+Shift+R`)
2. Purge `/landing-revamp` from Cloudflare / your CDN
3. The risk modal will fire the first time you visit; click "I understand
   & continue" once and it won't fire again on that browser.
4. If you're an admin, you'll also see the **PREVIEW** pill on the right
   of the navbar — click "Logged out" / "Logged in" to flip the design
   in real time. Regular visitors don't see this.
5. To re-trigger the risk modal during testing, run in the browser
   console: `localStorage.removeItem('btlp.riskAck.v1')` and reload.
