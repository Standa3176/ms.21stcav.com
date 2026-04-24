# Requirements: MeetingStore Ops

**Defined:** 2026-04-18
**Core Value:** One Laravel app owns product data, pricing rules, competitor intelligence and CRM sync — Woo is the display layer, nothing more.

## v1 Requirements

Categories reflect the 7-phase structure recommended in `research/SUMMARY.md`. Brief feature modules (A–F) map into SYNC / PRCE / COMP / AUTO / CRM / DASH; FOUND covers the cross-cutting infra research flagged as non-optional; CUT covers cutover.

### Foundation (FOUND)

- [x] **FOUND-01**: Laravel 12 + Filament 3.3 admin app boots with authenticated users and role-based access (admin / pricing_manager / sales / read_only)
- [x] **FOUND-02**: `app/Domain/<Module>/` layout enforced by Deptrac CI — cross-domain imports fail the build
- [x] **FOUND-03**: Domain event bus publishes events with a `correlation_id` that threads through audit, integration, and suggestion rows
- [x] **FOUND-04**: `audit_log` records every tracked-model change (actor, before, after, correlation_id) via `spatie/laravel-activitylog`
- [x] **FOUND-05**: `integration_events` records every outbound API attempt (endpoint, request, response, latency, status, correlation_id)
- [x] **FOUND-06**: `suggestions` table + Filament inbox let a human review, approve, or reject any proposed mutation before it's applied
- [x] **FOUND-07**: Inbound Woo webhooks are HMAC-verified, persisted raw, deduped by `X-WC-Webhook-Delivery-ID`, and queued within 200ms
- [x] **FOUND-08**: `WOO_WRITE_ENABLED` env flag (default `false`) routes every Woo write to a `sync_diffs` table instead of calling Woo
- [x] **FOUND-09**: Horizon supervisors segregate workload classes (`critical`, `sync-woo-push`, `sync-bulk`, `crm-bitrix`, `competitor-csv`, `webhook-inbound`, `default`) so long jobs never block webhooks
- [x] **FOUND-10**: Redis persistence is configured (`appendonly yes`, `appendfsync everysec`) so a VPS reboot does not lose in-flight sync chunks
- [x] **FOUND-11**: Failed Horizon jobs trigger an admin email/Slack alert via `spatie/laravel-failed-job-monitor`
- [x] **FOUND-12**: Retention policies are configured and enforced for `audit_log`, `integration_events`, competitor CSVs, `sync_errors` via scheduled prune commands
- [x] **FOUND-13**: `FeedGenerator` interface stub exists (empty contract) so Phase 8 channel feeds slot in without refactor

### Supplier sync (SYNC) — Module A

- [x] **SYNC-01**: A scheduled daily job pulls every Woo product's SKU + price + stock from the `21stcav.com` supplier API
- [x] **SYNC-02**: JWT tokens from `/generate_token.php` are auto-refreshed on 401 and the failing request retried once
- [x] **SYNC-03**: Sync progress is persisted in `sync_runs` + `sync_cursors` so a crashed run can be resumed with `php artisan sync:supplier --resume={run_id}`
- [x] **SYNC-04**: Woo updates go through the REST API only — direct WordPress DB writes are forbidden and covered by an architectural test
- [x] **SYNC-05**: `products/batch` responses are parsed per-item; failed items are written to `sync_errors` and never silently dropped
- [x] **SYNC-06**: SKUs missing from the supplier are set to Woo status `pending`, unless tagged `custom-ms` (which stays `publish`)
- [x] **SYNC-07**: Products with `_exclude_from_auto_update` meta set are skipped by the sync and still counted in the report
- [x] **SYNC-08**: On completion the admin distribution list receives an emailed CSV report showing updated/skipped/failed counts per-SKU
- [x] **SYNC-09**: Dry-run mode runs the full sync pipeline but writes only to `sync_diffs` — Woo is untouched
- [x] **SYNC-10**: `withoutOverlapping` and an adaptive rate-limit middleware keep the sync within Woo's 100-requests/minute ceiling
- [x] **SYNC-11**: A Filament "Supplier Sync Status" page shows the last run, duration, counts, and a per-SKU drill-down
- [x] **SYNC-12**: A Filament "Import Issues" page lists SKUs not found at the supplier, pending products, and products with missing cost/price
- [x] **SYNC-13**: Domain events `SupplierPriceChanged`, `SupplierStockChanged`, `SupplierSkuMissing` fire after each successful row update

