# Project Research Summary

**Project:** ParkourONE Campaign Tracking
**Domain:** WordPress/WooCommerce funnel-tracking plugin with secured pull REST API (DSGVO/German context)
**Researched:** 2026-05-31
**Confidence:** HIGH

## Executive Summary

ParkourONE Campaign Tracking is a self-contained WordPress plugin that measures a fixed 3-stage conversion funnel (landing-page visit → "Probetraining buchen" CTA click → completed WooCommerce booking) per UTM campaign, exposes the aggregates via a secured pull REST API for the ONE Statusboard, and retires the existing theme analytics system without a tracking gap. The research is unusually well-grounded: all four research files build on `CONTEXT-FINDINGS.md`, a code-verified analysis of the live codebase, so most patterns are confirmed against real files rather than inferred from documentation.

The recommended approach is a single PHP plugin following the `ab-webhook-endpoint`/`custom-events-plugin` house style verbatim (no build step, no framework, no Composer autoload in shipped code), with one deliberate and signed-off deviation: a `dbDelta` custom events table for time-series date-range queries. Visits and clicks are captured via a client-side JS beacon (consent-gated, admin-excluded) POSTed to a nonce-gated REST ingest route — never server-side, because full-page caching would silently zero PHP-side counters. Conversions are counted server-side from the WooCommerce `probetraining` order status, consent-independent. The attribution bridge (first-touch UTM cookie → order meta at checkout) is the spine that joins the client and server paths.

The three principal risks are: (1) silent zero-conversions if `ab-webhook-endpoint` is deactivated — requires a `post_status_exists` guard, admin notice, and a `not_configured` health flag in the pull-API payload; (2) attribution loss if the first-touch cookie is written before consent or expires before booking — UTM must be captured in JS memory on load and only written to a cookie once `po_has_consent('analytics')` fires; (3) double-counting during cutover from the theme's still-running `analytics-tracker.js` — theme tracker deactivation is a blocking acceptance criterion, not a cleanup task. All three risks have well-defined prevention patterns established in the research.

## Key Findings

### Recommended Stack

The plugin targets PHP 8.1+ (floor; test up to 8.3), WordPress >= 6.9, WooCommerce >= 8.2. WooCommerce 8.2 is when HPOS became the default — **HPOS compliance is non-negotiable**: declare `custom_order_tables` compatibility on `before_woocommerce_init` via `FeaturesUtil::declare_compatibility`, and read/write all orders through `wc_get_order()` + the CRUD meta methods (`update_meta_data` / `get_meta` / `save()`). Never use `get_post()` or `get_post_meta()` on order IDs.

The two REST surfaces have different auth postures and must not share a mechanism. The inbound visit/click ingest route uses `wp_rest` nonce verification (same-origin, public users). The outbound pull API uses a shared Bearer secret verified with `hash_equals()` after a length check — this mirrors the Statusboard's existing `CRON_SECRET`/`timingSafeEqual` pattern exactly, and is preferable to Application Passwords (which require a WP user account, are often stripped by hosts, and are broader than needed for a single read-only aggregate endpoint). The secret is minted with `wp_generate_password(64, false)` into an `autoload=false` option — never `wp_salt`, which cannot be shared with an external party.

**Core technologies:**
- PHP 8.1+ / WordPress 6.9+ / WooCommerce 8.2+: platform baseline — HPOS default, stable hooks available
- Custom `dbDelta` events table (`wp_pot_events`): time-series store — options/post-meta cannot serve date-range GROUP BY queries at visit volume
- WP REST API (two namespaced routes): `pot/v1/event` (nonce-gated ingest) + `pot/v1/metrics` (Bearer pull API)
- Vanilla JS beacon (~30 lines, no library): consent-gated first-touch UTM capture + pageview + delegated CTA click
- PHPCS 3.13 + WordPressCS 3.x (dev-only, Composer): linting — not shipped in the plugin zip

No charting library in v1. Admin dashboard uses `<table class="wp-list-table widefat fixed striped">` rows plus hand-built CSS bars, matching the existing house pattern. Chart.js v4 is the correct choice if a chart is later requested — bundled locally in `assets/js/`, never from a CDN (CDN loads leak admin IP/UA to third parties, violating DSGVO data-minimisation).

