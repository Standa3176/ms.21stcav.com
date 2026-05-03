<?php

declare(strict_types=1);

use App\Filament\Pages\Horizon\HorizonFailedJobsPage;
use App\Models\User;
use Illuminate\Support\Facades\Redis;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

function horizonFailedRoleUser(string $roleName): User
{
    Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole($roleName);

    return $user->fresh();
}

it('admin can access HorizonFailedJobsPage', function (): void {
    $admin = horizonFailedRoleUser('admin');
    $this->actingAs($admin);

    expect(HorizonFailedJobsPage::canAccess())->toBeTrue();
});

it('pricing_manager is denied access', function (): void {
    $user = horizonFailedRoleUser('pricing_manager');
    $this->actingAs($user);

    expect(HorizonFailedJobsPage::canAccess())->toBeFalse();
});

it('sales is denied access', function (): void {
    $user = horizonFailedRoleUser('sales');
    $this->actingAs($user);

    expect(HorizonFailedJobsPage::canAccess())->toBeFalse();
});

it('read_only is denied access', function (): void {
    $user = horizonFailedRoleUser('read_only');
    $this->actingAs($user);

    expect(HorizonFailedJobsPage::canAccess())->toBeFalse();
});

it('renders the Redis warning banner when Redis is unreachable', function (): void {
    $admin = horizonFailedRoleUser('admin');
    $this->actingAs($admin);

    Redis::shouldReceive('connection')
        ->andThrow(new \RuntimeException('Connection refused [tcp://127.0.0.1:6379]'));

    Livewire::test(HorizonFailedJobsPage::class)
        ->assertSuccessful()
        ->assertSee('Horizon requires Redis. Currently unreachable.');
});

it('non-admin retry attempts are forbidden', function (): void {
    $sales = horizonFailedRoleUser('sales');
    $this->actingAs($sales);

    expect(fn () => (new HorizonFailedJobsPage())->retry('any-id'))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

it('non-admin deleteFailed attempts are forbidden', function (): void {
    $sales = horizonFailedRoleUser('sales');
    $this->actingAs($sales);

    expect(fn () => (new HorizonFailedJobsPage())->deleteFailed('any-id'))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});
