# Deferred items — 260709-m3p (clean Deptrac refactors)

## Pre-existing test failures (OUT OF SCOPE — not caused by this task)

Discovered while running the extra-diligence `tests/Feature/Agents`,
`tests/Feature/Integrations`, `tests/Unit/Domain/Agents` suites (NOT in this
plan's verification scope, which is Products / ProductAutoCreate / Sync /
Unit\Domain\Sync — those are 358/358 GREEN).

**21 pre-existing failures, all unrelated to the namespace moves.** Root cause
is a stale-enum + credential/DB-config drift that predates this task. None of
the failing source files are in this task's changeset.

### Smoking gun — `IntegrationCredentialKind` enum drift
- `app/Domain/Integrations/Enums/IntegrationCredentialKind.php` now declares
  **10** cases.
- `tests/Feature/Integrations/IntegrationCredentialKindEnumTest` asserts
  **exactly 7** → FAIL ("actual size 10 matches expected size 7").
- `tests/Feature/Integrations/IntegrationHealthWidgetTest` (3 cases) asserts
  **5** health tiles → FAIL ("actual size 10 matches expected size 5").
- Neither the enum nor these tests are touched by 260709-m3p. The enum grew
  (Langfuse + others) without the tests being updated.

### Cascading / environmental failures (files untouched by this task)
- `Tests\Feature\Agents\ClaudeClientTest` (3) — `IntegrationCredentialMissingException`.
  Runtime credential resolution (Anthropic key) against the drifted credential
  config. NOT a class-resolution error → the ClaudeClient → Integrations move is
  fine (a broken import would fatal with "class not found", not this runtime
  exception). Only the test's `use` import was updated by this task.
- `Tests\Feature\Agents\PricingAgentCalibrationTest` (4) — same
  `IntegrationCredentialMissingException` root cause. Import-only edit here too.
- `Tests\Feature\Agents\AgentRunGdprScrubberTest` (7) — `QueryException`
  (gdpr_erasure_log / scrubber DB shape). File untouched by this task.
- `Tests\Feature\Agents\RunPricingAgentJobTest` (3) — `RuntimeException`
  "Failed to serialize job … Serialization of 'Closure' is not allowed". Queued
  listener closure-serialization. File's only edit here was the ClaudeClient
  FQCN swap in `app(...)` runtime calls.

**Action:** Leave as-is. These belong to a separate follow-up (enum-test refresh
+ agent credential/queue test harness fixes). Do NOT fix under 260709-m3p — they
are outside the refactor's blast radius and the in-scope suites are all green.

## Follow-up task (separate, already planned)
The 8 remaining Deptrac violations are Filament-in-domain cross-reads and are
explicitly deferred to the "260709 Deptrac Filament refactor" follow-up:
- `Pricing\Filament\Pages\PricingOperationsPage → Competitor\Models\CompetitorPrice` (x2)
- `ProductAutoCreate\Filament\...\EditAutoCreateReview → Agents\Appliers\SeoContentPatchApplier` (x2)
- `Suggestions\Filament\Resources\SuggestionResource → Competitor\Models\Competitor` (x2)
- `Suggestions\Filament\Resources\SuggestionResource → ProductAutoCreate\Jobs\RunAutoCreatePipelineJob` (x2)
