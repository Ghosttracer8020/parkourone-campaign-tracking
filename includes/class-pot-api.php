<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * POT_Api — the secured read-only PULL endpoint (GET pot/v1/metrics).
 *
 * EXTERNAL TRUST BOUNDARY: this is the only internet-facing read surface of the plugin.
 * It is consumed server-to-server by the ONE Statusboard, authenticated with a Bearer
 * secret compared in constant time (hash_equals after a length pre-check). Any auth
 * failure returns HTTP 401 — never 500, never revealing whether a secret exists, and the
 * secret is NEVER logged or echoed. No Access-Control-Allow-Origin header is sent (the
 * pull is server-to-server, not browser-CORS).
 *
 * ZERO-DRIFT: this controller contains NO $wpdb and NO raw SQL. All aggregation flows
 * through POT_Store::aggregate_by_campaign (the single gateway) and every rate derives
 * from POT_Metrics::rate_value, so the API numbers are identical to the dashboard.
 *
 * Range semantics: from/to are interpreted as UTC day bounds (the store's created_at is
 * UTC). When omitted, the default window is the last 30 days (today-UTC minus 29 days
 * through today-UTC). The same validation/clamp/span-cap rules as the dashboard apply
 * (see class-pot-admin.php:128-167): from<=to swap, clamp future to today-UTC, span cap
 * POT_Metrics::MAX_SPAN_DAYS=366 by pulling `from` forward.
 */
class POT_Api {

    public static function init() {
        add_action('rest_api_init', ['POT_Api', 'register_routes']);
    }

    /**
     * Read (or lazily mint) the Bearer secret. Self-heals regardless of activation timing.
     * Stored as a dedicated autoload=false option. NEVER logged or echoed.
     *
     * @return string
     */
    public static function get_or_create_secret() {
        $secret = get_option('pot_api_secret');
        if (empty($secret)) {
            $secret = wp_generate_password(32, false);
            add_option('pot_api_secret', $secret, '', false); // 4th arg false = autoload=false
        }
        return (string) $secret;
    }

    /**
     * Bearer permission_callback. NEVER __return_true, NEVER the WP-REST nonce.
     *
     * Reads the Authorization header, requires a 'Bearer ' prefix (case-insensitive),
     * then does a length pre-check BEFORE hash_equals (hash_equals needs equal-length
     * inputs; the pre-check also avoids leaking via an exception). Every failure returns
     * the SAME generic 401 so the response never reveals whether a secret exists. The
     * secret is never logged. No CORS header is set.
     *
     * @param WP_REST_Request $request
     * @return true|WP_Error
     */
    public static function check_bearer(WP_REST_Request $request) {
        $header = (string) $request->get_header('authorization');

        if (stripos($header, 'Bearer ') !== 0) {
            return new WP_Error('pot_unauthorized', 'Authentifizierung erforderlich.', ['status' => 401]);
        }

        $provided = substr($header, 7); // strip the 7-char 'Bearer ' prefix
        $secret   = self::get_or_create_secret();

        // Length pre-check before the constant-time compare.
        if ($provided === '' || strlen($provided) !== strlen($secret) || !hash_equals($secret, $provided)) {
            return new WP_Error('pot_unauthorized', 'Authentifizierung erforderlich.', ['status' => 401]);
        }

        return true;
    }

