---
phase: 01-plugin-foundation-events-store
plan: 01
subsystem: infra
tags: [wordpress, woocommerce, hpos, dbDelta, custom-table, wpdb, admin-menu]

requires: []
provides:
  - "parkourone-campaign-tracking.php bootstrap (POT_* constants, flat require chain, pot_init on plugins_loaded:11, class_exists-guarded HPOS declaration, activation hook)"
  - "{prefix}pot_events custom table via dbDelta with indices event_type_created, campaign_created, order_idx"
  - "POT_Store single DB gateway: insert_event(), aggregate_by_campaign(), delete_raw_older_than() stub"
  - "POT_Admin placeholder page under the parkourone menu with top-level fallback"
  - "Seeded options pot_db_version + pot_retention_days (autoload=false)"
affects: [conversion-tracking, client-capture, dashboard, pull-api, retention-cron, uninstall]

tech-stack:
  added: []
  patterns:
    - "POT_Store single-gateway: all pot_events reads/writes route through one class (zero drift across dashboard + pull-API)"
    - "dbDelta schema versioning via pot_db_version option + admin_init bump guard"
    - "Static POT_*::init() module idiom wired from a single pot_init()"
    - "class_exists-guarded HPOS declaration on before_woocommerce_init (WooCommerce optional)"

key-files:
  created:
    - parkourone-campaign-tracking.php
    - includes/class-pot-plugin.php
    - includes/class-pot-activator.php
    - includes/class-pot-store.php
    - includes/class-pot-admin.php
  modified:
    - .gitignore

key-decisions:
  - "Cron + github-updater requires and the deactivation hook are deferred to Plan 02 so Plan 01 activates without requiring not-yet-created files (avoids a fatal)."
  - "Schema columns use NOT NULL DEFAULT '' / DEFAULT 0 (STACK.md style) to keep dbDelta idempotent."
  - "Empty/NULL campaign bucketed under '(unattributed)' in aggregate_by_campaign (Phase-2 refines the label)."

patterns-established:
  - "Single DB gateway (POT_Store): no other class touches the pot_events table via \\$wpdb."
  - "Bracketed error_log('[POT Tracking] ...') diagnostics on DB failure."

requirements-completed: [INFRA-01, INFRA-02, INFRA-04]

duration: 18min
completed: 2026-05-31
---

# Phase 01: Plugin Foundation & Events Store — Plan 01 Summary

**Walking skeleton: house-style bootstrap + HPOS declaration + dbDelta `pot_events` table (3 indices) + POT_Store single gateway + placeholder admin page under the `parkourone` menu.**

## Performance

- **Duration:** ~18 min
- **Tasks:** 2 code tasks complete; Task 3 (human-verify) deferred to live staging
- **Files modified:** 6

## Accomplishments
- `parkourone-campaign-tracking.php` mirrors the ab-webhook-endpoint bootstrap 1:1: header with WP 6.9 / PHP 8.1 / WC 8.2 floors, `POT_*` constants, flat require chain, `pot_init()` on `plugins_loaded` priority 11, `class_exists`-guarded HPOS `custom_order_tables` declaration, `register_activation_hook`.
- `POT_Activator::activate()` runs `dbDelta` for `{prefix}pot_events` with `PRIMARY KEY  (id)` and keys `event_type_created (event_type,created_at)`, `campaign_created (campaign,created_at)`, `order_idx (order_id)`; seeds `pot_db_version` + `pot_retention_days=180` (autoload=false); `maybe_upgrade()` re-runs dbDelta on a version bump via `admin_init`.
- `POT_Store` is the single DB gateway: `insert_event()` (column whitelist + `$wpdb->insert` format array, UTC `created_at` default), `aggregate_by_campaign()` (one prepared GROUP BY, `(unattributed)` bucket), `delete_raw_older_than()` (prepared DELETE, booking rows excluded — body implemented now, called by the Plan 02 cron).
- `POT_Admin` registers a submenu under `parkourone` (cap `manage_options`) with a top-level `add_menu_page` fallback; placeholder renders `Coming soon — data store ready.`

## Task Commits

1. **Task 1: Bootstrap, HPOS, dbDelta events table** — `e727d39` (feat)
2. **Task 2: POT_Store gateway + placeholder admin page** — `1dd88d7` (feat)
3. **Task 3: Human verification (live activation)** — DEFERRED (see below)

## Files Created/Modified
- `parkourone-campaign-tracking.php` — main bootstrap / header / constants / require chain / pot_init / HPOS / activation hook
- `includes/class-pot-plugin.php` — orchestrator: admin_init upgrade guard + POT_Admin::init()
- `includes/class-pot-activator.php` — dbDelta schema + option seeding + version-bump guard
- `includes/class-pot-store.php` — single DB gateway (insert/aggregate/delete)
- `includes/class-pot-admin.php` — placeholder admin page + top-level fallback
- `.gitignore` — added `.git-version` (DS_Store + vendor/ already present)

