/**
 * pot-attribution.js — first-touch UTM capture with consent-gated cookie promotion.
 *
 * ATTRIB-01: UTM (campaign/source/medium) + landing_path are parsed on load. Until analytics
 * consent is present the first-touch object is held in sessionStorage ONLY — no cookie is written
 * before consent (TTDSG §25). Once consent is present (now, on a next-pageload re-check, or via a
 * light same-pageload poll) the held value is promoted into the first-party `pot_attribution`
 * cookie (SameSite=Lax, path=/, 90 days, not HttpOnly). First-touch wins: an existing cookie is
 * never overwritten. Logged-in admins never load this script (enqueue is skipped server-side).
 *
 * The consent manager dispatches NO consent-change event, so there is intentionally NO
 * addEventListener for one — promotion relies on the on-load check, the next-pageload re-check,
 * and an optional self-clearing poll.
 */
(function () {
    'use strict';

    var cfg = window.potAttribution || {};

    // Admin skip (belt-and-suspenders; enqueue already skips admins).
    if (cfg.isAdmin) {
        return;
    }

    var COOKIE_NAME = cfg.cookieName || 'pot_attribution';
    var COOKIE_DAYS = cfg.cookieDays || 90;
    var PENDING_KEY = 'pot_attribution_pending';

    // UTM parse helper (verbatim from analytics-tracker.js).
    function getParam(name) {
        var match = location.search.match(new RegExp('[?&]' + name + '=([^&]*)'));
        return match ? decodeURIComponent(match[1]) : '';
    }

    function getCookie(name) {
        var match = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
        return match ? decodeURIComponent(match[1]) : '';
    }

    function hasAnalyticsConsent() {
        return !!(window.poConsent && window.poConsent.categories && window.poConsent.categories.analytics);
    }

    function writeCookie(value) {
        var expires = new Date();
        expires.setTime(expires.getTime() + COOKIE_DAYS * 24 * 60 * 60 * 1000);
        document.cookie = COOKIE_NAME + '=' + encodeURIComponent(value) +
            '; expires=' + expires.toUTCString() + '; path=/; SameSite=Lax';
    }

    // FIRST-TOUCH: an existing cookie wins — never overwrite, nothing else to do.
    if (getCookie(COOKIE_NAME)) {
        return;
    }

    // Build (or restore) the first-touch object held until consent.
    function getFirstTouch() {
        var stashed = null;
        try {
            stashed = sessionStorage.getItem(PENDING_KEY);
        } catch (e) {
            stashed = null;
        }
        if (stashed) {
            try {
                var parsed = JSON.parse(stashed);
                if (parsed && typeof parsed === 'object') {
                    return parsed;
                }
            } catch (e) { /* fall through to fresh parse */ }
        }
        return {
            campaign: getParam('utm_campaign'),
            source: getParam('utm_source'),
            medium: getParam('utm_medium'),
            landing_path: location.pathname,
            first_seen: new Date().toISOString()
        };
    }

    var firstTouch = getFirstTouch();

    // Stash the first-touch value in sessionStorage so it survives until consent is granted.
    try {
        sessionStorage.setItem(PENDING_KEY, JSON.stringify(firstTouch));
    } catch (e) { /* sessionStorage unavailable — promotion still works in-memory */ }

    function promote() {
        if (getCookie(COOKIE_NAME)) {
            return true; // already promoted (e.g. another tab)
        }
        writeCookie(JSON.stringify(firstTouch));
        try {
            sessionStorage.removeItem(PENDING_KEY);
        } catch (e) { /* ignore */ }
        return true;
    }

    // (1) On load: if consent is already granted, promote immediately.
    if (hasAnalyticsConsent()) {
        promote();
        return;
    }

    // (2) Next-pageload re-check is the guaranteed path; no consent-change event exists.
    // (3) Optional light same-pageload poll for the accept-without-reload case; self-clearing.
    var ticks = 0;
    var poll = setInterval(function () {
        ticks++;
        if (hasAnalyticsConsent()) {
            promote();
            clearInterval(poll);
        } else if (ticks > 60) {
            // Give up after ~30s; the next-pageload re-check still covers later accepts.
            clearInterval(poll);
        }
    }, 500);
})();
