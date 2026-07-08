---
phase: 260708-gy0-repair-the-productautocreate-test-suite-
plan: 01
subsystem: ProductAutoCreate
tags: [test-repair, latent-prod-bugs, pin-enforcement, queued-listeners]
dependency_graph:
  requires: []
  provides:
    - "product_overrides.pin_price column (price-pin enforcement now works)"
    - "Suggestion payload/correlation_id defaults (failure-audit writes no longer throw)"
    - "queued-listener viaQueue() pattern (HandleNewSupplierSku + ApplyPinsDuringSync)"
  affects: [Suggestions, Pricing, Sync, Alerting]
tech_stack:
  added: []
  patterns:
    - "Queued LISTENERS select their queue via viaQueue(), not constructor onQueue()"
    - "Byte-identity-locked services (RuleResolver/PriceCalculator/TradeRuleResolver) are driven with real data in tests, never mocked"
key_files:
  created:
    - database/migrations/2026_07_08_020000_add_pin_price_to_product_overrides_table.php
    - database/factories/Domain/Alerting/Models/AlertRecipientFactory.php
  modified:
    - app/Foundation/Audit/Services/Auditor.php
    - app/Domain/ProductAutoCreate/Services/ProductOverrideGuard.php
    - app/Domain/ProductAutoCreate/Listeners/HandleNewSupplierSku.php
    - app/Domain/ProductAutoCreate/Listeners/ApplyPinsDuringSync.php
    - app/Domain/Suggestions/Models/Suggestion.php
    - app/Domain/ProductAutoCreate/Services/FieldPinManager.php
    - app/Domain/Pricing/Models/ProductOverride.php
decisions:
  - "Did NOT remove final from RuleResolver (plan mandated it) — would break the D-03 byte-identity guard TradePricingNoV1ModificationTest. Removed final only from Auditor + ProductOverrideGuard (no byte guards)."
  - "Fixed the pin-save NOT-NULL blocker via margin_basis_points=0 default (SeoContentPatchApplier precedent), NOT by making the column nullable — avoids touching the byte-identity-locked RuleResolver/TradeRuleResolver."
metrics:
  tasks: 2
  files_created: 2
  files_modified: 7
  test_files_repaired: 11
  completed: 2026-07-08
---

# Quick 260708-gy0: Repair the ProductAutoCreate Test Suite — Summary

Repaired `tests/Feature/ProductAutoCreate` from **67 failed / 118 passed → 0 failed / 185 passed** by fixing five (actually six) latent production bugs in app code and correcting stale test-signature/expectation drift. No production feature removed; the D-03 retail-pricing byte-identity invariant was preserved.

## Before / After — `tests/Feature/ProductAutoCreate`

| | Tests line |
|---|---|
| BEFORE | `Tests: 67 failed, 118 passed` |
| AFTER  | `Tests: 185 passed (689 assertions)` — 0 failed |

## Regression check (Products / Suggestions / Pricing / Architecture)

- `tests/Feature/Products` + `tests/Feature/Suggestions` + `tests/Feature/Pricing`: **218 passed, 0 failed.**
- `tests/Architecture`: 20 failed / 100 passed — **all 20 pre-existing** (confirmed by re-running at the pre-work commit `e0bec93`; see "Pre-existing failures" below). My changes caused **zero** regressions and actually *fixed one* Architecture test (`PinnedFieldsSurviveSyncTest` went 3→2 failing because the listener no longer fatals).
- `pint --test` on every changed file: **PASS**.

## Root-cause buckets and fixes

### Code-side — six latent PRODUCTION bugs (Task 1, commit `24d3393`)

