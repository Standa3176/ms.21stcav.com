---
phase: 02-supplier-sync
plan: 02
type: execute
wave: 2
depends_on:
  - 02-01
files_modified:
  - composer.json
  - composer.lock
  - config/services.php
  - .env.example
  - app/Domain/Sync/Services/WooClient.php
  - app/Domain/Sync/Services/SupplierClient.php
  - app/Domain/Sync/Exceptions/JwtRefreshFailedException.php
  - app/Domain/Sync/Exceptions/RateLimitExceededException.php
  - app/Providers/AppServiceProvider.php
  - tests/Feature/ShadowModeTest.php
  - tests/Feature/SupplierClientTest.php
  - tests/Feature/SupplierClientJwtRefreshTest.php
  - tests/Feature/WooClientGetTest.php
  - tests/Feature/WooRateLimitTest.php
autonomous: true
requirements:
  - SYNC-01
  - SYNC-02
  - SYNC-04
  - SYNC-10

must_haves:
  truths:
    - "composer packages `automattic/woocommerce:^3.1` and `spatie/simple-excel:^3.9` are installed at caret pins matching composer.lock"
    - "WooClient::get(string, array) method reads from Woo REST via Automattic\\WooCommerce\\Client + logs via IntegrationLogger"
    - "WooClient write paths (put/post/patch/delete) call the real Woo client when WOO_WRITE_ENABLED=true AND handle Woo 429 with Retry-After + exponential-backoff + jitter (SYNC-10)"
    - "SupplierClient fetches the full 21stcav.com catalogue, caches JWT in Redis with 10-min safety margin, and retries once on 401 before throwing JwtRefreshFailedException (SYNC-02)"
    - "IntegrationLogger captures every outbound call (channel=woo|supplier, direction, latency, http_status, redacted headers, correlation_id) — preserves Phase 1 FOUND-05 invariant"
    - "Shadow-mode (WOO_WRITE_ENABLED=false) behaviour is unchanged from Phase 1 Plan 04 — ShadowModeTest green"
  artifacts:
    - path: "composer.json"
      provides: "`automattic/woocommerce:^3.1` + `spatie/simple-excel:^3.9` in require block"
    - path: "config/services.php"
      provides: "woo.url/consumer_key/consumer_secret/write_enabled (already) + NEW supplier.url/username/password"
    - path: "app/Domain/Sync/Services/WooClient.php"
      provides: "get(), writeLive() filling previous LogicException branch, typed \\$inner: Automattic\\WooCommerce\\Client"
    - path: "app/Domain/Sync/Services/SupplierClient.php"
      provides: "fetchAllProducts(): array (supplier SKU → [price, stock] hashmap), getToken() with Cache::remember"
    - path: "app/Domain/Sync/Exceptions/JwtRefreshFailedException.php"
      provides: "Exception thrown when supplier 401 persists after one refresh — D-06(c) abort trigger"
    - path: "app/Domain/Sync/Exceptions/RateLimitExceededException.php"
      provides: "Exception thrown when Woo 429 exhausts 5 retries — tracked as single SyncError, doesn't spam consecutive-failure counter"
    - path: "app/Providers/AppServiceProvider.php"
      provides: "singleton binding for Automattic\\WooCommerce\\Client + WooClient + SupplierClient"
  key_links:
    - from: "app/Domain/Sync/Services/WooClient.php"
      to: "Automattic\\WooCommerce\\Client (vendor)"
      via: "typed constructor property; writeLive() calls \\$this->inner->{method}"
      pattern: "Automattic\\\\WooCommerce\\\\Client"
    - from: "app/Domain/Sync/Services/WooClient.php"
      to: "app/Foundation/Integration/Services/IntegrationLogger.php"
      via: "every request + response logs via \\$this->logger->log(...)"
      pattern: "\\$this->logger->log"
    - from: "app/Domain/Sync/Services/SupplierClient.php"
      to: "Laravel HTTP facade (Illuminate\\Support\\Facades\\Http)"
      via: "Http::baseUrl + ->withToken(->getToken()) + ->timeout(30) + ->retry"
      pattern: "Http::(baseUrl|withToken|retry)"
    - from: "app/Domain/Sync/Services/SupplierClient.php"
      to: "Illuminate\\Contracts\\Cache\\Repository"
      via: "\\$this->cache->remember(\\$this->tokenCacheKey(), ...)"
      pattern: "->remember\\(.*token"
---

<objective>
Install `automattic/woocommerce ^3.1` + `spatie/simple-excel ^3.9`, then build the two external-API clients: (a) extend Phase 1's `WooClient` skeleton with a `get()` method + fill the `LogicException` branch with a real writeLive() that honours Woo 429 backoff (SYNC-10), (b) new `SupplierClient` that authenticates against 21stcav.com with Cache::remember'd JWT and retries once on 401 before giving up (SYNC-02 + D-06c). Both clients log every call through Phase 1's IntegrationLogger with auto-redacted headers.

This plan is pure I/O plumbing — no orchestrator, no jobs, no Filament. The WooClient becomes live-writeable; the SupplierClient can be invoked standalone to fetch the catalogue. Plan 03 wires both into SyncSupplierCommand.