### Expected Features

The feature set is precisely scoped. The funnel is fixed at exactly 3 stages; no configurable funnels, no multi-touch attribution, no per-user journeys, no raw event export.

**Must have (table stakes for v1):**
- 3-stage funnel counts per campaign (visits / CTA clicks / bookings) with conversion rate and step-to-step drop-off
- Booking conversion via `woocommerce_order_status_probetraining` + `woocommerce_order_status_changed` fallback, idempotency flag (`_pot_conversion_tracked`), graceful degradation if `ab-webhook-endpoint` absent
- First-touch UTM attribution bridge: capture UTM in JS memory on load → write cookie on consent → persist to order meta at `woocommerce_checkout_create_order`
- "Direct / (kein UTM)" bucket and "(unattributed)" bucket — never silently drop unconverted campaigns
- Per-campaign breakdown table with date-range presets (today / 7d / 30d / custom), default 30d
- Secured pull REST endpoint — aggregated per-campaign metrics, `Authorization: Bearer` + `hash_equals`, camelCase fields + `generatedAt` ISO-8601
- Data retention: daily cron prunes raw visit/click rows after N days (recommend 180d); no IP column, URLs sanitized, no PII
- Bot/prefetch filtering at ingest; admin exclusion; consent gate

**Should have (add after initial validation):**
- Compare-to-previous-period delta — nearly free once date-range querying exists; high value
- Unique vs total visits toggle — honest conversion denominator
- API delivery health indicator (`not_configured`/`stale` status flag in pull-API payload)
- CSV export of per-campaign table (aggregated only, DSGVO-clean)

**Defer to v2+:**
- Configurable retention period UI
- Multiple conversion types (workshops/courses)
- Per-landing-page sub-dimension breakdowns

**Explicit anti-features (never build):**
- Charts in WP admin (tables + CSS bars are sufficient; visualisation belongs in the Statusboard)
- Multi-touch / last-touch attribution (contradicts the decided first-touch model)
- Raw per-event API export (violates Datensparsamkeit / DSGVO decision)
- Real-time updating dashboard

### Architecture Approach

The plugin decomposes into six components across three layers. All event data flows inward to `POT_Store` (the single DB gateway); all reporting flows outward from it. No component reads another component's data except through `POT_Store::aggregate_by_campaign($from, $to)`, which is the single shared query consumed by both the admin dashboard and the pull REST API — ensuring identical numbers in both places.

**Major components:**
1. **C1 Tracker JS** (`assets/js/tracker.js`) — consent-gated first-touch UTM capture, pageview beacon, delegated CTA click listener; never runs for admins or without consent
2. **C2 Ingest Route** (`class-pot-rest-ingest.php`) — `pot/v1/event`, real `permission_callback` verifying `wp_rest` nonce, bot-filter, sanitize, insert
3. **C3 Conversion Listener + C4b Attribution Bridge** (`class-pot-conversion.php`) — dual status hooks with idempotency flag, checkout meta bridge persisting UTM cookie to order meta, graceful degradation on missing `ab-webhook-endpoint`
4. **C4 Store** (`class-pot-store.php`) — owns schema, `dbDelta`, `insert_*` and `aggregate_by_campaign` methods; sole writer and sole read-aggregator for the events table
5. **C5 Admin Dashboard** (`class-pot-admin.php`) — submenu under `parkourone`, date-range UI, AJAX via `wp_ajax_pot_metrics` + `check_ajax_referer`, plain `wp-list-table` rows
6. **C6 Pull REST API** (`class-pot-rest-api.php`) — `GET pot/v1/metrics`, `permission_callback` verifying Bearer + `hash_equals`, same aggregation as C5, camelCase + `generatedAt` payload

### Critical Pitfalls

1. **HPOS orders via wrong API** — calling `get_post()` / `get_post_meta()` on order IDs silently corrupts data under HPOS (default since Woo 8.2). Use `wc_get_order()` + CRUD meta methods everywhere; declare compatibility on `before_woocommerce_init`.

2. **Full-page cache zeroes server-side visit counters** — PHP hooks do not execute on cache hits; visit counts then track cache-miss frequency, not real traffic. Prevention: visits must be a client-side beacon to a dynamic REST route (never a server-side PHP counter for the visit event).

