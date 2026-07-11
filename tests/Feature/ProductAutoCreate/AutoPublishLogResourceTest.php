<?php

declare(strict_types=1);

use App\Domain\ProductAutoCreate\Filament\Resources\AutoPublishLogResource;
use App\Domain\ProductAutoCreate\Filament\Resources\AutoPublishLogResource\Pages\ListAutoPublishLog;
use App\Domain\ProductAutoCreate\Models\AutoPublishLogEntry;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| Quick task 260711-aps Task 3 — Auto-Publish Log viewer (read-only)
|--------------------------------------------------------------------------
| READ-ONLY Filament Resource over auto_publish_log under the 'Woo Maintenance'
| nav group. Renders for admins; shows seeded rows; no create/edit/delete; the
| competitor_count filter isolates the 2-vs-3 split.
*/

beforeEach(function (): void {
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
});

function adminUser(): User
{
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    return $admin;
}

it('renders the list page for an admin and shows a seeded row', function (): void {
    $row = AutoPublishLogEntry::factory()->create(['sku' => 'SHOW-ME']);

    $this->actingAs(adminUser());

    Livewire::test(ListAutoPublishLog::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$row]);
});

it('is read-only — canCreate returns false', function (): void {
    expect(AutoPublishLogResource::canCreate())->toBeFalse();
});

it('is registered under the Woo Maintenance navigation group', function (): void {
    expect(AutoPublishLogResource::getNavigationGroup())->toBe('Woo Maintenance');
});

it('exposes only an index page (no create/edit/delete routes)', function (): void {
    expect(array_keys(AutoPublishLogResource::getPages()))->toBe(['index']);
});

it('filters by competitor_count to isolate the 2-vs-3 split', function (): void {
    $two = AutoPublishLogEntry::factory()->competitors(2)->create(['sku' => 'TWO-COMP']);
    $three = AutoPublishLogEntry::factory()->competitors(3)->create(['sku' => 'THREE-COMP']);

    $this->actingAs(adminUser());

    Livewire::test(ListAutoPublishLog::class)
        ->filterTable('competitor_count', 2)
        ->assertCanSeeTableRecords([$two])
        ->assertCanNotSeeTableRecords([$three]);
});
