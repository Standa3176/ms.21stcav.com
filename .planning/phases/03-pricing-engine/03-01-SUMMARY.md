---
phase: 03-pricing-engine
plan: 01
subsystem: pricing
tags: [pricing, calculator, pennies, bcmath, vat, shield, rbac, activitylog, migrations, pest, deptrac]

requires:
  - phase: 01-foundation
    provides: DomainEvent, Auditor, LogsActivity, Gate::policy, Shield RBAC, PolicyTemplateIntegrityTest
  - phase: 02-supplier-sync
    provides: Product + ProductVariant models (ProductOverride BelongsTo target), ImportIssue (for D-10 zero-price path in Plan 02)
provides:
  - App\Domain\Pricing\Services\PriceCalculator (pure integer-pennies, single round, HALF_UP locked)
  - App\Domain\Pricing\Exceptions\SupplierPriceUnusableException (D-10 guard)
  - App\Domain\Pricing\Models\PricingRule (scope enum, priority, is_default_tier, tier bounds)
  - App\Domain\Pricing\Models\ProductOverride (UNIQUE product_id, parent-only)
  - App\Domain\Pricing\Policies\PricingRulePolicy + ProductOverridePolicy (admin + pricing_manager gated writes)
  - pricing_rules + product_overrides tables (migrations 2026_04_19_090000/090100)
  - 50-triple golden-fixture parity JSON + Pest test (Phase 3 ship gate)
  - DefaultPricingTierSeeder (3 rows, idempotent, hooked into DatabaseSeeder)
  - stripVat() helper (D-05 — Phase 5 competitor ingest reuses unchanged)
affects: [03-02 RuleResolver, 03-03 RecomputePriceListener, 03-04 Filament resources, 03-05 pricing:recompute command, 05-competitor competitor-CSV ingest (stripVat)]

tech-stack:
  added: []  # no new packages — all implementation on top of Phase 1/2 foundations (spatie/activitylog, spatie/permission, Shield, Pest)
  patterns:
    - Pure calculator service — integer pennies in, integer pennies out, no Eloquent/events/logging
    - Rounding mode locked in config/pricing.php, read at call-time from calculator
    - Basis-points arithmetic (margin_basis_points, vat_basis_points) for no-float currency math
    - Golden fixture JSON as CI-blocking ship gate (Pest dataset from eager-loaded array)
    - firstOrCreate seeder pattern preserves admin edits across deploys
    - Hand-written Policies with hasRole() guarded by Architecture-suite integrity test

key-files:
  created:
    - config/pricing.php
    - app/Domain/Pricing/Exceptions/SupplierPriceUnusableException.php
    - app/Domain/Pricing/Services/PriceCalculator.php
    - app/Domain/Pricing/Models/PricingRule.php
    - app/Domain/Pricing/Models/ProductOverride.php
    - app/Domain/Pricing/Policies/PricingRulePolicy.php
    - app/Domain/Pricing/Policies/ProductOverridePolicy.php
    - database/migrations/2026_04_19_090000_create_pricing_rules_table.php
    - database/migrations/2026_04_19_090100_create_product_overrides_table.php
    - database/factories/Domain/Pricing/PricingRuleFactory.php
    - database/factories/Domain/Pricing/ProductOverrideFactory.php
    - database/seeders/Phase3/DefaultPricingTierSeeder.php
    - tests/Fixtures/Pricing/golden-fixtures.json
    - tests/Fixtures/Pricing/generate-fixtures.php
    - tests/Unit/Pricing/PriceCalculatorGoldenFixtureTest.php
    - tests/Unit/Pricing/PriceCalculatorGuardsTest.php
    - tests/Unit/Pricing/PriceCalculatorStripVatTest.php
    - tests/Unit/Pricing/PriceCalculatorPropertyTest.php
    - tests/Feature/Pricing/PricingRuleFactoryTest.php
    - tests/Feature/Pricing/ProductOverrideFactoryTest.php
    - tests/Feature/Pricing/DefaultPricingTierSeederTest.php
  modified:
    - app/Providers/AppServiceProvider.php  # Gate::policy bindings for PricingRule + ProductOverride
    - database/seeders/DatabaseSeeder.php   # hooks DefaultPricingTierSeeder
    - database/seeders/RolePermissionSeeder.php  # %_product_override + ::pricing_rule / ::product_override LIKE patterns
    - depfile.yaml + deptrac.yaml  # Pricing → Products allowed (read-only)
    - tests/Architecture/PolicyTemplateIntegrityTest.php  # scans Domain/Pricing/Policies + Gate bindings for new models
    - tests/Feature/Phase02DataModelTest.php  # rollback step bumped 7 → 9 for new Phase 3 migrations

