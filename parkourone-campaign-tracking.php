<?php
/**
 * Plugin Name: ParkourONE Campaign Tracking
 * Description: Zählt echte Probetraining-Buchungen je Kampagne/Landingpage und ordnet sie verlässlich zu. Eigener Events-Store, Conversion-Tracking, Dashboard und gesicherte Pull-API.
 * Version:     0.1.0
 * Author:      Pierre Biege
 * Text Domain: parkourone-campaign-tracking
 * Requires PHP: 8.1
 * Requires at least: 6.9
 * WC requires at least: 8.2
 */

if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants (defined immediately after the ABSPATH guard).
define('POT_VERSION', '0.1.0');
define('POT_PLUGIN_FILE', __FILE__);
define('POT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('POT_PLUGIN_URL', plugin_dir_url(__FILE__));

// 1) Flat require_once chain (house style). github-updater.php is ALWAYS the last require.
require_once POT_PLUGIN_DIR . 'includes/class-pot-plugin.php';
require_once POT_PLUGIN_DIR . 'includes/class-pot-activator.php';
require_once POT_PLUGIN_DIR . 'includes/class-pot-store.php';
require_once POT_PLUGIN_DIR . 'includes/class-pot-cron.php';
require_once POT_PLUGIN_DIR . 'includes/class-pot-admin.php';
require_once POT_PLUGIN_DIR . 'includes/class-pot-conversion.php';
require_once POT_PLUGIN_DIR . 'includes/class-pot-attribution.php';
require_once POT_PLUGIN_DIR . 'includes/class-pot-ingest.php';
require_once POT_PLUGIN_DIR . 'includes/class-pot-tracker.php';
require_once POT_PLUGIN_DIR . 'includes/class-pot-theme-retirement.php';
require_once POT_PLUGIN_DIR . 'includes/github-updater.php'; // ALWAYS last in the require chain.

// 2) Main initialisation: single pot_init() on plugins_loaded, priority 11
//    (after ab-webhook-endpoint registers the probetraining status — harmless now, required later).
function pot_init() {
    POT_Plugin::init();
}
add_action('plugins_loaded', 'pot_init', 11);

// 3) WooCommerce HPOS (custom order tables) compatibility declaration.
//    Guarded by class_exists so the plugin never fatals when WooCommerce is inactive.
add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', POT_PLUGIN_FILE, true);
    }
});

// 4) Activation / deactivation hooks. No flush_rewrite_rules — no rewrite rules in this phase.
register_activation_hook(POT_PLUGIN_FILE, ['POT_Activator', 'activate']);
register_deactivation_hook(POT_PLUGIN_FILE, ['POT_Cron', 'clear']);
