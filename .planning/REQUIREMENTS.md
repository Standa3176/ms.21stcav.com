# Requirements: MeetingStore Ops v2.0 Intelligence + B2B

**Defined:** 2026-04-24
**Milestone goal:** Build an AI agent framework on top of v1's suggestions seam + audit log, and open the B2B revenue motion with trade pricing, quote flow, and conversational chat surfaces.

**Carry-forward operator decisions (locked at milestone kickoff):**
- Anthropic monthly budget: £200/month default; revisit after Phase 8 ships + 2 weeks of real usage
- Langfuse: self-hosted Docker on ops VPS (EU residency)
- Catalogue: ~5k SKUs assumption; MySQL FULLTEXT for E4; skip vector DB in v2

## v2 Requirements

Categories reflect the 8 capabilities scoped for v2.0. Every v1 invariant (suggestions seam, dual-YAML Deptrac, dry-run CLI, shadow-mode gates, `shield:safe-regenerate`, correlation_id threading, ULID PKs, listener-based extension) is preserved.

### C4. Agent Framework (AGNT) — Phase 8

- [x] **AGNT-01**: A `RunsAsAgent` contract defines the agent interface (method signatures: `execute(input): AgentResult`, `tools(): array<Tool>`, `systemPrompt(): string`, `guardrails(): array<Guardrail>`). Every agent implements this contract.
- [x] **AGNT-02**: `AgentRegistry` service resolves agent class by kind (`pricing`, `seo`, `chatbot`, `ad_optimisation`). Registered in `AppServiceProvider::register()`.
- [x] **AGNT-03**: Every agent run creates an `AgentRun` Eloquent model row with ULID PK, `kind`, `correlation_id`, `started_at`, `completed_at`, `status` enum, `input_hash`, `output_suggestion_ids` array, `langfuse_trace_id`, `token_usage`, `cost_pence`. Retained indefinitely for audit (overrides Phase 1 audit_log 365-day retention).
- [x] **AGNT-04**: `BudgetGuard` service enforces per-feature daily ceilings (config/agents.php: `pricing: 500 pence`, `seo: 300 pence`, `chatbot: 200 pence per session`, `ad_optimisation: 300 pence`) via atomic `Cache::add` counters. Exceeded ceiling throws `BudgetExceededException`; does not silently fail.
- [x] **AGNT-05**: `ToolBus` service routes agent tool-calls to named tool handlers with per-agent allow-list. Unknown tool names raise `UnauthorisedToolException`. Tool naming convention enforced: every tool starts with `propose*`, `read*`, or `search*` — NEVER `create*` / `update*` / `delete*` (architectural test `AgentToolsNamingTest`).
- [x] **AGNT-06**: `GuardrailEngine` chains pre-run guardrails (trust-tier input tagging, prompt-injection XML fencing, sensitive-fields strip) and post-run guardrails (outbound regex filter for internal SKU codes, email PII, internal hostnames). Guardrail failure shorts-circuits the run and writes a `crm_push_failed`-style suggestion with kind `agent_guardrail_blocked`.
- [x] **AGNT-07**: Prism-based `ClaudeClient` wraps `prism-php/prism` with default `model=claude-sonnet-4-6`, `temperature=0`, `withMaxSteps(8)`, `withMaxTokens(4000)`. All writes route through this client — no direct `Http::post` to Anthropic API allowed (Deptrac `Agents → Foundation[ClaudeClient]` only).
- [x] **AGNT-08**: `Langfuse` observability via `mliviu79/laravel-langfuse-prism` auto-instrumentation. Every Prism call traces to self-hosted Langfuse Docker instance with `trace_id` persisted on `AgentRun.langfuse_trace_id`. Custom-OTel middleware fallback documented in `docs/ops/observability.md` with cutover runbook.
- [x] **AGNT-09**: `agents` Horizon queue configured (tries=1, timeout=180s). No retry policy — agent runs are non-idempotent by nature of LLM outputs; failures surface as suggestions for human review via existing Phase 1 `ApplySuggestionJob` → DLQ pattern.
- [x] **AGNT-10**: Deptrac `Agents` layer added to BOTH `depfile.yaml` AND `deptrac.yaml` with allow-list `[Foundation, Suggestions, Products, Pricing, Competitor, CRM, ProductAutoCreate]` (read-only for data layers; writes only via Suggestions). `AgentsWriteOnlyViaSuggestionsTest` Pest architecture test asserts zero `DB::insert`/`update`/`delete` calls from `app/Domain/Agents/**`.
- [ ] **AGNT-11**: `shield:safe-regenerate` artisan command wraps `shield:generate --all` with automatic P5-F restoration (reads `app/Domain/*/Policies/*` via git checkout post-regen). Documented in `docs/ops/shield-regeneration.md`.
- [x] **AGNT-12**: `AGENT_WRITE_ENABLED` + `AGENT_AUTO_APPLY_ENABLED` env flags default false in `.env.example`. When `AGENT_WRITE_ENABLED=false`, agent runs complete but suggestions are marked `status=shadow` and not surfaced in Filament. When `AGENT_AUTO_APPLY_ENABLED=false`, approved agent suggestions still require human review click (no auto-apply regardless of guardrails).
- [x] **AGNT-13**: Filament `AgentRunResource` (admin-only) shows paginated AgentRun history with filters (kind, status, cost range, date range). Detail view shows input_hash, output suggestions (linked), Langfuse trace link, token usage, cost breakdown.

