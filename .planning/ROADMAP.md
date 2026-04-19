# Roadmap: MeetingStore Ops

## Overview

Seven-phase greenfield build of a Laravel 12 + Filament 3 source-of-truth ops app that replaces two legacy WordPress plugins on `meetingstore.co.uk` — the in-house Stock Updater (supplier sync + competitor CSV) and the sanctions-blocked itgalaxycompany Bitrix24 integration (stuck on v1.50.1). Journey: lay modular-monolith foundation with event bus + audit + suggestions seam + shadow-mode write gate (Phase 1) → restore the daily supplier sync that keeps Woo stock fresh (Phase 2) → introduce a rule-driven VAT-inclusive pricing engine with golden-fixture parity vs the legacy plugin (Phase 3) → cut the sanctions-compliance dependency by shipping the one-way Bitrix24 CRM sync ahead of the original ordering (Phase 4) → add competitor intelligence that produces the first real Suggestions (Phase 5) → auto-create new Woo products from supplier SKUs (Phase 6) → polish the dashboard, run shadow-mode parity, and flip the cutover flag to disable the legacy plugins (Phase 7). Every phase delivers a coherent, independently verifiable capability; every v1 requirement maps to exactly one phase.

## Phases

**Phase Numbering:**
- Integer phases (1, 2, 3): Planned milestone work
- Decimal phases (2.1, 2.2): Urgent insertions (marked with INSERTED)

Decimal phases appear between their surrounding integers in numeric order.

- [ ] **Phase 1: Foundation** — Laravel + Filament + Horizon skeleton with modular domain structure, audit/integration/suggestions seams, RBAC, HMAC webhook middleware, and the `WOO_WRITE_ENABLED` shadow-mode flag
- [ ] **Phase 2: Supplier Sync** — Daily resumable supplier pull, per-item Woo REST push with error capture, emailed CSV report, and Filament sync-status + import-issues pages
- [x] **Phase 3: Pricing Engine** — Most-specific-wins `PricingRule` resolver, integer-pennies VAT-inclusive calculator, per-product overrides, and a golden-fixture parity test against the legacy plugin
 (completed 2026-04-19)
- [ ] **Phase 4: Bitrix24 CRM Sync** — One-way Woo→Bitrix push of Deal + Contact + Company on order/customer events, dynamic field mapping, UTM/GA capture, backfill command, and GDPR erasure
- [ ] **Phase 5: Competitor Analysis** — CSV watcher with BOM-safe ingest, full-history `competitor_prices`, margin-delta analyser producing Suggestions, trend/deltas dashboards
- [ ] **Phase 6: Product Auto-Create** — New-SKU detection, SEO-templated draft Woo products, image pipeline + placeholder flow, review inbox with completeness scoring, and `ProductOverride` pin UI
- [ ] **Phase 7: Dashboard Polish + Cutover** — Home health tiles, notification centre, global search, weekly reports, shadow-mode divergence scan, legacy-plugin crons deregistered, rollback drill, ops handover

## Phase Details

### Phase 1: Foundation
**Goal**: A Laravel 12 + Filament 3 admin app is running on the target VPS with all cross-cutting infrastructure in place so no later phase needs to retrofit audit, events, suggestions, HMAC, or shadow-mode.
**Depends on**: Nothing (first phase)
**Requirements**: FOUND-01, FOUND-02, FOUND-03, FOUND-04, FOUND-05, FOUND-06, FOUND-07, FOUND-08, FOUND-09, FOUND-10, FOUND-11, FOUND-12, FOUND-13
**Success Criteria** (what must be TRUE):
  1. An admin can log into the Filament panel at `ops.meetingstore.co.uk` and see role-gated navigation matching their assigned role (admin / pricing_manager / sales / read_only)
  2. A Deptrac CI run fails the build when a test PR introduces a cross-domain import between two `app/Domain/<Module>/` folders
  3. An operator can POST a signed Woo webhook fixture to `/webhooks/woo/order` and observe a row in `webhook_receipts` with correct HMAC verification, dedup by `X-WC-Webhook-Delivery-ID`, and a queued handler dispatched within 200ms
  4. With `WOO_WRITE_ENABLED=false` (the default), any code path calling `WooClient::put()` writes to the `sync_diffs` table instead of touching Woo — verified by a feature test
  5. A Horizon supervisor config boots with all seven workload queues (`critical`, `sync-woo-push`, `sync-bulk`, `crm-bitrix`, `competitor-csv`, `webhook-inbound`, `default`) visible in the Horizon dashboard; a deliberately-failed job triggers an admin Slack/email alert
  6. The Filament suggestions inbox is reachable, empty, and an admin can open a seeded test suggestion and approve/reject it (the apply path is stubbed — Phase 5 is the first real producer)
