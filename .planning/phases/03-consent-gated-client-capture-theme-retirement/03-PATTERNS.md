# Phase 3: Consent-Gated Client Capture & Theme Retirement - Pattern Map

**Mapped:** 2026-05-31
**Files analyzed:** 4 (3 new, 1 modified)
**Analogs found:** 4 / 4 (all exact or strong role+flow matches in-repo or in Input/ siblings)

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|-------------------|------|-----------|----------------|---------------|
| `includes/class-pot-ingest.php` (NEW) | controller (REST) | request-response (write-through to store) | `Input/ab-webhook-endpoint/includes/class-ab-rest-endpoint.php` (registration) + `Input/parkourone-theme/inc/analytics/class-analytics.php:255-287` (handler+sanitize) | role-match (registration); exact (handler shape) |
| `assets/js/pot-tracker.js` (NEW) | frontend asset (beacon + click listener) | event-driven (sendBeacon fire-and-forget) | `Input/parkourone-theme/assets/js/analytics-tracker.js` (beacon/consent/click) + `assets/js/pot-attribution.js` (consent gate + cookie read) | exact |
| theme-retirement (NEW: methods on `POT_Plugin` OR small `includes/class-pot-theme-retirement.php`) | utility/config (dequeue + remove_action) | event-driven (hooks) | `includes/class-pot-attribution.php:29-37` (init+add_action shape) inverted to dequeue/remove | role-match |
| `includes/class-pot-plugin.php` (MODIFIED) | orchestrator | n/a | itself (existing `POT_Plugin::init()` flat list) | exact |

---

## CRITICAL INVESTIGATION RESULTS (cited)

### 1. Theme analytics tracker — handle, hook, and server-side writers

