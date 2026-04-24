# MeetingStore Ops

## What This Is

Laravel 12 + Filament 3 operations platform — the sole source of truth for product data, pricing rules, competitor intelligence, and CRM sync on `meetingstore.co.uk`. Replaced two legacy WordPress plugins: the in-house **Stock Updater** (supplier price/stock sync + competitor CSV) and the third-party **WooCommerce → Bitrix24 CRM Integration** by itgalaxycompany (stuck on v1.50.1 because sanctions block updates). WordPress/WooCommerce is now a pure shop frontend; Laravel owns everything else.

## Current State (v1.50.1 shipped 2026-04-24)

v1 framework is **built and audited**. 7 phases, 38 plans, 82 tasks, ~15,160 LOC over 6 days of GSD execution. All 85 v1 requirements delivered; milestone audit verdict: **passed**.

**Cutover is an ops execution, not dev work.** Ops runs `php artisan cutover:checklist` per `docs/ops/cutover-handover.md` Appendix A: snapshot → divergence-scan → populate-overrides → drill-rollback-staging → disable-legacy-plugins → flip `WOO_WRITE_ENABLED=true` → monitor 7 days at ≥99% parity.

**Three operator carry-forward gates enforced by `cutover:checklist`:**
1. Supplier API probe with live 21stcav.com credentials
2. Woo sandbox image URL pass-through re-validation
3. Feature-tier Pest suite run against online `meetingstore_ops_testing` MySQL

## Core Value

