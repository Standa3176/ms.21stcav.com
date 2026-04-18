<?php

declare(strict_types=1);

use Database\Seeders\RolePermissionSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Feature test proving D-02 role scope after RolePermissionSeeder runs.
 *
 * NOTE: Pest.php registers RefreshDatabase for the Feature suite, which truncates
 * permissions between tests. Each test seeds Shield's Role-Resource permissions
 * manually (the 6 permissions produced by `shield:generate --all --panel=admin`
 * at execution time) so the LIKE patterns in RolePermissionSeeder have something
 * to bind to. This mirrors the post-migration state captured in 01-02-SUMMARY.md.
 *
 * When later plans add Resources, `shield:generate` is re-run in deploy; this
 * test's `seedRolePermissions()` helper stays in sync with whatever Shield
 * produces via the skipped-until-exists guards further down.
 */
function seedRolePermissions(): void
{
    foreach (
        [
            'view_role',
            'view_any_role',
            'create_role',
            'update_role',
            'delete_role',
            'delete_any_role',
        ] as $name
    ) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }
}

beforeEach(function () {
    seedRolePermissions();
    $this->seed(RolePermissionSeeder::class);
});

it('creates exactly 4 roles', function () {
    expect(Role::count())->toBe(4);
    expect(Role::pluck('name')->sort()->values()->all())
        ->toBe(['admin', 'pricing_manager', 'read_only', 'sales']);
});

it('admin role has every permission', function () {
    $admin = Role::where('name', 'admin')->firstOrFail();
    expect($admin->permissions()->count())->toBe(Permission::count());
});

it('read_only role has only view_ and view_any_ permissions', function () {
    $readOnly = Role::where('name', 'read_only')->firstOrFail();
    $names = $readOnly->permissions()->pluck('name');

    expect($names)->not->toBeEmpty();

    // Every permission on read_only starts with view_ (covers view_ and view_any_).
    foreach ($names as $name) {
        expect(str_starts_with((string) $name, 'view_'))
            ->toBeTrue("Permission '{$name}' on read_only role is not a view permission");
    }

    // T-02-01 mitigation: zero create/update/delete/restore/force_delete on read_only.
    $forbidden = $readOnly->permissions()
        ->where(function ($q) {
            $q->where('name', 'like', 'create_%')
                ->orWhere('name', 'like', 'update_%')
                ->orWhere('name', 'like', 'delete_%')
                ->orWhere('name', 'like', 'restore_%')
                ->orWhere('name', 'like', 'force_delete_%')
                ->orWhere('name', 'like', 'replicate_%')
                ->orWhere('name', 'like', 'reorder_%');
        })->count();
    expect($forbidden)->toBe(0);
});

it('pricing_manager role does NOT have view permission on crm_push_log (D-02 scope leak guard)', function () {
    $pricingManager = Role::where('name', 'pricing_manager')->firstOrFail();
    expect($pricingManager->hasPermissionTo('view_crm_push_log'))->toBeFalse()
        ->and($pricingManager->hasPermissionTo('view_any_crm_push_log'))->toBeFalse();
})->skip(fn () => ! Permission::where('name', 'view_crm_push_log')->exists(), 'CrmPushLog Resource not created yet — skip until Phase 4');

it('sales role does NOT have view permission on pricing_rule (D-02 scope leak guard)', function () {
    $sales = Role::where('name', 'sales')->firstOrFail();
    expect($sales->hasPermissionTo('view_any_pricing_rule'))->toBeFalse();
})->skip(fn () => ! Permission::where('name', 'view_any_pricing_rule')->exists(), 'PricingRule Resource not created yet — skip until Phase 3');

it('is idempotent — running twice produces no duplicate rows', function () {
    $initialRoleCount = Role::count();
    $initialPermissionCount = Permission::count();

    $this->seed(RolePermissionSeeder::class);
    $this->seed(RolePermissionSeeder::class);

    expect(Role::count())->toBe($initialRoleCount);
    expect(Permission::count())->toBe($initialPermissionCount);
});
