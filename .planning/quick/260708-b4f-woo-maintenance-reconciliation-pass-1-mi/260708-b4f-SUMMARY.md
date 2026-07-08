---
phase: 260708-b4f-woo-maintenance-reconciliation-pass-1-mi
plan: 01
subsystem: products / woo-maintenance
tags: [woo, reconciliation, maintenance-dashboard, read-only, scheduled-command, tdd]
requires:
  - products table (woo_product_id) + Product model
  - App\Domain\Sync\Services\WooClient::get() (read path)
  - App\Console\Commands\BaseCommand (perform() / correlation-id seam)
provides:
  - products.woo_image_count / woo_gtin / woo_category_count / woo_stock_status / woo_reconciled_at columns
  - products:reconcile-woo-maintenance command (paged, read-only Woo mirror)
  - nightly 04:30 London schedule entry
affects:
  - Pass 2 will rewire ProductGapReport + the Woo Maintenance dashboard to read the woo_* columns
tech-stack:
  added: []
  patterns:
    - "read-only paged WooClient::get() crawl (mirrors BackfillCategoryFromWooCommand)"
    - "throwing-write WooClient stub as a permanent read-only regression guard"
    - "forceFill()->saveQuietly() to write reconciliation columns without firing model events"
key-files:
  created:
    - database/migrations/2026_07_08_000000_add_woo_maintenance_reconciliation_to_products_table.php
    - app/Console/Commands/ReconcileWooMaintenanceCommand.php
    - tests/Feature/Products/ReconcileWooMaintenanceCommandTest.php
  modified:
    - app/Domain/Products/Models/Product.php
    - routes/console.php
decisions:
  - "READ-ONLY on Woo — the command only issues GETs; the test stub's put()/post() throw so any future write regression fails the suite."
  - "All 5 columns nullable → migration is safe/fast on the ~6k-row products table (no backfill, no lock-heavy op)."
  - "Additive only — ProductGapReport + the dashboard are UNTOUCHED (that rewire is Pass 2)."
metrics:
  duration: ~15m
  completed: 2026-07-08
---

# Quick task 260708-b4f: Woo Maintenance Reconciliation (Pass 1) Summary

Foundation for shop-wide Woo maintenance: a nightly, read-only paged command that mirrors each live product's REAL Woo state (image count, GTIN/EAN, category count, stock status) into five new local `woo_*` columns — so Pass 2 can report TRUE gaps across ALL ~4,612 live products, including the 3,614 legacy WC-migrated ones whose media/EAN were never mirrored locally.

## What shipped

- **Migration** `2026_07_08_000000_add_woo_maintenance_reconciliation_to_products_table.php` — adds nullable `woo_image_count` (unsignedInteger), `woo_gtin` (string), `woo_category_count` (unsignedInteger), `woo_stock_status` (string), `woo_reconciled_at` (timestamp, indexed) after `gallery_image_urls`. `down()` drops all five. Nullable → safe/fast on the 6k-row table.
- **Product model** — the 5 columns added to `$fillable`; casts `woo_image_count`/`woo_category_count` => `integer`, `woo_reconciled_at` => `datetime`.
- **`app/Console/Commands/ReconcileWooMaintenanceCommand.php`** (`products:reconcile-woo-maintenance`, extends `BaseCommand`, injects `WooClient`) — pages `GET /products` (`status=publish`, `per_page=100`, `_fields=id,images,global_unique_id,categories,stock_status`), matches each row to a local `Product` by `woo_product_id`, and writes `woo_image_count=count(images)`, `woo_gtin=global_unique_id` (null if blank), `woo_category_count=count(categories)`, `woo_stock_status=stock_status`, `woo_reconciled_at=now()` via `forceFill()->saveQuietly()`. Options `--per-page` (clamped 1..100), `--max-pages` (0 = unbounded), `--dry-run`. Per-page `try/catch` logs a warning + breaks (partial reconcile retained) so one bad page doesn't abort. Prints a summary table (pages / scanned / matched / updated / unmatched / dry_run). **READ-ONLY on Woo — GETs only, never put/post.**
- **`routes/console.php`** — `Schedule::command('products:reconcile-woo-maintenance')->dailyAt('04:30')->withoutOverlapping();` (off-peak, after the 03:xx prunes + `history:prune` 04:00, before the ~05:00 supplier/SEO scans).

