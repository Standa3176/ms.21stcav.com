# Architecture: v2.0 Intelligence + B2B Integration

**Domain:** Modular-monolith Laravel 12 ops app — extending v1.50.1 (13 domains shipped) with AI agents + trade pricing + quote flow + chat surfaces
**Researched:** 2026-04-24
**Confidence:** HIGH (v1 internals verified by direct file reads; v2 design synthesised from v1 seams)

---

## Executive Summary

v2.0 lands as **5 new Deptrac layers** on top of the v1 13-domain skeleton, introduces **2 new Horizon queues** (`agents` + `whatsapp-inbound`), and treats the `SuggestionApplierResolver` registry as the single integration point for every agent output. **Zero v1 domain is modified.** Trade pricing extends `pricing_rules` with a nullable `customer_group_id` column (rather than a parallel table) so v1's RuleResolver becomes the trade resolver with one new layer prepended. Quote flow lives in a new `Quotes` domain that consumes `Pricing` (read-only) and feeds `CRM` (one-way arrow, identical to Phase 4 precedent). WhatsApp + the AI product-finder split: WhatsApp inbound webhooks reuse the v1 `Webhooks` domain pattern (HMAC middleware + dedup + dispatch event); the product-finder lives in a new `Channels` domain (the public-facing surface), backed by an internal API talking to the same `Agents` domain. The build order is forced: **C4 framework first** (without it the 3 agents have no contract to register against), then **E1 trade pricing** (unblocks E2), then **E2 quote flow**, then **C1/C3 agents in parallel** (independent data domains), then **E3 WhatsApp + E4 product-finder** (channels are leaf consumers), with **C2 ad agent last** (depends on Phase 4 CRM data being live in production, which gates on the v1 cutover).

---

## Recommended Architecture

### High-Level Component Diagram

```
                                  ┌────────────────────────┐
   public users (E3+E4) ─────────▶│   Channels (NEW)       │   webhooks-inbound queue
   ops admins (Filament) ────┐    │   - WhatsApp Webhooks  │── + WHATSAPP_HMAC middleware
                             │    │   - Product-finder API │── + RateLimiter
                             │    └────────┬───────────────┘
                             │             │ dispatches AgentRunRequested events
                             │             ▼
                             │    ┌────────────────────────┐
                             │    │   Agents (NEW)         │   agents queue
                             │    │   - AgentRegistry      │── (LLM-bound, slow)
                             │    │   - ToolBus            │
                             │    │   - GuardrailEngine    │
                             │    │   - AgentRun model     │
                             │    └────────┬───────────────┘
                             │             │ produces Suggestion (kind=agent_*)
                             │             ▼
                             │    ┌────────────────────────┐
                             │    │ Suggestions (V1) ◀─────│ ← unchanged seam
                             │    │  +Applier registry     │
                             │    └────────┬───────────────┘
                             │             │ admin approves → ApplySuggestionJob
                             │             ▼
                             │    ┌────────────────────────┐
                             ▼    │  v1 domains (writes)   │
                        ┌────────────────┐
                        │   Quotes (NEW) │── reads Pricing
                        └───────┬────────┘── writes CRM (Bitrix Deal)
                                │
                                ▼
                        ┌────────────────┐
                        │  Pricing (V1)  │── extended: customer_group_id col
                        └────────────────┘   + TradeCustomerGroup model
```

### New Domain Boundaries (5 layers)

| Layer | Responsibility | Communicates with (Deptrac allow-list) |
|-------|----------------|----------------------------------------|
| **`Agents`** | Agent registry, tool-use contracts, ToolBus, GuardrailEngine, AgentRun model, AgentRunJob | `Foundation`, `Suggestions` (writes), + read-only allow-lists per agent (see §Read Boundaries) |
| **`Quotes`** | Quote model, QuoteLine, QuoteBuilder service, CRM Deal payload mapper, PDF/email rendering | `Foundation`, `Products`, `Pricing`, `CRM`, `Suggestions` |
| **`TradePricing`** | Customer-group resolver layer prepended to v1 RuleResolver (decorator pattern, NO parallel table) | `Foundation`, `Pricing`, `Products` |
| **`Channels`** | WhatsApp webhook controller, WhatsApp client, public product-finder API controller, rate-limit middleware, sessionised chat state | `Foundation`, `Products`, `Suggestions`, `Webhooks` (HMAC pattern), `Agents` |
| **`Marketing`** *(C2 only)* | UTM/GA aggregation reader over Bitrix Deals, ad-spend aggregator, BudgetSuggestion producer | `Foundation`, `CRM` (read-only models), `Suggestions` |

**Why 5 layers (not 1 mega-`Agents`):**
- C4 framework is provider-agnostic infrastructure. C1/C2/C3 are domain-specific consumers. Putting them in one layer would force the framework to know about `Pricing` + `Competitor` + `CRM` directly — exactly the cross-cutting coupling Deptrac was added to prevent.
- E2 quote flow has its own data model + UI + lifecycle (draft → sent → won/lost) that would bloat any other domain.
- E1 is a *decorator* over Pricing, not a replacement. Keeping it in a thin `TradePricing` layer means v1's `PriceCalculatorGoldenFixtureTest` keeps passing untouched (the trade path bypasses the calculator — see §E1 below).
- E3+E4 are both customer-facing channels. Keeping a single `Channels` layer (rather than `WhatsApp` + `ProductFinder`) lets shared concerns (rate limiting, session state, identity resolution) live once.
- Marketing is split out from Agents because C2 specifically reads CRM Deal aggregates — separating the *reader* from the *agent runtime* keeps the agent layer's allow-list narrow.

---

## Integration Points (V1 Seams Each Feature Plugs Into)

### Seam 1 — `SuggestionApplierResolver` (the load-bearing one)

**v1 contract** (`app/Domain/Suggestions/Services/SuggestionApplierResolver.php`):
```php
$resolver->register('kind_string', ApplierClass::class);
```
**Used today by 4 producers:** `test`, `crm_push_failed`, `new_product_opportunity`, `auto_create_failed`, `margin_change`.

