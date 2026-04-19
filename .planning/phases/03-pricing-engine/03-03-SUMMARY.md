---
phase: 03-pricing-engine
plan: 03
subsystem: pricing
tags: [pricing, filament, rbac, shield, rule-explorer, simulated-impact, dry-run, pest, policy-integrity, deptrac]

requires:
  - phase: 01-foundation
    provides: Shield RBAC pattern, Gate::policy bindings, PolicyTemplateIntegrityTest, ->authorize() Filament Actions pattern (Warning 9)
  - phase: 02-supplier-sync
    provides: Filament 3 Resource pattern (domain-local discovery), RolePermissionSeeder LIKE-pattern forward-compat, ImportIssueResource style reference
  - phase: 03-pricing-engine plan 01
    provides: PricingRule / ProductOverride models + policies, RolePermissionSeeder LIKE patterns for both separator styles, PolicyTemplateIntegrityTest Phase 3 coverage
  - phase: 03-pricing-engine plan 02
    provides: RuleResolver + PricingResolution + PriceCalculator + brand/category keys on products/variants
provides:
  - App\Domain\Pricing\Filament\Resources\PricingRuleResource (CRUD + custom pages)
  - App\Domain\Pricing\Filament\Resources\ProductOverrideResource (CRUD + form-layer D-08 unique)
  - App\Domain\Pricing\Filament\Resources\PricingRuleResource\Pages\RuleExplorer (PRCE-08)
  - App\Domain\Pricing\Filament\Resources\PricingRuleResource\Pages\SimulatedImpact (PRCE-09)
  - App\Domain\Pricing\Services\SimulatedImpactCalculator (dry-run transactional resolver+calculator loop)
  - App\Domain\Pricing\Services\SimulatedImpactRow (readonly DTO consumed by Plan 04 bulk command)
  - Blade views: rule-explorer, simulated-impact (full UI with coloured badges + delta colouring)
  - AdminPanelProvider: discoverResources for Domain/Pricing/Filament/Resources
affects: [03-04 PricingRecomputeCommand reuses SimulatedImpactCalculator pattern, 03-05 VERIFICATION uses these Resources for manual probe, Phase 7 CSV export on simulated impact]

tech-stack:
  added: []  # no new packages
  patterns:
    - Domain-local Filament Resource discovery via AdminPanelProvider::discoverResources
    - ->authorize() on every Filament Action (Warning 9 defence-in-depth)
    - Filament custom Page + Livewire test pattern (fillForm → call → assertSet)
    - DTO → array conversion at page/livewire boundary (readonly classes not Livewire-marshallable)
    - DB::beginTransaction + rollBack dry-run pattern for UI preview of mutating operations
    - Shield --ignore-existing-policies flag preserves hand-written Policies during generate

key-files:
  created:
    - app/Domain/Pricing/Filament/Resources/PricingRuleResource.php
    - app/Domain/Pricing/Filament/Resources/PricingRuleResource/Pages/ListPricingRules.php
    - app/Domain/Pricing/Filament/Resources/PricingRuleResource/Pages/CreatePricingRule.php
    - app/Domain/Pricing/Filament/Resources/PricingRuleResource/Pages/EditPricingRule.php
    - app/Domain/Pricing/Filament/Resources/PricingRuleResource/Pages/RuleExplorer.php
    - app/Domain/Pricing/Filament/Resources/PricingRuleResource/Pages/SimulatedImpact.php
    - app/Domain/Pricing/Filament/Resources/ProductOverrideResource.php
    - app/Domain/Pricing/Filament/Resources/ProductOverrideResource/Pages/ListProductOverrides.php
    - app/Domain/Pricing/Filament/Resources/ProductOverrideResource/Pages/CreateProductOverride.php
    - app/Domain/Pricing/Filament/Resources/ProductOverrideResource/Pages/EditProductOverride.php
    - app/Domain/Pricing/Services/SimulatedImpactCalculator.php
    - app/Domain/Pricing/Services/SimulatedImpactRow.php
    - resources/views/filament/pages/rule-explorer.blade.php
    - resources/views/filament/pages/simulated-impact.blade.php
    - tests/Feature/Pricing/PricingRuleResourceAccessTest.php
    - tests/Feature/Pricing/RuleExplorerPageTest.php
    - tests/Feature/Pricing/SimulatedImpactCalculatorTest.php
  modified:
    - app/Providers/Filament/AdminPanelProvider.php  # Pricing Resources discovery
    - tests/Architecture/PolicyTemplateIntegrityTest.php  # positive control title 7 → 9, docblock update
    - deptrac.yaml  # Pricing allowlist adds WpDirectDb
    - depfile.yaml  # same change (project keeps two files in sync)

