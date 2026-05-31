# Pitfalls Research

**Domain:** WordPress/WooCommerce campaign funnel-tracking plugin (visits → CTA clicks → completed Probetraining bookings) with secured pull REST API to an external dashboard, German/DSGVO context, replacing an existing theme analytics system.
**Researched:** 2026-05-31
**Confidence:** HIGH for code-grounded findings (verified against `CONTEXT-FINDINGS.md` codebase analysis); MEDIUM for general WP-caching/DSGVO behavior (verified against WP Rocket docs + German privacy sources, see Sources).

> This file DEEPENS the 10 risks already catalogued in `CONTEXT-FINDINGS.md` (lines 51–60). It does not repeat them — each pitfall below adds warning signs, prevention, and a phase. New pitfalls not in that list are flagged **[NEW]**.

---

## Critical Pitfalls

### Pitfall 1: Double-counting with the still-running theme tracker

**What goes wrong:**
The theme's `analytics-tracker.js` already fires a delegated capture-phase `cta_click` for any element whose text matches `/probetrain|buchen|jetzt|.../` and an `add_to_cart` event on the jQuery `added_to_cart` trigger (`inc/analytics/class-analytics.php`, `analytics-tracker.js:159–242`). A new plugin listener on `a[href*="/probetraining-buchen"]` binds the SAME buttons. Until the theme tracker is fully disabled, every CTA click is counted twice (once per system), and both write to overlapping stores. Worse: a partial cutover (plugin live, theme tracker "mostly" off) produces numbers that look plausible but are inflated by an unknown factor.

**Why it happens:**
"Replace the theme analytics" is treated as an end-state, not a hard cutover gate. The theme tracker is enqueued by the theme (`Analytics::enqueue_tracker()`), not the plugin, so the plugin author can't disable it from inside the plugin without a theme edit or a `wp_dequeue_script`/filter — easy to forget. Both systems gate on the same `po_has_consent('analytics')`, so they activate together.

**How to avoid:**
- Treat theme-tracker deactivation as a **blocking acceptance criterion** for the conversion/click phase, not a cleanup task. The plugin must not ship clicks to production while the theme tracker still emits `cta_click`.
- Decide the cutover mechanism explicitly: either (a) the theme stops enqueuing `analytics-tracker.js` (theme edit), or (b) the plugin `wp_dequeue_script`s it on `wp_enqueue_scripts` at high priority, or (c) reuse the theme's existing `window.poTrack`/`data-po-track` hook instead of binding a second listener (avoids double-binding entirely — recommended per `CONTEXT-FINDINGS.md:128`).
- Add a one-time migration/verification step: confirm `wp_po_analytics_events` stops receiving new `cta_click`/`pageview` rows after cutover.

**Warning signs:**
Click count ≈ 2× a manual click test; both `wp_po_analytics_events` and the new table grow on the same click; CTR > 100% on some campaigns; `window.poTrack` and the plugin both present in DevTools event listeners on a CTA.

**Phase to address:** Click-tracking phase (with an explicit cutover gate). Verify before the dashboard phase reports any click numbers.

---

### Pitfall 2: Full-page cache silently zeroes/undercounts server-side visit counts **[NEW — extends "high visit volume" risk]**

**What goes wrong:**
If visits are counted server-side (`template_redirect`/`wp` hook → DB insert), a full-page cache (WP Rocket, Varnish, host-level edge cache, Cloudflare) serves cached HTML **without executing PHP or any WordPress hook**. The first uncached request increments the counter; every subsequent cache HIT does not. Visit counts then reflect cache-miss frequency (≈ cache TTL and purge cadence), not real traffic — often undercounting by 90%+ on popular landing pages. The dashboard looks "done," the number is just quietly wrong.

**Why it happens:**
Server-side counting is the intuitive, consent-independent choice and works perfectly in local/dev (no cache). The failure only appears under production caching, which devs rarely replicate. `berlin.parkourone.com` is a production WooCommerce store and very likely sits behind page caching.

**How to avoid:**
- Count visits **client-side** via a beacon to a plugin REST route (the route itself is dynamic, never cached). This matches the theme's existing pageview approach and is consent-gateable. Recommended.
- If a server-side counter is kept for any reason, exclude those pages with `DONOTCACHEPAGE`/cache-exclusion rules — but this defeats the cache and hurts the very landing pages you're optimizing; avoid.
- Never mix: pick client-side beacon for visits and keep server-side ONLY for the conversion (which fires on uncached checkout/order transitions).

**Warning signs:**
Visit counts far lower than CTA clicks (clicks > visits is impossible in a real funnel); visit counts that jump only when cache is purged/deploys happen; visits flat across a traffic spike; visits correlate with deploy frequency rather than ad spend.

**Phase to address:** Visit-tracking phase. Architecture decision (client beacon vs server hook) must be made here, with a cache-behavior note in the phase plan.