**v2 plug-in points** (added to `AppServiceProvider::boot()` `afterResolving` block):
```php
// C1 Pricing agent
$resolver->register('agent_margin_proposal', \App\Domain\Agents\Appliers\AgentMarginProposalApplier::class);
// C2 Ad optimisation agent
$resolver->register('agent_ad_budget_change', \App\Domain\Marketing\Appliers\AdBudgetApplier::class);
// C3 SEO/content agent
$resolver->register('agent_seo_content_patch', \App\Domain\Agents\Appliers\SeoContentPatchApplier::class);
// E1 Trade pricing approval (manual customer-group rule changes)
$resolver->register('trade_pricing_rule_change', \App\Domain\TradePricing\Appliers\TradeRuleApplier::class);
// E3 WhatsApp inbound qualified-lead capture
$resolver->register('whatsapp_lead_qualified', \App\Domain\Channels\Appliers\WhatsappLeadApplier::class);
```

**Provenance tracking** (NEW — load-bearing for agent vs rule disambiguation):

The v1 `Suggestion` model already has a `proposed_by_type` / `proposed_by_id` morph pair (currently unused). v2 makes it required for agent suggestions:

| Producer | proposed_by_type | proposed_by_id | kind prefix |
|----------|------------------|----------------|-------------|
| Rule-based (v1) | `null` | `null` | `margin_change`, `new_product_opportunity`, … |
| Agent-based (v2) | `App\Domain\Agents\Models\AgentRun` | AgentRun ULID | `agent_*` |
| Channel-based (v2 E3) | `App\Domain\Channels\Models\WhatsappConversation` | conversation ULID | `whatsapp_*` |

**This means:** the existing `SuggestionResource` Filament inbox renders the same UI for both agent + rule suggestions, but the polymorphic `proposedBy` relation lets a "View reasoning" action drill into AgentRun.tool_calls (the LLM tool-use trace) only when the source is an agent. No new model required — v1 already shipped this.

### Seam 2 — `DomainEvent` base class (correlation_id threading)

**v1 contract** (`app/Foundation/Events/DomainEvent.php`): `ShouldDispatchAfterCommit` + auto-population of `correlationId` from `Context`.

**v2 use:** Every new event extends this:
- `Agents\Events\AgentRunRequested` (Channels → Agents)
- `Agents\Events\AgentRunCompleted` (Agents → multiple — Dashboard widget can subscribe)
- `Quotes\Events\QuoteCreated` / `QuoteAccepted` (Quotes → CRM listener creates Bitrix Deal)
- `Quotes\Events\QuoteSent` (Quotes → Audit / no listeners required initially)
- `Channels\Events\WhatsappMessageReceived` (Webhooks → Agents — same pattern as `OrderReceived`)
- `Channels\Events\ProductFinderQuerySubmitted` (Channels → Agents)

**Critical:** all events carry primitive fields, never models (T-03-05 mitigation locked in v1).

### Seam 3 — `IntegrationLogger` (every external API call)

**v1 contract:** All outbound HTTP through a wrapped client (WooClient, BitrixClient, SupplierClient) routes via `IntegrationLogger::log()` with auto-correlation + header redaction.

**v2 additions** (each is a new wrapped client, NOT a free-form HTTP client):
- `Agents\Services\ClaudeClient` — wraps Anthropic Messages API (replaces direct `Http::post` calls). Logs every tool-use turn separately.
- `Channels\Services\WhatsappClient` — wraps Meta Graph API for WhatsApp Business send.

**Sensitive-header additions** (extend `IntegrationLogger::SENSITIVE_HEADERS`):
- `x-anthropic-api-key`, `x-meta-app-secret`, `x-hub-signature-256`

### Seam 4 — `WOO_WRITE_ENABLED` shadow-mode gate

**v1 contract:** Every Woo write checks `config('woo.write_enabled')`. v2 introduces an analogous gate per agent:

```php
config('agents.<agent_name>.write_enabled') => false  // default
config('agents.<agent_name>.suggestions_enabled') => true  // default — agent runs but only writes Suggestions
config('agents.<agent_name>.auto_apply_enabled') => false  // default — admin approval required
```

This gives a 3-step ladder per agent: **off** → **suggestions-only** → **auto-apply**. Each rung is a config flip, not a code change. C1 (pricing) auto-apply will likely never be enabled in v2 (regulatory + stakeholder concern). C3 (SEO content) is the candidate for first auto-apply because copy edits are reversible.

### Seam 5 — `Webhooks` domain pattern (HMAC + dedup + receipt + dispatch)

**v1 contract:** `VerifyWooHmacSignature` middleware → `WebhookReceipt` row (unique `(source, delivery_id)`) → `OrderReceived` event dispatch.

**v2 reuse for E3 WhatsApp:** identical pattern, NEW middleware class (`VerifyWhatsappSignature` — Meta uses `X-Hub-Signature-256`) + NEW topic `whatsapp.message`. The `WebhookReceipt` table is reused as-is by adding `'whatsapp'` as a valid `source` value (no migration — `source` is already a string column).

**v2 reuse for E4 product-finder:** NOT a webhook. Product-finder is a public REST endpoint with rate limiting + optional session token. Lives in `Channels`, not `Webhooks`. But the queue handoff pattern is the same: HTTP controller persists a request artefact + dispatches a domain event + returns 202 with a poll URL.

### Seam 6 — `BitrixEntityMap` (CRM dedup ledger)

**v1 contract:** v1's CRM domain dedups Bitrix Deals/Contacts/Companies via `bitrix_entity_map` (Pitfall-6 ledger).

**v2 use:** E2 quote flow creates Bitrix Deals with `entity_local_type='quote'` (NEW value). The v1 dedup logic works unchanged because the ledger is keyed on `(entity_local_type, entity_local_id)`.

### Seam 7 — `Pricing` domain (RuleResolver + PriceCalculator)

**v1 contract:** RuleResolver returns `PricingResolution` based on (product, override, rule chain). PriceCalculator is pure integer-pennies math.

