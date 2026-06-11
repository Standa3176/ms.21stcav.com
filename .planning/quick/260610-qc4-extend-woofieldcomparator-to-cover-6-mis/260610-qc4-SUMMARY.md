---
quick_id: 260610-qc4
type: quick
status: shipped
created: 2026-06-10
completed: 2026-06-11
title: Extend WooFieldComparator to cover 6 missed fields
commits:
  - 8e52b78  # feat(cutover): WooFieldComparator covers stock + buy_price + category + brand + ean (260610-qc4)
  - cccffe5  # test(architecture): forbid silent removal of WooFieldComparator field comparisons (260610-qc4)
  - 29066e4  # test(cutover): WooFieldComparator 7 new field-coverage cases A-G (260610-qc4)
files-created:
  - tests/Architecture/DivergenceComparatorCoverageTest.php
  - tests/Feature/Cutover/WooFieldComparatorTest.php
files-modified:
  - app/Domain/Cutover/Services/WooFieldComparator.php
  - tests/Feature/Cutover/DivergenceScanCommandTest.php
suite-delta:
  baseline: 1978 / 222 / 3   # from 260609-rie
  after:    1989 / 222 / 3
  delta:    "+11 pass / 0 new fails / 0 new skips"
related_quicks:
  - 260606-c4o  # EnvUsageTest arch pattern mirrored
  - 260609-rie  # StockSeparateJoinTest arch pattern mirrored
  - 260609-nku  # phantom-stock audit (stock_quantity blind spot)
  - 260607-cgd  # EAN + brand backfill
  - 260607-v5g  # category_id backfill
---

# 260610-qc4 — Extend WooFieldComparator to cover 6 missed fields — SUMMARY

## One-liner

Closed the 2026-05-30 cutover's divergence-scan blind spot — WooFieldComparator
now covers all 13 source-of-truth fields (was 7, of which `meta_description`
was documented but unimplemented). Pre-cutover scans will now catch
stock/brand/EAN/category divergences that were customer-discovered post-cutover.

## What shipped

Three atomic commits on `main`:

| Commit  | Type | Purpose |
|---------|------|---------|
| 8e52b78 | feat(cutover) | Extended `WooFieldComparator::diff()` with 6 new comparison blocks + closed pre-existing meta_description gap; added 3 private helpers; updated DivergenceScanCommandTest fixtures to include `stock_status` (real Woo shape). |
| cccffe5 | test(architecture) | New `DivergenceComparatorCoverageTest` — file-scan + meta-assertion preventing silent removal of any of the 13 field comparisons. Mirrors EnvUsageTest + StockSeparateJoinTest patterns. |
| 29066e4 | test(cutover)      | New `WooFieldComparatorTest` — 9 cases (A-F per new field, G deferred-fallback contract, regression, sentinel) covering the 3-state matrix (match / mismatch / Woo-absent) per defensive contract. |

## Fields the comparator now covers (13)

Original 7 (Phase 7 Plan 05):
- `name`, `slug`, `short_description`, `long_description`, `meta_description` (newly implemented this quick), `sell_price`, `image_url`

New 6 (260610-qc4):
- `stock_quantity`, `stock_status`, `buy_price`, `category_id`, `brand_id`, `ean`

Plus the `exists` sentinel (emitted when Woo response is null/empty).

## Defensive contract

**Meta-only fields silent-skip** when Woo lacks the meta key:
- `buy_price` — `_alg_wc_cog_cost` (Algoritmika WC COG plugin)
- `brand_id` — `_product_brand_id`
- `ean` — walks `_global_unique_id` → `_ean` → `_alg_ean`
- `meta_description` — `_yoast_wpseo_metadesc` (Yoast SEO)

Rationale: most Woo installs don't carry these plugins. Emitting a diff for
every absence would flood `sync_diffs` with thousands of false positives on the
next live scan and bury the real divergences.

**Canonical Woo fields emit on absence** when local has a value:
- `stock_quantity`, `stock_status`, `category_id`

Rationale: those are canonical Woo top-level columns. Absence is meaningful —
Woo exists in a degenerate state (no stock managed, no category assigned). That
IS a divergence the cutover scan must see (260609-nku Ergotron HA310-2EP class).

## Deferred enhancement

**`pa_brand` attribute fallback for `brand_id`** — Woo legacy installs may
carry brand only via the global `pa_brand` attribute (which holds a NAME, not
a term id). Resolving pa_brand → id requires injecting `TaxonomyResolver`
(HTTP dependency), breaking the comparator's pure-function contract.

Case G of `WooFieldComparatorTest` asserts the comparator deliberately stays
silent on a pa_brand-only response. A follow-up quick can wire
`TaxonomyResolver` if pa_brand-only divergence becomes a real cutover-validation
signal post-rollout.

## Deviations from plan

### Auto-fixed Issues

