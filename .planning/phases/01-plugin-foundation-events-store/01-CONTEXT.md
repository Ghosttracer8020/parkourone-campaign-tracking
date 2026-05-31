# Phase 1: Plugin Foundation & Events Store - Context

**Gathered:** 2026-05-31
**Status:** Ready for planning
**Mode:** Auto-decided (autonomous; grey areas resolved from CONTEXT-FINDINGS.md + research, no open questions)

<domain>
## Phase Boundary

Deliver a clean, house-style WordPress plugin that activates without fatals (even when WooCommerce or `ab-webhook-endpoint` are inactive) and owns a performant, retention-bounded custom events store that every later phase reads/writes through. Also wire the GitHub self-updater and a placeholder admin submenu under the existing `parkourone` menu. NO tracking, NO conversion logic, NO dashboard UI, NO API yet — those are Phases 2-5.

Covers: INFRA-01..05.
</domain>

<decisions>
## Implementation Decisions

### Naming & Structure (locked)
- Plugin slug / text domain: `parkourone-campaign-tracking`. Main file: `parkourone-campaign-tracking.php` at plugin root with standard WP header (Author: Pierre Biege, bare-number Version e.g. `0.1.0`, `Requires PHP: 8.1`, `Requires at least: 6.5`, `WC requires at least`, `Update URI` left to updater).
- PHP class prefix `POT_`, function/hook/option prefix `pot_`, constants `POT_*` (e.g. `POT_VERSION`, `POT_PLUGIN_DIR`, `POT_PLUGIN_URL`, `POT_PLUGIN_FILE`).
- Bootstrap exactly like sibling plugins: ABSPATH guard → define constants → flat `require_once` of `includes/class-pot-*.php` (ending with `includes/github-updater.php`) → single `pot_init()` hooked on `plugins_loaded`. Activation/deactivation via `register_activation_hook`/`register_deactivation_hook`; uninstall via root `uninstall.php`.
- Mirror `Input/ab-webhook-endpoint` and `Input/custom-events-plugin` house style 1:1 (see CONTEXT-FINDINGS.md "Plugin house-style & admin UI conventions").

### Events Store (locked)
- Custom table `{$wpdb->prefix}pot_events` created via `dbDelta()` on activation (deliberate, signed-off deviation from the options-only house style — required for time-series date-range queries).
- Columns: `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY; `event_type` VARCHAR(16) NOT NULL (`visit`|`click`|`booking`); `campaign` VARCHAR(191) NULL; `source` VARCHAR(191) NULL; `medium` VARCHAR(191) NULL; `landing_path` VARCHAR(255) NULL; `session_id` VARCHAR(64) NULL; `order_id` BIGINT UNSIGNED NULL; `event_ref` BIGINT UNSIGNED NULL (e.g. event CPT id); `created_at` DATETIME NOT NULL. Use `$wpdb->get_charset_collate()`.
- Indices: KEY `event_type_created` (`event_type`,`created_at`); KEY `campaign_created` (`campaign`,`created_at`). Add KEY on `order_id` for idempotency lookups in Phase 2.
- Schema versioning: store `pot_db_version` option; run dbDelta on activation AND on version bump (admin_init guard) so future migrations are safe.
- `POT_Store` class is the SINGLE gateway: `insert_event(array $row)` and `aggregate_by_campaign($from, $to)` (the latter returns per-campaign visit/click/booking counts; it will feed BOTH the dashboard (Phase 4) and the pull-API (Phase 5) — zero drift). Phase 1 ships these methods with a manual-insert + aggregate smoke path; richer queries grow in later phases.

### HPOS / WooCommerce (locked)
- Declare WooCommerce HPOS compatibility: on `before_woocommerce_init`, call `\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', POT_PLUGIN_FILE, true)` (guarded by `class_exists`). All future order access uses `wc_get_order()` / order CRUD — never `get_post_meta` on orders.
- WooCommerce is an optional soft-dependency at this phase: absence must not fatal.

