<?php

declare(strict_types=1);

use App\Domain\Pricing\Filament\Resources\PricingRuleResource\Pages\CreatePricingRule;
use App\Domain\Pricing\Filament\Resources\PricingRuleResource\Pages\EditPricingRule;
use App\Domain\Pricing\Filament\Resources\PricingRuleResource\Pages\ListPricingRules;
use App\Domain\Pricing\Filament\Resources\ProductOverrideResource\Pages\CreateProductOverride;
use App\Domain\Pricing\Filament\Resources\ProductOverrideResource\Pages\EditProductOverride;
use App\Domain\Pricing\Filament\Resources\ProductOverrideResource\Pages\ListProductOverrides;
use App\Domain\Pricing\Models\PricingRule;
use App\Domain\Pricing\Models\ProductOverride;
use App\Domain\Products\Models\Product;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| Plan 03-03 Task 1 — Filament Resource role-gating tests.
|--------------------------------------------------------------------------
|
| Asserts that the 4-role matrix flowed through Shield → RolePermissionSeeder
| → PricingRulePolicy / ProductOverridePolicy → Filament Resource correctly:
|
|   Action        admin   pricing_manager   sales   read_only
|   viewAny       ✅      ✅                ✅      ✅
|   create        ✅      ✅                ❌      ❌
|   update        ✅      ✅                ❌      ❌
|   delete        ✅      ✅                ❌      ❌
|
| Follows the Phase 2 ImportIssueResourceTest / ProductResourceTest pattern:
| Livewire::test(Page::class)->assertSuccessful() for reach; closure checks
| for action authorisation (Warning 9 defence-in-depth pattern).
*/

