<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * POT_Conversion — server-side, consent-INDEPENDENT Probetraining conversion listener.
 *
 * This is the irreplaceable core-value metric: every completed Probetraining booking
 * must be counted exactly once on the server, regardless of consent or the client funnel.
 *
 * Two hooks route to record_conversion():
 *  - woocommerce_order_status_probetraining (primary)
 *  - woocommerce_order_status_changed → on_status_changed (fallback for the free / 100%-coupon
 *    redirect path that ab-webhook-endpoint runs at priority 999).
 *
 * Idempotency: the order-meta flag _pot_conversion_tracked is checked BEFORE any insert so a
 * double-fire (both hooks) or a repeated status transition never inflates the count.
 *
 * Graceful degradation: when the probetraining status (or WooCommerce itself) is unavailable,
 * the plugin records pot_conversion_status=not_configured, shows a dismissible admin notice,
 * and never fatals. All writes go through the parameterized POT_Store gateway.
 */
class POT_Conversion {

    public static function init() {
        // Guard: WooCommerce inactive → no WC hooks, record not_configured, never fatal.
        if (!function_exists('WC') || !function_exists('wc_get_order_statuses')) {
            update_option('pot_conversion_status', 'not_configured', false);
            return;
        }

        // Detect the probetraining status (registered by the sibling ab-webhook-endpoint plugin).
        // Prefer get_post_status_object; fall back to the wc status map.
        $status_present = (bool) get_post_status_object('wc-probetraining')
            || array_key_exists('wc-probetraining', wc_get_order_statuses());

        update_option('pot_conversion_status', $status_present ? 'ok' : 'not_configured', false);

        // PRIMARY hook — fires when an order reaches the probetraining status.
        add_action('woocommerce_order_status_probetraining', ['POT_Conversion', 'record_conversion'], 10, 1);

        // FALLBACK hook — registered unconditionally (even in not_configured) so a status that
        // appears later, or the free/100%-coupon priority-999 redirect path, is still caught.
        add_action('woocommerce_order_status_changed', ['POT_Conversion', 'on_status_changed'], 10, 4);

        // not_configured (WC active but status missing) → queue a dismissible admin notice.
        if (!$status_present) {
            add_action('admin_notices', ['POT_Conversion', 'render_not_configured_notice']);
        }
    }

    /**
     * Fallback router. The 3rd positional arg is the NEW status WITHOUT the wc- prefix.
     * Only the probetraining transition is forwarded to the idempotent core.
     */
    public static function on_status_changed($order_id, $old_status, $new_status, $order) {
        if ($new_status === 'probetraining') {
            self::record_conversion($order_id);
        }
    }

    /**
     * Idempotent core. Loads the order HPOS-safely, returns early when already tracked,
     * otherwise writes exactly one booking row through POT_Store and sets the flag.
     */
    public static function record_conversion($order_id) {
        // HPOS-safe load — NEVER get_post_meta on orders.
        if (!function_exists('wc_get_order')) {
            return;
        }
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        // Idempotency guard FIRST, before any insert (count-once integrity).
        if ($order->get_meta('_pot_conversion_tracked') === 'yes') {
            return;
        }

        // First-touch attribution read from stable order meta (written by POT_Attribution at
        // checkout-create time). Absent → empty string → POT_Store::UNATTRIBUTED bucket.
        $campaign = (string) $order->get_meta('_pot_campaign');
        $source   = (string) $order->get_meta('_pot_source');
        $medium   = (string) $order->get_meta('_pot_medium');
        $landing  = (string) $order->get_meta('_pot_landing');

        // Best-effort event_ref resolution from the order item (_event_product_id → product _event_id).
        // Never blocks the booking insert when absent.
        $event_ref = self::resolve_event_ref($order);

        $row = [
            'event_type'   => 'booking',
            'campaign'     => $campaign,
            'source'       => $source,
            'medium'       => $medium,
            'landing_path' => $landing,
            'order_id'     => (int) $order->get_id(),
        ];
        if ($event_ref !== null) {
            $row['event_ref'] = $event_ref;
        }

        $inserted = POT_Store::insert_event($row);

        if ($inserted === false) {
            error_log('[POT Tracking] record_conversion: booking insert failed for order ' . (int) $order_id);
            return;
        }

        // Persist the count-once flag via HPOS-safe CRUD.
        $order->update_meta_data('_pot_conversion_tracked', 'yes');
        $order->save();
    }

    /**
     * Best-effort event id: iterate items, resolve product via _event_product_id (fall back to
     * the line product), then read the product's _event_id. Returns (int) or null.
     */
    private static function resolve_event_ref($order) {
        if (!method_exists($order, 'get_items')) {
            return null;
        }
        foreach ($order->get_items() as $item) {
            $product_id = $item->get_meta('_event_product_id');
            if (!$product_id && method_exists($item, 'get_product_id')) {
                $product_id = $item->get_product_id();
            }
            if (!$product_id) {
                continue;
            }
            $event_id = get_post_meta($product_id, '_event_id', true);
            if ($event_id) {
                return (int) $event_id;
            }
        }
        return null;
    }

    /** Dismissible warning when the probetraining status is missing (ab-webhook-endpoint inactive). */
    public static function render_not_configured_notice() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $message = 'Probetraining-Status nicht gefunden — ist das Plugin ab-webhook-endpoint aktiv? '
            . 'Conversions werden bis dahin nicht gezählt.';
        printf(
            '<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
            esc_html($message)
        );
    }
}
