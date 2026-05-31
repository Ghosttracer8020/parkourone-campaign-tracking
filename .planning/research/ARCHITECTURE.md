# Architecture Research

**Domain:** WordPress/WooCommerce campaign funnel-tracking plugin (visits → CTA clicks → completed Probetraining bookings) with admin dashboard + secured PULL REST API for an external Next.js/Vercel dashboard. German/GDPR context.
**Researched:** 2026-05-31
**Confidence:** HIGH (built on `CONTEXT-FINDINGS.md` — 17 code-verified architecture decisions, exact hooks/selectors/contracts) and `PROJECT.md`. No external research needed; this is a synthesis of verified ground truth.

> **Scope correction vs CONTEXT-FINDINGS.md:** that document was written when a push-webhook was still a live option. `PROJECT.md` has since decided on a **PULL API**: the plugin exposes a *secured REST read endpoint*; the Statusboard cron pulls it. The outbound-`wp_remote_post`/queue/retry/idempotency material in CONTEXT-FINDINGS is therefore **out of scope**. What carries over from it: the `hash_equals` constant-time-compare primitive, the `permission_callback => '__return_true'` anti-pattern to avoid, the camelCase + `generatedAt` + `SourceStatus` + `.passthrough()` consumption contract, and the `Authorization: Bearer <secret>` auth pattern — now applied to an *inbound* read route instead of an outbound POST.

## Standard Architecture

The plugin is a single PHP WordPress plugin (`parkourone-campaign-tracking`, prefix e.g. `POT_`/`pot_`) following the sibling house-style verbatim. It decomposes into six components across three layers: **Capture** (front-end JS + server-side conversion listener), **Store** (one custom `dbDelta` events table), and **Serve** (admin dashboard + pull REST API). An attribution bridge straddles capture and store.

### System Overview

```
┌──────────────────────────────────────────────────────────────────────────┐
│  CAPTURE LAYER                                                             │
│                                                                            │
│   CLIENT (consent-gated, admins excluded)        SERVER (consent-indep.)   │
│  ┌────────────────────────────┐   ┌────────────────────────────────────┐  │
│  │ C1  Tracker JS             │   │ C3  Conversion Listener            │  │
│  │  - first-touch UTM cookie  │   │  woocommerce_order_status_         │  │
│  │  - pageview (visit)        │   │     probetraining (primary)        │  │
│  │  - delegated click on      │   │  + order_status_changed where      │  │
│  │    a[href*=/probetraining- │   │     new===probetraining (fallback) │  │
│  │    buchen]                 │   │  idempotent: _pot_conversion_      │  │
│  └─────────────┬──────────────┘   │     tracked meta flag              │  │
│                │ POST (wp_rest     │  graceful-degrade if ab-webhook    │  │
│                │ nonce)            │     absent                          │  │
│                ▼                   └──────────────┬─────────────────────┘  │
│  ┌────────────────────────────┐                  │                        │
│  │ C2  Inbound Ingest Route    │                  │                        │
│  │  pot/v1/event  (visit|click)│                  │                        │
│  │  real permission_callback   │   ┌──────────────┴──────────────────┐    │
│  │  bot filter, sanitize       │   │ C4b Attribution Bridge          │    │
│  └─────────────┬──────────────┘    │  woocommerce_checkout_create_   │    │
│                │                    │  order → copy UTM cookie +      │    │
│                │                    │  session_id to order meta       │    │
│                ▼                    └──────────────┬──────────────────┘    │
├────────────────────────────────────────────────── │ ──────────────────────┤
│  STORE LAYER                                       │                       │
│  ┌─────────────────────────────────────────────────▼──────────────────┐   │
│  │ C4  wp_pot_events  (custom dbDelta table)                          │   │
│  │  id, event_type[visit|click|booking], campaign, source, medium,    │   │
│  │  landing_url, order_id, event_id, session_id, created_at           │   │
│  │  INDEX (event_type, created_at), INDEX (campaign)                  │   │
│  └─────────────────────────────────┬──────────────────────────────────┘   │
├────────────────────────────────────│───────────────────────────────────────┤
│  SERVE LAYER          ┌─────────────┴──────────┐                           │
│  ┌────────────────────▼──────┐   ┌─────────────▼────────────────────────┐  │
│  │ C5  Admin Dashboard        │   │ C6  Pull REST API                    │  │
│  │  add_submenu_page(         │   │  GET pot/v1/metrics                  │  │
│  │    'parkourone')           │   │  permission_callback = Bearer +      │  │
│  │  date-range → $wpdb        │   │    hash_equals(secret)               │  │
│  │  GROUP BY campaign         │   │  same aggregation as C5              │  │
│  │  wp-list-table + AJAX      │   │  camelCase + generatedAt payload     │  │
│  └────────────────────────────┘   └──────────────────┬───────────────────┘  │
└─────────────────────────────────────────────────────│──────────────────────┘
                                                       │ HTTPS GET (Bearer)
                                                       ▼
                                          Statusboard cron (Vercel) — out of scope
```

