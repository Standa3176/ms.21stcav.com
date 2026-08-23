---
quick_id: 260823-clp
slug: alternative-supplier-sku-aliasing
date: 2026-08-23
status: in-progress
---

# Quick Task 260823-clp — Alternative supplier SKUs (stop duplicate products)

Implements steps 2–3 of the pending TODO
`.planning/todos/pending/2026-08-09-add-multi-supplier-sku-aliasing-to-stop-duplicate-products.md`.
Its step-1 prerequisite (pricing guards from the 2026-08-09 undercut incident)
already shipped as `260809-jie`.

## Problem

The legacy Stock Updater plugin had an **"alternative SKU"** field per product.
When a second supplier used a different code for the same physical part, an
operator recorded it and the plugin sourced that one product from both. This
app has no equivalent: `products` stores a single non-unique `sku`, there is
**no `mpn` column at all**, and `SkuMatcher` is one line of exact-match
(`return $this->map[$sku] ?? null;`). Second-supplier rows therefore look like
unknown parts, become add-candidates, and get auto-created as **duplicate
products on Woo**.

The upstream supplier DB already models the relation — `feeds_products` carries
`mpn` and `suppliersku` separately, and `SupplierAddCandidateScanner` even
aggregates `GROUP_CONCAT(suppliersku) GROUP BY TRIM(mpn)` — then discards it.

## Scope (operator-chosen 2026-08-23)

**Stop new duplicates + manual entry. NO price or stock behaviour changes.**

Explicitly OUT:
- merging duplicates already created (TODO step 4)
- multi-supplier `buy_price` / stock selection (TODO step 5) — this would change
  which offer feeds the margin bands and therefore `sell_price`, and there are
  281 open published price divergences. Held back deliberately.

Consequence for the build: aliases are wired into the DUPLICATE-PREVENTION
paths only. `SupplierDbSyncCommand` (the live Mon-Fri 07:00 price/stock pull)
is **left untouched** — extending its `$localSkus` set would let a second
supplier's offer win best-offer selection and move `buy_price`, which is
exactly step 5.

## Tasks

- **T1 — Storage.** `product_supplier_skus` table per the TODO:
  `product_id, supplier_id (nullable), supplier_sku, normalised_sku, source
  (manual|derived_mpn|derived_ean), confidence, notes, timestamps`,
  `unique (normalised_sku, supplier_id)`. Model + `Product::supplierSkus()`
  relation. `normalised_sku` is stored (not computed at query time) so lookups
  hit an index — the codebase already has a documented `LOWER(TRIM())`
  index-miss note on `products.sku`.
- **T2 — Duplicate prevention.** Alias-aware `ProductMatcher::existsNormalised()`
  (the AUTO-08 gate immediately before a Woo POST — the true last line of
  defence) and alias exclusion in `SupplierAddCandidateScanner` (so a known
  alternate never becomes an add-candidate in the first place).
- **T3 — Matching + UI.** `SkuMatcher` gains alias resolution and a
  lowercase-trimmed index, resolving the documented case-sensitivity
  inconsistency against `SupplierOfferSnapshot`'s matchKey convention. Filament
  `SupplierSkusRelationManager` on `ProductResource` for manual entry — the
  legacy plugin's field, done as one-to-many.
- **T4 — Proposals + tests.** `products:propose-sku-aliases` walks
  `feeds_products` for normalised-MPN / EAN matches against local products and
  reports them; `--apply` writes them as `source=derived_*`. Dry-run by default.
  Tests across all of it.

### Deviation from the TODO

The TODO proposes writing auto-seeded aliases as **Suggestions** for operator
confirmation. Implemented instead as a dry-run-by-default command with
`--apply`, because a new Suggestion kind needs an applier + inbox wiring for no
extra safety — the operator reviews the same list either way. Noted here rather
than silently diverging.

## must_haves

- truths:
  - a supplier row whose SKU is a known alternate never becomes an add-candidate
  - the auto-create duplicate gate recognises alternates
  - recording an alternate is possible from the product page, without SQL
  - `buy_price`, `stock_quantity` and `sell_price` behaviour are untouched
- artifacts:
  - `database/migrations/*_create_product_supplier_skus_table.php`
  - `app/Domain/Products/Models/ProductSupplierSku.php`
  - `app/Console/Commands/ProposeSkuAliasesCommand.php`
  - `tests/Feature/SupplierSkuAliasTest.php`
