# Phase 2: Conversion & Attribution Bridge - Pattern Map

**Mapped:** 2026-05-31
**Files analyzed:** 4 (3 created, 1 modified)
**Analogs found:** 4 / 4 (every file has a concrete in-repo or Input/ analog)

> All excerpts are real reference code with `file:line`. Paths are absolute under
> `/Users/ben/Desktop/Claude Workspace/WP Plugin Tracking ONE/`. Input/ plugins are
> separate git repos — copy the *pattern*, never add a runtime dependency.

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|-------------------|------|-----------|----------------|---------------|
| `includes/class-pot-conversion.php` (NEW) | service / WC listener | event-driven (order-status) | `Input/ab-webhook-endpoint/includes/class-ab-custom-statuses.php` (status hooks + idempotency + status-changed) + `includes/class-pot-store.php` (write path) | role + flow match |
| `includes/class-pot-attribution.php` (NEW) | service / checkout bridge | event-driven → write-meta | `Input/custom-events-plugin/includes/class-event-cart-integration.php` (`woocommerce_checkout_create_order_line_item` order-meta writes) + `helper-functions.php` (sanitize/`hash_equals`) | role + flow match |
| `assets/js/pot-attribution.js` (NEW) | client capture | transform → cookie/sessionStorage | `Input/parkourone-theme/assets/js/analytics-tracker.js` (consent gate, UTM parse, IIFE) | exact (closest existing tracker) |
| `includes/class-pot-plugin.php` (MODIFY) | orchestrator | wiring | itself + `parkourone-campaign-tracking.php` (require chain) | exact (extend in place) |

---

## Pattern Assignments

### `includes/class-pot-conversion.php` (service, event-driven)

**Primary analogs:** `Input/ab-webhook-endpoint/includes/class-ab-custom-statuses.php`, `includes/class-pot-store.php`, `includes/class-pot-admin.php` (notice).

**Class skeleton + ABSPATH guard** — mirror the in-repo house style, not the AB file's missing guard. Copy the header style from `includes/class-pot-store.php:1-16` and the `static init()` registration style from `includes/class-pot-plugin.php:12-22`.

**Hook signatures — copy EXACTLY (load-bearing).** From `class-ab-custom-statuses.php`:
- Status-specific action (line 338, primary path):
```php
add_action('woocommerce_order_status_probetraining', function($order_id) {
    AB_Email_Sender::send_status_email($order_id, 'wc-probetraining');
}, 10, 1);
```
  → POT uses `add_action('woocommerce_order_status_probetraining', ['POT_Conversion','record_conversion'], 10, 1);`
- Status-changed signature (line 101 / 119 / 151) — note it is `($order_id, $old_status, $new_status, $order)` with priority + 4 args:
```php
add_action('woocommerce_order_status_changed', [__CLASS__, 'track_previous_status'], 10, 4);
public static function track_previous_status($order_id, $old_status, $new_status, $order) { ... }
```
  → POT fallback: `add_action('woocommerce_order_status_changed', ['POT_Conversion','on_status_changed'], 10, 4);` then inside, `if ($new_status === 'probetraining') self::record_conversion($order_id);`
  CONTEXT.md fixes the param spelling as `$to` — the analog confirms the **3rd** positional arg is the new status. Use `$new_status` to match the analog signature; the guard is `$new_status === 'probetraining'` (status string is WITHOUT the `wc-` prefix — see `class-ab-custom-statuses.php:229-233` comment "get_status() liefert ohne wc-").

**The free/100%-coupon fallback this catches** — `class-ab-custom-statuses.php:439-475`. `ab_redirect_order_to_event_status` runs on `woocommerce_order_status_completed/processing` at **priority 999** and calls `$order->update_status('probetraining')`. That status change is exactly what `woocommerce_order_status_changed` (new === probetraining) catches. This is why the dual hook is non-negotiable.