3. **Silent zero-conversions from `ab-webhook-endpoint` dependency** — if that plugin is inactive, `woocommerce_order_status_probetraining` never fires and the dashboard shows 0 with no error. Prevention: `post_status_exists('wc-probetraining')` guard on `plugins_loaded`, admin notice when absent, `not_configured`/`stale` status flag propagated through the pull-API payload.

4. **Consent timing breaks UTM first-touch** — tracking before consent is a TTDSG §25 violation; tracking only after consent loses the original UTM params. Prevention: capture UTM into a JS memory variable on every page load; write the cookie and fire the beacon only once `po_has_consent('analytics')` fires on that same load.

5. **Double-counting during theme-tracker cutover** — `analytics-tracker.js` already fires `cta_click` on the same CTA selector. Prevention: theme-tracker deactivation is a hard acceptance criterion for the click-tracking phase, not a post-launch cleanup item.

6. **Free/coupon bookings bypass the primary conversion hook** — 100%-coupon Probetrainings reach `wc-probetraining` via `ab_redirect_order_to_event_status` at priority 999. Prevention: listen on both `woocommerce_order_status_probetraining` AND `woocommerce_order_status_changed` (where `new_status === 'probetraining'`), guarded by the `_pot_conversion_tracked` idempotency flag.

7. **Pull-API security: timing-unsafe compare + open endpoint** — `===` secret comparison (the `class-ab-contract-wizard.php:181` anti-pattern) leaks the secret byte-by-byte; `__return_true` (the `class-ab-rest-endpoint.php` anti-pattern) exposes all campaign data. Prevention: `hash_equals()` (length-checked), dedicated secret option, no permissive CORS, secret never logged.

## Implications for Roadmap

The dependency-ordered build sequence flows from infrastructure → events storage → server-side conversion (highest value, consent-independent) → client capture (consent-gated) → reporting surfaces → secured external API → theme retirement. Each phase produces a verifiable, testable outcome before the next phase depends on it.

### Phase 1: Scaffold + Self-Updater
**Rationale:** Everything else requires the plugin to activate cleanly and the house-style structure to be in place. Zero dependencies.
**Delivers:** Root plugin file, `pot_init()` on `plugins_loaded`, `github-updater.php` (re-prefixed), settings class with pull-API secret generation (`wp_generate_password(64, false)` into `autoload=false` option), `helper-functions.php` stubs (consent check, admin check, `hash_equals` wrapper). Plugin appears in `parkourone` admin menu.
**Addresses:** House-style constraint, self-update requirement, secret option setup
**Avoids:** Diverging from sibling plugin conventions; wrong autoload on the secret option

### Phase 2: Events Store (C4)
**Rationale:** `POT_Store` is the hub that all other components read from and write to. It must exist before any capture or reporting code is written.
**Delivers:** `wp_pot_events` table via `dbDelta`, activation hook, `insert_visit/click/booking` methods, `aggregate_by_campaign($from, $to)`. Composite indices on `(event_type, created_at)` and `(campaign, created_at)`. Schema version option. Daily retention cron.
**Addresses:** Custom DB table (signed-off deviation), date-range GROUP BY queries, data retention
**Avoids:** Missing composite index causing full table scans at production volume (Pitfall 10); unbounded growth / DSGVO retention violation; PII in schema (no IP column, URLs sanitized)

### Phase 3: Server-Side Conversion + Attribution Bridge (C3 + C4b)
**Rationale:** Conversions are the core value and are consent-independent. Building them before the client capture layer guarantees the irreplaceable metric works even if the JS layer is delayed. The attribution bridge belongs here because it shares the same order-lifecycle hooks.
**Delivers:** Dual status hooks, `_pot_conversion_tracked` idempotency flag, `post_status_exists` dependency guard with admin notice + `not_configured` status flag, checkout bridge persisting UTM cookie + `po_analytics_session_id` to order meta via HPOS CRUD API. `(unattributed)` campaign value when bridge meta is absent — never null.
**Addresses:** Booking conversion (P1), first-touch attribution bridge (P1), double-count guard, graceful degradation, free/coupon booking path, HPOS compliance
**Avoids:** Silent zero-conversions (Pitfall 6), free/coupon miss (Pitfall 5), HPOS corruption, attribution leakage (Pitfall 13)