beforeEach(function (): void {
    // Seed the pricing_manager / admin / sales / read_only role + permission
    // set so Livewire pages authorise through the real policy chain. Must
    // clear the Spatie permission cache between tests too.
    $this->seed(RolePermissionSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    // Create the permissions Shield would emit so policy lookups don't
    // short-circuit when running in isolation (RefreshDatabase wipes the
    // permissions table between tests).
    $actions = ['view', 'view_any', 'create', 'update', 'delete', 'restore', 'force_delete'];
    foreach ($actions as $action) {
        foreach (['pricing::rule', 'product::override'] as $resource) {
            Permission::firstOrCreate(['name' => "{$action}_{$resource}", 'guard_name' => 'web']);
        }
    }

    // Re-seed so new permissions attach.
    $this->seed(RolePermissionSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function pricingRoleUser(string $roleName): User
{
    Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole($roleName);

    return $user->fresh();
}

// ══════════════════════════════════════════════════════════════════════════════
// PricingRuleResource — viewAny reach for all 4 roles
// ══════════════════════════════════════════════════════════════════════════════

it('admin reaches the pricing rule list', function (): void {
    $this->actingAs(pricingRoleUser('admin'));
    Livewire::test(ListPricingRules::class)->assertSuccessful();
});

it('pricing_manager reaches the pricing rule list', function (): void {
    $this->actingAs(pricingRoleUser('pricing_manager'));
    Livewire::test(ListPricingRules::class)->assertSuccessful();
});

it('read_only reaches the pricing rule list (view-only gate)', function (): void {
    $this->actingAs(pricingRoleUser('read_only'));
    Livewire::test(ListPricingRules::class)->assertSuccessful();
});

it('sales reaches the pricing rule list (viewAny is open to all 4 roles)', function (): void {
    $this->actingAs(pricingRoleUser('sales'));
    Livewire::test(ListPricingRules::class)->assertSuccessful();
});

// ══════════════════════════════════════════════════════════════════════════════
// PricingRulePolicy gate checks — create / update / delete denied for sales + read_only
// ══════════════════════════════════════════════════════════════════════════════

it('admin + pricing_manager CAN create a pricing rule (policy gate)', function (): void {
    foreach (['admin', 'pricing_manager'] as $role) {
        $user = pricingRoleUser($role);
        expect($user->can('create', PricingRule::class))->toBeTrue("role {$role} should be able to create");
    }
});

it('sales + read_only CANNOT create a pricing rule (policy gate)', function (): void {
    foreach (['sales', 'read_only'] as $role) {
        $user = pricingRoleUser($role);
        expect($user->can('create', PricingRule::class))->toBeFalse("role {$role} should NOT be able to create");
    }
});

it('admin + pricing_manager CAN update an existing pricing rule (policy gate)', function (): void {
    $rule = PricingRule::factory()->create();
    foreach (['admin', 'pricing_manager'] as $role) {
        $user = pricingRoleUser($role);
        expect($user->can('update', $rule))->toBeTrue("role {$role} should be able to update");
    }
});

it('sales + read_only CANNOT update a pricing rule (policy gate)', function (): void {
    $rule = PricingRule::factory()->create();
    foreach (['sales', 'read_only'] as $role) {
        $user = pricingRoleUser($role);
        expect($user->can('update', $rule))->toBeFalse("role {$role} should NOT be able to update");
    }
});

it('admin + pricing_manager CAN delete a pricing rule (policy gate)', function (): void {
    $rule = PricingRule::factory()->create();
    foreach (['admin', 'pricing_manager'] as $role) {
        $user = pricingRoleUser($role);
        expect($user->can('delete', $rule))->toBeTrue("role {$role} should be able to delete");
    }
});

it('sales + read_only CANNOT delete a pricing rule (policy gate)', function (): void {
    $rule = PricingRule::factory()->create();
    foreach (['sales', 'read_only'] as $role) {
        $user = pricingRoleUser($role);
        expect($user->can('delete', $rule))->toBeFalse("role {$role} should NOT be able to delete");
    }
});

// ══════════════════════════════════════════════════════════════════════════════
// PricingRuleResource CRUD through Livewire — pricing_manager happy path
// ══════════════════════════════════════════════════════════════════════════════

it('pricing_manager can persist a new pricing rule through the create form', function (): void {
    $this->actingAs(pricingRoleUser('pricing_manager'));

    Livewire::test(CreatePricingRule::class)
        ->fillForm([
            'scope' => PricingRule::SCOPE_BRAND,
            'brand_id' => 77,
            'margin_basis_points' => 2400,
            'priority' => 200,
            'is_default_tier' => false,
            'active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(PricingRule::where('brand_id', 77)->where('margin_basis_points', 2400)->exists())->toBeTrue();
});

it('pricing_manager can update margin on an existing rule through the edit form', function (): void {
    $this->actingAs(pricingRoleUser('pricing_manager'));
    $rule = PricingRule::factory()->create(['margin_basis_points' => 2500]);

    Livewire::test(EditPricingRule::class, ['record' => $rule->getRouteKey()])
        ->fillForm([
            'scope' => $rule->scope,
            'margin_basis_points' => 3100,
            'priority' => $rule->priority,
            'is_default_tier' => false,
            'active' => true,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($rule->fresh()->margin_basis_points)->toBe(3100);
});

// ══════════════════════════════════════════════════════════════════════════════
// ProductOverrideResource — reach + policy gate
// ══════════════════════════════════════════════════════════════════════════════

it('admin + pricing_manager + sales + read_only all reach the product override list', function (): void {
    foreach (['admin', 'pricing_manager', 'sales', 'read_only'] as $role) {
        $this->actingAs(pricingRoleUser($role));
        Livewire::test(ListProductOverrides::class)->assertSuccessful();
    }
});

it('admin + pricing_manager CAN create a ProductOverride (policy gate)', function (): void {
    foreach (['admin', 'pricing_manager'] as $role) {
        $user = pricingRoleUser($role);
        expect($user->can('create', ProductOverride::class))->toBeTrue("role {$role} should be able to create override");
    }
});

it('sales + read_only CANNOT create a ProductOverride (policy gate)', function (): void {
    foreach (['sales', 'read_only'] as $role) {
        $user = pricingRoleUser($role);
        expect($user->can('create', ProductOverride::class))->toBeFalse("role {$role} should NOT be able to create override");
    }
});

it('pricing_manager can persist a product override through the create form (D-08 unique enforced)', function (): void {
    $this->actingAs(pricingRoleUser('pricing_manager'));
    $product = Product::factory()->create();

    Livewire::test(CreateProductOverride::class)
        ->fillForm([
            'product_id' => $product->id,
            'margin_basis_points' => 4000,
            'reason' => 'Loss-leader promo',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(ProductOverride::where('product_id', $product->id)->exists())->toBeTrue();
});

it('D-08 form-layer unique rejects a duplicate product_id override', function (): void {
    $this->actingAs(pricingRoleUser('pricing_manager'));
    $product = Product::factory()->create();
    ProductOverride::factory()->create(['product_id' => $product->id, 'margin_basis_points' => 3500]);

    Livewire::test(CreateProductOverride::class)
        ->fillForm([
            'product_id' => $product->id,
            'margin_basis_points' => 4000,
        ])
        ->call('create')
        ->assertHasFormErrors(['product_id']);
});

// ══════════════════════════════════════════════════════════════════════════════
// Seeder attaches both new Resource permissions to pricing_manager
// ══════════════════════════════════════════════════════════════════════════════

it('RolePermissionSeeder attaches ≥7 pricing_rule perms + ≥7 product_override perms to pricing_manager', function (): void {
    $pm = Role::where('name', 'pricing_manager')->first();

    $pricingRuleCount = $pm->permissions()->where('name', 'like', '%pricing_rule')->count()
        + $pm->permissions()->where('name', 'like', '%pricing::rule')->count();

    $overrideCount = $pm->permissions()->where('name', 'like', '%product_override')->count()
        + $pm->permissions()->where('name', 'like', '%product::override')->count();

    expect($pricingRuleCount)->toBeGreaterThanOrEqual(7, "pricing_manager has {$pricingRuleCount} pricing_rule permissions");
    expect($overrideCount)->toBeGreaterThanOrEqual(7, "pricing_manager has {$overrideCount} product_override permissions");
});

// ══════════════════════════════════════════════════════════════════════════════
// ->authorize() defence-in-depth source-grep (Warning 9)
// ══════════════════════════════════════════════════════════════════════════════

it('PricingRuleResource uses ->authorize() on actions (Warning 9 pattern)', function (): void {
    $source = file_get_contents(app_path('Domain/Pricing/Filament/Resources/PricingRuleResource.php'));
    expect($source)->toContain('->authorize(');
});

it('ProductOverrideResource uses ->authorize() on actions (Warning 9 pattern)', function (): void {
    $source = file_get_contents(app_path('Domain/Pricing/Filament/Resources/ProductOverrideResource.php'));
    expect($source)->toContain('->authorize(');
});
