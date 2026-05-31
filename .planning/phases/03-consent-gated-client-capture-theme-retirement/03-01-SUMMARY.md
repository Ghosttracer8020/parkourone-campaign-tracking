---
phase: 03-consent-gated-client-capture-theme-retirement
plan: 01
subsystem: api
tags: [rest-api, wp-nonce, sendbeacon, consent, dsgvo, tracking]

requires:
  - phase: 01-plugin-foundation-events-store
    provides: POT_Store::insert_event gateway + pot_events schema
  - phase: 02-conversion-attribution
    provides: pot-attribution.js consent/admin/cookie patterns; pot_attribution cookie
provides:
  - "POT_Ingest — POST pot/v1/event dynamic REST route with real WP-REST nonce gate (header + body fallback)"
  - "pot-tracker.js — consent/admin/bot-gated visit beacon + debounced capture-phase CTA click listener"
  - "POT_Tracker — admin-skipped enqueue + wp_localize_script of restUrl/nonce/isAdmin"
affects: [phase-04-dashboard, phase-05-pull-api, theme-retirement]

tech-stack:
  added: []
  patterns:
    - "Client beacon → dynamic REST route (cache-proof visit counting)"
    - "Nonce in X-WP-Nonce header with _wpnonce body fallback (sendBeacon cannot set headers)"

key-files:
  created:
    - includes/class-pot-ingest.php
    - assets/js/pot-tracker.js
    - includes/class-pot-tracker.php
  modified:
    - includes/class-pot-plugin.php
    - parkourone-campaign-tracking.php

key-decisions:
  - "Nonce permission_callback (never __return_true) with _wpnonce body fallback — sendBeacon cannot set headers"
  - "Bot UA check yields only a boolean; no IP/UA ever stored (DSGVO)"
  - "Visits/clicks are consent-gated client-side; conversions stay server-side/consent-independent (Phase 2)"

patterns-established:
  - "Capture-phase delegated click via document.addEventListener('click', handler, true) + closest(selector)"
  - "Plugin owns its session key pot_sid (never reuse theme's session key)"

requirements-completed: [CAPTURE-01, CAPTURE-02, CAPTURE-03, CAPTURE-04, CAPTURE-05]

duration: 12min
completed: 2026-05-31
---

# Phase 3 (Plan 01): Consent-Gated Client Capture Summary

**Landing-page visits and Probetraining-buchen CTA clicks are now captured client-side behind consent/admin/bot gates and written through POT_Store via a new nonce-gated POST pot/v1/event REST route.**

## Performance

- **Tasks:** 3 completed
- **Files modified:** 5 (3 created, 2 modified)
- **Completed:** 2026-05-31

## Accomplishments
- `POT_Ingest` registers `POST pot/v1/event` on `rest_api_init` with a real `wp_verify_nonce($nonce,'wp_rest')` gate — reads `X-WP-Nonce` header, falls back to `_wpnonce` body (sendBeacon path). Handler guards admins + bot UAs, sanitizes/length-caps all fields, writes via `POT_Store::insert_event`, stores no IP/UA.
- `pot-tracker.js` fires exactly one visit beacon per pageload (works on fully-cached pages since it is client JS) and a debounced capture-phase delegated click beacon for `a[href*="/probetraining-buchen"]`. Everything is gated behind `window.poConsent.categories.analytics`; admins and `navigator.webdriver` early-return. Nonce travels in `_wpnonce` body + `X-WP-Nonce` header.
- `POT_Tracker` enqueues the script for non-admins and localizes `{restUrl, nonce, isAdmin}`. Both new classes wired into `pot_init()`; both files required before github-updater.php (still last).

## Task Commits
1. **Task 1: REST ingest route** - `6a03bf4` (feat)
2. **Task 2: Tracker JS** - `a83df2f` (feat)
3. **Task 3: Enqueue + localize + wiring** - `fd367bd` (feat)

## Files Created/Modified
- `includes/class-pot-ingest.php` - POST pot/v1/event route, nonce gate, sanitizing handler, write-through
- `assets/js/pot-tracker.js` - consent/admin/bot-gated visit beacon + CTA click listener
- `includes/class-pot-tracker.php` - admin-skipped enqueue + localize
- `includes/class-pot-plugin.php` - wired POT_Ingest::init() + POT_Tracker::init()
- `parkourone-campaign-tracking.php` - require_once for both new files (github-updater stays last)

## Static Verification (run here — PHP/WP/browser unavailable)
- All Task 1/2/3 acceptance greps PASS; `__return_true`=0, `REMOTE_ADDR|visitor_hash`=0, `po_analytics_sid`=0.
- PHP structural: `<?php` present + balanced braces (php -l unavailable — substitute, NOT a defect).
- JS balance: braces 30/30, parens 60/60.

## DEFERRED MANUAL CHECKLIST (operator, on WP staging — no runtime here)
- One visit beacon fires per pageload, INCLUDING on a fully-cached page (verify in DevTools Network).
- A CTA click on `/probetraining-buchen` is recorded exactly once; a rapid double-click is debounced (~500ms).
- Consent OFF → no beacon fires and no `pot_sid` is written (DevTools Network + Application/sessionStorage).
- Logged-in admin (manage_options) → tracker not enqueued AND REST route rejects (admin guard returns 204, no row).
- Known bot UA → server denylist rejects (204), no IP/UA stored; `navigator.webdriver` → client early-return.