key-decisions:
  - "Shell `--ignore-existing-policies` passed to shield:generate — no destructive policy overwrite; Phase 1/2 restore protocol not required this plan"
  - "Shield permission emission for Phase 3 Resources confirmed `::` separator style: `view_pricing::rule`, `view_product::override` etc (matches Phase 2 Plan 02-04 post-discovery). RolePermissionSeeder LIKE patterns from Plan 01 deviation 1 already cover both styles — 24 perms attached to pricing_manager (12 per resource) on re-seed"
  - "SimulatedImpact page property named `$rule` (not `$record`) — Livewire's parameter reconciliation re-assigns the scalar route binding to any matching typed public property on re-render, so a typed PricingRule `$record` throws on hydration. Blade uses `$rule` end-to-end"
  - "SimulatedImpactRow DTOs converted to plain arrays at the page boundary — Livewire's HandleComponents hydrator cannot marshal final readonly classes across the wire. DTO stays the service-level contract (Plan 04 bulk command will continue to use it); only the Filament page flattens"
  - "SimulatedImpact::canAccess uses hasAnyRole(admin + pricing_manager) directly — can('update', PricingRule::class) invokes the hand-written policy method with a class-string while its signature requires a PricingRule instance. Role check matches PricingRulePolicy::update() matrix precisely without that gap"
  - "Pricing layer now allowed WpDirectDb in deptrac — SimulatedImpactCalculator requires DB::beginTransaction + rollBack for the PRCE-09 dry-run contract. SYNC-04's -WpDirectDb deny rule still applies to the Sync layer (Woo REST only constraint unchanged)"
  - "Plan Task 4 (PolicyTemplateIntegrityTest extension) was ALREADY IMPLEMENTED in Plan 01 deviation 3 — this plan only updated the test title (7 → 9 in the description) to match the existing >=9 assertion; scan paths, pairs map, and assertion were already Phase-3-aware"

patterns-established:
  - "Filament custom Page with form + action pattern: `public ?array $data = []; form()->statePath('data'); action('lookup')` + blade `wire:click`"
  - "Livewire test pattern for Filament Pages: `livewire(Page::class, [params])->fillForm(...)->call('method')->assertSet('prop.path', value)`"
  - "Shield `--ignore-existing-policies --panel=admin --no-interaction` is the safe re-run invocation; without the flag, hand-written policies get stubbed"
  - "Domain layer allowed to use DB facade when the operation is architecturally bounded (dry-run, explicit transaction control); deny rules remain at other layers"

requirements-completed: [PRCE-08, PRCE-09]

metrics:
  duration: 23min
  started: 2026-04-19T09:13:39Z
  completed: 2026-04-19T09:36:57Z
  tasks_completed: 4
  files_changed: 20  # 17 created, 3 modified (+2 deptrac sync)
  pest_tests_added: 37  # 20 access + 9 rule-explorer + 8 simulated-impact
---

# Phase 3 Plan 03: Filament Rule Explorer + Simulated Impact Summary

