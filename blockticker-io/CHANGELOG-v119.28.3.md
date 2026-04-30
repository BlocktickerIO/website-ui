# Changelog — v119.28.3

## Critical Hotfix — Landing page chrome was visible

After deploying v119.28.2, the live site at `/landing-revamp` still showed:

1. The old plugin `cp-navbar` rendered at the top of the page
2. The theme's "Landing Revamp" H1 page-title between the cp-navbar and our ticker
3. The new BT nav appearing third, looking out of place

The v119.28.1/.2 hide rules keyed off `body.page-slug-landing-revamp`,
which is only added when `is_page('landing-revamp')` returns true. On the
live site that condition wasn't met — the page slug differs, or the theme
strips body classes, or both. So **none of our overrides were applying**
and the user reported "nothing changed".

### Fix — three independent paths to mark the body

1. **Inline script in the template** (`<script>...add('bt-landing-page')</script>`)
   — runs synchronously during HTML parse, **before** any chrome paints.
   This is the primary path and works regardless of theme behaviour.

2. **JS re-assert at top of `landing-revamp.js`** — idempotent fallback for
   caching / async edge cases where the inline script ran before
   `document.body` existed.

3. **PHP body_class filter is now shortcode-aware** — uses
   `has_shortcode($post->post_content, 'blockticker_landing')` instead of
   only `is_page('landing-revamp')`. Adds both `bt-landing-page` (new
   canonical name) and `page-slug-landing-revamp` (legacy back-compat).

### CSS now hides via three selector groups

```
body.bt-landing-page         (primary — set by inline script + JS + PHP)
body.page-slug-landing-revamp (legacy)
body:has(.btlp)              (modern-browser fallback, no class needed)
```

So even if every body-class mechanism fails, the modern `:has()` selector
catches it: wherever `.btlp` exists in the DOM, the cp-navbar, theme
page-title H1, and theme content padding all get hidden.

### Theme page-title H1 also hidden

The "Landing Revamp" heading rendered by the theme's `the_title()` is now
suppressed via the same selector groups, covering common page-title
classes shipped by Twenty\*, Astra, GeneratePress, Kadence, Cornerstone,
Hello, Blocksy, OceanWP, and underscores-based starters:

```
.entry-title, .page-title, .post-title,
header.entry-header, header.page-header,
.wp-block-post-title, h1.wp-block-post-title,
.page-hero, .single-featured-page-header
```

## Files Changed
- `fx-live-markets.php` — version bump 119.28.2 → 119.28.3
- `includes/class-landing-revamp.php` — `add_body_class()` now shortcode-aware
- `templates/landing-revamp.php` — inline `<script>` at top to set body class immediately
- `assets/js/landing-revamp.js` — body-class re-assertion at top of IIFE
- `assets/css/landing-revamp.css` — selectors broadened with `:has(.btlp)` fallback + theme page-title hide rules

## After deploying

Hard-refresh (`Ctrl+F5` / `Cmd+Shift+R`) — both CSS and JS are versioned by
`BT_VERSION`, so once you deploy 119.28.3, the new asset URLs include
`?ver=119.28.3` and bypass any browser/CDN cache automatically. If you're
behind Cloudflare, also purge `/landing-revamp` from the CF cache.