key-decisions:
  - "PHP_ROUND_HALF_UP locked in config/pricing.php (D-02) — match legacy plugin's bare round(); re-baseline requires a dedicated commit that also flips the golden fixtures"
  - "Integer-pennies × basis-points math only; 2^63 headroom documented for realistic £10k-supplier catalogue (numerator worst case 2.4e14)"
  - "Single round() at return boundary in BOTH compute() and stripVat() — stripVat() ships in Phase 3 per D-05 so Phase 5 competitor ingest reuses it unchanged"
  - "Golden fixture: 42 deterministic tier triples + 8 edge cases = 50 rows; source tag 'deterministic-v1-2026-04-19' or 'edge-case-2026-04-19'. Re-baseline flips source to 'live-woo-snapshot-YYYY-MM-DD' in same commit as seeder update (D-04)"
  - "Pricing domain allowed to depend on Products (read-only, for ProductOverride BelongsTo + future RuleResolver buy_price/brand_id/category_id reads)"
  - "PolicyTemplateIntegrityTest extended to scan Domain/Pricing/Policies — new policies covered by Pitfall P2-H grep guard"
  - "RolePermissionSeeder LIKE patterns extended to cover both underscore (%_pricing_rule, %_product_override) AND Shield :: separator styles (%pricing::rule, %product::override) for forward-compat"
  - "Pest dataset eager-loaded via module-level helper — base_path() inside dataset closure tripped Pest's internal initialization on Laravel 12 + PHP 8.4 + Pest 3"

patterns-established:
  - "Pure service inside Domain/Pricing/Services — no Eloquent, no events, no logging; unit-testable without TestCase/RefreshDatabase"
  - "Eager-loaded dataset pattern for Pest datasets that need filesystem access (avoids base_path() init race)"
  - "Rule-driven config: rounding mode in one place (config/pricing.php), read at call time, NO static cache — swappable via config()->set() for rounding-mode regression tests"

requirements-completed: [PRCE-01, PRCE-03, PRCE-04, PRCE-05, PRCE-06]

duration: 27min
completed: 2026-04-19
---

# Phase 3 Plan 01: Data Model + Calculator Summary

**Pure integer-pennies VAT-inclusive PriceCalculator pinned by 50-triple golden fixture (Phase 3 ship gate GREEN); pricing_rules + product_overrides schema + models + policies + default-tier seeder all landed idempotently with admin + pricing_manager write gating.**

## Performance

- **Duration:** 27 min
- **Started:** 2026-04-19T08:17:37Z
- **Completed:** 2026-04-19T08:44:51Z
- **Tasks:** 3 / 3
- **Files modified:** 25 (21 created, 6 modified)

## Accomplishments

- Phase 3 ship gate GREEN — 50/50 golden-fixture triples match to the penny via pure integer-pennies calculator; rounding mode locked in config.
- pricing_rules (D-06, D-07) + product_overrides (D-08, D-09) migrations, models with LogsActivity, hand-written Policies gating writes to admin + pricing_manager, AppServiceProvider Gate::policy bindings, and factories all ship clean with no Shield-placeholder regressions.
- DefaultPricingTierSeeder writes 3 rows (35%/28%/22%) idempotent on re-run, hooked into DatabaseSeeder; `migrate:fresh --seed --env=testing` succeeds end-to-end; full test suite 321 passed / 4343 assertions.

## Task Commits

