<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * POT_Landing_Pages — the hard-allowlist surface for landing-page tracking (Phase 6).
 *
 * Owns two things:
 *  (1) normalize_path() — the SINGLE shared path-normalization primitive (LP-02). It maps any
 *      full URL or bare path to a canonical, lowercased path key (scheme/host/query/fragment
 *      stripped, collapsed slashes, single trailing slash removed except root). It is called
 *      IDENTICALLY by the settings save handler here and by POT_Ingest::handle_event's gate
 *      (Plan 06-02). If save and match ever diverge, the hard gate silently drops valid
 *      traffic — defining it once, in one public static, is the entire mitigation.
 *  (2) The allowlist settings page + pot_landing_pages option CRUD (LP-01). A submenu under
 *      the theme-owned `parkourone` menu (cap manage_options) lets an admin paste full landing
 *      URLs + optional labels, add/remove rows, and save. Save is CSRF-protected
 *      (check_admin_referer) and capability-gated; the persisted list is a clean, normalized,
 *      deduped, capped rebuild — never the raw $_POST.
 *
 * The option is stored autoload=false (read on every ingest from the object cache, not a DB
 * query per event). No new PII is stored — the option holds admin-entered paths/labels only.
 */
class POT_Landing_Pages {

    const PAGE_SLUG  = 'pot-landing-pages';
    const OPTION     = 'pot_landing_pages';
    const NONCE_NAME = 'pot_save_landing_pages';
    const MAX_PAGES  = 100;   // Sane cap; the ingest gate reads this small array per event.
    const LABEL_MAX  = 100;

    /**
     * Normalize a full URL or bare path to a canonical key.
     *
     * Pipeline (LP-02 / RESEARCH Pattern 2):
     *  - trim to string; empty input → '/' (root is registrable, D-04)
     *  - take only the PATH component (handles full URL OR bare path; host/scheme dropped)
     *  - rawurldecode (%2F, %C3%A4 → real chars) before normalizing
     *  - mb_strtolower (case-insensitive match, D-03 — NOT strtolower; multibyte-safe)
     *  - collapse repeated slashes ('//' → '/')
     *  - guarantee a single leading slash
     *  - strip trailing slash, but keep root as '/'
     *
     * Does NOT resolve '.'/'..' segments (out of scope — admins paste canonical URLs and the
     * settings page shows them the resulting key as confirmation, D-05).
     *
     * Acceptance (SPEC LP-02 + root + double-slash):
     *  normalize_path('https://berlin.parkourone.com/lp/x/?utm_source=fb') === '/lp/x'
     *  normalize_path('/lp/x/')   === '/lp/x'
     *  normalize_path('/lp/x')    === '/lp/x'
     *  normalize_path('/LP/X/')   === '/lp/x'
     *  normalize_path('')         === '/'
     *  normalize_path('/')        === '/'
     *  normalize_path('//lp//x')  === '/lp/x'
     *  normalize_path('/lp/x#frag') === '/lp/x'
     *
     * @param mixed $input Full URL or bare path (untrusted).
     * @return string Canonical lowercased path key (always non-empty; '/' for root).
     */
    public static function normalize_path($input) {
        $input = trim((string) $input);
        if ($input === '') {
            return '/'; // D-04: empty input normalizes to root.
        }

        // Take only the path component. Handles full URLs ('https://host/p?q#f'),
        // protocol-relative ('//host/p'), and bare paths ('/p'). Query + fragment are
        // dropped because PHP_URL_PATH excludes them.
        $path = wp_parse_url($input, PHP_URL_PATH);
        if ($path === null || $path === false) {
            $path = $input; // Bare path with no parseable structure — use as-is.
        }

        $path = rawurldecode($path);            // Decode percent-encoding before matching.
        $path = mb_strtolower($path, 'UTF-8');  // D-03: case-insensitive, multibyte-safe.
        $path = preg_replace('#/{2,}#', '/', $path); // Collapse '//' → '/'.

        if ($path === '') {
            return '/';
        }
        if ($path[0] !== '/') {
            $path = '/' . $path; // Guarantee a single leading slash.
        }

        $path = rtrim($path, '/'); // Normalize the trailing slash.

        return $path === '' ? '/' : $path; // Root stays '/' (D-04).
    }
}
