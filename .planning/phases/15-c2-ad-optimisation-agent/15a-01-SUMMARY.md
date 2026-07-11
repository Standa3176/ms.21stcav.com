---
phase: 15
plan: 15a-01
subsystem: Integrations / Marketing Intelligence
tags: [ga4, google-analytics, integration, read-only, tdd]
requires:
  - IntegrationCredentialKind enum + IntegrationCredentialResolver (Phase 09.1)
  - IntegrationTestResult value object (Phase 09.1)
  - TestIntegrationAction per-kind dispatch (Phase 09.1)
provides:
  - IntegrationCredentialKind::GoogleAnalytics ('google_analytics')
  - App\Domain\Integrations\Clients\GoogleAnalyticsClient (READ-ONLY GA4 Data API wrapper)
  - "Test connection" support for GA4 in the Integration Credentials admin UI
affects:
  - 15a-02 (snapshot pull/persist builds on GoogleAnalyticsClient::runReport())
tech-stack:
  added:
    - "google/analytics-data:^0.24 (installed 0.24.0) — REST transport only, no gRPC PECL"
  patterns:
    - "resolver-injected client + testConnection(): IntegrationTestResult (mirrors EanSearchClient/WooClient)"
    - "final inner SDK client -> method-override test seam (partial mock of runReport()/credentials())"
key-files:
  created:
    - app/Domain/Integrations/Clients/GoogleAnalyticsClient.php
    - tests/Unit/Domain/Integrations/Clients/GoogleAnalyticsClientTest.php
    - tests/Unit/Domain/Integrations/Enums/IntegrationCredentialKindGoogleAnalyticsTest.php
  modified:
    - app/Domain/Integrations/Enums/IntegrationCredentialKind.php
    - app/Domain/Integrations/Services/IntegrationCredentialResolver.php
    - app/Domain/Integrations/Filament/Actions/TestIntegrationAction.php
    - config/services.php
    - database/factories/Domain/Integrations/IntegrationCredentialFactory.php
    - tests/Feature/Integrations/TestIntegrationActionTest.php
    - composer.json
    - composer.lock
decisions:
  - "Decoded-array credentials per plan (['credentials' => <array>, 'transport' => 'rest']); GA4 read-only so no service-account object hardening needed this slice."
  - "Inner BetaAnalyticsDataClient is `final` -> test seam is the overridable public runReport() + protected credentials(), not a constructor-mock (deviation Rule 3)."
metrics:
  duration: ~26m
  completed: 2026-07-11
---

# Phase 15 Plan 15a-01: GA4 Read Foundation Summary

Stood up a READ-ONLY, shadow-safe GA4 Data API integration: `google/analytics-data`
(REST transport, no gRPC PECL) behind a new `GoogleAnalytics` credential kind and a
unit-testable `GoogleAnalyticsClient` whose "Test connection" runs a minimal read-only
`runReport` (metric `sessions`, yesterday→yesterday, limit 1). No data pull, no snapshot
table, no migration — those are 15a-02.

## Tasks completed

| Task | Description | Commit |
| ---- | ----------- | ------ |
| 1 | Add `google/analytics-data:^0.24` dep (installed 0.24.0, REST transport) | `aaaf29a` |
| 2 + 5 | `GoogleAnalytics` credential kind (enum arms) + resolver env-fallback + `config/services.google_analytics` | `12e0c39` |
| 3 | `GoogleAnalyticsClient` READ-ONLY wrapper + unit test (no network, no DB) | `cf2ced0` |
| 4 | Wire `GoogleAnalytics` branch into `TestIntegrationAction` + factory arm + matrix test | `231b9d1` |

## Key facts requested

- **`google/analytics-data` version installed:** `0.24.0` (satisfies `^0.24`).
- **Transport:** REST only — inner client always built with `['transport' => 'rest']`;
  the gRPC PECL extension is never required. (`vendor/grpc` is present only as a
  transitive composer stub; not used.)
- **Test seam chosen:** The inner SDK client
  `Google\Analytics\Data\V1beta\Client\BetaAnalyticsDataClient` is declared **`final`**,
  so it cannot be Mockery-mocked or subclassed. The clean seam is therefore an
  overridable **public `runReport(RunReportRequest): RunReportResponse`** passthrough plus
  a **protected `credentials()`** — both stubbed in the unit test via a Mockery
  **partial mock** (`makePartial()->shouldAllowMockingProtectedMethods()`). No real client
  is ever constructed, so no service-account JSON is parsed and no HTTP/network occurs, and
  the test needs no DB. A pre-built client may still be constructor-injected
  (`?BetaAnalyticsDataClient $client = null`) for future service-provider binding, mirroring
  WooClient's `?AutomatticClient` seam. `testConnection()` catches `Throwable` and returns a
  failure `IntegrationTestResult` — it never throws.

