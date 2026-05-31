# Phase 2: Conversion & Attribution Bridge - Context

**Gathered:** 2026-05-31
**Status:** Ready for planning
**Mode:** Auto-decided (autonomous; grey areas resolved from CONTEXT-FINDINGS.md + research + Phase 1 implementation, no open questions)

<domain>
## Phase Boundary

Make every completed Probetraining booking counted exactly once — server-side and consent-independent — and attribute it back to its originating campaign via first-touch UTM. This is the irreplaceable core-value metric. Delivers: (1) the server-side conversion listener that writes a `booking` row through `POT_Store`, (2) idempotency + free/coupon fallback + graceful degradation when the `probetraining` status is unavailable, (3) the first-touch UTM attribution bridge — a small consent-gated client capture of UTM into a first-party cookie, plus server-side persistence of that cookie to WooCommerce order meta at checkout, (4) the unattributed bucket.

In scope: CONVERT-01..04, ATTRIB-01..04.
OUT of scope this phase: the visit/click beacon + CTA-click tracking + theme-tracker retirement (Phase 3), the dashboard (Phase 4), the pull-API (Phase 5). The Phase 2 attribution JS is ONLY the UTM-first-touch cookie capture — NOT the visit/click beacon.
</domain>

<decisions>
## Implementation Decisions

### Phase 1 interface (reuse, do not modify)
- Write the booking via `POT_Store::insert_event([...])` — whitelisted columns are `event_type, campaign, source, medium, landing_path, session_id, order_id, event_ref, created_at` (see `includes/class-pot-store.php`). For a conversion: `event_type => 'booking'`, `campaign/source/medium` from attribution meta, `order_id => $order->get_id()`, `event_ref => (int) _event_id`, `created_at` defaults to UTC now.
- Empty/NULL campaign is already bucketed by `POT_Store::aggregate_by_campaign()` under `POT_Store::UNATTRIBUTED` (`'(unattributed)'`). Reuse that constant; do NOT invent a second bucket. For v1, "direct" == "unattributed" (no UTM → one named bucket).
- New conversion/attribution code lives in `includes/class-pot-conversion.php` and `includes/class-pot-attribution.js` (asset) + a server attribution class `includes/class-pot-attribution.php`, wired from `POT_Plugin` (the `pot_init()` orchestrator) — same flat `require_once` + instantiate-on-`plugins_loaded` pattern as Phase 1.

### Conversion listener (server-side, consent-independent) — CONVERT-01..03
- Single handler `POT_Conversion::record_conversion( int $order_id )`. Register it on BOTH:
  - `add_action('woocommerce_order_status_probetraining', [.., 'record_conversion'], 10, 1)` (primary path), AND
  - `add_action('woocommerce_order_status_changed', [.., 'on_status_changed'], 10, 4)` where `on_status_changed($order_id,$from,$to,$order)` calls `record_conversion($order_id)` only when `$to === 'probetraining'` (catches the free/100%-coupon fallback redirect at priority 999 documented in CONTEXT-FINDINGS).
- Hook registration deferred to `plugins_loaded` (priority 11+, after `ab-webhook-endpoint` registers the status).
- Idempotency (CONVERT-03): in `record_conversion`, load `$order = wc_get_order($order_id)` (HPOS-safe — never `get_post_meta`). If `$order->get_meta('_pot_conversion_tracked') === 'yes'` → return early. Otherwise insert the booking row, then `$order->update_meta_data('_pot_conversion_tracked','yes'); $order->save();`. Guards both hooks firing and repeated transitions.
- Booking attribution at conversion time: read campaign/source/medium from order meta `_pot_campaign`/`_pot_source`/`_pot_medium` (written by the attribution bridge below). If absent → campaign empty (→ unattributed). Also capture `event_ref` from the order's first matching event item meta `_event_id` when present (best-effort; null otherwise).

### Graceful degradation (CONVERT-04)
- On init, detect whether the `probetraining` status exists: check `array_key_exists('wc-probetraining', wc_get_order_statuses())` OR `post_status_exists('wc-probetraining')` (guard `function_exists`/WooCommerce active). Store result in autoload=false option `pot_conversion_status` = `'ok'` | `'not_configured'`.
- If `not_configured`: still register the `woocommerce_order_status_changed` fallback (status may appear later), show a dismissible admin notice ("Probetraining-Status nicht gefunden — ist das Plugin ab-webhook-endpoint aktiv? Conversions werden bis dahin nicht gezählt."), and never fatal. The dashboard/API (later phases) read `pot_conversion_status` to surface a health state instead of silently reporting zero.
- If WooCommerce itself is inactive: skip all WC hooks entirely, set `pot_conversion_status = 'not_configured'`, no fatal.