**Plans**: 5 plans
Plans:
- [x] 01-01-scaffold-PLAN.md — Bootstrap Laravel 12, install Phase 1 composer packages, create app/Domain + app/Foundation skeleton, wire Deptrac module-boundary ruleset, ship FeedGenerator contract stub (FOUND-13), register webhooks route file (FOUND-02, FOUND-13)
- [x] 01-02-rbac-PLAN.md — Install Filament Shield, add HasRoles to User, run shield:generate, write idempotent RolePermissionSeeder with D-02 role split (FOUND-01)
- [x] 01-03-foundation-PLAN.md — AttachCorrelationId middleware + DomainEvent base class + Auditor service + integration_events migration + IntegrationLogger with header redaction + BaseCommand + Context::hydrated callback for queue boundary propagation (FOUND-03, FOUND-04, FOUND-05)
- [x] 01-04-seams-PLAN.md — webhook_receipts + suggestions + sync_diffs migrations; VerifyWooHmacSignature middleware + WooWebhookController + OrderReceived/CustomerRegistered events; SuggestionApplier contract + StubApplier + ApplySuggestionJob + Filament SuggestionResource (admin-only) + seeded test suggestion; WooClient skeleton with WOO_WRITE_ENABLED shadow-mode gate (FOUND-06, FOUND-07, FOUND-08)
- [x] 01-05-horizon-alerting-PLAN.md — Horizon config with 7 supervisors + admin-only gate; AlertRecipient model + Filament Resource (admin-only) + AlertDistribution Notifiable + ThrottledFailedJobNotifier 5-min dedup listener; 3 retention prune commands (activity log, integration events, sync_diffs conditional) + schedule; CI pipeline (Deptrac + Pest + Larastan + Pint) (FOUND-09, FOUND-10, FOUND-11, FOUND-12)
**UI hint**: yes

### Phase 2: Supplier Sync
**Goal**: The daily supplier sync from `21stcav.com` replaces the legacy Stock Updater plugin — a crashed run resumes cleanly, per-item failures are captured instead of dropped, Woo is written only through REST, and ops receive an emailed CSV report on completion.
**Depends on**: Phase 1 (needs events, audit log, Woo write gate, Horizon supervisors, `WooClient` skeleton)
**Requirements**: SYNC-01, SYNC-02, SYNC-03, SYNC-04, SYNC-05, SYNC-06, SYNC-07, SYNC-08, SYNC-09, SYNC-10, SYNC-11, SYNC-12, SYNC-13
**Success Criteria** (what must be TRUE):
  1. An operator can run `php artisan sync:supplier --dry-run` and receive an emailed CSV report with updated / skipped / failed counts per-SKU, with zero writes to Woo (all diffs captured in `sync_diffs`)
  2. Killing the queue worker mid-run and running `php artisan sync:supplier --resume={run_id}` continues from the last processed cursor with no duplicate pushes or skipped SKUs
  3. An architectural test fails the build if any code path calls the WordPress DB connection directly instead of `WooClient` (SYNC-04 enforcement)
  4. A SKU missing from the supplier's response flips to Woo status `pending` unless it carries the `custom-ms` tag (which stays `publish`), and `_exclude_from_auto_update` products appear in the report as "skipped" with correct counts
  5. The Filament "Supplier Sync Status" page shows the last run's duration, updated/failed counts, and a per-SKU drill-down; the "Import Issues" page lists missing-at-supplier SKUs, pending products, and products with missing cost/price
  6. Domain events `SupplierPriceChanged`, `SupplierStockChanged`, and `SupplierSkuMissing` fire on the event bus after each successful row update (observable in `integration_events` with matching `correlation_id`)