### Component Responsibilities

| Component | Responsibility | Typical Implementation (house-style) |
|-----------|----------------|------------------------|
| **C1 Tracker JS** (`assets/js/tracker.js`) | Set first-touch UTM cookie; fire pageview; capture-phase delegated click on the CTA selector; never run for admins or without consent | Enqueued on `wp_enqueue_scripts`, deps `['jquery']`, `in_footer`, `wp_localize_script('potTrack', {endpoint, nonce})`. Mirrors `analytics-tracker.js` capture-phase listener |
| **C2 Ingest Route** (`class-pot-rest-ingest.php`) | Receive visit/click events from C1; validate nonce; bot-filter; sanitize; insert into store | `register_rest_route('pot/v1','/event', POST)` with a **real** `permission_callback` verifying the `wp_rest` nonce — never `__return_true` |
| **C3 Conversion Listener** (`class-pot-conversion.php`) | Count a real completed Probetraining booking exactly once; read event identifiers; resolve attribution from order meta; insert `booking` row | `add_action('woocommerce_order_status_probetraining', cb, 10, 1)` + `woocommerce_order_status_changed` fallback; `_pot_conversion_tracked` meta dedupe; registration deferred to `plugins_loaded`, degrades if ab-webhook absent |
| **C4 Store** (`class-pot-store.php`) | Own the schema; create/upgrade table via `dbDelta`; provide insert + date-range aggregation query methods | Custom `wp_pot_events` table (deliberate, signed-off deviation); `register_activation_hook` runs `dbDelta`; `db_version` option for upgrades |
| **C4b Attribution Bridge** (in `class-pot-conversion.php` or own class) | Persist first-touch UTM + session_id from cookie onto the order at checkout, closing the order↔campaign gap | `woocommerce_checkout_create_order` / `woocommerce_checkout_update_order_meta` → `update_meta_data('_pot_utm_*', ...)` |
| **C5 Admin Dashboard** (`class-pot-admin.php`) | Render per-campaign funnel metrics for a chosen date range | `add_submenu_page('parkourone', ... 'manage_options')`; `<div class="wrap">`; date picker drives `$wpdb` `GROUP BY campaign`; plain `wp-list-table` rows; refresh via `wp_ajax_pot_metrics` + `check_ajax_referer` |
| **C6 Pull REST API** (`class-pot-rest-api.php`) | Serve aggregated per-campaign metrics to the Statusboard, authenticated | `register_rest_route('pot/v1','/metrics', GET)`; `permission_callback` checks `Authorization: Bearer` via `hash_equals` against a stored secret; reuses C4 aggregation; emits camelCase + `generatedAt` |

## Recommended Project Structure