### Attribution — first-touch UTM (ATTRIB-01..04)
- Client capture `assets/js/pot-attribution.js` (enqueued front-end via `wp_enqueue_scripts`, deps none, skip for logged-in admins via a localized flag): on load, parse `utm_campaign`, `utm_source`, `utm_medium` (+ keep `landing_path` = `location.pathname`) from the URL. FIRST-TOUCH: only record if no existing first-touch value is stored. CONSENT TIMING (ATTRIB-01): if `po_has_consent('analytics')` is NOT yet granted, hold the captured UTM in `sessionStorage` only (no cookie). When/if consent becomes granted (re-check on load + on the theme's consent-change event if available), PROMOTE the held value into the persistent first-party cookie `pot_attribution` (JSON `{campaign,source,medium,landing_path,first_seen}`), `SameSite=Lax`, path `/`, lifetime 90 days. Never overwrite an existing cookie (first-touch wins).
- Server persistence (ATTRIB-02): on `woocommerce_checkout_create_order` (HPOS-safe order CRUD), read `$_COOKIE['pot_attribution']` (json_decode, sanitize each field with `sanitize_text_field`, cap length), and `$order->update_meta_data('_pot_campaign'/'_pot_source'/'_pot_medium'/'_pot_landing', ...)`. No cookie → write nothing (booking later lands in unattributed). Persisting at order-create time means the conversion handler (which may fire much later on status change) reads stable order meta, not a transient cookie.
- Grouping (ATTRIB-03): visits/clicks (Phase 3) and bookings all carry the same campaign string, so `aggregate_by_campaign` groups all three stages by first-touch campaign.
- Unattributed (ATTRIB-04): handled by the empty-campaign → `POT_Store::UNATTRIBUTED` bucket. Bookings are NEVER dropped for missing UTM.

### Cookie / privacy
- Cookie name `pot_attribution`, lifetime 90 days, `SameSite=Lax`, not `HttpOnly` (JS needs to read it; it carries only campaign labels, no PII). Consent-gated per above. Document the 90-day first-touch window; flagged for DPO review (STATE.md concern) but not blocking.

### Claude's Discretion
- Exact JS structure of `pot-attribution.js`, the consent-change event name to listen for (probe the theme; fall back to re-check on next pageload), order-item-meta lookup details for `event_ref`, and internal method decomposition — at Claude's discretion, consistent with CONTEXT-FINDINGS conventions.
</decisions>

<code_context>
## Existing Code Insights

### Reusable assets (Phase 1, this repo)
- `includes/class-pot-store.php` — `POT_Store::insert_event()`, `aggregate_by_campaign()`, `UNATTRIBUTED` const. THE write path for bookings.
- `includes/class-pot-plugin.php` — `pot_init()` orchestrator; wire new classes here (instantiate on `plugins_loaded`).
- `includes/class-pot-admin.php` — admin-notice pattern for the not_configured degradation notice.
- `parkourone-campaign-tracking.php` — constants (`POT_PLUGIN_DIR/URL/FILE`), enqueue base for the attribution asset.

### Ground-truth references
- `CONTEXT-FINDINGS.md` — verified conversion hooks (`woocommerce_order_status_probetraining`, fallback `order_status_changed`, free/coupon path at priority 999), `_event_id`/order-item meta, statusboard contract; consent gate `po_has_consent('analytics')`; selector facts.
- `.planning/research/PITFALLS.md` — consent-timing-vs-attribution (capture in memory, write cookie only on consent), silent-zero-conversions dependency risk, free/coupon bypass.
- `.planning/research/ARCHITECTURE.md` — C3 conversion listener + C4b attribution bridge design.

### Integration points
- WooCommerce hooks: `woocommerce_order_status_probetraining`, `woocommerce_order_status_changed`, `woocommerce_checkout_create_order`. All via `wc_get_order()` / order CRUD (HPOS).
- Theme consent API: `po_has_consent('analytics')` (front-end JS) — already used by the theme tracker being replaced.
- Order meta keys introduced: `_pot_conversion_tracked`, `_pot_campaign`, `_pot_source`, `_pot_medium`, `_pot_landing`.
</code_context>

<specifics>
## Specific Ideas

- The conversion number MUST be correct (core value): dual-hook + idempotency flag is non-negotiable. Add a static check that BOTH hooks route through the single `record_conversion` and that the `_pot_conversion_tracked` guard is checked BEFORE insert.
- Static verification (no PHP/WP runtime here): grep for both `add_action` registrations, the `$to === 'probetraining'` guard, the `_pot_conversion_tracked` early-return, `wc_get_order(`, `update_meta_data(`, `POT_Store::insert_event(`, the `pot_attribution` cookie read with `sanitize_text_field`, and `po_has_consent` in the JS. Provide manual runtime steps (place a test order → set status probetraining → assert exactly one booking row; repeat transition → still one; free-coupon order → counted; deactivate ab-webhook-endpoint → not_configured notice + no fatal) for the WP-staging checklist.
- Keep options `autoload=false`.
</specifics>

<deferred>
## Deferred Ideas

- Visit/click beacon, CTA-click tracking, bot filter, admin exclusion of beacons, theme-tracker retirement → Phase 3 (the attribution JS here only does UTM first-touch cookie capture; Phase 3's tracker will reuse the `pot_attribution` cookie).
- Surfacing `pot_conversion_status` as a visible health indicator → Phase 4 (dashboard) / Phase 5 (API). Phase 2 only sets the option + admin notice.
- Configurable cookie lifetime / attribution window UI → v2.
</deferred>
