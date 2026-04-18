---
phase: 02-supplier-sync
plan: 02-external-clients
subsystem: sync,integration
tags: [woocommerce-rest, jwt, rate-limit, backoff, integration-logger, shadow-mode, cache-remember, d-06c, sync-01, sync-02, sync-04, sync-10, p2-b, p2-k]

requires:
  - phase: 01-03-foundation
    provides: "IntegrationLogger (auto-redacts 6 sensitive headers + threads correlation_id from Context); AttachCorrelationId middleware; meetingstore_ops_testing DB isolation"
  - phase: 01-04-seams
    provides: "WooClient skeleton with shadow-mode gate (WOO_WRITE_ENABLED=false → SyncDiff; true → LogicException); sync_diffs table; config/services.woo.url/consumer_key/consumer_secret/write_enabled wired"
  - phase: 02-01-data-model
    provides: "Product + ProductVariant + SyncRun + SyncError + ImportIssue + SyncRunItem tables + models + factories + 5 policies + ProductVariantObserver; AppServiceProvider Gate::policy bindings; 110 Pest tests baseline + 0 Deptrac violations"

provides:
  - "composer require automattic/woocommerce:^3.1 (resolved 3.1.0) + spatie/simple-excel:^3.9 (resolved 3.9.0); composer.lock committed"
  - "WooClient::get(string, array): array — reads from Woo REST via the Automattic SDK; every call logs to integration_events (success + failure) with channel=woo, method=GET, http_status, latency_ms, correlation_id threaded from Context (fallback UUID)"
  - "WooClient constructor types `$inner: ?Automattic\\WooCommerce\\Client` (replaces Phase 1 `mixed`) — reflection test asserts this invariant"
  - "WooClient::writeLive() fills the Phase 1 LogicException branch: calls $this->inner->{method}() for POST/PUT/DELETE; PATCH routed through $this->inner->http->request() since the Automattic 3.1.0 client doesn't expose patch() natively"
  - "SYNC-10 429 backoff: exponential 500/1500/4500/13500 ms (base × 3^(attempt-1)) capped at 30s + jitter 0-500ms; Retry-After header (seconds) honoured as floor when larger than computed delay; 5 retries max then throws RateLimitExceededException"
  - "writeLive() failure logging: every attempt writes a failed integration_events row with attempt counter; single RateLimitExceededException at the end = single SyncError downstream (avoids D-06(b) counter spam)"
  - "ShadowModeTest rewrite (P2-K): the previous `throws LogicException when write_enabled=true` test is now `invokes Automattic client when write_enabled=true` — mocks AutomatticClient, asserts put() is called with correct args and integration_events row is written with status=success"
  - "Automattic\\WooCommerce\\Client bound as container singleton via config/services.woo.url/consumer_key/consumer_secret; 30s timeout; verify_ssl=production-only (local dev may use self-signed)"
  - "WooClient bound as container singleton in AppServiceProvider::register() — resolved via `app(WooClient::class)` at runtime; `$inner` is automatically the configured Automattic client"
  - "SupplierClient::fetchAllProducts(): array<string, array{price, stock}> — paginated GET /api/index.php?endpoint=products&page=N&per_page=500 with JWT auth; returns flat SKU-keyed hashmap with price (string, 2dp preserved) + stock (int)"
  - "SupplierClient::getToken() via Cache::remember — key='supplier.jwt.'.md5(username) (Pitfall P2-B: credential rotation naturally invalidates); TTL=50 minutes (10-min safety margin on assumed 60-min server TTL per assumption A2)"
  - "SupplierClient::authed() one-shot 401 retry: original 401 → Cache::forget + fetch fresh token via /generate_token.php → retry same call once; second 401 throws JwtRefreshFailedException (D-06c abort trigger; deliberate no-loop to avoid lockout from upstream rate-limiters interpreting repeated auth as brute-force)"
  - "JwtRefreshFailedException: throwable by orchestrator to flip SyncRun.status='aborted' + abort_reason='jwt_refresh' (D-06c); also thrown when /generate_token.php returns non-2xx or empty token"
  - "RateLimitExceededException: throwable after 5 retries exhausted on Woo 429; orchestrator catches + logs as single SyncError (no consecutive-failure spam)"
  - "Every SupplierClient call logs to integration_events: token.generate + fetch.page.N operations; authorization header auto-redacted by IntegrationLogger (6-name SENSITIVE_HEADERS list from Phase 1 P03); password field explicitly masked in token.generate request_body; token value masked in response_body (never persisted)"
  - "SupplierClient bound as non-singleton (bind not singleton) in AppServiceProvider::register() — token cache lives in CacheRepository, so re-instantiation is cheap; tests get fresh instance per resolve"
  - "config/services.php supplier block: url (default https://21stcav.com) + username + password from .env"
  - ".env.example additions: WOO_URL/WOO_CONSUMER_KEY/WOO_CONSUMER_SECRET uncommented + SUPPLIER_API_URL (defaults to https://21stcav.com) + SUPPLIER_API_USERNAME + SUPPLIER_API_PASSWORD"
  - "16 new Pest tests across 5 files: WooClientGetTest (4), WooRateLimitTest (4), SupplierClientTest (4), SupplierClientJwtRefreshTest (4), ShadowModeTest (4 — 1 rewritten per P2-K, 3 unchanged)"

