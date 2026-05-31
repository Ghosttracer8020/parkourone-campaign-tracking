# Feature Research

**Domain:** Campaign funnel-tracking analytics (self-hosted WordPress/WooCommerce plugin, single-store, GDPR/DSGVO context)
**Researched:** 2026-05-31
**Confidence:** HIGH (funnel/attribution definitions verified across multiple analytics vendors; WP privacy patterns verified against established plugins; scope constraints sourced from PROJECT.md + CONTEXT-FINDINGS.md)

> Scope reminder (decided, non-negotiable): UTM **first-touch** attribution, **aggregated-only** export, **simple** admin UI, **GDPR-clean** (consent-gated client tracking, admins excluded, no PII outbound). The funnel is fixed at exactly 3 stages: **Landing-page visit → "Probetraining buchen" CTA click → completed Probetraining booking**. Conversions come from a server-side WooCommerce status (`probetraining`), consent-independent.

## Feature Landscape

### Table Stakes (Users Expect These)

Missing any of these makes the product useless for "how many bookings came from which campaign".

| Feature | Why Expected | Complexity | Notes |
|---------|--------------|------------|-------|
| 3-stage funnel counts per campaign (visits, CTA clicks, bookings) | This *is* the product; the funnel is the deliverable | MEDIUM | Visits + clicks = client JS → custom DB table; bookings = server WooCommerce hook. Three different capture paths. |
| Conversion rate (booking ÷ visits) per campaign | Single most-asked number; "core value" per PROJECT.md | LOW | Derived metric. Decide denominator explicitly (see Pitfalls). Visits→Booking is the headline rate. |
| Step-to-step conversion + drop-off between stages | A funnel without inter-stage rates is just 3 counters | LOW | visit→click rate, click→booking rate. Drop-off = 1 − step rate. Pure arithmetic on the 3 counts. |
| Per-campaign breakdown table | The whole point is *attribution by campaign* | LOW | House style: plain `wp-list-table` + sprintf rows (no WP_List_Table). One row per campaign, sortable by bookings/rate. |
| Date-range selection with presets (today / 7d / 30d / custom) | Universal in every analytics tool; reporting is time-bound | MEDIUM | Drives `$wpdb` queries grouped by campaign. Custom table indexed on `(event_type, created_at, campaign)`. Default to last 30d. |
| Consent-gated client tracking | German context; legal requirement, not optional | MEDIUM | Reuse `po_has_consent('analytics')`. No consent → no visit/click event fired. Bookings still count server-side (legitimate business record, no client cookie). |
| Logged-in admin exclusion | Prevents self-inflicted data pollution; matches existing theme posture | LOW | Skip enqueue / skip event if `is_user_logged_in()` && `current_user_can('manage_options')`. |
| Bot / non-human filtering | Without it, visit counts are garbage and conversion rates collapse | MEDIUM | Bots rarely execute consent-gated JS, so client funnel is already somewhat self-filtering. Add a UA denylist + ignore known crawlers. Server bookings need no bot filter. |
| Secured pull REST endpoint (aggregated metrics) | Explicit deliverable for the ONE Statusboard | MEDIUM | Real `permission_callback` verifying Bearer secret with `hash_equals`. NEVER `__return_true`. camelCase fields + `generatedAt` ISO-8601 + `.passthrough()`-friendly. |
| Double-count protection on bookings | Free/coupon Probetrainings hit multiple hooks; would inflate conversions | MEDIUM | Order meta flag `_<prefix>_conversion_tracked`. Critical for "the conversion number must be correct" core value. |
| Graceful degradation if `ab-webhook-endpoint` inactive | Conversions silently drop to zero otherwise | LOW | Defer hook registration to `plugins_loaded`; fallback on `woocommerce_order_status_changed`. Admin notice if status unavailable. |
| Clean handover from existing theme analytics | Double-counting CTA clicks if both run | LOW | Disable theme `analytics-tracker.js` CTA emission, or dedupe. One tracker only. |

### Differentiators (Competitive Advantage)

Genuinely valuable for *this* use case, aligned with the "simple + correct attribution" core value. Not required to be useful.

