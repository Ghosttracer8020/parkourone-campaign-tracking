<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * POT_Attribution — first-touch UTM attribution bridge.
 *
 * Front end: enqueues assets/js/pot-attribution.js (admin-skipped) which captures first-touch
 * UTM into a consent-gated `pot_attribution` cookie.
 *
 * Server: at woocommerce_checkout_create_order it reads $_COOKIE['pot_attribution'], json_decodes
 * (wp_unslash), guards is_array, sanitizes + length-caps each field, and persists the four stable
 * order-meta keys (_pot_campaign/_pot_source/_pot_medium/_pot_landing) that POT_Conversion reads
 * later. Persisting at create-order time gives the conversion handler a stable source — the
 * conversion may fire days later on a status change and must not depend on a transient cookie.
 *
 * The cookie is untrusted input: every decoded field is sanitized and length-capped at the
 * boundary before it ever reaches order meta (and, via POT_Conversion, the events store).
 */
class POT_Attribution {

    const COOKIE_NAME = 'pot_attribution';
    const COOKIE_DAYS = 90;

    /** Per-field length cap (matches the theme's VARCHAR(100) utm_* columns). */
    const FIELD_MAX = 100;

    public static function init() {
        // Front-end asset (admin-skip handled inside enqueue()).
        add_action('wp_enqueue_scripts', ['POT_Attribution', 'enqueue']);

        // Checkout bridge — order-level (not line-item). Guarded so inactive WooCommerce never fatals.
        if (function_exists('WC')) {
            add_action('woocommerce_checkout_create_order', ['POT_Attribution', 'persist_attribution'], 10, 1);
        }
    }

    /**
     * Enqueue the first-touch capture script for non-admins. Logged-in admins are skipped
     * entirely. The script is NOT consent-gated at enqueue time: it must load so it can stash
     * UTM in sessionStorage and promote to the cookie once consent is granted.
     */
    public static function enqueue() {
        $is_admin = (is_user_logged_in() && current_user_can('manage_options'));
        if ($is_admin) {
            return;
        }

        wp_enqueue_script('pot-attribution',
            POT_PLUGIN_URL . 'assets/js/pot-attribution.js',
            [],
            POT_VERSION,
            true
        );

        wp_localize_script('pot-attribution', 'potAttribution', [
            'isAdmin'    => $is_admin,
            'cookieName' => self::COOKIE_NAME,
            'cookieDays' => self::COOKIE_DAYS,
        ]);
    }

    /**
     * Read the (untrusted) pot_attribution cookie at checkout-create and persist sanitized,
     * length-capped attribution into order meta. No cookie → write nothing. No $order->save():
     * WooCommerce persists the order itself on woocommerce_checkout_create_order.
     *
     * @param WC_Order $order The order being created.
     */
    public static function persist_attribution($order) {
        if (empty($_COOKIE[self::COOKIE_NAME])) {
            return; // no cookie → booking later lands in the unattributed bucket
        }

        $ft = json_decode(wp_unslash($_COOKIE[self::COOKIE_NAME]), true);
        if (!is_array($ft)) {
            // Malformed / tampered cookie — never fatal, write nothing.
            error_log('[POT Tracking] persist_attribution: malformed pot_attribution cookie ignored');
            return;
        }

        $order->update_meta_data('_pot_campaign', self::clean($ft['campaign'] ?? ''));
        $order->update_meta_data('_pot_source',   self::clean($ft['source'] ?? ''));
        $order->update_meta_data('_pot_medium',   self::clean($ft['medium'] ?? ''));
        $order->update_meta_data('_pot_landing',  self::clean($ft['landing_path'] ?? ''));
    }

    /** Sanitize a single cookie field and length-cap it at the boundary. */
    private static function clean($value) {
        $value = sanitize_text_field((string) $value);
        return substr($value, 0, self::FIELD_MAX);
    }
}