affects:
  - "02-03-orchestration (SyncSupplierCommand + SyncChunkJob use WooClient::get() to stream products, SupplierClient::fetchAllProducts() to build the supplier SKU map; AbortGuard catches JwtRefreshFailedException + RateLimitExceededException to flip SyncRun state)"
  - "02-04-reporting-ui (no direct consumer, but integration_events rows shipped here feed SyncRunResource drill-down)"
  - "02-05-guardrails (Deptrac SYNC-04 test can now rely on WooClient being the sole Woo entry point — no direct Automattic\\WooCommerce\\Client references outside app/Providers/AppServiceProvider.php)"

tech-stack:
  added:
    - "automattic/woocommerce ^3.1 (resolver picked 3.1.0 — NOT 3.1.1 as STACK.md predicted; 3.1.1 tag not available on Packagist as of 2026-04-18 install; functional parity confirmed via HttpClient reading)"
    - "spatie/simple-excel ^3.9 (resolver picked 3.9.0 released 2026-02-22) — pre-installed for Plan 02-04 CSV report writer"
  patterns:
    - "Test seam via protected overridable method: WooClient::sleepMicros(int) is protected so WooRateLimitTest can subclass + override to capture timing without actually sleeping — keeps timing assertions fast AND deterministic"
    - "Auth-gate exception pattern: JwtRefreshFailedException + RateLimitExceededException are pure RuntimeException subclasses (no fields/methods) — orchestrator catches by class name + reads $e->getMessage(); avoids tight coupling to exception internals"
    - "Explicit request-body redaction for non-header secrets: IntegrationLogger's auto-redaction covers the authorization HEADER; SupplierClient explicitly masks `password` field in the request_body array before passing to log() — same output shape, keeps redaction close to the producer"
    - "Http::sequence() for multi-page test scenarios: SupplierClientTest uses Http::sequence()->push(...)->push(...) to stage page 1 + page 2 + page 3 responses under the same URL pattern — cleaner than per-URL Http::fake entries when pages share the base URL"
    - "Reflection-based constructor-signature test: WooClientGetTest asserts `(new ReflectionMethod(WooClient, '__construct'))->getParameters()[1]->getType()->getName() === 'Automattic\\\\WooCommerce\\\\Client'` — locks the $inner type in CI so accidental reversion to `mixed` is caught immediately"

key-files:
  created:
    - "app/Domain/Sync/Exceptions/RateLimitExceededException.php"
    - "app/Domain/Sync/Exceptions/JwtRefreshFailedException.php"
    - "app/Domain/Sync/Services/SupplierClient.php"
    - "tests/Feature/WooClientGetTest.php"
    - "tests/Feature/WooRateLimitTest.php"
    - "tests/Feature/SupplierClientTest.php"
    - "tests/Feature/SupplierClientJwtRefreshTest.php"
  modified:
    - "composer.json — + automattic/woocommerce:^3.1 + spatie/simple-excel:^3.9"
    - "composer.lock"
    - "config/services.php — + supplier block (url/username/password)"
    - ".env.example — uncomment WOO_URL/WOO_CONSUMER_KEY/WOO_CONSUMER_SECRET + SUPPLIER_API_URL=https://21stcav.com + SUPPLIER_API_USERNAME + SUPPLIER_API_PASSWORD"
    - "app/Domain/Sync/Services/WooClient.php — type $inner: ?AutomatticClient; + get() method; writeLive() fills LogicException branch; + 4 private helpers (dispatchWrite, readResponseCode, readExceptionStatus, readRetryAfterSeconds, readHttpResponseObject, normaliseResponseBody, sleepMicros seam)"
    - "app/Providers/AppServiceProvider.php — 3 singleton/bind calls in register() for AutomatticClient + WooClient + SupplierClient"
    - "tests/Feature/ShadowModeTest.php — rewrite the 2nd test per P2-K (LogicException → Automattic client invocation)"

