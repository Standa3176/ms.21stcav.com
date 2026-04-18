# Project Research Summary — MeetingStore Ops

**Project:** MeetingStore Ops
**Domain:** Laravel 12 + Filament 3 source-of-truth ops/integration hub over WooCommerce, Bitrix24 CRM, an upstream supplier API, and n8n competitor CSV feeds — replacing two live WordPress plugins on `meetingstore.co.uk`
**Researched:** 2026-04-18
**Confidence:** HIGH (core stack, architecture patterns, pitfalls verified across official docs, Packagist, real-world post-mortems). MEDIUM on Bitrix24 SDK newness and a handful of store-specific unknowns.

## Executive Summary

MeetingStore Ops is a **single-purpose Laravel modular monolith** that takes ownership of the product catalogue, pricing engine, competitor intelligence, and CRM sync currently spread across a brittle ~1,700-line in-house Stock Updater plugin and the sanctions-blocked itgalaxycompany Bitrix24 integration (stuck on June 2024 v1.50.1). All four research dimensions converge on one shape: **Laravel 12 + Filament 3.3 + Horizon on Redis + WooCommerce REST (never DB writes) + official `bitrix24/b24phpsdk`**, organised as `app/Domain/{Products,Pricing,Competitor,Sync,Webhooks,CRM,Suggestions,Feeds}` domains communicating **only** through queued domain events, a shared `audit_log`, an `integration_events` outbound log, and a write-seam `suggestions` table that doubles as the Phase 10 agent output surface.

The technical approach is low-novelty — every pattern is well-trodden — but the **cutover is where the risk lives**. The eight critical pitfalls cluster around four failure modes: non-resumable sync state, non-idempotent webhook handlers producing duplicate Bitrix deals, float-based VAT rounding drift against the legacy plugin, and parallel-run parity violations where both old and new systems write to Woo. Mitigation is almost entirely a Phase 1 + Phase 2 investment: persistent `SyncRun` cursors, HMAC middleware with `delivery_id` idempotency, integer-pennies math with a golden-fixture parity test, and a `WOO_WRITE_ENABLED=false` shadow-mode flag that has to exist from day one because it cannot be retrofitted.

**Key recommendation:** ship foundation + supplier sync first (the business-outage dependency), then pricing engine, then **Bitrix CRM sync fourth — ahead of competitor and auto-create** — to cut the sanctions-compliance dependency on itgalaxy as early as the pricing engine lands. This diverges from the brief's original ordering and from the architecture agent's recommendation; rationale in "Phase Ordering Rationale" below.

## Key Findings

### Recommended Stack

Canonical Laravel 12 ops stack with Filament 3.3 pinned (Filament 4 deferred — plugin ecosystem still stabilising, Tailwind 4 migration is a tax we don't need mid-build). Queue throughput on phpredis, not Predis (~2× on the dedicated VPS). Official Bitrix24 PHP SDK exists (contradicts brief's "no official SDK" assumption) and is the primary choice with `mesilov/bitrix24-php-sdk` as documented fallback. No dedicated webhook library needed — Woo HMAC is a ~30-line middleware.

**Core technologies:**

- **Laravel `^12.0` + Filament `^3.3` + Tailwind `^3.4`** — pinned, do not touch
- **Horizon `^5.45` on Redis 7.x via phpredis** — `appendonly yes, appendfsync everysec` REQUIRED (otherwise reboots eat in-flight sync chunks)
- **`automattic/woocommerce` `^3.1`** — official SDK, wrap in thin `WooClient` for testability + audit logging
- **`bitrix24/b24phpsdk` `^1.x`** — official Bitrix org SDK (MEDIUM confidence on newness; `mesilov` is the fallback, 1-hour swap via wrapper)
- **`spatie/laravel-permission` + `bezhansalleh/filament-shield` + `spatie/laravel-activitylog` + `rmsramos/activitylog` (Filament) + `spatie/simple-excel`** — canonical RBAC/audit/CSV stack
- **Sentry (prod) + Pulse (prod, internal) + Telescope (dev-only)** — standard Laravel monitoring triple
- **`spatie/laravel-failed-job-monitor`** — Horizon does NOT notify on failures by default

