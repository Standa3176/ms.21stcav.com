# Phase 4: Bitrix24 CRM Sync - Context

**Gathered:** 2026-04-19
**Status:** Ready for planning

<domain>
## Phase Boundary

Phase 4 replaces the sanctions-blocked itgalaxy WooCommerce → Bitrix24 plugin with a native, audited, one-way Woo→Bitrix sync. Scope: `php artisan bitrix:bootstrap` creates the `UF_CRM_WOO_ORDER_ID` Deal custom field; four Woo webhooks (`order.created`, `order.updated`, `customer.created`, `customer.updated`) hand off to a queued `crm-bitrix` pipeline that pushes Deal + Contact + Company to Bitrix via the official `bitrix24/b24phpsdk`; every push is dedup-protected by a `BitrixEntityMap` ledger; UTM params + GA Client ID captured on checkout land on Deal/Contact custom fields; a Filament field-mapping UI lets admins remap Woo↔Bitrix without code edits; `php artisan bitrix:backfill-orders` replays historical orders idempotently; a Woo-order-status → Bitrix-Deal-stage map drives stage transitions on `order.updated`; failed pushes retry with backoff then surface as `crm_push_failed` suggestions (first real producer of the Phase 1 suggestions seam); `php artisan gdpr:erase-bitrix-customer` scrubs PII from matched Bitrix Contact + Deal.

Scope is fixed by ROADMAP.md Phase 4 and REQUIREMENTS.md CRM-01..CRM-13. Discussion resolved three open items flagged in STATE.md (UTM capture mechanism, pipeline routing complexity, webhook-event coverage + update behaviour) by grounding each in how the legacy itgalaxy plugin actually works (parity-first).

</domain>

<decisions>
## Implementation Decisions

### UTM / GA Client ID capture (CRM-09)

- **D-01:** **JS snippet + hidden checkout fields.** A small custom JS deployed on `meetingstore.co.uk` reads `utm_*` query params and the `_ga` cookie on landing, writes them to a first-party cookie, and injects hidden `<input>` fields into the WooCommerce checkout form. WooCommerce persists them automatically to order meta (`_bx_wc_utm_fields_data`-style keys). From Laravel's perspective, they arrive as part of the standard `order.created` webhook payload — no extra inbound endpoint, no WP plugin dependency. Rejected alternatives: a dedicated WP plugin (adds WP surface area we're moving away from) and session-cookie → Laravel endpoint (more moving parts, requires joining on email).
- **D-02:** **First-touch attribution, 30-day cookie window.** JS writes UTMs to the cookie on first landing; reuses stored values on subsequent visits if UTMs are absent; overwrites only when a fresh `utm_*` query param is present. Matches B2B buying-journey reality (campaign that *introduced* the lead gets credit for the eventual considered purchase). Checkout always submits the cookie values.
- **D-03:** **Standard 5 UTMs + GA Client ID = 6 Bitrix Deal custom fields.** `utm_source`, `utm_medium`, `utm_campaign`, `utm_term`, `utm_content`, and `_ga` Client ID parsed from the GA cookie. `gclid` / `fbclid` are explicitly OUT of scope for Phase 4 and deferred to a future Phase 8+ ads-sync item when offline-conversion upload becomes relevant.
- **D-04:** **UTMs ALSO captured on `customer.created`** (not only on order.created). Same cookie values are pushed to the Bitrix Contact as Contact-level custom fields. When a Deal later fires, the Deal copy is also written. This lets sales see attribution on registered-but-not-yet-purchased leads — a genuine extension over the legacy plugin, which only captures UTMs on the Deal.

### Pipeline routing & Deal-stage mapping (CRM-07)

