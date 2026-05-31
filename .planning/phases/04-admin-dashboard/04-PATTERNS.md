# Phase 4: Admin Dashboard - Pattern Map

**Mapped:** 2026-05-31
**Files analyzed:** 5 (2 modified, 2 created, 1 conditionally modified)
**Analogs found:** 5 / 5

## File Classification

| New/Modified File | Op | Role | Data Flow | Closest Analog | Match Quality |
|-------------------|----|------|-----------|----------------|---------------|
| `includes/class-pot-admin.php` | MODIFY | controller (admin page) | request-response + CRUD-read | `Input/ab-webhook-endpoint/includes/class-ab-customer-overview.php` | exact |
| `includes/class-pot-plugin.php` | MODIFY | orchestrator | wiring | (self — already wires `POT_Admin::init()`) | n/a (no change needed if handler stays in POT_Admin) |
| `assets/js/pot-dashboard.js` | CREATE | script (JS) | request-response (AJAX) | `Input/ab-webhook-endpoint/assets/js/customer-admin.js` | role-match |
| `assets/css/pot-dashboard.css` | CREATE (optional) | style | n/a | `Input/ab-webhook-endpoint/assets/css/customer-admin.css` | role-match |
| `includes/class-pot-store.php` | MODIFY (optional) | gateway (model) | CRUD-read | (self — `aggregate_by_campaign`) | exact (same class) |

**Key architectural call:** the existing `POT_Admin::init()` already runs (`class-pot-plugin.php:21`). The AJAX handler + enqueue belong INSIDE `POT_Admin::init()` (exactly as `AB_Customer_Overview::init()` registers all three: `add_menu_page`, `admin_enqueue_scripts`, `wp_ajax_*` in one place — `class-ab-customer-overview.php:8-12`). **No edit to `class-pot-plugin.php` is required** — it just calls `POT_Admin::init()`. Document this so the planner does not invent a separate handler class.

---

## Pattern Assignments

### `includes/class-pot-admin.php` (controller — MODIFY placeholder → dashboard)

**Analog:** `Input/ab-webhook-endpoint/includes/class-ab-customer-overview.php`

The placeholder (`class-pot-admin.php:19-24`) registers only `add_menu_page` at prio 999 with the `parkourone`-parent-or-fallback guard. **KEEP the prio-999 fallback guard** (`class-pot-admin.php:26-48`) — it is a deliberate improvement over the analog (analog has no fallback, `class-ab-customer-overview.php:14-23`). ADD the two missing `add_action` calls to `init()`.

**`init()` registration to MIRROR** (`class-ab-customer-overview.php:8-12`):
```php
public static function init() {
    add_action('admin_menu', [__CLASS__, 'add_menu_page']);            // KEEP existing at prio 999
    add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin_scripts']);  // ADD
    add_action('wp_ajax_pot_metrics', [__CLASS__, 'ajax_metrics']);    // ADD — action name = pot_metrics (no nopriv)
}
```
CHANGE vs analog: action is `wp_ajax_pot_metrics` (not `ab_get_customer_details`); keep existing `add_menu_page` at prio 999 unchanged.

**Hook-gated enqueue + localize to MIRROR** (`class-ab-customer-overview.php:25-49`):
```php
public static function enqueue_admin_scripts($hook) {
    if ($hook !== 'parkourone_page_ab-customers') { return; }     // CHANGE → 'parkourone_page_' . self::MENU_SLUG
    wp_enqueue_script('ab-customer-admin',
        plugins_url('assets/js/customer-admin.js', dirname(__FILE__)),  // CHANGE → assets/js/pot-dashboard.js, handle 'pot-dashboard'
        ['jquery'], '1.0.0', true);
    wp_localize_script('ab-customer-admin', 'abCustomerAdmin', [
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('ab_customer_admin')            // CHANGE → wp_create_nonce('pot_metrics'), JS var 'potDashboard'
    ]);
    wp_enqueue_style('ab-customer-admin',
        plugins_url('assets/css/customer-admin.css', dirname(__FILE__)), [], '1.0.0');  // CHANGE → pot-dashboard.css
}
```
GATE STRING: with `MENU_SLUG = 'parkourone-campaign-tracking'`, the hook is `parkourone_page_parkourone-campaign-tracking` — build it as `'parkourone_page_' . self::MENU_SLUG` so it survives slug changes AND matches the fallback `add_menu_page` hook (`toplevel_page_<slug>`). Note the fallback path produces a DIFFERENT hook (`toplevel_page_...`); accept both: `if ($hook !== 'parkourone_page_' . self::MENU_SLUG && $hook !== 'toplevel_page_' . self::MENU_SLUG) return;`

