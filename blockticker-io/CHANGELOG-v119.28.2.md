# Changelog — v119.28.2

## Bug Fixes

### Fixed: Credentials page — OAuth Client IDs treated as secrets
The save handler and form-renderer both classified any option key containing
the substring `client_id` as a secret. This caught `bt_google_client_id` and
`bt_github_client_id` — but Client IDs are **public values** (they appear in
the OAuth URL the browser is redirected to). Treating them as secrets meant:

- The input rendered with `value=""` and a "(saved — leave blank to keep)"
  placeholder, even though the value isn't sensitive.
- Operators editing any other field would see their Client IDs appear blank
  on next page load and assume the save had failed — making the entire
  Social Login section feel broken.

**Fix:** removed `client_id` from the secret detector in both
`render_credentials()` (form rendering) and the inline save handler. Client
IDs now display their stored value normally; only `_secret`, `_api_key`, and
`bt_claude_key` remain masked.

### Fixed: AI Review Mode permanently shows "empty" in saved-credentials status
The status-panel "is set" check treated literal `"0"` and `0` as empty. But
`bt_ai_review_mode` is a select with options `"0"` (Auto-publish — the
default) and `"1"` (Pending review). When an operator picked Auto-publish
and saved, the option was correctly written to the DB as `"0"`, but the
status indicator next to "AI Review Mode" perpetually displayed *empty* —
making it look like the save had silently failed.

**Fix:** the status check now uses key-aware logic. For `bt_ai_review_mode`
specifically, "set" means the option row exists (`get_option(false) !== false`).
For all other keys, "set" means the value is neither empty string nor `false`.
Operators now get accurate green/grey indicators that match what the rest of
the plugin actually reads.

### Fixed: Header — nav anchors render in theme accent colour instead of design colour
Most WordPress themes ship a global `a { color: <theme-accent> }` rule. On
the landing page the `Methodology` link and `Login` link are `<a>` tags
while `Markets`/`Analysis`/`Tools`/`Learn`/`News` are `<button>` tags — so
the anchors picked up the theme's green/blue/red accent while the buttons
kept the design's intended grey, leading to a half-coloured navbar that
didn't match the design template.

**Fix:** appended a hardening block to `landing-revamp.css` that pins
`color`, `background`, `text-decoration`, and `font-family` on every anchor
inside `.btlp .nav`, `.btlp .ticker`, and `.btlp .disclaim-strip` with
`!important`. The `!important` is contained to navigation/chrome anchors
only — content prose anchors are unaffected. The navbar now renders
identically across themes.

## Files Changed
- `fx-live-markets.php` — version bump 119.28.0 → 119.28.2 (header + BT_VERSION)
- `includes/class-admin.php` — secret-detector fix (form + save handler), status-panel "is set" logic
- `assets/css/landing-revamp.css` — appended theme-override hardening block for nav/disclaimer anchors