### C1. Pricing Agent (PRCAGT) — Phase 10

- [ ] **PRCAGT-01**: `PricingAgent implements RunsAsAgent` triggered when v1 `MarginAnalyser` produces a `margin_change` Suggestion with status `pending` AND agent hasn't run on that suggestion (`suggestions.evidence.agent_run_id` null). Agent enriches existing suggestion — never creates new margin_change rows.
- [ ] **PRCAGT-02**: Agent tools (all `read*` or `propose*`): `read_margin_history(sku)`, `read_competitor_prices(sku)`, `read_supplier_price_trend(sku)`, `read_sales_volume_90d(sku)`, `propose_margin_band(sku, proposed_bps, reasoning)`.
- [ ] **PRCAGT-03**: Agent output enriches the existing suggestion's `evidence` JSON with `agent_reasoning` (text), `agent_confidence_0_to_100`, `agent_proposed_band_min_bps`, `agent_proposed_band_max_bps`. Human approval workflow (existing Phase 5 `MarginChangeApplier`) unchanged.
- [ ] **PRCAGT-04**: Filament `SuggestionResource` margin_change detail view shows `agent_reasoning` + confidence badge + proposed band alongside v1's deterministic evidence. Approve action unchanged; rejection captures admin's note on whether agent reasoning was misleading (feeds back into prompt iteration).
- [ ] **PRCAGT-05**: Agent runs on `agents` queue. Budget ceiling `pricing_agent.daily_pence_cap=500`. Guardrails: trust-tier=`trusted` (admin-triggered only), no customer input, no external HTTP tools.

### C2. Ad Optimisation Agent (ADAGT) — Phase 15 (LATE — gates on post-cutover UTM data)

- [ ] **ADAGT-01**: `AdOptimisationAgent implements RunsAsAgent` triggered weekly by scheduler (`agents:run --kind=ad_optimisation`). Reads last 30 days of Bitrix Deal data filtered by UTM source/campaign presence + order totals.
- [ ] **ADAGT-02**: NEW `Marketing` Deptrac layer (separate from `Agents`) holds `UtmAttributionAnalyser` + `BudgetShiftProposalBuilder` services consumed by the agent and by Phase 7 dashboard widgets (non-agent readers).
- [ ] **ADAGT-03**: Agent tools: `read_deals_by_utm(campaign, window)`, `read_revenue_by_utm_source(window)`, `read_gclid_conversions(window)` (gated on Phase 15 pre-flight confirming GCLID capture on v1 side), `propose_budget_shift(campaign_id, delta_pence, reasoning)`.
- [ ] **ADAGT-04**: Suggestion kind `ad_budget_shift_proposal` with evidence: `current_spend_pence`, `current_cpa_pence`, `proposed_delta_pence`, `supporting_deal_ids`, `agent_reasoning`. Approval writes to a new `ad_budget_overrides` table (read by future ad-platform integration in v3+).
- [ ] **ADAGT-05**: Pre-flight gate: Phase 15 first task must verify GCLID capture in v1 order webhook payload OR ship a hotfix to Phase 4 CRM sync. If GCLID missing, agent falls back to utm_source-only attribution (documented degradation).

