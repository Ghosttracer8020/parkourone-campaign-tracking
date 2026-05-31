# Stack Research

**Domain:** Self-hosted WordPress/WooCommerce funnel-tracking plugin with a secured REST pull-API + lightweight admin dashboard (German/DSGVO context)
**Researched:** 2026-05-31
**Confidence:** HIGH (platform/version facts verified against official WP/Woo docs May 2026; pull-API auth choice cross-checked against codebase constraints)

> Scope note: The house style (file/class layout, `parkourone` admin menu, `wp-list-table`, `wp_ajax_`, github-updater, `hash_hmac`+`hash_equals`, no build step) is ALREADY settled in `CONTEXT-FINDINGS.md` and is treated as a constraint here, not re-derived. This file focuses on external/current best-practice and version choices.

## Recommended Stack

### Core Technologies

| Technology | Version | Purpose | Why Recommended |
|------------|---------|---------|-----------------|
| PHP | Target **8.1+**, test up to **8.3** | Plugin runtime | Woo 10.8 / WP 7.0 (May 2026) recommend PHP 8.3+; 8.1 is the lowest version that is still security-supported. Targeting 8.1 keeps `berlin.parkourone.com` portable while allowing typed code. Do NOT require 8.3 as a hard floor (host may lag). |
| WordPress | Min **6.9**, tested **7.0** | Host platform | WP 7.0 "Armstrong" shipped 2026-05-20; Woo 10.8 already requires WP 6.9. Set `Requires at least: 6.9` in the header. Custom REST routes, `dbDelta`, Application Passwords, and the Settings/AJAX APIs used here are all stable far below this. |
| WooCommerce | Min **8.2**, tested **10.8** | Conversion source (order status `probetraining`, order meta) | 8.2 is where **HPOS became default**. The plugin reads orders at conversion time, so it MUST be HPOS-safe (see below). Set `WC requires at least: 8.2`. |
| Custom DB table via `dbDelta()` | core | Time-series event store `wp_<prefix>_events` | The sign-off-gated deviation already approved in PROJECT.md. Correct call: visit/click volume + date-range GROUP BY queries are not performant on `options`/post-meta. This is the textbook "high-volume, time-filtered, transactional" case where a custom table is justified. |
| Plugin REST namespace `<prefix>/v1` | core REST API | (a) inbound visit/click ingest, (b) secured **pull** endpoint for the Statusboard | Custom `register_rest_route` with a real `permission_callback`. Two distinct auth postures — see Auth section. |

### REST API authentication — the central decision

The project has **two different REST surfaces** with **different threat models**. Do not use one mechanism for both.

| Surface | Caller | Recommended Auth | Why |
|---------|--------|------------------|-----|
| **Inbound** visit/click ingest (`POST <prefix>/v1/event`) | Browser JS on the public site | **WP REST nonce** (`wp_rest`) via `wp_localize_script` + `permission_callback` that calls `wp_verify_nonce`. Rate-limit + consent-gate. | Same-origin, anonymous public users. A nonce is the standard CSRF guard; it is NOT a secret (public site → anyone can mint one) so treat this endpoint as untrusted: validate/whitelist payload, cap row size, never trust client-supplied counts. |
| **Outbound pull** aggregates (`GET <prefix>/v1/metrics`) | Vercel cron (Next.js, machine) | **Shared Bearer secret** in `Authorization: Bearer <SECRET>`, verified in `permission_callback` with `hash_equals()` (constant-time) after a length check. Secret stored in a dedicated `get_option('<prefix>_pull_secret')`, minted via `wp_generate_password(64, false)`. | Mirrors the Statusboard's existing `CRON_SECRET` / `timingSafeEqual` pattern (CONTEXT-FINDINGS). Simplest correct M2M scheme for a single trusted consumer. No user, no role, no per-request token exchange. |

**Why Bearer-secret and NOT Application Passwords for the pull API (verified, MEDIUM→HIGH):**
- Application Passwords (WP 5.6+) authenticate **as a WordPress user via HTTP Basic Auth**. That means provisioning a dedicated user, and the pull endpoint would run inside full user context — more surface than a read-only aggregate feed needs. App Passwords are also frequently stripped by hosts/security plugins that block Basic Auth headers, and they are disabled over non-HTTPS. For a single Vercel cron pulling aggregates, that is overkill and more fragile.
- A shared Bearer secret + `hash_equals` is exactly the pattern the consumer already implements (`timingSafeEqual` on `CRON_SECRET`), so both sides stay symmetric, and it reuses the house `hash_hmac`/`hash_equals` primitive already in `helper-functions.php`.