**Graceful-degradation detection (CONVERT-04)** — the status registration to detect is `class-ab-custom-statuses.php:39-57`:
```php
public static function register_statuses() {
    foreach (self::get_custom_statuses() as $status_slug => $status_label) {
        if (!get_post_status_object($status_slug)) {
            register_post_status($status_slug, [ ... ]);
        }
    }
    add_filter('wc_order_statuses', [__CLASS__, 'add_to_wc_order_statuses']);
}
```
`get_custom_statuses()` (line 11-34) includes `'wc-probetraining' => 'Probetraining'`. So detect with `array_key_exists('wc-probetraining', wc_get_order_statuses())` OR `get_post_status_object('wc-probetraining')` (the analog uses `get_post_status_object`, not `post_status_exists` — prefer it). Guard `function_exists('wc_get_order_statuses')` / `function_exists('WC')` first so the plugin never fatals when WooCommerce is inactive.

**HPOS-safe order load + idempotency (CONVERT-03)** — copy the order-CRUD idiom from `class-ab-custom-statuses.php:278-289` (`check_and_set_probetraining_status`), NOT `get_post_meta`:
```php
function check_and_set_probetraining_status($order_id) {
    $order = wc_get_order($order_id);
    if ( ! $order ) {
        return;
    }
    ...
    $order->update_status($target_status);
}
```
POT equivalent (the idempotency-guarded core — the planner must assert this order):
```php
public static function record_conversion($order_id) {
    if (!function_exists('wc_get_order')) { return; }
    $order = wc_get_order($order_id);
    if (!$order) { return; }
    if ($order->get_meta('_pot_conversion_tracked') === 'yes') { return; } // BEFORE insert
    // read attribution meta + event_ref, then:
    POT_Store::insert_event([ 'event_type' => 'booking', ... ]);
    $order->update_meta_data('_pot_conversion_tracked', 'yes');
    $order->save();
}
```
The `get_meta()` / `update_meta_data()` / `save()` trio is the HPOS-safe pattern used throughout the events plugin (`class-event-cart-integration.php:171,350-407` use `$item->get_meta`/`add_meta_data`; same API on `$order`).

**Write path (Phase 1 gateway)** — `includes/class-pot-store.php:48-76`. Whitelisted columns (lines 22-34): `event_type, campaign, source, medium, landing_path, session_id, order_id, event_ref, created_at`. For a booking:
```php
POT_Store::insert_event([
    'event_type' => 'booking',
    'campaign'   => $campaign,            // from _pot_campaign meta, '' → UNATTRIBUTED bucket
    'source'     => $source,
    'medium'     => $medium,
    'landing_path' => $landing,
    'order_id'   => (int) $order->get_id(),
    'event_ref'  => $event_ref,           // (int) _event_id, best-effort
    // created_at omitted → defaults to current_time('mysql', true) at store:62-66
]);
```
Empty campaign is already bucketed by `aggregate_by_campaign()` (`class-pot-store.php:96-106`) under `POT_Store::UNATTRIBUTED` (line 19). Do NOT invent a second bucket.

**`event_ref` lookup (_event_id from order item)** — copy the resolution order from `Input/ab-webhook-endpoint/includes/helper-functions.php:393-407` (`ab_get_event_id_from_order`):
```php
foreach ($order->get_items() as $item) {
    $product_id = $item->get_meta('_event_product_id') ?: $item->get_product_id();
    if (!$product_id) continue;
    $event_id = get_post_meta($product_id, '_event_id', true);
    if ($event_id) return $event_id;
}
```
Note: `_event_id` lives on the **product**, resolved via `_event_product_id` on the order item (the item itself carries `_event_product_id`, `_event_title_clean`, `_event_venue`, etc., written at `class-event-cart-integration.php:347-408` and `514-664`). Best-effort — `null` if absent; never block the booking insert on a missing event_ref.

