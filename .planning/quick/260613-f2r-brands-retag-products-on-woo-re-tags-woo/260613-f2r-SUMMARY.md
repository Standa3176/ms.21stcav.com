---
phase: quick-260613-f2r
plan: 01
subsystem: brands
tags: [brands, woo, dedupe, retag, drift-prevention]
requires:
  - 260613-dir (brands:dedupe — MS-side reassignment)
  - WooClient (Phase 09.1 — credentials + 429 backoff + IntegrationLogger)
  - Auditor (Phase 1 — spatie/activitylog `system` log)
provides:
  - artisan command brands:retag-products-on-woo
  - BrandDuplicateFinder service (shared with DedupeBrandsCommand)
  - 5 new audit namespaces (brands.product_retagged / brands.retag_failed /
    brands.retag_pagination_failed / brands.retag_no_products_on_woo /
    brands.retag_discovery_failed)
affects:
  - DedupeBrandsCommand (refactored to consume BrandDuplicateFinder; observable
    behaviour byte-identical; 10/10 Pest cases GREEN unchanged)
tech-stack:
  added: []
  patterns:
    - "Service extraction on 2nd consumer (mirrors 260611-s2d WooProductWriter)"
    - "Drift-prevention via single source-of-truth service docblock"
    - "Per-request stub via app()->instance(WooClient::class, ...)"
key-files:
  created:
    - app/Console/Commands/RetagProductsOnWooCommand.php
    - app/Domain/Sync/Services/BrandDuplicateFinder.php
    - tests/Feature/Console/RetagProductsOnWooCommandTest.php
  modified:
    - app/Console/Commands/DedupeBrandsCommand.php
    - app/Providers/AppServiceProvider.php
    - .planning/STATE.md
decisions:
  - "Task 1: EXTRACT BrandDuplicateFinder service. Reason: discovery block self-contained on $this->woo, planned_affected stays command-specific, container DI auto-inherits WooClient stub. Mirrors 260611-s2d WooProductWriter precedent."
metrics:
  duration_minutes: 90
  completed_date: 2026-06-13
  commits: 4
  tests_added: 10
  test_assertions: 49
---

# Quick task 260613-f2r: brands:retag-products-on-woo Summary

Operator can now run `php artisan brands:retag-products-on-woo` to re-tag Woo
products from duplicate (source) brand terms → canonical brand terms, closing
the Woo-side gap left by 260613-dir's `brands:dedupe` and making the
`brands:dedupe --delete-empty-woo-terms` cascade safe.

## What shipped

**The operator workflow chain is now safe end-to-end:**

1. `brands:dedupe` — MS-side merge (`products.brand_id` source → canonical).
2. `brands:retag-products-on-woo` — Woo-side re-tag (this command).
3. `brands:dedupe --delete-empty-woo-terms` — safely delete empty source terms.

Without step 2, step 3 would have stripped the brand association from ~310
Woo products via Woo's `?force=true` cascade (the source terms are deleted,
and any product still tagged with them loses the brand link). Step 2 ensures
the source brands legitimately have count=0 on Woo before any DELETE fires.

### Files created

| Path | Purpose |
|------|---------|
| `app/Console/Commands/RetagProductsOnWooCommand.php` | New artisan command. Constructor DI on WooClient + Auditor + BrandDuplicateFinder. Three flags: `--dry-run`, `--source-ids=`, `--limit=`. |
| `app/Domain/Sync/Services/BrandDuplicateFinder.php` | New service. Single source of truth for Woo brand-duplicate discovery: pagination over `/products/brands`, group by lowercased+trimmed name, drop singletons, canonical pick = highest count DESC tie-break lowest id ASC. Consumed by both DedupeBrandsCommand (260613-dir) AND RetagProductsOnWooCommand (260613-f2r). |
| `tests/Feature/Console/RetagProductsOnWooCommandTest.php` | 10 Pest cases A-J (49 assertions). |

### Files modified

| Path | Change |
|------|--------|
| `app/Console/Commands/DedupeBrandsCommand.php` | Refactored to constructor-inject BrandDuplicateFinder; replaced inline pagination + grouping + canonical-pick block with `$this->finder->discover()`. `planned_affected` per-source DB count stays in the command. Observable behaviour byte-identical (10/10 DedupeBrandsCommandTest GREEN unchanged). |
| `app/Providers/AppServiceProvider.php` | Registered `RetagProductsOnWooCommand::class` adjacent to `DedupeBrandsCommand::class` with multi-line drift-prevention comment block. |
| `.planning/STATE.md` | New `stopped_at:` row + previous rotated to `old_stopped_at:`. `last_updated` bumped. |

## Engine contract (counters + audit namespaces)

**7 counters** in the final summary table:

| Counter | Meaning |
|---------|---------|
| `groups_processed` | Source-brand → canonical-brand pairs entered (incremented even if first GET 404s — we DID attempt the source). |
| `products_scanned` | Total products iterated across all sources. |
| `products_retagged` / `would_retag` | Successful PUTs (live) or planned PUTs (dry-run). |
| `already_canonical` | Products whose current brand-ID set equals the new set (no-op short-circuit, no PUT). |
| `errors` | Per-product PUT failures (5xx); batch continues. |
| `no_products_on_woo` | Per-source 404 on `/products?brand={sourceId}` (term deleted between discovery and now). |
| `source_not_a_duplicate` | `--source-ids` IDs that aren't in any duplicate group. |

**5 audit namespaces** (Auditor::record):

| Namespace | When | Payload |
|-----------|------|---------|
| `brands.product_retagged` | Per successful PUT | product_id, sku, from_brand_id, to_brand_id, new_brand_ids (full final list — forensic trail) |
| `brands.retag_failed` | Woo 5xx on PUT | product_id, sku, from_brand_id, to_brand_id, error |
| `brands.retag_pagination_failed` | Non-404 throw on per-source list GET | source_id, canonical_id, page, error |
| `brands.retag_no_products_on_woo` | 404 on per-source list GET | source_id, canonical_id |
| `brands.retag_discovery_failed` | BrandDuplicateFinder::discover throws | error |

## Test outcomes

**10 Pest cases A-J — RetagProductsOnWooCommandTest GREEN (49 assertions, 34.82s):**

| Case | Scenario |
|------|----------|
| A | 5 products tagged ONLY [source] → 5 PUTs with `brands=[{id:canonical}]`, products_retagged=5. |
| B | 1 product tagged [source, otherBrand] → PUT body has BOTH canonical AND otherBrand (sorted ID compare). |
| C | Product tagged BOTH source AND canonical AND otherBrand → PUT body has canonical EXACTLY ONCE (no duplicate). |
| D | Product tagged ONLY [canonical] → already_canonical++, ZERO PUTs, ZERO audit rows. |
| E | Woo 5xx on PUT of one product → errors++, second product still pushed, exit SUCCESS. |
| F | Woo 404 on `/products?brand={sourceId}` → no_products_on_woo++, ZERO PUTs for source. |
| G | `--dry-run` → ZERO PUTs, dry-run banner + sample table printed. |
| H | `--source-ids=3102,12822` → 3102 (in dup group) processed, 12822 (not in dup group) → source_not_a_duplicate++. |
| I | Pagination across 2 pages (100 + 1 products) → 101 PUTs, both pages hit. |
| J | Idempotent re-run after retag → already_canonical=5, ZERO new PUTs, ZERO new audit rows. |

**Touched-area regression suites GREEN (51/51 tests, 288 assertions):**

| Suite | Result |
|-------|--------|
| DedupeBrandsCommandTest (260613-dir) | 10/10 (62 assertions) — proves EXTRACT refactor preserved behaviour byte-identically |
| PushDivergenceToWooCommandTest (260611-g4q) | 10/10 (87) |
| PushVisibilityToWooCommandTest (260611-f1y) | 6/6 (29) |
| BackfillCategoryFromWooCommandTest (260607-v5g) | 6/6 (26) |
| BackfillProductBrandFromNameCommandTest (260611-sr7) | 9/9 (35) |
| RetagProductsOnWooCommandTest (260613-f2r) | 10/10 (49) |

**Full Pest baseline check:** OOM-deferred (Windows herd PHP plateaued at 366MB
then stopped emitting output — pre-existing infrastructure issue from
260611-qcq onwards, NOT introduced by 260613-f2r). Focused/touched-area
equivalent confirmed at **+10 pass / 0 new fails** vs 260613-dir baseline.

## Decisions Made

### Task 1: EXTRACT vs INLINE → EXTRACT chosen

- **Reason:** Discovery block in `DedupeBrandsCommand::perform()` lines
  113-209 is self-contained — only depends on `$this->woo->get()` and
  `BRANDS_PER_PAGE`. `planned_affected` is command-specific (DedupeBrands
  needs it for dry-run; RetagProducts doesn't) and stays in the command.
  DedupeBrandsCommandTest binds WooClient stub via
  `app()->instance(WooClient::class, $stub)`, so a `BrandDuplicateFinder`
  resolved through the container picks up the SAME stub automatically — no
  test-rig changes needed.
- **Mirrors:** 260611-s2d `WooProductWriter` extract-on-second-consumer
  precedent. The cost (one refactor commit + a return-shape contract) is
  one-time; the benefit (single source of truth for brand discovery across
  2+ consumers) is permanent.
- **Drift-prevention contract** baked into BrandDuplicateFinder docblock: "If
  a third consumer is added or the canonical-pick rule changes, edit HERE
  only — do not re-implement pagination + grouping + canonical-pick in
  commands. The same rule applies if a future quick task adds variation
  brand dedupe — extend this service, not the consumers."

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Case I test fixture flipped canonical-pick**

- **Found during:** Task 4 (Pest cases A-J first run).
- **Issue:** Case I used `'count' => 101` on the source brand (id=11) which
  beat the canonical's `count=50` (id=10). The canonical-pick rule (highest
  count DESC) made id=11 the canonical instead of id=10 → the
  `[sourceId => canonicalId]` map became empty for id=11 → zero retags. Test
  failed at `expect($stub->putCalls)->toHaveCount(101)` with "actual size 0".