1. **Task 1: PriceCalculator + fixtures + guards** — `e82782f` (feat; TDD RED → GREEN)
2. **Task 2: Migrations + models + factories + policies** — `c9f3f80` (feat)
3. **Task 3: Default tier seeder + DatabaseSeeder hook** — `71ae681` (feat)

## Files Created / Modified

### Calculator + fixtures (Task 1)

- `config/pricing.php` — D-02 locks PHP_ROUND_HALF_UP + D-05 VAT basis points + fixture path
- `app/Domain/Pricing/Exceptions/SupplierPriceUnusableException.php` — D-10 zero-price guard
- `app/Domain/Pricing/Services/PriceCalculator.php` — the Phase 3 ship-gate unit (compute + stripVat, pure, integer-only, single round per method)
- `tests/Fixtures/Pricing/generate-fixtures.php` — deterministic generator (same formula as calculator)
- `tests/Fixtures/Pricing/golden-fixtures.json` — 50 triples: 42 tier rows (14 × 3 buckets) + 8 edge cases
- `tests/Unit/Pricing/PriceCalculator*Test.php` — 66 unit tests across golden fixture, guards, stripVat, property (deterministic 1000-pair + rounding-mode-from-config)

### Schema + models + policies (Task 2)

- `database/migrations/2026_04_19_090000_create_pricing_rules_table.php` — scope enum, priority, is_default_tier, tier bounds, active toggle, composite indexes
- `database/migrations/2026_04_19_090100_create_product_overrides_table.php` — UNIQUE product_id, margin_basis_points, reason
- `app/Domain/Pricing/Models/{PricingRule,ProductOverride}.php` — LogsActivity on pricing-affecting columns, scope enum constants, BelongsTo(Product) on override
- `app/Domain/Pricing/Policies/{PricingRulePolicy,ProductOverridePolicy}.php` — hand-written hasRole() gates
- `database/factories/Domain/Pricing/*.php` — definition + defaultTier/brandCategory/inactive states
- `app/Providers/AppServiceProvider.php` — Gate::policy bindings registered
- `database/seeders/RolePermissionSeeder.php` — extended LIKE patterns for `%_product_override` + Shield `::` separator style
- `depfile.yaml` + `deptrac.yaml` — Pricing layer can now depend on Products (read-only)
- `tests/Architecture/PolicyTemplateIntegrityTest.php` — scans Domain/Pricing/Policies; asserts Gate bindings for PricingRule + ProductOverride
- 17 Pest feature tests across PricingRule + ProductOverride factories, schema, policy gating, Gate registration

### Seeder (Task 3)

- `database/seeders/Phase3/DefaultPricingTierSeeder.php` — 3 firstOrCreate rows (35% / 28% / 22% margins)
- `database/seeders/DatabaseSeeder.php` — calls Phase3\DefaultPricingTierSeeder after AlertRecipientSeeder
- `tests/Feature/Pricing/DefaultPricingTierSeederTest.php` — 6 tests including idempotency + admin-edit preservation

## Calculator Signature + Guard Behaviour

```php
namespace App\Domain\Pricing\Services;

final class PriceCalculator
{
    // Formula: final = round(supplier × (1 + margin/100) × (1 + vat/100), 0, HALF_UP)
    // Integer arithmetic only — numerator = supplier × (10000 + margin_bps) × (10000 + vat_bps), denom = 100_000_000.
    // Throws SupplierPriceUnusableException when $supplierPennies <= 0 (D-10).
    public function compute(int $supplierPennies, int $marginBasisPoints, int $vatBasisPoints = 2000): int;

    // Reverse direction for D-05 VAT strip. Returns 0 for non-positive input (no throw — matches D-10 spirit without
    // breaking competitor-CSV ingest on occasional bad rows).
    public function stripVat(int $grossPennies, int $vatBasisPoints = 2000): int;
}
```

**Guards:**
- `compute(0, …)` → throws `SupplierPriceUnusableException` with message "must be > 0"
- `compute(-100, …)` → same exception
- `compute(10000, 0, 2000)` → 12000 (zero margin is legal — MAP-list resale is real)
- `compute(10000, -1000, 2000)` → 10800 (negative margin accepted — v2 loss-leader promo, schema already allows it)