**Hook registration timing** — defer to `plugins_loaded` priority 11+ (after ab-webhook registers the status). The main file already runs `pot_init` at priority 11 (`parkourone-campaign-tracking.php:36`), so registering inside `POT_Conversion::init()` (called from `POT_Plugin::init()`) is already late enough. Do the WooCommerce-active guard at the top of `init()`.

**Admin notice for not_configured** — two analog options, prefer the in-repo style. Transient-based dismissible notice from `class-ab-custom-statuses.php:213-224`:
```php
public static function display_admin_notices() {
    $notice = get_transient('ab_admin_notice_' . get_current_user_id());
    if ($notice) {
        printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
            esc_attr($notice['type']), wp_kses_post($notice['message']));
        delete_transient('ab_admin_notice_' . get_current_user_id());
    }
}
```
Store the health state in an `autoload=false` option `pot_conversion_status` (`'ok' | 'not_configured'`) — mirrors the autoload=false log-option convention noted for `custom_events_import_log`. The placeholder admin class `includes/class-pot-admin.php` shows the `notice notice-* is-dismissible` + `esc_*` posture to match.

---

### `includes/class-pot-attribution.php` (service, event-driven → write-meta)

**Primary analogs:** `Input/custom-events-plugin/includes/class-event-cart-integration.php`, `Input/ab-webhook-endpoint/includes/helper-functions.php`, ARCHITECTURE.md Pattern 3.

**Constructor + hook registration** — the events plugin registers its WC hooks in a plain constructor (`class-event-cart-integration.php:2-11`):
```php
class Event_Cart_Integration {
    public function __construct() {
        add_action('woocommerce_checkout_create_order_line_item', array($this, 'save_participant_data_to_order'), 10, 4);
        ...
    }
}
```
POT uses `woocommerce_checkout_create_order` (order-level, not line-item) — register with `add_action('woocommerce_checkout_create_order', [$this,'persist_attribution'], 10, 1)`. Prefer the static `init()` style (consistent with `POT_Conversion` and `POT_Plugin::init()`), guarded by WooCommerce-active.

**HPOS-safe order-meta write at checkout** — the events plugin writes order-ITEM meta via `$item->add_meta_data(...)` (`class-event-cart-integration.php:347-408`, e.g. line 405 `$item->add_meta_data("_event_{$key}", $value);`). For ORDER-level meta the equivalent CRUD is `$order->update_meta_data(...)` (same HPOS-safe API; ARCHITECTURE.md:142-150 shows the exact bridge):
```php
add_action('woocommerce_checkout_create_order', function ($order) {
    if (!empty($_COOKIE['pot_attribution'])) {
        $ft = json_decode(wp_unslash($_COOKIE['pot_attribution']), true);
        $order->update_meta_data('_pot_campaign', sanitize_text_field($ft['campaign'] ?? ''));
        $order->update_meta_data('_pot_source',   sanitize_text_field($ft['source']   ?? ''));
        $order->update_meta_data('_pot_medium',   sanitize_text_field($ft['medium']   ?? ''));
        $order->update_meta_data('_pot_landing',  sanitize_text_field($ft['landing_path'] ?? ''));
    }
}, 10, 1);
```
On `woocommerce_checkout_create_order` the order is not yet saved, so `update_meta_data` is sufficient — no explicit `$order->save()` (WooCommerce persists it). This matches the line-item analog which also never calls save in the hook.

**Cookie field sanitization** — each decoded field through `sanitize_text_field` (matches the events-plugin POST handling at `class-event-cart-integration.php:239-241`) and the theme REST handler (`inc/analytics/class-analytics.php:274-276` sanitizes each `utm_*` with `sanitize_text_field`). Cap length per field (e.g. `substr(..., 0, 100)` — the theme caps `utm_*` columns at VARCHAR(100), `class-analytics.php:101-103`). `json_decode(..., true)` may return non-array on malformed cookie → guard `is_array($ft)` before reading. No cookie → write nothing (booking lands in unattributed).