**Filament CRUD for PricingRule + ProductOverride with role-gated Actions; Rule Explorer page resolves any SKU → effective retail price + full coloured resolution chain (PRCE-08); Simulated Impact page projects a proposed rule's effect across the catalogue inside a rolled-back DB transaction — 0 persisted writes, 0 dispatched events (PRCE-09).**

## Performance

- **Duration:** 23 min
- **Started:** 2026-04-19T09:13:39Z
- **Completed:** 2026-04-19T09:36:57Z
- **Tasks:** 4 / 4
- **Files changed:** 20 (17 created, 3 modified, +2 deptrac sync)
- **Pest tests added:** 37 (20 access + 9 rule-explorer + 8 simulated-impact)
- **Full suite:** 389 passed / 2 skipped / 4522 assertions / 0 failed

## Accomplishments

- Two Filament 3 Resources shipped under `app/Domain/Pricing/Filament/Resources/` — discoverable by AdminPanelProvider, grouped under "Pricing" navigation, with form + table + filters + actions all role-gated.
- Rule Explorer page (PRCE-08) — pricing manager types a SKU, RuleResolver walks the 5 layers (override → brand_category → category → brand → default_tier), PriceCalculator computes the effective retail price, and the page renders both with coloured badges per layer + drill-down links to the matched rule or override.
- Simulated Impact page (PRCE-09) — pricing manager opens any rule, clicks Simulate, and sees the count of affected SKUs + first 50 rows of sku / current_price / proposed_price / delta. The whole projection runs inside `DB::beginTransaction` → `DB::rollBack` in finally — verified by a test that mutates a rule in-memory, simulates, and asserts the disk-state is unchanged.
- Shield regeneration via `--ignore-existing-policies` preserved the hand-written PricingRulePolicy + ProductOverridePolicy — no placeholder leaks, PolicyTemplateIntegrityTest remains green.
- RolePermissionSeeder's Plan 01 forward-compat LIKE patterns (`%pricing::rule`, `%product::override`) correctly attached 12 pricing_rule + 12 product_override permissions to pricing_manager on re-seed — no seeder change required in this plan.

## Task Commits

1. **Task 1 — PricingRuleResource + ProductOverrideResource + role-gating tests** — `46eafbd`
2. **Task 2 — RuleExplorer full blade UI + 9 resolution + access tests (PRCE-08)** — `b349bf9`
3. **Task 3 — SimulatedImpactCalculator + Simulated Impact page + 8 tests (PRCE-09)** — `f0c2c99`
4. **Task 4 — PolicyTemplateIntegrityTest positive-control 7 → 9** — `d93f22f`
5. **Deptrac fix — allow WpDirectDb in Pricing for SimulatedImpactCalculator** — `3dc02af`

## Navigation + Routes

| Resource | Nav group | Nav sort | URL (admin panel) |
|----------|-----------|---------:|-------------------|
| PricingRuleResource | Pricing | 10 | /admin/pricing-rules |
| RuleExplorer (page) | Pricing | 15 | /admin/pricing-rules/rule-explorer |
| SimulatedImpact (page) | Pricing | n/a | /admin/pricing-rules/{record}/simulated-impact |
| ProductOverrideResource | Pricing | 20 | /admin/product-overrides |

Rule Explorer is discoverable both as a top-level nav item under "Pricing" (via its `$navigationLabel`) AND as a header action on ListPricingRules. SimulatedImpact is reached via a row-level action on the rules table AND via the edit page header.

## Role Matrix (verified by Test Pass)

| Action                                | admin | pricing_manager | sales | read_only |
| ------------------------------------- | ----- | --------------- | ----- | --------- |
| View Pricing Rules list               | ✅    | ✅              | ✅    | ✅        |
| Create / update / delete rule         | ✅    | ✅              | ❌    | ❌        |
| View Product Overrides list           | ✅    | ✅              | ✅    | ✅        |
| Create / update / delete override     | ✅    | ✅              | ❌    | ❌        |
| Access Rule Explorer (viewAny)        | ✅    | ✅              | ✅    | ✅        |
| Access Simulated Impact (update gate) | ✅    | ✅              | ❌    | ❌        |

