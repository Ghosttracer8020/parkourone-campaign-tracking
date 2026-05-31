---
phase: 05-secured-pull-rest-api
plan: 01
type: summary
status: complete
---

# Plan 05-01 Summary — Secured Pull REST API (wave 1)

## What was built
- **`includes/class-pot-metrics.php` (new)** — `POT_Metrics` static utility. `rate_value($num,$den): ?float` returns `null` when `(int)$den===0`, else `round(($num/$den)*100, 1)`. `MAX_SPAN_DAYS = 366`. The SINGLE rate source shared by dashboard + API (zero drift).
- **`includes/class-pot-admin.php` (refactor)** — `rate()` now delegates to `POT_Metrics::rate_value()` and formats the German string (`number_format_i18n($v,1).' %'` / `'–'`). No behavioral change to the dashboard. No SQL added (none existed).
- **`includes/class-pot-api.php` (new)** — `POT_Api`, the external trust boundary:
  - `get_or_create_secret()` → `get_option('pot_api_secret')`; lazily mints `wp_generate_password(32,false)` and `add_option('pot_api_secret', $s, '', false)` (autoload=false). Self-heals; never logged.
  - `check_bearer()` permission_callback — reads `$request->get_header('authorization')`, requires `Bearer ` prefix (`stripos !== 0`), strips 7 chars, **length pre-check** then `hash_equals`. Every failure → identical `WP_Error('pot_unauthorized', ..., ['status'=>401])`. NEVER `__return_true`, NEVER a nonce, no 500, no CORS header.
  - `register_routes()` → `register_rest_route('pot/v1','/metrics', ['methods'=>'GET', permission_callback=>check_bearer, args from/to with validate+sanitize])`.
  - `get_metrics()` → calls `POT_Store::aggregate_by_campaign($from_utc,$to_utc)` (ONLY read path; NO $wpdb). Builds camelCase payload; rates via `POT_Metrics::rate_value`. `generatedAt = gmdate('c')`, `status` from `pot_conversion_status`.
  - Range resolution interprets from/to as UTC, default last-30-days, clamp future, swap, 366-day cap.
- **Wiring** — `parkourone-campaign-tracking.php` requires metrics + api BEFORE github-updater (still last). `class-pot-plugin.php` calls `POT_Api::init()`.

## Static verification (no PHP/WP runtime — structural proxy)
- metrics/api/main/orchestrator: `<?php` open + balanced braces — PASS.
- `class POT_Metrics` + `rate_value` present; dashboard delegates (2 occurrences) — PASS.
- GET `pot/v1/metrics` route; permission_callback `check_bearer` — PASS.
- `get_header('authorization')` + length pre-check + `hash_equals` + `['status'=>401]`; `'status'=>500` count = 0 — PASS.
- No `__return_true` in non-comment code — PASS.
- No `$wpdb`/SELECT/INSERT in any **non-comment code line** (only zero-drift contract docblocks mention `$wpdb`); calls `POT_Store::aggregate_by_campaign` — PASS.
- `wp_generate_password(32` + `pot_api_secret` autoload=false — PASS.
- Payload: `gmdate('c')`, `generatedAt`, `conversionRate`/`visitToClick`/`clickToBooking`, status from `pot_conversion_status` — PASS.
- No `Access-Control-Allow-Origin`; no `error_log` referencing the secret — PASS.

## Deferred manual runtime checks (staging — no runtime here)
1. Valid `Authorization: Bearer <pot_api_secret>` → HTTP 200 and per-campaign numbers identical to the dashboard for the same range.
2. Missing / empty / wrong Bearer → HTTP 401 (never 500).
3. Secret never appears in any log.

### Ready-to-paste curl (Statusboard receiver session)
```bash
curl -s -H "Authorization: Bearer <pot_api_secret>" \
  "https://berlin.parkourone.com/wp-json/pot/v1/metrics?from=2026-05-01&to=2026-05-31"
```

### Exact payload JSON shape
```json
{
  "generatedAt": "2026-05-31T12:00:00+00:00",
  "status": "ok",
  "range": { "from": "2026-05-01", "to": "2026-05-31", "timezone": "UTC" },
  "totals": { "visits": 1234, "clicks": 210, "bookings": 18, "conversionRate": 1.5 },
  "campaigns": [
    {
      "campaign": "sommercamp-2026",
      "visits": 800, "clicks": 140, "bookings": 12,
      "conversionRate": 1.5, "visitToClick": 17.5, "clickToBooking": 8.6
    },
    {
      "campaign": "(unattributed)",
      "visits": 434, "clicks": 70, "bookings": 6,
      "conversionRate": 1.4, "visitToClick": 16.1, "clickToBooking": 8.6
    }
  ]
}
```
Notes: counts are integers; rates are percentages to 1 decimal; a rate is JSON `null` when its denominator is 0; `status` ∈ `ok|not_configured|stale`.