Purpose: Isolate the external-API risk (A1, A2 — supplier API shape is MEDIUM confidence) into one plan. If 21stcav.com's shape differs from RESEARCH.md §2 assumptions, only this plan needs rework. Plan 03 can still proceed with a stubbed SupplierClient.

Output: 2 new services + 2 exceptions + updated config + 5 test files (94+ tests should be passing after this plan).

Scope additions beyond REQUIREMENTS.md:
- `spatie/simple-excel ^3.9` composer require (Plan 04 needs it; installed here to avoid duplicating composer.lock churn)
- `automattic/woocommerce` real client binding + `Automattic\\WooCommerce\\Client` $inner typing (fulfils Phase 1 P04 TODO)
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/PROJECT.md
@.planning/STATE.md
@.planning/phases/02-supplier-sync/02-CONTEXT.md
@.planning/phases/02-supplier-sync/02-RESEARCH.md
@.planning/phases/02-supplier-sync/02-01-SUMMARY.md
@.planning/phases/01-foundation/01-03-SUMMARY.md
@.planning/phases/01-foundation/01-04-SUMMARY.md
@.planning/research/STACK.md
@.planning/research/PITFALLS.md
@app/Domain/Sync/Services/WooClient.php
@app/Foundation/Integration/Services/IntegrationLogger.php

<interfaces>
<!-- Phase 1 contracts this plan consumes. Do not re-explore. -->

From app/Foundation/Integration/Services/IntegrationLogger.php (Phase 1 Plan 03):
```php
final class IntegrationLogger
{
    /**
     * Persists to integration_events with auto-attached correlation_id (from Context)
     * and auto-redacted sensitive headers (authorization, cookie, x-api-key, x-auth-token,
     * x-wc-webhook-signature, x-bitrix-signature).
     */
    public function log(array $data): void;

    // Expected $data keys (from Phase 1 usage):
    //   channel: string (e.g. 'woo', 'supplier')
    //   operation: string (e.g. 'GET products', 'token.generate')
    //   method: string (GET/POST/etc.)
    //   endpoint: string
    //   request_headers: array (redacted)
    //   request_body: array|null
    //   response_headers: array (redacted)
    //   response_body: array|null
    //   http_status: int
    //   latency_ms: int
    //   status: 'success'|'failed'
    //   correlation_id: string (optional — falls back to Context::get)
    //   subject_type, subject_id: morph (optional)
}
```

From app/Domain/Sync/Services/WooClient.php (Phase 1 Plan 04 — skeleton):
```php
final class WooClient
{
    public function __construct(IntegrationLogger $logger, mixed $inner = null) {}
    public function put/post/patch/delete(...): array;  // route through writeOrShadow()
    private function writeOrShadow(): array;  // if !write_enabled → recordDiff(); else throw LogicException (Phase 2 fills)
    private function recordDiff(): array;  // writes SyncDiff + IntegrationLogger row with same correlation_id
    private function extractWooId(string $endpoint): ?string;
}
```

From Automattic\\WooCommerce\\Client (vendor ^3.1):
```php
class Client
{
    public function __construct(string $url, string $consumer_key, string $consumer_secret, array $options = []);
    public function get(string $endpoint, array $parameters = []);
    public function post(string $endpoint, array $data);
    public function put(string $endpoint, array $data);
    public function patch(string $endpoint, array $data);
    public function delete(string $endpoint, array $parameters = []);
    public int $http->response_code;
    // Throws Automattic\\WooCommerce\\HttpClient\\HttpClientException on 4xx/5xx with $response->getBody()
}
```

From Phase 1 Plan 04 tests/Feature/ShadowModeTest.php:
- 4 existing tests asserting shadow-mode + LogicException when write_enabled=true
- Pitfall P2-K: the LogicException test needs REWRITE to "invokes Automattic\\WooCommerce\\Client with signed request (HTTP faker)" once Phase 2 fills the branch

From config/services.php (Phase 1 Plan 04):
```php
'woo' => [
    'url' => env('WOO_URL'),
    'consumer_key' => env('WOO_CONSUMER_KEY'),
    'consumer_secret' => env('WOO_CONSUMER_SECRET'),
    'webhook_secret' => env('WC_WEBHOOK_SECRET'),
    'write_enabled' => env('WOO_WRITE_ENABLED', false),
],
// Phase 2 adds: 'supplier' => [...]
```
</interfaces>

