---
phase: 07-dashboard-polish-cutover
plan: 06-handover-deptrac-verification
subsystem: dashboard,cutover,architecture,documentation,verification
tags: [deptrac-layer, cutover-layer, dashboard-layer, dual-config-sync, architecture-test, handover-docs, cut-06, verification, v1-milestone, pitfall-p5-05-05, pitfall-p6-06]

requires:
  - phase: 07-01
    provides: "PolicyTemplateIntegrityTest floor at 26 + 2 hand-written Dashboard policies (DashboardSnapshot + UserSavedFilter)"
  - phase: 07-02
    provides: "Dashboard Deptrac layer registered in depfile.yaml + deptrac.yaml (ship-with-the-dependency from Plan 07-01 SUMMARY §Known concerns item 3)"
  - phase: 07-03
    provides: "HasExportableTable trait + CsvExportWriter + QueuedCsvExportJob (Plan 07-06 Task 1 Rule 1 relocates these out of the Dashboard layer)"
  - phase: 07-04
    provides: "NotificationCentrePage + NotificationCentreAggregator + reports:weekly-digest (referenced from handover doc Section 4)"
  - phase: 07-05
    provides: "6 cutover artisan commands + 7 Cutover domain services (Plan 07-05 deferred Deptrac layer registration; Plan 07-06 owns it)"
  - phase: 05-05
    provides: "Dual-config-sync lesson — depfile.yaml + deptrac.yaml must stay identical (Phase 5 Plan 05-05 one-file-drift pitfall)"
  - phase: 06-06
    provides: "DeptracProductAutoCreateLayerTest template — 4 it-block shape (positive + 2 negative violators + dual-file grep) with exit-code-only assertions (Windows Symfony\\Process reliability lesson)"

provides:
  - "Cutover Deptrac layer registered in BOTH depfile.yaml + deptrac.yaml with allow-list [Foundation, Products, Pricing, Sync, Suggestions, Alerting, Webhooks, Competitor, CRM, ProductAutoCreate, Dashboard, WpDirectDb]"
  - "Http allow-list extended with Cutover (controllers may route to cutover pages/actions)"
  - "tests/Architecture/DeptracDashboardLayerTest.php — 4 it-blocks: positive clean + Sync→Dashboard one-way-arrow negative + Dashboard→Feeds negative + dual-file grep"
  - "tests/Architecture/DeptracCutoverLayerTest.php — 4 it-blocks: positive clean + CRM→Cutover one-way-arrow negative + Cutover→Feeds negative + dual-file grep"
  - "CsvExportWriter + QueuedCsvExportJob relocated from app/Domain/Dashboard/ to app/Filament/Exports/ — Rule 1 fix of 18 pre-existing Deptrac violations"
  - "docs/ops/cutover-handover.md — 372-line operator runbook (4 CUT-06 sections + 5 appendices: D-19 sequence, env var inventory, rollback runbook, troubleshooting FAQ, Horizon primer)"
  - "07-VERIFICATION.md — 338-line Phase 7 ship verdict + v1 milestone declaration (FLAG verdict, all 13 REQ + 5 criteria + 21 decisions honored)"
  - "PolicyTemplateIntegrityTest verified holding at floor 26 with 0 {{ Placeholder }} leaks (no Shield regen needed)"

affects:
  - "Next phase (Phase 8: Feeds v2) — Dashboard + Cutover allow-lists currently EXCLUDE Feeds; Phase 8 extends when first FEED-* requirement ships"
  - "Operator cutover execution — docs/ops/cutover-handover.md is THE authoritative runbook; cutover:checklist is the single go/no-go signal"
  - "v1 milestone — post this plan, ROADMAP Phase 7 [x] + milestone v1.50.1 Complete"

tech-stack:
  added:
    - "app/Filament/Exports/ — new namespace for cross-domain CSV export infrastructure (outside every Deptrac layer; app/Filament/ is uncovered-bucket)"
  patterns:
    - "Dual-config-sync discipline (Phase 5 Plan 05-05 carried forward): depfile.yaml + deptrac.yaml edited identically; architecture test asserts both via grep"
    - "Exit-code-only Deptrac assertions (Phase 6 Plan 06-06 carried forward): Symfony\\Process on Windows PHP cannot reliably capture deptrac-shim stdout; we assert getExitCode() only"
    - "AST-traversal awareness (Phase 5 Plan 05-05 lesson): Deptrac only detects class references via type annotations / new / instanceof — a bare ::class constant is invisible. Negative-violator tests use parameter type-hints"
    - "Relocate-not-allow-list when a class should be cross-cutting infrastructure: moving CsvExportWriter to app/Filament/Exports/ is cleaner than adding Dashboard to 6 domains' allow-lists (which would break the one-way-arrow contract)"