- **Fix:** Raised the canonical's count to 500 so id=10 stays canonical (the
  test intent — pagination across 2 pages of source-tagged products, NOT a
  canonical-pick assertion).
- **Files modified:** `tests/Feature/Console/RetagProductsOnWooCommandTest.php`
  (single-line fixture edit; no command logic touched).
- **Commit:** 90f3e15 (folded into the Task 4 test commit before push — fixture
  was wrong, the command was right).

## Drift-prevention contract verified

- `$this->finder->discover()` confirmed in BOTH `DedupeBrandsCommand.php:121`
  AND `RetagProductsOnWooCommand.php:116`.
- Canonical-pick `usort` closure (`if ($a['count'] !== $b['count'])`) found
  in EXACTLY ONE file: `app/Domain/Sync/Services/BrandDuplicateFinder.php`.
  Single source of truth contract satisfied.
- `grep "new AutomatticClient|Http::|use GuzzleHttp" app/Console/Commands/RetagProductsOnWooCommand.php`
  → 1 hit, line 37 docblock describing what the command does NOT do. Zero
  actual usage. All Woo I/O through `$this->woo` (WooClient).

## Commits

| Hash | Type | Description |
|------|------|-------------|
| f32922e | refactor | extract BrandDuplicateFinder service from DedupeBrandsCommand (260613-f2r) |
| 1f48781 | feat | retag-products-on-woo command + AppServiceProvider registration (260613-f2r) |
| 90f3e15 | test | retag-products-on-woo Pest cases A-J (260613-f2r) |
| (pending) | docs | 260613-f2r retag-products-on-woo shipped (260613-f2r) |

## Operator post-deploy action sequence

1. Deploy all commits.
2. `php artisan brands:dedupe --dry-run` — re-confirms duplicate groups (should
   match yesterday's 11 groups from 260613-dir's first prod run).
3. `php artisan brands:retag-products-on-woo --dry-run` — prints per-source plan
   + 20-row sample of `would_retag` decisions. Expect ~310 products across the
   11 duplicate groups.
4. Live: `php artisan brands:retag-products-on-woo`. Expect ~310 PUTs over ~5-7
   min @ 200ms throttle. Audit log: 310× `brands.product_retagged` rows with
   from_brand_id + to_brand_id + new_brand_ids forensic payload.
5. Spot-check storefront: previously-source brand pages
   (`/product-brand/poly-1/` and other auto-slug-collision dupes) should now
   have product count=0; canonical `/product-brand/poly/` should have ALL Poly
   products.
6. Spot-check `/wp-json/wc/v3/products/brands?per_page=100` — duplicate source
   brands' `count` field should be 0.
7. **RISKY STEP — now SAFE because source terms have count=0:**
   `php artisan brands:dedupe --delete-empty-woo-terms`. Woo DELETE on the
   now-empty source ids; ZERO products lose their brand association because
   they're already on canonical.

## What this does NOT do

- No fuzzy/alias matching (HP vs Hewlett-Packard) — operator handles via
  Filament brand-mapping UI.
- No scheduled cron — operator-triggered, runs once after dedupe.
- No rollback — re-running on already-retagged state is a no-op via
  `already_canonical`.
- No new Filament UI — CLI-only workflow.
- No Woo-side variation brand handling — if a future quick task needs that,
  write a sibling command, do NOT extend this one (see
  RetagProductsOnWooCommand docblock).

## Self-Check: PASSED

Files created/modified verified to exist:
- `app/Console/Commands/RetagProductsOnWooCommand.php` — FOUND
- `app/Domain/Sync/Services/BrandDuplicateFinder.php` — FOUND
- `tests/Feature/Console/RetagProductsOnWooCommandTest.php` — FOUND
- `app/Console/Commands/DedupeBrandsCommand.php` — FOUND (modified)
- `app/Providers/AppServiceProvider.php` — FOUND (modified)
- `.planning/STATE.md` — FOUND (modified)

Commits verified via `git log --oneline -5`:
- f32922e — FOUND
- 1f48781 — FOUND
- 90f3e15 — FOUND
