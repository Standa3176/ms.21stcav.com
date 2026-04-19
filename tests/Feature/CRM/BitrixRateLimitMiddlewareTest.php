<?php

declare(strict_types=1);

use App\Domain\CRM\Services\BitrixRateLimitMiddleware;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

/*
|--------------------------------------------------------------------------
| Phase 4 Plan 02 Task 1 — rate-limit middleware
|--------------------------------------------------------------------------
|
| Bitrix cloud ceiling is ~2 requests/second. Middleware enforces token-bucket
| throttle + Retry-After on 429 with a single retry.
*/

beforeEach(function (): void {
    BitrixRateLimitMiddleware::reset();
});

function buildThrottledGuzzle(array $responses): Client
{
    $mock = new MockHandler($responses);
    $stack = HandlerStack::create($mock);
    $stack->push(new BitrixRateLimitMiddleware(), 'bitrix-rate-limit');

    return new Client(['handler' => $stack]);
}

it('enforces <=2 requests per second with 5 sequential calls', function (): void {
    $responses = array_fill(0, 5, new Response(200, [], '{}'));
    $client = buildThrottledGuzzle($responses);

    $start = microtime(true);
    for ($i = 0; $i < 5; $i++) {
        $client->request('GET', '/');
    }
    $elapsed = microtime(true) - $start;

    // 5 calls with a 2 req/sec ceiling → minimum 2 seconds to flush the bucket.
    expect($elapsed)->toBeGreaterThanOrEqual(2.0);
    // Safety cap — if this is over 5s the throttle is over-sleeping.
    expect($elapsed)->toBeLessThan(5.0);
});

it('sleeps Retry-After seconds on 429 then retries once', function (): void {
    $responses = [
        new Response(429, ['Retry-After' => '1']),
        new Response(200, [], '{"ok":true}'),
    ];
    $client = buildThrottledGuzzle($responses);

    $start = microtime(true);
    $response = $client->request('GET', '/');
    $elapsed = microtime(true) - $start;

    expect($response->getStatusCode())->toBe(200);
    expect($elapsed)->toBeGreaterThanOrEqual(1.0);
});

it('raises on second 429 (bubbles up)', function (): void {
    $responses = [
        new Response(429, ['Retry-After' => '1']),
        new Response(429, ['Retry-After' => '1']),
    ];
    $client = buildThrottledGuzzle($responses);

    // Second 429 returns as-is (middleware retries once). Guzzle does NOT by
    // default throw on 429 — the caller (SDK) handles the 429 bubble.
    $response = $client->request('GET', '/', ['http_errors' => false]);
    expect($response->getStatusCode())->toBe(429);
});

it('reset() clears the sliding window between tests', function (): void {
    // Prime the window with 2 timestamps.
    $mockClient = buildThrottledGuzzle([
        new Response(200, [], '{}'),
        new Response(200, [], '{}'),
    ]);
    $mockClient->request('GET', '/');
    $mockClient->request('GET', '/');

    BitrixRateLimitMiddleware::reset();

    // Fresh call should NOT be throttled — window is empty, no waiting required.
    $freshClient = buildThrottledGuzzle([new Response(200, [], '{}')]);
    $start = microtime(true);
    $freshClient->request('GET', '/');
    $elapsed = microtime(true) - $start;

    expect($elapsed)->toBeLessThan(0.5);
});