### C3. SEO / Content Agent (SEOAGT) — Phase 12

- [ ] **SEOAGT-01**: `SeoAgent implements RunsAsAgent` triggered when a Phase 6 AutoCreate draft enters `auto_create_status=pending_review` AND `completeness_score < 85`. Agent proposes content patches (title, short_description, long_description, meta_description) to raise the score.
- [ ] **SEOAGT-02**: Agent tools: `read_product_draft(sku)`, `read_brand_style_guide(brand)`, `read_similar_shipped_products(category, limit)`, `propose_content_patch(sku, field, before, after, reasoning)`.
- [ ] **SEOAGT-03**: Suggestion kind `seo_content_patch` surfaces in existing Phase 6 `AutoCreateReviewResource` as a sidebar panel per product. Approving a patch writes through v1 Phase 6 `ProductOverride` pin column (auto-pin the approved field so subsequent supplier sync preserves it).
- [ ] **SEOAGT-04**: Agent guardrail: outbound regex filter catches brand-voice violations (competitor brand names other than ours, absolute price claims without supplier data, marketing superlatives outside brand voice rules). Failed guardrail → suggestion kind `agent_guardrail_blocked` (does not surface to admin).
- [ ] **SEOAGT-05**: Budget ceiling `seo_agent.daily_pence_cap=300`. Batch-triggered: one scheduled run per night processes all eligible drafts in a single queue job (up to 20 drafts/run); remainder rolls to next night.

### E1. Trade Customer Pricing (TRDE) — Phase 9

- [ ] **TRDE-01**: Migration adds nullable `pricing_rules.customer_group_id BIGINT` column + new `customer_groups` table (id, name, slug, is_active). Seeded with Trade / Reseller / Education / NHS (default); seed list is ops-confirmable before Phase 9 planning.
- [ ] **TRDE-02**: `TradeRuleResolver` service decorates v1 `RuleResolver`. When called with a `customer_group_id`, resolver includes customer-group-scoped rules in specificity sort (`customer_group_id + brand + category` most specific); when null, falls through to v1 behaviour untouched.
- [ ] **TRDE-03**: Golden fixture extended from 50 → 80 triples. Original 50 triples remain byte-identical (regression guardrail `GoldenFixtureV1UnchangedTest`). New 30 triples cover customer-group scenarios including NULL handling, brand + customer-group, category + customer-group, override + customer-group.
- [ ] **TRDE-04**: `PricingRule` Filament Resource form gains optional `customer_group_id` Select field. Empty = retail rule (default). Group-scoped rules default to `priority + 100` so customer-group wins against same-scope retail rule.
- [ ] **TRDE-05**: Trade-pricing display strategy: admin-configurable (`config/b2b.php => 'anonymous_sees' => 'retail' | 'hidden'`). `retail` (proposed default) = anonymous users see retail prices; after login the Cart + PDP re-resolve using customer_group_id. `hidden` = anonymous users see "Login to see trade pricing". Phase 9 CONTEXT.md confirms operator choice.
- [ ] **TRDE-06**: Trade-only catalogue visibility NOT supported in v2.0 (all SKUs remain publicly visible). Documented as deferred.

### E2. Quote Request → Bitrix Deal Flow (QUOT) — Phase 11

