---
phase: 260709-hl0-fix-real-deptrac-architecture-violations
plan: 01
subsystem: Sync
tags: [deptrac, architecture, sync, refactor, sync-04]
requires: []
provides:
  - "WooFieldComparator relocated out of the Cutover sink into the Products layer"
  - "SupplierSkuCache Eloquent model for the supplier_sku_cache membership table"
  - "Sync services SupplierSkuRegistry / SupplierSyncDigestComposer / SupplierFreshnessResolver are DB-facade-free (SYNC-04)"
affects:
  - app/Domain/Sync/Services/*
  - app/Domain/Products/Services/WooFieldComparator.php
  - app/Domain/Products/Models/SupplierSkuCache.php
  - app/Domain/Cutover/Services/DivergenceScanner.php
tech-stack:
  added: []
  patterns:
    - "Sync-layer DB access via Products-layer Eloquent models (Model::query()) instead of the Illuminate DB facade"
key-files:
  created:
    - app/Domain/Products/Models/SupplierSkuCache.php
    - tests/Feature/Sync/SupplierSkuRegistryTest.php
  modified:
    - app/Domain/Products/Services/WooFieldComparator.php (git mv from Cutover/Services + renamespace)
    - app/Domain/Sync/Services/WooProductWriter.php
    - app/Domain/Sync/Services/SupplierSkuRegistry.php
    - app/Domain/Sync/Services/SupplierSyncDigestComposer.php
    - app/Domain/Sync/Services/SupplierFreshnessResolver.php
    - app/Domain/Cutover/Services/DivergenceScanner.php
    - tests/Feature/Cutover/WooFieldComparatorTest.php
    - tests/Feature/Cutover/DivergenceScanCommandTest.php
    - tests/Feature/Console/PushDivergenceToWooCommandTest.php
    - tests/Architecture/DivergenceComparatorCoverageTest.php
decisions:
  - "WooFieldComparator moved to the Products layer (not Foundation): its only import is Products\\Models\\Product, and both Cutover and Sync already allow-list Products in deptrac.yaml."
  - "Kept the plan's exact 6-file scope. Deptrac dropped 101 -> 88, NOT to 0: the plan's premise that all 101 violations were the two targeted edge types was inaccurate. 88 pre-existing violations remain across 8 other layers and cannot be reached without deptrac.yaml allow-list edits (forbidden by the task) or a large multi-layer refactor (out of scope). Reported for a follow-up decision; NOT baselined."
metrics:
  duration: ~35m
  completed: 2026-07-09
---

# Phase 260709-hl0 Plan 01: Fix Real Deptrac Architecture Violations Summary

Relocated `WooFieldComparator` out of the one-way Cutover sink into the Products layer and routed the three named Sync services' local DB access through Products-layer Eloquent models — eliminating all 13 Sync→Cutover and Sync→DB (SYNC-04) deptrac violations the recent Sync refactor introduced, with byte-identical SQL and zero behaviour change.

## What Was Done

### Part A — Relocate WooFieldComparator out of the Cutover sink

- **Verified target layer first:** `WooFieldComparator`'s only `use` import is `App\Domain\Products\Models\Product`. It has **no** dependency on any `App\Domain\Cutover\*` class, so nothing follows it out of Cutover.
- **Confirmed allow-lists in deptrac.yaml:** both `Cutover: [..., Products, ...]` (line 292) and `Sync: [Foundation, Products, ...]` (line 213) permit depending on Products. Products was preferred over Foundation because the comparator is product-field-comparison logic and `ProductObserver` (Products) already consumes it. Cutover→Products is allowed, so the fallback-to-Foundation condition did not apply.
- **`git mv`** `app/Domain/Cutover/Services/WooFieldComparator.php` → `app/Domain/Products/Services/WooFieldComparator.php` (history preserved), changed namespace `App\Domain\Cutover\Services` → `App\Domain\Products\Services`. No logic change.
- **Updated every real importer:**
  - `Sync\Services\WooProductWriter` — the violating `use` (was Cutover, now Products).
  - `Cutover\Services\DivergenceScanner` — previously same-namespace (no `use`); **added** `use App\Domain\Products\Services\WooFieldComparator;` since the class left the namespace.
  - Test importers: `tests/Feature/Cutover/WooFieldComparatorTest`, `tests/Feature/Cutover/DivergenceScanCommandTest`, `tests/Feature/Console/PushDivergenceToWooCommandTest`.
  - `tests/Architecture/DivergenceComparatorCoverageTest` — updated its hardcoded `base_path('app/Domain/Cutover/Services/WooFieldComparator.php')` to the new Products path (the move broke this coverage test; fixing it is a direct consequence of the move).
- **No AppServiceProvider binding existed** — the class is container-autowired via constructor injection (`DivergenceScanner`), so there was nothing to rebind. The plan's "~9 sites incl. AppServiceProvider binding" over-counted; the codebase reference at `AppServiceProvider.php:777` is a comment, not a binding.

### Part B — De-facade the three Sync services (SYNC-04)

New model `app/Domain/Products/Models/SupplierSkuCache.php`: `$table='supplier_sku_cache'`, `public $timestamps=false` (migration creates only the `sku` PRIMARY KEY column — no timestamps), `$guarded=[]` (chunked bulk `insertOrIgnore` mass-assigns freely). Mirrors `ProductPriceSnapshot`/`SupplierOfferSnapshot` conventions.

Per-service swaps (SQL byte-identical — only the entrypoint changed from the base builder to the Eloquent builder, which delegates to the same base query):

| Service | Before | After |
|---|---|---|
| `SupplierSkuRegistry` | `DB::table(self::TABLE)->truncate()` | `SupplierSkuCache::query()->truncate()` |
| `SupplierSkuRegistry` | `DB::table(self::TABLE)->insertOrIgnore($buffer)` (×2 sites) | `SupplierSkuCache::query()->insertOrIgnore($buffer)` |
| `SupplierSyncDigestComposer` | `DB::table('product_price_snapshots as today')…` (×2: price + stock) | `ProductPriceSnapshot::query()->from('product_price_snapshots as today')…` |
| `SupplierFreshnessResolver` | `DB::table('supplier_offer_snapshots')->selectRaw(…)` | `SupplierOfferSnapshot::query()->selectRaw(…)` |
| `SupplierFreshnessResolver` | `DB::connection()->getDriverName()` | `SupplierOfferSnapshot::query()->getConnection()->getDriverName()` |

- Removed `use Illuminate\Support\Facades\DB;` from all three. `Schema` facade retained in `SupplierFreshnessResolver` (it is not the banned `DB` facade). Join/select/where/orderBy/groupBy/limit chains, insert-buffer shape, and driver-aware date SQL (MySQL `DATEDIFF` vs SQLite `julianday`) are unchanged.
- Downstream call sites read result columns via `->sku` / `->old_buy` / `->days_since` etc.; these are `selectRaw` aliases hydrated as model attributes, so behaviour is identical to the prior stdClass rows. No model casts collide with the aliases.
- `SupplierSkuRegistry::TABLE` const kept (now documentary only) — plan permits either.

### TDD — new SupplierSkuRegistryTest

`tests/Feature/Sync/SupplierSkuRegistryTest.php` (the registry had none). `refresh()` reads the ~900k-row remote feed via a raw `\mysqli` connection with **no injectable seam**, so it cannot run end-to-end under the SQLite test DB. The test therefore pins the LOCAL write contract the DB→Eloquent swap actually touched, replicating the registry's exact `LOWER(TRIM())` + `mb_substr(191)` + first-seen-wins key construction:
1. truncate empties a pre-seeded (stale) cache table, then chunked `insertOrIgnore` repopulates it with the distinct normalized keys;
2. the `sku` PRIMARY KEY dedupes — a repeated key is silently ignored, no duplicate row, no throw.

Both new tests pass; all pre-existing Sync tests stay green.

## Deviations from Plan

### 1. [Rule 3 — Blocking] Coverage test hardcoded path updated
`DivergenceComparatorCoverageTest` read the comparator source from the old Cutover path. The `git mv` broke it, so the path was updated to `app/Domain/Products/Services/WooFieldComparator.php`. This is a direct, mandatory consequence of the relocation (not one of the sibling "4 test-rot" Deptrac layer files). Test green.

### 2. [SCOPE — surfaced for operator decision] Plan premise inaccurate: deptrac reached 88, not 0
The plan (and the operator brief) stated the 101 violations were entirely the two targeted edge types and that fixing them yields 0. **This is factually wrong.** Only 13 of the 101 are in the plan's scope. After the scoped fix, deptrac reports **88** violations across 8 other layers. The 14 Deptrac architecture tests assert **global** `exit 0` (both `deptrac.yaml` and `depfile.yaml`, which are functionally identical), so they remain RED until every violation is resolved. Reaching 0 is impossible within the plan's own constraints (exactly 6 files; no deptrac.yaml/depfile.yaml change). Per the operator's explicit instruction, these were **not** baselined and **not** silently patched by allow-list edits. See "Remaining Violations" below. This warrants a follow-up scoping decision.

## Verification

- **Deptrac:** 101 → 88 violations, 0 errors. All 13 targeted eliminated (Sync→Cutover WooFieldComparator ×3; SupplierSkuRegistry→DB ×4; SupplierSyncDigestComposer→DB ×3; SupplierFreshnessResolver→DB ×3). Confirmed 0 remaining Sync→Cutover and 0 DB-facade violations in the three named services.
- **`pest tests/Feature/Sync tests/Unit/Domain/Sync`:** 74 passed (237 assertions) — includes the 2 new registry tests; no SQL/behaviour regression.
- **Move-affected tests** (`WooFieldComparatorTest`, `DivergenceScanCommandTest`, `PushDivergenceToWooCommandTest`, `DivergenceComparatorCoverageTest`): 30 passed (160 assertions).
- **`pint --test`** on all changed files: PASS (pint auto-fixed import ordering in 2 test files first).
- **`pest tests/Architecture` Deptrac cases:** the 3 positive "zero violations" tests (`DeptracTest`, `DeptracSyncLayerTest`, `DeptracCutoverLayerTest`) FAIL — global deptrac is not 0 (88 pre-existing remain); all negative/planted-violator tests pass. `DivergenceComparatorCoverageTest` passes.

## Remaining Violations (88, out-of-scope, pre-existing — NOT baselined)

Grouped by source. **None are the two edge types this plan targeted.** Two are Sync-source but outside the plan's explicit 3-service file list:

- **Sync-source, not in plan scope:**
  - `Sync\Console\Commands\CheckStaleSuppliersCommand → DB` (WpDirectDb) — ×5. A 4th Sync→DB (SYNC-04) offender the plan did not list; same fix pattern would apply.
  - `Sync\Commands\ScanSupplierAddCandidatesCommand → Pricing\Services\PricingOpsReport` — ×2 (Sync→Pricing; Pricing not in Sync allow-list).
- **ProductAutoCreate → Integrations** (credential-resolver Phase-09.1 pattern; PAC allow-list missing `Integrations`): `EanSearchClient`, `IcecatClient`, `WebImageSearchClient` → `IntegrationTestResult`/`IntegrationCredentialResolver`/`IntegrationCredentialMissingException`/`IntegrationCredentialKind` — ~42.
- **Integrations → ProductAutoCreate:** `TestIntegrationAction → {EanSearch,Icecat,WebImageSearch}Client` — ×3.
- **Suggestions (allow-list is `[Foundation]` only):** `SuggestionResource → DB / RunAutoCreatePipelineJob / Competitor`, `Suggestion → DB`, `PruneOrphanSuggestionsCommand → DB` — ~17.
- **Products → Sync** (Sync not in Products allow-list): `WooGtinPublisher`/`WooGalleryPublisher → WooClient`, `PushProductFieldsToWoo → WooProductWriter`, `SupplierOfferSnapshot → SupplierFreshnessResolver` — ×7.
- **Misc DB-facade** (`-WpDirectDb` is Sync-only; these are other layers): `Products\...\AuditProductCategoriesCommand`, `Competitor\...\CsvParseErrorsByCompetitorWidget` — ×6.
- **ProductAutoCreate misc:** `ProductImageVisionValidator → Agents\ClaudeClient`, `EditAutoCreateReview → Agents\SeoContentPatchApplier` — ×4.
- **Pricing:** `PricingOperationsPage → Competitor\Models\CompetitorPrice` — ×2.

Most stem from allow-lists never being extended for later-phase cross-cutting dependencies (credential resolvers, Filament UI reaching multiple domains). Resolving them "properly" would require either allow-list edits in deptrac.yaml/depfile.yaml (out of scope per the task) or multi-layer code refactors (out of scope of this plan). Recommend a dedicated follow-up to triage each group (legit allow-list extension vs. real code smell).

## Operator Notes

- **Deploy:** push main → `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh`. **No migration** — pure code move + facade→Eloquent swap; SQL identical.
- **Not pushed, not deployed** (per instruction).
