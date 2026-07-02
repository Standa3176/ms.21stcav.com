---
phase: 260702-pes-publish-time-live-stock-hydration-cheape
plan: 01
subsystem: sync / product-auto-create
tags: [stock, supplier-db, publish, feeds_products, stockseparate, freshness]
requires:
  - JoinsStockSeparate trait (260609-rie)
  - SupplierFreshnessResolver (260608-g8x)
  - IntegrationCredentialResolver (SupplierDb kind)
provides:
  - LiveSupplierStockResolver (cheapest-fresh-in-stock, live over feeds_products+stockseparate)
  - publish-time live stock hydration in PublishProductJob
  - products:hydrate-live-stock backfill command (MS-side, no Woo writes)
affects:
  - PublishProductJob (every publish now hydrates stock first)
tech-stack:
  added: []
  patterns:
    - "reuse JoinsStockSeparate + SupplierFreshnessResolver — do not fork the stock/freshness rule"
    - "best-effort resolver — null on any failure, never blocks a publish"
    - "MS-side hydrate command + throwing-WooClient guard (mirrors hydrate-stock-from-offers)"
key-files:
  created:
    - app/Domain/Sync/Services/LiveSupplierStockResolver.php
    - app/Console/Commands/HydrateLiveStockCommand.php
    - tests/Unit/Sync/LiveSupplierStockResolverTest.php
    - tests/Feature/Console/HydrateLiveStockCommandTest.php
  modified:
    - app/Domain/ProductAutoCreate/Jobs/PublishProductJob.php
    - tests/Feature/ProductAutoCreate/PublishProductStockTest.php
    - app/Providers/AppServiceProvider.php
decisions:
  - "products:hydrate-live-stock is NOT scheduled — routes/console.php left untouched. Every publish now hydrates stock via PublishProductJob, so there is no window for new products; the command is an operator-triggered repair for today's already-published batch."
metrics:
  tasks: 3
  duration: ~40m
  completed: 2026-07-02
---

# Quick Task 260702-pes: Publish-time Live Stock Hydration (Cheapest Fresh In-Stock) Summary

New `LiveSupplierStockResolver` resolves the cheapest FRESH in-stock supplier offer live from `feeds_products`+`stockseparate`; `PublishProductJob` now hydrates local stock from it before building either Woo payload, and a new `products:hydrate-live-stock` command repairs today's already-published OOS batch — MS-side only, no Woo writes.

## Root Cause

Products created + published today went live **Out-of-stock even when the supplier had stock**. Proven on SKU 43376 (Lindy): `feeds_products` holds three feed rows — stock 0/64/0 at £71.36/76.92/79.20 — so 64 units were available, but:

1. **The create pipeline never persists stock** — `product.stock_quantity` stays `null`, so `wooStockPayload()` emits `qty=0 → outofstock`.
2. **`products:hydrate-stock-from-offers` can't help a new SKU** — it reads `supplier_offer_snapshots`, which the nightly sync only builds for SKUs that existed at sync time. A today-created SKU has zero snapshot rows (`last_synced_at=null`), so the hydrate step is a no-op for it.

Net effect: publish pushed `null → 0 → outofstock` and the SKU stayed OOS on the storefront until the next sync + push cycle.

## What Was Built

### Task 1 — `LiveSupplierStockResolver` (commit `a49ef3e`)
`app/Domain/Sync/Services/LiveSupplierStockResolver.php`. Reads `feeds_products` DIRECTLY (not snapshots) so a brand-new SKU gets correct stock at publish. Same rule as `SupplierDbSyncCommand::syncSupplierOfferSnapshots`:
- `feeds_products` + `stockseparate` via the **`JoinsStockSeparate` trait** (Ingram `is_stock_separate` stock is NOT masked — keeps the 260609-rie arch guard green).
- `product_excluded = 0`; match `LOWER(TRIM(mpn)) = key OR LOWER(TRIM(suppliersku)) = key`.
- gated to fresh `supplier_ids` via `SupplierFreshnessResolver` — a stale feed never asserts stock.
- cheapest by price among rows with resolved stock > 0.

`resolveForSku()` returns `{stock_quantity:int, stock_status:'instock', buy_price:?float}` or **null**. Best-effort — NEVER throws to the caller: blank sku, no fresh suppliers, unreachable supplier_db, or a prepare/exec error all return null so a publish is never blocked over stock. Non-final so Pest binds a Mockery double via the container. Pure `pickCheapestInStock` + `buildOfferSql` unit-tested.

### Task 2 — publish-time hydration in `PublishProductJob` (commit `d7bbab7`)
`handle()` method-injects `LiveSupplierStockResolver $stock` and calls `hydrateLiveStock($product, $stock)` immediately after `findOrFail`, **before** the wooId branch — so both Path A (PUT existing draft) and Path B (POST new create) read the hydrated qty. Best-effort: resolver `null` leaves existing stock untouched (a genuinely-OOS SKU stays out of stock); a thrown error is logged (`auto_create.publish.live_stock_failed`) and never fails the publish. `wooStockPayload`, `buildCreatePayload`, and the split-PUT price logic are untouched — hydration just fixes the value they read.

