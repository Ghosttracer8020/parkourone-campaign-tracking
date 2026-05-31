# Phase 4 — Code Review

**Reviewed:** 2026-05-31
**Depth:** standard (per-file, language-specific checks)
**Status:** clean — 0 Critical / 0 Warning / 0 Info
**Scope (from SUMMARY files):**
- includes/class-pot-admin.php
- assets/js/pot-dashboard.js
- assets/css/pot-dashboard.css

## Checks performed

### includes/class-pot-admin.php (PHP, WordPress admin)
- **Output escaping:** every table cell goes through `esc_html(number_format_i18n(...))` for counts,
  `esc_html(self::rate(...))` for rates, `esc_html()`/`esc_attr()` for text/attributes. The three
  `echo $banner_html / $rows_html / $totals_html` sites emit strings that are themselves fully escaped
  at construction (render_health_banner / render_rows / render_totals — all literals or esc_*-wrapped
  `sprintf` args). No raw `$_GET`/`$_POST` is ever echoed. CLEAN.
- **Input handling:** `wp_unslash` applied to all 6 raw request reads; `sanitize_key` on preset;
  `/^\d{4}-\d{2}-\d{2}$/` on from/to; future clamp; from<=to swap; 366-day span cap. CLEAN.
- **AJAX security:** `check_ajax_referer('pot_metrics','nonce')` is the FIRST statement in `ajax_metrics`,
  followed by `current_user_can('manage_options')` before any work; `wp_send_json_error` on failure.
  Only `wp_ajax_pot_metrics` is registered — no nopriv. Nonce string identical on create + verify. CLEAN.
- **Capability:** `render_page()` re-checks `manage_options` before any output. CLEAN.
- **SQL boundary:** no `$wpdb`/`SELECT`/`GROUP BY` anywhere in code (single doc-comment mention only).
  All aggregation via `POT_Store::aggregate_by_campaign` — zero drift with the future Phase 5 API. CLEAN.
- **Timezone:** local day bounds resolved in `wp_timezone()`, converted to UTC via `DateTimeZone('UTC')`
  before the gateway call; conversion never leaks into the gateway. CLEAN.
- **PHP 8.1 target (header):** `<=>` spaceship in `usort`, `DateTime`/`DateTimeZone`, typed array shapes —
  all supported. The span-cap `diff()->days` uses two dates parsed with identical wall-clock time, so the
  whole-day difference is correct. CLEAN.
- **Divide-by-zero:** `rate()` guards `(int)$den === 0 → '–'`. CLEAN.

### assets/js/pot-dashboard.js (jQuery, progressive enhancement)
- Reads config from localized `potDashboard` (no hardcoded URL); bails if undefined.
- Nonce sent under the `nonce` key (matches server). Inserts only the server-built, pre-escaped
  `response.data.rows` into `#pot-metrics-body` — no client-side templating of raw strings, so no new
  DOM-XSS sink is introduced.
- Guards `response.success !== true`; graceful `showError` that never wipes the table; `complete` always
  clears the loading state. Form `submit` is intercepted so refresh stays AJAX while the page still works
  without JS. CLEAN.

### assets/css/pot-dashboard.css
- Minimal; no `@import`, no external URL; styles only the classes Plan 01 emits. CLEAN.

## Findings
None. No fixes required.
