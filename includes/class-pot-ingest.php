<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * POT_Ingest — dynamic REST ingest route for client-side visit/click events.
 *
 * Registers POST pot/v1/event. Because full-page caching serves static HTML without PHP,
 * landing-page VISITS and CTA CLICKS MUST be captured client-side and POSTed to this
 * dynamic route (never a server-side template_redirect counter).
 *
 * Trust boundary: the incoming POST is untrusted anonymous front-end input. The route is
 * gated by a real WP-REST nonce (header X-WP-Nonce, with a _wpnonce body fallback because
 * navigator.sendBeacon cannot set headers) — NEVER __return_true. The handler then excludes
 * admins and known bots, sanitizes + length-caps every field at the boundary, and writes
 * through POT_Store::insert_event.
 *
 * DSGVO: no IP ($_SERVER['REMOTE_ADDR']) and no full User-Agent is ever stored — the bot
 * check yields only a boolean decision. Consent gating happens client-side (pot-tracker.js);
 * the device-storage / network beacon never fires before analytics consent is granted.
 */
class POT_Ingest {

    /** Per-field length cap for text attribution fields (matches utm_* VARCHAR(100)). */
    const FIELD_MAX = 100;

    /** Length cap for the stored landing path. */
    const PATH_MAX = 500;

    /** Length cap for the session id. */
    const SESSION_MAX = 64;

    public static function init() {
        add_action('rest_api_init', ['POT_Ingest', 'register_routes']);
    }

    /**
     * Register POST pot/v1/event with a real nonce permission_callback and a constrained
     * `type` argument ({visit,click} only).
     */
    public static function register_routes() {
        register_rest_route('pot/v1', '/event', [
            'methods'             => 'POST',
            'callback'            => ['POT_Ingest', 'handle_event'],
            'permission_callback' => ['POT_Ingest', 'check_nonce'],
            'args'                => [
                'type' => [
                    'required'          => true,
                    'validate_callback' => function ($param) {
                        return in_array($param, ['visit', 'click'], true);
                    },
                ],
            ],
        ]);
    }

    /**
     * Real WP-REST nonce gate. Reads the X-WP-Nonce header first; falls back to the
     * _wpnonce body param because navigator.sendBeacon cannot set request headers.
     * NEVER __return_true (CAPTURE-01).
     *
     * @param WP_REST_Request $request
     * @return bool
     */
    public static function check_nonce(WP_REST_Request $request) {
        $nonce = $request->get_header('X-WP-Nonce');
        if (empty($nonce)) {
            $nonce = $request->get_param('_wpnonce');
        }
        return wp_verify_nonce($nonce, 'wp_rest') !== false;
    }

    /**
     * Handle one ingest event: admin guard → bot denylist → sanitize → write-through → 204.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function handle_event(WP_REST_Request $request) {
        // (1) Server-side admin guard — never count logged-in admins (CAPTURE-04).
        if (is_user_logged_in() && current_user_can('manage_options')) {
            return new WP_REST_Response(null, 204);
        }

        // (2) Bot denylist on the User-Agent. Only the boolean decision is used — the UA
        //     itself is NEVER stored (DSGVO). No IP is read either.
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if (empty($ua) || preg_match('/bot|crawl|spider|slurp|facebookexternalhit|Bytespider|GPTBot/i', $ua)) {
            return new WP_REST_Response(null, 204);
        }

        // (3) LP-03 hard allowlist gate. The client landing_path is UNTRUSTED, so it is
        //     normalized server-side through the single shared primitive and matched against
        //     the registered allowlist (object-cached, autoload=false option — no per-event DB
        //     query). A non-listed key is hard-dropped before insert; an EMPTY allowlist yields
        //     an empty $allowed so EVERY visit/click is dropped. A drop still returns 204 to
        //     match the admin/bot guard contract (fire-and-forget beacon). This gate NEVER sees
        //     a `booking` — bookings route through POT_Conversion, never this handler (LP-04).
        $key     = POT_Landing_Pages::normalize_path((string) $request->get_param('landing_path'));
        $entries = get_option('pot_landing_pages', []);
        $allowed = is_array($entries) ? wp_list_pluck($entries, 'key') : [];
        if (!in_array($key, $allowed, true)) {
            return new WP_REST_Response(null, 204);
        }

        // (4) Sanitize + length-cap every field at the boundary, then write through the gateway.
        //     landing_path stores the canonical normalized $key (D-07) so the per-landing-page
        //     aggregation is a simple indexed WHERE landing_path IN (...).
        POT_Store::insert_event([
            'event_type'   => sanitize_text_field((string) $request->get_param('type')),
            'campaign'     => substr(sanitize_text_field((string) $request->get_param('campaign')), 0, self::FIELD_MAX),
            'source'       => substr(sanitize_text_field((string) $request->get_param('source')), 0, self::FIELD_MAX),
            'medium'       => substr(sanitize_text_field((string) $request->get_param('medium')), 0, self::FIELD_MAX),
            'landing_path' => $key,
            'session_id'   => substr(preg_replace('/[^a-z0-9]/i', '', (string) $request->get_param('session_id')), 0, self::SESSION_MAX),
        ]);

        // (5) Fire-and-forget: no body needed by the beacon client.
        return new WP_REST_Response(null, 204);
    }
}