</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: Install automattic/woocommerce + spatie/simple-excel, update config/services.php + .env.example, bind Automattic\\WooCommerce\\Client in the container, extend WooClient with get() + writeLive()</name>
  <files>
    composer.json,
    composer.lock,
    config/services.php,
    .env.example,
    app/Domain/Sync/Services/WooClient.php,
    app/Domain/Sync/Exceptions/RateLimitExceededException.php,
    app/Providers/AppServiceProvider.php,
    tests/Feature/ShadowModeTest.php,
    tests/Feature/WooClientGetTest.php,
    tests/Feature/WooRateLimitTest.php
  </files>
  <read_first>
    - 02-RESEARCH.md §Standard Stack (lines 110-150 — version pins + install commands), §10 Rate Limit Handling (lines 987-1026 — writeLive pattern), Pitfall P2-E (lines 1173-1182 — chunk timeout vs lease), Pitfall P2-K (lines 1246-1252 — ShadowModeTest rewrite)
    - 02-CONTEXT.md — lines 122-125 (WooClient extension contract), lines 157-158 (do NOT modify writeOrShadow() — only add get() + fill the LogicException branch)
    - 01-04-SUMMARY.md (ShadowModeTest current state; config/services.php already has woo block)
    - app/Domain/Sync/Services/WooClient.php — current skeleton (already loaded in interfaces)
    - app/Foundation/Integration/Services/IntegrationLogger.php (all 6 redacted header names — added automatically)
    - tests/Feature/ShadowModeTest.php (4 existing tests; 1 of the 4 must be rewritten per P2-K)
  </read_first>
  <behavior>
    Tests in tests/Feature/WooClientGetTest.php:
    - Test G1: `WooClient::get('products', ['per_page' => 100])` with a fake Automattic client returning a dummy payload returns the payload array.
    - Test G2: Every get() call writes an `integration_events` row with channel='woo', method='GET', endpoint='products', http_status=200, latency_ms>0, correlation_id populated (from Context or fallback).
    - Test G3: Errors from the Automattic client propagate but still write an integration_events row with status='failed', http_status=500 (or actual error code).
    - Test G4: WooClient constructor now types `$inner: \\Automattic\\WooCommerce\\Client` (reflection check: `(new ReflectionMethod(WooClient::class, '__construct'))->getParameters()[1]->getType()->getName() === 'Automattic\\\\WooCommerce\\\\Client'`).

    Tests in tests/Feature/WooRateLimitTest.php (SYNC-10):
    - Test R1: WooClient::put() with WOO_WRITE_ENABLED=true and a mocked client that returns 429 once then 200 retries successfully after exponential delay. Timing assertion: total elapsed ≥ 500ms (first backoff) but < 2s (well under cap).
    - Test R2: Woo returns 429 with `Retry-After: 3` header → the backoff honours 3s floor over the computed 500ms.
    - Test R3: 5 consecutive 429s → throws RateLimitExceededException (attempt 5 gives up).
    - Test R4: Jitter: run put() twice with the same mock (both get 429 once then 200); log the two latencies; they MUST differ by at least 1ms (random_int jitter 0-500ms).

    Tests in tests/Feature/ShadowModeTest.php:
    - KEEP 3 existing shadow-mode tests unchanged (WOO_WRITE_ENABLED=false path).
    - REWRITE the 4th test (previously asserting LogicException): now it asserts that WOO_WRITE_ENABLED=true → put('products/1234', ['regular_price' => '99.99']) calls the Automattic client's put() method with the same args. Mock the Automattic client; assert invocation with Mockery/inline partial.
  </behavior>
  <action>
**1. Install packages** (Windows dev requires the pcntl/posix flags per Phase 1 convention):
```bash
composer require "automattic/woocommerce:^3.1" --ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix
composer require "spatie/simple-excel:^3.9" --ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix
```
Verify: `composer show automattic/woocommerce` returns ^3.1.x; `composer show spatie/simple-excel` returns ^3.9.x.

**If bash caret-stripping recurs per Phase 1 Plan 01 Issue 2**, manually set `composer.json` entries:
```json
"automattic/woocommerce": "^3.1",
"spatie/simple-excel": "^3.9"
```
then `composer update automattic/woocommerce spatie/simple-excel`.

**2. Update `config/services.php`** — add the supplier block after the existing woo block:
```php
'supplier' => [
    'url' => env('SUPPLIER_API_URL', 'https://21stcav.com'),
    'username' => env('SUPPLIER_API_USERNAME'),
    'password' => env('SUPPLIER_API_PASSWORD'),
],
```

**3. Update `.env.example`** — add after the existing WOO_* block:
```
# 21stcav.com supplier API (SYNC-02 JWT auth)
SUPPLIER_API_URL=https://21stcav.com
SUPPLIER_API_USERNAME=
SUPPLIER_API_PASSWORD=
```

**4. Create `app/Domain/Sync/Exceptions/RateLimitExceededException.php`:**
```php
namespace App\Domain\Sync\Exceptions;

final class RateLimitExceededException extends \RuntimeException {}
```