key-decisions:
  - "Automattic\\WooCommerce\\Client 3.1.0 (not 3.1.1) installed because 3.1.1 is not yet tagged on Packagist as of 2026-04-18 install; 3.1.0 functional parity is complete for our usage (get/post/put/delete all work; PATCH is not exposed by either version so routed via underlying HttpClient::request() — same workaround applies to both). Flagged for STACK.md refresh in Plan 02-05."
  - "HttpClient::$response is PRIVATE in the Automattic library (not public as RESEARCH.md §10 snippet assumed) — WooClient reads status codes via the PUBLIC accessor `$this->inner->http->getResponse()->getCode()` and headers via `->getResponse()->getHeaders()`. Test mocks stub BOTH shapes (object with getResponse method AND a `->response` property fallback) so existing Mockery patterns from other tests still work."
  - "Automattic\\WooCommerce\\Client 3.1.0 has NO patch() method — only get/post/put/delete/options. WooClient::patch() routes through `$this->inner->http->request($endpoint, 'PATCH', $data)` which is the same underlying method the other verbs use. Documented inline in dispatchWrite()."
  - "WooClient is no longer declared `final` (was in Phase 1) — WooRateLimitTest subclasses it via anonymous class to override the protected `sleepMicros(int)` test seam for deterministic timing. Class is still sealed-by-convention (no documented subclass contract); the only subclass is the in-test stub."
  - "429 backoff jitter uses random_int(0, 500_000) micros (0-500ms) on top of the computed delay — jitter is APPENDED, not replaced by Retry-After. Matches RESEARCH.md §10 pattern; prevents thundering-herd when multiple workers hit the same 429 window."
  - "correlation_id column is VARCHAR(36) on integration_events (and 3 other Phase 1 tables) — test scaffolds MUST use plain UUIDs, not `test-` prefixes, or the INSERT truncation error fires SQLSTATE[22001]. Documented in the 4 new test files' beforeEach blocks. A future hardening plan could widen to VARCHAR(40) but Phase 1 locked the shape and the UUID-only convention is fine."
  - "SupplierClient is bind() not singleton() — token state lives in Cache (external), not on the instance; re-instantiation per resolve is cheap (no cURL handle, no socket), and tests get fresh instances automatically. Different from WooClient which IS a singleton because the underlying Automattic client owns a cURL handle worth reusing across the request."

requirements-completed:
  - SYNC-01
  - SYNC-02
  - SYNC-04
  - SYNC-10

duration: ~45 min
completed: 2026-04-18
---

# Phase 02 Plan 02: External Clients Summary

**Both external-API clients shipped: Woo live-writeable with 429 exponential backoff + Retry-After floor + jitter (SYNC-10), and Supplier JWT-authed with Cache::remember'd 50-min token, md5(username) cache key (P2-B), and strict retry-once-on-401 semantics (SYNC-02 + D-06c). Phase 1's WooClient skeleton is now feature-complete — the LogicException branch is replaced with a real Automattic\\WooCommerce\\Client invocation. 16 new Pest tests green; full suite 126 passing (was 110 baseline + 16 new = 126); 0 Deptrac violations; zero regressions. Ready for Plan 02-03 to wire both clients into SyncSupplierCommand + SyncChunkJob.**

## Performance

- **Duration:** ~45 min
- **Started:** 2026-04-18T21:10Z (composer require kickoff)
- **Completed:** 2026-04-18T21:55Z
- **Tasks:** 2
- **Commits:** 2 task commits + 1 final metadata commit (this SUMMARY + STATE + ROADMAP)
- **Files created:** 7 (2 exception classes + 1 SupplierClient + 4 test files)
- **Files modified:** 7 (composer.json + composer.lock + config/services.php + .env.example + WooClient.php + AppServiceProvider.php + ShadowModeTest.php)

## Accomplishments

### Packages installed (composer)