Rule Explorer is intentionally open to all 4 roles — it's a read-only "why does this SKU cost that" tool that even sales should be able to consult. Simulated Impact requires write-intent because its purpose is "what WOULD change if I saved this" — noise for read-only roles.

## Rule Explorer Behaviour (PRCE-08)

Input: one SKU. Output: the resolution chain the RuleResolver walked, highlighting the matched layer.

```
SKU: LOG-C930E
Product #1234 / Variant #5678 (optional)
Effective retail price: £129.60   ← bold, emerald
From £60.00 buy price · 22.00% margin

Resolution chain:
override → brand_category ✓ → category → brand → default_tier
           (highlighted emerald — the matching source)

Matched rule #42 → drill-down link to edit page
```

**Error paths:**
- Empty SKU → "Enter a SKU to look up."
- Unknown SKU → "No product found for SKU {sku}."
- Zero / null buy_price → "Product has zero / null buy_price — no retail price computable. Check the Import Issues page for missing cost-price entries."
- NoPricingRuleMatchedException → exception message ("catalogue incomplete — default tiers may be missing or buy_price out of all tier ranges")

**Variant SKU lookup falls back to parent Product** — if no `products.sku` match, the page tries `product_variants.sku` and uses the variant's product for resolution + variant's buy_price for calculation.

## Simulated Impact Contract (PRCE-09)

```
SimulatedImpactCalculator::simulate(PricingRule $proposedRule, int $limit = 50): array{
    count: int,                          # total affected SKUs
    rows:  array<int, SimulatedImpactRow> # first $limit with sku, current, proposed, delta, source
}
```

**Transactional dry-run flow:**
1. `DB::beginTransaction()`
2. Persist the hypothetical rule (insert OR update — supports "what if I edit this rule" + "what if I create this rule")
3. Walk every product with `buy_price > 0` in `chunkById(500)` batches
4. For each: resolve → compute → compare to stored sell_price
5. Drop rows where proposed == current (noise exclusion)
6. `DB::rollBack()` in finally — nothing persists, no `ProductPriceChanged` event fires

**Catch-all on resolver per product:** one broken product (catalogue-incomplete, zero price) must not fail the whole simulation — the try/catch skips that row and continues.

## Shield Permission Emission (Phase 3 Confirmed)

Running `php artisan shield:generate --resource=PricingRuleResource --resource=ProductOverrideResource --ignore-existing-policies --panel=admin` emitted permissions with the `::` separator pattern (as predicted by Plan 01 deviation 1 forward-compat):

| Resource | Permission name pattern emitted |
|----------|---------------------------------|
| PricingRuleResource | `view_pricing::rule`, `view_any_pricing::rule`, `create_pricing::rule`, `update_pricing::rule`, `delete_pricing::rule`, `delete_any_pricing::rule`, `restore_pricing::rule`, `restore_any_pricing::rule`, `replicate_pricing::rule`, `reorder_pricing::rule`, `force_delete_pricing::rule`, `force_delete_any_pricing::rule` (12 total) |
| ProductOverrideResource | Same 12 actions × `product::override` = 12 total |

RolePermissionSeeder re-run attached all 24 to `pricing_manager`, 0 to `sales`, 4 view-only to `read_only`.

## PolicyTemplateIntegrityTest State

The Phase 3 architectural guard was **already fully in place from Plan 01 deviation 3**. This plan only updated the test title string (`"at least 7 Policy files"` → `"at least 9 Policy files"`) and docblock comment to match the >=9 assertion that was already active. Scan paths + pairs map already covered both Pricing models and their policies.

