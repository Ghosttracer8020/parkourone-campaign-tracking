---
phase: 04-admin-dashboard
plan: 02
wave: 2
status: complete
files_modified:
  - assets/js/pot-dashboard.js
  - assets/css/pot-dashboard.css
requirements: [DASH-03, DASH-04]
---

# Summary — 04-02 Progressive-enhancement JS + CSS

## What was built
- **assets/js/pot-dashboard.js** (147 lines): `jQuery(document).ready(function($){…})`. Bails if
  `potDashboard` is undefined (page still works server-side). Binds a `refresh()` routine to the preset
  `change`, the custom from/to `change`, the `#pot-refresh` click, and the form `submit` (prevents native
  GET so refresh stays AJAX). `refresh()`: sets loading state (disables controls + `$spinner.addClass('is-active')`),
  POSTs to `potDashboard.ajaxurl` with `{action:'pot_metrics', nonce: potDashboard.nonce, preset, from, to}`
  (nonce key = `nonce`, matches `check_ajax_referer('pot_metrics','nonce')`). On success swaps
  `#pot-metrics-body` with `response.data.rows` (prebuilt, already-escaped markup — no client templating),
  updates `#pot-metrics-foot` from `.totals`, `#pot-range-label` from `.range`, and toggles `.pot-health-banner`
  from `.health`. Empty/blank rows leave the server's empty-state in place. Error / `success===false` shows a
  graceful inline `notice-error` (`#pot-ajax-error`) without wiping the table. `complete` always clears loading.
  `syncCustomVisibility()` shows the custom date inputs only for the `benutzerdefiniert` preset. No external libs.
- **assets/css/pot-dashboard.css** (50 lines): control-bar flex layout, inline WP spinner override,
  `.dashicons-warning.pot-warning` warning color (impossible-funnel marker, content not hidden),
  `.pot-health-banner` accent, totals-row emphasis. No `@import`, no external URL.

## Static verification (no browser/WP runtime here)
- `node --check assets/js/pot-dashboard.js` → OK (valid JS).
- JS greps: `jQuery(document).ready`, `potDashboard.ajaxurl`, `potDashboard.nonce`, `action: 'pot_metrics'`,
  `nonce: potDashboard.nonce`, `pot-metrics-body`, `spinner` + `is-active` — all present. No external CDN/URL.
- CSS: exists; `.pot-warning`/`dashicons-warning` rule present; no `@import`/`http(s)://`.

## Deferred (runtime — see root MANUAL-VERIFICATION.md)
Preset/custom-date change re-queries via AJAX and updates the body; spinner shows then controls re-enable;
empty range shows the empty-state; forced error shows graceful message; health banner toggles from payload;
with JS disabled the Plan 01 server render still works (progressive-enhancement check).
