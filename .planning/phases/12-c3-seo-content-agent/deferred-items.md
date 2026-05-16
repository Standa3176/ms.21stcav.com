
## 2026-05-16 — Pre-existing Architecture suite failures (out of scope for Plan 12-02)

`tests/Architecture/PinnedQuotePricesSurviveRuleEditTest` (and ~17 other Architecture cases)
fails with `SQLSTATE 1 no such table: customer_groups`. These tests were authored in Phase 11
(commit d2b9bb1 — `feat(11-02): QuoteLine immutability + total recompute observers + SHIP GATE`)
and depend on Phase 11 migrations that aren't running cleanly under the local SQLite schema.

Plan 12-02 verified scope: confirmed these failures reproduce on the parent commit `ace2f96`
(before any Plan 12-02 changes). My changes do not touch quote/pricing migrations, RuleResolver,
QuoteLine, or customer_groups. Architecture failures are NOT regressions introduced by Plan 12-02.

Deferred to: a future Phase 11 maintenance plan or an SQLite-schema reconciliation task.
