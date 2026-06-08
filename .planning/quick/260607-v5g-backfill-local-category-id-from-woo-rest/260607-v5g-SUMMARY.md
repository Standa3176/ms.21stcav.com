---
quick_id: 260607-v5g
type: summary
mode: quick
status: complete
completed_at: 2026-06-08
commits:
  - hash: 7cd9366
    msg: "feat(products): backfill-category-from-woo command (260607-v5g)"
  - hash: 20514e1
    msg: "test(products): backfill-category-from-woo Pest cases A-F (260607-v5g)"
  - hash: 2ce631a
    msg: "chore(commands): register products:backfill-category-from-woo (260607-v5g)"
  - hash: c047d11
    msg: "feat(category-audit): footer hint pointing operators at the Woo backfill command (260607-v5g)"
files_modified:
  - app/Console/Commands/BackfillCategoryFromWooCommand.php   # NEW (310 lines)
  - tests/Feature/Console/BackfillCategoryFromWooCommandTest.php   # NEW (288 lines)
  - app/Providers/AppServiceProvider.php   # +8 lines (import + registration block)
  - resources/views/filament/pages/category-audit.blade.php   # +10 lines (sky-blue hint banner)
---

# 260607-v5g — Backfill local category_id from Woo REST

## One-liner

New artisan `products:backfill-category-from-woo` pulls the authoritative `categories` array
from Woo REST for the 3,244 NULL-category live products surfaced by the 260607-t6w audit,
writing `category_id` + `category_ids` via `DB::table()` to bypass the Eloquent array cast.

## Outcome

All 5 plan tasks complete. 4 atomic commits shipped (Task 5 is verify-only, no commit).
Operator now has a one-command path to close the 3,244-row audit gap, with a sky-blue
hint banner on `/admin/category-audit` pointing future operators at the bulk-fix path.

## What shipped

| Component                                          | Provides                                                                                                                                  |
| -------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------- |
| `BackfillCategoryFromWooCommand`                   | Artisan `products:backfill-category-from-woo` with 6 options (--skus / --limit / --chunk / --dry-run / --resync / --no-confirm).          |
| `BackfillCategoryFromWooCommandTest`               | 6 Pest cases A-F (updated / no_woo_categories / woo_not_found / null woo_id excluded / chunk error / dry-run) — 26 assertions GREEN.      |
| `AppServiceProvider::boot()` registration          | Cluster the two backfill commands; 5-line docblock points back at quick task 260607-v5g.                                                  |
| `category-audit.blade.php` footer hint             | Sky-blue banner directly below the existing severity-coloured count banner; surfaces the bulk-fix workflow above the table.               |

## Key behaviours

- **Candidate query (default):** `status='publish' AND woo_product_id IS NOT NULL AND category_id IS NULL`.
- **Candidate query (--skus override):** Drops the `category_id IS NULL` filter so the operator can re-pull categories for an already-populated row (e.g. after a Woo-side category re-mapping). Keeps `status=publish` + `woo_product_id IS NOT NULL`.
- **Streaming:** `->cursor()` over the candidate set, then `array_chunk(..., $chunkSize, true)` for the Woo batch calls. Avoids hydrating 3,244 Product models at once.
- **Woo call shape:** `WooClient::get('products', ['include' => $idCsv, 'per_page' => count($ids), 'orderby' => 'include'])`. Single batched GET per chunk; default chunk=50, clamped to Woo's `per_page` ceiling of 100.
- **Live write:** `DB::table('products')->where('sku', $sku)->update(['category_id' => $primary, 'category_ids' => json_encode($idList), 'updated_at' => now()])`. The `json_encode()` is mandatory — `DB::table()` bypasses the Eloquent `'category_ids' => 'array'` cast which would otherwise auto-encode.
- **Outcome buckets:** `updated` (or `would_update` on dry-run) / `no_woo_categories` / `woo_not_found` / `no_woo_product_id` / `error` / `already_populated_excluded`.
- **--resync chain:** Mostly a no-op for category changes (categories flow Woo→MS, not MS→Woo). Present for symmetry with `BackfillMerchantFeedCommand` + in case other product fields drift on the touched SKUs.
- **NOT scheduled:** Operator-triggered only. Woo→MS category drift is rare once the initial backfill lands.

## Dry-run output

```
PS> php artisan products:backfill-category-from-woo --dry-run --limit=3 --no-confirm
Correlation: 4bc6560f-8042-49e2-8636-3d79f92d98ca
Backfill: 0 candidate products.
```

