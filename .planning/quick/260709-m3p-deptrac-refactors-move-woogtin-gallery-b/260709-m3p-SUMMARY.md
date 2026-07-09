---
phase: 260709-m3p-deptrac-refactors-move-woogtin-gallery-b
plan: 01
subsystem: infra
tags: [deptrac, architecture, namespaces, eloquent, refactor, product-auto-create, integrations]

requires:
  - phase: 260709-m3p-deptrac-allow-list-extends-add-wpdirectd
    provides: "PAC→Integrations + Integrations→ProductAutoCreate + WpDirectDb allow-list extends that make these CLEAN moves legal"
provides:
  - "16 CLEAN Deptrac violations cleared via namespaced moves + Eloquent swaps + one model inversion (identical runtime behaviour)"
  - "Deptrac 24 → 8; the 8 remaining are ONLY the Filament-in-domain cross-reads (separate follow-up)"
affects: [260709 deptrac Filament refactor follow-up]

tech-stack:
  added: []
  patterns:
    - "Facade-free DB access from the Sync layer: Model::query()->getConnection()->transaction(...) instead of DB::transaction (SYNC-04 deny preserved)"
    - "Dependency inversion: a Products MODEL scope no longer resolves a Sync SERVICE — the caller resolves the resolver and passes fresh ids into the scope"

key-files:
  created:
    - app/Domain/Products/Models/SupplierFreshnessSnapshot.php
  modified:
    - app/Domain/ProductAutoCreate/Services/WooGtinPublisher.php
    - app/Domain/ProductAutoCreate/Services/WooGalleryPublisher.php
    - app/Domain/ProductAutoCreate/Services/WooBrandPublisher.php
    - app/Domain/ProductAutoCreate/Listeners/PushProductFieldsToWoo.php
    - app/Domain/ProductAutoCreate/Commands/ScanSupplierAddCandidatesCommand.php
    - app/Domain/Integrations/Clients/ClaudeClient.php
    - app/Domain/Sync/Console/Commands/CheckStaleSuppliersCommand.php
    - app/Domain/Products/Models/SupplierOfferSnapshot.php

key-decisions:
  - "ClaudeResponse + CostCalculator stay in Agents\\Clients / Agents\\Services; ClaudeClient (moved to Integrations) references them via Integrations→Agents (allow-listed) — only the client itself moved, not its value object."
  - "WooBrandPublisher had no Products→Sync violation (it uses PAC's ProductBrandTermResolver, not WooClient) but moved with its two siblings for cohesion; pint dropped the now-same-namespace import."
  - "SupplierFreshnessSnapshot model: $timestamps=false (migration has created_at only, written explicitly per-row), $guarded=[] for machine-built bulk inserts — SQL byte-identical to the old DB::table path."
  - "scopeFreshOnly signature changed to accept array \$freshIds; the sole caller (a test) now resolves SupplierFreshnessResolver and passes freshSupplierIds()->all() — filtering behaviour unchanged."

patterns-established:
  - "git mv preserves history for domain relocations; renamespace + update importers/registrations in lockstep."

requirements-completed: []

duration: ~40min
completed: 2026-07-09
---

# Phase 260709-m3p Plan 01: Clean Deptrac Refactors Summary

**Cleared the 16 CLEAN Deptrac violations (24→8) via history-preserving namespaced moves, a facade→Eloquent swap, and one model-scope dependency inversion — identical runtime behaviour, the 358 in-scope tests unchanged, and the remaining 8 violations are exactly the deferred Filament cross-reads.**

## Performance
- **Duration:** ~40 min (excludes the two ~5–8 min full-suite runs)
- **Tasks:** 1 (six sub-groups A–F)
- **Files modified:** 8 code + 1 test + 2 registrations; 1 model created

## Accomplishments
- **Deptrac 24 → 8.** Every targeted category eliminated: Products→Sync (7), PAC→Agents/ClaudeClient (2), Sync→Pricing (2), Sync→DB (5).
- The 8 remaining are **only** the Filament-in-domain cross-reads (verified list below) — untouched per plan.
- In-scope suites `tests/Feature/Products tests/Feature/ProductAutoCreate tests/Feature/Sync tests/Unit/Domain/Sync` = **358 passed / 0 failed**, byte-for-byte with the pre-change baseline.
- `pint --test` on all changed files = **PASS**.

## The six groups (from → to)

### A. Woo publishers: Products → ProductAutoCreate
`git mv` (history preserved) + renamespace `App\Domain\Products\Services` → `App\Domain\ProductAutoCreate\Services`:
- `WooGtinPublisher.php`, `WooGalleryPublisher.php`, `WooBrandPublisher.php`

