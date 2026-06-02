<?php

declare(strict_types=1);

use App\Domain\Products\Models\Product;
use App\Domain\Products\Models\ProductException;
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

/*
|--------------------------------------------------------------------------
| product_exceptions allowlist (2026-06-02)
|--------------------------------------------------------------------------
|
| Operator-managed structured allowlist in addition to the custom-ms tag.
| Active rows preserve publish; paused rows are ignored (sync demotes
| normally so operator can opt out without deleting the row).
*/

it('skips publish products when an ACTIVE product_exception row exists for the SKU', function (): void {
    $product = Product::factory()->create([
        'status' => 'publish',
        'sku' => 'CUSTOM-IN-HOUSE-1',
        'buy_price' => null,
    ]);
    ProductException::factory()->create([
        'sku' => 'CUSTOM-IN-HOUSE-1',
        'reason' => 'In-house assembly',
        'is_paused' => false,
    ]);

    Artisan::call('products:flag-missing-buy-price');

    expect($product->fresh()->status)->toBe('publish');
});

it('DOES demote when the exception row is PAUSED (paused = sync follows normal rules)', function (): void {
    $product = Product::factory()->create([
        'status' => 'publish',
        'sku' => 'DRAFT-EXCEPTION-1',
        'buy_price' => null,
    ]);
    ProductException::factory()->paused()->create([
        'sku' => 'DRAFT-EXCEPTION-1',
    ]);

    Artisan::call('products:flag-missing-buy-price');

    expect($product->fresh()->status)->toBe('pending');
});

it('demotes a SKU with no matching exception even when other exceptions exist', function (): void {
    $protected = Product::factory()->create([
        'status' => 'publish',
        'sku' => 'PROTECTED-1',
        'buy_price' => null,
    ]);
    $unprotected = Product::factory()->create([
        'status' => 'publish',
        'sku' => 'UNPROTECTED-1',
        'buy_price' => null,
    ]);
    ProductException::factory()->create(['sku' => 'PROTECTED-1']);

    Artisan::call('products:flag-missing-buy-price');

    expect($protected->fresh()->status)->toBe('publish');
    expect($unprotected->fresh()->status)->toBe('pending');
});

it('SKU match is whitespace-insensitive (operator may paste with stray spaces)', function (): void {
    $product = Product::factory()->create([
        'status' => 'publish',
        'sku' => 'WS-MATCH-1',
        'buy_price' => null,
    ]);
    // Stored with trailing whitespace in the exception
    ProductException::factory()->create(['sku' => '  WS-MATCH-1  ']);

    Artisan::call('products:flag-missing-buy-price');

    expect($product->fresh()->status)->toBe('publish');
});