**Plans:** 5 plans
Plans:
- [x] 02-01-data-model-PLAN.md — Schema + Eloquent + 5 policies + factories for Product/ProductVariant/SyncRun/SyncError/SyncRunItem/ImportIssue (D-01 expansion, SYNC-03/05/06/09/12) ✅ 2026-04-18 — 2 commits (3b2ab98 + 381ca21), 18 tests, 6 tables migrated
- [x] 02-02-external-clients-PLAN.md — Install automattic/woocommerce + spatie/simple-excel; extend WooClient with get() + writeLive() 429 backoff; ship SupplierClient with JWT Cache::remember + retry-once-on-401 (SYNC-01, SYNC-02, SYNC-04, SYNC-10)
- [x] 02-03-orchestration-PLAN.md — ShouldDispatchAfterCommit retrofit + 4 domain events + WooProductIterator + SkuMatcher + AbortGuard + SyncDiffEngine + SyncChunkJob + MarkMissingSkusJob + SyncSupplierCommand with --live/--dry-run/--resume (SYNC-01/03/05/06/07/09/10/13, D-04..D-09)
- [x] 02-04-reporting-ui-PLAN.md — D-08 receives_sync_reports migration + SyncReportCsvGenerator (D-10 11 cols) + SupplierSyncReportMail + SyncRunResource + ImportIssueResource + ProductResource + shield:generate audit (SYNC-08, SYNC-11, SYNC-12)
- [x] 02-05-guardrails-PLAN.md — Deptrac WpDirectDb layer + PolicyTemplateIntegrityTest permanent guardrail + sync-errors:prune command + 02-VERIFICATION.md (SYNC-04)
**UI hint**: yes

### Phase 3: Pricing Engine
**Goal**: Supplier prices flow through a rule-driven engine that computes final VAT-inclusive Woo prices with penny-exact parity against the legacy plugin, and a pricing manager can preview the effective price for any SKU before changing a rule.
**Depends on**: Phase 2 (needs supplier prices landing in the DB and `SupplierPriceChanged` event firing)
**Requirements**: PRCE-01, PRCE-02, PRCE-03, PRCE-04, PRCE-05, PRCE-06, PRCE-07, PRCE-08, PRCE-09, PRCE-10
**Success Criteria** (what must be TRUE):
  1. The golden-fixture parity test covering 50 (supplier_price, margin, expected_final) triples from the legacy plugin passes to the penny — the build fails if any triple drifts, and this test is the Phase 3 ship gate
  2. A pricing manager can open the Filament rule explorer, type any SKU, and see the effective price with the full resolution chain displayed (`brand+category → brand → category → default tier`), and a per-product override row takes precedence over all rules
  3. Editing a `PricingRule` and previewing "simulated impact" lists the SKUs that would change before the rule is saved
  4. A `SupplierPriceChanged` event fired by Phase 2's sync causes a listener to recompute the final price via `PriceCalculator` (integer-pennies / BCMath) and fire `ProductPriceChanged` only when the output differs
  5. `php artisan pricing:recompute --all` dispatches a queued batch that recomputes every product's final price and surfaces progress in Horizon
