# Phase 11: E2 Quote Request → Bitrix Deal Flow - Context

**Gathered:** 2026-04-30
**Status:** Ready for planning

<domain>
## Phase Boundary

Phase 11 ships the v2 quote-flow on top of the v1 CRM seam (Phase 4) + Phase 9 trade-pricing decorator. Scope: `Quote` (ULID PK) + `QuoteLine` Eloquent models with snapshotted prices that survive subsequent PricingRule edits (`unit_price_pence_at_quote` + `line_total_pence_at_quote` + `product_snapshot` JSON immutably set at quote-creation); Filament `QuoteResource` (admin + pricing_manager + sales CRUD) walking customer + customer_group → TradeRuleResolver price resolution → per-line snapshot; PDF rendering via `spatie/laravel-pdf` v2.7 (DOMPDF driver) at `resources/views/pdf/quote.blade.php` reading snapshots only (never re-resolves); `QuoteApproved` domain event fires on single-button Approve action; `PushQuoteToBitrixDealJob` (on `crm-bitrix` queue) creates a Bitrix Deal of `TYPE_ID=QUOTE` with `UF_CRM_WOO_QUOTE_ID=Quote.id` + line items; `QUOTE_BITRIX_PUSH_ENABLED` env flag (default false) gates real vs shadow-mode (writes to `sync_diffs` with `provider='bitrix-quote'` when off); idempotent re-approval (matched on `UF_CRM_WOO_QUOTE_ID` — updates existing Deal, no duplicate); `quotes:expire` scheduled command flips `status=expired` past `expires_at` (default created_at + 14d) with optional config-gated customer email; UK B2B ex-VAT itemised PDF convention (per-line ex-VAT + Subtotal + VAT 20% + Total inc VAT block).

Scope is fixed by ROADMAP.md Phase 11 and REQUIREMENTS.md QUOT-01..08. Discussion resolved 4 gray areas: customer model dual-mode (FK + denormalised), single-button Approve workflow (draft → sent), manual sales acceptance update (no public token route in v1), search-and-add line UX with ex-VAT itemised PDF.

</domain>

<decisions>
## Implementation Decisions

### Customer model + quote ownership (QUOT-01 schema extension)

- **D-01:** **Dual-mode: nullable `Quote.user_id` FK + denormalised contact fields.** Schema: `Quote.user_id` is a NULLABLE foreign key to `users.id` (cascadeOnDelete=null). When set, downstream queries can JOIN to user (customer_group, sales history, prior quotes — supports Phase 14 chatbot lookup of "your quotes" by logged-in customer). When NULL, the denormalised fields per QUOT-01 (`customer_email`, `customer_name`, `billing_address` JSON) carry contact info — supports anonymous-lead quote requests from Phase 13 WhatsApp / Phase 14 chatbot / cold sales calls where the customer isn't yet a registered user.
- **D-02:** **`customer_group_id` resolution priority:** if `user_id` set, copy `users.customer_group_id` to `Quote.customer_group_id` AT CREATION (not via JOIN — snapshotted alongside line prices to survive future role changes); if `user_id` null, sales picks `customer_group_id` manually in the Filament form (default to NULL = retail). The resolved `customer_group_id` is the input to TradeRuleResolver per Phase 9 D-04.
- **D-03:** **Form behaviour:** Filament Quote-create form has a Toggle: "Existing customer?" → reveals user picker (search-and-select against `users.email`) which auto-fills name + customer_group_id. Toggle off → reveals free-text customer_email/customer_name/billing_address fields + manual customer_group_id picker. Both modes save to the same Quote schema; only difference is whether `user_id` is set.

### Approval + send workflow (QUOT-05, QUOT-07)

