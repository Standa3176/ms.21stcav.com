# Phase 5: Competitor Analysis - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-04-19
**Phase:** 05-competitor-analysis
**Mode:** `--auto` (recommended defaults auto-selected without interactive prompting)
**Areas discussed:** Competitor identity assignment, Column auto-detection, Noise-suppression thresholds, Orphaned-row handling

---

## Competitor identity assignment (auto-selected)

| Option | Description | Selected |
|--------|-------------|----------|
| Filename prefix `{slug}_{date}.csv` (Recommended) | Watcher parses prefix before first underscore, resolves competitor_id from lookup | ✓ |
| Subdirectory per competitor | More n8n config surface; no operational benefit | |
| In-CSV marker column | Brittle if n8n output changes | |

**Auto-choice:** Filename prefix.
**Rationale:** Simplest n8n contract; auto-discoverable; new prefixes surface as `status=pending` competitor rows in Filament for admin naming.

---

## Column auto-detection strategy (auto-selected)

| Option | Description | Selected |
|--------|-------------|----------|
| Two-stage: heuristics first, persist mapping after (Recommended) | Header heuristics on first-ever ingest; saved mapping used on subsequent CSVs | ✓ |
| Header heuristics every time | Brittle when competitor renames columns | |
| Per-competitor mapping only (no heuristics) | Requires admin to configure every competitor manually before first ingest | |

**Auto-choice:** Two-stage (research C.2 differentiator).
**Rationale:** Combines automation for new competitors with drift-resistance after first success. Ambiguous first-ingest cases (D-04) quarantine + surface as admin-resolvable issue.

---

## Noise-suppression thresholds (auto-selected)

| Option | Description | Selected |
|--------|-------------|----------|
| 3 scrapes + 10 sales/90d (Recommended) | ≥10 orders/90d ≈ real demand; filters slow-movers | ✓ |
| 3 scrapes + 5 sales/90d | Easier to trigger; more suggestions but potentially noisy | |
| 3 scrapes + no sales threshold | v1 simplest; would flood inbox with margin suggestions for zero-demand SKUs | |

**Auto-choice:** 3 scrapes + 10 sales/90d.
**Rationale:** REQUIREMENTS.md locks "≥N sales" without specifying N; 10 is the tightest sensible filter that still catches real trends. All three thresholds are config-driven so ops can tune post-cutover.

---

## Orphaned-row handling (auto-selected)

| Option | Description | Selected |
|--------|-------------|----------|
| Surface as `new_product_opportunity` suggestion (Recommended) | Research C.3 differentiator — turns waste into insight | ✓ |
| Log to csv_parse_errors only | Silent loss of signal; ops unaware | |
| Silent drop | Current legacy behaviour; rejected on principle (suggestions-first) | |

**Auto-choice:** new_product_opportunity suggestion with cross-competitor deduplication (D-09).
**Rationale:** Converts orphaned-row waste into a product-gap signal. Phase 6 (Product Auto-Create) becomes the approving consumer. Phase 5 ships the producer + a no-op applier stub (Phase 4 `crm_push_failed` → `CrmPushRetryApplier` pattern).

---

## Claude's Discretion (defaults documented in CONTEXT.md)

- Stale-feed detection + alerting (48h threshold, extend `AlertRecipient` with `receives_competitor_alerts` toggle per Phase 2 D-08 / Phase 4 D-12 pattern)
- Trend chart time windows (default 30d, toggles 7d/30d/90d/1y, Filament Chart.js widget)
- CSV retention prune (90d default, config/competitor.php configurable, raw files only — NEVER competitor_prices rows)
- CSV processing queue: `competitor-csv` (Phase 1 FOUND-09 already allocated)
- `MarginAnalyser` reuses `PriceCalculator::stripVat()` (Phase 3 D-05) — NEVER duplicate VAT math
- Encoding detection order (BOM → mb_detect → UTF-8 fallback)
- Decimal format detection (comma-as-decimal vs dot-as-decimal per first 10 non-header price rows)
- Schema design (`competitors`, `competitor_prices`, `competitor_csv_mappings`, `competitor_ingest_runs`, `csv_parse_errors` tables)

## Deferred Ideas

- MAP (Minimum Advertised Price) monitoring — research C.3 "depends on MS selling MAP-protected brands"; ops confirmation required
- Real-time / webhook competitor feeds (anti-feature per research C.4)
- In-Laravel scraping (anti-feature — n8n owns scraping)
- Fuzzy MPN matching (v2 with trigram index or external service)
- Price-change notifications to Slack (defer to Phase 7 notification centre)
- Auto-apply margin suggestions (violates "suggestions-first" project constraint)
- Merchant Center / Meta catalog competitor comparison (v2 Phase 8 feeds)
- MySQL 8 partitioning (revisit at 10M+ rows)
- Multi-currency competitor prices (v1 = GBP inc-VAT only; schema forward-compatible)
- Combined SupplierPriceChanged + CompetitorPriceRecorded listener (planner judgement call)
- Import-preview / validation UI (quarantine flow covers the error case)
- Orphan grouping by brand for bulk actions (Phase 6 differentiator)
