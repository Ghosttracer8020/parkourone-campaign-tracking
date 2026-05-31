<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * POT_Store — the SINGLE database gateway for the pot_events store.
 *
 * Every consumer (dashboard in Phase 4, pull-API in Phase 5, retention cron) reads
 * and writes through this class so the numbers never drift. No other class touches
 * $wpdb on this table directly.
 *
 * All writes go through $wpdb->insert with an explicit $formats array; the aggregate
 * read uses $wpdb->prepare with %s placeholders. No variable is concatenated into SQL.
 */
class POT_Store {

    /** Stable bucket label for rows with an empty/NULL campaign (refined in Phase 2). */
    const UNATTRIBUTED = '(unattributed)';

    /** Columns accepted by insert_event(), with their $wpdb format specifiers. */
    private static function columns() {
        return [
            'event_type'   => '%s',
            'campaign'     => '%s',
            'source'       => '%s',
            'medium'       => '%s',
            'landing_path' => '%s',
            'session_id'   => '%s',
            'order_id'     => '%d',
            'event_ref'    => '%d',
            'created_at'   => '%s',
        ];
    }

    /** Fully-qualified table name. */
    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'pot_events';
    }

    /**
     * Insert one event row.
     *
     * @param array $row Whitelisted columns; created_at defaults to UTC now when absent.
     * @return int|false New row id, or false on failure.
     */
    public static function insert_event(array $row) {
        global $wpdb;

        $columns = self::columns();
        $data    = [];
        $formats = [];

        foreach ($columns as $col => $format) {
            if (array_key_exists($col, $row)) {
                $data[$col]  = $row[$col];
                $formats[]   = $format;
            }
        }

        // Default created_at to UTC now (current_time('mysql', true)) when not supplied.
        if (!isset($data['created_at'])) {
            $data['created_at'] = current_time('mysql', true);
            $formats[]          = '%s';
        }

        $inserted = $wpdb->insert(self::table(), $data, $formats);

        if ($inserted === false) {
            error_log('[POT Tracking] insert_event failed: ' . $wpdb->last_error);
            return false;
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Aggregate per-campaign visit/click/booking counts within a date range.
     *
     * Single GROUP BY query hitting the (campaign,created_at) / (event_type,created_at)
     * composite indices. Empty/NULL campaigns are bucketed under UNATTRIBUTED rather
     * than dropped. Returns an array of rows: { campaign, visits, clicks, bookings }.
     *
     * @param string $from Inclusive lower bound (DATETIME, e.g. '2026-05-01 00:00:00').
     * @param string $to   Inclusive upper bound (DATETIME).
     * @return array<int,array<string,mixed>>
     */
    public static function aggregate_by_campaign($from, $to) {
        global $wpdb;

        $table = self::table();

        $sql = $wpdb->prepare(
            "SELECT
                CASE WHEN campaign IS NULL OR campaign = '' THEN %s ELSE campaign END AS campaign,
                SUM(event_type = 'visit') AS visits,
                SUM(event_type = 'click') AS clicks,
                SUM(event_type = 'booking') AS bookings
             FROM {$table}
             WHERE created_at BETWEEN %s AND %s
             GROUP BY CASE WHEN campaign IS NULL OR campaign = '' THEN %s ELSE campaign END",
            self::UNATTRIBUTED,
            $from,
            $to,
            self::UNATTRIBUTED
        );

        $rows = $wpdb->get_results($sql, ARRAY_A);

        if ($wpdb->last_error) {
            error_log('[POT Tracking] aggregate_by_campaign failed: ' . $wpdb->last_error);
            return [];
        }

        return is_array($rows) ? $rows : [];
    }

    /**
     * Delete raw visit/click rows older than the cutoff. NEVER touches booking rows
     * (business record). Routed through this gateway so the cron never calls $wpdb.
     *
     * @param string $cutoff DATETIME; rows strictly older than this are pruned.
     * @return int Number of rows deleted.
     */
    public static function delete_raw_older_than($cutoff) {
        global $wpdb;

        $table = self::table();

        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE event_type IN ('visit','click') AND created_at < %s",
                $cutoff
            )
        );

        if ($deleted === false) {
            error_log('[POT Tracking] delete_raw_older_than failed: ' . $wpdb->last_error);
            return 0;
        }

        return (int) $deleted;
    }
}