### Pricing engine (PRCE) — Module B

- [ ] **PRCE-01**: `PricingRule` records are scoped by brand, category, or brand+category
- [ ] **PRCE-02**: The rule resolver picks the most-specific rule (brand+category > category > brand > default tier) — no stacking
- [ ] **PRCE-03**: Default margin tiers `<£100`, `£100–499`, `£500+` are seeded and editable from Filament
- [ ] **PRCE-04**: Per-product overrides (matching the legacy `buy_price_percentage_to_add` meta) take precedence over all rules
- [ ] **PRCE-05**: Final price is computed in integer pennies (or BCMath) with a single rounding step: `final = round(supplier × (1 + margin%/100) × 1.2, 2)`
- [ ] **PRCE-06**: A golden-fixture test covers 50 known (supplier_price, margin, expected_final) triples from the legacy plugin and must pass to the penny before Phase 3 ships
- [ ] **PRCE-07**: A `SupplierPriceChanged` listener recomputes the final price and emits `ProductPriceChanged` when it differs
- [ ] **PRCE-08**: The Filament rule explorer previews the effective price for any SKU and shows which rule matched ("brand+cat → brand → cat → default")
- [ ] **PRCE-09**: A simulated-change impact view lists the SKUs a proposed rule would affect before it's saved
- [ ] **PRCE-10**: `php artisan pricing:recompute --all` triggers a bulk recomputation as a queued batch

### Bitrix24 CRM sync (CRM) — Module E (moved to Phase 4)

- [x] **CRM-01**: `php artisan bitrix:bootstrap` creates the `UF_CRM_WOO_ORDER_ID` integer custom field on Deal before any push code runs
- [x] **CRM-02**: Bitrix field schemas are cached for 24h with a manual "Refresh from Bitrix" button, and push-time validation reports stale mappings
- [x] **CRM-03**: On Woo order creation a Deal + Contact + Company is pushed to Bitrix24 via the official SDK
- [x] **CRM-04**: On Woo customer registration a Contact is upserted (find-or-create by email, never a duplicate insert)
- [x] **CRM-05**: Deals are found by `UF_CRM_WOO_ORDER_ID` before create so retries never produce duplicates
- [ ] **CRM-06**: Admins map Woo order/customer fields to Bitrix Deal/Contact/Company fields via a Filament UI — no code edits
- [ ] **CRM-07**: Multiple Bitrix deal pipelines are supported; pipeline routing rules are admin-configurable
- [x] **CRM-08**: Order notes are synced into the Deal's comments
- [x] **CRM-09**: UTM parameters and GA Client ID captured on checkout are pushed to configured Bitrix custom fields
- [x] **CRM-10**: `php artisan bitrix:backfill-orders --since={date} --dry-run` replays historical orders idempotently via `BitrixEntityMap`
- [ ] **CRM-11**: Every CRM push attempt (request, response, latency, retry count) is persisted to a CRM push log with a Filament replay action
- [x] **CRM-12**: Failed CRM pushes after N retries land in a dead-letter queue and surface as `suggestions('crm_push_failed')` for human review
- [x] **CRM-13**: A GDPR right-to-erasure command scrubs a customer's Bitrix Contact + related Deal PII on request

### Competitor analysis (COMP) — Module C

