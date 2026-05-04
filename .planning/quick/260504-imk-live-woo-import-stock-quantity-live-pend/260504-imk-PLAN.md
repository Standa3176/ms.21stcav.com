---
quick_id: 260504-imk
description: Live Woo import — stock_quantity + Live/Pending status labels
date: 2026-05-04
must_haves:
  truths:
    - WooClient credentials confirmed working (user just verified Test Connection)
    - WooImportProductsCommand exists from 260504-d7v; iterates Woo catalogue and Product::updateOrCreate's
    - WooProductIterator pulls stock_quantity + manage_stock per page; my command currently doesn't capture them
    - products table has stock_status (string) but NOT stock_quantity (int) — needs migration
  artifacts:
    - database/migrations/2026_05_04_132446_add_stock_quantity_to_products_table.php (NEW)
    - app/Domain/Products/Models/Product.php (fillable + casts)
    - app/Domain/Sync/Commands/WooImportProductsCommand.php (capture stock_quantity)
    - app/Domain/Products/Filament/Resources/ProductResource.php (Live/Pending status formatter + stock_quantity col + short_description col)
    - tests/Feature/Sync/WooImportProductsCommandTest.php (new case)
---

# Quick Task 260504-imk

## Goal

Pull live + pending Woo SKUs into local products table with stock qty captured + display Live/Pending status badges in /admin/products.

## Tasks

1. Migration: add `stock_quantity` int nullable column after `stock_status`
2. Product model: extend fillable + casts
3. WooImportProductsCommand: capture stock_quantity when manage_stock=true
4. ProductResource:
   - Status column formatStateUsing publish→Live, draft→Pending, private→Hidden
   - Status column color publish→success, draft→warning, private→gray
   - Add stock_quantity column (sortable, numeric)
   - Add short_description column (toggleable hidden by default)
   - Update SelectFilter('status') options to Live/Pending/Hidden labels
5. Test: stock_quantity populated when manage_stock=true, null otherwise
6. Live: `php artisan woo:import-products --with-supplier`
7. Verify via tinker probe + curl /admin/products (no 500)

## Out of scope

- Variation import (still skipped)
- Wiring into scheduler
