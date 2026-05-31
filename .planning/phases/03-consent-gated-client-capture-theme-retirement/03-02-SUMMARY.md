---
phase: 03-consent-gated-client-capture-theme-retirement
plan: 02
subsystem: infra
tags: [theme-retirement, wp-hooks, dequeue, remove-action, feature-gate, migration]

requires:
  - phase: 03-consent-gated-client-capture-theme-retirement
    provides: "Plan 01 plugin tracker (POT_Ingest + pot-tracker.js) — the replacement tracker"
provides:
  - "POT_Theme_Retirement — option-gated dequeue/deregister of po-analytics-tracker + remove_action of track_basic_pageview"
affects: [phase-04-dashboard, parity-cutover]

tech-stack:
  added: []
  patterns:
    - "Cross-repo hook retirement: dequeue at priority 99 + remove_action on a foreign singleton under class_exists guard"
    - "Toggleable cutover via option (default true) — code-free rollback"

key-files:
  created:
    - includes/class-pot-theme-retirement.php
  modified:
    - includes/class-pot-plugin.php
    - parkourone-campaign-tracking.php

key-decisions:
  - "Dequeue at priority 99 (theme enqueues at 10) so the dequeue runs after the theme's enqueue"
  - "remove_action passes the SAME PO_Analytics instance + SAME priority 10 the theme used, so removal matches"
  - "track_purchase intentionally NOT removed — Phase 2 owns conversions; theme purchase row stays until parity confirmed"
  - "Whole retirement gated by pot_retire_theme_tracker option (default true) for instant code-free rollback"

patterns-established:
  - "class_exists('PO_Analytics') guard before touching foreign theme hooks (graceful degradation, never fatal)"
  - "Theme repo (Input/) is never edited — the plugin inverts the theme's registrations from outside"

requirements-completed: [MIGRATE-01, MIGRATE-02]

duration: 8min
completed: 2026-05-31
---

# Phase 3 (Plan 02): Theme Tracker Retirement Summary

**The plugin now dequeues the theme's po-analytics-tracker and removes its wp_footer fallback writer behind a toggleable option, so exactly one tracker runs after cutover — without editing the theme and without touching track_purchase.**

## Performance

- **Tasks:** 2 completed
- **Files modified:** 3 (1 created, 2 modified)
- **Completed:** 2026-05-31

## Accomplishments
- `POT_Theme_Retirement::init()` returns early unless `get_option('pot_retire_theme_tracker', true)` is truthy. When enabled, it hooks `dequeue_theme_tracker` on `wp_enqueue_scripts` at priority 99 (after the theme's priority-10 enqueue) which `wp_dequeue_script` + `wp_deregister_script` the `po-analytics-tracker` handle — stopping all theme JS-driven writes since `handle_track` has no other caller.
- Under a `class_exists('PO_Analytics')` guard, it gets the singleton and `remove_action('wp_footer', [$po, 'track_basic_pageview'], 10)` with the SAME instance + priority the theme registered, silencing the server-side fallback pageview for non-consenting visitors.
- `track_purchase` is deliberately left registered; the theme repo is not edited. Wired into `pot_init()`; file required before github-updater.php (still last).

## Task Commits
1. **Task 1: POT_Theme_Retirement class** - `3a5fc4e` (feat)
2. **Task 2: Wire into pot_init() + require** - `3463a3e` (feat)

## Files Created/Modified
- `includes/class-pot-theme-retirement.php` - option-gated dequeue + remove_action of theme analytics
- `includes/class-pot-plugin.php` - wired POT_Theme_Retirement::init()
- `parkourone-campaign-tracking.php` - require_once before github-updater.php (still last)

## Static Verification (run here — PHP/WP unavailable)
- All Task 1/2 acceptance greps PASS; `track_purchase` (non-comment) = 0; priority-99 regex matches; `class_exists`/singleton present.
- PHP structural: `<?php` present + balanced braces 6/6 (php -l unavailable — substitute, NOT a defect).

## DEFERRED MANUAL CHECKLIST (operator, on WP staging — no runtime here)
- MIGRATE-02 parity: run plugin + theme tracker in parallel for a shadow window; confirm the plugin's visit/click counts match the theme's `wp_po_analytics_events` for the same window BEFORE flipping live (no gap).
- After cutover: confirm `po-analytics-tracker` (analytics-tracker.js) no longer enqueues and `wp_po_analytics_events` stops receiving new cta_click/pageview rows (handle_track/track_basic_pageview have no caller) — plugin is sole tracker.
- Confirm the theme's `track_purchase` row writer is still intact (intentionally not removed); retire it separately once booking parity is confirmed.
- Rollback: if parity fails, set `pot_retire_theme_tracker` to false to instantly restore the theme tracker without a deploy.
