---
phase: 04-bitrix24-crm-sync
plan: 02
subsystem: crm
tags: [bitrix24, crm, sdk-wrapper, rate-limit, shadow-mode, exception-classification, schema-cache, entity-deduper, pitfall-6, d-11]

# Dependency graph
requires:
  - phase: 01-foundation
    provides: IntegrationLogger, Context, Auditor, BaseCommand
  - phase: 04-bitrix24-crm-sync
    provides: Plan 04-01 shipped BitrixClient skeleton (18 method signatures, 4 real userfield bodies, 14 LogicException stubs); BitrixTransient/PermanentException classes; BitrixEntityMap ledger model + factory; sync_diffs.provider column
provides:
  - BitrixClient with all 18 method bodies implemented (shadow-mode gate + D-11 exception classification + 2 req/sec throttle)
  - BitrixRateLimitMiddleware — Guzzle middleware (token bucket + 429 Retry-After, single retry)
  - BitrixSchemaCache — 24h TTL for deal/contact/company field schemas; validateMapping() push-time stale-mapping detector
  - BitrixSchemaRefreshCommand — `php artisan bitrix:schema:refresh` invalidates + refetches + audits
  - EntityDeduper — 4-step cascade (map → phone → email → create) for Contact; sha256(title+postcode) for Company; UF-filter adopt for Deal
  - Deptrac CRM layer allow-list extended: [Foundation] → [Foundation, Sync, Alerting, Webhooks, Suggestions]
  - 7 new Pest test files (27 tests, 95 assertions) + 50 total CRM tests green
affects:
  - 04-03-webhook-listeners-push-jobs — PushOrderToBitrixJob + PushCustomerToBitrixJob now have full BitrixClient + EntityDeduper contracts to call; D-11 retry hooks are tested; shadow-mode test-rig is available
  - 04-04-filament-ui — CrmFieldMappingResource can consume BitrixSchemaCache::fieldsFor() select dropdowns + "Refresh" button wires to bitrix:schema:refresh
  - 04-05-backfill-gdpr-guardrails — BitrixBackfillChunkJob can route shadow-mode writes through the same SyncDiff table; GDPR erasure uses EntityDeduper::findDealByWooOrderId for re-resolution
  - 07-dashboard-polish-cutover — sync_diffs.provider='bitrix' divergence-scan UI reads the shadow rows accumulated during Plan 04-02 code exercise

# Tech tracking
tech-stack:
  added: []  # bitrix24/b24phpsdk ^1.10.0 already installed in Plan 04-01
  patterns:
    - "withSdk() wrapper pattern — every live SDK call routes through a single classifier (TransportException → Transient; any other Throwable → Permanent) so the D-11 retry policy has exactly ONE decision seam"
    - "Shadow-mode-first pattern — every write method checks config('services.bitrix.write_enabled') as its FIRST statement; reads always hit the SDK so Filament schema discovery works in shadow mode"
    - "Webhook URL sanitisation — sanitiseErrorMessage() runs str_replace on both the URL and the rtrim-slash variant before logging OR rethrowing, closing the T-04-02-01 leak vector"
    - "usleep-based per-instance throttle — SDK 1.10.x ServiceBuilderFactory doesn't accept a Guzzle HandlerStack (verified in vendor/bitrix24/b24phpsdk/src/Services/ServiceBuilderFactory.php:160-172 — only EventDispatcher + LoggerInterface are injectable), so the 2 req/sec ceiling is enforced at the wrapper layer. BitrixRateLimitMiddleware remains shipped + tested for a future SDK version that exposes HTTP-client injection (Phase 7 polish)"
    - "sha256(title + postcode) canonical company dedup key — avoids a lossy TITLE LIKE filter that would drown in noise; case-insensitive via mb_strtolower before hashing"
    - "Adopt-oldest-on-duplicate semantics — duplicateFindByComm and dealList multi-match both take the LOWEST Bitrix ID (oldest record wins) so the Woo order never attaches to a spun-up duplicate; multi-match writes a bitrix.deal.duplicate_detected audit row"