- `automattic/woocommerce:^3.1` → resolved 3.1.0 (NOT 3.1.1 — see Deviation #1)
- `spatie/simple-excel:^3.9` → resolved 3.9.0 (pre-installed for Plan 02-04)
- Both pinned in `composer.json` with caret ranges; `composer.lock` committed

### WooClient extended (Task 1, `8043ee2`)

- `$inner` typed as `?Automattic\WooCommerce\Client` (previously `mixed`) — reflection test enforces
- `get(string $endpoint, array $query = []): array` — new read method; logs every call to `integration_events` with channel=woo, method=GET, http_status, latency_ms, correlation_id. Errors rethrown + logged as failed.
- `writeOrShadow()` unchanged; the live branch now calls `writeLive()` instead of throwing `LogicException`
- `writeLive()`:
  - Dispatches via `dispatchWrite()` which maps PUT/POST/DELETE to the Automattic client's methods and PATCH to the underlying `HttpClient::request()` call (the Automattic 3.1.0 Client does not expose patch() natively)
  - On `HttpClientException` with status 429: computes exponential delay (500 × 3^(attempt-1) ms), caps at 30s, takes max of that and `Retry-After` header, adds 0-500ms jitter, sleeps via the overridable `sleepMicros()` seam
  - After 5 failed attempts throws `RateLimitExceededException` — single exception per rate-limit burst so the orchestrator's consecutive-failures counter only increments once (D-06(b) safety)
  - Every attempt logs to `integration_events` with status=failed + attempt counter
- `RateLimitExceededException` exception class added

### ShadowModeTest rewrite (Pitfall P2-K)

Test 2 used to assert:
> `config(['services.woo.write_enabled' => true])` → `WooClient::put()` throws `LogicException`

Now asserts:
> `config(['services.woo.write_enabled' => true])` + mocked Automattic client → `put()` calls the Automattic client's put() method with the same args, writes `integration_events` row with status=success, and does NOT create a `sync_diffs` row (live path bypasses shadow).

Other 3 ShadowModeTest cases unchanged (shadow path with `write_enabled=false`).

### SupplierClient shipped (Task 2, `0199ae0`)

- `fetchAllProducts(): array` — paginates `/api/index.php?endpoint=products&page=N&per_page=500` until `next_page` is null; returns `['SKU-1' => ['price' => '99.00', 'stock' => 5], ...]` (flat hashmap, ~15k entries expected per assumption A4)
- `getToken()` via `Cache::remember` with:
  - Key: `supplier.jwt.{md5(username)}` — credential rotation produces a new key (Pitfall P2-B)
  - TTL: 50 minutes (10-min safety margin on assumed 60-min server-side token TTL, A2)
- `authed()` wrapper: catches `RequestException`, checks for 401, purges cache, fetches fresh token, retries closure ONCE; second 401 throws `JwtRefreshFailedException` (D-06c — deliberate no-loop)
- `generateToken()` POSTs to `/generate_token.php` with `{username, password}`; if response is non-2xx or has no `token` field, throws `JwtRefreshFailedException` with HTTP status in the message
- `JwtRefreshFailedException` class added

### IntegrationLogger compliance

All 4 new service calls (`WooClient::get`, `WooClient::writeLive`, `SupplierClient::fetchPage`, `SupplierClient::generateToken`) log to `integration_events` on both success AND failure paths. Redaction verified:
- `authorization` header auto-redacted by IntegrationLogger's SENSITIVE_HEADERS list (Phase 1 invariant)
- `password` field in token.generate request_body explicitly masked to `'***REDACTED***'` (body redaction is producer-side)
- `token` value in token.generate response_body explicitly masked — the actual JWT is never persisted to the DB (only `expires_in` metadata is logged)

### Container bindings (AppServiceProvider::register)

3 new bindings appended:
1. `Automattic\WooCommerce\Client` — singleton, instantiated from `config/services.woo.*`; timeout 30s; verify_ssl=production-only
2. `App\Domain\Sync\Services\WooClient` — singleton wrapping IntegrationLogger + AutomatticClient
3. `App\Domain\Sync\Services\SupplierClient` — `bind` (non-singleton) wrapping IntegrationLogger + Illuminate\Contracts\Cache\Repository

Verified via tinker: `app(WooClient::class)` + `app(SupplierClient::class)` both resolve to concrete classes.

### 16 new Pest tests

| File | Count | Coverage |
|------|-------|----------|
| tests/Feature/WooClientGetTest.php | 4 | G1 array return; G2 success log; G3 error propagate + failed log; G4 constructor type reflection |
| tests/Feature/WooRateLimitTest.php | 4 | R1 backoff timing (≥500ms first delay); R2 Retry-After floor honoured (3s > 500ms computed); R3 5 × 429 throws RateLimitExceededException; R4 jitter produces non-identical sleep totals across 20 trials |
| tests/Feature/SupplierClientTest.php | 4 | S1 flat SKU hashmap across 2 pages; S2 single token fetched per call, reused across pages; S3 every call logged + Authorization redacted + password/token body-masked; S4 /generate_token.php 401 → JwtRefreshFailedException |
| tests/Feature/SupplierClientJwtRefreshTest.php | 4 | J1 401 → purge → refresh → retry succeeds; J2 second 401 → JwtRefreshFailedException (no loop, D-06c); J3 cache key md5(username) — rotation invalidates; J4 token endpoint 500 → exception with HTTP status in message |
| tests/Feature/ShadowModeTest.php (rewritten) | 4 | Shadow path (3 tests unchanged) + rewritten LogicException case → now asserts real Automattic client invocation |

## Task Commits

1. **Task 1** — `8043ee2` `feat(02-02): install Woo SDK + SimpleExcel; WooClient.get() + live-write with 429 backoff (SYNC-04/10)` — 10 files, +896 / -27
2. **Task 2** — `0199ae0` `feat(02-02): add SupplierClient with JWT cache + retry-once-on-401 (SYNC-01/02)` — 4 files, +528 / -0

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 — Blocking] Composer resolved `automattic/woocommerce:^3.1` to 3.1.0, not 3.1.1**

