# Walking Skeleton — ParkourONE Campaign Tracking

**Phase:** 1
**Generated:** 2026-05-31

## Capability Proven End-to-End

A site administrator can activate the plugin and have it create its `wp_pot_events` store, round-trip one event through the `POT_Store` gateway (insert → `aggregate_by_campaign` read-back), and see a placeholder admin page under the existing `parkourone` menu — with no fatal even when WooCommerce or `ab-webhook-endpoint` are inactive.

## Architectural Decisions

| Decision | Choice | Rationale |
|---|---|---|
| Plugin runtime | Pure PHP 8.1+ (test to 8.3), no build step, no Composer runtime deps | House style (CONTEXT-FINDINGS) + portability of `berlin.parkourone.com`; PHPCS/WPCS are dev-only, `vendor/` never ships (STACK.md). |
| Host platform | WordPress min 6.9 / tested 7.0; WooCommerce min 8.2 / tested 10.8 | WP 7.0 + Woo 10.8 May-2026 baseline; Woo 8.2 is where HPOS became default (STACK.md). |
| File / class layout | Root `parkourone-campaign-tracking.php` → ABSPATH guard → constants → flat `require_once includes/class-pot-*.php` ending with `includes/github-updater.php` → single `pot_init()` on `plugins_loaded` priority 11. One class per file, `POT_*` PascalCase, static `init()` registering hooks. | Mirrors `ab-webhook-endpoint` / `custom-events-plugin` 1:1 (PATTERNS.md, CONTEXT-FINDINGS). |
| Naming | Slug/text-domain `parkourone-campaign-tracking`; class prefix `POT_`; function/hook/option prefix `pot_`; constants `POT_*`. Author: Pierre Biege; bare-number Version. | Locked in 01-CONTEXT.md. |
| Data layer | Custom `{$wpdb->prefix}pot_events` table via `dbDelta` (sign-off-gated deviation from the options-only house style). Indices `(event_type,created_at)`, `(campaign,created_at)`, `order_id`. `$wpdb->get_charset_collate()`. Schema versioned via `pot_db_version` option. | Time-series date-range GROUP BY queries are not performant on options/post-meta (STACK.md, ARCHITECTURE.md). Indices match the real dashboard/pull-API query (Pitfall 10). |
| Store access | `POT_Store` is the SINGLE gateway: `insert_event(array)` + `aggregate_by_campaign($from,$to)`; later a prune `delete_raw_older_than($cutoff)`. No component touches `$wpdb` for `pot_events` directly. | One query, zero drift between dashboard (Phase 4) and pull-API (Phase 5) — ARCHITECTURE.md Pattern 1. |
| WooCommerce coupling | Declare HPOS `custom_order_tables` compatibility on `before_woocommerce_init` (class_exists-guarded). All future order access via `wc_get_order()` / order CRUD — never `get_post_meta`. WooCommerce is a soft dependency (absence must not fatal). | HPOS default since Woo 8.2 (STACK.md, INFRA-02). |
| Admin placement | `add_submenu_page('parkourone', …)`, cap `manage_options`, render in `<div class="wrap"><h1>`; top-level `add_menu_page` fallback if the `parkourone` parent is absent. | Theme-owned shared menu (CONTEXT-FINDINGS); fallback prevents an orphaned page (01-CONTEXT.md). |
| Self-update | `includes/github-updater.php` copied verbatim from `ab-webhook-endpoint`, re-prefixed to `POT_GitHub_Updater`, repo `monkeyspk/parkourone-campaign-tracking`, shared `parkourone_github_token` option + `.git-version` 7-char SHA scheme. `update-manager.php`/`version.txt` NOT carried over. | Established delivery mechanism for every monkeyspk plugin (CONTEXT-FINDINGS, INFRA-03). |
| Retention | Daily WP-Cron `pot_prune_events` scheduled on activation; prunes only `visit`/`click` rows older than `pot_retention_days` (default 180); never `booking` rows. | DB bloat + slow queries at visit volume (Pitfall 10); retention not yet a UI (RETAIN-01 deferred to v2). |
| Uninstall | Root `uninstall.php` guarded by `WP_UNINSTALL_PLUGIN`: drop `pot_events`, delete all `pot_*` options, clear cron. Shared `parkourone_github_token` left intact. | No orphaned data / Datensparsamkeit (Pitfall 8, "Looks Done But Isn't"). |
| Options autoload | All plugin state options (`pot_db_version`, `pot_retention_days`, future secret) stored with `autoload=false`. | Avoid site-wide `alloptions` bloat (Pitfall 11). |
| DSGVO posture | NO `ip` / `user_agent` columns; `session_id` pseudonymous only; only aggregates leave via the (later) pull-API. | Data minimization (Pitfall 8). |

## Stack Touched in Phase 1

- [x] Project scaffold (root file, constants, flat require chain, `pot_init()` on `plugins_loaded`, lint via `php -l`)
- [x] Routing — N/A this phase (no REST routes / front-end beacon until Phase 3); admin page registered under `parkourone`
- [x] Database — real write (`POT_Store::insert_event`) AND real read (`POT_Store::aggregate_by_campaign`) against the dbDelta `pot_events` table
- [x] UI — placeholder admin page under the `parkourone` menu (`manage_options`, with top-level fallback)
- [x] Deployment — documented local full-stack run: copy/symlink into `wp-content/plugins/`, activate, verify via `SHOW INDEX` + `wp eval` insert/aggregate (Plan 01 Task 3 checkpoint)

## Out of Scope (Deferred to Later Slices)

- Front-end tracker JS, visit beacon, CTA-click capture, consent/admin/bot gates — Phase 3 (CAPTURE-*)
- Inbound REST ingest route (`pot/v1/event`) — Phase 3
- Conversion listener (`woocommerce_order_status_probetraining` + fallback), idempotency flag, attribution bridge (cookie → order meta) — Phase 2 (CONVERT-*, ATTRIB-*)
- Per-campaign funnel dashboard table, date-range presets, AJAX refresh — Phase 4 (DASH-*)
- Secured pull REST API (`GET pot/v1/metrics`, Bearer + `hash_equals`), managed secret option — Phase 5 (API-*)
- Theme-analytics retirement + parity check — Phase 3 (MIGRATE-*)
- Configurable retention window as admin UI (RETAIN-01), daily rollup table, CSV export, charts — v2 / out of scope
- Richer `POT_Store` query methods (drop-off, conversion-rate flags, `(direct)`/`(unattributed)` bucket naming) — grow in Phases 2/4

## Subsequent Slice Plan

Each later phase adds one vertical slice on top of this skeleton without altering its architectural decisions (the `POT_Store` gateway signature, the `pot_events` schema, the `parkourone` admin menu, the bootstrap shape):

- **Phase 2 — Conversion & Attribution Bridge:** server-side `booking` rows via WooCommerce status hooks (idempotent, graceful degradation) + first-touch UTM cookie → order-meta bridge. Writes through `POT_Store::insert_event`.
- **Phase 3 — Consent-Gated Client Capture & Theme Retirement:** `visit`/`click` rows via consent-gated front-end beacon + nonce-gated ingest route; retire the theme tracker with a parity check.
- **Phase 4 — Admin Dashboard:** per-campaign funnel table reading `POT_Store::aggregate_by_campaign`, date-range presets + AJAX, under the `parkourone` menu created here.
- **Phase 5 — Secured Pull REST API:** `GET pot/v1/metrics` reusing the SAME `aggregate_by_campaign` aggregation, Bearer + `hash_equals`, camelCase + `generatedAt`, managed secret option (added to `uninstall.php` cleanup).
