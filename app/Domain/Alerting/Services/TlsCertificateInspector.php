<?php

declare(strict_types=1);

namespace App\Domain\Alerting\Services;

use App\Domain\Alerting\Contracts\TlsInspector;

/**
 * Quick task 260911-t80 — reads the TLS certificate a host is serving.
 *
 * WHY THIS EXISTS
 *
 * On 2026-09-11 the certificate for meetingstore.co.uk expired at 09:34 UTC.
 * Nobody noticed until `trade:sync --live` failed 4,333 times with
 * "cURL Error: SSL certificate problem: certificate has expired" — about
 * seven hours later, and only because somebody happened to be running a
 * catalogue-wide job. Every customer visiting the shop in that window got a
 * browser security warning first.
 *
 * The cause was structural, not an oversight on the day: certbot renews the
 * three domains it issued (systemd timer, working fine) while the CWP AutoSSL
 * domains — including the shop — had NO renewal scheduled at all. A sibling
 * cert, staging.meetingstore.co.uk, had been expired since 1 May and nothing
 * had reported it in four months.
 *
 * This inspector is deliberately independent of which tool owns renewal: it
 * asks the socket what is actually being served, so it stays true if the
 * domain moves between certbot and CWP, or if a renewal succeeds on disk but
 * the web server was never reloaded — a failure mode neither tool reports.
 *
 * Pure I/O against the network, no DB. The command owns the thresholds.
 */
final class TlsCertificateInspector implements TlsInspector
{
    /**
     * Inspect one host's live certificate.
     *
     * Never throws: an unreachable host is itself a finding the caller must be
     * able to report, not an exception that aborts a sweep over other hosts.
     *
     * @return array{host:string, ok:bool, expires_at:?\DateTimeImmutable, days_left:?int, subject:?string, issuer:?string, error:?string}
     */
    public function inspect(string $host, int $port = 443, int $timeoutSeconds = 10): array
    {
        $base = [
            'host' => $host,
            'ok' => false,
            'expires_at' => null,
            'days_left' => null,
            'subject' => null,
            'issuer' => null,
            'error' => null,
        ];

        // capture_peer_cert returns the chain regardless of validity, which is
        // the point: an EXPIRED cert must still be readable so we can say when
        // it expired. verify_peer is off for the same reason.
        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                'verify_peer' => false,
                'verify_peer_name' => false,
                'SNI_enabled' => true,
                'peer_name' => $host,
            ],
        ]);

        $client = @stream_socket_client(
            'ssl://'.$host.':'.$port,
            $errno,
            $errstr,
            $timeoutSeconds,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if ($client === false) {
            $base['error'] = $errstr !== '' ? $errstr : 'connection failed (errno '.$errno.')';

            return $base;
        }

        $params = stream_context_get_params($client);
        fclose($client);

        $cert = $params['options']['ssl']['peer_certificate'] ?? null;
        if ($cert === null) {
            $base['error'] = 'no certificate presented';

            return $base;
        }

        $parsed = @openssl_x509_parse($cert);
        if (! is_array($parsed) || ! isset($parsed['validTo_time_t'])) {
            $base['error'] = 'certificate could not be parsed';

            return $base;
        }

        $expires = (new \DateTimeImmutable)->setTimestamp((int) $parsed['validTo_time_t']);

        // Whole days, rounded DOWN and signed — a cert expiring in 14 hours
        // reports 0, not 1, and an expired one reports a negative number.
        $secondsLeft = $expires->getTimestamp() - time();
        $daysLeft = (int) floor($secondsLeft / 86400);

        return [
            'host' => $host,
            'ok' => true,
            'expires_at' => $expires,
            'days_left' => $daysLeft,
            'subject' => $this->flatten($parsed['subject'] ?? []),
            'issuer' => $this->flatten($parsed['issuer'] ?? []),
            'error' => null,
        ];
    }

    /** @param array<string, mixed> $parts */
    private function flatten(array $parts): string
    {
        $out = [];
        foreach ($parts as $k => $v) {
            $out[] = $k.'='.(is_array($v) ? implode('/', $v) : (string) $v);
        }

        return implode(', ', $out);
    }
}
