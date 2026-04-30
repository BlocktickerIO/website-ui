# BlockTicker — v119.19.0 (hotfix)

**Released:** 2026-04-25
**Theme:** Multi-bug hotfix — credentials save bug, forecast page styling, LIVE ticker visibility, new-page nav discoverability

## Summary

Multi-bug hotfix release covering five issues Salah reported after deploying
v119.18 to production:

1. **Credentials wiped on save** (data-loss bug, highest priority)
2. **LIVE bar `1 minute ago` timestamp invisible** (UX visibility)
3. **`/forecast/{slug}/` detail pages bare and unstyled** (UX polish)
4. **Recent forecasts pill date invisible** (theme override)
5. **New pages from v119.10–v119.17 not in navigation** (discoverability)

No new features. All five issues fixed in this turn.

## Issue 1 — Credentials wiped on save (data loss)

**Symptom**: User enters API credentials at `/wp-admin/admin.php?page=bt-credentials`,
clicks Save, navigates away, returns later and sees empty values. Even worse:
hitting Save again on the empty-looking form silently overwrites stored
credentials with empty strings.

**Root cause**: The form rendered secret fields with empty `value=""` and a
masked placeholder showing "leave blank to keep". This is a security pattern
(don't leak secrets into HTML). But the save handler did:

```php
foreach ( $fields as $f ) {
    if ( array_key_exists( $f, $_POST ) ) {
        $val = sanitize_text_field( wp_unslash( $_POST[ $f ] ) );
        update_option( $f, $val );  // ← OVERWRITES with empty string
    }
}
```

The `array_key_exists` check is true for every field on the form (every
`<input>` submits its name even when empty). So every Save click overwrote
every secret field with whatever was in the input — which for the standard
"don't re-type" workflow is empty.

The placeholder text "leave blank to keep the current value" was a promise
the save handler didn't keep.

**Fix** (`includes/class-admin.php`):

```php
$is_secret_field = function( $key ) {
    return strpos( $key, '_secret' )   !== false
        || strpos( $key, '_api_key' )  !== false
        || strpos( $key, 'client_id' ) !== false;
};
$kept = 0; $updated = 0;
foreach ( $fields as $f ) {
    if ( ! array_key_exists( $f, $_POST ) ) continue;
    $val = sanitize_text_field( wp_unslash( $_POST[ $f ] ) );
    if ( $val === '' && $is_secret_field( $f ) ) { $kept++; continue; }
    update_option( $f, $val );
    $updated++;
}
echo '<div class="notice notice-success is-dismissible"><p>' .
    esc_html( "Credentials saved. {$updated} updated" .
        ( $kept ? ", {$kept} kept (left blank)" : '' ) . '.' ) .
    '</p></div>';
```

Detection logic mirrors the render-side `$is_secret` check (substring match
on `_secret`/`_api_key`/`client_id`), so save-side and render-side behaviour
stay in lockstep — change one, change both.

The success notice now reports `"X updated, Y kept (left blank)"` so the
operator can see at a glance that empty submissions were preserved rather
than silently dropped.

**Recovery for affected installs**: any credentials that got wiped must be
re-entered after upgrading. Check `/wp-admin/admin.php?page=bt-credentials`
— the masked placeholders show what's currently saved (empty placeholder = no
saved value). Anthropic Claude key and CoinMarketCap key are the most
operationally critical; CoinGecko works without a key on the free tier.

## Issue 2 — LIVE bar timestamp invisible

**Symptom**: The `1 minute ago` text on the right side of the live forex
ticker bar (next to USD/CAD, USD/CHF, etc.) was invisible against the dark
background.

**Root cause**: `.fxlm-ticker-ts` had `color: var(--bt-text-3)` at
`font-weight: 400` and `font-size: 10px` — combination produced contrast
below WCAG minimum on the bar's dark background.

**Fix** (`assets/css/revamp-v44.css`):

```css
.fxlm-ticker-wrap .fxlm-ticker-ts {
    flex-shrink: 0;
    padding: 0 14px;
    font: 600 11px/1 var(--bt-font-mono);  /* was 400 10px */
    color: var(--bt-text-2, #d4d4d4);      /* was --bt-text-3 */
    letter-spacing: .04em;
    opacity: .9;
}
```

Freshness signals on a live data widget must be readable — if you can't see
when the last refresh was, you can't trust the data.

## Issue 3 — Forecast detail pages bare/unstyled

**Symptom**: Pages like `/forecast/btc/` rendered as plain text on dark
background — no visual hierarchy beyond bold headings, no card framing,
sections (`Market Snapshot`, `24-Hour Outlook`, `7-Day Context`,
`Methodology`) all blurred together.

**Root cause**: The forecast body is `wp_kses_post`-rendered HTML from
either a template or an AI prompt. The CSS only styled the outer container
(`.bt-forecast-body`) — the h2/h3/p elements inside got default theme
styling, which on the dark frontend was just bold text.

**Fix**: Appended a 23-rule CSS module to `revamp-v44.css` using
adjacent-sibling selectors so each `h2`-led section gets a card-frame
without requiring the renderer to wrap each section in a div:

```css
.bt-forecast-body h2,
.bt-forecast-body h3 {
    font: 800 18px/1.2 var(--bt-font-display);
    padding: 16px 24px 14px;
    background: var(--bt-bg-elev);
    border: 1px solid rgba(255, 255, 255, .08);
    border-bottom: 0;
    border-left: 3px solid var(--bt-accent);  /* h2 = green accent */
}
.bt-forecast-body h3 {
    border-left-color: var(--bt-accent-warm); /* h3 = warm accent */
}
.bt-forecast-body h2 + p,
.bt-forecast-body p,
.bt-forecast-body ul {
    padding: 16px 24px;
    background: var(--bt-bg-elev);
    border-left: 1px solid rgba(255, 255, 255, .08);
    border-right: 1px solid rgba(255, 255, 255, .08);
    /* …completes the card frame around content */
}
```

Each section now reads as a distinct card with an accent left bar, just
like the dashboard widgets and Top-N pages. Lists get accent-coloured
markers (`li::marker { color: var(--bt-accent); }`).

The trade-off: this relies on the body being heading-led (every section
starts with h2/h3). Bodies that are pure paragraphs degrade gracefully —
they get card-frame but no per-section separation.

## Issue 4 — Recent forecasts pill date invisible

**Symptom**: The "RECENT FORECASTS" pill at the bottom of `/forecast/btc/`
showed as a green block with no visible date inside.

**Root cause**: The pill's text colour was `#000` inline, but the active
theme had a higher-specificity rule on `a` that overrode the inline color.

**Fix**: Added `.bt-forecast-detail a[href*="/forecast/"] { color: inherit; }`
which forces the pill text to follow the inline `color:#000` declaration
again because the `inherit` walks back up to the `<a>` element's own inline
style.

## Issue 5 — New pages not in navigation

**Symptom**: Pages added across v119.10 → v119.17 (`/forecast/`, `/top/`,
`/performance/`, `/dashboard/`, `/screeners/`, `/following/`) had no entry
points from the public navigation. Users couldn't find them without typing
URLs directly.

**Fix** (`includes/class-navbar.php`): Added entries to existing dropdowns
rather than creating new top-level nav items (which would have cluttered
the navbar):

| Dropdown | Added |
|---|---|
| **Crypto** | 📊 Top Lists → `/top/` |
| **Analysis** | 🔮 Forecasts → `/forecast/`, 📈 Track Record → `/performance/` |
| **Tools** | 🏠 My Dashboard, 🔎 Screeners, ★ Following |
| **User menu** (logged-in only) | 🏠 My Dashboard, 🔎 My Screeners, ★ Following, 🔔 Alerts |

The personalized pages (Dashboard, Screeners, Following) appear in *both*
the Tools dropdown (so logged-out users discover them and get the upsell
flow) *and* the user menu (so logged-in users have a one-click path).

**Note**: nav edits are inline in `class-navbar.php`, not via the
`bt_menu_config` admin-configurable system. The Menu Configurator only
controls the flat fallback menu; the top-level dropdowns have always been
hardcoded for design control. Long-term these should be admin-configurable
too, but that's a v120 refactor.

## Files changed

| File | Change |
|---|---|
| `includes/class-admin.php` | Credentials save handler skips empty values for secret fields; reports updated/kept counts |
| `includes/class-navbar.php` | New page links added to Crypto, Analysis, Tools dropdowns + user menu |
| `assets/css/revamp-v44.css` | LIVE ticker timestamp contrast bump; 23-rule forecast detail page CSS module appended |
| `fx-live-markets.php` | Version → 119.19.0 (header + `BT_VERSION`) |
| `docs/ROADMAP.md` | 5 v119.19 decision-log entries (credentials fix, forecast styling, ticker visibility, nav discoverability, decision rationale) |
| `CHANGELOG-v119.19.md` | NEW |

No new PHP files. No schema changes. No new shortcodes.

## Validation

- `class-admin.php` parses clean via phply
- `class-navbar.php` parses clean via phply
- CSS brace balance: 2938/2938 (23 rules added)
- Version constants verified
- All 6 navigation additions verified via grep
- Credentials fix marker (`v119.19 fix: secret fields`) verified present
- LIVE ticker fix marker (`v119.19 fix: was var(--bt-text-3)`) verified present

## Verifying after deploy

1. **Credentials**: visit `/wp-admin/admin.php?page=bt-credentials`, observe
   placeholder text on saved fields. Click Save without typing — success
   notice should say `"X updated, Y kept (left blank)"` and the placeholder
   should still show the masked saved value.
2. **LIVE bar**: front-end of any page — the `1 minute ago` text on the
   right of the forex ticker should now be readable.
3. **Forecast page**: visit `/forecast/btc/` — sections should be
   card-framed with accent left bars, recent-forecasts pill text visible.
4. **Navigation**: hover Crypto → see "Top Lists"; hover Analysis → see
   "Forecasts" + "Track Record"; hover Tools → see "Dashboard / Screeners
   / Following"; sign in and check the user dropdown.

## Roadmap

No roadmap status changes — pure hotfix release. v119.17's "Following list
✅" remains the most recent feature; the eight-release acquisition→retention
chain is unchanged.

## Next-up unblocked items (unchanged from v119.17)

1. **🟡 Personalized AI brief based on watchlist holdings** — Phase 2's flagship
2. **🟡 Browser push notifications** — completes alerts triangle
3. **🟡 Mobile search overlay** — v119.14 follow-up
4. **🟡 Pull-to-refresh on data pages** — v119.14 follow-up
5. **🟡 Rich snippets audit** — Search Console verification
6. **🟡 Dashboard v2** — drag-drop deferred
7. **🟡 Screener v2** — cron alerts on screener results
8. **🟡 Following v2** — tags + per-source notification toggles
