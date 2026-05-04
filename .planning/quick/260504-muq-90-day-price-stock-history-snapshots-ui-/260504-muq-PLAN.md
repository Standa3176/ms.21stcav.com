---
quick_id: 260504-muq
description: 90-day price/stock history (snapshots + UI + prune)
date: 2026-05-04
must_haves:
  truths:
    - feeds_products has supplierid column joinable to suppliers.id → name (Nuvias, Ingram, Tech Data, etc.)
    - 5,633 local Woo products × ~3,939 matched on supplier feed → ~12k supplier offers per day
    - 90 days × 5,633 = 507k product snapshots, 90 days × ~12k = 1.1M supplier offers (SQLite handles fine)
    - SupplierDbSyncCommand uses mysqli (one-shot connection per run); snapshot-write hook needs the same DB connection
  artifacts:
    - database/migrations/<ts>_create_history_snapshot_tables.php (NEW)
    - app/Domain/Products/Models/ProductPriceSnapshot.php (NEW)
    - app/Domain/Products/Models/SupplierOfferSnapshot.php (NEW)
    - app/Domain/Sync/Commands/WooImportProductsCommand.php (write-hook)
    - app/Domain/Sync/Commands/SupplierDbSyncCommand.php (write-hook + offers query)
    - app/Domain/Products/Console/Commands/SnapshotsPruneCommand.php (NEW)
    - app/Domain/Products/Filament/Pages/PriceHistoryPage.php (NEW)
    - resources/views/filament/pages/price-history.blade.php (NEW)
    - app/Providers/AppServiceProvider.php (register prune command)
    - routes/console.php (snapshots:prune daily 04:00)
    - config/history.php (NEW retention_days)
    - tests/Feature/Products/ProductPriceSnapshotTest.php (NEW)
    - tests/Feature/Products/SupplierOfferSnapshotTest.php (NEW)
    - tests/Feature/Products/SnapshotsPruneCommandTest.php (NEW)
---

# Plan 260504-muq

Full Phase A+B+C bundled — see parent prompt for detailed task breakdown. Summary:

1. Migration: 2 snapshot tables with appropriate indexes
2. 2 Eloquent models with belongsTo Product
3. Write-hook in WooImportProductsCommand (writes ProductPriceSnapshot per product)
4. Write-hook in SupplierDbSyncCommand (writes ProductPriceSnapshot AND multiple SupplierOfferSnapshot rows per match)
5. SnapshotsPruneCommand (90-day default retention)
6. config/history.php
7. Schedule entry: snapshots:prune daily 04:00 London
8. Filament Page: Catalogue → Price History (sort 70). SKU search → stat row + line chart + per-day table + cheapest-supplier table + today-all-suppliers table
9. Tests for snapshots + prune
10. Live verify: migrate, run woo:import-products + supplier:db-sync, probe counts
