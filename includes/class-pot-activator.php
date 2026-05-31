<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Activation + schema migration for the pot_events store.
 *
 * No sibling analog (no dbDelta exists in any reference plugin) — schema follows
 * STACK.md. dbDelta runs on activation AND on a pot_db_version bump (admin_init guard).
 */
class POT_Activator {

    /**
     * Activation hook callback: create/upgrade the schema, seed options, schedule the cron.
     */
    public static function activate() {
        self::install_schema();

        // Seed state options with autoload=false (Pitfall 11 — avoid autoload bloat).
        if (get_option('pot_db_version') === false) {
            add_option('pot_db_version', POT_VERSION, '', false);
        } else {
            update_option('pot_db_version', POT_VERSION, false);
        }
        if (get_option('pot_retention_days') === false) {
            add_option('pot_retention_days', 180, '', false);
        }

        // Schedule the daily retention cron (idempotent).
        POT_Cron::schedule();
    }

    /**
     * Re-run the schema migration when the stored db version differs from the code constant.
     * Hooked from POT_Plugin::init() on admin_init so future migrations are safe.
     */
    public static function maybe_upgrade() {
        if (get_option('pot_db_version') !== POT_VERSION) {
            self::install_schema();
            update_option('pot_db_version', POT_VERSION, false);
        }
    }

    /**
     * Build and run the CREATE TABLE via dbDelta.
     *
     * dbDelta parsing is whitespace-sensitive (Pitfall 10): one column per line,
     * two spaces after PRIMARY KEY, exact "KEY name (cols)" spacing. Indices are
     * verified live via SHOW INDEX, never on an empty table.
     */
    private static function install_schema() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table           = $wpdb->prefix . 'pot_events';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_type VARCHAR(20) NOT NULL DEFAULT '',
  campaign VARCHAR(191) NOT NULL DEFAULT '',
  source VARCHAR(100) NOT NULL DEFAULT '',
  medium VARCHAR(100) NOT NULL DEFAULT '',
  landing_path VARCHAR(255) NOT NULL DEFAULT '',
  session_id VARCHAR(64) NOT NULL DEFAULT '',
  order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  event_ref BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY event_type_created (event_type,created_at),
  KEY campaign_created (campaign,created_at),
  KEY order_idx (order_id)
) {$charset_collate};";

        $result = dbDelta($sql);

        if (!empty($wpdb->last_error)) {
            error_log('[POT Tracking] dbDelta error: ' . $wpdb->last_error);
        }

        return $result;
    }
}
