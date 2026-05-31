# Phase 3: Consent-Gated Client Capture & Theme Retirement - Context

**Gathered:** 2026-05-31
**Status:** Ready for planning
**Mode:** Auto-decided (autonomous; grey areas resolved from CONTEXT-FINDINGS.md + research + Phase 1/2 implementation, no open questions)

<domain>
## Phase Boundary

Capture the top two funnel stages — landing-page VISITS and "Probetraining buchen" CTA CLICKS — client-side, behind consent/admin/bot gates, written through `POT_Store` via a dynamic REST ingest endpoint (cache-safe). Then retire the legacy theme analytics tracker in the SAME phase so exactly one tracker runs with no gap and no double-count. Conversions (Phase 2) already exist; this closes the visit→click→booking funnel.

In scope: CAPTURE-01..05, MIGRATE-01..02.
OUT of scope: dashboard (Phase 4), pull-API (Phase 5). No visible admin UI in this phase — the only "frontend" is a non-visual beacon + click listener, so NO UI-SPEC is needed.
</domain>

<decisions>
## Implementation Decisions

### Reuse from Phase 1/2 (do not modify contracts)
- Write all events via `POT_Store::insert_event(['event_type'=>'visit'|'click', 'campaign'/'source'/'medium'/'landing_path'/'session_id'=>..., 'created_at' default])`.
- Campaign context for visits/clicks comes from the SAME first-touch cookie `pot_attribution` (set in Phase 2 by `pot-attribution.js`): read `{campaign,source,medium}` from it client-side and send with each beacon. No cookie yet → send empty campaign → `POT_Store::UNATTRIBUTED` bucket.
- Consent check = the theme's `window.poConsent.categories.analytics` (confirmed in Phase 2 PATTERNS — there is NO consent-change event; re-check on pageload). Admin exclusion = the same localized "is admin" flag pattern used by `pot-attribution.js` (do not enqueue the tracker for logged-in `manage_options` users).
- Wire new classes from `pot_init()` in `includes/class-pot-plugin.php` (same flat require_once + instantiate-on-plugins_loaded pattern). github-updater require stays LAST.

### Ingest REST endpoint (cache-safe) — CAPTURE-01
- New `includes/class-pot-ingest.php`: register `POST pot/v1/event` on `rest_api_init`. This is a DYNAMIC endpoint (full-page cache serves static HTML without PHP, so visits MUST be a client beacon to this dynamic route — NOT a `template_redirect` server counter).
- `permission_callback` verifies the WP REST nonce: client sends `X-WP-Nonce` header (or `_wpnonce` body field); callback returns `wp_verify_nonce($nonce, 'wp_rest') !== false`. NEVER `__return_true`. (Anonymous front-end beacon → nonce is the standard CSRF/abuse gate; documented as the v1 control. No bearer/HMAC here — that is the OUTBOUND pull-API in Phase 5.)
- Handler validates `type` ∈ {visit, click}, reads sanitized `campaign/source/medium` (sanitize_text_field, length-cap), `landing_path` (esc_url_raw/sanitize, store path only), `session_id` (alnum, length-cap), then `POT_Store::insert_event(...)`. Returns a tiny 204/`{ok:true}`.
- Server-side guards in the handler (defense-in-depth, mirrors client gates): reject if `is_user_logged_in() && current_user_can('manage_options')` (admin), reject known bots via a UA denylist. NEVER store IP or full UA (DSGVO — only a boolean bot decision).

### Tracker script — CAPTURE-01..04
- New `assets/js/pot-tracker.js`, enqueued on `wp_enqueue_scripts`, SKIPPED for logged-in admins (localized flag), localized with `{ restUrl: rest_url('pot/v1/event'), nonce: wp_create_nonce('wp_rest') }`.
- Consent-gated: if `window.poConsent?.categories?.analytics` is not granted → do nothing (no beacon, no cookie). (Visits/clicks ARE consent-gated; conversions are not — that split is intentional.)
- VISIT (CAPTURE-01): on load (after consent check), send ONE beacon `{type:'visit', ...campaign from pot_attribution cookie, landing_path: location.pathname, session_id}` via `navigator.sendBeacon()` (fallback `fetch(..., {method:'POST', keepalive:true, headers:{'X-WP-Nonce':nonce}})`). One per pageview. Because this is client JS, it fires even on fully-cached pages (cache-safe).
- CLICK (CAPTURE-02): a delegated CAPTURE-phase listener on `document` for `a[href*="/probetraining-buchen"]` (covers all per-block CSS variants). On match, send `{type:'click', ...}`. Debounce identical rapid double-clicks (ignore same href within ~500ms). Use sendBeacon so it survives the navigation.
- session_id: a per-browser-session random id from `sessionStorage` (`pot_sid`), non-PII, consent-gated; included in beacons to support later unique-vs-total (v2). No new cookie required.
- Bot filtering (CAPTURE-05): client skips if `navigator.webdriver`; server UA denylist is the authoritative filter. Consent-gated JS already filters most bots (they rarely consent).