## Test (TDD)

`tests/Feature/Products/ReconcileWooMaintenanceCommandTest.php` (RefreshDatabase) — 4 cases:

1. **Mirrors Woo state** — 2 local products (woo id 101/102). Stub's `get('products', page 1)` returns `[{id:101, images:[{},{}], global_unique_id:'50123', categories:[{}], stock_status:'instock'}, {id:102, images:[], global_unique_id:'', categories:[], stock_status:'outofstock'}]`; page ≥ 2 returns `[]`. Asserts 101 → image_count=2 / gtin='50123' / category_count=1 / stock='instock' / reconciled_at not null; 102 → 0 / null / 0 / 'outofstock'. Also asserts the GET query args (`status=publish`, `_fields=...`).
2. **Woo-omitted product untouched** — product woo id 999 (not in the fixture) keeps all `woo_*` columns null.
3. **`--dry-run` writes nothing** — pages Woo (get called) but all `woo_*` columns stay null.
4. **`--per-page=500` clamped to 100** — asserts the outgoing query's `per_page` is 100.

**Read-only guard:** the stub's `put()` / `post()` throw `RuntimeException` — any accidental Woo write fails the suite (mirrors the HydrateProductStockFromOffersCommand invariant guard).

RED confirmed (`The command "products:reconcile-woo-maintenance" does not exist.` — 4 failed) → GREEN (**4 passed, 38 assertions**).

## Verification (Herd php84)

- `pest tests/Feature/Products/ReconcileWooMaintenanceCommandTest.php` → **4 passed (38 assertions)**
- `pint --test` on command + model + test → `{"result":"pass"}`
- `artisan list | grep reconcile-woo-maintenance` → command listed
- Confirmed product 101 reconciles to image_count=2 / gtin='50123' / category_count=1 (stock='instock', reconciled_at set); `--dry-run` writes nothing.

## Deviations from Plan

None — `<interfaces>` used as written (RED → GREEN, no refactor). The plan's suggested test asked for 2 assertions (mirror + dry-run); shipped 4 cases (added the Woo-omitted-untouched case and the `--per-page` clamp case for tighter coverage).

## Operator notes

- **Deploy:** push `main` → VPS `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh` (**RUNS A MIGRATION** — adds 5 nullable columns to `products`; safe/fast on the 6k-row table). NOT pushed / NOT deployed by this task (local commits only).
- **One-off seed** (off-peak, read-only ~47 Woo GET pages): `php artisan products:reconcile-woo-maintenance --dry-run` (preview matches) then `php artisan products:reconcile-woo-maintenance`. Nightly 04:30 keeps it fresh.
- **Pass 2 (next):** rewire `ProductGapReport` + the Woo Maintenance dashboard to read `woo_image_count` / `woo_gtin` / `woo_category_count` / `woo_stock_status`, with a "not yet reconciled" indicator — turning the counts into TRUE shop-wide gaps over all ~4,612 live products (legacy ones included).

## Commits

- `0d86fc6` — test(260708-b4f): add failing test (RED gate)
- `eeec432` — feat(260708-b4f): migration + model casts + command + schedule (GREEN gate)

## Self-Check: PASSED

- FOUND: database/migrations/2026_07_08_000000_add_woo_maintenance_reconciliation_to_products_table.php
- FOUND: app/Console/Commands/ReconcileWooMaintenanceCommand.php
- FOUND: tests/Feature/Products/ReconcileWooMaintenanceCommandTest.php
- FOUND commit 0d86fc6 (test / RED)
- FOUND commit eeec432 (feat / GREEN)
