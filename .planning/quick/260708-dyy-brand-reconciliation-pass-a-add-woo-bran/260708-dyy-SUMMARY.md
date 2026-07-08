---
phase: 260708-dyy-brand-reconciliation-pass-a-add-woo-bran
plan: 01
subsystem: products / woo-maintenance
tags: [woo-maintenance, reconciliation, brand, wp-rest, tdd]
requires:
  - "woo_* reconciliation columns + products:reconcile-woo-maintenance (260708-b4f / 260708-bdw / 260708-cey)"
  - "WpRestClient (Basic Auth WordPress REST client)"
provides:
  - "woo_brand_count column recording each live product's real product_brand term count"
  - "read-only WP-REST brand pass in products:reconcile-woo-maintenance (GET wp/v2/product, _fields=id,product_brand)"
  - "the data foundation for Pass B (missing_brand gap on the Woo Maintenance dashboard)"
affects:
  - app/Console/Commands/ReconcileWooMaintenanceCommand.php
  - app/Domain/Products/Models/Product.php
tech-stack:
  added: []
  patterns:
    - "second read-only pass reuses the WC pass's paging shape (per_page / page / _fields / match-by-woo_product_id)"
    - "per-page try/catch (log + break) — one bad WP page never aborts the reconcile"
    - "(array) $p cast per row (defensive, mirrors the WC-pass stdClass fix)"
key-files:
  created:
    - database/migrations/2026_07_08_010000_add_woo_brand_count_to_products_table.php
  modified:
    - app/Console/Commands/ReconcileWooMaintenanceCommand.php
    - app/Domain/Products/Models/Product.php
    - app/Domain/Sync/Services/WpRestClient.php
    - tests/Feature/Products/ReconcileWooMaintenanceCommandTest.php
decisions:
  - "product_brand count captured via a SEPARATE WP REST pass — product_brand is NOT in the WC /products response but IS the taxonomy that drives the storefront Brand link"
  - "additive + READ-ONLY — the WC pass, its counters, ProductGapReport and the dashboard are UNCHANGED (dashboard wire is Pass B)"
  - "dropped `final` from WpRestClient so the feature suite can bind a subclass stub with throwing writes (read-only guard), mirroring the WooClient stub idiom — no behaviour change"
metrics:
  tasks: 1
  duration: ~25m
  completed: 2026-07-08
---

# Phase 260708-dyy Plan 01: Brand Reconciliation Pass A (add woo_brand_count) Summary

Added a read-only WP-REST brand pass to `products:reconcile-woo-maintenance` that pages the WordPress product list (`GET wp/v2/product`, status=publish, `_fields=id,product_brand`) and records each matched live product's real `product_brand` term count into a new nullable `woo_brand_count` column — capturing the one storefront gap (the clickable Brand link) that the WC `/products` response never exposes. Pass A ships only the data; Pass B wires it into the dashboard once the numbers are confirmed on prod.

## What Was Built

- **Migration `2026_07_08_010000_add_woo_brand_count_to_products_table.php`** — adds nullable `woo_brand_count` (`unsignedInteger`) after `woo_category_count`; `down()` drops it. Nullable → safe/fast on the ~6k-row table (no backfill/lock).
- **`Product` model** — `woo_brand_count` added to `$fillable` and cast `=> 'integer'`.
- **`ReconcileWooMaintenanceCommand`** — injects `WpRestClient $wp` alongside `WooClient`; after the existing WC pass (before the summary table) runs a second `while` loop paging `wp/v2/product` (status=publish, per_page=$perPage, page=$bPage, `_fields=id,product_brand`). Each row is `(array)`-cast, matched to a local `Product` by `woo_product_id`, and (unless `--dry-run`) `forceFill(['woo_brand_count' => count(product_brand)])->saveQuietly()`. Per-page `try/catch` logs a warning + breaks (partial reconcile retained). New `brand_updated` counter + summary row. The WC pass and all its counters are byte-identical.
- **READ-ONLY** — the brand pass only ever issues `WpRestClient::get()`. The test's WpRestClient stub's `post()/put()/delete()` throw, so any accidental WP write fails the suite.

## Verification

- `pest tests/Feature/Products/ReconcileWooMaintenanceCommandTest.php` → **4 passed (49 assertions)** — product 101 (`product_brand:[12843]`) → `woo_brand_count=1`; product 102 (`product_brand:[]`) → `woo_brand_count=0`; product 999 (returned by neither WC nor WP) untouched (null); `--dry-run` leaves `woo_brand_count` null on all; existing WC-field assertions still hold; WP GET issued with `_fields=id,product_brand` (writes would throw).
- `pint --test` on the command / model / WpRestClient / test / migration → `{"result":"pass"}`.
- `artisan list | grep reconcile-woo-maintenance` → command still registered.

## Deviations from Plan

**1. [Rule 3 — Blocking] Dropped `final` from `WpRestClient`**
- **Found during:** Task 1 (writing the plan-prescribed test double).
- **Issue:** The plan prescribes binding a `WpRestClient` stub via `app()->instance` with overridden `get()` and throwing `post()/put()/delete()` (read-only guard, mirroring the in-file `WooClient` stub). `WpRestClient` was declared `final`, so it could not be subclassed, and binding a non-`WpRestClient` object fails the command constructor's `WpRestClient` type-hint.
- **Fix:** Removed the `final` keyword (added a comment explaining the test-double rationale). No behaviour change; no architecture test enforces `final` on services.
- **Files modified:** `app/Domain/Sync/Services/WpRestClient.php`
- **Commit:** 7bf1164 (bundled into the RED test commit as a test enabler)

Otherwise the plan `<interfaces>` were used as written (variable names adapted to the existing command: `$dry` not `$dryRun`; `Log::` used directly since it was already imported).

## Operator Notes (post-deploy)

- **Deploy:** push `main` → VPS `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh` (**RUNS A MIGRATION** — 1 nullable column, safe/fast).
- **Re-run the reconcile** (now also does the read-only brand pass): `php artisan products:reconcile-woo-maintenance` — the summary should show `brand_updated ≈ matched`. Requires `WP_REST_USERNAME` + `WP_REST_APP_PASSWORD` in prod `.env` (Basic Auth for `/wp/v2/*`; the WC consumer key does NOT auth WP REST).
- **Check the REAL missing-brand count:**
  ```
  php artisan tinker --execute='$rec=App\Domain\Products\Models\Product::where("status","publish")->whereNotNull("woo_product_id")->whereNotNull("woo_reconciled_at"); echo "reconciled: ".(clone $rec)->count()." | missing brand: ".(clone $rec)->where("woo_brand_count",0)->count()." | brand not reconciled (null): ".(clone $rec)->whereNull("woo_brand_count")->count()."\n";'
  ```
- **Pass B (next):** once the missing-brand number looks right, add `missing_brand` (`woo_brand_count = 0`) to `ProductGapReport` + the Overview + Catalogue Gaps.

## Commits

- `7bf1164` — test(260708-dyy): add failing WP-REST brand pass expectations (+ drop `final` from WpRestClient)
- `6cbe259` — feat(260708-dyy): add read-only WP-REST brand pass to reconcile command

## Self-Check: PASSED

- FOUND: database/migrations/2026_07_08_010000_add_woo_brand_count_to_products_table.php
- FOUND: commit 7bf1164
- FOUND: commit 6cbe259
- Test: 4 passed (49 assertions); pint: pass; command registered.
