<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Phase 7 Plan 02 D-03 (post-09.1 #3) — HorizonDashboardPage RBAC + banner
|--------------------------------------------------------------------------
|
| Locks the access matrix + Redis-down banner for /admin/horizon.
*/

use App\Filament\Pages\Horizon\HorizonDashboardPage;
use App\Models\User;
use Illuminate\Support\Facades\Redis;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

function horizonDashboardRoleUser(string $roleName): User
{
    Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole($roleName);

    return $user->fresh();
}

it('admin can access HorizonDashboardPage', function (): void {
    $admin = horizonDashboardRoleUser('admin');
    $this->actingAs($admin);

    expect(HorizonDashboardPage::canAccess())->toBeTrue();

    Livewire::test(HorizonDashboardPage::class)->assertSuccessful();
});

it('pricing_manager is denied access', function (): void {
    $user = horizonDashboardRoleUser('pricing_manager');
    $this->actingAs($user);

    expect(HorizonDashboardPage::canAccess())->toBeFalse();
});

it('sales is denied access', function (): void {
    $user = horizonDashboardRoleUser('sales');
    $this->actingAs($user);

    expect(HorizonDashboardPage::canAccess())->toBeFalse();
});

it('read_only is denied access', function (): void {
    $user = horizonDashboardRoleUser('read_only');
    $this->actingAs($user);

    expect(HorizonDashboardPage::canAccess())->toBeFalse();
});

it('renders the Redis warning banner when Redis is unreachable', function (): void {
    $admin = horizonDashboardRoleUser('admin');
    $this->actingAs($admin);

    Redis::shouldReceive('connection')
        ->andThrow(new \RuntimeException('Connection refused [tcp://127.0.0.1:6379]'));

    Livewire::test(HorizonDashboardPage::class)
        ->assertSuccessful()
        ->assertSee('Horizon requires Redis. Currently unreachable.')
        ->assertSee('Connection refused');
});
