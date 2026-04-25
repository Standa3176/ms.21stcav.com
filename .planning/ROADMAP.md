# Roadmap: MeetingStore Ops

## Milestones

- âœ… **v1.50.1 v1 Framework** â€” Phases 1-7 (shipped 2026-04-24)
- ðŸŸ¢ **v2.0 Intelligence + B2B** â€” Phases 8-15 (planning kicked off 2026-04-24)

See `.planning/milestones/v1.50.1-ROADMAP.md` for full v1 phase details.

## Phases

<details>
<summary>âœ… v1.50.1 v1 Framework (Phases 1-7) â€” SHIPPED 2026-04-24</summary>

- [x] Phase 1: Foundation (5/5 plans) â€” Laravel 12 + Filament 3 + Horizon skeleton with Domain/ layout, audit/integration/suggestions seams, RBAC, HMAC webhook middleware, WOO_WRITE_ENABLED shadow-mode flag
- [x] Phase 2: Supplier Sync (5/5 plans) â€” Daily resumable supplier pull, per-item Woo REST push with error capture, emailed CSV report, Filament sync-status + import-issues pages
- [x] Phase 3: Pricing Engine (5/5 plans) â€” Most-specific-wins PricingRule resolver, integer-pennies VAT-inclusive calculator, per-product overrides, golden-fixture parity test against legacy plugin
- [x] Phase 4: Bitrix24 CRM Sync (5/5 plans) â€” One-way Wooâ†’Bitrix push of Deal + Contact + Company, dynamic field mapping, UTM/GA capture, backfill command, GDPR erasure
- [x] Phase 5: Competitor Analysis (6/6 plans) â€” CSV watcher with BOM-safe ingest, full-history competitor_prices, margin-delta analyser producing Suggestions, trend/deltas dashboards
- [x] Phase 6: Product Auto-Create (6/6 plans) â€” New-SKU detection, SEO-templated draft Woo products, image pipeline + placeholder flow, review inbox with completeness scoring, ProductOverride pin UI
- [x] Phase 7: Dashboard Polish + Cutover (6/6 plans) â€” Home health tiles, notification centre, global search, weekly reports, shadow-mode divergence scan, legacy-plugin crons deregistered, rollback drill, ops handover

</details>

### ðŸŸ¢ v2.0 Intelligence + B2B (Phases 8-15) â€” ACTIVE

**Milestone goal:** Build a Claude-driven AI agent framework atop v1's suggestions seam + audit log, and open the B2B revenue motion with trade pricing, quote-to-Bitrix flow, and conversational chat surfaces (WhatsApp inbound + AI product-finder).

**Net-additive architecture:** 5 new Deptrac layers (`Agents`, `TradePricing`, `Quotes`, `Channels`, `Marketing`), 2 new Horizon queues (`agents` + `whatsapp-inbound`), 4 required composer packages, **zero version bumps to v1's stack**. v1 is frozen and only extended via the Suggestions seam, listener-based extension, and decorator pattern.

**Build order is dependency-forced:**

- [ ] **Phase 8: C4 Agent Framework** â€” Greenfield agent infrastructure (registry, ToolBus, GuardrailEngine, ClaudeClient, AgentRun model, agents queue, suggestion provenance morph, shield:safe-regenerate). Blocks all agent work.
- [ ] **Phase 9: E1 Trade Customer Pricing** â€” Decorator-pattern `TradeRuleResolver` extending v1 RuleResolver. Adds `pricing_rules.customer_group_id` + `customer_groups` table. Golden fixture extended 50â†’80 triples (original 50 byte-identical).
- [ ] **Phase 10: C1 Pricing Agent** â€” First concrete agent. Enriches existing `margin_change` Suggestions with LLM reasoning + confidence band. Validates the Phase 8 framework end-to-end with low blast radius.
- [ ] **Phase 11: E2 Quote Request â†’ Bitrix Deal Flow** â€” `Quote` + `QuoteLine` ULID models with snapshotted prices, Filament admin CRUD, PDF rendering via spatie/laravel-pdf, listener-based push to Bitrix Deal type=QUOTE.
- [ ] **Phase 12: C3 SEO / Content Agent** â€” Plug-and-play with Phase 6 AutoCreate review inbox. Proposes content patches for low-completeness drafts. Approved patches auto-pin via `ProductOverride.pin_*` columns.
- [ ] **Phase 13: E3 WhatsApp Business Channel** â€” Inbound webhook (Meta HMAC) + 24h-window outbound free-form + template registry. Marketing-template broadcasts deferred to v2.1. New `whatsapp-inbound` queue.
- [ ] **Phase 14: E4 AI Product-Finder Chatbot** â€” Public REST endpoint (`/api/chat/message`) + `ProductFinderAgent` over MySQL FULLTEXT (~5k SKUs assumption). Anonymous PII posture: phone/email captured only on explicit quote request, hashed at rest.
- [ ] **Phase 15: C2 Ad Optimisation Agent** â€” LATE phase. Reads Bitrix Deal UTM/GCLID data accumulated post-cutover. New `Marketing` Deptrac layer separate from `Agents`. Pre-flight: GCLID capture verified or v1 hotfix shipped.

