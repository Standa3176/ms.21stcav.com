<?php

declare(strict_types=1);

use App\Models\User;
use App\Providers\Filament\AdminPanelProvider;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Spatie\Permission\Traits\HasRoles;

it('registers FilamentShieldPlugin on the admin panel', function () {
    // Build the Filament panel through the real provider, collect its plugins.
    $provider = new AdminPanelProvider(app());
    $panel = $provider->panel(\Filament\Panel::make());
    $plugins = $panel->getPlugins();

    expect(collect($plugins)->contains(fn ($plugin) => $plugin instanceof FilamentShieldPlugin))
        ->toBeTrue();
});

it('User model uses HasRoles trait', function () {
    expect(in_array(HasRoles::class, class_uses_recursive(User::class), true))->toBeTrue();
});

it('permission tables exist on the connection', function () {
    expect(\Schema::hasTable('roles'))->toBeTrue()
        ->and(\Schema::hasTable('permissions'))->toBeTrue()
        ->and(\Schema::hasTable('model_has_roles'))->toBeTrue()
        ->and(\Schema::hasTable('role_has_permissions'))->toBeTrue();
});
