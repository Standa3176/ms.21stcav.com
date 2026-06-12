---
phase: quick-260611-sr7
plan: 01
subsystem: products / data-quality
tags: [m-1, brand-backfill, taxonomy-resolver, single-source-of-truth, no-woo-writes]
requires:
  - app/Domain/ProductAutoCreate/Services/TaxonomyResolver.php
  - app/Console/Commands/BackfillMerchantFeedCommand.php
  - app/Domain/Products/Models/Product.php
provides:
  - app/Console/Commands/BackfillProductBrandFromNameCommand.php
  - tests/Feature/Console/BackfillProductBrandFromNameCommandTest.php
affects:
  - app/Providers/AppServiceProvider.php
tech_stack:
  added: []
  patterns:
    - Single-source-of-truth fuzzy match (TaxonomyResolver imported via DI; never re-implemented)
    - SKU-shaped first-token detection via `SKU_LIKE_PATTERN` private const with positive-lookahead digit/hyphen/# requirement
    - Per-product try/catch swallows resolver throws; batch continues with errors counter
    - Single-column `DB::table('products')->update(['brand_id'=>X, 'updated_at'=>now()])` mirrors BackfillMerchantFeedCommand::backfillBrand parity
key_files:
  created:
    - app/Console/Commands/BackfillProductBrandFromNameCommand.php
    - tests/Feature/Console/BackfillProductBrandFromNameCommandTest.php
  modified:
    - app/Providers/AppServiceProvider.php
    - .planning/STATE.md
decisions:
  - TaxonomyResolver::resolveBrand is single-arg (?string $brandName): ?int — NOT the brief's two-arg signature
  - --min-confidence CLI option is informational only, surfaced in banner; threshold stays owned by the resolver
  - SKU_LIKE_PATTERN requires at least one digit/hyphen/# via positive lookahead (so "Logitech" is NOT SKU-shaped)
  - DB::table()->update bypasses Eloquent → no activity_log row; operator inspection of /admin/products?brand_id=X is the agreed accountability surface
  - NO Woo writes; NO scheduled cron; one-shot operator-triggered
metrics:
  duration: ~45 min
  completed: 2026-06-12
  commits: 3
  pest_focused: 9/9 (35 assertions, 6.95s)
  pest_regression: 38/38 (198 assertions, 19.75s)
  full_suite_baseline: 2,033/222/3 (260611-s2d) — OOM caveat carried forward
---

# Phase quick-260611-sr7 Plan 01: Products Backfill Brand From Name Summary

## One-liner

`products:backfill-brand-from-name` closes the M-1 Phase 7 gap (3,231 of 3,922 live products / 82.4% NULL brand_id) by resolving the FIRST WORD of `Product.name` through `TaxonomyResolver::resolveBrand` — with SKU-shaped first tokens falling back to the SECOND word.

## Purpose

Lift downstream signal quality for AdCandidateScanner ad targeting, `/admin/category-audit` `BRAND_NATURAL_HOMES` rule comparison, the storefront product-detail `Brand:` link, and Google Merchant Center feed enrichment. The Phase 7 cutover divergence scan silent-skips `brand_id` meta on both sides (per 260610-qc4 contract), so this backfill is pure MS-side data quality with no parity-noise downstream.

## What landed

1. **`app/Console/Commands/BackfillProductBrandFromNameCommand.php`** — new artisan command extending `BaseCommand`, constructor DI on `TaxonomyResolver`. Signature:
   ```
   products:backfill-brand-from-name {--skus=} {--limit=0} {--min-confidence=0.85} {--dry-run}
   ```
   - Class-level private const `SKU_LIKE_PATTERN = '/^(?=.*[0-9\-#])[A-Z0-9][A-Z0-9\-#]+$/i'` + `MIN_SKU_LIKE_LENGTH = 6`.
   - Candidate query mirrors `BackfillMerchantFeedCommand::backfillBrand` exactly: `Product::query()->where('status','publish')->where(fn($q)=>$q->whereNull('brand_id')->orWhere('brand_id',0))`.
   - Per-product `DB::table('products')->where('id', $product->id)->update(['brand_id'=>$brandId, 'updated_at'=>now()])` single-column write.
   - Per-product try/catch swallows resolver throws to the `errors` counter; batch continues.
   - Output: counter table (scanned / resolved / unresolved / skipped_sku_prefix / errors) + top-30 unresolved candidates histogram (sorted hits DESC then candidate ASC) + 20-row sample table.
   - Run banner surfaces operator's `--min-confidence=X.XX` verbatim.

