/**
 * pot-tracker.js — consent-gated client capture of landing-page VISITS and
 * "Probetraining buchen" CTA CLICKS.
 *
 * CAPTURE-01: a visit beacon fires once per pageload — as client JS it fires even on a
 * fully-cached page (the dynamic REST route pot/v1/event does the counting, not PHP).
 * CAPTURE-02: a single delegated CAPTURE-phase click listener records a click on any
 * a[href*="/probetraining-buchen"] CTA, debounced against rapid double-clicks.
 * CAPTURE-03: nothing fires and no pot_sid is written before analytics consent is present.
 * CAPTURE-04: logged-in admins are skipped (enqueue already skips them; this is belt-and-suspenders).
 * CAPTURE-05: navigator.webdriver is an early client bot hint (the server denylist is authoritative).
 *
 * No IP/PII is sent. The nonce travels in the _wpnonce body (sendBeacon path, which cannot
 * set headers) and as the X-WP-Nonce header on the fetch keepalive fallback. There is NO
 * consent-change event — the consent check happens on load and re-runs only on the next pageload.
 */
(function () {
    'use strict';

    var cfg = window.potTracker || {};

    // Admin skip (belt-and-suspenders; enqueue already skips admins).
    if (cfg.isAdmin) {
        return;
    }

    // Client-side bot hint; the server-side UA denylist is authoritative.
    if (navigator.webdriver) {
        return;
    }

    // Consent gate — identical to pot-attribution.js. ALL behavior is gated behind this on load.
    function hasAnalyticsConsent() {
        return !!(window.poConsent && window.poConsent.categories && window.poConsent.categories.analytics);
    }

    if (!hasAnalyticsConsent()) {
        return;
    }

    var SESSION_KEY = 'pot_sid';
    var CTA_SELECTOR = 'a[href*="/probetraining-buchen"]';
    var DEBOUNCE_MS = 500;

    function getCookie(name) {
        var match = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
        return match ? decodeURIComponent(match[1]) : '';
    }

    // First-touch attribution from the pot_attribution cookie (empty defaults → server buckets
    // to UNATTRIBUTED). Malformed cookie is tolerated, never throws.
    function getAttribution() {
        var attr = { campaign: '', source: '', medium: '' };
        var raw = getCookie('pot_attribution');
        if (!raw) {
            return attr;
        }
        try {
            var parsed = JSON.parse(raw);
            if (parsed && typeof parsed === 'object') {
                attr.campaign = parsed.campaign || '';
                attr.source = parsed.source || '';
                attr.medium = parsed.medium || '';
            }
        } catch (e) { /* malformed cookie — send empty attribution */ }
        return attr;
    }

    // Session id (sessionStorage, non-PII, plugin's OWN pot_sid key — never the theme's session key).
    function getSessionId() {
        var sid = '';
        try {
            sid = sessionStorage.getItem(SESSION_KEY) || '';
        } catch (e) { sid = ''; }
        if (!sid) {
            sid = Math.random().toString(36).substr(2, 12) + Date.now().toString(36);
            try {
                sessionStorage.setItem(SESSION_KEY, sid);
            } catch (e) { /* sessionStorage unavailable — sid stays in-memory for this load */ }
        }
        return sid;
    }

    var attribution = getAttribution();
    var sessionId = getSessionId();

    // Send one event. The nonce is added to the JSON body as _wpnonce (sendBeacon cannot set
    // headers); the fetch fallback also sets the X-WP-Nonce header.
    function send(type) {
        var payload = {
            type: type,
            campaign: attribution.campaign,
            source: attribution.source,
            medium: attribution.medium,
            landing_path: location.pathname,
            session_id: sessionId,
            _wpnonce: cfg.nonce
        };
        var body = JSON.stringify(payload);

        if (navigator.sendBeacon) {
            navigator.sendBeacon(cfg.restUrl, new Blob([body], { type: 'application/json' }));
        } else {
            fetch(cfg.restUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
                body: body,
                keepalive: true
            }).catch(function () {});
        }
    }

    // CAPTURE-01: exactly one visit beacon per pageload (fires even on fully-cached pages).
    send('visit');

    // CAPTURE-02: delegated CAPTURE-phase click listener for the CTA, debounced on identical hrefs.
    var lastHref = '';
    var lastClickTs = 0;

    function handleClick(e) {
        var link = e.target.closest(CTA_SELECTOR);
        if (!link) {
            return;
        }
        var now = Date.now();
        var href = link.href || '';
        if (href === lastHref && (now - lastClickTs) < DEBOUNCE_MS) {
            return; // debounce rapid double-clicks on the same CTA
        }
        lastHref = href;
        lastClickTs = now;
        send('click');
    }

    document.addEventListener('click', handleClick, true);
})();
