# 15a-01 — GA4 read foundation (approval-free, shadow-safe)

**Type:** GSD phase-plan slice (TDD, atomic commits). Executor does NOT push/deploy.
**Parent:** Phase 15 (expanded) — Marketing Intelligence. Research: `15-RESEARCH.md`.
**Decisions:** D15-1/2/3 in STATE.md — GA4-first, read-only, writes deferred.

## Goal
Stand up the GA4 Data API read path as a first-class integration so the operator can paste a
service-account key + property id and immediately verify connectivity — WITHOUT any Google
approval (GA4 needs only read access the operator grants). This slice is auth + a testable
client wrapper + a connection-test command. NO data pull, NO snapshot table, NO migration yet
(those are 15a-02). Fully read-only — the Data API cannot mutate anything.

## Context / patterns to mirror (verified)
- Credential kinds: `app/Domain/Integrations/Enums/IntegrationCredentialKind.php` — a `string`
  enum with `requiredFields()/optionalFields()/label()/urlFields()/color()` match arms. Add a
  new case and an arm in each method.
- Credential resolution: clients take `IntegrationCredentialResolver` via constructor and call
  `$this->resolver->for(IntegrationCredentialKind::X)` to get the decrypted payload (see
  `EanSearchClient::credentials()`).
- Client wrapper shape: mirror `EanSearchClient` — constructor-injected resolver, a public
  `testConnection(): IntegrationTestResult`, request logging. Place the new client in
  `app/Domain/Integrations/Clients/GoogleAnalyticsClient.php` (alongside `ClaudeClient.php` —
  deptrac-clean location).
- Test-connection UI: `app/Domain/Integrations/Filament/Actions/TestIntegrationAction.php`
  switches per kind — add a branch calling `GoogleAnalyticsClient::testConnection()`.
- PHP is `^8.2` (dev 8.3); `google/analytics-data` requires PHP 8.1+ ✓.

## Tasks

### Task 1 — Add the `google/analytics-data` dependency
`composer require google/analytics-data:^0.24` (REST transport — do NOT require the gRPC PECL
ext; the wrapper must use `['transport' => 'rest']`). Commit `composer.json` + `composer.lock`.
If the exact `^0.24` is unavailable, take the closest current stable and note the version used.

### Task 2 — `GoogleAnalytics` credential kind (TDD)
Add `case GoogleAnalytics = 'google_analytics';` and arms:
- `requiredFields()` → `['service_account_json', 'property_id']`
- `optionalFields()` → `[]` (default)
- `label()` → `'Google Analytics 4 (Data API)'`
- `urlFields()` → `[]` (add to the no-URL group)
- `color()` → `'info'` (data-source parity)
Test: extend the existing IntegrationCredentialKind enum test (find it under
`tests/**/*IntegrationCredentialKind*`) — assert the new case's requiredFields/label/color, and
that any "count of cases" assertions are updated (enum grows 10→11; there is a prior instance of
a cases()-count test that must be bumped — see memory: enum-grew tests).

### Task 3 — `GoogleAnalyticsClient` wrapper with a mockable seam (TDD)
`app/Domain/Integrations/Clients/GoogleAnalyticsClient.php`:
- Constructor: `IntegrationCredentialResolver $resolver` (+ an OPTIONAL injected
  `?BetaAnalyticsDataClient $client = null` OR a protected `makeClient(array $creds)` factory
  method — whichever gives a clean test seam WITHOUT hitting the network; mirror how WooClient/
  BitrixClient are unit-tested with a mocked inner SDK client).
- `credentials(): ?array` → `$this->resolver->for(IntegrationCredentialKind::GoogleAnalytics)`.
- Build the inner client from creds: decode `service_account_json`, pass
  `['credentials' => <decoded array>, 'transport' => 'rest']`; property id = `properties/{property_id}`.
- `testConnection(): IntegrationTestResult` — run a minimal `runReport` (metric `sessions`,
  dateRange yesterday→yesterday, limit 1) and return ok/fail with a human message; catch SDK
  exceptions → failure result (never throw out of testConnection). READ-ONLY.
- (Optional, if trivial) a thin `runReport(array $params)` passthrough for 15a-02 to build on —
  but do NOT add any pull/persist logic here.
Tests: mock the inner `BetaAnalyticsDataClient` so `testConnection()` returns success on a stub
report and failure on a thrown SDK exception. No real network. Driver-agnostic (no DB).

### Task 4 — Wire into `TestIntegrationAction`
Add the `GoogleAnalytics` branch so the "Test connection" button in the Integration Credentials
UI calls `GoogleAnalyticsClient::testConnection()`. Test the action branch if the existing
action has test coverage; otherwise a focused unit test on the resolver→client path is fine.

### Task 5 — Config
Add a `services.google_analytics` block if the app's convention resolves any static config
(follow how `services.php` handles other integration kinds; most creds come from the DB
credential store, so this may be minimal/empty — match precedent, don't invent env keys that
aren't used).

## Verify
- `pest` for the touched areas: the enum test, the new `GoogleAnalyticsClient` test, and any
  TestIntegrationAction test — all GREEN.
- `composer dump-autoload` clean; app boots (`php artisan route:list --path=admin` exit 0).
- `pint` pass on touched files.
- `vendor/bin/deptrac analyse` (or the project's deptrac test) — the new client sits in the
  Integrations layer; ensure no new violation. If deptrac flags it, the client belongs in
  `Integrations\Clients` (allowed) — do NOT add allow-list entries without noting why.

## Guardrails / out of scope
- READ-ONLY. No writes, no mutations, no scheduled pull, no snapshot table, NO migration.
- Do NOT stage the pre-existing working-tree noise (`storage/app/research/supplier-probe.json`,
  `tests/Unit/Competitor/CompetitorIngestFreshnessColorTest.php`, untracked `.claude/`).
- No push, no deploy. Atomic commits, e.g.:
  `feat(15a-01): add google/analytics-data dep`,
  `feat(15a-01): GoogleAnalytics credential kind + GoogleAnalyticsClient + ga4-test connection`.
- Write a `15a-01-SUMMARY.md` in this phase dir on completion (commit SHAs, versions used,
  test results, the mockable-seam approach chosen).
