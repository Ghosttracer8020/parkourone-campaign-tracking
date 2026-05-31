<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * POT_Metrics — the SINGLE rate computation shared by POT_Admin (dashboard) and
 * POT_Api (pull endpoint).
 *
 * CONTRACT: every funnel rate the dashboard renders and every rate the API emits
 * derives from rate_value() here, so the two surfaces can NEVER drift. The dashboard
 * formats the numeric result into its German string ('12,5 %' / '–'); the API returns
 * the raw number (or null). The num/den pairing is identical on both sides:
 *   conversionRate = bookings / visits   (class-pot-admin.php:319)
 *   visitToClick   = clicks   / visits   (class-pot-admin.php:320)
 *   clickToBooking = bookings / clicks   (class-pot-admin.php:322)
 *
 * This class contains NO SQL and never touches $wpdb — it is a pure numeric utility.
 */
class POT_Metrics {

    /**
     * Maximum span (in days) a custom range may cover. Mirrors POT_Admin::MAX_SPAN_DAYS
     * so the cap lives in one named place and dashboard + API agree.
     */
    const MAX_SPAN_DAYS = 366;

    /**
     * Percentage rate with a divide-by-zero guard, as a raw number.
     *
     * Returns null when the denominator is 0 (so callers can render '–' or emit JSON
     * null), otherwise the percentage rounded to 1 decimal (the unit the dashboard
     * already shows, e.g. 12.5 for 12.5 %).
     *
     * @param int|float $num Numerator (e.g. bookings).
     * @param int|float $den Denominator (e.g. visits).
     * @return float|null
     */
    public static function rate_value($num, $den) {
        if ((int) $den === 0) {
            return null;
        }
        return round(($num / $den) * 100, 1);
    }
}
