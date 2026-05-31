# Phase 5: Secured Pull REST API - Pattern Map

**Mapped:** 2026-05-31
**Files analyzed:** 4 (2 created, 2 modified) + 1 optional UI surface
**Analogs found:** 4 / 4

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|-------------------|------|-----------|----------------|---------------|
| `includes/class-pot-api.php` (NEW) | route + controller | request-response (read-only pull) | `includes/class-pot-ingest.php` (in-repo route reg) + `Input/ab-webhook-endpoint/includes/class-ab-rest-endpoint.php` (args/validate + WP_Error) | exact (role) — **auth REPLACED** |
| `includes/class-pot-metrics.php` (NEW, optional) | utility (shared rate helper) | transform | `includes/class-pot-admin.php::rate()` + `resolve_range()` | exact — extract-in-place |
| Settings UI: tab in `class-pot-admin.php` OR new `includes/class-pot-api-settings.php` | config / admin | request-response (form action) | `Input/ab-webhook-endpoint/includes/class-ab-gutschein-settings.php` + `custom-events-plugin.php` admin-post handler | role-match |
| `includes/class-pot-plugin.php` (MODIFY) | orchestrator | wiring | existing `POT_Plugin::init()` body | exact |
| `includes/class-pot-admin.php` (MODIFY) | admin (refactor rate helper out) | transform | itself | exact |

---

## Pattern Assignments

### `includes/class-pot-api.php` (route + controller, request-response)

**Primary analog:** `includes/class-pot-ingest.php` (same repo, same `pot/v1` namespace, same static-init shape).
**Secondary analog:** `Input/ab-webhook-endpoint/includes/class-ab-rest-endpoint.php` (args/validate_callback + WP_Error envelope).

**MIRROR — static init + rest_api_init hook** (`includes/class-pot-ingest.php:34-36`):
```php
public static function init() {
    add_action('rest_api_init', ['POT_Ingest', 'register_routes']);
}
```
→ For the API: `add_action('rest_api_init', ['POT_Api', 'register_routes']);`

**MIRROR — register_rest_route + args/validate_callback** (`includes/class-pot-ingest.php:42-56`, args style also at `class-ab-rest-endpoint.php:24-30,52-58`):
```php
register_rest_route('pot/v1', '/event', [
    'methods'             => 'POST',
    'callback'            => ['POT_Ingest', 'handle_event'],
    'permission_callback' => ['POT_Ingest', 'check_nonce'],
    'args'                => [
        'type' => [
            'required'          => true,
            'validate_callback' => function ($param) { return in_array($param, ['visit','click'], true); },
        ],
    ],
]);
```
→ **CHANGE for the API**: route `/metrics`, `'methods' => 'GET'` (use `WP_REST_Server::READABLE` or `'GET'`), `permission_callback => ['POT_Api','check_bearer']` (NOT the nonce, NOT `__return_true`). Add `from`/`to` args each with `validate_callback` (`/^\d{4}-\d{2}-\d{2}$/` or ISO-8601) + `sanitize_callback` (`sanitize_text_field`). Defaults handled in the callback (last 30 days UTC when omitted). No `campaign` arg (out of scope v1).

**REPLACE — the `__return_true` anti-pattern.** `class-ab-rest-endpoint.php:16,23,37,44,51` ALL use `'permission_callback' => '__return_true'` — CONTEXT-FINDINGS.md:21,71 flags this as the explicit anti-pattern. Do NOT copy it. The API permission_callback MUST verify the Bearer secret.

**MIRROR — constant-time compare idiom** (`Input/ab-webhook-endpoint/includes/helper-functions.php:123`):
```php
if (hash_equals($expected_token, $token) || hash_equals($legacy_token, $token)) {
```
→ **Bearer permission_callback shape (build new, mirror the `hash_equals` idiom)**:
```php
public static function check_bearer(WP_REST_Request $request) {
    $header = (string) $request->get_header('authorization');   // 'Bearer xxxx'
    if (stripos($header, 'Bearer ') !== 0) {
        return new WP_Error('pot_unauthorized', 'Authentifizierung erforderlich.', ['status' => 401]);
    }
    $provided = substr($header, 7);
    $secret   = self::get_or_create_secret();
    // Length pre-check BEFORE hash_equals (avoids leaking via length; hash_equals needs equal-length).
    if ($provided === '' || strlen($provided) !== strlen($secret) || !hash_equals($secret, $provided)) {
        return new WP_Error('pot_unauthorized', 'Authentifizierung erforderlich.', ['status' => 401]);
    }
    return true;
}
```
RULES: return `WP_Error(..., ['status'=>401])` on missing/empty/mismatch — never 500, never reveal whether the secret exists, NEVER `error_log` the secret. No `Access-Control-Allow-Origin` header anywhere. Mirrors the Statusboard CRON_SECRET/timingSafeEqual contract (CONTEXT-FINDINGS.md:29,159).