2. **`app/Providers/AppServiceProvider.php`** — added `use App\Console\Commands\BackfillProductBrandFromNameCommand;` import + inserted `BackfillProductBrandFromNameCommand::class` in `$this->commands([...])` adjacent to `BackfillMerchantFeedCommand::class` with a 3-line comment block describing the M-1 gap closure + no-Woo-writes guarantee.

3. **`tests/Feature/Console/BackfillProductBrandFromNameCommandTest.php`** — 8 Pest cases A-H + 1 architectural no-WooClient source-scan guard. Helper `bindStubTaxonomyResolver(array $brandIdByCandidate, ?string $throwTrigger = null)` binds an anonymous-subclass stub via `app()->instance(TaxonomyResolver::class, $stub)`. Baseline `beforeEach` covers Cases A, B, D, E, F, G; Cases C + H re-stub.

4. **`.planning/STATE.md`** — frontmatter rotation: 260611-s2d block moved to `old_stopped_at`; new `stopped_at` summarises 260611-sr7 with the post-deploy operator action sequence + Rule 1 fold-in note. `last_updated` + `last_activity` bumped to 2026-06-12. Appended a new row to the Quick Tasks Completed table.

## Pest cases

- **Case A** — Happy path Logitech (first-word) → brand_id=1.
- **Case B** — SKU-shaped first token "AV1E3AA#AC3" → fallback to "Poly" (second word) → brand_id=2; `skipped_sku_prefix` counter increments.
- **Case C** — Empty taxonomy map → `unresolved++`, candidate "Unknown" appears in top-30 histogram.
- **Case D** — `--skus=ABC-1` scopes to one product; sibling untouched.
- **Case E** — `--dry-run` writes nothing; all 5 products still NULL; output contains `dry-run` + `resolved`.
- **Case F** — Pre-existing `brand_id=99` row EXCLUDED by candidate query (stays 99); null-brand sibling backfilled to brand_id=4.
- **Case G** — `--min-confidence=0.95` surfaces verbatim in run banner.
- **Case H** — Forced throw on candidate "Bomb" → `errors=1`, `resolved=2`; H-1 + H-3 successfully backfilled.
- **Arch guard** — file source does NOT contain the literal `App\Domain\Sync\Services\WooClient` substring (no-Woo-writes invariant).

Result: **9/9 GREEN, 35 assertions, 6.95s.**

## Touched-area regression

`vendor\bin\pest tests/Feature/Console/BackfillMerchantFeedCommandTest.php tests/Feature/Console/BackfillCategoryFromWooCommandTest.php tests/Feature/Console/HydrateProductStockFromOffersCommandTest.php tests/Unit/Domain/Pricing/Services/AdCandidateScannerTest.php --colors=never`

Result: **38/38 PASS, 198 assertions, 19.75s.**

## Full-suite delta

Baseline 260611-s2d: 2,033 / 222 / 3.

Full-suite Pest invocation OOM'd at PHP 512MB before completion (pre-existing Windows herd PHP infrastructure issue, documented from 260611-qcq onwards — NOT introduced by 260611-sr7). Two pre-existing failures observed before OOM (`IntegrationHealthWidgetTest::computeAll integration_health` count-5-vs-10, `Phase02DataModelTest::rolls back 6 Phase-2 migrations`) — both UNRELATED to 260611-sr7 surface area (neither touches Product, BackfillProductBrandFromNameCommand, TaxonomyResolver, or any file in this plan's `files_modified` list).

Fall-back per plan: **focused/touched-area equivalent reconciliation confirms +9 pass / 0 new fails / 0 new risky** on the surface 260611-sr7 actually touches. OOM caveat acknowledged.

## Commits

| Hash      | Type       | Message                                                                |
| --------- | ---------- | ---------------------------------------------------------------------- |
| `d1df1d7` | feat       | backfill-brand-from-name command + registration (260611-sr7)           |
| `5c19c7a` | test       | backfill-brand-from-name Pest cases A-H (260611-sr7) + Rule 1 fold-in |
| `2f77a89` | docs(state)| 260611-sr7 backfill-brand-from-name shipped (260611-sr7)               |

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] SKU_LIKE_PATTERN false-positive on long alphabetic brand names**

