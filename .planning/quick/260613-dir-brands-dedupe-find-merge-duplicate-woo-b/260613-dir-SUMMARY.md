---
quick_id: 260613-dir
mode: quick
type: execute
status: complete
completed: 2026-06-13
description: New `brands:dedupe` artisan that finds case-insensitive duplicate Woo product_brand terms, reassigns MS products.brand_id from non-canonical → canonical, and optionally deletes the Woo-side duplicate terms.
commits:
  - 83230da feat(brands): brands:dedupe command + AppServiceProvider registration (260613-dir)
  - 23170a3 test(brands): brands:dedupe Pest cases A-J (260613-dir)
  - 59870fb docs(state): 260613-dir brands:dedupe shipped (260613-dir)
files_modified:
  - app/Console/Commands/DedupeBrandsCommand.php (new)
  - app/Providers/AppServiceProvider.php (use + commands array)
  - tests/Feature/Console/DedupeBrandsCommandTest.php (new)
  - .planning/STATE.md (stopped_at row rotated)
---

# 260613-dir — `brands:dedupe` SUMMARY

## One-paragraph dense summary

Quick task 260613-dir — `brands:dedupe` artisan command that finds case-insensitive duplicate Woo `product_brand` terms (legacy WC import residue: "Poly" vs "poly", " Logitech " vs "Logitech") and merges MS `products.brand_id` from non-canonical → canonical via `DB::table('products')->where('brand_id', $sourceId)->update(['brand_id' => $canonicalId, 'updated_at' => now()])` inside a `DB::transaction` per source. Canonical = highest Woo `count` DESC, tie-break by lowest term id ASC (deterministic — re-runs produce the same canonical pick). Two-phase safety: Phase A reassignment (SAFE — products always have a valid brand) runs by default; Phase B Woo DELETE via `WooClient::delete("products/brands/{$sourceId}", ['force' => true])` is RISKY (other plugins like Yoast SEO / GLA / Flatsome may reference TERM ids) so it is gated behind `--delete-empty-woo-terms` (default OFF). Phase B runs STRICTLY AFTER all Phase A reassignments complete — locked via Pest Case G assertion `max(reassign_audit_id) < min(delete_audit_id)`. Idempotent: re-run on already-deduped state → `groups_found=0` fast path; re-run `--delete-empty-woo-terms` after a prior successful delete → 404 from Woo increments `already_deleted` (NOT `errors`). Closes the residual brand fragmentation left after 260611-sr7's name-first-word backfill — MS products were pointing at a mix of canonical AND duplicate Woo term ids, splitting `/product-brand/{slug}/` landing pages, product counts, and SEO juice. Scope explicitly excludes fuzzy/alias matching (HP vs Hewlett-Packard) — those are handled via the existing Filament brand-mapping UI; this command is one-shot operator-triggered, no scheduled cron, no Woo PUT against products. 10 Pest cases A-J GREEN on first run (62 assertions, 33.61s). Touched-area regression suites GREEN at 21/21 / 90 assertions. Full-suite reconciliation OOM-deferred (pre-existing Windows-Herd 512MB infrastructure issue, NOT introduced by this task). 3 atomic commits.

## Files modified

| Path | Change |
|------|--------|
| `app/Console/Commands/DedupeBrandsCommand.php` | NEW — ~395 lines; extends BaseCommand; constructor DI `WooClient + Auditor`; not `final` |
| `app/Providers/AppServiceProvider.php` | Added `use App\Console\Commands\DedupeBrandsCommand;` + `DedupeBrandsCommand::class` to `$this->commands([...])` array |
| `tests/Feature/Console/DedupeBrandsCommandTest.php` | NEW — ~420 lines; 10 Pest cases A-J + anonymous-subclass WooClient stub + `seedProductsWithBrand()` helper |
| `.planning/STATE.md` | New `stopped_at` paragraph for 260613-dir; previous 260611-sr7 row rotated to `old_stopped_at`; `last_updated` + `last_activity` bumped to 2026-06-13 |

## Counters (6)

`groups_found / sources_merged / products_reassigned / woo_terms_deleted / already_deleted / errors`

## Audit log namespaces (5)

| Namespace | When |
|-----------|------|
| `brands.dedupe_reassigned` | Phase A success — per source merged |
| `brands.dedupe_reassign_failed` | Phase A DB transaction rollback |
| `brands.dedupe_woo_term_deleted` | Phase B success — Woo term deleted |
| `brands.dedupe_woo_term_already_deleted` | Phase B 404 — idempotent re-run signal |
| `brands.dedupe_woo_term_error` | Phase B 5xx — Woo delete failed |

(+ defensive `brands.dedupe_pagination_failed` when the Woo brands GET throws during pagination — aborts the run with `FAILURE` exit.)

## Pest cases A-J

