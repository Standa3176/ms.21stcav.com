# 260913-pyz — Woo cost-of-goods: write it at creation, backfill the gap

**Branch:** `quick/260913-woo-cog-cost`
**Trigger:** operator asked why Woo's `Cost (excl. tax)` field was £0 on a
product whose real cost is £1.88.

## Why it was empty — three things in a row

Measured 2026-09-13: **6 of 25 sampled published products (24%) had no Woo
cost.** Every one was created AFTER 2026-05-23; every product that had a cost
was created ON 2026-05-23 — the bulk migration that populated it once.

1. **`CreateWooProductJob` never wrote the key.** Its payload carried exactly
   one meta entry, `_yoast_wpseo_metadesc`. Every app-created product reached
   Woo with no `_alg_wc_cog_cost`.
2. **`WooFieldComparator` SILENT-SKIPS `buy_price` when Woo lacks the key.**
   Its guard is `$localBuyPrice !== null && $liveBuyPrice !== null` — both
   sides must be present. This is a deliberate defensive contract ("most Woo
   installs don't have the WC COG plugin ... would flood sync_diffs with
   thousands of false positives"). Sound in general; the premise is false for
   THIS install, which has the plugin. Contrast `category_id`, which DOES emit
   on Woo-absence.
3. **`cutover:auto-sync` (23:00 nightly) only pushes what the comparator
   flags.** Nothing flagged, nothing pushed.

Born blank meant blank forever, and nothing reported it.

## Why it matters — not to pricing, to three other things

The app never reads Woo's copy; `products.buy_price` is what every calculation
uses. But:

- **WooCommerce profit reporting is wrong** on ~24% of published products
  (`47132` displayed "Profit: £2.35 (0.00%)" against a real 33% markup).
- **A B2BKing dynamic rule computing "Cost + x%" prices those at ZERO.**
  Group 167509 "Trade" carries exactly such a rule, currently unarmed only
  because that group has no users. `IDS-UVC-P38` costs £1,066 and has no Woo
  cost.
- It hides a whole divergence class from the nightly self-heal.

## What was built

**`CreateWooProductJob::buildCreateMeta()`** — new products are BORN with cost.
Reads `$product->buy_price` (not the local `$buyPennies`) for the same reason
the rest of the payload reads from the row: on a RESUME the row is the truth
and may carry operator edits. **Omits the key when cost is unknown** rather
than writing `0.0000` — a zero reads as a real value to WC COG and to any
dynamic rule, which is strictly worse than an absent key.

**`products:backfill-woo-cost`** — dry-run by default, `--apply` writes,
`--skus` / `--limit` scope it. Not scheduled: once the key EXISTS the
comparator compares it normally and `cutover:auto-sync` keeps it fresh. Same
shape as the existing `products:backfill-woo-stock` (260701-pmr), which solved
the identical class of problem for `manage_stock`.

## The trap this deliberately avoids

`WooProductWriter`'s own comment: *"a blind PUT with
meta_data=[{key:_alg_wc_cog_cost,...}] WIPES the rest"*. Woo REST meta is
replace-not-merge, so a naive backfill would delete every other meta entry —
**including the b2bking group prices fixed earlier the same day**. The command
therefore does NOT build a payload; it delegates to
`WooProductWriter::putProductFields($product, ['buy_price'])`, which pre-GETs
and merges through `mergeBuyPriceMeta()`. A test asserts the command contains
no `'meta_data' =>` of its own.

## Why it cannot loop

`woo:import-products` may only SEED `buy_price` from Woo COG — never overwrite
a non-null local cost (quick task 260809-uza broke that circular authority,
traced on SKU 9C941AA). So pushing local cost out cannot come back and cement
itself. This was checked BEFORE writing anything, because the loop it would
have recreated is one this codebase has already been bitten by.

## Not done, deliberately

**`WooFieldComparator` was left alone.** Making it emit on Woo-absence would
fix the gap permanently, but would also queue ~1,500 new diffs on the next
nightly scan and erode a defensive contract that is correct for any install
without the plugin. An explicit, operator-run backfill is the smaller and more
reviewable change. If the gap recurs after this, revisit the comparator with a
config flag for plugin presence.

## Tests

`tests/Feature/Sync/WooCogCostBackfillTest.php` — 7 tests. The one that matters
most is that the create payload keeps the Yoast key ALONGSIDE the new cost key:
Woo meta is replace-not-merge, so an incomplete array is how you silently
delete another plugin's data.

Full run `tests/Feature/ProductAutoCreate tests/Feature/Sync tests/Architecture`:
**540 passed, 1,937 assertions**. Pint clean, deptrac 0 violations.

## Operator steps after deploy

    php artisan products:backfill-woo-cost                  # dry-run, counts the gap
    php artisan products:backfill-woo-cost --limit=50 --apply   # a first batch
    php artisan products:backfill-woo-cost --apply              # the rest

At the 60/min Woo write throttle roughly 1,500 products drain in ~25 minutes;
the command aborts after 15 consecutive read failures so an environmental fault
surfaces in seconds rather than an hour.

Separately and still outstanding: **delete the "Trade Price = Cost + 8%"
dynamic rule on B2BKing group 167509.** This backfill removes the £0 hazard for
products it touches, but the rule remains a second, competing definition of
trade price that reads a field the app does not own.
