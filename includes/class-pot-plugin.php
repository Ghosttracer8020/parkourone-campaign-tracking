<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Orchestrator: thin flat list of POT_*::init() calls, mirroring the
 * ab_we_init_plugin() body in the sibling plugin.
 */
class POT_Plugin {

    public static function init() {
        // Schema version-bump guard: re-runs dbDelta on admin_init when pot_db_version differs.
        add_action('admin_init', ['POT_Activator', 'maybe_upgrade']);

        // Retention cron handler binding (the action must be registered on every load
        // so WP-Cron can fire it).
        POT_Cron::init();

        // Admin shell (placeholder page under the parkourone menu).
        POT_Admin::init();

        // Server-side conversion listener (WooCommerce-guarded internally; consent-independent).
        POT_Conversion::init();

        // Front-end UTM capture enqueue + checkout cookie→order-meta bridge.
        POT_Attribution::init();

        // Client-side ingest REST route (POST pot/v1/event) for visits + CTA clicks.
        POT_Ingest::init();

        // Front-end capture tracker enqueue + localize (admin-skipped).
        POT_Tracker::init();

        // Theme-analytics retirement: option-gated dequeue of po-analytics-tracker +
        // removal of the theme's wp_footer fallback writer (single-tracker cutover).
        POT_Theme_Retirement::init();
    }
}
