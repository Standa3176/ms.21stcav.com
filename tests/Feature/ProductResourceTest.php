<?php

declare(strict_types=1);

use App\Domain\Products\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Domain\Products\Filament\Resources\ProductResource\RelationManagers\VariantsRelationManager;
use App\Domain\Products\Models\Product;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    foreach (['admin', 'pricing_manager', 'sales', 'read_only'] as $role) {
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    }
});

it('all four roles can reach the product list (viewAny gates)', function (): void {
    foreach (['admin', 'pricing_manager', 'sales', 'read_only'] as $roleName) {
        $user = User::factory()->create();
        $user->assignRole($roleName);
        $this->actingAs($user);

        Livewire::test(ListProducts::class)->assertSuccessful();
    }
});

it('filter by is_custom_ms returns only the custom-MS products', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);

    $custom = Product::factory()->create(['is_custom_ms' => true]);
    $normal = Product::factory()->create(['is_custom_ms' => false]);

    Livewire::test(ListProducts::class)
        ->filterTable('is_custom_ms', true)
        ->assertCanSeeTableRecords([$custom])
        ->assertCanNotSeeTableRecords([$normal]);
});

it('search by SKU returns the matching product', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);

    $match = Product::factory()->create(['sku' => 'MATCH-SKU-123']);
    $miss = Product::factory()->create(['sku' => 'OTHER-SKU-456']);

    Livewire::test(ListProducts::class)
        ->searchTable('MATCH-SKU-123')
        ->assertCanSeeTableRecords([$match])
        ->assertCanNotSeeTableRecords([$miss]);
});

it('VariantsRelationManager is only visible for variable products (D-01)', function (): void {
    $variable = Product::factory()->variable()->create();
    $simple = Product::factory()->create(['type' => 'simple']);

    expect(VariantsRelationManager::canViewForRecord($variable, 'ViewProduct'))->toBeTrue();
    expect(VariantsRelationManager::canViewForRecord($simple, 'ViewProduct'))->toBeFalse();
});
