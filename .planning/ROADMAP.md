# Roadmap: ParkourONE Campaign Tracking

## Overview

A single WordPress/WooCommerce plugin that measures a fixed 3-stage marketing funnel (landing-page visit → "Probetraining buchen" click → completed Probetraining booking) per UTM campaign, surfaces it in an admin dashboard, and exposes aggregates over a secured pull REST API for the ONE Statusboard. The build is dependency-ordered and core-value-first: a clean house-style scaffold with the custom events store comes first, then the irreplaceable consent-independent conversion + first-touch attribution layer, then the consent-gated client capture that retires the old theme tracker without a gap, then the dashboard to verify numbers, and finally the secured external API once the aggregates are proven.

## Phases

**Phase Numbering:**
- Integer phases (1, 2, 3): Planned milestone work
- Decimal phases (2.1, 2.2): Urgent insertions (marked with INSERTED)

Decimal phases appear between their surrounding integers in numeric order.

- [x] **Phase 1: Plugin Foundation & Events Store** - House-style scaffold, HPOS declaration, self-updater, `dbDelta` events table with composite indices, retention cron, uninstall cleanup (completed 2026-05-31)
- [ ] **Phase 2: Conversion & Attribution Bridge** - Consent-independent server-side booking conversion (dual hooks, idempotent, graceful degradation) + first-touch UTM cookie→order-meta bridge — the core value
- [ ] **Phase 3: Consent-Gated Client Capture & Theme Retirement** - Visit beacon + CTA-click tracking behind consent/admin/bot gates, then hard cutover off the theme analytics tracker with a parity check
- [ ] **Phase 4: Admin Dashboard** - Per-campaign funnel table (visits/clicks/bookings, conversion rate, drop-off) with date-range presets and AJAX refresh under the `parkourone` menu
- [ ] **Phase 5: Secured Pull REST API** - Bearer + `hash_equals` authenticated `GET /metrics` returning the same aggregates as camelCase + `generatedAt`, with a managed secret option

## Phase Details

### Phase 1: Plugin Foundation & Events Store
**Goal**: A clean, house-style plugin that activates without fatals and owns a performant, retention-bounded events store that every later component reads and writes through.
**Mode:** mvp
**Depends on**: Nothing (first phase)
**Requirements**: INFRA-01, INFRA-02, INFRA-03, INFRA-04, INFRA-05
**Success Criteria** (what must be TRUE):
  1. Activating the plugin creates the `wp_pot_events` table (verifiable via `SHOW INDEX`) with composite indices on `(event_type, created_at)` and `(campaign, created_at)`, and the plugin appears as a submenu under the existing `parkourone` menu.
  2. The plugin activates and runs without a fatal error even when WooCommerce or `ab-webhook-endpoint` is inactive.
  3. `POT_Store` can insert a visit/click/booking row and `aggregate_by_campaign($from, $to)` returns per-campaign counts (verified by a manual insert + aggregate).
  4. The daily retention cron is scheduled and prunes raw visit/click rows older than the retention window (~180 days) while never touching booking rows.
  5. Uninstall removes the events table, all plugin options (including any secret), and clears scheduled cron events; the GitHub self-updater is wired against `monkeyspk/<slug>` with the shared `parkourone_github_token`.
**Plans**: 2 plans
  - [x] 01-01-PLAN.md — Walking skeleton: bootstrap + HPOS, dbDelta pot_events table (3 indices), POT_Store insert/aggregate gateway, placeholder admin page under parkourone (INFRA-01, INFRA-02, INFRA-04)
  - [x] 01-02-PLAN.md — Hardening: daily retention cron (prune visit/click only), GitHub self-updater (POT_GitHub_Updater), uninstall cleanup (INFRA-03, INFRA-05)

### Phase 2: Conversion & Attribution Bridge
**Goal**: Every completed Probetraining booking is counted exactly once, server-side and consent-independent, and is attributed back to its originating campaign — the metric that must be correct if all else fails.
**Mode:** mvp
**Depends on**: Phase 1
**Requirements**: CONVERT-01, CONVERT-02, CONVERT-03, CONVERT-04, ATTRIB-01, ATTRIB-02, ATTRIB-03, ATTRIB-04
**Success Criteria** (what must be TRUE):
  1. A WooCommerce order reaching status `probetraining` writes exactly one `booking` row in the events store, with no double-count on repeated status transitions (idempotency flag `_pot_conversion_tracked`).
  2. A 100%-coupon / free Probetraining booking that reaches the status via the fallback path is also counted exactly once.
  3. When `ab-webhook-endpoint` is inactive (status unavailable), the plugin degrades gracefully (no fatal), shows an admin notice, and records a `not_configured` state instead of silently logging zero.
  4. A booking that originated from a UTM campaign is attributed to that campaign via first-touch UTM persisted from cookie to order meta at checkout, with the cookie written only after `po_has_consent('analytics')` (UTM held in JS memory until then).
  5. A booking with no first-touch UTM is recorded under a named `(unattributed)` / `(direct)` bucket — never dropped and never silently merged into a real campaign; visits, clicks, and bookings group by the first-touch campaign value.
**Plans**: 2 plans
  - [ ] 02-01-PLAN.md — Conversion listener: dual-hook (probetraining + status_changed fallback) POT_Conversion::record_conversion, idempotency flag before insert, graceful degradation + not_configured notice, booking write through POT_Store (CONVERT-01..04, ATTRIB-03/04)
  - [ ] 02-02-PLAN.md — Attribution bridge: first-touch UTM capture pot-attribution.js (consent-gated sessionStorage→cookie), POT_Attribution checkout cookie→order-meta persistence + admin-skip enqueue (ATTRIB-01, ATTRIB-02, ATTRIB-03)