| Case | What it asserts |
|------|-----------------|
| A | No duplicates → `groups_found=0`, no writes, no Woo deletes, no audit rows |
| B | Canonical = highest count (Poly id=10 c=50 vs poly id=11 c=3 → all 3 products migrate to id=10; `brands.dedupe_reassigned` audit with `from_id=11, to_id=10, products_affected=3`) |
| C | Tie-break by lowest id (Bose id=5 vs bose id=8, both count=8 → canonical=5) |
| D | Case mismatch Poly/POLY/poly → all merged into id=10; `sources_merged=2`; 2 `brands.dedupe_reassigned` audit rows |
| E | Whitespace trim " Logitech " grouped under `'logitech'` → canonical=20 (Logitech c=100), source id=21 reassigned |
| F | `--dry-run` writes nothing; stub records ZERO `delete()` invocations; ZERO `brands.dedupe_*` audit rows |
| G | `--delete-empty-woo-terms` happy path: 4 `delete("products/brands/{id}", ['force' => true])` calls; **Phase A → Phase B ordering** asserted via `max(reassign_audit_id) < min(delete_audit_id)`; 4 `brands.dedupe_reassigned` + 4 `brands.dedupe_woo_term_deleted` rows |
| H | `--delete-empty-woo-terms` with Woo 5xx: reassign STILL happened; `errors=1`; `brands.dedupe_woo_term_error` audit row; exit `SUCCESS` |
| I | `--delete-empty-woo-terms` with Woo 404 (idempotent re-run): `already_deleted=1`, `errors=0`; `brands.dedupe_woo_term_already_deleted` row; NO `brands.dedupe_woo_term_error` row |
| J | DB::transaction rollback on per-source UPDATE failure via `DB::beforeExecuting`: source A rolled back (products STILL on `brand_id=source`), source B succeeded (batch continues); `brands.dedupe_reassign_failed` audit for A + `brands.dedupe_reassigned` for B; exit `SUCCESS` |

## Delta vs 260611-sr7 baseline

| Suite | Pass / Fail / Risky |
|-------|---------------------|
| Focused — `DedupeBrandsCommandTest` | 10 / 0 / 0 (62 assertions, 9.43s) |
| Touched-area regression — BackfillProductBrandFromName + BackfillCategoryFromWoo + PushVisibility | 21 / 0 / 0 (90 assertions, 15.20s) — UNCHANGED from baseline |
| Full Pest | OOM-deferred (Windows-Herd 512MB pre-existing infrastructure issue) |

Touched-area equivalence: **+10 pass / 0 new fails / 0 new risky** vs 260611-sr7 baseline (~2,042 / 222 / 3).

## 6-step post-deploy operator sequence

1. Deploy all 3 commits.
2. `php artisan brands:dedupe --dry-run` confirms the plan size + which group keys are dupes. Operator eyeballs the canonical-vs-source choices for sanity (the resolver picked highest-count, but a human gut-check matters — e.g. if "Poly" 50 vs "poly" 3 the operator may notice the brand actually ships with capital "Poly" today, which matches).
3. Live run `php artisan brands:dedupe`. MS products reassigned; Woo terms untouched.
4. Storefront spot-check `/product-brand/poly/` (or whichever canonical you picked) — should now show MORE products than before. The duplicate `/product-brand/poly-1/` (Woo's auto-slug-collision fallback) is still live BUT now empty.
5. **Risky step — only if comfortable**: `php artisan brands:dedupe --delete-empty-woo-terms`. Woo term DELETE on the now-empty source ids. Re-running this is safe (`already_deleted++` on 404). After this, the empty duplicate landing pages 404 — fine, those URLs had no inbound traffic worth preserving (auto-slug-collision dupes never ranked).
6. Spot-check `/wp-json/wc/v3/products/brands?per_page=100` — duplicate names should be gone.

## What this does NOT do

- No fuzzy/alias matching (HP vs Hewlett-Packard) — operator handles via the existing Filament brand-mapping UI.
- No scheduled cron — brand dupes don't accumulate fast enough to need automation; operator-triggered keeps this safe.
- No backfilling new brand_ids — 260611-sr7 owns that surface; this command only moves products BETWEEN existing brand ids.
- No Woo PUT against products with `brand_id=$sourceId` — we update MS-side only. Woo's `_product_brand_id` meta is silent-skipped by WooFieldComparator per 260610-qc4, so no flood of sync_diffs.
- No rollback — if operator picks wrong canonical, a follow-up `--skus=` reassignment via 260611-sr7's `products:backfill-brand-from-name` is the manual unwind path.
- No modification to `BackfillProductBrandFromNameCommand` / `BackfillMerchantFeedCommand` / `BackfillCategoryFromWooCommand` / `TaxonomyResolver` / `WooClient` — all UNTOUCHED.

## Atomic commits

| Task | Hash | Message |
|------|------|---------|
| 2 | `83230da` | feat(brands): brands:dedupe command + AppServiceProvider registration (260613-dir) |
| 3 | `23170a3` | test(brands): brands:dedupe Pest cases A-J (260613-dir) |
| 5 | `59870fb` | docs(state): 260613-dir brands:dedupe shipped (260613-dir) |

## Self-Check: PASSED

- ✅ `app/Console/Commands/DedupeBrandsCommand.php` exists
- ✅ `tests/Feature/Console/DedupeBrandsCommandTest.php` exists
- ✅ `app/Providers/AppServiceProvider.php` contains `DedupeBrandsCommand::class`
- ✅ Commits `83230da` and `23170a3` present in `git log`
- ✅ `php artisan list | grep brands:dedupe` resolves to 1 row
- ✅ Focused suite 10/10 GREEN
- ✅ Touched-area regression suites 21/21 GREEN