3 Architecture tests verified green after the update:
1. No `{{ ` literal Shield placeholder in any Policy file ✅
2. ≥9 Policy files across 6 scan paths ✅
3. Gate::policy bindings resolve to Domain / app Policies (not stubs) — PricingRule → PricingRulePolicy, ProductOverride → ProductOverridePolicy both asserted ✅

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 — Blocking issue] Pricing layer Deptrac allowlist adds WpDirectDb**
- **Found during:** Task 3 post-write full test run — `DeptracSyncLayerTest` failed with 3 violations (SimulatedImpactCalculator imports `Illuminate\Support\Facades\DB`).
- **Issue:** Phase 2 Plan 05 added a global `WpDirectDb` Deptrac layer (classLike regex on `Illuminate\Support\Facades\DB`) and denied it from `Sync:` as SYNC-04. The default rule set treats any layer not explicitly allowing WpDirectDb as a violation. `Pricing:` didn't list it — but `SimulatedImpactCalculator` unavoidably needs `DB::beginTransaction` + `DB::rollBack` for the dry-run contract (this is THE core architectural intent of PRCE-09).
- **Fix:** Added `WpDirectDb` to `Pricing:` allowlist in BOTH `deptrac.yaml` and `depfile.yaml` with an inline comment documenting the narrowly-scoped intent. Sync's `-WpDirectDb` deny rule remains — the ban is per-layer. Cleared `.deptrac.cache`. DeptracTest + DeptracSyncLayerTest both green.
- **Files modified:** `deptrac.yaml`, `depfile.yaml`
- **Commit:** `3dc02af`

**2. [Rule 1 — Bug, self-inflicted] RuleExplorer::canAccess signature mismatch**
- **Found during:** Task 1 first `shield:generate` run (ErrorException raised at class load).
- **Issue:** My RuleExplorer::canAccess() signature was `(): bool` but the parent `Filament\Resources\Pages\Page::canAccess(array $parameters = []): bool` requires the array param. PHP 8.4 signature-contravariance rules rejected the declaration.
- **Fix:** Changed signature to `canAccess(array $parameters = []): bool`. Same fix applied to SimulatedImpact::canAccess.
- **File modified:** `app/Domain/Pricing/Filament/Resources/PricingRuleResource/Pages/RuleExplorer.php` (part of Task 1 commit)
- **Commit:** `46eafbd`

**3. [Rule 1 — Bug] SimulatedImpact $record typed-property hydration race**
- **Found during:** Task 3 Test 7 first run.
- **Issue:** Livewire's nested-component parameter reconciliation re-assigns public-property values that match mount-param names on every re-render. A typed `public PricingRule $record;` received the scalar `int` route binding and threw a type error. The `(array $parameters = [])` canAccess contract is what put `record` in the reconciliation bucket.
- **Fix:** Renamed the page's internal property to `$rule` (not `$record`), updated the blade view end-to-end to use `$rule`. Mount still accepts `int|string $record` as a mount param (Filament's routing contract).
- **File modified:** `app/Domain/Pricing/Filament/Resources/PricingRuleResource/Pages/SimulatedImpact.php`, `resources/views/filament/pages/simulated-impact.blade.php`
- **Commit:** `f0c2c99`

**4. [Rule 1 — Bug] SimulatedImpactRow readonly class not Livewire-marshallable**
- **Found during:** Task 3 Test 7 second run (after the $record→$rule rename fixed).
- **Issue:** Livewire's `HandleComponents::getSynthesizerByType` throws "Property type not supported in Livewire" for `final readonly class SimulatedImpactRow`. Livewire can serialise arrays, primitives, models, Collections, enums — but not arbitrary value objects.
- **Fix:** At the page boundary, SimulatedImpact::simulate() converts the `SimulatedImpactRow[]` to plain arrays keyed by the DTO property names. Service-layer contract unchanged — DTOs still cross the Plan 04 bulk-command boundary. Blade updated to use array-key access (`$row['sku']` etc) instead of property access.
- **File modified:** `app/Domain/Pricing/Filament/Resources/PricingRuleResource/Pages/SimulatedImpact.php`, `resources/views/filament/pages/simulated-impact.blade.php`
- **Commit:** `f0c2c99`

