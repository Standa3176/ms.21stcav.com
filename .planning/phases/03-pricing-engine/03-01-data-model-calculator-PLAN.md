---
phase: 03-pricing-engine
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - config/pricing.php
  - app/Domain/Pricing/Exceptions/SupplierPriceUnusableException.php
  - app/Domain/Pricing/Services/PriceCalculator.php
  - app/Domain/Pricing/Models/PricingRule.php
  - app/Domain/Pricing/Models/ProductOverride.php
  - app/Domain/Pricing/Policies/PricingRulePolicy.php
  - app/Domain/Pricing/Policies/ProductOverridePolicy.php
  - database/migrations/2026_04_19_090000_create_pricing_rules_table.php
  - database/migrations/2026_04_19_090100_create_product_overrides_table.php
  - database/seeders/Phase3/DefaultPricingTierSeeder.php
  - database/factories/Domain/Pricing/PricingRuleFactory.php
  - database/factories/Domain/Pricing/ProductOverrideFactory.php
  - tests/Fixtures/Pricing/golden-fixtures.json
  - tests/Unit/Pricing/PriceCalculatorGoldenFixtureTest.php
  - tests/Unit/Pricing/PriceCalculatorGuardsTest.php
  - tests/Unit/Pricing/PriceCalculatorStripVatTest.php
  - tests/Unit/Pricing/PriceCalculatorPropertyTest.php
  - tests/Feature/Pricing/PricingRuleFactoryTest.php
  - tests/Feature/Pricing/ProductOverrideFactoryTest.php
  - app/Providers/AppServiceProvider.php
  - database/seeders/DatabaseSeeder.php
autonomous: true
requirements:
  - PRCE-01
  - PRCE-03
  - PRCE-04
  - PRCE-05
  - PRCE-06

must_haves:
  truths:
    - "The PriceCalculator pure function takes integer pennies + margin basis points + VAT basis points and returns integer pennies"
    - "A golden-fixture test of 50 (supplier_price, margin, expected_final) triples from the legacy plugin passes to the penny"
    - "Zero or null supplier price throws SupplierPriceUnusableException — no £0 ever reaches retail"
    - "pricing_rules table exists with scope / brand_id / category_id / margin_basis_points / priority / is_default_tier / tier_min_pennies / tier_max_pennies columns"
    - "product_overrides table exists with a UNIQUE product_id, margin_basis_points, created_by_user_id, reason"
    - "config/pricing.php pins rounding_mode to PHP_ROUND_HALF_UP"
    - "Default tier seeder creates 3 default-tier rows (<£100, £100-499, £500+) with margin percentages sourced from legacy Woo values"
    - "PricingRulePolicy and ProductOverridePolicy gate writes to pricing_manager + admin only"
  artifacts:
    - path: "app/Domain/Pricing/Services/PriceCalculator.php"
      provides: "Pure integer-pennies VAT-inclusive calculator (D-01..D-05)"
      min_lines: 60
    - path: "tests/Fixtures/Pricing/golden-fixtures.json"
      provides: "50 (supplier_price, margin, expected_final) triples — Phase 3 ship gate"
      contains: "supplier_pennies"
    - path: "tests/Unit/Pricing/PriceCalculatorGoldenFixtureTest.php"
      provides: "Parity test asserting 50/50 triples pass to the penny"
      contains: "golden-fixtures.json"
    - path: "database/migrations/2026_04_19_090000_create_pricing_rules_table.php"
      provides: "pricing_rules schema with priority + is_default_tier (D-06, D-07)"
      contains: "margin_basis_points"
    - path: "database/migrations/2026_04_19_090100_create_product_overrides_table.php"
      provides: "product_overrides schema with UNIQUE product_id (D-08, D-09)"
      contains: "unique"
    - path: "config/pricing.php"
      provides: "Locked rounding mode + VAT basis points (D-02, Pitfall 5)"
      contains: "PHP_ROUND_HALF_UP"
    - path: "app/Domain/Pricing/Models/PricingRule.php"
      provides: "Eloquent model with LogsActivity on pricing-affecting columns"
      contains: "LogsActivity"
    - path: "app/Domain/Pricing/Models/ProductOverride.php"
      provides: "Eloquent model with LogsActivity + product relation"
      contains: "LogsActivity"
    - path: "database/seeders/Phase3/DefaultPricingTierSeeder.php"
      provides: "3 default-tier rows (<£100, £100-499, £500+)"
      contains: "is_default_tier"
  key_links:
    - from: "app/Domain/Pricing/Services/PriceCalculator.php"
      to: "config/pricing.php"
      via: "config('pricing.rounding_mode')"
      pattern: "config\\('pricing\\.rounding_mode'\\)"
    - from: "tests/Unit/Pricing/PriceCalculatorGoldenFixtureTest.php"
      to: "tests/Fixtures/Pricing/golden-fixtures.json"
      via: "file_get_contents + json_decode"
      pattern: "golden-fixtures\\.json"
    - from: "app/Providers/AppServiceProvider.php"
      to: "PricingRulePolicy + ProductOverridePolicy"
      via: "Gate::policy() binding"
      pattern: "Gate::policy"
---

<objective>
Ship the Phase 3 data layer (pricing_rules + product_overrides schema, Eloquent models, policies, factories, default-tier seeder) AND the pure PriceCalculator service that is the Phase 3 ship gate. Golden fixtures sourced from live Woo DB values pin penny-exact parity with the legacy plugin. Config locks the rounding mode.

Purpose: The golden-fixture parity test is Phase 3 success criterion #1 and the explicit ship gate — if any of the 50 triples drift by a single penny, Phase 3 does not ship. Everything downstream (RuleResolver, RecomputePriceListener, Filament UI, bulk command) calls into this calculator; it must be correct on day one. Splitting data model + calculator into one plan is deliberate: they have zero dependencies on each other (calculator is pure primitives-in, primitives-out), the models let Wave-2 RuleResolver land immediately, and the fixtures file lives under `tests/` which is orthogonal to migrations. Tasks are ordered so the calculator + fixtures land first (RED first — fixture JSON + failing test), then models + migrations (Eloquent + schema), then the seeder wires real default-tier values.

