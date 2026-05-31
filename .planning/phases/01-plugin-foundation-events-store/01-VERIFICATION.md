---
status: human_needed
phase: 01-plugin-foundation-events-store
verified: 2026-05-31
requirements: [INFRA-01, INFRA-02, INFRA-03, INFRA-04, INFRA-05]
must_haves_total: 9
must_haves_static_pass: 9
must_haves_runtime_deferred: 9
automated_checks: passed
environment_note: "No PHP runtime and no live WordPress in this environment — php -l and all activation/runtime checks substituted with static structural + grep verification. Runtime-only criteria are deferred to the user's WordPress staging (not failures)."
---

# Phase 01: Plugin Foundation & Events Store — Verification

## Status: human_needed

All statically-verifiable acceptance criteria PASS. The remaining items can only be
confirmed on a live WordPress install (activation, SHOW INDEX, store round-trip, cron
firing, self-update HTTP, uninstall side-effects). They are recorded below as deferred
manual verification — not phase failures (per the no-PHP-runtime environment constraint).

## Plans Verified

| Plan | Tasks | Summary | Commits |
|------|-------|---------|---------|
| 01-01 | 2 code + 1 human-verify (deferred) | 01-01-SUMMARY.md | e727d39, 1dd88d7, bd2e004 |
| 01-02 | 2 (autonomous) | 01-02-SUMMARY.md | b97a242, 0fe7a17 |

## Requirement Traceability

| Req | Description | Plan | Static Evidence | Runtime Check |
|-----|-------------|------|-----------------|---------------|
| INFRA-01 | House-style activate w/o fatal (WooCommerce / ab-webhook absent) | 01-01 | Root slug.php, includes/class-pot-*.php, pot_init() on plugins_loaded:11, HPOS class_exists-guarded; require chain references only existing files | Deferred: activate on staging |
| INFRA-02 | HPOS custom_order_tables declared, class_exists-guarded | 01-01 | `before_woocommerce_init` + `FeaturesUtil::declare_compatibility('custom_order_tables', ...)` inside `class_exists(...)` | Deferred: activate w/ WC inactive |
| INFRA-03 | GitHub self-updater + shared token + .git-version | 01-02 | `POT_GitHub_Updater`, repo `monkeyspk/parkourone-campaign-tracking`, `parkourone_github_token` kept, `.git-version` scheme; 0 `abw_`/`AB_Webhook` leftovers | Deferred: manual update check |
| INFRA-04 | dbDelta table w/ composite indices | 01-01 | dbDelta CREATE TABLE w/ `event_type_created`, `campaign_created`, `order_idx`; POT_Store gateway | Deferred: SHOW INDEX + round-trip |
| INFRA-05 | Daily prune (visit/click only) + uninstall cleanup | 01-02 | `pot_prune_events` daily, prune via POT_Store w/ `event_type IN ('visit','click')`; uninstall drops table + options + cron under WP_UNINSTALL_PLUGIN | Deferred: prune + uninstall smoke |

## Must-Haves (static proxies — all PASS)

1. ✅ dbDelta creates {prefix}pot_events with the three indices — `KEY event_type_created`, `KEY campaign_created`, `KEY order_idx` present in CREATE TABLE.
2. ✅ Activates w/o fatal when WooCommerce/ab-webhook inactive — HPOS is class_exists-guarded; require chain references only files that exist.
3. ✅ POT_Store insert_event + aggregate_by_campaign defined — both present; aggregate uses one prepared GROUP BY; insert uses $wpdb->insert format array.
4. ✅ Placeholder admin page under parkourone w/ manage_options + top-level fallback — `add_submenu_page('parkourone', ...)` + `add_menu_page` fallback + `current_user_can('manage_options')`.
5. ✅ HPOS custom_order_tables declared on before_woocommerce_init, class_exists-guarded.
6. ✅ Daily pot_prune_events cron prunes only visit/click, never bookings — `wp_schedule_event(..., 'daily', ...)`; DELETE guarded by `event_type IN ('visit','click')`.
7. ✅ Deactivation clears cron; reactivation re-schedules idempotently — `register_deactivation_hook(..., ['POT_Cron','clear'])`; `schedule()` guarded by `wp_next_scheduled`.
8. ✅ Uninstall drops table + deletes pot_* options + clears cron, WP_UNINSTALL_PLUGIN-guarded — all present; shared token preserved.
9. ✅ POT_GitHub_Updater wired to monkeyspk/parkourone-campaign-tracking w/ shared token + .git-version — confirmed.

## Automated / Static Checks

- PHP: NO runtime available — `php -l` substituted. All 8 PHP files open with `<?php`; brace counts balance in every file.
- All per-task grep acceptance criteria from both plans PASS (see each SUMMARY's "Static Verification" section).
- Working tree clean; `.git-version` correctly untracked (gitignored, written by the updater at runtime).

## Human Verification Required (deferred — run on WordPress staging)

These map 1:1 to the runtime checks documented in the two SUMMARY files:

1. Activate the plugin (with and without WooCommerce active) — expect no fatal, no unexpected-output notice.
2. `wp db query "SHOW INDEX FROM wp_pot_events"` — expect `event_type_created`, `campaign_created`, `order_idx`.
3. Insert 1 visit + 2 clicks + 1 booking for `spring`, then `POT_Store::aggregate_by_campaign(...)` — expect visits=1, clicks=2, bookings=1.
4. Confirm "Campaign Tracking" appears under the parkourone menu rendering "Coming soon — data store ready."
5. `wp cron event list` after activation lists `pot_prune_events` daily; deactivation removes it; reactivation re-adds once.
6. Seed a 200-day-old visit + booking, run `wp eval 'POT_Cron::prune_events();'` — visit gone, booking survives.
7. Self-updater: with `parkourone_github_token` set, run a manual check (requires the monkeyspk repo to exist).
8. `wp plugin uninstall parkourone-campaign-tracking` — expect table dropped, pot_* options gone, cron cleared, `parkourone_github_token` intact.

## Verdict

Phase 1 is code-complete and passes all static/structural verification with full requirement
traceability (INFRA-01..05). Live activation verification is deferred to the user's WordPress
staging environment.
