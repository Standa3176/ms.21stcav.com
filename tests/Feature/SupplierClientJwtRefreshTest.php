<?php

declare(strict_types=1);

use App\Domain\Sync\Exceptions\JwtRefreshFailedException;
use App\Domain\Sync\Services\SupplierClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    Cache::flush();
    // correlation_id column is VARCHAR(36) — use a plain UUID.
    Context::add('correlation_id', (string) Str::uuid());
    config([
        'services.supplier.url' => 'https://fake-supplier.test',
        'services.supplier.username' => 'testuser',
        'services.supplier.password' => 'testpass',
    ]);
});

// ─────────────────────────────────────────────────────────────
// Test J1: 401 → purge cache → fetch fresh token → retry succeeds (SYNC-02)
// ─────────────────────────────────────────────────────────────
it('on 401 purges cached token, fetches fresh token, retries once, succeeds (SYNC-02)', function () {
    // Seed cache with a stale token so the first call uses it
    Cache::put('supplier.jwt.' . md5('testuser'), 'stale-token', now()->addMinutes(50));

    // Track data-endpoint calls so we can return 401 on the first, 200 on the retry
    $dataCallCount = 0;

    Http::fake(function ($request) use (&$dataCallCount) {
        if (str_contains($request->url(), '/generate_token.php')) {
            return Http::response(['token' => 'fresh-jwt', 'expires_in' => 3600], 200);
        }

        if (str_contains($request->url(), '/api/index.php')) {
            $dataCallCount++;
            return $dataCallCount === 1
                ? Http::response(['error' => 'unauthenticated'], 401)
                : Http::response(
                    ['data' => [['sku' => 'SKU-Y', 'price' => '1.00', 'stock' => 1]], 'next_page' => null],
                    200
                );
        }

        return Http::response([], 404);
    });

    $result = app(SupplierClient::class)->fetchAllProducts();

    expect($result)->toHaveKey('SKU-Y');
    expect($dataCallCount)->toBe(2); // original 401 + successful retry

    // Verify fresh token was fetched (via /generate_token.php) after the 401
    $tokenCalls = collect(Http::recorded())
        ->filter(fn ($pair) => str_contains($pair[0]->url(), '/generate_token.php'))
        ->count();
    expect($tokenCalls)->toBeGreaterThanOrEqual(1);
});

// ─────────────────────────────────────────────────────────────
// Test J2: Second 401 after refresh throws JwtRefreshFailedException (D-06c)
// ─────────────────────────────────────────────────────────────
it('throws JwtRefreshFailedException when retry also returns 401 (D-06c, no infinite loop)', function () {
    Http::fake(function ($request) {
        if (str_contains($request->url(), '/generate_token.php')) {
            return Http::response(['token' => 'new-but-useless-jwt', 'expires_in' => 3600], 200);
        }
        if (str_contains($request->url(), '/api/index.php')) {
            return Http::response(['error' => 'still unauthenticated'], 401);
        }
        return Http::response([], 404);
    });

    expect(fn () => app(SupplierClient::class)->fetchAllProducts())
        ->toThrow(JwtRefreshFailedException::class);
});

// ─────────────────────────────────────────────────────────────
// Test J3: Cache key includes md5(username) — credential rotation invalidates cache (Pitfall P2-B)
// ─────────────────────────────────────────────────────────────
it('cache key is hashed by username so credential rotation invalidates cache (Pitfall P2-B)', function () {
    // Seed cache with a token under the OLD username's key
    Cache::put('supplier.jwt.' . md5('olduser'), 'old-token', now()->addMinutes(50));

    // Switch username in config — the old cache key is no longer looked up
    config(['services.supplier.username' => 'newuser']);

    Http::fake([
        'https://fake-supplier.test/generate_token.php' => Http::response(
            ['token' => 'new-token-for-newuser', 'expires_in' => 3600],
            200
        ),
        'https://fake-supplier.test/api/index.php*' => Http::response(
            ['data' => [['sku' => 'Z', 'price' => '5.00', 'stock' => 5]], 'next_page' => null],
            200
        ),
    ]);

    $result = app(SupplierClient::class)->fetchAllProducts();
    expect($result)->toHaveKey('Z');

    // The old key's value is untouched (still in cache)
    expect(Cache::get('supplier.jwt.' . md5('olduser')))->toBe('old-token');

    // A NEW cache entry exists for the new username
    expect(Cache::get('supplier.jwt.' . md5('newuser')))->toBe('new-token-for-newuser');

    // Token.generate was called (because newuser's cache was empty)
    $tokenCalls = collect(Http::recorded())
        ->filter(fn ($pair) => str_contains($pair[0]->url(), '/generate_token.php'))
        ->count();
    expect($tokenCalls)->toBe(1);
});

// ─────────────────────────────────────────────────────────────
// Test J4: /generate_token.php returns 500 → JwtRefreshFailedException with descriptive message
// ─────────────────────────────────────────────────────────────
it('throws JwtRefreshFailedException with HTTP status in message when token endpoint fails', function () {
    Http::fake([
        'https://fake-supplier.test/generate_token.php' => Http::response(
            ['error' => 'internal server error'],
            500
        ),
    ]);

    try {
        app(SupplierClient::class)->fetchAllProducts();
        $this->fail('Expected JwtRefreshFailedException to be thrown');
    } catch (JwtRefreshFailedException $e) {
        expect($e->getMessage())->toContain('500')
            ->and($e->getMessage())->toContain('token');
    }
});
