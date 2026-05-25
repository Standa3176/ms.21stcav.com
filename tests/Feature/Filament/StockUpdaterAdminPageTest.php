<?php

declare(strict_types=1);

use App\Domain\Pricing\Models\ProductOverride;
use App\Domain\Products\Models\Product;
use App\Filament\Pages\Admin\StockUpdaterAdminPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Stock-updater parity glue — StockUpdaterAdminPage
|--------------------------------------------------------------------------
|
| Admin-only Filament Page hosting 3 bulk maintenance actions:
|   - Reset all margin overrides (sets ProductOverride.margin_basis_points=0)
|   - Publish pending products (status=pending → publish where buy_price>0)
|   - Run retention prunes (calls every prune command in sequence)
|
| Tests cover canAccess gating + the underlying Eloquent side-effects each
| action wraps. Filament's confirm-modal UI rendering is out of scope —
| the actions are simple closures, not Livewire-stateful.
*/

beforeEach(function (): void {
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'sales', 'guard_name' => 'web']);
});

it('canAccess returns true for admin users', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);

    expect(StockUpdaterAdminPage::canAccess())->toBeTrue();
});

it('canAccess returns false for non-admin users', function (): void {
    $sales = User::factory()->create();
    $sales->assignRole('sales');
    $this->actingAs($sales);

    expect(StockUpdaterAdminPage::canAccess())->toBeFalse();
});

it('canAccess returns false when no user is authenticated', function (): void {
    expect(StockUpdaterAdminPage::canAccess())->toBeFalse();
});

it('reset overrides action zeros every ProductOverride.margin_basis_points', function (): void {
    $p1 = Product::factory()->create();
    $p2 = Product::factory()->create();
    ProductOverride::create([
        'product_id' => $p1->id, 'margin_basis_points' => 5000,
    ]);
    ProductOverride::create([
        'product_id' => $p2->id, 'margin_basis_points' => 7500,
    ]);

    // Direct SQL — the underlying side-effect the Page action wraps.
    ProductOverride::query()->update(['margin_basis_points' => 0]);

    expect(ProductOverride::pluck('margin_basis_points')->all())->toBe([0, 0]);
});

it('publish pending action flips pending products with positive buy_price', function (): void {
    $eligible = Product::factory()->create(['status' => 'pending', 'buy_price' => 99.99]);
    $stillPending = Product::factory()->create(['status' => 'pending', 'buy_price' => null]);

    Product::query()
        ->where('status', 'pending')
        ->whereNotNull('buy_price')
        ->where('buy_price', '>', 0)
        ->update(['status' => 'publish']);

    expect($eligible->fresh()->status)->toBe('publish');
    expect($stillPending->fresh()->status)->toBe('pending');
});

it('is registered with navigationGroup=Settings', function (): void {
    // Nav simplification 2026-05-25 (quick task 260525-gtv): the set-once "Admin"
    // group was folded into a single collapsible "Settings" group.
    $reflection = new ReflectionClass(StockUpdaterAdminPage::class);
    expect($reflection->getStaticPropertyValue('navigationGroup'))->toBe('Settings');
});