**JS enqueue handle + hook** — `Input/parkourone-theme/inc/analytics/class-analytics.php:158-181`:
- Handle: **`po-analytics-tracker`**
- Hook: `add_action('wp_enqueue_scripts', [$this, 'enqueue_tracker'])` registered at `class-analytics.php:46` (NO explicit priority → default 10).
- File: `get_template_directory_uri() . '/assets/js/analytics-tracker.js'`, deps `[]`, footer `true`.
- → Plugin must `wp_dequeue_script('po-analytics-tracker')` AND `wp_deregister_script('po-analytics-tracker')` on `wp_enqueue_scripts` at **priority 99+** (after the theme's priority-10 enqueue runs).

**Singleton + named callbacks (all REMOVABLE)** — `class-analytics.php:31-36, 41-74`:
- Instance accessor: `PO_Analytics::get_instance()` (`class-analytics.php:31`). Class name **`PO_Analytics`** (constructor is private; only `get_instance()` mints/returns the singleton).
- Every hook is `[$this, 'method']` — NOT closures → **removable via `remove_action`** PROVIDED you pass the SAME instance: `$po = PO_Analytics::get_instance(); remove_action('hook', [$po, 'method'], $priority);`.

**Server-side `wp_po_analytics_events` writers (the rows that must stop):**
| Writer | Hook (file:line) | Priority | Removable? |
|--------|------------------|----------|-----------|
| `track_basic_pageview` → `flush_fallback_pageview` → `insert_server_event('pageview',...)` | `add_action('wp_footer', [$this, 'track_basic_pageview'])` `class-analytics.php:49` | 10 | YES (named, same instance) |
| `track_purchase($order_id)` (purchase row) | `add_action('woocommerce_checkout_order_processed', [$this, 'track_purchase'])` `class-analytics.php:64` | 10 | YES (named) — but Phase-2 conversions are server-independent; do NOT remove unless double-count of bookings confirmed |
| `handle_track` REST ingest (JS-driven writes) | `register_rest_route('parkourone/v1','/analytics/track', ... 'callback'=>[$this,'handle_track'], 'permission_callback'=>'__return_true')` `class-analytics.php:237-241` | n/a | route stays registered; writes stop once the JS (`po-analytics-tracker`) is dequeued — no client = no POST |

**Decision for MIGRATE-01:** Dequeueing `po-analytics-tracker` (priority 99+) stops ALL JS-driven `wp_po_analytics_events` writes (pageview/cta_click/etc.) because `handle_track` has no other caller. The `wp_footer` server-fallback only fires when consent is ABSENT (`class-analytics.php:200-202` returns early when consent granted) — to fully silence the fallback for non-consenting visitors, additionally `remove_action('wp_footer', [PO_Analytics::get_instance(), 'track_basic_pageview'])` at priority 10. All target callbacks are named methods on the singleton → **no closure escape hatch needed**; nothing must be flagged as a manual theme-side toggle for the trackers in scope. (Booking/`track_purchase` is out of scope for dequeue — Phase 2 already owns conversions server-side; removing it risks losing the theme's own purchase row before parity is confirmed → leave it, document in deferred parity checklist.)

**Consent gate + beacon shape to MIRROR** (so pot-tracker.js is drift-free):
- Consent guard — `analytics-tracker.js:11`: `if (!window.poConsent || !window.poConsent.categories || !window.poConsent.categories.analytics) return;`
- sendBeacon + fetch fallback — `analytics-tracker.js:107-119` (see Shared Patterns).
- Capture-phase delegated click — `analytics-tracker.js:160-207` (third arg `true`). The theme matches CTA by TEXT regex incl. `probetrain|buchen`; the plugin must instead use the robust SELECTOR `a[href*="/probetraining-buchen"]` (CONTEXT-FINDINGS.md:23).
- session_id pattern — `analytics-tracker.js:14-19`: `sessionStorage` key + `Math.random().toString(36)...`. Plugin uses its OWN key `pot_sid` (do NOT reuse `po_analytics_sid`).

### 2. REST route registration to mirror — `Input/ab-webhook-endpoint/includes/class-ab-rest-endpoint.php`

- Static-class + `rest_api_init` hook — `class-ab-rest-endpoint.php:8-10`:
  ```php
  public static function init() {
      add_action('rest_api_init', [__CLASS__, 'register_routes']);
  }
  ```
- Route registration + `args`/`validate_callback` shape — `class-ab-rest-endpoint.php:20-31` (the validate_callback closure pattern to mirror for `type`).
- **ANTI-PATTERN to REPLACE** — `class-ab-rest-endpoint.php:16,23,37,44,51`: every route uses `'permission_callback' => '__return_true'`. The theme's ingest route does the same (`class-analytics.php:240`). The new `pot/v1/event` route MUST use a real nonce check:
  ```php
  'permission_callback' => function (WP_REST_Request $request) {
      $nonce = $request->get_header('X-WP-Nonce');
      if (!$nonce) { $nonce = $request->get_param('_wpnonce'); }
      return wp_verify_nonce($nonce, 'wp_rest') !== false;
  },
  ```
  (Decision CAPTURE-01, 03-CONTEXT.md:27. NEVER `__return_true`.)

### 3. Phase-2 consent global, admin-skip flag, cookie read (mirror VERBATIM)

- Consent global — `assets/js/pot-attribution.js:40-42`: `return !!(window.poConsent && window.poConsent.categories && window.poConsent.categories.analytics);` (identical to theme `analytics-tracker.js:11`). NO consent-change event — re-check on pageload only.
- Admin-skip — TWO layers: server enqueue skip `class-pot-attribution.php:45-48` (`is_user_logged_in() && current_user_can('manage_options')`), localized flag `'isAdmin'` (`class-pot-attribution.php:58`), JS belt-and-suspenders `pot-attribution.js:21-23` (`if (cfg.isAdmin) { return; }`).
- Cookie read — `pot-attribution.js:35-38`: `getCookie(name)` regex; the `pot_attribution` value is `JSON.parse`d (`{campaign,source,medium,landing_path,...}`). pot-tracker.js reads the SAME cookie to attach `campaign/source/medium` to each beacon (03-CONTEXT.md:21,34).

---

## Pattern Assignments

### `includes/class-pot-ingest.php` (controller, request-response)

**Analogs:** registration → `class-ab-rest-endpoint.php`; handler/sanitize → `class-analytics.php:255-287`; write → `POT_Store::insert_event`.

**Class/init pattern** — mirror `class-ab-rest-endpoint.php:6-10` and Phase-2 `class-pot-attribution.php:29`:
```php
class POT_Ingest {
    public static function init() {
        add_action('rest_api_init', ['POT_Ingest', 'register_routes']);
    }
}
```

**Route registration** — mirror `class-ab-rest-endpoint.php:13-31`, but with the nonce `permission_callback` from Investigation 2 above:
```php
register_rest_route('pot/v1', '/event', [
    'methods'             => 'POST',
    'callback'            => ['POT_Ingest', 'handle_event'],
    'permission_callback' => [ /* nonce check, see above */ ],
    'args' => [
        'type' => [
            'required'          => true,
            'validate_callback' => function ($param) { return in_array($param, ['visit','click'], true); },
        ],
    ],
]);
```

**Handler: sanitize + length-cap + write** — combine theme's `handle_track` sanitize chain (`class-analytics.php:267-287`: `sanitize_text_field`, `esc_url_raw(substr(...,0,500))`, `absint`) with Phase-2's boundary-clean discipline (`class-pot-attribution.php:83-92`) and write through the gateway:
```php
public static function handle_event(WP_REST_Request $request) {
    // server-side admin guard (defense-in-depth, mirrors client gate)
    if (is_user_logged_in() && current_user_can('manage_options')) {
        return new WP_REST_Response(null, 204);
    }
    // UA bot denylist (NEVER store the UA itself — boolean decision only; DSGVO)
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (preg_match('/bot|crawl|spider|slurp|facebookexternalhit|Bytespider|GPTBot/i', $ua)) {
        return new WP_REST_Response(null, 204);
    }
    $type = sanitize_text_field($request->get_param('type'));   // validated to visit|click
    POT_Store::insert_event([
        'event_type'   => $type,
        'campaign'     => substr(sanitize_text_field((string) $request->get_param('campaign')), 0, 100),
        'source'       => substr(sanitize_text_field((string) $request->get_param('source')), 0, 100),
        'medium'       => substr(sanitize_text_field((string) $request->get_param('medium')), 0, 100),
        'landing_path' => substr(esc_url_raw((string) $request->get_param('landing_path')), 0, 500),
        'session_id'   => substr(preg_replace('/[^a-z0-9]/i', '', (string) $request->get_param('session_id')), 0, 64),
    ]);
    return new WP_REST_Response(null, 204);
}
```
- **MIRROR:** theme's bot regex (`class-analytics.php:195`), per-field sanitize+cap (`class-analytics.php:267-287`), `POT_Store::insert_event` signature/columns (`class-pot-store.php:22-34, 48`).
- **CHANGE / NEVER:** no `$_SERVER['REMOTE_ADDR']`, no `visitor_hash`, no UA storage (theme stores `visitor_hash` at `class-analytics.php:264-269` — DO NOT copy). Phase-3 store has no such column.
- **Error/log convention:** reuse `error_log('[POT Tracking] ...')` bracketed prefix (`class-pot-store.php:71`).

### `assets/js/pot-tracker.js` (frontend asset, event-driven)

**Analogs:** `analytics-tracker.js` (beacon/consent/click), `pot-attribution.js` (IIFE, cfg, admin-skip, getCookie, consent helper).

**MIRROR verbatim:**
- IIFE + `'use strict'` + cfg read + admin-skip — `pot-attribution.js:15-23`.
- `hasAnalyticsConsent()` — `pot-attribution.js:40-42` (identical to `analytics-tracker.js:11`). Gate the ENTIRE tracker behind it on load; no consent-change event → re-check on pageload only (`pot-attribution.js:105`).
- `getCookie('pot_attribution')` + `JSON.parse` to pull `{campaign,source,medium}` — `pot-attribution.js:35-38`.
- session_id from `sessionStorage` key `pot_sid` — pattern from `analytics-tracker.js:14-19`.
- sendBeacon + fetch keepalive fallback — `analytics-tracker.js:107-119` (Shared Pattern below); add `X-WP-Nonce` header on the fetch path (theme omits it because it uses `__return_true`; the plugin's route requires it).
- Capture-phase delegated click `addEventListener('click', fn, true)` — `analytics-tracker.js:160-207`.

**CHANGE:**
- Localize `{ restUrl: rest_url('pot/v1/event'), nonce: wp_create_nonce('wp_rest'), isAdmin }` (vs theme's `{endpoint, nonce}` at `class-analytics.php:177-180`).
- Click match by SELECTOR `e.target.closest('a[href*="/probetraining-buchen"]')` — NOT the theme's text-regex (`analytics-tracker.js:190-196`).
- Send only `type:'visit'` (one per pageview) and `type:'click'`; do NOT send the theme's pageview/scroll/device/page_leave/form payloads (`analytics-tracker.js:122-251`).
- Debounce identical rapid clicks (~500ms, same href) — new code (Claude's discretion).
- Bot skip: `if (navigator.webdriver) return;` (client hint; server denylist is authoritative).

### theme-retirement (utility/config, event-driven)

**Analog:** the `init()`+`add_action` shape of `class-pot-attribution.php:29-37`, inverted to dequeue/remove. Recommend a dedicated `includes/class-pot-theme-retirement.php` (one-class-per-file house style) over piling methods on the orchestrator.

```php
class POT_Theme_Retirement {
    const OPTION = 'pot_retire_theme_tracker'; // default true; short-lived feature gate
    public static function init() {
        if (!get_option(self::OPTION, true)) { return; }
        add_action('wp_enqueue_scripts', ['POT_Theme_Retirement', 'dequeue_theme_tracker'], 99);
        // server-fallback row writer (named callback on the singleton → removable)
        if (class_exists('PO_Analytics')) {
            $po = PO_Analytics::get_instance();
            remove_action('wp_footer', [$po, 'track_basic_pageview'], 10);
        }
    }
    public static function dequeue_theme_tracker() {
        wp_dequeue_script('po-analytics-tracker');
        wp_deregister_script('po-analytics-tracker');
    }
}
```
- **MIRROR:** handle `po-analytics-tracker` (`class-analytics.php:170`), singleton accessor `PO_Analytics::get_instance()` (`class-analytics.php:31`), hook names (`class-analytics.php:46,49`).
- **CHANGE / GUARD:** wrap `remove_action` in `class_exists('PO_Analytics')` — the theme repo is separate and may not define the class on a given site (graceful degradation, same posture as `function_exists('WC')` at `class-pot-attribution.php:34`). Do NOT edit the theme.
- **Priority 99** is essential — the theme enqueues at default 10 (`class-analytics.php:46`); dequeue must run AFTER.
- **Do NOT** `remove_action` `track_purchase` (`class-analytics.php:64`) — out of scope; leave the booking row writer until runtime parity is confirmed (deferred checklist).

### `includes/class-pot-plugin.php` (orchestrator, MODIFIED)

**Analog:** itself — `class-pot-plugin.php:12-28`. Append to the existing flat `POT_*::init()` list (mirrors `pot_init()` body and the sibling `ab_we_init_plugin`):
```php
POT_Ingest::init();             // REST pot/v1/event
POT_Tracker::init();            // OR fold the tracker enqueue into POT_Ingest::init() — discretion
POT_Theme_Retirement::init();
```
Also add `require_once` lines for the new class files in `parkourone-campaign-tracking.php:24-31` (github-updater.php stays LAST at line 31). The tracker JS enqueue (the `wp_enqueue_script('pot-tracker', POT_PLUGIN_URL.'assets/js/pot-tracker.js', [], POT_VERSION, true)` + `wp_localize_script`) mirrors `class-pot-attribution.php:50-61` exactly, including the admin-skip guard at `class-pot-attribution.php:45-48`.

---

## Shared Patterns

### Consent gate (TTDSG/DSGVO) — apply to pot-tracker.js
**Source:** `assets/js/pot-attribution.js:40-42` (== `Input/parkourone-theme/assets/js/analytics-tracker.js:11`)
```js
function hasAnalyticsConsent() {
    return !!(window.poConsent && window.poConsent.categories && window.poConsent.categories.analytics);
}
```
No consent-change event exists → re-check on pageload (`pot-attribution.js:105`).

### Admin exclusion (two layers) — apply to tracker enqueue + ingest handler
**Source:** `includes/class-pot-attribution.php:45-48` (server enqueue skip) + `assets/js/pot-attribution.js:21-23` (JS flag) + theme server guard `Input/parkourone-theme/inc/analytics/class-analytics.php:160`
```php
if (is_user_logged_in() && current_user_can('manage_options')) { return; }
```
Mirror in: enqueue (skip), localized `isAdmin` flag, JS early-return, AND the REST handler (defense-in-depth).

### sendBeacon + fetch keepalive fallback — apply to pot-tracker.js
**Source:** `Input/parkourone-theme/assets/js/analytics-tracker.js:107-119`
```js
var body = JSON.stringify(payload);
if (navigator.sendBeacon) {
    navigator.sendBeacon(endpoint, new Blob([body], { type: 'application/json' }));
} else {
    fetch(endpoint, { method:'POST', headers:{'Content-Type':'application/json','X-WP-Nonce':nonce}, body:body, keepalive:true }).catch(function(){});
}
```
NOTE: sendBeacon cannot set custom headers → the nonce must travel in the BODY (`_wpnonce`) for the beacon path; the fetch fallback can use the `X-WP-Nonce` header. The permission_callback (Investigation 2) checks header THEN body — covers both.

### Write gateway — apply to ingest handler
**Source:** `includes/class-pot-store.php:48` — `POT_Store::insert_event(array $row)`. Whitelisted columns at `class-pot-store.php:22-34`; `created_at` auto-defaults to UTC now (`class-pot-store.php:63-65`); empty campaign → `UNATTRIBUTED` bucket on read (`class-pot-store.php:19`). No class except POT_Store touches `$wpdb` on `pot_events`.

### Error logging convention — apply to all PHP
**Source:** `includes/class-pot-store.php:71` / `Input/ab-webhook-endpoint/includes/class-ab-rest-endpoint.php:92`
```php
error_log('[POT Tracking] ...');
```

## No Analog Found

| File | Role | Data Flow | Reason |
|------|------|-----------|--------|
| (none) | | | All four files have a strong in-repo (Phase 1/2) or sibling-repo analog. The only NEW-code areas (nonce permission_callback, click debounce, `pot_sid` gen, UA denylist contents) are small, spec-defined deltas, not pattern gaps. |

## Metadata

**Analog search scope:** `includes/` (this repo), `assets/js/` (this repo), `Input/ab-webhook-endpoint/includes/`, `Input/parkourone-theme/inc/analytics/`, `Input/parkourone-theme/assets/js/`, project root `*.php`.
**Files scanned:** ~12 (4 in-repo source, 4 Input/ analogs, 2 context docs, 2 root plugin files).
**Pattern extraction date:** 2026-05-31
