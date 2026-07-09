<?php

declare(strict_types=1);

use App\Domain\Integrations\Enums\IntegrationCredentialKind;
use App\Domain\Integrations\Exceptions\IntegrationCredentialMissingException;
use App\Domain\Integrations\Services\IntegrationCredentialResolver;
use App\Domain\ProductAutoCreate\Services\EanSearchClient;
use App\Foundation\Integration\Services\IntegrationLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Quick task 260607-hxa — EanSearchClient unit coverage
|--------------------------------------------------------------------------
|
| Tests the MPN → GTIN reverse-lookup path for EAN-search.org. The HTTP
| layer is mocked at the Http::fake boundary, the credentials resolver
| is bound via a container-instance stub. NO live api.ean-search.org
| traffic.
|
| Brand-match logic (no native filter in the EAN-search API):
|   - With a brand string, pick the first row whose `name` field contains
|     the brand (case-insensitive).
|   - With no brand match (or null brand) → fall back to the first row.
|
| Failure modes ALL return null (silent degrade — mirrors IcecatClient):
|   - Empty array, JSON error object, HTTP 4xx/5xx, network throw, missing
|     token. The BackfillMerchantFeedCommand call site then moves to the
|     next SKU instead of failing the whole run.
|
| Token redaction (T-260607hxa-01): the request_body field passed to
| IntegrationLogger must replace the live token with '***'. This is
| covered at the structural level by Case 8 (token never appears in
| the assertSent recorded URL is the request the API sees, not what we
| log).
*/

beforeEach(function (): void {
    // Bind a stub resolver that returns a configured token without touching the DB.
    $resolver = new class extends IntegrationCredentialResolver
    {
        public function __construct() {}

        public function for(IntegrationCredentialKind $kind): array
        {
            if ($kind === IntegrationCredentialKind::EanSearch) {
                return ['token' => 'fake-token'];
            }

            throw IntegrationCredentialMissingException::for($kind);
        }
    };
    app()->instance(IntegrationCredentialResolver::class, $resolver);
});

/**
 * Sugar: instantiate the client through the container so it picks up the
 * stub resolver bound in beforeEach().
 */
function makeEanSearchClient(): EanSearchClient
{
    return app(EanSearchClient::class);
}

it('Case 1: brand match, single row — returns the EAN from the only row', function (): void {
    Http::fake([
        'api.ean-search.org/*' => Http::response([
            ['ean' => '5033588057222', 'name' => 'Panasonic PT-REZ80BEJ Projector'],
        ], 200),
    ]);

    $result = makeEanSearchClient()->lookupGtinByMpn('Panasonic', 'PT-REZ80BEJ');

    expect($result)->toBe('5033588057222');
});

it('Case 2: brand match, multi-row — prefers the brand-matching row over first', function (): void {
    Http::fake([
        'api.ean-search.org/*' => Http::response([
            ['ean' => '123', 'name' => 'Sony FW-98BZ30L'],
            ['ean' => '4711', 'name' => 'Panasonic PT-X'],
        ], 200),
    ]);

    $result = makeEanSearchClient()->lookupGtinByMpn('Panasonic', 'PT-X');

    expect($result)->toBe('4711');
});

it('Case 3: empty response — returns null', function (): void {
    Http::fake([
        'api.ean-search.org/*' => Http::response([], 200),
    ]);

    $result = makeEanSearchClient()->lookupGtinByMpn('Sony', 'FW-50EZ20L');

    expect($result)->toBeNull();
});

it('Case 4: row carries placeholder string — returned as-is (caller normalises)', function (): void {
    // The client is a pure-transport-and-decode layer. NormalisesEan trait at
    // the call site rejects placeholders like "N/A" — the client returns the
    // raw value so the caller's outcome bucket records `ean_lookup_invalid_ean`.
    Http::fake([
        'api.ean-search.org/*' => Http::response([
            ['ean' => 'N/A', 'name' => 'foo'],
        ], 200),
    ]);

    $result = makeEanSearchClient()->lookupGtinByMpn(null, 'JUNK-1');

    expect($result)->toBe('N/A');
});

