# MeetingStore Ops — Living Retrospective

A milestone-by-milestone record of what was built, what worked, what was inefficient, and lessons carried forward. Cross-milestone trends accumulate at the bottom.

---

## Milestone: v1.50.1 — v1 Framework

**Shipped:** 2026-04-24
**Phases:** 7 | **Plans:** 38 | **Tasks:** 82 | **LOC:** ~15,160 | **Timeline:** 2026-04-18 → 2026-04-24 (6 days)

### What Was Built

Laravel modular-monolith replacement for two sanctions-compromised WordPress plugins. 7 discrete business capabilities (Foundation seams → Supplier Sync → Pricing Engine → Bitrix24 CRM Sync → Competitor Analysis → Product Auto-Create → Dashboard + Cutover) each verifiable independently. 4 real suggestion producers (margin_change, crm_push_failed, new_product_opportunity, auto_create_failed), 7 Horizon queue supervisors, dual-YAML Deptrac architecture enforcement, end-to-end correlation_id threading through webhook → Context → LogBatch → queued jobs, and a parity-threshold-gated cutover command suite that preserves operator control over the `WOO_WRITE_ENABLED=true` flip.

### What Worked

- **Phase 1 "seams not features" strategy paid off across every subsequent phase.** Shipping `DomainEvent` + `Auditor` + `IntegrationLogger` + `BaseCommand` + suggestions seam + `WOO_WRITE_ENABLED` shadow-mode gate before any business logic meant Phases 2-7 composed cleanly with zero retrofits.
- **Dry-run-default CLI pattern (Phase 2 D-04) became a universal convention** — `sync:supplier`, `pricing:recompute`, `bitrix:backfill-orders`, `competitor:watch`, and the 6 cutover commands all inherited the same `--live` opt-in + env-gate safety pattern.
- **Golden-fixture ship gate (Phase 3 D-04) caught zero pricing regressions** across 5 subsequent phases that touched the pricing path. 50-triple penny-exact parity is cheaper than any alternative.
- **Listener-based Phase 2 extension (Phase 6 D-11)** preserved regression-test scope cleanly. Zero Phase 2 code modified during Phase 6 pin enforcement; ApplyPinsDuringSync listener intercepts events post-commit. Architectural grep test proved Phase 2's SyncChunkJob was byte-identical.
- **Auto-mode discuss-phase with recommended defaults** sustained momentum across Phases 5, 6, 7. Converted planning from interactive-dialogue overhead into deterministic CONTEXT.md authoring; the verification loop caught real issues without per-question ceremony.
- **Deptrac dual-YAML sync + architectural tests** caught integration drift before it landed. The `DeptracCompetitorLayerTest` regression in Plan 05-05 pre-flight (stale `depfile.yaml` while `deptrac.yaml` was current) surfaced a would-be silent CI break.

### What Was Inefficient

- **MySQL unavailable in code-executor sandbox for Phases 6+7** forced `deferred` verification status on ~120 Feature-tier Pest tests. They were authored to correct spec + schema + doubles shape — but didn't execute until ops runs them during cutover prep. The workaround (`cutover:checklist` Gate 3) is clean but defers confidence. Next milestone: ensure executor agents have MySQL access before starting.
- **REQUIREMENTS.md traceability drift** accumulated across phases. 14 rows stayed marked `Pending` despite delivery (PRCE-01..10, CRM-06, CRM-07, CRM-11, DASH-03) because the post-plan-complete "flip the checkbox" step was easy to miss. The milestone audit caught it. Fix shipped inline as a doc maintenance commit; next milestone: add a post-summary hook that auto-flips.
- **Empty `07.1-milestone-closeout-polish/` scaffold directory** got created by `/gsd-plan-milestone-gaps` pre-emptively, then orphaned when I routed user through inline cleanup instead of creating a phase. Deleted at milestone-complete time — harmless but noisy. Next: only scaffold when user confirms the path.
- **Shield regeneration protocol (P5-F)** is brittle. Three plans (04-04, 05-04a, 06-04) had to execute the same 3-step restoration (`shield:generate --all` → `git checkout -- app/Domain/*/Policies/` → re-run PolicyTemplateIntegrityTest). Should be wrapped in a single artisan command for v2.
- **Phase 4 first-iteration planning** produced a 27-file Plan 05-04 that the plan-checker correctly flagged as scope-risk. Splitting into 05-04a + 05-04b worked, but the initial planner judgement was off. Plan-checker saved the day; keep the revision loop.

### Patterns Established (carry forward to v2)