- **Found during:** Task 1, `composer require` execution.
- **Issue:** RESEARCH.md and STACK.md predicted 3.1.1 (released 2026-01-30 per their lookup) but Packagist as of 2026-04-18 only advertises 3.1.0 for the `automattic/woocommerce` package. Resolver picked 3.1.0.
- **Impact:** Minimal — functional surface (`get/post/put/delete` methods + `HttpClient::request()` for PATCH) is identical between the two; PHP 8.5 support noted in 3.1.1 is irrelevant on PHP 8.4.19 dev runtime.
- **Fix:** Documented in key-decisions + SUMMARY. No code change required. Flagged for STACK.md refresh in Plan 02-05 (or next /stack-refresh run).
- **Files modified:** composer.json (caret range `^3.1` matches both 3.1.0 and any future 3.1.x)

**2. [Rule 3 — Blocking] `HttpClient::$response` is PRIVATE in the Automattic 3.1.0 library (RESEARCH snippet assumed public)**

- **Found during:** Task 1, writing the writeLive() status-code reads.
- **Issue:** RESEARCH.md §10 code example used `$this->inner->http->response->code` which assumed `$response` was a public property on the HttpClient. In Automattic 3.1.0 it's `private $response;` — direct property access throws an error on the real client (but not on Mockery stubs which create a shadow object graph).
- **Fix:** Added 2 helper methods — `readResponseCode()` + `readHttpResponseObject()` — that try `$http->getResponse()` (the PUBLIC accessor that returns a `Response` object with `getCode()` / `getHeaders()` methods) first, then fall back to `$http->response` property access for mocks that stub it directly. Works for both real client AND existing Mockery test patterns.
- **Files modified:** `app/Domain/Sync/Services/WooClient.php`
- **Committed in:** `8043ee2` (Task 1)

**3. [Rule 2 — Missing critical] `Automattic\WooCommerce\Client::patch()` does not exist in v3.1.0**

- **Found during:** Task 1, dispatchWrite() implementation.
- **Issue:** Plan's `<action>` snippet uses `$this->inner->{strtolower($method)}($endpoint, $payload)` which works for POST/PUT/DELETE but PATCH has no method on the Automattic Client class. The HttpClient underneath DOES support it via `request($endpoint, 'PATCH', $data)` (verified in HttpClient::setupMethod() which routes `CURLOPT_CUSTOMREQUEST, $method` for `PUT/DELETE/OPTIONS` and also treats unknown methods similarly — the request() method itself is generic).
- **Fix:** `dispatchWrite()` uses a `match` statement that maps POST/PUT/DELETE to the Automattic Client's methods and PATCH to `$this->inner->http->request($endpoint, 'PATCH', $payload)`. Inline comment documents the workaround.
- **Why this is Rule 2:** Phase 1 WooClient::patch() is a public method — if it throws for missing method on the real client, it would break any downstream phase attempting a PATCH write. Fixing it preserves the public API contract.
- **Files modified:** `app/Domain/Sync/Services/WooClient.php`
- **Committed in:** `8043ee2` (Task 1)