key-files:
  created:
    - "app/Filament/Exports/CsvExportWriter.php (moved from app/Domain/Dashboard/Services/)"
    - "app/Filament/Exports/QueuedCsvExportJob.php (moved from app/Domain/Dashboard/Jobs/)"
    - "tests/Architecture/DeptracDashboardLayerTest.php"
    - "tests/Architecture/DeptracCutoverLayerTest.php"
    - "docs/ops/cutover-handover.md"
    - ".planning/phases/07-dashboard-polish-cutover/07-VERIFICATION.md"
  modified:
    - "depfile.yaml (+ Cutover layer entry + allow-list + Http extension)"
    - "deptrac.yaml (mirror of depfile.yaml Phase 5 dual-config-sync lesson)"
    - "app/Filament/Concerns/HasExportableTable.php (updated CsvExportWriter import)"
    - "app/Filament/Actions/QueueCsvExportAction.php (updated QueuedCsvExportJob import)"
    - "tests/Feature/Dashboard/CsvExportTest.php (updated import)"
    - "tests/Feature/Dashboard/QueuedCsvExportJobTest.php (updated import)"
  deleted:
    - "app/Domain/Dashboard/Services/CsvExportWriter.php"
    - "app/Domain/Dashboard/Jobs/QueuedCsvExportJob.php"
    - "app/Domain/Dashboard/Jobs/ (empty directory)"

decisions:
  - "Relocate CsvExportWriter + QueuedCsvExportJob to app/Filament/Exports/ (Rule 1 — Bug). Rationale: 18 pre-existing Deptrac violations reported Resource → Dashboard imports via HasExportableTable trait. Adding Dashboard to 6 domains' allow-lists would break the one-way-arrow contract. Moving the 2 files to a non-layer namespace (app/Filament/) preserves the architecture AND unblocks 0-violation Deptrac. Least-invasive architectural fix: 2 file moves + 4 import updates."
  - "Cutover allow-list includes WpDirectDb narrowly (Rule 2 — Missing critical). OverridePopulator uses DB::transaction() for atomic product_overrides upserts — that's a LOCAL meetingstore_ops DB transaction, not a WORDPRESS DB write. Same pattern as Pricing's SimulatedImpactCalculator. LegacyPluginDisabler has separate Plan 07-05 DL4 static-scan test asserting zero DB:: facade refs — the SYNC-04 WpDirectDb ban on WORDPRESS writes is preserved."
  - "No shield:generate in Plan 07-06 (Task 2 verified no-op). All new Phase 7 policies are hand-written; running shield:generate --all would reintroduce the Phase 5/6 P5-F restoration cycle for zero gain. PolicyTemplateIntegrityTest runs green at floor 26."
  - "Handover doc is single markdown file at docs/ops/cutover-handover.md (not split across multiple files). 372 lines organised as 4 top-level CUT-06 sections + 5 appendices. Version-committed; updateable via PR."
  - "07-VERIFICATION.md verdict is FLAG (same as Phase 6), not PASS. Reason: Feature-tier MySQL Pest suite (~120 cases) is infrastructure-deferred (PDO::connect refusal at 127.0.0.1:3306). Architectural tests + lint + static scans all green; operator executes the suite before cutover via cutover:checklist feature-suite gate."

metrics:
  completed_at: "2026-04-24T11:30Z"
  duration_minutes: 25
  tasks_completed: 4
  files_created: 6
  files_modified: 6
  files_deleted: 2
  commits: 3
  arch_test_files: 2
  arch_it_blocks: 8
  deptrac_violations: 0
  deptrac_configs: 2
  handover_doc_lines: 372
  verification_doc_lines: 338
  policy_floor: 26
  v1_requirements: "85 / 85 shipped"
  v1_plans: "38 plans across 7 phases"

requirements:
  - CUT-06 (ops handover docs — 4 mandated sections + 5 appendices)
---

# Phase 07 Plan 06: Handover + Deptrac + Verification — Summary

