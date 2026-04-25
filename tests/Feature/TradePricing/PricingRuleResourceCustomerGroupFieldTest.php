<?php

declare(strict_types=1);

use App\Domain\Pricing\Filament\Resources\PricingRuleResource\Pages\CreatePricingRule;
use App\Domain\Pricing\Filament\Resources\PricingRuleResource\Pages\ListPricingRules;
use App\Domain\Pricing\Models\PricingRule;
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
| Phase 9 Plan 05 Task 2 — PricingRuleResource customer_group_id field test
|--------------------------------------------------------------------------
|
| Locks the additive D-09 invariant: PricingRuleResource gains ONE Select
| (customer_group_id, FIRST in form) + ONE SelectFilter (customer_group_id,
| alongside existing TernaryFilter('active') + SelectFilter('scope')) — and
| nothing else. Phase 3 form/table behaviour is preserved.
|
| Plus the W-05 RolePermissionSeeder extension: 5 customer_group_* perms
| seeded; admin + pricing_manager get all 5; sales gets view_any + view
| only; read_only is locked out entirely (D-10 matrix).
|
| Skip-on-MySQL-offline parity with Phase 6/7/8 + Plans 09-01..04 precedent.
| RefreshDatabase applied per-test via ->uses(RefreshDatabase::class) so the
| static source-grep tests (Tests 3, 4) run offline.
*/

function skipIfMySqlOfflinePricingRuleCgField(): void
{
    try {
        \DB::connection()->getPdo();
    } catch (\Throwable $e) {
        test()->markTestSkipped('MySQL offline: '.$e->getMessage());
    }
}

function pricingRuleCgRoleUser(string $roleName): User
{
    Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole($roleName);

    return $user->fresh();
}