## Fixture Generation Recipe (deterministic-v1 + 8 edges)

Per D-04, the golden fixture is committed under `tests/Fixtures/Pricing/golden-fixtures.json` with 50 rows. Every row carries `id`, `tier`, `supplier_pennies`, `margin_basis_points`, `vat_basis_points`, `expected_final_pennies`, `source`.

**42 tier triples — 14 per bucket:**
- `<£100`: supplier 100..9900 px, margin 3500 bps, VAT 2000 — 14 evenly-spaced points via `supplier = tier_floor + step * (i + 1)`
- `£100-499`: supplier 10000..49900 px, margin 2800 bps, VAT 2000
- `£500+`: supplier 50000..250000 px, margin 2200 bps, VAT 2000 (top-of-realistic-range for AV equipment)

**8 edge cases** (ids fx-043..fx-050):
- tier boundaries (£99.99 and £100.01 either side of 10000 px; £499.99 and £500.01 either side of 50000 px)
- HALF_UP clean case (supplier 1000, 25% margin, 20% VAT → 15000)
- HALF_UP-critical (supplier 1234, 17.5% margin, 20% VAT)
- override-equipped (supplier 4567 @ 40% and 12345 @ 15%)

Every `expected_final_pennies` is computed IN THE FIXTURE using the same integer formula the calculator uses; the fixture is therefore self-consistent and the Pest ship-gate test validates that the calculator reproduces what the fixture asserts.

**Re-baseline protocol (D-04):** when ops supplies live Woo DB values, replace the fixture content and flip `source` to `"live-woo-snapshot-YYYY-MM-DD"` IN THE SAME COMMIT that updates `database/seeders/Phase3/DefaultPricingTierSeeder.php` margins. Never mix.

## Migration Timestamps + Columns

| Timestamp           | Migration                          | Columns                                                                                                                                                                                            |
| ------------------- | ---------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 2026_04_19_090000   | `create_pricing_rules_table`       | id, scope enum(brand/category/brand_category/default_tier), brand_id nullable, category_id nullable, margin_basis_points int, priority smallint default 100, is_default_tier bool, tier_min_pennies nullable, tier_max_pennies nullable, active bool default true, created_by_user_id FK users nullOnDelete, timestamps. Indexes: (scope, priority), (brand_id, category_id), (is_default_tier). |
| 2026_04_19_090100   | `create_product_overrides_table`   | id, product_id FK products UNIQUE cascadeOnDelete, margin_basis_points int, reason text nullable, created_by_user_id FK users nullOnDelete, timestamps.                                             |

## Policy Matrix

| Action                | admin | pricing_manager | sales      | read_only  |
| --------------------- | ----- | --------------- | ---------- | ---------- |
| viewAny / view        | ✅    | ✅              | ✅         | ✅         |
| create                | ✅    | ✅              | ❌         | ❌         |
| update                | ✅    | ✅              | ❌         | ❌         |
| delete                | ✅    | ✅              | ❌         | ❌         |
| restore / forceDelete | ✅    | ❌              | ❌         | ❌         |

Both `PricingRulePolicy` and `ProductOverridePolicy` use the same matrix — overrides are just targeted pricing rules.

## Default Tier Margins ↔ Golden Fixture Linkage

| Tier        | tier_min_pennies | tier_max_pennies | margin_basis_points | Fixture tier label |
| ----------- | ----------------: | ----------------: | -------------------: | ------------------ |
| <£100       | 0                 | 9999              | 3500 (35%)           | `<£100`            |
| £100-499    | 10000             | 49999             | 2800 (28%)           | `£100-499`         |
| £500+       | 50000             | NULL (open)       | 2200 (22%)           | `£500+`            |

