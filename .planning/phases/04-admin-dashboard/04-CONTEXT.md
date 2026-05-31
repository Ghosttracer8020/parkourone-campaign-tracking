# Phase 4: Admin Dashboard - Context

**Gathered:** 2026-05-31
**Status:** Ready for planning
**Mode:** Auto-decided (autonomous; UI + behavior decisions resolved from CONTEXT-FINDINGS.md + research + Phase 1-3 implementation, no open questions)

<domain>
## Phase Boundary

A simple admin dashboard where staff read the per-campaign funnel (visits → clicks → bookings) and verify the numbers are correct for any chosen date range — BEFORE the data is ever exposed externally (Phase 5). Replaces the Phase 1 placeholder page under the existing `parkourone` menu. Native WordPress-admin UI (no charts, no JS framework, no build step) per the established house style.

In scope: DASH-01..04.
OUT of scope: the pull-API (Phase 5), compare-to-previous-period / unique-vs-total / CSV export (v2). This is the LAST internal-verification surface before the external API.
</domain>

<decisions>
## Implementation Decisions

### Data source (reuse, do not modify)
- Single read path: `POT_Store::aggregate_by_campaign($from, $to)` (Phase 1) returns rows `{campaign, visits, clicks, bookings}`, with empty/NULL campaign already bucketed under `POT_Store::UNATTRIBUTED` (`'(unattributed)'`). The dashboard computes all derived rates from these three counts — no new SQL outside the gateway. If a per-range helper is needed, ADD it to `POT_Store` (keep the gateway the single source so Phase 5's API reuses the exact same numbers — zero drift).
- Health: read option `pot_conversion_status` (`'ok'|'not_configured'`, set in Phase 2) to drive the dashboard health banner.

### Page & layout (DASH-01) — native wp-admin
- Convert the Phase 1 placeholder (`includes/class-pot-admin.php`, submenu under `parkourone`, cap `manage_options`, registered at `admin_menu` priority 999) into the real dashboard. Render inside `<div class="wrap"><h1>Campaign Tracking</h1>…`.
- Top: a date-range control bar. Below: a health banner (only when not ok). Below: the per-campaign table.
- Per-campaign table = `<table class="wp-list-table widefat fixed striped">` built with sprintf rows + `esc_html`/`esc_attr`/`number_format_i18n` (NOT WP_List_Table — matches sibling-plugin house style). Columns: Kampagne | Visits | Klicks | Buchungen | Conversion-Rate | Visit→Klick | Klick→Buchung. Include the `(unattributed)` row. A totals/summary row at the top or bottom.
- Default sort: by Buchungen desc, then Visits desc. (Sorting can be client-side or re-query; keep simple — server-ordered is fine.)

### Metrics (DASH-02)
- Conversion-Rate (headline) = bookings / visits (guard divide-by-zero → show "–" when visits = 0).
- Step rates: Visit→Klick = clicks / visits; Klick→Buchung = bookings / clicks. Drop-off = 1 − step rate (display the step rate as %, optionally drop-off in a tooltip/secondary). All percentages via a shared helper with divide-by-zero guards, formatted with `number_format_i18n(…, 1)`.
- Data-quality flag (ROADMAP SC2): if `clicks > visits` OR `bookings > clicks` (impossible-funnel), render the offending cell with a visible warning marker (e.g. a `⚠`/`dashicons-warning` + title attr "Unplausibel: …") instead of silently showing the ratio. Do NOT hide the row.

### Date range (DASH-03)
- Presets: Heute / 7 Tage / 30 Tage / Benutzerdefiniert. Default = 30 Tage. Render as a small button group or `<select>` + two `<input type="date">` (from/to) shown for "Benutzerdefiniert".
- TIMEZONE CORRECTNESS (important): events store `created_at` in UTC (`current_time('mysql', true)`). The picker is in site timezone. Convert the selected LOCAL day boundaries (from 00:00:00 local, to 23:59:59 local) to UTC before calling `aggregate_by_campaign` (use `wp_timezone()` / `get_option('gmt_offset')` → `DateTime`/`gmdate`). Document this so dashboard and API agree.
- Sanitize/validate range server-side: cap span (e.g. max 366 days), reject from > to, clamp future dates.

### Refresh (DASH-04) — AJAX
- AJAX action `wp_ajax_pot_metrics` (no `nopriv`): `check_ajax_referer('pot_metrics')` + `current_user_can('manage_options')` → read sanitized `preset`/`from`/`to` → query via the gateway → `wp_send_json_success(['rows'=>…, 'totals'=>…, 'health'=>…, 'range'=>…])`.
- Enqueue a small `assets/js/pot-dashboard.js` ONLY on the dashboard hook (`parkourone_page_…`), deps `['jquery']` (sibling-plugin convention), `wp_localize_script` with `{ajaxurl: admin_url('admin-ajax.php'), nonce: wp_create_nonce('pot_metrics')}`. Changing a preset or custom dates re-queries via AJAX and re-renders the table body; include a manual "Aktualisieren" button. Initial page load renders server-side (works without JS); AJAX is progressive enhancement.
- States: loading (spinner/disabled control), empty ("Keine Daten im gewählten Zeitraum"), error (graceful message), and the health banner when `pot_conversion_status !== 'ok'` ("Conversion-Tracking ist offline — ist das Plugin ab-webhook-endpoint aktiv? Buchungen werden derzeit nicht gezählt.").

### Claude's Discretion
- Exact control markup (button group vs select), whether sorting is server or client side, summary-row placement, the precise rate-helper signatures, and CSS polish (minimal, native wp-admin classes) — at Claude's discretion. No external chart library.
</decisions>

<code_context>
## Existing Code Insights

### Reusable assets (this repo)
- `includes/class-pot-store.php` — `aggregate_by_campaign($from,$to)`, `UNATTRIBUTED`. THE read path; add a range helper here if needed (keep it the single gateway).
- `includes/class-pot-admin.php` — Phase 1 placeholder page (submenu under `parkourone`, prio 999, `manage_options`, asset-enqueue gated on the dashboard hook). Convert this into the dashboard.
- `includes/class-pot-plugin.php` — orchestrator (wire AJAX handler + dashboard enqueue here).
- Phase 2 `pot_conversion_status` option → health banner.

### Ground-truth references
- `CONTEXT-FINDINGS.md` — admin-UI house style: `add_submenu_page('parkourone',…)`, `wp-list-table widefat fixed striped`, sprintf rows + `esc_*`, AJAX via `wp_ajax_<prefix>_*` + `check_ajax_referer` + cap + `wp_send_json_*`, assets gated on the page hook + `wp_localize_script`. NO in-house chart lib.
- `.planning/research/FEATURES.md` — funnel metric/rate/drop-off definitions; denominator notes; simple-UI constraint.
- `.planning/research/PITFALLS.md` — divide-by-zero, impossible ratios, denominator honesty.

### Analog sources (Input/, separate repos — copy patterns)
- `Input/ab-webhook-endpoint/includes/class-ab-customer-overview.php` / `class-ab-*-overview.php` — the canonical wp-list-table + AJAX + date-filter admin page to mirror.
- `Input/ab-webhook-endpoint/includes/class-ab-admin-menu-organizer.php` — submenu registration under `parkourone`.

### Integration points
- Menu parent `parkourone`; page hook `parkourone_page_<slug>` for asset gating.
- AJAX `wp_ajax_pot_metrics`; nonce `pot_metrics`.
- `POT_Store::aggregate_by_campaign`; option `pot_conversion_status`.
</code_context>

<specifics>
## Specific Ideas

- The dashboard numbers MUST equal what the Phase 5 API returns — both read through `POT_Store`. Bake a comment/contract that the aggregation lives ONLY in the gateway.
- Static verification (no PHP/WP/browser here): grep for `add_submenu_page`/page render, `wp-list-table`, `aggregate_by_campaign(`, the rate helpers with divide-by-zero guards, UTC conversion (`wp_timezone()`/`gmdate`), `wp_ajax_pot_metrics`, `check_ajax_referer('pot_metrics')`, `current_user_can('manage_options')`, `wp_send_json_`, enqueue gated on the page hook, `esc_html`/`esc_attr` on all output, the impossible-ratio warning marker, the health-banner read of `pot_conversion_status`. Runtime behaviors (pick range → table updates; AJAX refresh; warning shows on seeded impossible data; health banner on not_configured) → DEFERRED manual checklist.
- Accessibility: native wp-admin markup, `<th scope>` headers, button labels — no custom widgets.
</specifics>

<deferred>
## Deferred Ideas

- Compare-to-previous-period, unique-vs-total toggle, CSV export, API health indicator (live pull timestamp) → v2 / Phase 5.
- Charts/visualizations → out of scope (anti-feature); table only.
- Per-landing-page sub-breakdown → v2.
</deferred>