---

### Pitfall 3: Bots, crawlers, and link-prefetch inflate visits and clicks **[NEW]**

**What goes wrong:**
A naive client beacon or click listener fires for: search-engine crawlers running JS, uptime monitors, headless preview bots (Slack/WhatsApp/Facebook link unfurlers hitting `/probetraining-buchen/`), and **browser prefetch/prerender** (Chrome's `Speculation Rules`/`<link rel=prefetch>`, instant.page, Flying Pages, WP Rocket's link-preload). Prefetch in particular pre-loads the CTA's destination page on hover, which can fire a "visit" to `/probetraining-buchen/` and inflate the mid-funnel. Result: visits and clicks are systematically inflated, dragging conversion rate down and making campaigns look worse than they are.

**Why it happens:**
The robust selector `a[href*="/probetraining-buchen"]` (the project's deliberate choice) is broad; prefetch tooling navigates exactly those links. Bot filtering is an afterthought because dev traffic is clean.

**How to avoid:**
- Filter obvious bots server-side at ingest by user-agent (a maintained bot/crawler regex) AND ignore requests with no/implausible referrer chains.
- For prefetch: honor `Purpose: prefetch` / `Sec-Purpose: prefetch;prerender` request headers and the `Page Visibility` API — don't count a pageview while the page is prerendered/hidden; count on first real `visibilitychange→visible` or a short dwell timer.
- Already covered partially: excluding logged-in admins (`current_user_can('manage_options')`) — keep that, but it does NOT cover bots/prefetch.
- Send the beacon on a small interaction/dwell signal rather than immediate `DOMContentLoaded`.

**Warning signs:**
Visit spikes from a single user-agent or datacenter IP range; `/probetraining-buchen/` visits with zero downstream `Jetzt buchen`; conversion rate inexplicably halving after enabling a speed plugin; visits at 03:00 matching crawler schedules.

**Phase to address:** Visit-tracking phase (define the bot/prefetch filter as a success criterion, not "later").

---

### Pitfall 4: First-touch UTM attribution lost or overwritten

**What goes wrong:**
Several independent failure modes collapse attribution to "(none)" or wrong-campaign:
- **Last-click overwrite:** writing the UTM cookie on every visit overwrites the genuine first touch when the user returns via a different (or no) campaign. First-touch must be write-once.
- **Cookie not yet set at checkout:** the booking flow (`/probetraining-buchen/` → `event-booking` block → REST add-to-cart → checkout) can span multiple page loads; if the cookie isn't persisted to order meta at the RIGHT checkout hook, the order has no campaign.
- **Attribution-window gap:** a visit today, booking in three weeks — if the cookie expired (or consent wasn't given on the first visit, see Pitfall 7) the link is gone.
- **Cross-domain / redirect loss:** payment redirects or any cross-host hop can drop a host-only cookie; UTM params on the landing URL are gone after the first navigation unless captured immediately.

**Why it happens:**
There is **no order↔campaign link anywhere today** (`CONTEXT-FINDINGS.md:27,146`); the bridge is brand-new code. The theme passes `po_analytics_session_id` but not first-touch UTM. Devs test the happy path (land with UTM, book immediately) and never see the multi-session/expiry cases.

**How to avoid:**
- First-touch cookie: set ONLY if not already present (`if (!isset($_COOKIE[...]))`). Store `utm_source/medium/campaign` + landing `page_url` + timestamp.
- Persist to order meta at `woocommerce_checkout_create_order` / `woocommerce_checkout_update_order_meta` (per `CONTEXT-FINDINGS.md:41,147`), AND also persist the theme's `po_analytics_session_id` as a second bridge key for redundancy.
- Define and document the attribution window (cookie lifetime) explicitly — and reconcile it with DSGVO (Pitfall 8): a long marketing cookie needs a justification.
- Capture UTM from `document.referrer`/URL on the very first load, before any redirect can strip it.
- At conversion, if no first-touch meta exists, fall back to same-day `session_id`/`visitor_hash` join — but record it as "low-confidence attribution" so it's visible, not silently merged.

**Warning signs:**
High share of bookings attributed to "(direct)/(none)"; campaign with many clicks but near-zero attributed bookings while "direct" bookings spike in parallel; attributed campaign changes if the same test user re-visits via a different link.

**Phase to address:** Attribution-bridge phase (the core-value phase — "if all else fails, conversion attribution must be correct," per `PROJECT.md`).

---

### Pitfall 5: Free / 100%-coupon bookings bypass the primary conversion hook

**What goes wrong:**
A 100%-coupon or free Probetraining may never hit `woocommerce_payment_complete` (no payment), so `check_and_set_probetraining_status` and thus `woocommerce_order_status_probetraining` may not fire on the normal path. These orders reach the `probetraining` status only via the fallback `ab_redirect_order_to_event_status` hooked on `woocommerce_order_status_completed/processing` at priority 999 (`CONTEXT-FINDINGS.md:54,142`). Counting ONLY the primary hook silently drops every free booking — and free trials are exactly the kind of conversion this product cares about.

**Why it happens:**
`woocommerce_order_status_probetraining` is the obvious "completed Probetraining" signal and works for paid orders in testing. The free/coupon path is a second, later code path most devs don't exercise.

**How to avoid:**
- Count on BOTH: `woocommerce_order_status_probetraining` AND `woocommerce_order_status_changed` where `new_status === 'probetraining'` (catches the redirect path).
- Guard against the resulting double-count with an idempotency flag in order meta, e.g. `_pot_conversion_tracked` (`CONTEXT-FINDINGS.md:24,150`). Set-and-check atomically.

**Warning signs:**
Conversions consistently lower than actual bookings during promo campaigns; zero conversions on days with coupon campaigns; an order in `probetraining` status with no matching row in the events table.

**Phase to address:** Conversion-tracking phase.

---

### Pitfall 6: Silent zero-conversions from the `ab-webhook-endpoint` dependency

**What goes wrong:**
The custom `probetraining` status is registered by a SEPARATE plugin (`AB_Custom_Statuses` in `ab-webhook-endpoint`). If that plugin is deactivated, updated, or loads after this one, `register_post_status('wc-probetraining')` never runs and the `woocommerce_order_status_probetraining` action never fires. Conversions drop to zero with **no error** — the dashboard just shows 0, which looks like "a quiet week," not "tracking is broken." This is the single most dangerous failure for the product's core value.

**Why it happens:**
Hard cross-plugin dependency on a runtime-registered status, plus load-order sensitivity (sibling uses `plugins_loaded` priority 11). Zero is a valid-looking number, so the failure is invisible without an explicit health check.

**How to avoid:**
- Defer hook registration to `plugins_loaded` and check for the dependency: `class_exists('AB_Custom_Statuses')` or `post_status_exists('wc-probetraining')` (or `function_exists('ab_order_is_event_booking')`).
- Degrade gracefully: never fatal; if absent, raise an **admin notice** AND a `not_configured`/`failed` source-status flag that propagates to the pull-API payload (matches the Statusboard `SourceStatus` enum, `CONTEXT-FINDINGS.md:30`).
- Add a heartbeat: persist `last_conversion_at`; if conversions are 0 for N days while visits/clicks continue, surface a warning in the dashboard and in the API (`status: stale`). Distinguish "0 real bookings" from "tracking offline."
- Optionally count off `woocommerce_order_status_changed` matching the status SLUG string `'probetraining'` directly, which works even if you don't depend on the named dynamic action.

**Warning signs:**
Conversions = 0 while clicks/visits are healthy; admin notice missing because nobody checks; `post_status_exists('wc-probetraining')` returns false in a debug check; deploy or plugin-update day coincides with conversions flatlining.

**Phase to address:** Conversion-tracking phase (dependency guard + graceful degradation) and Pull-API phase (propagate the `stale`/`not_configured` status outward).

---

### Pitfall 7: Consent timing — tracking before consent, or losing first-touch when consent is deferred **[NEW — extends DSGVO risk]**

**What goes wrong:**
Two opposite failures:
1. **Tracking too early:** writing the UTM cookie or firing the visit beacon on page load, before `po_has_consent('analytics')` is true, is a DSGVO/TTDSG §25 violation (storing/accessing info on the user's device needs prior consent). This is a legal/privacy bug, not just a data bug.
2. **Tracking too late / never:** if first-touch is only captured AFTER consent, and the user lands via a campaign but consents on a later page (or after navigating away from the UTM URL), the original UTM is already gone → attribution lost. Strict consent-gating directly fights attribution accuracy (Pitfall 4).

**Why it happens:**
The consent manager loads asynchronously; the UTM params are on the FIRST URL, which is exactly when consent is least likely to be granted yet. Devs either ignore consent (mode 1) or gate everything and lose the data (mode 2).

**How to avoid:**
- Gate ALL device storage and beacons behind `po_has_consent('analytics')`, matching the theme posture (`CONTEXT-FINDINGS.md:52,127`). Re-check on the consent-granted event, not just on load.
- For the first-touch loss: capture UTM into a transient JS variable on load; only WRITE the cookie/fire the beacon once consent is present. If consent arrives on the same page load, you still have the UTM in memory. If the user never consents, you have no data — which is the correct DSGVO outcome.
- Document the legal basis: conversion attribution generally requires consent under German rules (verified — see Sources). Do not assume "legitimate interest" covers cross-page marketing-campaign attribution cookies.
- Server-side conversion counting (the WC status hook) is consent-INDEPENDENT (it's first-party order processing, no device access) — lean on it as the source of truth; consent only affects the visit/click funnel top.

**Warning signs:**
Network beacon fires in DevTools before the consent banner is answered; UTM cookie present on a session that declined consent; large gap between ad-platform-reported clicks and tracked visits (consent decline rate); legal/DPO review flags the cookie.

**Phase to address:** Visit-tracking phase (consent gate) and Attribution-bridge phase (in-memory-capture-then-consent pattern).

---

### Pitfall 8: Storing IP / PII and indefinite retention **[NEW — extends DSGVO risk]**

**What goes wrong:**
Logging raw IP address, full user-agent, precise timestamps tied to identifiers, or persisting order-level participant data (`_event_participant_data` includes names/birthdates) into the tracking table turns an "aggregate analytics" store into a PII database — triggering full DSGVO obligations (retention limits, deletion requests, ROPA, possibly DPIA). IP is personal data under DSGVO (verified — see Sources). Unbounded retention compounds this: a forever-growing events table is also a data-minimization violation.

**Why it happens:**
"Store everything, we might need it" is the default. The theme's `wp_po_analytics_events` already stores `visitor_hash`, `referrer`, `page_url` — copying its schema verbatim may import PII fields. The project's own constraint says "IP/PII vermeiden, Datensparsamkeit" but it's easy to violate in the rush to debug.

**How to avoid:**
- Store NO raw IP. If you need uniqueness/bot-filtering, use a salted, rotated, truncated hash (and document it as pseudonymous, not anonymous).
- Keep the events table to the minimum: `event_type`, `campaign`, `landing_url` (sanitized, no PII query params), `order_id`, `event_id`, `session_id`, `created_at`. Do NOT copy participant names/birthdates into it.
- Define a retention policy NOW (e.g. raw events pruned after 90 days; only daily/campaign aggregates kept long-term). Implement pruning via `wp_schedule_event` (see Pitfall 9/11).
- Only AGGREGATES leave via the pull-API (already decided, `PROJECT.md` out-of-scope: no raw single-event export) — keep that boundary firm.
- Sanitize `landing_url`: strip query strings except the UTM keys you need; `/probetraining-buchen/?event=123` and email/token params must not be logged raw.

**Warning signs:**
Events table has an `ip` or `user_agent` column; rows contain `?token=`/`?email=` URLs; table has no retention/pruning job; a data-subject deletion request can't be fulfilled because tracking rows aren't linked to a deletable identity; table older than the documented retention window.

**Phase to address:** Schema/storage phase (column design + retention) and Visit/click phase (URL sanitization at ingest).

---

### Pitfall 9: Pull-API security — open endpoint, timing-unsafe compare, secret in logs **[deepens "permission_callback" anti-pattern]**

**What goes wrong:**
- **Open endpoint:** copying the sibling's `permission_callback => '__return_true'` (`CONTEXT-FINDINGS.md:21,71`) exposes per-campaign metrics to anyone with the URL.
- **Timing-unsafe compare:** verifying the bearer secret with `===` (the exact anti-pattern at `class-ab-contract-wizard.php:181`) leaks the secret byte-by-byte via response timing.
- **Secret in logs:** `error_log()`-ing the request (the house style uses bracketed-prefix `error_log`) can write the `Authorization` header or secret to `debug.log`, readable by anyone who can reach `wp-content/debug.log`.
- **No rate limiting / no CORS discipline:** an unauthenticated-but-discovered endpoint can be brute-forced; permissive CORS lets a browser-based attacker read it.

**Why it happens:**
The reference plugin's REST layer is the wrong model (it's an unauthenticated receiver). The house `error_log` convention is fine for diagnostics but dangerous for secrets. The Statusboard is a server-side Vercel cron (`CONTEXT-FINDINGS.md:159`) — so CORS should be CLOSED (no browser caller), but devs often add `Access-Control-Allow-Origin: *` reflexively.

**How to avoid:**
- Real `permission_callback` that reads `Authorization: Bearer <secret>` and compares with `hash_equals()` (constant-time) — reuse the `helper-functions.php` pattern, NOT the `===` one (`CONTEXT-FINDINGS.md:20,76`).
- Mint the secret with `wp_generate_password(32, false)` into a dedicated `autoload=false` option (NOT `wp_salt`, since it's shared with an external party — `CONTEXT-FINDINGS.md:74`).
- NEVER log the secret/Authorization header. Redact before any `error_log`. Don't ship with `WP_DEBUG_LOG` writing secrets.
- Length-check before `hash_equals` (mirror the Statusboard `timingSafeEqual` length guard, `CONTEXT-FINDINGS.md:159`).
- CORS: this is a server-to-server pull — do NOT send permissive CORS headers. Optionally add light rate-limiting (transient counter per IP) and return 401 (never 500) on auth failure.
- Consider HMAC-SHA256 of the response/request as the stronger option already documented for the Statusboard side.

**Warning signs:**
Endpoint returns data with no/garbage Authorization header; `grep` finds the secret in `debug.log`; auth code uses `==`/`===`; `Access-Control-Allow-Origin: *` in the response; endpoint is in the public REST `/wp-json/` index discoverable without auth (consider not advertising it).

**Phase to address:** Pull-API phase. Security must be a built-in acceptance criterion, not a follow-up hardening pass.

---

### Pitfall 10: Custom table unbounded growth + missing/Wrong indices for date-range GROUP BY **[deepens "DB bloat" risk]**

**What goes wrong:**
Visit/click volume on a public site is high. Without the right composite indices, the dashboard's core query — `SELECT campaign, COUNT(*) ... WHERE event_type=? AND created_at BETWEEN ? AND ? GROUP BY campaign` — does a full table scan and filesort. It's instant on a dev table of 500 rows and takes seconds (then times out AJAX) at millions of rows. Combined with no retention (Pitfall 8), the table grows forever, slows every query, and bloats backups.

**Why it happens:**
This is a deliberate deviation from the options-only house style (`CONTEXT-FINDINGS.md:22,56,107`), so there's no sibling pattern to copy — indices and retention must be designed from scratch. `dbDelta` index syntax is finicky and silently no-ops on mistakes.

**How to avoid:**
- Add a composite index matching the query's leftmost columns: `(event_type, created_at)` and, if grouping by campaign, `(event_type, campaign, created_at)`. Verify with `EXPLAIN` on a seeded large table — not on an empty one.
- Get `dbDelta` index syntax exactly right (two spaces after `KEY`, named keys); `dbDelta` won't add an index if the line doesn't match its expected format. Verify the index actually exists post-activation (`SHOW INDEX`).
- Consider a pre-aggregated daily rollup table (campaign × day × event_type counts) that the dashboard and pull-API read, with raw events pruned after the retention window. This makes date-range queries trivial regardless of raw volume.
- Paginate/cap admin queries; never `SELECT *` the raw table for display.

**Warning signs:**
Dashboard AJAX slow or timing out as data accumulates; `EXPLAIN` shows `type: ALL` / `Using filesort`; `SHOW INDEX` doesn't list the expected key; table size grows linearly with no plateau; DB CPU spikes on dashboard load.

**Phase to address:** Schema/storage phase (indices + rollup design), verified again in the Dashboard phase against a realistically-sized table.

---

### Pitfall 11: Synchronous tracking work on page load + autoloaded options bloat **[NEW — extends performance scope]**

**What goes wrong:**
- **Synchronous ingest:** doing a DB insert (or worse, an outbound HTTP push) synchronously inside the front-end request that serves the landing page adds latency to every visit and can pile up under traffic. A blocking `wp_remote_post` to the Statusboard on each conversion within the request is especially bad.
- **Autoload bloat:** storing the events buffer, last-send status, or any growing state in an autoloaded option means it's loaded on EVERY WordPress request site-wide (`wp_load_alloptions`), slowing the whole site, not just tracking.

**Why it happens:**
Inserting on the same request is the simplest code. `update_option($key, $val)` defaults to `autoload = 'yes'`. The house style stores state in options; it's natural to do the same for tracking state without thinking about autoload.

**How to avoid:**
- Visit/click ingest goes through a lightweight REST/AJAX beacon, not the page render. Keep the insert minimal and indexed.
- Outbound pushes to the Statusboard use `wp_remote_post(..., ['blocking' => false])` (fire-and-forget) or, better, defer to `wp_schedule_single_event`/cron so the user request never waits (`CONTEXT-FINDINGS.md:47,74`).
- ALL plugin state options that are large or frequently-changing → `update_option($key, $val, false)` (autoload off): the events buffer, `last_send_status`, the secret, logs. Only tiny, every-request config may autoload.
- Batch the periodic visit/click aggregate push via `wp_schedule_event` rather than per-event.

**Warning signs:**
TTFB on landing pages rises after plugin activation; `wp_load_alloptions` size grows (check `SELECT SUM(LENGTH(option_value)) FROM wp_options WHERE autoload='yes'`); Statusboard pushes correlate with slow checkout; cron not registered so pushes never batch.

**Phase to address:** Visit/click phase (async ingest, autoload discipline) and Pull-API/outbound phase (non-blocking send / cron).

---

### Pitfall 12: Selector & URL fragility, query-param noise in click de-dup **[deepens "selector fragility" risk]**

**What goes wrong:**
`a[href*="/probetraining-buchen"]` is robust to CSS-class changes but breaks if the destination URL ever changes, and it matches BOTH the bare `/probetraining-buchen/` and the `stundenplan-detail` variant `/probetraining-buchen/?event=123` (`CONTEXT-FINDINGS.md:59,121`). If you store the raw href as the "click target," query-param noise fragments the same logical CTA into many rows and (per Pitfall 8) may log `?event=ID`. Also: rapid double-clicks or the capture-phase listener firing alongside navigation can double-fire a single click.

**Why it happens:**
The `*=` substring match is intentionally broad. Storing raw href feels precise but is noisy. Click + navigation timing is subtle.

**How to avoid:**
- Normalize the click target to the path (`/probetraining-buchen/`) and store the source context (which block/page) separately, not by parsing the destination query string.
- De-dupe rapid repeat clicks client-side (short debounce / one-shot per element per N ms) before sending the beacon.
- Use `sendBeacon`/keepalive so the event isn't lost to navigation, but guard against double-send.

**Warning signs:**
Many click rows differing only by `?event=` params; click counts higher than plausible per session; the same CTA click logged twice within milliseconds.

**Phase to address:** Click-tracking phase.

---

### Pitfall 13: Conversion attribution join breaks silently when the bridge key is missing **[NEW]**

**What goes wrong:**
The conversion (server-side WC status) and the campaign (client-side cookie/session) are joined via order meta written at checkout. If that meta write fails (consent declined → no cookie; session_id not POSTed; checkout hook didn't run for a free order), the conversion is counted but attributed to no campaign — so total conversions look right while per-campaign conversions undercount. The funnel "balances" at the top and bottom but leaks in the middle, which is hard to notice.

**Why it happens:**
Two data sources (consent-gated client, consent-independent server) with different availability. The conversion always exists; the attribution sometimes doesn't. Devs verify "did we count the booking?" but not "did we attribute it?"

**How to avoid:**
- Record EVERY conversion even when attribution is missing, with an explicit `campaign = '(unattributed)'` bucket — never drop it, never silently merge into a real campaign.
- Surface the unattributed share in the dashboard and the pull-API so a rising "(unattributed)" rate flags a broken bridge.
- Provide the same-day `session_id`/`visitor_hash` fallback join (Pitfall 4) but tag it as a fallback.

**Warning signs:**
Sum of per-campaign conversions < total conversions; "(unattributed)" share trending up after a consent-banner change; conversions present but no first-touch order meta on the order.

**Phase to address:** Attribution-bridge phase + Dashboard phase (expose the unattributed bucket).

---

## Technical Debt Patterns

| Shortcut | Immediate Benefit | Long-term Cost | When Acceptable |
|----------|-------------------|----------------|-----------------|
| Server-side visit counter (no client beacon) | Simple, consent-independent, no JS | Silently undercounts behind full-page cache (Pitfall 2) | Never on a cached production site for VISIT counts |
| Store raw events forever, no retention job | One less moving part | DB bloat + DSGVO data-minimization violation | MVP only IF a retention/pruning job is on the very next phase's backlog |
| Reuse theme's `wp_po_analytics_events` schema verbatim | No new table, instant | Imports PII-ish fields; couples to theme that's being retired | Only if PII columns are dropped/sanitized first |
| Count conversion on the single named status hook | Matches the obvious signal | Drops free/coupon bookings (Pitfall 5) | Never — always add the `order_status_changed` fallback |
| Per-event blocking outbound push to Statusboard | Near-real-time, simple | Adds request latency, fire-and-forget loses data on downtime | OK with `blocking=>false`; add cron re-queue for durability |
| Autoload all plugin options | Default behavior, no thought | Site-wide slowdown via `alloptions` | Only tiny static config; never the events buffer/log/secret |
| Drop the dependency health check on `ab-webhook-endpoint` | Less code | Silent zero-conversions look like a quiet week (Pitfall 6) | Never — core value depends on knowing tracking is alive |

## Integration Gotchas

| Integration | Common Mistake | Correct Approach |
|-------------|----------------|------------------|
| `ab-webhook-endpoint` (`probetraining` status) | Register hooks at load; assume status exists | Defer to `plugins_loaded`; `post_status_exists()`/`class_exists()` guard; graceful `not_configured` flag |
| WooCommerce checkout (attribution write) | Use the wrong/late hook; miss free orders | `woocommerce_checkout_create_order`/`update_order_meta` for meta; both status hooks for conversion |
| Consent manager (`po_has_consent`) | Gate on load only; track before consent | Re-check on consent-granted event; capture UTM in memory, write on consent |
| Full-page cache (WP Rocket/Varnish/edge) | Assume PHP hooks run on every view | Client beacon to dynamic REST route; never count visits in cached PHP |
| Theme `analytics-tracker.js` | Leave it running during cutover | Hard cutover gate; dequeue or reuse `window.poTrack`; verify old table stops growing |
| Statusboard pull-API (Vercel cron) | Permissive CORS; assume browser caller | Server-to-server only, no CORS; `hash_equals` bearer; camelCase + `generatedAt` payload |

## Performance Traps

| Trap | Symptoms | Prevention | When It Breaks |
|------|----------|------------|----------------|
| Missing composite index on events table | Dashboard AJAX slow/timeout; `EXPLAIN` shows full scan + filesort | Index `(event_type, created_at[, campaign])`; verify with `SHOW INDEX`; rollup table | ~10^5–10^6 rows / a few months of public traffic |
| No retention/pruning | Table + backups grow without bound; queries degrade | `wp_schedule_event` prune raw events past retention; keep aggregates | Continuously; noticeable within weeks at public volume |
| Synchronous insert/push on page load | Rising TTFB after activation; slow checkout | Async beacon; `blocking=>false`/cron for outbound | Under traffic spikes / concurrent checkouts |
| Autoloaded growing options | Whole-site slowdown; large `alloptions` | `autoload=false` for buffers/logs/secret | As option payload grows; affects every request |
| Per-event outbound push | Statusboard rate-limits; lost events on downtime | Batch via cron; idempotency key; re-queue | At high conversion/visit volume or Statusboard downtime |

## Security Mistakes

| Mistake | Risk | Prevention |
|---------|------|------------|
| `permission_callback => '__return_true'` on the pull-API | Anyone reads per-campaign metrics | Real bearer/HMAC check with `hash_equals` |
| `===`/`==` secret comparison (`contract-wizard.php:181`) | Timing side-channel leaks secret | `hash_equals()` constant-time, length-checked first |
| Secret/Authorization header in `error_log`/`debug.log` | Secret disclosure via readable log | Redact before logging; no secret logging; secure `debug.log` |
| Reusing `wp_salt` as the shared API secret | Can't share/rotate with external party safely | Dedicated `wp_generate_password(32,false)` option, `autoload=false` |
| Permissive CORS on pull-API | Browser-side data exfiltration | No CORS headers (server-to-server only) |
| Logging raw IP/PII in events | DSGVO breach; deletion-request exposure | Salted/rotated hash or omit; no IP column |
| 500 on auth failure | Leaks internal state, aids probing | Return 401 on missing/bad secret, never 500 |

## UX Pitfalls

| Pitfall | User Impact | Better Approach |
|---------|-------------|-----------------|
| Dashboard shows 0 conversions with no "tracking offline" signal | Admin assumes a slow week; doesn't fix broken dependency | Distinguish "0 bookings" from "tracking unhealthy"; show `stale`/`not_configured` |
| No "(unattributed)" bucket | Per-campaign numbers silently undercount, decisions made on wrong data | Always show unattributed share; alert when it rises |
| CTR > 100% / clicks > visits shown without warning | Erodes trust in the whole dashboard | Validate funnel monotonicity; flag impossible ratios |
| Date-range picker with no timezone clarity | Off-by-one-day reporting vs Statusboard/ad platforms | Fix and document timezone (site tz vs UTC) consistently across DB, dashboard, API |

## "Looks Done But Isn't" Checklist

- [ ] **Visit counting:** Works in dev — but verify it still counts behind the PRODUCTION full-page cache (Pitfall 2). Test on a cached page, not just logged-in.
- [ ] **Conversion counting:** Counts paid bookings — but verify a 100%-coupon/free booking is ALSO counted via the fallback hook (Pitfall 5).
- [ ] **Dependency:** Counts conversions — but verify behavior when `ab-webhook-endpoint` is deactivated (admin notice + `not_configured`, NOT silent 0) (Pitfall 6).
- [ ] **Attribution:** Attributes immediate same-session bookings — but verify multi-session (visit today, book in 2 weeks) and consent-declined cases produce correct/"(unattributed)" results (Pitfalls 4, 7, 13).
- [ ] **Consent:** Tracking respects consent — but verify NO cookie/beacon fires before consent is granted, in DevTools network/cookies (Pitfall 7).
- [ ] **Pull-API:** Returns data — but verify it returns 401 with no/wrong bearer, uses `hash_equals`, sends no permissive CORS, and the secret never appears in `debug.log` (Pitfall 9).
- [ ] **DB:** Queries are fast — but verify with a SEEDED large table (10^5+ rows) and `EXPLAIN`, and confirm the index actually exists via `SHOW INDEX` (Pitfall 10).
- [ ] **Retention:** Data is stored — but verify a pruning cron is scheduled and removes raw events past the retention window (Pitfalls 8, 10).
- [ ] **Cutover:** New tracking works — but verify the theme tracker stopped writing `cta_click`/`pageview` to `wp_po_analytics_events` (Pitfall 1).
- [ ] **Uninstall:** Plugin deactivates cleanly — but verify the `uninstall.php`/uninstall hook drops the custom table, deletes options (incl. the secret), and clears scheduled cron events. [NEW]
- [ ] **Autoload:** Options save — but verify large/volatile options use `autoload=false` (Pitfall 11).

## Recovery Strategies

| Pitfall | Recovery Cost | Recovery Steps |
|---------|---------------|----------------|
| Double-counting (theme tracker) | LOW | Cut over fully; mark affected date ranges as inflated; future numbers clean (no historical merge needed if dashboards read forward) |
| Full-page-cache undercount of visits | MEDIUM | Switch to client beacon; past visit data unrecoverable — annotate the gap; don't back-fill |
| Lost attribution (no bridge meta) | HIGH | Unrecoverable retroactively (no order↔campaign link exists historically); fix forward; use same-day heuristic only as best-effort |
| Silent zero-conversions | MEDIUM | Re-enable/repair `ab-webhook-endpoint`; back-fill by scanning orders already in `probetraining` status (data still in WC) — this IS recoverable from order history |
| Missing index / slow queries | LOW | Add index via migration; build rollup table; immediate relief |
| DB bloat | MEDIUM | Add retention job; prune old raw rows; keep aggregates; reclaim space |
| Secret leaked in logs | MEDIUM | Rotate the secret (regenerate option, update Statusboard env); purge `debug.log`; audit access |
| PII stored unintentionally | HIGH | Stop collection; purge offending columns/rows; document the incident; reassess DSGVO obligations |

## Pitfall-to-Phase Mapping

| Pitfall | Prevention Phase | Verification |
|---------|------------------|--------------|
| 1. Double-counting (theme tracker) | Click-tracking (cutover gate) | Old `wp_po_analytics_events` stops getting new click/pageview rows |
| 2. Full-page-cache undercount | Visit-tracking (architecture decision) | Visit increments on a cached page in production-like cache |
| 3. Bot/crawler/prefetch inflation | Visit-tracking | Bot UAs and prefetch requests excluded; visits ≥ clicks holds |
| 4. UTM first-touch loss/overwrite | Attribution-bridge | Multi-session + redirect test attributes to original campaign |
| 5. Free/coupon bypass | Conversion-tracking | A 100%-coupon order is counted exactly once |
| 6. Silent zero-conversions (dependency) | Conversion-tracking + Pull-API | Deactivating `ab-webhook-endpoint` → admin notice + `not_configured` flag |
| 7. Consent timing | Visit-tracking + Attribution-bridge | No beacon/cookie before consent; UTM survives same-page consent |
| 8. IP/PII + retention | Schema/storage + Visit/click | No IP column; URLs sanitized; pruning cron scheduled |
| 9. Pull-API security | Pull-API | 401 on bad bearer; `hash_equals`; no CORS; secret not in logs |
| 10. Missing index / GROUP BY | Schema/storage + Dashboard | `EXPLAIN` on seeded table uses the index; AJAX fast at 10^5+ rows |
| 11. Sync work + autoload bloat | Visit/click + Pull-API/outbound | TTFB unchanged; `alloptions` small; outbound non-blocking/cron |
| 12. Selector/URL fragility | Click-tracking | Click target normalized to path; rapid double-click deduped |
| 13. Attribution join breaks | Attribution-bridge + Dashboard | "(unattributed)" bucket exists and is surfaced |
| (Uninstall cleanup) | Final/packaging phase | `uninstall.php` drops table, deletes options + secret, clears cron |

## Sources

- `CONTEXT-FINDINGS.md` (verified codebase analysis, 2026-05-31): risks (lines 51–60), conversion model (135–150), attribution gap (146–147), house-style anti-patterns (21,71–78), Statusboard auth contract (152–175). HIGH confidence.
- `PROJECT.md` (project constraints: DSGVO, dependency, API security). HIGH confidence.
- WP Rocket Knowledge Base — page caching bypasses PHP/hooks; `DONOTCACHEPAGE`; Varnish + dynamic-cookie incompatibility. https://docs.wp-rocket.me/article/1528-page-caching , https://docs.wp-rocket.me/article/493-using-varnish-with-wp-rocket , https://docs.wp-rocket.me/article/141-force-page-caching . MEDIUM confidence (vendor docs).
- German DSGVO/TTDSG analytics & conversion-tracking consent, IP as personal data, §25 device-storage consent: dr-dsgvo.de (https://dr-dsgvo.de/tracking-en/), CookieYes (https://www.cookieyes.com/blog/google-analytics-gdpr/), EARNST GDPR tracking guide (https://www.earnst.io/en/knowledge/gdpr-tracking-guide/). MEDIUM confidence (legal-info sources, not formal legal advice — recommend DPO review).
- WordPress core: `dbDelta` index syntax requirements, option `autoload` semantics, REST `permission_callback`, `wp_schedule_event`. HIGH confidence (well-established WP behavior).

---
*Pitfalls research for: WordPress/WooCommerce campaign funnel-tracking plugin (DSGVO, pull-API to external dashboard)*
*Researched: 2026-05-31*
