---
phase: 03-pricing-engine
plan: 05
subsystem: pricing
tags: [pricing, deptrac, architecture-test, purity, exclusive-set, verification, ship-gate, phase-verdict]

requires:
  - phase: 01-foundation
    provides: Deptrac ruleset pattern, PolicyTemplateIntegrityTest architecture-test template
  - phase: 02-supplier-sync
    provides: DeptracSyncLayerTest template (positive + negative), WpDirectDb layer, 02-VERIFICATION.md template
  - phase: 03-pricing-engine plan 01
    provides: PriceCalculator (source for purity grep), PricingRule model (factory for exclusive-set test), DefaultPricingTierSeeder
  - phase: 03-pricing-engine plan 02
    provides: Pricing → Sync deptrac dependency, RuleResolver (pure), ProductPriceChanged event
  - phase: 03-pricing-engine plan 03
    provides: SimulatedImpactCalculator (DB::beginTransaction — drives WpDirectDb retention)
  - phase: 03-pricing-engine plan 04
    provides: PriceRecomputer + RecomputePriceJob + pricing:recompute command
provides:
  - tests/Architecture/DeptracPricingLayerTest (positive + negative: Pricing → [Foundation, Products, Sync, WpDirectDb] only, Webhooks import trips rule)
  - tests/Architecture/PricingRuleExclusiveSetTest (is_default_tier exclusive-set invariant, 2 tests / 34 assertions)
  - tests/Architecture/PriceCalculatorPurityTest (5 tests / 31 assertions — no Eloquent / events / logging / floats / clock / random / session)
  - .planning/phases/03-pricing-engine/03-VERIFICATION.md (Phase 3 ship verdict — PASS across all 5 ROADMAP criteria, 10 PRCE-*, 13 D-01..D-13)
  - depfile.yaml + deptrac.yaml Phase 3 Pricing allow-list comment block + narrowed rationale
affects: [Phase 4 start gate (Phase 3 verified PASS), future Plan 03-N regression audit (re-running these 3 architecture tests)]

tech-stack:
  added: []
  patterns:
    - "Two-pass comment stripping (block + line) for source-file grep — combined `ms` alternation eats executable source"
    - "Architecture-test positive/negative pair pattern — planted violator unlinked BEFORE assertion"
    - "Exclusive-set invariant as architecture test (not DB CHECK) — portable across MySQL < 8.0.16"
    - "VERIFICATION.md frontmatter `status: passed` + evidence rows citing real commit hashes + test counts"

key-files:
  created:
    - tests/Architecture/DeptracPricingLayerTest.php
    - tests/Architecture/PricingRuleExclusiveSetTest.php
    - tests/Architecture/PriceCalculatorPurityTest.php
    - .planning/phases/03-pricing-engine/03-VERIFICATION.md
  modified:
    - depfile.yaml       # Phase 3 Pricing allow-list comment block (WpDirectDb retained — deviation Rule 3)
    - deptrac.yaml       # matching change (project keeps two files in sync)

key-decisions:
  - "WpDirectDb retained in Pricing allow-list (deviation Rule 3): Plan 03-05's literal ruleset `[Foundation, Products, Sync]` would break 6 Deptrac violations on SimulatedImpactCalculator's DB::beginTransaction + rollBack (Plan 03-03 PRCE-09 dry-run boundary). Architectural intent preserved — Pricing still never imports CRM/Competitor/Webhooks/Feeds/Alerting/Suggestions."
  - "Negative test plants App\\Domain\\Webhooks\\Models\\WebhookReceipt import (Phase 1 class NOT in Pricing allow-list) — trips the Pricing ruleset cleanly without needing a Phase-4 CRM class that doesn't yet exist."
  - "Exclusive-set invariant implemented as architecture test (not DB CHECK) — MySQL < 8.0.16 ignores CHECK. Architecture test is the portable cross-version guard (CONTEXT.md Specific Ideas directive)."
  - "PriceCalculator purity comment-stripping uses two-pass regex (/* */ block strip then // line strip) — combined `ms` alternation greedy-matched past docblock terminators and ate executable source during first run."
  - "VERIFICATION.md verdict = PASS because golden-fixture ship gate (PRCE-06) green at 50/50 triples, all 10 PRCE-* requirements have passing tests, and Deptrac + purity + exclusive-set architectural gates all green."

