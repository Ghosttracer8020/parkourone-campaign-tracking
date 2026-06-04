<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * POT_Admin — the per-landing-page funnel dashboard under the theme-owned `parkourone`
 * top-level menu.
 *
 * Mirrors class-ab-customer-overview.php (submenu under parkourone, cap manage_options,
 * <div class="wrap"><h1>, hook-gated enqueue + wp_localize_script, wp_ajax_* handler) and
 * borrows the wp_send_json_* envelope from class-ab-gutschein-settings.php. The fallback
 * top-level page (theme inactive) is a deliberate improvement over the analog.
 *
 * CONTRACT: ALL aggregation lives in POT_Store (here: aggregate_by_landing_page, scoped to
 * the registered pot_landing_pages allowlist). This class contains NO SQL and never touches
 * $wpdb. The local-day→UTC conversion is the CALLER's job (here), not the gateway's — the
 * gateway expects already-UTC 'Y-m-d H:i:s' bounds. The Phase 5 pull-API (POT_Api) stays on
 * the per-campaign aggregation and is intentionally NOT touched by this dashboard (LP-07).
 */
class POT_Admin {

    const MENU_SLUG = 'parkourone-campaign-tracking';

    /** Default preset when none/unknown is supplied. */
    const DEFAULT_PRESET = '30t';

    /** Maximum span (in days) a custom range may cover. */
    const MAX_SPAN_DAYS = 366;

    /** Number of table columns (for empty-state colspan). */
    const COL_COUNT = 7;

    public static function init() {
        // Priority 999 so the theme/sibling-owned `parkourone` top-level menu is
        // registered before our check runs — otherwise the fallback could add a
        // duplicate standalone page even when the parent exists (WR-01).
        add_action('admin_menu', [__CLASS__, 'add_menu_page'], 999);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin_scripts']);
        // Authenticated admins only — NO wp_ajax_nopriv_pot_metrics is registered.
        add_action('wp_ajax_pot_metrics', [__CLASS__, 'ajax_metrics']);
    }

    public static function add_menu_page() {
        // Register under the shared `parkourone` parent if it exists; otherwise add a
        // top-level page so the dashboard is never orphaned (CONTEXT.md fallback).
        if (!empty($GLOBALS['admin_page_hooks']['parkourone'])) {
            add_submenu_page(
                'parkourone',
                'Campaign Tracking',
                'Campaign Tracking',
                'manage_options',
                self::MENU_SLUG,
                [__CLASS__, 'render_page']
            );
        } else {
            add_menu_page(
                'Campaign Tracking',
                'Campaign Tracking',
                'manage_options',
                self::MENU_SLUG,
                [__CLASS__, 'render_page'],
                'dashicons-chart-line'
            );
        }
    }

    /**
     * Hook-gated enqueue + localize. Accept BOTH page hooks: the parkourone-parent path
     * yields `parkourone_page_<slug>`, the fallback add_menu_page yields `toplevel_page_<slug>`.
     */
    public static function enqueue_admin_scripts($hook) {
        if ($hook !== 'parkourone_page_' . self::MENU_SLUG
            && $hook !== 'toplevel_page_' . self::MENU_SLUG) {
            return;
        }

        wp_enqueue_script(
            'pot-dashboard',
            plugins_url('assets/js/pot-dashboard.js', dirname(__FILE__)),
            ['jquery'],
            '1.0.0',
            true
        );

        wp_localize_script('pot-dashboard', 'potDashboard', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('pot_metrics'),
        ]);

