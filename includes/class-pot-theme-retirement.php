<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * POT_Theme_Retirement — retires the legacy theme analytics tracker so exactly ONE
 * tracker runs (no double-count, Pitfall 1).
 *
 * The theme (Input/parkourone-theme) is a SEPARATE repo and is NEVER edited. Instead this
 * plugin:
 *   1. Dequeues + deregisters the theme's `po-analytics-tracker` script at wp_enqueue_scripts
 *      priority 99 (the theme enqueues at default priority 10, so the dequeue must run AFTER).
 *      With the script gone, the theme's REST handler (handle_track) has no caller, so ALL
 *      theme JS-driven cta_click/pageview writes to wp_po_analytics_events stop.
 *   2. Removes the theme's wp_footer server-fallback pageview writer (track_basic_pageview) so
 *      non-consenting visitors no longer produce theme analytics rows.
 *
 * The whole retirement is gated behind the `pot_retire_theme_tracker` option (default true)
 * so the cutover can be toggled off WITHOUT a deploy if the runtime parity check fails.
 *
 * track_purchase is intentionally LEFT registered — Phase 2 owns conversions server-side, and
 * removing the theme's purchase row before runtime parity is confirmed risks losing booking
 * data. Its retirement is a separate, deferred decision.
 *
 * The remove_action is guarded by class_exists('PO_Analytics') — same graceful-degradation
 * posture as POT_Attribution's function_exists('WC') guard — so the plugin never fatals on a
 * site where the theme/class is absent.
 */
class POT_Theme_Retirement {

    /** Short-lived feature gate (default true). Toggle off to instantly restore the theme tracker. */
    const OPTION = 'pot_retire_theme_tracker';

    public static function init() {
        // Cutover gate — default true. If parity fails, set the option false to roll back without code.
        if (!get_option(self::OPTION, true)) {
            return;
        }

        // Priority 99: the theme enqueues at default priority 10, so the dequeue must run AFTER it.
        add_action('wp_enqueue_scripts', ['POT_Theme_Retirement', 'dequeue_theme_tracker'], 99);

        // Remove the theme's wp_footer server-fallback pageview writer. Guarded so an absent
        // theme/class never fatals (graceful degradation, same posture as function_exists('WC')).
        if (class_exists('PO_Analytics')) {
            $po = PO_Analytics::get_instance();
            // SAME instance + SAME priority 10 the theme registered at, so the removal matches.
            remove_action('wp_footer', [$po, 'track_basic_pageview'], 10);
        }
    }

    /**
     * Dequeue AND deregister the theme's tracker script. With the script gone the theme's
     * handle_track REST route has no caller, stopping all theme JS-driven writes.
     */
    public static function dequeue_theme_tracker() {
        // Literal theme handle (class-analytics.php).
        wp_dequeue_script('po-analytics-tracker');
        wp_deregister_script('po-analytics-tracker');
    }
}