The **FINAL plan of Phase 7 and of the v1 milestone**. Three artefacts shipped:

1. **Deptrac Cutover layer** registered in both `depfile.yaml` + `deptrac.yaml` with allow-list `[Foundation, Products, Pricing, Sync, Suggestions, Alerting, Webhooks, Competitor, CRM, ProductAutoCreate, Dashboard, WpDirectDb]`. Two architectural tests (`DeptracDashboardLayerTest` + `DeptracCutoverLayerTest`) each with 4 it-blocks (positive clean + one-way-arrow negative + allow-list-exclusion negative + dual-file grep) enforce the boundaries permanently in CI.
2. **Operator cutover handover runbook** at `docs/ops/cutover-handover.md` — 372 lines covering the 4 CUT-06 mandated scenarios (resume-a-sync / replay-CRM-push / refresh-Bitrix-schema / interpret-notification-centre) plus 5 appendices (D-19 cutover sequence, environment variable inventory, rollback runbook, troubleshooting FAQ, Horizon primer).
3. **Phase 7 ship verdict + v1 milestone declaration** at `.planning/phases/07-dashboard-polish-cutover/07-VERIFICATION.md` — 338 lines with FLAG verdict (same Feature-tier-MySQL-deferred pattern as Phase 6), all 13 DASH/CUT requirements evidenced, all 5 ROADMAP success criteria verified, all 21 locked decisions honored.

## Accomplishments

### Task 1 — Deptrac Cutover layer + dual-file-sync architecture tests (commit `b1cd812`)

**Deptrac layer registration:**

Both `depfile.yaml` and `deptrac.yaml` now contain identical Cutover layer definitions:

```yaml
- name: Cutover
  collectors:
    - type: directory
      regex: app/Domain/Cutover/.*

# And under ruleset:
Cutover: [Foundation, Products, Pricing, Sync, Suggestions, Alerting, Webhooks, Competitor, CRM, ProductAutoCreate, Dashboard, WpDirectDb]

# Http extended to include Cutover:
Http: [Foundation, Products, Pricing, Competitor, Sync, Webhooks, CRM, Suggestions, Alerting, Feeds, ProductAutoCreate, Dashboard, Cutover]
```

Comment blocks on each entry document (a) why each member is in the allow-list, (b) why `Feeds` is explicitly excluded, (c) why `WpDirectDb` is narrowly permitted for `OverridePopulator::DB::transaction()` (local DB only, not WP), (d) the dual-config-sync contract (Phase 5 Plan 05-05 lesson).

**Two architectural tests (8 it-blocks total):**

`tests/Architecture/DeptracDashboardLayerTest.php`:
- `positive_clean_codebase` — `deptrac analyse` returns exit 0
- `catches_Sync_import_from_Dashboard` (one-way-arrow negative) — plant a violator at `app/Domain/Sync/__DeptracViolatorDashboardRef.php` type-hinting `DashboardSnapshot $s` → deptrac exit != 0
- `catches_Dashboard_import_from_Feeds` (Feeds-not-in-allow-list negative) — plant a violator in `app/Domain/Dashboard/` type-hinting `FeedGenerator $f` → deptrac exit != 0
- `both_config_files_declare_Dashboard_allow_list` — regex-assert both `depfile.yaml` + `deptrac.yaml` contain `Dashboard: [...]` with all 10 mandated members

`tests/Architecture/DeptracCutoverLayerTest.php` mirrors the same 4-it-block shape for Cutover (CRM→Cutover as the negative violator; DivergenceScanner as the canonical Cutover-layer class import).

All 8 it-blocks green (24 architecture tests total when combined with prior Deptrac tests — zero regression on Phase 1-6 layer tests).

**Rule 1 — Bug: CsvExportWriter + QueuedCsvExportJob relocation (P7-01 violation fix)**

Running `deptrac analyse` at the start of Plan 07-06 Task 1 revealed **18 pre-existing violations** from Plan 07-03:

```
App\Domain\Competitor\Filament\Resources\CompetitorPriceResource must not depend on App\Domain\Dashboard\Services\CsvExportWriter
App\Domain\CRM\Filament\Resources\CrmPushLogResource        must not depend on App\Domain\Dashboard\Services\CsvExportWriter
App\Domain\Pricing\Filament\Resources\PricingRuleResource   must not depend on App\Domain\Dashboard\Services\CsvExportWriter
App\Domain\Products\Filament\Resources\ProductResource      must not depend on App\Domain\Dashboard\Services\CsvExportWriter
App\Domain\ProductAutoCreate\Filament\Resources\AutoCreateReviewResource must not depend on App\Domain\Dashboard\Services\CsvExportWriter
App\Domain\Suggestions\Filament\Resources\SuggestionResource must not depend on App\Domain\Dashboard\Services\CsvExportWriter
(3 per Resource × 6 Resources = 18)
```

Plan 07-05 documented these as "out of scope for this plan, Plan 07-06 owns the Deptrac layer." Plan 07-06's positive-exit-code assertion on `DeptracDashboardLayerTest` REQUIRES 0 violations. Three fix options:

1. **Move the classes out of the Dashboard layer.** Cleanest architecturally; preserves one-way-arrow contract.
2. **Add Dashboard to 6 domains' allow-lists.** Breaks one-way-arrow; violates D-01 intent.
3. **Restructure via interfaces / DI.** Heavy; required refactoring the trait.

**Chose option 1.** Moved both files from `app/Domain/Dashboard/Services|Jobs/` to `app/Filament/Exports/`. `app/Filament/` is NOT a Deptrac layer (uncovered-bucket, same as `app/Http/`, `app/Models/`), so cross-domain imports don't trip layer boundaries. The classes are semantically Filament-UI infrastructure anyway (CSV export is a Filament affordance, not a Dashboard-domain concept). Updated 4 import sites: `HasExportableTable` trait, `QueueCsvExportAction`, `CsvExportTest`, `QueuedCsvExportJobTest`. Post-move deptrac run: **0 violations** on both `depfile.yaml` + `deptrac.yaml`.

**Rule 2 — Missing critical: Cutover WpDirectDb allow-list extension**

After adding the Cutover layer to the rulesets, deptrac reported 2 new violations:

```
App\Domain\Cutover\Services\OverridePopulator must not depend on Illuminate\Support\Facades\DB (WpDirectDb)
```

`OverridePopulator::populateFromScan()` uses `DB::transaction()` to wrap local `product_overrides` upserts for atomicity. That's a LOCAL meetingstore_ops DB transaction, NOT a WordPress DB write — the SYNC-04 WpDirectDb ban is about preventing direct writes to WP's DB, not banning Laravel's local DB facade. Same pattern as Phase 3 Pricing's `SimulatedImpactCalculator` which retains `WpDirectDb` in its allow-list for the same reason. `LegacyPluginDisabler` is separately static-scan-tested (Plan 07-05 DL4) to contain ZERO `DB::` facade references, so the WP-write side of SYNC-04 remains locked. Added `WpDirectDb` to Cutover's allow-list with a comment documenting the narrow scope.

### Task 2 — PolicyTemplateIntegrityTest verified holding at 26 (no shield:generate)

Plan 07-01 SUMMARY documented that Dashboard policies (DashboardSnapshotPolicy + UserSavedFilterPolicy) are hand-written per Pitfall P5-F protocol. Plan 07-06 Task 2 confirmed the floor is stable:

```
vendor/bin/pest tests/Architecture/PolicyTemplateIntegrityTest.php
# PASS — 3 tests (27 assertions)
grep -r "{{ Placeholder }}" app/Policies/ app/Domain/*/Policies/ | wc -l
# 0
```

No `shield:generate --all` invocation. Running it would reintroduce the Phase 5 + Phase 6 P5-F restoration cycle for zero architectural gain — the policies are correct as-shipped and CI gates already enforce the "no placeholder" property.

### Task 3 — docs/ops/cutover-handover.md (commit `a6fa502`)

372-line operator runbook version-committed to the repo. Structure:

**Quick Reference** — admin URL / Horizon URL / Notification Centre URL / checklist invocation.

**Section 1 — How to resume a sync (CUT-06.1):** signal (dashboard widget red + Failed jobs tab entries); Action (find run_id → `sync:supplier --resume={run_id}` → SYNC-03 ledger semantics); troubleshooting if resume fails.

**Section 2 — How to replay a failed CRM push (CUT-06.2):** signal (dashboard widget < 95% + Pending suggestions kind=crm_push_failed + weekly digest DLQ count); Action (Suggestions inbox → filter kind → Replay → `CrmPushRetryApplier` dispatches fresh `PushOrderToBitrixJob` on crm-bitrix queue); troubleshooting by error type.

