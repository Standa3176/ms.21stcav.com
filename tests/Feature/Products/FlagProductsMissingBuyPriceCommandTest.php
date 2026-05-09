<?php

declare(strict_types=1);

use App\Domain\Products\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Stock-updater parity glue — products:flag-missing-buy-price
|--------------------------------------------------------------------------
|
| Port of the legacy plugin's logProductChanges() / handle_pending_product()
| flow: any published product with NULL or zero buy_price gets demoted to
| status=pending so it falls out of the storefront. Skipped: custom-ms
| tagged products (priced by hand) and anything not currently 'publish'.
*/

it('registers products:flag-missing-buy-price as an artisan command', function (): void {
    expect(array_keys(Artisan::all()))->toContain('products:flag-missing-buy-price');
});

it('flips publish products with NULL buy_price to pending', function (): void {
    $product = Product::factory()->create(['status' => 'publish', 'buy_price' => null]);

    Artisan::call('products:flag-missing-buy-price');

    expect($product->fresh()->status)->toBe('pending');
});

it('flips publish products with zero buy_price to pending', function (): void {
    $product = Product::factory()->create(['status' => 'publish', 'buy_price' => 0]);

    Artisan::call('products:flag-missing-buy-price');

    expect($product->fresh()->status)->toBe('pending');
});

it('leaves publish products with a real buy_price alone', function (): void {
    $product = Product::factory()->create(['status' => 'publish', 'buy_price' => 99.99]);

    Artisan::call('products:flag-missing-buy-price');

    expect($product->fresh()->status)->toBe('publish');
});

it('skips products tagged custom-ms even when buy_price is missing', function (): void {
    $product = Product::factory()->create([
        'status' => 'publish',
        'buy_price' => null,
        'tags' => ['custom-ms', 'bespoke'],
    ]);

    Artisan::call('products:flag-missing-buy-price');

    expect($product->fresh()->status)->toBe('publish');
});

it('does not touch products in non-publish statuses (draft / private / pending)', function (): void {
    $draft = Product::factory()->create(['status' => 'draft', 'buy_price' => null]);
    $private = Product::factory()->create(['status' => 'private', 'buy_price' => null]);
    $alreadyPending = Product::factory()->create(['status' => 'pending', 'buy_price' => null]);

    Artisan::call('products:flag-missing-buy-price');

    expect($draft->fresh()->status)->toBe('draft');
    expect($private->fresh()->status)->toBe('private');
    expect($alreadyPending->fresh()->status)->toBe('pending');
});

it('--dry-run flips no rows', function (): void {
    $product = Product::factory()->create(['status' => 'publish', 'buy_price' => null]);

    $exit = Artisan::call('products:flag-missing-buy-price', ['--dry-run' => true]);

    expect($exit)->toBe(0);
    expect($product->fresh()->status)->toBe('publish');
});