- **D-05:** **Match legacy — single admin-picked pipeline + landing stage for every new Deal.** No B2B-vs-retail routing in v1. Inspection of the extracted legacy plugin (`C:/Users/sonny.tanda/Documents/1 - Laravel Projects/woo project/bitrix24-extracted/woocommerce-bitrix24-integration`) confirmed its "multiple pipeline support" is just an admin choice of *which* pipeline to use — the plugin does NOT dynamically route orders by attribute. CRM-07 is met by letting the admin pick the pipeline in a Filament settings page; zero routing logic. Full rule-driven routing is deferred as a post-cutover enhancement only if ops explicitly asks for it.
- **D-06:** **Configurable Woo-order-status → Bitrix-Deal-stage map (new `crm_status_mappings` table).** Admin-only Filament UI with one row per Woo status (`pending`, `processing`, `on-hold`, `completed`, `cancelled`, `refunded`, `failed`, plus any custom statuses detected on the store). Each row maps to a Bitrix Deal `STAGE_ID`. When `order.updated` fires and the status has changed, the handler calls `crm.deal.update` with the mapped `STAGE_ID`. Legacy parity: the itgalaxy plugin ships this via `functions/itglx-wcbx24-update-deal-stage.php`; we reimplement it as a first-class Filament feature.
- **D-07:** **Pipeline + status-map settings live in dedicated Filament pages, not a config file.** `CrmPipelineSettingsPage` holds `bitrix_pipeline_id` + `landing_stage_id` (plus assigned-user / responsible-person defaults under Claude's Discretion). `CrmStatusMappingResource` manages the status-map rows. Both admin-only via Shield permissions (Phase 1 D-01..D-03 pattern). "No code edits" — CRM-06 — is met.

### Webhook scope & order-update behaviour (CRM-03, CRM-04, CRM-08)

- **D-08:** **Four Woo events drive the sync:** `order.created`, `order.updated`, `customer.created`, `customer.updated`. `order.deleted` is explicitly OUT — Woo rarely hard-deletes; cancellations flow via `order.updated` → status-map → stage transition. All four handlers are queued (`crm-bitrix` queue per Phase 1 FOUND-09), respond to the webhook controller within 200ms (Phase 1 D-07 pattern — webhook already persists + dispatches), and are idempotent on the existing Phase 1 `X-WC-Webhook-Delivery-ID` dedup.
- **D-09:** **On `order.updated`, patch STAGE_ID + OPPORTUNITY (total) + append new notes only.** Full-field re-push is rejected because it would overwrite manual edits a salesperson makes in the Bitrix UI (e.g. a corrected phone number, a reassigned responsible user). Narrow patch scope is predictable and preserves human edits. Stage transition only fires when the status has actually changed (compare against last-pushed status stored on the `BitrixEntityMap` row). Notes are appended as Deal comments (de-duplicated by hashing `note_id` so re-delivery doesn't double-post).
- **D-10:** **Race-safe handling when `order.updated` fires before `order.created` has been processed.** Handler checks `BitrixEntityMap` for the order's `deal_id`; if absent, the job re-queues itself with a 30-second delay (up to 5 attempts). If still missing after 5 attempts, writes a `crm_push_failed` suggestion with kind-subtype `update_before_create` so ops sees the anomaly instead of it being silently dropped. Uses Laravel's `release($delay)` on `ShouldQueue` jobs — no separate scheduler. Chaining jobs was rejected (fragile under retry across supervisor workers).
- **D-11:** **Retry policy: 3 attempts, short backoff (30s, 5m, 30m), 4xx fail-fast.** 5xx/network/429 errors retry (honour `Retry-After` on 429 per Bitrix ~2 req/sec ceiling from STACK.md §2). 4xx validation errors (e.g. invalid field ID, malformed VAT number) fail immediately — retrying won't fix them. After attempts exhausted: write `suggestions('crm_push_failed')` row with request+response snapshot + correlation_id, fire the `AlertRecipient` distribution (Phase 1 D-10..D-13), and park the job in the DLQ. Justification: MeetingStore is low-volume enough that fast ops-visibility beats long back-off cushions; a stale Deal tomorrow is worse than a pinged ops inbox today.
- **D-12:** **First real producer of the Phase 1 suggestions seam is `crm_push_failed`.** Kind string: `crm_push_failed`. `payload`: target entity_type (Deal/Contact/Company), woo_id, last attempt's HTTP status + body snippet, retry count. `evidence`: correlation_id, full request payload (for replay). A Filament "Replay" action on the suggestion calls `ApplySuggestionJob` with a `CrmPushRetryApplier` that re-fires the push. This satisfies CRM-11 (push log) + CRM-12 (DLQ surface) + the "seeded test suggestion approve/reject" path established in Phase 1.

### Claude's Discretion

Areas not user-discussed — planner/researcher picks the default approach:

- **GDPR right-to-erasure workflow (CRM-13).** Default: dual-entry-point — `php artisan gdpr:erase-bitrix-customer --email={addr}` CLI command *and* a Filament admin-only action on the CRM push log. PII scrub-in-place (NOT hard delete): Contact fields — name, phone, email, address — replaced with `REDACTED-{sha256-of-email}` tokens via `crm.contact.update`; related Deal's PII-bearing custom fields cleared; Deal's `OPPORTUNITY` + `UF_CRM_WOO_ORDER_ID` + stage preserved (financial-record requirement). Every erasure writes a dedicated `audit_log` entry with actor + correlation_id + entity_ids touched. Company is NOT erased (it's a legal entity, not personal data). Reason for scrub-vs-delete: UK GDPR allows anonymisation as a valid alternative to deletion when the business has legitimate retention interest (financial records) — preserves auditability of past sales without retaining PII.
- **Contact / Company deduplication keys (CRM-04, CRM-05, Pitfall 6).** Contact: primary key `EMAIL` (case-insensitive normalisation via `mb_strtolower` before `crm.contact.list` filter), fallback `PHONE` (normalised to E.164). Company: `UF_CRM_COMPANY_VAT` (if present in Woo order billing meta), fallback `TITLE + ADDRESS_POSTAL_CODE` exact match. Deal: `UF_CRM_WOO_ORDER_ID` exact (Pitfall 6 mandate). `BitrixEntityMap` table carries `(entity_type, woo_id, bitrix_id, email_hash, last_pushed_at, last_payload_hash)` with a unique index on `(entity_type, woo_id)`. Email change on an existing customer → update existing Bitrix Contact (don't create new); matched by the entity map's `(entity_type='contact', woo_id=customer_id)` row, not by email.
- **Field mapping storage + UX (CRM-02, CRM-06).** New `crm_field_mappings` table: `(entity_type enum, woo_field string, bitrix_field string, is_custom bool, updated_at)`. Seeded from the legacy plugin's mappings (ported from `admin/DealSettings.php` + `ContactSettings.php` + `CompanySettings.php`). Filament Resource per entity (Deal/Contact/Company) with a select-dropdown of available Bitrix fields (populated from `crm.deal.fields` / `crm.contact.fields` / `crm.company.fields` cached 24h per CRM-02). "Refresh from Bitrix" button re-fetches + invalidates cache. On save, validate each mapped bitrix_field exists in the fresh schema — reject save with per-row errors if any are stale.
- **`php artisan bitrix:bootstrap`** (CRM-01) is the first Phase 4 deliverable. Creates the `UF_CRM_WOO_ORDER_ID` Deal custom field via `crm.deal.userfield.add` if absent; idempotent (checks existence first via `crm.deal.userfield.list`). Fails hard if Bitrix auth is broken — no silent fallback to creating Deals without the dedup key (Pitfall 6 warning). Run before any push code ships.
- **`php artisan bitrix:backfill-orders --since={date}` (CRM-10).** Dry-run default (Phase 2 D-04 pattern); `--live` opt-in; `--since` required (no default — prevents "backfill everything back to 2019" accidents); chunk 50 orders per batch; sleep 600ms between chunks to respect Bitrix ~2 req/sec cloud ceiling; every order fed through the same `PushOrderToBitrix` job the webhook handler uses, so idempotency is identical; progress logged to `bitrix_backfill_runs` (mirroring `sync_runs` pattern from Phase 2).
- **Shadow-mode gate for Bitrix writes.** Parallel to Phase 1 `WOO_WRITE_ENABLED`: introduce `CRM_WRITE_ENABLED` env (default `false`). When false, the push job serialises the payload into `sync_diffs` (or a new `crm_push_shadow` table if a distinct shape is needed) instead of calling Bitrix. Enables the Phase 7 parity window without code changes. Planner decides whether to reuse `sync_diffs` or add `crm_push_shadow`.
- **Listener queue routing.** `order.*` + `customer.*` handlers run on the `crm-bitrix` Horizon queue (Phase 1 FOUND-09). Backfill runs on `sync-bulk` to avoid starving real-time pushes (Pitfall 7's "single queue for all job types" warning).
- **B24 SDK lock with fallback.** Pin `bitrix24/b24phpsdk` ^1.x per STATE.md stack decision; if sandbox validation during research flags blockers, fall back to `mesilov/bitrix24-php-sdk` ^2.x without changing the app-side wrapper interface (`App\Domain\CRM\Clients\BitrixClient`). The wrapper's public surface is SDK-agnostic.

### Folded Todos

None — no pending todos matched Phase 4 scope in `.planning/todos/`.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Legacy plugin being replaced (authoritative parity reference)

- `C:/Users/sonny.tanda/Documents/1 - Laravel Projects/woo project/bitrix24-extracted/woocommerce-bitrix24-integration/` — extracted itgalaxy v1.50.1 plugin source. Read before deciding field mappings, pipeline settings UX, status-map semantics, UTM cookie names, or Bitrix SDK calling patterns. Key files:
  - `readme.txt` — feature list and auth setup (inbound webhook)
  - `includes/OrderToBitrix24.php` — order.created/updated handler shape; `_wc_bitrix24_deal_id` post_meta as the legacy dedup key (we replace with `BitrixEntityMap` + `UF_CRM_WOO_ORDER_ID`)
  - `includes/CustomerToBitrix24.php` — customer upsert semantics
  - `includes/Crm.php` — pipeline parsing: `STAGE_ID` encodes `CATEGORY_ID:STAGE_ID` or `CATEGORY_ID||||STAGE_ID`
  - `includes/CrmFields.php` — legacy default field mappings (port into our seeder for `crm_field_mappings`)
  - `admin/DealSettings.php`, `ContactSettings.php`, `CompanySettings.php` — field-mapping UX reference
  - `functions/itglx-wcbx24-update-deal-stage.php` — status→stage map source material for D-06

### Phase 1 Foundation (authoritative contracts Phase 4 consumes)

- `.planning/phases/01-foundation/01-CONTEXT.md` — 17 locked decisions (RBAC, correlation_id threading, suggestions seam D-14..D-17, AlertRecipient D-12, retention D-04..D-09)
- `.planning/phases/01-foundation/01-03-SUMMARY.md` — `DomainEvent` base + `Context::hydrated` queue bridge + `Auditor` + `IntegrationLogger` + `BaseCommand` (all Phase 4 listeners/commands MUST use these)
- `.planning/phases/01-foundation/01-04-SUMMARY.md` — `VerifyWooHmacSignature` middleware, `WooWebhookController` (already dispatches `OrderReceived` + `CustomerRegistered` events — Phase 4 just adds listeners), `SuggestionApplier` contract (Phase 4 is the first real producer)
- `.planning/phases/01-foundation/01-05-SUMMARY.md` — `crm-bitrix` Horizon supervisor; `sync-bulk` for backfill; `AlertRecipient` Notifiable for DLQ alerts
- `app/Domain/Webhooks/Http/Controllers/WooWebhookController.php` — confirm existing order+customer endpoints; Phase 4 does NOT add new webhook routes
- `app/Domain/Webhooks/Events/OrderReceived.php`, `CustomerRegistered.php` — the events Phase 4 listeners subscribe to

### Phase 2 Supplier Sync (dry-run-default CLI pattern + report distribution)

- `.planning/phases/02-supplier-sync/02-CONTEXT.md` — D-04 (dry-run default + `--live` opt-in) is the pattern for `bitrix:backfill-orders`; D-08 (`AlertRecipient.receives_sync_reports` column pattern) — extend with `receives_crm_alerts` or reuse same fallback list (planner decides)

### Project foundations

- `.planning/PROJECT.md` — Key Decisions: one-way CRM sync (no inbound Bitrix webhook); Deal+Contact+Company fixed entity mode; capture UTM + GA CID (skip Roistat/Yandex); suggestions-first pattern
- `.planning/REQUIREMENTS.md` — CRM-01 through CRM-13 acceptance criteria
- `.planning/ROADMAP.md` §Phase 4 — 6 success criteria; "research: YES" flag (sandbox-validate `bitrix24/b24phpsdk` is the #1 research item before planning)
- `.planning/STATE.md` — open items flagged for Phase 4 (UTM capture mechanism, GDPR workflow, webhook-delivery SLA — D-01..D-04 and Claude's Discretion resolve all three)

### Research artefacts

- `.planning/research/FEATURES.md` §E — Module E Bitrix24 CRM sync: E.1 brief items, E.2 differentiators (replay UI, pipeline routing, contact dedup), E.3 gaps (HMAC, idempotency, DLQ, Bitrix rate-limit, field-mapping validation, UTM capture, GDPR), E.4 anti-features
- `.planning/research/PITFALLS.md` §Pitfall 4 — HMAC + webhook retry storm (already mitigated by Phase 1 D-07); §Pitfall 6 — Bitrix duplicate contacts/deals (CRITICAL) — the `BitrixEntityMap` + `UF_CRM_WOO_ORDER_ID` mandate is non-negotiable; §Pitfall 7 — queue starvation (crm-bitrix vs sync-bulk separation)
- `.planning/research/STACK.md` §2 — `bitrix24/b24phpsdk` ^1.x official SDK (MEDIUM confidence; sandbox-validate first); `mesilov/bitrix24-php-sdk` ^2.x as documented fallback; Bitrix cloud ~2 req/sec ceiling

### No external specs

No ADRs, RFCs, or external documents beyond the above. The legacy plugin source + REQUIREMENTS + research files constitute the full contract.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets (Phase 1 delivered)

- **`WooWebhookController`** (`app/Domain/Webhooks/Http/Controllers/WooWebhookController.php`) — already persists `webhook_receipts`, dedupes on `(source, delivery_id)`, stamps `correlation_id` from `Context`, and dispatches `OrderReceived` + `CustomerRegistered` events. Phase 4 adds listener classes ONLY — no new webhook endpoints, no HMAC middleware changes.
- **`OrderReceived` + `CustomerRegistered` events** (`app/Domain/Webhooks/Events/`) — carry `webhook_receipt_id` + `delivery_id`; Phase 4 listeners load the receipt, decode `raw_body`, and hand off to push jobs.
- **`DomainEvent` base + `ShouldDispatchAfterCommit`** (Phase 1) — Phase 4's new events (`BitrixDealPushed`, `BitrixContactPushed`, `BitrixCompanyPushed` — optional, emit only if downstream phases want them) extend the same base.
- **`BaseCommand`** (Phase 1) — `BitrixBootstrapCommand`, `BitrixBackfillOrdersCommand`, `GdprEraseBitrixCustomerCommand` all extend this for correlation_id threading + console CID visibility.
- **`Auditor`** (Phase 1) — logs `CrmFieldMapping` changes + GDPR-erasure events + `CrmPipelineSettings` edits into the existing `audit_log` table.
- **`IntegrationLogger`** (Phase 1) — every Bitrix HTTP call wraps in an integration_events write with redacted auth headers. Satisfies CRM-11 (push log).
- **`ApplySuggestionJob`** + **`SuggestionApplier` contract** (Phase 1 D-15, D-17) — Phase 4 registers `CrmPushRetryApplier` against kind `crm_push_failed`. First real producer of the seam (Phase 1 stub becomes real here).
- **`AlertRecipient` model + Notifiable** (Phase 1 D-12) — CRM DLQ alert routing. Extend with `receives_crm_alerts` boolean column (Phase 2 D-08 pattern) OR reuse same default list — planner decides.
- **`crm-bitrix` Horizon supervisor** (Phase 1 Plan 05) — already booted; Phase 4 dispatches onto it.
- **`ThrottledFailedJobNotifier`** (Phase 1) — 5-min dedup on repeated Bitrix failures prevents alert storms during a Bitrix outage.
- **Shield RBAC pattern** — seeder LIKE patterns (`%_crm_%` already matched by admin role) auto-attach new CRM Resources after `shield:generate` re-run (Phase 1 P02 lesson).

### Established Patterns (from Phase 1 + 2 + 3 SUMMARY files)

- **Migration timestamps** — Phase 3 at `2026_04_19_*`; Phase 4 at `2026_04_20_XXXXXX` (planner picks exact values).
- **Domain layout** — `app/Domain/CRM/` (currently `.gitkeep`) gets populated: `Models/` (`BitrixEntityMap`, `CrmFieldMapping`, `CrmStatusMapping`, `CrmPipelineSettings`, `CrmPushLog`), `Services/` (`BitrixClient`, `BitrixFieldSchemaCache`, `EntityDeduper`), `Jobs/` (`PushOrderToBitrixJob`, `PushCustomerToBitrixJob`, `UpdateDealStageJob`, `BitrixBackfillChunkJob`), `Listeners/` (on the two Phase 1 events), `Filament/Resources/`, `Filament/Pages/`, `Console/Commands/`, `Policies/`, `Events/`.
- **Deptrac layer** — new `CRM` layer allowed to depend on `Foundation` (Auditor, IntegrationLogger, DomainEvent, BaseCommand), `Webhooks` (subscribes to OrderReceived + CustomerRegistered), `Suggestions` (first real producer). NOT allowed to depend on `Sync`, `Pricing`, `Products`, `Competitor`, `Feeds`. Extend `depfile.yaml` + add `DeptracCrmLayerTest`.
- **Filament Resource pattern** — `CrmFieldMappingResource` (Deal/Contact/Company, admin-only), `CrmStatusMappingResource` (admin-only), `CrmPushLogResource` (read-only for sales, admin CRUD), `SuggestionResource` already exists (Phase 1) — Phase 4 just makes it produce real rows.
- **Filament Settings Pages** — `CrmPipelineSettingsPage` for pipeline_id + landing_stage_id (admin-only). Pattern: custom page extending `Filament\Pages\Page`, backed by the `spatie/laravel-settings` package OR a singleton `crm_settings` table — planner decides.
- **Policy template integrity** — Phase 2's `tests/Architecture/PolicyTemplateIntegrityTest` auto-checks all Phase 4 policies for `{{ Placeholder }}` Shield stub leaks.
- **Testing DB isolation** — `meetingstore_ops_testing` MySQL DB (Phase 1 P03). Phase 4 tests follow the same pattern.
- **`->authorize()` mandatory on Filament Actions** — applies to the "Refresh from Bitrix" button, GDPR erasure action, and the `crm_push_failed` suggestion's Replay action.
- **Migration + nullable column pattern** (Pitfall 7) — any nullable column on new CRM tables must have a backfill path; `BitrixEntityMap.bitrix_id` is NOT nullable (must be populated on first successful push).

### Integration Points

- **Inbound:** `OrderReceived` event → `HandleOrderReceivedListener` → dispatch `PushOrderToBitrixJob` (crm-bitrix queue). Same pattern for `CustomerRegistered` → `PushCustomerToBitrixJob`.
- **Outbound:** `BitrixClient::dealAdd()` / `dealUpdate()` / `contactAdd()` / `contactUpdate()` / `companyAdd()` / `dealUserfieldList()` / `dealUserfieldAdd()` — every call writes to `integration_events` via `IntegrationLogger` and updates `BitrixEntityMap` on success.
- **New migrations:** `bitrix_entity_map` (dedup ledger), `crm_field_mappings` (Deal/Contact/Company field map), `crm_status_mappings` (Woo-status → Deal-stage map), `crm_pipeline_settings` (pipeline_id + landing_stage_id singleton), `crm_push_logs` (per-attempt request/response/latency — OR derivable from `integration_events` filtered by provider, planner decides), `bitrix_backfill_runs` (progress tracking for `bitrix:backfill-orders` — mirrors `sync_runs` shape).
- **New Filament Resources / Pages:** `CrmFieldMappingResource`, `CrmStatusMappingResource`, `CrmPushLogResource` (read-only list + replay action), `CrmPipelineSettingsPage`, plus "Refresh Bitrix Schema" button on the field-mapping page.
- **Existing seam re-used:** `SuggestionResource` (Phase 1) now displays real `crm_push_failed` rows; `AlertRecipient` Filament Resource (Phase 1) gets a new `receives_crm_alerts` toggle.
- **Domain:** `app/Domain/CRM/` currently empty — Phase 4 populates it from zero.

</code_context>

<specifics>
## Specific Ideas

- **Parity over invention.** The extracted legacy plugin in `woo project/bitrix24-extracted/` IS the spec for fields, defaults, and UX patterns that MeetingStore ops already understand. Port the field-mapping defaults from `includes/CrmFields.php` into the `crm_field_mappings` seeder; replicate the status-map idea from `functions/itglx-wcbx24-update-deal-stage.php`; match the Deal TITLE shortcodes (`{order_number}`, `{order_date}`, `{billing_first_name}`, etc.) the legacy plugin exposes. Every deviation from legacy behaviour must be explicit and justified.
- **`BitrixEntityMap` is the first Phase 4 deliverable, alongside `bitrix:bootstrap`.** Pitfall 6 is CRITICAL severity. Without the map + `UF_CRM_WOO_ORDER_ID`, the first order.created replay will silently create a duplicate Deal. Planner: map table migration + bootstrap command are Plan 01, before any push code.
- **Webhook handlers MUST be idempotent by design.** The Phase 1 `(source, delivery_id)` unique index catches exact retries; Phase 4 `BitrixEntityMap` catches semantically-duplicate events (e.g. `order.updated` fired twice with different delivery IDs). Both layers of dedup are required — see Pitfall 6 "Bitrix Contact count grows faster than unique-customer count" warning sign.
- **UTM cookie name + JS snippet are Phase 4 deliverables on the WORDPRESS side.** Planner must coordinate with the meetingstore.co.uk WP theme/snippets owner to deploy the JS. The snippet is small (~30 lines) but is the on-ramp for all UTM data — without it, CRM-09 is not met. Document the cookie name (`ms_utm_first_touch` proposed), TTL (30 days), and Bitrix custom-field names so the WP side can align.
- **`bitrix:bootstrap` is NOT a migration** — it's an idempotent artisan command run during every deploy (Phase 1 D-03 Shield-seeder pattern). Creates `UF_CRM_WOO_ORDER_ID` + any other custom fields Phase 4 requires in Bitrix (proposed: `UF_CRM_WOO_UTM_SOURCE` etc. — 6 fields for D-03, plus `UF_CRM_WOO_CUSTOMER_ID` on Contact for cross-reference). Fails hard if auth broken.
- **Shadow-mode gate for Bitrix** (`CRM_WRITE_ENABLED`, Claude's Discretion) is non-trivial — design it alongside the Phase 7 shadow-mode monitoring rather than retrofit. Planner: include in Plan 04 (the final Phase 4 plan) so the Phase 7 divergence-scan hookpoint exists from day one.
- **Status-map admin UX** — the seed must cover the standard Woo 7 statuses. Meeting Store likely uses all of them; audit the live store + legacy plugin config before finalising the seed.
- **GDPR erasure audit trail is itself subject to retention** — the `audit_log` retention is 365 days (Phase 1 D-04). A customer's erasure event must survive that window for regulator queries. Planner decides: either bump retention for `audit_log` rows with `log_name = 'gdpr_erasure'` (keep indefinitely) or spin off a dedicated `gdpr_erasure_log` table.

</specifics>

<deferred>
## Deferred Ideas

These came up during discussion or in related research but are explicitly scoped out of Phase 4 to keep the cutover-unblock goal tight:

- **B2B-vs-retail dynamic pipeline routing** — legacy plugin doesn't do this; ops haven't asked. Defer; if needed, add a small routing-rules table post-cutover.
- **Full rule-driven pipeline routing** (match-criteria table with priorities, Phase-3-pricing-rule style) — overkill for v1 parity; post-cutover enhancement only.
- **`gclid` + `fbclid` offline-conversion capture** — defer to Phase 8+ ads-sync work when there's a concrete ad-platform integration need.
- **Two-way status sync (Bitrix → Woo)** — explicit Out of Scope in REQUIREMENTS.md and PROJECT.md. Document-only.
- **Inbound Bitrix webhook** — explicit Out of Scope. Document-only.
- **Coupon list sync** — explicit Out of Scope. Document-only.
- **Read-only mirror of Bitrix Deal status back into Laravel for dashboard** (research E.3 "borderline scope creep" item) — not requested by ops; defer to Phase 7 dashboard polish discussion if useful.
- **`order.deleted` webhook handling** — rare event; cancellations flow via `order.updated` status-map. Add only if ops reports dropped deletions.
- **Bitrix Lead entity mode** — legacy plugin supports Lead-only or Contact-only modes; fixed to Deal+Contact+Company per PROJECT.md Key Decision.
- **Custom Woo order statuses auto-discovery** — status-map seed covers the standard 7; custom statuses (if any on meetingstore.co.uk) require admin to add a row manually. Auto-discovery is a nice-to-have for Phase 7 dashboard polish.
- **Full-field re-push on `order.updated`** — rejected in D-09 to protect Bitrix manual edits; if ops later reports Bitrix drifting from Woo, reconsider.
- **Pipeline routing settings in env vars** — rejected in D-07 in favour of Filament UI (meets CRM-06 "no code edits").

### Reviewed Todos (not folded)

No pending todos matched Phase 4 scope at the time of this discussion — none to defer.

</deferred>

---

*Phase: 04-bitrix24-crm-sync*
*Context gathered: 2026-04-19*
