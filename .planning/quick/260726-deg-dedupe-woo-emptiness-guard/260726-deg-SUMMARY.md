# 260726-deg — Fail-safe Woo emptiness guard on `brands:dedupe --delete-empty-woo-terms` — Summary

**One-liner:** Phase B of `DedupeBrandsCommand` can no longer force-delete a Woo `product_brand`
term that still holds live products — every delete is now gated behind a live
`GET products?brand=<id>&per_page=1&status=any` read that must PROVE the term empty, and
fail-safe-skips on any uncertainty.

## Why

The 260726-bwa audit proved on live prod that source `-brand` terms still hold up to 285
products each (yealink 285, logitech 176, samsung 163, lenovo 71, lg 4) that the LOCAL
`products.brand_id` view cannot see — `product_brand` is many-to-many on Woo, but the local
`brand_id` is single-valued. Phase A "reassigning" a term locally therefore does **not** empty
the Woo term. The old Phase B force-deleted EVERY source term based only on Phase A, so
`brands:dedupe --delete-empty-woo-terms` run without `brands:retag-products-on-woo` first would
have stripped the brand off ~699 live products. This task makes that delete impossible to fire
against a non-empty Woo term.

## What changed

- **`app/Console/Commands/DedupeBrandsCommand.php`**
  - New `wooTermEmptinessStatus(int $sourceId): string` — READ-ONLY guard (one GET, zero writes)
    returning `TERM_EMPTY` / `TERM_HAS_PRODUCTS` / `TERM_CHECK_FAILED`.
  - New `isMissingTermError(\Throwable): bool` — extracted the existing 404 idiom
    (`getCode()===404 || 'term does not exist' || 'rest_term_invalid'`), now shared between the
    guard read and the delete catch.
  - Phase B loop rewritten to branch on the guard verdict:
    - `TERM_HAS_PRODUCTS` ⇒ skip delete, `skipped_non_empty++`, audit
      `brands.dedupe_woo_term_not_empty_skipped`.
    - `TERM_CHECK_FAILED` (non-404 error on the read) ⇒ fail-safe skip, `skipped_non_empty++`,
      audit `brands.dedupe_woo_emptiness_check_failed`. **Never delete on uncertainty.**
    - `TERM_EMPTY` (read returned `[]` **or** 404 = term already gone) ⇒ existing delete runs
      unchanged; a 404 on the delete still lands on `already_deleted`.
  - Exactly one `usleep(WOO_DELETE_THROTTLE_USEC)` per source iteration (moved to the loop tail;
    no double pacing).
  - `skipped_non_empty` added to the final counter table.
  - Dry-run Section 3 now calls the SAME guard read (READ-ONLY) and renders `Live Woo products`
    + `Will delete?` (`SKIP …` vs `yes (empty, force=true)`) per term instead of the old blanket
    `yes (force=true)`.
  - Docblock corrected: canonical selection is **slug-rank via `BrandDuplicateFinder`** (not the
    stale "highest Woo `count` DESC"); added the mandatory operator order
    (`brands:retag-products-on-woo` → `brands:dedupe --delete-empty-woo-terms`) and documented the
    guard.

- **`tests/Feature/Console/DedupeBrandsCommandTest.php`**
  - Stub `bindWooBrandsStub()` extended with `$productsByBrand` (live membership for the guard read)
    and `$emptinessCheckBehaviour` (make the read throw 404/5xx). `get()` now handles the
    `products` endpoint.
  - 5 new cases: K (non-empty ⇒ not deleted), L (empty ⇒ still deleted, regression), M (non-404 ⇒
    fail-safe skip), N (404 ⇒ delete proceeds ⇒ already_deleted), O (dry-run does GET reads but
    zero deletes; Section 3 shows skip-vs-delete).

## TDD RED → GREEN

- **RED** (`2ee2bff`): added the 5 guard cases + stub extension. Against the current unguarded
  command, cases **K, M, O genuinely FAILED** — K/M showed a real `DELETE products/brands/11`
  call fired against the populated term (`deleteCalls` non-empty), and O showed no `products` GET
  read in dry-run. L/N passed as regressions. Confirmed failing before any implementation.
- **GREEN** (`9b24268`): implemented the guard; all **15 cases pass** (95 assertions).

## Guard semantics (safety contract)

| Guard read outcome | Verdict | Delete? | Counter / audit |
|---|---|---|---|
| returns ≥1 product | `TERM_HAS_PRODUCTS` | **NO** | `skipped_non_empty`, `brands.dedupe_woo_term_not_empty_skipped` |
| throws non-404 | `TERM_CHECK_FAILED` | **NO** (fail-safe) | `skipped_non_empty`, `brands.dedupe_woo_emptiness_check_failed` |
| returns `[]` | `TERM_EMPTY` | yes | `woo_terms_deleted` (or `already_deleted` on 404) |
| throws 404 (term gone) | `TERM_EMPTY` | yes → 404 | `already_deleted` |

## Confirmed safe operator sequence

1. `php artisan brands:retag-products-on-woo` — moves live Woo product membership source→canonical
   (this is what actually empties the source terms on Woo).
2. Re-audit (260726-bwa) — confirm the source terms now show **0** live products.
3. `php artisan brands:dedupe --delete-empty-woo-terms` — deletes the now-empty terms; the guard
   hard-blocks deletion of any term still holding products.

## Verification

- `pest tests/Feature/Console/DedupeBrandsCommandTest.php` → **15 passed, 95 assertions**.
- `pint app/Console/Commands/DedupeBrandsCommand.php tests/Feature/Console/DedupeBrandsCommandTest.php`
  → `{"result":"pass"}`.
- `deptrac analyse` → **0 violations, 0 errors** (only pre-existing phar-level `TableStyle`
  deprecation notices).
- `artisan route:list --path=admin` → **exit 0**.

## Constraints honoured

- No changes to `BrandDuplicateFinder`, `RetagProductsOnWooCommand`, or `WooClient`. No migration.
  No `WOO_WRITE_ENABLED` change. All Woo interaction via `WooClient` (GET only for the guard).
- Driver-portable SQL (no raw SQL added). No raw HTTP.
- No push, no deploy, no live artisan command against prod.
- Pre-existing working-tree noise left unstaged: `storage/app/research/supplier-probe.json`,
  `tests/Unit/Competitor/CompetitorIngestFreshnessColorTest.php`, untracked `.claude/`.

## Deviations from Plan

None — plan executed exactly as written.

## Commits

- `2ee2bff` — test(quick-260726-deg): RED — Phase B must never delete a non-empty Woo term
- `9b24268` — feat(quick-260726-deg): fail-safe live Woo emptiness guard on Phase B delete

## Self-Check: PASSED

- `app/Console/Commands/DedupeBrandsCommand.php` — FOUND (modified, committed in 9b24268)
- `tests/Feature/Console/DedupeBrandsCommandTest.php` — FOUND (committed in 2ee2bff)
- `260726-deg-SUMMARY.md` — FOUND
- Commit `2ee2bff` — FOUND in git log
- Commit `9b24268` — FOUND in git log
