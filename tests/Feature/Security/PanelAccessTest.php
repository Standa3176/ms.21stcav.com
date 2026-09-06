<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Quick task 260906-hbl — panel access requires a role
|--------------------------------------------------------------------------
|
| canAccessPanel() returned a bare `true`, so the per-resource policies were
| the ONLY thing between a role-less account and the data. One Resource
| shipped without a policy and it was exposed to every authenticated user.
| This is the defence-in-depth layer that was missing.
*/

it('denies the admin panel to an authenticated user with no roles', function (): void {
    $user = User::factory()->create();

    expect($user->fresh()->canAccessPanel(Filament\Facades\Filament::getPanel('admin')))->toBeFalse();
});

it('allows the panel to a user holding a role', function (string $role): void {
    Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole($role);

    expect($user->fresh()->canAccessPanel(Filament\Facades\Filament::getPanel('admin')))->toBeTrue();
})->with(['admin', 'pricing_manager', 'sales', 'read_only']);

it('redirects a role-less user away from the panel rather than rendering it', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/admin')->assertStatus(403);
});
