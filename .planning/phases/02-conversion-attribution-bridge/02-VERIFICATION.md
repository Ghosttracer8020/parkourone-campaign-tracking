---
phase: 02-conversion-attribution-bridge
status: passed_static
runtime_status: human_needed
date: 2026-05-31
---

# Phase 2 — Conversion & Attribution Bridge — VERIFICATION

**Overall status:** PASSED (static) — runtime behaviors DEFERRED to WordPress staging (human_needed).

Environment has NO PHP runtime, NO Node/browser, NO live WordPress. `php -l` is substituted by a
structural check (file opens `<?php`, balanced braces) — missing php is expected, not a defect.

## Files delivered

| File | Status |
|------|--------|
| `includes/class-pot-conversion.php` | created |
| `includes/class-pot-attribution.php` | created |
| `assets/js/pot-attribution.js` | created |
| `includes/class-pot-plugin.php` | modified (both ::init wired) |
| `parkourone-campaign-tracking.php` | modified (require chain) |

## Static acceptance — all PASS

### Plan 02-01 (conversion listener)
- file exists, ABSPATH guard, `class POT_Conversion`.
- primary hook `woocommerce_order_status_probetraining` (10,1) → `record_conversion`.
- fallback hook `woocommerce_order_status_changed` (10,4) → `on_status_changed`; 4-arg signature;
  guard `$new_status === 'probetraining'`.
- status detect `get_post_status_object('wc-probetraining')`.
- `pot_conversion_status` written autoload=false; never with `true`.
- dismissible `is-dismissible` notice + `esc_html`.
- `record_conversion($order_id)`; HPOS-safe `wc_get_order`; NO `get_post_meta($order...)`.
- **idempotency `_pot_conversion_tracked === 'yes'` precedes `POT_Store::insert_event`** (awk order check).
- `'event_type' => 'booking'`; reads `_pot_campaign`; flag set + `$order->save()`.
- best-effort `_event_id`; `[POT Tracking]` log prefix.
- orchestrator `POT_Conversion::init()`; require precedes github-updater; priority 11 intact.

### Plan 02-02 (attribution bridge)
- JS: file exists, `'use strict'`, all three UTM params, `location.pathname`,
  `poConsent`+`analytics` gate, `sessionStorage`, `pot_attribution`, `SameSite=Lax`,
  **NO consent-change `addEventListener`** (inverse confirmed).
- PHP: file exists, ABSPATH guard, `class POT_Attribution`, `woocommerce_checkout_create_order`
  (10,1) → `persist_attribution`, `wp_enqueue_script('pot-attribution'` + `POT_PLUGIN_URL`,
  admin skip `current_user_can('manage_options')`, `wp_localize_script` + `cookieName`,
  `$_COOKIE['pot_attribution']` + `json_decode` + `is_array`, `sanitize_text_field` + `substr` cap,
  four meta keys `_pot_campaign/_pot_source/_pot_medium/_pot_landing`, NO `$order->save()` in hook.
- orchestrator `POT_Attribution::init()`; require precedes github-updater; plan 01 unregressed.

### Structural (php-lint substitute)
- `class-pot-conversion.php` opens `<?php`, braces 21/21.
- `class-pot-attribution.php` opens `<?php`, braces 10/10.
- `class-pot-plugin.php` opens `<?php`, braces 3/3.
- `pot-attribution.js` braces 26/26.
- `parkourone-campaign-tracking.php` opens `<?php`, braces 4/4 (paren delta is comment text only).

## Key security/integrity invariants confirmed (static)
- **Dual-hook + count-once:** both hooks route to one idempotent core; guard precedes insert.
- **Consent honored:** no cookie before `poConsent.categories.analytics`; no listener (next-pageload re-check).
- **Cookie untrusted-input hardening:** decode + `is_array` + `sanitize_text_field` + 100-char cap.
- **All store writes via `POT_Store::insert_event`** (no direct `$wpdb`).
- **HPOS-safe** order access throughout (`wc_get_order` / order CRUD).
- **Graceful degradation:** never fatal when WC / probetraining status absent.

## Runtime — DEFERRED (requires WordPress staging)
Recorded in root `MANUAL-VERIFICATION.md` → "Phase 2 — Conversion & Attribution Bridge". These
cannot run here (no PHP/WP/WC/HPOS, no browser): one-booking-per-order, idempotent repeat,
free/coupon fallback, not_configured notice + no fatal, end-to-end UTM→cookie→order-meta→
booking.campaign, unattributed bucket, malformed-cookie no-fatal.
