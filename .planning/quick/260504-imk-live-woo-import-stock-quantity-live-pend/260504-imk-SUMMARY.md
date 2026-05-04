---
quick_id: 260504-imk
description: Live Woo import — stock_quantity + Live/Pending status labels
date: 2026-05-04
commit: 60dee1f
status: completed
---

# Quick Task 260504-imk — Summary

## Real-world result

**5,633 products imported from live Woo store** in 57 pages (~3-5min). 0 errors.

| Metric | Count |
|---|---|
| Total products | 5,633 |
| Live (publish) | 3,518 |
| Pending (draft + pending) | 2,115 |
| With stock_quantity tracking | 5,447 |
| With sell_price | 5,431 (96%) |
| With buy_price | 1 (waiting on supplier creds) |

**Match ratio breakthrough:** 4,453 of 17,940 distinct competitor SKUs (**24.8%**) flipped from Orphan to Matched the moment products landed. `/admin → Catalogue → Competitor Prices → filter Matched (in catalogue)` now shows real direct-competitor pricing for products MS actually sells.

## Files changed (5, +100 / -6)

### 1. Migration: `2026_05_04_132446_add_stock_quantity_to_products_table.php` (NEW)

Adds `products.stock_quantity` nullable int after `stock_status`. Down migration drops the column. Comment explains the manage_stock=false null semantic.

### 2. `app/Domain/Products/Models/Product.php` (+2)

- `$fillable` extended with `'stock_quantity'`
- `$casts` extended with `'stock_quantity' => 'integer'`

### 3. `app/Domain/Sync/Commands/WooImportProductsCommand.php` (+13)

- Captures `stock_quantity` only when `manage_stock=true` (otherwise null — Woo's count is meaningless when stock isn't tracked)
- Defensive `(array) $p` cast inside the foreach because WooClient::normaliseResponseBody only json-round-trips the outer wrapper, leaving each product as stdClass

### 4. `app/Domain/Products/Filament/Resources/ProductResource.php` (+27 / -5)

- Status column `formatStateUsing` + `color` now translates Woo internals to ops-friendly labels:
  - `publish` → "Live" (success / green)
  - `draft` → "Pending" (warning / amber)
  - `private` → "Hidden" (gray)
  - other → `Str::headline($state)` fallback (covers Woo's native "pending" status)
- New `stock_quantity` column (sortable numeric) before `stock_status`
- New `short_description` column (toggleable, hidden by default, tooltip on hover)
- `SelectFilter('status')` options relabelled to match the badges

### 5. `tests/Feature/Sync/WooImportProductsCommandTest.php` (+18)

New case: "captures stock_quantity when manage_stock=true and null when manage_stock=false". Explicitly asserts that 0-tracked persists as 0 (not null) — distinguishes "0 in stock" from "stock isn't tracked."

## Tests + verification

- 6/6 WooImportProductsCommand tests passing (was 5; +1 stock_quantity case)
- Deptrac: 0 violations
- Lint clean on all 5 files
- 7 admin URLs probed via curl → all 302 (login redirect, no 500s)
- Live import end-to-end: 5,633 rows persisted, 0 errors, status mix verified

## Bug found + fixed during execution

**WooClient response shape mismatch:** initial run threw "Cannot use object of type stdClass as array" at the foreach over Woo products. Root cause: `WooClient::normaliseResponseBody` only json-converts the outer wrapper, leaving inner items as stdClass. Fix: `(array) $p` cast inside the loop. This also future-proofs against SDK version drift where the response shape varies between arrays and objects.

## Operator next steps

1. Refresh `/admin → Catalogue → Products` — should show ~5,633 rows
2. Status badges visible: green "Live" + amber "Pending" + gray "Hidden"
3. Stock Qty column populated for tracked products
4. Toggle "Short description" column on for product copy preview
5. Filter Status = "Live" → 3,518 rows
6. Visit `/admin → Catalogue → Competitor Prices` → filter "Matched (in catalogue)" → ~4,453 distinct SKUs with real competitor pricing now visible

## Commit

`60dee1f` — feat(products): import live + pending Woo SKUs with stock qty + Live/Pending status labels
