---
phase: 260707-wa9-woo-maintenance-pass-2-catalogue-gaps-dr
plan: 01
subsystem: Products / Woo Maintenance (Filament admin)
tags: [filament, products, woo-maintenance, gaps, drill-down, page, table, select-filter, deep-link, artisan-actions, tdd, additive]
requires:
  - App\Domain\Products\Services\ProductGapReport (liveBase + GAPS + apply — Pass-1 single source of truth, REUSED not duplicated)
  - App\Domain\Products\Models\Product (sku / name / ean / stock_status / brand_id / category_id / gallery_image_urls / woo_product_id)
  - App\Filament\Pages\AutoCreateHealthPage (per-row Artisan::call([--skus]) + Notification + try/catch action shape)
  - App\Filament\Widgets\HighConfidenceSourceableWidget (tableFilters[<name>][value]=<v> deep-link precedent)
  - products:source-images / products:backfill-merchant-feed / products:hydrate-live-stock / products:resync-to-woo (existing --skus commands)
provides:
  - app/Filament/Pages/CatalogueGapsPage.php (gap-filtered live-product list + row/bulk fix actions)
  - CatalogueGapsPage gap SelectFilter (reuses ProductGapReport::apply — list matches the Overview counts)
  - WooMaintenanceGapsWidget stat cards deep-linking into CatalogueGapsPage pre-filtered per gap
affects:
  - Filament admin sidebar (Woo Maintenance group → new 'Catalogue Gaps', sort 20)
  - app/Filament/Widgets/WooMaintenanceGapsWidget.php (each Stat gains ->url())
tech-stack:
  added: []
  patterns:
    - drill-down list reusing a Pass-1 service (ProductGapReport::liveBase + apply) so the list and the Overview counts cannot drift
    - required SelectFilter whose ->query() delegates to the shared service (gap predicates defined ONCE, in ProductGapReport)
    - per-row + bulk Artisan::call([--skus]) fix actions (admin-gated, requiresConfirmation, try/catch + Notification) mirroring AutoCreateHealthPage
    - StatsOverviewWidget Stat ->url() deep-link via Filament SelectFilter form-state (?tableFilters[gap][value]=<gap>), precedent HighConfidenceSourceableWidget
key-files:
  created:
    - app/Filament/Pages/CatalogueGapsPage.php
    - resources/views/filament/pages/catalogue-gaps.blade.php
    - tests/Feature/Products/CatalogueGapsPageTest.php
  modified:
    - app/Filament/Widgets/WooMaintenanceGapsWidget.php
decisions:
  - Gap predicates are NOT redefined here — the SelectFilter ->query() calls ProductGapReport::apply($query,$value) over liveBase(), so the drill-down list is EXACTLY the set the Overview counted (single source of truth preserved from Pass 1).
  - Four fixable gaps map to one-click commands (source-images / backfill-merchant-feed / hydrate-live-stock / resync-to-woo); brand/category gaps have NO one-click fix (surfaced for manual triage). Resync is always visible; the other three are visible only when the row actually has that gap.
  - Fix actions dispatch the existing commands via Artisan::call with the ARRAY option form ['--skus'=>$sku] (never string-concatenated) — same security boundary as AutoCreateHealthPage.
  - Bulk actions gather $records->pluck('sku')->implode(',') into a single ['--skus'=>$csv] call per command (with an empty-selection guard).
  - Overview 'Live on Woo' total links to the page unfiltered (which defaults to missing_images); each gap Stat deep-links pre-filtered to its gap.
metrics:
  duration: ~30m
  completed: 2026-07-07
---

# Phase 260707-wa9 Plan 01: Woo Maintenance — Catalogue Gaps drill-down (Pass 2) Summary

Completed the Woo Maintenance section. Pass 1 shipped the **Maintenance Overview** (gap counts) + the `ProductGapReport` service; this pass adds the **Catalogue Gaps** drill-down: an admin-only Filament page that lists the live-on-Woo products for a chosen gap (reusing `ProductGapReport::liveBase()` + `apply()` so the list matches the Overview counts), with one-click **Source images / Backfill EAN / Hydrate stock / Resync to Woo** fix actions (per-row + bulk), and the Overview stat cards now deep-link into it pre-filtered per gap. Purely additive — `ProductGapReport`, the Overview page, and all existing commands are unchanged; the only edit to existing code is the widget gaining `->url()` on its stats.

## What was built

