---
phase: 260630-c3q-generate-drafts-assign-taxonomy-pick-the
plan: 01
status: complete
---

# 260630-c3q — Source the real product supplier row (generate-drafts + assign-taxonomy)

## Problem
HD226 published to Woo (#181662) with the WRONG identity — title "Protect Plus HD226 …" and NO brand.
The 260629-rct filter fix let HD226 through as BrightSign, but `products:generate-drafts` and
`products:assign-taxonomy` each picked the supplier row via `ORDER BY updated_at DESC LIMIT 1`. HD226
has two `supplier_products` rows under the same MPN: the real BrightSign player (BSHD226, stock 118)
and a "Protect Plus" extended-warranty row (MSBSHD226, stock 0). The warranty row was updated more
recently, so it became the title + brand source.

## Fix
- New shared trait `app/Domain/ProductAutoCreate/Concerns/PrefersRealSupplierRow` with
  `pickBestSupplierRow(array $rows, callable $isBrand): ?array` — prefers (1) manufacturer that
  resolves to a Woo brand, (2) in-stock (stock>0), (3) input order (caller passes `updated_at DESC`
  so most-recent is the final tiebreak). Pure + unit-tested (7 cases incl. the HD226 case).
- `GenerateProductDraftsCommand`: added `stock` to the SELECT, `LIMIT 1` → `LIMIT 25`, `fetch_all` +
  `pickBestSupplierRow` before building `$facts` (isBrand = `$this->taxonomy->resolveBrand($m) !== null`).
- `AssignProductTaxonomyCommand::supplierManufacturers`: `SELECT manufacturer, stock`, `LIMIT 25`,
  `fetch_all` + `pickBestSupplierRow` → returns the best row's manufacturer.
- Single-row SKUs (the vast majority) behave byte-identically — one candidate is returned unchanged.

## Verification
- `pest tests/Unit/Domain/ProductAutoCreate/ tests/Unit/Console/ tests/Feature/Console/` → **165 passed
  (658 assertions)** — no regression (260628-b9t / 260629-pqh / 260629-rct tests all still green).
- `pint --test` on the trait + both commands → pass.

## Commits
- `a0bbae9` feat — PrefersRealSupplierRow trait + unit test (Task 1)
- `8c9c06f` fix — wire picker into generate-drafts + assign-taxonomy (Task 2)
- (this) docs — PLAN + SUMMARY + STATE row

NOTE: the background executor died mid-run after committing Task 1 + writing (uncommitted) the Task 2
edits; the edits were reviewed against the plan, verified (165 green / pint pass), and committed by the
main session.

## Operator steps (NOT executed here)
- Deploy: push main → on VPS `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh`.
- Clean up HD226 (live as #181662 with the Protect Plus name): re-run
  `php artisan products:draft-from-suggestions --skus=HD226 --auto-approve --no-confirm` — generate-drafts
  now sources BrightSign (correct title + brand), assign-taxonomy assigns BrightSign, PublishProductJob
  PUT-updates Woo #181662. Verify the storefront listing shows BrightSign + a clean title.
- Corrects every other product whose title/brand was sourced from a warranty/add-on row.

## Related
Fourth fix in the 2026-06-28/30 auto-create-pipeline arc: [[260628-b9t]] (Brand-Category suffix),
260629-pqh (skip reporting), 260629-rct (filter multi-mfr), this (content/brand multi-mfr). Auto-create
missing brands + buy-price stale-exclusion remain deferred.
