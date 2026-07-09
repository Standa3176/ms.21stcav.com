<?php

declare(strict_types=1);

use App\Domain\Sync\Exceptions\RateLimitExceededException;
use App\Domain\Integrations\Services\IntegrationCredentialResolver;
use App\Domain\Sync\Models\SyncDiff;
use App\Domain\Sync\Services\WooClient;
use App\Foundation\Integration\Models\IntegrationEvent;
use App\Foundation\Integration\Services\IntegrationLogger;
use Automattic\WooCommerce\Client as AutomatticClient;
use Automattic\WooCommerce\HttpClient\HttpClientException;
use Automattic\WooCommerce\HttpClient\Request as WooRequest;
use Automattic\WooCommerce\HttpClient\Response as WooResponse;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['services.woo.write_enabled' => true]);
    // These backoff tests mock the SDK's put() verb directly. Production
    // routes put() through POST when services.woo.use_post_for_updates is true
    // (the default — WP-REST treats POST/PUT identically for updates). Pin the
    // flag off here so put() dispatches to $sdk->put() and the put() mocks match.
    config(['services.woo.use_post_for_updates' => false]);
    // correlation_id column is VARCHAR(36) — use a plain UUID, no prefix.
    Context::add('correlation_id', (string) Str::uuid());
});

/**
 * Helper: a partial WooClient that overrides usleep() for predictable timing tests.
 */
function rateLimitTestClient($mockInner): WooClient
{
    return new class (app(IntegrationLogger::class), app(IntegrationCredentialResolver::class), $mockInner) extends WooClient {
        /** @var int total usleep microseconds requested */
        public int $sleptMicros = 0;
        public array $sleptSamples = [];

        protected function sleepMicros(int $micros): void
        {
            $this->sleptMicros += $micros;
            $this->sleptSamples[] = $micros;
        }
    };
}

// ─────────────────────────────────────────────────────────────
// Test R1: 429 then 200 — retries after exponential backoff (≥500ms first delay)
// ─────────────────────────────────────────────────────────────
it('retries after 429 with exponential backoff and eventually succeeds', function () {
    $mockInner = Mockery::mock(AutomatticClient::class);
    $mockHttp = Mockery::mock();
    $mockInner->http = $mockHttp;

    // After first failed attempt, response code is 429; after retry, 200
    $codes = [429, 200];
    $mockHttp->shouldReceive('getResponse')
        ->andReturnUsing(function () use (&$codes) {
            $code = array_shift($codes) ?? 200;
            return new WooResponse($code, [], '');
        });

    $mockInner->shouldReceive('put')
        ->times(2)
        ->andReturnUsing(function () use (&$codes) {
            static $call = 0;
            $call++;
            if ($call === 1) {
                throw new HttpClientException(
                    '429 Too Many Requests',
                    429,
                    new WooRequest(),
                    new WooResponse(429, ['Retry-After' => ['0']], '')
                );
            }

            return ['id' => 1234, 'regular_price' => '99.99'];
        });

    $client = rateLimitTestClient($mockInner);
    $result = $client->put('products/1234', ['regular_price' => '99.99']);

    expect($result)->toHaveKey('id', 1234);
    // First backoff is computed 500ms (baseDelayMs * 3^0), jitter in [0, 500_000 micros].
    // Our stub captures the usleep micros; assert ≥ 500ms = 500_000 micros total was slept.
    expect($client->sleptMicros)->toBeGreaterThanOrEqual(500_000)
        ->and($client->sleptMicros)->toBeLessThan(2_000_000);
});

// ─────────────────────────────────────────────────────────────
// Test R2: Retry-After header (in seconds) honoured as the floor
// ─────────────────────────────────────────────────────────────
it('honours Retry-After header as the floor when larger than computed delay', function () {
    $mockInner = Mockery::mock(AutomatticClient::class);
    $mockHttp = Mockery::mock();
    $mockInner->http = $mockHttp;

    $codes = [429, 200];
    $mockHttp->shouldReceive('getResponse')
        ->andReturnUsing(function () use (&$codes) {
            $code = array_shift($codes) ?? 200;
            // Retry-After = 3 seconds
            return new WooResponse($code, ['Retry-After' => ['3']], '');
        });

    $mockInner->shouldReceive('put')
        ->times(2)
        ->andReturnUsing(function () {
            static $call = 0;
            $call++;
            if ($call === 1) {
                throw new HttpClientException(
                    '429',
                    429,
                    new WooRequest(),
                    new WooResponse(429, ['Retry-After' => ['3']], '')
                );
            }
            return ['id' => 1234];
        });

    $client = rateLimitTestClient($mockInner);
    $client->put('products/1234', ['regular_price' => '99.99']);

    // Retry-After: 3 → 3_000ms floor; computed 500ms; floor wins
    // Asserted: slept ≥ 3_000ms = 3_000_000 micros (plus jitter up to 500k)
    expect($client->sleptMicros)->toBeGreaterThanOrEqual(3_000_000)
        ->and($client->sleptMicros)->toBeLessThan(3_600_000);
});

// ─────────────────────────────────────────────────────────────
// Test R3: 5 consecutive 429s → throws RateLimitExceededException
// ─────────────────────────────────────────────────────────────
it('throws RateLimitExceededException after 5 consecutive 429s', function () {
    $mockInner = Mockery::mock(AutomatticClient::class);
    $mockHttp = Mockery::mock();
    $mockInner->http = $mockHttp;

    $mockHttp->shouldReceive('getResponse')->andReturn(new WooResponse(429, ['Retry-After' => ['0']], ''));
    $mockInner->shouldReceive('put')
        ->times(5)
        ->andThrow(new HttpClientException(
            '429 Too Many Requests',
            429,
            new WooRequest(),
            new WooResponse(429, ['Retry-After' => ['0']], '')
        ));

    // Make sleeps cheap by passing a dummy stub
    $client = rateLimitTestClient($mockInner);

    expect(fn () => $client->put('products/1234', ['x' => 1]))
        ->toThrow(RateLimitExceededException::class);
});

// ─────────────────────────────────────────────────────────────
// Test R4: Jitter — two runs with same mock produce different total sleep times
// ─────────────────────────────────────────────────────────────
it('applies jitter so consecutive retries have non-identical sleep totals', function () {
    $makeMock = function () {
        $mockInner = Mockery::mock(AutomatticClient::class);
        $mockHttp = Mockery::mock();
        $mockInner->http = $mockHttp;

        $codes = [429, 200];
        $mockHttp->shouldReceive('getResponse')
            ->andReturnUsing(function () use (&$codes) {
                $code = array_shift($codes) ?? 200;
                return new WooResponse($code, ['Retry-After' => ['0']], '');
            });

        $mockInner->shouldReceive('put')
            ->times(2)
            ->andReturnUsing(function () {
                static $call = 0;
                $call++;
                if ($call === 1) {
                    throw new HttpClientException(
                        '429',
                        429,
                        new WooRequest(),
                        new WooResponse(429, ['Retry-After' => ['0']], '')
                    );
                }
                return ['id' => 1234];
            });

        return $mockInner;
    };

    // Run 20 trials collecting slept micros; assert at least 2 distinct values.
    // (random_int(0, 500_000) jitter — collision probability ≈ 1/500k per pair, so 20 trials has
    // a vanishingly small chance of all-equal.)
    $samples = [];
    for ($i = 0; $i < 20; $i++) {
        $client = rateLimitTestClient($makeMock());
        $client->put('products/1234', ['x' => 1]);
        $samples[] = $client->sleptMicros;
    }

    expect(count(array_unique($samples)))->toBeGreaterThan(1);
});
