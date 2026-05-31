---
phase: 01-plugin-foundation-events-store
plan: 02
subsystem: infra
tags: [wordpress, wp-cron, retention, github-updater, uninstall, wpdb]

requires:
  - phase: 01-plugin-foundation-events-store
    provides: "POT_Store gateway, pot_events table, bootstrap + activation path, pot_retention_days option"
provides:
  - "POT_Cron daily pot_prune_events retention cron (prunes raw visit/click rows, never bookings)"
  - "Activation schedules the cron; deactivation clears it (register_deactivation_hook)"
  - "POT_GitHub_Updater wired against monkeyspk/parkourone-campaign-tracking with shared parkourone_github_token + .git-version scheme"
  - "uninstall.php: drops pot_events, deletes pot_* options, clears cron (WP_UNINSTALL_PLUGIN guarded)"
  - ".git-version placeholder file (0000000)"
affects: [delivery, maintenance, data-retention, dsgvo-cleanup]

tech-stack:
  added: []
  patterns:
    - "Plain WP-Cron daily retention (no Action Scheduler dependency)"
    - "Prune routed through POT_Store single gateway — cron never touches the DB directly"
    - "Verbatim sibling-updater copy with minimal re-prefix + shared cross-plugin token"

key-files:
  created:
    - includes/class-pot-cron.php
    - includes/github-updater.php
    - uninstall.php
    - .git-version
  modified:
    - parkourone-campaign-tracking.php
    - includes/class-pot-plugin.php
    - includes/class-pot-activator.php

key-decisions:
  - "delete_raw_older_than() was already fully implemented in Plan 01-01 (not a bare stub), so Task 1 only confirmed it; the cron calls it directly."
  - ".git-version stays gitignored (updater self-overwrites at runtime); the file exists on disk per the plan but is not tracked."

patterns-established:
  - "Single-gateway prune: POT_Cron::prune_events computes the cutoff and delegates the DELETE to POT_Store."
  - "Uninstall keys list with an explicit comment to extend when new pot_* options are added."

requirements-completed: [INFRA-03, INFRA-05]

duration: 12min
completed: 2026-05-31
---

# Phase 01: Plugin Foundation & Events Store — Plan 02 Summary

**Operational lifecycle: daily pot_prune_events retention cron (visit/click only, never bookings), GitHub self-updater wired to monkeyspk/parkourone-campaign-tracking, and a WP_UNINSTALL_PLUGIN-guarded uninstall that drops the table + options + cron.**

## Performance

- **Duration:** ~12 min
- **Tasks:** 2 (both autonomous)
- **Files modified:** 7

## Accomplishments
- `POT_Cron` (plain WP-Cron, no Action Scheduler): `CRON_HOOK='pot_prune_events'`, `init()` binds `prune_events`, `schedule()` registers a daily event idempotently, `clear()` unschedules it. `prune_events()` reads `pot_retention_days` (default 180), computes a UTC cutoff, and routes the DELETE through `POT_Store::delete_raw_older_than()` — the cron never touches the DB directly.
- Booking rows are never pruned: the store DELETE is guarded by `event_type IN ('visit','click')` with a prepared `%s` cutoff.
- Wired into the bootstrap: `class-pot-cron.php` required, `POT_Cron::init()` in the orchestrator, `POT_Cron::schedule()` in the activation path, `register_deactivation_hook(..., ['POT_Cron','clear'])`, and `github-updater.php` re-added as the last require.
- `POT_GitHub_Updater` is a verbatim copy of the sibling updater with exactly the four value changes + `abw_`→`pot_` re-prefixing; keeps the shared `parkourone_github_token` and `.git-version` SHA scheme; `error_log` tag `[POT Tracking]`. `update-manager.php`/`version.txt` not carried over.
- `uninstall.php` (WP_UNINSTALL_PLUGIN-guarded) drops `{prefix}pot_events`, deletes `pot_db_version`/`pot_retention_days`, clears the `pot_prune_events` cron, and leaves the shared token intact.

## Task Commits

1. **Task 1: Retention cron + activation/deactivation wiring** — `b97a242` (feat)
2. **Task 2: Self-updater + .git-version + uninstall.php** — `0fe7a17` (feat)

## Files Created/Modified
- `includes/class-pot-cron.php` — daily retention cron, prune routed through POT_Store
- `includes/github-updater.php` — re-prefixed verbatim copy of the sibling updater
- `uninstall.php` — table/options/cron cleanup under WP_UNINSTALL_PLUGIN
- `.git-version` — placeholder SHA file (gitignored; updater self-overwrites)
- `parkourone-campaign-tracking.php` — cron + updater requires re-added, schedule on activation, deactivation hook
- `includes/class-pot-plugin.php` — POT_Cron::init() wired
- `includes/class-pot-activator.php` — POT_Cron::schedule() in the activation path

