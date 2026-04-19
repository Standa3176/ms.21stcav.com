<?php

declare(strict_types=1);

use App\Domain\Pricing\Events\ProductPriceChanged;
use App\Domain\Pricing\Filament\Resources\PricingRuleResource\Pages\SimulatedImpact;
use App\Domain\Pricing\Models\PricingRule;
use App\Domain\Pricing\Services\SimulatedImpactCalculator;
use App\Domain\Pricing\Services\SimulatedImpactRow;
use App\Domain\Products\Models\Product;
use App\Models\User;
use Database\Seeders\Phase3\DefaultPricingTierSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| Plan 03-03 Task 3 — SimulatedImpactCalculator + page tests (PRCE-09).
|--------------------------------------------------------------------------
|
| Contract:
|   - simulate(PricingRule) wraps in DB::beginTransaction + rollBack; stored
|     rule state is unchanged after the call
|   - result.count = affected-SKU count (all)
|   - result.rows = at most $limit SimulatedImpactRow DTOs
|   - Only rows where proposed ≠ current appear in the list
|   - Zero / null buy_price products are silently skipped
|   - Nothing emits ProductPriceChanged (no ->dispatch side-effect in calculator)
*/

beforeEach(function (): void {
    $this->seed(DefaultPricingTierSeeder::class);
    $this->seed(RolePermissionSeeder::class);

    $actions = ['view', 'view_any', 'create', 'update', 'delete', 'restore', 'force_delete'];
    foreach ($actions as $action) {
        foreach (['pricing::rule', 'product::override'] as $resource) {
            Permission::firstOrCreate(['name' => "{$action}_{$resource}", 'guard_name' => 'web']);
        }
    }

    $this->seed(RolePermissionSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    Event::fake([ProductPriceChanged::class]);
});

function simImpactUser(string $roleName): User
{
    Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole($roleName);

    return $user->fresh();
}

// ══════════════════════════════════════════════════════════════════════════════
// Test 1 — dry-run does not persist changes to an edited rule
// ══════════════════════════════════════════════════════════════════════════════

it('simulate() rolls back changes to an existing rule — margin on disk unchanged', function (): void {
    $rule = PricingRule::factory()->create([
        'scope' => PricingRule::SCOPE_BRAND,
        'brand_id' => 99,
        'margin_basis_points' => 2500,
        'priority' => 100,
        'active' => true,
    ]);

    // Mutate the rule in memory WITHOUT saving — simulate() will save it inside
    // its transaction then roll back.
    $rule->margin_basis_points = 5000;

    app(SimulatedImpactCalculator::class)->simulate($rule);

    // After rollback, disk must show the original margin.
    expect($rule->fresh()->margin_basis_points)->toBe(2500);
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 2 — count of affected SKUs under a NEW rule
// ══════════════════════════════════════════════════════════════════════════════

it('simulate() with a NEW rule counts only products whose proposed price differs', function (): void {
    // 3 products that WILL match the proposed brand_category rule.
    for ($i = 0; $i < 3; $i++) {
        Product::factory()->create([
            'brand_id' => 10,
            'category_id' => 20,
            'buy_price' => '50.0000',
            'sell_price' => '81.0000',  // default_tier 35% baseline — new rule will CHANGE this
        ]);
    }

    // 2 products that will NOT match (different brand) — stay at default tier,
    // so proposed == current and they are excluded from the diff set.
    for ($i = 0; $i < 2; $i++) {
        Product::factory()->create([
            'brand_id' => 77,
            'category_id' => 88,
            'buy_price' => '50.0000',
            'sell_price' => '81.0000',
        ]);
    }

    $newRule = new PricingRule([
        'scope' => PricingRule::SCOPE_BRAND_CATEGORY,
        'brand_id' => 10,
        'category_id' => 20,
        'margin_basis_points' => 4500,   // 45%, different from default 35%
        'priority' => 200,
        'is_default_tier' => false,
        'tier_min_pennies' => null,
        'tier_max_pennies' => null,
        'active' => true,
    ]);

    $result = app(SimulatedImpactCalculator::class)->simulate($newRule);

    expect($result['count'])->toBe(3);
    expect($result['rows'])->toHaveCount(3);
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 3 — row shape: SimulatedImpactRow with correct deltaPennies
// ══════════════════════════════════════════════════════════════════════════════

it('rows are SimulatedImpactRow DTOs with deltaPennies == proposed - current', function (): void {
    Product::factory()->create([
        'brand_id' => 10,
        'category_id' => 20,
        'sku' => 'DTO-001',
        'buy_price' => '50.0000',
        'sell_price' => '81.0000',  // 8100 pennies
    ]);

    $newRule = new PricingRule([
        'scope' => PricingRule::SCOPE_BRAND_CATEGORY,
        'brand_id' => 10,
        'category_id' => 20,
        'margin_basis_points' => 5000,
        'priority' => 200,
        'is_default_tier' => false,
        'active' => true,
    ]);

    $result = app(SimulatedImpactCalculator::class)->simulate($newRule);

    $row = $result['rows'][0];

    expect($row)->toBeInstanceOf(SimulatedImpactRow::class);
    expect($row->sku)->toBe('DTO-001');
    expect($row->currentPennies)->toBe(8100);

    // 5000 × (10000 + 5000) × (10000 + 2000) / 100_000_000
    // = 5000 × 15000 × 12000 / 100_000_000
    // = 900_000_000_000 / 100_000_000
    // = 9000 pennies = £90.00
    expect($row->proposedPennies)->toBe(9000);
    expect($row->deltaPennies)->toBe($row->proposedPennies - $row->currentPennies);
    expect($row->deltaPennies)->toBe(900);
    expect($row->resolutionSource)->toBe('brand_category');
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 4 — limit caps rows but count tracks all
// ══════════════════════════════════════════════════════════════════════════════

it('result.count tracks all diffs but rows are capped at $limit', function (): void {
    // 10 affected products.
    for ($i = 0; $i < 10; $i++) {
        Product::factory()->create([
            'brand_id' => 10,
            'category_id' => 20,
            'buy_price' => '50.0000',
            'sell_price' => '81.0000',
        ]);
    }

    $newRule = new PricingRule([
        'scope' => PricingRule::SCOPE_BRAND_CATEGORY,
        'brand_id' => 10,
        'category_id' => 20,
        'margin_basis_points' => 5000,
        'priority' => 200,
        'is_default_tier' => false,
        'active' => true,
    ]);

    $result = app(SimulatedImpactCalculator::class)->simulate($newRule, limit: 3);

    expect($result['count'])->toBe(10);
    expect($result['rows'])->toHaveCount(3);
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 5 — products with buy_price <= 0 are silently skipped
// ══════════════════════════════════════════════════════════════════════════════

it('products with zero or null buy_price are silently skipped (no throw)', function (): void {
    // 1 affected product (buy_price > 0)
    Product::factory()->create([
        'brand_id' => 10,
        'category_id' => 20,
        'buy_price' => '50.0000',
        'sell_price' => '81.0000',
    ]);

    // 1 zero-price (must be skipped at the query filter level)
    Product::factory()->create([
        'brand_id' => 10,
        'category_id' => 20,
        'buy_price' => '0.0000',
        'sell_price' => '0.0000',
    ]);

    $newRule = new PricingRule([
        'scope' => PricingRule::SCOPE_BRAND_CATEGORY,
        'brand_id' => 10,
        'category_id' => 20,
        'margin_basis_points' => 5000,
        'priority' => 200,
        'is_default_tier' => false,
        'active' => true,
    ]);

    $result = app(SimulatedImpactCalculator::class)->simulate($newRule);

    expect($result['count'])->toBe(1);
    expect($result['rows'])->toHaveCount(1);
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 6 — products with no-change prices are NOT in rows
// ══════════════════════════════════════════════════════════════════════════════

it('rows exclude products whose proposed price equals current', function (): void {
    // Product whose proposed will EQUAL current (rule keeps same margin as default tier).
    Product::factory()->create([
        'sku' => 'NO-CHANGE-001',
        'brand_id' => null,
        'category_id' => null,
        'buy_price' => '50.0000',
        'sell_price' => '81.0000',  // exactly 35% default tier result
    ]);

    // Simulate a BRAND rule that does NOT match (product has brand_id NULL) —
    // so resolver STILL returns default_tier for this product. Proposed ==
    // current = 8100. Row should be excluded.
    $newRule = new PricingRule([
        'scope' => PricingRule::SCOPE_BRAND,
        'brand_id' => 9999,   // doesn't match
        'margin_basis_points' => 5000,
        'priority' => 200,
        'is_default_tier' => false,
        'active' => true,
    ]);

    $result = app(SimulatedImpactCalculator::class)->simulate($newRule);

    expect($result['count'])->toBe(0);
    expect($result['rows'])->toHaveCount(0);
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 7 — SimulatedImpact page hooks up to the calculator end-to-end
// ══════════════════════════════════════════════════════════════════════════════

it('SimulatedImpact page calls the calculator and sets result with count', function (): void {
    $this->actingAs(simImpactUser('pricing_manager'));

    // A rule already on disk — edit page opens it.
    $rule = PricingRule::factory()->create([
        'scope' => PricingRule::SCOPE_BRAND_CATEGORY,
        'brand_id' => 10,
        'category_id' => 20,
        'margin_basis_points' => 4500,
        'active' => true,
    ]);

    // 1 matching product (so the simulate produces >=1 diff row)
    Product::factory()->create([
        'brand_id' => 10,
        'category_id' => 20,
        'buy_price' => '50.0000',
        'sell_price' => '81.0000',
    ]);

    Livewire::test(SimulatedImpact::class, ['record' => $rule->id])
        ->call('simulate')
        ->assertSet('result.count', 1);

    // No ProductPriceChanged event emitted by simulation.
    Event::assertNotDispatched(ProductPriceChanged::class);
});

it('SimulatedImpact page canAccess denies sales and read_only (update gate)', function (): void {
    $this->actingAs(simImpactUser('sales'));
    expect(SimulatedImpact::canAccess())->toBeFalse();

    $this->actingAs(simImpactUser('read_only'));
    expect(SimulatedImpact::canAccess())->toBeFalse();

    $this->actingAs(simImpactUser('pricing_manager'));
    expect(SimulatedImpact::canAccess())->toBeTrue();

    $this->actingAs(simImpactUser('admin'));
    expect(SimulatedImpact::canAccess())->toBeTrue();
});