### Task 3 — `products:hydrate-live-stock` backfill (commit `5d7c9c1`)
`app/Console/Commands/HydrateLiveStockCommand.php`. Re-hydrates `stock_quantity`/`stock_status`/`buy_price` from the resolver for targeted published products. Default targets published products with a `woo_product_id` created **today**; `--skus` / `--created-since` / `--only-null-qty` / `--limit` / `--dry-run`. cursor() loop: `resolveForSku` → null counts under `no_offer`; else compare-then-write (skip identical) with `forceFill(...)->saveQuietly()`. Prints a sample table (sku, current_qty, new_qty, outcome) + counters (scanned, updated, unchanged, no_offer). **MS-side only — NO Woo writes**; operator pushes with the existing `products:backfill-woo-stock`. Registered in `AppServiceProvider`; **NOT scheduled** (`routes/console.php` untouched — every publish hydrates now, so no nightly pass is warranted).

## Tests

- `tests/Unit/Sync/LiveSupplierStockResolverTest.php` — **5 passed**. Pure surface: 43376 three-row case → qty **64 @ 76.92**; cheapest-in-stock wins; all-zero → null; empty → null; `buildOfferSql(3)` contains trait fragments + WHERE guards + exactly 5 placeholders.
- `tests/Feature/ProductAutoCreate/PublishProductStockTest.php` — **5 passed** (2 pre-existing + 3 new). Reuses the existing `bindWooStockStub`; new `bindLiveStockResolver` helper + a null-returning default bind in `beforeEach` keeps the 2 original stock tests on the unchanged path. New cases: Path A PUT qty override (null local → 64), Path B POST qty override, null resolver no-op (local qty 5 preserved).
- `tests/Feature/Console/HydrateLiveStockCommandTest.php` — **4 passed**. Today null-qty hydrate → 64/instock/76.92; `--dry-run` no-op; null resolver → unchanged + `no_offer`; `--only-null-qty` skips a product that already has qty. Suite-wide throwing-WooClient guard proves no Woo write.
- `tests/Architecture/StockSeparateJoinTest.php` — **3 passed** (arch guard green; resolver uses the trait).

Combined verification run: **17 passed (68 assertions)**. Pint `--test` PASS on all new/edited source + test files.

## Verification (Herd php)

```
pest tests/Unit/Sync/LiveSupplierStockResolverTest.php \
     tests/Feature/ProductAutoCreate/PublishProductStockTest.php \
     tests/Feature/Console/HydrateLiveStockCommandTest.php \
     tests/Architecture/StockSeparateJoinTest.php        → 17 passed (68 assertions)
pint --test (resolver, job, command, provider, 3 tests)  → pass
artisan list | grep hydrate-live-stock                   → present
grep resolveForSku PublishProductJob.php                 → present
git status routes/console.php                            → untouched
```

## Deviations from Plan

None of Rules 1–4 fired against production logic. One trivial test-fixture correction during Task 2: the new Path A hydration case initially seeded `stock_status => null`, which violates the `products.stock_status` NOT NULL constraint on the SQLite test DB — changed the seed to `'outofstock'` (the resolver overrides it to `'instock'` anyway). Pure test-data fix, no source change.

## Operator Repair — Today's Already-Published Batch (two steps)

Deploy: push `main` → on VPS `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh`. Then:

```
php artisan products:hydrate-live-stock --dry-run          # preview (defaults to created-today)
php artisan products:hydrate-live-stock                    # write local stock
php artisan products:backfill-woo-stock --dry-run          # preview the Woo push
php artisan products:backfill-woo-stock                    # push local stock → Woo
```

Target the wider recent batch with `--created-since=2026-06-25` or `--skus=43376,...`. Verify 43376: `php artisan products:hydrate-live-stock --skus=43376 --dry-run` should show current_qty=—/0 → new_qty=64; after the push the storefront shows In stock (64). From now on **every publish hydrates stock first**, so newly-created products go live with correct stock automatically — no window. A genuinely OOS-everywhere or stale-feed-only SKU correctly stays out of stock (resolver returns null → existing stock left as-is).

## NOT Fixed Here (flagged, separate)

`products:generate-drafts` still writes drafts with no stock, so a DRAFT in review shows qty null until publish hydrates it. Only the live storefront matters, so this is cosmetic; can be closed later by calling the resolver in generate-drafts too.

## Self-Check: PASSED
- app/Domain/Sync/Services/LiveSupplierStockResolver.php — FOUND
- app/Console/Commands/HydrateLiveStockCommand.php — FOUND
- tests/Unit/Sync/LiveSupplierStockResolverTest.php — FOUND
- tests/Feature/Console/HydrateLiveStockCommandTest.php — FOUND
- commits a49ef3e, d7bbab7, 5d7c9c1 — present in git log