**Do-not-use (non-negotiable):** Filament 4 for v1; Tailwind 4; direct Woo DB writes; any `itgalaxycompany/*` or Russian-origin repackage; Roistat/Yandex SDKs; `maatwebsite/laravel-excel` (heavy, non-streaming); Saloon (scope too small for v1); `Z3d0X/filament-logger` (unmaintained).

Full detail: [STACK.md](STACK.md).

### Expected Features

All 6 modules in the brief are well-scoped — every listed item is either table-stakes or a genuine differentiator; nothing needs removing. The real risk is **cross-cutting gaps** the brief under-emphasises: webhook HMAC + idempotency, per-SKU sync resumability, integer-pennies money math with golden-fixture parity, Bitrix dedup by custom field, CSV encoding/BOM handling, failed-job alerting, retention policies.

**Must have (table stakes — parity with old plugins + ecosystem baseline):**

- Daily supplier sync pull with JWT auth, chunked resumable state, missing-SKU handling, `_exclude_from_auto_update` preservation, CSV email report
- Pricing engine: brand/category scope, most-specific-wins, default tiers, per-product override, VAT-inclusive formula
- Competitor CSV ingest with column auto-detect, VAT removal, **full history** (old plugin truncates daily)
- Bitrix24 Deal + Contact + Company push on order; Contact upsert on customer registration; dynamic field mapping UI; UTM/GA capture; backfill command
- Filament dashboard: sync status, import issues, competitor analysis, pricing rules, CRM push log
- **Webhook HMAC middleware + `X-WC-Webhook-Delivery-ID` idempotency** (brief gap)
- **`products/batch` per-item error parsing** — HTTP 200 ≠ all items succeeded (brief gap)
- **Integer-pennies / BCMath money math** — float + compound `round()` drifts vs legacy (brief gap)
- **Find-or-create by email (Contact/Company) and by `UF_CRM_WOO_ORDER_ID` (Deal)** (brief gap)
- **`WOO_WRITE_ENABLED=false` shadow-mode flag + `sync_diffs` table** — cannot retrofit at Phase 7 (brief gap)
- **Dry-run mode on supplier sync AND Bitrix backfill** (brief gap)
- **Bitrix schema cache + 24h TTL + "Refresh from Bitrix" button + push-time validation** (brief gap)
- **Atomic-rename CSV convention + BOM/encoding handling** (brief gap)
- **Retention policies** for audit log, integration events, competitor CSVs, sync errors (brief open item — resolve Phase 1)

**Should have (differentiators):**

- Rule-driven pricing with effective-price preview showing resolution chain
- Full competitor history + rationale-bearing margin-change suggestions
- UI-driven Bitrix field mapping (itgalaxy requires code edits)
- Correlation-ID event trace across sync/push/audit
- Suggestions inbox pattern usable by Phase 10 agents from day one
- Replay UI for failed webhooks/CRM pushes
- Draft-first auto-create with completeness scoring

**Defer (v2+):**

- Merchant Center / Meta / Bing catalogue feeds (Phase 8)
- Abandoned cart, back-in-stock, price-drop alerts (Phase 9)
- AI agents — pricing / ads / SEO (Phase 10)
- Forecasting, supplier-performance, true-profitability (Phase 11)
- B2B account pricing, quote flow, WhatsApp, RAMS hook (Phase 12)

Full detail: [FEATURES.md](FEATURES.md).

### Architecture Approach

**Modular monolith** enforced via `app/Domain/<Module>/` with Deptrac CI (lighter than `nwidart/laravel-modules`, stricter than stock Laravel — consensus best-practice for integration-heavy ops apps in 2026). Event bus is the spine: every cross-module reaction (price change → Woo push, order arrived → Bitrix push, competitor undercut → suggestion) flows through Laravel Events carrying a `correlation_id` that threads audit/integration/suggestion rows together. Three distinct foundation tables — `audit_log` (data changes), `integration_events` (outbound API attempts), `suggestions` (proposed mutations) — never conflated. Horizon supervisor-per-workload-class (not per-module) keeps Bitrix isolated from supplier sync.

**Major components:**