**1. [Rule 1 — Bug] Closed pre-existing `meta_description` implementation gap**
- **Found during:** Task 2 — sanity-grep field-literal count.
- **Issue:** Phase 7 Plan 05's `WooFieldComparator` docblock declared
  `Product->meta_description ↔ woo['meta_data']` but the `diff()` body never
  had a comparison block for it. The original 7-field claim was actually 6.
- **Fix:** Added a `meta_description` comparison block using
  `_yoast_wpseo_metadesc` meta key (Yoast SEO canonical slot — confirmed in
  `PublishProductJob.php:280`). Treats as meta-only field — silent-skip when
  Yoast key absent. Updated docblock column-mapping line.
- **Files modified:** `app/Domain/Cutover/Services/WooFieldComparator.php`
- **Commit:** 8e52b78

**2. [Rule 1 — Bug] Updated DivergenceScanCommandTest IDENT-1 + PAR-1 fixtures**
- **Found during:** Task 2 verify — regression tests failed because the
  fixtures' Woo responses omitted `stock_status`, but `ProductFactory` defaults
  `stock_status='instock'`. The new canonical-absence contract correctly emitted
  a `stock_status` diff (which is the correct behaviour for the contract).
- **Fix:** Extended IDENT-1 and PAR-1 Woo response fixtures with
  `stock_status => 'instock'` to match real Woo REST shape (Woo always returns
  this top-level column — the original minimal fixtures predated the
  canonical-absence contract).
- **Files modified:** `tests/Feature/Cutover/DivergenceScanCommandTest.php`
- **Commit:** 8e52b78
- **Plan note:** The plan flagged this exact failure mode in Task 2's verify
  section ("D3's 'identical product, no diff' assertion will catch any new
  false-positive from the new comparisons leaking into the fixture's
  default-shape product"). The cleanest fix per plan intent — make the fixtures
  honest about real Woo shape — was applied.

### Constraints Honored

- HONOURED — `_alg_wc_cog_cost` (NOT `_wc_cog_cost`) used for buy_price.
- HONOURED — `WooFieldComparatorTest.php` created fresh (file did NOT exist).
- HONOURED — pa_brand attribute fallback deferred (Case G asserts silent-skip).
- HONOURED — `DivergenceScanner.php` unchanged.
- HONOURED — Canonical Woo fields emit on absence; meta-only fields silent-skip.
- HONOURED — Arch test failure message literally contains the required
  drift-prevention contract breadcrumb.
- HONOURED — No `env()` calls anywhere.

## Verification

| Step | Command | Result |
|------|---------|--------|
| 1    | `pest tests/Feature/Cutover/WooFieldComparatorTest.php`                              | 9/9 GREEN  |
| 1    | `pest tests/Architecture/DivergenceComparatorCoverageTest.php`                       | 2/2 GREEN  |
| 2    | `pest tests/Feature/Cutover/DivergenceScanCommandTest.php`                           | 9/9 GREEN  |
| 3    | `pest tests/Architecture/EnvUsageTest.php`                                           | 3/3 GREEN  |
| 3    | `pest tests/Architecture/StockSeparateJoinTest.php`                                  | 3/3 GREEN  |
| 3    | `pest tests/Architecture/AutoCreatedPredicateTest.php`                               | 2/2 GREEN  |
| 4    | `pest` (full suite)                                                                  | **1989 passed / 222 failed / 3 skipped** vs baseline 1978/222/3 → **+11/0/0** |
| 5    | `artisan list | grep cutover:divergence-scan`                                        | registered |
| 6    | `php -l WooFieldComparator.php`                                                      | clean      |
| 7    | field-literal count                                                                  | 14 (13 fields + `exists`) — one emission per field |

## Post-deploy operator action

After this commit lands on prod, run:

```bash
php artisan cutover:divergence-scan --live
```

against meetingstore.co.uk Woo. Expect significant `brand_id` and `ean` diff
volume (matches 260607-cgd backfill scope — 82.4% brand NULL, 89% Google
Merchant disapproval pre-backfill). Also expect `stock_quantity` /
`stock_status` divergences for any product Woo manages but MS has stale state
on (260609-nku Ergotron class).

Review via:
- Filament `/admin/sync-diffs`, OR
- Tinker: `SyncDiff::where('provider', 'divergence-scan')->whereDate('created_at', today())->count()`

This is a 30-60 minute scan over 3,922 products. Operator action only — not
part of this task's verify gate.

## Self-Check: PASSED

Verified files exist on disk:
- `app/Domain/Cutover/Services/WooFieldComparator.php` — FOUND
- `tests/Architecture/DivergenceComparatorCoverageTest.php` — FOUND
- `tests/Feature/Cutover/WooFieldComparatorTest.php` — FOUND
- `tests/Feature/Cutover/DivergenceScanCommandTest.php` — FOUND

Verified commits exist on `main`:
- 8e52b78 — FOUND
- cccffe5 — FOUND
- 29066e4 — FOUND
