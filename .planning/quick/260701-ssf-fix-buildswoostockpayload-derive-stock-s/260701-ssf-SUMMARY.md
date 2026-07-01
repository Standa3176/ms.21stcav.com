---
phase: 260701-ssf-fix-buildswoostockpayload-derive-stock-s
plan: 01
subsystem: ProductAutoCreate / Woo stock sync
tags: [woo, stock, oversell, product-auto-create, tdd]
requires:
  - BuildsWooStockPayload trait (shipped 260701-opg)
provides:
  - qty-authoritative Woo stock_status derivation (no qty=0 + instock possible)
affects:
  - PublishProductJob (both publish paths — 260701-opg)
  - products:backfill-woo-stock (260701-pmr)
tech-stack:
  added: []
  patterns:
    - "Quantity is authoritative for stock_status under manage_stock=true; only 'onbackorder' is preserved"
key-files:
  created: []
  modified:
    - app/Domain/ProductAutoCreate/Concerns/BuildsWooStockPayload.php
    - tests/Unit/Domain/ProductAutoCreate/BuildsWooStockPayloadTest.php
decisions:
  - "stock_status is now DERIVED from quantity (qty>0 => instock, qty<=0 => outofstock); the sole preserved local value is 'onbackorder' (sellable at qty<=0 when backorders allowed). Any other local value — instock, outofstock, blank, garbage — is ignored in favour of the qty-derived status."
metrics:
  duration: ~15m
  completed: 2026-07-01
---

# Phase 260701-ssf Plan 01: qty-authoritative Woo stock_status Summary

Made quantity authoritative in `BuildsWooStockPayload::wooStockPayload` so a payload can never emit the contradictory `qty=0 + stock_status=instock` (oversell risk) caught in the `products:backfill-woo-stock` dry-run — while still preserving a genuine `onbackorder`.

## What changed

Previously the trait preserved ANY valid WC status (`instock`/`outofstock`/`onbackorder`) whenever the product's local `stock_status` held one, only deriving from quantity when the local value was blank or garbage. That meant a product with `stock_quantity=0` but a stale local `stock_status='instock'` (e.g. 7090043790993, PULSE-PRO-BOARD1) produced `qty=0 + instock`. Under `manage_stock=true` WooCommerce likely reconciles status from quantity, but sending `qty=0 + instock` risks a zero-stock product displaying in-stock (oversell) if WC honours the sent status.

New derivation (per the plan's TARGET):

```php
$qty = max(0, (int) ($product->stock_quantity ?? 0));
$status = ((string) ($product->stock_status ?? '')) === 'onbackorder'
    ? 'onbackorder'
    : ($qty > 0 ? 'instock' : 'outofstock');
```

- `qty>0` => `instock`; `qty<=0` => `outofstock`.
- The ONLY preserved local status is `onbackorder` (a genuine backorder stays sellable at qty<=0).
- `manage_stock` stays `true`; `stock_quantity` stays `max(0,(int) stock_quantity)` — both unchanged.

The method docblock was updated: status is no longer described as "reconciled by WC from quantity" but as derived from quantity in-app (onbackorder excepted).

## Why it corrects two flows

The same trait powers `PublishProductJob` (new-product publishes, both Path A PUT and Path B POST — 260701-opg) and `products:backfill-woo-stock` (260701-pmr). Both are corrected by this one change: the backfill dry-run rows that showed `qty=0 + instock` now read `outofstock`, and new publishes get the same qty-authoritative guarantee.

## Tests

Unit `BuildsWooStockPayloadTest` — updated to assert the qty-authoritative rule; added the bug-fix cases:

- qty=5, status=null -> instock
- qty=0, status=null -> outofstock
- qty=null -> 0 + outofstock
- qty=-3 -> 0 + outofstock
- qty=0, onbackorder -> onbackorder (preserved)
- qty=5, garbage -> instock (derived)
- **NEW** qty=0, instock -> outofstock (the bug: qty wins, no oversell)
- **NEW** qty=5, outofstock -> instock (qty wins both directions)
- **NEW** qty=5, onbackorder -> onbackorder (preserved even with stock)

TDD RED confirmed the two new qty-wins cases failed against the old logic (2 failed / 7 passed), then GREEN after the trait fix (**9 passed, 9 assertions**).

Consumer tests were checked: their seeded products all use consistent qty/status pairs (qty>0+instock, qty=0+outofstock, qty>0+onbackorder), so no contradictory local-status expectation existed to correct. Both still pass:

- `PublishProductStockTest` — **2 passed (11 assertions)**
- `BackfillWooStockCommandTest` — **4 passed (23 assertions)**

`pint --test` on the trait: `{"result":"pass"}`.

## Deviations from Plan

None — plan executed exactly as written. No local-status expectation in the consumer tests contradicted the qty-derived value, so no consumer-test corrections were needed (as the plan anticipated).

## Out-of-scope observation (not fixed)

Running `PublishProductStockTest.php` and `BackfillWooStockCommandTest.php` together in a single Pest invocation triggers a pre-existing fatal: both files declare a top-level `bindWooStockStub()` helper, so PHP throws "Cannot redeclare function bindWooStockStub()". This is unrelated to this task (both helpers predate it) and does not affect either file run on its own. Logged for a future cleanup; not touched here per scope boundary.

## Operator notes (NOT executed here)

- Deploy: push `main` → on VPS `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh` (no migration).
- Re-run the backfill dry-run — qty=0 rows now read `outofstock` (no more qty=0 + instock): `php artisan products:backfill-woo-stock --dry-run`, then apply without `--dry-run`.

## Self-Check: PASSED

- app/Domain/ProductAutoCreate/Concerns/BuildsWooStockPayload.php — FOUND, contains onbackorder-preserved qty-derivation.
- tests/Unit/Domain/ProductAutoCreate/BuildsWooStockPayloadTest.php — FOUND, contains the qty=0+instock -> outofstock case.
- Commit 864b41f — present in git log.