**One Laravel app owns product data, pricing rules, competitor intelligence and CRM sync — Woo is the display layer, nothing more.** If this fails, the business loses its daily supplier sync (stock goes stale) and its CRM pipeline (leads don't land in Bitrix24). Everything else — dashboards, auto-created products, pricing UX — is secondary to that source-of-truth guarantee. v1 shipping proved this value: 85/85 requirements delivered without compromise; operator now holds the cutover lever.

## Requirements

### Validated (shipped in v1.50.1)

<!-- All 85 v1 requirements delivered; audit status: passed. -->

- ✓ **A. Supplier sync** (SYNC-01..13) — v1.50.1 Phase 2
- ✓ **B. Pricing engine** (PRCE-01..10) — v1.50.1 Phase 3
- ✓ **C. Competitor analysis** (COMP-01..12) — v1.50.1 Phase 5
- ✓ **D. Product auto-create** (AUTO-01..11) — v1.50.1 Phase 6
- ✓ **E. Bitrix24 CRM sync** (CRM-01..13) — v1.50.1 Phase 4
- ✓ **F. Dashboard** (DASH-01..06) — v1.50.1 Phase 7
- ✓ **G. Cutover framework** (CUT-01..07) — v1.50.1 Phase 7 — cutover EXECUTION is ops (not dev)
- ✓ **Cross-cutting infrastructure** (FOUND-01..13) — v1.50.1 Phase 1

Full requirement detail + traceability in `.planning/milestones/v1.50.1-REQUIREMENTS.md`.

## Current Milestone: v2.0 Intelligence + B2B

**Goal:** Build an AI agent framework on top of v1's suggestions seam + audit log, and open the B2B revenue motion with trade pricing, quote flow, and conversational chat surfaces.

**Target features (7 active + infrastructure):**
- **C4 Agent Framework** (infrastructure) — registry, Claude SDK tool-use contracts, guardrails, audit trail, suggestions-seam integration
- **C1 Pricing agent** — LLM-assisted reasoning over v1 competitor_prices + margin data
- **C2 Ad optimisation agent** — consumes UTM/GA CID on Bitrix Deals
- **C3 SEO/content agent** — suggestions for low-completeness auto-created products
- **E1 Trade customer pricing** — extends PricingRule with customer-group scope
- **E2 Quote request → Bitrix Deal** — new Filament flow + Deal-type routing
- **E3+E4 Chat surfaces** — WhatsApp Business integration + AI product-finder chatbot

**Key context:** All agent outputs flow through v1's existing `suggestions` seam. v1 cutover is ops-executed in parallel with v2 dev. RAMS cross-project integration (E5) and channel feeds (Phase 8) + customer automation (Phase 9) + forecasting (Phase 11) are deferred to future milestones.

### Active (v2.0 scope)

<!-- Current dev scope — detail populated in REQUIREMENTS.md by requirements-definition step. -->

- [ ] **C4.** Agent framework infrastructure (registry, tool-use contracts, guardrails, audit)
- [ ] **C1.** Pricing agent (LLM-assisted margin suggestions)
- [ ] **C2.** Ad optimisation agent (UTM/GA-driven budget suggestions)
- [ ] **C3.** SEO/content agent (auto-create content suggestions)
- [ ] **E1.** Trade customer pricing (PricingRule customer-group scope)
- [ ] **E2.** Quote request → Bitrix Deal flow
- [ ] **E3.** WhatsApp Business integration
- [ ] **E4.** AI product-finder chatbot

### Future Requirements (v2.1+ — deferred from v2.0)

<!-- Post-v2.0 candidate scope; deferred to keep v2.0 tight. -->

- **Channel feeds:** Google Merchant Center, Google Ads sync, GA4 revenue, Meta catalog, Google Search Console per-product impressions
- **Customer automation:** abandoned-cart recovery via Bitrix24, back-in-stock alerts, price-drop alerts, review aggregation
- **Forecasting & operational intelligence:** stock-planning agent, sales forecasting, supplier performance dashboard, true profitability per SKU
- **E5 RAMS integration hook:** cross-project FK from meetingstore-ops-app to rams.21stcav.com for shared AV-installation customers

### Out of Scope (confirmed after v1.50.1)

- **Two-way CRM sync (Bitrix24 → Woo)** — reasoning held through v1; confirmed at cutover
- **Coupon list sync** — itgalaxy feature we don't use
- **Delayed CRM send via cron** — push on event is faster and simpler
- **WooCommerce checkout/cart/frontend replacement** — Woo stays as shop frontend
- **Customer-facing features** — this is an internal ops tool
- **Multi-store support** — single store; reconsider if acquisition expands the estate
- **Manual overrides in Woo admin propagating back** — Laravel is source of truth; `ProductOverride` pin columns are the supported escape hatch
- **Roistat / Yandex Metrika analytics** — Russian analytics stack, unused
- **Filament v4 upgrade** — defer until plugin ecosystem stabilises post-cutover
- **Tailwind 4 migration** — coupled to Filament 4
- **AI-generated product descriptions** — constraint respected through Phase 6; Phase 10 agent territory
- **Rich product variation auto-creation** — simple products only in v1 per AUTO anti-feature
- **Immediate-publish auto-create mode** — gated behind `config('product_auto_create.mode')` + Woo sandbox gate 2; defaults to draft-first

## Context

**Why now:** itgalaxy plugin cannot be legally updated (procurement sanctions — stuck June 2024 v1.50.1 while upstream is v1.67.2); Stock Updater is brittle 1,700-line single-file WP plugin; pricing tied to flat margin tiers with no rules; no unified dashboard; product creation manual.

**Result:** v1 replaces both plugins with a modular-monolith Laravel app that owns data and emits domain events. Cutover framework is shipped; legacy plugins remain live until ops executes D-19 sequence and parity stays ≥99% over 7 days.

**External systems:**

| System | Purpose | Direction | Auth |
|---|---|---|---|
| `21stcav.com/api/index.php` | Central supplier SKU/price DB (updated daily) | Laravel ← API | JWT |
| WooCommerce REST API | Push products, prices, stock, content, images | Laravel → Woo | Consumer key/secret |
| WooCommerce webhooks | Receive order + customer events | Woo → Laravel | HMAC-SHA256 signature, verified + dedup'd |
| Bitrix24 CRM REST | Push deals/contacts/companies (via `bitrix24/b24phpsdk ^1.10`) | Laravel → Bitrix24 | Inbound webhook URL |
| n8n competitor scraping | CSV files dropped in `storage/app/competitors/incoming/` | Laravel reads disk | File-based, atomic `.tmp → rename` convention |

**Hosting:** Same VPS as `meetingstore.co.uk` WooCommerce install. Subdomain `ops.meetingstore.co.uk`.

**Relationship to existing 21CAV Rams2 platform:** Separate app, separate DB. No runtime coupling in v1. Phase 12 candidate: RAMS integration hook for Meeting Store customers who are also 21CAV installation clients.

**Tech stack shipped (all verified v1.50.1):**
- PHP 8.2+ (floor; 8.4 works); Laravel 12; Filament 3.3; Horizon (7 named queue supervisors)
- MySQL 8 (`meetingstore_ops` + `meetingstore_ops_testing`); Redis 7 (phpredis extension)
- `automattic/woocommerce ^3.1` (Woo REST); `bitrix24/b24phpsdk ^1.10` (Bitrix CRM — v1.10 pinned because 3.x needs PHP 8.4+ floor); `spatie/simple-excel ^3.9` (CSV ingest + export); `intervention/image ^3.11` (auto-create image pipeline); `spatie/laravel-permission ^6.0` + `bezhansalleh/filament-shield ^3.3` (RBAC); `spatie/laravel-activitylog ^4.12` + `rmsramos/activitylog ^2.0` (audit trail)
- Pest 3 + PHPUnit 11; Deptrac 2 with dual-config sync (`depfile.yaml` + `deptrac.yaml`)

**Test posture:** Phases 1-5 Feature suite was green at execution time (~780 tests passing). Phases 6-7 authored ~120 Feature tests but execution was MySQL-deferred in the code-executor sandbox — the `cutover:checklist` Gate 3 enforces running them online before `WOO_WRITE_ENABLED=true` flip.

## Constraints

- **Tech stack fixed** (Laravel 12 + PHP 8.2+ + Filament 3 + Horizon + Redis + MySQL + Pest); no breaking changes mid-v2
- **WooCommerce integration: REST only, never direct WP DB writes** — Deptrac `WpDirectDb` layer architectural test enforces across Sync + CRM + Cutover domains
- **Event-driven sync** — all cross-domain communication via `DomainEvent` + `ShouldDispatchAfterCommit`; correlation_id threads through Context + spatie LogBatch + queued jobs
- **Audit everything** — every write hits `audit_log` (spatie activitylog); every outbound HTTP call hits `integration_events` via `IntegrationLogger`; every failure has a trace back to its origin webhook
- **Suggestions-first for any data-changing feature** — 4 active producers (margin_change, crm_push_failed, new_product_opportunity, auto_create_failed); seeded stub applier pattern proven across phases
- **Feed abstraction (FOUND-13)** — `FeedGenerator` contract stub exists; Phase 8 v2 slots channel feeds into this seam without core refactor
- **Compliance** — itgalaxy replacement is partly compliance-driven; sanctions posture held by using Bitrix's own official SDK (not third-party plugins)
- **Parity first** — cutover command framework allows operator to run in parallel with legacy plugins; ≥99% parity over 7 days before flipping `WOO_WRITE_ENABLED=true`
- **Dual-YAML Deptrac sync** — Phase 5 05-05 lesson locked: both `depfile.yaml` and `deptrac.yaml` MUST be updated in lockstep when adding layers
- **Shield:generate P5-F restoration protocol** — after every `shield:generate --all`, hand-written policies restored from git; PolicyTemplateIntegrityTest enforces `{{ Placeholder }}` ban

## Key Decisions

| Decision | Rationale | Outcome |
|----------|-----------|---------|
| Laravel owns all product/pricing/CRM data; Woo is display layer only | Enables future multi-channel expansion without core rewrite | ✓ Good (v1.50.1) — proved via Woo REST-only constraint honoured across 85 requirements |
| One-way CRM sync (Woo → Bitrix24); no inbound webhook | itgalaxy's two-way sync is the feature we're most willing to drop; reduces complexity | ✓ Good (v1.50.1) — Bitrix push path shipped with URL-based image pass-through, dedup via BitrixEntityMap + UF_CRM_WOO_ORDER_ID |
| Deal + Contact + Company entity mode fixed | Covers 100% of current usage | ✓ Good (v1.50.1) — no configurability burden shipped |
| Most-specific pricing rule wins (no stacking) | Predictable, auditable | ✓ Good (v1.50.1) — 50-triple golden fixture passes to the penny |
| Capture UTM + GA Client ID; skip Roistat/Yandex Metrika | UK market; Russian analytics stack unused | ✓ Good (v1.50.1) — 6 Bitrix custom fields (UF_CRM_WOO_UTM_*) on Deal + Contact |
| Same VPS, `ops.meetingstore.co.uk` subdomain | Keeps latency to Woo API low | — Pending (ops deploys post-cutover) |
| Laravel 12 + Filament 3 + Horizon stack fixed | User preference; matches existing 21CAV ecosystem | ✓ Good (v1.50.1) — no stack changes mid-build |
| Domain events + audit log + suggestions pattern from day one | Non-negotiable for Phase 10 AI agent compound-ability | ✓ Good (v1.50.1) — 4 real suggestion producers shipped; FeedGenerator contract stubbed for Phase 8 |
| Dry-run-default CLI pattern | Protect against accidental prod writes | ✓ Good (v1.50.1) — pattern inherited by sync:supplier, pricing:recompute, bitrix:backfill-orders, competitor:watch, cutover:* commands |
| Listener-based Phase 2 extension (never modify SyncChunkJob) | Preserves Phase 2 regression test scope | ✓ Good (v1.50.1) — Phase 6 pin enforcement via ApplyPinsDuringSync listener verified by D-11 grep test |
| Pitfall P5-F shield:generate restoration protocol | Shield 3.9.10 overwrites hand-written policies | ✓ Good (v1.50.1) — executed clean on Phase 4 + Phase 5 + Phase 6 |
| Phase 6 `NewProductOpportunityApplier` move from Competitor → ProductAutoCreate | Closes Phase 5→6 loop with one-way arrow preserved | ✓ Good (v1.50.1) — old file deleted, new registration in AppServiceProvider verified |
| Dual-config Deptrac sync (depfile.yaml + deptrac.yaml) | Phase 5 lesson: stale config breaks layer tests silently | ✓ Good (v1.50.1) — 13 domain layers verified in both configs at audit time |

## Cutover Runbook Reference

Full runbook at `docs/ops/cutover-handover.md`. Key invocation:

```bash
php artisan cutover:checklist
```

Exit code 1 until all gates PASS. Wraps all 3 operator carry-forward gates plus the D-19 cutover runbook steps. See `docs/ops/cutover-handover.md` Appendix A for the canonical sequence.

## Evolution

This document evolves at milestone boundaries. Next evolution: `/gsd-new-milestone` for v2 scope.

---
*Last updated: 2026-04-24 — v2.0 Intelligence + B2B milestone kicked off (v1.50.1 shipped same day)*
