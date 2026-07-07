---
phase: 260707-w2w-woo-maintenance-section-pass-1-add-a-woo
plan: 01
subsystem: Products / Woo Maintenance (Filament admin)
tags: [filament, products, woo-maintenance, gaps, stats-widget, page, cache, driver-portable, tdd, additive]
requires:
  - App\Domain\Products\Models\Product (status / woo_product_id / ean / stock_status / brand_id / category_id / gallery_image_urls)
  - AutoCreateHealthPage::emptyImagesExpr (driver-portable JSON-length precedent)
  - CompetitorFreshnessWidget (StatsOverviewWidget Stat::make precedent)
provides:
  - app/Domain/Products/Services/ProductGapReport.php (liveBase + GAPS + apply + 60s-cached counts — single source of truth)
  - ProductGapReport::GAPS const (missing_images / missing_ean / missing_stock_status / missing_brand / missing_category)
  - ProductGapReport::apply(Builder, gap) ready for the Pass-2 drill-down list to reuse
  - app/Filament/Pages/WooMaintenanceOverviewPage.php (new 'Woo Maintenance' nav group + admin-only Overview)
  - app/Filament/Widgets/WooMaintenanceGapsWidget.php (Stat per gap + Live-on-Woo total)
affects:
  - Filament admin sidebar (new 'Woo Maintenance' group → 'Maintenance Overview', sort 10)
tech-stack:
  added: []
  patterns:
    - service as single source of truth (liveBase + per-gap apply) shared by overview counts and (Pass 2) the drill-down list
    - driver-portable empty-images expr (SQLite json_array_length / MariaDB JSON_LENGTH) mirroring AutoCreateHealthPage::emptyImagesExpr
    - 60s Cache::remember on the 6 COUNT queries (mirrors AutoCreateHealthPage nav-badge / CompetitorFreshnessWidget caching)
    - StatsOverviewWidget as a page header widget (auto-discovered)
key-files:
  created:
    - app/Domain/Products/Services/ProductGapReport.php
    - app/Filament/Widgets/WooMaintenanceGapsWidget.php
    - app/Filament/Pages/WooMaintenanceOverviewPage.php
    - resources/views/filament/pages/woo-maintenance-overview.blade.php
    - tests/Feature/Products/ProductGapReportTest.php
  modified: []
decisions:
  - Scope = products LIVE on Woo (status='publish' AND woo_product_id NOT NULL). Drafts and never-pushed products are OUT of scope by design — the operator note offers a scope toggle if the business wants all local products.
  - EAN and stock-status gaps are null-OR-blank (TRIM(x) = ''); brand/category are NULL id; images is NULL or empty JSON array. All predicates live ONLY in ProductGapReport::apply so the overview counts and the Pass-2 list cannot drift.
  - counts() is cached 60s under 'woo_maintenance.gap_counts' so the stat page does not re-run 6 COUNTs on every render.
  - No ->url() on the stat cards yet — Pass 2 wires the drill-down links.
metrics:
  duration: ~25m
  completed: 2026-07-07
---

# Phase 260707-w2w Plan 01: Woo Maintenance Overview (Pass 1) Summary

Added a new **Woo Maintenance** sidebar group whose admin-only **Maintenance Overview** page shows at-a-glance COUNTS of products live on Woo (`status='publish'` AND `woo_product_id NOT NULL`) that are missing images / EAN / stock status / brand / category, plus the total live-product count. All counts are served by a new, 60s-cached, driver-portable `ProductGapReport` service — the single source of truth the Pass-2 drill-down list will reuse via `apply()`. Purely additive: no existing page, command, or model was modified.

## What was built

- **`ProductGapReport` (app/Domain/Products/Services)** — the single source of truth:
  - `liveBase()`: `Product::where('status','publish')->whereNotNull('woo_product_id')`.
  - `GAPS` const: ordered `key => label` map for the five maintainable gaps.
  - `apply(Builder $query, string $gap)`: narrows a query to one gap. `missing_images` = `gallery_image_urls` NULL or empty JSON array (driver-portable `emptyImagesExpr()`: SQLite `json_array_length` / MariaDB `JSON_LENGTH`, mirroring `AutoCreateHealthPage::emptyImagesExpr`); `missing_ean` / `missing_stock_status` = NULL or `TRIM(x) = ''`; `missing_brand` / `missing_category` = NULL id.
  - `counts()`: `Cache::remember('woo_maintenance.gap_counts', 60, …)` returning `['total'=>int, 'gaps'=>[key=>int]]`.
