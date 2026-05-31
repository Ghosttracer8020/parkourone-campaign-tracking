---
phase: 03-consent-gated-client-capture-theme-retirement
status: clean
depth: standard
critical: 0
warning: 0
info: 0
date: 2026-05-31
reviewer: inline (gsd-code-reviewer subagent unavailable in nested context)
---

# Phase 3 — Code Review

**Status: CLEAN — 0 Critical, 0 Warning, 0 Info.**

Scope (phase source files):
- `includes/class-pot-ingest.php` (created)
- `assets/js/pot-tracker.js` (created)
- `includes/class-pot-tracker.php` (created)
- `includes/class-pot-theme-retirement.php` (created)
- `includes/class-pot-plugin.php` (modified — wiring)
- `parkourone-campaign-tracking.php` (modified — require chain)

Note: PHP/Node/WP runtimes are unavailable; review is static (read + targeted greps).
`php -l` substituted by structural checks (opens `<?php`, balanced braces).

## Findings

None.

## Verified correctness / security points

- **Nonce gate is real, never `__return_true`.** `check_nonce()` reads `X-WP-Nonce` header,
  falls back to `_wpnonce` body param (required because `navigator.sendBeacon` cannot set
  headers), and returns `wp_verify_nonce($nonce, 'wp_rest') !== false`. When both sources are
  absent, `$nonce` is `null` → `wp_verify_nonce` returns `false` → request rejected; no PHP
  warning (WP casts internally). Correct fail-closed behavior.
- **No PII stored.** No `$_SERVER['REMOTE_ADDR']`, no IP, no `visitor_hash`, no full UA written.
  The bot check (`preg_match` on UA) yields only a boolean decision; the UA is never persisted.
- **Boundary sanitization is complete.** `type` is both `validate_callback`-constrained to
  {visit,click} AND `sanitize_text_field`'d; campaign/source/medium are text-field + 100-cap;
  `landing_path` is `esc_url_raw` + 500-cap (path stored); `session_id` is alnum-only + 64-cap.
- **All writes via the gateway** `POT_Store::insert_event` — no direct `$wpdb` from the handler.
- **JS consent gate** mirrors `pot-attribution.js` exactly; admin + `navigator.webdriver`
  early-returns precede everything. No beacon/`pot_sid` before consent.
- **Single delegated capture-phase click** with a correct debounce (tracks last href + timestamp,
  ignores identical href within 500ms). `closest('a[href*="/probetraining-buchen"]')` on
  `e.target` is safe (click target is always an Element).
- **Beacon nonce delivery** is correct on both paths: `_wpnonce` in the JSON body for sendBeacon,
  and `X-WP-Nonce` header on the fetch keepalive fallback. Blob content-type is application/json.
- **Theme retirement** dequeues+deregisters `po-analytics-tracker` at priority 99 (after the
  theme's default-10 enqueue), removes `track_basic_pageview` with the SAME singleton instance +
  SAME priority 10, under a `class_exists('PO_Analytics')` guard so it never fatals when the theme
  is absent. `track_purchase` is intentionally left registered. Theme repo (Input/) untouched.
  Option-gated by `pot_retire_theme_tracker` (default true) for code-free rollback.

## Deferred (runtime — see MANUAL-VERIFICATION.md)

Theme-constructor timing (whether `track_basic_pageview` is already registered when
`remove_action` runs at plugins_loaded 11 + wp_enqueue_scripts 99) is a runtime ordering concern,
not a static defect — covered by the deferred parity/cutover checklist.
