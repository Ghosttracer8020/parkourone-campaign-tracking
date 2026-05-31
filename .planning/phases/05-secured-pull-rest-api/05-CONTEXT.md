# Phase 5: Secured Pull REST API - Context

**Gathered:** 2026-05-31
**Status:** Ready for planning
**Mode:** Auto-decided (autonomous; contract resolved from CONTEXT-FINDINGS.md statusboard section + research + Phase 1-4 implementation, no open questions)

<domain>
## Phase Boundary

The ONE Statusboard can pull contract-shaped, aggregated per-campaign metrics over an authenticated read-only endpoint that reuses the EXACT same `POT_Store` aggregation as the dashboard (zero drift). Final phase. Only the WordPress SENDER side is built here — the Statusboard receiver/cron is the user's separate session.

In scope: API-01..04.
OUT of scope: the Statusboard receiver route + its cron (separate session); raw per-event export, push-webhook, CSV (out of scope / v2). No charts.
</domain>

<decisions>
## Implementation Decisions

### Endpoint (API-01) — read-only pull
- New `includes/class-pot-api.php`: register `GET pot/v1/metrics` on `rest_api_init`. Read-only; no writes. Reuses `POT_Store::aggregate_by_campaign($from,$to)` — aggregation lives ONLY in the gateway, so API numbers == dashboard numbers.
- Query params: `from`, `to` (YYYY-MM-DD or ISO-8601, interpreted as UTC — the store's `created_at` is UTC). Default = last 30 days (UTC) when omitted. Validate: from<=to, span cap (366d), clamp future. `validate_callback`/`sanitize_callback` on args (mirror ab-rest-endpoint's args pattern, but WITH real auth — see below).
- Optional `campaign` filter param is OUT of scope for v1 (return all campaigns incl. `(unattributed)`).

### Auth (API-02) — Bearer + constant-time, NEVER __return_true
- `permission_callback` (NOT `__return_true` — that is the explicit anti-pattern in ab-webhook-endpoint/theme): read `Authorization: Bearer <secret>` header (`$request->get_header('authorization')`), strip the `Bearer ` prefix, and compare to the stored secret with `hash_equals()` AFTER a length pre-check. Reject missing/empty/mismatch with HTTP 401 (`WP_Error('pot_unauthorized', ..., ['status'=>401])`) — never 500, never reveal whether the secret exists. NEVER log the secret. No permissive CORS headers (do not send `Access-Control-Allow-Origin: *`). Mirror the constant-time compare idiom from `Input/ab-webhook-endpoint/includes/helper-functions.php` (`hash_equals`).
- Mirrors the Statusboard's own auth pattern (Bearer + timing-safe compare, like its `CRON_SECRET`) so the receiver, when built, matches.

### Secret management (API-04)
- Secret stored in a dedicated `autoload=false` option `pot_api_secret`. Accessor `POT_Api::get_or_create_secret()`: if the option is missing/empty, generate via `wp_generate_password(32, false)` and persist (robust regardless of activation timing — do NOT rely solely on the Phase 1 activator, which predates this phase).
- A small settings UI: add an "API"/"Statusboard" section — either a tab on the existing dashboard page or a dedicated settings subpage under `parkourone` (cap `manage_options`). It shows: the full endpoint URL (`rest_url('pot/v1/metrics')`), the current secret (masked with a reveal/copy), and a "Secret neu generieren" button (POST self-form or AJAX with nonce + cap → regenerates `pot_api_secret`). Document that regenerating breaks any configured Statusboard until the new secret is pasted there.

### Payload (API-03) — Statusboard-compatible
- `wp_send_json`-style response via the REST controller returning an array (auto-encoded). camelCase fields + `generatedAt` ISO-8601 (`gmdate('c')`) + a `status` field. Shape:
  - `generatedAt` (ISO-8601 UTC)
  - `status`: `'ok' | 'not_configured' | 'stale'` — derived from option `pot_conversion_status` (Phase 2): if conversion tracking is offline → `'not_configured'` so the Statusboard surfaces a health state instead of trusting a zero. (`'stale'` reserved for forward-compat.)
  - `range`: `{ from, to, timezone: 'UTC' }`
  - `totals`: `{ visits, clicks, bookings, conversionRate }`
  - `campaigns`: array of `{ campaign, visits, clicks, bookings, conversionRate, visitToClick, clickToBooking }` (same derived rates + divide-by-zero handling as the dashboard; include the `(unattributed)` bucket).
- Statusboard store uses `.passthrough()` (extra fields tolerated) → additive/backward-compatible. Keep numeric fields as numbers, rates as numbers (e.g. 0.0–100.0 or 0–1 — pick ONE and document; recommend percentages to 1 decimal to match the dashboard, documented in the payload contract comment).

