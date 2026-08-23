---
quick_id: 260823-elz
slug: alias-sourcing-slice
date: 2026-08-23
status: complete
---

# Summary — source through an alternative SKU when a product has no supplier

Completes 260823-clp. That task built the alias RECORD and wired two consumers
(the auto-create duplicate gate, the add-candidate scanner) but deliberately
left the third — `supplier:db-sync`, the live Mon-Fri 07:00 price/stock pull —
alias-blind, because using an alias there touches `buy_price`.

## Why it was needed

Biamp made the gap concrete. Local SKUs follow the dotted `9xx.xxxx.900` scheme
Nimans quotes; Midwich quotes a dashed `920-0xxxx-00003` scheme. Result on
prod 2026-08-23:

- 106 local Biamp products, **102 with zero stock, 99 pending**
- Midwich holds **112 Biamp rows, 20 in stock** — structurally invisible
- Nimans, the scheme we CAN match, shows zero stock on every Biamp line
- e.g. product 4949 Tesira Forte AVBCI, pending, no stock — Midwich has **18**
  at £2,391.70 under `920-00395-00003`

Recording the alias alone changed nothing on the storefront, because the sync
never read it.

## What changed

`SupplierDbSyncCommand` only:

1. Alias codes join the remote feed query, so their offers are FETCHED.
2. `resolveMatchKey()` — new pure helper — decides whether to use one.
3. The offer-snapshot path resolves alias codes to their product, so a second
   supplier's offer is captured against the right product. Product SKUs seed
   that map first and are never overwritten.
4. Run summary reports `via_alt_sku=N`; each fallback logs
   `supplier_db_sync.matched_via_alias`.

## The safety property

`resolveMatchKey()` returns null when the product's own SKU already matched.
An alias is consulted **only** for an otherwise-unmatched product: zero offers
becomes one offer. A product that already sources keeps exactly the offer it
had, so no existing `buy_price` moves and no supplier ranking is re-decided.

A test pins this with the alias priced CHEAPER than the matched own-SKU offer
and asserts the alias still loses — because choosing the cheapest across
suppliers is step 5 of the 2026-08-09 TODO, not this change.

## Expected effect on first run

For a product that gains an offer this way, `buy_price` moves from stale to the
supplier's current cost, and the margin rules recompute `sell_price`. That is
the intended outcome — a real cost replacing a stale one — but it IS a price
movement, so expect those specific products to reprice. It applies only to
products that had NO supplier at all, which by definition were not selling.

## Tests

80 passed (247 assertions) across `tests/Feature/Sync`,
`tests/Feature/Domain/Sync` and `SupplierSkuAliasTest`. deptrac 0 violations.
6 new cases on `resolveMatchKey`, including the cheaper-alias-still-loses guard.

## Deploy note

No migration. Behaviour is inert until aliases exist — the table is empty, so
the first run after deploy is byte-identical to today unless someone has
recorded alternates. Verify with `supplier:db-sync --dry-run` and read
`via_alt_sku=` in the summary.
