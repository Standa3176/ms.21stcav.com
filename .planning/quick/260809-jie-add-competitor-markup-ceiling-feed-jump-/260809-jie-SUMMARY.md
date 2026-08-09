---
phase: quick
plan: 260809-jie
subsystem: pricing
tags: [pricing, competitor-intelligence, incident-response, guardrails, tdd, pest]

# Dependency graph
requires:
  - phase: 05-competitor-intelligence
    provides: CompetitorCsvRowWriter, CompetitorPrice model, competitor CSV ingest pipeline
  - phase: 03-pricing-engine
    provides: PriceCalculator, CompetitorUndercutPricer, RuleResolver (all untouched — byte-locked)
provides:
  - Guard 1 — margin-ceiling block in pricing:undercut-competitors (config('competitor.max_margin_ceiling_bps'))
  - Guard 2a — feed-jump quarantine flag on competitor_prices ingest (is_price_anomaly, config('competitor.max_row_move_pct'))
  - Guard 2b — pricer excludes quarantined rows from "lowest current competitor" lookup
  - competitor_price_ceiling_blocked Suggestion kind for human review
affects: [pricing, competitor-intelligence, suggestions]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Suggestion dedup keyed on (kind, evidence->sku, PENDING status) via updateOrCreate-shape lookup — same pattern as OrphanDetector::record()"
    - "Persist-everything-gate-on-consumption for anomalous data — quarantined competitor_prices rows still persist (audit trail), only excluded from the one query that turns them into a live price"

key-files:
  created:
    - database/migrations/2026_08_09_000000_add_price_anomaly_flag_to_competitor_prices_table.php
    - tests/Feature/Pricing/CompetitorUndercutPricingCommandMarginCeilingTest.php
    - tests/Feature/Competitor/CompetitorPriceAnomalyQuarantineTest.php
    - tests/Feature/Pricing/CompetitorUndercutPricingCommandQuarantineExclusionTest.php
  modified:
    - app/Console/Commands/CompetitorUndercutPricingCommand.php
    - app/Domain/Competitor/Services/CompetitorCsvRowWriter.php
    - app/Domain/Competitor/Models/CompetitorPrice.php
    - config/competitor.php
    - database/factories/Domain/Competitor/CompetitorPriceFactory.php

key-decisions:
  - "Guard 1 fires in BOTH dry-run and --live (informational review flag, not a price write) — operators only run --live once daily, so dry-run detection is the earlier warning signal"
  - "Guard 2a flags the row but still writes it (persist-everything-gate-on-consumption convention) — full audit trail retained, only Guard 2b's pricer query excludes flagged rows"
  - "No SuggestionApplier registered for competitor_price_ceiling_blocked — informational-only, same shape as existing auto_create_failed/crm_push_failed/quote_push_failed kinds"

patterns-established:
  - "Asymmetric margin guardrail pair: min_margin_floor_bps (never sell at a loss) + max_margin_ceiling_bps (never sell at an implausible feed-error markup) — same posture, opposite direction"

requirements-completed: []

# Metrics
duration: 8min
completed: 2026-08-09
---

# Quick Task 260809-jie: Competitor Pricing Safety Guards Summary

**Margin-ceiling block (50% default) plus feed-jump quarantine (50%-move default) close the exact gap that let a bad competitor feed row reprice SKU 9C941AA 280% above cost in production today.**

## Performance

- **Duration:** ~8 min (commit-to-commit, excluding investigation/verification time)
- **Started:** 2026-08-09T14:23:00+01:00 (T1 commit)
- **Completed:** 2026-08-09T14:30:35+01:00 (T3 commit)
- **Tasks:** 3
- **Files modified:** 9 (5 modified, 4 new)

## Accomplishments