### Self-Updater (locked)
- Copy `Input/ab-webhook-endpoint/includes/github-updater.php`, rename class to `POT_GitHub_Updater`, set `$github_repo = 'monkeyspk/parkourone-campaign-tracking'` and `$plugin_slug` accordingly. Reuse the shared `parkourone_github_token` option and the `.git-version` SHA scheme. Do NOT carry over `update-manager.php` or `version.txt`. Create a `.git-version` file.

### Retention & Uninstall (locked)
- On activation, schedule a daily WP-Cron event `pot_prune_events`. Handler deletes rows WHERE `event_type IN ('visit','click') AND created_at < (now - retention)`. Retention default = 180 days, stored as option `pot_retention_days` (constant default, not yet a UI). NEVER prune `booking` rows (business record).
- On deactivation: clear the scheduled cron (`wp_clear_scheduled_hook('pot_prune_events')`).
- `uninstall.php`: drop the `pot_events` table, delete all `pot_*` options (including any secret added later), and clear scheduled hooks. Guard with `WP_UNINSTALL_PLUGIN`.

### Admin shell (locked)
- Register a submenu page under the existing theme-owned top-level menu slug `parkourone` via `add_submenu_page('parkourone', ...)`, capability `manage_options`, rendered in `<div class="wrap"><h1>…`. Phase 1 = placeholder ("Coming soon — data store ready"); the real dashboard is Phase 4. If the `parkourone` parent menu does not exist (theme inactive), register a top-level fallback so the page is never orphaned.

### Claude's Discretion
- Exact file split among `includes/class-pot-*.php` (e.g. `class-pot-activator.php`, `class-pot-store.php`, `class-pot-cron.php`, `class-pot-admin.php`, `class-pot-plugin.php`), column nullability fine-tuning, and internal method signatures beyond the two named gateway methods — at Claude's discretion, following sibling-plugin conventions.
</decisions>

<code_context>
## Existing Code Insights

### Primary reference (read these)
- `CONTEXT-FINDINGS.md` (repo root) — verified house-style, github-updater pattern, HMAC/`hash_equals` primitives, admin-menu/`wp-list-table` conventions, and the statusboard contract. **Treat as ground truth.**
- `.planning/research/STACK.md` — versions (WP 7.0 / Woo 10.8 / PHP 8.1 floor), dbDelta schema/index guidance, retention/pruning, PHPCS/WPCS, "what NOT to use".
- `.planning/research/ARCHITECTURE.md` — the `POT_Store` single-gateway design (C4) and dependency-ordered build.
- `.planning/research/PITFALLS.md` — uninstall-cleanup gap, autoload bloat, index-on-seeded-table verification (`EXPLAIN`).

### Reusable assets (in `Input/`, separate git repos — copy patterns, do not depend on them)
- `Input/ab-webhook-endpoint/includes/github-updater.php` — the updater to copy & re-prefix.
- `Input/ab-webhook-endpoint/ab-webhook-endpoint.php` & `Input/custom-events-plugin/custom-events-plugin.php` — bootstrap/header/`*_init()` patterns.
- `Input/ab-webhook-endpoint/includes/class-ab-*-overview.php` / `class-ab-admin-menu-organizer.php` — admin submenu + `wp-list-table` patterns (for the placeholder + later phases).

### Integration points
- Parent admin menu slug `parkourone` (owned by the theme/sibling plugins).
- Shared option `parkourone_github_token` (updater auth).
- WooCommerce `before_woocommerce_init` hook (HPOS declaration).
</code_context>

<specifics>
## Specific Ideas

- Keep options minimal and `autoload=false` for anything not needed on every request (avoid autoload bloat — PITFALLS).
- After creating the table, the plan should include a verification that the composite indices exist (`SHOW INDEX`) and that `aggregate_by_campaign()` returns correct counts from a seeded insert — not an empty-table check.
- No build step, no Composer runtime deps (PHPCS/WPCS are dev-only, never ship `vendor/`).
</specifics>

<deferred>
## Deferred Ideas

- Configurable retention window as admin UI → v2 (RETAIN-01); Phase 1 hardcodes the default option value.
- Daily rollup/aggregate table → deferred; current visit volume is low, raw + indexed table is sufficient (per ARCHITECTURE.md open question).
</deferred>