- [x] **COMP-01**: CSVs dropped into `storage/app/competitors/` by n8n are picked up, parsed, and ingested by a scheduled watcher
- [x] **COMP-02**: Column auto-detection finds `sku`/`mpn` + `price` columns even when headers vary across competitors
- [x] **COMP-03**: Ingest handles UTF-8 BOM, Windows-1252, and European decimal formats without silent failure
- [x] **COMP-04**: Atomic `.tmp → rename` convention with an mtime > 30s gate prevents mid-write files being ingested
- [x] **COMP-05**: Per-row parse errors are logged to `csv_parse_errors` and surfaced in a Filament page — never discarded
- [x] **COMP-06**: Currency symbols are stripped and prices divided by 1.2 to remove UK VAT before storage
- [x] **COMP-07**: Every competitor price row is persisted to `competitor_prices` — history is never truncated
- [x] **COMP-08**: `MarginAnalyser` computes our-margin-at-competitor-price and suggests a new margin when delta ≥ threshold (default 8%) with noise suppression (≥3 consecutive scrapes AND ≥N sales/90d)
- [x] **COMP-09**: Margin-change suggestions write to the `suggestions` table; approval triggers a `PricingRule` update and audit-log entry
- [x] **COMP-10**: A Filament "Competitor Analysis" page shows price trend charts, biggest deltas, and a per-competitor view
- [x] **COMP-11**: Stale-feed detection warns the admin when a competitor hasn't reported in > 48h
- [x] **COMP-12**: Competitor CSV source files are pruned after 90 days (configurable)

### Product auto-create (AUTO) — Module D

- [x] **AUTO-01**: A supplier SKU not matched to any Woo product triggers a `NewSupplierSkuDetected` event
- [x] **AUTO-02**: A draft Woo product is created via REST with title, slug, meta description, long description, brand + category taxonomy applied from an SEO template
- [x] **AUTO-03**: Images are sourced from the supplier DB when available; otherwise a placeholder is used and the product is flagged for manual image review
- [x] **AUTO-04**: Images are resized, converted to WebP, and EXIF-stripped via `intervention/image` before upload
- [x] **AUTO-05**: `CreateWooProductJob` is queued, retried on failure, and every attempt writes to `integration_events`
- [x] **AUTO-06**: Auto-created products land in a Filament review inbox with completeness score, bulk approve/edit, and a rejection-reason field
- [x] **AUTO-07**: Draft-first review is the v1 default; immediate-publish is gated by an admin config flag
- [x] **AUTO-08**: Duplicate detection rejects a SKU that differs only in casing or trailing whitespace from an existing Woo product
- [x] **AUTO-09**: Slug generation guarantees uniqueness and handles collisions deterministically
- [x] **AUTO-10**: A `ProductOverride` model lets admins pin individual fields (title, description, image) so the next sync won't overwrite a human edit
- [x] **AUTO-11**: A Filament pin UI on the product edit page lets admins toggle pins per field with an audit trail

### Dashboard (DASH) — Module F

- [x] **DASH-01**: A home dashboard surfaces health tiles (last sync, failed jobs, pending review count, CRM push failures) at a glance
- [x] **DASH-02**: Horizon is linked from the dashboard for queue visibility
- [ ] **DASH-03**: Global search jumps to any product, rule, or CRM push log entry from the admin header
- [x] **DASH-04**: All tabular views support saved filters and CSV export
- [x] **DASH-05**: Scheduled reports email the admin distribution list weekly with sync, margin, and CRM metrics
- [x] **DASH-06**: A notification centre consolidates failed jobs, stale feeds, pending suggestions, and webhook dead-letters

### Cutover (CUT) — Module G

- [x] **CUT-01**: A shadow-mode monitoring dashboard shows Laravel-vs-Woo divergence over a configurable window with a parity-threshold pass/fail
- [x] **CUT-02**: A pre-cutover divergence scan auto-populates `ProductOverride` rows for fields where a human edit in Woo differs from Laravel's computed value
- [x] **CUT-03**: `wp_unschedule_event` commands remove the Stock Updater + itgalaxy plugin crons before Laravel writes are enabled
- [x] **CUT-04**: A Woo DB snapshot is taken before `WOO_WRITE_ENABLED=true` is flipped
- [x] **CUT-05**: A rollback runbook + drill (flip flag back to `false`, restore snapshot if needed) is rehearsed before go-live
- [x] **CUT-06**: Ops handover docs cover: how to resume a sync, how to replay a failed CRM push, how to refresh Bitrix schema, how to interpret the notification centre
- [x] **CUT-07**: Stock Updater + itgalaxy Bitrix24 plugins are disabled in WordPress only after a clean monitored parallel-run window passes the parity threshold