- **Found during:** Task 3 — initial Case A (Logitech happy path) red.
- **Issue:** Original `SKU_LIKE_PATTERN = '/^[A-Z0-9][A-Z0-9\-#]+$/i'` matched pure-alphabetic 6+ char tokens like "Logitech" (8 chars), "Microsoft", "Vivitek", "Panasonic" as SKU-shaped. The engine then skipped the first word and tried the second word for resolution — "Logitech 4k conference camera 960-001503" tried "4k" (null) instead of "Logitech" (id=1).
- **Brief language:** "uppercase + digits + hyphen/#, length > 5" — the "digits + hyphen/#" requirement was always there; the implementation drifted.
- **Fix:** Positive lookahead `'/^(?=.*[0-9\-#])[A-Z0-9][A-Z0-9\-#]+$/i'` requires at least one digit, hyphen, or `#` anywhere in the token. "AV1E3AA#AC3" still matches (contains digits + `#`); "Logitech" no longer matches (pure letters). Single-pass regex.
- **Files modified:** `app/Console/Commands/BackfillProductBrandFromNameCommand.php` (SKU_LIKE_PATTERN const + docblock note).
- **Commit:** `5c19c7a` (folded into the test commit so the test that catches the bug ships alongside the fix).

## Probe corrections honoured

- TaxonomyResolver::resolveBrand is single-arg `(?string $brandName): ?int` — the brief's `resolveBrand($candidate, $minConfidence)` is WRONG.
- FUZZY_THRESHOLD = 0.85 is a private const owned by the resolver — NOT plumbed through.
- `--min-confidence` CLI option is informational only (surfaced verbatim in run banner) — the value never reaches the resolver. Source carries explicit comment.
- BackfillMerchantFeedCommand candidate query uses `whereNull OR brand_id=0` — mirrored exactly.
- AppServiceProvider registration site is inside `if ($this->app->runningInConsole())` block — confirmed.

## Untouched files (per scope guardrails)

- `app/Domain/ProductAutoCreate/Services/TaxonomyResolver.php` — UNTOUCHED.
- `app/Console/Commands/BackfillMerchantFeedCommand.php` — UNTOUCHED.
- `app/Console/Commands/BackfillCategoryFromWooCommand.php` — UNTOUCHED.
- `app/Console/Commands/HydrateProductStockFromOffersCommand.php` — UNTOUCHED.
- `git diff --name-only main~3..main` lists only the four files in `files_modified` plus `.planning/STATE.md`.

## What this does NOT do

- **No Woo writes.** `brand_id` is silent-skipped by `WooFieldComparator` (260610-qc4 meta-only contract) — both sides null, no parity diff. Operator decides if a follow-up Woo brand-id push is worth shipping.
- **No scheduled cron.** One-shot operator-triggered; the candidate count drops toward zero after the first live run.
- **No operator override for SKU_LIKE_PATTERN.** If false-positives surface in the field (e.g. legitimate brand names that contain digits + 6+ chars and accidentally match), tighten the const in a follow-up quick task.
- **No activity_log row per backfilled product.** `DB::table()->update` bypasses Eloquent. Documented in the command docblock; operator inspection of `/admin/products?brand_id=X` after the run is the agreed accountability surface for the M-1 closure event.

## POST-DEPLOY OPERATOR ACTION

1. Deploy all 3 commits.
2. `php artisan products:backfill-brand-from-name --dry-run` — confirms ~3,231 candidate plan + top-30 unresolved histogram + 20-row sample table.
3. Review the histogram for false-positive first-words (e.g. category-leading names like "USB cable Logitech ..." would surface "USB" as the unresolved candidate). Tighten SKU_LIKE_PATTERN in a follow-up if material.
4. `php artisan products:backfill-brand-from-name` — live run.
5. Spot-check `Product::whereNull('brand_id')->where('status','publish')->count()` — expect the count to drop from 3,231 toward the un-namable tail (likely a few hundred). The `Brand:` link on `/product/<sku>` should populate for thousands of newly-backfilled products.
6. `php artisan cutover:divergence-scan --live` re-emits sync_diffs — `brand_id` Woo-side will silent-skip per the 260610-qc4 meta-only contract, so the parity widget won't get noisier.

## Self-Check: PASSED

- [x] `app/Console/Commands/BackfillProductBrandFromNameCommand.php` exists (verified via Glob).
- [x] `tests/Feature/Console/BackfillProductBrandFromNameCommandTest.php` exists (verified via Pest run).
- [x] Commits `d1df1d7`, `5c19c7a`, `2f77a89` exist in `git log --oneline -5` (verified above).
- [x] `php artisan list | findstr backfill-brand-from-name` resolves the command (verified Task 2 + Task 4).
- [x] `SKU_LIKE_PATTERN` private const present in source for grep-discoverability.
- [x] `TaxonomyResolver` imported via constructor DI (`private readonly TaxonomyResolver $taxonomy`).
- [x] Pest 9/9 GREEN (8 cases A-H + 1 architectural guard).
- [x] Regression suites 38/38 GREEN.
- [x] STATE.md `stopped_at` summarises 260611-sr7.
