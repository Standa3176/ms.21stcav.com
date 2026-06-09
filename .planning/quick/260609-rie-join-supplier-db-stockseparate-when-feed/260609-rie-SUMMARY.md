---
quick_id: 260609-rie
type: quick
mode: summary
created: 2026-06-09
completed: 2026-06-09
status: complete
commits: 4
files_created:
  - app/Domain/Sync/Concerns/JoinsStockSeparate.php
  - tests/Architecture/StockSeparateJoinTest.php
  - tests/Feature/Sync/SupplierDbSyncCommandStockSeparateTest.php
  - tests/Feature/Sync/ExplainSupplierCostCommandStockSeparateTest.php
files_modified:
  - app/Domain/Sync/Commands/SupplierDbSyncCommand.php
  - app/Domain/Sync/Commands/ExplainSupplierCostCommand.php
  - app/Console/Commands/BackfillMerchantFeedCommand.php
  - app/Domain/Sync/Services/SupplierSkuRegistry.php
  - app/Domain/Sync/Services/SupplierFeedSourceabilityChecker.php
  - app/Domain/Sync/Services/SupplierAddCandidateScanner.php
tests_delta: "+13 pass / 0 new fails (1,978 / 222 / 3 vs 1,965 / 222 / 3 baseline)"
---

# Quick Task 260609-rie — JOIN supplier_db.stockseparate when feeds.is_stock_separate=1

## One-line

LEFT JOIN supplier_db.stockseparate when feeds.is_stock_separate=1 — fix Ingram silently zeroing 124k in-stock SKUs across SupplierDbSyncCommand + ExplainSupplierCostCommand. Trait-centralised SQL fragments + architecture-test regression guard so the dual-file gap cannot be silently reintroduced.

## Live numbers (prod probe 2026-06-09)

| Metric | Value |
|---|---:|
| Ingram catalogue size (feeds_products rows) | 192,011 |
| Ingram rows with stock in `stockseparate` | 125,319 |
| Ingram rows with stock in `feeds_products.stock` | 1,418 |
| **Ingram SKUs invisible to MS pre-fix** | **123,901** |
| Sample SKU CP15851 (Sennheiser HA310-2EP), fp.stock | 0 |
| Sample SKU CP15851, ss.stock | 5,659 |

WestCoast (id=39) and every other supplier have `is_stock_separate=0` and keep their stock in `feeds_products.stock` — byte-identical post-fix.

## Audit table (6-row scope decision — confirmed via Task 1 probe)

| # | File:line | SELECTs include `.stock`? | Disposition |
|---|---|---|---|
| 1 | `app/Domain/Sync/Commands/SupplierDbSyncCommand.php:139` | YES (`fp.mpn, fp.suppliersku, fp.supplierid, fp.price, fp.stock, f.name`) | IN-SCOPE — trait wired |
| 2 | `app/Domain/Sync/Commands/SupplierDbSyncCommand.php:392` | YES (`fp.mpn, fp.suppliersku, fp.supplierid, fp.price, fp.stock, fp.rrp, f.name`) | IN-SCOPE — trait wired |
| 3 | `app/Domain/Sync/Commands/ExplainSupplierCostCommand.php:72` | YES (`fp.supplierid, f.name, fp.mpn, fp.suppliersku, fp.price, fp.stock, fp.rrp, fp.product_excluded, fp.updated_at`) | IN-SCOPE — trait wired |
| 4 | `app/Console/Commands/BackfillMerchantFeedCommand.php:881` | NO (`suppliersku, ean`) | OUT-OF-SCOPE — annotated |
| 5 | `app/Console/Commands/BackfillMerchantFeedCommand.php:905` | NO (`mpn, ean`) | OUT-OF-SCOPE — annotated |
| 6 | `app/Console/Commands/BackfillMerchantFeedCommand.php:966` | NO (`suppliersku, manufacturer`) | OUT-OF-SCOPE — annotated |
| 7 | `app/Console/Commands/BackfillMerchantFeedCommand.php:990` | NO (`mpn, manufacturer`) | OUT-OF-SCOPE — annotated |
| 8 | `app/Domain/Sync/Services/SupplierSkuRegistry.php:52` | NO (`mpn_key, ssku_key`) | OUT-OF-SCOPE — annotated |
| 9 | `app/Domain/Sync/Services/SupplierFeedSourceabilityChecker.php:70` | NO (`mpn_key, ssku_key`) | OUT-OF-SCOPE — annotated |
| 10 | `app/Domain/Sync/Services/SupplierAddCandidateScanner.php:61` | NO (`mpn, supplier_count, brand, title, supplierskus`) | OUT-OF-SCOPE — annotated |

10 hits inventoried, 3 fixed, 7 annotated with `// stock-separate-not-applicable: <reason>`.

## Commits (4)

| # | Hash | Type | Summary |
|---|---|---|---|
| 1 | `779472a` | feat(sync) | JoinsStockSeparate trait — SQL helpers for the stockseparate dual-file fix |
| 2 | `bfc9f75` | fix(sync) | SupplierDbSyncCommand + ExplainSupplierCostCommand read stockseparate for is_stock_separate=1 |
| 3 | `d8781bf` | test(architecture) | forbid feeds_products.stock reads without JoinsStockSeparate |
| 4 | `7ebb774` | test(sync) | stockseparate JOIN coverage cases A-E |