The seeder margins AND the fixture margins move together in any re-baseline commit per D-04. A mismatch will immediately fail the Phase 3 ship-gate Pest test.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 — Missing critical functionality] Added `%_product_override` + Shield `::` style LIKE patterns to RolePermissionSeeder**
- **Found during:** Task 2 plan read
- **Issue:** Existing seeder covered `%_pricing_rule` but not `%_product_override`, and neither Shield `::` variant (`%pricing::rule`, `%product::override`). When Plan 03 runs `shield:generate` after Filament Resources land, the pricing_manager role would not auto-gain perms on the new Resources.
- **Fix:** Added 3 additional orWhere LIKE patterns covering both separator styles, matching the existing Phase 2 forward-compat pattern.
- **File modified:** database/seeders/RolePermissionSeeder.php
- **Commit:** c9f3f80

**2. [Rule 3 — Blocking issue] Deptrac Pricing → Products allow**
- **Found during:** Task 2 verification (`./vendor/bin/pest tests/Architecture/DeptracTest.php`)
- **Issue:** `Pricing: [Foundation]` alone rejected `App\Domain\Pricing\Models\ProductOverride` using `App\Domain\Products\Models\Product` for the BelongsTo relation — 2 Deptrac violations.
- **Fix:** Updated both `depfile.yaml` and `deptrac.yaml` (project has two files; runtime prefers deptrac.yaml) ruleset to `Pricing: [Foundation, Products]` with inline comment documenting read-only intent + Plan 02 RuleResolver forward compat. Cleared `.deptrac.cache`.
- **Files modified:** depfile.yaml, deptrac.yaml
- **Commit:** c9f3f80

**3. [Rule 2 — Missing critical functionality] Extended PolicyTemplateIntegrityTest to scan Pricing policies**
- **Found during:** Task 2 architecture test run
- **Issue:** The P2-H Pitfall guardrail was not scanning `app/Domain/Pricing/Policies/` — new policies were uncovered by the Shield-placeholder grep, and the Gate::policy binding resolution test didn't include the new models. T-03-01-04 mitigation depends on this coverage.
- **Fix:** Added `app_path('Domain/Pricing/Policies')` to both scan-paths; bumped positive-control minimum from 7 → 9; added PricingRule + ProductOverride to the Gate-binding assertion map.
- **File modified:** tests/Architecture/PolicyTemplateIntegrityTest.php
- **Commit:** c9f3f80

**4. [Rule 1 — Bug, self-inflicted] Removed `{{ Placeholder }}` literal from PricingRulePolicy docblock**
- **Found during:** Task 2 post-test-extension run
- **Issue:** My own docblock on PricingRulePolicy contained the literal `` `{{ Placeholder }}` `` string (used as a human-readable reference to what the Shield stub leaks). The newly-extended PolicyTemplateIntegrityTest caught it as a false-positive leak.
- **Fix:** Rewrote the docblock sentence to describe "placeholder literal strings" without embedding the actual forbidden token.
- **File modified:** app/Domain/Pricing/Policies/PricingRulePolicy.php
- **Commit:** c9f3f80

**5. [Rule 3 — Blocking issue] Phase02DataModelTest rollback step bumped 7 → 9**
- **Found during:** Full-suite regression check after Task 3
- **Issue:** `Phase02DataModelTest` hardcoded `migrate:rollback --step=7` to roll back its 6 Phase-2 tables + receives_sync_reports column. My Phase 3 adds 2 new migrations on top, so step=7 now stops short of the Phase-2 `products` + `product_variants` tables and the rollback assertions fail.
- **Fix:** Bumped step from 7 → 9 (rolls back 2 Phase 3 + 1 Phase 2 additive + 6 Phase 2 tables). Docblock rewritten to enumerate the exact migration names in rollback order. Added assertions for `pricing_rules` and `product_overrides` on both sides of the rollback round-trip.
- **File modified:** tests/Feature/Phase02DataModelTest.php
- **Commit:** 71ae681