1. **Foundation** (`app/Foundation/*`) — `DomainEvent` base, `Auditor`, `IntegrationLogger`, `SuggestionApplier` contract, HMAC middleware, Horizon supervisors
2. **Domain/Products** — canonical product/brand/category/supplier models, `ProductOverride` (pins fields against auto-overwrite)
3. **Domain/Sync** — `WooClient` wrapper, `SyncRun` + `SyncCursor`, chunked resumable jobs, `BatchResult` parser
4. **Domain/Pricing** — `PricingRule` (brand/category/both), `RuleResolver` (most-specific-wins), `PriceCalculator` (integer-pennies), golden-fixture parity tests
5. **Domain/Webhooks** — Woo HMAC-verified ingestion, `webhook_events` dedup by `delivery_id`
6. **Domain/CRM** — `BitrixClient` wrapper, `FieldMapper` + `bitrix_schema_cache`, `BitrixEntityMap`, find-or-create resolvers, `UF_CRM_WOO_ORDER_ID` bootstrap
7. **Domain/Competitor** — `CsvIngestor` (BOM/encoding-safe, atomic-rename), `CompetitorPrice` (full history), `MarginAnalyser` with noise-suppression, `MarginChangeApplier` (first Suggestions producer)
8. **Domain/AutoCreate** — `CreateWooProductJob`, SEO template, completeness scorer, draft-first review inbox
9. **Domain/Suggestions** — `suggestions` table + Filament inbox + one-click apply → audit trail
10. **Domain/Feeds** — empty `FeedGenerator` interface stub for Phase 8

Full detail: [ARCHITECTURE.md](ARCHITECTURE.md).

### Critical Pitfalls

Top 8 of 23 ranked in PITFALLS.md — the ones that would tank the cutover:

1. **Non-resumable supplier sync** (Phase 2) — mid-run crash = half-stale catalogue, no cursor. Prevention: persistent `supplier_sync_runs` + `sync_cursors`, chunk-level queued jobs in DB tx, `--resume={run_id}`.
2. **Parallel-run parity violation** (Phase 1 infra + Phase 7 execution) — both old and new systems writing to Woo. Prevention: `WOO_WRITE_ENABLED=false` default, shadow mode writes to `sync_diffs` only, old plugin crons DEREGISTERED (`wp_unschedule_event`), reconciliation before flip.
3. **Non-idempotent webhook → duplicate Bitrix deals** (Phase 1 + Phase 4) — slow Bitrix + Woo 5s timeout + Woo retries = duplicates. Prevention: webhook handler does 4 things in ≤200ms (HMAC verify, persist raw with unique `(topic, delivery_id)`, dispatch queued job, return 200); job checks `processed_at`; find-or-create by `UF_CRM_WOO_ORDER_ID`.
4. **VAT rounding drift vs legacy plugin** (Phase 3) — float + compound `round()` = penny drift users call a "bug". Prevention: integer-pennies OR BCMath throughout, single pure `FinalPriceCalculator::compute()` with rounding ONCE, **golden-fixture parity test (50 triples, penny-exact) — ship gate**.
5. **Bitrix24 duplicate contacts/deals from naïve create-on-every-order** (Phase 4) — Bitrix REST never dedupes; itgalaxy handles this so absence is a regression day one. Prevention: find-or-create everywhere, `BitrixEntityMap`, `UF_CRM_WOO_ORDER_ID` bootstrapped via artisan before any push code.
6. **`products/batch` silent per-item failures** (Phase 2) — HTTP 200 does NOT mean every item succeeded. Prevention: `BatchResult` parser extracts per-item errors to `sync_errors`, cap 50/batch, negative-test with bad payload.
7. **Long supplier sync blocks Horizon queue** (Phase 1) — 45-min sync on `default` queue = webhook-triggered CRM push waits 45 min. Prevention: supervisor-per-workload-class, `withoutOverlapping(30)`, per-job timeout/tries, worker recycling (`--max-jobs=1000 --max-time=3600`).
8. **Competitor CSV silent fail on BOM/encoding/partial-file** (Phase 5) — UTF-8 BOM, Windows-1252, European decimals, half-written files. Prevention: `spatie/simple-excel` + BOM-strip + `mb_detect_encoding`, atomic `.tmp → rename` coordinated with n8n, mtime-gate (>30s), per-row `csv_parse_errors`.