- **Guard 1 (margin ceiling):** `pricing:undercut-competitors` now refuses to write a competitor-derived price whose resulting margin exceeds `config('competitor.max_margin_ceiling_bps')` (default 5000 bps / 50%). Instead it logs a blocked line and files a deduplicated `competitor_price_ceiling_blocked` Suggestion for human review — in both dry-run and `--live`. This command previously had **zero test coverage**; it now has 10 Pest tests across all 3 tasks.
- **Guard 2a (feed-jump quarantine):** `CompetitorCsvRowWriter` flags a newly-ingested `competitor_prices` row `is_price_anomaly = true` when it moves more than `config('competitor.max_row_move_pct')` (default 50%) vs. its own immediately-prior row for the same `(competitor_id, sku)` pair. The row still persists (COMP-07 "never truncated" mandate + audit trail intact).
- **Guard 2b (pricer exclusion):** `lowestCurrentCompetitorGross()` adds `->where('is_price_anomaly', false)` — a quarantined row can never be selected as the "lowest current competitor" price. The exact incident shape (competitor's 263%-jump row present alongside an older good row) is proven unreachable by an end-to-end regression test.
- Both guards are purely additive and config-driven — safe to deploy while `WOO_WRITE_ENABLED=true` and a bulk price push is in flight, per the incident's production-safety constraint.

## Task Commits

Each task was committed atomically (TDD: test-first, watched RED, then implemented to GREEN):

1. **Task 1: Guard 1 — margin ceiling block + review Suggestion** - `489ec4a` (feat)
2. **Task 2: Guard 2a — quarantine flag on competitor_prices ingest** - `3e5a89c` (feat)
3. **Task 3: Guard 2b — wire the pricer to exclude quarantined rows + incident-reproduction test** - `a07cd5e` (feat)

_Note: this quick task used single-commit-per-task (test + implementation combined in one commit, not split RED/GREEN commits) — each task's Pest test file was written first, run to confirm failure, then the implementation was added and the test re-run to confirm pass, before staging and committing together._

## Files Created/Modified

- `app/Console/Commands/CompetitorUndercutPricingCommand.php` - Guard 1 (ceiling block + `recordCeilingBlockedSuggestion()`) and Guard 2b (`is_price_anomaly` exclusion in `lowestCurrentCompetitorGross()`)
- `app/Domain/Competitor/Services/CompetitorCsvRowWriter.php` - Guard 2a: prior-row lookup + move-% quarantine flag before `CompetitorPrice::create()`
- `app/Domain/Competitor/Models/CompetitorPrice.php` - `is_price_anomaly` (bool cast) + `price_anomaly_reason` fillable/cast
- `config/competitor.php` - `max_margin_ceiling_bps` (default 5000 = 50%), `max_row_move_pct` (default 50)
- `database/factories/Domain/Competitor/CompetitorPriceFactory.php` - default `is_price_anomaly=false`, `price_anomaly_reason=null`
- `database/migrations/2026_08_09_000000_add_price_anomaly_flag_to_competitor_prices_table.php` - additive-only (nullable-safe boolean + string + explicit backfill), verified reversible
- `tests/Feature/Pricing/CompetitorUndercutPricingCommandMarginCeilingTest.php` - 4 tests (this command's first-ever coverage)
- `tests/Feature/Competitor/CompetitorPriceAnomalyQuarantineTest.php` - 4 tests
- `tests/Feature/Pricing/CompetitorUndercutPricingCommandQuarantineExclusionTest.php` - 3 tests

## Decisions Made

- Guard 1 fires identically in dry-run and `--live` — it's a detection/review mechanism, not a price write, so it must surface even when an operator only ever dry-runs the command for inspection.
- Guard 2a leaves the flagged row in place rather than rejecting the CSV row outright — matches this codebase's established "persist everything, gate on consumption" convention (mirrors the existing orphan-row precedent in the same writer), keeping the full audit trail while still closing the pricing-safety gap via Guard 2b.
- `competitor_price_ceiling_blocked` Suggestions have no registered `SuggestionApplier` — they're informational-only for human review, the same shape as `auto_create_failed` / `crm_push_failed` / `quote_push_failed`.

## Deviations from Plan

None — plan executed exactly as written. One clarification during implementation: while writing Task 1's test, discovered that Laravel's `expectsOutputToContain()` test helper only satisfies **one** substring expectation per unique console output line (Mockery's `doWrite` mock consumes one matching expectation per call, not all matching ones) — so the plan's implied multi-field assertion on a single blocked-detail line ("naming the SKU, buy price, proposed price, effective margin, and the driving competitor price") was split: the SKU and the final summary count are asserted via separate output lines, and the individual pennies/margin/competitor-price values are asserted via the Suggestion's `evidence` JSON instead (a stronger, more precise check than string-matching a formatted line). No production code behavior was affected — this is a test-authoring detail only.

## Issues Encountered

None blocking. Two pre-existing, unrelated test failures were observed while running the full `Pricing` and `Competitor` sibling suites (see Verification below) — both confirmed pre-existing via `git log` (last touched in unrelated historical commits, long before this session) and explicitly NOT fixed per this task's scope boundary.

## Verification

- **New tests (this task):** 11 Pest tests across 3 files, all GREEN.
  - `pest --filter=CompetitorUndercutPricingCommandMarginCeilingTest` — 4/4 passed
  - `pest --filter=CompetitorPriceAnomalyQuarantineTest` — 4/4 passed
  - `pest --filter=CompetitorUndercutPricingCommandQuarantineExclusionTest` — 3/3 passed
- **Sibling suite regression check:**
  - `tests/Feature/Pricing` + `tests/Unit/Pricing` (full directories): **318 passed**, 21 failed — all 21 failures are the same pre-existing `Tests\Unit\Pricing\GoldenFixtureV2TradeTest` (a `customer_group_id` foreign-key violation on `pricing_rules` inserts, unrelated to competitor pricing; file last touched in commit `5472e48`, long before this session; not modified by any task in this plan).
  - `tests/Feature/Competitor` + `tests/Unit/Competitor` (full directories): **218 passed**, 1 failed — `Tests\Feature\Competitor\ShieldRestorationProtocolTest` (asserts a stray Filament Shield-generated policy stub at `app/Foundation/Integration/Policies/IntegrationEventPolicy.php` does not exist on disk; that file is dated 2026-05-03, an environment artifact wholly unrelated to this task, not created or touched by any commit in this plan).
  - `CompetitorCsvChunkJobTest.php` (direct sibling of the Guard 2a writer change) — 5/5 passed, confirmed no regression.
- **Static/architecture checks:**
  - `vendor/bin/pint` on all touched files — pass, no style violations.
  - `vendor/bin/deptrac analyse` — 0 violations, 0 errors (Console/Commands is outside deptrac's layer collectors; `Suggestion` import already an established-legal edge via `OrphanDetector` in the same Competitor domain).
  - `php artisan route:list --path=admin` — exits 0.
  - Migration verified against a scratch SQLite DB (not the local dev DB): applies cleanly, `migrate:rollback --step=1` reverses cleanly, re-`migrate` re-applies cleanly.
- **Byte-locked files confirmed untouched:** `PriceCalculator` and `RuleResolver` were not modified (grep-verified — no diff in either file across all 3 commits).

## User Setup Required

None — no external service configuration required. Both new config keys are env-overridable with safe defaults (`COMPETITOR_MAX_MARGIN_CEILING_BPS=5000`, `COMPETITOR_MAX_ROW_MOVE_PCT=50`) and require no `.env` changes to deploy.

## Follow-up: 14 Already-Mispriced Live SKUs (Operator Action Required)

**This task does NOT retroactively correct the 14 SKUs already mispriced by the incident** (including `9C941AA`, currently live at £4652.02 against a £1297.30 baseline). That is a deliberate scope boundary — these guards prevent recurrence, they do not undo damage already live on Woo.

Recommended next step for the operator, **after** this deploy lands:
1. Re-run `pricing:undercut-competitors` in dry-run (no `--live`) across the full catalogue and review the `blocked (margin ceiling)` output plus the new `competitor_price_ceiling_blocked` Suggestions — this surfaces every SKU where a competitor-driven price is currently sitting above the ceiling (should include all or most of the 14).
2. For each flagged SKU, manually verify the correct price (cross-check the supplier cost + intended margin, or wait for a corrected competitor feed) and either fix `sell_price` directly or let a corrected feed row naturally re-price it on the next `--live` run.
3. Since Guard 2a only quarantines *rows ingested from this point forward*, the 14 SKUs' existing bad `competitor_prices` history rows are NOT retroactively flagged — Guard 1's margin ceiling is what protects them going forward (it re-evaluates live, independent of any flag on the historical row).

## Next Phase Readiness

- Both guards are deployed-ready: additive migration, config-driven with safe defaults, zero behavior change to any other pricing path.
- No blockers. Recommend deploying promptly given the live production incident context, then executing the 14-SKU remediation follow-up above.

---
*Phase: quick*
*Completed: 2026-08-09*

## Self-Check: PASSED

All 9 claimed files (5 modified, 4 new) verified present on disk; all 3 task commit hashes (`489ec4a`, `3e5a89c`, `a07cd5e`) verified present in `git log`.
