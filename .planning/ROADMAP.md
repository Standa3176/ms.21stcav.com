# Roadmap: MeetingStore Ops

## Milestones

- ✅ **v1.50.1 v1 Framework** — Phases 1-7 (shipped 2026-04-24)

See `.planning/milestones/v1.50.1-ROADMAP.md` for full v1 phase details.

## Phases

<details>
<summary>✅ v1.50.1 v1 Framework (Phases 1-7) — SHIPPED 2026-04-24</summary>

- [x] Phase 1: Foundation (5/5 plans) — Laravel 12 + Filament 3 + Horizon skeleton with Domain/ layout, audit/integration/suggestions seams, RBAC, HMAC webhook middleware, WOO_WRITE_ENABLED shadow-mode flag
- [x] Phase 2: Supplier Sync (5/5 plans) — Daily resumable supplier pull, per-item Woo REST push with error capture, emailed CSV report, Filament sync-status + import-issues pages
- [x] Phase 3: Pricing Engine (5/5 plans) — Most-specific-wins PricingRule resolver, integer-pennies VAT-inclusive calculator, per-product overrides, golden-fixture parity test against legacy plugin
- [x] Phase 4: Bitrix24 CRM Sync (5/5 plans) — One-way Woo→Bitrix push of Deal + Contact + Company, dynamic field mapping, UTM/GA capture, backfill command, GDPR erasure
- [x] Phase 5: Competitor Analysis (6/6 plans) — CSV watcher with BOM-safe ingest, full-history competitor_prices, margin-delta analyser producing Suggestions, trend/deltas dashboards
- [x] Phase 6: Product Auto-Create (6/6 plans) — New-SKU detection, SEO-templated draft Woo products, image pipeline + placeholder flow, review inbox with completeness scoring, ProductOverride pin UI
- [x] Phase 7: Dashboard Polish + Cutover (6/6 plans) — Home health tiles, notification centre, global search, weekly reports, shadow-mode divergence scan, legacy-plugin crons deregistered, rollback drill, ops handover

</details>

## Progress

| Phase | Milestone | Plans Complete | Status | Completed |
|-------|-----------|----------------|--------|-----------|
| 1. Foundation | v1.50.1 | 5/5 | Complete | 2026-04-18 |
| 2. Supplier Sync | v1.50.1 | 5/5 | Complete | 2026-04-19 |
| 3. Pricing Engine | v1.50.1 | 5/5 | Complete | 2026-04-19 |
| 4. Bitrix24 CRM Sync | v1.50.1 | 5/5 | Complete | 2026-04-19 |
| 5. Competitor Analysis | v1.50.1 | 6/6 | Complete | 2026-04-19 |
| 6. Product Auto-Create | v1.50.1 | 6/6 | Complete | 2026-04-23 |
| 7. Dashboard Polish + Cutover | v1.50.1 | 6/6 | Complete | 2026-04-24 |

## Next Milestone

Run `/gsd-new-milestone` to start planning v2 (candidate scope in PROJECT.md v2 Requirements section — Phase 8 channel feeds, Phase 9 customer automation, Phase 10 AI agent framework, Phase 11 forecasting, Phase 12 B2B).
