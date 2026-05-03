<?php

declare(strict_types=1);

use App\Filament\Pages\Horizon\HorizonCompletedJobsPage;
use App\Models\User;
use Illuminate\Support\Facades\Redis;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

function horizonCompletedRoleUser(string $roleName): User
{
    Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole($roleName);

    return $user->fresh();
}

it('admin can access HorizonCompletedJobsPage', function (): void {
    $admin = horizonCompletedRoleUser('admin');
    $this->actingAs($admin);

    expect(HorizonCompletedJobsPage::canAccess())->toBeTrue();
});

it('pricing_manager is denied access', function (): void {
    $user = horizonCompletedRoleUser('pricing_manager');
    $this->actingAs($user);

    expect(HorizonCompletedJobsPage::canAccess())->toBeFalse();
});

it('sales is denied access', function (): void {
    $user = horizonCompletedRoleUser('sales');
    $this->actingAs($user);

    expect(HorizonCompletedJobsPage::canAccess())->toBeFalse();
});

it('read_only is denied access', function (): void {
    $user = horizonCompletedRoleUser('read_only');
    $this->actingAs($user);

    expect(HorizonCompletedJobsPage::canAccess())->toBeFalse();
});

it('renders the Redis warning banner when Redis is unreachable', function (): void {
    $admin = horizonCompletedRoleUser('admin');
    $this->actingAs($admin);

    Redis::shouldReceive('connection')
        ->andThrow(new \RuntimeException('Connection refused [tcp://127.0.0.1:6379]'));

    Livewire::test(HorizonCompletedJobsPage::class)
        ->assertSuccessful()
        ->assertSee('Horizon requires Redis. Currently unreachable.');
});