### Theme analytics retirement — MIGRATE-01..02
- The plugin DISABLES the theme's existing tracker so only one tracker runs (no double-count). The theme (`Input/parkourone-theme`) is a SEPARATE repo — do NOT edit it. Instead, from the plugin at a LATE priority:
  - `wp_dequeue_script` / `wp_deregister_script` the theme's analytics tracker handle (handle to be confirmed by pattern-mapper from `assets/js/analytics-tracker.js` enqueue), hooked on `wp_enqueue_scripts` priority 99+.
  - `remove_action`/`remove_filter` for the theme's server-side analytics emission (e.g. its purchase/`wp_po_analytics_events` writer) IF it is registered as a removable named callback (pattern-mapper to identify hook + callback). If it is a closure (not removable), document it as a manual theme-side toggle in the deferred checklist instead.
- A short-lived feature gate is acceptable: a `pot_retire_theme_tracker` option (default true) so the cutover can be toggled without code if parity fails.
- MIGRATE-02 (parity, no gap): a parity check comparing the plugin's visit/click counts vs the theme tracker's `wp_po_analytics_events` for an overlapping shadow window is a RUNTIME check → deferred manual checklist. The code ships the dequeue/cutover; the operator runs the parity comparison on staging before flipping live.

### Claude's Discretion
- Exact UA denylist contents, sendBeacon-vs-fetch fallback details, debounce window, `pot_sid` generation, the precise theme script handle/hook names (from pattern-mapper), and internal method decomposition — at Claude's discretion, consistent with established conventions.
</decisions>

<code_context>
## Existing Code Insights

### Reusable assets (this repo)
- `includes/class-pot-store.php` — `insert_event()` write path, `UNATTRIBUTED`.
- `includes/class-pot-attribution.php` + `assets/js/pot-attribution.js` (Phase 2) — the consent gate pattern (`window.poConsent.categories.analytics`), admin-skip localized flag, `pot_attribution` cookie read, sanitize+cap chain to MIRROR in the tracker + ingest handler.
- `includes/class-pot-plugin.php` — `pot_init()` orchestrator (wire `POT_Ingest`, tracker enqueue, theme-retirement here).

### Ground-truth references
- `CONTEXT-FINDINGS.md` — selector `a[href*="/probetraining-buchen"]`, consent gate, theme `analytics-tracker.js` (emits cta_click/pageview), `wp_po_analytics_events`, `po_analytics_session_id`, admin posture.
- `.planning/research/PITFALLS.md` — full-page cache zeroes server counters (→ client beacon to dynamic route), bots/prefetch, consent timing, double-count on cutover (hard acceptance criterion).
- `.planning/research/STACK.md` — REST nonce for inbound ingest; no IP/PII.

### Analog sources (Input/, separate repos — copy patterns, no runtime dep)
- `Input/parkourone-theme/assets/js/analytics-tracker.js` + its enqueue in `functions.php`/`inc/` — the tracker being replaced: mirror its consent/admin gate and beacon shape; IDENTIFY its script handle + server-side analytics hooks to dequeue/remove.
- `Input/ab-webhook-endpoint/includes/class-ab-rest-endpoint.php` — `register_rest_route` pattern (but REPLACE its `__return_true` with a real nonce `permission_callback`).
- `Input/ab-webhook-endpoint/includes/helper-functions.php` — sanitization conventions.

### Integration points
- REST namespace `pot/v1`, route `/event` (POST).
- Theme handle/hooks to dequeue/remove (pattern-mapper to confirm).
- Consent global `window.poConsent.categories.analytics`; `pot_attribution` cookie; `pot_sid` sessionStorage.
</code_context>

<specifics>
## Specific Ideas

- Hard acceptance (MIGRATE-01): after cutover, the theme's `analytics-tracker.js` must NOT emit and `wp_po_analytics_events` must stop receiving new tracking rows — one tracker only. Static proof: the plugin contains a `wp_dequeue_script`/`wp_deregister_script` of the theme handle at priority 99+ and (if removable) a `remove_action` of the theme analytics hook. Runtime parity comparison is deferred manual.
- Static verification (no PHP/WP/browser here): grep for `register_rest_route('pot/v1', '/event'`, the nonce `permission_callback` (and ABSENCE of `__return_true`), `wp_verify_nonce`, `is_user_logged_in()`/`current_user_can('manage_options')` server guard, UA denylist, `POT_Store::insert_event`, no `$_SERVER['REMOTE_ADDR']`/IP storage; in JS: `navigator.sendBeacon`, `a[href*="/probetraining-buchen"]`, capture-phase `addEventListener(...true)`, `window.poConsent`, read of `pot_attribution`. Runtime behaviors → deferred manual checklist.
- Reuse the consent/admin patterns from Phase 2 verbatim to avoid drift.
</specifics>

<deferred>
## Deferred Ideas

- Unique-vs-total visit counting (uses `pot_sid`) → v2 (UNIQUE-01); Phase 3 only records session_id.
- Runtime parity comparison + live cutover flip → deferred manual checklist (WP staging).
- Per-landing-page sub-breakdown → v2.
</deferred>