**HMAC-signed requests — when to upgrade (LOW priority for v1):** HMAC-SHA256 over the request (path + timestamp) is *stronger* (replay-resistant if a timestamp/nonce window is added) but is unnecessary for a GET pull of non-sensitive aggregates over HTTPS. CONTEXT-FINDINGS already flags HMAC as "the stronger documented option." Recommendation: ship Bearer first; design the `permission_callback` so an HMAC header path can be added later without breaking the contract. Do NOT block v1 on it.

**Anti-pattern (explicit):** never `permission_callback => '__return_true'` (the documented sin in `class-ab-rest-endpoint.php`). Every route gets a real callback.

### HPOS compatibility (non-negotiable for a Woo plugin in 2026)

Declare compatibility on `before_woocommerce_init`:
```php
add_action( 'before_woocommerce_init', function () {
    if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );
```
And read/write orders via `wc_get_order()` + `$order->update_meta_data()` / `$order->get_meta()` / `$order->save()` — **never** `get_post()` / `get_post_meta()` on order IDs. HPOS is default since Woo 8.2, so order-meta attribution persistence MUST go through the CRUD API.

### Custom table schema (dbDelta) — prescriptive

```sql
CREATE TABLE {$wpdb->prefix}<prefix>_events (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_type  VARCHAR(20)     NOT NULL,         -- 'visit' | 'click' | 'booking'
  campaign    VARCHAR(191)    NOT NULL DEFAULT '',
  source      VARCHAR(100)    NOT NULL DEFAULT '',
  medium      VARCHAR(100)    NOT NULL DEFAULT '',
  landing_url VARCHAR(255)    NOT NULL DEFAULT '',
  order_id    BIGINT UNSIGNED NOT NULL DEFAULT 0,
  event_ref   BIGINT UNSIGNED NOT NULL DEFAULT 0,  -- _event_id / CPT for bookings
  session_id  VARCHAR(64)     NOT NULL DEFAULT '',
  created_at  DATETIME        NOT NULL,
  PRIMARY KEY  (id),
  KEY type_time (event_type, created_at),
  KEY campaign_time (campaign, created_at),
  KEY order_idx (order_id)
) {$charset_collate};
```
Rules (HIGH — from official `dbDelta` reference + WPVIP guidance):
- One column per line; `PRIMARY KEY` two spaces before `(`; `KEY name (col)` exact spacing — `dbDelta` parsing is whitespace-sensitive.
- Use `$wpdb->prefix` and `$wpdb->get_charset_collate()`.
- **Indices match real queries:** dashboard groups by `(event_type, created_at)` and `(campaign, created_at)` → those are the two composite keys. Conversion join uses `order_id`. Do not over-index.
- **Schema versioning:** store `get_option('<prefix>_db_version')`; on `plugins_loaded`/activation compare to a code constant, run `dbDelta` only when it changed.
- **NO PII / no raw IP.** `session_id` is the pseudonymous theme key; hash if it isn't already. Datensparsamkeit per DSGVO.
- **Retention/pruning (required, not optional):** a daily `wp_schedule_event` cron deletes rows older than N days (recommend **180 days** for raw events; keep aggregates longer if needed). Without pruning a high-traffic store bloats the table — flagged risk in CONTEXT-FINDINGS. Prune raw `visit`/`click` aggressively; `booking` rows are low-volume and can be kept longer or mirrored to order meta.

### Admin dashboard date-range UI — no framework, no build step

| Choice | Recommendation | Why |
|--------|----------------|-----|
| Date inputs | Native `<input type="date">` for from/to | Zero JS dependency, browser-native, accessible, respects locale. House style is plain HTML + jQuery; this fits. |
| Presets | Plain `<select>` (7/30/90 days, this month) that fills the two date fields | No datepicker library needed. |
| Avoid | jQuery UI Datepicker / flatpickr | Extra weight for a 2-field admin form. WP bundles jQuery UI but it is being deprecated; native inputs are the current best practice. |
| Refresh | `wp_ajax_<prefix>_metrics` + `check_ajax_referer` + cap `manage_options` + `wp_send_json_*` | Verbatim house pattern. Server runs the `$wpdb` GROUP BY for the chosen range. |

### Charting library — prescriptive

**Recommendation: ship v1 with NO charting library.** Render funnel metrics as the existing house pattern — `<table class="wp-list-table widefat fixed striped">` rows (visits / clicks / bookings / conversion-rate) plus hand-built CSS bar/funnel `<div>`s (the sibling plugins already do hand-built progress bars). This keeps the no-build-step constraint, adds zero third-party JS, and has no privacy/CDN exposure.

