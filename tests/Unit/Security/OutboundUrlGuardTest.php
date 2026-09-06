<?php

declare(strict_types=1);

use App\Domain\ProductAutoCreate\Services\OutboundUrlGuard;

/*
|--------------------------------------------------------------------------
| Quick task 260906-hbl — SSRF guard
|--------------------------------------------------------------------------
|
| ProductImageFetcher fetches $supplierData['image_url'] — supplier feed data
| delivered by FTP/CSV — with no validation at all before this. These pin the
| classes of URL that must never leave the host.
|
| DNS is stubbed through the constructor seam: the resolver is the one part
| that cannot run offline, and a test that silently passes because a lookup
| failed is worse than no test at all.
*/

/** @param array<string, array<int, string>> $map */
function guardWithDns(array $map): OutboundUrlGuard
{
    return new OutboundUrlGuard(fn (string $host): array => $map[$host] ?? []);
}

it('allows an ordinary public https image url', function (): void {
    $guard = guardWithDns(['cdn.example.com' => ['93.184.216.34']]);

    expect($guard->isAllowed('https://cdn.example.com/img/product-main.jpg'))->toBeTrue();
});

it('blocks non-http schemes', function (string $url): void {
    $reason = null;
    expect((new OutboundUrlGuard)->isAllowed($url, $reason))->toBeFalse()
        ->and($reason)->toBe('scheme_not_allowed');
})->with([
    'file:///etc/passwd',
    'gopher://127.0.0.1:6379/_SET%20x%20y',
    'ftp://internal/secrets',
    'data:text/html,<script>alert(1)</script>',
]);

it('blocks literal private, loopback and link-local addresses', function (string $url): void {
    $reason = null;
    expect((new OutboundUrlGuard)->isAllowed($url, $reason))->toBeFalse()
        ->and($reason)->toBe('private_or_reserved_ip');
})->with([
    'http://127.0.0.1/',
    'http://10.0.0.5/admin',
    'http://192.168.1.1/',
    'http://172.16.4.9/',
    'http://169.254.169.254/latest/meta-data/',  // cloud metadata
    'http://0.0.0.0/',
    'http://[::1]/',
    'http://[fc00::1]/',
]);

it('blocks a hostname that resolves into a private range', function (): void {
    // The rebinding shape: a public-looking name answering 127.0.0.1.
    $guard = guardWithDns(['evil.example.com' => ['127.0.0.1']]);

    $reason = null;
    expect($guard->isAllowed('http://evil.example.com/x', $reason))->toBeFalse()
        ->and($reason)->toBe('resolves_to_private_ip');
});

it('blocks a host that answers with BOTH a public and a private address', function (): void {
    $guard = guardWithDns(['mixed.example.com' => ['93.184.216.34', '10.1.2.3']]);

    expect($guard->isAllowed('http://mixed.example.com/x'))->toBeFalse();
});

it('blocks credentials in the authority', function (string $url): void {
    // Refused on shape, before host analysis — `http://trusted-cdn.com@127.0.0.1/`
    // reads as the CDN to a human and resolves to loopback for cURL, so the
    // useful thing is to reject the form outright rather than parse it well.
    $reason = null;
    expect((new OutboundUrlGuard)->isAllowed($url, $reason))->toBeFalse()
        ->and($reason)->toBe('credentials_in_url');
})->with([
    'http://user:pass@cdn.example.com/a.jpg',
    'http://cdn.example.com@127.0.0.1/a.jpg',
    'http://localhost.localdomain@127.0.0.1/',
]);

it('fails closed on unresolvable, empty and malformed input', function (): void {
    $guard = guardWithDns([]);

    expect($guard->isAllowed('http://nx.example.com/a.jpg'))->toBeFalse()
        ->and($guard->isAllowed(''))->toBeFalse()
        ->and($guard->isAllowed('   '))->toBeFalse()
        ->and($guard->isAllowed('http://'))->toBeFalse()
        ->and($guard->isAllowed('not a url at all'))->toBeFalse();
});