## Phase Details

### Phase 8: C4 Agent Framework
**Goal**: Greenfield Claude-driven agent infrastructure exists, can run a registered agent end-to-end through Suggestions, and forbids any agent from writing directly to v1 domains.
**Depends on**: Nothing in v2 (net-new domain). Reuses v1 Suggestions seam + Auditor + IntegrationLogger + Horizon + Shield.
**Requirements**: AGNT-01, AGNT-02, AGNT-03, AGNT-04, AGNT-05, AGNT-06, AGNT-07, AGNT-08, AGNT-09, AGNT-10, AGNT-11, AGNT-12, AGNT-13
**Success Criteria** (what must be TRUE):
  1. An admin can register a stub agent (`RunsAsAgent` contract) and trigger it via `php artisan agent:run <kind> --dry-run`; the run completes through the `agents` Horizon queue, persists an `AgentRun` row with token usage + cost in pence + Langfuse trace ID, and produces a shadow-mode Suggestion that is NOT yet visible in the Filament inbox (because `AGENT_WRITE_ENABLED=false` by default).
  2. The architectural test `AgentsWriteOnlyViaSuggestionsTest` fails the build when a test PR introduces a `DB::insert/update/delete` or `::create()/->save()` call from `app/Domain/Agents/**` outside `Models/AgentRun*`. The Deptrac `Agents` layer is registered in BOTH `depfile.yaml` AND `deptrac.yaml` with read-only allow-list to data domains.
  3. A `BudgetGuard` ceiling breach (e.g. `pricing: 500 pence/day` exceeded by an admin-triggered run) raises `BudgetExceededException` audibly â€” the agent run row records `status=budget_exceeded`, the operator sees a notification, and no further pricing-agent runs dispatch until midnight rollover.
  4. A guardrail violation (prompt-injection attempt, sensitive-field leak in agent output, unauthorised tool name) shorts-circuits the run and writes an `agent_guardrail_blocked` Suggestion (does not surface to admin); the violation reason is captured in `AgentRun.guardrail_failures` JSON.
  5. The `shield:safe-regenerate` artisan command wraps `shield:generate --all` with automatic P5-F restoration, runs `PolicyTemplateIntegrityTest` post-restoration, and exits 1 if any `{{ Placeholder }}` literal remains. Documented in `docs/ops/shield-regeneration.md`.
  6. The Filament `AgentRunResource` (admin-only) shows paginated AgentRun history with filters (kind, status, cost range, date range) and a detail view linking to the Langfuse trace, the produced Suggestion(s), and a token usage / cost breakdown.
