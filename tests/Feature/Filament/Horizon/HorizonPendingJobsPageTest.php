<?php

declare(strict_types=1);

use App\Filament\Pages\Horizon\HorizonPendingJobsPage;
use App\Models\User;
use Illuminate\Support\Facades\Redis;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

function horizonPendingRoleUser(string $roleName): User
{
    Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole($roleName);

    return $user->fresh();
}

it('admin can access HorizonPendingJobsPage', function (): void {
    $admin = horizonPendingRoleUser('admin');
    $this->actingAs($admin);

    expect(HorizonPendingJobsPage::canAccess())->toBeTrue();
});

it('pricing_manager is denied access', function (): void {
    $user = horizonPendingRoleUser('pricing_manager');
    $this->actingAs($user);

    expect(HorizonPendingJobsPage::canAccess())->toBeFalse();
});

it('sales is denied access', function (): void {
    $user = horizonPendingRoleUser('sales');
    $this->actingAs($user);

    expect(HorizonPendingJobsPage::canAccess())->toBeFalse();
});

it('read_only is denied access', function (): void {
    $user = horizonPendingRoleUser('read_only');
    $this->actingAs($user);

    expect(HorizonPendingJobsPage::canAccess())->toBeFalse();
});

it('renders the Redis warning banner when Redis is unreachable', function (): void {
    $admin = horizonPendingRoleUser('admin');
    $this->actingAs($admin);

    Redis::shouldReceive('connection')
        ->andThrow(new \RuntimeException('Connection refused [tcp://127.0.0.1:6379]'));

    Livewire::test(HorizonPendingJobsPage::class)
        ->assertSuccessful()
        ->assertSee('Horizon requires Redis. Currently unreachable.');
});
