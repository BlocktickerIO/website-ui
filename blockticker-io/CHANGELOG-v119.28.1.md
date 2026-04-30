# Changelog — v119.28.1

## Landing Page Revamp — Full Implementation

### New: Ticker Bar
- Live scrolling price ticker injected at top of landing page
- Scoped under `.btlp` to avoid conflicts with plugin-wide ticker

### New: Full Navigation Bar (replaces `.cp-navbar` on landing page)
- BT logo + wordmark
- Mega-menu dropdowns: Features, Pricing, Resources
- Auth-aware CTAs: logged-out shows "Start Free" + "Sign In"; logged-in shows avatar dropdown with dashboard/logout links
- Mobile drawer (hamburger) with full nav tree
- Scroll-aware: adds `.is-scrolled` class after 60px for background blur

### New: How It Works — Auto-Cycle Design
- 4-step auto-cycling section (5.4 s per step)
- Click progress indicator to jump to any step
- Re-triggers dial + score-bar animations on each step
- Persistent live-indicator strip (signal count, win-rate, latency) below the steps

### Fixes
- Fixed broken `.btlp body[data-auth=…]` selectors (body cannot be a descendant of .btlp — split into two rules)
- Hid `.cp-navbar` on landing page via `body.btlp-page .cp-navbar { display:none }`

### Files Changed
- `includes/class-landing-revamp.php` — added `auth_payload()`, ticker rows, nav URLs, expanded `compact()`
- `templates/landing-revamp.php` — ticker bar + full BT nav + drawer at top; howit section replaced with auto-cycle markup + persistent live strip + progress bars
- `assets/css/landing-revamp.css` — fixed auth selectors, hid .cp-navbar, appended §3 HOWIT auto-cycle block with keyframes
- `assets/js/landing-revamp.js` — rewrote: `howit()` cycle controller, `liveMetrics()` counter+latency, `navScroll()`, `avatarDropdown()`, `drawer()`, `megaA11y()`