- [ ] **QUOT-01**: `Quote` Eloquent model (ULID PK) with `customer_group_id`, `customer_email`, `customer_name`, `billing_address` JSON, `status` enum(`draft`, `pending_approval`, `approved`, `sent`, `accepted`, `rejected`, `expired`), `expires_at` (default today + 14 days), timestamps.
- [ ] **QUOT-02**: `QuoteLine` Eloquent model with `quote_id` FK, `sku`, `quantity_int`, `unit_price_pence_at_quote` (immutable snapshot at quote creation), `line_total_pence_at_quote`, `product_snapshot` JSON (title, brand, category at quote moment).
- [ ] **QUOT-03**: Filament `QuoteResource` (admin + pricing_manager + sales CRUD). Creating a quote selects customer + customer_group → resolves prices via `TradeRuleResolver` → snapshots each line. Line prices frozen on creation; subsequent PricingRule edits don't affect saved quotes.
- [ ] **QUOT-04**: `spatie/laravel-pdf` renders a quote PDF from `resources/views/pdf/quote.blade.php` with branded header, itemised lines, totals, expiry date, customer signature block (optional). PDF reads `unit_price_pence_at_quote` snapshots, never recomputes.
- [ ] **QUOT-05**: On quote approval (admin clicks Approve → Send), a `QuoteApproved` domain event fires. New listener `PushQuoteToBitrix` in v1 CRM domain dispatches `PushQuoteToBitrixDealJob` (onto `crm-bitrix` queue) that creates a Bitrix Deal of `TYPE_ID=QUOTE` with line items mapped via existing BitrixClient.
- [ ] **QUOT-06**: `QUOTE_BITRIX_PUSH_ENABLED` env flag default false (shadow-mode). When false, PushQuoteToBitrixDealJob serialises payload to `sync_diffs` with `provider='bitrix-quote'`. When true, pushes live. Operator flips manually after Phase 11 runbook rehearsal.
- [ ] **QUOT-07**: Quote dedup: quote re-send is idempotent via `Quote.id` as `UF_CRM_WOO_QUOTE_ID` on Bitrix Deal. Re-approving the same quote updates the Deal; does not create duplicate.
- [ ] **QUOT-08**: `quotes:expire` scheduled command flips `status=expired` for quotes past `expires_at`. Email to customer on expiry (optional, config-gated).

### E3. WhatsApp Business Channel (CHAT) — Phase 13

- [ ] **CHAT-01**: `WhatsAppConversation` Eloquent model (phone_number as natural key, `last_inbound_at`, `session_expires_at=last_inbound_at + 24h`, `customer_id` nullable FK, `opt_in_status` enum(`pending`, `opted_in`, `opted_out`), `opt_in_at`, `opt_in_source`).
- [ ] **CHAT-02**: WhatsApp inbound webhook at `/webhooks/whatsapp` verifies Meta signature via existing Phase 1 HMAC middleware pattern. Message dedup via `WebhookReceipt` (Phase 1 pattern) — `(source=whatsapp, delivery_id)` unique index.
- [ ] **CHAT-03**: Inbound message dispatches to `whatsapp-inbound` Horizon queue within 200ms (Phase 1 FOUND-07 pattern). `HandleWhatsAppInbound` listener routes by intent: `whatsapp:conversation_start` event for opt-in flow, `whatsapp:product_query` for chatbot routing (Phase 14 will subscribe), `whatsapp:quote_request` for direct human handoff.
- [ ] **CHAT-04**: Outbound `WhatsAppClient::sendFreeForm($phone, $text)` enforces 24h conversation window via `ConversationWindowGuard`. Throws `OutsideCustomerCareWindowException` if session_expires_at is past. NO arbitrary-text path bypasses the guard.
- [ ] **CHAT-05**: Outbound `WhatsAppClient::sendTemplate($phone, $template_id, $variables)` enforces template pre-registration via `WhatsAppTemplateRegistry` (synced hourly from Meta via `whatsapp:sync-templates` scheduled command). Un-registered template ID throws `UnapprovedTemplateException`.
- [ ] **CHAT-06**: `WHATSAPP_OUTBOUND_ENABLED` env flag default false. When false, outbound messages log to `integration_events` with direction=outbound-shadow. When true, sends via netflie/whatsapp-cloud-api.
- [ ] **CHAT-07**: `whatsapp:contacts-export` GDPR erasure command scrubs a customer's conversation history (hashes phone, redacts message bodies) on right-to-erasure request. Mirrors v1 `gdpr:erase-bitrix-customer` pattern.
- [ ] **CHAT-08**: Marketing-template broadcasts NOT supported in v2.0 (deferred to v2.1). Phase 13 scope is inbound + 24h-window free-form outbound only. Documented in Phase 13 VERIFICATION.md deferred section.

### E4. AI Product-Finder Chatbot (FIND) — Phase 14