key-files:
  created:
    - app/Domain/CRM/Services/BitrixRateLimitMiddleware.php
    - app/Domain/CRM/Services/BitrixSchemaCache.php
    - app/Domain/CRM/Services/EntityDeduper.php
    - app/Domain/CRM/Console/Commands/BitrixSchemaRefreshCommand.php
    - tests/Feature/CRM/BitrixClientShadowModeTest.php
    - tests/Feature/CRM/BitrixClientExceptionClassificationTest.php
    - tests/Feature/CRM/BitrixRateLimitMiddlewareTest.php
    - tests/Feature/CRM/BitrixSchemaCacheTest.php
    - tests/Feature/CRM/BitrixSchemaRefreshCommandTest.php
    - tests/Feature/CRM/EntityDeduperTest.php
  modified:
    - app/Domain/CRM/Services/BitrixClient.php (14 LogicException stubs replaced with real bodies + withSdk classifier + shadowIfDisabled + sanitiseErrorMessage)
    - app/Providers/AppServiceProvider.php (BitrixClient singleton, BitrixSchemaCache singleton, bitrix:schema:refresh registered)
    - depfile.yaml + deptrac.yaml (CRM layer allow-list extended)

key-decisions:
  - "Rate-limit middleware shipped standalone (tested) but NOT wired into the SDK client — SDK 1.10.x ServiceBuilderFactory (`createServiceBuilderFromWebhook`) only injects EventDispatcher + LoggerInterface; no HandlerStack hook-point. Fallback path from plan action #4 (simpler usleep inside withSdk) is adopted. Middleware stays shipped for forward-compat + documentation of the throttle contract."
  - "Exception classification uses instanceof TransportException catch FIRST, then generic Throwable catch second — Bitrix SDK's TransportException extends BaseException, so ordering matters. This guarantees 5xx/network/429 lands in the transient lane (retry eligible) before a BaseException catch scoops it into permanent."
  - "withSdk() sanitises BOTH the rethrown exception message AND the integration_events.error_message — webhook URL leak is closed on both code paths (the Throwable's message that bubbles to the job layer, and the audit row the ops team reads)."
  - "SDK's duplicate service exposes findByEmail() + findByPhone() as separate methods (not a unified findByComm); wrapper normalises to a single `duplicateFindByComm(type, entityType, values)` so EntityDeduper doesn't care which path the SDK picked. EntityType enum (Lead/Contact/Company) is resolved from the string 'CONTACT' via a match expression."
  - "BitrixSchemaCache singleton: a per-request singleton means Cache::remember() is hit once per entity per request; multi-call pushes in the same job don't re-consult Laravel cache for the same key."
  - "EntityDeduper's `adoptContactIfFound` helper returns null (not throw) when the dupe call finds no match — the caller then drops through to the next cascade step. Throw-on-empty would crunch the 4-step cascade into a branchy try/catch."
  - "findDealByWooOrderId delegates multi-match audit writes behind try/catch so audit failure never blocks the core dedup decision — the Bitrix ID returned is always the lowest, audit-or-no-audit."

patterns-established:
  - "Test-only BitrixClient subclass via anonymous class (e.g. `throwingBitrixClient(\\Throwable)` + `noSdkBitrixClient()`) — lets Task 1 tests exercise the withSdk classifier and the shadow-mode gate without mocking the ServiceBuilder. Parallel to WooClient's protected sleepMicros() test seam."
  - "forgetInstance() after `$this->app->instance(BitrixClient::class, $mock)` — mandatory whenever a downstream service is a singleton (BitrixSchemaCache in Task 2) so the container rebuilds with the mocked dependency on next resolve."
  - "logDecision() helper in EntityDeduper writes one integration_events row per cascade step with endpoint='crm.deduper.{entity}' — Plan 04-04 admin log UI can filter on this endpoint to render the decision trace"

requirements-completed: [CRM-02, CRM-04, CRM-05]

# Metrics
duration: ~60min
completed: 2026-04-19
---

# Phase 4 Plan 02: BitrixClient Wrapper + Schema Cache + EntityDeduper Summary