**5. Extend `app/Domain/Sync/Services/WooClient.php`** — keep `recordDiff()` + `writeOrShadow()` + `extractWooId()` untouched. Additions:
```php
use Automattic\WooCommerce\Client as AutomatticClient;
use Automattic\WooCommerce\HttpClient\HttpClientException;
use App\Domain\Sync\Exceptions\RateLimitExceededException;

// Constructor — type $inner properly per CONTEXT lines 122-125:
public function __construct(
    private IntegrationLogger $logger,
    private ?AutomatticClient $inner = null,
) {}

// NEW: get() — reads from Woo REST
public function get(string $endpoint, array $query = []): array
{
    $start = microtime(true);
    $correlationId = Context::get('correlation_id') ?? (string) Str::uuid();

    try {
        if ($this->inner === null) {
            throw new \RuntimeException('WooClient $inner not bound; call from DI container.');
        }
        $response = $this->inner->get($endpoint, $query);
        $latencyMs = (int) round((microtime(true) - $start) * 1000);

        $this->logger->log([
            'channel' => 'woo',
            'operation' => "GET {$endpoint}",
            'method' => 'GET',
            'endpoint' => $endpoint,
            'request_body' => $query,
            'response_body' => is_array($response) ? $response : json_decode(json_encode($response), true),
            'http_status' => $this->inner->http->response->code ?? 200,
            'latency_ms' => $latencyMs,
            'status' => 'success',
            'correlation_id' => $correlationId,
        ]);

        return is_array($response) ? $response : (array) $response;
    } catch (\Throwable $e) {
        $latencyMs = (int) round((microtime(true) - $start) * 1000);
        $this->logger->log([
            'channel' => 'woo',
            'operation' => "GET {$endpoint}",
            'method' => 'GET',
            'endpoint' => $endpoint,
            'request_body' => $query,
            'response_body' => ['error' => $e->getMessage()],
            'http_status' => $e instanceof HttpClientException ? ($this->inner?->http?->response?->code ?? 500) : 500,
            'latency_ms' => $latencyMs,
            'status' => 'failed',
            'correlation_id' => $correlationId,
        ]);
        throw $e;
    }
}

// Update writeOrShadow() — the 'true' branch now calls writeLive() instead of throwing:
private function writeOrShadow(string $method, string $endpoint, array $payload): array
{
    if (! (bool) config('services.woo.write_enabled', false)) {
        return $this->recordDiff($method, $endpoint, $payload);
    }
    return $this->writeLive($method, $endpoint, $payload);
}

// NEW: writeLive() with Woo 429 exponential backoff + Retry-After honoured + jitter (SYNC-10, RESEARCH §10)
private function writeLive(string $method, string $endpoint, array $payload): array
{
    if ($this->inner === null) {
        throw new \RuntimeException('WooClient $inner not bound; call from DI container.');
    }
    $attempt = 0;
    $maxAttempts = 5;
    $baseDelayMs = 500;
    $correlationId = Context::get('correlation_id') ?? (string) Str::uuid();

    while ($attempt < $maxAttempts) {
        $attempt++;
        $start = microtime(true);
        try {
            $response = $this->inner->{strtolower($method)}($endpoint, $payload);
            $latencyMs = (int) round((microtime(true) - $start) * 1000);

            $this->logger->log([
                'channel' => 'woo',
                'operation' => "{$method} {$endpoint}",
                'method' => $method,
                'endpoint' => $endpoint,
                'request_body' => $payload,
                'response_body' => is_array($response) ? $response : json_decode(json_encode($response), true),
                'http_status' => $this->inner->http->response->code ?? 200,
                'latency_ms' => $latencyMs,
                'status' => 'success',
                'correlation_id' => $correlationId,
            ]);

            return is_array($response) ? $response : (array) $response;
        } catch (HttpClientException $e) {
            $httpStatus = $this->inner?->http?->response?->code ?? 0;
            $this->logger->log([
                'channel' => 'woo',
                'operation' => "{$method} {$endpoint}",
                'method' => $method,
                'endpoint' => $endpoint,
                'request_body' => $payload,
                'response_body' => ['error' => $e->getMessage(), 'attempt' => $attempt],
                'http_status' => $httpStatus,
                'latency_ms' => (int) round((microtime(true) - $start) * 1000),
                'status' => 'failed',
                'correlation_id' => $correlationId,
            ]);

            if ($httpStatus === 429 && $attempt < $maxAttempts) {
                $retryAfterHeader = (int) ($this->inner->http->response->headers['Retry-After'] ?? 0);
                $computedDelayMs = $baseDelayMs * (3 ** ($attempt - 1));  // 500, 1500, 4500, 13500
                $delayMs = max($retryAfterHeader * 1000, $computedDelayMs);
                $delayMs = min($delayMs, 30_000);  // cap 30s
                $jitterMicros = random_int(0, 500_000);
                usleep($delayMs * 1000 + $jitterMicros);
                continue;
            }
            throw $e;
        }
    }

    throw new RateLimitExceededException("Woo 429 after {$maxAttempts} attempts: {$method} {$endpoint}");
}
```

**6. Register the Automattic client + WooClient in `app/Providers/AppServiceProvider.php`** (per RESEARCH Code Examples):

In `register()` (BEFORE existing bindings that might resolve WooClient):
```php
$this->app->singleton(\Automattic\WooCommerce\Client::class, function ($app) {
    return new \Automattic\WooCommerce\Client(
        (string) config('services.woo.url', 'https://meetingstore.co.uk'),
        (string) config('services.woo.consumer_key', ''),
        (string) config('services.woo.consumer_secret', ''),
        [
            'version' => 'wc/v3',
            'timeout' => 30,
            'verify_ssl' => app()->isProduction(),
        ]
    );
});

$this->app->singleton(\App\Domain\Sync\Services\WooClient::class, function ($app) {
    return new \App\Domain\Sync\Services\WooClient(
        $app->make(\App\Foundation\Integration\Services\IntegrationLogger::class),
        $app->make(\Automattic\WooCommerce\Client::class),
    );
});
```