## v2 Requirements

Deferred to future milestones (Phases 8–12 in the brief). Tracked but not in the current roadmap.

### Channel feeds & ad integration (Phase 8)

- **FEED-01**: Google Merchant Center feed auto-generated from Laravel source of truth
- **FEED-02**: Google Ads sync (feed push + auto-pause on stock-out + bid adjustments)
- **FEED-03**: GA4 revenue/conversion data pulled into dashboard
- **FEED-04**: Meta / Facebook catalog feed
- **FEED-05**: Google Search Console impressions/clicks/CTR per product URL

### Customer-facing automation (Phase 9)

- **CUST-01**: Abandoned-cart recovery via Bitrix24 Deal stale-trigger
- **CUST-02**: Back-in-stock alerts
- **CUST-03**: Price-drop alerts for wishlist subscribers
- **CUST-04**: Review aggregation (Trustpilot / Google)

### AI agent framework (Phase 10)

- **AGENT-01**: Pricing agent (competitor + GA + margin + stock → margin-change suggestions)
- **AGENT-02**: Ad optimisation agent
- **AGENT-03**: SEO / content agent

### Forecasting & operational intelligence (Phase 11)

- **FCAST-01**: Stock-planning agent (reorder-point suggestions)
- **FCAST-02**: Sales forecasting
- **FCAST-03**: Supplier performance dashboard
- **FCAST-04**: True profitability per SKU (margin − ad spend − fulfilment)

### B2B & channel expansion (Phase 12)

- **B2B-01**: Logged-in trade customer pricing
- **B2B-02**: Quote request flow → Bitrix24 Deal
- **B2B-03**: WhatsApp Business integration
- **B2B-04**: AI product-finder chatbot
- **B2B-05**: RAMS platform integration hook

## Out of Scope

Explicitly excluded. Documented to prevent scope creep.

| Feature | Reason |
|---------|--------|
| Two-way CRM sync (Bitrix24 → Woo) | Dropping itgalaxy's most complex feature; project scope narrowing |
| Coupon list sync | itgalaxy feature we don't use |
| Delayed CRM send via cron | We push immediately on order/customer events; delay adds complexity with no business value |
| WooCommerce checkout/cart/frontend replacement | Woo stays as shop frontend |
| Customer-facing features in v1 | Internal ops tool |
| Multi-store support | Single store (meetingstore.co.uk) only for v1 |
| Manual overrides in Woo admin | Laravel is source of truth; Woo admin changes are overwritten on next sync (documented behaviour, not a bug) — `ProductOverride` is the supported escape hatch |
| Roistat / Yandex Metrika analytics | UK market; Russian analytics stack unused |
| Filament v4 upgrade | Plugin ecosystem still stabilising; deferred to dedicated post-cutover phase |
| Tailwind 4 migration | Coupled to Filament 4; same reason |
| `maatwebsite/laravel-excel` | Loads whole workbook into RAM; use `spatie/simple-excel` instead |
| Saloon HTTP abstraction | Only 3 external APIs in v1; premature abstraction. Reassess at Phase 8 |
| Direct Woo DB writes | Future multi-channel expansion (Shopify, Amazon) depends on REST-only discipline |

## Traceability

Per-REQ-ID phase mapping. Populated by `/gsd-roadmap` at initialisation; `Status` advances as plans land and phases verify.