**Plans:** 5/5 plans complete
Plans:
- [x] 03-01-data-model-calculator-PLAN.md — config/pricing.php + PriceCalculator (integer-pennies, HALF_UP, pure) + 50-triple golden fixtures + pricing_rules/product_overrides migrations + policies + factories + DefaultPricingTierSeeder (PRCE-01/03/04/05/06, D-01..D-09)
- [x] 03-02-resolver-listener-event-PLAN.md — RuleResolver (most-specific-wins, priority+id tiebreak, purity-tested) + brand_id/category_id columns on products + ProductPriceChanged event + RecomputePriceListener (default queue, D-13 penny-diff gate, D-10 zero-price ImportIssue) + EventServiceProvider wiring (PRCE-02, PRCE-07)
- [x] 03-03-filament-rule-explorer-PLAN.md — PricingRuleResource + ProductOverrideResource (role-gated) + Rule Explorer page (SKU → effective price + chain) + Simulated Impact page (transactional dry-run projection) + SimulatedImpactCalculator + seeder LIKE patterns + PolicyTemplateIntegrityTest extended (PRCE-08, PRCE-09)
- [x] 03-04-bulk-recompute-command-PLAN.md — PriceRecomputer shared core (listener + bulk both delegate) + RecomputePriceJob (ShouldQueue+ShouldBeUnique, sync-bulk queue) + pricing:recompute command (dry-run default D-12, --live opt-in, --only/--brand/--category scopes) (PRCE-10)
- [x] 03-05-guardrails-verification-PLAN.md — Deptrac Pricing layer (Foundation+Products+Sync allow-list) + DeptracPricingLayerTest + PricingRuleExclusiveSetTest + PriceCalculatorPurityTest + 03-VERIFICATION.md ship verdict
**UI hint**: yes

### Phase 4: Bitrix24 CRM Sync
**Goal**: The sanctions-blocked itgalaxy plugin is replaced by a one-way Woo→Bitrix24 sync — orders and customer registrations create deduplicated Deal + Contact + Company records, admins map fields in the UI (no code edits), and historical orders can be backfilled idempotently.
**Depends on**: Phase 1 (needs webhook HMAC middleware, dedup infrastructure, integration log, suggestions seam for the push-failure DLQ — **does NOT depend on Phase 2 or 3**; CRM cutover is independent of supplier-sync cutover, which is why this is prioritised over competitor/auto-create)
**Requirements**: CRM-01, CRM-02, CRM-03, CRM-04, CRM-05, CRM-06, CRM-07, CRM-08, CRM-09, CRM-10, CRM-11, CRM-12, CRM-13
**Success Criteria** (what must be TRUE):
  1. A live Woo checkout order creates a Bitrix Deal + Contact + Company visible in Bitrix within 30 seconds, with `UF_CRM_WOO_ORDER_ID` populated — verified by placing two identical test orders and confirming no duplicate Deal, Contact, or Company is created
  2. An admin can open the Filament field-mapping UI, see live `crm.deal.fields` / `crm.contact.fields` / `crm.company.fields` loaded from Bitrix (with a "Refresh from Bitrix" button and 24h cache), and remap a Woo order field to a different Bitrix field without touching code
  3. `php artisan bitrix:backfill-orders --since=2026-01-01 --dry-run` replays historical orders through `BitrixEntityMap` with zero duplicates on a second (non-dry-run) pass
  4. UTM parameters and GA Client ID captured at Woo checkout appear on the resulting Bitrix Deal's configured custom fields; order notes appear as Deal comments; pipeline routing rules send B2B orders to a different Bitrix pipeline than retail orders
  5. A Bitrix API outage causes the push to retry N times, land in the dead-letter queue, and surface as a `suggestions('crm_push_failed')` row with a Filament "replay" action; the CRM push log shows every attempt (request/response/latency/retry count)
  6. `php artisan gdpr:erase-bitrix-customer --email=...` scrubs PII from the matched Bitrix Contact and related Deal, with the action recorded in the audit log
