---
phase: quick-260527-sgp
plan: 01
subsystem: pricing
tags: [pricing-ops, sourcing-gaps, competitor-position, supplier-feed, deptrac]
requires:
  - CompetitorPositionScanner (cost buckets — now status='publish' scoped)
  - competitor_prices (read via raw DB::select, windowed)
  - competitors table (read via raw DB::select, deptrac-safe)
  - feeds_products on stcav_dash (remote per-supplier feed)
  - IntegrationCredentialResolver (SupplierDb credential)
provides:
  - "Sourcing gaps" = competitor-listed parts NO supplier carries + we don't sell
  - SourcingGapScanner (Pricing) + SupplierFeedSourceabilityChecker (Sync)
  - pricing:scan-sourcing-gaps command (cached, weekly Sun 05:30)
  - dashboard tile + filterable modal + CSV/XLS export
  - cost dashboard counters now exclude no-supplier (pending/obsolete) products
affects:
  - PricingOpsReport (new SOURCING_GAPS_CACHE_KEY, bucket, sourcingGaps(), csv branch)
  - PricingOperationsPage (sourcingGapsAction + view data)
  - pricing-operations blade (2-up add/gaps tile row)
tech-stack:
  added: []
  patterns:
    - "Cross-domain split to satisfy deptrac: Pricing owns the local read (competitor_prices + competitors + products via WpDirectDb) and delegates the remote-feed read to a Sync service that owns the Integrations credential (Pricing↛Integrations)"
    - "Unbuffered mysqli stream (MYSQLI_USE_RESULT) + early-exit once all wanted keys matched — flat client memory regardless of feed size"
    - "Test double via non-final Sync checker subclass returning a fixed sourceable set (no live supplier DB in tests)"
key-files:
  created:
    - app/Domain/Pricing/Services/SourcingGapScanner.php
    - app/Domain/Sync/Services/SupplierFeedSourceabilityChecker.php
    - app/Domain/Pricing/Console/Commands/ScanSourcingGapsCommand.php
    - resources/views/filament/pages/pricing-ops-sourcing-gaps.blade.php
    - tests/Feature/Pricing/SourcingGapScannerTest.php
  modified:
    - app/Domain/Pricing/Services/CompetitorPositionScanner.php
    - app/Domain/Pricing/Services/PricingOpsReport.php
    - app/Domain/Pricing/Filament/Pages/PricingOperationsPage.php
    - app/Providers/AppServiceProvider.php
    - routes/console.php
    - resources/views/filament/pages/pricing-operations.blade.php
    - tests/Feature/Pricing/CompetitorPositionScannerTest.php
    - tests/Feature/Pricing/PricingOpsExportTest.php
decisions:
  - "Sourcing gap identity = competitor sku (the listing identity); sku AND mpn both checked for both local-product exclusion and supplier sourceability (mirrors existing match semantics)"
  - "Part A: status='publish' filter on the cost scanner — we can't be 'below cost' on something we can't buy; obsolete products we DO sell are already demoted to pending by --flag-obsolete"
  - "'Products to add' (≥4 suppliers) is sourceable by definition → already disjoint from sourcing gaps; no extra removal logic needed, requirement satisfied by construction"
  - "Sort gaps most-tracked first (more competitors ⇒ more likely real demand), then highest competitor price, then part for determinism"
  - "SupplierFeedSourceabilityChecker left non-final purely for test substitution"
metrics:
  duration: ~50 min
  completed: 2026-05-27
  tasks: 2
  files: 13
---

# Phase quick-260527-sgp Plan 01: Sourcing gaps view + exclude no-supplier products from cost counts — Summary

Builds the operator-requested **"Sourcing gaps"** view — parts a competitor currently lists that **no supplier carries** and we don't sell (almost certainly obsolete, or we need to find a supplier) — and stops such no-supplier products from inflating the cost dashboard. *"...dont count in any of the dash counter as if we have no supplier, they are very likely obsolete or we need to find a supplier."*

## What was built

**Part A — cost counters exclude no-supplier products (1 line)**
- `CompetitorPositionScanner` product query now filters `->where('status', 'publish')`. Pending/obsolete products (those `supplier:db-sync --flag-obsolete` demotes because no supplier offer exists) no longer count in below_cost / at_floor / winnable / matched. We can't be "below cost" on something we can't buy.

