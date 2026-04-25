---
gsd_state_version: 1.0
milestone: v2.0
milestone_name: Intelligence + B2B
status: executing
stopped_at: "Completed 08-01-PLAN.md (foundation: agent_runs ULID model, agents-supervisor, Deptrac Agents layer, 3 arch tests)"
last_updated: "2026-04-25T11:04:39.295Z"
last_activity: 2026-04-25
progress:
  total_phases: 8
  completed_phases: 0
  total_plans: 5
  completed_plans: 1
  percent: 20
---

# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-04-24 — v2.0 milestone kicked off)

**Core value:** One Laravel app owns product data, pricing rules, competitor intelligence and CRM sync — Woo is the display layer, nothing more.
**Current focus:** Phase 08 — C4 Agent Framework

## Current Position

Milestone: v2.0 Intelligence + B2B
Phase: 08 (C4 Agent Framework) — EXECUTING
Plan: 2 of 5
Status: Ready to execute
Last activity: 2026-04-25

Progress: [░░░░░░░░░░] 0% (0/8 v2 phases; 7/7 v1 phases shipped 2026-04-24)

**v2 build order:** 8 (C4) ∥ 9 (E1) → 10 (C1) → 11 (E2) → 12 (C3) → 13 (E3) → 14 (E4) → 15 (C2 — LATE, gates on v1 cutover + ≥4 weeks UTM data)

**v2 active research flags:**

- Phase 8: YES — Prism API surface, Langfuse self-hosted Docker, MCP PHP SDK, shield:safe-regenerate design
- Phase 10: YES — prompt design, deterministic temp=0 calibration, token budget across input contexts
- Phase 13: YES — WABA setup, Meta OBO BSP deprecation 2026, 24h window state machine

**Top 3 operator decisions blocking ANY v2 phase (per research/SUMMARY.md):**

1. Anthropic monthly budget ceiling — proposal: £200/month default
2. Self-hosted Langfuse vs Cloud — proposal: self-hosted Docker (EU residency)
3. Catalogue size sanity-check — assumption: ~5k SKUs (drives E4 FULLTEXT vs vector DB)

## Performance Metrics

**Velocity (v1.50.1 final):**

- Total plans completed: 38 (v1)
- Average duration: ~25 min/plan
- Total execution time: ~16 hours over 6 days

**By Phase (v1.50.1):**

| Phase | Plans | Total | Avg/Plan |
|-------|-------|-------|----------|
| 1 | 5 | ~3.7h | 44m |
| 2 | 5 | ~2.0h | 24m |
| 3 | 5 | - | - |
| 4 | 5 | ~3.5h | 42m |
| 5 | 6 | ~2.4h | 24m |
| 6 | 6 | ~1.7h | 17m |
| 7 | 6 | ~2.0h | 19m |

**v2 phases:** No data yet — v2 planning kicks off with Phase 8.
| Phase 08-c4-agent-framework P01 | 55min | 3 tasks | 20 files |

## Accumulated Context

### v2 Milestone Decisions (locked 2026-04-24)

- **Anthropic budget:** £200/month default; revisit after Phase 8 ships + 2 weeks of real usage
- **Langfuse:** self-hosted Docker on ops VPS (EU residency for margin + customer data)
- **Catalogue assumption:** ~5k SKUs; MySQL FULLTEXT for E4; vector DB deferred to v2.1
- **Phase numbering:** Continues from v1 (Phase 8-15); does NOT reset
- **Net-additive architecture:** 5 new Deptrac layers, 2 new Horizon queues, 4 required composer packages, ZERO version bumps to v1's stack
- **Cutover gate on Phase 15:** Must NOT begin Phase 15 planning until v1 cutover is live in production AND ≥4 weeks of Bitrix Deal UTM data has accumulated. Pre-flight (ADAGT-05) verifies GCLID capture or ships a v1 hotfix first.
- **v1 frozen:** v2 is net-additive; v1 jobs/listeners are NEVER modified; all v2 hooks are subscribers via DomainEvent + listener-based extension

### v2 Cross-Cutting Invariants (every phase respects)

1. Suggestions seam mandatory for any data-changing feature
2. Dual-YAML Deptrac sync — every new layer in BOTH `depfile.yaml` AND `deptrac.yaml`
3. Dry-run-default CLI — `--live` opt-in
4. Shadow-mode gates default false: `AGENT_WRITE_ENABLED`, `AGENT_AUTO_APPLY_ENABLED`, `WHATSAPP_OUTBOUND_ENABLED`, `QUOTE_BITRIX_PUSH_ENABLED`
5. `shield:safe-regenerate` wrapper ships in Phase 8 — every later phase uses it
6. Correlation_id threading: Context → Prism → Langfuse → Suggestions → integration_events
7. ULID PKs for all cross-domain references (`AgentRun`, `Quote`, `WhatsAppConversation`, `ChatbotSession`)
8. Listener-based extension of v1 — never modify v1 jobs
9. Provider seam pattern — new external APIs get thin `<X>Client` wrappers (mirror `WooClient` / `BitrixClient`)
10. Golden fixture extension, not modification — v1's 50 PriceCalculator triples remain byte-identical

### Decisions (carry-forward from v1.50.1 — see PROJECT.md Key Decisions table)

Decisions are logged in PROJECT.md Key Decisions table. Recent v1 decisions affecting v2 work:

- v1 framework + audit/integration/suggestions seams are the load-bearing seams for every v2 phase
- v1 cutover is ops-executed in parallel with v2 dev (does NOT block v2 phases 8-14)
- E5 RAMS cross-project integration deferred to v2.1+
- Channel feeds (Phase 8 in candidate list), customer automation, forecasting all deferred to v2.1+

### Pending Todos

None yet — Phase 8 planning kicks off with `/gsd-plan-phase 8`.

### Blockers/Concerns

**Operator decisions to confirm before Phase 8 plan 01 lands:**

- Anthropic budget ceiling sign-off (proposal: £200/month)
- Self-hosted Langfuse Docker provisioning on ops VPS

**Carry-forward from v1.50.1 (operator-side, not v2 dev work):**

- v1 cutover execution per `docs/ops/cutover-handover.md` Appendix A
- 3 operator carry-forward gates: supplier API probe, Woo sandbox image URL pass-through re-validation, feature-tier Pest suite run against online MySQL

**Per-phase operator questions (per research/SUMMARY.md operator decisions tracker):**

- Phase 9 (E1): trade customer-group seed list (Trade / Reseller / Education / NHS proposed); display strategy `retail` vs `hidden`
- Phase 11 (E2): quote PDF branding template ownership; customer signature mechanism; Bitrix Deal line-item modelling for 30-line quotes
- Phase 13 (E3): WABA ownership confirmation; template catalogue scope; Meta OBO deprecation verification
- Phase 14 (E4): public vs internal SKU split; anonymous chatbot PII storage posture; chatbot 24/7 vs business-hours
- Phase 15 (C2): GCLID capture in v1 — hotfix on v1 may be required before P15
- Plan 08-01: AgentRunTest (12 unit tests) + migration verification deferred until MySQL service is running on 127.0.0.1:3306

## Session Continuity

Last session: 2026-04-25T11:04:31.132Z
Stopped at: Completed 08-01-PLAN.md (foundation: agent_runs ULID model, agents-supervisor, Deptrac Agents layer, 3 arch tests)
Resume: `/gsd-plan-phase 8` (begin C4 Agent Framework planning; research flag YES — run `/gsd-research-phase 8` first if research-before-plan workflow enabled)