**6. [Rule 1 — Pest integration bug] Golden-fixture dataset eager-loaded via module-level helper**
- **Found during:** Task 1 first GREEN run
- **Issue:** Pest 3 on PHP 8.4 tripped "Typed static property P\Tests\Unit\Pricing\PriceCalculatorGoldenFixtureTest::$__latestDescription must not be accessed before initialization" when the `dataset()` closure called `base_path()`. Known interaction between Pest's lazy-dataset evaluator and Laravel's path helpers during test discovery.
- **Fix:** Replaced `dataset('golden fixtures', fn () => …)->with('golden fixtures')` with a plain module-level `function goldenFixtures(): array` that eagerly loads + caches the JSON, invoked via `->with(goldenFixtures())`. Same 50 rows, same per-row labels (`"fx-037"` etc.), now executes cleanly.
- **File modified:** tests/Unit/Pricing/PriceCalculatorGoldenFixtureTest.php (during Task 1 iteration — shipped in `e82782f`)

### Manual Policy Decisions

None — Plan 03-01 had no `checkpoint:decision` tasks and I honoured every D-0x lock exactly.

## Pointer for Plan 02 (RuleResolver + RecomputePriceListener)

- `RuleResolver` in Plan 02 consumes `PricingRule` (filter by brand_id / category_id / is_default_tier / active=true) + `ProductOverride` (if exists for product_id, short-circuit and return override margin). Sort order: specificity DESC → priority DESC → id ASC (D-07).
- `RecomputePriceListener` consumes `SupplierPriceChanged` (Phase 2 event) and calls `PriceCalculator::compute()`. It MUST catch `SupplierPriceUnusableException` and log an `ImportIssue` with `issue_type=missing_cost_price` + bumped `last_seen_at` via updateOrCreate (Phase 2 D-09 seam) rather than writing £0. The existing `products.sell_price` stays untouched.
- `stripVat()` is ready for Phase 5 competitor-CSV ingest to import unchanged (D-05) — don't add a parallel VAT-strip helper in Phase 5.

## Self-Check: PASSED

**Files verified on disk (all present):**
- ✅ `config/pricing.php` — contains `PHP_ROUND_HALF_UP`
- ✅ `app/Domain/Pricing/Services/PriceCalculator.php` — `final class PriceCalculator`, 2 `round(` calls, no `float` in code (only in PHPDoc)
- ✅ `app/Domain/Pricing/Exceptions/SupplierPriceUnusableException.php`
- ✅ `app/Domain/Pricing/Models/PricingRule.php` + `ProductOverride.php` — both `LogsActivity`
- ✅ `app/Domain/Pricing/Policies/PricingRulePolicy.php` + `ProductOverridePolicy.php` — hasRole gated, no `{{ ` literals
- ✅ `database/migrations/2026_04_19_090000_create_pricing_rules_table.php` + `…_090100_create_product_overrides_table.php`
- ✅ `database/factories/Domain/Pricing/PricingRuleFactory.php` + `ProductOverrideFactory.php`
- ✅ `database/seeders/Phase3/DefaultPricingTierSeeder.php`
- ✅ `tests/Fixtures/Pricing/golden-fixtures.json` — 50 entries, every row has `expected_final_pennies`
- ✅ 4 unit tests + 3 feature tests

**Commits verified (all present in git log):**
- ✅ `e82782f` — Task 1 (calculator + fixtures)
- ✅ `c9f3f80` — Task 2 (migrations + models + policies)
- ✅ `71ae681` — Task 3 (seeder)

**End-to-end verification:**
- ✅ `php artisan migrate:fresh --env=testing --seed --no-interaction` — clean boot, 3 default-tier rows seeded
- ✅ `./vendor/bin/pest tests/Unit/Pricing tests/Feature/Pricing` — 89 passed, 3517 assertions
- ✅ `./vendor/bin/pest` (full project suite) — 321 passed, 2 skipped, 0 failed, 4343 assertions
- ✅ `./vendor/bin/pest tests/Architecture` — 7 passed (Deptrac + PolicyTemplateIntegrity both GREEN with Pricing coverage)
- ✅ `tinker: PricingRule::where('is_default_tier', true)->count()` → `3`
- ✅ `tinker: PricingRule::where('is_default_tier', true)->orderBy('tier_min_pennies')->pluck('margin_basis_points')->implode(',')` → `3500,2800,2200`
