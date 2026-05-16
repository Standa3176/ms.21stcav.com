
## 2026-05-16 — Pre-existing Architecture suite failures (out of scope for Plan 12-02)

`tests/Architecture/PinnedQuotePricesSurviveRuleEditTest` (and ~17 other Architecture cases)
fails with `SQLSTATE 1 no such table: customer_groups`. These tests were authored in Phase 11
(commit d2b9bb1 — `feat(11-02): QuoteLine immutability + total recompute observers + SHIP GATE`)
and depend on Phase 11 migrations that aren't running cleanly under the local SQLite schema.

Plan 12-02 verified scope: confirmed these failures reproduce on the parent commit `ace2f96`
(before any Plan 12-02 changes). My changes do not touch quote/pricing migrations, RuleResolver,
QuoteLine, or customer_groups. Architecture failures are NOT regressions introduced by Plan 12-02.

Deferred to: a future Phase 11 maintenance plan or an SQLite-schema reconciliation task.

## 2026-05-16 — PricingAgentCalibrationTest failures (out of scope for Plan 12-03)

`tests/Feature/Agents/PricingAgentCalibrationTest.php` 4 fixtures (data-rich HIGH /
data-sparse LOW / withMaxSteps exhausted / malformed-args) fail with
`IntegrationCredentialMissingException` — the IntegrationCredentialResolver expects an
`integration_credentials` row with `kind='anthropic_api'` to be seeded in the local DB
but none exists in the SQLite-in-memory test environment.

Plan 12-03 verified scope: confirmed all 4 failures reproduce on commit `2ca020e` (before
the Task 3 integration test was added). My changes do NOT touch the integration credentials
resolver, ClaudeClient, or Phase 10's prompt rendering — Plan 12-03's surface is the
Plan 10 backward-compat invariants on GuardrailViolationException + the new SEO guardrail.

The PricingAgentCalibrationTest failures appear to be a pre-existing local env gap
(the test should mock the resolver or seed a fake credential; it currently does
neither). Same posture as Plan 12-02 deferred items — out of scope, not a regression.

Deferred to: a future Phase 10 calibration-test fixture-hygiene plan.

