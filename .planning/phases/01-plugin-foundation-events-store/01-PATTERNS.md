# Phase 1: Plugin Foundation & Events Store - Pattern Map

**Mapped:** 2026-05-31
**Files analyzed:** 9 new files
**Analogs found:** 7 / 9 (2 deliberate house-style deviations have no analog — see "No Analog Found")

> **Analog source:** This plugin's own codebase is empty (greenfield). All analogs live in the REFERENCE plugins under `Input/` (each a separate git repo). COPY patterns, do NOT add a runtime dependency on them. Every excerpt below is real code cited by `file:line`.
>
> **Global re-prefix rule (applies to every file):** class prefix `AB_`/`Event_` → `POT_`; function/hook/option prefix `ab_`/`ab_we_`/`abw_`/`event_`/`custom_events_` → `pot_`; slug `ab-webhook-endpoint` → `parkourone-campaign-tracking`; repo `monkeyspk/ab-webhook-endpoint` → `monkeyspk/parkourone-campaign-tracking`; table `{$wpdb->prefix}pot_events`. Author stays `Pierre Biege`. Version is a bare number (e.g. `0.1.0`).

## File Classification

| New File | Role | Data Flow | Closest Analog | Match Quality |
|----------|------|-----------|----------------|---------------|
| `parkourone-campaign-tracking.php` | bootstrap/main | request-response | `Input/ab-webhook-endpoint/ab-webhook-endpoint.php` | exact |
| `includes/class-pot-plugin.php` | bootstrap/orchestrator | event-driven | `ab-webhook-endpoint.php:81-137` (`ab_we_init_plugin`) | role-match |
| `includes/class-pot-activator.php` | migration/activator (dbDelta) | batch | none (no dbDelta in any sibling) — STACK.md schema | no-analog |
| `includes/class-pot-store.php` | model/data-gateway | CRUD + transform | none (no custom table in any sibling) — ARCHITECTURE.md C4 | no-analog |
| `includes/class-pot-cron.php` | service (retention) | batch/event-driven | `Input/ab-webhook-endpoint/includes/class-ab-bestandskunde-reminder.php` | role-match |
| `includes/class-pot-admin.php` | controller (admin page) | request-response | `Input/ab-webhook-endpoint/includes/class-ab-customer-overview.php` | exact |
| `includes/github-updater.php` | service (self-update) | request-response | `Input/ab-webhook-endpoint/includes/github-updater.php` | exact (copy verbatim) |
| `uninstall.php` | config/cleanup | batch | none in `Input/` — build fresh per CONTEXT decisions | no-analog |
| `.git-version` | config (SHA file) | n/a | consumed by `github-updater.php:302-313` | exact (one-line file) |

> File split among `includes/class-pot-*.php` is Claude's discretion (CONTEXT.md). Above reflects the ARCHITECTURE.md Phase-1 cut. Plugin may merge activator+store or split further — keep `POT_Store` the single DB gateway.

## Pattern Assignments

### `parkourone-campaign-tracking.php` (bootstrap, request-response)

**Analog:** `Input/ab-webhook-endpoint/ab-webhook-endpoint.php` (mirror 1:1; the leaner `custom-events-plugin.php:1-46` confirms the same shape).

**Header + ABSPATH guard** (analog lines 1-12) — MIRROR shape, CHANGE all field values:
```php
<?php
/**
 * Plugin Name: AB Webhook Endpoint + Multiple Custom Order Status
 * Description: ...
 * Version:     1.4
 * Author:      Pierre Biege
 * Text Domain: ab-webhook-endpoint
 */
if (!defined('ABSPATH')) {
    exit;
}
```
CHANGE to: `Plugin Name: ParkourONE Campaign Tracking`, `Version: 0.1.0`, `Text Domain: parkourone-campaign-tracking`, ADD `Requires PHP: 8.1`, `Requires at least: 6.5`, `WC requires at least: 8.2` (per CONTEXT.md + STACK.md). Keep `Author: Pierre Biege`.

**Constant defines** — NOT in analog (analog has none); ADD per CONTEXT.md right after the ABSPATH guard:
```php
define('POT_VERSION', '0.1.0');
define('POT_PLUGIN_FILE', __FILE__);
define('POT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('POT_PLUGIN_URL', plugin_dir_url(__FILE__));
```

**Flat `require_once` block** (analog lines 14-48) — MIRROR exactly; `github-updater.php` is ALWAYS the last require:
```php
require_once plugin_dir_path(__FILE__) . 'includes/class-ab-custom-statuses.php';
...
require_once plugin_dir_path(__FILE__) . 'includes/github-updater.php';
```
CHANGE to the `class-pot-*.php` list (plugin/activator/store/cron/admin) ending with `includes/github-updater.php`.

