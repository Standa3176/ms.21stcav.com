<?php

declare(strict_types=1);

use App\Domain\Pricing\Services\OrderLineCostLookup;
use App\Domain\Products\Models\Product;
use App\Domain\Products\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

it('prefers variant buy_price, falls back to product, and nulls unknown/unusable', function (): void {
    $parent = Product::factory()->variable()->create(['sku' => 'PARENT']);
    ProductVariant::factory()->create(['product_id' => $parent->id, 'sku' => 'V1', 'buy_price' => '10.00']);
    Product::factory()->create(['sku' => 'V1', 'buy_price' => '99.00']);
    Product::factory()->create(['sku' => 'P1', 'buy_price' => '25.5']);
    Product::factory()->create(['sku' => 'PNULL', 'buy_price' => null]);
    Product::factory()->create(['sku' => 'PZERO', 'buy_price' => '0']);

    $out = (new OrderLineCostLookup)->buyPricesForSkus(['V1', 'P1', 'PNULL', 'PZERO', 'NOPE', '']);

    expect($out['V1'])->toBe('10.0000')
        ->and($out['P1'])->toBe('25.5000')
        ->and($out['PNULL'])->toBeNull()
        ->and($out['PZERO'])->toBeNull()
        ->and($out['NOPE'])->toBeNull()
        ->and($out)->not->toHaveKey('');
});

it('uses two queries regardless of sku count', function (): void {
    Product::factory()->create(['sku' => 'A1', 'buy_price' => '1']);
    Product::factory()->create(['sku' => 'A2', 'buy_price' => '2']);
    Product::factory()->create(['sku' => 'A3', 'buy_price' => '3']);

    DB::enableQueryLog();
    (new OrderLineCostLookup)->buyPricesForSkus(['A1', 'A2', 'A3', 'X']);
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($count)->toBe(2);
});