**7. Update `tests/Feature/ShadowModeTest.php`** — 3 existing tests unchanged; the 4th (the LogicException-asserting one) is rewritten per Pitfall P2-K:
```php
it('WOO_WRITE_ENABLED=true invokes Automattic client (not LogicException)', function () {
    config(['services.woo.write_enabled' => true]);
    Context::add('correlation_id', 'test-' . Str::uuid());

    $mockInner = Mockery::mock(\Automattic\WooCommerce\Client::class);
    $mockInner->http = (object) ['response' => (object) ['code' => 200, 'headers' => []]];
    $mockInner->shouldReceive('put')
        ->once()
        ->with('products/1234', ['regular_price' => '99.99'])
        ->andReturn(['id' => 1234, 'regular_price' => '99.99']);

    $client = new WooClient(app(IntegrationLogger::class), $mockInner);
    $result = $client->put('products/1234', ['regular_price' => '99.99']);

    expect($result)->toHaveKey('id', 1234);
    // integration_events row persisted:
    expect(IntegrationEvent::where('endpoint', 'products/1234')->where('status', 'success')->count())->toBe(1);
    // NO sync_diffs row for a live write:
    expect(SyncDiff::count())->toBe(0);
});
```

**8. Write `tests/Feature/WooClientGetTest.php`** per behavior tests G1-G4 — use Mockery for the Automattic client, assert IntegrationEvent row is written, verify reflection-based constructor signature.

**9. Write `tests/Feature/WooRateLimitTest.php`** per behavior tests R1-R4 — use Mockery to return 429 then 200; fabricate Retry-After header via `$mock->http->response->headers = ['Retry-After' => '3']`; assert `usleep` timing via microtime delta ≥ 500ms on first retry.

