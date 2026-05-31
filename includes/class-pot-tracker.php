<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * POT_Tracker — enqueues assets/js/pot-tracker.js (admin-skipped) and localizes the
 * REST route + nonce + admin flag it needs.
 *
 * Mirrors POT_Attribution::enqueue: logged-in admins (manage_options) are skipped at
 * enqueue time (CAPTURE-04), so the tracker never even loads for them. The script itself
 * is consent-gated client-side (it reads window.poConsent before doing anything).
 *
 * The nonce is a wp_rest nonce; pot-tracker.js sends it in the _wpnonce body (sendBeacon)
 * and the X-WP-Nonce header (fetch fallback) so POT_Ingest::check_nonce can verify it.
 */
class POT_Tracker {

    public static function init() {
        // Front-end asset (admin-skip handled inside enqueue()).
        add_action('wp_enqueue_scripts', ['POT_Tracker', 'enqueue']);
    }

    /**
     * Enqueue the capture tracker for non-admins and localize the ingest config.
     */
    public static function enqueue() {
        $is_admin = (is_user_logged_in() && current_user_can('manage_options'));
        if ($is_admin) {
            return; // do not enqueue the tracker for admins (CAPTURE-04)
        }

        wp_enqueue_script('pot-tracker',
            POT_PLUGIN_URL . 'assets/js/pot-tracker.js',
            [],
            POT_VERSION,
            true
        );

        wp_localize_script('pot-tracker', 'potTracker', [
            'restUrl' => rest_url('pot/v1/event'),
            'nonce'   => wp_create_nonce('wp_rest'),
            'isAdmin' => $is_admin,
        ]);
    }
}
