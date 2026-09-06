<?php

declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Services;

/**
 * Quick task 260906-hbl — SSRF guard for outbound fetches of THIRD-PARTY URLs.
 *
 * WHY THIS EXISTS
 *
 * `ProductImageFetcher` issues a server-side HEAD+GET against a URL that comes
 * from `$supplierData['image_url']` (CreateWooProductJob:281) — i.e. straight
 * out of a supplier feed delivered by FTP/CSV — or from `WebImageSearchClient`,
 * whose results are steered by a prompt built from that same supplier text.
 * Neither source is trusted, and before this class there was NO validation at
 * all: no scheme check, no host check, no private-range check. A feed row
 * carrying `http://127.0.0.1:6379/` or `http://169.254.169.254/…` made the
 * queue worker issue that request from inside the network, and
 * `ProductImageFetcher::logAttempt()` recorded the resulting HTTP status into
 * `integration_logs` — turning a blind SSRF into an oracle a reader of that
 * table could mine.
 *
 * WHAT IT BLOCKS
 *
 *   - any scheme other than http/https (file://, gopher://, ftp://, data:)
 *   - credentials in the authority (`http://user:pass@host` — smuggling)
 *   - hosts that resolve to loopback, private, link-local, unique-local,
 *     multicast, reserved or unspecified addresses (IPv4 and IPv6)
 *   - literal-IP hosts in those ranges, without a DNS round trip
 *
 * WHAT IT DOES NOT CLAIM
 *
 * This is not a complete SSRF defence. A hostile DNS server can answer public
 * here and private on the request cURL makes moments later (DNS rebinding /
 * TOCTOU); closing that needs pinned resolution plus a Host header, which
 * breaks SNI on the CDNs this pipeline actually depends on. The redirect hop
 * check in ProductImageFetcher narrows the window; egress filtering is the
 * real answer and belongs in infrastructure, not here.
 */
final class OutboundUrlGuard
{
    /**
     * @param  (\Closure(string): array<int, string>)|null  $resolver  DNS lookup
     *                                                                 seam. Null uses the real resolver; tests inject a stub so the
     *                                                                 private-range assertions cannot silently pass on a failed lookup.
     */
    public function __construct(private ?\Closure $resolver = null) {}

    /**
     * True when $url is safe to fetch server-side.
     *
     * Fails CLOSED — anything unparseable, unresolvable or unexpected is
     * rejected. $reason is set to a short machine-readable token for logging.
     */
    public function isAllowed(string $url, ?string &$reason = null): bool
    {
        $url = trim($url);
        if ($url === '') {
            $reason = 'empty_url';

            return false;
        }

        $parts = parse_url($url);
        if ($parts === false || ! is_array($parts)) {
            $reason = 'unparseable_url';

            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true)) {
            $reason = 'scheme_not_allowed';

            return false;
        }

        // `http://evil@internal/` and `http://user:pass@host/` are parser-
        // confusion classics — refuse the whole shape rather than reason about it.
        if (isset($parts['user']) || isset($parts['pass'])) {
            $reason = 'credentials_in_url';

            return false;
        }

        $host = trim((string) ($parts['host'] ?? ''));
        if ($host === '') {
            $reason = 'missing_host';

            return false;
        }

        // Strip the brackets around an IPv6 literal so it reaches filter_var.
        $bare = trim($host, '[]');

        // A literal IP needs no DNS round trip.
        if (filter_var($bare, FILTER_VALIDATE_IP) !== false) {
            if (! $this->isPublicIp($bare)) {
                $reason = 'private_or_reserved_ip';

                return false;
            }

            return true;
        }

        $addresses = $this->resolve($bare);
        if ($addresses === []) {
            $reason = 'dns_resolution_failed';

            return false;
        }

        // EVERY answer must be public. A host answering both a public and a
        // private address is exactly the rebinding shape we refuse to serve.
        foreach ($addresses as $address) {
            if (! $this->isPublicIp($address)) {
                $reason = 'resolves_to_private_ip';

                return false;
            }
        }

        return true;
    }

    /**
     * Resolve a hostname to every A/AAAA answer. Returns [] on failure.
     *
     * @return array<int, string>
     */
    private function resolve(string $host): array
    {
        if ($this->resolver !== null) {
            return ($this->resolver)($host);
        }

        $out = [];

        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            $out = $v4;
        }

        $v6 = @dns_get_record($host, DNS_AAAA);
        if (is_array($v6)) {
            foreach ($v6 as $record) {
                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $out[] = $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * A public, routable address — the ONLY thing this pipeline may fetch.
     *
     * FILTER_FLAG_NO_PRIV_RANGE covers 10/8, 172.16/12, 192.168/16 and fc00::/7;
     * NO_RES_RANGE covers 0/8, 127/8, 169.254/16, 240/4, ::1, ::/128 and the
     * IPv6 documentation/reserved blocks.
     */
    private function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }
}