**5. [Rule 1 — Bug] SimulatedImpact canAccess gate class-vs-instance mismatch**
- **Found during:** Task 3 Test 8 first run.
- **Issue:** `$user->can('update', PricingRule::class)` passes the class-string, but the hand-written `PricingRulePolicy::update(User $user, PricingRule $rule): bool` requires a PricingRule instance. Gate::denies hit a TypeError before returning bool.
- **Fix:** Replaced with `hasAnyRole(['admin', 'pricing_manager'])` — precisely matches the policy's update matrix without the class/instance gap. Role check is intent-equivalent because every PricingRulePolicy write method delegates to the same `hasAnyRole(['admin', 'pricing_manager'])` check.
- **File modified:** `app/Domain/Pricing/Filament/Resources/PricingRuleResource/Pages/SimulatedImpact.php`
- **Commit:** `f0c2c99`

**6. [Rule 1 — Test bug] Variant SKU test buy_price expectation**
- **Found during:** Task 2 Test 7 first run.
- **Issue:** My test created a variable parent Product WITHOUT setting buy_price, then a variant with buy_price=50.00. RuleResolver reads `$product->buy_price` for default-tier matching, but the variable-product ProductFactory default is `fake()->randomFloat(2, 10, 500)` — sometimes lands in the £100-499 tier (28% margin) instead of <£100 (35% margin). My test expected 8100 (35% of 5000 after VAT) but got 7680 (28% of 5000 after VAT).
- **Fix:** Explicitly set the parent's buy_price=50.00 in the test — ensures default-tier <£100 matches deterministically.
- **File modified:** `tests/Feature/Pricing/RuleExplorerPageTest.php`
- **Commit:** `b349bf9`

### Manual Policy Decisions

None. Plan 03-03 had no `checkpoint:decision` tasks and every D-0x + Phase 1/2 convention was honoured.

## Pointer for Plan 04 (PricingRecomputeCommand)