| Feature | Value Proposition | Complexity | Notes |
|---------|-------------------|------------|-------|
| Compare-to-previous-period | "30 bookings, +20% vs prior 30d" is far more actionable than a raw number | MEDIUM | Run the same grouped query for the shifted window, show delta + %. Cheap once date-range querying exists. Strong, low-risk win. |
| First-touch attribution done *correctly* (cookie → order meta) | The hard part most simple trackers get wrong; directly enables campaign→booking join | MEDIUM-HIGH | First-touch UTM in cookie on first visit; persist to order meta at checkout via `woocommerce_checkout_create_order`. This bridge IS the core value — treat as table-stakes-adjacent, not optional polish. |
| Unique vs total visits toggle | Total inflates funnels; unique gives honest conversion denominators | MEDIUM | Needs a session/visitor identifier (already have `po_analytics_session_id`). Adds dedup logic + a counted column. Recommend: store both, default the dashboard rate on *unique* visits. |
| "Direct / no-campaign" bucket | Bookings without UTM still need a home; avoids silently dropping conversions | LOW | Treat null campaign as a named row ("(direct / kein UTM)"). Prevents attribution leakage. |
| API delivery health indicator in admin | Operator can see if Statusboard pull is working without leaving WP | LOW | Persist last-served timestamp / last error to autoload=false option; show on dashboard. |
| CSV export of the per-campaign table | Manual reporting / spot-checks without touching the API | LOW | Aggregated rows only (no raw events → stays GDPR-clean). |

### Anti-Features (Commonly Requested, Often Problematic)

Deliberately NOT built — to keep it simple and GDPR-clean. Documenting to prevent scope creep.

| Feature | Why Requested | Why Problematic | Alternative |
|---------|---------------|-----------------|-------------|
| Multi-touch / last-touch / linear attribution models | "Industry best practice", more complete picture | Massive complexity (path storage, attribution windows, weighting); contradicts the decided first-touch model; needs per-event journeys = PII risk | Stick to first-touch only. One model, explained in one sentence. |
| Configurable attribution windows / lookback config | Power-user flexibility | Config surface + edge cases (cookie expiry vs window vs sales cycle) for a single-store, short funnel | Hardcode a sane cookie lifetime (e.g. 30–90 days), documented, not exposed as UI. |
| Raw per-event export over the API | Statusboard "might want detail later" | Directly violates Datensparsamkeit/DSGVO decision; turns aggregate API into a PII pipe | Aggregated metrics only. PROJECT.md already declares this out of scope. |
| Individual user journeys / session replay / user-level dashboards | "See exactly what each visitor did" | PII-heavy, consent-fragile, huge storage, zero relevance to campaign-level booking counts | Aggregate counts per campaign per day. Never identify individuals. |
| Charts / graphs / visualizations in the WP admin | Dashboards "should look like dashboards" | No in-house chart lib exists (verified); CDN charts = privacy/maintenance cost; adds a build/asset decision for a number that fits in a table | Numbers + delta arrows in a clean table. Visualization belongs in the Statusboard (explicitly its job). |
| Tracking other conversions (workshops, courses, add-to-cart) | "While we're at it…" | Dilutes focus; each conversion type has different hooks/meta; PROJECT.md scopes to Probetraining only | Probetraining booking only. The `_event_is_workshop` meta is available to *exclude*, not to expand. |
| Real-time / live-updating dashboard | "I want to see it update now" | Polling/websocket complexity, server load, no business need for a daily-pull Statusboard | Date-range query on page load + manual AJAX refresh button. Statusboard pulls on cron anyway. |
| Generic event tracking ("track any click/page") | "Make it reusable" | Becomes a Google-Analytics clone; explodes consent scope, storage, UI | Fixed 3-stage funnel on one fixed CTA selector (`a[href*="/probetraining-buchen"]`). |
| Cross-device / fingerprint identity stitching | More accurate attribution | Fingerprinting is a GDPR red flag; disproportionate for this scope | Cookie-based first-touch within one browser. Accept the known limitation. |
| Goal/funnel builder UI (define arbitrary funnels) | "Future flexibility" | Config complexity for a funnel that is fixed by the business | Hardcode the 3 stages. |

## Feature Dependencies

