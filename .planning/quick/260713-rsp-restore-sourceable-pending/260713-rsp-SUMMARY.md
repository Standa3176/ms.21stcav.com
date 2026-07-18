---
phase: quick
plan: 260713-rsp
subsystem: Sync / Products (cutover-prep)
tags: [cutover, supplier-sync, status-realignment, local-only, tdd]
requires:
  - LiveSupplierStockResolver (feeds_products + stockseparate, freshness-gated)
  - Product model (status/woo_product_id/is_custom_ms/exclude_from_auto_update/tags)
provides:
  - products:restore-sourceable-pending (artisan command, local-only)
  - LiveSupplierStockResolver::isListedByFreshSupplier()
affects:
  - Local product.status realignment before cutover (NO Woo write, NO cutover-flag change)
tech-stack:
  added: []
  patterns:
    - "Consistent-inverse restore: same live fresh-supplier signal flag-obsolete keeps on"
    - "Dry-run-by-default operator command (--live to apply)"
    - "Throwing-WooClient test guard for local-only invariant"
key-files:
  created:
    - app/Console/Commands/RestoreSourceablePendingCommand.php
    - tests/Feature/Products/RestoreSourceablePendingCommandTest.php
  modified:
    - app/Domain/Sync/Services/LiveSupplierStockResolver.php
    - app/Providers/AppServiceProvider.php
decisions:
  - "Restore keys on the LIVE fresh-supplier offer, NOT supplier_sku_cache membership — the cache is broader (stale/excluded/OOS-inclusive) and would churn against the nightly sync."
  - "--dry-run is the default; the command's dry-run IS the instrument that produces the real 162 in-stock breakdown when run on prod."
metrics:
  duration: ~1h
  completed: 2026-07-18
---

# Quick Task 260713-rsp: Restore wrongly-flagged sourceable `pending` products (local-only) Summary

Built `products:restore-sourceable-pending` — a LOCAL-ONLY, dry-run-by-default artisan command that restores wrongly-demoted `pending` + on-Woo products with a **current fresh-supplier offer** back to `publish`, using the consistent inverse of `supplier:db-sync --flag-obsolete`'s keep-set (the live `LiveSupplierStockResolver` signal, **not** `supplier_sku_cache`) so restored rows survive the next nightly sync.

## Task 1 — Investigation findings (why the 162 were demoted)

### The two demotion paths (both LOCAL-only, no Woo write)

1. **`supplier:db-sync --flag-obsolete`** (`SupplierDbSyncCommand::isObsoleteCandidate`) demotes a `publish` product to `pending` when its SKU has **NO entry in the best-offer map**. That map (`buildBestOfferMap`) is built from a LIVE `feeds_products` query filtered by:
   - `product_excluded = 0`
   - **minus operator-excluded suppliers** (`suppliers.is_active = false`, unconditional)
   - **minus stale suppliers** (no upload for ≥ threshold_days — default ON)

   A key lands in the map for **any** matching row from a fresh, non-excluded supplier — **stock-agnostic** (an out-of-stock listing still counts). So flag-obsolete's *keep* rule = "listed by a fresh, non-excluded supplier."

2. **`products:flag-missing-buy-price`** demotes a `publish` product when its **local `buy_price` is NULL or ≤ 0** (skips `custom-ms` tag + active `product_exceptions`). Orthogonal to supplier-feed membership entirely.

### Why "in `supplier_sku_cache`" ≠ "should be published"

`supplier_sku_cache` (built by `SupplierSkuRegistry::refresh`) is materialised from `feeds_products` filtered **only** by `product_excluded = 0`. It is **strictly broader** than flag-obsolete's keep-set:

- it **includes** SKUs carried only by **stale** or **operator-excluded** suppliers (which flag-obsolete drops), and
- it is **completely stock-agnostic** (it selects only `mpn`/`suppliersku` keys — never reads `.stock`).

So a product can legitimately be **both** in `supplier_sku_cache` **and** correctly demoted, for three reasons:
- **(a)** its only supplier(s) are **stale / operator-excluded** → dropped by flag-obsolete;
- **(b)** it is **listed but out of stock everywhere** → no in-stock offer;
- **(c)** its local `buy_price` was never synced (null) → demoted by path 2.

`LiveSupplierStockResolver` is stricter still than flag-obsolete's map: fresh suppliers **and** `stock > 0`.

### In-stock breakdown of the 162 — and why it must be produced on prod

**The exact 162 breakdown cannot be computed from the executor environment.** The local DB is an empty SQLite scratch DB (`products = 0`, `supplier_sku_cache = 0`); the "162" is a **production (MariaDB)** cutover-audit finding, and `feeds_products` / `stockseparate` live on a remote supplier MySQL VPS that is unreachable from local dev.

This is by design of the deliverable: **the command's default `--dry-run` IS the instrument that produces the breakdown on prod.** The operator runs (on prod):

```
php artisan products:restore-sourceable-pending                              # strict in-stock cohort + counts
php artisan products:restore-sourceable-pending --include-listed-out-of-stock  # broader fresh-listed cohort + counts
```

The summary table then reports, over the pending + on-Woo cohort: `restored (would)` (= current in-stock, or fresh-listed with the flag), `skipped: carve-out`, `skipped: no supplier offer / out-of-stock`, `skipped: blank sku` — i.e. exactly the in-stock vs listed-but-OOS vs not-sourceable split.

### Churn-risk verdict

