# 260726-sle — `supplier:lookup-eans` Summary

**One-liner:** READ-ONLY `supplier:lookup-eans` artisan command that returns the supplier_db feed EAN for any given SKU list (regardless of local product state) via a new `SupplierEanLookup` Sync service, with per-SKU normalisation + GTIN checksum + `matched_by` and an optional review CSV.

## What was built

- **`app/Domain/Sync/Services/SupplierEanLookup.php`** — READ-ONLY mysqli lookup service. Two-pass match (`suppliersku` preferred, then `mpn` for still-unmatched keys), `product_excluded = 0`, `SELECT LOWER(TRIM(col)) AS sku_key, ean FROM feeds_products WHERE product_excluded = 0 AND LOWER(TRIM(col)) IN (...)`. Adds `matched_by` (`suppliersku`|`mpn`) and IN()-list chunking (500 keys/query). Single connection, closed in a `finally`. Credentials resolved via `IntegrationCredentialResolver::for(IntegrationCredentialKind::SupplierDb)` — the identical path `BackfillMerchantFeedCommand` and `MysqlSupplierFeedReader` use.
- **`app/Console/Commands/SupplierLookupEansCommand.php`** — `supplier:lookup-eans {--skus=} {--skus-file=} {--csv=}`. Unions `--skus` (comma) + `--skus-file` (one per line), trims, de-dupes case-insensitively (lookup is `LOWER(TRIM())`-keyed), errors cleanly if none. Per SKU emits `supplier_ean` (raw), `normalised` (`NormalisesEan::normaliseEan`), `checksum_valid` (`NormalisesEan::isValidGtinChecksum` on the normalised value), `matched_by` (`suppliersku`|`mpn`|`none`), `found` (yes/no). Prints a summary + a console table capped at 50 rows (notes when capped); `--csv` writes ALL rows with header `sku,supplier_ean,normalised,checksum_valid,matched_by,found`. No writes anywhere.
- **`tests/Feature/Console/SupplierLookupEansCommandTest.php`** — 7 Pest tests against an injected fake `SupplierEanLookup` (no real DB).

## Extraction vs replication decision

**Replicated (did NOT extract).** The query + connection semantics are a verbatim mirror of `BackfillMerchantFeedCommand::lookupSupplierEans()`, re-homed into a new `SupplierEanLookup` service used ONLY by the new command. `BackfillMerchantFeedCommand` was left completely untouched, so its output/counts are byte-identical and its 16 tests stay green (verified). Rationale: the hard constraint prioritised byte-identical backfill behaviour over sharing, and the new command needs richer output (`matched_by`) that backfill's method does not expose — so a shared method would have required changing backfill's signature/behaviour. The duplication is the two SELECT strings + the mysqli connect block; it is intentional and documented in the service docblock.

## Verification

- **pest (new command):** 7 passed (22 assertions) — empty-input error, checksum via trait (`6938820000000` → checksum_valid=no, `5033588057222` → yes), unmatched → `found=no`/`matched_by=none`, suppliersku-wins-over-mpn, CSV exact header + one row per SKU, `--skus`/`--skus-file` union + dedupe, and a no-write assertion (seeded product row untouched, `Product::count()` unchanged, lookup consulted once, no write API on the seam).
- **pest (backfill byte-identical guard):** 16 passed (67 assertions).
- **pint:** pass (new files formatted; `SupplierEanLookup` re-checked clean after removing a docblock-only `use App\Console\...` import pint had added, keeping Sync decoupled from Console).
- **deptrac analyse:** 0 violations (0 skipped). The raw supplier_db mysqli read sits in the Sync layer beside `MysqlSupplierFeedReader` — an already-accepted pattern; `IntegrationCredentialResolver` is in Sync's allow-list; the command lives in `app/Console/Commands` (uncovered by any layer, as commands are).
- **route:list --path=admin:** exit 0.
- **No-write confirmation:** PASSES — only SELECTs against `feeds_products`; the only file write is the operator-requested `--csv` output artefact (not a supplier_db or products write); no migration, no Woo calls, no `WOO_WRITE_ENABLED` change.

## Prod usage (no push/deploy performed)

```
php artisan supplier:lookup-eans --skus=A30-020,DS-D6075UN
php artisan supplier:lookup-eans --skus-file=storage/app/shortlist-skus.txt --csv=storage/app/supplier-eans.csv
```

## Deviations from Plan

None. Plan executed as written. One in-flight cleanup: pint's `fully_qualified_strict_types` fixer added a docblock-driven `use App\Console\Commands\SupplierLookupEansCommand` import to the Sync service; reworded the `@see` to plain text and removed the import so the Sync domain does not depend on the Console namespace (deptrac stayed 0 either way — the target is uncovered).

## Commits

- `8377026` test(260726-sle): add failing test for supplier:lookup-eans read-only EAN lookup
- `341d26f` feat(260726-sle): add read-only supplier:lookup-eans command
- `ba3c0a0` style(260726-sle): pint-format supplier:lookup-eans test

## Untouched pre-existing working-tree noise (left unstaged, per instructions)

- `storage/app/research/supplier-probe.json` (deleted)
- `tests/Unit/Competitor/CompetitorIngestFreshnessColorTest.php` (modified)
- `.claude/` (untracked)

## Self-Check: PASSED
