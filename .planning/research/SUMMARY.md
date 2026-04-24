# Project Research Summary — MeetingStore Ops v2.0 Intelligence + B2B

**Project:** MeetingStore Ops
**Milestone:** v2.0 Intelligence + B2B (continuing v1.50.1 phase numbering — Phases 8-15)
**Domain:** Laravel 12 + Filament 3 modular monolith. Extending v1's shipped 13-domain architecture with a Claude-driven agent framework, B2B trade pricing, quote-to-Bitrix flow, and conversational chat surfaces (WhatsApp inbound + AI product-finder).
**Researched:** 2026-04-24
**Confidence:** HIGH on stack, architecture, pitfalls. MEDIUM on observability bus-factor (single-maintainer Langfuse-Prism shim) and operator-facing decisions (catalogue size, WABA ownership, per-agent cost caps).

> **This SUMMARY replaces the v1 SUMMARY.** v1 originals preserved in git history; v2 builds *on top of* — never modifies — v1's 13 production domains.

---

## Executive Summary

v2.0 is **net-additive**: 5 new Deptrac layers (`Agents`, `TradePricing`, `Quotes`, `Channels`, `Marketing`), 2 new Horizon queues (`agents` + `whatsapp-inbound`), 4 required composer packages, **zero version bumps to v1's stack**.

**Load-bearing decision:** every agent output flows through v1's `SuggestionApplierResolver` seam. v1's `MarginChangeApplier` is the template; agents become producer kinds #5-8. No agent is permitted direct DB writes — enforced by Deptrac (`Agents → Suggestions` only) + Pest grep test.

**8 phases (8-15) in a dependency-forced order:**
- P8 C4 Agent Framework (11 of 24 pitfalls land here)
- P9 E1 Trade Pricing (parallel to agent track; preserves v1 golden fixture)
- P10 C1 Pricing Agent (validates C4)
- P11 E2 Quote Flow (B2B revenue destination)
- P12 C3 SEO Agent (plug-and-play with v1 AutoCreate)
- P13 E3 WhatsApp (inbound + 24h outbound only; no broadcasts)
- P14 E4 AI Product-Finder (compound value; last non-late phase)
- P15 C2 Ad Agent (LATE — needs ~4 weeks post-cutover UTM data)

---

## Recommended Stack Delta (v1 frozen; additions only)

```bash
# Phase 8+ (agent framework)
composer require "prism-php/prism:^0.100"
composer require "mliviu79/laravel-langfuse-prism"

# Phase 11 (quote PDF)
composer require "spatie/laravel-pdf:^2.7"
composer require "dompdf/dompdf"

# Phase 13 (WhatsApp)
composer require "netflie/whatsapp-cloud-api:^3.1"
```

**Picks with rationale:**
- **Prism ^0.100.1** — Laravel-native LLM/agent layer; 3.1M installs; native Anthropic provider; PHP 8.2 floor matches v1
- **`mliviu79/laravel-langfuse-prism`** — auto-traces Prism calls to self-hosted Langfuse Docker; single-maintainer caveat with ~150 LOC custom-OTel fallback
- **`spatie/laravel-pdf` ^2.7 + DOMPDF** — driver-based; Browsershot escape hatch
- **`netflie/whatsapp-cloud-api` ^3.1** (NOT 4.0.0-beta) — direct Meta Cloud API; Bitrix Open Channel rejected on compliance grounds
- **No vector DB, no new chat UI deps** — MySQL FULLTEXT sufficient for ~5k SKUs; Livewire 3.5 `wire:stream` already pinned by Filament 3.3

**Rejected:** `laravel/ai` (PHP 8.3+ floor), `anthropic-ai/sdk` (beta), `laravel-fuse` (custom `BudgetGuard` reuses v1 patterns), all Filament chatbot plugins (OpenAI-locked or Filament-4-only).

---

## Phase Proposal Detail