**Plans:** 5 plans
Plans:
- [x] 04-01-data-model-bootstrap-PLAN.md — SDK install (bitrix24/b24phpsdk ^1.10) + 6 migrations (bitrix_entity_map Pitfall-6 ledger + crm_field_mappings + crm_status_mappings + crm_pipeline_settings + bitrix_backfill_runs + sync_diffs.provider) + 5 Eloquent models + 5 policies + factories + CrmStatusMappingSeeder + BitrixClient skeleton + bitrix:bootstrap + bitrix:smoke-test commands (CRM-01, CRM-02)
- [x] 04-02-bitrix-client-wrapper-PLAN.md — Full BitrixClient SDK wrapper (18 methods) + shadow-mode gate (CRM_WRITE_ENABLED=false → sync_diffs provider=bitrix) + BitrixRateLimitMiddleware (Guzzle 2 req/sec + 429 Retry-After) + BitrixTransient/PermanentException (D-11) + BitrixSchemaCache (24h TTL) + bitrix:schema:refresh + EntityDeduper (4-step cascade) + Deptrac CRM→Sync/Alerting/Webhooks/Suggestions (CRM-02, CRM-04, CRM-05)
- [x] 04-03-webhook-listeners-push-jobs-PLAN.md — HandleOrderReceived + HandleCustomerRegistered listeners on crm-bitrix queue + PushOrderToBitrixJob (Company→Contact→Deal; D-10 release(30); D-09 narrow status patch; D-11 3-attempt backoff; D-12 DLQ suggestion producer) + PushCustomerToBitrixJob + UpdateDealStageJob + 3 payload builders + UtmExtractor + OrderNoteSynchroniser + CrmPushRetryApplier (first real SuggestionApplier producer) + 3 domain events + 40-row CrmFieldMappingSeeder + AlertRecipient.receives_crm_alerts (CRM-03, CRM-04, CRM-05, CRM-08, CRM-09, CRM-12)
- [x] 04-04-filament-ui-PLAN.md — CrmFieldMappingResource (Deal/Contact/Company tabs + Refresh from Bitrix + per-save schema-validate) + CrmStatusMappingResource (pipeline-filtered stage picker) + CrmPushLogResource (read-only; sales-role visible) + CrmPipelineSettingsPage singleton + Replay action on SuggestionResource for crm_push_failed + AlertRecipient form toggle + shield:generate audit + RolePermissionSeeder LIKE patterns + human-verify checkpoint (CRM-02, CRM-06, CRM-07, CRM-11)
- [x] 04-05-backfill-gdpr-guardrails-PLAN.md — bitrix:backfill-orders (dry-run default + --live + --adopt-legacy-deal-ids per Pitfall 5 + --since required + 50/chunk + 600ms sleep on sync-bulk) + BackfillOrdersChunkJob + gdpr:erase-bitrix-customer + EraseCustomerAction (admin-only with ERASE phrase) + GdprEraser (18 Contact PII + 4 Deal PII fields; preserve OPPORTUNITY + UF_CRM_WOO_ORDER_ID + STAGE_ID) + gdpr_erasure_log indefinite-retention table + DeptracCrmLayerTest + docs/wordpress-snippets/ (JS + PHP hook + README) + 04-VERIFICATION.md ship verdict (CRM-10, CRM-12, CRM-13)
**UI hint**: yes

### Phase 5: Competitor Analysis
**Goal**: n8n-dropped competitor CSVs are ingested with full history (never truncated), margin-delta analysis produces noise-suppressed margin-change Suggestions tied to real pricing rules, and a pricing manager can see trend charts, biggest deltas, and per-competitor views.
**Depends on**: Phase 2 (needs supplier prices for margin calculation) + Phase 3 (needs `PricingRule` to propose changes against) + Phase 1 (needs suggestions inbox)
**Requirements**: COMP-01, COMP-02, COMP-03, COMP-04, COMP-05, COMP-06, COMP-07, COMP-08, COMP-09, COMP-10, COMP-11, COMP-12
**Success Criteria** (what must be TRUE):
  1. An n8n CSV dropped into `storage/app/competitors/` (using the atomic `.tmp → rename` convention with mtime > 30s) is detected by the scheduled watcher, ingested with auto-detected `sku|mpn` + `price` columns — regardless of UTF-8 BOM, Windows-1252 encoding, or European decimal formats — and every row appears in `competitor_prices` with history preserved
  2. Per-row parse errors from a malformed CSV row are captured in `csv_parse_errors` and shown in a Filament "CSV Ingest Issues" page (never silently discarded)
  3. When a competitor's margin delta exceeds the 8% threshold AND is corroborated by ≥3 consecutive scrapes AND ≥N sales in the last 90 days, a `margin_change` suggestion is created; approving it updates the matching `PricingRule`, fires `PricingRuleChanged`, and writes an audit-log entry with the full evidence trail
  4. The Filament "Competitor Analysis" page shows price trend charts per SKU, biggest margin deltas across the catalogue, and a per-competitor view; a stale-feed warning fires when a competitor hasn't reported in >48 hours
  5. Competitor CSV source files older than 90 days (configurable) are pruned by a scheduled command, with the prune action logged