**Plans**: 5 plans (waves 1â€”5; linear chain â€” each wave consumes prior wave's contracts)
  - [ ] 08-01-PLAN.md â€” Foundation: AgentRun model + 14-col schema + 4 enums + Deptrac Agents layer (dual-YAML) + agents-supervisor + 3 architecture tests
  - [ ] 08-02-PLAN.md â€” ClaudeClient + Prism + Langfuse Docker compose + observability runbook (composer adds prism-php/prism + mliviu79/laravel-langfuse-prism â€” zero v1 bumps)
  - [ ] 08-03-PLAN.md â€” RunsAsAgent contract + AgentRegistry + BudgetGuard (atomic Cache::add) + ToolBus + GuardrailEngine + 3 guardrails + AgentSuggestionWriter + 4 exceptions
  - [ ] 08-04-PLAN.md â€” EchoAgent + ReadHealthCheckTool + RunAgentJob + agent:run CLI + Filament AgentRunResource + Prism::fake() E2E test
  - [ ] 08-05-PLAN.md â€” shield:safe-regenerate + AgentRunGdprScrubber + agents:prune-archive + agents:gdpr-purge-langfuse stub + 08-VERIFICATION.md ship-verdict
**Research flag**: YES â€” Prism API surface verified, Langfuse self-hosted Docker compose verified, shield:safe-regenerate designed (08-RESEARCH.md complete)

### Phase 9: E1 Trade Customer Pricing
**Goal**: Trade customers (linked to a `customer_group`) resolve to customer-group-scoped pricing rules; retail customers (NULL group) reach v1 pricing behaviour bit-for-bit.
**Depends on**: Phase 3 (v1 PricingRule + RuleResolver â€” decorator wraps it). Parallel to Phase 8 (no agent dependency).
**Requirements**: TRDE-01, TRDE-02, TRDE-03, TRDE-04, TRDE-05, TRDE-06
**Success Criteria** (what must be TRUE):
  1. `pricing_rules.customer_group_id` (nullable BIGINT) + `customer_groups` table (id, name, slug, is_active) ship via migration. Seed list (Trade / Reseller / Education / NHS) is operator-confirmable in CONTEXT.md before plan 01 lands.
  2. The golden-fixture regression test `GoldenFixtureV1UnchangedTest` passes byte-identical against the original 50 v1 triples â€” proving retail behaviour is untouched. A new `GoldenFixtureV2TradeTest` covers 30 new customer-group triples (NULL handling, brand + customer-group, category + customer-group, override + customer-group, two groups same-rule tiebreak).
  3. Calling `PriceCalculator->priceFor($product, customerGroupId: null)` reaches `RuleResolver::resolve()` directly (verified by Pest spy); calling with a concrete group ID reaches `TradeRuleResolver::resolve()` first which prefers customer-group-scoped rules at `priority + 100` and falls through to the v1 base when no group rule matches.
  4. The Filament `PricingRule` resource form gains an optional `customer_group_id` Select field. Empty = retail rule (existing default). Group-scoped rules respect the priority bias so customer-group wins against same-scope retail.
  5. Trade-pricing display strategy is admin-configurable via `config/b2b.php => 'anonymous_sees' => 'retail' | 'hidden'` (default `retail`); operator confirms choice in Phase 9 CONTEXT.md. Trade-only catalogue visibility is documented as deferred (TRDE-06).
**Plans**: TBD

### Phase 10: C1 Pricing Agent
**Goal**: Pricing agent enriches v1's existing `margin_change` Suggestions with LLM reasoning + confidence band â€” never replaces the deterministic qualifier, never creates new margin_change rows on its own.
**Depends on**: Phase 8 (agent framework â€” registry, ToolBus, GuardrailEngine, ClaudeClient, BudgetGuard, AGENT_WRITE_ENABLED gate)
**Requirements**: PRCAGT-01, PRCAGT-02, PRCAGT-03, PRCAGT-04, PRCAGT-05
**Success Criteria** (what must be TRUE):
  1. When v1's `MarginAnalyser` produces a `margin_change` Suggestion with status `pending` AND no `evidence.agent_run_id`, the `PricingAgent` runs on the `agents` queue (admin-triggered only â€” trust-tier=`trusted`); the agent NEVER fires on already-enriched suggestions (idempotency verified by Pest test).
  2. The agent tool set is read-only or `propose*` only: `read_margin_history(sku)`, `read_competitor_prices(sku)`, `read_supplier_price_trend(sku)`, `read_sales_volume_90d(sku)`, `propose_margin_band(sku, proposed_bps, reasoning)`. No `create*`/`update*`/`delete*` tool â€” enforced by `AgentToolsNamingTest`.
  3. The existing Suggestion's `evidence` JSON is enriched with `agent_reasoning` (text), `agent_confidence_0_to_100`, `agent_proposed_band_min_bps`, `agent_proposed_band_max_bps`. The Filament `SuggestionResource` margin_change detail view renders the agent reasoning + confidence badge + proposed band alongside v1's deterministic evidence.
  4. The existing Phase 5 `MarginChangeApplier` approve/reject workflow is unchanged â€” admin still approves the v1-deterministic suggestion; rejection captures admin's note on whether agent reasoning was misleading (feeds back into prompt iteration).
  5. The `pricing_agent.daily_pence_cap=500` ceiling holds in production: a synthetic test triggering 6 runs (each ~100p) on the same day causes the 6th to fail with `BudgetExceededException`; the operator sees the breach in the Filament `AgentRunResource`.
**Plans**: TBD
**Research flag**: YES â€” prompt design, deterministic temp=0 calibration, token budget across input contexts, Langfuse trace shape

### Phase 11: E2 Quote Request â†’ Bitrix Deal Flow
**Goal**: Sales/pricing manager creates a quote with snapshotted line prices that survive subsequent PricingRule edits; on approval the quote pushes idempotently to a Bitrix Deal of `TYPE_ID=QUOTE` (shadow-mode default false; operator-flippable).
**Depends on**: Phase 9 (customer_group_id resolution via `TradeRuleResolver` for accurate trade pricing on the quote) + Phase 4 (v1 CRM domain owns `BitrixClient` + `BitrixEntityMap` dedup ledger)
**Requirements**: QUOT-01, QUOT-02, QUOT-03, QUOT-04, QUOT-05, QUOT-06, QUOT-07, QUOT-08
**Success Criteria** (what must be TRUE):
  1. `Quote` (ULID PK) + `QuoteLine` Eloquent models ship with `unit_price_pence_at_quote` + `line_total_pence_at_quote` + `product_snapshot` JSON immutably set at quote-creation. Pest test `QuotePdfPriceImmunityTest` creates a quote, edits the underlying PricingRule, regenerates the PDF, and asserts the rendered prices match the original snapshot.
  2. The Filament `QuoteResource` (admin + pricing_manager + sales CRUD) walks: select customer + customer_group â†’ resolve prices via `TradeRuleResolver` â†’ snapshot each line â†’ save. Subsequent edits to `PricingRule` rows do not affect saved quotes.
  3. The quote PDF (`spatie/laravel-pdf` v2.7 via DOMPDF driver) renders with branded header, itemised lines, totals, expiry date, and an optional customer signature block. PDF reads `unit_price_pence_at_quote` snapshots, never re-resolves.
  4. With `QUOTE_BITRIX_PUSH_ENABLED=false` (default), approving a quote dispatches `PushQuoteToBitrixDealJob` to `crm-bitrix` queue which serialises the payload to `sync_diffs` with `provider='bitrix-quote'`; with `QUOTE_BITRIX_PUSH_ENABLED=true`, the same approval pushes a real Bitrix Deal of `TYPE_ID=QUOTE` with `UF_CRM_WOO_QUOTE_ID=Quote.id`.
  5. Quote re-approval is idempotent: a second approval of the same `Quote.id` updates the existing Bitrix Deal (matched on `UF_CRM_WOO_QUOTE_ID`) and does not create a duplicate. Verified by integration test against a Bitrix sandbox or HTTP fake.
  6. The `quotes:expire` scheduled command flips `status=expired` for quotes past `expires_at` (default `created_at + 14d`); an optional config-gated email notifies the customer. The status transition is auditable in `activity_log`.
**Plans**: TBD
**Research flag**: MAYBE â€” Bitrix Deal line-item modelling for 30-line quotes (custom-fields strategy vs Bitrix Estimate API surface)
**UI hint**: yes

### Phase 12: C3 SEO / Content Agent
**Goal**: SEO agent proposes content patches (title, descriptions, meta_description) for low-completeness Phase 6 AutoCreate drafts; approved patches auto-pin via `ProductOverride` so subsequent supplier sync preserves them.
**Depends on**: Phase 8 (agent framework) + Phase 6 (v1 AutoCreate review inbox + `auto_create_status=pending_review` + `completeness_score` + `ProductOverride.pin_*` columns)
**Requirements**: SEOAGT-01, SEOAGT-02, SEOAGT-03, SEOAGT-04, SEOAGT-05
**Success Criteria** (what must be TRUE):
  1. Nightly batch run processes up to 20 eligible drafts (`auto_create_status=pending_review` AND `completeness_score < 85`) per scheduled invocation; the remainder rolls to the next night. Operator can verify scheduling via `php artisan schedule:list`.
  2. The agent tool set: `read_product_draft(sku)`, `read_brand_style_guide(brand)`, `read_similar_shipped_products(category, limit)`, `propose_content_patch(sku, field, before, after, reasoning)`. Each `propose_content_patch` writes a Suggestion of kind `seo_content_patch` linked to the `AgentRun.id`.
  3. The existing Phase 6 `AutoCreateReviewResource` gains a sidebar panel showing per-product agent-proposed patches alongside the v1 completeness score. Approving a patch via the existing Filament action writes through to `ProductOverride` with the matching `pin_*` field set true (auto-pin).
  4. The agent's outbound regex guardrail catches brand-voice violations (competitor brand names other than ours, absolute price claims without supplier evidence, marketing superlatives outside brand-voice rules). A failed guardrail produces a kind `agent_guardrail_blocked` Suggestion (does not surface to admin) and an audit row in `AgentRun.guardrail_failures`.
  5. Daily spend stays under `seo_agent.daily_pence_cap=300` across normal volume (â‰¤20 drafts/run Ã— 1 nightly run). A synthetic test exceeding the cap triggers `BudgetExceededException`, halts the batch mid-run, and emits a notification to ops.
**Plans**: TBD
**Research flag**: MAYBE â€” content diff format for the patch; brand-voice regex pattern library
**UI hint**: yes

### Phase 13: E3 WhatsApp Business Channel
**Goal**: Inbound WhatsApp messages from customers are HMAC-verified, deduped, and routed by intent; outbound free-form replies are gated by a non-bypassable 24h customer-care window guard; outbound templates require pre-registered approved Meta template IDs.
**Depends on**: Phase 1 (v1 webhook HMAC middleware + `WebhookReceipt` dedup + `webhook-inbound` queue patterns). The WhatsApp HMAC scheme (`X-Hub-Signature-256`) reuses the v1 middleware shape with a new app-secret env var.
**Requirements**: CHAT-01, CHAT-02, CHAT-03, CHAT-04, CHAT-05, CHAT-06, CHAT-07, CHAT-08
**Success Criteria** (what must be TRUE):
  1. A signed Meta WhatsApp inbound webhook posted to `/webhooks/whatsapp` with a valid `X-Hub-Signature-256` HMAC creates a `WebhookReceipt` with `(source='whatsapp', delivery_id=<meta_message_id>)` UNIQUE-deduped, dispatches `HandleWhatsAppInbound` to the `whatsapp-inbound` queue within 200ms, and ACKs Meta with HTTP 200 (no retries triggered).
  2. The `HandleWhatsAppInbound` listener finds-or-creates `WhatsAppConversation` (phone_number natural key + `last_inbound_at` + `session_expires_at = last_inbound_at + 24h`) and routes by intent: `whatsapp:conversation_start` for opt-in flow, `whatsapp:product_query` (Phase 14 subscriber), `whatsapp:quote_request` for human handoff.
  3. Calling `WhatsAppClient::sendFreeForm($phone, $text)` after `session_expires_at` is past throws `OutsideCustomerCareWindowException` â€” non-bypassable. Pest test stubs the conversation 25h after last inbound and asserts the throw. There is NO arbitrary-text outbound code path in the codebase (CI grep enforces).
  4. Calling `WhatsAppClient::sendTemplate($phone, $template_id, $variables)` against an unregistered/unapproved template ID throws `UnapprovedTemplateException`. The `whatsapp:sync-templates` scheduled command pulls Meta-approved templates hourly into `whatsapp_templates` table; un-approved templates are auto-disabled.
  5. With `WHATSAPP_OUTBOUND_ENABLED=false` (default), outbound messages log to `integration_events` with `direction=outbound-shadow` and do not reach Meta; with `true`, they send via `netflie/whatsapp-cloud-api` ^3.1. Operator flips manually after Phase 13 runbook rehearsal.
  6. The `whatsapp:contacts-export` GDPR erasure command scrubs a customer's conversation history (hashes phone, redacts message bodies) on right-to-erasure request, mirroring v1 `gdpr:erase-bitrix-customer` shape; the action is recorded in `gdpr_erasure_log`.
  7. Marketing-template broadcasts are explicitly NOT supported in v2.0 â€” verified by code grep + documented in Phase 13 VERIFICATION.md deferred section.
**Plans**: TBD
**Research flag**: YES â€” WABA setup checklist, Meta OBO BSP deprecation impact (2026), 24h window state-machine edge cases (rolling vs hard-cliff), per-number tier rate limits

### Phase 14: E4 AI Product-Finder Chatbot
**Goal**: Public REST chatbot endpoint that finds products by natural-language query (MySQL FULLTEXT over ~5k SKUs), respects rate limits + anonymous PII posture, and can hand off to a quote via the Phase 11 quote flow.
**Depends on**: Phase 8 (agent framework) + Phase 11 (`propose_quote` tool â€” quote-from-chat handoff) + Phase 13 (optional WhatsApp surface; the chatbot is reachable both via REST and WhatsApp inbound product_query intent)
**Requirements**: FIND-01, FIND-02, FIND-03, FIND-04, FIND-05, FIND-06
**Success Criteria** (what must be TRUE):
  1. `POST /api/chat/message` accepts `{session_id, message, context}` and returns `{session_id, reply, suggested_products}`. Anonymous rate limit `throttle:api-chat` is 10/min; authenticated customer is 100/min. Limits exercised by Pest test against Laravel's `RateLimiter`.
  2. The `ProductFinderAgent` consumes message + session context with tool set: `search_products_fulltext(query, limit=10)`, `read_product_detail(sku)`, `read_stock_status(sku)`, `propose_quote(skus[], customer_email)`. The MySQL FULLTEXT index spans `products.name + short_description + long_description + brand_name + sku` (catalogue â‰¤ 5k SKU assumption documented; vector DB deferred).
  3. The `ChatbotSession` model (ULID PK, session_token, started_at, last_activity_at, message_count, token_usage_total, customer_id nullable, client_ip_hash) tracks per-session state. Phone/email PII is collected ONLY when the customer explicitly requests a quote, and is hashed at rest.
  4. Guardrails are layered: trust-tier=`untrusted` for customer messages, XML-fenced input, no internal SKU codes in output, outbound regex filter (internal hostnames, admin email domains, competitor brand names). Prompt-injection corpus regression test passes 50/50 documented jailbreak prompts.
  5. The `chatbot.daily_pence_cap=200 per session` ceiling is enforced â€” a session reaching the cap receives a graceful "we'll continue tomorrow or via email" reply and the session is closed; new sessions can start. Verified by Pest test exhausting the cap on a synthetic session.
  6. The Filament `ChatbotSessionResource` (admin-only, read-only) renders session history + agent reasoning trace for post-mortem analysis. Sessions are pruned after 90 days via `chatbot:prune-sessions` scheduled command (matches v1 `integration_events` 90-day retention).
**Plans**: TBD
**Research flag**: MAYBE â€” rate-limit calibration against real expected traffic, anonymous PII posture clarification, FULLTEXT recall sanity check on actual catalogue subset
**UI hint**: yes

### Phase 15: C2 Ad Optimisation Agent (LATE)
**Goal**: Weekly-scheduled agent reads the last 30 days of Bitrix Deal data filtered by UTM source/campaign + GCLID, proposes ad-spend budget shifts as Suggestions, and writes approved shifts to a new `ad_budget_overrides` table for future ad-platform integration.
**Depends on**: Phase 8 (agent framework) + **v1 cutover LIVE in production for â‰¥4 weeks** so real Bitrix Deal data with populated UTM/GCLID fields has accumulated. **DO NOT begin Phase 15 planning until ops confirms the cutover monitoring window has passed AND a sample query against Bitrix returns â‰¥4 weeks of UTM-tagged Deals.** Phase 15's pre-flight (ADAGT-05) verifies GCLID capture in the v1 order webhook payload OR ships a hotfix to Phase 4 CRM sync first.
**Requirements**: ADAGT-01, ADAGT-02, ADAGT-03, ADAGT-04, ADAGT-05
**Success Criteria** (what must be TRUE):
  1. The `AdOptimisationAgent` runs weekly via `agents:run --kind=ad_optimisation` scheduler; reads the last 30 days of Bitrix Deal data filtered by UTM source/campaign presence + order totals via the new `Marketing` Deptrac layer (separate from `Agents`).
  2. The new `Marketing` Deptrac layer holds `UtmAttributionAnalyser` + `BudgetShiftProposalBuilder` services consumed by both the agent and Phase 7 dashboard widgets (non-agent readers). Layer is registered in BOTH `depfile.yaml` AND `deptrac.yaml`.
  3. The agent tool set: `read_deals_by_utm(campaign, window)`, `read_revenue_by_utm_source(window)`, `read_gclid_conversions(window)` (gated on Phase 15 pre-flight confirming GCLID capture), `propose_budget_shift(campaign_id, delta_pence, reasoning)`.
  4. Approval of a `ad_budget_shift_proposal` Suggestion writes to a new `ad_budget_overrides` table with `current_spend_pence`, `current_cpa_pence`, `proposed_delta_pence`, `supporting_deal_ids` JSON, `agent_reasoning`, `applied_at`. Future ad-platform integration (v3+) reads this table.
  5. The Phase 15 pre-flight gate (ADAGT-05) is a documented operator decision: GCLID is verified present in the v1 order webhook payload sample OR a hotfix to Phase 4 CRM sync ships first. If GCLID is missing and not hotfixable in-window, the agent falls back to `utm_source`-only attribution (documented degradation in CONTEXT.md).
**Plans**: TBD
**Research flag**: LATE â€” gates on v1 cutover going live AND â‰¥4 weeks of UTM data accumulating; do not pre-research; v1 GCLID capture hotfix may be required first

## Progress

**Execution Order:**
- v1.50.1: Phases 1 â†’ 2 â†’ 3 â†’ 4 â†’ 5 â†’ 6 â†’ 7 (complete)
- v2.0: Phases 8 â†’ 9 (parallel) â†’ 10 â†’ 11 â†’ 12 â†’ 13 â†’ 14 â†’ 15 (LATE â€” gates on cutover + 4 weeks UTM data)

| Phase | Milestone | Plans Complete | Status | Completed |
|-------|-----------|----------------|--------|-----------|
| 1. Foundation | v1.50.1 | 5/5 | Complete | 2026-04-18 |
| 2. Supplier Sync | v1.50.1 | 5/5 | Complete | 2026-04-19 |
| 3. Pricing Engine | v1.50.1 | 5/5 | Complete | 2026-04-19 |
| 4. Bitrix24 CRM Sync | v1.50.1 | 5/5 | Complete | 2026-04-19 |
| 5. Competitor Analysis | v1.50.1 | 6/6 | Complete | 2026-04-19 |
| 6. Product Auto-Create | v1.50.1 | 6/6 | Complete | 2026-04-23 |
| 7. Dashboard Polish + Cutover | v1.50.1 | 6/6 | Complete | 2026-04-24 |
| 8. C4 Agent Framework | v2.0 | 0/TBD | Not started | - |
| 9. E1 Trade Customer Pricing | v2.0 | 0/TBD | Not started | - |
| 10. C1 Pricing Agent | v2.0 | 0/TBD | Not started | - |
| 11. E2 Quote â†’ Bitrix Deal | v2.0 | 0/TBD | Not started | - |
| 12. C3 SEO / Content Agent | v2.0 | 0/TBD | Not started | - |
| 13. E3 WhatsApp Channel | v2.0 | 0/TBD | Not started | - |
| 14. E4 AI Product-Finder | v2.0 | 0/TBD | Not started | - |
| 15. C2 Ad Optimisation Agent | v2.0 | 0/TBD | Not started (gated on cutover + UTM data) | - |

## Research Flags

Captured from `research/SUMMARY.md` Phase Proposal â€” informs whether `/gsd-research-phase` runs before planning:

| Phase | Research | Notes |
|-------|----------|-------|
| 8. C4 Agent Framework | YES | Prism API surface, Langfuse self-hosted Docker, MCP PHP SDK Context7 verify, shield:safe-regenerate design |
| 9. E1 Trade Pricing | LIGHT | Decorator pattern over RuleResolver well-understood; operator confirms customer-group seed list + display strategy |
| 10. C1 Pricing Agent | YES | Prompt design, deterministic temp=0 calibration, token budget across input contexts |
| 11. E2 Quote Flow | MAYBE | Bitrix Deal line-item modelling for 30-line quotes (custom-fields vs Estimate API) |
| 12. C3 SEO Agent | MAYBE | Content diff format, brand-voice regex pattern library |
| 13. E3 WhatsApp | YES | WABA setup, Meta OBO BSP deprecation 2026, 24h window state machine edge cases, per-number tier rate limits |
| 14. E4 Product-Finder | MAYBE | Rate-limit calibration, anonymous PII posture, FULLTEXT recall on real catalogue subset |
| 15. C2 Ad Agent | LATE | Gates on v1 cutover going live + â‰¥4 weeks of UTM data accumulating; do not pre-research |

## Coverage

**v1 requirements (v1.50.1) mapped:** 85 / 85 âœ“ (shipped)

**v2 requirements (v2.0) mapped:** 56 / 56 âœ“
- 13 AGNT â†’ Phase 8
- 6 TRDE â†’ Phase 9
- 5 PRCAGT â†’ Phase 10
- 8 QUOT â†’ Phase 11
- 5 SEOAGT â†’ Phase 12
- 8 CHAT â†’ Phase 13
- 6 FIND â†’ Phase 14
- 5 ADAGT â†’ Phase 15

**Orphaned requirements:** 0
**Duplicate mappings:** 0

## Cross-Cutting v2 Invariants (every phase respects)

These are global invariants â€” not repeated in every phase's success criteria, but enforced by every phase's plans:

1. **Suggestions seam mandatory** for any data-changing feature (agents, quote approvals, WhatsApp lead capture all become producer kinds)
2. **Dual-YAML Deptrac sync** â€” every new layer added to BOTH `depfile.yaml` AND `deptrac.yaml`; grep test locks
3. **Dry-run-default CLI** â€” `--live` opt-in for agents, WhatsApp, quote, GDPR, cutover commands
4. **Shadow-mode gates default false** in `.env.example`: `AGENT_WRITE_ENABLED`, `AGENT_AUTO_APPLY_ENABLED`, `WHATSAPP_OUTBOUND_ENABLED`, `QUOTE_BITRIX_PUSH_ENABLED`
5. **`shield:safe-regenerate` wrapper** ships in Phase 8 (automates P5-F restoration) â€” every later phase that adds Filament Resources uses it
6. **Correlation_id threading** â€” `Context` â†’ Prism â†’ Langfuse â†’ Suggestions â†’ integration_events (one trace per agent run)
7. **ULID PKs** for all cross-domain references (`AgentRun`, `Quote`, `WhatsAppConversation`, `ChatbotSession`)
8. **Listener-based extension of v1** â€” never modify v1 jobs (`SyncChunkJob`, `PushOrderToBitrixJob`, etc.); all v2 hooks are subscribers to v1 events
9. **Provider seam pattern** â€” new external APIs get thin `<X>Client` wrappers mirroring v1's `WooClient` / `BitrixClient` / `SupplierClient` shape
10. **Golden fixture extension, not modification** â€” v1's 50 PriceCalculator triples remain byte-identical; E1 adds 30 new customer-group triples

---
*v1 roadmap created: 2026-04-18 â€” Granularity: coarse (7 phases â€” coarse target is 3-5; deferred-upward because the 7 domains are dependency-forced and cannot be compressed without losing coherent delivery boundaries)*
*v2 roadmap created: 2026-04-24 â€” Granularity: coarse (8 phases â€” each is one cleanly-bounded capability with its own Deptrac layer; per research SUMMARY.md compression would break the dependency-forced build order)*