**If/when a trend chart is genuinely wanted later:** use **Chart.js v4 (~4.4)**, **bundled locally** in `assets/js/` (download the UMD build), enqueued with `wp_enqueue_script` deps. Reasons (MEDIUM):
- Chart.js is the lighter, canvas-based, MIT-licensed standard; v4 dropped the old `moment` dependency, so it's self-contained. ApexCharts is heavier (SVG, more features you don't need for a simple funnel/line).
- **Bundle, never CDN.** A CDN `<script>` in a German admin context leaks the admin's IP/UA to a third party (cdnjs/jsdelivr) on every dashboard load — a DSGVO concern and against the data-minimization posture of this project. Local bundle = no third-party request, version-pinned, works offline, no SRI fragility.
- Admin-only asset → gate enqueue on hook id `parkourone_page_<slug>` so it never loads on the front end.

**Do NOT** pull Chart.js from a CDN, and do NOT reach for ApexCharts/Highcharts/ECharts — Highcharts is non-free for commercial use, the others are overkill for three funnel numbers.

### UTM capture + first-party cookie — hand-roll it

**Recommendation: hand-rolled ~30-line vanilla JS, NOT a library (HIGH).**
- The need is tiny: on first visit, if `?utm_*` present and **`po_has_consent('analytics')` is true and visitor is not a logged-in admin**, read `utm_campaign/source/medium` + landing path, write a **first-touch** first-party cookie (e.g. `<prefix>_ft`, 90-day expiry, `SameSite=Lax`, `Secure`). Do not overwrite if already set (first-touch).
- At checkout, persist those values as **order meta via `woocommerce_checkout_create_order` / `woocommerce_checkout_update_order_meta`** using the CRUD API (HPOS-safe), bridging order → originating campaign.
- A library (AFL UTM Tracker, attribution.js, etc.) brings a build step, extra cookies set before consent, and storage you don't control — all DSGVO liabilities in a consent-gated German store. The house style already does delegated click tracking in vanilla JS; mirror it.
- The cookie is **non-essential / marketing** under GDPR → only set after `po_has_consent('analytics')`. This is the single biggest compliance constraint; reuse the theme's consent gate verbatim.

### Development Tools

| Tool | Purpose | Notes |
|------|---------|-------|
| **PHP_CodeSniffer (PHPCS) 3.13.x** + **WordPressCS 3.x** | Lint to WP Coding Standards | Composer is the ONLY supported install for WPCS 3.x. Install as dev-only; the shipped plugin stays Composer-free (no autoload, matching house style). Add `phpcs.xml.dist` with `<rule ref="WordPress"/>`, set `testVersion 8.1-`. |
| **PHPCompatibilityWP** | Catch PHP-version issues | Bundled in WPCS deps; confirms code runs on the 8.1 floor while you write 8.3-friendly code. |
| `composer.json` (dev) + `.gitignore` | Keep `vendor/`, `.git-version`, `.DS_Store` out of the repo | `vendor/` must not ship in the plugin zip. github-updater self-overwrites the dir, so dev tooling lives outside the distributed artifact. |
| Query Monitor (manual, dev) | Verify HPOS reads + slow `$wpdb` queries on the events table | Use to confirm the composite indices are actually hit on date-range queries. |

## Installation

```bash
# Dev-only tooling (NOT shipped in the plugin zip — house style is build-free)
composer require --dev squizlabs/php_codesniffer:"^3.13"
composer config allow-plugins.dealerdirect/phpcodesniffer-composer-installer true
composer require --dev wp-coding-standards/wpcs:"^3.0"

# Lint / autofix
./vendor/bin/phpcs  --standard=phpcs.xml.dist .
./vendor/bin/phpcbf --standard=phpcs.xml.dist .

# Charting (ONLY if a chart is later required — vendored, not via npm/CDN)
# download Chart.js v4 UMD build into assets/js/chart.umd.min.js manually
```

## Alternatives Considered

| Recommended | Alternative | When to Use Alternative |
|-------------|-------------|-------------------------|
| Bearer secret + `hash_equals` (pull API) | Application Passwords | If the consumer needed full WP user context / multiple endpoints with role checks. Not the case here. |
| Bearer secret | HMAC-SHA256 signed request | Upgrade if replay protection or untrusted-network concerns arise; add timestamp + window. Over-engineered for v1. |
| Custom `dbDelta` table | Reuse theme's `wp_po_analytics_events` | If the team prefers not to add a table and the theme table schema fits. But theme tracker is being retired → owning the schema is cleaner. |
| No chart (tables + CSS bars) | Chart.js v4 bundled | Add a chart only when a stakeholder explicitly asks for a trend line; ship without. |
| Native `<input type="date">` | flatpickr / jQuery UI datepicker | Only if a fancy range-picker UX is demanded; not worth a dependency for an internal admin tool. |
| Hand-rolled UTM JS | AFL UTM Tracker / attribution library | Never for this project — adds pre-consent cookies + build step, conflicts with DSGVO posture. |

## What NOT to Use

