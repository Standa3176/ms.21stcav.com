---
phase: 260701-pmr-add-products-backfill-woo-stock-one-time
plan: 01
subsystem: cutover / product-auto-create
tags: [woo, stock, backfill, cli, one-time]
requires:
  - "App\\Domain\\ProductAutoCreate\\Concerns\\BuildsWooStockPayload (260701-opg)"
  - "App\\Domain\\Sync\\Services\\WooClient::put"
  - "App\\Console\\Commands\\BaseCommand"
provides:
  - "products:backfill-woo-stock — push manage_stock+quantity+status to Woo for existing app-created products"
affects:
  - "WooCommerce storefront stock-line display for ~600 pre-260701-opg app-created products"
tech-stack:
  added: []
  patterns:
    - "WooClient anon-subclass stub (records puts + throws for a specific woo id) — mirrors BackfillCategoryFromWooCommandTest"
    - "invalid-id self-heal: null stale woo_product_id + continue (mirrors ReconcileStaleWooIdsCommand + the price-push guard)"
key-files:
  created:
    - "app/Console/Commands/BackfillWooStockCommand.php"
    - "tests/Feature/Console/BackfillWooStockCommandTest.php"
  modified:
    - "app/Providers/AppServiceProvider.php"
decisions:
  - "Reuse the 260701-opg BuildsWooStockPayload trait verbatim — one payload definition, two consumers (publish + backfill)."
  - "NOT scheduled: one-time (re-runnable) command; new products already get stock keys at publish, cutover:auto-sync keeps quantities fresh."
  - "invalid-id error self-heals (nulls the stale id + continues) rather than aborting — batch resilience over strictness."
metrics:
  duration: "~15 min"
  completed: "2026-07-01"
  tasks: 1
  files: 3
  tests: "4 new (23 assertions); full tests/Feature/Console/ = 115 passed"
---

# Phase 260701-pmr Plan 01: products:backfill-woo-stock (one-time Woo stock backfill) Summary

One-liner: `products:backfill-woo-stock` PUTs `manage_stock=true` + current `stock_quantity` + `stock_status` (via the shared `BuildsWooStockPayload` trait) to Woo for existing app-created published products so the ~600 published BEFORE 260701-opg finally show a stock line, self-healing stale Woo ids as it goes.

## Why the backfill is needed

260701-opg fixed stock DISPLAY at PUBLISH time: new auto-published products now carry `manage_stock=true` + `stock_quantity` + `stock_status` in the initial Woo write. But the ~600 products published BEFORE that fix still have `manage_stock=false` on Woo. With `manage_stock=false`, WooCommerce ignores the quantity entirely and the Flatsome theme renders no stock line — unlike legacy WC-migrated products which have stock management on.

The scheduled `cutover:auto-sync` (`--field=stock_quantity`) keeps quantities fresh but pushes `stock_quantity` ONLY — never `manage_stock`. So it can update a number WooCommerce is configured to ignore; those products never start displaying stock. This one-time backfill flips `manage_stock=true` (alongside the current quantity + status) so the display turns on; from then on `cutover:auto-sync` maintains the number.

## The command

- `app/Console/Commands/BackfillWooStockCommand.php` — extends `BaseCommand` (correlation-id threading), constructor-injects `WooClient`, `use App\Domain\ProductAutoCreate\Concerns\BuildsWooStockPayload` so `$this->wooStockPayload($product)` produces the identical `{manage_stock:true, stock_quantity, stock_status}` payload the publish path uses (one definition, two consumers).
- Signature: `products:backfill-woo-stock {--skus=} {--limit=0} {--dry-run}`.
- **Scope:** default = products where `auto_create_status='published'` AND `woo_product_id > 0`. `--skus=CSV` overrides the scope to those exact SKUs (ignoring the published default). `--limit=N` caps the run (0 = all). `--dry-run` prints what would be pushed and writes nothing.
- Per product it `PUT products/{woo_product_id}` with the stock payload.
- **invalid-id guard (self-heal, no batch abort):** on a WooCommerce `woocommerce_rest_product_invalid_id` error it NULLs the stale `woo_product_id` (`forceFill(['woo_product_id'=>null])->saveQuietly()`), logs `backfill_woo_stock.stale_id_cleared`, counts `skipped_stale`, and CONTINUES. Any other error logs `backfill_woo_stock.push_failed`, counts `errors`, and continues. The loop is never aborted — mirrors `ReconcileStaleWooIdsCommand` and the price-push guard.
- **Idempotent + no Claude spend:** pure Woo PUTs; re-running against already-correct products is harmless.
- Registered in `AppServiceProvider`'s command list next to `ReconcileStaleWooIdsCommand`. **NOT scheduled** — one-time by design.

## Verification (Herd PHP)

- `pest tests/Feature/Console/BackfillWooStockCommandTest.php` → 4 passed (23 assertions). Cases: live run PUTs 3 published products with correct payloads + excludes the draft (`pushed=3`); `--dry-run` records zero puts + changes nothing; invalid-id on one product nulls its id, others still push (`skipped_stale=1 pushed=2`), exit SUCCESS; `--skus` targets one product.
- `pest tests/Feature/Console/` → 115 passed (618 assertions), zero new failures.
- `artisan list | grep backfill-woo-stock` → command present.
- `pint --test` on the command + AppServiceProvider + test → `{"result":"pass"}`.

## Operator dry-run -> apply steps

Ongoing freshness is already handled — this is a one-shot display fix.

1. Deploy: push main -> on VPS `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh` (no migration).
2. Preview: `php artisan products:backfill-woo-stock --dry-run` (shows candidate count + sample; no writes).
3. Apply: `php artisan products:backfill-woo-stock` (~600 Woo PUTs; ~100 req/min headroom -> a few minutes). Optionally batch with `--limit=200`. Re-runnable / idempotent.
4. After it runs, the scheduled `cutover:auto-sync` (`--field=stock_quantity`) keeps quantities fresh; app products now display stock like legacy products.
5. Any stale Woo ids encountered are cleared (nulled) + logged `backfill_woo_stock.stale_id_cleared`, same as the price-push guard — no separate cleanup needed.

## Deviations from Plan

**1. [Rule 3 - Blocking] Pint import-ordering fix on AppServiceProvider.** Adding the `BackfillWooStockCommand` import triggered a `pint --test` `ordered_imports` failure. Ran `pint` on `AppServiceProvider.php` to reorder the import alphabetically. Verified with `pint --test` -> `{"result":"pass"}`. No behavioural change.

Otherwise the plan executed exactly as written.

## TDD Gate Compliance

- RED: `test(260701-pmr): add failing test for products:backfill-woo-stock` (4c24679) — 4 cases fail with "command does not exist".
- GREEN: `feat(260701-pmr): add products:backfill-woo-stock command` (979265c) — command + registration; all 4 cases pass.
- REFACTOR: none needed.

## Self-Check: PASSED

- `app/Console/Commands/BackfillWooStockCommand.php` — FOUND
- `tests/Feature/Console/BackfillWooStockCommandTest.php` — FOUND
- `app/Providers/AppServiceProvider.php` — modified (import + command list entry)
- Commit 4c24679 (RED) — FOUND
- Commit 979265c (GREEN) — FOUND