- **D-04:** **Single-button "Approve" action transitions `draft → sent` atomically.** Sales/pricing_manager creates a Quote in `draft` (status enum default). One Approve button (admin + pricing_manager only — sales CANNOT approve their own quotes per separation-of-duties; sales sees the button as disabled with tooltip "ask pricing_manager or admin to approve") performs in a single transaction: (1) status `draft → sent`, (2) fires `QuoteApproved` domain event → `PushQuoteToBitrixDealJob` dispatched to `crm-bitrix` queue (gated by `QUOTE_BITRIX_PUSH_ENABLED`), (3) emails the rendered PDF to `customer_email` via existing Laravel Mail driver, (4) writes `audit_log` entry with actor + correlation_id + Quote.id.
- **D-05:** **Intermediate status values (`pending_approval`, `approved`) are reserved for v1.x workflow extension** but UNUSED in v1.0 — they stay in the enum so a future plan can add them without a migration. v1 transitions: `draft → sent → accepted | rejected | expired`. Admin can also `sent → draft` revert (rare; for correction before customer reads PDF) within 5 minutes of send via a "Revert" admin-only action; after 5 min the revert action is hidden (PDF presumed read).
- **D-06:** **`accepted` / `rejected` are MANUAL sales updates** per D-08 below. `expired` flips automatically via `quotes:expire`. There is no `withdrawn` status in v1 — sales overwrites a quote by editing in `draft` mode (creating a new quote with same customer is the v1 pattern; quote_history relation deferred).

### Quote acceptance mechanism (QUOT-08 + downstream)

- **D-07:** **Manual sales update — v1 simplest.** PDF is emailed to customer (D-04); customer replies via email / phone / Bitrix Deal stage transition externally. Sales clicks "Mark as accepted" or "Mark as rejected" in Filament `QuoteResource` row action. The transition writes `audit_log` + optionally fires `QuoteAccepted` / `QuoteRejected` domain events for Phase 7 dashboard subscribers.
- **D-08:** **Reject capture:** Reject action prompts for a structured reason (Select: `price_too_high`, `wrong_specifications`, `competitor_won`, `delayed_decision`, `other`) + optional free-text note. Persists to `Quote.rejection_metadata` JSON column (parallels Phase 10 D-09 `agent_rejection_feedback` shape). Feeds future v2.x analytics on quote-loss patterns.
- **D-09:** **Public accept-link, public chatbot self-service, Bitrix stage mirror-back ALL DEFERRED.** v1 keeps zero public-facing URLs, zero token-rotation surface, no inbound Bitrix webhook coupling. Acceptance is a sales-side bookkeeping action.

### Line-add UX + VAT PDF convention (QUOT-02, QUOT-03, QUOT-04)

- **D-10:** **Filament line-add: search-and-add picker (primary) + manual SKU entry (fallback).** The existing Phase 6 `ProductResource` SKU/name search infrastructure is reused as a Filament `Select::searchable()` field. Admin types ≥3 chars → debounced query against `products.sku|name|brand` returns up to 25 matches. Click → adds a `QuoteLine` row with `sku` populated, `quantity_int=1` default, and `unit_price_pence_at_quote` auto-snapshotted via `TradeRuleResolver::resolveForQuote(sku, customer_group_id)` (new method delegating to existing resolver). Manual SKU text-input fallback for cases where the picker doesn't match (rare; primarily for SKUs not yet in `products` table — a Phase 6 auto-create gap). Bulk-paste DEFERRED to v1.1.
- **D-11:** **PDF VAT layout: ex-VAT itemised + VAT line + inc-VAT total (UK B2B convention).** Per QuoteLine: quantity, SKU, name, unit_price_pence_at_quote (ex-VAT), line_total_pence_at_quote (ex-VAT). Bottom block: `Subtotal (ex VAT) £X,XXX.XX` / `VAT 20% £XXX.XX` / `Total (inc VAT) £X,XXX.XX`. VAT calculation reuses `PriceCalculator::stripVat()` inverse helper (Phase 3 D-05) — quote totals stored ex-VAT internally; VAT computed at PDF render time. Pence are integer-only throughout (Phase 3 D-03 invariant).
- **D-12:** **Quantity is integer (`quantity_int`).** No fractional/weight quantities in v1 (B2B AV catalogue is unit-based — no fluid metres, weight-based pricing, etc). Validation enforces `quantity_int >= 1` + `<= 9999` (catches fat-finger errors).
- **D-13:** **Line snapshot immutability:** `unit_price_pence_at_quote` + `line_total_pence_at_quote` + `product_snapshot` are set ONCE on QuoteLine creation and NEVER updated. Editing a draft quote's line quantity recalculates `line_total_pence_at_quote = unit_price_pence_at_quote * quantity_int` but leaves `unit_price_pence_at_quote` and `product_snapshot` frozen. After quote `status = sent`, line edits are forbidden (UI hides edit buttons; model-level Pest test `QuoteLineImmutabilityTest` asserts `saving` Eloquent observer throws on price/snapshot changes when status != draft).