**Single `*_init()` on `plugins_loaded`** (analog lines 81-137) — MIRROR; instantiate each module via static `::init()`:
```php
function ab_we_init_plugin() {
    AB_Custom_Statuses::register_statuses();
    AB_Rest_Endpoint::init();
    AB_Customer_Overview::init();
    // ...one call per module
}
add_action('plugins_loaded', 'ab_we_init_plugin', 11);
```
CHANGE to `function pot_init()` calling `POT_Plugin::init()` (or each `POT_*::init()`). Keep priority `11` (after ab-webhook registers the `probetraining` status — needed in later phases, harmless now). NOTE: `custom-events-plugin.php:46` uses default priority and `new Class()` constructors — both idioms are valid; prefer static `init()` (dominant in ab-webhook).

**HPOS declaration** — NOT in analog; ADD per CONTEXT.md + STACK.md (guarded `class_exists`, on `before_woocommerce_init`):
```php
add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', POT_PLUGIN_FILE, true);
    }
});
```

**Activation / deactivation hooks** (analog lines 139-150) — MIRROR registration, REPLACE bodies:
```php
register_activation_hook(__FILE__, 'ab_we_on_activate');
function ab_we_on_activate() { AB_Custom_Statuses::register_statuses(); flush_rewrite_rules(); }
register_deactivation_hook(__FILE__, 'ab_we_on_deactivate');
function ab_we_on_deactivate() { flush_rewrite_rules(); AB_Bestandskunde_Reminder::deactivate(); }
```
CHANGE activation body → `POT_Activator::activate()` (dbDelta + schedule `pot_prune_events` cron + seed `pot_db_version`/`pot_retention_days`). CHANGE deactivation → `wp_clear_scheduled_hook('pot_prune_events')`. Do NOT call `flush_rewrite_rules()` (no rewrite rules in Phase 1).

---

### `includes/class-pot-admin.php` (controller, request-response)

**Analog:** `Input/ab-webhook-endpoint/includes/class-ab-customer-overview.php` (exact match: submenu under `parkourone`, cap `manage_options`, `<div class="wrap">`, enqueue gated on `parkourone_page_<slug>` hook).

**Static `init()` registering hooks** (lines 8-12):
```php
public static function init() {
    add_action('admin_menu', [__CLASS__, 'add_menu_page']);
    add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin_scripts']);
    add_action('wp_ajax_ab_get_customer_details', [__CLASS__, 'ajax_get_customer_details']);
}
```
Phase 1 placeholder: keep only `admin_menu`; drop AJAX/enqueue until Phase 4.

**Submenu under shared `parkourone` parent** (lines 14-23) — MIRROR, CHANGE slug/titles:
```php
public static function add_menu_page() {
    add_submenu_page(
        'parkourone', 'Kunden', 'Kunden', 'manage_options',
        'ab-customers', [__CLASS__, 'render_customers_page']
    );
}
```
CHANGE slug → e.g. `parkourone-campaign-tracking`, cap stays `manage_options`. ADD a fallback per CONTEXT.md: if the `parkourone` parent menu does not exist (theme inactive), register a top-level page instead so it is never orphaned (e.g. check `menu_page_url('parkourone', false)` is empty, then `add_menu_page(...)`). The analog assumes the parent always exists — this fallback is the one addition.

**Enqueue gated on screen hook id** (line 26) — the hook id is `parkourone_page_<slug>`:
```php
public static function enqueue_admin_scripts($hook) {
    if ($hook !== 'parkourone_page_ab-customers') { return; }
    // ...
}
```
Phase 1: no assets to enqueue (placeholder), but keep the gate pattern for Phase 4.

**Page render in `<div class="wrap">`** (lines 51-70) — MIRROR wrapper; Phase 1 body is a placeholder:
```php
public static function render_customers_page() { ?>
    <div class="wrap">
        <h1>Kundenübersicht</h1>
        <table class="wp-list-table widefat fixed striped"> ... </table>
    </div>
<?php }
```
CHANGE Phase 1 body to: `<h1>Campaign Tracking</h1><p>Coming soon — data store ready.</p>`. The `wp-list-table` markup is the Phase-4 reference, not Phase 1.

---

### `includes/github-updater.php` (service, request-response)

**Analog:** `Input/ab-webhook-endpoint/includes/github-updater.php` (527 lines). COPY VERBATIM, then re-prefix. `custom-events-plugin/includes/github-updater.php` is byte-near-identical, confirming this is the canonical template.

