<?php

declare(strict_types=1);

use App\Filament\Pages\Horizon\HorizonBatchesPage;
use App\Models\User;
use Illuminate\Support\Facades\Redis;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

function horizonBatchesRoleUser(string $roleName): User
{
    Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole($roleName);

    return $user->fresh();
}

it('admin can access HorizonBatchesPage', function (): void {
    $admin = horizonBatchesRoleUser('admin');
    $this->actingAs($admin);

    expect(HorizonBatchesPage::canAccess())->toBeTrue();
});

it('pricing_manager is denied access', function (): void {
    $user = horizonBatchesRoleUser('pricing_manager');
    $this->actingAs($user);

    expect(HorizonBatchesPage::canAccess())->toBeFalse();
});

it('sales is denied access', function (): void {
    $user = horizonBatchesRoleUser('sales');
    $this->actingAs($user);

    expect(HorizonBatchesPage::canAccess())->toBeFalse();
});

it('read_only is denied access', function (): void {
    $user = horizonBatchesRoleUser('read_only');
    $this->actingAs($user);

    expect(HorizonBatchesPage::canAccess())->toBeFalse();
});

it('renders the Redis warning banner when Redis is unreachable', function (): void {
    $admin = horizonBatchesRoleUser('admin');
    $this->actingAs($admin);

    Redis::shouldReceive('connection')
        ->andThrow(new \RuntimeException('Connection refused [tcp://127.0.0.1:6379]'));

    Livewire::test(HorizonBatchesPage::class)
        ->assertSuccessful()
        ->assertSee('Horizon requires Redis. Currently unreachable.');
});