Local dev DB is empty (verified: `total=0 publish=0 withWooId=0 nullCat=0` via tinker probe).
The command completed without exception — exactly the expected "no candidates" SUCCESS path.
The 3,244-row backfill target only exists in production.

## Pre-flight `missing_category_id` count

**N/A — last audit older than dev DB state.** The 3,244 count is from the production audit
(260607-t6w field intel). Dev DB has zero products so neither pre- nor post-flight counts
are meaningful locally.

## Post-flight verification (expected on prod run)

When the operator runs this command live in production:
1. Re-running `php artisan products:audit-categories` afterward should show `missing_category_id` dropping from 3,244 toward near zero.
2. The 105 `suspicious_brand_category_mismatch` count is **expected unchanged** — those rows already have a `category_id` value, may just be the wrong one. Different problem class, different fix path. This command does not address brand-vs-category mismatches.

## Regression tally

| Surface                                | Before (260607-t6w baseline) | After (260607-v5g)     |
| -------------------------------------- | ---------------------------- | ---------------------- |
| Full Pest suite                        | 1,929 pass / 222 fail / 3 skip | 1,935 pass / 222 fail / 3 skip |
| Delta                                  | —                            | +6 pass / +0 fail      |

Zero new failures introduced. The +6 passes are exactly the new
`BackfillCategoryFromWooCommandTest` cases A-F. The 222 pre-existing failures
(including the 3 known `IntegrationHealthWidgetTest` failures from 260607-hxa on
the deferred-items.md list) are unchanged.

Focused-filter regression gate (run individually because PowerShell mangles the
multi-test regex when chained via `vendor/bin/pest`):

| Test                       | Result                          |
| -------------------------- | ------------------------------- |
| EnvUsageTest               | 3 / 3 GREEN  (6 assertions)     |
| AutoCreatedPredicateTest   | 2 / 2 GREEN  (16 assertions)    |
| AutoCreateHealthPageTest   | 3 / 3 GREEN  (10 assertions)    |
| CategoryAuditPageTest      | 9 / 9 GREEN  (33 assertions)    |
| AdCandidatesPageTest       | 6 / 6 GREEN  (22 assertions)    |

## Deviations from plan

None — plan executed exactly as written.

### Minor friction notes (no deviation needed)

- The plan's Task 1 verify acknowledges the command won't be `php artisan list`-resolvable
  until Task 3 registers it. Same dynamic applies to Task 2's verify (the focused Pest
  test can't pass until the command is registered). Resolved by sequencing: Task 2 commits
  the test file (lint clean), Task 3 lands the registration, and the focused test then
  goes 6/6 GREEN before Task 3's commit. Verified on the actual Task 3 verify run.

- PowerShell does not pass the regex `EnvUsageTest|AutoCreatedPredicateTest|...` cleanly
  to `vendor/bin/pest` via either pipeline or single-quoted form — the `|` is interpreted
  as a pipe by the underlying `.bat` shim, not by Pest's filter regex parser. Workaround:
  run each filter individually (5 separate invocations). Same total wall time, equivalent
  signal. Documented above in the regression tally.

## Follow-up notes

- **Prod run is the real verification.** When operator runs this command live, expect the
  audit `missing_category_id` count to drop from 3,244 toward near zero on the next
  weekly audit (Fri 22:00 London cron, or manual `php artisan products:audit-categories`).
- **--resync caveat carried in the docblock + this SUMMARY.** Categories flow Woo→MS, so
  re-pushing the touched SKUs to Woo via `products:resync-to-woo` is mostly a no-op for
  category data specifically, but useful for catching drift on other product fields.
- **Sky-blue hint banner** on `/admin/category-audit` mentions both `--dry-run` and the
  live invocation + this quick task ID — future operators land on the right SUMMARY when
  they grep the codebase for "backfill-category-from-woo".
- **Threat surface scan:** No new endpoints, auth paths, file access patterns, or schema
  changes at trust boundaries. The command reuses `WooClient::get()` (no new client
  surface), reads from `products` table, writes back two existing columns. Same trust
  boundary as the existing `woo:import-products` and `products:resync-to-woo` flows.

## Self-Check: PASSED

- All 4 commits exist in git log (verified: `git log --oneline`).
- All 4 files modified are present and have correct content.
- Focused 6/6 BackfillCategoryFromWooCommandTest GREEN.
- Full Pest suite at +6 / +0 vs baseline.
- Command resolves in `php artisan list`.
- Dev `--dry-run` smoke completes cleanly.
- Footer hint banner contains `products:backfill-category-from-woo` (verified via grep).