Full detail: [PITFALLS.md](PITFALLS.md).

## Implications for Roadmap

Suggested 7-phase structure, coarse granularity.

### Phase 1: Foundation

**Rationale:** Four pitfalls (2, 3, 7, and most of the audit/suggestions-retrofit class) are Phase-1 investments that cannot be retrofitted later without rewrites.
**Delivers:** Laravel + Filament + Horizon skeleton; `app/Domain/*` + Deptrac; `DomainEvent` base, `Auditor`, `IntegrationLogger`, `SuggestionApplier` contract; suggestions + inbox scaffold; HMAC middleware + `webhook_receipts`; `WOO_WRITE_ENABLED` flag + `sync_diffs`; Horizon supervisors (empty); Redis `appendonly yes`; phpredis; Shield RBAC with 4 roles; retention policy schema; `FeedGenerator` stub; `WooClient` + `SupplierClient` auth skeletons.
**Addresses:** Cross-cutting requirements, RBAC, audit, suggestions seam.
**Avoids:** Pitfalls 2, 3, 7, 10, 16, 22; entire retrofit class.
**Research flag:** skip.

### Phase 2: Supplier sync

**Rationale:** Highest business-outage risk if old plugin breaks; lowest external coupling (only supplier API + Woo REST); highest parity risk after pricing — build first so we have data flowing.
**Delivers:** Products module; `RunSupplierSyncJob` + `SyncSupplierChunkJob` + persistent `sync_runs`/`sync_cursors`; `BatchResult` parser; JWT refresh middleware; default-tier pricing applied; email CSV report; adaptive rate-limit; variable-product audit day one; `withoutOverlapping`; Filament Supplier Sync Status + Import Issues pages; domain events; dry-run shadow mode.
**Uses:** `WooClient`, Horizon, `automattic/woocommerce` SDK.
**Implements:** Sync domain, Products domain.
**Research flag:** MAYBE — variable-product count if > 0; Woo rate-limit ceiling.

### Phase 3: Pricing engine

**Rationale:** Supplier prices need a rule engine to compute final VAT-inclusive prices before the Woo push is final; pricing is the core differentiator vs old plugin's flat tiers.
**Delivers:** Pricing module; `PricingRule`, `PricingOverride`, `RuleResolver` (most-specific-wins), `PriceCalculator` (integer-pennies + BCMath); listener `SupplierPriceChanged → recalc → ProductPriceChanged`; Filament rule CRUD + effective-price preview + simulated impact; min-margin floor; validity windows; zero/null supplier price handling; bulk-recompute artisan command; **golden-fixture parity test (50 triples) — ship gate**.
**Avoids:** Pitfall 4 (VAT rounding drift).
**Research flag:** light — rounding convention check with ops.

### Phase 4: Bitrix24 CRM sync (moved up — sanctions compliance priority)

**Rationale:** Sanctions-blocked itgalaxy plugin is the project's original "why now" — every week on v1.50.1 accumulates legal/security risk on the highest-trust integration. Webhook infra is already Phase 1, so Phase 4 consumes existing infra, not inventing it. CRM cutover is independent of supplier-sync cutover — no reason to bundle them.
**Delivers:** Webhooks module wires Woo routes; CRM module with `BitrixClient` (official SDK), `FieldMapper` + `bitrix_schema_cache` + 24h TTL + "Refresh from Bitrix", `DealBuilder`, find-or-create resolvers, `BitrixEntityMap`, `UF_CRM_WOO_ORDER_ID` bootstrap command (first Phase 4 deliverable); listeners for `OrderReceived`/`CustomerRegistered`/`OrderRefunded`; Filament dynamic field-mapping UI; CRM push log with replay; `BackfillBitrixOrdersCommand` (chunked, rate-limited, idempotent, dry-run); UTM + GA Client ID capture; pipeline routing; DLQ → `suggestions('crm_push_failed')`; GDPR right-to-erasure.
**Avoids:** Pitfalls 3, 5, 11.
**Research flag:** YES — SDK newness; UTM capture mechanism on Woo side; GDPR workflow.

