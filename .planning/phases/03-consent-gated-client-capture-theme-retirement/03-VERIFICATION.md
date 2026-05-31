---
phase: 03-consent-gated-client-capture-theme-retirement
status: passed_static
runtime_status: human_needed
date: 2026-05-31
---

# Phase 3 — Consent-Gated Client Capture & Theme Retirement — VERIFICATION

**Overall status:** PASSED (static) — runtime behaviors DEFERRED to WordPress staging (human_needed).

Environment has NO PHP runtime, NO Node/browser, NO live WordPress. `php -l` is substituted by a
structural check (file opens `<?php`, balanced braces) — missing php is expected, not a defect.

## Files delivered

| File | Status |
|------|--------|
| `includes/class-pot-ingest.php` | created |
| `assets/js/pot-tracker.js` | created |
| `includes/class-pot-tracker.php` | created |
| `includes/class-pot-theme-retirement.php` | created |
| `includes/class-pot-plugin.php` | modified (3 ::init wired) |
| `parkourone-campaign-tracking.php` | modified (require chain) |

## Static acceptance — all PASS

### Plan 03-01 (client capture)
- `class-pot-ingest.php`: ABSPATH guard; `register_rest_route('pot/v1', '/event'` on `rest_api_init`;
  real nonce gate (`wp_verify_nonce` + `X-WP-Nonce` header + `_wpnonce` body fallback);
  **`__return_true` ABSENT** (non-comment count 0); admin guard `current_user_can('manage_options')`;
  bot denylist `preg_match(/bot|crawl|spider.../i)`; write via `POT_Store::insert_event`;
  **no IP/UA storage** (`REMOTE_ADDR|visitor_hash` non-comment count 0); `type` validated ∈ {visit,click}.
- `pot-tracker.js`: `poConsent`+`analytics` gate; `isAdmin` skip; `navigator.webdriver` early-return;
  `navigator.sendBeacon`; `keepalive` fetch fallback; `_wpnonce` in body; capture-phase delegated click
  `addEventListener('click', handler, true)`; robust selector `a[href*="/probetraining-buchen"]`;
  `pot_attribution` cookie read; `pot_sid` session key; **theme key `po_analytics_sid` ABSENT** (count 0).
- `class-pot-tracker.php`: ABSPATH guard; admin skip; `add_action('wp_enqueue_scripts'`;
  `wp_enqueue_script('pot-tracker'`; `wp_localize_script('pot-tracker'` + `rest_url('pot/v1/event')`
  + `wp_create_nonce('wp_rest')`.
- Wiring: `POT_Ingest::init();` + `POT_Tracker::init();` in `pot_init()`; both requires precede
  github-updater.php which stays LAST.

### Plan 03-02 (theme retirement)
- `class-pot-theme-retirement.php`: ABSPATH guard; option gate `pot_retire_theme_tracker` (default true);
  `wp_dequeue_script('po-analytics-tracker')` + `wp_deregister_script('po-analytics-tracker')`;
  dequeue hooked at priority **99**; `remove_action('wp_footer', ..., 10)` of `track_basic_pageview`;
  `class_exists('PO_Analytics')` guard + `PO_Analytics::get_instance()`;
  **`track_purchase` NOT removed** (non-comment count 0); theme repo (Input/) untouched.
- Wiring: `POT_Theme_Retirement::init();` in `pot_init()`; require precedes github-updater.php (still last);
  Plan 01 wiring unregressed.

### Structural (php-lint substitute)
- `class-pot-ingest.php` opens `<?php`, braces 11/11.
- `class-pot-tracker.php` opens `<?php`, braces 5/5.
- `class-pot-theme-retirement.php` opens `<?php`, braces 6/6.
- `class-pot-plugin.php` opens `<?php`, braces 3/3.
- `parkourone-campaign-tracking.php` opens `<?php`, braces 4/4.
- `pot-tracker.js` braces 30/30, parens 60/60.

## Key security/integrity invariants confirmed (static)
- **Real nonce gate, never `__return_true`:** `wp_verify_nonce($nonce,'wp_rest')` with header + body fallback
  (sendBeacon cannot set headers).
- **No PII:** no `$_SERVER['REMOTE_ADDR']`, no IP, no `visitor_hash`, no full UA stored — bot check is boolean only.
- **Consent honored client-side:** entire tracker gated behind `window.poConsent.categories.analytics`;
  no beacon, no `pot_sid` before consent.
- **Admin excluded at 3 layers:** enqueue, JS early-return, REST handler 204.
- **Capture-phase single delegated click** + robust selector + ~500ms debounce.
- **Single-tracker cutover:** dequeue at priority 99 (after theme's 10) + `remove_action` of the
  fallback writer, under `class_exists` guard, gated by a toggleable option; `track_purchase` left intact.
- **All store writes via `POT_Store::insert_event`** (no direct `$wpdb`).
- **Theme repo never edited** — the plugin inverts the theme's registrations from outside.

## Runtime — DEFERRED (requires WordPress staging)
Recorded in root `MANUAL-VERIFICATION.md` → "Phase 3 — Client Capture & Theme Retirement". These cannot
run here (no PHP/WP, no browser): one-visit-beacon-per-load incl. cached page, debounced single CTA click,
consent-off no-beacon/no-cookie, admin/bot exclusion + REST reject, post-cutover theme silence + parity
shadow-window comparison before flipping `pot_retire_theme_tracker`, `track_purchase` still writing.