**Section 3 — How to refresh Bitrix schema (CUT-06.3):** signal (CrmFieldMappingResource missing a known field + Webhook DLQ entries with "unknown field"); Action (`bitrix:schema:refresh` or Filament UI button); cache backing + verification query.

**Section 4 — How to interpret the Notification Centre (CUT-06.4):** 4 tabs × source table + quick action + rule-of-thumb. Failed jobs / Stale feeds / Pending suggestions / Webhook DLQ — each with explicit source query reference, click-action dispatch path, and "when to retry vs investigate" guidance.

**Appendix A — D-19 Cutover Sequence:** the 7-step authoritative table (snapshot → scan → populate → drill → disable → flag → monitor) with commands + expected outcomes + gates. Phase 6 D-20 carry-forward items (supplier-probe / woo-sandbox / feature-suite) listed as mandatory pre-cutover prerequisites.

**Appendix B — Environment Variable Inventory:** feature flags (WOO_WRITE_ENABLED / CRM_WRITE_ENABLED / CUTOVER_DRILL_ALLOWED / CUTOVER_DISABLE_LIVE_ALLOWED / CUTOVER_DIVERGENCE_SCAN_SCHEDULE_ENABLED), Plan 07-01 dashboard tunables, Plan 07-01 cutover tunables, external service credentials (WOO_* + SUPPLIER_* + BITRIX_* + WOO_DB_* + MAIL_*). NAMES only — no values. Explicit security note.

**Appendix C — Rollback Runbook:** 6-step rollback procedure (reverse of D-19 steps 5-6) + staging drill requirement + audit_log record command.

**Appendix D — Troubleshooting FAQ:** 10 common operator questions (Horizon red / parity=null / zero overrides / weekly digest missing / Bitrix unknown field / shadow-mode inspection / premature flag-flip / manual edit overwrite risk / divergence scan 500 / feature-suite gate reset).

**Appendix E — Horizon Primer:** 7-supervisor queue workload map + operational rules of thumb + "if Horizon offline" recovery.

Doc passes acceptance criteria: `wc -l` = 372 (≥300 ✓), 4 Section headings present, 3 D-19 references, 12 env-gate references.

### Task 4 — 07-VERIFICATION.md + v1 milestone declaration (commit `e8f2ef3`)

338-line Phase 7 ship verdict doubling as de-facto v1 milestone summary.

**Verdict:** FLAG (same pattern as Phase 6). All 13 DASH/CUT requirements shipped + tested + documented. All 5 ROADMAP success criteria PASS. All 21 locked decisions D-01..D-21 honored. FLAG reason: Feature-tier Pest suite (~120 MySQL-RefreshDatabase cases across Plans 07-01..07-05) is execution-deferred — `PDO::connect` to `meetingstore_ops_testing` at `127.0.0.1:3306` fails with SQLSTATE[HY000] [2002] in the dev environment. Operator runs the suite pre-cutover via `cutover:checklist`'s `feature-suite` gate.

Sections: Executive Summary + v1 Milestone Declaration + Requirement Coverage Table (13 rows) + ROADMAP Success Criteria Verification (5/5) + Locked Decisions Honored (21 rows) + Cutover Commands Shipped (6 rows) + Deptrac Allow-Lists + Test Suite Metrics + Known Limitations (8 items) + Deferred Items Carried Forward (16 + 3 re-probe) + Handoffs for Phase 8+ + Deviations from Plan + v1 Milestone Full Phase Recap (table spanning all 7 phases, 85/85 requirements) + Operator Cutover Pointer (8-step execution path) + Sign-off checklist.

## Task Commits

1. **Task 1 — Deptrac Cutover layer + 2 architecture tests + CsvExportWriter relocation** — `b1cd812`
   - depfile.yaml + deptrac.yaml: + Cutover layer entries + allow-list rows + Http extension
   - CsvExportWriter + QueuedCsvExportJob moved to app/Filament/Exports/
   - 4 import-site updates (trait, action, 2 tests)
   - DeptracDashboardLayerTest.php (4 it-blocks)
   - DeptracCutoverLayerTest.php (4 it-blocks)
   - Zero deptrac violations on both configs

2. **Task 3 — docs/ops/cutover-handover.md** — `a6fa502`
   - 372 lines: 4 CUT-06 sections + 5 appendices (D-19 sequence, env inventory, rollback runbook, FAQ, Horizon primer)

