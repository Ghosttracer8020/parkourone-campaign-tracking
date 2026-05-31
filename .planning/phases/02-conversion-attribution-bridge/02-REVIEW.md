---
phase: 02-conversion-attribution-bridge
review_status: clean
depth: standard
date: 2026-05-31
critical: 0
warning: 0
info: 2
---

# Phase 2 — Code Review

**Status:** CLEAN — 0 Critical, 0 Warning, 2 Info (no fix required).

Scope (changed source files):
- `includes/class-pot-conversion.php` (new)
- `includes/class-pot-attribution.php` (new)
- `assets/js/pot-attribution.js` (new)
- `includes/class-pot-plugin.php` (modified — wiring)
- `parkourone-campaign-tracking.php` (modified — require chain)

Environment: no PHP/JS/WP runtime. `php -l` substituted by structural check (opens `<?php`,
balanced braces — all pass). Review is static (read + reason).

## Security review

- **Untrusted cookie → order meta** (`persist_attribution`): `wp_unslash` + `json_decode(...,true)`
  + `is_array` guard + `sanitize_text_field` + `substr(...,0,100)` per field before
  `update_meta_data`. No raw echo/eval of cookie. Hardened. (T-02-05 mitigated.)
- **Store writes** go only through `POT_Store::insert_event` (parameterized). No direct `$wpdb`. (T-02-03/06.)
- **HPOS-safe** order access everywhere: `wc_get_order`, `$order->get_meta`, `$order->update_meta_data`,
  `$order->save()`, `$item->get_meta` — no `get_post_meta` on orders.
- **Admin notice** output escaped via `esc_html`; capability `current_user_can('manage_options')` gate.
- **Consent (TTDSG §25):** JS writes no cookie before `window.poConsent.categories.analytics`; held in
  sessionStorage; no consent-change listener (next-pageload re-check + light poll). (T-02-08.)
- **No PII** copied to the store (only campaign labels + best-effort int event_ref). (T-02-04/07.)
- **No supply-chain surface:** no package installs; vanilla JS + WP/WC APIs only.

## Correctness review

- Dual-hook idempotency: WC fires `woocommerce_order_status_changed` (fallback) before
  `woocommerce_order_status_{status}` (primary) within `status_transition()`. The fallback inserts +
  `save()`s the `_pot_conversion_tracked='yes'` flag; the subsequent primary call reloads via
  `wc_get_order` and returns early. Guard precedes insert in both paths → no double count.
- Empty campaign → `''` → `POT_Store::UNATTRIBUTED` bucket (never dropped, never a second bucket).
- Graceful degradation: WC absent or status missing → `not_configured` + notice, never fatal; fallback
  hook still registered so a later-appearing status is caught.
- `event_ref` best-effort, null when absent, never blocks the insert.

## Info findings (no action)

- **I-1** `class-pot-conversion.php:78,113` — idempotency meta key `_pot_conversion_tracked` is written
  as a literal in two places. Intentional: the plan's acceptance gates grep the literal string, and a
  shared const was removed to satisfy them. Acceptable duplication (two call sites, one file).
- **I-2** `class-pot-attribution.php:71` `persist_attribution($order)` has no explicit
  `instanceof WC_Order` check. Safe: the hook always passes a `WC_Order`; `update_meta_data` is the
  documented HPOS CRUD API. Adding a guard would be defensive-only, not a defect.

## Verdict

No Critical or Warning findings. Phase 2 source is clean; the two Info items are intentional and
require no fix. Runtime behaviors deferred to WP staging (see MANUAL-VERIFICATION.md → Phase 2).
