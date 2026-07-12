# 260712-pbi — HOTFIX: suggestions.proposed_by_id must hold AgentRun ULIDs (agent advice can't save)

**Type:** GSD quick task (TDD, atomic commits). Executor does NOT push/deploy.
**Severity:** prod — ALL agent-generated Suggestions fail to write on MariaDB. Surfaced when
`AGENT_WRITE_ENABLED` was first enabled (2026-07-12). Blocks the whole agent-advice feature.

## Root cause (confirmed from a live AgentRun)
`AgentRun` uses **ULID** primary keys. Every agent suggestion writer sets
`proposed_by_type = AgentRun::class` + `proposed_by_id = $run->id` (a 26-char ULID string):
- `AdOptimisationResultMapper` (L131-132), `SeoAgentResultMapper` (L140-141, 172-173),
  `PricingAgentResultMapper` (L100-101), `AgentSuggestionWriter` (L63-64).
But the `suggestions` table defines `proposed_by` via **`nullableMorphs('proposed_by')`**
(migration `2026_04_18_180100`) → `proposed_by_id` is `unsignedBigInteger`. Inserting a ULID into an
integer column → MariaDB strict: `SQLSTATE 1265 Data truncated for column 'proposed_by_id'` → the
INSERT fails → no Suggestion. `AgentSuggestionWriter`'s docblock even notes "NO additive migration
shipped from Plan 03" — the column was never widened for ULIDs. SQLite (tests) ignores column typing,
so every mapper test passed while prod would always have failed. (memory: sqlite-mariadb-strict-trap.)
The `proposed_by` morph IS used (Suggestion::proposedBy(); AgentRunResource ViewAgentRun L154 queries
`where proposed_by_type = AgentRun::class`), so the correct fix is to make the column hold ULIDs — NOT
to null it out.

## Fix — migration widening `proposed_by_id` to a ULID-compatible string
The column is currently **all-NULL in prod** (no agent suggestion ever wrote; non-agent proposers set
`proposed_by_type=null`), so this is **data-safe** — no value conversion.

### Task 1 — Migration (TDD, MariaDB-safe)
New migration changing `suggestions.proposed_by_id` from `unsignedBigInteger` to a **nullable string**
sized for a ULID and index-safe under utf8mb4 (use `char(26)` or `string(..,191)` — ULID is 26 chars;
keep it well under the InnoDB 3072-byte composite-index limit alongside the varchar `proposed_by_type`).
- The `nullableMorphs` created a composite index on `(proposed_by_type, proposed_by_id)`. To be
  bulletproof across MySQL/MariaDB versions, **drop that morph index → change the column → re-create the
  index** (rather than an in-place MODIFY on an indexed column). Do it driver-aware if the index name /
  DDL differs; the real target is **MariaDB** — the migration MUST run cleanly there.
- `down()` restores `unsignedBigInteger` (best-effort; note the column is nullable/empty).
- Keep it driver-portable enough that `migrate` runs green on the SQLite test DB too (Laravel rebuilds
  the table for `change()` on SQLite — verify the test suite migrates cleanly).

### Task 2 — Regression test (TDD)
- Assert an agent-proposed Suggestion round-trips: create an `AgentRun` (ULID id), then
  `Suggestion::create([... 'proposed_by_type' => AgentRun::class, 'proposed_by_id' => $run->id ...])`
  succeeds, and `$suggestion->proposedBy` resolves back to that `AgentRun`. (This is the exact path that
  fails in prod.)
- Assert the column is now a **string type** post-migration in a driver-aware way (e.g.
  `Schema::getColumnType('suggestions','proposed_by_id')` is NOT an integer type; on SQLite accept its
  string/num affinity but assert it is not `integer`/`bigint`). Document in the SUMMARY that the
  definitive MariaDB verification is the prod re-run of "Review with Claude".
- Run the existing AdOptimisation/SEO/Pricing mapper tests — all still GREEN.

## Verify
- `pest`: the new round-trip + column-type test, plus the agent mapper suites (ad/seo/pricing) — GREEN.
  Wider Agents + Suggestions suites — no regression.
- `php artisan migrate` runs clean on the test DB; `php artisan route:list --path=admin` exit 0.
- `pint` pass; `vendor/bin/deptrac analyse` → 0 violations.

## Guardrails / out of scope
- Do NOT change the mappers' code (they're correct — the column was wrong). Fix is the migration only
  (+ test). This one migration unblocks ad/seo/pricing agent suggestion writes together.
- Do NOT stage the pre-existing working-tree noise (`storage/app/research/supplier-probe.json`,
  `tests/Unit/Competitor/CompetitorIngestFreshnessColorTest.php`, untracked `.claude/`).
- PHP/composer via Herd (~/.config/herd/bin/php84/php.exe). No push, no deploy. Atomic commits. Write
  `260712-pbi-SUMMARY.md` (root cause, the migration approach + index handling, tests, and an explicit
  note: **DEPLOY runs a migration; after deploy the operator re-clicks "Review with Claude" to verify
  the suggestion now persists on MariaDB**).