- `SimulatedImpactCalculator` is reusable for the bulk command's `--dry-run` mode: drop the DB::beginTransaction wrapper (it reads ALREADY-persisted rules, not hypothetical ones), keep the chunkById(500) iteration + per-product try/catch + integer-pennies diff.
- `SimulatedImpactRow` is the right DTO for the bulk command's CSV report (sku, current, proposed, delta, source) — extract the mapper into a shared helper if both the page and the command use the same format.
- `--live` mode should dispatch `ProductPriceChanged` per row that changed (matching Phase 2 listener's fire-on-diff behaviour D-13).

## Pointer for Plan 05 (VERIFICATION gate)

- Manual probe: log in as `pricing_manager`, visit `/admin/pricing-rules/rule-explorer`, type a seeded SKU → confirm effective price + coloured resolution chain render.
- Manual probe: edit any rule → click "Simulated Impact" → confirm count + first N rows render with coloured delta columns.
- Ship-gate regression check: `vendor/bin/pest tests/Architecture` must stay at 3 passing PolicyTemplateIntegrityTest cases (placeholder grep, ≥9 policies, Gate bindings) after every `shield:generate` run.

## Threat Flags

None new. The plan's `<threat_model>` surface (T-03-03-01..T-03-03-07) was fully honoured:
- T-03-03-01 (unauthorised rule mod): Gate::policy + ->authorize() on every action + role-gating access test.
- T-03-03-02 (override smuggling): Form `->unique(ignoreRecord: true)` + DB UNIQUE + policy gate.
- T-03-03-03 (accidental persist on Simulated Impact): DB::beginTransaction + DB::rollBack in finally + rollback test.
- T-03-03-04 (info disclosure in Rule Explorer): accept — internal tool, viewAny gate covers it.
- T-03-03-05 (PolicyTemplateIntegrityTest weakened): 3 tests still green, positive control floor raised 7 → 9.
- T-03-03-06 (DoS on 15k catalogue): accept — chunkById(500) bounded memory.
- T-03-03-07 (Rule Explorer accepts any SKU): accept — Eloquent parameter binding, no SQL injection surface.

## Self-Check: PASSED

**Files verified on disk (all present):**
- ✅ `app/Domain/Pricing/Filament/Resources/PricingRuleResource.php` — navigation group "Pricing", `->authorize(` on actions
- ✅ `app/Domain/Pricing/Filament/Resources/ProductOverrideResource.php` — `->unique(ignoreRecord: true)`, `->authorize(`
- ✅ `app/Domain/Pricing/Filament/Resources/PricingRuleResource/Pages/RuleExplorer.php` — `RuleResolver`, `PriceCalculator`, `public function lookup`, `canAccess`
- ✅ `app/Domain/Pricing/Filament/Resources/PricingRuleResource/Pages/SimulatedImpact.php` — `simulate()`, `canAccess`, `$rule` property
- ✅ `app/Domain/Pricing/Services/SimulatedImpactCalculator.php` — `DB::beginTransaction`, `DB::rollBack`, `chunkById`
- ✅ `app/Domain/Pricing/Services/SimulatedImpactRow.php` — `final readonly class SimulatedImpactRow`
- ✅ `resources/views/filament/pages/rule-explorer.blade.php` — `chain`, `resolution`, coloured badges
- ✅ `resources/views/filament/pages/simulated-impact.blade.php` — `$rule`, `$result`, delta colouring
- ✅ `app/Providers/Filament/AdminPanelProvider.php` — `discoverResources` for `Domain/Pricing/Filament/Resources`
- ✅ `database/seeders/RolePermissionSeeder.php` — `%product_override` + `%product::override` patterns (inherited from Plan 01)
- ✅ `tests/Architecture/PolicyTemplateIntegrityTest.php` — `Domain/Pricing/Policies`, `PricingRulePolicy::class`, `ProductOverridePolicy::class`, `toBeGreaterThanOrEqual(9`
- ✅ No `{{ ` literals in `app/Domain/Pricing/Policies/*.php`

**Commits verified (all present in git log):**
- ✅ `46eafbd` — feat(03-03): PricingRule + ProductOverride Filament Resources + role-gating tests
- ✅ `b349bf9` — feat(03-03): RuleExplorer blade + resolution + access tests (PRCE-08)
- ✅ `f0c2c99` — feat(03-03): SimulatedImpactCalculator + Simulated Impact page (PRCE-09)
- ✅ `d93f22f` — test(03-03): PolicyTemplateIntegrityTest positive control 7 → 9
- ✅ `3dc02af` — fix(03-03): allow WpDirectDb in Pricing layer for SimulatedImpactCalculator

**End-to-end verification:**
- ✅ `php artisan shield:generate --resource=PricingRuleResource --resource=ProductOverrideResource --ignore-existing-policies --panel=admin --no-interaction` — 24 new permissions created, 0 placeholder leaks
- ✅ `php artisan db:seed --class=RolePermissionSeeder --no-interaction` — pricing_manager=24, admin=24, read_only=4, sales=0
- ✅ `tinker: pricing_manager pricing_rule perms (both styles) = 12 ≥ 7` ✅
- ✅ `tinker: pricing_manager product_override perms (both styles) = 12 ≥ 7` ✅
- ✅ `vendor/bin/pest tests/Feature/Pricing tests/Architecture` — 80 passed, 245 assertions
- ✅ `vendor/bin/pest` (full project suite) — 389 passed, 2 skipped, 0 failed, 4522 assertions
- ✅ `vendor/bin/deptrac analyse` — 0 violations, 58 allowed (WpDirectDb correctly scoped to Pricing + Sync)
- ✅ `php artisan route:list --name=filament.admin.resources.pricing-rules` — 5 routes: index, create, edit, rule-explorer, simulated-impact