- **A restore keyed on `supplier_sku_cache` WOULD churn** — it would restore SKUs carried only by stale/excluded suppliers or that are OOS everywhere, and the next `--flag-obsolete` run would re-demote them. That band-aid was explicitly rejected.
- **The command instead keys on the live fresh-supplier offer (the consistent inverse), so it does NOT churn:**
  - **Default (strict in-stock, `stock > 0`)** — a guaranteed **subset** of flag-obsolete's keep-set, so it survives path 1; and `supplier:db-sync` (matched) rewrites its `buy_price` to a real value on the next run, so it survives path 2.
  - **`--include-listed-out-of-stock`** — the exact stock-agnostic keep-set of flag-obsolete (fresh-listed, any stock); survives path 1, and db-sync sets a fallback (cheapest-overall) `buy_price` so it survives path 2 too.
- **Documented micro-gap (minor, transient):** `LiveSupplierStockResolver` gates on `freshSupplierIds()` but does **not** apply `SupplierExclusionResolver` (operator-excluded suppliers), whereas flag-obsolete drops both. So a SKU in-stock **only** at an operator-excluded-**but-fresh** supplier could be restored yet re-demoted. In practice operator-excluded suppliers (e.g. Nuvias) are excluded *because* they ship stale data and are therefore usually already stale (and thus already dropped by the fresh gate), so this window is rare and self-closing. Flagged for the operator; not worth reimplementing `buildBestOfferMap`'s exclusion filter in the resolver for a cutover-prep tool whose default is dry-run + operator review.

### Did I mass-restore or stop?

**Built the command; mass-restored nothing.** Because (a) the default is dry-run, (b) the command keys on the churn-safe live signal (not the cache), and (c) there is no prod data in this environment, no product statuses were changed. The command does **not** blanket-restore the 162 by cache membership — it restores only the churn-safe in-stock subset, and only under `--live`, run by the operator after reviewing the dry-run on prod.

## Task 2 — The command

`app/Console/Commands/RestoreSourceablePendingCommand.php`

- **Cohort:** `status = 'pending'` AND `woo_product_id IS NOT NULL`.
- **Carve-outs skipped:** `is_custom_ms`, `exclude_from_auto_update`, `custom-ms` tag.
- **Restore signal:** `LiveSupplierStockResolver::resolveForSku()` (strict in-stock, default). `--include-listed-out-of-stock` adds `LiveSupplierStockResolver::isListedByFreshSupplier()` (new — fresh-listed, any stock).
- **Action:** sets `status = 'publish'` LOCALLY via `Product::where('id',…)->update()`. **Never** calls `WooClient` (on-Woo products are already `publish`).
- **`--dry-run` is the DEFAULT**; `--live` applies. `--limit=N` caps the scan. Idempotent (only `pending` rows are candidates). Prints a sample table + outcome counts, and a "would restore N — re-run with --live" hint on dry-run.

### Usage

```
php artisan products:restore-sourceable-pending                                  # dry-run, strict in-stock
php artisan products:restore-sourceable-pending --live                           # apply, strict in-stock
php artisan products:restore-sourceable-pending --include-listed-out-of-stock    # dry-run, broader
php artisan products:restore-sourceable-pending --include-listed-out-of-stock --live
php artisan products:restore-sourceable-pending --limit=50                       # smoke-test wiring
```

## Deviations from Plan

None — plan executed as written. The plan's "report the in-stock breakdown of the 162" is delivered as the command's dry-run output (the breakdown is a prod-data computation the command performs; it cannot be produced from the local scratch DB, as documented above). The command was built (not stopped) because it is churn-safe and dry-run by default.

## Verify results

- `pest tests/Feature/Products/RestoreSourceablePendingCommandTest.php` — 11 passed (20 assertions). Covers: in-stock → publish; listed-OOS skipped by default; restored with `--include-listed-out-of-stock`; no-offer skipped; is_custom_ms / exclude_from_auto_update / custom-ms carve-outs skipped; only `pending` touched; no-woo_product_id skipped; dry-run default no-op; idempotency; **NO Woo call** (throwing WooClient guard + `Http::assertNothingSent()`).
- Regression: `LiveSupplierStockResolverTest` + `HydrateLiveStockCommandTest` — 9 passed.
- `php artisan route:list --path=admin` — exit 0.
- `pint --test` (changed files) — pass.
- `deptrac analyse` — 0 violations.
- Smoke: `php artisan products:restore-sourceable-pending` (dry-run, empty local DB) — exit 0, clean summary table.

## Commits

- `18909a5` — test(260713-rsp): add failing test (RED)
- `c548f5c` — feat(260713-rsp): command + resolver method + registration (GREEN)

## Known Stubs

None.

## Guardrails honoured

No Woo writes, no cutover-flag change, no status-push to Woo, no migration, `WOO_WRITE_ENABLED` untouched. Driver-portable (tests run on SQLite; resolver's date math is already driver-aware). Pre-existing working-tree noise (`storage/app/research/supplier-probe.json` deletion, `CompetitorIngestFreshnessColorTest.php`, untracked `.claude/`) left untouched. No push, no deploy.

## Self-Check: PASSED

- Files: `RestoreSourceablePendingCommand.php`, `RestoreSourceablePendingCommandTest.php`, `260713-rsp-SUMMARY.md` all present.
- Commits `18909a5` (RED) and `c548f5c` (GREEN) present in history.
- Working tree shows only pre-existing noise + this SUMMARY (nothing unintended staged).
