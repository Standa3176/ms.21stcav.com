<?php

declare(strict_types=1);

use App\Domain\Products\Models\Product;
use App\Domain\Sync\Models\ProductSupplierBlock;
use App\Domain\Sync\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Quick task 261027-duz — supplier:block-offer operator CLI
|--------------------------------------------------------------------------
*/

it('adds a block, resolving product_id from the SKU', function (): void {
    $product = Product::factory()->create(['sku' => '1301222']);

    $this->artisan('supplier:block-offer', [
        'sku' => '1301222',
        'supplier' => 'MIDWICH',
        '--reason' => 'Midwich 1301222 is an unrelated £7.20 item',
    ])->assertExitCode(0);

    $blocks = ProductSupplierBlock::all();
    expect($blocks)->toHaveCount(1)
        ->and($blocks->first()->sku)->toBe('1301222')
        ->and($blocks->first()->supplier_id)->toBe('MIDWICH')
        ->and((int) $blocks->first()->product_id)->toBe((int) $product->id)
        ->and($blocks->first()->reason)->toBe('Midwich 1301222 is an unrelated £7.20 item')
        ->and($blocks->first()->created_by_user_id)->toBeNull();
});

it('is idempotent — re-running updates the reason, still one row', function (): void {
    $this->artisan('supplier:block-offer', ['sku' => '1301222', 'supplier' => 'MIDWICH', '--reason' => 'first'])->assertExitCode(0);
    $this->artisan('supplier:block-offer', ['sku' => '1301222', 'supplier' => 'MIDWICH', '--reason' => 'second'])->assertExitCode(0);

    expect(ProductSupplierBlock::count())->toBe(1)
        ->and(ProductSupplierBlock::first()->reason)->toBe('second');
});

it('fails without --reason when adding and writes nothing', function (): void {
    $this->artisan('supplier:block-offer', ['sku' => '1301222', 'supplier' => 'MIDWICH'])->assertExitCode(1);

    expect(ProductSupplierBlock::count())->toBe(0);
});

it('--remove deletes the pair', function (): void {
    ProductSupplierBlock::create(['sku' => '1301222', 'supplier_id' => 'MIDWICH', 'reason' => 'x']);
    ProductSupplierBlock::create(['sku' => '1301222', 'supplier_id' => 'NIMANS', 'reason' => 'y']);

    $this->artisan('supplier:block-offer', ['sku' => '1301222', 'supplier' => 'MIDWICH', '--remove' => true])->assertExitCode(0);

    expect(ProductSupplierBlock::count())->toBe(1)
        ->and(ProductSupplierBlock::first()->supplier_id)->toBe('NIMANS');
});

it('--remove on a non-existent pair warns and exits 0', function (): void {
    $this->artisan('supplier:block-offer', ['sku' => 'nope', 'supplier' => 'MIDWICH', '--remove' => true])
        ->expectsOutputToContain('No block')
        ->assertExitCode(0);
});

it('resolves a case-insensitive supplier name to its supplier_id', function (): void {
    Supplier::create(['supplier_id' => '12', 'name' => 'Midwich', 'is_active' => true]);

    $this->artisan('supplier:block-offer', ['sku' => 'cp05osswh815', 'supplier' => 'midwich', '--reason' => 'wrong item'])
        ->assertExitCode(0);

    expect(ProductSupplierBlock::first()->supplier_id)->toBe('12');
});

it('prefers an exact supplier_id match over a name', function (): void {
    Supplier::create(['supplier_id' => '12', 'name' => 'Midwich', 'is_active' => true]);

    $this->artisan('supplier:block-offer', ['sku' => 'x1', 'supplier' => '12', '--reason' => 'r'])->assertExitCode(0);

    expect(ProductSupplierBlock::first()->supplier_id)->toBe('12');
});

it('stores an unknown supplier raw with a warning', function (): void {
    $this->artisan('supplier:block-offer', ['sku' => 'x1', 'supplier' => 'FEED99', '--reason' => 'r'])
        ->expectsOutputToContain('not a known supplier')
        ->assertExitCode(0);

    expect(ProductSupplierBlock::first()->supplier_id)->toBe('FEED99');
});

it('normalises the SKU (83Z49AA#ABU → 83z49aa#abu)', function (): void {
    $this->artisan('supplier:block-offer', ['sku' => '83Z49AA#ABU', 'supplier' => 'TECHDATA', '--reason' => 'pending confirmation'])
        ->assertExitCode(0);

    expect(ProductSupplierBlock::first()->sku)->toBe('83z49aa#abu');
});
