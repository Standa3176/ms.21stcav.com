---
quick_id: 260607-cgd
type: quick
mode: quick
status: complete
title: products:backfill-merchant-feed — backfill EAN/brand/category from supplier_db to lift Google Merchant Center disapproval rate
date: 2026-06-07
commits:
  - sha: da9bbdb
    message: "refactor(commands): extract normaliseEan() to NormalisesEan trait (260607-cgd)"
  - sha: e86fe74
    message: "feat(products): backfill-merchant-feed command (EAN path) (260607-cgd)"
  - sha: 1c35c30
    message: "feat(products): backfill-merchant-feed brand path via supplier_db.manufacturer + TaxonomyResolver fuzzy (260607-cgd)"
  - sha: 0f6af53
    message: "feat(products): backfill-merchant-feed category (Claude via assign-taxonomy) + --resync chain (260607-cgd)"
  - sha: 7da38a7
    message: "chore(commands): register products:backfill-merchant-feed (260607-cgd)"
files_modified:
  - app/Console/Concerns/NormalisesEan.php (new)
  - app/Console/Commands/GenerateProductDraftsCommand.php (retrofit)
  - app/Console/Commands/BackfillMerchantFeedCommand.php (new)
  - app/Providers/AppServiceProvider.php (registration)
  - tests/Unit/Console/Concerns/NormalisesEanTest.php (new)
  - tests/Feature/Console/BackfillMerchantFeedCommandTest.php (new)
---

# Quick Task 260607-cgd — products:backfill-merchant-feed

