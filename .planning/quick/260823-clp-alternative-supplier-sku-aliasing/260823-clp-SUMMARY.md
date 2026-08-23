---
quick_id: 260823-clp
slug: alternative-supplier-sku-aliasing
date: 2026-08-23
status: complete
commit: 6a24348
---

# Summary — Alternative supplier SKUs

Implements steps 2–3 of the 2026-08-09 TODO. Step 1 (pricing guards) had
already shipped as `260809-jie`.

## What was wrong

`products` stores a single, non-unique `sku`; **there is no `mpn` column
anywhere**; `SkuMatcher` was `return $this->map[$sku] ?? null;`. So a second
supplier's code for a part already on the storefront matched nothing, became an
add-candidate, and got auto-created as a duplicate Woo product. The supplier DB
already models the relation — `feeds_products` carries `mpn` and `suppliersku`
separately, and the add-candidate scanner even aggregates them — and the app
threw it away.

## What shipped

| piece | effect |
|---|---|
| `product_supplier_skus` table + model | one product, many supplier codes; `normalised_sku` stored (not computed) so the hot readers use an index; `unique(normalised_sku, supplier_id)` because the same short code from two suppliers can be two different parts |
| `ProductMatcher::existsNormalised()` | the AUTO-08 gate right before a Woo POST counts an alternate as stocked — last line of defence |
| `SupplierAddCandidateScanner` | alternates excluded from the candidate set — the duplicate never gets proposed |
| `SkuMatcher` | alias fallback, after the exact match |
| `SupplierSkusRelationManager` | "Alternative SKUs" tab on the product page; admin + pricing_manager write |
| `products:propose-sku-aliases` | proposes links from feed MPNs; dry-run by default, `--apply` writes `derived_mpn` |

## Two deliberate non-changes

**Prices and stock are untouched.** `SupplierDbSyncCommand` — the live Mon-Fri
07:00 pull — is NOT alias-aware. Extending its match set would let a second
supplier's offer enter best-offer selection and move `buy_price`, which feeds
the margin bands and therefore `sell_price`. That is TODO step 5, held back
while 281 published price divergences are open (see 260822-rmo).

**`SkuMatcher` stays case-sensitive.** The TODO proposed folding case to match
`SupplierOfferSnapshot`'s lowercase-trimmed convention. Not done: that
behaviour is a deliberate AUTO-08 Woo convention with its own named test
(`SkuMatcherTest` M2), and on that path a wrong match is a wrong PRICE, not
just a wrong label. Normalisation is confined to alias resolution — alias codes
are stored normalised, so the feed is indexed normalised for alias lookups
only. M2 passes untouched.

Also deviated: the TODO wanted auto-seeded aliases written as **Suggestions**.
Implemented as a dry-run command instead — a new Suggestion kind needs an
applier plus inbox wiring and buys no extra safety, since the operator reviews
the same list either way.

## Tests

16 passed (25 assertions) — `SupplierSkuAliasTest` (13) + `SkuMatcherTest` (3,
untouched). Wider sweep 229 passed across `tests/Feature/Console`,
`ProductResourceTest` and the matcher suites. `deptrac` 0 violations.

## Deploy

Ships a migration, so the deploy must run `php artisan migrate --force`
(`deploy.sh` does this at step 3). Nothing changes behaviour until an operator
records an alternate or runs the propose command — the table starts empty.

## Next

1. `products:propose-sku-aliases` (dry-run) on prod to size the backlog, then
   `--strip-suffix` to see the `9C941AA` vs `9C941AA#ABU` class.
2. TODO step 4 — find and merge duplicates already created
   (`BrandDuplicateFinder` is the precedent).
3. TODO step 5 — multi-supplier `buy_price`/stock, once prices are trusted.
4. The five operator-reported wrong SKUs (7393, 7422, 7505, 7570, 32147) —
   still awaiting correct values; a wrong SKU also corrupts the divergence scan,
   which looks Woo up by SKU.