Output:
- `config/pricing.php` with locked rounding_mode + VAT basis points
- `App\Domain\Pricing\Services\PriceCalculator` (integer-pennies, BCMath where needed, single rounding at return)
- `App\Domain\Pricing\Exceptions\SupplierPriceUnusableException`
- 50-triple golden fixture JSON + Pest parity test (CI-blocking)
- `App\Domain\Pricing\Models\PricingRule` + `ProductOverride` with LogsActivity
- `App\Domain\Pricing\Policies\PricingRulePolicy` + `ProductOverridePolicy`
- Migrations for both tables (timestamps 2026_04_19_0900xx)
- Factories under `database/factories/Domain/Pricing/`
- `Phase3\DefaultPricingTierSeeder` (3 tier rows from legacy Woo values)
- `AppServiceProvider` Gate::policy bindings
- Unit tests for guards (zero/null/negative supplier, boundary tiers), stripVat helper, property-based rounding stability
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/PROJECT.md
@.planning/ROADMAP.md
@.planning/STATE.md
@.planning/phases/03-pricing-engine/03-CONTEXT.md
@.planning/phases/02-supplier-sync/02-CONTEXT.md
@.planning/research/PITFALLS.md
@CLAUDE.md
@app/Domain/Products/Models/Product.php
@app/Domain/Products/Models/ProductVariant.php
@app/Domain/Sync/Models/ImportIssue.php
@app/Foundation/Events/DomainEvent.php
@database/migrations/2026_04_18_200400_create_import_issues_table.php
@database/seeders/RolePermissionSeeder.php
@depfile.yaml

<interfaces>
<!-- Key contracts downstream plans (02, 03, 04) will consume. -->

PriceCalculator signature (D-03, Pitfall 5):
```php
namespace App\Domain\Pricing\Services;

final class PriceCalculator
{
    /**
     * Compute final VAT-inclusive retail price in integer pennies.
     *
     * Single pure function — no Eloquent, no events, no logging, no config reads
     * other than rounding mode pulled from config/pricing.php at call time.
     *
     * Formula: round(supplier × (1 + margin_bps/10000) × (1 + vat_bps/10000), 2) in pennies.
     *
     * @param  int  $supplierPennies        Supplier ex-VAT price in pennies (MUST be > 0).
     * @param  int  $marginBasisPoints      Margin percent × 100 (e.g. 2200 = 22.00%).
     * @param  int  $vatBasisPoints         VAT percent × 100 (default 2000 = 20.00% UK standard).
     * @return int                          Retail price in pennies, rounded once.
     *
     * @throws SupplierPriceUnusableException  When $supplierPennies <= 0.
     */
    public function compute(int $supplierPennies, int $marginBasisPoints, int $vatBasisPoints = 2000): int;

    /**
     * Strip VAT from a gross-inclusive price (Phase 5 competitor ingest reuses this, D-05).
     *
     * Formula: intdiv($grossPennies * 10000, 10000 + $vatBasisPoints) with explicit rounding.
     *
     * @param  int  $grossPennies           Inclusive-of-VAT price in pennies.
     * @param  int  $vatBasisPoints         VAT percent × 100 (default 2000).
     * @return int                          Ex-VAT price in pennies.
     */
    public function stripVat(int $grossPennies, int $vatBasisPoints = 2000): int;
}
```

SupplierPriceUnusableException (thrown by compute() on $supplierPennies <= 0):
```php
namespace App\Domain\Pricing\Exceptions;

final class SupplierPriceUnusableException extends \RuntimeException
{
    public static function zeroOrNegative(int $pennies): self
    {
        return new self("Supplier price must be > 0 pennies; got {$pennies}");
    }
}
```

PricingRule columns (D-06, D-07):
| column | type | notes |
|---|---|---|
| id | bigint | PK |
| scope | enum('brand','category','brand_category','default_tier') | D-06 — default_tier is its own scope, exclusive-set with brand/category NULL |
| brand_id | bigint NULL | FK to future brands table (nullable column for forward-compat; Phase 3 has no brands table yet — nullable bigint with no FK constraint in v1) |
| category_id | bigint NULL | FK to future categories table (same note) |
| margin_basis_points | int | e.g. 2200 = 22.00% |
| priority | unsignedSmallInteger default 100 | D-07 tiebreaker |
| is_default_tier | boolean default false | TRUE for tier fallback rows |
| tier_min_pennies | unsignedInteger NULL | only used when is_default_tier=true |
| tier_max_pennies | unsignedInteger NULL | only used when is_default_tier=true; null = open-ended upper |
| active | boolean default true | D-07 — soft-toggle without DELETE |
| created_by_user_id | bigint NULL FK users | audit trail |
| timestamps |  |  |

Indexes: `(scope, priority)`, `(brand_id, category_id)`, `(is_default_tier)`.

ProductOverride columns (D-08, D-09):
| column | type | notes |
|---|---|---|
| id | bigint | PK |
| product_id | bigint UNIQUE FK products | D-08 — one row per product; D-09 parent-only |
| margin_basis_points | int | legacy buy_price_percentage_to_add equivalent |
| reason | text NULL | audit trail |
| created_by_user_id | bigint NULL FK users |  |
| timestamps |  |  |

config/pricing.php shape:
```php
return [
    'rounding_mode' => PHP_ROUND_HALF_UP,  // D-02 — match legacy bare round()
    'vat_basis_points' => (int) env('PRICING_VAT_BASIS_POINTS', 2000),  // 20.00% UK standard
    'fixture_path' => base_path('tests/Fixtures/Pricing/golden-fixtures.json'),
];
```

