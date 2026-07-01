---
phase: 260701-opg-app-created-products-missing-woo-stock-s
plan: 01
subsystem: sync
tags: [woocommerce, stock, publish, auto-create, trait]

requires:
  - phase: 260606-q3o (auto-create pipeline)
    provides: PublishProductJob two-path publish (Path A PUT / Path B create POST)
provides:
  - App-created products now publish to Woo with manage_stock + stock_quantity + stock_status
  - Shared BuildsWooStockPayload trait as the single source for the stock payload
affects: [PublishProductJob, auto-create publish, storefront stock display]

tech-stack:
  added: []
  patterns:
    - "Pure stock-derivation trait (BuildsWooStockPayload) merged into both publish paths"

key-files:
  created:
    - app/Domain/ProductAutoCreate/Concerns/BuildsWooStockPayload.php
    - tests/Unit/Domain/ProductAutoCreate/BuildsWooStockPayloadTest.php
    - tests/Feature/ProductAutoCreate/PublishProductStockTest.php
  modified:
    - app/Domain/ProductAutoCreate/Jobs/PublishProductJob.php
    - tests/Feature/ProductAutoCreate/PublishProductJobTest.php

key-decisions:
  - "Stock keys ride in the Path B initial POST (not the split-PUT): stock is unaffected by the Cost-of-Goods plugin that clobbers regular_price, so it does not need the price isolation."
  - "stock_status kept when it is a valid WC value (instock/outofstock/onbackorder), else derived from quantity (instock if qty>0 else outofstock)."
  - "qty = max(0, (int) stock_quantity) — defensive against negative/null."

patterns-established:
  - "BuildsWooStockPayload trait is the single source for the Woo stock payload — extend HERE if a fourth stock key is needed, do not re-implement at call sites."

requirements-completed: []

duration: 20min
completed: 2026-07-01
---

# Quick Task 260701-opg: App-created products carry Woo stock keys Summary

**Both PublishProductJob publish paths now merge manage_stock + stock_quantity + stock_status (via a shared BuildsWooStockPayload trait) so app-created products display a storefront stock line ("In stock (N)" / "Out of stock") like legacy products — price/EAN/description/split-write logic untouched.**

## Performance

- **Duration:** ~20 min
- **Started:** 2026-07-01T16:50:25Z
- **Completed:** 2026-07-01T17:10Z (approx)
- **Tasks:** 2 completed
- **Files modified:** 5 (2 created source/test + 1 modified source + 2 test)

## The Gap

App-created products didn't show a stock line on the storefront; legacy products show "In stock (N)" or "Out of stock". Neither Woo write in the auto-create publish flow set stock management:

- **Path A** (product already on Woo as a draft): `PUT products/{id} {status:publish}` — no stock keys.
- **Path B** (auto-drafted, no `woo_product_id`): `buildCreatePayload()` → `POST products` — no stock keys.

With no `manage_stock`, WooCommerce left stock unmanaged and the Flatsome theme rendered no stock line.

## The Fix

### Task 1 — `BuildsWooStockPayload` trait (TDD)

New `app/Domain/ProductAutoCreate/Concerns/BuildsWooStockPayload.php` with a pure `protected wooStockPayload(Product $product): array{manage_stock:bool, stock_quantity:int, stock_status:string}`:

- `stock_quantity = max(0, (int) ($product->stock_quantity ?? 0))`
- `stock_status` = the product's value when it is a valid WC value (`instock` / `outofstock` / `onbackorder`), else derived (`instock` if qty>0 else `outofstock`).
- `manage_stock` always `true`.

Unit-tested via an anonymous class that uses the trait and re-exposes the method publicly, across all 6 cases (qty 5/null → instock; qty 0 → outofstock; null qty → 0+outofstock; -3 → 0+outofstock; qty 0 + onbackorder preserved; qty 5 + garbage → derived instock). RED→GREEN TDD.

### Task 2 — wire into `PublishProductJob` (both paths)

- Added the trait `use` on the class.
- **Path A** PUT: `array_merge(['status' => 'publish'], $this->wooStockPayload($product))`.
- **Path B** `buildCreatePayload()`: `$payload = array_merge($payload, $this->wooStockPayload($product));` before `return` — stock stays in the initial POST because it is unaffected by the Cost-of-Goods plugin (unlike `regular_price`, which handle() still defers to a follow-up PUT).
- Feature test (`PublishProductStockTest`) asserts both paths via a `WooClient` anon-subclass stub recording `[method, path, body]`, with `config()->set('services.woo.write_enabled', true)`:
  - Path A: `woo_product_id=999`, `stock_quantity=7` → PUT `products/999` body has `status=publish` + `manage_stock=true` + `stock_quantity=7` + `stock_status=instock`.
  - Path B: `woo_product_id=null`, `stock_quantity=0` → POST `products` body has `manage_stock=true` + `stock_quantity=0` + `stock_status=outofstock`.