Shipped the `products:backfill-merchant-feed` artisan command to recover the 89% Google Merchant Center disapproval rate on live products (3,493 TRIPLE FAIL rows — no EAN + no brand_id + no category_id). The command pulls EAN + manufacturer from `supplier_db.feeds_products` (94% recoverable per today's diagnostic), fuzzy-matches manufacturer against the Woo brand taxonomy via `TaxonomyResolver`, and chains Claude category assignment via the existing `products:assign-taxonomy` command. Default behaviour is `--dry-run`; live runs are idempotent and optionally chain `products:resync-to-woo` on the SUCCESSFULLY UPDATED SKUs only.

## Per-task outcome

| # | Commit | Outcome |
|---|--------|---------|
| 1 | `da9bbdb` | NormalisesEan trait extracted from GenerateProductDraftsCommand:479-491. Visibility widened private → public so the new command can call it on `$this` (legal in PHP trait composition). 15 Pest behaviour cases green. Drift gate: `grep "private function normaliseEan" app/Console/Commands/` = 0. |
| 2 | `e86fe74` | BackfillMerchantFeedCommand created — EAN field path complete. Supplier_db mysqli lookup extracted to protected `lookupSupplierEans()` so the Pest test overrides via anonymous subclass + container binding (OPTION A; mirrors 260607-9c6 H-2 `runDumpCommand`). Class is non-final to support the subclass. 4 EAN feature cases green. |
| 3 | `1c35c30` | Brand field path added — supplier manufacturer → `TaxonomyResolver::resolveBrand()` fuzzy ≥ 0.85 → `products.brand_id`. New protected `lookupSupplierManufacturers()` mirrors the EAN-lookup shape. TaxonomyResolver fake bound via `app()->instance()` (anonymous subclass with skipped parent ctor so no WooClient needed). 3 brand feature cases green. FUZZY_THRESHOLD never duplicated in the command. |
| 4 | `0f6af53` | Category field path + finalised --resync chain. 50-SKU batches of `Artisan::call('products:assign-taxonomy')`. Cost banner ~1p/SKU shown BEFORE any Claude spend. Interactive y/N confirm unless `--no-confirm`; non-interactive runs without `--no-confirm` ABORT. Successfully-updated set = intersection of (was missing before this batch) ∩ (has category after) — correct attribution to this run only. |
| 5 | `7da38a7` | AppServiceProvider registers `BackfillMerchantFeedCommand::class` with quick-task comment header (matches established pattern flagged in 260606-c4o). `php artisan list \| grep backfill-merchant-feed` = 1 line. Pint formatting tweaks on the feature test folded in here. |

## Pest results

| Run | Pass | Fail | Skip | vs 260607-9c6 baseline |
|---|---|---|---|---|
| NormalisesEanTest (focused) | 15 | 0 | 0 | new |
| BackfillMerchantFeedCommandTest (focused) | 7 | 0 | 0 | new |
| EnvUsageTest + AutoCreatedPredicateTest (architecture) | 5 | 0 | 0 | unchanged |
| **Full Pest suite (1,272s)** | **1,867** | **219** | **3** | **+22 / 0 / 0** |

Baseline 260607-9c6 was 1,845 / 219 / 3. New delta = exactly the 22 cases (15 + 7) added by this task. **Zero new failures, zero new skipped.**

## Dry-run output shape (4-quadrant + 20-row sample)

Live dev smoke `php artisan products:backfill-merchant-feed --field=ean --dry-run --limit=10` against the dev DB connected to supplier_db:

```
=== EAN backfill ===
EAN backfill: 10 candidate products.

+----------------------------+-------+
| Outcome                    | Count |
+----------------------------+-------+
| would_update               | 0     |
| skipped_invalid_ean        | 9     |
| skipped_no_supplier_match  | 1     |
| already_populated_excluded | 0     |
+----------------------------+-------+

Sample (first 20):
+--------------+-------------------+---------------------------+
| SKU          | Candidate EAN     | Outcome                   |
+--------------+-------------------+---------------------------+
| DEMO-SKU-001 | (no supplier row) | skipped_no_supplier_match |
| B5NH6AA#ABU  |                   | skipped_invalid_ean       |
| B22X6AA#AC3  |                   | skipped_invalid_ean       |
| ...          |                   |                           |
+--------------+-------------------+---------------------------+

Dry-run — exiting EAN pass without writes.
```

**Dev smoke outcome: A (creds present + connected).** Most supplier rows on dev had empty EAN strings → all 9 fell into `skipped_invalid_ean` (the trait correctly returns null for empty input). On prod with the diagnostic's 3,778 valid supplier EANs, the bulk will fall into `would_update`. Note: the plan's verification step flagged the dev smoke as possibly hitting Connect failed; in practice the supplier_db credentials are reachable from this dev environment, so outcome A applies. If creds were absent the failure mode would be the documented `Supplier DB connect failed (errno=…)` exception with non-zero exit — captured here for the SUMMARY rather than aborting verification.

Brand path 4-quadrant on dry-run (test-verified shape):

```
+----------------------------------+-------+
| Outcome                          | Count |
+----------------------------------+-------+
| would_update                     | N     |
| skipped_fuzzy_below_threshold    | M     |
| skipped_no_supplier_manufacturer | K     |
| already_populated_excluded       | 0     |
+----------------------------------+-------+
```

## Prod usage recipe

Preview the EAN damage before spending anything:

```bash
php artisan products:backfill-merchant-feed --field=ean --dry-run
```

Free deterministic backfill (EAN from supplier_db.ean + brand from supplier_db.manufacturer via fuzzy ≥ 0.85) and push to Woo:

```bash
php artisan products:backfill-merchant-feed --field=ean,brand --resync
```

Claude-paid category backfill (~£35 for the full 3,490-SKU set at ~1p/SKU):

```bash
php artisan products:backfill-merchant-feed --field=category --resync
```

For cron / queue invocation (Claude path):

```bash
php artisan products:backfill-merchant-feed --field=category --resync --no-confirm
```

`--no-confirm` is REQUIRED for non-interactive runs of the category path (the live guard ABORTs without it — operator must explicitly opt in to spending Claude credit without a TTY).

## Expected prod impact

- **EAN backfill**: 3,778 supplier rows have valid EANs per today's diagnostic. Live products currently missing `ean` ≈ ~3,720. Post-backfill: ~226 still missing (94% recovery, the residual is products with no supplier row or with `N/A`-style placeholders).
- **Brand backfill**: 3,494 live products currently have `brand_id` null/0. Fuzzy-match % vs supplier manufacturer is TBD on prod — the Woo brand taxonomy has 100+ terms post-2026-05-31 native-taxonomy fix (73ac682), so the 0.85 threshold should catch the bulk of clean manufacturer names. False-positive risk is bounded by the threshold being calibrated UP to 0.85 specifically to reject Lindy/Linsn cross-letter-swap edges (the 2026-06-01 incident).
- **Category backfill**: ~3,490 candidates at ~1p/SKU = ~£35 of Claude spend. Cost banner + interactive confirm gate every run.
- **--resync chain**: only SUCCESSFULLY UPDATED SKUs are pushed to Woo (intersection of "was missing before this run" ∩ "populated after"). Never the candidate set, never legacy SKUs, never the whole catalogue. Once data lands on Woo, Google Listings & Ads re-feeds Merchant Center daily.

## Deviations

None. Plan executed as written, with two minor notes:

1. **Class is non-final** (vs the plan's `final class` wording) — required for the OPTION A Pest test pattern (anonymous subclass overrides `lookupSupplierEans` / `lookupSupplierManufacturers`). This matches the same precedent set by `TaxonomyResolver` itself and `WooDbSnapshotter` (both already non-final for testability per their docblocks).
2. **Pint formatting tweaks** to the new feature test (class_definition / braces_position / phpdoc_align) were folded into the Task 5 chore commit rather than amending the Task 2/3 history. Behaviour identical.

## Verification gate results (PLAN.md `<verification>`)

1. `php artisan list | grep products:backfill-merchant-feed` → 1 line ✓
2. Focused Pest (Unit Concerns + Feature Console + 2 Architecture) → all green ✓
3. Full Pest suite: 1,867 / 219 / 3 vs baseline 1,845 / 219 / 3 → ZERO new failures ✓
4. `grep -c "private function normaliseEan" app/Console/Commands/GenerateProductDraftsCommand.php` → 0 ✓
5. `grep -c "env(" app/Console/Commands/BackfillMerchantFeedCommand.php app/Console/Concerns/NormalisesEan.php` → 0 + 0 ✓
6. `git log --oneline -5` → 5 atomic commits in declared order referencing 260607-cgd ✓
7. Dev smoke `--field=ean --dry-run --limit=10` → outcome A (creds present, 4-quadrant + sample printed) ✓

## Prod-deploy notes

- No migrations required.
- Command is auto-discoverable but explicit registration via AppServiceProvider matches the established repo convention.
- Prod backfill has NOT been kicked off yet — this task ships the command + tests + registration. The operator deploys the merge, then runs the prod recipe above (dry-run first, then `--field=ean,brand --resync`, then optionally `--field=category --resync` for the £35 Claude spend).

## Self-Check: PASSED

All 5 created files exist on disk (NormalisesEan trait, BackfillMerchantFeedCommand, two test files, this SUMMARY). All 5 commit hashes (da9bbdb, e86fe74, 1c35c30, 0f6af53, 7da38a7) are present in git log.
