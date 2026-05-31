# 02-01 SUMMARY — Conversion Listener (Wave 1)

**Status:** COMPLETE (static-verified; PHP/WP runtime unavailable in this environment)
**Date:** 2026-05-31
**Requirements:** CONVERT-01, CONVERT-02, CONVERT-03, CONVERT-04, ATTRIB-03, ATTRIB-04

## What was built

`includes/class-pot-conversion.php` (NEW) — server-side, consent-INDEPENDENT Probetraining
conversion listener:

- **Dual-hook registration** (Task 1): `woocommerce_order_status_probetraining` (primary, 10/1)
  routing to `record_conversion`, and `woocommerce_order_status_changed` (fallback, 10/4) routing
  to `on_status_changed` which forwards only `$new_status === 'probetraining'`. The fallback
  catches the free / 100%-coupon priority-999 redirect path (CONVERT-02).
- **Graceful degradation** (CONVERT-04): WooCommerce inactive → `pot_conversion_status=not_configured`
  (autoload=false), no WC hooks, no fatal. WC active but status missing → `not_configured` + a
  dismissible `notice-warning is-dismissible` German admin notice (esc_html); the fallback hook is
  still registered so a later-appearing status is caught.
- **Idempotent core** (Task 2, CONVERT-01/03): HPOS-safe `wc_get_order($order_id)` load (never
  `get_post_meta` on the order); the `_pot_conversion_tracked === 'yes'` early-return appears BEFORE
  the `POT_Store::insert_event` call (awk ordering check passes); on success the flag is set via
  `update_meta_data('_pot_conversion_tracked', 'yes')` + `$order->save()`.
- **Attribution + bucketing** (ATTRIB-03/04): reads `_pot_campaign/_pot_source/_pot_medium/_pot_landing`
  from order meta; empty campaign flows to `POT_Store::UNATTRIBUTED` (never a second bucket, never
  dropped). `event_ref` resolved best-effort from order item `_event_product_id` → product `_event_id`,
  null when absent, never blocking the insert. Failure paths log `[POT Tracking] ...`.

`includes/class-pot-plugin.php` — `POT_Conversion::init()` added to the flat orchestrator list
(after `POT_Admin::init()`). `parkourone-campaign-tracking.php` — `require_once` for
`class-pot-conversion.php` added before `github-updater.php` (which stays last); `plugins_loaded`
priority 11 unchanged.

## Static verification

All Task 1/2/3 acceptance-criteria grep + awk gates PASS. Structural PHP check: opens `<?php`,
braces balanced 21/21. `php -l` substituted by structural check (php runtime absent — not a defect).

## Deferred (requires WordPress staging)

See root `MANUAL-VERIFICATION.md` → "Phase 2". Runtime behaviors (one booking row per probetraining
order, idempotent repeat, free/coupon fallback, not_configured notice, unattributed bucket) cannot
run without live WP/WC/HPOS — recorded, not blocking.
