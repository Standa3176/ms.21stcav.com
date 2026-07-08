---
phase: 260708-cey-woo-maintenance-reconciliation-pass-2-re
plan: 01
subsystem: products / woo-maintenance
tags: [filament, woo-maintenance, reconciliation, dashboard, tdd]
requires:
  - "woo_* reconciliation columns + products:reconcile-woo-maintenance (260708-b4f / 260708-bdw)"
provides:
  - "Woo Maintenance dashboard reporting TRUE whole-shop gaps from the reconciled woo_* mirror"
  - "reconciliation coverage + freshness (reconciled X/Y + last-reconciled) on the Overview"
affects:
  - app/Domain/Products/Services/ProductGapReport.php
  - app/Filament/Widgets/WooMaintenanceGapsWidget.php
  - app/Filament/Pages/CatalogueGapsPage.php
tech-stack:
  added: []
  patterns:
    - "single cached aggregate (conditional SUMs) over plain indexed columns — no per-row JSON scan"
    - "gap predicates gate on woo_reconciled_at IS NOT NULL (only reconciled products have known Woo state)"
key-files:
  created: []
  modified:
    - app/Domain/Products/Services/ProductGapReport.php
    - app/Filament/Widgets/WooMaintenanceGapsWidget.php
    - app/Filament/Pages/CatalogueGapsPage.php
    - tests/Feature/Products/ProductGapReportTest.php
    - tests/Feature/Products/CatalogueGapsPageTest.php
decisions:
  - "GAPS = images/EAN/category only; brand (not in the Woo /products reconciliation) and stock (always set on Woo) dropped from the gap set"
  - "Removed the Hydrate stock fix action from Catalogue Gaps — stock is no longer a surfaced gap; Source images / Backfill EAN / Resync retained unchanged"
metrics:
  tasks: 1
  duration: ~30m
  completed: 2026-07-08
---

# Phase 260708-cey Plan 01: Woo Maintenance Reconciliation Pass 2 (rewire) Summary

Rewired the Woo Maintenance dashboard off the misleading local-mirror emptiness and onto the reconciled `woo_*` fields, so it now reports the TRUE whole-shop gaps (prod-validated: 180 missing images / 628 missing EAN / 0 missing category over 4,604 live) plus reconciliation coverage and freshness — all via cheap plain-indexed-column queries that keep the 260708-akz hang fix intact.

## What changed

### `ProductGapReport`
- `GAPS` reduced from 5 to **3**: `missing_images` / `missing_ean` / `missing_category`. Brand + stock dropped (brand isn't in the Woo `/products` reconciliation; `stock_status` is always set on Woo so it's never a gap — documented in the class docblock).
- `apply()` now gates on `whereNotNull('woo_reconciled_at')` first (gaps are only meaningful for products whose real Woo state we know), then: `missing_images` → `where('woo_image_count', 0)`, `missing_ean` → `whereNull('woo_gtin')`, `missing_category` → `where('woo_category_count', 0)`.
- `counts()` is still a **single 300s-cached aggregate**, now returning `total` (all live), `reconciled`, `not_reconciled`, `last_reconciled_at` (`MAX(woo_reconciled_at)`), and the 3 `gaps` — each gap counted only `WHERE woo_reconciled_at IS NOT NULL`.
- Removed the old `EMPTY_GALLERY_SQL` / gallery / ean / stock_status / brand predicates.

### `WooMaintenanceGapsWidget`
- Stats: **Live on Woo** total, a **Reconciled X / Y** stat whose description is the last-reconciled `diffForHumans()` (or `never — run products:reconcile-woo-maintenance`) and which colours `warning` when anything is un-reconciled or reconciliation never ran, then the **3 gap stats** each deep-linking into the pre-filtered Catalogue Gaps page.

### `CatalogueGapsPage`
- Gap `SelectFilter` options = the 3 `ProductGapReport::GAPS`, default `missing_images`, `->query` reuses `$report->apply` (now woo_*-based). Base query stays `liveBase()`.
- Columns replaced with the reconciled truth: `woo_image_count` (Images, danger when 0), `woo_gtin` (EAN, `— none`, danger when null), `woo_category_count` (Categories, danger when 0), plus `sku`, `name`, and `woo_reconciled_at` (Reconciled). `woo_product_id` kept as a hidden-by-default toggle.
- **Removed the Hydrate stock** row + bulk action (stock is no longer a surfaced gap). **Source images / Backfill EAN / Resync** fix actions (row + bulk) unchanged — still `Artisan::call($cmd, ['--skus' => …])`.

## Tests (TDD)
- **RED** (`77a0a6d`): rewrote both tests to seed the `woo_*` reconciled fields; failed 6 / passed 5 against the old local-mirror predicates.
- **GREEN** (`be9e522`): after the rewire, **11 passed (47 assertions)**.
  - `ProductGapReportTest`: seeds R1 (woo_image_count=0), R2 (woo_gtin=null), R3 (woo_category_count=0), R4 (fully populated), R5 (UNRECONCILED). Asserts `counts()` → total=5, reconciled=4, not_reconciled=1, each gap=1, `last_reconciled_at` not null; `apply(liveBase(),'missing_images')` = `[R1]` only; the unreconciled R5 is excluded from every gap.
  - `CatalogueGapsPageTest`: `filterTable('gap',…)` narrows via `woo_*`; the Source images / Resync fix actions still dispatch the `--skus` commands; the widget gap stats deep-link per gap. `Cache::flush()` in `beforeEach`.

## Deviations from Plan
- **[Judgement call] Removed the Hydrate stock fix action.** The task and `<interfaces>` list the fix actions to keep as exactly "Source images / Backfill EAN / Resync" (twice), and stock was dropped from the gap set. Kept those three unchanged and removed the now-inconsistent Hydrate stock row + bulk action. No test asserted Hydrate stock.
- Otherwise the `<interfaces>` code was used as written. Reconciliation command + migration untouched (additive only).

## Verification
- `pest ProductGapReportTest CatalogueGapsPageTest` → **11 passed (47 assertions)**.
- `pint --test` on the 3 source files → `{"result":"pass"}`.
- Gaps now come from the reconciled `woo_*` fields over reconciled live products (unreconciled excluded), and `counts()` reports coverage (`reconciled` / `not_reconciled`) + freshness (`last_reconciled_at`).

## Operator notes
- **Deploy:** push `main` → `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh` (**no migration** — code only). NOT pushed/deployed here (local only).
- The Maintenance Overview now shows the TRUE shop-wide gaps (~180 images / ~628 EAN / 0 category over ~4,604 live) plus a Reconciled X/Y + last-reconciled stat. Cards drill into Catalogue Gaps → Source images / Backfill EAN / Resync (cap batches — API cost).
- Freshness: the nightly `products:reconcile-woo-maintenance` (04:30) keeps `woo_*` current; `counts()` caches 300s. If Reconciled shows 0 / stale, run the command manually.
- Dropped from the gap set: brand (not in the Woo `/products` reconciliation — would need a `product_brand` scan) and stock status (Woo always sets one). Note for a future brand-reconciliation pass.

## Self-Check: PASSED
- Files exist: ProductGapReport.php, WooMaintenanceGapsWidget.php, CatalogueGapsPage.php, both test files — all present.
- Commits exist: `77a0a6d` (test RED) + `be9e522` (feat GREEN).
