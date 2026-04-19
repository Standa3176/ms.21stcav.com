<?php

declare(strict_types=1);

use App\Domain\Pricing\Filament\Resources\PricingRuleResource\Pages\RuleExplorer;
use App\Domain\Pricing\Models\PricingRule;
use App\Domain\Pricing\Models\ProductOverride;
use App\Domain\Products\Models\Product;
use App\Domain\Products\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\Phase3\DefaultPricingTierSeeder;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| Plan 03-03 Task 2 — RuleExplorer page resolution + render tests (PRCE-08).
|--------------------------------------------------------------------------
|
| Uses Livewire component testing against the Filament custom Page:
|   livewire(RuleExplorer::class)
|     ->fillForm(['sku' => …])
|     ->call('lookup')
|     ->assertSet('resolution.sell_pennies', …)
|
| The page resolves a Product by SKU, walks the 5-layer RuleResolver, computes
| the effective retail price via PriceCalculator, and sets $resolution (or
| $lastError when something goes wrong). No DB writes. No events dispatched.
*/

beforeEach(function (): void {
    // Create roles + Shield-style permissions so the policy gate + page canAccess resolve.
    $this->seed(RolePermissionSeeder::class);
    $this->seed(DefaultPricingTierSeeder::class);

    $actions = ['view', 'view_any', 'create', 'update', 'delete', 'restore', 'force_delete'];
    foreach ($actions as $action) {
        foreach (['pricing::rule', 'product::override'] as $resource) {
            Permission::firstOrCreate(['name' => "{$action}_{$resource}", 'guard_name' => 'web']);
        }
    }

    $this->seed(RolePermissionSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function ruleExplorerUser(string $roleName): User
{
    Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole($roleName);

    return $user->fresh();
}

// ══════════════════════════════════════════════════════════════════════════════
// Test 1 — unknown SKU → lastError set, resolution null
// ══════════════════════════════════════════════════════════════════════════════

it('unknown SKU sets lastError and leaves resolution null', function (): void {
    $this->actingAs(ruleExplorerUser('pricing_manager'));

    Livewire::test(RuleExplorer::class)
        ->fillForm(['sku' => 'NONEXISTENT-SKU-999'])
        ->call('lookup')
        ->assertSet('resolution', null)
        ->assertSet('lastError', 'No product found for SKU NONEXISTENT-SKU-999.');
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 2 — known simple product resolves through default_tier
// ══════════════════════════════════════════════════════════════════════════════

it('simple product with no brand/category resolves through default_tier', function (): void {
    $this->actingAs(ruleExplorerUser('pricing_manager'));

    $product = Product::factory()->create([
        'sku' => 'TEST-001',
        'buy_price' => '50.0000',
        'brand_id' => null,
        'category_id' => null,
    ]);

    // 5000 × (10000 + 3500) × (10000 + 2000) / 100_000_000
    //   = 5000 × 13500 × 12000 / 100_000_000
    //   = 810_000_000_000 / 100_000_000
    //   = 8100 pennies = £81.00

    Livewire::test(RuleExplorer::class)
        ->fillForm(['sku' => 'TEST-001'])
        ->call('lookup')
        ->assertSet('lastError', null)
        ->assertSet('resolution.sell_pennies', 8100)
        ->assertSet('resolution.source', 'default_tier')
        ->assertSet('resolution.buy_pennies', 5000)
        ->assertSet('resolution.margin_basis_points', 3500);
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 3 — brand_category match → source=brand_category, chain contains it
// ══════════════════════════════════════════════════════════════════════════════

it('product with brand+category rule resolves source=brand_category with chain first', function (): void {
    $this->actingAs(ruleExplorerUser('pricing_manager'));

    PricingRule::factory()->create([
        'scope' => PricingRule::SCOPE_BRAND_CATEGORY,
        'brand_id' => 10,
        'category_id' => 20,
        'margin_basis_points' => 4500,
        'active' => true,
        'priority' => 150,
    ]);

    $product = Product::factory()->create([
        'sku' => 'BC-001',
        'buy_price' => '50.0000',
        'brand_id' => 10,
        'category_id' => 20,
    ]);

    Livewire::test(RuleExplorer::class)
        ->fillForm(['sku' => 'BC-001'])
        ->call('lookup')
        ->assertSet('resolution.source', 'brand_category')
        ->assertSet('resolution.margin_basis_points', 4500);

    $component = Livewire::test(RuleExplorer::class)
        ->fillForm(['sku' => 'BC-001'])
        ->call('lookup');
    expect($component->get('resolution')['chain'])->toContain('brand_category');
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 4 — override wins over every rule
// ══════════════════════════════════════════════════════════════════════════════

it('product with override resolves source=override, chain=[override], override_id populated', function (): void {
    $this->actingAs(ruleExplorerUser('pricing_manager'));

    $product = Product::factory()->create([
        'sku' => 'OVR-001',
        'buy_price' => '50.0000',
        'brand_id' => 1,
        'category_id' => 1,
    ]);

    $override = ProductOverride::factory()->create([
        'product_id' => $product->id,
        'margin_basis_points' => 6000,
    ]);

    $component = Livewire::test(RuleExplorer::class)
        ->fillForm(['sku' => 'OVR-001'])
        ->call('lookup');

    $resolution = $component->get('resolution');

    expect($resolution['source'])->toBe('override');
    expect($resolution['chain'])->toBe(['override']);
    expect($resolution['override_id'])->toBe($override->id);
    expect($resolution['margin_basis_points'])->toBe(6000);
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 5 — zero buy_price → friendly error (not a stack trace from calculator)
// ══════════════════════════════════════════════════════════════════════════════

it('product with zero buy_price surfaces a friendly error, not a calculator exception', function (): void {
    $this->actingAs(ruleExplorerUser('pricing_manager'));

    Product::factory()->create([
        'sku' => 'ZERO-001',
        'buy_price' => '0.0000',
        'brand_id' => null,
        'category_id' => null,
    ]);

    $component = Livewire::test(RuleExplorer::class)
        ->fillForm(['sku' => 'ZERO-001'])
        ->call('lookup');

    expect($component->get('resolution'))->toBeNull();
    expect($component->get('lastError'))->toContain('zero / null buy_price');
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 6 — unauthenticated / unauthorised access (sanity check)
// ══════════════════════════════════════════════════════════════════════════════

it('canAccess returns false when no user is authenticated', function (): void {
    expect(RuleExplorer::canAccess())->toBeFalse();
});

it('canAccess returns true for all 4 roles (viewAny is open)', function (): void {
    foreach (['admin', 'pricing_manager', 'sales', 'read_only'] as $role) {
        $this->actingAs(ruleExplorerUser($role));
        expect(RuleExplorer::canAccess())->toBeTrue("role {$role} should be able to reach Rule Explorer");
    }
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 7 — variant SKU lookup falls back to parent Product for resolution
// ══════════════════════════════════════════════════════════════════════════════

it('variant SKU resolves via parent Product (brand/category inherited)', function (): void {
    $this->actingAs(ruleExplorerUser('pricing_manager'));

    // Parent carries the brand/category keys + tier-qualifying buy_price used by
    // RuleResolver; the variant carries its own buy_price that PriceCalculator
    // consumes at the boundary.
    $parent = Product::factory()->variable()->create([
        'brand_id' => null,
        'category_id' => null,
        'buy_price' => '50.0000',   // ensures default_tier <£100 (35%) wins
    ]);

    $variant = ProductVariant::factory()->create([
        'product_id' => $parent->id,
        'sku' => 'VAR-BLUE-001',
        'buy_price' => '50.0000',
    ]);

    $component = Livewire::test(RuleExplorer::class)
        ->fillForm(['sku' => 'VAR-BLUE-001'])
        ->call('lookup');

    expect($component->get('resolution'))->not->toBeNull();
    expect($component->get('resolution')['sell_pennies'])->toBe(8100);
    expect($component->get('resolution')['variant_id'])->toBe($variant->id);
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 8 — empty SKU input surfaces its own error
// ══════════════════════════════════════════════════════════════════════════════

it('empty SKU input sets lastError asking user to enter a SKU', function (): void {
    $this->actingAs(ruleExplorerUser('pricing_manager'));

    Livewire::test(RuleExplorer::class)
        ->fillForm(['sku' => '   '])   // whitespace only
        ->call('lookup')
        ->assertSet('resolution', null)
        ->assertSet('lastError', 'Enter a SKU to look up.');
});
