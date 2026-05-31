<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Daily retention cron for the pot_events store.
 *
 * Mirrors class-ab-bestandskunde-reminder.php but simplified to plain WP-Cron (no
 * Action Scheduler branch). Prunes ONLY raw visit/click rows older than the retention
 * window; booking rows (business record) are never touched. The DELETE is routed
 * through POT_Store so the cron never touches the database directly (single-gateway rule).
 */
class POT_Cron {

    const CRON_HOOK = 'pot_prune_events';

    /** Bind the prune handler so WP-Cron can fire it on every load. */
    public static function init() {
        add_action(self::CRON_HOOK, [__CLASS__, 'prune_events']);
    }

    /** Schedule the daily event idempotently. Called from the activation path. */
    public static function schedule() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), 'daily', self::CRON_HOOK);
        }
    }

    /** Clear the scheduled event. Called from the plugin deactivation hook. */
    public static function clear() {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    /** Prune raw visit/click rows older than pot_retention_days (default 180). */
    public static function prune_events() {
        $days   = (int) get_option('pot_retention_days', 180);
        $cutoff = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);

        $deleted = POT_Store::delete_raw_older_than($cutoff);

        error_log('[POT Tracking] pruned ' . (int) $deleted . ' raw rows older than ' . $cutoff);
    }
}
