---
gsd_state_version: 1.0
milestone: v2.0
milestone_name: Intelligence + B2B
status: executing
stopped_at: Completed 11-03 Filament QuoteResource plan — 3/5 plans done in Phase 11
last_updated: "2026-05-01T14:47:29.005Z"
last_activity: 2026-05-01
progress:
  total_phases: 9
  completed_phases: 3
  total_plans: 21
  completed_plans: 19
  percent: 90
---

# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-04-24 — v2.0 milestone kicked off)

**Core value:** One Laravel app owns product data, pricing rules, competitor intelligence and CRM sync — Woo is the display layer, nothing more.
**Current focus:** Phase 11 — E2 Quote Request → Bitrix Deal Flow

## Current Position

Milestone: v2.0 Intelligence + B2B
Phase: 11 (E2 Quote Request → Bitrix Deal Flow) — EXECUTING
Plan: 4 of 5
Status: Ready to execute
Last activity: 2026-05-01

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

- Total plans completed: 54 (v1)
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
| 08 | 5 | - | - |
| 09 | 6 | - | - |
| 10 | 5 | - | - |

**v2 phases:** No data yet — v2 planning kicks off with Phase 8.
| Phase 08-c4-agent-framework P01 | 55min | 3 tasks | 20 files |
| Phase 08-c4-agent-framework P02 | 36min | 3 tasks | 9 files |
| Phase 08 P03 | 40 min | 3 tasks | 25 files |
| Phase 08-c4-agent-framework P04 | 19min | 3 tasks | 14 files |
| Phase 08 P05 | 17min | 4 tasks | 23 files |
| Phase 09 P09-02 | 15min | 3 tasks | 4 files |
| Phase 09-e1-trade-customer-pricing P03 | 44m | 3 tasks | 6 files |
| Phase 09 P09-04 | 7m | 3 tasks | 11 files |
| Phase 09 P05 | 37min | 2 tasks | 12 files |
| Phase 09-e1-trade-customer-pricing P06 | 25min | 3 tasks | 6 files |
| Phase 10-c1-pricing-agent P01 | 16min | 3 tasks | 11 files |
| Phase 10-c1-pricing-agent P02 | 16min | 2 tasks | 12 files |
| Phase 10-c1-pricing-agent P03 | 13min | 3 tasks | 7 files |
| Phase 10-c1-pricing-agent P04 | 17min | 3 tasks | 11 files |
| Phase 10-c1-pricing-agent P05 | 21min | 4 tasks | 8 files |
| Phase 11-e2-quote-request-bitrix-deal-flow P11-01 | 28min | 2 tasks | 23 files |
| Phase 11-e2-quote-request-bitrix-deal-flow P02 | 14min | 2 tasks | 12 files |
| Phase 11-e2-quote-request-bitrix-deal-flow P03 | 60min | 2 tasks | 20 files |

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

**v2 Plan 08-02 (2026-04-25):**

- ClaudeClient is the SOLE Anthropic call site (Prism wrapper); enforced by Deptrac + AgentsWriteOnlyViaSuggestionsTest
- Self-hosted Langfuse on lf.ops.meetingstore.co.uk with 127.0.0.1-only port binding; nginx + admin basic auth; mliviu79 shim primary path, custom-OTel fallback documented in observability.md
- Plan W8 verify regex corrected to `[* ]v?<version>` (handles real `composer show v0.100.1` prefix); future plan-checkers should use this pattern
- ClaudeResponse maps Prism's 7-case FinishReason enum to local 5-case D-06 enum with default→Error fall-through (future-proofs against Prism enum additions)
- CostCalculator throws RuntimeException on unknown model (fail-loud — unbudgeted call surfaces as runtime error, not silent zero-cost)

**v2 Plan 11-01 (2026-05-01) — Phase 11 Foundation:**

- OQ-2 RESOLVED: BitrixEntityMap dedup ledger extended with nullable `quote_id` ULID + composite UNIQUE(entity_type, quote_id) coexisting with existing UNIQUE(entity_type, woo_id) for orders. Plan 11-04 EntityDeduper queries via two parallel scope methods (scopeForWooOrder + scopeForQuote)
- A2 RESOLVED (Rule 1 deviation): bitrix_entity_map.entity_type was MySQL ENUM (not VARCHAR as plan A2 assumed). Migration MODIFIES the ENUM allow-list to include 'quote_deal' — preserves the Phase 4 DB-level enum guarantee. SQLite no-op via `DB::getDriverName() === 'mysql'` guard.
- A1 LOCKED: VAT-INCLUSIVE pence storage convention encoded at column-comment + model-docblock level (Pitfall 1). PDF strips VAT at render time via PriceCalculator::stripVat in Plan 11-04. Pest test asserts integer cast preserves 1999 sentinel value.
- OQ-1 RESOLVED: quotes.total_pence_at_quote cached UNSIGNED BIGINT column added; recompute observer ships in Plan 11-02 (draft-only writes; locked alongside lines after status=sent).
- D-04 separation-of-duties enforced at QuotePolicy::approve gate — sales role explicitly DENIED (T-11-01-03 mitigation). 4-eyes pattern in place before Filament UI lands in Plan 11-03.
- D-06 reserved enum cases: QuoteStatus ships PendingApproval + Approved cases for v1.x non-breaking extension; v1.0 transitions never branch on them. NO `withdrawn` case (D-06 deferred — sales overwrites by editing draft).
- PolicyTemplateIntegrityTest floor bumped 27 → 29 covering QuotePolicy + QuoteLinePolicy.
- A9 LOCKED: customer_group_name_at_quote VARCHAR(255) denormalised on quotes table (CONTEXT.md Claude's Discretion).

### Roadmap Evolution

- Phase 09.1 inserted after Phase 9: Integration Connections Admin (URGENT) — Filament admin page for Supplier API + WooCommerce REST + Bitrix + Langfuse + Anthropic credentials with per-integration "Test connection" actions; closes the env-only-credentials ops gap before Phase 10 PricingAgent ships and burns Anthropic budget against potentially-misconfigured upstreams

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
- Plan 08-02: 5 Prism::fake() integration tests in tests/Feature/Agents/ClaudeClientTest.php deferred until MySQL service is online (Pest auto-applies RefreshDatabase to tests/Feature). 9 unit tests pass today. Langfuse Docker stack ready for ops to provision per docs/ops/observability.md

## Session Continuity

Last session: 2026-05-01T14:47:28.991Z
Stopped at: Completed 11-03 Filament QuoteResource plan — 3/5 plans done in Phase 11
Resume: `/gsd-plan-phase 8` (begin C4 Agent Framework planning; research flag YES — run `/gsd-research-phase 8` first if research-before-plan workflow enabled)