**Why bridge at create-order time (not at conversion)** — the conversion handler may fire much later on status change (free/coupon path can be days later). Persisting cookie→order-meta at `woocommerce_checkout_create_order` gives `POT_Conversion::record_conversion` a stable `$order->get_meta('_pot_campaign')` to read, never a transient cookie. (ARCHITECTURE.md "Data Flow → Conversion flow", lines 191-201.)

**hash_equals convention (for reference only)** — `helper-functions.php:123` uses constant-time compare `hash_equals($expected_token, $token)`. Attribution does NOT verify a signature (cookie carries only campaign labels, no secret), so `hash_equals` is NOT needed here. It is listed only so the planner does not over-apply it; the real sanitization primitive for this file is `sanitize_text_field` + length cap, not `hash_equals`.

---

### `assets/js/pot-attribution.js` (client capture, transform → cookie/sessionStorage)

**Closest analog:** `Input/parkourone-theme/assets/js/analytics-tracker.js` (the tracker being replaced — mine it, do not run it in parallel).

**IIFE + early consent/config gate** — copy the top-of-file structure (`analytics-tracker.js:5-11`):
```js
(function () {
    'use strict';
    if (!window.poAnalytics || !window.poAnalytics.endpoint) return;
    // ── Consent Check: Nicht tracken ohne Analytics-Consent ──
    if (!window.poConsent || !window.poConsent.categories || !window.poConsent.categories.analytics) return;
```
For POT: gate on the localized admin flag (skip logged-in admins) and on `window.poConsent.categories.analytics`. The CONTEXT.md requires using `po_has_consent('analytics')` semantics — on the client that is exactly `window.poConsent.categories.analytics` (the helper `po_has_consent` at `class-consent-manager.php:1029-1031` is server-side PHP; the JS truth source is the localized `window.poConsent` object set at `class-consent-manager.php:387`).

**KEY DIFFERENCE — POT must NOT early-return on missing consent.** The tracker bails entirely without consent. POT instead must still PARSE the UTM and hold it in `sessionStorage` (ATTRIB-01 consent-timing), promoting to the cookie only once analytics consent is present. So restructure: parse first → if consent now, write cookie; else stash in sessionStorage.

**UTM parsing — copy verbatim** (`analytics-tracker.js:48-52`):
```js
function getParam(name) {
    var match = location.search.match(new RegExp('[?&]' + name + '=([^&]*)'));
    return match ? decodeURIComponent(match[1]) : '';
}
```
Capture `utm_campaign`, `utm_source`, `utm_medium`, and `landing_path = location.pathname`.