## Decisions Made
- Cron (`class-pot-cron.php`) and `github-updater.php` requires, plus the deactivation hook, are deferred to Plan 02. Requiring those files before they exist would fatal on activation, so Plan 01's require chain and activation path are deliberately self-contained.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Removed Plan-02 requires/wiring from the Plan-01 bootstrap**
- **Found during:** Task 1
- **Issue:** An initial draft required `class-pot-cron.php` + `github-updater.php` and registered a `POT_Cron`-based deactivation hook / schedule call. Those files do not exist until Plan 02, so activating after Plan 01 alone would fatal — directly violating the "activates without a fatal" must-have.
- **Fix:** Trimmed the require chain to the four Plan-01 classes (with a comment marking where the updater/cron requires land in Plan 02), removed the `register_deactivation_hook`, and removed the `POT_Cron::schedule()` call from the activator. Plan 02 re-adds all of it.
- **Files modified:** parkourone-campaign-tracking.php, includes/class-pot-plugin.php, includes/class-pot-activator.php
- **Verification:** require chain now references only existing files; static checks pass.
- **Committed in:** e727d39 (Task 1 commit)

---

**Total deviations:** 1 auto-fixed (1 blocking)
**Impact on plan:** Necessary for the "no fatal on activation" must-have. No scope change — the removed wiring is exactly Plan 02's scope.

## Issues Encountered
- **No PHP runtime / no live WordPress in this environment.** `php -l` and all activation-time checks (SHOW INDEX, option reads, admin render, store round-trip) cannot run here. `php -l` was substituted with static structural checks: every file opens with `<?php`, brace counts balance, and all plan grep acceptance criteria pass. The runtime-only checks are recorded as deferred manual items below.

## Static Verification (ran here — all PASS)
- All 5 PHP files: `<?php` opener present, `{`/`}` brace counts balanced.
- `grep -c dbDelta` in activator = 6 (>= 1).
- CREATE TABLE contains `PRIMARY KEY  (id)`, `KEY event_type_created`, `KEY campaign_created`, `order_idx (order_id)`.
- Main file contains `before_woocommerce_init`, `FeaturesUtil`, `class_exists`, `register_activation_hook`.
- Seeded options use the autoload=false form (`add_option(..., '', false)` / `update_option(..., false)`).
- `grep -c prepare` in store = 3; `insert_event`, `aggregate_by_campaign`, `delete_raw_older_than` all defined; no value concatenation into SQL.
- Admin file: `add_submenu_page('parkourone'`, `manage_options`, `add_menu_page` fallback, placeholder text present.

## Deferred — requires user's WordPress staging (Task 3 human-verify)
Run on a real WP test site (cannot be proven by php -l / grep):
1. Symlink the plugin into `wp-content/plugins/parkourone-campaign-tracking/` and activate in wp-admin → Plugins. Expect: activates, no fatal, no "unexpected output" notice.
2. Deactivate WooCommerce, re-activate this plugin. Expect: still no fatal (HPOS declaration is class_exists-guarded).
3. `wp db query "SHOW INDEX FROM wp_pot_events"` — confirm keys `event_type_created`, `campaign_created`, `order_idx` exist.
4. `wp eval 'POT_Store::insert_event(["event_type"=>"visit","campaign"=>"spring","created_at"=>"2026-05-15 10:00:00"]); POT_Store::insert_event(["event_type"=>"click","campaign"=>"spring","created_at"=>"2026-05-15 10:01:00"]); POT_Store::insert_event(["event_type"=>"click","campaign"=>"spring","created_at"=>"2026-05-15 10:02:00"]); POT_Store::insert_event(["event_type"=>"booking","campaign"=>"spring","created_at"=>"2026-05-15 10:03:00"]); print_r(POT_Store::aggregate_by_campaign("2026-05-01 00:00:00","2026-05-31 23:59:59"));'` — expect a `spring` row with visits=1, clicks=2, bookings=1.
5. Confirm a "Campaign Tracking" item under the `parkourone` menu rendering "Coming soon — data store ready."
6. `wp eval 'echo get_option("pot_retention_days");'` — expect `180`.

## Next Phase Readiness
- Store gateway, schema, and admin shell ready. Plan 02 adds the retention cron (`POT_Cron`), the github-updater require (last in chain), `.git-version`, `uninstall.php`, the deactivation hook, and wires `POT_Cron::init()` + `POT_Cron::schedule()`.

---
*Phase: 01-plugin-foundation-events-store*
*Completed: 2026-05-31*