| Avoid | Why | Use Instead |
|-------|-----|-------------|
| `permission_callback => '__return_true'` | Documented house anti-pattern (`class-ab-rest-endpoint.php`); open endpoint = anyone reads campaign data | Real `permission_callback`: nonce (inbound) / Bearer+`hash_equals` (pull) |
| Plain `===` for secret compare | Timing-leak (the wizard.php:181 mistake) | `hash_equals()` after length check |
| Application Passwords for the pull API | Basic Auth as a WP user; often stripped by hosts; broader context than needed | Shared Bearer secret in an option |
| Chart.js / any JS from a CDN | Leaks admin IP/UA to a third party every dashboard load → DSGVO issue | Bundle locally in `assets/js/`, or no chart |
| ApexCharts / Highcharts / ECharts | Overkill for a 3-stage funnel; Highcharts non-free commercially; weight | Chart.js v4 bundled, or hand-built CSS bars |
| UTM/attribution npm library | Pre-consent cookies, build step, opaque storage | ~30 lines vanilla JS, consent-gated |
| `get_post()` / `get_post_meta()` on order IDs | Breaks under HPOS (default since Woo 8.2) | `wc_get_order()` + order CRUD meta methods |
| Hard PHP 8.3 floor | Host may run 8.1/8.2; locks out the store | Require 8.1, write 8.3-compatible, test the range |
| jQuery UI Datepicker | Deprecating, heavy, extra enqueue | Native `<input type="date">` |
| Shipping `vendor/` in the zip | github-updater self-overwrites the dir; bloat + risk | Composer is dev-only; `.gitignore` vendor/ |
| No retention/pruning on events table | DB bloat + slow date-range queries at visit volume | Daily cron prune (e.g. 180-day raw retention) |

## Stack Patterns by Variant

**If the host is stuck on PHP 7.4:**
- Lower `testVersion` to `7.4-` in PHPCS and avoid 8.0+ syntax (enums, named args, readonly).
- Still works, but flag it — 7.4 is EOL (Nov 2022). Push for 8.1+.

**If the Statusboard later wants per-event rows (currently out of scope / DSGVO-deferred):**
- Add an HMAC-signed, paginated `GET <prefix>/v1/events` route; keep aggregates as the default. Do NOT relax the no-raw-PII rule.

**If chart visualization is requested:**
- Chart.js v4 UMD, vendored to `assets/js/`, enqueued only on `parkourone_page_<slug>`, fed by the same AJAX JSON the table uses.

## Version Compatibility

| Package A | Compatible With | Notes |
|-----------|-----------------|-------|
| WordPress 7.0 | WooCommerce 10.8 | Current May 2026 baseline; Woo 10.8 needs WP ≥ 6.9. |
| WooCommerce 8.2+ | HPOS default | Must declare `custom_order_tables` compatibility + use order CRUD. |
| WordPressCS 3.x | PHP_CodeSniffer 3.13.x | Composer-only install; PHPCSUtils handles PHP 8.x parsing. WPCS 3.x supports PHPCS up to 4.x. |
| Chart.js 4.4 | none (self-contained) | v4 removed the moment.js dependency; safe to bundle standalone. |
| PHP 8.1 (floor) | WP 7.0 / Woo 10.8 | Both run on 8.1; 8.3 recommended. Test 8.1 + 8.3. |

## Sources

- developer.wordpress.org/rest-api/extending-the-rest-api/adding-custom-endpoints/ — `permission_callback` contract (HIGH)
- developer.wordpress.org/reference/functions/dbdelta/ + docs.wpvip.com/databases/custom-tables/ — dbDelta spacing rules, indexing-to-queries guidance (HIGH)
- developer.woocommerce.com (HPOS docs + 10.8.0 release 2026-05-26) — HPOS default since 8.2, `FeaturesUtil::declare_compatibility`, `wc_get_order` CRUD (HIGH)
- woocommerce.com/document/server-requirements/ + update-php-wordpress/ — PHP 8.1 min-safe / 8.3 recommended (HIGH)
- make.wordpress.org/core/7-0/ + wordpress.org/news (WP 7.0 "Armstrong", 2026-05-20) — current WP version baseline (HIGH)
- github.com/WordPress/WordPress-Coding-Standards + packagist wp-coding-standards/wpcs — WPCS 3.x Composer-only, PHPCS 3.13 compat (HIGH/MEDIUM)
- Chart.js v4 docs (no-moment, MIT, canvas) — charting choice (MEDIUM)
- UTM/GDPR consent sources (trackfunnels, voxxy) — marketing cookies are non-essential, require consent (MEDIUM)
- WP REST auth guides (developer.wordpress.org/rest-api/.../authentication, oddjar 2025) — App Passwords = Basic Auth as user, server-side only (MEDIUM)
- CONTEXT-FINDINGS.md — house style, Statusboard auth pattern, anti-patterns (constraint, HIGH)

---
*Stack research for: WordPress/WooCommerce funnel-tracking plugin with secured pull-API*
*Researched: 2026-05-31*