**Plans:** 6 plans
Plans:
- [ ] 05-01-data-model-admin-crud-PLAN.md — 7 migrations (competitors, competitor_csv_mappings, competitor_ingest_runs, competitor_prices, csv_parse_errors, +receives_competitor_alerts, +products.last_sales_count_90d) + 5 Eloquent models + 5 policies + 5 factories + config/competitor.php (COMP-07 schema)
- [ ] 05-02-csv-ingest-pipeline-PLAN.md — CompetitorWatchCommand + IngestCompetitorCsvJob + CompetitorCsvChunkJob + EncodingDetector + DecimalFormatDetector + ColumnHeuristicDetector + PriceParser + OrphanDetector (D-09 dedup) + NewProductOpportunityApplier stub + CompetitorPriceRecorded event + docs/n8n-integration/README.md (COMP-01..COMP-07)
- [ ] 05-03-margin-analyser-suggestion-producers-PLAN.md — PricingRuleChanged event (A1 backport) + PricingRule observer + SalesCounterService + IncrementSkuSalesCount listener + MarginAnalyser (P5-E min-margin-floor guard) + DispatchMarginAnalyserJob (24h Cache::add debounce) + ComputeMarginSuggestionJob + MarginChangeApplier + CompetitorSalesRecacheCommand (COMP-08, COMP-09)
- [ ] 05-04a-filament-resources-and-rbac-PLAN.md — 3 read-only Filament Resources (CompetitorPrice + CompetitorIngestRun + CsvParseError) + SuggestionResource kind-specific Approve actions (margin_change + new_product_opportunity) + AlertRecipientResource receives_competitor_alerts toggle + shield:generate restoration protocol (P5-F) + RolePermissionSeeder LIKE-pattern extension + PolicyTemplateIntegrityTest floor bump (COMP-05, COMP-09)
- [ ] 05-04b-filament-pages-stale-feed-PLAN.md — CompetitorAnalysisPage (SkuPriceTrendChart + BiggestMarginDeltasTable with W4 null-safety + StaleFeedTrafficLight + per-competitor tabs) + CsvIngestIssuesPage (4-tab with Quarantine resolve) + CompetitorCheckStaleCommand hourly + StaleFeedNotification + CompetitorDemoSeeder + human-verify checkpoint (COMP-10, COMP-11)
- [ ] 05-05-retention-guardrails-verification-PLAN.md — CompetitorCsvPruneCommand (90d archive retention, NEVER touches competitor_prices) + Deptrac Competitor layer [Foundation, Pricing, Products, Suggestions, Alerting] + DeptracCompetitorLayerTest + CompetitorPricesNeverPrunedTest + 05-VERIFICATION.md ship verdict (COMP-12)
**UI hint**: yes

### Phase 6: Product Auto-Create
**Goal**: A new SKU appearing in the supplier feed triggers a draft Woo product with SEO-templated content, a sourced or placeholder image, and a review inbox — and human edits in Woo admin can be pinned via `ProductOverride` so the next sync doesn't overwrite them.
**Depends on**: Phase 2 (detects new SKUs) + Phase 3 (computes final prices) + Phase 5 (suggestions seam proven on a simpler use case first) + Phase 1 (override infrastructure)
**Requirements**: AUTO-01, AUTO-02, AUTO-03, AUTO-04, AUTO-05, AUTO-06, AUTO-07, AUTO-08, AUTO-09, AUTO-10, AUTO-11
**Success Criteria** (what must be TRUE):
  1. A new supplier SKU with no matching Woo product triggers `NewSupplierSkuDetected`, and `CreateWooProductJob` creates a draft Woo product with title/slug/meta description/long description/brand/category populated from the SEO template; slug uniqueness is guaranteed and casing-only duplicates are rejected
  2. An auto-created product's image is sourced from the supplier DB when available, otherwise a placeholder image is used and the product is flagged "manual image review required" in the inbox; every image is resized, converted to WebP, and EXIF-stripped before upload
  3. The Filament auto-create review inbox shows each draft with a completeness score, supports bulk approve/edit, and records rejection reasons when rejected; draft-first is the v1 default and immediate-publish is gated by an admin config flag
  4. On the product edit page, an admin can toggle per-field pins (title, description, image) via `ProductOverride`, and the next supplier sync leaves pinned fields untouched — observable via a regression test that runs a sync after pinning and asserts unchanged content
  5. Every `CreateWooProductJob` attempt writes to `integration_events` with request/response/latency, and a failed attempt retries per Horizon policy before surfacing in the notification centre