- **Suggestion producer seam** — first real producer was Phase 4 CrmPushRetryApplier; then Phase 5 (margin_change + new_product_opportunity stub); then Phase 6 (real NewProductOpportunityApplier + AutoCreateRetryApplier). Phase 6 Q4 "move the applier across domains" proved the one-way-arrow pattern works cleanly with Deptrac.
- **Research Open Questions (RESOLVED) ratification** (Dimension 11 lesson from Phase 5) — plans that reference research MUST mark open questions resolved inline. Plan-checker enforces.
- **Checklist-as-go/no-go-gate** — `cutover:checklist` integrating 3 operator carry-forward gates + 7 runbook steps is the cleanest "is it time to ship?" signal pattern. Reuse for v2 feature flags / channel rollouts.
- **Dual-YAML config sync** — when adding architectural layers, BOTH depfile.yaml and deptrac.yaml get identical edits in the same commit. Enforced by grep test.
- **Documented ops one-liners as fallback for WP-CLI-over-REST** — LegacyPluginDisabler pattern (attempt WP-CLI programmatically; fall through to documented ops command). Avoids Deptrac `WpDirectDb` bans while remaining honest about execution boundaries.
- **Observer-bypass awareness (A3 finding)** — Laravel 12 `saveQuietly` suppresses both `saving` and `saved` observer events. Phase 2's `forceFill + saveQuietly` pattern forced Phase 6 to use domain-event listeners for completeness scoring, not Eloquent observers. Document in v2 onboarding docs.

### Key Lessons

1. **Plan the seams before planning the features.** Phase 1's 5 plans looked like overkill at the time; they paid for themselves in Phases 2-7 with zero retrofits.
2. **Research flag `skip` is valid and meaningful.** Phase 7 was "execution discipline, not novel research" — skipping research saved an agent round-trip without sacrificing plan quality.
3. **The plan-checker's scope-sanity dimension is load-bearing.** Plan 05-04 (27 files, 4 tasks) was correctly flagged and split. Without this check, that plan would have exhausted executor context mid-flight.
4. **Operator carry-forward items should become checklist gates, not new phases.** Three Phase 6 gates (supplier probe, Woo sandbox, Feature suite) were candidates for a "Phase 6.1 cleanup phase" — instead they lived as PENDING rows in `cutover:checklist`. Cleaner, no ceremony, still blocking.
5. **Traceability drift is real; add a structural hook.** Manual checkbox flipping after plan completion misses rows 16% of the time (14/85 drift rate). Post-summary automation is cheap insurance.
6. **FLAG verdict is a valid ship state.** Phase 6 + 7 shipped with FLAG (architecture green, test execution deferred). Docs capture the deferral; operator runbook enforces resolution. Would block blindly `passed` ship.

### Cost Observations

- **Model mix:** Opus primary for planning + checker; Sonnet for executor agents; mix roughly 40/60 across ~120 agent spawns
- **Agent sessions:** ~120 executor + ~14 researcher + ~14 planner + ~14 plan-checker + ~6 integration/verifier = ~170 subagent runs
- **Rate-limit events:** 2 mid-phase (Wave 4 Phase 4 — recovered via commit-inventory spot-check; Wave 5 Phase 5 — recovered via partial-commit continuation)
- **API 500 events:** 1 (Wave 5 Phase 5 — recovered via partial-commit continuation)
- **Notable efficiency:** Phase 7 research skip saved ~1 agent round-trip (~15% of Phase 7 orchestration cost); auto-mode discuss-phase eliminated ~30 interactive question roundtrips across Phases 5-7

---

## Cross-Milestone Trends

(Tables populate as additional milestones complete.)

### Timeline Velocity

| Milestone | Phases | Plans | Tasks | LOC | Days | Tasks/day |
|-----------|--------|-------|-------|-----|------|-----------|
| v1.50.1 | 7 | 38 | 82 | ~15,160 | 6 | ~14 |

### Verification Debt Accumulation

| Milestone | Deferred Feature tests | Resolved before ship? | Carry-forward gates |
|-----------|------------------------|----------------------|---------------------|
| v1.50.1 | ~120 (Phases 6+7) | No — MySQL unavailable in executor; gated via cutover:checklist | 3 operator gates |

### Architectural Discipline

| Milestone | Deptrac violations at ship | WP DB write ban violated? | Shield placeholder leaks |
|-----------|----------------------------|---------------------------|--------------------------|
| v1.50.1 | 0 | 0 (static-scan test enforces) | 0 (P5-F protocol) |

### Top Recurring Patterns

| Pattern | First Seen | Reused In | Status |
|---------|------------|-----------|--------|
| Dry-run-default CLI | Phase 2 D-04 | Phases 3, 4, 5, 6, 7 | Canonical |
| Suggestion producer seam | Phase 1 D-17 (contract) | Phases 4, 5, 6 (4 kinds) | Canonical |
| Listener-based Phase 2 extension | Phase 5 + Phase 6 | Verified byte-identical Phase 2 | Canonical |
| Dual-YAML Deptrac sync | Phase 5 05-05 | Phases 6, 7 | Canonical |
| P5-F shield restoration | Phase 5 04a | Phases 6, 7 | Canonical (wrap in artisan command for v2) |

---

*Retrospective initiated at v1.50.1 milestone completion. Next entry: v2 when it ships.*
