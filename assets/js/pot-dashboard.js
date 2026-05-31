/**
 * pot-dashboard.js — progressive-enhancement AJAX re-render for the Campaign Tracking
 * dashboard. The initial table is server-rendered (Plan 01) and works without JS; this
 * script only re-queries wp_ajax_pot_metrics on user interaction and swaps the prebuilt,
 * already-escaped row markup the server returns. No external libraries, no templating.
 *
 * Config (ajaxurl, nonce) comes from the localized `potDashboard` object — never hardcoded.
 */
jQuery(document).ready(function ($) {
    // Bail quietly if the localized config is missing (page renders fine server-side).
    if (typeof potDashboard === 'undefined') {
        return;
    }

    var $form       = $('#pot-dashboard-controls');
    var $preset     = $('#pot-preset');
    var $from       = $('#pot-from');
    var $to         = $('#pot-to');
    var $customWrap = $('.pot-custom-dates');
    var $refresh    = $('#pot-refresh');
    var $spinner    = $('#pot-spinner');
    var $body       = $('#pot-metrics-body');
    var $foot       = $('#pot-metrics-foot');
    var $rangeLabel = $('#pot-range-label');

    var inFlight = false;

    // Toggle the custom from/to inputs based on the selected preset.
    function syncCustomVisibility() {
        if ($preset.val() === 'benutzerdefiniert') {
            $customWrap.show();
        } else {
            $customWrap.hide();
        }
    }

    function setLoading(on) {
        inFlight = on;
        $preset.prop('disabled', on);
        $from.prop('disabled', on);
        $to.prop('disabled', on);
        $refresh.prop('disabled', on);
        if (on) {
            $spinner.addClass('is-active');
        } else {
            $spinner.removeClass('is-active');
        }
    }

    function clearError() {
        $('#pot-ajax-error').remove();
    }

    function showError(message) {
        clearError();
        // Graceful inline message; never wipes the existing table.
        $('<div/>', {
            id: 'pot-ajax-error',
            'class': 'notice notice-error',
            html: '<p>' + message + '</p>'
        }).insertAfter($form);
    }

    // The health banner lives just before the control form; toggle it from the payload.
    function syncHealthBanner(health) {
        var $banner = $('.pot-health-banner');
        if (health && health !== 'ok') {
            if ($banner.length === 0) {
                $('<div/>', {
                    'class': 'notice notice-error pot-health-banner',
                    html: '<p>Conversion-Tracking ist offline — ist das Plugin ab-webhook-endpoint aktiv? Buchungen werden derzeit nicht gezählt.</p>'
                }).insertBefore($form);
            }
        } else {
            $banner.remove();
        }
    }

    function refresh() {
        if (inFlight) {
            return;
        }
        clearError();
        setLoading(true);

        $.ajax({
            url: potDashboard.ajaxurl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'pot_metrics',
                nonce: potDashboard.nonce, // key MUST be 'nonce' to match check_ajax_referer('pot_metrics','nonce')
                preset: $preset.val(),
                from: $from.val(),
                to: $to.val()
            },
            success: function (response) {
                if (!response || response.success !== true || !response.data) {
                    showError('Daten konnten nicht geladen werden.');
                    return;
                }
                var data = response.data;
                // Server returns ready-to-insert, already-escaped row markup — just swap it.
                if (data.rows && $.trim(data.rows) !== '') {
                    $body.html(data.rows);
                }
                // If rows is empty/blank, leave the existing server-rendered body in place.
                if (typeof data.totals !== 'undefined') {
                    $foot.html(data.totals);
                }
                if (typeof data.range !== 'undefined') {
                    $rangeLabel.text(data.range);
                }
                syncHealthBanner(data.health);
            },
            error: function () {
                showError('Fehler beim Laden der Kennzahlen. Bitte erneut versuchen.');
            },
            complete: function () {
                setLoading(false);
            }
        });
    }

    // Bindings: preset change, custom-date change, and the manual Aktualisieren button.
    $preset.on('change', function () {
        syncCustomVisibility();
        refresh();
    });

    $from.on('change', refresh);
    $to.on('change', refresh);

    $refresh.on('click', function (e) {
        e.preventDefault();
        refresh();
    });

    // Prevent the form's native GET submit so refresh stays AJAX (progressive enhancement).
    $form.on('submit', function (e) {
        e.preventDefault();
        refresh();
    });

    // Initial state only adjusts control visibility — the table is already server-rendered.
    syncCustomVisibility();
});