**Plans:** 5 plans
Plans:
- [x] 02-01-data-model-PLAN.md — Schema + Eloquent + 5 policies + factories for Product/ProductVariant/SyncRun/SyncError/SyncRunItem/ImportIssue (D-01 expansion, SYNC-03/05/06/09/12)
- [ ] 02-02-external-clients-PLAN.md — Install automattic/woocommerce + spatie/simple-excel; extend WooClient with get() + writeLive() 429 backoff; ship SupplierClient with JWT Cache::remember + retry-once-on-401 (SYNC-01, SYNC-02, SYNC-04, SYNC-10)
- [ ] 02-03-orchestration-PLAN.md — ShouldDispatchAfterCommit retrofit + 4 domain events + WooProductIterator + SkuMatcher + AbortGuard + SyncDiffEngine + SyncChunkJob + MarkMissingSkusJob + SyncSupplierCommand with --live/--dry-run/--resume (SYNC-01/03/05/06/07/09/10/13, D-04..D-09)
- [ ] 02-04-reporting-ui-PLAN.md — D-08 receives_sync_reports migration + SyncReportCsvGenerator (D-10 11 cols) + SupplierSyncReportMail + SyncRunResource + ImportIssueResource + ProductResource + shield:generate audit (SYNC-08, SYNC-11, SYNC-12)
- [ ] 02-05-guardrails-PLAN.md — Deptrac WpDirectDb layer + PolicyTemplateIntegrityTest permanent guardrail + sync-errors:prune command + 02-VERIFICATION.md (SYNC-04)
**UI hint**: yes

### Phase 7: Dashboard Polish + Cutover
**Goal**: The dashboard graduates from per-phase pages to a unified home, the shadow-mode divergence scan establishes parity with the legacy plugins, the old plugin crons are deregistered, the flag is flipped, and Laravel runs solo under observation before the legacy plugins are disabled.
**Depends on**: All previous phases (nothing to cut over without them)
**Requirements**: DASH-01, DASH-02, DASH-03, DASH-04, DASH-05, DASH-06, CUT-01, CUT-02, CUT-03, CUT-04, CUT-05, CUT-06, CUT-07
**Success Criteria** (what must be TRUE):
  1. The Filament home dashboard shows health tiles at a glance (last sync time/duration, failed jobs, pending review count, CRM push failures, stale competitor feeds), with Horizon linked from the header and global search jumping to any product / rule / CRM log entry
  2. The shadow-mode monitoring dashboard compares Laravel-computed values against Woo's live values over a configurable window and reports a parity pass/fail against the configured threshold; a pre-cutover divergence scan auto-populates `ProductOverride` rows for every field where a human edit in Woo differs from Laravel's computed value
  3. The rollback drill is rehearsed end-to-end: flip `WOO_WRITE_ENABLED=false`, restore the Woo DB snapshot from a fresh dump, confirm the legacy plugin crons re-engage cleanly, and the runbook is updated with any gaps found during the drill
  4. The Stock Updater and itgalaxy Bitrix24 plugins are disabled in WordPress only after a monitored parallel-run window passes the parity threshold; the `wp_unschedule_event` commands have successfully removed the legacy crons before Laravel writes were enabled
  5. Laravel has run solo for 7 consecutive days without divergence alarms, the weekly scheduled report has landed in the admin distribution list, the ops handover docs cover resume-a-sync / replay-a-failed-CRM-push / refresh-Bitrix-schema / interpret-the-notification-centre, and a tabular view exports a filtered CSV successfully