function seedPricingRuleCgPermissions(): void
{
    test()->seed(RolePermissionSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    // Pre-create the perms RolePermissionSeeder LIKE-patterns expect for
    // PricingRule + CustomerGroup so isolation tests don't drop perms.
    $actions = ['view', 'view_any', 'create', 'update', 'delete', 'restore', 'force_delete'];
    foreach ($actions as $action) {
        Permission::firstOrCreate(['name' => "{$action}_pricing_rule", 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => "{$action}_pricing::rule", 'guard_name' => 'web']);
    }

    $cgPerms = [
        'view_any_customer_group',
        'view_customer_group',
        'create_customer_group',
        'update_customer_group',
        'delete_customer_group',
    ];
    foreach ($cgPerms as $perm) {
        Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
    }

    test()->seed(RolePermissionSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

// ══════════════════════════════════════════════════════════════════════════════
// Test 3 — D-09 additive invariant: existing PricingRuleResource shape preserved
// (DB-free source-grep — runs offline)
// ══════════════════════════════════════════════════════════════════════════════

it('PricingRuleResource preserves Phase 3 form fields (D-09 additive invariant)', function (): void {
    $source = file_get_contents(app_path('Domain/Pricing/Filament/Resources/PricingRuleResource.php'));

    // Existing form fields (Phase 3 — must stay).
    expect($source)->toContain("Select::make('scope')");
    expect($source)->toContain("TextInput::make('brand_id')");
    expect($source)->toContain("TextInput::make('category_id')");
    expect($source)->toContain("TextInput::make('margin_basis_points')");
    expect($source)->toContain("TextInput::make('priority')");
    expect($source)->toContain("Toggle::make('is_default_tier')");
    expect($source)->toContain("Toggle::make('active')");
    // Reactive scope behaviour — preserved.
    expect($source)->toContain('->reactive()');

    // New additive Select (D-09).
    expect($source)->toContain("Select::make('customer_group_id')");
    expect($source)->toContain("relationship('customerGroup', 'name')");
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 4 — D-09 additive invariant: existing table filters retained + new added
// (DB-free source-grep — runs offline)
// ══════════════════════════════════════════════════════════════════════════════

it('PricingRuleResource retains existing filters AND adds customer_group_id (D-09 additive invariant)', function (): void {
    $source = file_get_contents(app_path('Domain/Pricing/Filament/Resources/PricingRuleResource.php'));

    // Phase 3 filters — must stay.
    expect($source)->toContain("TernaryFilter::make('active')");
    expect($source)->toContain("SelectFilter::make('scope')");

    // New filter (D-09 additive).
    expect($source)->toContain("SelectFilter::make('customer_group_id')");

    // grep-counted: 4 retained + 2 added = at least 5 Select/Filter make() calls
    // (TernaryFilter + 2× SelectFilter + 2× Select::make from form).
    $count = substr_count($source, 'TernaryFilter::make')
        + substr_count($source, 'SelectFilter::make')
        + substr_count($source, 'Select::make');
    expect($count)->toBeGreaterThanOrEqual(5, "Expected ≥5 Select/Filter make() calls; got {$count}");
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 1 — admin can create a rule with null customer_group_id (retail)
// AND with a non-null customer_group_id (trade)
// ══════════════════════════════════════════════════════════════════════════════

it('admin can create a PricingRule with null customer_group_id (retail) and with a group (Test 1)', function (): void {
    skipIfMySqlOfflinePricingRuleCgField();
    seedPricingRuleCgPermissions();

    test()->actingAs(pricingRuleCgRoleUser('admin'));
    $group = CustomerGroup::factory()->create(['slug' => 'trade-test-group']);

    // Retail rule (customer_group_id null).
    Livewire::test(CreatePricingRule::class)
        ->fillForm([
            'customer_group_id' => null,
            'scope' => PricingRule::SCOPE_BRAND,
            'brand_id' => 101,
            'margin_basis_points' => 2200,
            'priority' => 100,
            'is_default_tier' => false,
            'active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(PricingRule::where('brand_id', 101)->whereNull('customer_group_id')->exists())->toBeTrue();

    // Trade rule (customer_group_id set).
    Livewire::test(CreatePricingRule::class)
        ->fillForm([
            'customer_group_id' => $group->id,
            'scope' => PricingRule::SCOPE_BRAND,
            'brand_id' => 102,
            'margin_basis_points' => 2400,
            'priority' => 200,
            'is_default_tier' => false,
            'active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(PricingRule::where('brand_id', 102)->where('customer_group_id', $group->id)->exists())->toBeTrue();
})->uses(RefreshDatabase::class);

// ══════════════════════════════════════════════════════════════════════════════
// Test 2 — SelectFilter on customer_group_id filters list correctly
// ══════════════════════════════════════════════════════════════════════════════

it('SelectFilter on customer_group_id filters list correctly (Test 2)', function (): void {
    skipIfMySqlOfflinePricingRuleCgField();
    seedPricingRuleCgPermissions();

    test()->actingAs(pricingRuleCgRoleUser('admin'));

    $tradeGroup = CustomerGroup::factory()->create(['slug' => 'filter-trade']);
    $resellerGroup = CustomerGroup::factory()->create(['slug' => 'filter-reseller']);

    $tradeRule = PricingRule::factory()->create([
        'scope' => PricingRule::SCOPE_BRAND,
        'brand_id' => 201,
        'customer_group_id' => $tradeGroup->id,
        'active' => true,
    ]);
    $resellerRule = PricingRule::factory()->create([
        'scope' => PricingRule::SCOPE_BRAND,
        'brand_id' => 202,
        'customer_group_id' => $resellerGroup->id,
        'active' => true,
    ]);
    $retailRule = PricingRule::factory()->create([
        'scope' => PricingRule::SCOPE_BRAND,
        'brand_id' => 203,
        'customer_group_id' => null,
        'active' => true,
    ]);

    Livewire::test(ListPricingRules::class)
        ->filterTable('customer_group_id', $tradeGroup->id)
        ->assertCanSeeTableRecords([$tradeRule])
        ->assertCanNotSeeTableRecords([$resellerRule, $retailRule]);
})->uses(RefreshDatabase::class);

// ══════════════════════════════════════════════════════════════════════════════
// Test 5 — RBAC matrix: post-seed, the customer_group permissions land per D-10
// ══════════════════════════════════════════════════════════════════════════════

it('RolePermissionSeeder grants customer_group perms per D-10 matrix (Test 5)', function (): void {
    skipIfMySqlOfflinePricingRuleCgField();
    seedPricingRuleCgPermissions();

    $admin = Role::findByName('admin');
    $pricingManager = Role::findByName('pricing_manager');
    $sales = Role::findByName('sales');
    $readOnly = Role::findByName('read_only');

    $allFive = [
        'view_any_customer_group',
        'view_customer_group',
        'create_customer_group',
        'update_customer_group',
        'delete_customer_group',
    ];

    // admin → all 5.
    foreach ($allFive as $perm) {
        expect($admin->hasPermissionTo($perm))->toBeTrue("admin must have {$perm}");
    }

    // pricing_manager → all 5.
    foreach ($allFive as $perm) {
        expect($pricingManager->hasPermissionTo($perm))->toBeTrue("pricing_manager must have {$perm}");
    }

    // sales → view_any + view ONLY.
    expect($sales->hasPermissionTo('view_any_customer_group'))->toBeTrue();
    expect($sales->hasPermissionTo('view_customer_group'))->toBeTrue();
    expect($sales->hasPermissionTo('create_customer_group'))->toBeFalse('sales must NOT have create');
    expect($sales->hasPermissionTo('update_customer_group'))->toBeFalse('sales must NOT have update');
    expect($sales->hasPermissionTo('delete_customer_group'))->toBeFalse('sales must NOT have delete');

    // read_only → none (D-10 lock-out; revoked at step 4b).
    foreach ($allFive as $perm) {
        expect($readOnly->hasPermissionTo($perm))->toBeFalse("read_only must NOT have {$perm} (D-10 lock-out)");
    }
})->uses(RefreshDatabase::class);

// ══════════════════════════════════════════════════════════════════════════════
// Test 6 — CustomerGroupPolicy gates each action against the matching string
// (cross-checks Plan 05 Task 1 policy file behaviour against Task 2 perms)
// ══════════════════════════════════════════════════════════════════════════════

it('CustomerGroupPolicy gates each action via $user->can(*_customer_group) (Test 6)', function (): void {
    skipIfMySqlOfflinePricingRuleCgField();
    seedPricingRuleCgPermissions();

    $admin = pricingRuleCgRoleUser('admin');
    $pricingManager = pricingRuleCgRoleUser('pricing_manager');
    $sales = pricingRuleCgRoleUser('sales');
    $readOnly = pricingRuleCgRoleUser('read_only');

    $group = CustomerGroup::factory()->create();

    // admin + pricing_manager — full CRUD.
    foreach ([$admin, $pricingManager] as $u) {
        expect($u->can('viewAny', CustomerGroup::class))->toBeTrue();
        expect($u->can('view', $group))->toBeTrue();
        expect($u->can('create', CustomerGroup::class))->toBeTrue();
        expect($u->can('update', $group))->toBeTrue();
        expect($u->can('delete', $group))->toBeTrue();
    }

    // sales — view-only.
    expect($sales->can('viewAny', CustomerGroup::class))->toBeTrue();
    expect($sales->can('view', $group))->toBeTrue();
    expect($sales->can('create', CustomerGroup::class))->toBeFalse();
    expect($sales->can('update', $group))->toBeFalse();
    expect($sales->can('delete', $group))->toBeFalse();

    // read_only — locked out entirely.
    expect($readOnly->can('viewAny', CustomerGroup::class))->toBeFalse();
    expect($readOnly->can('view', $group))->toBeFalse();
    expect($readOnly->can('create', CustomerGroup::class))->toBeFalse();
    expect($readOnly->can('update', $group))->toBeFalse();
    expect($readOnly->can('delete', $group))->toBeFalse();
})->uses(RefreshDatabase::class);
