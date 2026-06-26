<?php

declare(strict_types=1);

use App\Domain\Products\Models\SupplierOfferSnapshot;
use App\Domain\Sync\Filament\Resources\SupplierResource;
use App\Domain\Sync\Filament\Resources\SupplierResource\Pages\ListSuppliers;
use App\Domain\Sync\Models\Supplier;
use App\Domain\Sync\Services\SupplierFreshnessResolver;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Quick task 260626-oqr — SupplierResource Filament feature test
|--------------------------------------------------------------------------
|
| Locks the RBAC matrix (admin + pricing_manager write; sales/read_only
| view-only), the ToggleColumn write path, the Excluded-only TernaryFilter,
| and that the page mounts with the freshness column resolving.
|
| A SupplierOfferSnapshot is seeded so SupplierFreshnessResolver::classify
| has data to read without error.
*/

function supplierResourceUser(string $role): User
{
    Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user->fresh();
}

function seedSupplierWithSnapshot(string $supplierId, bool $isActive): Supplier
{
    $supplier = Supplier::create([
        'supplier_id' => $supplierId,
        'name' => $supplierId.' Ltd',
        'is_active' => $isActive,
    ]);

    SupplierOfferSnapshot::create([
        'sku' => 'sku-'.strtolower($supplierId),
        'product_id' => null,
        'supplier_id' => $supplierId,
        'supplier_name' => $supplierId.' Ltd',
        'price' => 9.00,
        'stock' => 5,
        'rrp' => 12.00,
        'recorded_at' => today(),
    ]);

    return $supplier;
}

beforeEach(function (): void {
    app(SupplierFreshnessResolver::class)->forget();
});

it('admin can mount the list page and sees a seeded supplier', function (): void {
    $admin = supplierResourceUser('admin');
    seedSupplierWithSnapshot('WCOAST', true);

    $this->actingAs($admin);

    Livewire::test(ListSuppliers::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords(Supplier::all());
});

it('admin can toggle is_active via the ToggleColumn (DB flips)', function (): void {
    $admin = supplierResourceUser('admin');
    $supplier = seedSupplierWithSnapshot('NUVIAS', true);

    $this->actingAs($admin);

    // ToggleColumn enabled for admin → updating the cell persists.
    Livewire::test(ListSuppliers::class)
        ->call('updateTableColumnState', 'is_active', (string) $supplier->getKey(), false)
        ->assertSuccessful();

    expect($supplier->fresh()->is_active)->toBeFalse();
});

it('pricing_manager can mount and toggle', function (): void {
    $pm = supplierResourceUser('pricing_manager');
    $supplier = seedSupplierWithSnapshot('INGRAM', true);

    $this->actingAs($pm);

    Livewire::test(ListSuppliers::class)
        ->assertSuccessful()
        ->call('updateTableColumnState', 'is_active', (string) $supplier->getKey(), false);

    expect($supplier->fresh()->is_active)->toBeFalse();
});

it('read_only can mount but the ToggleColumn is disabled (write rejected)', function (): void {
    $readOnly = supplierResourceUser('read_only');
    $supplier = seedSupplierWithSnapshot('EXERTIS', true);

    $this->actingAs($readOnly);

    // Disabled ToggleColumn → updateTableColumnState short-circuits; DB unchanged.
    Livewire::test(ListSuppliers::class)
        ->assertSuccessful()
        ->call('updateTableColumnState', 'is_active', (string) $supplier->getKey(), false);

    expect($supplier->fresh()->is_active)->toBeTrue();
});

it('sales can mount but the ToggleColumn is disabled (write rejected)', function (): void {
    $sales = supplierResourceUser('sales');
    $supplier = seedSupplierWithSnapshot('TECHDATA', true);

    $this->actingAs($sales);

    Livewire::test(ListSuppliers::class)
        ->assertSuccessful()
        ->call('updateTableColumnState', 'is_active', (string) $supplier->getKey(), false);

    expect($supplier->fresh()->is_active)->toBeTrue();
});

it('Excluded-only filter shows inactive suppliers and hides active ones', function (): void {
    $admin = supplierResourceUser('admin');
    $active = seedSupplierWithSnapshot('ACTIVECO', true);
    $excluded = seedSupplierWithSnapshot('PAUSEDCO', false);

    $this->actingAs($admin);

    Livewire::test(ListSuppliers::class)
        ->filterTable('is_active', false)
        ->assertCanSeeTableRecords([$excluded])
        ->assertCanNotSeeTableRecords([$active]);
});

it('has no create action (suppliers are auto-discovered)', function (): void {
    expect(SupplierResource::canCreate())->toBeFalse();
});

// 260626-q2b — the Feed date column now renders the REAL feed_remote_date
// (feeds.remote_date mirrored locally), NOT the recorded_at MS-pull date. A
// disabled-upstream supplier (feed_status=0) shows the 'Feed off' status with
// its true (RED) file date. Carbon is pinned so the working-day colour is
// deterministic.
it('renders the real feed_remote_date and a Feed off status for a disabled feed', function (): void {
    Carbon::setTestNow('2026-06-26'); // Friday

    $admin = supplierResourceUser('admin');
    // Nuvias — true file date 14 May 2026, feed disabled upstream (status=0).
    Supplier::create([
        'supplier_id' => '1',
        'name' => 'Nuvias',
        'is_active' => true,
        'feed_remote_date' => '2026-05-14 00:20:24',
        'feed_status' => 0,
    ]);

    $this->actingAs($admin);

    Livewire::test(ListSuppliers::class)
        ->assertSuccessful()
        ->assertSee('Thu 14 May 2026') // 'D j M Y' of the real feed_remote_date
        ->assertSee('Feed off');       // truthful status (feed_status === 0)

    Carbon::setTestNow();
});

// 260626-q2b — a supplier whose feed refreshed TODAY (status=1) shows 'Fresh'.
it('renders a Fresh status for a supplier with a recent feed date', function (): void {
    Carbon::setTestNow('2026-06-26'); // Friday

    $admin = supplierResourceUser('admin');
    Supplier::create([
        'supplier_id' => '2',
        'name' => 'Ingram',
        'is_active' => true,
        'feed_remote_date' => '2026-06-26 06:00:00', // today → 0 working days
        'feed_status' => 1,
    ]);

    $this->actingAs($admin);

    Livewire::test(ListSuppliers::class)
        ->assertSuccessful()
        ->assertSee('Fri 26 Jun 2026')
        ->assertSee('Fresh');

    Carbon::setTestNow();
});