**Table render to MIRROR** (`class-ab-customer-overview.php:51-72`, `86-129`) — plain wp-list-table with `<div class="wrap"><h1>`, sprintf rows, `esc_*`:
```php
echo '<div class="wrap"><h1>Campaign Tracking</h1>';
echo '<table class="wp-list-table widefat fixed striped"><thead><tr>'
   . '<th scope="col">...</th>...</tr></thead><tbody>';
$output .= sprintf(
    '<tr><td>%s</td><td>%d</td>...</tr>',
    esc_html($row['campaign']),
    $row['visits']                       // ints; use number_format_i18n() for display
);
// empty-state fallback (analog line 128):
return $output ?: '<tr><td colspan="7">Keine Daten im gewählten Zeitraum</td></tr>';
```
CHANGE vs analog: columns are Kampagne | Visits | Klicks | Buchungen | Conversion-Rate | Visit→Klick | Klick→Buchung; add `<th scope="col">` for a11y (CONTEXT specific); format counts with `number_format_i18n()`; rates via a shared rate helper (see Shared Patterns). The dashboard body `<tbody>` must carry an id (e.g. `id="pot-metrics-body"`) so the JS replaces it. ADD the date-range control bar above the table and the health banner (read `get_option('pot_conversion_status')`, render only when `!== 'ok'`).

**AJAX handler — MIRROR the `wp_send_json_*` shape from gutschein, NOT the `wp_die(html)` shape.** The customer-overview handler returns raw HTML via `wp_die(ob_get_clean())` (`class-ab-customer-overview.php:359`); CONTEXT requires `wp_send_json_success(['rows'=>…,'totals'=>…,'health'=>…,'range'=>…])`. Use the gutschein guard pattern (`class-ab-gutschein-settings.php:43-78`):
```php
public static function ajax_metrics() {
    check_ajax_referer('pot_metrics', 'nonce');          // CONTEXT: check_ajax_referer('pot_metrics')
    if (!current_user_can('manage_options')) {           // CONTEXT: manage_options (analog used manage_woocommerce)
        wp_send_json_error(['message' => 'Keine Berechtigung']);
        return;
    }
    // sanitize preset/from/to → resolve UTC window → POT_Store::aggregate_by_campaign($from,$to)
    wp_send_json_success(['rows'=>$rows, 'totals'=>$totals, 'health'=>$health, 'range'=>$range]);
}
```
Server-side sanitize input the way the analog sanitizes (`sanitize_email` at `class-ab-customer-overview.php:185`) — here use `sanitize_key($_POST['preset'] ?? '')` and validate `from`/`to` against `/^\d{4}-\d{2}-\d{2}$/`, reject `from > to`, cap span ≤ 366 days, clamp future dates (CONTEXT DASH-03).

**No `nopriv` action** — register only `wp_ajax_pot_metrics`, never `wp_ajax_nopriv_pot_metrics`.

---

### `assets/js/pot-dashboard.js` (script — CREATE)

**Analog:** `Input/ab-webhook-endpoint/assets/js/customer-admin.js`

**jQuery-ready + `$.ajax` + localized object to MIRROR** (`customer-admin.js:1-33`):
```js
jQuery(document).ready(function($) {
    $.ajax({
        url: abCustomerAdmin.ajaxurl,                 // CHANGE → potDashboard.ajaxurl
        type: 'POST',
        data: { action: 'ab_get_customer_details',    // CHANGE → action:'pot_metrics', plus preset/from/to
                nonce: abCustomerAdmin.nonce },        // CHANGE → potDashboard.nonce
        success: function(response) { ... },           // CHANGE → render rows from response.data.rows/totals
        error: function() { ... }                      // CHANGE → graceful error message
    });
});
```
CHANGE vs analog: action `pot_metrics`; localized var `potDashboard`; bind change handlers on the preset control + custom date inputs + a manual "Aktualisieren" button; on response replace `#pot-metrics-body` innerHTML (build rows client-side from `response.data.rows`/`totals`); loading state = disable control + WP `spinner is-active` (analog uses `'<div class="spinner is-active"></div>'`, `customer-admin.js:11`); empty/error states per CONTEXT DASH-04. Initial page load is server-rendered (progressive enhancement) — JS only re-renders on interaction.

---

### `assets/css/pot-dashboard.css` (style — CREATE, optional)

**Analog:** `Input/ab-webhook-endpoint/assets/css/customer-admin.css`. Keep minimal — native wp-admin classes carry most styling. Only add: control-bar layout, `.pot-warning` marker styling for impossible-funnel cells, optional health-banner accents. Enqueued identically (`class-ab-customer-overview.php:43-48`).

---

### `includes/class-pot-store.php` (gateway — MODIFY only if a range helper is needed)

**Analog:** itself — `aggregate_by_campaign($from,$to)` (`class-pot-store.php:89-117`) is THE read path and already does the GROUP BY with `UNATTRIBUTED` bucketing and `$wpdb->prepare`.