    /**
     * Register GET pot/v1/metrics behind the Bearer permission_callback.
     * from/to are optional ISO date args (validated + sanitized at the boundary).
     */
    public static function register_routes() {
        register_rest_route('pot/v1', '/metrics', [
            'methods'             => 'GET',
            'callback'            => ['POT_Api', 'get_metrics'],
            'permission_callback' => ['POT_Api', 'check_bearer'],
            'args'                => [
                'from' => [
                    'required'          => false,
                    'validate_callback' => ['POT_Api', 'validate_date_param'],
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'to' => [
                    'required'          => false,
                    'validate_callback' => ['POT_Api', 'validate_date_param'],
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);
    }

    /**
     * Accept a 'Y-m-d' date or a parseable ISO-8601 timestamp.
     *
     * @param mixed $param
     * @return bool
     */
    public static function validate_date_param($param) {
        $param = (string) $param;
        if ($param === '') {
            return true; // optional — default handled in the callback
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $param)) {
            return true;
        }
        return strtotime($param) !== false;
    }

    /**
     * GET pot/v1/metrics handler. Builds the camelCase Statusboard payload from the
     * single aggregation gateway. Contains NO $wpdb.
     *
     * PAYLOAD CONTRACT:
     *   - numbers stay numbers (counts are ints);
     *   - rates are percentages to 1 decimal (matching the dashboard), via POT_Metrics;
     *   - a rate is JSON null when its denominator is 0 (never 0.0);
     *   - generatedAt is ISO-8601 UTC (gmdate('c'));
     *   - status is derived from the Phase 2 pot_conversion_status option;
     *   - the shape is .passthrough()-compatible for the Statusboard store.
     *
     * @param WP_REST_Request $request
     * @return array
     */
    public static function get_metrics(WP_REST_Request $request) {
        $from_raw = (string) $request->get_param('from');
        $to_raw   = (string) $request->get_param('to');

        $range = self::resolve_range_utc($from_raw, $to_raw);

        // Single gateway aggregate call (UTC bounds). NO $wpdb here — zero drift.
        $rows = POT_Store::aggregate_by_campaign($range['from_utc'], $range['to_utc']);

        $campaigns = [];
        $total_visits = $total_clicks = $total_bookings = 0;

        foreach ((array) $rows as $row) {
            $campaign = isset($row['campaign']) ? (string) $row['campaign'] : POT_Store::UNATTRIBUTED;
            $visits   = isset($row['visits']) ? (int) $row['visits'] : 0;
            $clicks   = isset($row['clicks']) ? (int) $row['clicks'] : 0;
            $bookings = isset($row['bookings']) ? (int) $row['bookings'] : 0;

            $total_visits   += $visits;
            $total_clicks   += $clicks;
            $total_bookings += $bookings;

            $campaigns[] = [
                'campaign'       => $campaign,
                'visits'         => $visits,
                'clicks'         => $clicks,
                'bookings'       => $bookings,
                'conversionRate' => POT_Metrics::rate_value($bookings, $visits), // bookings/visits
                'visitToClick'   => POT_Metrics::rate_value($clicks, $visits),   // clicks/visits
                'clickToBooking' => POT_Metrics::rate_value($bookings, $clicks), // bookings/clicks
            ];
        }

        $status = self::resolve_status();

        return [
            'generatedAt' => gmdate('c'),
            'status'      => $status,
            'range'       => [
                'from'     => $range['from_date'],
                'to'       => $range['to_date'],
                'timezone' => 'UTC',
            ],
            'totals' => [
                'visits'         => $total_visits,
                'clicks'         => $total_clicks,
                'bookings'       => $total_bookings,
                'conversionRate' => POT_Metrics::rate_value($total_bookings, $total_visits),
            ],
            'campaigns' => $campaigns,
        ];
    }

    /**
     * Map the Phase 2 conversion-status option onto the Statusboard status enum.
     * 'ok' stays 'ok'; anything else collapses to 'not_configured' ('stale' reserved).
     *
     * @return string 'ok'|'not_configured'|'stale'
     */
    private static function resolve_status() {
        $status = (string) get_option('pot_conversion_status', 'not_configured');
        if ($status === 'ok' || $status === 'stale') {
            return $status;
        }
        return 'not_configured';
    }

    /**
     * Resolve from/to (interpreted as UTC) into 'Y-m-d H:i:s' day bounds plus the
     * echo-friendly 'Y-m-d' dates. Applies the same rules as the dashboard:
     * default last-30-days when omitted, clamp future to today-UTC, swap when from>to,
     * cap the span at POT_Metrics::MAX_SPAN_DAYS by pulling `from` forward.
     *
     * @param string $from_raw 'Y-m-d' / ISO-8601 / ''
     * @param string $to_raw   'Y-m-d' / ISO-8601 / ''
     * @return array{from_utc:string,to_utc:string,from_date:string,to_date:string}
     */
    private static function resolve_range_utc($from_raw, $to_raw) {
        $utc   = new DateTimeZone('UTC');
        $today = new DateTime('now', $utc);
        $today->setTime(0, 0, 0);

        $to_dt   = self::parse_utc_date($to_raw, $utc);
        $from_dt = self::parse_utc_date($from_raw, $utc);

        // Defaults: last 30 calendar days (today-UTC minus 29 days through today-UTC).
        if (!$to_dt) {
            $to_dt = clone $today;
        }
        if (!$from_dt) {
            $from_dt = clone $today;
            $from_dt->modify('-29 days');
        }

        // Clamp future dates to today-UTC.
        if ($from_dt > $today) {
            $from_dt = clone $today;
        }
        if ($to_dt > $today) {
            $to_dt = clone $today;
        }

        // Enforce from <= to (swap).
        if ($from_dt > $to_dt) {
            $tmp     = $from_dt;
            $from_dt = $to_dt;
            $to_dt   = $tmp;
        }

        // Cap the span at MAX_SPAN_DAYS — pull `from` forward toward `to`.
        $span = (int) $from_dt->diff($to_dt)->days;
        if ($span > POT_Metrics::MAX_SPAN_DAYS) {
            $from_dt = clone $to_dt;
            $from_dt->modify('-' . POT_Metrics::MAX_SPAN_DAYS . ' days');
        }

        $from_date = $from_dt->format('Y-m-d');
        $to_date   = $to_dt->format('Y-m-d');

        // Day boundaries: lower at 00:00:00, upper at 23:59:59 (UTC).
        $from_dt->setTime(0, 0, 0);
        $to_dt->setTime(23, 59, 59);

        return [
            'from_utc'  => $from_dt->format('Y-m-d H:i:s'),
            'to_utc'    => $to_dt->format('Y-m-d H:i:s'),
            'from_date' => $from_date,
            'to_date'   => $to_date,
        ];
    }

    /**
     * Parse a 'Y-m-d' or ISO-8601 string as a UTC DateTime (date part only), or null.
     *
     * @param string       $value
     * @param DateTimeZone $utc
     * @return DateTime|null
     */
    private static function parse_utc_date($value, DateTimeZone $utc) {
        $value = (string) $value;
        if ($value === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $dt = DateTime::createFromFormat('Y-m-d', $value, $utc);
            if ($dt) {
                $dt->setTime(0, 0, 0);
                return $dt;
            }
        }
        $ts = strtotime($value);
        if ($ts === false) {
            return null;
        }
        $dt = new DateTime('@' . $ts);
        $dt->setTimezone($utc);
        $dt->setTime(0, 0, 0);
        return $dt;
    }
}
