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

    public static function init() {
        // Priority 1000 so POT_Admin (999) has already registered the fallback standalone
        // parent (POT_Admin::MENU_SLUG) by the time we resolve our parent — the page is NEVER
        // orphaned (falls back to the standalone Campaign-Tracking top menu).
        add_action('admin_menu', [__CLASS__, 'add_menu_page'], 1000);
        add_action('admin_post_pot_save_landing_pages', [__CLASS__, 'handle_save']);
    }

    public static function add_menu_page() {
        // Register under the shared `parkourone` parent if it exists; otherwise hang under the
        // standalone Campaign-Tracking top menu POT_Admin created in its own fallback (999) —
        // so the page is NEVER orphaned. Only the parent slug differs between the branches.
        if (!empty($GLOBALS['admin_page_hooks']['parkourone'])) {
            add_submenu_page(
                'parkourone',
                'Landingpages',
                'Landingpages',
                'manage_options',
                self::PAGE_SLUG,
                [__CLASS__, 'render_page']
            );
        } else {
            add_submenu_page(
                POT_Admin::MENU_SLUG,
                'Landingpages',
                'Landingpages',
                'manage_options',
                self::PAGE_SLUG,
                [__CLASS__, 'render_page']
            );
        }
    }

    public static function render_page() {
        // Capability re-check before any output (T-06-02).
        if (!current_user_can('manage_options')) {
            return;
        }

        $entries = get_option(self::OPTION, []);
        $entries = is_array($entries) ? $entries : [];
        ?>
        <div class="wrap">
            <h1><?php echo esc_html('Landingpages'); ?></h1>

            <?php if (isset($_GET['pot_saved']) && $_GET['pot_saved'] === '1') : ?>
                <div class="notice notice-success is-dismissible"><p>
                    <?php echo esc_html('Landingpages gespeichert.'); ?>
                </p></div>
            <?php endif; ?>

            <p class="description">
                <?php echo esc_html('Nur hier registrierte Landingpages werden getrackt. Besuche/Klicks auf nicht gelistete Pfade werden serverseitig verworfen. Vollständige URL einfügen — gespeichert und verglichen wird der normalisierte Pfad (siehe Spalte rechts).'); ?>
            </p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="pot_save_landing_pages" />
                <?php wp_nonce_field(self::NONCE_NAME); ?>

                <table class="form-table" role="presentation">
                    <thead>
                        <tr>
                            <th scope="col"><?php echo esc_html('URL oder Pfad'); ?></th>
                            <th scope="col"><?php echo esc_html('Bezeichnung (optional)'); ?></th>
                            <th scope="col"><?php echo esc_html('Normalisierter Schlüssel'); ?></th>
                            <th scope="col"><?php echo esc_html('Aktion'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="pot-lp-rows">
                        <?php foreach ($entries as $entry) :
                            $key   = isset($entry['key']) ? (string) $entry['key'] : '';
                            $label = isset($entry['label']) ? (string) $entry['label'] : '';
                            ?>
                            <tr class="pot-lp-row">
                                <td>
                                    <input type="text" class="regular-text code" name="pot_lp_url[]"
                                           value="<?php echo esc_attr($key); ?>"
                                           placeholder="https://berlin.parkourone.com/lp/…" />
                                </td>
                                <td>
                                    <input type="text" class="regular-text" name="pot_lp_label[]"
                                           value="<?php echo esc_attr($label); ?>"
                                           maxlength="<?php echo esc_attr((string) self::LABEL_MAX); ?>" />
                                </td>
                                <td>
                                    <code><?php echo esc_html($key); ?></code>
                                </td>
                                <td>
                                    <button type="button" class="button pot-lp-remove"><?php echo esc_html('Entfernen'); ?></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="pot-lp-row">
                            <td>
                                <input type="text" class="regular-text code" name="pot_lp_url[]"
                                       value="" placeholder="https://berlin.parkourone.com/lp/…" />
                            </td>
                            <td>
                                <input type="text" class="regular-text" name="pot_lp_label[]"
                                       value="" maxlength="<?php echo esc_attr((string) self::LABEL_MAX); ?>" />
                            </td>
                            <td>
                                <span class="description"><?php echo esc_html('— neu —'); ?></span>
                            </td>
                            <td>
                                <button type="button" class="button pot-lp-remove"><?php echo esc_html('Entfernen'); ?></button>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <p>
                    <button type="button" class="button" id="pot-lp-add">
                        <?php echo esc_html('Zeile hinzufügen'); ?>
                    </button>
                </p>

                <p class="description">
                    <?php echo esc_html('Eintrag entfernen: auf „Entfernen“ klicken und anschließend speichern. Bearbeiten geht weiterhin direkt über die Felder.'); ?>
                </p>

                <?php submit_button('Speichern'); ?>
            </form>
        </div>
        <?php
        // Dependency-free progressive enhancement: add cleared rows + per-row delete.
        // No build step, no jQuery, no CDN — vanilla JS only. The server-rendered blank
        // add-row + the (now bugfixed) save handler make the page work without JS too.
        ?>
        <script>
        (function () {
            var btn  = document.getElementById('pot-lp-add');
            var body = document.getElementById('pot-lp-rows');
            if (!btn || !body) { return; }

            // Capture a CLEAN template ONCE at init from the first row, then clear it.
            // Storing the cleared template up front means "Zeile hinzufügen" keeps working
            // even after the user has removed EVERY live row (no reliance on live DOM).
            var seed = body.querySelector('tr.pot-lp-row');
            var template = seed ? seed.cloneNode(true) : null;
            if (template) {
                template.querySelectorAll('input').forEach(function (el) { el.value = ''; });
                var seedKey = template.querySelector('code');
                if (seedKey) { seedKey.textContent = ''; }
            }

            btn.addEventListener('click', function () {
                if (!template) { return; }
                var clone = template.cloneNode(true);
                // Re-clear on each append to be safe.
                clone.querySelectorAll('input').forEach(function (el) { el.value = ''; });
                var key = clone.querySelector('code');
                if (key) { key.textContent = ''; }
                body.appendChild(clone);
            });

            // Delegated remove: a DOM-removed row is absent from $_POST, so the next save
            // rebuilds the allowlist without it — no server-side delete logic needed.
            body.addEventListener('click', function (e) {
                var hit = e.target.closest('.pot-lp-remove');
                if (!hit) { return; }
                var row = hit.closest('tr.pot-lp-row');
                if (row) { row.remove(); }
            });
        })();
        </script>
        <?php
    }

    /**
     * Persist the allowlist. CSRF + capability gated. NEVER persists raw $_POST — always a
     * clean rebuilt list: skip truly-empty raw rows before normalization (D-04), normalize each
     * remaining URL → non-empty key, sanitize + cap each
     * label, first-wins dedupe by key (registered order stable, D-02/D-06), cap at MAX_PAGES,
     * autoload=false (T-06-01/T-06-03).
     */
    public static function handle_save() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer(self::NONCE_NAME);

        $urls   = isset($_POST['pot_lp_url'])   ? (array) wp_unslash($_POST['pot_lp_url'])   : [];
        $labels = isset($_POST['pot_lp_label']) ? (array) wp_unslash($_POST['pot_lp_label']) : [];

        $entries = []; // key => label (assoc dedupes by key automatically; first-wins).
        foreach ($urls as $i => $raw) {
            // Drop truly-empty raw rows BEFORE normalize. normalize_path('') returns '/'
            // (root is registrable), so an empty field must be skipped here — otherwise the
            // always-present blank "neu" row, and any cleared field, would inject a '/' entry
            // on every save. An explicit '/' is NOT empty (trim('/') === '/') and still passes.
            if (trim((string) $raw) === '') {
                continue;
            }
            $key = self::normalize_path($raw);
            if ($key === '') {
                continue; // Unreachable in practice: empties are dropped above and normalize_path always returns a non-empty key. Left as a defensive belt.
            }
            $label = isset($labels[$i])
                ? substr(sanitize_text_field((string) $labels[$i]), 0, self::LABEL_MAX)
                : '';
            if (!isset($entries[$key])) {
                $entries[$key] = $label; // First-wins dedupe; preserves registered order (D-02/D-06).
            }
            if (count($entries) >= self::MAX_PAGES) {
                break; // Cap entries (T-06-03 option-injection guard).
            }
        }

        // Re-shape to an ordered list (preserve insertion order for D-02).
        $list = [];
        foreach ($entries as $key => $label) {
            $list[] = ['key' => $key, 'label' => $label];
        }

        update_option(self::OPTION, $list, false); // autoload=false (Pitfall 11).

        wp_safe_redirect(
            add_query_arg(
                'pot_saved',
                '1',
                wp_get_referer() ?: admin_url('admin.php?page=' . self::PAGE_SLUG)
            )
        );
        exit;
    }

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