### Claude's Discretion

Areas not separately discussed — planner/researcher picks the default approach:

- **`spatie/laravel-pdf` driver choice:** DOMPDF (per ROADMAP success criterion 3 — "via DOMPDF driver"). Requires `barryvdh/laravel-dompdf` ^3.x already installed by Phase 1. Verify in Plan 11-01 task action.
- **PDF storage:** generated on-demand for Filament admin download + email attachment; NOT persisted to disk by default. If post-cutover ops asks for archival, add a `storage/app/quotes/` retention path + `quotes:prune-pdfs` command (90-day default per Phase 1 retention pattern). v1 ships without storage.
- **PDF branded header:** logo asset at `public/images/meetingstore-logo.png` (operator drops the file; placeholder shipped at 200×60 px). Hard-coded company details (name, address, VAT number, registration number) in `config/quote.php` for ease of edit. Footer: page numbers + "Quote #{ulid_short_8} • Generated {YYYY-MM-DD HH:mm}".
- **Bitrix Deal `TYPE_ID=QUOTE`:** assumes the Bitrix instance has the `QUOTE` deal type configured. Plan 11-04 ships a pre-flight check that calls `crm.dealtype.list` and warns if no deal type with system code `QUOTE` exists. Operator runbook documents how to create the type in Bitrix admin.
- **Bitrix line-item modelling for 30-line quotes (ROADMAP research flag):** RESEARCH must investigate (a) Bitrix `crm.deal.productrows.set` for line items vs (b) custom-fields on the Deal vs (c) Bitrix Estimate (`crm.quote.add`) entity. Lean toward `crm.deal.productrows.set` (matches v1 Phase 4 conventions; Bitrix Estimate adds an entity surface we don't currently track).
- **Quote ULID format:** Phase 1 D-16 + Phase 8 AGNT-03 ULID precedent. `Quote.id` is a 26-char ULID. Filament URL slug uses the full ULID; PDF reference shows `Quote #{ulid_short_8}` truncated to 8 chars (ULIDs are unique enough at 8 chars for human reference).
- **`QuoteApproved` event payload:** carries `Quote.id`, `Quote.user_id`, `Quote.customer_email`, `Quote.customer_group_id`, `Quote.status_before`, `Quote.status_after`, `correlation_id`. Phase 7 dashboard subscribes for "quotes sent today" widget (post-Phase-11 dashboard refresh).
- **Listener queue:** `PushQuoteToBitrixDealJob` runs on `crm-bitrix` (Phase 1 FOUND-09) — same queue as Phase 4 CRM pushes; Bitrix-side ~2 req/sec rate limit shared. Email mail dispatch on `default` queue.
- **Retry policy (PushQuoteToBitrixDealJob):** Phase 4 D-11 pattern — 3 attempts, 30s/5m/30m backoff, 4xx fail-fast → write `quote_push_failed` Suggestion (new kind, parallels `crm_push_failed`) for ops Replay action. Reuses Phase 4 retry shape verbatim.
- **`expires_at` default:** `created_at + 14 days`. Configurable via `config('quote.default_expiry_days', 14)`. Per-quote override available in Filament form.
- **Customer expiry email (QUOT-08 optional):** config flag `config('quote.email_on_expiry', false)` — DEFAULT FALSE. Operator opts in post-cutover after observing v1 expiry volume.
- **Filament resource navigation group:** "Sales" (new top-level group; QuoteResource is the first member). Future v1.x can add CustomerResource + InvoiceResource here.
- **Deptrac `Quotes` layer:** new layer allowed to depend on `Foundation, Products, Pricing, TradePricing, Suggestions, CRM, Webhooks` (read-only access to TradeRuleResolver + BitrixClient + PushQuoteToBitrixDealJob delegation). Deny: `Agents, Competitor, ProductAutoCreate`. Listener wiring lives in `app/Domain/CRM/Listeners/PushQuoteToBitrix.php` (CRM domain, not Quotes — keeps the one-way arrow CRM-doesn't-know-about-Quotes intact).
- **`PushQuoteToBitrix` listener placement:** on `app/Domain/CRM/Listeners/` per QUOT-05 explicit text — listener subscribes to `QuoteApproved` event but lives in CRM domain so the BitrixClient + BitrixEntityMap dependency stays inside CRM. Quotes domain emits the event; CRM domain consumes. Deptrac one-way arrow: CRM → Quotes ALLOWED (CRM listener reads Quote model); Quotes → CRM DENIED (Quotes domain doesn't import Bitrix anything).
- **Quote.customer_group_id snapshot vs FK:** `Quote.customer_group_id` is a NULLABLE FK with `onDelete('restrict')` — prevents deleting a CustomerGroup that has historical quotes. Application-level snapshot-of-name `customer_group_name_at_quote` (denormalised string) preserves the group label even if the FK is later renamed. Phase 9 D-? established the customer_group rename pattern.
- **`UF_CRM_WOO_QUOTE_ID` Bitrix custom field:** Plan 11-01 extends `bitrix:bootstrap` (Phase 4) to ALSO create `UF_CRM_WOO_QUOTE_ID` (string type, indexed) on Deal entity. Idempotent — matches Phase 4 `bitrix:bootstrap` pattern. Operator runbook updated.
- **`Quote.status` timestamps:** new columns `sent_at`, `accepted_at`, `rejected_at`, `expired_at` (each nullable timestamp) — capture transition moments alongside the enum. Audit trail per Phase 1 D-04 + activity_log already covers status as a model attribute change; the timestamps make analytics queries (e.g. "median quote-to-accept latency") direct without scanning activity_log.
- **PDF "customer signature block":** ROADMAP success criterion 3 says optional. v1 default = OFF (config flag `config('quote.pdf_signature_block', false)` — when on, PDF reserves space at the bottom for a printed signature line). e-Signature integration deferred to v2.x.
- **Plan breakdown proposal:** 5 plans (linear waves):
  - 11-01: data model + bitrix:bootstrap UF_CRM_WOO_QUOTE_ID extension + config + Deptrac Quotes layer
  - 11-02: TradeRuleResolver::resolveForQuote + QuoteLine snapshot logic + immutability observer + QuotePdfPriceImmunityTest
  - 11-03: Filament QuoteResource + create/edit/Approve/Reject/Mark-accepted actions + line picker + RolePermissionSeeder
  - 11-04: PDF rendering (spatie/laravel-pdf + DOMPDF + Blade view + VAT block) + email Mailable + QuoteApproved event + PushQuoteToBitrix listener + PushQuoteToBitrixDealJob (reuse Phase 4 retry shape) + sandbox test
  - 11-05: quotes:expire command + 11-VERIFICATION.md ship verdict + Deptrac dual-config + DeptracQuotesLayerTest

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Phase 9 Trade Customer Pricing (resolver Phase 11 calls)

- `.planning/phases/09-e1-trade-customer-pricing/09-CONTEXT.md` — D-? customer_group resolution pattern
- `.planning/phases/09-e1-trade-customer-pricing/09-02-SUMMARY.md` — TradeRuleResolver decorator API surface
- `.planning/phases/09-e1-trade-customer-pricing/09-VERIFICATION.md` — B-03 byte-identical pattern (Phase 11 must NOT modify TradeRuleResolver)

### Phase 4 Bitrix24 CRM Sync (BitrixClient + dedup ledger Phase 11 reuses)

- `.planning/phases/04-bitrix24-crm-sync/04-CONTEXT.md` — D-09 retry semantics; D-12 BitrixEntityMap dedup
- `.planning/phases/04-bitrix24-crm-sync/04-02-SUMMARY.md` — BitrixClient.dealAdd / dealUpdate / userfield.add (UF_CRM_WOO_QUOTE_ID Plan 11-01 extension)
- `.planning/phases/04-bitrix24-crm-sync/04-03-SUMMARY.md` — listener-job-applier pattern (PushQuoteToBitrix follows identical shape)

### Phase 3 Pricing Engine (penny math + stripVat Phase 11 reuses)

- `.planning/phases/03-pricing-engine/03-CONTEXT.md` — D-03 integer pennies + BCMath; D-05 stripVat helper Phase 11 inverts for VAT-add

### Phase 1 Foundation (audit + suggestions seam + ULID precedent)

- `.planning/phases/01-foundation/01-CONTEXT.md` — D-14 suggestions seam (`quote_push_failed` is a new producer kind)
- `.planning/phases/01-foundation/01-04-SUMMARY.md` — SuggestionApplier contract (Phase 11 ships QuotePushRetryApplier)

### v1 byte-unchanged anchors (Phase 11 must NOT modify)

- `app/Domain/Pricing/Services/PriceCalculator.php` — Phase 3 (sha256 baseline locked)
- `app/Domain/Pricing/Services/RuleResolver.php` — Phase 3 (sha256 baseline locked by Phase 9 B-03)
- `app/Domain/TradePricing/Services/TradeRuleResolver.php` — Phase 9 (sha256 baseline locked)
- `app/Domain/CRM/Services/BitrixClient.php` — Phase 4 (Plan 11-01 may EXTEND the bootstrap command — verify contract preserved; new methods added DON'T modify existing ones)
- `app/Domain/Competitor/Appliers/MarginChangeApplier.php` — Phase 5 (Phase 10 baseline locked)

### Project foundations

- `.planning/PROJECT.md` — Anthropic budget locked; trade pricing decision; quote-flow as v2.0 milestone item
- `.planning/REQUIREMENTS.md` — QUOT-01..08 acceptance criteria
- `.planning/ROADMAP.md` §Phase 11 — 6 success criteria; depends-on Phase 9 + Phase 4
- `.planning/STATE.md` — Phase 10 ships 2026-04-30; v2.0 milestone 4/8 complete after Phase 10

### Research target (Plan 11-04 must-investigate)

- ROADMAP research flag MAYBE — Bitrix Deal line-item modelling for 30-line quotes. RESEARCH.md must compare:
  - `crm.deal.productrows.set` (deal-attached line items — Bitrix native)
  - Custom-field JSON on Deal (single field carrying line-items JSON — fragile; Bitrix UI doesn't render)
  - `crm.quote.add` (Bitrix Estimate entity — separate from Deal; adds entity surface)
  Recommended (per RESEARCH §12 hypothesis): `crm.deal.productrows.set`. Validate against a Bitrix sandbox.

### No external specs

No ADRs/RFCs beyond the above.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets (Phase 1-10 delivered)

- **`TradeRuleResolver`** (Phase 9) — primary input for line-price snapshots. Phase 11 adds `resolveForQuote(sku, customer_group_id): int` which delegates to existing `resolve(sku, customer_group_id)` and returns the integer-pennies result. Zero modification to TradeRuleResolver itself.
- **`PriceCalculator::stripVat`** (Phase 3 D-05) — inverse helper for VAT calculation on PDF.
- **`BitrixClient`** (Phase 4) — `dealAdd`, `dealUpdate`, `dealUserfieldAdd` already shipped. Plan 11-01 extends `bitrix:bootstrap` to add UF_CRM_WOO_QUOTE_ID custom field idempotently.
- **`BitrixEntityMap`** (Phase 4) — Phase 11 adds `entity_type='quote_deal'` rows mapping `Quote.id` → `Bitrix Deal.id` (separate from existing `entity_type='deal'` for order-Deals so the dedup ledger doesn't cross-contaminate).
- **`SuggestionApplier` + `ApplySuggestionJob`** (Phase 1 D-17) — `QuotePushRetryApplier` registered against new kind `quote_push_failed`.
- **`DomainEvent` base + `ShouldDispatchAfterCommit`** (Phase 1) — `QuoteApproved` / `QuoteAccepted` / `QuoteRejected` extend the same base.
- **`Auditor`** (Phase 1) — logs Quote model changes via `LogsActivity` trait (status transitions + line edits + approval).
- **`IntegrationLogger`** (Phase 1) — every BitrixClient HTTP call wraps with correlation_id.
- **`BaseCommand`** (Phase 1) — `quotes:expire` extends this for correlation_id threading.
- **`AlertRecipient` Notifiable** (Phase 1 D-12) — extend with `receives_quote_alerts` boolean column (parallels Phase 4 `receives_crm_alerts` / Phase 5 `receives_competitor_alerts` / Phase 8 `receives_agent_alerts`).
- **`crm-bitrix` Horizon supervisor** (Phase 1 FOUND-09) — already booted; Phase 11 dispatches PushQuoteToBitrixDealJob onto it.
- **Shield RBAC pattern** — seeder LIKE patterns auto-attach `quote` perms after `shield:safe-regenerate`.
- **Filament 3 search-and-add picker** — Phase 6 `ProductResource` Select::searchable() pattern reused for QuoteLine SKU picker.
- **`spatie/laravel-pdf` v2.7** — install in Plan 11-04 (composer require). DOMPDF driver per ROADMAP.
- **`users.customer_group_id`** (Phase 9) — Quote D-02 reads this when user_id set.
- **PolicyTemplateIntegrityTest** (Phase 1+ permanent guardrail) — auto-checks new QuotePolicy + QuoteLinePolicy.

### Established Patterns

- **Migration timestamps:** Phase 10 ended `2026_04_30_010000`; Phase 11 starts `2026_05_*` (planner picks).
- **Domain layout:** `app/Domain/Quotes/` (new) gets `Models/Quote.php` + `QuoteLine.php`, `Events/QuoteApproved.php` + Accepted + Rejected, `Filament/Resources/QuoteResource.php`, `Console/Commands/QuotesExpireCommand.php`, `Policies/`, `Observers/QuoteLineImmutabilityObserver.php`.
- **CRM-domain listener for QuoteApproved:** `app/Domain/CRM/Listeners/PushQuoteToBitrix.php` + `app/Domain/CRM/Jobs/PushQuoteToBitrixDealJob.php` + `app/Domain/CRM/Appliers/QuotePushRetryApplier.php`. CRM domain owns the Bitrix wiring; Quotes domain just emits the event.
- **Deptrac dual-YAML:** BOTH `depfile.yaml` AND `deptrac.yaml` get the new `Quotes` layer + extend `CRM` allow-list to read `Quotes` model.
- **Filament Action authorisation:** `->authorize('approve_quote')` + `->authorize('mark_accepted_quote')` etc. Shield permissions per resource.
- **B-03 byte-identity discipline (Phase 9 + Phase 10 pattern):** sha256-baseline architectural tests for `TradeRuleResolver`, `PriceCalculator`, `BitrixClient` to prevent accidental Phase 11 modifications.
- **Testing DB:** SQLite is current local-dev; in-memory `:memory:` for Pest where needed. CI ideally uses MySQL but SQLite is the day-to-day driver.
- **`->authorize()` mandatory** on every Filament Action.

### Integration Points

- **Inbound (event-driven):** Filament Approve action → fires `QuoteApproved` → `PushQuoteToBitrix` listener (CRM domain) → dispatches `PushQuoteToBitrixDealJob` on `crm-bitrix` queue.
- **Outbound to Bitrix:** `BitrixClient::dealAdd([...payload..., 'TYPE_ID' => 'QUOTE', 'UF_CRM_WOO_QUOTE_ID' => $quote->id])` OR `dealUpdate($existingDealId, ...)` if `BitrixEntityMap.entity_type='quote_deal' AND woo_id=$quote->id` exists.
- **Shadow-mode:** `QUOTE_BITRIX_PUSH_ENABLED=false` → BitrixClient writes to `sync_diffs` with `provider='bitrix-quote'` (existing column extended with new provider value).
- **PDF rendering:** spatie/laravel-pdf → `Pdf::view('pdf.quote', ['quote' => $quote])->name("quote-{$quote->ulid_short}.pdf")` — generated on-demand; emailed via `QuoteSentMail` Mailable; admin-download via Filament action.
- **Migration:** `2026_05_01_*_create_quotes_table.php` + `_create_quote_lines_table.php` + `_add_receives_quote_alerts_to_alert_recipients.php` + `_add_quote_status_timestamps_to_quotes.php` (or single migration if planner prefers).
- **New Filament Resource:** `QuoteResource` under "Sales" navigation group.
- **No new migrations on existing v1 tables** beyond the AlertRecipient column extension.

### New domain

- `app/Domain/Quotes/` — populated from zero in Plan 11-01.

</code_context>

<specifics>
## Specific Ideas

- **Snapshot integrity is the headline ship gate.** `QuotePdfPriceImmunityTest` (per ROADMAP success criterion 1) is the load-bearing test — creates Quote, edits underlying PricingRule, re-renders PDF, asserts unchanged. CI MUST run this on every PR.
- **Single-button approval simplifies the v1 sales workflow** — multi-step approval was rejected in D-04 because team size doesn't justify the friction. Enum reserves the unused states for v1.x extension without a schema migration.
- **Search-and-add picker reuses Phase 6 ProductResource SKU search infra** — zero new search infrastructure. Bulk paste deferred until ops asks (most quotes are 5-15 lines per ops report).
- **Bitrix `UF_CRM_WOO_QUOTE_ID` is intentionally separate from `UF_CRM_WOO_ORDER_ID`** — prevents the Phase 4 order-dedup ledger from confusing a Quote-Deal with an Order-Deal. Two parallel custom fields, two parallel `BitrixEntityMap.entity_type` values (`'deal'` for orders, `'quote_deal'` for quotes).
- **Dual-mode customer model unblocks Phase 13 + Phase 14** — Phase 13 (WhatsApp inbound) and Phase 14 (chatbot) can create quotes for anonymous leads without forcing a User row creation. Adding `user_id` later (when lead converts) is a non-breaking UPDATE.
- **Deptrac one-way arrow on Quotes domain** is non-negotiable. Quotes emits events; CRM consumes. CRM listener reads Quote model (allowed); Quotes domain MUST NOT import BitrixClient (denied by deny-list).
- **B-03 byte-identity discipline** — Phase 11 ships sha256-baseline tests for TradeRuleResolver + PriceCalculator + BitrixClient. The pattern is now standard across v2.x (Phase 9 + Phase 10 established it; Phase 11 inherits).
- **Quote.id ULID format aligns with Phase 1 D-16 / Phase 8 AGNT-03** — consistent ID convention across v2.x; UI shows truncated 8-char form for human reference.
- **Manual sales acceptance is a deliberate v1 simplification** — public accept-link surface adds token security + anti-replay surface for marginal v1 value. Defer to v1.x once ops validates v1.0 quote volume warrants self-service.
- **`receives_quote_alerts` AlertRecipient extension** is the v1.x-friendly pattern — when ops asks for "alert me when a quote expires" or "alert me when a quote pushes to Bitrix", the channel is already wired.

</specifics>

<deferred>
## Deferred Ideas

These surfaced during analysis but are explicitly scoped out of Phase 11 to keep the snapshot-integrity ship goal tight:

- **Public accept-link in PDF** (token-signed URL → public Laravel route → status flip) — defer to v1.x once ops validates customer self-service value
- **Bitrix Deal stage mirror-back** (inbound webhook → Quote.status update) — out of scope (Phase 4 v1 is one-way Woo→Bitrix)
- **e-Signature integration** — v1 ships printed signature line only (config-gated); DocuSign / Adobe Sign deferred to v2.x
- **Bulk paste line-add UX** (textarea: SKU,qty per line) — defer until ops reports >20-line quotes
- **Per-brand quote PDF templates** (different layouts per brand) — single canonical template in v1; per-brand deferred
- **Quote_history relation** (track edit history per quote) — Phase 1 activity_log already captures status changes; full edit-history table deferred
- **Quote PDF storage / archival** — generated on-demand only in v1; storage/app/quotes/ + retention command deferred
- **Quote analytics dashboard** (median time-to-accept, win rate by customer_group) — Phase 7 dashboard refresh territory; Phase 11 ships data + status timestamps; analytics surface deferred
- **Multi-currency quotes** — v1 is GBP only; multi-currency deferred (matches v1 PROJECT.md scope)
- **Quote line discounts** (per-line override on top of TradeRuleResolver) — TradeRuleResolver already supports customer_group-tier pricing; ad-hoc per-line discount deferred
- **Reject reason analytics dashboard** — `Quote.rejection_metadata` JSON stored in v1; dashboard surface deferred
- **`QuoteAccepted` / `QuoteRejected` Bitrix Deal stage transitions** — Phase 4 status-map exists for orders; extending to quotes deferred (operator can manually update Bitrix Deal stage post-acceptance)
- **Customer self-service "my quotes" page** — would require Phase 14 chatbot context; defer
- **Quote → Order conversion** — when accepted quote becomes a Woo order, auto-create or sync? Defer to v2.x; manual sales handoff in v1
- **Per-customer quote count limits** (rate-limit lead capture from anonymous channels) — defer to Phase 13/14 anti-abuse work
- **Customer signature block on PDF (e-signature integration)** — v1 ships printed signature line only when `config('quote.pdf_signature_block', false)=true`; e-signature defer

### Reviewed Todos (not folded)

No pending todos matched Phase 11 scope.

</deferred>

---

*Phase: 11-e2-quote-request-bitrix-deal-flow*
*Context gathered: 2026-04-30 — 13 D-XX decisions across 4 user-discussed gray areas + Claude's Discretion*