### Phase 3: Consent-Gated Client Capture & Theme Retirement
**Goal**: Visits and CTA clicks are captured client-side behind consent, admin, and bot gates, and the legacy theme analytics tracker is retired in the same phase so exactly one tracker runs with no gap and no double-count.
**Mode:** mvp
**Depends on**: Phase 1, Phase 2
**Requirements**: CAPTURE-01, CAPTURE-02, CAPTURE-03, CAPTURE-04, CAPTURE-05, MIGRATE-01, MIGRATE-02
**Success Criteria** (what must be TRUE):
  1. A landing-page view fires exactly one client-side beacon to the dynamic `pot/v1/event` ingest route (nonce-gated), counting visits correctly even behind full-page caching — one event per pageview.
  2. A click on any `a[href*="/probetraining-buchen"]` CTA is captured once via a delegated capture-phase listener (path-normalized, debounced against rapid double-clicks).
  3. No beacon fires and no tracking cookie is written before `po_has_consent('analytics')` is granted, and logged-in admins (`manage_options`) plus known bots/prefetch are excluded from visit/click counts (verifiable in DevTools network/cookies).
  4. After cutover, the theme's `analytics-tracker.js` no longer emits `cta_click`/`pageview` and `wp_po_analytics_events` stops receiving new tracking rows — the new plugin is the sole tracker.
  5. A parity check confirms the new plugin's visit/click counts match the theme tracker's for the same shadow window before cutover, so no tracking gap is introduced.
**Plans**: 2 plans
  - [x] 03-01-PLAN.md — Capture vertical slice: POST pot/v1/event ingest route (nonce gate, sanitizing handler, write-through), consent/admin/bot-gated pot-tracker.js (visit beacon + capture-phase CTA click listener), admin-skipped enqueue + wiring (CAPTURE-01..05)
  - [x] 03-02-PLAN.md — Theme retirement: option-gated POT_Theme_Retirement dequeues po-analytics-tracker (priority 99) + removes wp_footer track_basic_pageview on the PO_Analytics singleton; parity check deferred to manual staging checklist (MIGRATE-01, MIGRATE-02)
**UI hint**: yes

### Phase 4: Admin Dashboard
**Goal**: An admin can read the per-campaign funnel and verify the numbers are correct for any chosen date range before they are ever exposed externally.
**Mode:** mvp
**Depends on**: Phase 1, Phase 2, Phase 3
**Requirements**: DASH-01, DASH-02, DASH-03, DASH-04
**Success Criteria** (what must be TRUE):
  1. An admin (`manage_options`) opens a dashboard page under the `parkourone` menu showing a per-campaign table with visits, clicks, and bookings, including the `(direct)` and `(unattributed)` bucket rows.
  2. The table shows conversion rate and step-to-step drop-off (visit→click, click→booking) per campaign, and flags impossible ratios (e.g. clicks > visits) rather than showing them silently.
  3. The admin selects a date range via presets (today / 7d / 30d / custom, default 30d) and the table re-queries for that range.
  4. The dashboard loads and refreshes data via AJAX (`wp_ajax_pot_metrics` + nonce + capability check) and surfaces a `not_configured`/`stale` health indicator when conversion tracking is offline.
**Plans**: 2 plans
  - [ ] 04-01-PLAN.md — Server-side dashboard: convert POT_Admin placeholder → control bar + health banner + wp-list-table (rate helpers w/ divide-by-zero guards, UTC day-boundary conversion, impossible-funnel warnings) + wp_ajax_pot_metrics handler (nonce + manage_options, rows/totals/health/range) reusing POT_Store::aggregate_by_campaign (DASH-01..04)
  - [ ] 04-02-PLAN.md — Progressive-enhancement layer: pot-dashboard.js re-queries wp_ajax_pot_metrics on preset/custom-date/Aktualisieren and swaps #pot-metrics-body (loading/empty/error/health states) + minimal pot-dashboard.css (control bar + warning marker) (DASH-03, DASH-04)
**UI hint**: yes

### Phase 5: Secured Pull REST API
**Goal**: The ONE Statusboard can pull contract-shaped aggregated campaign metrics over an authenticated endpoint that reuses the exact same store aggregation as the dashboard.
**Mode:** mvp
**Depends on**: Phase 1, Phase 2, Phase 3, Phase 4
**Requirements**: API-01, API-02, API-03, API-04
**Success Criteria** (what must be TRUE):
  1. An authenticated `GET pot/v1/metrics` with a valid `Authorization: Bearer <secret>` returns per-campaign aggregates for a requested date range, identical to the dashboard numbers (same `POT_Store` aggregation).
  2. A request with a missing or wrong bearer secret returns 401 (never 500), with the secret compared via `hash_equals` after a length check and never logged; no permissive CORS is sent.
  3. The payload uses camelCase fields and includes a `generatedAt` ISO-8601 timestamp plus a `status` field (`ok`/`stale`/`not_configured`), Statusboard `.passthrough()`-compatible.
  4. The bearer secret lives in a dedicated `autoload=false` option, generated via `wp_generate_password` on activation, and is viewable / regeneratable from the settings page.
**Plans**: TBD

## Progress

**Execution Order:**
Phases execute in numeric order: 1 → 2 → 3 → 4 → 5

| Phase | Plans Complete | Status | Completed |
|-------|----------------|--------|-----------|
| 1. Plugin Foundation & Events Store | 2/2 | Complete   | 2026-05-31 |
| 2. Conversion & Attribution Bridge | 0/2 | Planned | - |
| 3. Consent-Gated Client Capture & Theme Retirement | 0/2 | Planned | - |
| 4. Admin Dashboard | 0/2 | Planned | - |
| 5. Secured Pull REST API | 0/TBD | Not started | - |