**Class header + properties** (lines 7-15) — CHANGE 4 values, keep structure:
```php
if (!defined('ABSPATH')) exit;
class AB_Webhook_GitHub_Updater {
    private $github_repo = 'monkeyspk/ab-webhook-endpoint';
    private $plugin_slug = 'ab-webhook-endpoint';
    private $check_interval = 3600; // 1 Stunde
    private $transient_key = 'ab_webhook_github_update_check';
    private $last_error = null;
```
CHANGE → class `POT_GitHub_Updater`; `$github_repo = 'monkeyspk/parkourone-campaign-tracking'`; `$plugin_slug = 'parkourone-campaign-tracking'`; `$transient_key = 'pot_github_update_check'`. KEEP `$check_interval = 3600`.

**Admin-only constructor + hooks** (lines 17-28) — copy verbatim.

**Shared GitHub token option** (lines 53, 249, 357) — DO NOT re-prefix; the token option is shared across all monkeyspk plugins:
```php
$has_token = !empty(get_option('parkourone_github_token', ''));
$token = get_option('parkourone_github_token', '');
```
KEEP `parkourone_github_token` exactly.

**Manual-check nonce/query prefixes** (lines 76-81, 144-170) — these `abw_` prefixes ARE re-prefixed → `pot_` (e.g. `pot_check_update`, `pot_nonce`, `pot_manual_update`, `pot_updated`, `pot_version`, `pot_error`, `pot_uptodate`).

**`.git-version` SHA scheme** (lines 302-318) — copy verbatim (reads/writes `<plugin-dir>/.git-version`, 7-char SHA):
```php
private function get_local_version() {
    $version_file = $this->get_plugin_dir() . '.git-version';
    if (file_exists($version_file)) { return trim(file_get_contents($version_file)); }
    return 'unknown';
}
private function get_plugin_dir() { return plugin_dir_path(dirname(__FILE__)); }
```

**Outbound HTTP error pattern** (lines 264-287) — Bearer header + `timeout` + `sslverify` fallback + `is_wp_error`/response-code + bracketed `error_log`. Copy verbatim; re-prefix the `error_log('AB Webhook Updater: ...')` tag → `error_log('[POT Tracking] ...')` for consistency.

**Self-instantiation at file end** (line 527): `new AB_Webhook_GitHub_Updater();` → `new POT_GitHub_Updater();`.

**Do NOT carry over:** `update-manager.php` and `version.txt` (dead legacy, CONTEXT.md + CONTEXT-FINDINGS.md). Add a sibling `.git-version` file (see below).

---

### `includes/class-pot-cron.php` (service, batch/event-driven)

**Analog:** `Input/ab-webhook-endpoint/includes/class-ab-bestandskunde-reminder.php` (the only cron-scheduling class in the references).

**`CRON_HOOK` constant + hook binding** (lines 15-19):
```php
const CRON_HOOK = 'ab_bestandskunde_reminder_check';
public static function init() {
    add_action(self::CRON_HOOK, [__CLASS__, 'check_and_send_reminders']);
    // ...
}
```
CHANGE → `const CRON_HOOK = 'pot_prune_events';` and bind `[__CLASS__, 'prune_events']`.

**Schedule daily event idempotently** (lines 252-258) — MIRROR the `wp_next_scheduled` guard; CONTEXT.md schedules on ACTIVATION (not `init`), so put this call in `POT_Activator::activate()`:
```php
public static function maybe_schedule_wp_cron() {
    if (function_exists('as_next_scheduled_action')) { return; } // Action Scheduler übernimmt
    if (!wp_next_scheduled(self::CRON_HOOK)) {
        wp_schedule_event(time(), 'daily', self::CRON_HOOK);
    }
}
```
SIMPLIFY: Phase 1 has no Action Scheduler dependency — drop the `as_*` branch and just `if (!wp_next_scheduled(self::CRON_HOOK)) wp_schedule_event(time(), 'daily', self::CRON_HOOK);`.

**Deactivation cleanup** (lines 620-630) — MIRROR; the simple WP-Cron branch is what CONTEXT.md wants:
```php
public static function deactivate() {
    $timestamp = wp_next_scheduled(self::CRON_HOOK);
    if ($timestamp) { wp_unschedule_event($timestamp, self::CRON_HOOK); }
    if (function_exists('as_unschedule_all_actions')) { as_unschedule_all_actions(self::CRON_HOOK); }
}
```
SIMPLIFY to `wp_clear_scheduled_hook('pot_prune_events');` (CONTEXT.md), call it from the plugin deactivation hook.

**Prune handler (NEW logic, no analog):** delete rows `WHERE event_type IN ('visit','click') AND created_at < (now - pot_retention_days)`. NEVER prune `booking` rows (business record). Default retention `180` days from option `pot_retention_days`. Route the DELETE through `POT_Store` (single gateway), not a raw `$wpdb` call here.

