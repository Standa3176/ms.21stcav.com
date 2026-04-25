<?php

declare(strict_types=1);

use App\Domain\Pricing\Filament\Resources\PricingRuleResource;
use App\Domain\Pricing\Models\PricingRule;
use App\Domain\TradePricing\Filament\Resources\CustomerGroupResource;
use App\Domain\TradePricing\Filament\Resources\CustomerGroupResource\Pages\CreateCustomerGroup;
use App\Domain\TradePricing\Filament\Resources\CustomerGroupResource\Pages\EditCustomerGroup;
use App\Domain\TradePricing\Filament\Resources\CustomerGroupResource\Pages\ListCustomerGroups;
use App\Domain\TradePricing\Models\CustomerGroup;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| Phase 9 Plan 05 Task 1 — CustomerGroupResource feature test (TRDE-04)
|--------------------------------------------------------------------------
|
| Locks the CRUD + RBAC matrix + I-01 navigationSort invariant for the new
| CustomerGroupResource (D-10). Mirrors PricingRuleResourceAccessTest's
| skipIfMySqlOffline guard pattern (Phase 6/7/8 + Plans 09-01..04 precedent).
|
| Role matrix (CustomerGroupPolicy):
|   Action        admin   pricing_manager   sales   read_only
|   viewAny       ✅      ✅                ✅      ❌
|   view          ✅      ✅                ✅      ❌
|   create        ✅      ✅                ❌      ❌
|   update        ✅      ✅                ❌      ❌
|   delete        ✅      ✅                ❌      ❌
|
| I-01 invariant: CustomerGroupResource $navigationSort != PricingRuleResource
| $navigationSort. Reflection-based assertion runs offline (no DB needed).
|
| RefreshDatabase is applied PER-TEST via ->uses(RefreshDatabase::class)
| rather than file-globally so the I-01 reflection test runs even when
| MySQL is offline (Phase 9 Plan 02 TradeRuleResolverPurityTest precedent).
*/

function skipIfMySqlOfflineCustomerGroupResource(): void
{
    try {
        \DB::connection()->getPdo();
    } catch (\Throwable $e) {
        test()->markTestSkipped('MySQL offline: '.$e->getMessage());
    }
}

function customerGroupRoleUser(string $roleName): User
{
    Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole($roleName);

    return $user->fresh();
}