**MIRROR — WP_Error-with-status + auto-JSON-array response** (`class-ab-rest-endpoint.php:71,77,108` return `WP_Error(code,msg,['status'=>4xx])`; success returns a plain array WP auto-encodes). The metrics callback returns a plain associative array (camelCase) — WP REST auto-encodes it; no manual `wp_send_json`.

**REUSE — the ONLY read path** (`includes/class-pot-store.php:89-117`): `POT_Store::aggregate_by_campaign($from_utc, $to_utc)` returns rows `{campaign,visits,clicks,bookings}`, `(unattributed)` bucket included (`POT_Store::UNATTRIBUTED`, line 19). The API MUST call this — it must contain NO `$wpdb`/SELECT of its own (zero-drift contract, CONTEXT.md:20,73).

**REUSE — local→UTC bounds** must match the dashboard exactly: see the shared helper extraction below.

**Secret accessor** (`get_or_create_secret`): store in dedicated option `pot_api_secret`. Mint with `wp_generate_password(32, false)` (precedent `class-ab-rest-endpoint.php:388`) and persist with `autoload=false`:
```php
public static function get_or_create_secret() {
    $secret = get_option('pot_api_secret');
    if (empty($secret)) {
        $secret = wp_generate_password(32, false);
        add_option('pot_api_secret', $secret, '', false);   // 4th arg 'no' = autoload=false
    }
    return $secret;
}
```
Mirrors the repo's `add_option(..., '', false)` precedent (`includes/class-pot-activator.php:22,27`). Do NOT rely solely on the Phase 1 activator (predates this phase) — self-heal here.

---

### `includes/class-pot-metrics.php` (utility, transform) — shared rate helper

**Analog (extract from):** `includes/class-pot-admin.php`.

**EXTRACT — divide-by-zero rate** (`includes/class-pot-admin.php:110-115`):
```php
private static function rate($num, $den) {
    if ((int) $den === 0) { return '–'; }
    return number_format_i18n(($num / $den) * 100, 1) . ' %';
}
```
→ Dashboard returns a **formatted German string** (`'12,5 %'` / `'–'`). The API needs a **number** (CONTEXT.md:39 — keep rates numeric, percentages to 1 decimal). RECOMMENDED: introduce `POT_Metrics::rate_value($num,$den): ?float` (returns `null` or e.g. `12.5` when den=0) as the single source, and have `POT_Admin::rate()` format the result of `rate_value()`. This keeps API numbers == dashboard numbers (CONTEXT.md:42 — "API and dashboard cannot drift"). Per-campaign rates the API must emit: `conversionRate`=bookings/visits, `visitToClick`=clicks/visits, `clickToBooking`=bookings/clicks (mapping per `class-pot-admin.php:319-322`).