### Shared rate logic
- Reuse the SAME rate helper as the dashboard (Phase 4). If Phase 4 placed it on `POT_Admin`, refactor it to a shared location (e.g. a static `POT_Metrics::rates()` helper or onto `POT_Store`) so the API and dashboard cannot drift. Aggregation stays in `POT_Store`.

### Claude's Discretion
- Whether the settings UI is a dashboard tab vs a subpage; secret reveal/copy UX; exact rate unit (percent vs ratio) as long as it's documented and matches the dashboard; whether to add a lightweight `POST` health/no-op — at Claude's discretion. Keep it minimal and secure.
</decisions>

<code_context>
## Existing Code Insights

### Reusable assets (this repo)
- `includes/class-pot-store.php` — `aggregate_by_campaign($from,$to)` (UTC bounds), `UNATTRIBUTED`. THE read path; API and dashboard share it.
- Phase 4 dashboard rate helper + UTC handling (`includes/class-pot-admin.php`) — reuse / refactor to shared so API matches dashboard exactly.
- `includes/class-pot-plugin.php` — orchestrator; wire `POT_Api::init()`.
- Phase 2 option `pot_conversion_status` → API `status` field.
- Phase 1 settings/option conventions (`autoload=false`).

### Ground-truth references
- `CONTEXT-FINDINGS.md` — STATUSBOARD CONTRACT: Bearer + constant-time compare (like CRON_SECRET/timingSafeEqual), camelCase fields, `generatedAt` ISO-8601, `status` enum `'ok'|'stale'|'failed'|'not_configured'`, `.passthrough()` store; runs on Vercel (project one-statusboard). The insecure `permission_callback => '__return_true'` on every ab/v1 route is the explicit ANTI-PATTERN — do not copy.
- `.planning/research/STACK.md` — shared Bearer secret + hash_equals (NOT Application Passwords); HMAC is a documented stronger upgrade but NOT required for v1.

### Analog sources (Input/, separate repos — copy patterns, FIX the auth)
- `Input/ab-webhook-endpoint/includes/class-ab-rest-endpoint.php` — `register_rest_route` + args/validate_callback shape. REPLACE its `__return_true` permission_callback with a real Bearer + `hash_equals` check.
- `Input/ab-webhook-endpoint/includes/helper-functions.php` — `hash_equals` constant-time compare; `wp_generate_password` for minting the secret.

### Integration points
- REST `GET pot/v1/metrics`; option `pot_api_secret` (autoload=false); option `pot_conversion_status` (status field); `POT_Store::aggregate_by_campaign`; settings UI under `parkourone` (nonce + manage_options for regenerate).
</code_context>

<specifics>
## Specific Ideas

- API numbers MUST equal dashboard numbers for the same range — both call `POT_Store::aggregate_by_campaign` with UTC bounds and the shared rate helper. Add a static check that the API does NOT contain its own `$wpdb`/SELECT.
- Static verification (no PHP/WP runtime): grep for `register_rest_route('pot/v1', '/metrics'` with `methods => 'GET'`, the permission_callback using `get_header('authorization')` + `hash_equals` + 401 + ABSENCE of `__return_true`, no secret in `error_log`, no `Access-Control-Allow-Origin`, `wp_generate_password(32`, option `pot_api_secret` with `autoload`=>false (or add_option 4th arg 'no'), `generatedAt`/`gmdate('c')`, camelCase keys, `status` from `pot_conversion_status`, the regenerate action with `check_admin_referer`/nonce + `current_user_can('manage_options')`. Runtime behaviors (valid Bearer → 200 + numbers == dashboard; missing/wrong → 401; regenerate rotates secret) → DEFERRED manual checklist + a curl example.
- Provide a ready-to-paste curl example and the exact payload JSON shape in the SUMMARY so the user can build the Statusboard receiver in the next session.

### Statusboard handoff note (for the user's next session)
The receiver should: call `GET https://berlin.parkourone.com/wp-json/pot/v1/metrics?from=YYYY-MM-DD&to=YYYY-MM-DD` with `Authorization: Bearer <pot_api_secret>`, expect the camelCase payload above, and store it (the `.passthrough()` snapshot model). Secret comes from the WP settings page built in this phase.
</specifics>

<deferred>
## Deferred Ideas

- HMAC-SHA256 body/over-the-wire signature (stronger than Bearer) → documented v2 upgrade.
- `campaign` filter param, compare-to-previous-period, raw-event export → v2 / out of scope.
- The Statusboard RECEIVER route + cron + persistence → user's separate session (explicitly out of scope here).
</deferred>
