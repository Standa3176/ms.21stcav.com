<?php

declare(strict_types=1);

namespace App\Domain\Alerting\Contracts;

/**
 * Quick task 260911-t80 — seam for reading a host's live TLS certificate.
 *
 * Exists so tests can substitute the network. A test that opened real sockets
 * would pass or fail on somebody else's infrastructure, and — worse — would go
 * green the day the very thing it is meant to catch gets fixed.
 */
interface TlsInspector
{
    /**
     * @return array{host:string, ok:bool, expires_at:?\DateTimeImmutable, days_left:?int, subject:?string, issuer:?string, error:?string}
     */
    public function inspect(string $host, int $port = 443, int $timeoutSeconds = 10): array;
}