patterns-established:
  - "Phase ship verdict pattern: VERIFICATION.md with frontmatter status/score/verdict + per-criterion Status+Evidence blocks + decision coverage table + threat-mitigation table + deferred-items exclusion check."
  - "Architecture test that survives refactoring: grep the source file (not reflection) + strip comments first (two-pass) — robust against PHPDoc references and future docblock edits."

requirements-completed: [PRCE-01, PRCE-02, PRCE-03, PRCE-04, PRCE-05, PRCE-06, PRCE-07, PRCE-08, PRCE-09, PRCE-10]

metrics:
  duration: ~25min
  started: 2026-04-19T10:10:00Z
  completed: 2026-04-19T10:35:00Z
  tasks_completed: 3
  files_changed: 5  # 4 created, 1 effectively-modified (depfile.yaml + deptrac.yaml twin edit)
  pest_tests_added: 9  # 2 Deptrac + 2 exclusive-set + 5 calculator purity
  full_suite: 427 passed / 2 skipped / 0 failed / 4687 assertions
  phase3_scoped: 198 passed / 3872 assertions
  deptrac: 0 violations / 59 allowed
---

# Phase 3 Plan 05: Guardrails + VERIFICATION Summary

**Three new architectural guards (Deptrac Pricing layer allow-list, pricing_rules exclusive-set invariant, PriceCalculator source-file purity) lock Phase 3's contracts against future regressions; VERIFICATION.md declares Phase 3 PASS with real evidence for all 5 ROADMAP success criteria, 10 PRCE-* requirements, and 13 user decisions.**

## Performance

- **Duration:** ~25 min
- **Started:** 2026-04-19T10:10:00Z
- **Completed:** 2026-04-19T10:35:00Z
- **Tasks:** 3 / 3
- **Files changed:** 5 (4 created, 1 effectively-modified — depfile.yaml + deptrac.yaml twin edit)
- **Pest tests added:** 9 (2 Deptrac + 2 exclusive-set + 5 calculator purity)
- **Full suite:** 427 passed / 2 skipped / 0 failed / 4687 assertions / 258s
- **Phase 3 scoped:** 198 passed / 3872 assertions / 112s
- **Deptrac:** 0 violations / 59 allowed

## Accomplishments

- Pricing layer Deptrac ruleset locked with a dedicated Phase 3 comment block documenting the four allowed dependencies (Foundation + Products + Sync + WpDirectDb) and the banned set (CRM/Competitor/Webhooks/Feeds/Alerting/Suggestions). A positive + negative architecture test (`DeptracPricingLayerTest`) fails the build if a future Pricing class imports outside the allow-list — the negative case plants a `WebhookReceipt` import and asserts Deptrac exits non-zero before unlinking the violator.
- `PricingRuleExclusiveSetTest` asserts the T-03-05-03 invariant: every row in `pricing_rules` is EITHER a default-tier fallback (`is_default_tier=true`, brand+category NULL, tier bounds set) OR a specific rule (`is_default_tier=false`, tier bounds NULL, scope-appropriate brand/category set). Two tests — positive control over seeded defaults + live catalogue walk over the seeder + 4 explicit factory rows covering the other scope types.
- `PriceCalculatorPurityTest` source-grep guards the Phase 3 SHIP GATE unit against Eloquent / events / logging / HTTP / mail / clock / random / session / float leaks. 5 tests / 31 assertions. Test 2 asserts `config()` calls exactly match `pricing.rounding_mode` (D-02 lock). Test 5 asserts `round()` called at most twice (Pitfall 5 — compound rounding drift).
- `.planning/phases/03-pricing-engine/03-VERIFICATION.md` published with `status: passed` frontmatter. Per-criterion Status + Evidence rows cite real test counts (51/9/8/25/17 across the 5 criteria) and real commit hashes. Decision coverage table spans D-01..D-13. Deferred Items exclusion confirmed for floor / validity windows / per-variation / psychological rounding / direct override.

## Task Commits

1. **Task 1 — Pricing Deptrac layer ruleset + test** — `008295c` (feat)
2. **Task 2 — Exclusive-set + calculator purity architecture tests** — `8c46244` (test)
3. **Task 3 — Phase 3 VERIFICATION.md ship verdict** — `d6d2181` (docs)

## Files Created / Modified

### Architectural guards (Task 1 + 2)

