---
created: 2026-08-09T12:56:16.382Z
title: Add multi-supplier SKU aliasing to stop duplicate products
area: sync
files:
  - app/Domain/Sync/Services/SkuMatcher.php:32
  - app/Domain/Sync/Services/SupplierAddCandidateScanner.php:60-101
  - app/Domain/Products/Models/SupplierOfferSnapshot.php:12-24
  - database/migrations/*_create_products_table.php:29
---

## Problem

The legacy Stock Updater plugin carried an **"alternative SKU"** field per product. When a different supplier used a different SKU for the same physical part, an operator recorded it there and the plugin then sourced that one product from **both** suppliers. The new app has no equivalent, so second-supplier rows look like unknown parts and **duplicate products are being created**.

### Root cause

The app's identity key is a single string; the supplier universe is one-to-many.

1. **`products` stores only `sku`** — `string(100)`, `->index()` and **not unique**, so duplicates are physically permitted. There is **no `mpn` column** anywhere in the migrations or the `Product` model. The manufacturer part number — the actual cross-supplier identity key — is received and never persisted.

2. **`SkuMatcher` is exact-match and case-sensitive** — the whole matching layer is `return $this->map[$sku] ?? null;` ([SkuMatcher.php:32](app/Domain/Sync/Services/SkuMatcher.php#L32)). One key, one row, no aliasing, no normalisation. Its docblock notes case-sensitivity was an AUTO-08 Woo convention with "ops can revisit in Phase 6".

3. **The upstream supplier DB already models the relation and the app discards it.** `feeds_products` carries `mpn` and `suppliersku` as separate columns, and [SupplierAddCandidateScanner.php:60-68](app/Domain/Sync/Services/SupplierAddCandidateScanner.php#L60-L68) does:

   ```sql
   SELECT TRIM(mpn) AS mpn,
          COUNT(DISTINCT supplierid) AS supplier_count,
          GROUP_CONCAT(DISTINCT LOWER(TRIM(suppliersku))) AS supplierskus
   FROM feeds_products
   GROUP BY TRIM(mpn)
   ```

   Many `suppliersku` under one `mpn` — exactly the mapping we want. It is used transiently to filter add-candidates (lines 92-101, lowercased/trimmed against local SKUs) and then thrown away.

### Why duplicates still get through

The scanner already excludes on both `mpn` and the `suppliersku` set, so plain case/whitespace differences are handled. Duplicates arise when:

- **MPN strings differ between suppliers** — most commonly region suffixes: supplier A lists `9C941AA`, supplier B lists `9C941AA#ABU`. Different `GROUP BY` bucket → unmatched part → add candidate → duplicate. The codebase already knows this class exists: quick task `260709-db5` added a base-MPN fallback stripping `#ABU`/`#ABB`/`#AC3` — but that normalisation lives in `EanSearchClient` and nowhere near the matcher.
- **`products.sku` is a legacy or Woo-internal code** matching neither the MPN nor any supplier SKU, so nothing links it and every supplier looks new.

### Related finding

`SupplierOfferSnapshot` **already captures multi-supplier offers** — one row per `(sku, supplier_id, date)` with price/stock/rrp and a nullable `product_id` "so feed entries for SKUs we don't yet stock still land". The per-supplier capture exists and runs daily off `supplier:db-sync`. What is missing is the alias mapping that would make two suppliers' rows land on **one** product, plus the rule selecting which offer feeds `buy_price`.

**Latent inconsistency:** `SupplierOfferSnapshot` stores `sku` lowercase-trimmed as a deliberate "matchKey" convention, while `SkuMatcher` is explicitly case-sensitive. Two matching conventions in one codebase.

## Solution

New **`product_supplier_skus` alias table** — not a single column, since the legacy plugin's one "alternative SKU" field only holds one alternate and we have suppliers plural:

```
product_id, supplier_id (nullable), supplier_sku,
source (manual | derived_mpn | derived_ean), confidence, created_at
unique (supplier_sku, supplier_id)
```

Three changes, all small:

1. **`SkuMatcher`** gains an alias lookup — resolve `supplier_sku → product_id` before falling back to exact SKU. It is the single choke point, so every sync path inherits the fix from one change. Resolve the case-sensitivity inconsistency here, aligning on `SupplierOfferSnapshot`'s lowercase-trimmed matchKey.
2. **`SupplierAddCandidateScanner`** adds the alias set to its exclusion, so a known alternate never becomes a duplicate candidate.
3. **Auto-seeding, not manual-only** — propose aliases where a normalised MPN matches (strip region suffix, uppercase, strip punctuation) or where EANs match; write them as Suggestions with `source=derived_*` for operator confirmation. Manual entry stays for what automation cannot see. Normalised-MPN seeding alone likely clears most duplicate pressure with no human input.

### Genuinely new work

"Use both suppliers for the main SKU" means one product has N offers, but `buy_price` is a single column. Needs a **best-offer selection rule** — cheapest in-stock offer from an active, non-excluded, non-stale supplier — and a decision on whether stock is the **sum or the max** across suppliers. Build on `SupplierOfferSnapshot` rather than a new table.

### Sequencing

1. **Pricing guards from the 2026-08-09 undercut incident FIRST** — multi-offer changes which cost feeds the margin bands, so those guards must land before this work. (See `pricing:undercut-competitors` markup ceiling + competitor-row quarantine.)
2. Alias table + `SkuMatcher` + scanner exclusion — stops *new* duplicates.
3. Auto-seed from normalised MPN / EAN — clears the backlog of missed links.
4. Find and merge duplicates already created — `BrandDuplicateFinder` is the precedent for the pattern.
5. Multi-offer `buy_price`/stock selection — the "use both suppliers" behaviour proper.

**Steps 2-3 are the most value for the least risk.**
