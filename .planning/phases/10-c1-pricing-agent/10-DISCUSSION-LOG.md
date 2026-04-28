# Phase 10: C1 Pricing Agent - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-04-28
**Phase:** 10-c1-pricing-agent
**Mode:** interactive discuss-phase (3 of 4 areas selected; system prompt design deferred to Claude's Discretion)

---

## Trigger + idempotency

### Q1 — Trigger mechanism

| Option | Description | Selected |
|--------|-------------|----------|
| Admin-pull only — Filament button on suggestion detail (Recommended) | Honors PRCAGT-05 'admin-triggered' literally; admin clicks button on suggestion detail to dispatch RunPricingAgentJob | ✓ |
| Auto-trigger on MarginChangeSuggestionCreated event | Listener auto-dispatches; tension with PRCAGT-05; budget burn on auto-rejected suggestions | |
| Both — auto-trigger gated by env flag | Env-flag scaffolding now, auto-trigger off by default | |

**User's choice:** Admin-pull only.
**Rationale:** Trusted-tier means admin-triggered means admin-clicked; auto-trigger is a v2.1 candidate after daily-cap calibration against real traffic.

### Q2 — Re-run rules after failure / rejection

| Option | Description | Selected |
|--------|-------------|----------|
| Latest wins — array of agent_run_ids[] in evidence (Recommended) | New runs append; latest run's enrichment displayed; full history preserved via Phase 8 5y AgentRun retention | ✓ |
| Single-shot — first successful run wins | Once agent_run_id populated, button greys out; clunky UX for prompt iteration | |
| Re-run only on failure or explicit reset | Re-run button visible only when latest agent_run.status=failed or after explicit reset | |

**User's choice:** Latest wins — array.
**Rationale:** Iterative prompt improvement is a v2.0 reality; full audit trail preserved per Phase 8 D-06.

---

## Tool I/O shapes

### Q1 — read_* tool windowing

| Option | Description | Selected |
|--------|-------------|----------|
| 90-day rolling, aligned to Phase 5 sales-threshold-90d (Recommended) | All four read_* tools answer with consistent 90d scope; ~3-5 KB per response | ✓ |
| 30-day rolling, narrower context | Cheaper but may miss seasonal patterns; misaligned with sales-volume reasoning | |
| Configurable per-tool, 90d default | Operator-tunable; risks divergence between tools; defer config to v2.1 | |

**User's choice:** 90-day rolling.
**Rationale:** Consistent time scope across all tools; matches Phase 5's existing window; predictable token budget.

### Q2 — propose_margin_band semantics

| Option | Description | Selected |
|--------|-------------|----------|
| Pure structured tool-call output (Recommended) | Tool is no-op writer; mapper extracts final call after Prism tool-loop and persists to Suggestion.evidence | ✓ |
| Tool writes directly to Suggestion.evidence | Couples tool with model; harder to test in isolation | |
| Tool emits proposal; admin must explicitly accept it | Slower workflow; redundant given approve action already gates real apply | |

**User's choice:** Pure structured contract + mapper.
**Rationale:** Cleanest separation of concerns; Phase 8 tool naming convention applied literally.

### Q3 — Tool response payload caps

| Option | Description | Selected |
|--------|-------------|----------|
| Per-tool 3 KB soft caps with truncation hint (Recommended) | Cap with `_truncated: true` + `_total_available: N` hints; agent can request narrower window | ✓ |
| Hard error on cap breach | Tools throw exception; brittle UX | |
| No caps, trust withMaxSteps + monthly ceiling | Phase 8's loop limit + £200/mo ceiling sufficient; cheaper to build | |

**User's choice:** Soft caps + truncation hint.
**Rationale:** Predictable input-token usage; agent-friendly; protects against pathological responses.

---

## Confidence + band semantics + Filament UX

### Q1 — Confidence calibration

| Option | Description | Selected |
|--------|-------------|----------|
| Prompt-instructed bands with anchor examples (Recommended) | 0-30 LOW / 31-70 MODERATE / 71-100 HIGH; few-shot examples; Filament colour badge | ✓ |
| Computed from data signal-strength, not agent-emitted | More objective but loses qualitative judgment | |
| Both — agent self-report + mapper-computed dual-track | Richer signal but UI clutter; defer to v2.1 | |

**User's choice:** Prompt-instructed bands.
**Rationale:** Calibration improves over time via prompt iteration; dual-track deferred until rejection-misleading data exists.

### Q2 — Out-of-band conflict UX

| Option | Description | Selected |
|--------|-------------|----------|
| Flag conflict in UI, approve allowed but with warning (Recommended) | Red OUT-OF-BAND chip + approve-with-reason confirmation modal; reason captured to audit_log | ✓ |
| Block approve until admin manually overrides per band | Treats agent as gating signal; defeats PRCAGT-01 'enriches deterministic, never replaces' | |
| Display both, no gating logic | No automated conflict detection; misses audit-trail signal | |

**User's choice:** Flag + approve-with-reason modal.
**Rationale:** Preserves admin authority + creates audit trail for prompt iteration.

### Q3 — Rejection feedback shape

| Option | Description | Selected |
|--------|-------------|----------|
| Structured form + dedicated Filament inbox (Recommended) | misleading? Y/N/Partial radio + mandatory note; new /admin/agent-runs/rejection-inbox page | ✓ |
| Free-text note only, no structured field | Lightest schema; harder to filter for prompt-iteration triage | |
| Auto-feed rejection notes into next-run system prompt | Compounding-drift risk; defer to v2.1 | |

**User's choice:** Structured form + inbox.
**Rationale:** Structured 'misleading' flag is the prompt-iteration data source; no auto-prompt-feedback in v2.0 (compounding-drift risk).

---

## Claude's Discretion (defaults documented in CONTEXT.md)

- **System prompt design** — Blade view at `resources/views/agents/pricing/system.blade.php` with persona (UK B2B AV reseller pricing analyst, predictability over aggressive optimisation), workflow (read sequence suggested but not enforced), confidence rubric (3 anchor bands inline), output contract (mandatory final propose_margin_band), few-shot examples (1 HIGH-confidence + 1 LOW-confidence worked case), versioning via git + system_prompt_hash column
- **Temperature lock = 0** confirmed (Phase 8 default)
- **EchoAgent deletion** in this phase (Phase 8 smoke fixture replaced with inline test stub)
- **Filament page route** `/admin/agent-runs/rejection-inbox` (single-purpose triage page, not a Resource)
- **Tool implementation files** at `app/Domain/Agents/Tools/Pricing/`
- **PricingAgent class** at `app/Domain/Agents/Agents/PricingAgent.php`
- **Listener for MarginChangeSuggestionCreated** NOT shipped (admin-pull only per D-01)
- **New permission** `run_pricing_agent` for admin + pricing_manager via `shield:safe-regenerate`
- **Single migration** `2026_04_28_010000_add_agent_rejection_feedback_to_suggestions_table.php` (nullable JSON column)
- **Test scope** ~25-30 Pest cases covering 5 tools + mapper + Filament + queue + idempotency

## Deferred Ideas

- Auto-trigger via listener on MarginChangeSuggestionCreated (v2.1)
- Agent confidence dual-track (mapper-computed + self-report)
- Auto-prompt-feedback from rejection notes
- Multi-LLM provider fallback (Claude-only v2.0)
- Pre-flight token estimation
- Token streaming on Filament UI
- Per-brand prompt variants
- Agent-initiated rule edits (out of scope)
- Confidence-driven auto-apply (out of scope; AGENT_AUTO_APPLY_ENABLED stays false)
- Real-time cost ticker on UI
- Cross-suggestion batch enrichment
- Tool result caching across runs