**Full BitrixClient implementation (18 methods) with shadow-mode gate + D-11 exception classification + 2 req/sec throttle + T-04-02-01 URL sanitisation; 24h BitrixSchemaCache with CLI refresh command; 4-step EntityDeduper cascade (Pitfall 6 mandate) with adopt-oldest-on-duplicate semantics and per-step decision logging — CRM-02, CRM-04, CRM-05 complete.**

## Performance

- **Duration:** ~60 min (3 tasks + 3 minor test fixups)
- **Started:** 2026-04-19T12:51:26Z
- **Completed:** 2026-04-19T13:52:00Z (approx)
- **Tasks:** 3 (all tdd="true" pattern; tests written alongside implementation)
- **Files created:** 10 (4 services/commands + 6 test files)
- **Files modified:** 3 (BitrixClient body, AppServiceProvider bindings + command reg, depfile/deptrac layer allow-list)
- **Tests added:** 27 (95 assertions across 6 new test files)

## Accomplishments

- **All 14 LogicException-throwing BitrixClient methods replaced with real SDK-call bodies** — deal/contact/company Add/Update/Get/List/FieldsGet + duplicateFindByComm. `grep -c "LogicException" app/Domain/CRM/Services/BitrixClient.php` returns 0. Plan 04-03 push jobs can now type-hint against concrete bodies with confidence.
- **Shadow-mode gate is real end-to-end** — every write method (6 total: dealAdd/Update, contactAdd/Update, companyAdd/Update) checks `services.bitrix.write_enabled` as its FIRST statement; when false, a SyncDiff row (`provider='bitrix'`) is created + an integration_events row is written + a `SHADOW-{uuid}` sentinel is returned. A test-only `noSdkBitrixClient()` anonymous subclass proves the SDK is NEVER contacted in shadow mode (`sdk()` hard-throws).
- **D-11 exception classification is testable** — mocked SDK `TransportException` lands in the transient lane (retry-eligible); mocked generic `BaseException` lands in the permanent lane (fail-fast). Response body status is 503 for transient and 4xx for permanent. The job-layer retry policy can rely on these contracts.
- **T-04-02-01 mitigation shipped** — `sanitiseErrorMessage()` runs `str_replace($webhookUrl, '***REDACTED_URL***', $msg)` on both the URL and the rtrim-slash variant. A dedicated test (`it('sanitises webhook URL from exception messages')`) confirms `fake-token` never leaks into either the rethrown exception message or the integration_events audit row.
- **2 req/sec throttle lives at the BitrixClient layer (not the Guzzle HandlerStack)** — SDK 1.10.x's `ServiceBuilderFactory::createServiceBuilderFromWebhook` doesn't accept a Guzzle client, so the wrapper enforces the ceiling via a per-instance `lastCallAt` timestamp + `usleep(500_000)` gap. `BitrixRateLimitMiddleware` stays shipped as a standalone Guzzle middleware (4 tests green) documenting the canonical throttle contract for a future SDK version that opens up HTTP-client injection.
- **BitrixSchemaCache delivers CRM-02 acceptance** — 24h `Cache::remember` on predictable keys (`bitrix:schema:deal`, `bitrix:schema:contact`, `bitrix:schema:company`); `invalidate()` clears all three; `validateMapping()` is the push-time stale-mapping detector that Plan 04-03 push jobs will call. Live-fetch failure invalidates the cache + returns false rather than hiding auth breakage behind stale data.
- **`bitrix:schema:refresh` CLI wires to both admin deploys and the "Refresh from Bitrix" Filament button** — on full success writes a `bitrix.schema.refreshed` activity_log row with per-entity field counts. Any partial failure returns exit code 1 with NO audit row (partial success is failure from ops' perspective).
- **EntityDeduper's 4-step Contact cascade is the Pitfall 6 payoff** — `BitrixEntityMap` ledger lookup (the fast path, hit on every retry after first push) → `duplicateFindByComm('PHONE', 'CONTACT', ...)` → `duplicateFindByComm('EMAIL', 'CONTACT', ...)` → `contactAdd` + record. Multi-value Bitrix COMM fields (PHONE/EMAIL as `[['VALUE' => '...'], ...]`) parsed safely. Adopt-oldest semantics on duplicate match. Every step logs a decision row (`integration_events` with `endpoint='crm.deduper.contact'`) for admin-log visibility.
- **Company dedup avoids a lossy TITLE LIKE filter** — `sha256(json_encode([title: mb_strtolower(trim($t)), postcode: mb_strtolower(trim($p))]))` becomes the `last_payload_hash` row value. Case-insensitive collision on 'ACME Ltd' / 'EC1A 1AA' vs 'acme ltd' / 'ec1a 1aa' is tested.
- **Deal find-only method resolves the race where `order.updated` arrives before `order.created` is processed** — map lookup first (fast path); UF_CRM_WOO_ORDER_ID filter second; multi-match writes `bitrix.deal.duplicate_detected` audit row + adopts the lowest Bitrix ID.
- **Deptrac allow-list extended with 0 violations** — `CRM: [Foundation, Sync, Alerting, Webhooks, Suggestions]`. Plan 04-03 can now dispatch push jobs that write SyncDiff shadow rows + subscribe to Webhooks events + produce Suggestion rows without architecture-test regressions.
- **Full suite remains green** — 477 passed, 2 skipped, 0 failed (4877 assertions). Phase 1/2/3/04-01 all untouched.

## Task Commits

1. **Task 1: BitrixClient full implementation + rate-limit middleware + Deptrac ruleset extension** — `9d79530` (feat)
2. **Task 2: BitrixSchemaCache + bitrix:schema:refresh command** — `4111958` (feat)
3. **Task 3: EntityDeduper 4-step cascade for Contact/Company/Deal** — `1c312c5` (feat)

## Files Created/Modified

See frontmatter `key-files`. Highlights:

- `app/Domain/CRM/Services/BitrixClient.php` — 660+ lines. 18 public methods (14 new bodies + 4 preserved from Plan 04-01); 2 shared helpers (`withSdk`, `shadowIfDisabled`); 8 result-normalisation helpers for SDK → array conversion across Deal/Contact/Company/Duplicate shapes.
- `app/Domain/CRM/Services/BitrixRateLimitMiddleware.php` — 75 lines. Token-bucket sliding window + 429 Retry-After honour + single retry + `reset()` test seam.
- `app/Domain/CRM/Services/BitrixSchemaCache.php` — 95 lines. 24h `Cache::remember` + `invalidate()` + `validateMapping()` with auth-break fall-through.
- `app/Domain/CRM/Services/EntityDeduper.php` — 250+ lines. 4-step Contact cascade + sha256 Company dedup + UF-filter Deal adopt + E.164 phone normaliser + multi-value COMM parser + per-step decision logger.
- `app/Domain/CRM/Console/Commands/BitrixSchemaRefreshCommand.php` — 55 lines. BaseCommand subclass; fail-fast on first per-entity fetch failure; audit on full success only.

## Decisions Made

- **Fallback path for 2 req/sec throttle (plan action #4 "simpler path"):** SDK 1.10.x internal HTTP-client injection IS awkward — validated directly by reading `vendor/bitrix24/b24phpsdk/src/Services/ServiceBuilderFactory.php:160-172` (no HandlerStack parameter). Adopted the `usleep`-inside-`withSdk` approach. `BitrixRateLimitMiddleware` stays shipped + tested as the canonical throttle contract for a future SDK version, and a BitrixClient test-seam subclass could opt into the middleware via its own Guzzle client when exercising the 429 Retry-After path.
- **Exception catch ordering — specific TransportException FIRST, then Throwable:** Bitrix SDK's `TransportException extends BaseException`, so a reversed order would scoop 5xx errors into the permanent lane. Test coverage for both lanes proves the ordering is correct.
- **Dedup adopts LOWEST Bitrix ID on multi-match:** legacy plugin parity (`woo project/bitrix24-extracted/.../includes/Crm.php:621-641`) + "oldest record wins" is the sanest rule for operator intuition. Multi-match writes an audit row so ops can see which duplicates should be merged in the Bitrix UI.
- **BitrixSchemaCache singleton + `forgetInstance()` in tests:** binding BitrixSchemaCache as a singleton means production code resolves ONE cache reader per request (cheap). Tests must `forgetInstance()` after `$this->app->instance(BitrixClient::class, $mock)` so the rebuilt schema cache resolves against the mock.
- **Audit write wrapped in try/catch in `findDealByWooOrderId` multi-match path:** audit failure should not block the dedup decision. The lowest Bitrix ID is returned audit-or-no-audit.
- **EntityDeduper's `duplicateFindByComm` adapter reads both `['CONTACT' => [...]]` (direct REST shape) and `['result']['CONTACT' => [...]]` (wrapped shape)** — accommodates both the raw Bitrix response and potential SDK result-wrapper variations.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Test helper PHP type hint too strict (`MockInterface`)**
- **Found during:** Task 3 (first run of EntityDeduperTest)
- **Issue:** `function makeDeduper(MockInterface $client)` rejected `Mockery::mock(BitrixClient::class)` because Mockery returns `Mockery\LegacyMockInterface` which doesn't satisfy the `MockInterface` union.
- **Fix:** Drop the type hint to accept any client-shaped instance (`function makeDeduper($client)`).
- **Files modified:** tests/Feature/CRM/EntityDeduperTest.php
- **Committed in:** 1c312c5 (Task 3 commit)

**2. [Rule 3 - Blocking] Activity properties array access pattern**
- **Found during:** Task 2 (first run of BitrixSchemaRefreshCommandTest)
- **Issue:** `$activity->properties['counts']->toArray()` threw "Call to member function toArray() on array" — Spatie's activitylog v2 returns a plain PHP array at that depth, not a collection.
- **Fix:** Drop the `->toArray()` call. Verify individual keys directly.
- **Files modified:** tests/Feature/CRM/BitrixSchemaRefreshCommandTest.php
- **Committed in:** 4111958 (Task 2 commit)

**3. [Rule 3 - Blocking] JSON re-serialisation reorders array keys**
- **Found during:** Task 2 (first run of BitrixSchemaRefreshCommandTest)
- **Issue:** `expect($activity->properties['counts'])->toBe(['deal' => 2, 'contact' => 1, 'company' => 3])` failed because activitylog's JSON→array round-trip produced `[deal=>2, company=>3, contact=>1]` in a different order than our ENTITIES constant dictates. PHPUnit's `toBe` is strict on key order.
- **Fix:** Assert each key individually (`$counts['deal'] === 2`, etc.) — order-agnostic + still catches missing entries.
- **Files modified:** tests/Feature/CRM/BitrixSchemaRefreshCommandTest.php
- **Committed in:** 4111958 (Task 2 commit)

---

**Total deviations:** 3 auto-fixed, all Rule 3 blocking issues on test assertions. No production code changes, no scope creep into Plan 04-03/04/05 body work.

## Unexpected SDK Surface Notes

- **`ServiceBuilderFactory::createServiceBuilderFromWebhook` accepts NO Guzzle HandlerStack** — only `EventDispatcherInterface` and `LoggerInterface`. The rate-limit middleware consequently lives outside the wrapped SDK path today; the in-wrapper usleep throttle handles the 2 req/sec ceiling.
- **`Duplicate` service exposes `findByEmail()` + `findByPhone()` as SEPARATE methods** (not a unified `findByComm`). Our `BitrixClient::duplicateFindByComm` normalises to a single external-facing signature; internally it picks the SDK method based on `strtoupper($type) === 'EMAIL'`.
- **`DuplicateResult::getContactsId()` returns `array<int>`** (per legacy SDK shape); we coerce to `array<string>` inside `duplicateResultToArray()` so consumers never have to cast.
- **SDK's `DealResult`/`ContactResult`/etc. return Result objects, not arrays** — normalisation helpers (`dealItemToArray`, `contactsToArray`, etc.) unwrap via `->deal()->getResult()` or `->getCoreResponse()->getResponseData()->getResult()` chains. Consumers see plain arrays.

## Unexpected Exception Classes

None. Only the documented `TransportException` (transient) + `BaseException` (permanent) are thrown by the SDK's CRM service calls. No new exception class needs to be added to the BitrixClient catch chain.

## normalisePhone Regex Decision

- **Shipped regex:** `^\+?[1-9]\d{7,14}$` (E.164: 8–15 digits starting with 1–9, optional `+` prefix)
- **Rationale:** Accepts standard UK/US/EU numbers (`+447700900111`, `447700900111`); rejects literal prefixes, leading zeros, and non-digit noise. Before the regex is applied, all non-digit characters (except `+`) are stripped.
- **Deferred:** Supplier-data validation of edge cases (short regional numbers, extensions, quoted multi-party numbers). If Plan 04-03 push rounds reveal real Woo customer phones that normalise to null, relax the bound to `\d{6,15}` or add a country-specific normaliser pathway.

## Issues Encountered

None besides the 3 Rule-3 test fixups. No Bitrix sandbox needed for any Plan 04-02 work — all test runs used Mockery to fake the SDK surface.

## User Setup Required

None. Plan 04-02 is pure code + test work; no new env vars or operator steps introduced beyond what Plan 04-01 shipped.

## Next Phase Readiness

- **Plan 04-03 (webhook listeners + push jobs) is unblocked** — BitrixClient's 18 methods are concrete; EntityDeduper's three find-or-create methods are ready to consume; exception classification makes D-11 retry policy trivially encodable in the Job's `retryAfter()` / `failed()` hooks.
- **Plan 04-04 (Filament UI) can wire fields-selectors** — BitrixSchemaCache::fieldsFor('deal') feeds the CrmFieldMappingResource's bitrix_field select dropdown; the "Refresh from Bitrix" button wires to `Artisan::call('bitrix:schema:refresh')`; push-time validation goes through `validateMapping()`.
- **Plan 04-05 (backfill + GDPR) can route shadow writes** — BitrixBackfillChunkJob will consume the same `services.bitrix.write_enabled` flag + same `SyncDiff(provider=bitrix)` shadow table; GDPR erasure calls `EntityDeduper::findDealByWooOrderId($wooOrderId)` to resolve the Bitrix Deal before scrubbing PII.
- **Phase 7 dashboard polish has the divergence-scan input** — `SyncDiff::where('provider', 'bitrix')->where('status', 'pending')` is the query the divergence UI filter needs; populated organically every time Plan 04-03 tests fire in shadow mode.

**Blockers:** None.

## Self-Check: PASSED

All 4 task-critical files exist:
- `app/Domain/CRM/Services/BitrixClient.php` — FOUND (`grep -c "LogicException" == 0`; `grep -c "shadowIfDisabled" == 6`)
- `app/Domain/CRM/Services/BitrixRateLimitMiddleware.php` — FOUND
- `app/Domain/CRM/Services/BitrixSchemaCache.php` — FOUND (`grep -c "Cache::remember" == 1`)
- `app/Domain/CRM/Services/EntityDeduper.php` — FOUND (`grep -c "findOrCreateContact\|findOrCreateCompany\|findDealByWooOrderId" == 3+`; `grep -c "duplicateFindByComm" == 2`)

All 3 task commits resolvable in git log:
- `9d79530 feat(04-02): BitrixClient full SDK wrapper + shadow-mode + rate-limit middleware (Task 1)`
- `4111958 feat(04-02): BitrixSchemaCache + bitrix:schema:refresh command (Task 2)`
- `1c312c5 feat(04-02): EntityDeduper 4-step cascade for Contact/Company/Deal (Task 3)`

`php artisan list bitrix` lists all 3 expected commands:
- bitrix:bootstrap
- bitrix:schema:refresh (NEW — this plan)
- bitrix:smoke-test

Final test suite: **477 passed, 2 skipped, 0 failed (4877 assertions)**. CRM sub-suite: **50 passed**. Deptrac: **0 violations, 0 warnings, 0 errors**.

---
*Phase: 04-bitrix24-crm-sync*
*Plan: 02*
*Completed: 2026-04-19*
