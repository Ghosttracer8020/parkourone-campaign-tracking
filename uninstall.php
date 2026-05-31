<?php
/**
 * Uninstall cleanup for ParkourONE Campaign Tracking.
 *
 * Runs only when the plugin is deleted via wp-admin (or `wp plugin uninstall`).
 * Drops the events table, deletes all pot_* options, and clears the retention cron.
 * Does NOT delete the shared parkourone_github_token option (owned by sibling plugins).
 */

// Guard: only run inside WordPress's uninstall flow.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// Drop the custom events table.
$table = $wpdb->prefix . 'pot_events';
$wpdb->query("DROP TABLE IF EXISTS {$table}");

// Delete all pot_* options explicitly by key.
// NOTE: any future pot_* option (e.g. a pull-API secret added in a later phase)
// MUST be added to this list so uninstall leaves no orphaned data.
delete_option('pot_db_version');
delete_option('pot_retention_days');
delete_option('pot_api_secret');

// Clear the scheduled retention cron.
wp_clear_scheduled_hook('pot_prune_events');

// Intentionally NOT deleted: 'parkourone_github_token' (shared across all monkeyspk plugins).

error_log('[POT Tracking] uninstall complete: table dropped, pot_* options deleted, cron cleared');