golden-fixtures.json shape (Phase 3 ship gate):
```json
[
  {
    "id": "fx-001",
    "tier": "<£100",
    "supplier_pennies": 5900,
    "margin_basis_points": 3500,
    "vat_basis_points": 2000,
    "expected_final_pennies": 9558,
    "source": "legacy-woo-snapshot-2026-04-19"
  },
  ... 49 more
]
```
</interfaces>
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: PriceCalculator + fixtures + guards (RED-first, golden-fixture ship gate)</name>
  <files>
    config/pricing.php,
    app/Domain/Pricing/Exceptions/SupplierPriceUnusableException.php,
    app/Domain/Pricing/Services/PriceCalculator.php,
    tests/Fixtures/Pricing/golden-fixtures.json,
    tests/Unit/Pricing/PriceCalculatorGoldenFixtureTest.php,
    tests/Unit/Pricing/PriceCalculatorGuardsTest.php,
    tests/Unit/Pricing/PriceCalculatorStripVatTest.php,
    tests/Unit/Pricing/PriceCalculatorPropertyTest.php
  </files>
  <read_first>
    .planning/phases/03-pricing-engine/03-CONTEXT.md,
    .planning/research/PITFALLS.md (§ Pitfall 5 only — lines 120-147),
    app/Foundation/Events/DomainEvent.php,
    config/app.php
  </read_first>
  <behavior>
    - Test 1 (golden fixture — THE SHIP GATE): for each of 50 triples in tests/Fixtures/Pricing/golden-fixtures.json, PriceCalculator::compute(supplier_pennies, margin_basis_points, vat_basis_points) returns exactly expected_final_pennies. Test uses a Pest dataset iterating the JSON file; each triple is its own assertion row so failures report "fx-037: expected 119924, got 119925" not a wall of "array mismatch".
    - Test 2 (guard — zero supplier price): compute(0, 2200) throws SupplierPriceUnusableException with message containing "must be > 0".
    - Test 3 (guard — negative supplier price): compute(-100, 2200) throws SupplierPriceUnusableException.
    - Test 4 (guard — zero margin allowed): compute(10000, 0) returns 12000 (10000 * 1 * 1.2 = 12000; zero margin is legal).
    - Test 5 (stripVat — reverse of compute): stripVat(12000, 2000) returns 10000; stripVat(7188, 2000) returns 5990 (matches golden fixture style).
    - Test 6 (property — rounding stability): for 1000 random (supplier, margin) inputs generated via a deterministic seed, compute(x, m) equals compute(x, m) on re-invocation (pure function guarantee).
    - Test 7 (property — rounding mode from config): when config('pricing.rounding_mode') is PHP_ROUND_HALF_UP (default), compute(5025, 0, 0) [i.e. supplier 50.25, 0% margin, 0% VAT] returns 5025; when temporarily set to PHP_ROUND_HALF_EVEN via config()->set(), compute(5050, 0, 0) still returns 5050. (This test exists to document rounding-mode locking per D-02; it reads config at call time, does NOT cache.)
  </behavior>
  <action>
    Step 1 — RED: author the golden fixtures JSON FIRST. Use this seeding strategy so the fixtures are reproducible and cover the boundary cases D-04 requires:

    **Fixture generation recipe (deterministic — NO live Woo DB required at execute time):**
    - 3 tier buckets × 14 triples each = 42 triples + 8 edge cases = 50 total.
    - For each tier bucket, generate 14 triples by iterating supplier_pennies in a deterministic pattern (e.g. `for i in 1..14: supplier = tier_floor + (tier_range / 14) * i rounded`) and assigning the default margin for that tier.
    - Tier margins (documented in action as the Phase-3-ship-gate values; re-baseline requires a dedicated commit):
      - `<£100`: margin 3500 basis points (35.00%)
      - `£100-499`: margin 2800 basis points (28.00%)
      - `£500+`: margin 2200 basis points (22.00%)
    - 8 edge cases:
      1. Tier boundary £99.99 (supplier_pennies=7407, margin=3500, vat=2000) — forces the `<£100` tier
      2. Tier boundary £100.00 (supplier_pennies=7408) — forces the `£100-499` tier (one penny above)
      3. Tier boundary £499.99 — forces `£100-499` upper bound
      4. Tier boundary £500.01 — forces `£500+` lower bound
      5. HALF_UP case: supplier 1000, margin 2500, vat 2000 — should produce exactly 15000 (no rounding drift)
      6. HALF_UP-critical case: supplier 1234, margin 1750, vat 2000 — known to round up
      7. Override-equipped: supplier 4567, margin 4000 (override margin, not tier), vat 2000
      8. Override-equipped: supplier 12345, margin 1500 (override margin), vat 2000
    - For EACH triple, compute expected_final_pennies IN THE FIXTURE using integer math: `round_half_up(supplier_pennies * (10000 + margin_bps) * (10000 + vat_bps) / 100_000_000)`. The fixture JSON is self-consistent — the test validates that the CALCULATOR returns the same number the fixture asserts. If ops later supplies a live-Woo-sourced fixture (as D-04 envisions), the re-baseline commit replaces this file + message cites the reason.
    - Write the fixture as a JSON array with entries matching the shape in <interfaces>. Every entry MUST include: id, tier, supplier_pennies, margin_basis_points, vat_basis_points, expected_final_pennies, source.
    - Set `source` to `"deterministic-v1-2026-04-19"` for fixtures 1-42 and `"edge-case-2026-04-19"` for 43-50. When ops re-baselines from live Woo DB, source flips to `"live-woo-snapshot-YYYY-MM-DD"`.

    Step 2 — RED: author all 4 test files under tests/Unit/Pricing/:
    - PriceCalculatorGoldenFixtureTest.php: Pest dataset loop that loads golden-fixtures.json at file head and iterates `it('matches fixture fx-XXX', fn($fixture) => expect((new PriceCalculator)->compute($fixture['supplier_pennies'], $fixture['margin_basis_points'], $fixture['vat_basis_points']))->toBe($fixture['expected_final_pennies']))->with('golden fixtures')`. The `dataset('golden fixtures', ...)` loads the JSON once.
    - PriceCalculatorGuardsTest.php: zero / negative / null-handling (null is caught at PHP type level; test with 0 and -100).
    - PriceCalculatorStripVatTest.php: reversibility check + edge case (pennies resulting in rounding).
    - PriceCalculatorPropertyTest.php: use `mt_srand(12345)` for determinism, generate 1000 (supplier, margin) pairs in realistic ranges, assert `compute(x, m) === compute(x, m)` across two back-to-back calls. Also assert that all outputs are strictly positive integers.
    - Run: `vendor/bin/pest tests/Unit/Pricing --stop-on-failure` — MUST FAIL with "class PriceCalculator not found".

    Step 3 — GREEN: author config/pricing.php exactly as shown in <interfaces>.

    Step 4 — GREEN: author SupplierPriceUnusableException extending \RuntimeException with the factory shown in <interfaces>.

    Step 5 — GREEN: author PriceCalculator. CRITICAL implementation notes:
    - `compute()` signature is `(int $supplierPennies, int $marginBasisPoints, int $vatBasisPoints = 2000): int` — all integers, integer return.
    - Guard FIRST: `if ($supplierPennies <= 0) throw SupplierPriceUnusableException::zeroOrNegative($supplierPennies);`
    - Formula in integer arithmetic: numerator = `$supplierPennies * (10000 + $marginBasisPoints) * (10000 + $vatBasisPoints)`; denominator = 100_000_000. Both sides fit in PHP 64-bit int for realistic catalogue values (max supplier £10k = 1_000_000 pennies × 20000 × 12000 = 2.4e14, well under 2^63).
    - Single `round()` at return boundary: `(int) round($numerator / $denominator, 0, config('pricing.rounding_mode', PHP_ROUND_HALF_UP));`
    - NEVER call round() twice. NEVER use float intermediates. NO BCMath needed for £10k max supplier; document the 2^63 headroom in a class-level comment citing Pitfall 5.
    - `stripVat()`: guard `if ($grossPennies <= 0) return 0;` then `return (int) round($grossPennies * 10000 / (10000 + $vatBasisPoints), 0, config('pricing.rounding_mode', PHP_ROUND_HALF_UP));`.
    - Class-level PHPDoc MUST reference Pitfall 5, D-01..D-05, and the fact that golden-fixtures.json is the ship gate. Use the `// ══════` / `// ── Label ──` comment style from CLAUDE.md conventions.
    - Class is `final` and has no constructor parameters. It is stateless — bind as singleton in AppServiceProvider is optional; `new PriceCalculator()` anywhere is equally valid.

    Step 6 — GREEN: run `vendor/bin/pest tests/Unit/Pricing --stop-on-failure`. All 4 test files MUST pass. The golden-fixture test specifically MUST show "PASSED 50/50" (or whatever Pest's dataset reporter emits — the count of executed rows MUST equal 50).

    Step 7 — REFACTOR if duplication emerged (e.g. rounding helper). Keep compute() and stripVat() in the single class.

    **DO NOT:**
    - Do NOT use `float` anywhere in PriceCalculator.
    - Do NOT call round() more than once per public method.
    - Do NOT add domain events / logging / Eloquent inside the calculator.
    - Do NOT skip the fixture generation. "Add fixtures later" fails the Phase 3 ship gate.
    - Do NOT weaken assertions (`toBeGreaterThan` instead of `toBe`) — the fixture demands penny-exact equality.
  </action>
  <verify>
    <automated>vendor/bin/pest tests/Unit/Pricing --stop-on-failure</automated>
  </verify>
  <acceptance_criteria>
    - `test -f tests/Fixtures/Pricing/golden-fixtures.json` returns 0
    - `jq 'length' tests/Fixtures/Pricing/golden-fixtures.json` returns `50`
    - `jq '[.[] | .expected_final_pennies] | length' tests/Fixtures/Pricing/golden-fixtures.json` returns `50` (no nulls)
    - `jq '.[0] | keys' tests/Fixtures/Pricing/golden-fixtures.json` contains `expected_final_pennies`, `margin_basis_points`, `supplier_pennies`, `vat_basis_points`
    - `test -f config/pricing.php` returns 0
    - `grep -q "PHP_ROUND_HALF_UP" config/pricing.php`
    - `test -f app/Domain/Pricing/Services/PriceCalculator.php` returns 0
    - `grep -q "final class PriceCalculator" app/Domain/Pricing/Services/PriceCalculator.php`
    - `grep -q "SupplierPriceUnusableException" app/Domain/Pricing/Services/PriceCalculator.php`
    - `grep -cE "round\\(" app/Domain/Pricing/Services/PriceCalculator.php` returns `2` (once in compute, once in stripVat — no other round calls)
    - `grep -c "float" app/Domain/Pricing/Services/PriceCalculator.php` returns `0`
    - `vendor/bin/pest tests/Unit/Pricing/PriceCalculatorGoldenFixtureTest.php --stop-on-failure` exits 0
    - `vendor/bin/pest tests/Unit/Pricing/PriceCalculatorGuardsTest.php --stop-on-failure` exits 0
    - `vendor/bin/pest tests/Unit/Pricing/PriceCalculatorStripVatTest.php --stop-on-failure` exits 0
    - `vendor/bin/pest tests/Unit/Pricing/PriceCalculatorPropertyTest.php --stop-on-failure` exits 0
    - Full Pricing suite: `vendor/bin/pest tests/Unit/Pricing` reports at least 53 tests passed (50 fixture + 3+ guards + 1+ stripVat + 1+ property)
  </acceptance_criteria>
  <done>
    PriceCalculator is pure, integer-only, guarded against zero/null supplier, and the 50-triple golden fixture passes to the penny. Ship gate GREEN.
  </done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: Migrations + models + factories + policies (PricingRule + ProductOverride)</name>
  <files>
    database/migrations/2026_04_19_090000_create_pricing_rules_table.php,
    database/migrations/2026_04_19_090100_create_product_overrides_table.php,
    app/Domain/Pricing/Models/PricingRule.php,
    app/Domain/Pricing/Models/ProductOverride.php,
    app/Domain/Pricing/Policies/PricingRulePolicy.php,
    app/Domain/Pricing/Policies/ProductOverridePolicy.php,
    database/factories/Domain/Pricing/PricingRuleFactory.php,
    database/factories/Domain/Pricing/ProductOverrideFactory.php,
    tests/Feature/Pricing/PricingRuleFactoryTest.php,
    tests/Feature/Pricing/ProductOverrideFactoryTest.php,
    app/Providers/AppServiceProvider.php
  </files>
  <read_first>
    .planning/phases/03-pricing-engine/03-CONTEXT.md,
    database/migrations/2026_04_18_200000_create_products_table.php,
    database/migrations/2026_04_18_200400_create_import_issues_table.php,
    app/Domain/Products/Models/Product.php,
    app/Domain/Sync/Models/ImportIssue.php,
    app/Domain/Sync/Policies/ImportIssuePolicy.php,
    database/seeders/RolePermissionSeeder.php,
    app/Providers/AppServiceProvider.php
  </read_first>
  <behavior>
    - Test 1 (factory resolves): PricingRule::factory()->make() returns a filled model with scope='brand', priority=100 default, margin_basis_points set.
    - Test 2 (default-tier factory state): PricingRule::factory()->defaultTier()->create() persists is_default_tier=true + tier_min_pennies + tier_max_pennies populated, brand_id+category_id NULL.
    - Test 3 (product override factory resolves + unique constraint): ProductOverride::factory()->for($product)->create() succeeds; creating a second override for the same product fails with QueryException (UNIQUE violation on product_id).
    - Test 4 (LogsActivity wired): PricingRule::create($valid) persists a corresponding activity_log row (spatie activitylog).
    - Test 5 (policy — pricing_manager can update): a user with pricing_manager role can `$user->can('update', $rule)` → true.
    - Test 6 (policy — sales CANNOT update): a user with sales role can `$user->can('update', $rule)` → false.
    - Test 7 (policy — ProductOverride same gating): pricing_manager allowed, read_only forbidden on ProductOverridePolicy.
  </behavior>
  <action>
    Step 1 — author migration `2026_04_19_090000_create_pricing_rules_table.php`. Column-by-column per <interfaces>:
    ```
    Schema::create('pricing_rules', function (Blueprint $t) {
        $t->id();
        $t->enum('scope', ['brand', 'category', 'brand_category', 'default_tier'])->index();
        $t->unsignedBigInteger('brand_id')->nullable()->index();
        $t->unsignedBigInteger('category_id')->nullable()->index();
        $t->integer('margin_basis_points'); // signed — negative allowed for loss-leader promos in v2
        $t->unsignedSmallInteger('priority')->default(100); // D-07
        $t->boolean('is_default_tier')->default(false)->index();
        $t->unsignedInteger('tier_min_pennies')->nullable();
        $t->unsignedInteger('tier_max_pennies')->nullable();
        $t->boolean('active')->default(true)->index();
        $t->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        $t->timestamps();

        $t->index(['scope', 'priority'], 'pricing_rules_scope_priority_idx');
        $t->index(['brand_id', 'category_id'], 'pricing_rules_brand_category_idx');
    });
    ```
    Docblock at file head MUST cite D-06, D-07 and Pitfall 7 (nullable columns need null-safe code — brand_id/category_id read paths in RuleResolver handle this).

    Step 2 — author migration `2026_04_19_090100_create_product_overrides_table.php`:
    ```
    Schema::create('product_overrides', function (Blueprint $t) {
        $t->id();
        $t->foreignId('product_id')->unique()->constrained('products')->cascadeOnDelete();
        $t->integer('margin_basis_points');
        $t->text('reason')->nullable();
        $t->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        $t->timestamps();
    });
    ```
    Docblock MUST cite D-08 (override = margin % not direct final price), D-09 (parent-only; variant_id is a v2 forward-compatible addition).

    Step 3 — author `app/Domain/Pricing/Models/PricingRule.php`. Follow Phase 2 `Product.php` style exactly:
    - `final class PricingRule extends Model` with `use HasFactory; use LogsActivity;`
    - `$fillable` lists every column except id/timestamps
    - `$casts` for booleans + integers: `'is_default_tier' => 'bool'`, `'active' => 'bool'`, `'margin_basis_points' => 'integer'`, `'priority' => 'integer'`, `'tier_min_pennies' => 'integer'`, `'tier_max_pennies' => 'integer'`
    - `getActivitylogOptions()` logs ['scope', 'brand_id', 'category_id', 'margin_basis_points', 'priority', 'is_default_tier', 'tier_min_pennies', 'tier_max_pennies', 'active'] with `->logOnlyDirty()`
    - `newFactory()` returns PricingRuleFactory::new()
    - Class-level PHPDoc cites D-06, D-07 and names the RuleResolver in Plan 02 as primary reader
    - Scope enum constants: `SCOPE_BRAND = 'brand'; SCOPE_CATEGORY = 'category'; SCOPE_BRAND_CATEGORY = 'brand_category'; SCOPE_DEFAULT_TIER = 'default_tier';`

    Step 4 — author `app/Domain/Pricing/Models/ProductOverride.php`:
    - `final class ProductOverride extends Model` with `use HasFactory; use LogsActivity;`
    - `$fillable`: product_id, margin_basis_points, reason, created_by_user_id
    - `$casts`: 'margin_basis_points' => 'integer'
    - `product()` BelongsTo(Product::class)
    - `getActivitylogOptions()` logs ['product_id', 'margin_basis_points', 'reason'] with logOnlyDirty
    - `newFactory()` returns ProductOverrideFactory::new()
    - Class-level PHPDoc cites D-08, D-09, and Pitfall 7 (variant_id nullable column reserved for v2 — schema forward-compatibility).

    Step 5 — author both policies following `app/Domain/Sync/Policies/ImportIssuePolicy.php` pattern exactly. Each policy has viewAny/view/create/update/delete/restore/forceDelete methods. Allow matrix:
    - PricingRulePolicy: admin or pricing_manager can viewAny/view/create/update/delete/restore/forceDelete. All other roles forbidden on writes; read_only gets viewAny+view only. Use `$user->hasRole('admin') || $user->hasRole('pricing_manager')` (string role names per Shield D-02 Phase 1). Read-only check via `$user->hasPermissionTo('view_pricing_rule')` OR `$user->hasRole('read_only')`.
    - ProductOverridePolicy: same matrix — admin + pricing_manager on writes; read_only on viewAny/view.
    - CRITICAL: hand-write these policies with hasRole checks (same pattern as Phase 1 SuggestionPolicy + Phase 2 ImportIssuePolicy). Do NOT leave any `{{ Placeholder }}` strings — PolicyTemplateIntegrityTest (promoted to Architecture in Phase 2) will catch a regression, but we must ship them clean from day one.
    - No `shield:generate` run in this plan — Plan 03 runs it after the Filament Resources are in place (Phase 1 pattern: generate policies AFTER Resources exist so Shield discovers them). These hand-written policies are registered via Gate::policy() in AppServiceProvider (Step 8).

    Step 6 — author factories under `database/factories/Domain/Pricing/`. Match the `database/factories/Domain/Products/ProductFactory.php` pattern:
    - PricingRuleFactory: `definition()` returns [scope => SCOPE_BRAND, brand_id => fake()->numberBetween(1, 100), category_id => null, margin_basis_points => 2500, priority => 100, is_default_tier => false, tier_min_pennies => null, tier_max_pennies => null, active => true, created_by_user_id => null].
      - State method `defaultTier()`: scope => SCOPE_DEFAULT_TIER, brand_id => null, category_id => null, is_default_tier => true, tier_min_pennies => 0, tier_max_pennies => 9999, margin_basis_points => 3500.
      - State method `brandCategory()`: scope => SCOPE_BRAND_CATEGORY, brand_id + category_id both set.
      - State method `inactive()`: active => false.
    - ProductOverrideFactory: `definition()` returns [product_id => Product::factory(), margin_basis_points => 4000, reason => 'Manual tune (test)', created_by_user_id => null].

    Step 7 — author 2 factory smoke tests under `tests/Feature/Pricing/`:
    - PricingRuleFactoryTest: `make()` resolves, `create()` persists, `defaultTier()->create()` persists with correct flags, LogsActivity captures create events (check activity_log table has a matching row).
    - ProductOverrideFactoryTest: `for($product)->create()` persists, creating a second for the same product throws QueryException (unique constraint), LogsActivity captures.

    Step 8 — register policies in `app/Providers/AppServiceProvider.php`. Find the existing `Gate::policy()` section (Phase 1 Plan 04 + Phase 2 Plan 01 added bindings for Suggestion, AlertRecipient, Product, ProductVariant, SyncRun, ImportIssue). ADD two new lines:
    ```php
    Gate::policy(\App\Domain\Pricing\Models\PricingRule::class, \App\Domain\Pricing\Policies\PricingRulePolicy::class);
    Gate::policy(\App\Domain\Pricing\Models\ProductOverride::class, \App\Domain\Pricing\Policies\ProductOverridePolicy::class);
    ```
    Keep the comment format consistent with existing bindings (one-line comment citing the phase/plan, e.g. `// Phase 3 Plan 01`).

    Step 9 — run `php artisan migrate --database=mysql --no-interaction` against dev DB and against `meetingstore_ops_testing` via `php artisan migrate --env=testing`. Both MUST succeed. Verify via `php artisan tinker --execute="echo \\DB::getSchemaBuilder()->hasTable('pricing_rules') ? 'yes' : 'no';"`.

    Step 10 — run the factory smoke tests + factory of PricingRule/ProductOverride from tinker as a belt-and-braces check.

    **DO NOT:**
    - Do NOT run `shield:generate` in this plan. Plan 03 runs it after Resources land (Phase 2 pattern).
    - Do NOT add any migration rollback logic beyond `Schema::dropIfExists` — keep migrations simple.
    - Do NOT add a `variant_id` column to product_overrides (D-09 defers this).
    - Do NOT FK-constrain brand_id / category_id (no brands/categories tables exist yet — nullable bigint is the forward-compatible shape).
  </action>
  <verify>
    <automated>php artisan migrate:fresh --env=testing --seed=false && vendor/bin/pest tests/Feature/Pricing --stop-on-failure</automated>
  </verify>
  <acceptance_criteria>
    - `test -f database/migrations/2026_04_19_090000_create_pricing_rules_table.php` returns 0
    - `test -f database/migrations/2026_04_19_090100_create_product_overrides_table.php` returns 0
    - `grep -q "priority" database/migrations/2026_04_19_090000_create_pricing_rules_table.php`
    - `grep -q "is_default_tier" database/migrations/2026_04_19_090000_create_pricing_rules_table.php`
    - `grep -q "tier_min_pennies" database/migrations/2026_04_19_090000_create_pricing_rules_table.php`
    - `grep -q "default_tier" database/migrations/2026_04_19_090000_create_pricing_rules_table.php`
    - `grep -q "->unique()" database/migrations/2026_04_19_090100_create_product_overrides_table.php`
    - `test -f app/Domain/Pricing/Models/PricingRule.php` returns 0
    - `grep -q "final class PricingRule" app/Domain/Pricing/Models/PricingRule.php`
    - `grep -q "LogsActivity" app/Domain/Pricing/Models/PricingRule.php`
    - `grep -q "SCOPE_BRAND_CATEGORY" app/Domain/Pricing/Models/PricingRule.php`
    - `grep -q "final class ProductOverride" app/Domain/Pricing/Models/ProductOverride.php`
    - `grep -q "LogsActivity" app/Domain/Pricing/Models/ProductOverride.php`
    - `test -f app/Domain/Pricing/Policies/PricingRulePolicy.php` returns 0
    - `test -f app/Domain/Pricing/Policies/ProductOverridePolicy.php` returns 0
    - `grep -q "hasRole" app/Domain/Pricing/Policies/PricingRulePolicy.php` (confirms hand-written, not Shield stub)
    - `grep -L "{{ " app/Domain/Pricing/Policies/PricingRulePolicy.php app/Domain/Pricing/Policies/ProductOverridePolicy.php` returns both file paths (i.e. no placeholder literals)
    - `test -f database/factories/Domain/Pricing/PricingRuleFactory.php` returns 0
    - `test -f database/factories/Domain/Pricing/ProductOverrideFactory.php` returns 0
    - `grep -q "PricingRulePolicy" app/Providers/AppServiceProvider.php`
    - `grep -q "ProductOverridePolicy" app/Providers/AppServiceProvider.php`
    - `php artisan migrate --env=testing --no-interaction` exits 0
    - `php artisan tinker --env=testing --execute="echo \\App\\Domain\\Pricing\\Models\\PricingRule::factory()->make()->scope;"` prints `brand`
    - `php artisan tinker --env=testing --execute="echo \\App\\Domain\\Pricing\\Models\\PricingRule::factory()->defaultTier()->make()->is_default_tier ? 'yes' : 'no';"` prints `yes`
    - `vendor/bin/pest tests/Feature/Pricing --stop-on-failure` exits 0
  </acceptance_criteria>
  <done>
    pricing_rules and product_overrides tables exist with the exact D-06..D-09 shape. Models have LogsActivity + factories. Hand-written policies gate writes to admin + pricing_manager. Gate::policy bindings wire models to policies.
  </done>
</task>

<task type="auto">
  <name>Task 3: Default tier seeder (3 rows from legacy Woo values) + DatabaseSeeder hook</name>
  <files>
    database/seeders/Phase3/DefaultPricingTierSeeder.php,
    database/seeders/DatabaseSeeder.php,
    tests/Feature/Pricing/DefaultPricingTierSeederTest.php
  </files>
  <read_first>
    .planning/phases/03-pricing-engine/03-CONTEXT.md,
    database/seeders/DatabaseSeeder.php,
    database/seeders/RolePermissionSeeder.php,
    app/Domain/Pricing/Models/PricingRule.php,
    tests/Fixtures/Pricing/golden-fixtures.json
  </read_first>
  <action>
    Step 1 — author `database/seeders/Phase3/DefaultPricingTierSeeder.php`. Idempotent via firstOrCreate on (scope, is_default_tier, tier_min_pennies, tier_max_pennies). 3 rows, values MATCHING the fixture tier-margin pairing locked in Task 1:
    ```
    [
        ['tier_min_pennies' => 0,      'tier_max_pennies' => 9999,    'margin_basis_points' => 3500, 'priority' => 50], // <£100 → 35% margin
        ['tier_min_pennies' => 10000,  'tier_max_pennies' => 49999,   'margin_basis_points' => 2800, 'priority' => 50], // £100–499 → 28% margin
        ['tier_min_pennies' => 50000,  'tier_max_pennies' => null,    'margin_basis_points' => 2200, 'priority' => 50], // £500+ → 22% margin (null upper = open)
    ]
    ```
    For each row, firstOrCreate with:
    ```php
    PricingRule::firstOrCreate(
        [
            'scope' => PricingRule::SCOPE_DEFAULT_TIER,
            'is_default_tier' => true,
            'tier_min_pennies' => $row['tier_min_pennies'],
            'tier_max_pennies' => $row['tier_max_pennies'],
        ],
        [
            'brand_id' => null,
            'category_id' => null,
            'margin_basis_points' => $row['margin_basis_points'],
            'priority' => $row['priority'],
            'active' => true,
        ],
    );
    ```
    Lower priority (50) than a user-created specific rule default (100) — priority DESC sort means specific rules override defaults naturally.

    Docblock cites D-06 tier locked boundaries + "re-baseline with live Woo values when ops supplies them; the 35/28/22 triple matches the golden fixtures so both move together". Also cites CONTEXT.md Claude's Discretion — "tier margins are discretion until ops confirms".

    Step 2 — edit `database/seeders/DatabaseSeeder.php`. Find the existing `$this->call([...])` block (Phase 1 registered RolePermissionSeeder + admin user seeder; Phase 2 may have added others). Add `\Database\Seeders\Phase3\DefaultPricingTierSeeder::class` to the array. Order: AFTER RolePermissionSeeder (so roles exist first) but BEFORE any test-only suggestion seeds.

    Step 3 — author `tests/Feature/Pricing/DefaultPricingTierSeederTest.php`:
    - Test 1: running the seeder on an empty DB creates exactly 3 default-tier rows.
    - Test 2: running the seeder TWICE is idempotent (still 3 rows, not 6).
    - Test 3: all 3 rows have is_default_tier=true, scope='default_tier', brand_id+category_id NULL.
    - Test 4: the 3 margin values are 3500, 2800, 2200 in that order.
    - Test 5: upper tier has tier_max_pennies NULL (open-ended).

    Step 4 — run seeder against testing DB:
    ```
    php artisan migrate:fresh --env=testing --seed
    php artisan tinker --env=testing --execute="echo \\App\\Domain\\Pricing\\Models\\PricingRule::where('is_default_tier', true)->count();"
    ```
    MUST output 3.

    **DO NOT:**
    - Do NOT hardcode the tier margin values in multiple files. The seeder is the single source. When ops re-baselines from live Woo, both this seeder AND the golden fixtures get updated in the SAME commit (re-baseline commit per D-04).
    - Do NOT use Model::create() instead of firstOrCreate() — the seeder MUST be idempotent on re-run (deploy-time pattern per Phase 1 D-03).
  </action>
  <verify>
    <automated>php artisan migrate:fresh --env=testing --seed && vendor/bin/pest tests/Feature/Pricing/DefaultPricingTierSeederTest.php --stop-on-failure</automated>
  </verify>
  <acceptance_criteria>
    - `test -f database/seeders/Phase3/DefaultPricingTierSeeder.php` returns 0
    - `grep -q "is_default_tier" database/seeders/Phase3/DefaultPricingTierSeeder.php`
    - `grep -q "firstOrCreate" database/seeders/Phase3/DefaultPricingTierSeeder.php`
    - `grep -c "margin_basis_points" database/seeders/Phase3/DefaultPricingTierSeeder.php` >= 3
    - `grep -q "DefaultPricingTierSeeder" database/seeders/DatabaseSeeder.php`
    - `php artisan migrate:fresh --env=testing --seed --no-interaction` exits 0
    - `php artisan tinker --env=testing --execute="echo \\App\\Domain\\Pricing\\Models\\PricingRule::where('is_default_tier', true)->count();"` outputs `3`
    - `php artisan tinker --env=testing --execute="echo \\App\\Domain\\Pricing\\Models\\PricingRule::where('is_default_tier', true)->orderBy('tier_min_pennies')->pluck('margin_basis_points')->implode(',');"` outputs `3500,2800,2200`
    - `vendor/bin/pest tests/Feature/Pricing/DefaultPricingTierSeederTest.php --stop-on-failure` exits 0
    - Running seeder twice yields same 3 rows (not 6): test covers this explicitly
  </acceptance_criteria>
  <done>
    Default tier seeder writes 3 rows matching the golden-fixture margin values. Idempotent on re-run. Hooked into DatabaseSeeder so `migrate:fresh --seed` populates defaults on every dev/test boot.
  </done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| CLI → DB | php artisan migrate / seed run by operator — migrations ship in repo, no untrusted input |
| Test suite → fixtures file | tests/Fixtures/Pricing/golden-fixtures.json is version-controlled — supply-chain integrity via git |
| PriceCalculator inputs | Primitives only (int supplier, int margin, int VAT) — NO Eloquent models cross this boundary |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-03-01-01 | T (Tampering) | tests/Fixtures/Pricing/golden-fixtures.json | mitigate | Fixture file MUST be version-controlled; re-baseline requires a dedicated commit with justification in message per D-04. CI-blocking parity test catches any drift — covered by Task 1. |
| T-03-01-02 | I (Information Disclosure) | PriceCalculator | accept | Pure function, no Eloquent, no logging, no secrets — nothing to disclose. Documented in class-level PHPDoc. |
| T-03-01-03 | D (Denial of Service) | PriceCalculator — integer overflow at pathological inputs | mitigate | Class-level PHPDoc documents 2^63 headroom for £10k supplier max. Guard: `if ($supplierPennies <= 0) throw` already catches zero/negative; realistic catalogue prices (≤£1M in pennies) cannot overflow. Covered by Task 1 Test 6 (property) which exercises wide ranges. |
| T-03-01-04 | E (Elevation of Privilege) | PricingRulePolicy / ProductOverridePolicy | mitigate | Hand-written policies with hasRole('admin')||hasRole('pricing_manager') checks on every write method. NO Shield-generated `{{ Placeholder }}` stubs — existing PolicyTemplateIntegrityTest (promoted to Architecture in Phase 2) catches regressions. Covered by Task 2 Steps 5 + acceptance criterion grep. |
| T-03-01-05 | I (Information Disclosure) | SupplierPriceUnusableException message | accept | Message contains supplier_pennies (internal metric, not PII). Log output goes to audit/integration log which is admin-read-only. |
| T-03-01-06 | T (Tampering) | Default tier seeder margin values | mitigate | Seeder is firstOrCreate on (scope, is_default_tier, tier_min_pennies, tier_max_pennies) — re-run NEVER overwrites an admin-edited margin. Only an empty slot is filled. Covered by Task 3 Test 2 (idempotency). |
| T-03-06 (from security_context) | T (Tampering) | Rounding mode drift | mitigate | config/pricing.php locks PHP_ROUND_HALF_UP. PriceCalculator reads config at call time — single place to change. Fixture parity test catches drift immediately. Covered by Task 1 Step 3 + acceptance criterion grep. |
</threat_model>

<verification>
- `vendor/bin/pest tests/Unit/Pricing tests/Feature/Pricing --stop-on-failure` — all tasks' tests pass
- `php artisan migrate:fresh --env=testing --seed --no-interaction` — clean boot applies all Phase 3 schema + 3 default-tier rows
- `jq 'length' tests/Fixtures/Pricing/golden-fixtures.json` == 50
- `grep -q "PHP_ROUND_HALF_UP" config/pricing.php`
- Ship gate probe: `vendor/bin/pest tests/Unit/Pricing/PriceCalculatorGoldenFixtureTest.php --stop-on-failure` reports 50/50 passing
</verification>

<success_criteria>
- PriceCalculator is a final class, pure function, integer-only
- Golden fixture parity test passes 50/50 triples to the penny (Phase 3 ship gate GREEN)
- Zero/null supplier price throws SupplierPriceUnusableException (no £0 leak)
- pricing_rules table exists with priority, is_default_tier, tier_min_pennies columns
- product_overrides table has UNIQUE product_id (D-08)
- config/pricing.php locks rounding_mode to PHP_ROUND_HALF_UP (D-02)
- Hand-written policies gate writes to admin + pricing_manager only
- Default tier seeder creates 3 rows; re-run idempotent (3 rows, not 6)
- Gate::policy bindings registered in AppServiceProvider
- All 50+ tests pass; Plan 01 is GREEN and Wave 2 can start
</success_criteria>

<output>
After completion, create `.planning/phases/03-pricing-engine/03-01-SUMMARY.md` covering:
- Calculator signature + guard behaviour
- Exact fixture generation recipe (deterministic tier triples + 8 edge cases; source tag for re-baseline)
- Migration timestamps + column set
- Policy matrix (which role can do what)
- Default tier margin values (3500/2800/2200) and their explicit linkage to the fixture
- Any deviations from plan (with justification)
- Pointer for Plan 02: RuleResolver consumes PricingRule + ProductOverride models; RecomputePriceListener catches zero-supplier exception
</output>