---

### `includes/class-pot-plugin.php` (bootstrap/orchestrator, event-driven)

**Analog:** the `ab_we_init_plugin()` body itself (`ab-webhook-endpoint.php:81-137`) — a flat list of `POT_*::init()` calls. If the plugin keeps init logic in the main file instead of a class, this file may not exist (Claude's discretion). If used: one static `init()` that wires up `POT_Activator`/`POT_Store`/`POT_Cron`/`POT_Admin` and registers the `before_woocommerce_init` HPOS declaration. No new pattern beyond the bootstrap excerpt above.

## Shared Patterns

### Static `init()` + `add_action` module idiom
**Source:** `class-ab-customer-overview.php:8-12`, `class-ab-bestandskunde-reminder.php:17-19`
**Apply to:** every `class-pot-*.php` (admin, cron, plugin). One class per `class-pot-<feature>.php`, PascalCase `POT_*`, a static `init()` that registers hooks, called once from `pot_init()`. (custom-events-plugin uses `new Class()` constructors — acceptable but prefer static init.)

### ABSPATH guard at top of every PHP file
**Source:** `ab-webhook-endpoint.php:10-12`, `github-updater.php:7`, `class-ab-customer-overview.php:2-4`
**Apply to:** all PHP files including `uninstall.php` (which uses `WP_UNINSTALL_PLUGIN` instead — see below).
```php
if (!defined('ABSPATH')) { exit; }
```

### Bracketed-prefix `error_log` diagnostics
**Source:** `github-updater.php:280,287` (`error_log('AB Webhook Updater: ...')`); CONTEXT-FINDINGS.md:80 mandates the bracketed tag.
**Apply to:** all `class-pot-*` files that log. Use `error_log('[POT Tracking] ...')`.

### Options minimal + `autoload=false` for non-per-request state
**Source:** CONTEXT.md "Specific Ideas"; `custom-events-plugin.php:100` (`get_option('custom_events_import_log', [], false)`).
**Apply to:** `pot_db_version`, `pot_retention_days`, and any future secret — pass `false` as the third arg to `update_option` / `add_option` so they are not autoloaded.

## No Analog Found

Files with no match in `Input/` (planner uses STACK.md / ARCHITECTURE.md / CONTEXT.md instead — these are the deliberate, signed-off deviations from the options-only house style):

| File | Role | Data Flow | Reason / Source to use |
|------|------|-----------|------------------------|
| `includes/class-pot-activator.php` | migration (dbDelta) | batch | No `dbDelta`/`CREATE TABLE` exists in any sibling (CONTEXT-FINDINGS.md:22,106). Use STACK.md schema (lines 50-76): one column per line, `PRIMARY KEY` two-space rule, `$wpdb->get_charset_collate()`, indices `(event_type, created_at)` + `(campaign, created_at)` + `order_id`. Run on activation AND on `pot_db_version` bump (admin_init guard). Seed `pot_retention_days = 180`. |
| `includes/class-pot-store.php` | model/data-gateway | CRUD + transform | No custom-table gateway exists in siblings. Use ARCHITECTURE.md C4 (Pattern 1, lines 108-127): single gateway with `insert_event(array $row)` + `aggregate_by_campaign($from,$to)`; both dashboard (Ph4) and pull-API (Ph5) call the SAME method. Phase 1 ships a manual-insert + aggregate smoke path. |
| `uninstall.php` | config/cleanup | batch | No `uninstall.php` exists in EITHER sibling (confirmed via `find Input/`). Build fresh per CONTEXT.md: guard `if (!defined('WP_UNINSTALL_PLUGIN')) exit;`, drop `{$wpdb->prefix}pot_events`, `delete_option` for all `pot_*` options, `wp_clear_scheduled_hook('pot_prune_events')`. |
| `.git-version` | config | n/a | Not an analog target — it is the SHA file CONSUMED by `github-updater.php:302-313`. Create a one-line file with the current 7-char commit SHA (or a placeholder until first push). Add `.git-version` + `.DS_Store` to `.gitignore`. |

## Metadata

**Analog search scope:** `Input/ab-webhook-endpoint/` (main file, `includes/github-updater.php`, `includes/class-ab-customer-overview.php`, `includes/class-ab-bestandskunde-reminder.php`), `Input/custom-events-plugin/custom-events-plugin.php`. Confirmed absence of `dbDelta`/`uninstall.php` via project-wide `find`/`grep`.
**Files scanned:** 5 reference PHP files read; 2 reference repos enumerated.
**Pattern extraction date:** 2026-05-31