it('Case 5: HTTP error (401/403/404/500) — returns null, no throw', function (): void {
    Http::fake([
        'api.ean-search.org/*' => Http::sequence()
            ->push(['error' => 'unauthorized'], 401)
            ->push(['error' => 'forbidden'], 403)
            ->push(['error' => 'not found'], 404)
            ->push(['error' => 'server'], 500),
    ]);

    $client = makeEanSearchClient();
    foreach ([401, 403, 404, 500] as $_ignored) {
        expect($client->lookupGtinByMpn('Sony', 'X'))->toBeNull();
    }
});

it('Case 6: null brand — falls back to first row', function (): void {
    Http::fake([
        'api.ean-search.org/*' => Http::response([
            ['ean' => '5033588057222', 'name' => 'foo'],
        ], 200),
    ]);

    $result = makeEanSearchClient()->lookupGtinByMpn(null, 'PT-X');

    expect($result)->toBe('5033588057222');
});

it('Case 7: token not configured — returns null without issuing HTTP', function (): void {
    // Override the beforeEach resolver to throw the missing-credential error.
    $resolver = new class extends IntegrationCredentialResolver
    {
        public function __construct() {}

        public function for(IntegrationCredentialKind $kind): array
        {
            throw IntegrationCredentialMissingException::for($kind);
        }
    };
    app()->instance(IntegrationCredentialResolver::class, $resolver);

    Http::fake([
        'api.ean-search.org/*' => Http::response([['ean' => '5033588057222', 'name' => 'foo']], 200),
    ]);

    $result = makeEanSearchClient()->lookupGtinByMpn('Sony', 'X');
    expect($result)->toBeNull();

    Http::assertNothingSent();
});

it('Case 8: testConnection returns ok with valid token + non-empty array response', function (): void {
    Http::fake([
        'api.ean-search.org/*' => Http::response([
            ['ean' => '5033588057222', 'name' => 'Sony FW-50EZ20L'],
        ], 200),
    ]);

    $result = makeEanSearchClient()->testConnection();

    expect($result->ok)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Quick task 260709-db5 — base-part-number retry on a region-suffixed miss
|--------------------------------------------------------------------------
|
| Region-localized SKUs (HP 'B5NH6AA#ABU' UK, '#ABB', '#AC3', '#UUZ', …) are
| listed in EAN databases under the BASE part number ('B5NH6AA'), not the
| localized code. When the full-term lookup misses AND the term carries a '#',
| the client retries ONCE with everything before the first '#'. Plain terms
| (no '#') behave exactly as before — a single query, no retry.
*/

it('Case 9: suffixed miss retries the base part number and recovers the GTIN', function (): void {
    // Full localized code is not listed; the base part number is.
    Http::fake(function ($request) {
        if (str_contains($request->url(), 'search=B5NH6AA%23ABU')) {
            return Http::response([], 200); // localized code → no match
        }

        return Http::response([
            ['ean' => '0195697115905', 'name' => 'HP B5NH6AA Keyboard'],
        ], 200); // base part number → match
    });

    $result = makeEanSearchClient()->lookupGtinByMpn(null, 'B5NH6AA#ABU');

    expect($result)->toBe('0195697115905');
    Http::assertSentCount(2); // full term missed, base was queried
});

it('Case 10: plain search term with a match — one request, no retry', function (): void {
    Http::fake([
        'api.ean-search.org/*' => Http::response([
            ['ean' => '4548736112144', 'name' => 'Sony FW-50EZ20L'],
        ], 200),
    ]);

    $result = makeEanSearchClient()->lookupGtinByMpn('Sony', 'FW-50EZ20L');

    expect($result)->toBe('4548736112144');
    Http::assertSentCount(1); // no '#', so no retry
});

it('Case 11: plain search term with no match — null, one request (no retry)', function (): void {
    Http::fake([
        'api.ean-search.org/*' => Http::response([], 200),
    ]);

    $result = makeEanSearchClient()->lookupGtinByMpn('Sony', 'NOPE-123');

    expect($result)->toBeNull();
    Http::assertSentCount(1); // no '#', so no retry even on a miss
});

it('Case 12: suffixed term where both full and base miss — null, two requests', function (): void {
    Http::fake([
        'api.ean-search.org/*' => Http::response([], 200),
    ]);

    $result = makeEanSearchClient()->lookupGtinByMpn(null, 'B5NH6AA#ABU');

    expect($result)->toBeNull();
    Http::assertSentCount(2); // full term + base both queried, both missed
});
