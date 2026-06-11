---
quick_id: 260611-f1y
phase: quick-260611-f1y
plan: 01
type: execute
status: complete
completed: 2026-06-11
files_created:
  - database/migrations/2026_06_11_095943_add_is_internal_only_to_products_table.php
  - database/migrations/2026_06_11_100127_seed_internal_only_products.php
  - app/Console/Commands/PushVisibilityToWooCommand.php
  - tests/Feature/Console/PushVisibilityToWooCommandTest.php
files_modified:
  - app/Domain/Products/Models/Product.php
  - app/Providers/AppServiceProvider.php
  - app/Domain/Products/Filament/Resources/ProductResource.php
  - app/Domain/Products/Filament/Resources/ProductResource/Pages/EditProduct.php
commits:
  - 31f1967 — feat(products): is_internal_only column + index (260611-f1y)
  - 2babc42 — feat(products): seed is_internal_only=true for Credit/Offer/Quote Payment (260611-f1y)
  - 5fb2c4a — feat(products): products:push-visibility-to-woo command + registration (260611-f1y)
  - 279ce0b — feat(filament): is_internal_only toggle on Products edit form (260611-f1y)
  - 574878b — test(products): push-visibility-to-woo Pest cases A-F (260611-f1y)
test_delta:
  baseline: "1,989 / 222 / 3 (260610-qc4)"
  current:  "1,995 / 222 / 3 (260611-f1y)"
  delta:    "+6 pass / 0 new fails / 0 new skipped"
requirements:
  - 260611-f1y/storefront-hide-3-internal-products
  - 260611-f1y/ms-side-is_internal_only-flag
  - 260611-f1y/operator-toggle-from-product-edit
  - 260611-f1y/idempotent-push-command-with-pre-get-check
---

# 260611-f1y — Hide Internal-Use Products (Credit/Offer/Quote Payment) Summary

**Headline.** 3 internal-use products (Credit/Offer/Quote Payment (No Vat)) are flagged
for storefront hiding via a new `is_internal_only` boolean on `products` + a permanent
operator mechanism: `products:push-visibility-to-woo` artisan command + Filament toggle
on the Product Edit page. Storefront discovery is hidden via Woo `catalog_visibility=hidden`;
direct-URL access + custom-quote attach preserved (intentional property of
catalog_visibility=hidden — see Toggle-OFF caveat below).

## Files changed

- `database/migrations/2026_06_11_095943_add_is_internal_only_to_products_table.php`
- `database/migrations/2026_06_11_100127_seed_internal_only_products.php`
- `app/Console/Commands/PushVisibilityToWooCommand.php`
- `app/Domain/Products/Models/Product.php` (fillable + casts)
- `app/Providers/AppServiceProvider.php` (command registration)
- `app/Domain/Products/Filament/Resources/ProductResource.php` (Toggle on Details tab)
- `app/Domain/Products/Filament/Resources/ProductResource/Pages/EditProduct.php` (afterSave push)
- `tests/Feature/Console/PushVisibilityToWooCommandTest.php` (6 Pest cases A-F)

## Commits (5 atomic + 1 verify-only Task 6)

| # | Hash      | Message                                                                                |
| - | --------- | -------------------------------------------------------------------------------------- |
| 1 | `31f1967` | `feat(products): is_internal_only column + index (260611-f1y)`                         |
| 2 | `2babc42` | `feat(products): seed is_internal_only=true for Credit/Offer/Quote Payment (260611-f1y)` |
| 3 | `5fb2c4a` | `feat(products): products:push-visibility-to-woo command + registration (260611-f1y)`  |
| 4 | `279ce0b` | `feat(filament): is_internal_only toggle on Products edit form (260611-f1y)`            |
| 5 | `574878b` | `test(products): push-visibility-to-woo Pest cases A-F (260611-f1y)`                   |

## Pest delta vs baseline

- **260610-qc4 baseline:** 1,989 pass / 222 fail / 3 skipped
- **260611-f1y current:** 1,995 pass / 222 fail / 3 skipped
- **Delta: +6 pass / 0 new fails / 0 new skipped** (exactly the 6 new Case A-F cases)
- Focused: PushVisibilityToWooCommandTest 6/6 GREEN, 29 assertions, 31.18s
- Regression: BackfillCategoryFromWooCommandTest (6) + DivergenceScanCommandTest (9) +
  WooFieldComparatorTest (9) — 24/24 GREEN, 94 assertions, 15.18s
