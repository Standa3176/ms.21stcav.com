# 260726-deg — Fail-safe Woo emptiness guard on `brands:dedupe --delete-empty-woo-terms`

**Type:** GSD quick task (TDD, atomic commits). Executor does NOT push/deploy.
**Why:** the 260726-bwa audit proved on live prod that the `-brand` source terms still hold up to
285 products each (yealink 285, logitech 176, samsung 163, lenovo 71, lg 4) that the LOCAL
`products.brand_id` view cannot see (`product_brand` is many-to-many on Woo; local brand_id is
single-valued). `DedupeBrandsCommand` Phase B (currently lines 240-292) force-deletes EVERY source
term via `WooClient::delete(..., ['force' => true])` based only on Phase A's local reassignment — so
`brands:dedupe --delete-empty-woo-terms` run without `brands:retag-products-on-woo` first would
force-delete those populated terms and strip the brand off ~699 live products. This task makes the
delete impossible to fire against a non-empty Woo term.

**Out of scope / DO NOT touch:** `BrandDuplicateFinder` (its slug-rank canonical pick is CORRECT — the
clean slug is the intended survivor; products are MOVED to it by the retag command, not deleted),
`RetagProductsOnWooCommand` (already hardened — 260613-ogv pagination + `status=any` fixes present),
`WooClient`. No migration. No `WOO_WRITE_ENABLED` change. No push, no deploy.

## Task 1 — Live Woo emptiness guard in Phase B (TDD)
In `DedupeBrandsCommand`, before each `products/brands/{sourceId}` DELETE:
- Query the term's LIVE Woo membership: `WooClient::get('products', ['brand'=>$sourceId,
  'per_page'=>1, 'status'=>'any'])`. Non-empty response ⇒ term still has products.
- If still-has-products ⇒ **skip the delete**, warn, increment a new `skipped_non_empty` counter, and
  record `brands.dedupe_woo_term_not_empty_skipped` (source_id, source_name, canonical_id).
- **Fail-safe on uncertainty:** if the emptiness GET throws a NON-404 error, treat the term as
  non-empty (skip + `brands.dedupe_woo_emptiness_check_failed` audit) — NEVER delete when we cannot
  prove empty. A 404 on the check (term already gone) ⇒ treat as empty and let the existing delete run
  (it will 404 → `already_deleted`, the desired idempotent end-state).
- Do NOT double the pacing: keep one `usleep(WOO_DELETE_THROTTLE_USEC)` per source iteration.
- Reuse the SAME 404-detection idiom already in this file (getCode()===404 || 'term does not exist' ||
  'rest_term_invalid') — extract a tiny private `isMissingTermError(\Throwable): bool` if it de-dups
  cleanly; otherwise inline consistently. No behaviour change to the existing delete/404 path beyond
  gating it behind the guard.
- Add `skipped_non_empty` to the final counter table.

## Task 2 — Reflect the guard in dry-run + fix stale docs
- Dry-run Section 3 ("Woo term deletes") currently prints `yes (force=true)` for every source. Make it
  call the SAME guard read so the operator sees the truth per term, e.g. a `Live Woo products` column +
  `Will delete?` = `SKIP (N products)` vs `yes (empty)`. Dry-run stays READ-ONLY (GET only, no delete).
- Fix the class docblock: line ~33 still says "Canonical selection: highest Woo `count` DESC" — that is
  STALE (moved to `BrandDuplicateFinder` slug-rank after the 2026-06-13 incident). Correct it and update
  the operator-workflow block to state the mandatory order: (1) `brands:retag-products-on-woo` moves Woo
  products source→canonical, (2) `brands:dedupe --delete-empty-woo-terms` deletes the now-empty terms,
  and that the guard hard-blocks deletion of any term still holding products.

## Verify
- `pest` (extend the existing DedupeBrands feature test, stubbing WooClient):
  - **RED-first:** a source term that Phase A "reassigned" locally but that STILL returns a product from
    the Woo `products?brand=` read is NOT deleted — assert no `DELETE products/brands/<id>` call, assert
    `skipped_non_empty` counter, assert the `brands.dedupe_woo_term_not_empty_skipped` audit row.
  - an EMPTY term (Woo read returns []) IS deleted exactly as today (regression — existing green
    behaviour preserved).
  - emptiness-check throws non-404 ⇒ term skipped (fail-safe), not deleted.
  - emptiness-check 404 ⇒ delete proceeds ⇒ existing `already_deleted` path still works.
  - dry-run performs GET reads but NO delete (assert zero delete calls) and the Section-3 output
    reflects skip-vs-delete.
- `php artisan route:list --path=admin` exit 0; `pint`; `vendor/bin/deptrac analyse` → 0 violations.

## Guardrails
- All Woo interaction via `WooClient` (throttle + shadow gate + audit) — no raw HTTP. Driver-portable.
- Do NOT stage the pre-existing working-tree noise (`storage/app/research/supplier-probe.json`,
  `tests/Unit/Competitor/CompetitorIngestFreshnessColorTest.php`, untracked `.claude/`).
- PHP/composer via Herd (~/.config/herd/bin/php84/php.exe). Atomic commits on `main`. No push, no deploy.
  Write `260726-deg-SUMMARY.md` with the guard semantics, the RED→GREEN test, and the confirmed safe
  operator sequence (retag → re-audit shows 0 → dedupe --delete-empty-woo-terms).