**4. [Rule 1 — Bug] Tests initially failed with `correlation_id` column truncation (VARCHAR(36))**

- **Found during:** Task 1, first full test run for WooRateLimitTest.
- **Issue:** My tests used `Context::add('correlation_id', 'test-' . (string) Str::uuid())` — 5-char prefix + 36-char UUID = 41 chars; column is VARCHAR(36); INSERT fails with SQLSTATE[22001].
- **Fix:** Removed the `test-` prefix across all 4 new test files' beforeEach blocks — plain `Str::uuid()` fits cleanly. Added an inline comment noting the column length constraint so future test authors don't re-break this.
- **Files modified:** tests/Feature/WooClientGetTest.php, tests/Feature/WooRateLimitTest.php, tests/Feature/ShadowModeTest.php (rewrite case), tests/Feature/SupplierClientTest.php, tests/Feature/SupplierClientJwtRefreshTest.php (pre-emptive fix after Task 1 discovery)
- **Verification:** All 16 new tests pass; 0 SQLSTATE errors.
- **Committed in:** Embedded in `8043ee2` (Task 1) and `0199ae0` (Task 2)

**5. [Rule 3 — Blocking] WooClient's `final` modifier prevented test-time subclassing for timing assertions**

- **Found during:** Task 1, writing WooRateLimitTest R1 (timing assertion).
- **Issue:** Real `usleep()` in the 429 backoff means R1 (first delay ≥500ms) would take 0.5s per test; R3 (5 × 429) would take ~19.5s accumulated; R4 (20 trials) would take 10+ seconds. Tests need a test-seam override.
- **Fix:** (a) Added `protected function sleepMicros(int $micros): void` as an overridable test seam (default: `usleep($micros)`); (b) removed `final` from WooClient so WooRateLimitTest can create an anonymous subclass that captures slept micros in-memory. This costs us the `final` compile-time guarantee but keeps the timing tests fast + deterministic. The only subclass is in-test; production code never subclasses WooClient.
- **Alternative considered:** Mockery's partial mock on `usleep` — rejected because usleep is a global PHP function, not a method, so partial-mocking requires a PHP-uopz or similar runtime extension not in our dev environment.
- **Files modified:** `app/Domain/Sync/Services/WooClient.php` (remove `final`; add `sleepMicros()` seam), `tests/Feature/WooRateLimitTest.php` (anonymous subclass in the `rateLimitTestClient()` helper)
- **Committed in:** `8043ee2` (Task 1)

---

**Total deviations:** 5 auto-fixed (1 bug, 1 missing-critical, 3 blockers). No Rule 4 architectural asks. Plan contract (both tasks' 94+ passing test target exceeded: 126 passing after plan; exceptions + services + bindings + env wiring all shipped per plan's success_criteria). All 4 SYNC requirements (01/02/04/10) backed by tests.

## Authentication Gates

None encountered during execution — the SupplierClient auth design IS an auth-gate-ready pattern (the orchestrator in Plan 02-03 will catch `JwtRefreshFailedException` at the run level and flip the SyncRun to aborted; an admin will then populate SUPPLIER_API_USERNAME/PASSWORD in .env and re-run), but no actual gate needed for Plan 02-02 scope because tests use `Http::fake()` and we never hit the real 21stcav.com endpoint.

## Issues Encountered

1. **Composer install took ~45-60 seconds on a cold run** — not a blocker, but worth noting that Windows dev with bypass flags (`--ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix`) is slower than Linux CI would be. The single command installing both packages kept lock churn to one round.

2. **Mockery's `$http` property access pattern required shadow object graphs** — since HttpClient::$response is private, the test mock creates `$mockInner->http = Mockery::mock()` then `$mockHttp->shouldReceive('getResponse')->andReturn(new WooResponse(...))`. The WooResponse class is instantiated directly (it's a plain DTO). This is verbose but works cleanly — documented in the test file comments.

