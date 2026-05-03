<?php

declare(strict_types=1);

use App\Filament\Pages\Horizon\HorizonSilencedJobsPage;
use App\Models\User;
use Illuminate\Support\Facades\Redis;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

function horizonSilencedRoleUser(string $roleName): User
{
    Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole($roleName);

    return $user->fresh();
}

it('admin can access HorizonSilencedJobsPage', function (): void {
    $admin = horizonSilencedRoleUser('admin');
    $this->actingAs($admin);

    expect(HorizonSilencedJobsPage::canAccess())->toBeTrue();
});

it('pricing_manager is denied access', function (): void {
    $user = horizonSilencedRoleUser('pricing_manager');
    $this->actingAs($user);

    expect(HorizonSilencedJobsPage::canAccess())->toBeFalse();
});

it('sales is denied access', function (): void {
    $user = horizonSilencedRoleUser('sales');
    $this->actingAs($user);

    expect(HorizonSilencedJobsPage::canAccess())->toBeFalse();
});

it('read_only is denied access', function (): void {
    $user = horizonSilencedRoleUser('read_only');
    $this->actingAs($user);

    expect(HorizonSilencedJobsPage::canAccess())->toBeFalse();
});

it('renders the Redis warning banner when Redis is unreachable', function (): void {
    $admin = horizonSilencedRoleUser('admin');
    $this->actingAs($admin);

    Redis::shouldReceive('connection')
        ->andThrow(new \RuntimeException('Connection refused [tcp://127.0.0.1:6379]'));

    Livewire::test(HorizonSilencedJobsPage::class)
        ->assertSuccessful()
        ->assertSee('Horizon requires Redis. Currently unreachable.');
});