### Phase 4: Client-Side Capture (C1 + C2)
**Rationale:** Depends on the store existing. Visits and clicks feed the top of the funnel but are consent-gated; building server-side truth first keeps the critical path intact.
**Delivers:** `tracker.js` (consent-gated, admin-excluded, UTM in memory on load → cookie on consent, pageview beacon, delegated click listener on `a[href*="/probetraining-buchen"]` with debounce + path normalisation), `pot/v1/event` ingest route with real nonce `permission_callback`, bot/prefetch filter (UA denylist + `Sec-Purpose` header + `Page Visibility`). Theme tracker deactivation is a blocking acceptance criterion for this phase.
**Addresses:** Visit tracking (P1), CTA click tracking (P1), consent gate (P1), admin exclusion (P1), bot filtering (P1), theme analytics handover (P1)
**Avoids:** Cache-zeroed visit counts (Pitfall 2 — client beacon not server hook), consent violation (Pitfall 7), click double-counting (Pitfall 1 + 12)
**Research flag:** Test visit counting on a production-cache-equivalent environment; verify no beacon fires before consent is granted in DevTools

### Phase 5: Admin Dashboard (C5)
**Rationale:** Once data is flowing, the dashboard lets you verify numbers are correct before exposing them externally.
**Delivers:** `add_submenu_page('parkourone', ...)`, date-range presets (today / 7d / 30d / custom, default 30d), `$wpdb` GROUP BY campaign via `POT_Store::aggregate_by_campaign`, plain `wp-list-table` rows (visits / clicks / bookings / conversion rate / drop-off), `(direct)` and `(unattributed)` bucket rows, AJAX refresh via `wp_ajax_pot_metrics` + `check_ajax_referer` + `manage_options`, health indicator showing `not_configured`/`stale`.
**Addresses:** Per-campaign breakdown table (P1), conversion rate + drop-off (P1), date-range presets (P1), API health indicator
**Avoids:** Dashboard showing 0 with no "tracking offline" signal; CTR > 100% without warning; unattributed share hidden

### Phase 6: Secured Pull REST API (C6)
**Rationale:** Built after the dashboard so numbers can be verified against real data before external exposure. Reuses the same `POT_Store` method — no new data logic.
**Delivers:** `GET pot/v1/metrics`, `permission_callback` with `Authorization: Bearer` + `hash_equals()` (length-checked), camelCase + `generatedAt` ISO-8601 payload, `status: ok|stale|not_configured` field, no permissive CORS, 401 on auth failure, secret never logged.
**Addresses:** Secured pull REST endpoint (P1), Statusboard contract (camelCase, `generatedAt`, `.passthrough()`-compatible)
**Avoids:** Open endpoint / `__return_true` anti-pattern, timing-unsafe `===` compare, secret in `debug.log` (Pitfall 9)

### Phase 7: Theme Analytics Retirement
**Rationale:** Last, because the new plugin must be running and validated end-to-end first. Shadow period allows count comparison.
**Delivers:** Theme tracker deactivation (stop `Analytics::enqueue_tracker()` and `track_purchase` server insert in one coordinated change), confirmation `wp_po_analytics_events` stops receiving new rows. Old table left as read-only historical record.
**Addresses:** Theme-analytics handover (P1), no double-counting, no tracking gap
**Avoids:** Gap where conversions go uncounted (C3 is live before cutover)

### Phase Ordering Rationale

- Store (Phase 2) before all consumers: `POT_Store` is the single hub; nothing can write or read without it.
- Conversion before client capture (Phase 3 before 4): conversions are the core value and consent-independent; they should work even if the JS layer is delayed.
- Dashboard before pull API (Phase 5 before 6): visually verify numbers before exposing them externally; catches query/schema bugs without a live Statusboard dependency.
- Retirement last (Phase 7): the new system must be proven before the old one is removed; shadow period prevents a data gap.

### Research Flags