3. **Task 4 — 07-VERIFICATION.md + v1 milestone declaration** — `e8f2ef3`
   - 338 lines: verdict FLAG, 13 REQ coverage, 5 criteria PASS, 21 decisions honored, full-phase recap, operator pointer

(Task 2 was a no-op — PolicyTemplateIntegrityTest already green at floor 26 with zero `{{ Placeholder }}` leaks; no Shield regen needed.)

## Deviations from Plan

### [Rule 1 — Bug] CsvExportWriter + QueuedCsvExportJob relocated out of Dashboard layer

- **Found during:** Task 1 initial `deptrac analyse` run — 18 pre-existing violations reported.
- **Issue:** Plan 07-03 placed these 2 classes inside `app/Domain/Dashboard/`. The `HasExportableTable` trait imports `CsvExportWriter`; 6 domain Resources `use` the trait → Deptrac trace-imports `CsvExportWriter` from each Resource → 18 cross-layer violations (3 per Resource × 6 Resources — each `use` statement is counted triply).
- **Fix:** Moved both classes to `app/Filament/Exports/` (outside every Deptrac layer). Updated 4 import sites. Deptrac now 0 violations.
- **Commit:** `b1cd812`

### [Rule 2 — Missing critical] Cutover allow-list extended with WpDirectDb

- **Found during:** Task 1 post-Cutover-layer deptrac run — 2 new violations on `OverridePopulator`.
- **Issue:** `DB::transaction()` for local meetingstore_ops DB writes is legitimate (atomicity on product_overrides upserts). The SYNC-04 WpDirectDb ban targets WORDPRESS DB writes, not Laravel's local DB facade.
- **Fix:** Extended Cutover allow-list with `WpDirectDb` (same pattern as Pricing layer). LegacyPluginDisabler's static-scan DL4 test continues to lock the WP-write side.
- **Commit:** `b1cd812`

### [Rule 2 — Missing critical] Task 2 verified no-op — Shield regen consciously skipped

- **Planned:** Task 2 anticipated possibly running `shield:generate --all` + P5-F restoration protocol.
- **Actual:** Did NOT run shield:generate. PolicyTemplateIntegrityTest already green at floor 26; 0 placeholder leaks; all Dashboard policies hand-written per Plan 07-01 design.
- **Rationale:** Running shield:generate at phase end would reintroduce the Phase 5 + 6 P5-F restoration cycle for zero architectural gain. Hand-written policies are authoritative; CI gate enforces the "no placeholder" property already.
- **Commit:** N/A — no code change needed

---

**Total deviations:** 2 Rule-1/2 auto-fixes (code-moving CsvExportWriter + allow-list extending Cutover). 1 conscious Task 2 skip documented. No Rule 4 architectural asks.

## Authentication Gates

None — Plan 07-06 is pure documentation + test + YAML editing. No external API, no credentialled execution.

## Issues Encountered

1. **MySQL unreachable — Feature-tier Pest suite deferred.** Same as every Phase 6 + Phase 7 Plan SUMMARY. `PDO::connect` to `meetingstore_ops_testing` at `127.0.0.1:3306` fails with SQLSTATE[HY000] [2002]. All architecture tests that don't use `RefreshDatabase` ran green (24 Deptrac + PolicyTemplateIntegrityTest + PriceCalculatorPurityTest = 32 passing). 9 MySQL-dependent architecture tests fail at the PDO::connect step — infrastructure limitation, not a code regression.

2. **18 pre-existing Deptrac violations surfaced.** Plan 07-05 documented these as "out of scope for Plan 07-05, Plan 07-06 owns." Plan 07-06 resolved them via the Rule 1 relocation above (code move, not allow-list change).

## Next Phase Readiness

### Phase 8 (Feeds v2) can assume

- `v1.50.1` milestone SHIPPED (framework complete; operator executes cutover via D-19 runbook).
- Dashboard + Cutover Deptrac layers are locked. Extending Feeds in Phase 8 requires adding Feeds to Dashboard's allow-list AND to each consuming feed's allow-list (one-way arrow preserved: Feeds depends on Products/Pricing; Dashboard reads Feeds metrics but only one-way).
- `HasExportableTable` + `CsvExportWriter` + `QueuedCsvExportJob` are Phase-8-reusable: any new Feed Resource can `use HasExportableTable` + register via `getGloballySearchableAttributes` without Deptrac layer implications.
- All 6 cutover artisan commands are stable; Phase 8 feeds do not touch Woo-write cutover semantics.