**Consent-change mechanism — load-bearing finding.** The consent manager dispatches **no** `CustomEvent` on change. Inspected `assets/js/consent-manager.js`: `saveConsent()` (lines 451-497) and `setFallbackCookie()` (522-566) only update `window.poConsent` in-memory + call `this.activateConsentedScripts()`; the "necessary only" decline path does `location.reload()` (line 38). There is therefore NO `'po-consent-change'` / `'consentchange'` event to listen for. → `pot-attribution.js` must:
  1. On load: read `window.poConsent.categories.analytics`. If granted → promote any held sessionStorage value into the cookie.
  2. Re-check on the **next pageload** (the reliable path; a fresh accept reloads or the user navigates).
  3. OPTIONAL belt-and-suspenders: a short `setInterval`/poll of `window.poConsent.categories.analytics` for the same-pageload accept case, clearing the timer once promoted. (Claude's discretion per CONTEXT.md — do not over-engineer; the next-pageload re-check is the guaranteed mechanism.)

**First-touch + cookie write rules.** Never overwrite an existing `pot_attribution` cookie (first-touch wins). Cookie value = JSON `{campaign, source, medium, landing_path, first_seen}`, `SameSite=Lax`, `path=/`, 90-day lifetime, NOT HttpOnly (JS must read it). The cookie-write idiom (expiry + SameSite=Lax + path) is shown in `consent-manager.js:540-544`:
```js
const expires = new Date();
expires.setFullYear(expires.getFullYear() + 1);
document.cookie = `po_consent=${cookieValue}; expires=${expires.toUTCString()}; path=/${domain}; SameSite=Lax`;
```
Adapt to a 90-day expiry and name `pot_attribution`. sessionStorage idiom from the tracker (`analytics-tracker.js:14-19`):
```js
var SESSION_KEY = 'po_analytics_sid';
var sessionId = sessionStorage.getItem(SESSION_KEY);
if (!sessionId) { ...; sessionStorage.setItem(SESSION_KEY, sessionId); }
```

**Enqueue (server side, in `class-pot-attribution.php` or the plugin orchestrator).** Mirror `inc/analytics/class-analytics.php:158-181`:
```php
public function enqueue_tracker() {
    if (is_user_logged_in() && current_user_can('manage_options')) { return; } // skip admins
    if (function_exists('po_has_consent') && !po_has_consent('analytics')) { return; }
    wp_enqueue_script('po-analytics-tracker', get_template_directory_uri().'/assets/js/analytics-tracker.js', [], self::VERSION, true);
    wp_localize_script('po-analytics-tracker', 'poAnalytics', [ 'endpoint' => ..., 'nonce' => ... ]);
}
```
POT differences: (1) deps `[]` (no jQuery — UTM capture needs none); (2) URL via `POT_PLUGIN_URL . 'assets/js/pot-attribution.js'` (constant defined at `parkourone-campaign-tracking.php:21`), version `POT_VERSION`, in_footer `true`; (3) **do NOT early-return on no-consent at enqueue** — the script must load so it can stash UTM in sessionStorage and promote later. Instead pass an admin-skip flag + the consent state via `wp_localize_script('potAttribution', ['isAdmin'=>..., 'cookieName'=>'pot_attribution', 'cookieDays'=>90])` and let the JS decide cookie-vs-sessionStorage. Skip the enqueue entirely only for admins.

---

### `includes/class-pot-plugin.php` (orchestrator — MODIFY)

**Current state** (`includes/class-pot-plugin.php:12-22`): `init()` registers `POT_Activator::maybe_upgrade`, `POT_Cron::init()`, `POT_Admin::init()`.

**Change:** add two lines inside `init()`, mirroring the existing flat list:
```php
public static function init() {
    add_action('admin_init', ['POT_Activator', 'maybe_upgrade']);
    POT_Cron::init();
    POT_Admin::init();
    POT_Conversion::init();    // NEW — server conversion listener (WC-guarded internally)
    POT_Attribution::init();   // NEW — checkout bridge + front-end UTM enqueue
}
```

**Also MODIFY** `parkourone-campaign-tracking.php` require chain (lines 24-29) — add the two new class files BEFORE `github-updater.php` (which must stay last, per the comment at line 29):
```php
require_once POT_PLUGIN_DIR . 'includes/class-pot-conversion.php';
require_once POT_PLUGIN_DIR . 'includes/class-pot-attribution.php';
require_once POT_PLUGIN_DIR . 'includes/github-updater.php'; // ALWAYS last.
```
Timing is already correct: `pot_init` runs on `plugins_loaded` priority 11 (line 36), after ab-webhook registers the status. HPOS compatibility is already declared (lines 40-44) so order CRUD is HPOS-safe.

---

## Shared Patterns

### House style (ABSPATH guard + class-per-file + static init)
**Source:** `includes/class-pot-store.php:1-16`, `includes/class-pot-plugin.php:1-23`
**Apply to:** both new PHP classes.
```php
<?php
if (!defined('ABSPATH')) { exit; }
/** Doc comment. */
class POT_Conversion {
    public static function init() { /* add_action(...) */ }
}
```
Note: the AB analog file `class-ab-custom-statuses.php` has the guard (lines 2-4); `class-event-cart-integration.php` does NOT (it relies on the loader). New POT files MUST include the guard to match the in-repo siblings.

### HPOS-safe order access (NEVER get_post_meta on orders)
**Source:** `class-ab-custom-statuses.php:278-289` (`wc_get_order` + `update_status`), `class-event-cart-integration.php:159-209` (`wc_get_order`, `$order->get_items()`, `$item->get_meta`)
**Apply to:** `class-pot-conversion.php` (read meta + flag), `class-pot-attribution.php` (write meta).
- Load: `$order = wc_get_order($order_id);` then `if (!$order) return;`
- Read: `$order->get_meta('_pot_campaign')`, `$item->get_meta('_event_product_id')`
- Write: `$order->update_meta_data($key, $val);` then `$order->save();` (save only outside the create-order hook)

### error_log bracketed prefix
**Source:** `includes/class-pot-store.php:71` (`error_log('[POT Tracking] insert_event failed: ' ...)`), AB convention `class-ab-custom-statuses.php:156` (`'[AB Status Plugin] ...'`)
**Apply to:** both new classes for any failure path. Use `'[POT Tracking] ...'` to match the in-repo store.

### Consent + admin gate at the edge
**Source:** `inc/analytics/class-analytics.php:160-167` (admin skip + `po_has_consent('analytics')`)
**Apply to:** `pot-attribution.js` enqueue (admin skip only — script still loads for consent-stashing) and the JS consent check (`window.poConsent.categories.analytics`). Server conversion (`class-pot-conversion.php`) is **consent-INDEPENDENT** — apply NO consent gate there.

### autoload=false option for health/log state
**Source:** house-style note (`custom_events_import_log` written with autoload `false`); CONTEXT.md `<specifics>` "Keep options autoload=false"
**Apply to:** `pot_conversion_status` in `class-pot-conversion.php` — `update_option('pot_conversion_status', $state, false)`.

---

## No Analog Found

None. Every file maps to a concrete reference:

| File | Coverage |
|------|----------|
| `class-pot-conversion.php` | Hooks/idempotency/status from `class-ab-custom-statuses.php`; write path from `class-pot-store.php`; event_ref from `helper-functions.php` |
| `class-pot-attribution.php` | Order-meta CRUD from `class-event-cart-integration.php`; checkout bridge from ARCHITECTURE.md Pattern 3; sanitize from `class-analytics.php` |
| `pot-attribution.js` | Consent gate + UTM parse + IIFE from `analytics-tracker.js`; cookie write idiom from `consent-manager.js` |
| `class-pot-plugin.php` | Extends itself + the require chain in `parkourone-campaign-tracking.php` |

> One genuine gap (not "no analog", a *behavioural* divergence to flag for the planner):
> the consent manager fires **no** consent-change JS event. `pot-attribution.js` must
> use next-pageload re-check (+ optional poll) to promote sessionStorage→cookie, NOT an
> event listener. Verified in `assets/js/consent-manager.js:451-566` and `:38`.

---

## Metadata

**Analog search scope:**
- In-repo: `includes/class-pot-store.php`, `class-pot-plugin.php`, `class-pot-admin.php`, `parkourone-campaign-tracking.php`
- `Input/ab-webhook-endpoint/includes/`: `class-ab-custom-statuses.php`, `helper-functions.php`
- `Input/custom-events-plugin/includes/`: `class-event-cart-integration.php`
- `Input/parkourone-theme/`: `assets/js/analytics-tracker.js`, `inc/analytics/class-analytics.php`, `inc/cookie-consent/class-consent-manager.php`, `assets/js/consent-manager.js`
- Ground truth: `CONTEXT-FINDINGS.md`, `.planning/research/ARCHITECTURE.md`, `02-CONTEXT.md`

**Files scanned:** 11
**Pattern extraction date:** 2026-05-31
