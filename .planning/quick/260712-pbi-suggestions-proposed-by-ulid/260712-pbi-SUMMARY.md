# 260712-pbi — SUMMARY: suggestions.proposed_by_id widened for AgentRun ULIDs

**One-liner:** Migration changing `suggestions.proposed_by_id` from `unsignedBigInteger`
(from `nullableMorphs`) to nullable `CHAR(26)` so agent-generated Suggestions carrying a
26-char AgentRun ULID stop failing on MariaDB — the single fix that unblocks ad/seo/pricing
agent suggestion writes together.

**Type:** GSD quick task (TDD, atomic commits). No push, no deploy.
**Status:** COMPLETE — all verify gates green.

## Root cause (confirmed)

`AgentRun` uses ULID primary keys. Every agent suggestion writer
(`AdOptimisationResultMapper`, `SeoAgentResultMapper`, `PricingAgentResultMapper`,
`AgentSuggestionWriter`) sets `proposed_by_type = AgentRun::class` +
`proposed_by_id = $run->id` (a 26-char ULID string). But the `suggestions` table defined
`proposed_by` via `nullableMorphs('proposed_by')` (migration `2026_04_18_180100`) →
`proposed_by_id` was `UNSIGNED BIGINT`. Inserting a ULID into an integer column under MariaDB
strict mode → `SQLSTATE 1265 Data truncated for column 'proposed_by_id'` → the INSERT fails →
no Suggestion. SQLite (tests) ignores column typing, so every mapper test passed while prod
always failed (memory: sqlite-mariadb-strict-trap). The morph is in active use
(`Suggestion::proposedBy()`; `ViewAgentRun` queries `where proposed_by_type = AgentRun::class`),
so the correct fix is to make the column hold ULIDs — NOT to null the morph out.

## Migration approach (index handling + column type)

New migration `2026_07_12_000000_widen_suggestions_proposed_by_id_for_ulids.php`:

- **Column type chosen: nullable `CHAR(26)`.** ULID is exactly 26 chars. This matches the
  canonical ULID width already used in the schema (`integration_events.subject_id` is
  `nullableUlidMorphs` → `CHAR(26)`). Under utf8mb4 that is 104 bytes; alongside the
  `VARCHAR(255)` `proposed_by_type` (1020 bytes) the composite index stays far under the
  InnoDB 3072-byte limit.
- **MariaDB-safe index handling (drop → change → re-create):** `nullableMorphs` created the
  composite index `suggestions_proposed_by_type_proposed_by_id_index` on
  `(proposed_by_type, proposed_by_id)`. Rather than modifying a column while it is part of a
  live index, `up()` does:
  1. `dropIndex(['proposed_by_type','proposed_by_id'])`
  2. `char('proposed_by_id', 26)->nullable()->change()`
  3. `index(['proposed_by_type','proposed_by_id'])` (re-create with the default name)
- **Driver-portable, single path:** the default morph index name is identical on
  MySQL/MariaDB and SQLite, so one Schema-builder path serves both. Laravel 12.56 has native
  `change()` (no doctrine/dbal needed); on SQLite `change()` rebuilds the table (the remaining
  three indexes are preserved during the rebuild, and the morph index is re-added after).
- **`down()`** best-effort restores `UNSIGNED BIGINT` (same drop→change→re-create dance). The
  column is nullable/empty in prod so this is data-safe.
- **Data-safe:** the column is all-NULL in prod (no agent Suggestion ever wrote; non-agent
  proposers set `proposed_by_type = NULL`), so there is no value conversion.
- **Mappers unchanged** — they were correct; the column was wrong. This one migration unblocks
  ad/seo/pricing agent suggestion writes together.

## Regression test

`tests/Feature/Suggestions/ProposedByUlidRoundTripTest.php` (2 tests, 6 assertions):

1. **Round-trip (the exact prod-failing path):** create an `AgentRun` (asserts 26-char ULID id),
   `Suggestion::create([... 'proposed_by_type' => AgentRun::class, 'proposed_by_id' => $run->id])`
   persists intact, and `$suggestion->fresh()->proposedBy` resolves back to that `AgentRun`.
   **Result on SQLite: PASS** (round-trip resolves, morph rehydrates the AgentRun).
2. **Column-type guard (driver-aware):** `Schema::getColumnType('suggestions','proposed_by_id')`
   is NOT in `['integer','bigint','biginteger']`. On SQLite `char(26)` maps to `varchar`
   affinity — the assertion is what catches an integer-column regression. **Result: PASS.**

> **TDD note:** the plan orders Task 1 (migration) then Task 2 (test); commits are atomic in that
> order. Strict RED-first isn't meaningful for the round-trip half on SQLite (SQLite would accept
> a ULID into an int column, so the INSERT never fails there) — the column-type assertion is the
> real regression guard and would return `integer` (RED) against the pre-migration schema
> (confirmed: pre-migration `getColumnType` was integer affinity; post-migration it is `varchar`).

## Verify results

| Gate | Result |
|------|--------|
| `migrate:fresh` on SQLite test DB | clean, exit 0 (new migration DONE 21.67ms) |
| New round-trip + column-type test | 2 passed / 6 assertions |
| `tests/Feature/Agents` + `tests/Unit/Domain/Agents` (incl. Ad/SEO/Pricing mappers) | 306 passed / 823 assertions |
| `tests/Feature/Suggestions` + `tests/Unit/Suggestions` | 45 passed / 194 assertions |
| `route:list --path=admin` | exit 0 |
| `pint` (changed files) | pass |
| `deptrac analyse` | 0 violations |

## Commits

- `3ecfed6` — fix: widen suggestions.proposed_by_id to CHAR(26) for AgentRun ULIDs (migration)
- `05c3902` — test: guard AgentRun ULID round-trip on suggestions.proposed_by_id

## DEPLOY NOTE (operator)

**Deploy runs a database migration.** After deploying to prod (MariaDB), the operator must
**re-click "Review with Claude"** on a product to trigger a fresh AgentRun and confirm the
Suggestion now persists (the definitive MariaDB verification — SQLite cannot prove the strict-mode
truncation is fixed). Expect the ad/seo/pricing agent advice to appear in the Suggestions list
where before it silently failed to write. No worker/queue config change required; no mapper code
changed.

## Deviations from plan

None — plan executed exactly as written. Pre-existing working-tree noise
(`storage/app/research/supplier-probe.json` deletion,
`tests/Unit/Competitor/CompetitorIngestFreshnessColorTest.php` modification, untracked `.claude/`)
was left untouched and not staged, per guardrails.

## Self-Check: PASSED

- Migration file exists: `database/migrations/2026_07_12_000000_widen_suggestions_proposed_by_id_for_ulids.php`
- Test file exists: `tests/Feature/Suggestions/ProposedByUlidRoundTripTest.php`
- Commit `3ecfed6` present in `git log`
- Commit `05c3902` present in `git log`