**EXTRACT / SHARE — local→UTC range resolution** (`includes/class-pot-admin.php:128-252`, esp. `resolve_range` 187-252 producing `from_utc`/`to_utc` as `'Y-m-d H:i:s'`, span cap `MAX_SPAN_DAYS=366` at 28, future-clamp at 134-141, swap at 144-148). The API takes explicit `from`/`to` interpreted as **UTC** (CONTEXT.md:21 — the store's `created_at` is UTC), whereas the dashboard converts site-local days→UTC. So the API does NOT reuse `resolve_range` verbatim, but MUST reuse the same **validation/clamp/span-cap logic** (from<=to, clamp future, 366d cap) so both behave identically. Extract `sanitize_range_input`'s validation rules into `POT_Metrics` or duplicate them deliberately with a comment pointing back to `class-pot-admin.php:128-167`.

> Discretion (CONTEXT.md:44): the helper may live on a new `POT_Metrics` static class OR on `POT_Store`. Either way, both `POT_Admin` and `POT_Api` call the SAME function.

---

### Settings UI (config/admin, request-response) — view/regenerate secret

**Analog A (settings page + cap + Settings-API render):** `Input/ab-webhook-endpoint/includes/class-ab-gutschein-settings.php`.
**Analog B (nonce-protected mutating form-action + cap + redirect):** `custom-events-plugin/custom-events-plugin.php:711-767`.

**MIRROR — submenu under `parkourone`, cap, init shape** (`class-ab-gutschein-settings.php:10-25`):
```php
public static function init() {
    add_action('admin_menu', [__CLASS__, 'add_menu_page']);
    add_action('admin_init', [__CLASS__, 'register_settings']);
    add_action('wp_ajax_ab_create_gutschein_product', [__CLASS__, 'ajax_create_product']);
}
public static function add_menu_page() {
    add_submenu_page('parkourone', 'Gutschein Einstellungen', 'Gutscheine', 'manage_woocommerce', 'ab-gutschein-settings', [__CLASS__,'render_settings_page']);
}
```
→ Use cap `manage_options` (CONTEXT.md:30). If a TAB on the existing dashboard: reuse `POT_Admin::MENU_SLUG` + the nav-tab-wrapper convention (CONTEXT-FINDINGS.md:94). If a subpage: `add_submenu_page('parkourone', ...)` with priority 999 like `class-pot-admin.php:37` (parent registered first).

**MIRROR — `<div class="wrap"><h1>` render** (`class-ab-gutschein-settings.php:81-105`). Show: full endpoint URL `esc_url(rest_url('pot/v1/metrics'))`, the secret **masked** with reveal/copy (never echo it unmasked into the page on first load by default; never log it), and a "Secret neu generieren" button.

**MIRROR — nonce + cap-gated mutating action** (regenerate). Two equally valid idioms in the codebase:

(a) admin-post form action (`custom-events-plugin.php:717,745-767`):
```php
$url = wp_nonce_url(admin_url('admin-post.php?action=pot_regenerate_secret'), 'pot_regenerate_secret');
// handler:
function pot_regenerate_secret() {
    if (!current_user_can('manage_options')) { wp_die('Unauthorized access'); }
    check_admin_referer('pot_regenerate_secret');
    update_option('pot_api_secret', wp_generate_password(32, false), false);  // keep autoload=false
    wp_safe_redirect(add_query_arg('pot_secret', 'regenerated', wp_get_referer()));
    exit;
}
add_action('admin_post_pot_regenerate_secret', 'pot_regenerate_secret');
```

(b) AJAX (`class-ab-gutschein-settings.php:43-49`): `check_ajax_referer('pot_regen','nonce')` → `current_user_can('manage_options')` → regenerate → `wp_send_json_success`. (This matches the existing `POT_Admin::ajax_metrics` style at `class-pot-admin.php:480-506`.)

Either way: nonce + `current_user_can('manage_options')` BEFORE the write; document that regenerating breaks any configured Statusboard until the new secret is re-pasted (CONTEXT.md:30).

**MIRROR — settings option registration** (`class-ab-gutschein-settings.php:27-29`) only if you store anything else; `pot_api_secret` itself is a plain `autoload=false` option, NOT registered via Settings API (it's mutated only through the gated regenerate action, not an `options.php` form).

---

### `includes/class-pot-plugin.php` (orchestrator) — MODIFY

**MIRROR — existing flat init list** (`includes/class-pot-plugin.php:12-38`): add one line alongside the others:
```php
// Secured read-only pull API (GET pot/v1/metrics, Bearer-gated).
POT_Api::init();
```
And `POT_Api_Settings::init();` if the UI is a separate class. Require the new file(s) in the main plugin file before `github-updater.php` (house-style, CONTEXT-FINDINGS.md:88).

---

### `includes/class-pot-admin.php` — MODIFY (refactor only)

Change `rate()` (line 110-115) to delegate to the shared `POT_Metrics::rate_value()` so the dashboard string and the API number derive from one computation. No behavioral change to the dashboard output. Do NOT move SQL — there is none here (line 16-18 contract).

---

## Shared Patterns

### Constant-time secret compare
**Source:** `Input/ab-webhook-endpoint/includes/helper-functions.php:123` (`hash_equals`).
**Apply to:** `POT_Api::check_bearer`. Length pre-check, then `hash_equals($secret, $provided)`. 401 on any failure. Never `===` (the wizard.php:181 plain-compare is the timing-leak anti-pattern, CONTEXT-FINDINGS.md:20,77).

### Secret minting + autoload=false option
**Source:** `wp_generate_password(32, false)` (`class-ab-rest-endpoint.php:388`) + `add_option(..., '', false)` (`class-pot-activator.php:22,27`).
**Apply to:** `pot_api_secret` (accessor + regenerate). Always pass the 4th `autoload` arg = `false`/`'no'`.

### REST error envelope
**Source:** `class-ab-rest-endpoint.php:71,77,108` — `new WP_Error('code','msg',['status'=>4xx])`; success = plain array auto-encoded.
**Apply to:** `POT_Api` (401 unauthorized; 200 payload array).

### Single aggregation gateway (zero-drift)
**Source:** `POT_Store::aggregate_by_campaign` (`class-pot-store.php:89-117`); dashboard caller `class-pot-admin.php:410,498`.
**Apply to:** `POT_Api` calls the identical method with UTC bounds. API contains NO `$wpdb`.

### Status field from Phase 2 option
**Source:** `class-pot-admin.php:377,388` — `get_option('pot_conversion_status', 'not_configured')`.
**Apply to:** payload `status` field. Map: `'ok'` → `'ok'`; offline/`not_configured` → `'not_configured'` (Statusboard surfaces health instead of trusting a zero, CONTEXT.md:35). `'stale'` reserved.

### Payload contract (camelCase + generatedAt)
**Source:** CONTEXT-FINDINGS.md:30,168 (Statusboard `.passthrough()` store, SourceStatus enum, ISO-8601).
**Apply to:** `POT_Api` response:
```php
[
  'generatedAt' => gmdate('c'),                 // ISO-8601 UTC
  'status'      => $status,                       // 'ok'|'not_configured'|'stale'
  'range'       => ['from'=>$from,'to'=>$to,'timezone'=>'UTC'],
  'totals'      => ['visits'=>..,'clicks'=>..,'bookings'=>..,'conversionRate'=>..],
  'campaigns'   => [ ['campaign'=>..,'visits'=>..,'clicks'=>..,'bookings'=>..,
                      'conversionRate'=>..,'visitToClick'=>..,'clickToBooking'=>..], ... ],
]
```
Numbers as numbers; rates as numbers (percent to 1 decimal — document the unit in a payload contract comment).

---

## No Analog Found

| File | Role | Data Flow | Reason |
|------|------|-----------|--------|
| (none) | — | — | All four files have strong in-repo or sibling-plugin analogs. The Bearer auth has no in-repo precedent but mirrors `helper-functions.php:123` (`hash_equals`) + the documented Statusboard CRON_SECRET pattern (CONTEXT-FINDINGS.md:29,159). |

---

## Static Verification Checklist (no PHP/WP runtime)

Grep targets the planner should encode (CONTEXT.md:74):
- `register_rest_route('pot/v1', '/metrics'` with `'methods' => 'GET'` (or `WP_REST_Server::READABLE`).
- permission_callback uses `get_header('authorization')` + `hash_equals` + `['status' => 401]`; ABSENCE of `__return_true` in `class-pot-api.php`.
- NO secret in `error_log(`; NO `Access-Control-Allow-Origin`.
- `wp_generate_password(32` present; `pot_api_secret` written with `autoload=false` (4th arg `false`/`'no'`).
- `gmdate('c')` for `generatedAt`; camelCase keys; `status` derived from `pot_conversion_status`.
- regenerate action has `check_admin_referer`/nonce + `current_user_can('manage_options')`.
- `class-pot-api.php` contains NO `$wpdb` / `SELECT` (calls `POT_Store::aggregate_by_campaign` only).

Deferred to manual checklist: valid Bearer → 200 + numbers == dashboard; missing/wrong → 401; regenerate rotates secret. Provide a `curl` example + payload JSON in the SUMMARY.

---

## Metadata

**Analog search scope:** `includes/` (in-repo), `Input/ab-webhook-endpoint/includes/`, `Input/custom-events-plugin/`.
**Files read:** class-pot-ingest, class-pot-store, class-pot-admin, class-pot-plugin, class-pot-activator, ab-rest-endpoint, helper-functions, ab-gutschein-settings, custom-events-plugin (admin-post block); CONTEXT.md + CONTEXT-FINDINGS.md.
**Pattern extraction date:** 2026-05-31