- **`CatalogueGapsPage` (app/Filament/Pages)** — `Page implements HasTable` + `InteractsWithTable`; slug `catalogue-gaps`; nav group **Woo Maintenance**, label **Catalogue Gaps**, sort 20, icon `heroicon-o-clipboard-document-list`; admin-only `canAccess`.
  - `table()->query(fn () => $report->liveBase())` — REUSES the Pass-1 base.
  - Columns: `sku` (mono, copyable, searchable), `name` (limit 50 + tooltip), `brand_id` (`#id` / `— none` danger badge), `category_id` (same), `images` (gallery count badge, danger at 0), `ean` (danger when null/blank), `stock_status` (danger when null/blank), `woo_product_id` (mono, toggleable).
  - Required **Gap** `SelectFilter` — `options(ProductGapReport::GAPS)`, `default('missing_images')`, and `->query(fn ($q,$data) => $report->apply($q,(string) $data['value']))`. The gap logic is REUSED, never redefined.
  - **Row fix actions** (admin `->authorize()` + `->visible()`, `requiresConfirmation`, `Artisan::call($cmd, ['--skus'=>$record->sku])`, try/catch + Notification — copied from `AutoCreateHealthPage`): `source_images` → `products:source-images` (visible when 0 images), `backfill_ean` → `products:backfill-merchant-feed` (visible when ean null/blank), `hydrate_stock` → `products:hydrate-live-stock` (visible when stock_status null/blank), `resync` → `products:resync-to-woo` (always visible).
  - **Bulk fix actions** — the same four commands over `$records->pluck('sku')->implode(',')` into one `['--skus'=>$csv]` call each (empty-selection guarded).
- **`resources/views/filament/pages/catalogue-gaps.blade.php`** — minimal `<x-filament-panels::page>{{ $this->table }}</x-filament-panels::page>`.
- **`WooMaintenanceGapsWidget` (edited)** — each per-gap `Stat` now `->url(CatalogueGapsPage::getUrl().'?tableFilters[gap][value]='.$key)`; the 'Live on Woo' total links to the page unfiltered. No other behaviour changed.

## Test — tests/Feature/Products/CatalogueGapsPageTest.php

`RefreshDatabase`; `Cache::flush()` in `beforeEach`. Seeds 3 LIVE products (P1 empty gallery, P2 null ean, P3 complete) via a `wa9LiveProduct` helper (unique name — avoids the known global-helper redeclare trap). 6 cases:

1. `filterTable('gap','missing_images')` → sees P1 only, not P2/P3 (proves the filter reuses `apply()`).
2. `filterTable('gap','missing_ean')` → sees P2 only.
3. `callTableAction('source_images', P1)` → `Artisan::shouldReceive('call')->with('products:source-images', ['--skus'=>'WA9-P1-NO-IMAGES'])` (spy — the real command never runs).
4. `callTableAction('resync', P1)` → `products:resync-to-woo` with `--skus`.
5. Widget: `getStats()` (reflection) — every gap key in `ProductGapReport::GAPS` appears in some Stat `->getUrl()` containing `catalogue-gaps` + the gap key (panel set via `Filament::setCurrentPanel`).
6. Admin-only `canAccess` (true for admin, false for guest + sales).

## Verification (Herd php)

- `pest tests/Feature/Products/CatalogueGapsPageTest.php tests/Feature/Products/ProductGapReportTest.php` → **10 passed (40 assertions)** — 6 new + the 4 Pass-1 `ProductGapReportTest` still green.
- `pint --test app/Filament/Pages/CatalogueGapsPage.php app/Filament/Widgets/WooMaintenanceGapsWidget.php tests/Feature/Products/CatalogueGapsPageTest.php` → `{"result":"pass"}` (test file auto-fixed once for import ordering + fully-qualified Stat type, then clean; re-ran tests → still 10 passed).

Confirmed: the gap filter narrows the list via `ProductGapReport::apply` (missing_images → P1 only; missing_ean → P2 only), and the fix actions call the right commands with the row SKU (`products:source-images` / `products:resync-to-woo` with `['--skus'=>'WA9-P1-NO-IMAGES']`).

## Deviations from Plan

None — plan executed exactly as written; the `<interfaces>` code was used as specified. The per-row/bulk actions were factored into small private `fixAction()`/`bulkFixAction()` helpers (identical behaviour to spelling out four Action blocks — a readability choice, not a behaviour change). RED (`test` commit) → GREEN (`feat` commit), no refactor commit needed.

## Commits

- `9adc332` — test(260707-wa9): failing test for the Catalogue Gaps drill-down (RED)
- `0d46bfa` — feat(260707-wa9): CatalogueGapsPage + view + widget deep-links (GREEN)

NOT pushed / NOT deployed (local commits only).

## Operator notes

- **Deploy:** push main → VPS `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh` (no migration).
- Woo Maintenance now has BOTH: **Maintenance Overview** (counts) → click a gap card → **Catalogue Gaps** (the actual products for that gap) with one-click **Source images / Backfill EAN / Hydrate stock / Resync to Woo** (+ bulk). Brand/category gaps are surfaced for manual triage (no one-click).
- Fix actions run the existing commands per-SKU via `Artisan::call` (in-request/synchronous); the heavy ones (images / EAN) cost Claude/API — run when the box isn't under load (see [[meetingstore-prod-load-hazards]]).

## Self-Check: PASSED

- FOUND: app/Filament/Pages/CatalogueGapsPage.php
- FOUND: resources/views/filament/pages/catalogue-gaps.blade.php
- FOUND: app/Filament/Widgets/WooMaintenanceGapsWidget.php (edited)
- FOUND: tests/Feature/Products/CatalogueGapsPageTest.php
- FOUND commit: 9adc332 (test/RED)
- FOUND commit: 0d46bfa (feat/GREEN)
