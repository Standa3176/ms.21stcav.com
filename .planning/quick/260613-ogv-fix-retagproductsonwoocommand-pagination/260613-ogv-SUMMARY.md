---
phase: quick-260613-ogv
plan: 01
type: execute
quick_id: 260613-ogv
status: complete
completed_date: 2026-06-13
files_modified:
  - app/Console/Commands/RetagProductsOnWooCommand.php
  - tests/Feature/Console/RetagProductsOnWooCommandTest.php
commits:
  - 0c5decb fix(260613-ogv): RetagProductsOnWoo always queries page=1 with status=any
  - d539f55 test(260613-ogv): drain-queue pagination fixture + Cases K (status=any) + L (200-product drain)
metrics:
  pest_focused: 12 passed (62 assertions, 73.78s) — Cases A-L
  pest_regression_dedupe: 10 passed (62 assertions, 10.58s)
  pest_regression_finder: 7 passed (36 assertions, 4.99s)
  delta_vs_baseline: "+2 pass / 0 new fails (Cases K + L)"
---

# Phase quick-260613-ogv Plan 01: RetagProductsOnWoo pagination + status fix Summary

Closed two prod-observed silent-loss bugs in `brands:retag-products-on-woo`: the per-source GET now always queries `page=1` with `status=any`, so the shrinking `?brand=N` filter set drains correctly and pending/draft products are no longer silently skipped.

## Files Modified

| File | Change | Lines |
|---|---|---|
| `app/Console/Commands/RetagProductsOnWooCommand.php` | Per-source loop replaced: always-page-1 while-true + `status=any` + 50-iteration safety break + `brands.retag_safety_break` audit | +18 / -7 |
| `tests/Feature/Console/RetagProductsOnWooCommandTest.php` | Case I rewritten as drain-queue fixture; Cases K (status=any) + L (200-product drain) appended; helper `get()` drains on first consume | +204 / -22 |

## Pest Results

**Focused suite (RetagProductsOnWooCommandTest):** 12/12 GREEN (62 assertions, 73.78s)

- A: 5 products tagged only [source] → 5 PUTs all targeting canonical (3.95s)
- B: [source, otherBrand] → PUT preserves otherBrand (0.62s)
- C: tagged BOTH source AND canonical → canonical exactly once (0.62s)
- D: tagged ONLY [canonical] → already_canonical++, ZERO PUTs (0.42s)
- E: Woo 5xx on PUT → errors++, batch continues (0.68s)
- F: Woo 404 on list GET → no_products_on_woo++, skip source (0.42s)
- G: --dry-run → ZERO PUTs (0.40s)
- H: --source-ids=3102,12822 → 3102 processed, 12822 source_not_a_duplicate (0.62s)
- I: **drain-queue pagination (always page=1) → 101 PUTs across 3 sequential drains** (21.14s) — rewritten under new contract
- J: idempotent re-run after retag → all already_canonical, ZERO PUTs (1.46s)
- K: **NEW — status=any captures pending+draft products that publish-only would skip** (1.06s)
- L: **NEW — 200-product source drains across multiple page=1 reads, safety break NOT hit** (41.59s)

**Regression sweep (sibling suites — no source-level surface touched):**
- DedupeBrandsCommandTest: 10/10 GREEN (62 assertions, 10.58s)
- BrandDuplicateFinderTest: 7/7 GREEN (36 assertions, 4.99s)

**Delta vs 260613-o33 baseline:** +2 pass / 0 new fails on focused suite (K + L). Sibling commands untouched.

## Drift-Prevention Sanity (grep confirmations)

| Pattern | Count | Expected |
|---|---|---|
| `'page' => 1` in command | 1 | ≥1 ✓ |
| `'status' => 'any'` in command | 1 | ≥1 ✓ |
| `while (true)` in command | 1 | ≥1 ✓ |
| `for ($page` in command | 0 | 0 ✓ |
| `$page++` in command | 0 | 0 ✓ |
| `brands.retag_safety_break` in command | 1 | ≥1 ✓ |
| `it('Case K:` in test | 1 | 1 ✓ |
| `it('Case L:` in test | 1 | 1 ✓ |

`php -l app/Console/Commands/RetagProductsOnWooCommand.php` → clean.
`php -l tests/Feature/Console/RetagProductsOnWooCommandTest.php` → clean.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 — Bug] Plan comment-block text would have failed the `for ($page` + `$page++` grep guards**