- [ ] **FIND-01**: Public REST endpoint `POST /api/chat/message` accepts `{session_id, message, context}` and returns `{session_id, reply, suggested_products}`. Rate-limited by Laravel `throttle:api-chat` middleware (10/min anonymous, 100/min authenticated customer).
- [ ] **FIND-02**: `ProductFinderAgent implements RunsAsAgent` consumes message + session context; tools: `search_products_fulltext(query, limit=10)`, `read_product_detail(sku)`, `read_stock_status(sku)`, `propose_quote(skus[], customer_email)`.
- [ ] **FIND-03**: MySQL FULLTEXT index on `products.name + products.short_description + products.long_description + products.brand_name + products.sku` (catalogue ≤ 5k SKU assumption documented; vector DB deferred per operator decision).
- [ ] **FIND-04**: Chatbot session state: `ChatbotSession` model (ULID PK, `session_token`, `started_at`, `last_activity_at`, `message_count`, `token_usage_total`, `customer_id` nullable on authenticated, `client_ip_hash`). Anonymous PII storage posture: phone/email collected only when customer explicitly requests quote; hashed at rest.
- [ ] **FIND-05**: Guardrails: prompt-injection defence (trust-tier=`untrusted`, XML-fenced customer input, no internal SKU codes in output), outbound regex filter (internal hostnames, admin email domains, competitor brand names). Budget ceiling `chatbot.daily_pence_cap=200 per session`. Session ends on cap exceed.
- [ ] **FIND-06**: Filament `ChatbotSessionResource` (admin-only, read-only) shows session history + agent reasoning for post-mortem analysis. Sessions retained 90 days then pruned via `chatbot:prune-sessions` scheduled command (matches v1 `integration_events` retention pattern).

## v2.1+ Future Requirements (deferred)

- Channel feeds (Google Merchant Center, Google Ads sync, GA4 revenue, Meta catalog, GSC)
- Customer automation (abandoned-cart recovery, back-in-stock alerts, price-drop alerts, review aggregation)
- Forecasting (stock planning, sales forecasting, supplier performance dashboard, true profitability per SKU)
- E5 RAMS integration hook (cross-project FK to rams.21stcav.com)
- WhatsApp marketing-template broadcasts (v2.0 is inbound + 24h-window outbound only)
- Trade-only catalogue visibility (v2.0 all SKUs publicly visible)
- E4 vector DB (pgvector / Meilisearch) — re-evaluate at v2.1 if MySQL FULLTEXT recall poor
- Per-brand content templates for SEO agent
- Fuzzy MPN matching for duplicate detection
- Auto-publish for whitelisted brands in AutoCreate
- Product variation auto-creation
- Quote e-signature integration (DocuSign / Bitrix Sign)
- Provider abstraction in C4 (Claude-only in v2.0; OpenAI/Gemini fallback deferred)
- Agent auto-apply mode (`AGENT_AUTO_APPLY_ENABLED` stays off by default even post-cutover)

## Out of Scope (confirmed)

Inherited from v1 plus v2.0-specific:

| Feature | Reason |
|---------|--------|
| Two-way CRM sync (Bitrix24 → Woo) | Confirmed v1 out-of-scope |
| WooCommerce checkout replacement | Woo remains shop frontend |
| Customer-facing admin portal | Internal ops only |
| Multi-store support | Single store (meetingstore.co.uk) |
| Direct agent DB writes | Architectural prohibition; all agent outputs via suggestions seam |
| Agent tool-use loops beyond `withMaxSteps(8)` | Hard token-cost defence |
| Non-deterministic agent temperature for pricing | temp=0 for audit-critical PricingAgent; higher allowed for SEO/Chatbot with guardrails |
| WhatsApp marketing broadcasts in v2.0 | Needs opt-in funnel + Meta template approval + GDPR review; v2.1 candidate |
| Raw text outbound on WhatsApp outside 24h window | Non-bypassable policy — WABA single-strike |
| Real-time WebSocket chat for E4 | Polling REST API sufficient for v2 scale |
| Vector DB in v2.0 | MySQL FULLTEXT on ~5k SKUs; revisit v2.1 |

## Traceability

Populated by `/gsd-roadmap` at milestone initialisation; status advances as plans land and phases verify.