DO NOT add SQL outside this gateway (CONTEXT: single source, zero drift with Phase 5 API). If a preset→datetime resolver is added, mirror the existing static-helper + `$wpdb->prepare` style (`class-pot-store.php:89-107`) and the bracketed-prefix error log (`class-pot-store.php:71,112`: `error_log('[POT Tracking] ...')`). The aggregation itself stays unchanged — the dashboard computes rates from the three returned counts. **UTC conversion does NOT belong in the gateway** — `aggregate_by_campaign` expects already-UTC `'Y-m-d H:i:s'` bounds (`class-pot-store.php:85-86`). The local-day→UTC conversion is the caller's job (in `POT_Admin`).

---

## Shared Patterns

### AJAX security envelope
**Source:** `Input/ab-webhook-endpoint/includes/class-ab-gutschein-settings.php:43-49,72-78`
**Apply to:** `POT_Admin::ajax_metrics`
```php
check_ajax_referer('pot_metrics', 'nonce');
if (!current_user_can('manage_options')) { wp_send_json_error(['message'=>'Keine Berechtigung']); return; }
// ... wp_send_json_success([...]) / wp_send_json_error([...])
```
Nonce name `pot_metrics` is identical on the create (`wp_create_nonce('pot_metrics')` in enqueue) and verify (`check_ajax_referer('pot_metrics',...)`) sides.

### Output escaping
**Source:** `Input/ab-webhook-endpoint/includes/class-ab-customer-overview.php:110-125`
**Apply to:** every cell in the table render and AJAX-built rows. `esc_html()` for text/counts, `esc_attr()` for attributes (`data-*`, status slugs), `esc_url()` for any links. Counts go through `number_format_i18n()` before `esc_html()`.

### Rate helper (divide-by-zero guard) — NEW, no analog
**Source:** none (no rate/percentage helper exists in either sibling plugin — confirmed). Define in `POT_Admin`.
**Apply to:** Conversion-Rate, Visit→Klick, Klick→Buchung.
```php
// numerator/denominator → '–' when denominator is 0, else number_format_i18n(pct, 1) . ' %'
private static function rate($num, $den) {
    if ((int)$den === 0) { return '–'; }
    return number_format_i18n(($num / $den) * 100, 1) . ' %';
}
```
Use `number_format_i18n` (German locale) per house style. RESEARCH `.planning/research/FEATURES.md` / `PITFALLS.md` define the denominator semantics — defer exact wording to those.

### Impossible-funnel warning marker — NEW, no analog
**Source:** none. CONTEXT DASH-02 / ROADMAP SC2. If `clicks > visits` OR `bookings > clicks`, render the offending cell with a visible marker (`<span class="dashicons dashicons-warning" title="Unplausibel: …"></span>`) instead of hiding the row. `esc_attr()` the title.

### Bracketed-prefix error logging
**Source:** `Input/ab-webhook-endpoint/includes/github-updater.php` convention; already used in this repo at `includes/class-pot-store.php:71,112` (`error_log('[POT Tracking] ...')`).
**Apply to:** any new failure path (`POT_Store` range helper, unexpected AJAX state).

---

## UTC / timezone conversion (CRITICAL, no direct analog)

Events store `created_at` as UTC via `current_time('mysql', true)` (`includes/class-pot-store.php:64`). The date picker is in site timezone. In `POT_Admin` (the caller, NOT the gateway): take the LOCAL day boundaries (from `00:00:00`, to `23:59:59` site-local), convert to UTC with `wp_timezone()` → `DateTime`/`DateTimeZone('UTC')` → `format('Y-m-d H:i:s')` (or `get_gmt_from_date()`), THEN pass to `aggregate_by_campaign`. Document that the Phase 5 API must use the identical conversion so dashboard and API agree. No sibling-plugin code does this conversion — it is new, derived from the store's UTC write contract.

---

## No Analog Found

| File/Concern | Role | Reason |
|--------------|------|--------|
| rate-percentage helper w/ divide-by-zero guard | utility | No percentage/rate helper exists in either sibling plugin |
| impossible-funnel warning marker | utility | No data-quality-flag pattern exists |
| local→UTC date-window conversion | utility | No timezone-conversion code in siblings; derived from `POT_Store` UTC write contract |
| `wp_send_json_success` with structured `rows/totals/health/range` | controller | The wp-list-table analog returns raw HTML via `wp_die`, not JSON; JSON shape borrowed from gutschein handler but the multi-key payload is new |

Planner: for the four above, follow CONTEXT.md DASH-02/03/04 and `.planning/research/FEATURES.md` + `PITFALLS.md`, not a codebase analog.

---

## Metadata

**Analog search scope:** `Input/ab-webhook-endpoint/includes/` (overview classes, gutschein settings, menu organizer), `Input/ab-webhook-endpoint/assets/js|css/`, this repo's `includes/`.
**Files scanned:** 8 read + grep across siblings.
**Best analog:** `class-ab-customer-overview.php` (exact: submenu under `parkourone` + hook-gated enqueue + `wp_localize_script` nonce/ajaxurl + wp-list-table sprintf/esc + `wp_ajax_*` handler). `wp_send_json_*` envelope borrowed from `class-ab-gutschein-settings.php`.
**Pattern extraction date:** 2026-05-31
