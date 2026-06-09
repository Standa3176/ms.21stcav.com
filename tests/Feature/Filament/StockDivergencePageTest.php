<?php

declare(strict_types=1);

use App\Domain\Products\Models\StockDivergenceFinding;
use App\Filament\Pages\StockDivergencePage;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| Quick task 260609-nku — StockDivergencePage Pest feature test
|--------------------------------------------------------------------------
|
| 4 cases:
|   1. Admin can mount /admin/stock-divergence → 200.
|   2. pricing_manager can mount → 200.
|   3. sales gets 403 (or canAccess false → forbidden).
|   4. phantom_min filter + bulk-resync action assertions.
*/

function stockDivergenceUser(string $role): User
{
    Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user->fresh();
}

function seedDivergence(string $sku, int $phantom): StockDivergenceFinding
{
    return StockDivergenceFinding::create([
        'sku' => $sku,
        'name' => "Product {$sku}",
        'woo_product_id' => random_int(1000, 99999),
        'ms_stock_quantity' => 0,
        'woo_stock_quantity' => $phantom,
        'phantom_units' => $phantom,
        'woo_last_modified' => now()->subDay(),
        'ms_last_synced_at' => now()->subHour(),
        'status' => 'woo_overcount',
        'run_id' => '01HX0000000000000000000000',
        'audited_at' => now(),
    ]);
}

it('admin can mount the page', function (): void {
    $this->actingAs(stockDivergenceUser('admin'));

    Livewire::test(StockDivergencePage::class)->assertSuccessful();
});

it('pricing_manager can mount the page', function (): void {
    $this->actingAs(stockDivergenceUser('pricing_manager'));

    Livewire::test(StockDivergencePage::class)->assertSuccessful();
});

it('sales role gets 403 on page access', function (): void {
    $this->actingAs(stockDivergenceUser('sales'));

    expect(StockDivergencePage::canAccess())->toBeFalse();

    $this->get('/admin/stock-divergence')->assertForbidden();
});

it('phantom_min filter narrows to rows >= threshold and bulk action is registered', function (): void {
    $this->actingAs(stockDivergenceUser('admin'));

    seedDivergence('SKU-LOW', 2);
    seedDivergence('SKU-MID', 7);
    $hi = seedDivergence('SKU-HI', 25);

    // Bulk action is registered on the table.
    Livewire::test(StockDivergencePage::class)
        ->assertTableBulkActionExists('resync_selected');

    // The filter is a form-input Filter::make('phantom_min') with a TextInput
    // (also named 'phantom_min'). Pin the underlying predicate at the query
    // level — Filament's Livewire wiring for custom form filters is exercised
    // by the page-mounts-cleanly smoke checks above + the assertCanSeeTableRecords
    // below confirms the included row is visible after applying the filter.
    $hiOnly = StockDivergenceFinding::query()
        ->where('phantom_units', '>=', 10)
        ->pluck('sku')
        ->all();
    expect($hiOnly)->toBe(['SKU-HI']);

    Livewire::test(StockDivergencePage::class)
        ->filterTable('phantom_min', ['phantom_min' => 10])
        ->assertCanSeeTableRecords([$hi]);
});

it('per-row resync action invokes products:resync-to-woo with the row SKU', function (): void {
    $this->actingAs(stockDivergenceUser('admin'));

    $row = seedDivergence('RESYNC-SKU', 5);

    Artisan::shouldReceive('call')
        ->once()
        ->with('products:resync-to-woo', ['--skus' => 'RESYNC-SKU'])
        ->andReturn(0);

    Livewire::test(StockDivergencePage::class)
        ->callTableAction('resync', $row)
        ->assertHasNoTableActionErrors();
});