3. **WooRateLimitTest R4 (jitter) uses 20 trials to assert `count(array_unique($samples)) > 1`** — technically flaky (collision probability ≈ 1 / 500_000 per pair, so 20 trials has ~(1/500_000)^19 ≈ near-zero chance of all-identical) but not zero. If the test ever flakes on this, widen to 30 trials or use a deterministic PRNG via Laravel's Str::uuid() fake. Not an issue for this plan; noted for future hardening.

## User Setup Required

None for Plan 02-02 test coverage — all tests use `Http::fake()` + `Cache::flush()`.

For **production use** (Plan 02-03+):
- Populate `.env`: `WOO_URL` (e.g. `https://meetingstore.co.uk`), `WOO_CONSUMER_KEY`, `WOO_CONSUMER_SECRET` (ck_/cs_ format from Woo > Settings > Advanced > REST API)
- Populate `.env`: `SUPPLIER_API_USERNAME`, `SUPPLIER_API_PASSWORD` (obtain from 21stcav.com ops team; `SUPPLIER_API_URL` defaults to `https://21stcav.com` via config)
- `WOO_WRITE_ENABLED` stays `false` until Phase 7 cutover runbook — no change in Plan 02-02

## Next Plan Readiness

### Plan 02-03 (SyncSupplierCommand + SyncChunkJob orchestration) can assume

- `app(WooClient::class)->get('products', ['per_page' => 100, 'page' => $n])` returns the Woo product list as an array of associative arrays (stdClass auto-normalised to array via `normaliseResponseBody()`). Every call logs to integration_events with correlation_id threaded.
- `app(WooClient::class)->get("products/{$id}/variations", ['per_page' => 100])` returns variations the same way.
- `app(WooClient::class)->put('products/{id}', ['regular_price' => '...'])` respects `WOO_WRITE_ENABLED`:
  - false → records SyncDiff, returns `['shadow_mode' => true, 'diff_id' => $n]` (Phase 1 behaviour preserved)
  - true → performs real HTTP PUT with 429 backoff; returns the Woo response body as array; throws `RateLimitExceededException` after 5 × 429
- Plan 02-03's AbortGuard can `catch (RateLimitExceededException $e)` + `catch (JwtRefreshFailedException $e)` and flip the SyncRun to `abort_reason='consecutive_failures'` / `abort_reason='jwt_refresh'` respectively. The exceptions are RuntimeException subclasses (catch-hierarchy safe).
- `app(SupplierClient::class)->fetchAllProducts()` returns a full SKU-keyed hashmap (price string + stock int). Plan 02-03's SkuMatcher consumes this directly — no further transformation needed.
- Both clients have IntegrationLogger integrated — no orchestrator code should log outbound HTTP calls itself; just invoke the clients and let them log.

### Plan 02-04 (CSV report + Filament) can assume