### Phase 5: Competitor analysis

**Rationale:** First real producer to the suggestions table (validates the seam before Phase 6 depends on it). Self-contained — only depends on supplier prices being in the DB (Phase 2) and pricing engine's rule resolver (Phase 3) for margin-delta suggestions.
**Delivers:** Competitor module with `CsvIngestor` (BOM-safe, atomic-rename, encoding-detecting), `CompetitorPrice` (full history), `CompetitorSource`, per-competitor schema mapping, `MarginAnalyser` with noise-suppression (8% delta AND ≥3 consecutive scrapes AND ≥N sales/90d), `MarginChangeApplier`, orphan-row reporting, stale-feed detection, Filament trends/deltas/per-competitor pages, CSV prune (90d), exact-match matching v1.
**Avoids:** Pitfall 8.
**Research flag:** MAYBE — MAP-brand coverage.

### Phase 6: Product auto-create

**Rationale:** Depends on Phase 2 (discovers new SKUs) + Phase 3 (pricing rules compute final prices) + suggestions seam proven in Phase 5. `ProductOverride` lands here (Pitfall 7 mitigation) because this phase introduces the "Woo admin user edits something" flow.
**Delivers:** Auto-create trigger; SEO template; image pipeline (resize, WebP, EXIF via `intervention/image`); `CreateWooProductJob` + `ProductCreateApplier`; Filament review inbox with completeness score, bulk approve/edit, rejection-reason persistence, auto-skip rules; `ProductOverride` model + Filament pin UI; brand-templated content; duplicate detection; slug uniqueness.
**Research flag:** YES — supplier image-DB availability (biggest single unknown).

### Phase 7: Dashboard polish + cutover

**Rationale:** Dashboard grows across every functional phase; this phase is polish + the actual cutover runbook, not a first-time build.
**Delivers:** Home dashboard with health tiles; notification centre; global search; saved filters; export-to-CSV; scheduled reports; cutover runbook — shadow-mode monitoring; pre-cutover divergence scan auto-populating `ProductOverride`; `sync_diffs` parity-threshold gate; `wp_unschedule_event` removal of old plugin crons; final old-plugin pass + DB snapshot + Laravel flip + first pass + diff; rollback drill; ops handover docs.
**Avoids:** Pitfall 2.
**Research flag:** skip — execution discipline.

### Phase Ordering Rationale

The research agents disagreed on where Bitrix CRM sync should sit. **Decision: adopt the features-agent ordering — Bitrix CRM sync lands in Phase 4, ahead of competitor and auto-create.**

Why diverge from the architecture agent + original brief:

1. The brief names sanctions compliance as the #1 "why now" trigger — every additional week on v1.50.1 accumulates legal and security risk on the revenue-critical integration.
2. The architecture agent's "CRM is highest-risk, save for last" is valid but mitigable — risk is reduced by *finishing it earlier*, not *deferring it*. Bitrix cutover is independent of supplier-sync cutover (different plugin, different data path), so there's no reason to bundle them.
3. Webhook infrastructure is Phase 1 either way — HMAC middleware, `webhook_receipts` dedup, `ShouldQueue` discipline are foundation. Phase 4 consumes existing infra, not inventing it.
4. Competitor (C) and auto-create (D) are genuinely independent of CRM — moving CRM up doesn't delay them structurally.

### Research Flags

Phases likely needing deeper research during planning:

- **Phase 4 (CRM):** YES — validate official Bitrix SDK against sandbox before committing; UTM capture mechanism on Woo side unresolved; GDPR right-to-erasure workflow needs design
- **Phase 6 (Auto-create):** YES — supplier image-DB availability is the biggest unknown; drives entire image pipeline complexity

Phases with standard patterns (skip research-phase):

- **Phase 1 (Foundation):** established Laravel scaffolding + Filament + Horizon patterns
- **Phase 2 (Supplier sync):** standard Laravel queue + HTTP client patterns
- **Phase 3 (Pricing):** research already done — integer-pennies pattern + golden-fixture approach is the whole answer
- **Phase 5 (Competitor):** `spatie/simple-excel` + CSV ingest patterns well-documented
- **Phase 7 (Cutover):** execution discipline, not novel research