function seedCustomerGroupPermissions(): void
{
    test()->seed(RolePermissionSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    // Pre-create the 5 customer_group permissions Shield would emit so policy
    // lookups work in isolation (RefreshDatabase wipes permissions between tests).
    $tradePricingPerms = [
        'view_any_customer_group',
        'view_customer_group',
        'create_customer_group',
        'update_customer_group',
        'delete_customer_group',
    ];
    foreach ($tradePricingPerms as $perm) {
        Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
    }

    // Re-seed so the new permissions attach to the roles per Plan 05 Task 2.
    test()->seed(RolePermissionSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

// ══════════════════════════════════════════════════════════════════════════════
// Test 8 (I-01) — navigationSort collision check (DB-free, ALWAYS runs)
// ══════════════════════════════════════════════════════════════════════════════

it('CustomerGroupResource navigationSort does not collide with PricingRuleResource (I-01)', function (): void {
    // Reflection so private/protected static properties are reachable.
    $cg = (new ReflectionClass(CustomerGroupResource::class))
        ->getStaticPropertyValue('navigationSort');
    $pr = (new ReflectionClass(PricingRuleResource::class))
        ->getStaticPropertyValue('navigationSort');

    expect($cg)->not->toBe($pr, "navigationSort collision: both Resources sort to {$pr}");
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 1-2: CRUD reach for admin + pricing_manager
// ══════════════════════════════════════════════════════════════════════════════

it('admin can create a CustomerGroup via Filament (Test 1)', function (): void {
    skipIfMySqlOfflineCustomerGroupResource();
    seedCustomerGroupPermissions();

    test()->actingAs(customerGroupRoleUser('admin'));

    Livewire::test(CreateCustomerGroup::class)
        ->fillForm([
            'slug' => 'wholesale-distributor',
            'name' => 'Wholesale Distributor',
            'display_order' => 50,
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(CustomerGroup::where('slug', 'wholesale-distributor')->exists())->toBeTrue();
})->uses(RefreshDatabase::class);

it('pricing_manager can update a CustomerGroup via Filament (Test 2)', function (): void {
    skipIfMySqlOfflineCustomerGroupResource();
    seedCustomerGroupPermissions();

    test()->actingAs(customerGroupRoleUser('pricing_manager'));
    $group = CustomerGroup::factory()->create([
        'slug' => 'oem-account',
        'name' => 'OEM Account',
        'display_order' => 60,
    ]);

    Livewire::test(EditCustomerGroup::class, ['record' => $group->getRouteKey()])
        ->fillForm([
            'slug' => 'oem-account',
            'name' => 'OEM Strategic Account',
            'display_order' => 65,
            'is_active' => true,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($group->fresh()->name)->toBe('OEM Strategic Account');
})->uses(RefreshDatabase::class);

// ══════════════════════════════════════════════════════════════════════════════
// Test 3: sales is view-only (policy gate)
// ══════════════════════════════════════════════════════════════════════════════

it('sales can VIEW the CustomerGroup index but CANNOT create/update/delete (Test 3)', function (): void {
    skipIfMySqlOfflineCustomerGroupResource();
    seedCustomerGroupPermissions();

    test()->actingAs(customerGroupRoleUser('sales'));

    // Reach: list page renders for sales.
    Livewire::test(ListCustomerGroups::class)->assertSuccessful();

    // Policy gates: create/update/delete denied for sales.
    $user = customerGroupRoleUser('sales');
    $group = CustomerGroup::factory()->create();
    expect($user->can('create', CustomerGroup::class))->toBeFalse('sales should NOT be able to create');
    expect($user->can('update', $group))->toBeFalse('sales should NOT be able to update');
    expect($user->can('delete', $group))->toBeFalse('sales should NOT be able to delete');
})->uses(RefreshDatabase::class);

// ══════════════════════════════════════════════════════════════════════════════
// Test 4: read_only cannot view CustomerGroupResource at all
// ══════════════════════════════════════════════════════════════════════════════

it('read_only user cannot view CustomerGroupResource (Test 4)', function (): void {
    skipIfMySqlOfflineCustomerGroupResource();
    seedCustomerGroupPermissions();

    $user = customerGroupRoleUser('read_only');
    $group = CustomerGroup::factory()->create();

    expect($user->can('viewAny', CustomerGroup::class))->toBeFalse('read_only should NOT have viewAny');
    expect($user->can('view', $group))->toBeFalse('read_only should NOT have view');
    expect($user->can('create', CustomerGroup::class))->toBeFalse('read_only should NOT have create');
    expect($user->can('update', $group))->toBeFalse('read_only should NOT have update');
    expect($user->can('delete', $group))->toBeFalse('read_only should NOT have delete');
})->uses(RefreshDatabase::class);

// ══════════════════════════════════════════════════════════════════════════════
// Test 5: slug uniqueness enforced
// ══════════════════════════════════════════════════════════════════════════════

it('slug uniqueness enforced — second create with same slug fails validation (Test 5)', function (): void {
    skipIfMySqlOfflineCustomerGroupResource();
    seedCustomerGroupPermissions();

    test()->actingAs(customerGroupRoleUser('admin'));
    CustomerGroup::factory()->create(['slug' => 'aerospace-defence']);

    Livewire::test(CreateCustomerGroup::class)
        ->fillForm([
            'slug' => 'aerospace-defence',
            'name' => 'Aerospace & Defence (duplicate)',
            'display_order' => 70,
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasFormErrors(['slug']);
})->uses(RefreshDatabase::class);

// ══════════════════════════════════════════════════════════════════════════════
// Test 6: alphaDash validation rejects non-slug strings
// ══════════════════════════════════════════════════════════════════════════════

it('alphaDash validation rejects "Trade Customer" (slug must be lowercase-with-hyphens) (Test 6)', function (): void {
    skipIfMySqlOfflineCustomerGroupResource();
    seedCustomerGroupPermissions();

    test()->actingAs(customerGroupRoleUser('admin'));

    Livewire::test(CreateCustomerGroup::class)
        ->fillForm([
            'slug' => 'Trade Customer', // space + capital — alphaDash should reject.
            'name' => 'Trade Customer',
            'display_order' => 80,
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasFormErrors(['slug']);
})->uses(RefreshDatabase::class);

// ══════════════════════════════════════════════════════════════════════════════
// Test 7: FK ON DELETE RESTRICT actionable error when deleting group with rules
// ══════════════════════════════════════════════════════════════════════════════

it('deleting a CustomerGroup with active pricing_rules raises a Filament-handled error (Test 7)', function (): void {
    skipIfMySqlOfflineCustomerGroupResource();
    seedCustomerGroupPermissions();

    test()->actingAs(customerGroupRoleUser('admin'));
    $group = CustomerGroup::factory()->create([
        'slug' => 'fk-restrict-test',
        'name' => 'FK Restrict Test',
    ]);

    // Attach a pricing rule to the group — FK ON DELETE RESTRICT should
    // block the delete and surface as a QueryException.
    PricingRule::factory()->create([
        'scope' => PricingRule::SCOPE_BRAND,
        'brand_id' => 88,
        'customer_group_id' => $group->id,
        'margin_basis_points' => 2200,
        'priority' => 200,
        'active' => true,
    ]);

    // Plan 09-01 schema — pricing_rules.customer_group_id has restrictOnDelete.
    // Eloquent Model::delete() bubbles QueryException; assert the FK guard fires.
    expect(fn () => $group->delete())->toThrow(\Illuminate\Database\QueryException::class);

    // Group still present (delete blocked).
    expect(CustomerGroup::where('id', $group->id)->exists())->toBeTrue();
})->uses(RefreshDatabase::class);