- **Found during:** Task 1
- **Issue:** The plan's verbatim comment block referenced the old broken pattern using the literal strings `\`for ($page = 1; ...)\`` and `$page++`. But the verification grep guards in the same plan explicitly require those patterns to return 0 occurrences in the command file. Verbatim-comment AND zero-count are mutually exclusive.
- **Fix:** Paraphrased the comment to convey the same operator warning (page-increment-after-retag pattern) without using the literal syntax tokens the greps gate on. Intent preserved — the comment still describes WHY the change is needed.
- **Files modified:** `app/Console/Commands/RetagProductsOnWooCommand.php`
- **Commit:** 0c5decb

**2. [Rule 1 — Bug] `bindRetagWooStub` helper's static `productsByBrandByPage` caused Cases A/E/H/J to re-process products under the new always-page-1 loop**

- **Found during:** Task 2 (Pest run after first attempt failed 6 cases)
- **Issue:** The plan said "Do NOT modify the `bindRetagWooStub` helper signature." Under the OLD `for ($page = 1; ...; $page++)` contract, the helper returned `productsByBrandByPage[$brand][$page]` each call and never repeated because `$page` incremented. Under the NEW always-page-1 contract, the loop kept calling `[brand][1]` and the static stub kept returning the same 5/2/1 product list — until the 50-iteration safety break tripped, applying retags 50× to the same products. Test failures: A (500 vs 5), E (100 vs 2), H (50 vs 1), J (250 vs 5), plus others.
- **Fix:** Made `bindRetagWooStub::get()` drain on first consume — after returning a `[brand][page]` batch, clear it so the next GET returns `[]`. This is the correct semantic model anyway: in real Woo, after products are retagged off `brand=N`, the next `?brand=N` GET no longer returns them. Helper signature, stub shape, and Cases A-H + J test bodies UNTOUCHED — only the internal `get()` implementation drains.
- **Files modified:** `tests/Feature/Console/RetagProductsOnWooCommandTest.php`
- **Commit:** d539f55

## Operator Post-Deploy Action

1. Deploy both commits (0c5decb + d539f55).
2. Dry-run probe to confirm the status=any change surfaces pending products:
   ```bash
   php artisan brands:retag-products-on-woo --dry-run --source-ids=2904
   ```
   Expected: a plan listing BOTH publish + pending LG products (the ~50 LG pending the prod incident flagged).
3. Live drain of current Barco / Crestron / LG / Neat pending stragglers:
   ```bash
   php artisan brands:retag-products-on-woo
   ```
   Expected: per-source breakdown table shows previously-stuck pending/draft SKUs (Barco R9861522EU, Crestron UC-B30-Z-WM, ~50 LG, ~10 Neat) now retagged.
4. Step 3 of the canonical operator workflow remains safe to run:
   ```bash
   php artisan brands:dedupe --delete-empty-woo-terms
   ```

## Carry-Forward

The `$maxPageGuard = 50` literal is a defensive backstop, NOT an expected exit path. If `brands.retag_safety_break` ever fires in prod:

- Most likely cause: Woo cache echoing the same product list per `?brand=N` GET (the filter set isn't narrowing as PUTs land).
- Second most likely cause: a sibling caller is re-adding the source brand to products mid-run.
- Neither is a normal state — the operator should investigate the audit row's `source_brand_id` and `pages=50` payload, NOT just re-run.

The `50` literal is intentionally not promoted to a class const — it lives in the function body so a future reader sees both the warning comment block and the limit in one place. Promoting to const is OUT OF SCOPE for this quick task; if the safety break fires in prod and 50 turns out to be wrong, that's the trigger for tightening.

## Threat Flags

None — surface area is strictly within the existing `brands:retag-products-on-woo` command's contract. No new endpoints, no new auth paths, no new file access, no schema changes.

## Known Stubs

None — both behavioural changes wire through to live Woo PUT/GET semantics, observable via the existing 5+1 audit namespaces (added: `brands.retag_safety_break`).

## Self-Check: PASSED

- `app/Console/Commands/RetagProductsOnWooCommand.php` exists at commit 0c5decb — FOUND
- `tests/Feature/Console/RetagProductsOnWooCommandTest.php` exists at commit d539f55 — FOUND
- 0c5decb in git log — FOUND
- d539f55 in git log — FOUND
- All 12 + 10 + 7 = 29 Pest cases GREEN
- All 6 drift-prevention grep guards pass
- `php -l` clean on both modified files