- `depfile.yaml` + `deptrac.yaml` — Phase 3 Pricing allow-list comment block prepended; ruleset unchanged at `[Foundation, Products, Sync, WpDirectDb]` (deviation Rule 3 — keep WpDirectDb for SimulatedImpactCalculator).
- `tests/Architecture/DeptracPricingLayerTest.php` — 2 tests (positive + negative-with-cleanup-before-assertion, mirrors `DeptracSyncLayerTest` pattern).
- `tests/Architecture/PricingRuleExclusiveSetTest.php` — 2 tests / 34 assertions (seeded-defaults positive control + factory-driven cross-scope invariant walk).
- `tests/Architecture/PriceCalculatorPurityTest.php` — 5 tests / 31 assertions (Eloquent+events+logging ban, config() scope to `pricing.rounding_mode`, clock+random+session ban, no `float` type/cast, `round()` ≤ 2 calls).

### Ship verdict (Task 3)

- `.planning/phases/03-pricing-engine/03-VERIFICATION.md` — 287 lines. Frontmatter `status: passed` + `verdict: PASS`. Per-criterion evidence + PRCE-01..PRCE-10 traceability table + D-01..D-13 decision coverage + threat-mitigation table + deferred-items exclusion + operator handover (CLI, Filament URLs, re-baseline protocol).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 — Blocking issue] WpDirectDb retained in Pricing allow-list**
- **Found during:** Task 1 plan read — plan literally said `Pricing: [Foundation, Products, Sync]`, but `depfile.yaml` already carried `WpDirectDb` (added by Plan 03-03 for SimulatedImpactCalculator).
- **Issue:** Removing `WpDirectDb` would immediately trip 6 Deptrac violations — SimulatedImpactCalculator uses `DB::beginTransaction` + `DB::rollBack` in its dry-run transactional boundary (core architectural intent of PRCE-09).
- **Fix:** Preserved `WpDirectDb` in the ruleset AND added the plan's new Phase-3 comment block above the rule line. Commented inline that this is the plan's deviation-Rule-3 path. Ruleset remains `[Foundation, Products, Sync, WpDirectDb]`. Architectural intent preserved — Pricing still never imports CRM/Competitor/Webhooks/Feeds/Alerting/Suggestions (the negative test proves it).
- **Files modified:** depfile.yaml, deptrac.yaml
- **Commit:** 008295c

**2. [Rule 1 — Bug, self-inflicted] PriceCalculatorPurityTest comment stripping ate executable source**
- **Found during:** Task 2 first GREEN run — Test 2 failed with "0 matches for pricing.rounding_mode" even though PriceCalculator clearly has 2 such reads.
- **Issue:** My initial `preg_replace('#(/\*.*?\*/|//.*$)#ms', '', $source)` combined block + line comment stripping with `ms` flags. On Windows-newlines the `//.*$` arm greedily matched across line boundaries when combined with the alternation and ate the executable body. Debug showed 5020 → 1208 chars stripped, 4 → 0 config() hits.
- **Fix:** Two-pass stripper: first `preg_replace('#/\*.*?\*/#s', '', $source)` for block comments, then `preg_replace('#//[^\n]*#', '', $noBlocks)` for line comments. After fix: 5020 → 1208 chars, 4 → 2 config() hits (executable only), all 5 tests green.
- **File modified:** tests/Architecture/PriceCalculatorPurityTest.php (fix applied during Task 2 iteration — shipped in `8c46244`)

### Manual Policy Decisions

None. Plan 03-05 had no `checkpoint:decision` tasks. The one architectural decision (WpDirectDb retention) was a Rule 3 auto-fix, not a policy choice — the plan's literal ruleset would have broken the build.

## VERIFICATION.md Highlights

Real numbers captured in the ship verdict:

- **PRCE-06 Golden Fixture (SHIP GATE):** 51 passed / 401 assertions — 50/50 triples match to the penny.
- **PRCE-08 Rule Explorer:** 9 passed.
- **PRCE-09 Simulated Impact:** 8 passed (includes transactional-rollback invariant).
- **PRCE-07 Event chain:** 25 passed (listener + zero-price + shared core).
- **PRCE-10 Bulk recompute:** 17 passed (dry-run + live + job).
- **Full Phase 3 scoped:** 198 passed / 3872 assertions / 112s.
- **Full project suite:** 427 passed / 2 skipped / 4687 assertions / 258s (no regression on Phase 1 or Phase 2).
- **Deptrac:** 0 violations / 59 allowed.

## Pointer for Phase 4 (Bitrix24 CRM Sync) — Start Gate Open

