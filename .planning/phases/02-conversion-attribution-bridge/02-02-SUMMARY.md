# 02-02 SUMMARY — Attribution Bridge (Wave 2)

**Status:** COMPLETE (static-verified; PHP/JS/WP runtime unavailable in this environment)
**Date:** 2026-05-31
**Requirements:** ATTRIB-01, ATTRIB-02, ATTRIB-03 (write side)
**Depends on:** 02-01

## What was built

`assets/js/pot-attribution.js` (NEW) — first-touch UTM capture with consent-gated cookie promotion:

- Strict-mode IIFE; admin-skip via localized `potAttribution.isAdmin`.
- Parses `utm_campaign/utm_source/utm_medium` (verbatim `getParam` helper) + `landing_path =
  location.pathname`.
- **First-touch wins:** if a `pot_attribution` cookie already exists, does nothing.
- **Consent gating (ATTRIB-01):** before `window.poConsent.categories.analytics` is true, the
  first-touch object is held in `sessionStorage` (`pot_attribution_pending`) — NO cookie written.
  On consent it is promoted to the `pot_attribution` cookie (SameSite=Lax, path=/, 90 days, not
  HttpOnly). The consent manager fires NO consent-change event, so there is NO event listener;
  promotion uses on-load check + next-pageload re-check + a light self-clearing `setInterval` poll
  for the same-pageload accept case.

`includes/class-pot-attribution.php` (NEW) — enqueue + checkout bridge:

- `enqueue()` on `wp_enqueue_scripts`: skips logged-in admins (`current_user_can('manage_options')`);
  otherwise `wp_enqueue_script('pot-attribution', POT_PLUGIN_URL . ...)` (no deps, footer) +
  `wp_localize_script('pot-attribution','potAttribution', {isAdmin, cookieName, cookieDays})`.
- `persist_attribution($order)` on `woocommerce_checkout_create_order` (10/1, WC-guarded): no cookie
  → write nothing; `json_decode(wp_unslash($_COOKIE['pot_attribution']), true)` + `is_array` guard;
  each field `sanitize_text_field` + `substr(...,0,100)` length-cap; writes the four order-meta keys
  `_pot_campaign/_pot_source/_pot_medium/_pot_landing` via `$order->update_meta_data`. No
  `$order->save()` in the hook (WooCommerce persists). Malformed cookie logged + ignored, never fatal.

`includes/class-pot-plugin.php` — `POT_Attribution::init()` added after `POT_Conversion::init()`.
`parkourone-campaign-tracking.php` — `require_once` for `class-pot-attribution.php` after the
conversion require and before `github-updater.php` (still last). Plan 01 wiring unregressed.

## Static verification

All Task 1/2/3 acceptance-criteria gates PASS, including: all three UTM params + `location.pathname`,
consent gate on `poConsent.categories.analytics`, sessionStorage stash, SameSite=Lax, NO
consent-change `addEventListener`, cookie read+decode+`is_array` guard, `sanitize_text_field` +
length cap, four meta keys, no save in hook, admin skip. Structural: PHP opens `<?php`, braces 10/10;
JS braces 26/26.

## Deferred (requires WordPress staging)

See root `MANUAL-VERIFICATION.md` → "Phase 2": no-cookie-before-consent, cookie-after-consent,
first-touch-wins, admin-not-enqueued, checkout meta write, end-to-end UTM→cookie→order-meta→
booking.campaign, malformed-cookie no-fatal.
