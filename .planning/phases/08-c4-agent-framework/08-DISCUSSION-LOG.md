# Phase 8: C4 Agent Framework - Discussion Log

> **Audit trail only.** Decisions captured in CONTEXT.md.

**Date:** 2026-04-24
**Phase:** 08-c4-agent-framework
**Mode:** Interactive (no `--auto`)
**Areas user selected:** Budget split math conflict, Agent run retention conflict
**Areas deferred to Claude's Discretion:** Langfuse Docker placement, Stub agent for E2E verification

---

## Budget split math conflict

### Q1 — Reconciliation approach

| Option | Description | Selected |
|--------|-------------|----------|
| Monthly cap as kill-switch atop daily caps (Recommended) | 2-layer defence; daily soft caps + monthly hard ceiling | ✓ |
| Lower daily caps proportionally (×0.6) | Cleaner math; agents hit caps faster | |
| Dynamic weighted split | Auto-rebalances; complex/unpredictable | |
| Disable monthly ceiling | Trust daily caps only; raises monthly to ~£330 implicitly | |

### Q2 — Kill-switch behaviour when monthly fires

| Option | Selected |
|--------|----------|
| Block new + complete in-flight (Recommended) | ✓ |
| Block + cancel everything | |
| Block writes only, allow reads | |

### Q3 — Daily cap exceed behaviour

| Option | Selected |
|--------|----------|
| Hard fail with BudgetExceededException (Recommended, per AGNT-04 spec) | ✓ |
| Soft fail — record + alert, complete run | |

### Q4 — Day boundary

| Option | Selected |
|--------|----------|
| Europe/London (Recommended — matches v1 convention) | ✓ |
| UTC | |

### Q5 — Default cap for unfamiliar agent kind

| Option | Selected |
|--------|----------|
| 100 pence/day fail-safe (Recommended) | ✓ |
| 0 pence/day = effectively disabled | |
| Same as median configured cap | |

---

## Agent run retention conflict

### Q1 — Resolution strategy

| Option | Selected |
|--------|----------|
| Snapshot relevant audit context onto AgentRun (Recommended) | ✓ |
| Mark audit_log rows with log_name='agent_run' for indefinite retention | |
| Spin off dedicated agent_audit_log table | |

### Q2 — Practical retention horizon

| Option | Selected |
|--------|----------|
| 5 years rolling (Recommended) | ✓ |
| Truly indefinite | |
| 1 year rolling | |

### Q3 — Anthropic payload storage

| Option | Selected |
|--------|----------|
| Structured summary only (Recommended; Langfuse holds full for 90d) | ✓ |
| Full request/response JSON | |
| Hash + Langfuse-only | |

### Q4 — GDPR erasure on customer-bearing AgentRun rows

| Option | Selected |
|--------|----------|
| Scrub PII fields in-place; preserve row (Recommended; mirrors Phase 4 + 6) | ✓ |
| Hard delete AgentRun rows | |
| Hybrid surgical scrub | |

---

## Claude's Discretion (defaults documented in CONTEXT.md)

- Langfuse Docker on `lf.ops.meetingstore.co.uk` (same VPS, admin basic auth, 90d trace + 1y aggregate retention, `docs/ops/observability.md` runbook)
- Stub agent: `EchoAgent` returning timestamp + git SHA — framework smoke test fixture, deleted in Phase 10
- MCP PHP SDK adoption: skip v2.0; Prism's native tool-use sufficient
- AGENT_AUTO_APPLY_ENABLED: permanent false in v2.0; `suggestions.auto_apply_eligible` column shipped nullable for v2.1+
- Suggestion provenance: activate Phase 1's `proposed_by_type/id` morph; polymorphic AgentRun reference
- Prompt storage: Blade views at `resources/views/agents/{kind}/system.blade.php`; sha256 hash on AgentRun column
- Pre-flight token estimation: post-flight only in v2.0
- Trust-tier tagging: explicit `TrustTier` enum constructor arg; Pest architecture test enforces

## Deferred Ideas

- Per-suggestion-kind auto-apply (column shipped v2.0; behaviour deferred v2.1)
- MCP PHP SDK adoption
- Pre-flight token estimation
- Prompt management UI
- Agent provider abstraction (OpenAI/Gemini fallback)
- Token streaming to Filament UI
- Custom Langfuse alert rules within Langfuse
- `agents:checklist` ops command (Phase 15 candidate)
- Agent A/B prompt routing
- WhatsApp integration of agent outputs (Phase 13/14)