- **`WooMaintenanceGapsWidget` (StatsOverviewWidget)** — a leading `Stat::make('Live on Woo', total)` plus one `Stat::make(label, count)` per gap; colour `warning` when count>0 else `success`. Reads only `ProductGapReport::counts()`. No `->url()` yet.
- **`WooMaintenanceOverviewPage`** — `navigationGroup 'Woo Maintenance'`, label 'Maintenance Overview', admin-only `canAccess`, `getHeaderWidgets()=[WooMaintenanceGapsWidget::class]`, + a minimal blade view. Page + widget auto-discover (Filament scans `app/Filament/Pages` + `app/Filament/Widgets`).

## Test — tests/Feature/Products/ProductGapReportTest.php

`RefreshDatabase`; `Cache::flush()` in `beforeEach` so the 60s cache never bleeds across cases. Seeds 6 LIVE products each with exactly one gap (P1 empty gallery, P2 null ean, P3 '' stock_status, P4 null brand_id, P5 null category_id, P6 complete) plus a DRAFT (all gaps) and a not-on-Woo product (all gaps) that must be excluded. Asserts `counts()['total']==6`, each gap count `==1`, and spot-checks `apply(liveBase(),'missing_images')` returns P1 only (proving scope wins over the gap predicate for the draft/not-on-Woo rows). A fourth case renders the page as admin (`assertSuccessful`).

## Verification (Herd php)

- `pest tests/Feature/Products/ProductGapReportTest.php` → **4 passed (8 assertions)**. Confirmed `counts()['total']==6`, each gap `==1`, drafts + not-on-Woo excluded.
- `pint --test` on the 3 source files + the test → `{"result":"pass"}` (auto-fixed phpdoc/import/concat-space style on first pass, then clean).

## Deviations from Plan

None — plan executed exactly as written; the `<interfaces>` code was used verbatim (widget + page follow the described spec). RED (`test` commit) → GREEN (`feat` commit), no refactor needed.

## Commits

- `55b6bda` — test(260707-w2w): failing test (RED)
- `c4ef3f1` — feat(260707-w2w): ProductGapReport + Overview page/widget (GREEN)

NOT pushed / NOT deployed (local commits only).

## Operator notes

- **Deploy:** push main → VPS `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh` (no migration).
- New sidebar group **Woo Maintenance → Maintenance Overview**: at-a-glance counts of live-on-Woo products missing images / EAN / stock status / brand / category (+ total live). Counts cache 60s.
- **Scope:** products live on Woo (`status=publish` + `woo_product_id`). Drafts / not-yet-pushed products are excluded by design — say so if you'd rather include ALL local products and I'll add a scope toggle.

## Pass 2 (next)

A **Catalogue Gaps** drill-down list — filter by gap (reusing `ProductGapReport::apply`) with one-click fixes (Source images, Backfill EAN, Hydrate stock, Resync to Woo); the Overview stat cards will `->url()` into it. The existing fix commands (`products:source-images` / `retry-missing-images` / `backfill-merchant-feed` / `hydrate-live-stock` / `resync-to-woo`) become the row/bulk actions.

## Self-Check: PASSED

- FOUND: app/Domain/Products/Services/ProductGapReport.php
- FOUND: app/Filament/Widgets/WooMaintenanceGapsWidget.php
- FOUND: app/Filament/Pages/WooMaintenanceOverviewPage.php
- FOUND: resources/views/filament/pages/woo-maintenance-overview.blade.php
- FOUND: tests/Feature/Products/ProductGapReportTest.php
- FOUND commit: 55b6bda (test/RED)
- FOUND commit: c4ef3f1 (feat/GREEN)
