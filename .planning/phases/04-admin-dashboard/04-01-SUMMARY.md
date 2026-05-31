---
phase: 04-admin-dashboard
plan: 01
wave: 1
status: complete
files_modified:
  - includes/class-pot-admin.php
requirements: [DASH-01, DASH-02, DASH-03, DASH-04]
---

# Summary — 04-01 Dashboard controller (server-side + AJAX)

## What was built
Rewrote the Phase 1 placeholder `POT_Admin` into the real per-campaign funnel dashboard.
Server-rendered first (works without JS); AJAX endpoint added for Plan 02 to consume.

- **Rate helper** `rate($num,$den)`: `(int)$den === 0` → `'–'`, else `number_format_i18n(pct,1) . ' %'`.
- **UTC window resolver** `resolve_range($preset,$from,$to)`: presets `heute`/`7t`/`30t`/`benutzerdefiniert`
  (default `30t`). Builds LOCAL day bounds (00:00:00 / 23:59:59) in `wp_timezone()`, converts each to
  UTC via `DateTimeZone('UTC')` → `format('Y-m-d H:i:s')`, returns the UTC pair + a `d.m.Y – d.m.Y` label.
  Conversion is the caller's job — never pushed into the gateway.
- **Sanitizer** `sanitize_range_input`: preset via `sanitize_key`; from/to via `/^\d{4}-\d{2}-\d{2}$/`
  (else null); future dates clamped to `current_time('Y-m-d')`; from>to swapped; span capped at 366d
  (pulls `from` forward); unexpected states logged with `[POT Tracking]` prefix.
- **render_page()**: re-checks `manage_options`; reads + sanitizes GET preset/from/to; resolves UTC window;
  calls `POT_Store::aggregate_by_campaign` ONCE. Renders control bar (preset `<select>` + custom date inputs
  + Aktualisieren button + spinner + range label), conditional health banner, and
  `<table class="wp-list-table widefat fixed striped">` with 7 `<th scope="col">` columns, `<tbody id="pot-metrics-body">`,
  `<tfoot id="pot-metrics-foot">`.
- **Shared row builder** `render_rows($rows)` + `render_totals($rows)`: used by BOTH render_page and the AJAX
  handler (byte-identical markup). Sort: bookings desc → visits desc. Counts via `number_format_i18n` + `esc_html`;
  rates via `rate()`. Impossible-funnel cells (clicks>visits OR bookings>clicks) append a
  `<span class="dashicons dashicons-warning pot-warning" title="…">` (esc_attr'd) — row never hidden.
  `(unattributed)` bucket row preserved (comes through from the gateway). Empty state: single colspan=7 row
  "Keine Daten im gewählten Zeitraum".
- **Health banner** `render_health_banner()`: rendered only when `get_option('pot_conversion_status') !== 'ok'`,
  with the locked offline copy, `notice-error` class, `.pot-health-banner`.
- **AJAX** `ajax_metrics()`: `check_ajax_referer('pot_metrics','nonce')` FIRST → `current_user_can('manage_options')`
  → sanitize → resolve → aggregate → `wp_send_json_success(['rows','totals','health','range'])`. No nopriv action.
- **init()**: kept `add_menu_page` at prio 999; added `admin_enqueue_scripts` + `wp_ajax_pot_metrics`.
  `enqueue_admin_scripts($hook)` gated on BOTH `parkourone_page_<slug>` AND `toplevel_page_<slug>`; enqueues
  `pot-dashboard` script (deps `['jquery']`, footer) + style; `wp_localize_script('pot-dashboard','potDashboard',{ajaxurl,nonce})`.
- `includes/class-pot-plugin.php` untouched.

## Contract for Plan 02 (JS)
- Form id `#pot-dashboard-controls` (method=get, hidden `page` field).
- Preset `<select id="pot-preset" name="preset">` values: `heute` / `7t` / `30t` / `benutzerdefiniert`.
- Custom inputs `#pot-from`, `#pot-to` (`name="from"`/`name="to"`), wrapped in `.pot-custom-dates`.
- Button `#pot-refresh` ("Aktualisieren"); spinner `<span class="spinner" id="pot-spinner">`; range label `#pot-range-label`.
- Body `<tbody id="pot-metrics-body">`; totals `<tfoot id="pot-metrics-foot">`.
- Health banner element class `.pot-health-banner`.
- AJAX: POST `potDashboard.ajaxurl`, `{action:'pot_metrics', nonce, preset, from, to}` (nonce key = `nonce`).
- Response: `response.data.rows` (escaped tbody markup), `.totals` (tfoot markup), `.health` (status slug), `.range` (label).

## Static verification (no PHP/WP runtime here)
- Structural lint: opens `<?php`; braces 53/53, parens 236/236 — BALANCED.
- All grep gates pass; NO `$wpdb`/SQL in code (only doc-comment mention); NO `wp_ajax_nopriv` registration.
- class-pot-plugin.php diff empty.

## Deferred (runtime — see root MANUAL-VERIFICATION.md)
Open page as admin shows 30d table incl. (unattributed); preset/custom re-query the UTC window; seeded
impossible-funnel shows marker (row not hidden); health banner on not_configured; numbers match Phase 5 API;
AJAX refresh works and page renders without JS.
