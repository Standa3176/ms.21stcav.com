// Source: D-01..D-04 + WordPress hook reference + legacy plugin AnalyticsHelper
// Deploy as a mu-plugin OR as a wp_footer-hooked snippet on meetingstore.co.uk
// File: ms-utm-capture.js (~30 lines as CONTEXT specifies)

(function () {
    var COOKIE_NAME = 'ms_utm_first_touch';
    var TTL_DAYS = 30;
    var KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];

    function readGaClientId() {
        // GA cookie format: GA1.2.<client_id>.<timestamp>
        var match = document.cookie.match(/(?:^|;\s*)_ga=([^;]+)/);
        if (!match) return '';
        var parts = match[1].split('.');
        return parts.length >= 4 ? parts[2] + '.' + parts[3] : '';
    }

    function getCookie(name) {
        var m = document.cookie.match(new RegExp('(?:^|;\\s*)' + name + '=([^;]+)'));
        return m ? decodeURIComponent(m[1]) : null;
    }

    function setCookie(name, value, days) {
        var d = new Date(); d.setTime(d.getTime() + days * 86400000);
        document.cookie = name + '=' + encodeURIComponent(value) + ';expires=' + d.toUTCString() + ';path=/;SameSite=Lax';
    }

    // Build the touch object from current URL (if UTMs present) — first-touch only
    var url = new URLSearchParams(window.location.search);
    var hasNewUtm = KEYS.some(function (k) { return url.get(k); });

    var existing = getCookie(COOKIE_NAME);
    var touch = existing ? JSON.parse(existing) : null;

    if (hasNewUtm || !touch) {
        touch = {};
        KEYS.forEach(function (k) { touch[k] = url.get(k) || ''; });
        touch._ga = readGaClientId();
        setCookie(COOKIE_NAME, JSON.stringify(touch), TTL_DAYS);
    }

    // On checkout page, inject hidden inputs (works whether or not jQuery is present)
    document.addEventListener('DOMContentLoaded', function () {
        var form = document.querySelector('form.woocommerce-checkout, form.checkout');
        if (!form || !touch) return;
        Object.keys(touch).forEach(function (k) {
            if (form.querySelector('input[name="ms_utm_' + k + '"]')) return; // idempotent
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'ms_utm_' + k;
            input.value = touch[k] || '';
            form.appendChild(input);
        });
    });
})();