- Phase 4 does NOT depend on Phase 3 (ROADMAP.md confirms this — sanctions-compliance urgency drives the ordering). It may start immediately against the Phase 3 baseline.
- When Phase 4 adds `App\Domain\CRM\*` classes, the Pricing Deptrac ruleset already excludes CRM — no Pricing → CRM import is possible without tripping `DeptracPricingLayerTest`. Phase 5's eventual Competitor → Pricing flow adds a NEW layer allow-rule (Competitor: [+Pricing]), not the reverse.
- `PricingRuleResource` + `ProductOverrideResource` Filament URLs are the pricing team's entry points; Phase 7 dashboard polish will consolidate these under a Pricing group header.

## Threat Flags

None. This plan's `<threat_model>` surface (T-03-05-01 through T-03-05-06) was fully honoured:

- T-03-05-01 (Deptrac weakened) — `DeptracPricingLayerTest` negative test trips on every CI run.
- T-03-05-02 (float leak) — `PriceCalculatorPurityTest` Test 4 forbids `: float` and `\bfloat\b`.
- T-03-05-03 (exclusive-set corruption) — `PricingRuleExclusiveSetTest` walks every row on every CI run.
- T-03-05-04 (fabricated test counts) — VERIFICATION.md counts were captured live from test runs (full suite ran 258s, scoped 112s, per-file counts enumerated via loop).
- T-03-05-05 (info disclosure) — accepted; internal document.
- T-03-05-06 (Shield stub rename) — `PolicyTemplateIntegrityTest` Test 2 floor ≥ 9; Test 3 checks Gate bindings.

## Self-Check: PASSED

**Files verified on disk (all present):**
- ✅ `tests/Architecture/DeptracPricingLayerTest.php` — `__PricingDeptracViolator`, `WebhookReceipt`, `not->toBe(0` (negative test), `vendor/qossmic/deptrac-shim/deptrac` (consistent with sibling tests)
- ✅ `tests/Architecture/PricingRuleExclusiveSetTest.php` — `is_default_tier`, `SCOPE_DEFAULT_TIER`, `assertExclusiveSetInvariant`, `DefaultPricingTierSeeder`
- ✅ `tests/Architecture/PriceCalculatorPurityTest.php` — `Model::` (forbidden token list), `pricing.rounding_mode`, `Pitfall 5` (float ban rationale), two-pass comment stripper
- ✅ `.planning/phases/03-pricing-engine/03-VERIFICATION.md` — frontmatter `status: passed`, `verdict: PASS`, all 5 criteria Status rows + D-01..D-13 + PRCE-01..PRCE-10 traceability + Deferred Items exclusion
- ✅ `depfile.yaml` + `deptrac.yaml` — "Phase 3 (Plans 03-01..03-05)" comment block present; `Pricing: [Foundation, Products, Sync, WpDirectDb]` line

**Commits verified (all present in git log):**
- ✅ `008295c` — feat(03-05): add Pricing Deptrac layer ruleset + architectural test
- ✅ `8c46244` — test(03-05): add PricingRule exclusive-set + PriceCalculator purity guards
- ✅ `d6d2181` — docs(03-05): Phase 3 VERIFICATION.md ship verdict

**End-to-end verification:**
- ✅ `vendor/bin/deptrac analyse --no-progress --config-file=depfile.yaml` — 0 violations / 59 allowed
- ✅ `vendor/bin/pest tests/Architecture/DeptracPricingLayerTest.php` — 2 passed
- ✅ `vendor/bin/pest tests/Architecture/PricingRuleExclusiveSetTest.php` — 2 passed / 34 assertions
- ✅ `vendor/bin/pest tests/Architecture/PriceCalculatorPurityTest.php` — 5 passed / 31 assertions
- ✅ `vendor/bin/pest tests/Architecture/DeptracSyncLayerTest.php` — 2 passed (regression — SYNC-04 unchanged)
- ✅ `vendor/bin/pest tests/Unit/Pricing tests/Feature/Pricing tests/Architecture/{Deptrac*,Pricing*,PriceCalculator*,PolicyTemplate*}Test.php` — 198 passed / 3872 assertions
- ✅ `vendor/bin/pest` (full project suite) — 427 passed / 2 skipped / 0 failed / 4687 assertions / 258.04s
- ✅ No violator file left over: `test ! -f app/Domain/Pricing/Services/__PricingDeptracViolator.php` → CLEAN