**v2 extension for E1:** `RuleResolver::resolve()` is **NOT modified**. Instead, `TradePricing\Services\TradeRuleResolver` wraps it:

```php
final class TradeRuleResolver {
    public function __construct(
        private readonly RuleResolver $base,
    ) {}

    public function resolve(Product $product, ?TradeCustomerGroup $group = null): PricingResolution {
        if ($group === null) {
            return $this->base->resolve($product);  // retail path — unchanged v1 behaviour
        }
        // Trade path — try customer-group rules first, fall back to base
        $tradeRule = PricingRule::query()
            ->where('customer_group_id', $group->id)
            ->where('active', true)
            ->orderByDesc('priority')->orderBy('id')->first();
        if ($tradeRule) {
            return new PricingResolution(/* ... */);
        }
        return $this->base->resolve($product);  // fallback
    }
}
```

This is the **decorator pattern** — v1's golden-fixture parity test (50 triples, retail path) keeps passing because retail callers still reach `RuleResolver::resolve()` directly. The `customer_group_id` column is `null` for all v1 rules, so the fallback path is the v1 path bit-for-bit.

---

## Schema Changes (Minimal — v2 is mostly NEW tables, not column additions)

### Modified v1 tables (only 2 column additions)

```sql
-- E1 Trade Pricing
ALTER TABLE pricing_rules ADD COLUMN customer_group_id BIGINT UNSIGNED NULL AFTER scope;
ALTER TABLE pricing_rules ADD INDEX idx_pricing_rules_customer_group (customer_group_id, active);

-- E3 WhatsApp opt-in tracking on existing customers
ALTER TABLE products ADD COLUMN whatsapp_finder_views BIGINT UNSIGNED DEFAULT 0;  -- C3 SEO signal
```

### New v2 tables (per-feature)

| Table | Owner | Purpose |
|-------|-------|---------|
| `agent_runs` | Agents | One row per LLM invocation: agent_name, status, tool_calls JSON, input/output tokens, started_at, finished_at, correlation_id, suggestion_id (nullable FK) |
| `agent_tool_invocations` | Agents | One row per tool-call inside a run: tool_name, args JSON, result JSON, latency_ms, error_message |
| `agent_guardrail_violations` | Agents | Audit row when GuardrailEngine blocks an action: agent_run_id, rule_name, attempted_action, reason |
| `trade_customer_groups` | TradePricing | id, name, description, default_margin_basis_points, requires_approval (bool) |
| `trade_customer_group_members` | TradePricing | customer_group_id, bitrix_contact_id (NULL for unbound), email, approved_at |
| `quotes` | Quotes | id, ulid, status (draft/sent/accepted/rejected/expired), bitrix_deal_id (nullable), customer_group_id, valid_until, total_pennies, correlation_id |
| `quote_lines` | Quotes | quote_id, product_id, qty, unit_price_pennies (snapshot), margin_basis_points (snapshot), notes |
| `whatsapp_conversations` | Channels | id, ulid, phone_e164, bitrix_contact_id (nullable), last_message_at, agent_handoff_at, status |
| `whatsapp_messages` | Channels | conversation_id, direction (inbound/outbound), wa_message_id, body, media JSON, received_at |
| `product_finder_sessions` | Channels | id, ulid, session_token (cookie), bitrix_contact_id (nullable on guest), correlation_id, started_at |
| `product_finder_queries` | Channels | session_id, query_text, agent_run_id (FK), suggested_skus JSON, clicked_sku, converted (bool) |
| `marketing_deal_attribution` | Marketing | bitrix_deal_id, utm_campaign, ga_client_id, attributed_spend_pennies, computed_at |