Why: they call `Sync\Services\WooClient` (a Products→Sync violation); PAC is allow-listed to use WooClient, so the move makes the arrow legal. Importers updated: 5 console commands (`SourceProductImages`, `PublishSourcedImages`, `PublishSourcedEans`, `PublishSourcedBrands`, `BackfillMerchantFeed`) + 3 publisher tests (`WooGtin/WooGallery/WooBrandPublisherTest`). `WooBrandPublisher` had no violation (uses PAC's `ProductBrandTermResolver`) but moved for cohesion.

### B. `PushProductFieldsToWoo` listener: Products → ProductAutoCreate
`git mv` to `ProductAutoCreate\Listeners` + renamespace. It uses `Sync\Services\WooProductWriter` (Products→Sync); PAC is allowed. Registration updated in `app/Providers/EventServiceProvider.php` (`use` + `$listen[ProductFieldsChangedEvent::class]`). Listener test import updated.

### C. `ClaudeClient`: Agents → Integrations
`git mv` to `Integrations\Clients` + renamespace. It is an external-API client like WooClient/BitrixClient and Integrations already resolves its api_key. `ClaudeResponse`/`CostCalculator` stay in Agents; ClaudeClient now imports them via the allow-listed Integrations→Agents arrow. This clears the PAC→Agents violation from `ProductImageVisionValidator` (PAC→Integrations allowed) and keeps every other importer legal (Agents→Integrations, Integrations-internal). ALL importers updated: `ProductImageVisionValidator`, `TestIntegrationAction`, `RunAgentJob`, `RunPricingAgentJob`, `RunSeoAgentJob`, `AssignProductTaxonomyCommand`, `GenerateProductDraftsCommand`, plus 5 test files (`use`) + 3 test files (FQCN `app(...)` calls). No container binding existed (auto-resolved via constructor injection).

### D. `ScanSupplierAddCandidatesCommand`: Sync → ProductAutoCreate
`git mv` to `ProductAutoCreate\Commands` + renamespace. It reads `Pricing\Services\PricingOpsReport::ADD_CANDIDATES_CACHE_KEY` (Sync→Pricing); PAC allows both Pricing and Sync. **Artisan signature `supplier:scan-add-candidates` unchanged.** Registration updated in `AppServiceProvider` (`use`).

### E. De-facade `CheckStaleSuppliersCommand` (Sync → DB, x5)
- New `app/Domain/Products/Models/SupplierFreshnessSnapshot.php` (mirrors SupplierOfferSnapshot; `$table='supplier_freshness_snapshots'`, `$timestamps=false`, `$guarded=[]`).
- `DB::table('supplier_offer_snapshots')…` → `SupplierOfferSnapshot::query()…` (identical chain).
- `DB::transaction(…)` → `SupplierFreshnessSnapshot::query()->getConnection()->transaction(…)`; inner truncate/insert now on `SupplierFreshnessSnapshot::query()`.
- Removed `use Illuminate\Support\Facades\DB;`. SQL is byte-identical. Sync→Products is allow-listed; the SYNC-04 `-WpDirectDb` deny is preserved (no DB facade remains).

### F. Invert `SupplierOfferSnapshot` → `SupplierFreshnessResolver` (Products→Sync)
The `scopeFreshOnly` scope previously called `app(SupplierFreshnessResolver::class)` inside a Products MODEL (reaching a Sync SERVICE). Inverted: `scopeFreshOnly(Builder $q, array $freshIds)` now takes the fresh ids as an argument; the caller resolves the Sync resolver and passes `freshSupplierIds()->all()` in. The empty-set `__NO_FRESH_SUPPLIERS__` sentinel is unchanged. Sole caller is `SupplierOfferSnapshotScopeTest`, updated to resolve + pass; it still asserts `['FRESH']` (SILENT excluded, sentinel never returned) — proving the freshness result is unchanged.

## Verification
- **Deptrac:** `Violations 24 → 8`. None are Products→Sync / PAC→Agents(ClaudeClient) / Sync→Pricing / Sync→DB. Remaining 8 (Filament only):
  1. `Pricing\Filament\Pages\PricingOperationsPage → Competitor\Models\CompetitorPrice` (x2)
  2. `ProductAutoCreate\Filament\Resources\AutoCreateReviewResource\Pages\EditAutoCreateReview → Agents\Appliers\SeoContentPatchApplier` (x2)
  3. `Suggestions\Filament\Resources\SuggestionResource → Competitor\Models\Competitor` (x2)
  4. `Suggestions\Filament\Resources\SuggestionResource → ProductAutoCreate\Jobs\RunAutoCreatePipelineJob` (x2)
- **Tests (in scope):** `pest tests/Feature/Products tests/Feature/ProductAutoCreate tests/Feature/Sync tests/Unit/Domain/Sync` → **358 passed** (matches baseline exactly).
- **Pint:** `pint --test <changed files>` → `{"result":"pass"}`.

## Deviations from Plan
None to the six groups. All applied exactly as specified; behaviour identical, guarded by the moved/updated tests.

## Deferred Issues (pre-existing, OUT OF SCOPE)
Running the extra-diligence Agents/Integrations suites surfaced **21 pre-existing failures** unrelated to this refactor (root cause: `IntegrationCredentialKind` enum grew to 10 cases while `IntegrationCredentialKindEnumTest`/`IntegrationHealthWidgetTest` still expect 7/5; plus `AgentRunGdprScrubber` QueryExceptions and `RunPricingAgentJob` closure-serialization). None of those source files are in this changeset; my only edits to `ClaudeClientTest`/`PricingAgentCalibrationTest` were import-only. Logged in `deferred-items.md` — left untouched per the SCOPE BOUNDARY rule.

## Operator notes
- **Deploy:** push `main` → `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh`. **No migration** (namespace moves + facade→Eloquent, identical SQL; `supplier_freshness_snapshots` already exists). Command signatures unchanged (`supplier:scan-add-candidates`, `suppliers:check-stale`).
- **Follow-up:** the 8 Filament cross-reads need UI-layer relocation / event routing — handled separately (260709 Deptrac Filament refactor) so the admin UI isn't put at risk in the same pass.

## Self-Check: PASSED
- All 8 moved/created code files exist at their new paths; the 6 `git mv` renames are recorded as renames (R093–R098, history preserved), 0 pure deletions in the commit.
- Old paths confirmed gone (`Products\Services\Woo*Publisher`, `Agents\Clients\ClaudeClient`, `Sync\Commands\ScanSupplierAddCandidatesCommand`).
- Commit `a836d8d` created on `main`; NOT pushed (`main...origin/main [ahead 1]`).
- Pre-existing working-tree changes (supplier-probe.json, CompetitorIngestFreshnessColorTest.php, .claude/) left untouched and unstaged.