### Operator cutover can assume

- `php artisan cutover:checklist` is the single go/no-go signal — ops runs it daily during the parallel-run window.
- `docs/ops/cutover-handover.md` §Appendix A is the authoritative D-19 sequence.
- D-20 carry-forward gates (supplier-probe / woo-sandbox / feature-suite) are the only remaining blockers before the `WOO_WRITE_ENABLED=true` flip.

### Known concerns for Phase 8+

1. **Deptrac single-file consolidation outstanding.** STATE.md pending todo from Phase 5 Plan 05-05 → Phase 7 added 3 more dual-file-sync edits. A dedicated follow-up phase could consolidate `depfile.yaml` + `deptrac.yaml` into one canonical config + delete the other.
2. **Feature-tier MySQL suite will need to execute before go-live.** Operator task (cutover:checklist feature-suite gate). Not a Phase 8 dependency.
3. **Shield `::` separator inconsistency.** Carried from Phase 2 + Phase 6 — Shield's mixed underscore / `::` output pattern in seeder LIKE queries. Not blocking Phase 8.

## Threat Flags

None — Plan 07-06 ships documentation + non-executable YAML config + non-executable verification markdown. No new trust boundaries, no new network endpoints, no new file writes at runtime. The 4 architectural test `file_put_contents` calls (violator files) + `@unlink` cleanups match the Phase 5 + Phase 6 pattern.

## Self-Check

**Files on disk (verified via ls):**
- `app/Filament/Exports/CsvExportWriter.php` — FOUND
- `app/Filament/Exports/QueuedCsvExportJob.php` — FOUND
- `tests/Architecture/DeptracDashboardLayerTest.php` — FOUND
- `tests/Architecture/DeptracCutoverLayerTest.php` — FOUND
- `docs/ops/cutover-handover.md` — FOUND (372 lines)
- `.planning/phases/07-dashboard-polish-cutover/07-VERIFICATION.md` — FOUND (338 lines)
- `app/Domain/Dashboard/Services/CsvExportWriter.php` — NOT FOUND (deleted, intended)
- `app/Domain/Dashboard/Jobs/QueuedCsvExportJob.php` — NOT FOUND (deleted, intended)

**Commits verified via `git log --oneline`:**
- `b1cd812` — Task 1 (Deptrac Cutover layer + 2 architecture tests + relocation) — FOUND
- `a6fa502` — Task 3 (docs/ops/cutover-handover.md) — FOUND
- `e8f2ef3` — Task 4 (07-VERIFICATION.md) — FOUND

**Runtime verification (executed successfully):**
- `php -l` on all 4 new/moved PHP files — 0 syntax errors
- `vendor/bin/deptrac analyse --config-file=depfile.yaml` → **0 violations**
- `vendor/bin/deptrac analyse --config-file=deptrac.yaml` → **0 violations**
- `vendor/bin/pest tests/Architecture/DeptracDashboardLayerTest.php tests/Architecture/DeptracCutoverLayerTest.php` → **8 tests, 8 passed** (10 assertions total)
- `vendor/bin/pest tests/Architecture/PolicyTemplateIntegrityTest.php` → **3 tests, 3 passed** (27 assertions, floor ≥26 verified)
- `grep -r "{{ Placeholder }}" app/Policies/ app/Domain/*/Policies/` → **0 matches**
- Full Deptrac architecture sweep (8 layer tests) → **24 passed, 0 regressions on Phase 1-6 layer tests**
- `wc -l docs/ops/cutover-handover.md` → **372** (≥300 required ✓)
- `wc -l .planning/phases/07-dashboard-polish-cutover/07-VERIFICATION.md` → **338** (≥300 required ✓)

**Deferred verification (requires MySQL online — operator pre-cutover task):**
- ~120 Feature-tier Pest cases across Plans 07-01..07-05 — MySQL-deferred per infrastructure limitation; operator runs before cutover.

## Self-Check: PASSED

---

*Phase: 07-dashboard-polish-cutover*
*Plan: 06-handover-deptrac-verification*
*Completed: 2026-04-24*
*Verdict: SHIPS (FLAG — Feature-suite carries to operator pre-cutover)*
*v1 milestone framework: COMPLETE*
