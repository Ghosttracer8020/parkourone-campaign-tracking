# Plan 05-02 Summary — Managed Secret UI

**Status:** Complete (static-verified; runtime deferred to staging)
**Requirements:** API-04

## What was built
- `includes/class-pot-api-settings.php` (new) — `POT_Api_Settings`: submenu page "API / Statusboard" under the `parkourone` menu (cap `manage_options`, prio 999, graceful no-op if the parent menu is absent). Shows the endpoint URL (`rest_url('pot/v1/metrics')`) and the API Bearer secret MASKED by default (first 4 chars + dots), with a "Anzeigen / Kopieren" reveal button (hidden readonly field). A "Secret neu generieren" form posts to `admin-post.php` (`action=pot_regenerate_secret`).
- `handle_regenerate()` — rotates `pot_api_secret` via `update_option(..., wp_generate_password(32, false), false)` (autoload stays false), gated by `current_user_can('manage_options')` + `check_admin_referer('pot_regenerate_secret')`, then `wp_safe_redirect` with a success notice. Secret value is never logged.
- `includes/class-pot-plugin.php` — wired `POT_Api_Settings::init()` into `pot_init()`.
- `parkourone-campaign-tracking.php` — added the require for `class-pot-api-settings.php` (github-updater require stays LAST).
- `uninstall.php` — added `delete_option('pot_api_secret')` so the credential is removed on uninstall.

## Static verification (no PHP/WP runtime here)
- Structural lint: file opens `<?php`, braces balanced (9/9).
- `wp_generate_password(32, false)` present; `pot_api_secret` written with autoload `false`.
- Regenerate guarded by `check_admin_referer` + `current_user_can('manage_options')`.
- Endpoint URL shown via `rest_url`; all output escaped (`esc_html`/`esc_attr`/`esc_url`).
- Uninstall deletes `pot_api_secret`.

## Deferred to WP staging (manual)
See `MANUAL-VERIFICATION.md` → "Phase 5".
