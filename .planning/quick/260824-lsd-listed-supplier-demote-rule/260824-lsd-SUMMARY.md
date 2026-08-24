---
quick_id: 260824-lsd
slug: listed-supplier-demote-rule
date: 2026-08-24
status: complete
---

# Summary — "a live SKU must have at least one supplier"

Operator rule, 2026-08-24: **demote when no supplier lists the product. Zero
stock does not matter. Not Nuvias.**

## Two defects this fixes

**1. The demote asked the wrong question.** `--flag-obsolete` decided
obsolescence from `buildBestOfferMap`, which filters by stock, supplier
freshness and the operator exclusion list, then ranks by price. That is right
for choosing a PRICE and wrong for asking whether a supplier exists at all. A
product listed by a supplier who merely had no stock, or whose snapshots had
lapsed, looked obsolete.

That mismatch made the two halves contradict each other on 2026-08-23: 689
products demoted at 07:00, and `restore-sourceable-pending` proposing to
restore 64 of them minutes later. Same data, two definitions.

**2. Local demotions never survived.** `WooImportProductsCommand:143` writes
`'status' => $p['status']` through `updateOrCreate`, which applies the whole
values array on update — so the 03:00 and 09:00 Woo imports copy Woo's status
onto every local product unconditionally. The 689 demotions applied by hand on
2026-08-23 were all back to `publish` by morning. Any local-only status change
has a lifespan of hours.

## What changed

`SupplierDbSyncCommand`:
- `buildListedKeySet()` — every supplier code in the fetched rows, UNFILTERED.
  Free: the remote query is already scoped `product_excluded = 0`, so a row
  existing IS the listing. Nuvias is absent because its rows were excluded at
  source on 2026-08-23 when the company folded.
- `isListedForProduct()` — checks the product's own SKU and every alternative
  code. The alias arm matters: a Biamp product whose only supplier quotes the
  dashed scheme is listed even though its dotted SKU appears nowhere.
- The obsolete decision now uses these instead of `$map`. `$map` still drives
  price and stock, untouched.

`routes/console.php` — the morning sequence, all London time:

```
07:00  supplier:db-sync --flag-obsolete            demote unlisted
07:25  restore-sourceable-pending --push-to-woo
       --include-listed-out-of-stock               promote re-listed + push
07:35  products:push-status-to-woo --live          push demotions   (NEW)
09:00  woo:import-products                         reads back a consistent state
09:05  supplier:db-sync (bare)                     safety net, deliberately no demote
```

The 07:35 push is load-bearing — without it the 09:00 import silently reverts
every demotion. `--include-listed-out-of-stock` on the restore makes both
directions ask the same question, which is what stops the daily oscillation.

## Tests

8 new cases pinning the rule: a zero-stock row still counts as a listing; a
listing is indexed under both part number and supplier code; case and padding
normalise; blanks are ignored; own-SKU listing counts; alias-only listing
counts; neither means unlisted; a blank SKU is never listed.

`tests/Feature/Sync` + `tests/Feature/Domain/Sync` 75 passed.
`tests/Architecture` 121 passed. deptrac 0 violations.

## Residual risk

The demote fires on a single day's absence. If a supplier's feed drops a line
briefly, the product goes to pending and returns the next morning — with a Woo
write each way. Worth watching the first week's `flagged obsolete` counts; if
they churn, requiring N consecutive days of absence is the fix.

Unicol remains `is_active = 1` on 2026-01-06 data. Its rows still count as
listings under this rule, deliberately — the operator parked it 2026-08-23.