Phases with light research needs (MAYBE):

- **Phase 2:** variable-product count check with ops
- **Phase 3:** rounding convention conversation with ops (5 min)
- **Phase 5:** MAP-policy brand coverage check

## Confidence Assessment

| Area | Confidence | Notes |
|------|------------|-------|
| Stack | HIGH | Every package verified on Packagist April 2026 with `^12.0`; Filament v4 caveat explicit; only MEDIUM item is the official Bitrix SDK's newness (fallback documented) |
| Features | HIGH (WooCommerce + Bitrix24 + Filament ecosystems); MEDIUM (exact volume/latency); LOW (internal ops-team workflow — no user research) | All GAPs cross-referenced in FEATURES.md |
| Architecture | HIGH | Modular monolith + event-driven comms is Laravel 2026 community standard; Horizon supervisor-per-workload is official-docs explicit; Suggestions + Feeds seams bespoke but anchored in brief constraints |
| Pitfalls | HIGH | 23 ranked pitfalls, each cross-referenced to production post-mortems or official-docs gotchas; phase mapping explicit |

**Overall confidence:** HIGH. The project is low-novelty in its technical approach — every pattern has been done; the risk is cutover-execution discipline, not invention.

### Gaps to Address (Open Questions Into Phase Planning)

De-duplicated across all four research agents:

| # | Question | First-impacted phase | How to resolve |
|---|---|---|---|
| 1 | Variable-product count in the Woo catalogue | Phase 2 | 5-min ops check; if > 0, adds `ProductVariant` modelling |
| 2 | Supplier image-DB availability — format, size, quality | Phase 6 | Ops + supplier API docs |
| 3 | Pricing rounding rule — plain 2dp or `.99`/`.95` endings? | Phase 3 | 5-min conversation with ops; drives golden-fixture generator |
| 4 | Retention policies for audit_log, integration_events, competitor CSVs, sync_errors | Phase 1 | Ops/compliance sign-off; drives DB sizing + prune commands |
| 5 | User roles — admin-only or admin / pricing_manager / sales / read_only? | Phase 1 | Ops/business decision |
| 6 | Webhook-delivery SLA — is 30s queue-processing delay acceptable for Bitrix deal creation? | Phase 4 | Sales/ops; drives queue priority + retry tuning |
| 7 | UTM capture mechanism on the Woo side — cookie vs JS vs webhook enrichment | Phase 4 | Dev lead + marketing |
| 8 | MAP-policy brand coverage | Phase 5 | Ops/buying |
| 9 | Admin CSV report distribution list | Phase 2 | Ops |
| 10 | Draft vs immediate-publish for auto-created products | Phase 6 | Ops — research recommends draft-first for v1 |
| 11 | Rollback SLA | Phase 1 policy, Phase 7 execution | Business decision; drives monitoring investment |

## Sources

### Primary (HIGH confidence)

- Laravel 12.x official docs — Horizon, events/listeners, queue workers
- Filament 3.3 documentation + v4 announcement
- WooCommerce REST API docs + webhook signature guide
- Bitrix24 API reference — contacts, deals, custom fields
- Packagist — current versions for all primary packages (April 2026)
- Spatie package docs (laravel-permission, laravel-activitylog, laravel-failed-job-monitor, simple-excel)
- PHP 8.4 BCMath API docs

### Secondary (MEDIUM confidence)

- Modular-monolith Laravel 12 guides (200OK Solutions, Medium Feb 2026)
- Hookdeck WooCommerce webhooks guide
- Laravel Horizon GitHub issues #612, #833, #1034, #1280
- Filament perf issue #9304 + community best-practice guides
- League Money + integer-pennies pattern discussions
- Pakk Academy UK VAT rounding guidance

### Tertiary (LOW confidence — validate during phase planning)

- Bitrix24 b24phpsdk (newer than mesilov; validate via 1-day sandbox spike)
- Exact itgalaxy plugin parity feature set (behavior inferred from marketing pages + changelog)
- Store-specific unknowns listed in Gaps above

---
*Research completed: 2026-04-18*
*Ready for roadmap: yes*