Plan called for 4 commits (T2/T3/T4/T5); T1 audit + T6 verify are non-committing. Delivered exactly 4.

## Suite delta

Baseline 260609-nku: **1,965 pass / 222 fail / 3 skipped**.
Post-fix: **1,978 pass / 222 fail / 3 skipped**.
Delta: **+13 pass / 0 new fails** — matches the planner's prediction byte-for-byte.

The 222 pre-existing failures are unrelated to this work (Deptrac cross-domain import violations, PriceCalculator SHA pin drift, and similar — see `tests/Architecture/Deptrac*Test.php` + `TradePricingNoV1ModificationTest.php`). Out of scope per CLAUDE.md SCOPE BOUNDARY rule.

## Operator post-deploy workflow

1. Deploy this fix to prod.
2. **Re-run `php artisan supplier:db-sync --dry-run`** — confirm the report shows ~125k Ingram SKUs flipping from "no-stock" to "has-stock". If the number is suspiciously low (<50k), the JOIN may be misconfigured; STOP, do not run live, investigate.
3. **Re-run live `php artisan supplier:db-sync`** (no flag) — this rewrites `products.buy_price` and `products.stock_quantity` for the Ingram catalogue.
4. **Re-run `php artisan products:audit-stock-divergence`** (260609-nku). The previous 256 phantom count should drop substantially as the genuine Ingram drop-ship SKUs (misclassified as "phantom" because MS thought they were OOS) reconcile correctly. Only then is the divergence table trustworthy.

## Regression guard

`tests/Architecture/StockSeparateJoinTest.php` (3 assertions):
1. **File-scan** over `app/Domain/Sync/`, `app/Console/Commands/`, `app/Domain/Pricing/Services/` — any file containing `FROM feeds_products` (after comment-stripping) MUST either `use App\Domain\Sync\Concerns\JoinsStockSeparate;` + `use JoinsStockSeparate;` in its class body, OR carry a `// stock-separate-not-applicable: <reason>` annotation.
2. **Meta-assertion** — the regex still has teeth (positive case matches, comment-stripped negative does not).
3. **Trait sanity** — the canonical trait at `app/Domain/Sync/Concerns/JoinsStockSeparate.php` still defines both `stockColumnSelect()` and `stockSeparateJoinClause()`.

A future `FROM feeds_products` query reading `.stock` without using the trait WILL fail CI with a useful error message pointing back to this PLAN/SUMMARY.

## Deviations from plan

### [Rule 1 - Bug] docblock parse error caught by deliberately-broken sanity-run

The plan's annotation wording referenced the directory as `.planning/quick/260609-rie-*/260609-rie-PLAN.md`. When that string sat inside a `/** ... */` docblock (as it did in `BackfillMerchantFeedCommand.php`), the `*/` inside `260609-rie-*/` terminated the docblock early and PHP threw a parse error on the next line. Caught by `php -l` after the deliberately-broken Task 4 sanity-test run cascaded across the whole architecture slice. Fixed by rewording all 4 docblock + 3 line-comment annotations to `.planning/quick/260609-rie-... directory` — wording change only, no semantic impact. Annotation marker `stock-separate-not-applicable:` is unchanged so the arch test still finds it.

### BackfillMerchantFeedCommand deviation note (brief assumption corrected)

The task brief originally listed BackfillMerchantFeedCommand as needing 4 fixes. The Task 1 audit (grep + read of each SELECT list) confirmed all 4 of its SQL sites read `ean` or `manufacturer` only — NOT `.stock`. The command's buy-price decision goes through `products.buy_price` (written by `SupplierDbSyncCommand`), so fixing `SupplierDbSyncCommand` transitively fixes BackfillMerchantFeedCommand's downstream pricing without any direct edits there. Documented in PLAN frontmatter; preserved here for traceability.

## Phase-7 cutover correction

Phase 7's "legacy Stock Updater plugin was the bug" assumption is now corrected: the dual-file architecture is the real bug. The plugin was probably broken in the same way for the same reason — a separate, untouched-during-migration MySQL table that nobody documented. The new architecture-test guardrail prevents the same mistake from creeping back in across `app/Domain/Sync/`, `app/Console/Commands/`, and `app/Domain/Pricing/Services/`.

## Self-Check: PASSED

- `app/Domain/Sync/Concerns/JoinsStockSeparate.php` — FOUND
- `tests/Architecture/StockSeparateJoinTest.php` — FOUND
- `tests/Feature/Sync/SupplierDbSyncCommandStockSeparateTest.php` — FOUND
- `tests/Feature/Sync/ExplainSupplierCostCommandStockSeparateTest.php` — FOUND
- Commit `779472a` — FOUND
- Commit `bfc9f75` — FOUND
- Commit `d8781bf` — FOUND
- Commit `7ebb774` — FOUND
- All 4 modified out-of-scope files carry `stock-separate-not-applicable:` annotation
- Both in-scope command classes carry `use JoinsStockSeparate;` (verified via `class_uses()` drift guards in the Pest suite)
