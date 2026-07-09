---
phase: 260709-vvj-two-small-fixes-retagproductsonwoo-dry-r
plan: 01
subsystem: [sync, product-auto-create]
tags: [woocommerce, brands, retag, pins, dry-run, filament]
requires: []
provides:
  - "RetagProductsOnWoo dry-run drains via processed-id dedup (no phantom rows)"
  - "pin_price is a working pin-tab toggle (PIN_COLUMNS + UI + persistence)"
affects:
  - app/Console/Commands/RetagProductsOnWooCommand.php
  - app/Domain/ProductAutoCreate/Services/FieldPinManager.php
  - app/Domain/Products/Filament/Resources/ProductResource.php
tech-stack:
  added: []
  patterns:
    - "Processed-id dedup for always-page-1 drain loops (terminate when a page introduces no new ids)"
key-files:
  created:
    - .planning/quick/260709-vvj-two-small-fixes-retagproductsonwoo-dry-r/260709-vvj-SUMMARY.md
  modified:
    - app/Console/Commands/RetagProductsOnWooCommand.php
    - app/Domain/ProductAutoCreate/Services/FieldPinManager.php
    - app/Domain/Products/Filament/Resources/ProductResource.php
    - tests/Feature/Console/RetagProductsOnWooCommandTest.php
    - tests/Feature/ProductAutoCreate/ProductResourcePinTabTest.php
decisions:
  - "Break the retag drain loop the moment a page=1 read introduces no new Woo product ids — covers both dry-run (no PUTs) and live (a PUT that failed to move a product off the source brand)."
metrics:
  tasks_completed: 1
  files_modified: 5
  completed: 2026-07-09
---

# Phase 260709-vvj Plan 01: Two small owed fixes (RetagProductsOnWoo dry-run + pin_price toggle) Summary

Stopped RetagProductsOnWoo's dry-run from emitting phantom repeated `would_retag` rows via
processed-id dedup, and exposed the `pin_price` pin-tab toggle so the gy0 `pin_price` column is
settable end-to-end.

## What Changed

### FIX #6 — RetagProductsOnWooCommand dry-run infinite-loop (`would_retag` phantom rows)

- The per-source `while (true) { get('products', page=>1, brand=>N, status=>any) }` drain loop
  previously iterated over the raw `$response` every pass. In LIVE mode the `?brand=N` filter set
  shrinks after each PUT (products move off the source brand) so it drained naturally; in DRY-RUN
  there are no PUTs, so the filter set never shrank and the loop spun to the 50-iteration
  `$pageGuard` backstop, re-emitting the same products up to 50× (brand-cleanup-followups #6).
- Added a `$processedIds` map (declared before the `while (true)`). Each iteration now computes
  `$newRows = array_filter($response, ...id not in $processedIds)` and `break`s immediately when
  `$newRows === []`. The retag/plan loop iterates `$newRows` (not `$response`) and marks each
  handled id in `$processedIds`.
- Everything else preserved: `status=any`, `page=1`, 404/error handling, auditor calls, dry-run
  vs live branch, `--limit`, throttle, and the `$pageGuard` safety backstop.
- Result: a dry-run over N products emits exactly N plan rows and drains cleanly in BOTH modes.

### FIX #4 — pin_price pin-tab toggle

- `FieldPinManager::PIN_COLUMNS` was a hardcoded 8-item list missing `pin_price`. Added
  `'pin_price'` (now 9) and updated the three "8" docblock references to "9".
- Added `Toggle::make('override_pins.pin_price')->label('Pin price')` to ProductResource's pin
  section, next to Pin brand / Pin category.
- No model change: `ProductOverride` already has `pin_price` fillable + bool cast + pin-list (gy0),
  and both `FieldPinManager::loadPinsFor` and `savePins` iterate `PIN_COLUMNS`, so `pin_price` now
  round-trips (persist + hydrate) automatically. The `ProductOverrideGuard` regular_price→pin_price
  mapping was already wired — this closes the UI gap.

## Tests

- `tests/Feature/Console/RetagProductsOnWooCommandTest.php` — added **Case M**: a DRY-RUN over a
  non-draining source whose Woo stub returns the SAME 3 products on every `page=1` read produces
  exactly 3 `would_retag` plan rows (not 3×50=150), fires no `brands.retag_safety_break`, and makes
  only 2 `brand=41` GETs. Existing Cases A–L (incl. live-mode drain/pagination) stay green.
- `tests/Feature/ProductAutoCreate/ProductResourcePinTabTest.php` — added a case: saving with
  `pin_price=true` persists `ProductOverride.pin_price=true` and `FieldPinManager::loadPinsFor`
  hydrates it back. Existing pin-tab cases stay green.

**Tests (both files):** 19 passed (83 assertions).
**Pint:** `{"result":"pass"}` on all 5 changed files.

## Deviations from Plan

None — plan executed as written. (The two test files live at
`tests/Feature/Console/RetagProductsOnWooCommandTest.php` and
`tests/Feature/ProductAutoCreate/ProductResourcePinTabTest.php`; the PLAN's `<verify>` paths were
approximate but the intended files were unambiguous.)

## Operator Notes

- Deploy: push main → `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh` (NO migration).
- `brands:retag-products-on-woo --dry-run` now shows an accurate plan (was repeating each product
  up to 50×).
- The Product pin tab now has a **Pin price** toggle — setting it makes supplier sync leave that
  product's price alone (ProductOverrideGuard regular_price→pin_price mapping was already wired;
  only the UI toggle + the FieldPinManager column list were missing).

## Self-Check: PASSED