**Plans:** 5 plans
Plans:
- [ ] 02-01-data-model-PLAN.md — Schema + Eloquent + 5 policies + factories for Product/ProductVariant/SyncRun/SyncError/SyncRunItem/ImportIssue (D-01 expansion, SYNC-03/05/06/09/12)
- [ ] 02-02-external-clients-PLAN.md — Install automattic/woocommerce + spatie/simple-excel; extend WooClient with get() + writeLive() 429 backoff; ship SupplierClient with JWT Cache::remember + retry-once-on-401 (SYNC-01, SYNC-02, SYNC-04, SYNC-10)
- [ ] 02-03-orchestration-PLAN.md — ShouldDispatchAfterCommit retrofit + 4 domain events + WooProductIterator + SkuMatcher + AbortGuard + SyncDiffEngine + SyncChunkJob + MarkMissingSkusJob + SyncSupplierCommand with --live/--dry-run/--resume (SYNC-01/03/05/06/07/09/10/13, D-04..D-09)
- [ ] 02-04-reporting-ui-PLAN.md — D-08 receives_sync_reports migration + SyncReportCsvGenerator (D-10 11 cols) + SupplierSyncReportMail + SyncRunResource + ImportIssueResource + ProductResource + shield:generate audit (SYNC-08, SYNC-11, SYNC-12)
- [ ] 02-05-guardrails-PLAN.md — Deptrac WpDirectDb layer + PolicyTemplateIntegrityTest permanent guardrail + sync-errors:prune command + 02-VERIFICATION.md (SYNC-04)
**UI hint**: yes

## Progress

**Execution Order:**
Phases execute in numeric order: 1 → 2 → 3 → 4 → 5 → 6 → 7

| Phase | Plans Complete | Status | Completed |
|-------|----------------|--------|-----------|
| 1. Foundation | 0/5 | Not started | - |
| 2. Supplier Sync | 0/5 | Not started | - |
| 3. Pricing Engine | 5/5 | Complete    | 2026-04-19 |
| 4. Bitrix24 CRM Sync | 0/TBD | Not started | - |
| 5. Competitor Analysis | 0/TBD | Not started | - |
| 6. Product Auto-Create | 0/TBD | Not started | - |
| 7. Dashboard Polish + Cutover | 0/TBD | Not started | - |

## Research Flags

Captured from `research/SUMMARY.md` — informs whether `/gsd-research-phase` runs before planning:

| Phase | Research | Notes |
|-------|----------|-------|
| 1. Foundation | skip | Established Laravel + Filament + Horizon scaffolding patterns |
| 2. Supplier Sync | MAYBE | Confirm variable-product count with ops (drives `ProductVariant` modelling); Woo rate-limit ceiling |
| 3. Pricing Engine | light | 5-min ops conversation on rounding convention (plain 2dp vs `.99`/`.95` endings) before writing the golden fixtures |
| 4. Bitrix24 CRM Sync | YES | Validate `bitrix24/b24phpsdk` against sandbox before committing; UTM capture mechanism on Woo side; GDPR right-to-erasure workflow design |
| 5. Competitor Analysis | MAYBE | MAP-policy brand coverage check with buying team |
| 6. Product Auto-Create | YES | Supplier image-DB availability is the biggest single unknown — drives the whole image pipeline complexity |
| 7. Dashboard + Cutover | skip | Execution discipline, not novel research |

## Coverage

**v1 requirements mapped:** 85 / 85 ✓
- 13 FOUND → Phase 1
- 13 SYNC → Phase 2
- 10 PRCE → Phase 3
- 13 CRM → Phase 4
- 12 COMP → Phase 5
- 11 AUTO → Phase 6
- 6 DASH + 7 CUT → Phase 7

**Orphaned requirements:** 0
**Duplicate mappings:** 0

---
*Roadmap created: 2026-04-18*
*Granularity: coarse (7 phases — coarse target is 3-5; deferred-upward because the 7 domains are dependency-forced and cannot be compressed without losing coherent delivery boundaries, confirmed by research SUMMARY.md)*
