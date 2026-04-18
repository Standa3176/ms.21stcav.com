# Phase 2: Supplier Sync - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-04-18
**Phase:** 02-supplier-sync
**Areas discussed:** Variable products strategy, Default run mode, Error threshold, CSV report + new-SKU detection

---

## Variable products strategy

### Q1: Does meetingstore.co.uk sell variable WooCommerce products?

| Option | Description | Selected |
|--------|-------------|----------|
| Yes — significant variant-product catalogue | Many products with size/color/finish variations. ProductVariant model needed. | ✓ |
| Yes — small number, defer proper modelling to Phase 6 | A handful; Phase 2 treats all as simple, logs variants to import_issues. | |
| No — simple products only | Catalogue is all simple products. Cleanest code path. | |

**User's choice:** Yes — significant variant-product catalogue

### Q2: How does the supplier DB map to Woo variants?

| Option | Description | Selected |
|--------|-------------|----------|
| One supplier SKU per Woo variant (Recommended) | Each variation has own SKU in both systems; sync iterates variants. | |
| Parent-level pricing with variant attributes layered on | One SKU per parent; variant differences are attributes. | |
| Mixed — some parent-level, some variant-level | Phase 2 detects by Woo variations presence and branches. | ✓ |

**User's choice:** Mixed — some products parent-level, some variant-level

### Q3: Missing-variant handling

| Option | Description | Selected |
|--------|-------------|----------|
| Only the affected variation (Recommended) | Variation → `private`; parent stays `publish`; siblings unaffected. | ✓ |
| Whole parent product → pending | Any missing variant hides whole product. Conservative. | |
| Leave publish, mark sync issue in import_issues | Stale data visible to customers; riskier. | |

**User's choice:** Only the affected variation (Recommended)

---

## Default run mode (dry vs live)

### Q1: What should `php artisan sync:supplier` (no flags) do by default?

| Option | Description | Selected |
|--------|-------------|----------|
| Conservative — default is dry-run; --live required (Recommended) | `sync:supplier` alone = shadow mode. `--live` flag required for real writes. Belt-and-braces. | ✓ |
| Aggressive — default is live; --dry-run for shadow | Laravel convention. Risk of typos pushing real changes. | |
| Gated by WOO_WRITE_ENABLED only | No command flag; env var controls everything. Simpler but less flexible. | |

**User's choice:** Conservative — default is dry-run; --live required to write (Recommended)

### Q2: Scheduled daily cron behaviour on Phase 7 cutover day?

| Option | Description | Selected |
|--------|-------------|----------|
| Daily cron runs --live unconditionally (Recommended) | Post-cutover cron always writes. Assumes parity proven. Simpler ops. | ✓ |
| Daily cron reads a feature flag SYNC_CRON_LIVE | Separate env var for independent cron rollback. | |
| Cron disabled until manually enabled post-cutover | Runbook manually enables after parity proven. | |

**User's choice:** Daily cron runs --live unconditionally (Recommended)

---

## Error threshold — abort or complete

### Q1: When per-item failures accumulate, when should the run ABORT vs complete?

| Option | Description | Selected |
|--------|-------------|----------|
| Tiered: complete with log + abort if systemic failure (Recommended) | Per-item non-fatal; abort on >20% error rate, 50+ consecutive, or JWT refresh fail. | ✓ |
| Never abort — always complete | Report failures but always finish. Simplest. Risk: chugging through broken supplier API. | |
| Strict abort — stop at first error | One bad SKU blocks whole run. Most conservative. | |

**User's choice:** Tiered: complete with log + abort if systemic failure (Recommended)

### Q2: When a sync aborts, what happens to partial progress?

| Option | Description | Selected |
|--------|-------------|----------|
| Resumable — cursor saved, operator can retry (Recommended) | Aborted run's cursor persists; `--resume={run_id}` picks up. Same path as SYNC-03 crash recovery. | ✓ |
| Discard partial progress — next run starts fresh | Abort clears cursor. Simpler bookkeeping. Wastes partial work. | |

**User's choice:** Resumable — cursor saved, operator can retry (Recommended)

---

## CSV report + new-SKU detection

### Q1: Who receives the emailed CSV sync report (SYNC-08)?

| Option | Description | Selected |
|--------|-------------|----------|
| Reuse existing AlertRecipients table (Recommended) | Same list as failed-job alerts. Opt-out via new `receives_sync_reports` column. | ✓ |
| Separate sync_report_recipients table + Resource | Separate list. More scope. | |
| Single hardcoded env var | Fastest; needs deploy to change. | |

**User's choice:** Reuse existing AlertRecipients table (Recommended)

### Q2: When supplier returns an unknown SKU, what does Phase 2 do?

| Option | Description | Selected |
|--------|-------------|----------|
| Emit NewSupplierSkuDetected event + log to import_issues (Recommended) | Event fires + DB row. Phase 6 wires real listener later; Phase 2 ships no-op stub. | ✓ |
| Log to import_issues only — no event emission | Operator review in Filament. Phase 6 reads the table later (needs backfill for historical). | |
| Silently ignore for Phase 2 | Don't track at all. Phase 6 adds detection logic. | |

**User's choice:** Emit NewSupplierSkuDetected event + log to import_issues (Recommended)

### Q3: What columns should the CSV report include?

| Option | Description | Selected |
|--------|-------------|----------|
| Standard set (Recommended) | sku, woo_product_id, action, reason, old/new price, old/new stock, error_message, correlation_id. | ✓ |
| Minimal — counts + failure details only | Header aggregate + only failed SKUs detailed. | |
| Maximal — full diff per SKU | Every field change logged. Largest file. Best forensics. | |

**User's choice:** Standard set (Recommended)

---

## Claude's Discretion

Areas left to research/planner defaults:

- Chunk size (derive from Woo 100/min ceiling and queue timeout)
- JWT refresh strategy (retry once on 401, then fail SKU)
- Rate-limit 429 backoff (exponential, cap 5 retries)
- Supplier → Woo SKU matcher (in-memory hash map per run)
- Progress reporting granularity (50 SKUs per progress tick)
- Tolerance comparisons (exact match; price as 2dp string)

## Deferred Ideas

None — discussion stayed within Phase 2 scope.

## Scope additions vs original REQUIREMENTS.md

These are driven by the 10 user decisions captured above and are NEW scope for Phase 2:

- `products` + `product_variants` tables (D-01)
- `receives_sync_reports` column on `alert_recipients` (D-08)
- `import_issues` table + Resource (D-09 + SYNC-12)
- `NewSupplierSkuDetected` event + no-op stub listener (D-09; Phase 6 wires real handler)
- `--live` flag on `SyncSupplierCommand` (D-04)
- `sync-errors:prune` command + schedule (Phase 1 Plan 05 TODO reference; 90d retention per D-07 Phase 1)
