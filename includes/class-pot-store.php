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
     * Aggregate per-landing-page visit/click/booking counts within a date range, restricted
     * to the registered allowlist keys (LP-05).
     *
     * Admitted visit/click rows store the canonical normalized key (D-07), so they are matched
     * by a single prepared `WHERE landing_path IN (...)` GROUP BY that hits the new
     * (landing_path,created_at) composite prefix index. The dynamic IN() is built SAFELY with
     * array_fill('%s') placeholders + $wpdb->prepare — keys are NEVER concatenated into SQL
     * (injection guard, T-06-07).
     *
     * Booking rows store landing_path UN-normalized (POT_Conversion is intentionally untouched,
     * LP-04), so a raw IN() would under-count listed-page bookings whose stored path differs
     * from the canonical key. Because booking rows are low-volume, they are fetched separately
     * and bucketed by POT_Landing_Pages::normalize_path() in PHP at read time (R-2); a booking
     * is added to a listed key's count only when its normalized key is in $keys. Refining LP-05
     * per FIX-01: bookings whose raw path is empty or whose normalized key is NOT listed are
     * returned in the UNATTRIBUTED bucket row instead of being dropped — no booking is ever
     * invisible in this view, and each booking counts in EXACTLY one bucket.
     *
     * All SQL stays inside this gateway (no $wpdb in callers). Rows are NOT zero-filled here —
     * the caller (Plan 03 dashboard) zero-fills in registered key order.
     *
     * @param array  $keys Normalized allowlist keys (registered order). Empty ⇒ the visit/click
     *                     query is skipped, but the booking pass still runs (FIX-01).
     * @param string $from Inclusive lower bound (DATETIME, UTC).
     * @param string $to   Inclusive upper bound (DATETIME, UTC).
     * @return array<int,array<string,mixed>> Rows: { landing_path, visits, clicks, bookings }.
     */
    public static function aggregate_by_landing_page(array $keys, $from, $to) {
        global $wpdb;

        $table = self::table();

        // Index visit/click results by their (already canonical) landing_path key.
        $by_key = [];

        // Empty allowlist ⇒ no visit/click rows can exist (ingest gate drops them), so the
        // IN() query is skipped — but the booking pass below ALWAYS runs (FIX-01): bookings
        // must stay visible even when no landing page is registered.
        if (!empty($keys)) {
            // --- Visit/click portion: prepared IN() of the canonical keys (D-07). ---
            $placeholders = implode(',', array_fill(0, count($keys), '%s'));
            // Arg order MUST match placeholder order: the IN() keys first, then the date bounds.
            $args = array_merge($keys, [$from, $to]);

            $sql = $wpdb->prepare(
                "SELECT
                    landing_path,
                    SUM(event_type = 'visit') AS visits,
                    SUM(event_type = 'click') AS clicks,
                    SUM(event_type = 'booking') AS bookings
                 FROM {$table}
                 WHERE landing_path IN ({$placeholders})
                   AND created_at BETWEEN %s AND %s
                 GROUP BY landing_path",
                $args
            );

            $rows = $wpdb->get_results($sql, ARRAY_A);

            if ($wpdb->last_error) {
                error_log('[POT Tracking] aggregate_by_landing_page failed: ' . $wpdb->last_error);
                return [];
            }

            foreach (is_array($rows) ? $rows : [] as $row) {
                $k          = (string) $row['landing_path'];
                $by_key[$k] = [
                    'landing_path' => $k,
                    'visits'       => (int) $row['visits'],
                    'clicks'       => (int) $row['clicks'],
                    // Bookings recomputed from the normalized pass below; the IN() booking SUM is
                    // discarded because booking rows are stored un-normalized (R-2).
                    'bookings'     => 0,
                ];
            }
        }

        // --- Booking-match portion (R-2): normalize un-normalized booking paths at read time. ---
        $booking_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT landing_path
                 FROM {$table}
                 WHERE event_type = 'booking'
                   AND created_at BETWEEN %s AND %s",
                $from,
                $to
            ),
            ARRAY_A
        );

        if ($wpdb->last_error) {
            error_log('[POT Tracking] aggregate_by_landing_page failed: ' . $wpdb->last_error);
            return [];
        }

        // FIX-01 bucketing: every booking counts in EXACTLY one bucket — either a listed
        // key or the UNATTRIBUTED row (mutually exclusive if/else, no double counting).
        $listed       = array_flip($keys);
        $unattributed = 0;
        foreach (is_array($booking_rows) ? $booking_rows : [] as $brow) {
            $raw  = (string) $brow['landing_path'];
            $bkey = POT_Landing_Pages::normalize_path($raw);
            // An empty raw path (no attribution cookie / no consent / admin booking) is
            // checked explicitly: normalize_path('') returns '/', which must NOT silently
            // attribute the booking to a registered root page.
            if ($raw === '' || !isset($listed[$bkey])) {
                $unattributed++;
            } else {
                if (!isset($by_key[$bkey])) {
                    // Listed key with bookings but no visit/click rows in range — still surface it.
                    $by_key[$bkey] = [
                        'landing_path' => $bkey,
                        'visits'       => 0,
                        'clicks'       => 0,
                        'bookings'     => 0,
                    ];
                }
                $by_key[$bkey]['bookings']++;
            }
        }

        $result = array_values($by_key);

        if ($unattributed > 0) {
            $result[] = [
                'landing_path' => self::UNATTRIBUTED,
                'visits'       => 0,
                'clicks'       => 0,
                'bookings'     => $unattributed,
            ];
        }

        return $result;
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