```
parkourone-campaign-tracking/
├── parkourone-campaign-tracking.php   # root: header (Author: Pierre Biege, bare Version),
│                                      #   ABSPATH guard, flat require_once list,
│                                      #   pot_init() on plugins_loaded, activation hook → dbDelta
├── .git-version                       # 7-char SHA for self-updater
├── .gitignore                         # .git-version, .DS_Store
├── includes/
│   ├── class-pot-store.php            # C4: schema, dbDelta, insert, aggregation queries
│   ├── class-pot-rest-ingest.php      # C2: inbound visit/click route (nonce-gated)
│   ├── class-pot-conversion.php       # C3 + C4b: status hooks + checkout attribution bridge
│   ├── class-pot-admin.php            # C5: submenu page, date-range UI, AJAX
│   ├── class-pot-rest-api.php         # C6: secured pull endpoint (Bearer + hash_equals)
│   ├── class-pot-settings.php         # secret + endpoint-toggle option (Settings API)
│   ├── helper-functions.php           # consent check, admin check, hash_equals compare, bot filter
│   └── github-updater.php             # copied + re-prefixed; $github_repo='monkeyspk/<slug>'
└── assets/
    ├── js/tracker.js                  # C1 front-end capture
    └── js/admin.js, css/admin.css     # C5 dashboard
```

### Structure Rationale

- **`includes/class-pot-*.php`:** one class per file, PascalCase `POT_*`, static `init()` registering hooks — exactly the dominant ab-webhook idiom. Each file = one component boundary above.
- **`class-pot-store.php` as the single DB owner:** every read/write goes through it. C2, C3, C5, C6 never touch `$wpdb` for the events table directly — they call store methods. This is the key boundary that lets the dashboard and the pull API share one aggregation query.
- **C3 + C4b in one file:** both are WooCommerce-order-lifecycle server hooks sharing the same order context; keeping the checkout-time bridge next to the conversion-time read avoids a fragile cross-class dependency.
- **`github-updater.php` last in the require list** and a settings class for the API secret follow house style verbatim.

## Architectural Patterns

### Pattern 1: Single store gateway, two consumers

**What:** `POT_Store` exposes `aggregate_by_campaign($from, $to)` returning per-campaign `{visits, clicks, bookings, conversionRate}`. Both C5 (dashboard) and C6 (pull API) call the *same* method.
**When to use:** Whenever two read surfaces must report identical numbers.
**Trade-offs:** One query to optimize/index; zero drift between admin view and external payload. Cost: the method must return a neutral shape that both a `wp-list-table` renderer and a camelCase JSON serializer can consume (return snake/internal keys; C6 maps to camelCase at the edge).

```php
// C6 maps internal → contract at the boundary, never C4:
$rows = POT_Store::aggregate_by_campaign($from, $to);
$payload = [
  'generatedAt' => gmdate('c'),
  'campaigns'   => array_map(fn($r) => [
      'campaign'       => $r['campaign'],
      'visits'         => (int) $r['visits'],
      'clicks'         => (int) $r['clicks'],
      'bookings'       => (int) $r['bookings'],
      'conversionRate' => $r['visits'] ? round($r['bookings'] / $r['visits'], 4) : 0,
  ], $rows),
];
```

### Pattern 2: Consent + admin gate at the edge, server truth in the middle

**What:** Visits and clicks (C1/C2) are consent-gated and exclude logged-in admins — they are *interest* signals and tolerate gaps. Conversions (C3) are server-side, consent-independent, and authoritative.
**When to use:** This is the core of the GDPR posture and of the "if all else fails, conversion attribution must be correct" core value.
**Trade-offs:** Visit/click totals will undercount (no consent = no row); that is acceptable and correct. Booking counts are always complete because they ride WooCommerce status, not JS.

### Pattern 3: First-touch attribution via cookie → order meta bridge