| # | Phase | Primary feature | Rationale | Research flag |
|---|-------|-----------------|-----------|---------------|
| 8 | **C4 Agent Framework** | C4 | Greenfield; blocks all agents. Ships `AgentRegistry` + `ToolBus` + `GuardrailEngine` + `ClaudeClient` + `AgentRun` + `agents` queue + `shield:safe-regenerate` + `AgentsWriteOnlyViaSuggestionsTest`. 11 of 24 pitfalls land here. | YES |
| 9 | **E1 Trade Pricing** | E1 | Parallel to Phase 8. Decorator `TradeRuleResolver` wraps v1 `RuleResolver` — preserves 50-triple fixture byte-identical. Schema: add `pricing_rules.customer_group_id BIGINT NULL`. Extends fixture 50→80 triples. | LIGHT |
| 10 | **C1 Pricing Agent** | C1 | First agent; enriches ALREADY-QUALIFIED `margin_change` suggestions. Admin-only inputs. Validates C4 end-to-end with low blast radius. | YES |
| 11 | **E2 Quote Flow** | E2 | B2B revenue destination. Needs E1 for accurate trade pricing. Quote line snapshots immune to subsequent rule changes. CRM push via dispatched event handled by listener IN v1 CRM domain (preserves dedup ledger ownership). | MAYBE |
| 12 | **C3 SEO Agent** | C3 | Plug-and-play with v1 Phase 6 AutoCreate review inbox. Approved patches auto-pin via v1 `ProductOverride`. | MAYBE |
| 13 | **E3 WhatsApp** | E3 | Inbound + 24h-window outbound only; marketing templates deferred to v2.1. NEW `Channels` domain reuses v1 Webhooks HMAC+dedup. New `whatsapp-inbound` Horizon queue. | YES (WABA setup + OBO deprecation + 24h window) |
| 14 | **E4 Product-Finder** | E4 | Compound — needs C4 + E3 surface; bridges to E2 via quote-from-chat tool. Public REST + polling (no WebSockets). MySQL FULLTEXT only. | MAYBE |
| 15 | **C2 Ad Agent** | C2 | LATE — needs real Bitrix Deal UTM data (≥4 weeks post-cutover). NEW `Marketing` domain separate from `Agents`. | LATE |

---

## Top 3 Operator Decisions Blocking ANY v2 Phase

1. **Anthropic monthly budget ceiling** — £200/month proposal (PricingAgent £5/day, SeoAgent £3/day, ChatbotAgent £2/session). Drives `BudgetGuard` defaults. Cost-cap defence is highest-leverage control against #1 pitfall.
2. **Self-hosted Langfuse vs Langfuse Cloud** — self-hosted Docker proposed (EU residency for margin + customer data). Extra ops surface (~2 GB/month, `docs/ops/observability.md` runbook). Langfuse SDK wired in Phase 8.
3. **Catalogue size sanity-check** — ~5k SKUs assumed. If 10× wrong, vector-DB phase inserts before Phase 14.

---

## Cross-Cutting Invariants (every phase respects)

1. **Suggestions seam mandatory** for data-changing features — agents, quote approvals, WhatsApp inbound replies all become producer kinds
2. **Dual-YAML Deptrac sync** (depfile.yaml + deptrac.yaml identical) — grep test locks
3. **Dry-run-default CLI** — `--live` opt-in for agents/WhatsApp/quote commands
4. **Shadow-mode gates** (all default false):
   - `AGENT_WRITE_ENABLED` — gates agent execution
   - `AGENT_AUTO_APPLY_ENABLED` — off even post-cutover
   - `WHATSAPP_OUTBOUND_ENABLED` — gates outbound messages
   - `QUOTE_BITRIX_PUSH_ENABLED` — gates quote→Deal push
5. **`shield:safe-regenerate` wrapper** ships Phase 8 (automates P5-F restoration)
6. **Correlation_id threading** through Context → Prism → Langfuse → Suggestions
7. **ULID PKs** for all cross-domain references
8. **Listener-based extension** — never modify v1 jobs
9. **Provider seam pattern** — new external APIs get thin `<X>Client` wrapper mirroring WooClient/BitrixClient
10. **Golden fixture extension, not modification** — v1's 50 triples remain byte-identical; E1 adds 30 new customer-group triples