```
[Custom DB events table (visit|click|booking)]
    └──requires──> nothing (foundation; deliberate house-style deviation, signed off)

[Visit tracking] ──requires──> [Consent gate] + [Admin exclusion] + [Custom DB table]
[CTA click tracking] ──requires──> [Consent gate] + [Admin exclusion] + [Custom DB table]
    └──requires──> [Theme-analytics handover/dedupe]   (else double-count)

[Booking conversion] ──requires──> [WooCommerce probetraining hook + fallback]
    └──requires──> [Double-count guard (order meta flag)]
    └──requires──> [Graceful degradation if ab-webhook-endpoint absent]

[First-touch attribution bridge]
    └──requires──> [Visit tracking]  (cookie set on first visit)
    └──requires──> [Booking conversion] (cookie → order meta at checkout)
    └──enables───> per-campaign grouping of ALL THREE stages

[Per-campaign breakdown table] ──requires──> [Attribution bridge] + [DB table]
[Conversion rate / drop-off]   ──requires──> [Per-campaign counts]
[Date-range presets]           ──requires──> [DB table with created_at index]
[Compare-to-previous-period]   ──requires──> [Date-range presets]   (runs query twice)
[Unique vs total visits]       ──requires──> [Visit tracking + session id]
[Direct/no-campaign bucket]    ──requires──> [Attribution bridge]   (handles null UTM)

[Pull REST API (aggregated)]   ──requires──> [Per-campaign counts] + [Bearer secret + hash_equals]
[API health indicator]         ──enhances──> [Pull REST API]
[CSV export]                   ──requires──> [Per-campaign counts]

[Bot filtering] ──enhances──> [Visit tracking]   (data quality)

CONFLICTS:
[New plugin CTA tracker] ──conflicts──> [Theme analytics-tracker.js CTA emission]
[Charts in admin]        ──conflicts──> [Simple UI constraint] + [no chart lib]
[Multi-touch attribution]──conflicts──> [Decided first-touch model]
[Raw event API export]   ──conflicts──> [Aggregated-only / DSGVO decision]
```

### Dependency Notes

- **Attribution bridge is the spine.** Visits, clicks, AND bookings are all worthless at campaign level until the first-touch cookie is persisted to order meta. Build the cookie-set (with visit) and cookie-persist (at checkout) together; they are two ends of one feature.
- **Bookings are independent of consent.** A completed WooCommerce order is a legitimate business record — count it server-side regardless of analytics consent. Only the *visit/click* legs are consent-gated. This split is what keeps the headline conversion number correct even when consent is denied (denominator shrinks, numerator may not — see Pitfalls).
- **Double-count guard is non-negotiable for core value.** "Conversion attribution must st+stay correct" (PROJECT.md). The free/100%-coupon path reaches `probetraining` via a fallback redirect at priority 999, so a single hook misses or doubles them.
- **Compare-to-previous-period is nearly free** once date-range querying exists — same query, shifted window. High value-to-cost ratio; promote it into v1 if time allows.
- **Unique-vs-total touches consent scope.** A session/visitor identifier is needed for "unique". The theme already uses `po_analytics_session_id`; reuse it rather than minting a new identifier (avoids expanding consent footprint).

## MVP Definition

### Launch With (v1)

Minimum to validate "we can correctly attribute Probetraining bookings to campaigns".

- [ ] Custom DB events table (visit | click | booking) — foundation for date-range queries
- [ ] Visit tracking (consent-gated, admin-excluded, bot-filtered)
- [ ] CTA click tracking on `a[href*="/probetraining-buchen"]` (+ theme tracker handover)
- [ ] Booking conversion via `woocommerce_order_status_probetraining` + status-changed fallback + double-count guard + graceful degradation
- [ ] First-touch UTM bridge (cookie on first visit → order meta at checkout)
- [ ] Per-campaign breakdown table with visits / clicks / bookings / conversion rate / drop-off
- [ ] Date-range presets (today / 7d / 30d / custom), default 30d
- [ ] Direct / no-campaign bucket (handle null UTM)
- [ ] Secured pull REST endpoint — aggregated per-campaign metrics, Bearer + `hash_equals`, camelCase + `generatedAt`
- [ ] Data retention policy (auto-prune raw visit/click rows after N days; keep aggregates if needed)

### Add After Validation (v1.x)

- [ ] Compare-to-previous-period (delta + %) — add as soon as numbers look right; cheap, high value
- [ ] Unique vs total visits toggle — add once stakeholders question denominator honesty
- [ ] API delivery health indicator in admin — add once Statusboard is live and pulling
- [ ] CSV export of the per-campaign table — add on first manual-reporting request

### Future Consideration (v2+)

- [ ] Per-landing-page breakdown (sub-dimension under campaign) — defer until multiple landing pages per campaign exist and matter
- [ ] Configurable retention period UI — defer; hardcode a sane default first
- [ ] Multiple conversion types (workshops/courses) — defer; only if focus on Probetraining proves the model first (currently explicit anti-feature)

## Feature Prioritization Matrix