| Requirement | Phase | Status |
|-------------|-------|--------|
| AGNT-01 | Phase 8 | Complete |
| AGNT-02 | Phase 8 | Complete |
| AGNT-03 | Phase 8 | Complete |
| AGNT-04 | Phase 8 | Complete |
| AGNT-05 | Phase 8 | Complete |
| AGNT-06 | Phase 8 | Complete |
| AGNT-07 | Phase 8 | Complete |
| AGNT-08 | Phase 8 | Complete |
| AGNT-09 | Phase 8 | Complete |
| AGNT-10 | Phase 8 | Complete |
| AGNT-11 | Phase 8 | Pending |
| AGNT-12 | Phase 8 | Complete |
| AGNT-13 | Phase 8 | Complete |
| TRDE-01 | Phase 9 | Pending |
| TRDE-02 | Phase 9 | Pending |
| TRDE-03 | Phase 9 | Pending |
| TRDE-04 | Phase 9 | Pending |
| TRDE-05 | Phase 9 | Pending |
| TRDE-06 | Phase 9 | Pending |
| PRCAGT-01 | Phase 10 | Pending |
| PRCAGT-02 | Phase 10 | Pending |
| PRCAGT-03 | Phase 10 | Pending |
| PRCAGT-04 | Phase 10 | Pending |
| PRCAGT-05 | Phase 10 | Pending |
| QUOT-01 | Phase 11 | Pending |
| QUOT-02 | Phase 11 | Pending |
| QUOT-03 | Phase 11 | Pending |
| QUOT-04 | Phase 11 | Pending |
| QUOT-05 | Phase 11 | Pending |
| QUOT-06 | Phase 11 | Pending |
| QUOT-07 | Phase 11 | Pending |
| QUOT-08 | Phase 11 | Pending |
| SEOAGT-01 | Phase 12 | Pending |
| SEOAGT-02 | Phase 12 | Pending |
| SEOAGT-03 | Phase 12 | Pending |
| SEOAGT-04 | Phase 12 | Pending |
| SEOAGT-05 | Phase 12 | Pending |
| CHAT-01 | Phase 13 | Pending |
| CHAT-02 | Phase 13 | Pending |
| CHAT-03 | Phase 13 | Pending |
| CHAT-04 | Phase 13 | Pending |
| CHAT-05 | Phase 13 | Pending |
| CHAT-06 | Phase 13 | Pending |
| CHAT-07 | Phase 13 | Pending |
| CHAT-08 | Phase 13 | Pending |
| FIND-01 | Phase 14 | Pending |
| FIND-02 | Phase 14 | Pending |
| FIND-03 | Phase 14 | Pending |
| FIND-04 | Phase 14 | Pending |
| FIND-05 | Phase 14 | Pending |
| FIND-06 | Phase 14 | Pending |
| ADAGT-01 | Phase 15 | Pending (LATE — gates on v1 cutover + ~4 weeks UTM data) |
| ADAGT-02 | Phase 15 | Pending (LATE — gates on v1 cutover + ~4 weeks UTM data) |
| ADAGT-03 | Phase 15 | Pending (LATE — gates on v1 cutover + ~4 weeks UTM data) |
| ADAGT-04 | Phase 15 | Pending (LATE — gates on v1 cutover + ~4 weeks UTM data) |
| ADAGT-05 | Phase 15 | Pending (LATE — gates on v1 cutover + ~4 weeks UTM data) |

**Coverage:**
- v2 requirements: 56 total (13 AGNT + 5 PRCAGT + 5 ADAGT + 5 SEOAGT + 6 TRDE + 8 QUOT + 8 CHAT + 6 FIND)
- Mapped to phases: 56
- Unmapped: 0 ✓
- Duplicates: 0 ✓

---
*Requirements defined: 2026-04-24 after v1.50.1 shipped. Operator decisions locked: Anthropic £200/month budget default, self-hosted Langfuse Docker, ~5k SKU MySQL FULLTEXT assumption.*
*Traceability table expanded by `/gsd-roadmap`: 2026-04-24 — every REQ-ID mapped to exactly one phase (8 phases, 56 requirements, 100% coverage).*
