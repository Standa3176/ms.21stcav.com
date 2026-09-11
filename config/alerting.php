<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Alerting — operational monitoring that is not domain-specific
|--------------------------------------------------------------------------
|
| Quick task 260911-t80. Pricing, competitor and sync alerts live with their
| own domains; this file is for checks on the infrastructure the whole app
| depends on.
*/

return [

    /*
    |----------------------------------------------------------------------
    | TLS certificate expiry (tls:check-expiry)
    |----------------------------------------------------------------------
    |
    | 2026-09-11: meetingstore.co.uk's certificate expired at 09:34 UTC. It
    | was discovered seven hours later by a catalogue-wide trade:sync failing
    | 4,333 times — customers had been meeting a browser security warning the
    | whole time, and every Woo write from this app had been failing too.
    |
    | The cause was a gap between two certificate systems on the one box:
    | certbot renews the three domains it issued (systemd timer, healthy)
    | while the CWP AutoSSL domains — including the storefront — had NO
    | renewal scheduled at all. staging.meetingstore.co.uk had been expired
    | since 1 May and nothing reported it for four months.
    |
    | The host list is bound here rather than derived from WOO_URL alone,
    | because the app depends on more than one hostname and a check that only
    | watches what it writes to would have missed the ops domain entirely.
    */
    'tls' => [

        // Warn this many days out. 14 gives two clear weekends to act, and
        // sits inside Let's Encrypt's 30-day renewal window so a warning
        // always means renewal is possible right now, not "come back later".
        'warn_days' => (int) env('TLS_WARN_DAYS', 14),

        'hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env(
            'TLS_CHECK_HOSTS',

            // Defaults: the storefront this app writes to, and the ops app
            // itself. Add or override per environment via TLS_CHECK_HOSTS.
            'meetingstore.co.uk,ms.21stcav.com',
        ))))),
    ],

];