**Part B — the Sourcing gaps view**
- **`SourcingGapScanner`** (Pricing): windowed `competitor_prices` → latest row per (competitor, sku) → aggregate per competitor sku (distinct-competitor count + lowest current ex-VAT + winning competitor, lowest-id tie-break). Drops parts we already sell (sku OR mpn = a local product sku). Delegates a remote feed sourceability check; **gaps = parts no supplier carries.** Competitor names resolved batched via raw `DB::select` on `competitors` (no Competitor-domain import — deptrac, same pattern as the position scanner).
- **`SupplierFeedSourceabilityChecker`** (Sync): given candidate keys, one **unbuffered** scan of `feeds_products` (matching both `mpn` and `suppliersku`, lowercased/trimmed) returns which keys any supplier carries. Early-exits once every wanted key is matched; client memory stays flat. Lives in Sync because Sync owns the Integrations credential (Pricing↛Integrations).
- **`pricing:scan-sourcing-gaps`** command — caches the scan under `PricingOpsReport::SOURCING_GAPS_CACHE_KEY`; registered in AppServiceProvider; scheduled weekly **Sun 05:30 London** (30 min after `supplier:scan-add-candidates` so the two feed scans don't overlap).
- **`PricingOpsReport`** — `SOURCING_GAPS_CACHE_KEY`, `'sourcing_gaps'` in `BUCKETS`, `sourcingGaps()` accessor, and a `csv()` branch (Part / MPN / Competitors / Lowest competitor ex-VAT / Competitor).
- **Dashboard** — a second catalogue-expansion tile ("Sourcing gaps") beside "Products to add", a filterable modal (`pricing-ops-sourcing-gaps.blade.php`), and CSV/XLS export via the existing route.

## Why this satisfies the request

- **"build a Sourcing gaps view"** → tile + modal + export, scanned weekly.
- **"remove these products from to add list"** → "Products to add" requires ≥4 suppliers, so it's sourceable *by definition*; sourcing gaps (0 suppliers) are inherently disjoint — nothing to remove, satisfied by construction.
- **"dont count in any of the dash counter"** → Part A removes the products-we-sell-but-can't-source side (status≠publish); the competitor-only side never entered the cost buckets (those start from local products).

## Verification results

| Gate | Command | Result |
|------|---------|--------|
| Pest — sourcing gaps | `pest …/SourcingGapScannerTest.php` | **PASS** — 6 passed |
| Pest — cost scanner (incl. Part A regression) | `pest …/CompetitorPositionScannerTest.php` | **PASS** — 8 passed |
| Pest — export (incl. sourcing_gaps) | `pest …/PricingOpsExportTest.php` | **PASS** — 8 passed |
| Pest — page render/RBAC | `pest …/Filament/PricingOperationsPageTest.php` | **PASS** — 3 passed |
| Deptrac | `deptrac analyse` | **PASS for new files** — SourcingGapScanner / SupplierFeedSourceabilityChecker / ScanSourcingGapsCommand: **zero** violations |
| Pint | `pint <changed>` | **PASS** — auto-fixed checker formatting only |
| Command registered | `artisan list \| grep scan-sourcing-gaps` | **PASS** — `pricing:scan-sourcing-gaps` listed |

PHP via the Herd binary (`$HOME/.config/herd/bin/php.bat`, 8.4); prod is 8.3.

## Deviations from Plan

- **`SupplierFeedSourceabilityChecker` left non-`final`** (the codebase prefers `final`) purely so the scanner test can substitute a fixed sourceable set without a live supplier-DB connection. Documented in the class docblock.
- **Deptrac pre-existing violations (NOT introduced here):** the run reports 41 total, all in untouched files (`ScanSupplierAddCandidatesCommand`→PricingOpsReport, `SupplierSyncDigestComposer`→DB, etc.) — same baseline as quick task 260527-c0m. My new classes have zero.

## Known Stubs

None.

## Threat Flags

None new. The Sync checker opens the **same** remote supplier-DB connection `SupplierAddCandidateScanner` already uses (`IntegrationCredentialResolver` → `IntegrationCredentialKind::SupplierDb`), read-only `SELECT` on `feeds_products`. Competitor names come from our own DB, echoed via auto-escaped Blade. No Woo writes, no new endpoints, no schema change.

## Manual check still pending

Open Pricing Operations → run `php artisan pricing:scan-sourcing-gaps` once (or wait for Sun 05:30) → confirm the "Sourcing gaps" tile populates, the modal lists Part / MPN / Competitors / Lowest comp / Competitor, the filter box works, and CSV/XLS export downloads.

## Commits

- `23c8632` feat(pricing): Sourcing gaps view + exclude no-supplier products from cost counts

## Self-Check: PASSED

- app/Domain/Pricing/Services/SourcingGapScanner.php — FOUND (23c8632)
- app/Domain/Sync/Services/SupplierFeedSourceabilityChecker.php — FOUND (23c8632)
- app/Domain/Pricing/Console/Commands/ScanSourcingGapsCommand.php — FOUND (23c8632)
- resources/views/filament/pages/pricing-ops-sourcing-gaps.blade.php — FOUND (23c8632)
- tests/Feature/Pricing/SourcingGapScannerTest.php — FOUND (23c8632)