---

## Top 5 Risks

| # | Risk | Severity | Phase | Mitigation |
|---|------|----------|-------|------------|
| 1 | Runaway agent token consumption | CRITICAL (cost) | P8 | Prism `withMaxSteps(8)` + `BudgetGuard` daily £ ceiling + Sentry token-spike alert |
| 2 | Prompt injection on customer-facing surfaces | CRITICAL (exfil) | P8+13+14 | Trust-tier tagging, XML fences, per-agent tool allow-list, sensitive-fields strip, outbound regex post-filter, jailbreak corpus regression |
| 3 | Agent writes to DB bypassing Suggestions | CRITICAL (audit) | P8 | Deptrac `Agents` layer denies writes to Pricing/Products/Sync/CRM/Competitor; `AgentsWriteOnlyViaSuggestionsTest`; tool naming `propose*` not `create*` |
| 4 | WhatsApp 24h window violation | CRITICAL (WABA suspension = single-strike) | P13 | Per-conversation state machine + `OutsideCustomerCareWindowException` non-bypassable + template registry hourly sync + separate `sendFreeForm` vs `sendTemplate` methods |
| 5 | Quote PDF rendered with stale prices | CRITICAL (commercial) | P11 | Snapshot `quote_lines.unit_price_pence_at_quote` at creation; PDF reads snapshot, never recomputes |

Full 24-pitfall catalogue in `.planning/research/PITFALLS.md`.

---

## Build-Order Dependency Graph

```
                  v1.50.1 shipped
        (ops cutover runs in parallel)
                       │
          ┌────────────┴────────────┐
          ▼                         ▼
    ┌──────────┐              ┌──────────┐
    │ P8: C4   │              │ P9: E1   │
    │ Framework│              │ Trade    │
    └─────┬────┘              └─────┬────┘
          │                         │
    ┌─────┼─────┐                   │
    ▼     ▼     ▼                   ▼
  ┌────┐┌────┐┌────┐           ┌─────────┐
  │P10 ││P12 ││ …  │           │ P11: E2 │
  │ C1 ││ C3 │                 │ Quote   │
  └────┘└────┘                 └────┬────┘
                                    ▼
                              ┌──────────┐
                              │ P13: E3  │
                              │ WhatsApp │
                              └────┬─────┘
                                   ▼
                              ┌──────────┐
                              │ P14: E4  │
                              │ Product- │
                              │ finder   │
                              └────┬─────┘
                                   ▼ (gated on v1 cutover + ~4 weeks UTM data)
                              ┌──────────┐
                              │ P15: C2  │
                              │ Ad agent │
                              └──────────┘
```

**Parallelism:** P8 ∥ P9. P10 ∥ P11 ∥ P12 once P8+P9 complete.

---

## v1 → v2 Seam Mapping