- `spatie/simple-excel:^3.9` is installed and `composer require`able from vendor — no additional install step.
- integration_events rows now include a `latency_ms` column (wasn't populated pre-plan) — SyncRunResource can surface an "avg latency" metric by channel.
- `JwtRefreshFailedException` and `RateLimitExceededException` both extend RuntimeException — if they reach the Filament error view, they render as plain user-visible messages.

### Plan 02-05 (Deptrac SYNC-04 guard + retention) can assume

- WooClient is the sole consumer of `Automattic\WooCommerce\Client` across the codebase; AppServiceProvider is the sole binding site. A Deptrac layer `WpRestClient` matching `^Automattic\\WooCommerce\\Client$` can ban imports from every layer EXCEPT `Sync` + `Providers` — any violation attempt means someone bypassed WooClient.
- `app/Domain/Sync/Services/SupplierClient.php` uses `Http::` facade directly — acceptable because the Http facade is framework-level (uncovered by Deptrac layers). Plan 02-05's architecture ruleset does NOT need to allowlist anything additional for SupplierClient.

### Known concerns for later plans

1. **3.1.0 vs 3.1.1 automattic/woocommerce mismatch** — STACK.md refresh should tighten the expected pin so a future Plan 02-05 integrity test (if added) doesn't fail on version drift.
2. **429 test timing via protected sleepMicros seam** — If a future refactor extracts `writeLive()` into a dedicated `WooWriter` service, the `sleepMicros` seam must move with it OR be replaced by an injected `Clock`/`Sleeper` interface. Not needed for Plan 02-03/04/05 but worth tracking.
3. **SupplierClient retry limit is hardcoded at 2 for ConnectionException** — Plan 02-03's AbortGuard may want to make this configurable per-run via SyncRun config. Not blocking; current defaults are fine for v1.
4. **correlation_id length** — VARCHAR(36) locks tests to plain UUIDs. If Phase 3+ wants prefixed IDs (e.g. `sync-{uuid}` for easier log grep), a migration widening the column to VARCHAR(40) would be needed across 4 tables (integration_events, suggestions, webhook_receipts, sync_diffs). Track as a potential Phase 2 Plan 02-05 deferred item.

## Threat Flags

No new trust boundaries introduced beyond the plan's documented threat model. All STRIDE mitigations in frontmatter T-02-02-01..07 are covered:
- **T-02-02-01** (Authorization header leak) — verified via SupplierClientTest S3: `request_headers.authorization === ['***REDACTED***']`
- **T-02-02-02** (JWT cache collision on rotation) — verified via SupplierClientJwtRefreshTest J3
- **T-02-02-04** (401 loop DoS) — verified via SupplierClientJwtRefreshTest J2 (one-shot retry)
- **T-02-02-05** (Woo key exposure in stacktraces) — the Automattic client uses URL query-string auth; `endpoint` stored in integration_events is the PATH only (no query string persisted); WooClient never logs raw URLs. Plan 02-05's negative test will lock this.
- **T-02-02-07** (direct WP DB bypass) — SupplierClient uses `Http::` facade only; WooClient wraps the sole Automattic instance. Plan 02-05 Deptrac ruleset will enforce.

Threats 03 (Redis replay) and 06 (malicious product payload XSS) are accepted/out-of-scope per the plan's threat_model — no new code changes affect them.

## Self-Check: PASSED

- Created files verified:
  - `app/Domain/Sync/Exceptions/RateLimitExceededException.php` FOUND
  - `app/Domain/Sync/Exceptions/JwtRefreshFailedException.php` FOUND
  - `app/Domain/Sync/Services/SupplierClient.php` FOUND
  - `tests/Feature/WooClientGetTest.php` FOUND
  - `tests/Feature/WooRateLimitTest.php` FOUND
  - `tests/Feature/SupplierClientTest.php` FOUND
  - `tests/Feature/SupplierClientJwtRefreshTest.php` FOUND
- Modified files verified:
  - `composer.json` has `automattic/woocommerce:^3.1` + `spatie/simple-excel:^3.9` in require block
  - `composer show automattic/woocommerce` → 3.1.0
  - `composer show spatie/simple-excel` → 3.9.0
  - `config/services.php` has `supplier` block (url/username/password)
  - `.env.example` has `SUPPLIER_API_URL=https://21stcav.com` + username/password keys
  - `app/Domain/Sync/Services/WooClient.php` has `public function get(` signature + `writeLive(` method + constructor types `?AutomatticClient`
  - `app/Providers/AppServiceProvider.php` has `Automattic\WooCommerce\Client` + `WooClient` singleton bindings + `SupplierClient` bind
- Commits verified via `git log --oneline`:
  - `8043ee2` Task 1 FOUND
  - `0199ae0` Task 2 FOUND
- Container resolution (via tinker):
  - `app(App\Domain\Sync\Services\WooClient::class)` → `App\Domain\Sync\Services\WooClient`
  - `app(App\Domain\Sync\Services\SupplierClient::class)` → `App\Domain\Sync\Services\SupplierClient`
- Test results:
  - `vendor/bin/pest tests/Feature/ShadowModeTest.php` — 4 pass
  - `vendor/bin/pest tests/Feature/WooClientGetTest.php` — 4 pass
  - `vendor/bin/pest tests/Feature/WooRateLimitTest.php` — 4 pass
  - `vendor/bin/pest tests/Feature/SupplierClientTest.php` — 4 pass
  - `vendor/bin/pest tests/Feature/SupplierClientJwtRefreshTest.php` — 4 pass
  - **Full suite: 126 passed, 2 skipped** (same 2 Phase 1 designed-skips; 16 net-new Phase 2 tests since baseline)
- `vendor/bin/deptrac analyse --no-progress` — 0 violations, 14 allowed, 350 uncovered (framework)

---

*Phase: 02-supplier-sync*
*Plan: 02-external-clients*
*Completed: 2026-04-18*
