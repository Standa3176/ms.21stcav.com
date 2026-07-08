---
phase: 260708-akz-urgent-prod-hang-fix-productgapreport-co
plan: 01
subsystem: Woo Maintenance / catalogue gap reporting
tags: [prod-hang, performance, filament-widget, aggregate-query, cache]
requires:
  - "ProductGapReport (260707-w2w) + CatalogueGapsPage (260707-wa9)"
provides:
  - "single-aggregate ProductGapReport::counts() (no per-row JSON scan)"
  - "no-JSON empty-gallery predicate (EMPTY_GALLERY_SQL) shared by counts() + apply()"
  - "WooMaintenanceGapsWidget auto-poll disabled"
affects:
  - "Woo Maintenance Overview, the auto-discovered Dashboard gaps widget, Catalogue Gaps filter"
tech-stack:
  added: []
  patterns:
    - "conditional-SUM single-aggregate replaces N-count scans"
    - "Laravel array-cast empty = literal '[]' → plain string compare, no JSON function"
key-files:
  created: []
  modified:
    - app/Domain/Products/Services/ProductGapReport.php
    - app/Filament/Widgets/WooMaintenanceGapsWidget.php
    - tests/Feature/Products/ProductGapReportTest.php
decisions:
  - "Kept the widget poll OFF even though counts() is now cheap — gap totals move on the daily sync cadence, not per-30s, so there's no reason to re-run on a timer."
  - "'[]'/''/NULL string compare is the exact equivalent of the old JSON_LENGTH=0 (Laravel stores an empty array cast as the literal '[]'), so counts are byte-identical — no data-verdict change, only query shape."
metrics:
  duration: "~7 min"
  completed: 2026-07-08
  tasks: 1
  files: 3
  commits: 1
---

# Phase 260708-akz Plan 01: URGENT prod-hang fix — ProductGapReport::counts() single aggregate Summary

**One-liner:** Rewrote `ProductGapReport::counts()` from 5-6 per-row-JSON full-table scans into ONE conditional-SUM aggregate with a no-JSON empty-gallery predicate, raised the cache TTL to 300s, and disabled the auto-discovered dashboard widget's 30s poll — resolving the admin hang while keeping counts byte-identical.

## Root Cause (the hang)

The Filament admin was hanging with 30s `max_execution_time` fatals. `ProductGapReport::counts()` ran:

- `liveBase()->count()` for the total, then
- **five more** `liveBase()->apply($gap)->count()` scans — one per gap,

each a full-table scan over the whole live-on-Woo catalogue, and the images gap used a **per-row JSON function** (`JSON_LENGTH(gallery_image_urls)=0`) that the DB had to evaluate for every row of every scan.

On the production catalogue that took **longer than PHP's 30s limit**. Worse: the work happened **inside `Cache::remember(...)`**, so when it timed out the closure never returned and **nothing was ever cached** — meaning every single render re-ran the full six-scan cost. And because `WooMaintenanceGapsWidget` (a `StatsOverviewWidget`) is **auto-discovered onto the Dashboard** and **auto-polled every 30s by default**, the Dashboard re-triggered the expensive uncached query on a timer → the admin hung.

## The Fix (commit `5e39041`)

`app/Domain/Products/Services/ProductGapReport.php`:
- Added `private const EMPTY_GALLERY_SQL = "(gallery_image_urls IS NULL OR gallery_image_urls = '[]' OR gallery_image_urls = '')"` — a plain, index-friendly string compare with **no JSON function**. Laravel's array cast stores an empty gallery as the literal `'[]'`, so this verdict is **identical** to the old `JSON_LENGTH=0` (empty array). This also removed the SQLite/MariaDB driver branch, so `use ...\DB` is gone and the `emptyImagesExpr()` helper was deleted.
- `apply('missing_images')` now `->whereRaw(self::EMPTY_GALLERY_SQL)` — so the Catalogue Gaps drill-down filter is cheap too. `missing_ean` / `missing_stock_status` (`TRIM(x)=''`) and `missing_brand` / `missing_category` (`whereNull`) are **unchanged**.
- `counts()` is now a **single aggregate**: `liveBase()->selectRaw('COUNT(*) as total, SUM(CASE WHEN … THEN 1 ELSE 0 END) as missing_images, … missing_ean, … missing_stock_status, … missing_brand, … missing_category')->first()` — **one scan instead of six**, completes well under 30s, and therefore actually caches.
- Cache TTL raised **60s → 300s**.

`app/Filament/Widgets/WooMaintenanceGapsWidget.php`:
- `protected static ?string $pollingInterval = null;` — auto-poll disabled so the Dashboard/Overview stop re-running `counts()` every 30s. (`getStats()` still reads `counts()` — now cheap and cached.)

All operators used (`TRIM` / `CASE` / `SUM` / string compare) are **driver-portable** across SQLite (tests) and MariaDB (prod).

## Counts are unchanged

The only data-verdict change is the images predicate, and it's an exact equivalent (`'[]'`/`''`/`NULL` string compare == old `JSON_LENGTH=0`). Everything else is a pure query-shape change. The existing test matrix (total==6, each gap==1, `apply(missing_images)`→P1 only) still passes, and a new guard case proves a live product with a **non-empty** gallery (`['http://x/img.jpg']`) is NOT counted as `missing_images` — via both `apply()` and the aggregate `counts()`.

## Verification

- `~/.config/herd/bin/php84/php.exe vendor/bin/pest tests/Feature/Products/ProductGapReportTest.php tests/Feature/Products/CatalogueGapsPageTest.php` → **11 passed (42 assertions)** (5 in ProductGapReportTest incl. the new non-empty-gallery guard + 6 in CatalogueGapsPageTest).
- `~/.config/herd/bin/php84/php.exe vendor/bin/pint --test app/Domain/Products/Services/ProductGapReport.php app/Filament/Widgets/WooMaintenanceGapsWidget.php` → `{"result":"pass"}`.
- Confirmed `counts()` is a single `selectRaw` aggregate over `liveBase()` with `SUM(CASE WHEN …)` and no per-row JSON function, returning the same totals.

## Deviations from Plan

None — the `<interfaces>` code was used as written. Note: the widget's `$pollingInterval = null` was already present in the working tree from an earlier uncommitted edit; it is committed here as part of this task. Docblocks on all three files were refreshed to reflect the 300s cache and the akz rationale (docs-only).

## Operator Notes — DEPLOY IMMEDIATELY

- Push `main` → on the VPS run `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh` (no migration).
- This resolves the admin hang: the Woo Maintenance gap counts are now one cheap aggregate query (no per-row JSON scan), so the Maintenance Overview + the auto-discovered Dashboard widget + Catalogue Gaps all load fast; counts cache 300s; the widget no longer polls.
- Confirm on prod after deploy (should be sub-second):
  ```
  php artisan tinker --execute='Cache::forget("woo_maintenance.gap_counts"); $t=microtime(true); $r=app(App\Domain\Products\Services\ProductGapReport::class)->counts(); echo json_encode($r)." in ".round((microtime(true)-$t)*1000)."ms\n";'
  ```
- If pages OTHER than the Dashboard / Woo Maintenance were also hanging, that's separate — this fix covers the gap-count path only.

## Self-Check: PASSED