- Full suite 1,454.32s (sqlite :memory:)

## POST-DEPLOY OPERATOR ACTION (required to actually hide the 3 SKUs)

After deploying to prod, the operator runs:

```bash
# 1. Dry-run — verify the 3 internal candidates resolve.
php artisan products:push-visibility-to-woo --dry-run
#   Expected: would_hide=3 (Credit woo=167493, Offer woo=167492, Quote Payment woo=165038)

# 2. Live push — single-field PUT catalog_visibility=hidden per candidate.
php artisan products:push-visibility-to-woo
#   Expected: hidden=3 already_hidden=0 errors=0 no_woo_product_id=0

# 3. Storefront spot-check.
#    - Open https://meetingstore.co.uk/shop — the 3 internals must NOT appear.
#    - Open https://meetingstore.co.uk/?s=Credit — must NOT surface "Credit" tile.
#    - Open https://meetingstore.co.uk/product/credit/ — must STILL load + be addable
#      to cart (orderable preserved via direct URL — intentional, see T-f1y-04).

# 4. Re-run for idempotency proof.
php artisan products:push-visibility-to-woo
#   Expected: hidden=0 already_hidden=3 errors=0 (pre-GET short-circuit)
```

If any candidate produces `errors=1`, inspect `integration_events` for the `PUT products/{id}`
correlation_id — usually a 502/504 from the host. Re-run; the pre-GET check makes retries safe.

## Drift-prevention contract

`app/Domain/Cutover/Services/WooFieldComparator.php` does **NOT** compare
`catalog_visibility` — verified by `grep catalog_visibility` returning zero hits
against the comparator source. The 3 seeded SKUs will therefore **not** surface as
`sync_diffs` rows after the push lands on Woo.

If a future quick task ever extends the comparator to include `catalog_visibility`,
it MUST teach the comparator to treat `is_internal_only=true` rows as **expected-hidden**.
Otherwise the 3 seeded SKUs (and every future operator-toggled internal) will flood
the divergence scan with false positives.

This contract is also recorded in the `PushVisibilityToWooCommand` class docblock so
the next dev grepping for `catalog_visibility` lands on the comment before the comparator.

## Toggle-OFF caveat

The push command sets `catalog_visibility=hidden` ONLY. Toggling
`is_internal_only` from true → false on the Filament edit form does **NOT** automatically
re-set `catalog_visibility=visible` on Woo.

The success notification in `EditProduct::afterSave()` surfaces this explicitly:

> `is_internal_only toggled OFF — Woo visibility NOT changed by command default.
>  Re-enable visibility manually in Woo admin if needed.`

**Follow-up (NOT in this task):** extend the command with a `--visibility=visible|hidden`
flag so the toggle-OFF path can push the inverse change. Queued for the next quick task
that needs this — until then the OFF flip is operator-handled in Woo admin.

## Future internals workflow

The Filament Details tab on every Product edit page now has:

> **Internal-only (hide from storefront)** — Toggle, visible to `admin` + `pricing_manager` only.
> Helper text: "When enabled, the next save pushes catalog_visibility=hidden to Woo.
> Product remains orderable via direct URL + custom-quote attach. Toggling OFF does
> NOT auto-restore Woo visibility — operator must manually re-enable in Woo admin."

`EditProduct::afterSave()` detects a real toggle change (pre-save vs current diff;
not a no-op resave) and synchronously invokes
`Artisan::call('products:push-visibility-to-woo', ['--skus' => $token])` for the
single product. Token = SKU when present, else `(string)$woo_product_id` (covers
empty-SKU internals).

## Self-Check: PASSED

Files verified present:
- `database/migrations/2026_06_11_095943_add_is_internal_only_to_products_table.php` — FOUND
- `database/migrations/2026_06_11_100127_seed_internal_only_products.php` — FOUND
- `app/Console/Commands/PushVisibilityToWooCommand.php` — FOUND
- `tests/Feature/Console/PushVisibilityToWooCommandTest.php` — FOUND

Commits verified via `git log --oneline`:
- `31f1967` — FOUND
- `2babc42` — FOUND
- `5fb2c4a` — FOUND
- `279ce0b` — FOUND
- `574878b` — FOUND

Threat surface scan: no new network endpoints, auth paths, file access, or schema
changes at trust boundaries beyond what's already in `<threat_model>` of the plan.

Known stubs: none.