| v1 artefact | Consumed by v2 phase | Integration pattern |
|-------------|----------------------|---------------------|
| `SuggestionApplierResolver` | P8, P10, P12, P14 | Agent outputs register as new kinds (`pricing_agent_margin`, `seo_agent_patch`, `chatbot_quote_draft`) |
| `proposed_by_type/id` morph on `suggestions` | P8 | Becomes agent-vs-rule provenance discriminator |
| `RuleResolver` | P9 | Wrapped by `TradeRuleResolver` decorator — 50-triple fixture untouched |
| `PricingRule.priority` tiebreaker | P9 | Trade rules default `priority + 100` so customer-group wins against same-scope retail rule |
| `MarginAnalyser` + `margin_change` | P10 | Agent enriches ALREADY-QUALIFIED suggestions; never replaces qualifier |
| `ProductOverride.pin_*` columns | P12 | SEO agent's approved patches auto-pin affected fields |
| `AutoCreateReviewResource` review inbox | P12 | SEO agent suggestions surface here first |
| `WooWebhookController` + HMAC middleware | P13 | WhatsApp inbound reuses HMAC+dedup pattern; new route, same middleware chain |
| `BitrixClient` | P11 | Quote→Deal push reuses existing client; new event, new listener in CRM domain |
| `AlertRecipient` with `receives_*` booleans | P8, P13 | New columns: `receives_agent_alerts`, `receives_whatsapp_alerts` |
| `IntegrationLogger` + `integration_events` | All phases | Every Prism call, WhatsApp send, Bitrix Quote push logs here |
| `Auditor` + spatie/activitylog | All phases | `AgentRun`, `Quote`, `WhatsAppConversation` get `LogsActivity` trait |
| Horizon supervisors | P8, P13 | Extend with 2 new queues: `agents` (tries=1) + `whatsapp-inbound` |
| PolicyTemplateIntegrityTest | All phases adding policies | Floor bumped per phase; `shield:safe-regenerate` wrapper automates P5-F |
| Deptrac dual-YAML lesson | All phases adding layers | Every layer addition updates BOTH configs; grep test locks |
| `cutover:checklist` + D-19 runbook | Independent | v1 cutover runs in parallel; no direct v2 integration |
| `dashboard_snapshots` | P8, P15 | New metrics: `agent_runs_daily`, `agent_spend_daily`, `whatsapp_sessions_active`; agents widget row on home dashboard |

---

## Operator Questions Consolidated (17 unique)

### A. Block ANY v2 phase starting (3)

1. Anthropic monthly budget ceiling (£200/month proposal)
2. Self-hosted Langfuse vs Langfuse Cloud (self-hosted proposed)
3. Catalogue size sanity-check (~5k SKUs assumed)

### B. Block specific phase (11)

4. WABA ownership confirmation (P13)
5. WhatsApp template-message catalogue scope (P13)
6. Meta WABA OBO deprecation verification (P13)
7. Trade customer-group seed list (P9)
8. B2 trade-pricing display strategy (P9)
9. Public vs internal SKU split for E4 (P14)
10. Quote PDF branding + template ownership (P11)
11. Customer signature mechanism on quotes (P11)
12. Quote expiry default — 14d proposed (P11)
13. Trade-only catalogue visibility (P9)
14. Bitrix Deal line-item modelling for 30-line quotes (P11)

### C. Discuss-phase OK (3)

15. GCLID capture in v1 — hotfix on v1 side before P15
16. Anonymous chatbot PII storage posture (P14)
17. Chatbot 24/7 vs business-hours (P14)

---

## Gaps to Address

1. Anthropic budget ceiling — sign-off before P8
2. WABA ownership + OBO deprecation — verify before P13 (webhook subs silently fail otherwise)
3. GCLID capture — hotfix on v1 may be needed before P15
4. Catalogue size verification — if >5k, vector-DB phase inserts before P14
5. Trade customer-group seed list — P9 cannot plan without
6. Quote PDF branding — P11 cannot start without design input
7. Provider abstraction depth in C4 — design-only in v2, Claude-only implementation
8. Bitrix24 SDK Quote/Estimate API surface — verify before P11

---

## Sources

- [Prism PHP](https://prismphp.com) + [Packagist prism-php/prism](https://packagist.org/packages/prism-php/prism)
- [Anthropic: Prompt injection defences](https://www.anthropic.com/research/prompt-injection-defenses)
- [Anthropic Agent SDK](https://platform.claude.com/docs/en/agent-sdk/overview)
- [Meta WhatsApp Business Policy](https://business.whatsapp.com/policy) + [Twilio WhatsApp docs](https://www.twilio.com/docs/whatsapp/key-concepts)
- [Bitrix24 REST API: Quotes](https://apidocs.bitrix24.com/api-reference/crm/quote/index.html)
- [WooCommerce B2B Plugins 2026 — PluginHive](https://www.pluginhive.com/woocommerce-b2b-plugins/)
- [LLM Agent Cost Attribution 2026 — Digital Applied](https://www.digitalapplied.com/blog/llm-agent-cost-attribution-guide-production-2026)

---

*Research synthesis complete — 2026-04-24. Ready for requirements definition + roadmapping.*