**Note on quotes ↔ Bitrix Deal:** the `quotes` table is **the source of truth** for the line-item structure (Bitrix Deal custom fields can't model line items cleanly). The Bitrix Deal is a one-way push (same as v1 orders) carrying total + a reference to the local quote URL. This means the v1 CRM domain doesn't need any new code — Quotes domain dispatches a quote-accepted event that v1's existing CRM listener pattern handles via a NEW listener subscribed to `QuoteAccepted`.

---

## Updated Deptrac Allow-Lists (dual-YAML — depfile.yaml + deptrac.yaml)

**Critical lesson from v1 Phase 5:** both YAML files must be updated in lockstep. Phase 5 Plan 05-05 lost a half day to silently-stale config. Every v2 phase plan that adds a layer must include "edit both files" as a tasked step + verify with the existing pattern test.

```yaml
ruleset:
  # v1 (unchanged)
  Products:          [Foundation, ProductAutoCreate]
  Pricing:           [Foundation, Products, Sync, WpDirectDb]
  Sync:              [Foundation, Products, Alerting, '-WpDirectDb']
  Webhooks:          [Foundation]
  CRM:               [Foundation, Sync, Alerting, Webhooks, Suggestions]
  Suggestions:       [Foundation]
  Alerting:          [Foundation]
  Feeds:             [Foundation]
  Competitor:        [Foundation, Pricing, Products, Suggestions, Webhooks, Alerting]
  ProductAutoCreate: [Foundation, Products, Pricing, Sync, Suggestions, Alerting, Webhooks]
  Dashboard:         [Foundation, Products, Pricing, Sync, Suggestions, Alerting, Webhooks, Competitor, CRM, ProductAutoCreate, WpDirectDb]
  Cutover:           [Foundation, Products, Pricing, Sync, Suggestions, Alerting, Webhooks, Competitor, CRM, ProductAutoCreate, Dashboard, WpDirectDb]

  # v2 NEW
  Agents:            [Foundation, Suggestions, Products, Pricing, Competitor, CRM, ProductAutoCreate]
                     # NOTE: Agents READS broadly (LLM tool-use needs catalogue + pricing + competitor data
                     # context) but WRITES only via Suggestions seam. Read-only access is enforced by tool
                     # contract (see §C4 below) — Deptrac can't see "read-only" but the architecture test
                     # `AgentsWriteOnlyViaSuggestionsTest` greps for Eloquent ::create / ::update / ::delete /
                     # ->save in app/Domain/Agents/* and fails the build if any are found outside
                     # Models/AgentRun*.
  TradePricing:      [Foundation, Pricing, Products]
                     # Decorates RuleResolver. NEW PricingRule.customer_group_id col → still owned by Pricing.
  Quotes:            [Foundation, Products, Pricing, TradePricing, CRM, Suggestions]
                     # Quotes write to CRM via dispatched event (one-way arrow preserved).
  Channels:          [Foundation, Products, Webhooks, Suggestions, Agents]
                     # Channels can dispatch agent runs but never imports Pricing/CRM directly.
                     # Lead capture → Suggestion → CRM listener (existing pattern).
  Marketing:         [Foundation, CRM, Suggestions]
                     # Reads CRM Deal aggregates; produces budget Suggestions.

  # v1 Http unchanged BUT extended:
  Http: [Foundation, Products, Pricing, Competitor, Sync, Webhooks, CRM, Suggestions, Alerting, Feeds,
         ProductAutoCreate, Dashboard, Cutover, Agents, TradePricing, Quotes, Channels, Marketing]
```

**One-way arrow guarantees** (each enforced by a per-layer Deptrac test, mirroring v1 Phase 7 pattern):
- Nothing imports `Agents` except `Channels`, `Http`, `Dashboard` (widget for agent run health), `Cutover` (forward-compat).
- Nothing imports `Channels`. Nothing imports `Quotes`. Nothing imports `Marketing`. Nothing imports `TradePricing` (it decorates `Pricing`; consumers go through `Pricing` for retail and through `TradePricing` for trade).
- **Critical:** `Agents` does NOT import `Channels` (channels dispatch events to agents, not the reverse). This prevents the catastrophic "agent calls back into the channel that invoked it" cycle that breaks correlation_id threading.

---

## C4 Agent Framework — Detailed Design

### Class skeleton

```
app/Domain/Agents/
├── Contracts/
│   ├── Agent.php                  // run(AgentInput): AgentOutput
│   ├── Tool.php                   // name(), schema(), execute(args): result
│   └── Guardrail.php              // check(action): GuardrailDecision
├── Services/
│   ├── AgentRegistry.php          // maps agent_name → class (mirror of SuggestionApplierResolver)
│   ├── ToolBus.php                // tool registry; resolves tools by name; logs invocations
│   ├── GuardrailEngine.php        // runs every Guardrail before each tool execution
│   ├── ClaudeClient.php           // wraps Anthropic Messages API; logs via IntegrationLogger
│   └── AgentRunRecorder.php       // persists AgentRun + AgentToolInvocation + violations
├── Models/
│   ├── AgentRun.php               // ULID PK; correlation_id; tool_calls JSON; suggestion_id
│   ├── AgentToolInvocation.php
│   └── AgentGuardrailViolation.php
├── Jobs/
│   └── RunAgentJob.php            // ShouldQueue — onQueue('agents'); 1 try (LLM is expensive); no retry on error → DLQ Suggestion
├── Tools/
│   ├── ReadProductCatalogueTool.php
│   ├── ReadCompetitorPricesTool.php
│   ├── ReadPricingRulesTool.php
│   ├── ReadCrmDealsTool.php
│   └── (NO WriteTools — every "write" is a CreateSuggestionTool that produces a Suggestion row)
├── Guardrails/
│   ├── BudgetCapGuardrail.php     // per-agent token spend ceiling (24h rolling)
│   ├── ScopeGuardrail.php         // tool name must be on agent's allow-list
│   └── PiiRedactionGuardrail.php  // strip emails/phones from CRM tool returns before LLM
├── Appliers/
│   ├── AgentMarginProposalApplier.php   // C1
│   └── SeoContentPatchApplier.php       // C3
└── Console/Commands/
    └── AgentRunCommand.php        // php artisan agent:run pricing --dry-run
```

### Why a `ToolBus` (and not direct PHP calls)

- **Isolation:** every tool call is logged + guardrailed. A free-form `\App\Domain\Products\Models\Product::find(...)` call from inside an agent class would bypass GuardrailEngine and break the `AgentsWriteOnlyViaSuggestionsTest` enforcement.
- **Schema export:** the LLM needs JSON Schema for each tool. ToolBus collects all registered tools' schemas at boot and exposes a single array to the ClaudeClient (matches Anthropic Messages API tool-use shape).
- **Replay:** AgentToolInvocation rows can replay an entire agent run for debugging — load tool name + args + result, no LLM re-invocation needed.

### Why ONE `RunAgentJob` (not per-agent jobs)

- Agent runs are LLM-bound. Per-agent jobs would multiply Horizon supervisors with no benefit (they all share one rate limit pool — Anthropic API key tier).
- `RunAgentJob` takes `(string $agentName, AgentInput $input)`. Registry resolves `$agentName` → class. Single queue (`agents`) means a single supervisor with `maxProcesses=2` (LLM concurrency cap matches Anthropic tier-1 limits).
- **No retry policy:** `tries=1`. LLM errors are usually deterministic (bad prompt, schema drift). Retry-on-failure would burn tokens twice for the same wrong answer. Failure → write DLQ Suggestion (kind=`agent_run_failed`) and surface in inbox.

### Agent run data flow

```
Channels::WhatsappWebhookController
  ↓ dispatches WhatsappMessageReceived
Channels::HandleWhatsappMessage listener
  ↓ inspects intent → if "find product"
  ↓ dispatches AgentRunRequested(agent='product_finder', input=...)
Agents::HandleAgentRunRequested listener
  ↓ dispatches RunAgentJob → 'agents' queue
RunAgentJob::handle
  ↓ resolve agent from registry
  ↓ persist AgentRun (status=running, correlation_id from Context)
  ↓ agent.run(input) → uses ToolBus → uses ClaudeClient
  ↓ ClaudeClient → Anthropic Messages API w/ tool-use loop:
  │     - LLM proposes tool_use
  │     - GuardrailEngine.check() — block or allow
  │     - ToolBus.execute() — log to AgentToolInvocation
  │     - return tool_result to LLM
  │     - repeat until LLM returns final response
  ↓ persist AgentRun (status=completed, output=...)
  ↓ if output proposes change → produce Suggestion row (proposed_by_type=AgentRun)
  ↓ dispatch AgentRunCompleted event (Dashboard widget subscribes)
SuggestionResource (Filament) — admin reviews, approves, ApplySuggestionJob → applier → v1 domain write
```

**Observation:** the agent never directly writes to Product, PricingRule, etc. Every agent output that proposes a change goes through Suggestions. This satisfies the v1 invariant (every change auditable + reversible) without modifying any v1 code.

---

## E1 Trade Customer Pricing — Schema Decision

**Decision: extend `pricing_rules` with nullable `customer_group_id`. Do NOT create a parallel `trade_pricing_rules` table.**

**Reasoning:**
1. v1's `RuleResolver` query plan, `PricingRuleResource` Filament UI, simulated-impact preview, and golden-fixture test all operate on a single table. Two tables means two query plans, two UIs, two test suites.
2. The "most-specific wins" semantics already account for tiebreaks via `priority` + `id`. Customer-group becomes one more *layer* in the resolution chain (above brand+category), implemented in `TradePricing\TradeRuleResolver` — no rule-engine surgery.
3. NULL `customer_group_id` is the universal retail/anonymous case. Existing rows take this default automatically (column is nullable). Zero data migration.
4. The decorator pattern (`TradeRuleResolver` wraps `RuleResolver`) preserves the v1 golden-fixture test untouched — retail callers reach the v1 path bit-for-bit.

**Tradeoff acknowledged:** if v3 ever needs *radically different* trade pricing logic (e.g. negotiated per-line discounts), the column-extension approach gets cramped. At that point a `trade_pricing_rules` parallel table becomes the right call. v2's scope (single margin per customer group) fits the column extension cleanly.

**Implementation note for E1 plans:**
- The decorator decision means `TradePricing\TradeRuleResolver` is the *only* class consumers should call going forward. Add a static analysis test: `app/Domain/Quotes/**/*` must NOT import `App\Domain\Pricing\Services\RuleResolver` (must use the trade resolver). Internal `Pricing` callers (RecomputePriceListener) keep using the base resolver because they don't know the customer group.

---

## E2 Quote Flow — CRM Integration

**Decision: NEW `Quotes` domain. Quote → Bitrix Deal sync via NEW listener subscribed to `QuoteAccepted` event, dispatched into the existing `crm-bitrix` queue.**

**Why NOT extend the CRM domain:**
- Quotes have rich line-item state, expiry windows, status transitions. CRM's job is to *push* events to Bitrix. Mixing the two would explode CRM's responsibility.
- v1's CRM domain has 5 models, 18+ services. Adding Quote + QuoteLine + QuoteRenderer + QuoteEmailMailer + QuotePdfRenderer would make CRM bigger than its v1 scope.

**Why NOT skip the CRM domain entirely:**
- CRM owns the `BitrixClient` + `BitrixEntityMap` dedup ledger. Quotes domain importing `BitrixClient` directly bypasses the dedup ledger (Pitfall-6 reintroduction).
- Solution: Quotes dispatches `QuoteAccepted` event. NEW `CRM\Listeners\HandleQuoteAccepted` listener (lives in CRM domain, not Quotes) builds the Deal payload using v1's `DealPayloadBuilder` + `EntityDeduper` + `PushOrderToBitrixJob`. **Quotes never imports CRM directly except for the event class definition** (which is in Quotes\Events).

**Quote status state machine:**
```
draft → sent → accepted → (CRM Deal pushed) → won/lost (manual)
        ↓
     expired (by scheduled command — quotes:expire-stale --age=14d)
        ↓
     rejected (by sales action)
```

---

## E3 WhatsApp — Webhook + Agent Handoff

**Decision: extend the v1 `Webhooks` domain pattern with a new HMAC middleware + topic, but house the WhatsApp client + conversation state in the new `Channels` domain.**

**Why split:**
- The v1 Webhooks domain is intentionally minimal: HMAC verify + dedup + dispatch event. WhatsApp adds session state (conversations span hours), media handling (image uploads), and outbound replies (a Webhooks domain has no business doing outbound).
- Putting WhatsappClient in Channels keeps Webhooks pure. Channels imports Webhooks for the receipt + middleware patterns; Webhooks does NOT import Channels.

**Inbound flow:**
1. Meta POST → `/webhooks/whatsapp` (route in `routes/webhooks.php`)
2. `Channels\Http\Middleware\VerifyWhatsappSignature` middleware (mirror of v1's `VerifyWooHmacSignature` — checks `X-Hub-Signature-256` HMAC against `WHATSAPP_APP_SECRET`)
3. `Channels\Http\Controllers\WhatsappWebhookController::handle` — persists `WebhookReceipt` (source='whatsapp', topic='message') + dedups by Meta's `messages[].id` field + dispatches `WhatsappMessageReceived` event
4. `Channels\Listeners\RouteWhatsappMessage` (default queue, fast) — finds-or-creates `WhatsappConversation` row + dispatches `AgentRunRequested(agent='product_finder')` for product queries OR `WhatsappLeadQualified` Suggestion for sales handoff
5. `Agents::RunAgentJob` runs LLM tool-use loop on the `agents` queue (slow)
6. Agent output → either reply via `WhatsappClient::send` OR escalate via Suggestion

**Queue routing:**
- Webhook ACK is fastest path: `webhook-inbound` queue (existing) handles the receipt write + event dispatch in <200ms.
- Heavy work (agent runs, message-send retries) lives on `agents` (NEW) and `whatsapp-inbound` (NEW) queues.

**Why TWO new queues:**
- `agents` — LLM-bound, slow (5-30s per turn), low concurrency (Anthropic rate limits). Reused by E4 product-finder.
- `whatsapp-inbound` — message routing, conversation upsert, fast (<2s), medium concurrency. Separate from `agents` so a slow LLM doesn't block message dedup.

### Meta 2026 lesson — own your WABA

(From WebSearch — MEDIUM confidence) Meta deprecated the BSP On-Behalf-Of model in 2026. WABA must be owned by the meetingstore.co.uk Meta Business account directly. **Phase plan must include an ops gate:** "WABA verified + owned by 21CAV Ltd Meta Business — no BSP intermediary." Otherwise webhook subscriptions silently fail.

---

## E4 AI Product-Finder — Public-Facing Surface

**Decision: lives in the same Laravel app, NOT a separate mini-app. Public-facing endpoint with rate limiting + optional session token.**

**Why same app:**
- Product catalogue + pricing + Bitrix CRM are all in one DB. A separate app means another deploy + cross-app HTTP calls + duplicated authn.
- Rate limiting + WAF protection are concerns shared with the WhatsApp webhook (Channels domain handles both).
- Future C2 ad-attribution flow (UTM landing → product-finder query → Bitrix Lead) requires read access to v1 catalogue and write access to Suggestions. A separate app would need a private API anyway — better to skip the round trip.

**Why NOT serve from Filament:**
- Filament is admin-authn-gated. Product-finder users are unauthenticated (or carry a thin session cookie).
- Filament auto-generates routes under `/admin/...`. Product-finder routes live under `/api/finder/...` to keep the mental model clean.

**Endpoint design:**
```
POST /api/finder/sessions          → returns session_token (cookie)
POST /api/finder/queries           → submits query (rate-limited per session)
                                     → dispatches AgentRunRequested(agent='product_finder')
                                     → returns 202 with poll_url
GET  /api/finder/queries/{id}      → polls for agent result (returns suggested_skus on completion)
POST /api/finder/queries/{id}/click → tracks SKU click for conversion attribution
```

**Authentication / rate limiting:**
- Anonymous users get a session cookie (HttpOnly, SameSite=Lax) and 10 queries / hour / session.
- Bitrix-known users (matched by browser fingerprint OR captured email) get 100 queries / hour.
- Rate limiter uses Laravel's stock `RateLimiter::for('product-finder', ...)` keyed on session OR Bitrix contact id.

**Why polling, not WebSockets:**
- Same-app keeps it simple. Agent runs are 5-30s. Polling at 2s intervals is fine.
- WebSockets requires Reverb/Pusher infrastructure not currently in v1. Adding it for one feature is overkill — defer to v2.1 if needed.

---

## C1 Pricing Agent — Data Flow + Tools

**Tools available** (Agents → Read* allow-list):
- `ReadProductCatalogueTool` — query products by SKU/brand/category
- `ReadCompetitorPricesTool` — last 90d competitor history per SKU
- `ReadPricingRulesTool` — current resolver chain for a SKU
- `ReadSalesCounterTool` — `last_sales_count_90d` per SKU (v1 Phase 5 sales counter)
- `CreateMarginProposalTool` — produces a Suggestion (kind='agent_margin_proposal'). NO direct PricingRule write.

**Trigger:**
- Scheduled: `php artisan agent:run pricing --weekly` (Sunday 03:00) iterates over high-velocity SKUs flagged by competitor margin deltas.
- Manual: pricing manager clicks "Ask agent" on a SKU detail page → dispatches RunAgentJob synchronously.

**Diff vs v1 `MarginAnalyser` (Phase 5):**
- v1's MarginAnalyser is rule-based: 8% threshold + 3 consecutive scrapes + ≥N sales = produce margin_change Suggestion.
- C1 agent reasons over the same data plus contextual signals (seasonality, brand reputation, related-SKU prices) the rule engine can't model. Output is a Suggestion with kind=`agent_margin_proposal` (different kind so they don't collide in the inbox).
- Both producers can fire on the same SKU. Filament inbox shows both. Admin chooses which to approve.

---

## C2 Ad Optimisation Agent — Marketing Domain

**Why a SEPARATE `Marketing` domain (not in `Agents`):**
- C2 reads CRM Deal aggregates by UTM/GA Client ID. The reader logic is reusable: future Dashboard widgets, future ROI reports.
- Putting the reader in `Agents` would force a non-agent dashboard widget to import `Agents` (which the Deptrac one-way arrow forbids).

**Tools available:**
- `ReadDealAttributionTool` — joins `bitrix_entity_map` + `integration_events` (channel='bitrix') to extract UTM/GA Client ID per Deal
- `ReadAdSpendTool` — reads from a NEW `marketing_deal_attribution` table (populated by a scheduled command — out of v2 scope to actually wire to Google Ads API; v2 ships the table + manual CSV import command)
- `CreateAdBudgetSuggestionTool` — produces Suggestion (kind='agent_ad_budget_change') with payload describing recommended campaign budget shifts

**Critical dependency:** C2 needs *real* Bitrix Deal data with UTM fields populated. v1 Phase 4 ships UTM capture, but production data won't exist until v1 cutover lands AND a few weeks of orders pass. **C2 should be the LAST agent built**, after cutover is live and data has accumulated. Anything earlier is testing on synthetic data.

---

## C3 SEO/Content Agent — Auto-Created Product Patches

**Tools available:**
- `ReadAutoCreatedDraftsTool` — products where `auto_create_status='pending_review'` and `completeness_score < 70` (Phase 6 fields)
- `ReadCompetitorContentTool` — read competitor product titles/descriptions for context (when available in scrape data)
- `CreateSeoContentPatchTool` — produces Suggestion (kind='agent_seo_content_patch') with a unified diff against current product fields

**Why a Suggestion (not direct write):**
- v1's "no AI in product content" anti-feature is preserved by design. Agent generates *proposals*, admin reviews + approves. The SuggestionApplier writes the patch.
- This crosses the line from "AI for formatting" (allowed by v1 constraint) into "AI for content" (forbidden in v1 Phase 6). v2 explicitly opens this with admin approval gating — the constraint evolves but is not violated, because admin still owns the content.

**First candidate for `auto_apply_enabled=true`:** if approval rate exceeds 90% over a few hundred suggestions, the auto-apply gate becomes a config flip. C1 (pricing) auto-apply will likely never enable — financial risk too high.

---

## Queue Routing — Updated Horizon Config

**Recommendation: add 2 new queues, do NOT consolidate into existing.**

```yaml
# config/horizon.php — production environment additions
agents-supervisor:
    connection: redis
    queue: ['agents']
    balance: simple
    minProcesses: 1
    maxProcesses: 2          # Anthropic tier-1 concurrency cap
    tries: 1                  # NEVER retry LLM calls — burn tokens once, write DLQ on failure
    timeout: 300              # 5min — enough for tool-use loops
    memory: 512

whatsapp-inbound-supervisor:
    connection: redis
    queue: ['whatsapp-inbound']
    balance: simple
    minProcesses: 1
    maxProcesses: 4           # Meta send rate is generous; conversation routing is cheap
    tries: 3                  # message-send is fast + retry-safe
    timeout: 60
    memory: 256
```

**Why NOT reuse `default`:**
- A 30-second LLM call would block all default-queue jobs (saved-filter housekeeping, audit-log prunes). LLM calls deserve their own pool with their own concurrency cap.
- Horizon dashboard shows per-queue health — easier to spot agent backlog when it has its own row.

**Why NOT a per-agent queue:**
- All agents share the Anthropic API key + tier rate limit. Multiple queues would each need to throttle against the same upstream — single queue with `maxProcesses=2` is the simplest correct answer.

---

## Patterns to Follow (Carry Forward from v1)

### Pattern 1: Provider seam (Foundation contract + per-provider adapter)
**v1 example:** WooClient wraps `automattic/woocommerce`; BitrixClient wraps `bitrix24/b24phpsdk`.
**v2 use:** ClaudeClient wraps Anthropic SDK. WhatsappClient wraps Meta Graph API. Both use IntegrationLogger + shadow-mode gate.

### Pattern 2: Producer registers via `AppServiceProvider::boot()`
**v1 example:** `$resolver->register('crm_push_failed', CrmPushRetryApplier::class)`.
**v2 use:** every new SuggestionApplier, every new Agent (in AgentRegistry), every new Tool (in ToolBus) registers identically. Single boot file = single audit point.

### Pattern 3: Dual-YAML Deptrac sync
**v1 lesson:** Phase 5 lost half a day to stale config. Both YAML files must be edited in lockstep.
**v2 enforcement:** every plan that adds a layer includes both files in tasked changes + a grep test (existing `DeptracDashboardLayerTest` pattern).

### Pattern 4: Shadow-mode gate per external write
**v1 example:** `WOO_WRITE_ENABLED`, `CRM_WRITE_ENABLED` default false; writes diverted to `sync_diffs`.
**v2 use:** `AGENT_AUTO_APPLY_ENABLED`, `WHATSAPP_SEND_ENABLED` default false during onboarding. WhatsApp sends in shadow mode write to `sync_diffs` with `provider='whatsapp'`.

### Pattern 5: ULID PKs for cross-domain references
**v1 example:** `Suggestion.id` is ULID so `IntegrationEvent.subject_id` can morph cleanly.
**v2 use:** `AgentRun.id`, `Quote.id`, `WhatsappConversation.id`, `ProductFinderSession.id` — all ULID.

### Pattern 6: `ShouldDispatchAfterCommit` for every domain event
**v1 lesson:** Phase 2 Plan 03 retrofit. Without this, listeners fire on rolled-back transactions.
**v2 use:** every new event extends `DomainEvent` (which already implements the interface).

### Pattern 7: Listener-based extension (never modify upstream jobs)
**v1 example:** `ApplyPinsDuringSync` subscribes to v1 supplier events without modifying `SyncChunkJob`.
**v2 use:** `AgentRunCompleted` listener on Dashboard side; `RouteWhatsappMessage` listener on Channels side. v1 jobs/listeners are NEVER modified.

---

## Anti-Patterns to Avoid

### Anti-Pattern 1: Direct LLM HTTP calls outside ClaudeClient
**Why bad:** bypasses IntegrationLogger (no audit), bypasses GuardrailEngine (no token cap), bypasses correlation_id threading.
**Instead:** every Anthropic call goes through `Agents\Services\ClaudeClient`. Add a Deptrac classLike rule banning `Illuminate\Support\Facades\Http` from `app/Domain/Agents/*` except in ClaudeClient.

### Anti-Pattern 2: Agents that write directly to v1 models
**Why bad:** breaks the audit trail, breaks the human-in-the-loop guarantee, breaks reversibility.
**Instead:** every agent change is a Suggestion. Admin approval triggers the existing applier path. Architecture test (`AgentsWriteOnlyViaSuggestionsTest`) greps `app/Domain/Agents/**/*.php` for `::create(`, `->save(`, `::update(`, `::delete(` outside `Models\AgentRun*` and fails the build.

### Anti-Pattern 3: Channel domain calling agent domain synchronously
**Why bad:** LLM is 5-30s. HTTP request would time out. WhatsApp webhook would exceed Meta's 200ms ACK.
**Instead:** Channels dispatches event → Agents handles async on `agents` queue → Agents persists result → Channels polls (E4) or listens for AgentRunCompleted (E3).

### Anti-Pattern 4: Quote → Bitrix push without going through CRM domain
**Why bad:** bypasses BitrixEntityMap dedup ledger (Pitfall-6 reintroduction).
**Instead:** Quotes dispatches `QuoteAccepted` event → CRM listener handles via existing `PushOrderToBitrixJob`-equivalent path with `entity_local_type='quote'`.

### Anti-Pattern 5: Modifying `pricing_rules` table semantics
**Why bad:** breaks v1's golden-fixture test (50 triples). v1 cutover monitoring expects bit-exact parity.
**Instead:** add nullable `customer_group_id` column. NULL = retail (existing behaviour). Decorator pattern for trade.

### Anti-Pattern 6: Building a custom queue for agent runs
**Why bad:** Horizon already provides queue tagging, retries, monitoring, failed-job DLQ.
**Instead:** new Horizon queue (`agents`) with documented `tries=1` (no retries) + `maxProcesses=2` (rate limit cap).

---

## Build Order — Dependency-Forced

```
                          ┌─── C4 Agent Framework (no deps; greenfield)
                          │       │
                          │       ├─── C1 Pricing Agent (needs C4 + v1 Phase 5)
                          │       │
   E1 Trade Pricing ──────┤       ├─── C3 SEO Agent (needs C4 + v1 Phase 6)
        (deps: v1 Phase 3)│       │
                          │       └─── C2 Ad Agent (needs C4 + LIVE Bitrix data
                          │                          → blocks on cutover going live)
                          │
                          └─── E2 Quote Flow (needs E1 — quote-to-trade-customer
                                              relationship is load-bearing)
                                       │
                                       └─── E3 WhatsApp Channel
                                                       │
                                                       └─── E4 Product-Finder
                                                            (needs C4 — agent backbone)
```

### Recommended phase sequence (8 phases — proposing 8 because v2 scope is structurally different from v1's monolithic phase shape)

| # | Phase | Builds | Unlocks | Research flag |
|---|-------|--------|---------|---------------|
| 8 | C4 Agent Framework | Agents domain skeleton, ToolBus, GuardrailEngine, ClaudeClient, AgentRun model, agents queue, suggestion provenance morph | All agent work | YES — Anthropic SDK PHP options + tool-use loop semantics |
| 9 | E1 Trade Pricing | TradePricing domain, customer_group_id col, TradeRuleResolver decorator, customer-group admin UI | E2 | light — RuleResolver decorator pattern is well-trod |
| 10 | C1 Pricing Agent | Concrete agent + tools + prompt + scheduled command, Filament "Ask agent" action | Validates C4 framework end-to-end | YES — prompt design + token budget calibration |
| 11 | E2 Quote Flow | Quotes domain, QuoteResource Filament UI, PDF rendering, Bitrix Deal listener, quotes:expire-stale command | E3 (lead-to-quote handoff) | MAYBE — line-item Deal modelling against Bitrix custom fields |
| 12 | C3 SEO Agent | Concrete agent on Phase 6 auto-create draft inbox | Auto-apply candidate (longer-term) | MAYBE — content patch diff format |
| 13 | E3 WhatsApp Channel | Channels domain, VerifyWhatsappSignature, WhatsappWebhookController, WhatsappClient, conversation state, RouteWhatsappMessage listener, whatsapp-inbound queue | E4 | YES — Meta WABA setup + 2026 OBO deprecation impact + 24h messaging window rules |
| 14 | E4 AI Product-Finder | Public /api/finder/* endpoints, ProductFinderSession state, rate limiting, polling protocol | Customer-facing surface | MAYBE — rate-limit calibration |
| 15 | C2 Ad Agent | Marketing domain, ReadDealAttributionTool, ReadAdSpendTool, BudgetSuggestion producer | Closing the marketing loop | LATE — runs after some weeks of cutover-live Bitrix data accumulates |

**Phase 8 (C4) before everything else:** without the framework, agents are just one-off scripts. The discipline of building the framework first forces correct provenance + guardrails + tool-bus design before three agents accidentally diverge into three different shapes.

**Phase 11 (E2 Quote) before E3/E4 channels:** chat surfaces ultimately funnel customers into quotes. Without quotes, the chat ends at "we'll email you" — the funnel stops. Build the destination first.

**Phase 15 (C2) last:** depends on real Bitrix Deal data with UTM populated. v1 cutover ships UTM capture but production data needs weeks to accumulate. Building C2 too early means testing on synthetic data + risking re-work when real distributions surface.

---

## Scalability Considerations

| Concern | At launch (v2 ship) | At 3 months | At 12 months |
|---------|---------------------|-------------|--------------|
| Agent token spend | Single Anthropic key, tier-1 rate limit, ~£500/mo budget | Multi-key rotation OR upgrade to tier-2 | Switch C1 + C3 to Sonnet for cost (Haiku for C2 cheap reads) |
| WhatsApp message volume | Single phone number, <1000/day | Conversation routing logic (sales vs support) | Multiple WABA phone numbers, source attribution per number |
| Product-finder queries | Anonymous: 10/hr/session, Identified: 100/hr | Add Redis cache layer for popular queries | Move to dedicated finder app if traffic exceeds 10K/day (decision deferred) |
| Quote PDF rendering | dompdf (already installed) | Same | Same — PDFs are async, queue-bound |
| AgentRun row volume | ~100/day | ~1K/day | Prune AgentToolInvocation older than 90d |

---

## Sources

- v1.50.1 codebase (direct file reads — HIGH confidence): `app/Domain/`, `app/Foundation/`, `app/Providers/AppServiceProvider.php`, `app/Providers/EventServiceProvider.php`, `depfile.yaml`, `deptrac.yaml`, `config/horizon.php`, `routes/webhooks.php`
- v1.50.1 planning artefacts: `.planning/PROJECT.md`, `.planning/milestones/v1.50.1-ROADMAP.md`, `.planning/milestones/v1.50.1-MILESTONE-AUDIT.md`
- [Claude PHP SDK Laravel](https://packagist.org/packages/claude-php/claude-php-sdk-laravel) — MEDIUM confidence (community SDK, not Anthropic-official; verify via Context7 in Phase 8 research)
- [Anthropic MCP PHP SDK](https://www.nihardaily.com/85-official-php-sdk-for-mcp-integrate-claude-ai-easily) — MEDIUM confidence (recent release, Context7 verify)
- [WhatsApp Business API Integration 2026](https://chatarmin.com/en/blog/whats-app-business-api-integration) — MEDIUM confidence (verify with Meta docs in Phase 13 research; OBO deprecation lesson is critical)
- [Laravel WhatsApp wrapper (MissaelAnda)](https://github.com/MissaelAnda/laravel-whatsapp) — LOW confidence (community package; Phase 13 needs to evaluate vs direct Meta Graph API)

---

*Architecture authored: 2026-04-24 — synthesised from 13-domain v1.50.1 ship-state for v2.0 Intelligence + B2B kickoff*