| Feature | User Value | Implementation Cost | Priority |
|---------|------------|---------------------|----------|
| Booking conversion + double-count guard | HIGH | MEDIUM | P1 |
| First-touch attribution bridge | HIGH | MEDIUM-HIGH | P1 |
| Per-campaign breakdown table | HIGH | LOW | P1 |
| Conversion rate + drop-off | HIGH | LOW | P1 |
| Visit + CTA-click tracking (consent-gated) | HIGH | MEDIUM | P1 |
| Date-range presets | HIGH | MEDIUM | P1 |
| Secured pull REST API (aggregated) | HIGH | MEDIUM | P1 |
| Data retention / pruning | MEDIUM | LOW | P1 |
| Bot filtering | MEDIUM | MEDIUM | P1 |
| Theme-analytics handover (dedupe) | MEDIUM | LOW | P1 |
| Compare-to-previous-period | HIGH | MEDIUM | P2 |
| Direct / no-campaign bucket | MEDIUM | LOW | P1 |
| Unique vs total visits | MEDIUM | MEDIUM | P2 |
| API health indicator | MEDIUM | LOW | P2 |
| CSV export | LOW-MEDIUM | LOW | P2 |
| Charts in admin | LOW | MEDIUM | P3 (anti-feature) |
| Multi-touch attribution | LOW | HIGH | P3 (anti-feature) |
| Raw event API export | NEGATIVE | LOW | Never (DSGVO) |

**Priority key:** P1 = must have for launch · P2 = should have, add when possible · P3 = nice to have / explicitly deferred

## Competitor Feature Analysis

How privacy-friendly analytics tools approach the relevant features, and our pragmatic take.

| Feature | Plausible/Koko/WP Statistics (privacy analytics) | Plausible/Matomo (funnel goals) | Our Approach |
|---------|--------------------------------------------------|--------------------------------|--------------|
| Funnel definition | Goal/funnel builder, arbitrary steps | Configurable multi-step funnels | Hardcoded 3 stages (fixed business funnel) — simpler, no config UI |
| Attribution | Last-touch / referrer-based, UTM dimensions | Multi-touch options | First-touch only, cookie→order-meta bridge |
| Consent | Cookieless by default (no banner needed) | Cookie + consent integrations | Consent-gated (`po_has_consent`); bookings server-side, consent-independent |
| Bot filtering | UA denylist + known-crawler list | Built-in bot filtering | UA denylist; consent-gated JS already filters most bots |
| Date compare | Previous period / previous year built in | Period comparison | Previous-period delta (P2), no year-over-year (single-season relevance) |
| Unique vs total | Both, per-period dedup nuance | Both, with evolution-report caveats | Store both; default rate on unique visits |
| Export / API | REST stats API, CSV | Stats API | Aggregated-only pull API (Bearer + hash_equals) + CSV (P2) |
| Data retention | Configurable retention | Configurable retention | Hardcoded prune of raw rows after N days (config later) |

## Sources

- [How To Measure CTA Conversion Rates — TopMarketingFunnels](https://www.topmarketingfunnels.com/blog/how-to-measure-cta-conversion-rates/) — CTA click-through and conversion-rate formula (MEDIUM)
- [26 Conversion Rate Metrics & KPIs — CleverTap](https://clevertap.com/blog/conversion-rate-metrics/) — metric definitions (MEDIUM)
- [Conversion Funnel Analysis — CustomerLabs](https://www.customerlabs.com/blog/conversion-funnel-analysis-optimization/) — drop-off / step-rate definitions (MEDIUM)
- [First Touch vs Last Touch Attribution — Simulmedia](https://www.simulmedia.com/blog/first-touch-vs-last-touch-attribution-models) — attribution model semantics & use cases (MEDIUM)
- [First and Last Touch Attribution in WordPress with UTM Grabber](https://blog.utmgrabber.com/first-and-last-touch-attribution-in-wordpress-with-utm-grabber/) — UTM first-touch capture pattern in WP (LOW-MEDIUM)
- [6 WordPress Plugins For GDPR-Compliant User Tracking — FooPlugins](https://fooplugins.com/wordpress-tracking-plugin/) — consent/cookieless/bot/retention patterns (MEDIUM)
- [WP Statistics — WordPress.org](https://wordpress.org/plugins/wp-statistics/) — cookieless, bot filtering, retention as table-stakes (MEDIUM)
- [Select and compare date ranges — Google Analytics Help](https://support.google.com/analytics/answer/1010052?hl=en) — previous-period comparison semantics (HIGH)
- [Why Unique Visitors differ between Table and Evolution reports — Matomo](https://matomo.org/faq/reports/why-do-unique-visitors-differ-between-custom-table-and-evolution-reports/) — unique-vs-total dedup nuance (HIGH)
- [Design guidelines for ads reporting APIs — Funnel.io](https://help.funnel.io/en/articles/868571-design-guidelines-for-ads-reporting-apis) — per-day, group-by-campaign aggregated API contract shape (MEDIUM)
- Project context: `.planning/PROJECT.md`, `CONTEXT-FINDINGS.md` (decided scope — HIGH)

---
*Feature research for: campaign funnel-tracking analytics (WordPress/WooCommerce, GDPR)*
*Researched: 2026-05-31*