| Requirement | Phase | Status |
|-------------|-------|--------|
| FOUND-01 | Phase 1 | Complete |
| FOUND-02 | Phase 1 | Complete |
| FOUND-03 | Phase 1 | Complete |
| FOUND-04 | Phase 1 | Complete |
| FOUND-05 | Phase 1 | Complete |
| FOUND-06 | Phase 1 | Complete |
| FOUND-07 | Phase 1 | Complete |
| FOUND-08 | Phase 1 | Complete |
| FOUND-09 | Phase 1 | Complete |
| FOUND-10 | Phase 1 | Complete |
| FOUND-11 | Phase 1 | Complete |
| FOUND-12 | Phase 1 | Complete |
| FOUND-13 | Phase 1 | Complete |
| SYNC-01 | Phase 2 | Complete |
| SYNC-02 | Phase 2 | Complete |
| SYNC-03 | Phase 2 | Complete |
| SYNC-04 | Phase 2 | Complete |
| SYNC-05 | Phase 2 | Complete |
| SYNC-06 | Phase 2 | Complete |
| SYNC-07 | Phase 2 | Complete |
| SYNC-08 | Phase 2 | Complete |
| SYNC-09 | Phase 2 | Complete |
| SYNC-10 | Phase 2 | Complete |
| SYNC-11 | Phase 2 | Complete |
| SYNC-12 | Phase 2 | Complete |
| SYNC-13 | Phase 2 | Complete |
| PRCE-01 | Phase 3 | Complete |
| PRCE-02 | Phase 3 | Complete |
| PRCE-03 | Phase 3 | Complete |
| PRCE-04 | Phase 3 | Complete |
| PRCE-05 | Phase 3 | Complete |
| PRCE-06 | Phase 3 | Complete |
| PRCE-07 | Phase 3 | Complete |
| PRCE-08 | Phase 3 | Complete |
| PRCE-09 | Phase 3 | Complete |
| PRCE-10 | Phase 3 | Complete |
| CRM-01 | Phase 4 | Complete |
| CRM-02 | Phase 4 | Complete |
| CRM-03 | Phase 4 | Complete |
| CRM-04 | Phase 4 | Complete |
| CRM-05 | Phase 4 | Complete |
| CRM-06 | Phase 4 | Complete |
| CRM-07 | Phase 4 | Complete |
| CRM-08 | Phase 4 | Complete |
| CRM-09 | Phase 4 | Complete |
| CRM-10 | Phase 4 | Complete |
| CRM-11 | Phase 4 | Complete |
| CRM-12 | Phase 4 | Complete |
| CRM-13 | Phase 4 | Complete |
| COMP-01 | Phase 5 | Complete |
| COMP-02 | Phase 5 | Complete |
| COMP-03 | Phase 5 | Complete |
| COMP-04 | Phase 5 | Complete |
| COMP-05 | Phase 5 | Complete |
| COMP-06 | Phase 5 | Complete |
| COMP-07 | Phase 5 | Complete |
| COMP-08 | Phase 5 | Complete |
| COMP-09 | Phase 5 | Complete |
| COMP-10 | Phase 5 | Complete |
| COMP-11 | Phase 5 | Complete |
| COMP-12 | Phase 5 | Complete |
| AUTO-01 | Phase 6 | Complete |
| AUTO-02 | Phase 6 | Complete |
| AUTO-03 | Phase 6 | Complete |
| AUTO-04 | Phase 6 | Complete |
| AUTO-05 | Phase 6 | Complete |
| AUTO-06 | Phase 6 | Complete |
| AUTO-07 | Phase 6 | Complete |
| AUTO-08 | Phase 6 | Complete |
| AUTO-09 | Phase 6 | Complete |
| AUTO-10 | Phase 6 | Complete |
| AUTO-11 | Phase 6 | Complete |
| DASH-01 | Phase 7 | Complete |
| DASH-02 | Phase 7 | Complete |
| DASH-03 | Phase 7 | Complete |
| DASH-04 | Phase 7 | Complete |
| DASH-05 | Phase 7 | Complete |
| DASH-06 | Phase 7 | Complete |
| CUT-01 | Phase 7 | Complete |
| CUT-02 | Phase 7 | Complete |
| CUT-03 | Phase 7 | Complete |
| CUT-04 | Phase 7 | Complete |
| CUT-05 | Phase 7 | Complete |
| CUT-06 | Phase 7 | Complete |
| CUT-07 | Phase 7 | Complete |

**Coverage:**
- v1 requirements: 85 total (13 FOUND + 13 SYNC + 10 PRCE + 13 CRM + 12 COMP + 11 AUTO + 6 DASH + 7 CUT)
- Mapped to phases: 85
- Unmapped: 0 ✓
- Duplicates: 0 ✓

---
*Requirements defined: 2026-04-18*
*Traceability expanded per-REQ-ID: 2026-04-18 at roadmap creation*