**Self-check before commit:**
```bash
vendor/bin/pest --filter=ShadowMode --filter=WooClientGet --filter=WooRateLimit
vendor/bin/pest  # full suite — must stay green
```
  </action>
  <verify>
    <automated>vendor/bin/pest --filter=ShadowMode &amp;&amp; vendor/bin/pest --filter=WooClientGet &amp;&amp; vendor/bin/pest --filter=WooRateLimit</automated>
  </verify>
  <done>
    - `composer show automattic/woocommerce` reports 3.1.x; `composer show spatie/simple-excel` reports 3.9.x; both caret-pinned in composer.json
    - `grep "supplier" config/services.php` returns the new supplier block
    - `.env.example` contains SUPPLIER_API_URL/USERNAME/PASSWORD keys
    - WooClient has `public function get(string $endpoint, array $query = []): array` signature
    - WooClient constructor types `$inner: ?\\Automattic\\WooCommerce\\Client`
    - `app(WooClient::class)` resolves without errors and `->get('nonsense-endpoint')` returns (or fails) via the real Automattic client
    - All 4 ShadowModeTest cases pass (3 existing + 1 rewritten)
    - 4 new WooClientGetTest cases pass
    - 4 new WooRateLimitTest cases pass
    - `vendor/bin/pest` full suite ≥ 118 passing
  </done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: Ship SupplierClient with JWT lifecycle + Cache::remember + retry-once-on-401 + JwtRefreshFailedException</name>
  <files>
    app/Domain/Sync/Services/SupplierClient.php,
    app/Domain/Sync/Exceptions/JwtRefreshFailedException.php,
    app/Providers/AppServiceProvider.php,
    tests/Feature/SupplierClientTest.php,
    tests/Feature/SupplierClientJwtRefreshTest.php
  </files>
  <read_first>
    - 02-RESEARCH.md §2 SupplierClient (lines 549-629 — expected API shape, JWT lifecycle, Client code), §Code Examples getToken (lines 1283-1317), Pitfall P2-B (lines 1150-1157 — cache key collision on credential rotation), assumption A1 (line 1403 — API shape MEDIUM confidence)
    - 02-CONTEXT.md — D-06 (c) abort trigger on JWT refresh failure (lines 39-42)
    - 01-03-SUMMARY.md (IntegrationLogger signature — already loaded)
    - app/Foundation/Integration/Services/IntegrationLogger.php (6 auto-redacted headers; `authorization` IS in the list, so Bearer tokens never persist)
    - .planning/research/PITFALLS.md Pitfall 12 (JWT cache TTL = token_expiry - 60s)
  </read_first>
  <behavior>
    Tests in tests/Feature/SupplierClientTest.php:
    - Test S1: `SupplierClient::fetchAllProducts()` with Http::fake() returning paged {'data': [...], 'next_page': 2} → {...} → {'data': [], 'next_page': null} returns a flat array keyed by SKU: `['SKU-1' => ['price' => '100.00', 'stock' => 5], 'SKU-2' => ...]`.
    - Test S2: Token is fetched exactly once per fetchAllProducts() call when Cache is empty; subsequent calls within TTL reuse the cached token (assert Http::assertSentCount(1) for /generate_token.php).
    - Test S3: Every supplier API call writes an integration_events row with channel='supplier', operation like 'fetch.page.N' or 'token.generate', http_status, latency_ms, correlation_id. Authorization header must be REDACTED (value === '***REDACTED***') thanks to IntegrationLogger.
    - Test S4: Missing .env credentials → HTTP call to /generate_token.php returns 401 → SupplierClient throws `JwtRefreshFailedException`.

    Tests in tests/Feature/SupplierClientJwtRefreshTest.php (SYNC-02 + D-06c):
    - Test J1: First call to `/api/index.php` returns 401 with old cached token → SupplierClient forgets the cache, fetches a fresh token from `/generate_token.php`, retries the same call, gets 200 → returns data successfully. Assert Http::assertSent(3 calls: first 401, then token regen, then successful retry).
    - Test J2: First 401 → fresh token — but the retry call ALSO returns 401 → throws JwtRefreshFailedException (don't loop — D-06c).
    - Test J3: Cache key includes a hash of the username → rotating the username produces a different cache key (assert cache miss on new username even when old token is still cached).
    - Test J4: When /generate_token.php itself returns 401 / 500, SupplierClient throws JwtRefreshFailedException with descriptive message including the HTTP status.
  </behavior>
  <action>
**1. Create `app/Domain/Sync/Exceptions/JwtRefreshFailedException.php`:**
```php
namespace App\Domain\Sync\Exceptions;

final class JwtRefreshFailedException extends \RuntimeException {}
```

**2. Create `app/Domain/Sync/Services/SupplierClient.php`** — follows RESEARCH §2 + Pitfall P2-B:
```php
namespace App\Domain\Sync\Services;

use App\Domain\Sync\Exceptions\JwtRefreshFailedException;
use App\Foundation\Integration\Services\IntegrationLogger;
use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * SYNC-01 + SYNC-02 — 21stcav.com JWT-authed catalogue client.
 *
 * JWT lifecycle (Pitfall 12 + Pitfall P2-B):
 * - Token cached in Redis keyed by md5(username) so credential rotation naturally invalidates
 * - TTL = 50 minutes (10-min safety margin on assumed 60-min token TTL — A2)
 * - On 401: invalidate cache, retry once with fresh token; second 401 = JwtRefreshFailedException (D-06c)
 */
final class SupplierClient
{
    public function __construct(
        private IntegrationLogger $logger,
        private Repository $cache,
    ) {}

    /**
     * Returns ['SKU-1' => ['price' => '100.00', 'stock' => 5], ...] for the full supplier catalogue.
     * ~15k entries expected per assumption A4.
     */
    public function fetchAllProducts(): array
    {
        $out = [];
        $page = 1;
        do {
            $response = $this->authed(function () use ($page) {
                $start = microtime(true);
                $response = Http::baseUrl((string) config('services.supplier.url'))
                    ->withToken($this->getToken())
                    ->timeout(30)
                    ->retry(
                        times: 2,
                        sleepMilliseconds: 500,
                        when: fn (\Throwable $e) => $e instanceof ConnectionException,
                    )
                    ->get('/api/index.php', [
                        'endpoint' => 'products',
                        'page' => $page,
                        'per_page' => 500,
                    ]);
                $latencyMs = (int) round((microtime(true) - $start) * 1000);

                $this->logger->log([
                    'channel' => 'supplier',
                    'operation' => "fetch.page.{$page}",
                    'method' => 'GET',
                    'endpoint' => '/api/index.php',
                    'request_body' => ['endpoint' => 'products', 'page' => $page],
                    'request_headers' => $response->transferStats?->getRequest()?->getHeaders() ?? ['authorization' => '***REDACTED***'],
                    'response_body' => $response->json(),
                    'http_status' => $response->status(),
                    'latency_ms' => $latencyMs,
                    'status' => $response->successful() ? 'success' : 'failed',
                    'correlation_id' => Context::get('correlation_id') ?? (string) Str::uuid(),
                ]);

                if ($response->status() === 401) {
                    $response->throw();  // caller's authed() wrapper catches + refreshes
                }
                return $response;
            });

            foreach (($response->json('data') ?? []) as $row) {
                if (! empty($row['sku'])) {
                    $out[(string) $row['sku']] = [
                        'price' => (string) ($row['price'] ?? ''),
                        'stock' => (int) ($row['stock'] ?? 0),
                    ];
                }
            }

            $hasMore = $response->json('next_page') !== null;
            $page++;
        } while ($hasMore);

        return $out;
    }

    private function authed(Closure $call): Response
    {
        try {
            return $call();
        } catch (RequestException $e) {
            if ($e->response?->status() !== 401) {
                throw $e;
            }
            // D-06(c) retry-once-then-fail. Purge cached token, retry once.
            $this->cache->forget($this->tokenCacheKey());
            try {
                return $call();
            } catch (RequestException $retry) {
                throw new JwtRefreshFailedException(
                    'Supplier API returned 401 after token refresh — auth credentials likely broken (D-06c).',
                    previous: $retry,
                );
            }
        }
    }

    private function getToken(): string
    {
        return $this->cache->remember(
            $this->tokenCacheKey(),
            now()->addMinutes(50),  // 10-min safety margin on assumed 60-min TTL (A2)
            function (): string {
                $start = microtime(true);
                $response = Http::timeout(10)->post(
                    (string) config('services.supplier.url') . '/generate_token.php',
                    [
                        'username' => (string) config('services.supplier.username'),
                        'password' => (string) config('services.supplier.password'),
                    ]
                );
                $latencyMs = (int) round((microtime(true) - $start) * 1000);

                $this->logger->log([
                    'channel' => 'supplier',
                    'operation' => 'token.generate',
                    'method' => 'POST',
                    'endpoint' => '/generate_token.php',
                    'request_body' => ['username' => (string) config('services.supplier.username'), 'password' => '***REDACTED***'],
                    'response_body' => $response->successful() ? ['token' => '***REDACTED***', 'expires_in' => $response->json('expires_in')] : $response->json(),
                    'http_status' => $response->status(),
                    'latency_ms' => $latencyMs,
                    'status' => $response->successful() ? 'success' : 'failed',
                    'correlation_id' => Context::get('correlation_id') ?? (string) Str::uuid(),
                ]);

                if (! $response->successful() || ! $response->json('token')) {
                    throw new JwtRefreshFailedException(
                        "Supplier token generation failed: HTTP {$response->status()}",
                    );
                }

                return (string) $response->json('token');
            }
        );
    }

    private function tokenCacheKey(): string
    {
        // Pitfall P2-B: hash username so credential rotation produces a new key
        $username = (string) config('services.supplier.username', '');
        return 'supplier.jwt.' . md5($username);
    }
}
```

**3. Register in `AppServiceProvider::register()`** (additive):
```php
$this->app->singleton(\App\Domain\Sync\Services\SupplierClient::class, function ($app) {
    return new \App\Domain\Sync\Services\SupplierClient(
        $app->make(\App\Foundation\Integration\Services\IntegrationLogger::class),
        $app->make(\Illuminate\Contracts\Cache\Repository::class),
    );
});
```

**4. Write `tests/Feature/SupplierClientTest.php`** with tests S1-S4 using `Http::fake()` and `Cache::flush()` between tests:
```php
beforeEach(function () {
    Cache::flush();
    Context::add('correlation_id', 'test-' . \Illuminate\Support\Str::uuid());
    config([
        'services.supplier.url' => 'https://fake-supplier.test',
        'services.supplier.username' => 'testuser',
        'services.supplier.password' => 'testpass',
    ]);
});

it('fetchAllProducts returns flat SKU-keyed hashmap from paged JSON response', function () {
    Http::fake([
        'https://fake-supplier.test/generate_token.php' => Http::response(['token' => 'fake-jwt-abc', 'expires_in' => 3600], 200),
        'https://fake-supplier.test/api/index.php?endpoint=products&page=1*' => Http::response(['data' => [['sku' => 'SKU-1', 'price' => '99.00', 'stock' => 5]], 'next_page' => 2], 200),
        'https://fake-supplier.test/api/index.php?endpoint=products&page=2*' => Http::response(['data' => [['sku' => 'SKU-2', 'price' => '125.50', 'stock' => 0]], 'next_page' => null], 200),
    ]);

    $result = app(SupplierClient::class)->fetchAllProducts();

    expect($result)->toHaveCount(2)
        ->and($result)->toHaveKey('SKU-1')
        ->and($result['SKU-1'])->toEqual(['price' => '99.00', 'stock' => 5]);
});
```

**5. Write `tests/Feature/SupplierClientJwtRefreshTest.php`** with tests J1-J4:
```php
it('on 401 purges cache, fetches fresh token, retries once, succeeds (SYNC-02)', function () {
    Cache::put('supplier.jwt.' . md5('testuser'), 'stale-token', now()->addMinutes(50));

    $callCount = 0;
    Http::fake([
        'https://fake-supplier.test/generate_token.php' => Http::response(['token' => 'fresh-jwt', 'expires_in' => 3600], 200),
        'https://fake-supplier.test/api/index.php*' => function () use (&$callCount) {
            $callCount++;
            return $callCount === 1
                ? Http::response(['error' => 'unauthenticated'], 401)
                : Http::response(['data' => [['sku' => 'SKU-Y', 'price' => '1.00', 'stock' => 1]], 'next_page' => null], 200);
        },
    ]);

    $result = app(SupplierClient::class)->fetchAllProducts();

    expect($result)->toHaveKey('SKU-Y');
    Http::assertSent(fn ($req) => str_contains($req->url(), '/generate_token.php'));  // fresh token fetched
    expect($callCount)->toBe(2);  // original 401 + retry success
});
```
Implement all 4 JWT tests similarly.

**Self-check:**
```bash
vendor/bin/pest --filter=SupplierClient
vendor/bin/pest  # full suite
```
  </action>
  <verify>
    <automated>vendor/bin/pest --filter=SupplierClient</automated>
  </verify>
  <done>
    - `app(SupplierClient::class)->fetchAllProducts()` resolves via DI (tested via closure)
    - JWT cache key uses `md5(username)` prefix (Pitfall P2-B); test J3 proves
    - 401 retry logic is ONE-SHOT (D-06c); second 401 throws JwtRefreshFailedException
    - Every supplier call logs to integration_events with `authorization: ***REDACTED***` (IntegrationLogger auto-redaction verified)
    - SupplierClientTest — 4 tests green; SupplierClientJwtRefreshTest — 4 tests green
    - Full Pest suite stays green (≥ 126 passing)
    - composer.json contains both new packages at caret pins
  </done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| Laravel app → 21stcav.com supplier API | Outbound HTTP to third-party; JWT credentials in .env; Bearer token in every request header |
| Laravel app → Woo REST (meetingstore.co.uk) | Outbound; HMAC-via-URL signed via Automattic client's consumer_key/secret |
| Laravel cache (Redis) → SupplierClient | JWT lives in Redis cache; anyone with Redis access can read all active tokens |
| Logs (integration_events) → DB | API responses persisted; must redact Authorization header + password bodies |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-02-02-01 | Information Disclosure | SupplierClient Authorization header leaking into integration_events | mitigate | IntegrationLogger auto-redacts `authorization` (already in SENSITIVE_HEADERS list from Phase 1 P03). Manual override in getToken() sets `password: '***REDACTED***'` in logged request_body (only the auto-redact covers headers; request-body redaction is explicit here). Test S3 asserts. |
| T-02-02-02 | Tampering | JWT cache key collision on credential rotation (Pitfall P2-B) | mitigate | tokenCacheKey() = `supplier.jwt.{md5(username)}`; rotating SUPPLIER_API_USERNAME produces a fresh cache key. Test J3 asserts. |
| T-02-02-03 | Spoofing | Attacker with Redis access replays cached JWT to steal supplier data | accept | Production VPS Redis is bound to 127.0.0.1 with requirepass; only the Laravel app reads DB 1 (cache). Threat requires pre-existing server compromise — out of Phase 2 scope. |
| T-02-02-04 | Denial of Service | Infinite retry loop on persistent 401 — exhausts supplier quota | mitigate | `authed()` retry-once-then-throw semantics (D-06c). Max 2 calls per 401. Deterministic. |
| T-02-02-05 | Information Disclosure | Woo consumer_key/secret exposed via log stacktraces | mitigate | Automattic client uses URL query args (not headers) for auth; the URL is NOT logged (IntegrationLogger `endpoint` captures the path only, not the query string for woo channel — verify). Plan 05 ship an architecture test asserting no `consumer_secret=` appears in integration_events. |
| T-02-02-06 | Elevation of Privilege | Compromised supplier feed delivering malicious product payloads (XSS in name/tags) | mitigate | Eloquent $casts on Product.tags = 'array' (JSON parse — structural only, no eval). Plan 04 Filament Resource renders via Filament's auto-escaped Blade components. Phase 6 (which publishes to Woo) is where stricter input validation lives. |
| T-02-02-07 | Tampering | Architecture bypass — direct WP DB write instead of WooClient (SYNC-04) | mitigate | Plan 05 ships Deptrac `WpDirectDb` layer + negative test. This plan does NOT introduce any direct DB client — compliant by construction. |
</threat_model>

<verification>
1. **Composer integrity:**
   ```bash
   composer show automattic/woocommerce | grep versions
   composer show spatie/simple-excel | grep versions
   composer validate --strict
   ```
   Both packages at ^3.1.x / ^3.9.x; composer.lock clean.

2. **Client bindings resolve:**
   ```bash
   php artisan tinker --execute="dd(get_class(app(App\\Domain\\Sync\\Services\\WooClient::class)))"
   # → "App\\Domain\\Sync\\Services\\WooClient"
   php artisan tinker --execute="dd(get_class(app(App\\Domain\\Sync\\Services\\SupplierClient::class)))"
   # → "App\\Domain\\Sync\\Services\\SupplierClient"
   ```

3. **Test suite:**
   ```bash
   vendor/bin/pest
   ```
   ≥ 126 passing (Phase 1: 92 + P01: 18 + P02: 16).

4. **Deptrac:**
   ```bash
   vendor/bin/deptrac analyse --no-progress
   ```
   Exits 0. Sync domain is allowed to import from Foundation (IntegrationLogger); `Http` facade is framework-level (outside any layer — uncovered).

5. **ShadowModeTest + Phase 1 regression:**
   ```bash
   vendor/bin/pest --filter=ShadowMode --filter=WooWebhook --filter=SuggestionInbox
   ```
   All Phase 1 feature suites still green.
</verification>

<success_criteria>
- Both composer packages installed at caret pins; composer.lock committed
- config/services.php has supplier block; .env.example has SUPPLIER_API_* keys
- WooClient extended with get() method + writeLive() honouring Woo 429 + Retry-After + jitter (SYNC-10)
- WooClient $inner typed as `?Automattic\\WooCommerce\\Client`; real client bound in AppServiceProvider
- SupplierClient ships with Cache::remember'd JWT, credential-hashed cache key, retry-once-on-401 semantics (SYNC-02 + D-06c)
- JwtRefreshFailedException + RateLimitExceededException classes exist in app/Domain/Sync/Exceptions/
- All Phase 1 tests still green; 16+ new Phase 2 tests green
- Every outbound call logs via IntegrationLogger (channel=woo|supplier, correlation_id threaded)
- ShadowModeTest rewritten per Pitfall P2-K — LogicException test now asserts real Automattic client invocation
</success_criteria>

<output>
Create `.planning/phases/02-supplier-sync/02-02-SUMMARY.md` after completion with:
- Package versions installed (exact composer.lock output)
- WooClient extension delta (get + writeLive lines added)
- SupplierClient JWT TTL chosen (50 min safety margin documented)
- Rate-limit backoff schedule (500/1500/4500/13500/30000 ms + jitter)
- Any deviations in the .env.example shape or service container bindings
</output>
