---
phase: 01-plugin-foundation-events-store
reviewed: 2026-05-31T00:00:00Z
depth: standard
files_reviewed: 8
files_reviewed_list:
  - parkourone-campaign-tracking.php
  - includes/class-pot-plugin.php
  - includes/class-pot-activator.php
  - includes/class-pot-store.php
  - includes/class-pot-admin.php
  - includes/class-pot-cron.php
  - includes/github-updater.php
  - uninstall.php
findings:
  critical: 0
  warning: 1
  info: 2
  total: 3
status: issues_found
---

# Phase 1: Code Review Report

**Reviewed:** 2026-05-31
**Depth:** standard
**Files Reviewed:** 8
**Status:** issues_found

> Note: No PHP runtime available in this environment — review is static (read + grep + cross-file reasoning), not `php -l`/execution-based.

## Summary

Reviewed the full plugin foundation: bootstrap, activator (dbDelta), the POT_Store
single gateway, admin shell, retention cron, the re-prefixed GitHub updater, and
uninstall. The security posture is solid for this phase: every SQL value bound goes
through `$wpdb->prepare` with `%s`/`%d`, table names derive only from `$wpdb->prefix`
(no interpolation of untrusted data), the admin page re-checks `manage_options` and
escapes output, and the updater's manual-check path is nonce- and capability-gated.
No critical issues. One warning (admin-menu attachment ordering) and two info items.

## Narrative Findings (AI reviewer)

## Warnings

### WR-01: Admin submenu fallback is admin_menu-priority-fragile

**File:** `includes/class-pot-admin.php:20,24-43`
**Issue:** `POT_Admin::init()` hooks `add_menu_page` on `admin_menu` at the default
priority (10). The decision between attaching under the `parkourone` parent
(`add_submenu_page`) and registering a standalone top-level page depends on whether
`$GLOBALS['admin_page_hooks']['parkourone']` is already populated when our callback
runs. The sibling plugin's menu organizer runs at `admin_menu` priority **999**
(`class-ab-admin-menu-organizer.php:50`), and the theme that owns the `parkourone`
top-level menu may also register late. If our callback fires before the parent is
registered, the fallback path adds a **duplicate standalone top-level "Campaign
Tracking" menu** even though the parent exists — the opposite of the intended
"never orphaned" behavior.
**Fix:** Run the menu registration on a late `admin_menu` priority so the parent is
present by the time the check runs:
```php
public static function init() {
    // Priority 999 so the theme/sibling `parkourone` parent is registered first.
    add_action('admin_menu', [__CLASS__, 'add_menu_page'], 999);
}
```
This is a runtime/UX correctness concern (not a security issue) and is also covered
by the deferred live-staging check ("confirm the item appears under parkourone").

## Info

### INFO-01: aggregate_by_campaign returns count columns as strings

**File:** `includes/class-pot-store.php:96-118`
**Issue:** `SUM(event_type = 'visit')` etc. returned via `get_results(..., ARRAY_A)`
yields string values (MySQL/`$wpdb` returns numeric aggregates as strings). Consumers
must cast to `int`. This is already anticipated by `ARCHITECTURE.md` (the pull-API maps
`(int)` at the edge), so it is acceptable, but worth flagging so Phase 4/5 consumers
don't compare/serialize the raw strings.
**Fix:** Optionally cast in the gateway, or document that callers cast at the boundary
(current design choice — keep the gateway returning the neutral DB shape).

### INFO-02: pot_retention_days not refreshed on reactivation by design

**File:** `includes/class-pot-activator.php:26-28`
**Issue:** `pot_retention_days` is only seeded when absent (`add_option` guarded by
`=== false`); reactivation never overwrites it. This is intentional (don't clobber an
admin-tuned value once the Phase-2 UI exists), but there is no UI yet, so the value is
effectively constant this phase. No action needed — recorded for traceability.
**Fix:** None — intended behavior.

## Non-Findings (verified clean)

- SQL injection: all value bounds use `$wpdb->prepare` with `%s`/`%d`; table names are `$wpdb->prefix`-derived only. `delete_raw_older_than` and `aggregate_by_campaign` confirmed parameterized; `uninstall.php` `DROP TABLE IF EXISTS {$table}` uses the prefix only.
- Secrets: none hardcoded; `parkourone_github_token` read from an option (shared, correct).
- Capability/authz: admin page registers + re-checks `manage_options`; updater manual check verifies nonce + `current_user_can('manage_options')` before acting.
- Booking-row protection: prune DELETE guarded by `event_type IN ('visit','click')`.
- No debug artifacts, no TODO/FIXME, no leftover `AB_Webhook`/`abw_` identifiers (the single `ab-webhook` hit is an intentional explanatory comment).
- HPOS declaration `class_exists`-guarded; require chain references only existing files (no fatal when WooCommerce/ab-webhook inactive).