## Verify results

- **Pest (all touched areas): 23 passed / 170 assertions.**
  - `IntegrationCredentialKindGoogleAnalyticsTest` — 7 passed
  - `GoogleAnalyticsClientTest` — 5 passed (ok / property+report shape / SDK ApiException → failure / not-configured → failure / constructor reflection)
  - `TestIntegrationActionTest` — 5 passed (GA4 added to per-kind dispatch matrix)
  - `IntegrationCredentialKindEnumTest` — 3 passed (count derived from `cases()`, stays green at 11)
  - `IntegrationHealthWidgetTest` — 3 passed (count derived from `cases()`)
- **Boot check:** `php artisan route:list --path=admin` exit 0 (95 routes shown).
- **Pint:** pass on all touched files (`{"result":"pass"}`).
- **Deptrac:** `analyse` → **0 violations, 0 errors** (GoogleAnalyticsClient sits in the
  `Integrations` layer; no allow-list changes needed). The only stderr output is a
  pre-existing PHP 8.4 deprecation notice from the vendored `deptrac-shim` symfony/string —
  unrelated to this change.

## Enum "count of cases" note

The plan flagged a likely hard-coded `10→11` count bump. **No hard-coded count exists** —
both `IntegrationCredentialKindEnumTest` and `IntegrationHealthWidgetTest` derive their
expected count from `count(IntegrationCredentialKind::cases())`, so they are rot-proof and
stayed green as the enum grew to 11 cases. No count assertion needed changing.

## Deviations from Plan

### 1. [Rule 3 — Blocking] Test seam: method-override instead of inner-client mock
- **Found during:** Task 3
- **Issue:** The plan suggested "mock the inner `BetaAnalyticsDataClient`", but that class is
  declared `final` and cannot be Mockery-mocked/subclassed.
- **Fix:** Used the plan's sanctioned alternative — an overridable public `runReport()` +
  protected `credentials()`/`client()` seam, stubbed via a Mockery partial mock of
  `GoogleAnalyticsClient` itself. No network, no DB. Constructor still accepts an optional
  pre-built `?BetaAnalyticsDataClient` for DI symmetry with WooClient.
- **Files:** `GoogleAnalyticsClient.php`, `GoogleAnalyticsClientTest.php`
- **Commit:** `cf2ced0`

### 2. [Rule 3 — Blocking] IntegrationCredentialFactory GoogleAnalytics arm
- **Found during:** Task 4
- **Issue:** `IntegrationCredentialFactory::kind()` has no `default` match arm; adding
  `GoogleAnalytics` to the `TestIntegrationActionTest` dispatch matrix would throw
  `UnhandledMatchError` when creating the row.
- **Fix:** Added a `GoogleAnalytics` payload arm to the factory (test support only).
- **Files:** `IntegrationCredentialFactory.php`
- **Commit:** `231b9d1`

## Guardrails honoured

- READ-ONLY: no writes/mutations, no scheduled pull, no snapshot table, **no migration**.
- REST transport only; no dependency on the gRPC PECL extension.
- Client placed in `app/Domain/Integrations/Clients/` (deptrac-clean).
- Did **not** stage or touch the pre-existing working-tree noise
  (`storage/app/research/supplier-probe.json` deletion,
  `tests/Unit/Competitor/CompetitorIngestFreshnessColorTest.php`, untracked `.claude/`) —
  they remain unstaged.
- No push, no deploy. Four atomic per-task commits.

## Known Stubs

None. `GoogleAnalyticsClient::runReport()` is an intentional thin passthrough left as the
seam for 15a-02's snapshot ingestion; it delegates to the live SDK and contains no
placeholder data.

## Self-Check: PASSED

- Created files exist: `GoogleAnalyticsClient.php`, `GoogleAnalyticsClientTest.php`,
  `IntegrationCredentialKindGoogleAnalyticsTest.php` — all FOUND.
- Commits exist: `aaaf29a`, `12e0c39`, `cf2ced0`, `231b9d1` — all FOUND.