Phases with standard, well-documented patterns (skip additional research):
- **Phase 1:** Plugin scaffold — mirrored from sibling plugins verbatim
- **Phase 2:** `dbDelta` — prescriptive schema and index design in STACK.md
- **Phase 3:** WooCommerce order hooks, HPOS CRUD — all verified against live codebase
- **Phase 5:** Admin dashboard, AJAX patterns — verbatim house style
- **Phase 6:** Bearer + `hash_equals` — pattern verified against Statusboard source
- **Phase 7:** Theme tracker retirement — coordination task, not an implementation research question

Phases needing explicit acceptance-test verification (not research, but careful testing):
- **Phase 4 (client capture):** Test on a production-cache-equivalent environment. Verify no beacon fires before consent in DevTools network/cookies tab. Verify theme tracker stops writing to `wp_po_analytics_events`.

## Confidence Assessment

| Area | Confidence | Notes |
|------|------------|-------|
| Stack | HIGH | PHP/WP/WC versions from official docs (May 2026); HPOS from Woo 10.8 release notes; Bearer-secret pattern verified against Statusboard source in CONTEXT-FINDINGS |
| Features | HIGH | Scope constraints from PROJECT.md (decided, non-negotiable); funnel definitions from analytics vendor docs; consent rules from German DSGVO sources |
| Architecture | HIGH | All 17 architecture decisions in CONTEXT-FINDINGS.md are code-verified against live files; component decomposition directly derived from those findings |
| Pitfalls | HIGH/MEDIUM | Pitfalls 1, 5, 6 verified against live code; Pitfall 2 (full-page cache) verified against WP Rocket docs; DSGVO consent rules from German legal-info sources (not formal legal advice) |

**Overall confidence:** HIGH

### Gaps to Address

- **Statusboard payload contract (live endpoint unverified):** The pull-API payload shape is designed to the `SourceStatus` enum and `.passthrough()` contract from CONTEXT-FINDINGS, but the Statusboard receiver route for the new endpoint does not exist yet. Integration testing requires the Statusboard session to be completed. No blocking risk — a missed cron pull simply retries next cycle.
- **DSGVO legal basis for attribution cookie lifetime:** Research confirms marketing attribution cookies require consent under German TTDSG §25. The 90-day first-touch cookie lifetime is a pragmatic choice; formal DPO review is recommended before go-live.
- **`ab-webhook-endpoint` load order on the live host:** The `plugins_loaded` priority-11 guard is the standard mitigation, but the exact activation order on `berlin.parkourone.com` has not been tested. Verify during Phase 3.

## Sources

### Primary (HIGH confidence)
- `CONTEXT-FINDINGS.md` (2026-05-31) — 17 code-verified architecture decisions, hooks, selectors, Statusboard contract, house anti-patterns; primary ground truth for all phases
- `PROJECT.md` — decided scope, DSGVO constraints, pull-API decision, dependency acknowledgement
- developer.woocommerce.com — HPOS default since Woo 8.2, `FeaturesUtil::declare_compatibility`, `wc_get_order` CRUD
- developer.wordpress.org/rest-api — `permission_callback` contract, `register_rest_route`
- developer.wordpress.org/reference/functions/dbdelta/ + docs.wpvip.com — `dbDelta` spacing rules, index-to-query guidance
- make.wordpress.org/core/7-0/ — WP 7.0 "Armstrong" (2026-05-20) baseline

### Secondary (MEDIUM confidence)
- docs.wp-rocket.me — full-page cache bypasses PHP hooks; `DONOTCACHEPAGE`; Varnish + dynamic-cookie incompatibility
- woocommerce.com/document/server-requirements/ — PHP 8.1 min-safe / 8.3 recommended
- github.com/WordPress/WordPress-Coding-Standards — WPCS 3.x Composer-only, PHPCS 3.13 compat
- Chart.js v4 docs — no-moment, MIT, canvas; safe to bundle standalone
- Matomo FAQ, Google Analytics Help — unique-vs-total dedup nuance; previous-period comparison semantics
- dr-dsgvo.de, cookieyes.com, earnst.io — TTDSG §25 device-storage consent; IP as personal data under DSGVO

### Tertiary (LOW-MEDIUM confidence)
- topmarketingfunnels.com, clevertap.com, customerlabs.com — CTA conversion rate / funnel drop-off metric definitions
- utmgrabber.com — UTM first-touch capture patterns in WordPress

---
*Research completed: 2026-05-31*
*Ready for roadmap: yes*