**What:** C1 writes a `pot_first_touch` cookie (utm_source/medium/campaign + landing_url) only if not already set. At checkout, C4b copies it (plus the theme's `po_analytics_session_id`) onto the order as `_pot_utm_*` meta. At conversion, C3 reads that meta and stamps the `booking` row's `campaign` column.
**When to use:** This is the only reliable way to join a completed booking back to its originating campaign — no such link exists today.
**Trade-offs:** First-touch (not last-touch) is a deliberate choice matching "which campaign produced this booking." Cookie lifetime defines the attribution window; if the cookie expires before booking, attribution falls to `(direct)`.

```php
// C4b — checkout bridge
add_action('woocommerce_checkout_create_order', function ($order) {
    if (!empty($_COOKIE['pot_first_touch'])) {
        $ft = json_decode(wp_unslash($_COOKIE['pot_first_touch']), true);
        $order->update_meta_data('_pot_utm_campaign', sanitize_text_field($ft['campaign'] ?? ''));
        $order->update_meta_data('_pot_utm_source',   sanitize_text_field($ft['source']   ?? ''));
        $order->update_meta_data('_pot_landing_url',  esc_url_raw($ft['landing']  ?? ''));
    }
}, 10, 1);
```

### Pattern 4: Idempotent, gracefully-degrading conversion hook

**What:** Register the primary and fallback hooks on `plugins_loaded`. In the callback, bail early if `_pot_conversion_tracked` meta is set; otherwise insert the `booking` row and set the flag. Detect ab-webhook absence (e.g. `post_status_exists('wc-probetraining')` / class_exists) and log a degraded state rather than fataling.
**When to use:** Required because the `probetraining` status is owned by ab-webhook-endpoint, and free/100%-coupon bookings reach the status via a different (priority-999 redirect) path.

```php
add_action('plugins_loaded', function () {
    add_action('woocommerce_order_status_probetraining', 'pot_track_conversion', 10, 1);
    add_action('woocommerce_order_status_changed', function ($id, $old, $new) {
        if ($new === 'probetraining') pot_track_conversion($id);
    }, 10, 3);
}, 11); // priority 11 — after ab-webhook registers the status

function pot_track_conversion($order_id) {
    $order = wc_get_order($order_id);
    if (!$order || $order->get_meta('_pot_conversion_tracked')) return; // idempotent
    POT_Store::insert_booking($order); // reads _event_id, _pot_utm_*, order_id
    $order->update_meta_data('_pot_conversion_tracked', '1');
    $order->save();
}
```

## Data Flow

### Funnel capture flow (visits & clicks — client)

```
Visitor lands (consent given, not admin)
    ↓
C1 tracker.js: set pot_first_touch cookie (first visit only) → POST pageview
    ↓ fetch (Authorization-less, wp_rest nonce)
C2 pot/v1/event → validate nonce → bot filter → POT_Store::insert(visit)
    ↓
Visitor clicks a[href*="/probetraining-buchen"]
    ↓ capture-phase delegated listener
C1 → POST click → C2 → POT_Store::insert(click)
```

### Conversion flow (bookings — server, authoritative)

```
Checkout → C4b woocommerce_checkout_create_order: cookie UTM → order meta
    ↓ payment / free-coupon path
ab-webhook sets order status 'probetraining'
    ↓
C3 woocommerce_order_status_probetraining  (or _status_changed fallback)
    ↓ idempotency check on _pot_conversion_tracked
POT_Store::insert(booking{order_id, event_id, campaign from _pot_utm_*})
```

### Serve flow (read-only, two surfaces, one query)

```
Admin picks date range            Statusboard cron GETs pot/v1/metrics
    ↓ wp_ajax_pot_metrics              ↓ Authorization: Bearer <secret>
    │  (check_ajax_referer + cap)      │ permission_callback: hash_equals
    └──────────────┬───────────────────┘
                   ▼
        POT_Store::aggregate_by_campaign(from,to)   ← single query, GROUP BY campaign
                   │
        ┌──────────┴───────────┐
        ▼                       ▼
  wp-list-table rows      camelCase + generatedAt JSON
```

**Data-flow direction summary:** All event data flows *inward* to `POT_Store` (client → C2 → store; WooCommerce → C3 → store). All reporting flows *outward* from `POT_Store` (store → C5 admin; store → C6 → Statusboard). The store is the single hub; no component reads another component's data except through it.

## Build Order / Dependencies

Dependency-ordered so each phase produces something verifiable and nothing depends on code that doesn't exist yet.

| # | Phase | Builds | Depends on | Verifiable outcome |
|---|-------|--------|------------|--------------------|
| 1 | **Scaffold + self-updater** | root file, `pot_init()`, `github-updater.php`, settings class, `helper-functions.php` (consent/admin/hash_equals stubs) | — | Plugin activates cleanly under `parkourone` menu; updater wired |
| 2 | **Store** (C4) | `wp_pot_events` table via `dbDelta`, activation hook, `insert_*` + `aggregate_by_campaign` | 1 | Table created on activation; manual insert + aggregate works |
| 3 | **Conversion listener + bridge** (C3 + C4b) | status hooks, idempotency flag, checkout meta bridge | 2 | A test order reaching `probetraining` writes exactly one `booking` row with campaign attribution; graceful-degrades without ab-webhook |
| 4 | **Client capture** (C1 + C2) | tracker.js, first-touch cookie, nonce-gated ingest route, bot filter, consent/admin gate | 2 | Visits + clicks land as rows only when consent given and not admin |
| 5 | **Admin dashboard** (C5) | submenu page, date-range UI, AJAX, `wp-list-table` | 2,3,4 | Per-campaign funnel visible for a chosen range |
| 6 | **Pull REST API** (C6) | secured GET `/metrics`, Bearer + `hash_equals`, camelCase + `generatedAt` | 2,3,4 + secret option | Authenticated GET returns contract-shaped JSON; 401 without/with wrong secret |
| 7 | **Retire theme analytics** | disable theme tracker enqueue/server insert without leaving a gap | 3,4,5 (running) | No double-counting; new plugin is sole source |

**Why this order:** C4 (store) is the hub everything else reads/writes, so it comes first. C3 (conversions) precedes C1/C4 client capture because conversions are the *core value* and are independent of the client layer — shipping them first guarantees the irreplaceable metric works even if the client layer is delayed. C5 and C6 both consume the same store method, so they come after capture is producing data; building C5 first lets you eyeball numbers before exposing them externally via C6. Theme retirement is last and gated on the new system already running (see Integration Points).

## Scaling Considerations

| Scale | Architecture adjustments |
|-------|--------------------------|
| Single site, low traffic (current `berlin.parkourone.com`) | Custom table with `(event_type, created_at)` + `(campaign)` indices is ample. No caching needed |
| Higher visit volume | Add a `pot_events_daily` rollup (aggregate visits/clicks per campaign per day via `wp_schedule_event`); date-range queries hit the rollup, raw rows kept only for a short window |
| Retention pressure | Cron pruning of raw `visit`/`click` rows older than N days; **never prune `booking` rows** (they are the conversion record of truth) |

### Scaling Priorities

1. **First bottleneck:** unindexed date-range `GROUP BY campaign` over a growing visits table → add the composite index from day one; if it still slows, introduce the daily rollup table.
2. **Second bottleneck:** table bloat from high visit volume → retention/pruning policy on raw rows; keep bookings forever.

## Anti-Patterns

### Anti-Pattern 1: Open REST endpoints (`permission_callback => '__return_true'`)
**What people do:** Copy ab-webhook-endpoint's REST routes literally.
**Why it's wrong:** Both the ingest route (C2) and especially the pull API (C6) would be fully open — anyone could exfiltrate aggregated metrics or spam fake events.
**Do this instead:** C2 uses a real `wp_rest` nonce check; C6 verifies `Authorization: Bearer` with `hash_equals` against a stored secret (generated via `wp_generate_password(32,false)` on activation). Never reuse `wp_salt` — the Statusboard can't share it.

### Anti-Pattern 2: Double-counting against the still-live theme tracker
**What people do:** Ship the new plugin's CTA click listener while `analytics-tracker.js` still emits `cta_click` on the same buttons.
**Why it's wrong:** Both fire on the same `/probetraining-buchen` links → inflated click counts during the overlap.
**Do this instead:** Build and verify the new plugin end-to-end first, then in the retirement phase disable the theme tracker's enqueue and its `track_purchase` server insert in one move. Run a brief shadow period reading both, compare, then cut over.

### Anti-Pattern 3: Relying on a single conversion hook
**What people do:** Hook only `woocommerce_order_status_probetraining`.
**Why it's wrong:** Free/100%-coupon bookings reach the status via the priority-999 `ab_redirect_order_to_event_status` path and may not fire the same action cleanly.
**Do this instead:** Also listen to `woocommerce_order_status_changed` where `new_status === 'probetraining'`, guarded by the `_pot_conversion_tracked` idempotency flag so the two hooks never double-count.

### Anti-Pattern 4: Same-day visitor-hash attribution heuristic
**What people do:** Join bookings to campaigns by matching same-day `visitor_hash` (the existing `get_probetraining_count` approach).
**Why it's wrong:** Misattributes and undercounts; breaks across days/devices.
**Do this instead:** Persist first-touch UTM to order meta at checkout (C4b). The booking carries its own campaign; no heuristic join.

## Integration Points

### External Services

| Service | Integration Pattern | Notes |
|---------|---------------------|-------|
| ONE Statusboard (Next.js/Vercel) | **Pull**: Statusboard cron GETs `pot/v1/metrics` with `Authorization: Bearer <secret>` | Plugin only *exposes* the read endpoint; receiver/cron is out of scope. Payload must be camelCase + `generatedAt` ISO-8601, `SourceStatus`-compatible, additive (`.passthrough()` on the consumer side tolerates extra fields). No retry/idempotency needed — pull model means a missed pull just retries next cycle |
| github.com (monkeyspk) | Self-updater polls `commits/main`, compares `.git-version` SHA | Copied verbatim, re-prefixed; shared `parkourone_github_token` |

### Internal Boundaries

| Boundary | Communication | Notes |
|----------|---------------|-------|
| C1 ↔ C2 | HTTP POST (`fetch`, `wp_rest` nonce) | Consent + admin gate enforced *before* the POST in C1 |
| C2/C3 → C4 | Direct PHP method calls (`POT_Store::insert_*`) | Store is the only writer of `wp_pot_events` |
| C4 → C5/C6 | Direct PHP method call (`POT_Store::aggregate_by_campaign`) | One query, two renderers; C6 maps to camelCase at the edge |
| C3 ↔ ab-webhook-endpoint | Loose: depends on the `probetraining` status existing | `plugins_loaded` priority 11 + graceful degradation if absent |
| Plugin ↔ parkourone-theme | Reuses `po_has_consent('analytics')` + `po_analytics_session_id`; replaces theme tracker | Retirement must be coordinated to avoid a tracking gap (shadow period → cut over) |

### Retiring the theme analytics without a gap

1. Ship and activate the new plugin with C1–C6 working; let it record in parallel with the theme tracker (**shadow period**, a few days).
2. Compare new-plugin counts against the theme's `wp_po_analytics_events` for the same window to validate parity (clicks/visits) and that bookings now reflect *real* conversions, not the `LIKE '%probetraining%'` heuristic.
3. In one change, disable the theme tracker: stop `Analytics::enqueue_tracker()` (no more `analytics-tracker.js`) and stop the `woocommerce_checkout_order_processed → track_purchase` insert. The new plugin's conversion listener (C3) is already live, so there is **no window where bookings go uncounted**.
4. Leave the old `wp_po_analytics_events` table in place (read-only historical) — do not migrate; the new table is the forward source of truth.

## Sources

- `CONTEXT-FINDINGS.md` — code-verified hooks, selector, house style, Statusboard contract (HIGH; primary ground truth, all claims file-cited)
- `PROJECT.md` — pull-API decision, GDPR constraints, dependency on ab-webhook-endpoint, theme-retirement requirement (HIGH)
- Verified WooCommerce hooks: `woocommerce_order_status_probetraining`, `woocommerce_order_status_changed`, `woocommerce_checkout_create_order` / `_update_order_meta` (HIGH — confirmed in sibling-plugin code per CONTEXT-FINDINGS)
- Verified CTA selector `a[href*="/probetraining-buchen"]` (HIGH — confirmed 100% URL-consistent across all theme blocks)

---
*Architecture research for: WordPress/WooCommerce campaign funnel-tracking plugin with secured pull REST API*
*Researched: 2026-05-31*