        wp_enqueue_style(
            'pot-dashboard',
            plugins_url('assets/css/pot-dashboard.css', dirname(__FILE__)),
            [],
            '1.0.0'
        );
    }

    // ---------------------------------------------------------------------
    // Metric / range helpers (NO SQL — all aggregation stays in POT_Store).
    // ---------------------------------------------------------------------

    /**
     * Percentage with a divide-by-zero guard. Returns '–' when the denominator is 0,
     * else number_format_i18n(pct, 1) . ' %' (German locale per house style).
     *
     * Delegates the numeric computation to POT_Metrics::rate_value() — the SINGLE rate
     * source shared with POT_Api so dashboard and API numbers cannot drift. This method
     * only formats the result into the German display string (no behavioral change).
     *
     * @param int|float $num Numerator (e.g. bookings).
     * @param int|float $den Denominator (e.g. visits).
     * @return string
     */
    private static function rate($num, $den) {
        $value = POT_Metrics::rate_value($num, $den);
        if ($value === null) {
            return '–';
        }
        return number_format_i18n($value, 1) . ' %';
    }

    /**
     * Sanitize raw preset/from/to input (GET render + AJAX POST share this).
     *
     * - preset via sanitize_key (defaults handled in resolve_range)
     * - from/to must match /^\d{4}-\d{2}-\d{2}$/, else dropped to null
     * - future dates clamped to today (site-local)
     * - from>to swapped so from<=to always holds
     * - span capped at MAX_SPAN_DAYS (pull `from` forward toward `to`)
     *
     * @return array{preset:string,from:?string,to:?string}
     */
    private static function sanitize_range_input($preset_raw, $from_raw, $to_raw) {
        $preset = sanitize_key((string) $preset_raw);

        $from = self::valid_date($from_raw);
        $to   = self::valid_date($to_raw);

        // Clamp future dates to today (site-local).
        $today = current_time('Y-m-d');
        if ($from !== null && $from > $today) {
            $from = $today;
        }
        if ($to !== null && $to > $today) {
            $to = $today;
        }

        // Enforce from <= to (swap when both present and reversed).
        if ($from !== null && $to !== null && $from > $to) {
            $tmp  = $from;
            $from = $to;
            $to   = $tmp;
        }

        // Cap the span at MAX_SPAN_DAYS — pull `from` forward toward `to`.
        if ($from !== null && $to !== null) {
            $tz       = wp_timezone();
            $from_dt  = DateTime::createFromFormat('Y-m-d', $from, $tz);
            $to_dt    = DateTime::createFromFormat('Y-m-d', $to, $tz);
            if ($from_dt && $to_dt) {
                $span = (int) $from_dt->diff($to_dt)->days;
                if ($span > self::MAX_SPAN_DAYS) {
                    error_log('[POT Tracking] custom range span ' . $span . 'd exceeds cap; clamping to ' . self::MAX_SPAN_DAYS . 'd');
                    $capped = clone $to_dt;
                    $capped->modify('-' . self::MAX_SPAN_DAYS . ' days');
                    $from = $capped->format('Y-m-d');
                }
            }
        }

        return ['preset' => $preset, 'from' => $from, 'to' => $to];
    }

    /** Return $value when it is a 'Y-m-d' string, else null. */
    private static function valid_date($value) {
        $value = (string) $value;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value;
        }
        return null;
    }

    /**
     * Resolve a (preset, from, to) triple into UTC 'Y-m-d H:i:s' bounds plus an
     * echo-friendly local range label. The conversion is the CALLER's job — it must NEVER
     * be pushed into the gateway, which expects already-UTC bounds.
     *
     * Presets: 'heute' / '7t' / '30t' / 'benutzerdefiniert' (default '30t').
     *
     * @return array{from_utc:string,to_utc:string,label:string,preset:string,from:string,to:string}
     */
    private static function resolve_range($preset, $from, $to) {
        $tz = wp_timezone();

        $allowed = ['heute', '7t', '30t', 'benutzerdefiniert'];
        if (!in_array($preset, $allowed, true)) {
            $preset = self::DEFAULT_PRESET;
        }

        $today = new DateTime('now', $tz);
        $today->setTime(0, 0, 0);

        if ($preset === 'benutzerdefiniert' && $from !== null && $to !== null) {
            $local_from = DateTime::createFromFormat('Y-m-d', $from, $tz);
            $local_to   = DateTime::createFromFormat('Y-m-d', $to, $tz);
        } else {
            // Preset windows end today; compute the lower bound by days-back.
            $days_back = 0;
            if ($preset === '7t') {
                $days_back = 6; // last 7 calendar days incl. today
            } elseif ($preset === '30t') {
                $days_back = 29; // last 30 calendar days incl. today
            }
            // For unknown/empty presets we already defaulted to 30t above.
            if ($preset !== 'heute' && $preset !== '7t' && $preset !== '30t') {
                $days_back = 29;
                $preset    = self::DEFAULT_PRESET;
            }
            $local_to   = clone $today;
            $local_from = clone $today;
            if ($days_back > 0) {
                $local_from->modify('-' . $days_back . ' days');
            }
        }

        // Fallback if a custom date failed to parse — default to 30t window.
        if (!$local_from || !$local_to) {
            error_log('[POT Tracking] resolve_range fell back to default window (bad custom dates)');
            $preset     = self::DEFAULT_PRESET;
            $local_to   = clone $today;
            $local_from = clone $today;
            $local_from->modify('-29 days');
        }

        // Local day boundaries: lower at 00:00:00, upper at 23:59:59 (site-local).
        $local_from->setTime(0, 0, 0);
        $local_to->setTime(23, 59, 59);

        $label = $local_from->format('d.m.Y') . ' – ' . $local_to->format('d.m.Y');

        // Convert LOCAL boundaries → UTC for the gateway. Phase 5 API MUST use the
        // identical conversion so dashboard and API agree.
        $utc = new DateTimeZone('UTC');
        $utc_from_dt = clone $local_from;
        $utc_to_dt   = clone $local_to;
        $utc_from_dt->setTimezone($utc);
        $utc_to_dt->setTimezone($utc);

        return [
            'from_utc' => $utc_from_dt->format('Y-m-d H:i:s'),
            'to_utc'   => $utc_to_dt->format('Y-m-d H:i:s'),
            'label'    => $label,
            'preset'   => $preset,
            'from'     => $local_from->format('Y-m-d'),
            'to'       => $local_to->format('Y-m-d'),
        ];
    }

    // ---------------------------------------------------------------------
    // Shared row builder (server render AND AJAX use this — byte-identical).
    // ---------------------------------------------------------------------

    /**
     * Build the per-landing-page data set for the given UTC window.
     *
     * Reads the registered allowlist (pot_landing_pages, autoload=false), asks the gateway
     * for the listed-only aggregate, then PHP-zero-fills one row per registered page IN
     * REGISTERED ORDER (D-02) so every listed page shows a row even with zero activity
     * (D-09). No `(unattributed)` bucket. NO SQL here — all aggregation stays in POT_Store.
     *
     * Returns [] when no pages are registered (render_rows then shows the empty-allowlist
     * state). Byte-identical source for render_page() and ajax_metrics().
     *
     * @param string $from_utc UTC 'Y-m-d H:i:s' lower bound.
     * @param string $to_utc   UTC 'Y-m-d H:i:s' upper bound.
     * @return array<int,array{key:string,label:string,visits:int,clicks:int,bookings:int}>
     */
    private static function landing_page_rows($from_utc, $to_utc) {
        $entries = get_option('pot_landing_pages', []);
        $entries = is_array($entries) ? $entries : [];
        $keys    = wp_list_pluck($entries, 'key');

        $raw = POT_Store::aggregate_by_landing_page($keys, $from_utc, $to_utc);

        // Index gateway rows by their canonical landing_path key.
        $by_key = [];
        foreach ($raw as $r) {
            if (isset($r['landing_path'])) {
                $by_key[(string) $r['landing_path']] = $r;
            }
        }

        // Zero-fill one row per registered page, in registered order (D-02/D-09).
        $data = [];
        foreach ($entries as $e) {
            $key   = isset($e['key']) ? (string) $e['key'] : '';
            $label = isset($e['label']) ? (string) $e['label'] : '';
            $hit   = $by_key[$key] ?? [];
            $data[] = [
                'key'      => $key,
                'label'    => $label,
                'visits'   => (int) ($hit['visits']   ?? 0),
                'clicks'   => (int) ($hit['clicks']   ?? 0),
                'bookings' => (int) ($hit['bookings'] ?? 0),
            ];
        }

        return $data;
    }

    /**
     * Build the <tbody> rows for the per-landing-page table from zero-filled rows.
     * Rows arrive in registered (admin-listed) order — NO performance sort (D-02). The first
     * cell is a two-line label + muted-path cell (D-01); when an entry has no label only the
     * path is shown. Every cell is escaped. Impossible-funnel cells (clicks>visits OR
     * bookings>clicks) carry a dashicons-warning marker; the row is never hidden.
     *
     * An empty input means NO landing pages are registered (zero-fill always emits a row per
     * listed page), so the empty branch renders the distinct empty-ALLOWLIST state — "register
     * pages", NOT "no data in range".
     *
     * @param array<int,array{key:string,label:string,visits:int,clicks:int,bookings:int}> $rows
     * @return string Escaped <tr>… markup.
     */
    private static function render_rows($rows) {
        if (empty($rows)) {
            // Distinct empty-allowlist state (LP-06): no pages registered, not "no data in range".
            return '<tr><td colspan="' . self::COL_COUNT . '">'
                . esc_html('Keine Landingpages registriert — bitte unter Einstellungen → Landingpages anlegen.')
                . '</td></tr>';
        }

        $output = '';
        foreach ($rows as $row) {
            $key      = isset($row['key']) ? (string) $row['key'] : '';
            $label    = isset($row['label']) ? (string) $row['label'] : '';
            $visits   = isset($row['visits']) ? (int) $row['visits'] : 0;
            $clicks   = isset($row['clicks']) ? (int) $row['clicks'] : 0;
            $bookings = isset($row['bookings']) ? (int) $row['bookings'] : 0;

            // Two-line label+path cell (D-01): escape EACH piece, then concatenate. The
            // assembled HTML is passed as %s WITHOUT re-escaping — re-escaping would render
            // the <br>/<span> as literal text (RESEARCH Anti-Pattern). Path-only when no label.
            $first = ($label !== '')
                ? esc_html($label) . '<br><span class="pot-lp-path description">' . esc_html($key) . '</span>'
                : esc_html($key);

            // Impossible-funnel markers (do NOT hide the row).
            $visit_click_warn = ($clicks > $visits)
                ? self::warning_marker('Unplausibel: mehr Klicks als Visits')
                : '';
            $click_booking_warn = ($bookings > $clicks)
                ? self::warning_marker('Unplausibel: mehr Buchungen als Klicks')
                : '';

            $output .= sprintf(
                '<tr>'
                . '<td>%s</td>'
                . '<td>%s</td>'
                . '<td>%s</td>'
                . '<td>%s</td>'
                . '<td>%s</td>'
                . '<td>%s%s</td>'
                . '<td>%s%s</td>'
                . '</tr>',
                $first,                                       // already-escaped pieces — NOT re-escaped
                esc_html(number_format_i18n($visits)),
                esc_html(number_format_i18n($clicks)),
                esc_html(number_format_i18n($bookings)),
                esc_html(self::rate($bookings, $visits)),     // Conversion-Rate = bookings/visits
                esc_html(self::rate($clicks, $visits)),       // Visit→Klick = clicks/visits
                $visit_click_warn,
                esc_html(self::rate($bookings, $clicks)),     // Klick→Buchung = bookings/clicks
                $click_booking_warn
            );
        }

        return $output;
    }

    /**
     * Build the totals/summary row from gateway rows (sum across all campaigns).
     *
     * @param array<int,array<string,mixed>> $rows
     * @return string Escaped <tr>… markup.
     */
    private static function render_totals($rows) {
        $visits = $clicks = $bookings = 0;
        foreach ($rows as $row) {
            $visits   += isset($row['visits']) ? (int) $row['visits'] : 0;
            $clicks   += isset($row['clicks']) ? (int) $row['clicks'] : 0;
            $bookings += isset($row['bookings']) ? (int) $row['bookings'] : 0;
        }

        return sprintf(
            '<tr class="pot-totals">'
            . '<th scope="row">%s</th>'
            . '<td>%s</td>'
            . '<td>%s</td>'
            . '<td>%s</td>'
            . '<td>%s</td>'
            . '<td>%s</td>'
            . '<td>%s</td>'
            . '</tr>',
            esc_html('Gesamt'),
            esc_html(number_format_i18n($visits)),
            esc_html(number_format_i18n($clicks)),
            esc_html(number_format_i18n($bookings)),
            esc_html(self::rate($bookings, $visits)),
            esc_html(self::rate($clicks, $visits)),
            esc_html(self::rate($bookings, $clicks))
        );
    }

    /** A dashicons-warning marker with an esc_attr'd title; never replaces the value. */
    private static function warning_marker($title) {
        return ' <span class="dashicons dashicons-warning pot-warning" title="'
            . esc_attr($title) . '"></span>';
    }

    /**
     * Build the health-banner markup, or '' when conversion tracking is OK.
     * Driven by the pot_conversion_status option set in Phase 2.
     *
     * @return string
     */
    private static function render_health_banner() {
        $status = get_option('pot_conversion_status', 'not_configured');
        if ($status === 'ok') {
            return '';
        }
        return '<div class="notice notice-error pot-health-banner"><p>'
            . esc_html('Conversion-Tracking ist offline — ist das Plugin ab-webhook-endpoint aktiv? Buchungen werden derzeit nicht gezählt.')
            . '</p></div>';
    }

    /** Current health status slug for the AJAX payload. */
    private static function health_status() {
        return (string) get_option('pot_conversion_status', 'not_configured');
    }

    // ---------------------------------------------------------------------
    // Page render (server-side first — works without JS).
    // ---------------------------------------------------------------------

    public static function render_page() {
        // Capability re-check before any output (D-locked manage_options gate).
        if (!current_user_can('manage_options')) {
            return;
        }

        // Read + sanitize GET input, then resolve the UTC window.
        $preset_raw = isset($_GET['preset']) ? wp_unslash($_GET['preset']) : '';
        $from_raw   = isset($_GET['from']) ? wp_unslash($_GET['from']) : '';
        $to_raw     = isset($_GET['to']) ? wp_unslash($_GET['to']) : '';

        $clean = self::sanitize_range_input($preset_raw, $from_raw, $to_raw);
        $range = self::resolve_range($clean['preset'], $clean['from'], $clean['to']);

        // Per-landing-page data: aggregate the listed keys, then zero-fill in registered
        // order so every listed page has a row even with no activity (LP-06, D-02/D-09).
        $data = self::landing_page_rows($range['from_utc'], $range['to_utc']);

        $rows_html   = self::render_rows($data);
        $totals_html = self::render_totals($data);
        $banner_html = self::render_health_banner();

        $presets = [
            'heute'             => 'Heute',
            '7t'                => '7 Tage',
            '30t'               => '30 Tage',
            'benutzerdefiniert' => 'Benutzerdefiniert',
        ];
        $active_preset = $range['preset'];
        ?>
        <div class="wrap">
            <h1><?php echo esc_html('Campaign Tracking'); ?></h1>

            <?php
            // Health banner — only when conversion tracking is not OK.
            echo $banner_html; // already escaped in render_health_banner()
            ?>

            <form id="pot-dashboard-controls" class="pot-control-bar" method="get">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::MENU_SLUG); ?>" />
                <label for="pot-preset"><?php echo esc_html('Zeitraum'); ?></label>
                <select id="pot-preset" name="preset">
                    <?php foreach ($presets as $value => $label) : ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($active_preset, $value); ?>>
                            <?php echo esc_html($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="pot-custom-dates"<?php echo $active_preset === 'benutzerdefiniert' ? '' : ' style="display:none;"'; ?>>
                    <label for="pot-from"><?php echo esc_html('Von'); ?></label>
                    <input type="date" id="pot-from" name="from" value="<?php echo esc_attr($range['from']); ?>" />
                    <label for="pot-to"><?php echo esc_html('Bis'); ?></label>
                    <input type="date" id="pot-to" name="to" value="<?php echo esc_attr($range['to']); ?>" />
                </span>
                <button type="submit" id="pot-refresh" class="button button-primary"><?php echo esc_html('Aktualisieren'); ?></button>
                <span class="spinner" id="pot-spinner"></span>
                <span class="pot-range-label" id="pot-range-label"><?php echo esc_html($range['label']); ?></span>
            </form>

            <table class="wp-list-table widefat fixed striped pot-metrics-table">
                <thead>
                    <tr>
                        <th scope="col"><?php echo esc_html('Landingpage'); ?></th>
                        <th scope="col"><?php echo esc_html('Visits'); ?></th>
                        <th scope="col"><?php echo esc_html('Klicks'); ?></th>
                        <th scope="col"><?php echo esc_html('Buchungen'); ?></th>
                        <th scope="col"><?php echo esc_html('Conversion-Rate'); ?></th>
                        <th scope="col"><?php echo esc_html('Visit→Klick'); ?></th>
                        <th scope="col"><?php echo esc_html('Klick→Buchung'); ?></th>
                    </tr>
                </thead>
                <tbody id="pot-metrics-body">
                    <?php echo $rows_html; // escaped in render_rows() ?>
                </tbody>
                <tfoot id="pot-metrics-foot">
                    <?php echo $totals_html; // escaped in render_totals() ?>
                </tfoot>
            </table>
        </div>
        <?php
    }

    // ---------------------------------------------------------------------
    // AJAX handler — wp_ajax_pot_metrics (no nopriv).
    // ---------------------------------------------------------------------

    public static function ajax_metrics() {
        // Verify nonce FIRST (sent under the 'nonce' key by the JS).
        check_ajax_referer('pot_metrics', 'nonce');

        // Capability gate.
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Keine Berechtigung']);
            return;
        }

        $preset_raw = isset($_POST['preset']) ? wp_unslash($_POST['preset']) : '';
        $from_raw   = isset($_POST['from']) ? wp_unslash($_POST['from']) : '';
        $to_raw     = isset($_POST['to']) ? wp_unslash($_POST['to']) : '';

        $clean = self::sanitize_range_input($preset_raw, $from_raw, $to_raw);
        $range = self::resolve_range($clean['preset'], $clean['from'], $clean['to']);

        // Per-landing-page data — IDENTICAL source as render_page() so server render and
        // AJAX agree byte-for-byte (LP-06, D-02/D-09).
        $data = self::landing_page_rows($range['from_utc'], $range['to_utc']);

        wp_send_json_success([
            'rows'   => self::render_rows($data),   // identical markup to the server render
            'totals' => self::render_totals($data),
            'health' => self::health_status(),
            'range'  => $range['label'],
        ]);
    }
}