1. **Queued-listener `onQueue()` fatal (×2 listeners).** `HandleNewSupplierSku` AND `ApplyPinsDuringSync` called `$this->onQueue('sync-bulk')` in their constructor. Listeners use `InteractsWithQueue`, which — unlike jobs' `Queueable` — has **no `onQueue()`**, so the listener fatals on instantiation. Replaced with a `viaQueue(): string` selector. *The plan flagged only one listener; the second (`ApplyPinsDuringSync`, 9 failing tests) is the same root cause — a discovered deviation.*
2. **`Suggestion` NOT-NULL `payload` + `correlation_id` with no defaults.** The failure-audit hooks (`CreateWooProductJob::failed`, `ProcessAutoCreateImageJob`) write Suggestions with neither field → threw in prod. Added a `booted()` creating hook defaulting `payload=[]` and `correlation_id = Context::get('correlation_id') ?? Str::uuid()` (fills only when missing).
3. **`product_overrides.pin_price` column never migrated.** `ProductOverrideGuard` maps `regular_price → pin_price`, so price-pinning was silently a no-op. Added migration `2026_07_08_020000_add_pin_price_to_product_overrides_table` (boolean, default false, after `pin_image`) + model `$fillable`/`$casts`/`logOnly`.
4. **`FieldPinManager::savePins` authorized with a class-string.** `can('update', ProductOverride::class)` tripped `ProductOverridePolicy::update(User, ProductOverride)` (pin-save 500). Now authorises against a `ProductOverride` **instance**.
5. **`FieldPinManager::savePins` could not persist a pins-only override** — discovered second root-cause of the pin-save 500: `margin_basis_points` is NOT NULL and `savePins` never set it, so creating an override for an un-overridden product violated the constraint. Fixed by defaulting `margin_basis_points = 0` on create, matching the existing `SeoContentPatchApplier` precedent for pins-only overrides. *(Called out per the plan's "only touch app code if it reveals another real bug" instruction.)*
6. **`Auditor` + `ProductOverrideGuard` were `final`** → un-mockable. Removed `final` (matches the WpRestClient/TaxonomyResolver non-final-for-stubbing precedent).

### Test-side — signature + expectation drift (Task 2, commit `91716d2`)

| Bucket | Fix |
|---|---|
| `PublishProductJobTest` (×13, ArgumentCountError) | Added the 5th `handle()` arg `LiveSupplierStockResolver` (260702-pes) via a `noStockResolver()` mock (`resolveForSku()→null`, stock stays seeded). |
| `WooUrlPassthroughSmokeTest` + `ProcessAutoCreateImageJobTest` (WooClient TypeError) | Construct `WooClient` with the current 3-arg signature `(IntegrationLogger, IntegrationCredentialResolver, ?AutomatticClient $inner)` — inner client is arg #3. |
| `CreateWooProductJobTest` (×4) | Drive pricing through the **real** RuleResolver + PriceCalculator against a seeded default-tier rule (they are byte-identity-locked — must NOT be mocked); fixed the stale slug-probe expectation (ProductContentBuilder now composes the title `{name} {category}` → base slug `logitech-meetup-video-conferencing`). |
| `ProcessAutoCreateImageJobTest` (×3) | `WooClient::put()` tunnels updates through **POST** (`services.woo.use_post_for_updates` default true): assert `method=POST`, mock inner `post()`. |
| `TaxonomyResolverTest` (×5) | Resolver rewritten (2026-05-31) to query native `products/brands` + `products/categories` (no leading slash) with fuzzy matching; updated stubbed endpoints + rewrote the configured-`brand_taxonomy` test to the current fallback flow. |
| `ProductImageFetcherTest` (×3) | HEAD is advisory since the rewrite (logs a `head_inconclusive` event then GETs); event counts 2→3; the mislabelled-`text/html` case is now rejected at the GET size floor, not on HEAD content-type. |
| `ProductResourcePinTabTest` (×1 explicit-create) | `margin_basis_points` NOT NULL → set `4000` on the manual `create()`. |
| `HandleNewSupplierSkuTest` / `ApplyPinsDuringSyncTest` | Assert `viaQueue()` instead of `$listener->queue`/`onQueue`. |
| `AutoCreateReviewResourceTest` (×1) | The `approve` action is authorization-hidden for sales (correct). Filament now strictly asserts visibility inside `callTableAction`, so assert `assertTableActionHidden('approve', …)` instead. |
| `SuggestionResourceAutoCreateKindsTest` (×1) | `ListSuggestions` kind filter now defaults to `new_product_opportunity` (260707-gsy); select the `auto_create_failed` filter before `replay_auto_create` or the row isn't in the table. |
| `AlertRecipientAutoCreateToggleTest` (×3) | Created the missing `Database\Factories\Domain\Alerting\Models\AlertRecipientFactory` — `AlertRecipient` uses default `HasFactory` resolution which looks for that exact path, so `AlertRecipient::factory()` 500'd. |

## Deviations from the plan

- **Did NOT remove `final` from `RuleResolver`** although the plan (`<interfaces>` (1) + must-have truth 5) mandated it. Removing it changes the file's sha256 and breaks the shipped D-03 byte-identity guard `TradePricingNoV1ModificationTest`, which is itself a stated must-have ("no Architecture regressions"). The plan was internally contradictory here. I resolved it in favour of the pricing invariant: `RuleResolver`, `PriceCalculator`, and `TradeRuleResolver` are left **byte-identical** (verified: `RuleResolver.php sha256` test passes), and the one test that wanted to mock them (`CreateWooProductJobTest`) was rewritten to use real pricing instead. Only `Auditor` + `ProductOverrideGuard` (no byte guards; required by `PinnedFieldsSurviveSyncTest` + 14 mock sites) were de-`final`ed.
- **pin-save NOT-NULL fix via `margin=0`, not a nullable migration.** The "correct" design (nullable `margin_basis_points` + resolver skip) would require editing the byte-identity-locked `RuleResolver`/`TradeRuleResolver`. `margin=0` matches the shipped `SeoContentPatchApplier` convention and touches no pricing resolver. (Note: this preserves the pre-existing codebase behaviour that a pins-only override applies a 0% margin — a latent concern that predates this work and is out of scope; it would need a resolver change guarded by the D-03 invariant.)
- **Extended scope beyond `files_modified`** to reach 0 failures: fixed drift in test files not listed in the plan's frontmatter (`ProductImageFetcherTest`, `AutoCreateReviewResourceTest`, `SuggestionResourceAutoCreateKindsTest`, `ApplyPinsDuringSyncTest`, `CreateWooProductJobTest`, `ProductResourcePinTabTest`) and created `AlertRecipientFactory`. The three forbidden pre-existing working-tree changes (`storage/app/research/supplier-probe.json`, `tests/Unit/Competitor/CompetitorIngestFreshnessColorTest.php`, untracked `.claude/`) were left untouched.

## Migration + deploy note

- **Runs one migration on deploy:** `2026_07_08_020000_add_pin_price_to_product_overrides_table` (adds `pin_price` boolean, default false). Driver-portable; tested on SQLite `:memory:`, safe for MariaDB.
- After `git pull` → `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh`, prod behaviour changes (all improvements): (1) the new-supplier-SKU auto-create listener and the pin-enforcement sync listener no longer fatal on instantiation; (2) `auto_create_failed` / image-failure audit Suggestions now write successfully; (3) a pinned price (`pin_price`) is now actually enforced on supplier sync; (4) the Product pin-tab save no longer 500s for authorised users, even for products with no prior override.
- **Not pushed. Not deployed.** The human pushes.

## Pre-existing failures (NOT caused by this work — confirmed at baseline `e0bec93`)

All 20 `tests/Architecture` failures reproduce at the pre-work commit:
- **Deptrac ×14** — `deptrac analyse` fails across every domain (tooling/boundary; environment-level, pre-existing).
- **`PriceCalculatorPurityTest` ×2 + `TradePricingNoV1ModificationTest` (PriceCalculator sha256) ×1** — `PriceCalculator.php` (never touched by this work) has drifted from its pinned purity/hash invariants. Pre-existing.
- **`PinnedFieldsSurviveSyncTest` ×2** — full-sync-cycle price revert + a decimal-formatting drift (`'2100.00'` vs `'2100.0000'`). Failing at baseline (3 there → 2 here; the listener fix removed one).
- **`PinnedQuotePricesSurviveRuleEditTest` ×1** — `QueryException: no such table: customer_groups` (migration/test-infra, Phase 11; unrelated).

## Self-Check: PASSED

- Created files exist: `database/migrations/2026_07_08_020000_add_pin_price_to_product_overrides_table.php`, `database/factories/Domain/Alerting/Models/AlertRecipientFactory.php`.
- Commits exist: `24d3393` (Task 1 code fixes), `91716d2` (Task 2 test repairs).
- `tests/Feature/ProductAutoCreate`: 185 passed / 0 failed. Regression suites: no new failures vs baseline. `pint --test`: PASS.