## Decisions Made
- `delete_raw_older_than()` was implemented in full during Plan 01-01 rather than as a bare stub, so Task 1 simply confirmed its body and pointed the cron at it. No re-write needed.
- `.git-version` remains gitignored (Plan 01-01 added it to `.gitignore`); the file exists on disk to satisfy the updater's `get_local_version()` read, but is not committed since the updater overwrites it on each self-update.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Cleanup] Removed literal "$wpdb" from a cron docblock comment**
- **Found during:** Task 1
- **Issue:** Task 1's acceptance criterion greps for zero `$wpdb` occurrences in `class-pot-cron.php` to prove the single-gateway rule. The docblock used the literal "$wpdb" in prose ("never calls $wpdb directly"), which tripped the grep although it was only a comment.
- **Fix:** Reworded to "never touches the database directly". Behavior unchanged.
- **Files modified:** includes/class-pot-cron.php
- **Verification:** `grep -c '$wpdb' includes/class-pot-cron.php` now returns 0.
- **Committed in:** b97a242 (Task 1 commit)

---

**Total deviations:** 1 auto-fixed (1 cleanup)
**Impact on plan:** Cosmetic; satisfies the AC grep without changing logic.

## Issues Encountered
- **No PHP runtime / no live WordPress.** `php -l` and all runtime checks (cron scheduling, prune behavior, self-update HTTP, uninstall side-effects) cannot run here. `php -l` substituted with static structural checks (all PHP files open with `<?php`, brace counts balance) plus every plan grep AC. Runtime-only checks recorded as deferred manual items below.

## Static Verification (ran here — all PASS)
- `class-pot-cron.php`, `github-updater.php`, `uninstall.php`, main file: `<?php` opener + balanced braces.
- `grep -c pot_prune_events` in cron = 1; contains `wp_schedule_event` + `'daily'` + `wp_clear_scheduled_hook`.
- Cron calls `POT_Store::delete_raw_older_than`; `grep -c '$wpdb'` in cron = 0 (single-gateway rule).
- Store DELETE contains `event_type IN ('visit','click')` and a prepared `%s` cutoff.
- Main file calls `POT_Cron::schedule()` (in activator) + `register_deactivation_hook`; require chain ends with `includes/github-updater.php`.
- `POT_GitHub_Updater` x2; repo + `parkourone_github_token` present; zero `AB_Webhook_GitHub_Updater`/`abw_` leftovers.
- uninstall.php: first executable statement is the WP_UNINSTALL_PLUGIN guard; contains `DROP TABLE`, both `delete_option` calls, `wp_clear_scheduled_hook('pot_prune_events')`; does NOT delete `parkourone_github_token`.
- `.git-version` exists on disk.

## Deferred — requires user's WordPress staging
Run on a real WP test site (cannot be proven by php -l / grep):
1. **Retention prune:** seed a `visit` row and a `booking` row both dated 200 days ago, then run the handler: `wp eval 'POT_Cron::prune_events();'`. Expect the visit row gone, the booking row still present.
2. **Cron scheduled:** after activation, `wp cron event list` lists `pot_prune_events` running daily.
3. **Deactivation clears cron:** deactivate the plugin, then `wp cron event list` no longer lists `pot_prune_events`; reactivation re-schedules it once (idempotent).
4. **Self-updater:** with `parkourone_github_token` set, visit the updater admin section and click "Jetzt prüfen & aktualisieren"; confirm it reaches api.github.com and reports a version (requires the monkeyspk/parkourone-campaign-tracking repo to exist).
5. **Uninstall:** delete the plugin via wp-admin (or `wp plugin uninstall parkourone-campaign-tracking`), then confirm `SHOW TABLES LIKE '%pot_events'` returns nothing, the two pot_* options are gone, `wp cron event list` no longer lists pot_prune_events, and `parkourone_github_token` still exists.

## Next Phase Readiness
- Foundation complete and maintainable: store + schema + admin shell (Plan 01-01) plus retention, self-update, and clean uninstall (Plan 01-02). Phase 2 (conversion + attribution) can write bookings through `POT_Store::insert_event()` and read via `aggregate_by_campaign()`.

---
*Phase: 01-plugin-foundation-events-store*
*Completed: 2026-05-31*