Price / EAN (`global_unique_id`) / description / split-write and the EAN-collision retry are unchanged.

## Verification

- `pest tests/Unit/Domain/ProductAutoCreate/BuildsWooStockPayloadTest.php` → **6 passed (6 assertions)**.
- `pest tests/Feature/ProductAutoCreate/PublishProductStockTest.php` → **2 passed (11 assertions)**.
- `pest tests/Feature/ProductAutoCreate/PublishProductJobTest.php` → **14 passed (49 assertions)** (existing Path A assertion updated for the new stock keys; all Path B assertions unchanged and still green — proves price/EAN logic intact).
- Combined focused run (unit + both feature files) → **22 passed (66 assertions)**.
- `pint --test` on the trait + `PublishProductJob.php` + both test files → **pass** (new feature test auto-formatted by pint; no logic change).
- `grep -nE "wooStockPayload|BuildsWooStockPayload" PublishProductJob.php` → trait `use` (L8, L54) + merged into Path A PUT (L88) + buildCreatePayload (L353).

## Deviations from Plan

### Auto-fixed / necessary adjustments

**1. [Rule 1 — Test correctness] Updated existing Path A assertion in `PublishProductJobTest.php`.**
- **Found during:** Task 2.
- **Issue:** The existing "path A: PUTs status=publish" test asserted the PUT body was *exactly* `['status' => 'publish']`. Task 2 (per the plan) changes Path A to also send the stock keys, which would break that exact-match assertion — a guaranteed regression.
- **Fix:** Seeded explicit `stock_quantity=12`/`stock_status=instock` on the test product and updated the expected PUT body to include `manage_stock=true`, `stock_quantity=12`, `stock_status=instock` alongside `status=publish`. Behavioural intent (Path A flips the draft live) preserved.
- **Files modified:** `tests/Feature/ProductAutoCreate/PublishProductJobTest.php`.
- **Commit:** b7d12ed.

## Pre-existing Failures (out of scope, NOT caused by this change)

Running the full `tests/Feature/ProductAutoCreate/` suite shows **54 failing / 121 passing**. These failures are pre-existing test-infra rot documented in STATE.md ("~165 pre-existing failures… test-infra rot, not prod bugs") — causes seen include `NoPricingRuleMatchedException` (pricing-rule seeding), `QueryException` NOT NULL on `suggestions.payload` (factory seeding), and `WooClient::__construct` Mockery arg-type mismatches (test-rig rot).

**Proven pre-existing:** with this task's source/test edits reverted to pristine HEAD, `CreateWooProductJobTest` + `TaxonomyResolverTest` still failed identically (10 failed / 8 passed) with no stock code present. The three files this task owns (`BuildsWooStockPayloadTest`, `PublishProductStockTest`, `PublishProductJobTest`) all pass. Adding three keys to a Woo payload cannot cause a pricing-rule or suggestions-DB failure. Fixing the broader suite is its own documented milestone.

## Scope Note & Follow-up (from plan `<objective>`)

- This makes stock **display** correctly at publish time. Existing app-created products already live on Woo won't retroactively gain stock management — **backfill** by re-publishing them (`products:draft-from-suggestions --skus=… --auto-approve`) or a future one-off stock-push.
- **FOLLOW-UP to verify (not built here):** is stock kept fresh on Woo *after* publish (ongoing stock→Woo sync)? If there is no ongoing push, the displayed stock will freeze at publish-time quantity. Worth a separate check — the event-driven push (260611-s2d) does push `stock_quantity` on MS-side saves when `CUTOVER_EVENT_DRIVEN_PUSH_ENABLED=true`, but it pushes quantity, not `manage_stock`; confirm WC keeps `manage_stock` on once set.

## Operator Notes (NOT executed here)

- Deploy: push main → on VPS `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh` (no migration).
- After deploy, newly auto-published products carry `manage_stock` + quantity + status → storefront shows the stock line like legacy products.

## Commits

- cc9bfea — `test(260701-opg)`: failing unit test for BuildsWooStockPayload (RED)
- 866a306 — `feat(260701-opg)`: BuildsWooStockPayload trait (GREEN)
- b7d12ed — `feat(260701-opg)`: wire stock into PublishProductJob both paths + feature test

## Self-Check: PASSED
