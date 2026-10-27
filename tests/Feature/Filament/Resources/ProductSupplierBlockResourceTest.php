<?php

declare(strict_types=1);

use App\Domain\Sync\Filament\Resources\ProductSupplierBlockResource\Pages\ManageProductSupplierBlocks;
use App\Domain\Sync\Models\ProductSupplierBlock;
use App\Domain\Sync\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Quick task 261027-duz — ProductSupplierBlockResource (Filament)
|--------------------------------------------------------------------------
|
| RBAC mirrors SupplierResource: admin + pricing_manager create/delete;
| sales + read_only can view but not write.
*/

function supplierBlockUser(string $role): User
{
    Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user->fresh();
}

beforeEach(function (): void {
    Supplier::create(['supplier_id' => '12', 'name' => 'Midwich', 'is_active' => true]);
});

foreach (['admin', 'pricing_manager'] as $writerRole) {
    it("{$writerRole} can mount, create and delete a block", function () use ($writerRole): void {
        $user = supplierBlockUser($writerRole);
        $this->actingAs($user);

        Livewire::test(ManageProductSupplierBlocks::class)
            ->assertSuccessful()
            ->callAction('create', data: [
                'sku' => ' 1301222 ',
                'supplier_id' => '12',
                'reason' => 'Midwich 1301222 is an unrelated item',
            ])
            ->assertHasNoActionErrors();

        $block = ProductSupplierBlock::first();
        expect($block)->not->toBeNull()
            ->and($block->sku)->toBe('1301222')
            ->and($block->supplier_id)->toBe('12')
            ->and((int) $block->created_by_user_id)->toBe((int) $user->id);

        Livewire::test(ManageProductSupplierBlocks::class)
            ->assertCanSeeTableRecords([$block])
            ->callTableAction('delete', $block);

        expect(ProductSupplierBlock::count())->toBe(0);
    });
}

it('requires a reason', function (): void {
    $this->actingAs(supplierBlockUser('admin'));

    Livewire::test(ManageProductSupplierBlocks::class)
        ->callAction('create', data: ['sku' => '1301222', 'supplier_id' => '12', 'reason' => ''])
        ->assertHasActionErrors(['reason' => 'required']);

    expect(ProductSupplierBlock::count())->toBe(0);
});

foreach (['sales', 'read_only'] as $readerRole) {
    it("{$readerRole} can mount but cannot create or delete", function () use ($readerRole): void {
        $block = ProductSupplierBlock::create(['sku' => '1301222', 'supplier_id' => '12', 'reason' => 'x']);
        $this->actingAs(supplierBlockUser($readerRole));

        Livewire::test(ManageProductSupplierBlocks::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$block])
            ->assertActionHidden('create')
            ->assertTableActionHidden('delete', $block);

        expect(ProductSupplierBlock::count())->toBe(1);
    });
}

it('rejects a duplicate (sku, supplier) block with a validation error, not a 500', function (): void {
    ProductSupplierBlock::create(['sku' => '1301222', 'supplier_id' => '12', 'reason' => 'x']);
    $this->actingAs(supplierBlockUser('admin'));

    Livewire::test(ManageProductSupplierBlocks::class)
        ->callAction('create', data: ['sku' => '1301222 ', 'supplier_id' => '12', 'reason' => 'again'])
        ->assertHasActionErrors(['sku']);

    expect(ProductSupplierBlock::count())->toBe(1);
});
