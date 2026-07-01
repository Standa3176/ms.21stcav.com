<?php

declare(strict_types=1);

use App\Domain\ProductAutoCreate\Concerns\BuildsWooStockPayload;
use App\Domain\Products\Models\Product;

/*
|--------------------------------------------------------------------------
| Quick task 260701-opg — BuildsWooStockPayload trait (unit)
|--------------------------------------------------------------------------
|
| Pure derivation of the WooCommerce stock keys (manage_stock / stock_quantity
| / stock_status) from a local Product. App-created products currently publish
| WITHOUT stock management, so the storefront shows no "In stock (N)" line the
| way legacy products do. This trait is the single source for that payload,
| merged into both PublishProductJob publish paths.
|
| Exercised via an anonymous class that USES the trait and re-exposes the
| protected wooStockPayload() as public. Products are unsaved (new Product([..]))
| — no DB needed; stock_quantity/stock_status are fillable + cast on the model.
*/

/** Anonymous host that exposes the protected trait method publicly. */
function stockPayloadHost(): object
{
    return new class
    {
        use BuildsWooStockPayload;

        /** @return array{manage_stock:bool, stock_quantity:int, stock_status:string} */
        public function build(Product $product): array
        {
            return $this->wooStockPayload($product);
        }
    };
}

it('qty 5, null status → instock', function (): void {
    $payload = stockPayloadHost()->build(
        new Product(['stock_quantity' => 5, 'stock_status' => null])
    );

    expect($payload)->toBe([
        'manage_stock' => true,
        'stock_quantity' => 5,
        'stock_status' => 'instock',
    ]);
});

it('qty 0 → outofstock', function (): void {
    $payload = stockPayloadHost()->build(
        new Product(['stock_quantity' => 0])
    );

    expect($payload)->toBe([
        'manage_stock' => true,
        'stock_quantity' => 0,
        'stock_status' => 'outofstock',
    ]);
});

it('qty null → 0 + outofstock', function (): void {
    $payload = stockPayloadHost()->build(
        new Product(['stock_quantity' => null])
    );

    expect($payload)->toBe([
        'manage_stock' => true,
        'stock_quantity' => 0,
        'stock_status' => 'outofstock',
    ]);
});

it('qty -3 (defensive) → 0 + outofstock', function (): void {
    $payload = stockPayloadHost()->build(
        new Product(['stock_quantity' => -3])
    );

    expect($payload)->toBe([
        'manage_stock' => true,
        'stock_quantity' => 0,
        'stock_status' => 'outofstock',
    ]);
});

it('qty 0 + valid status onbackorder → preserved', function (): void {
    $payload = stockPayloadHost()->build(
        new Product(['stock_quantity' => 0, 'stock_status' => 'onbackorder'])
    );

    expect($payload)->toBe([
        'manage_stock' => true,
        'stock_quantity' => 0,
        'stock_status' => 'onbackorder',
    ]);
});

it('qty 5 + invalid status garbage → derived instock', function (): void {
    $payload = stockPayloadHost()->build(
        new Product(['stock_quantity' => 5, 'stock_status' => 'garbage'])
    );

    expect($payload)->toBe([
        'manage_stock' => true,
        'stock_quantity' => 5,
        'stock_status' => 'instock',
    ]);
});

it('qty 0 + local status instock → outofstock (qty wins, no oversell)', function (): void {
    $payload = stockPayloadHost()->build(
        new Product(['stock_quantity' => 0, 'stock_status' => 'instock'])
    );

    expect($payload)->toBe([
        'manage_stock' => true,
        'stock_quantity' => 0,
        'stock_status' => 'outofstock',
    ]);
});

it('qty 5 + local status outofstock → instock (qty wins both directions)', function (): void {
    $payload = stockPayloadHost()->build(
        new Product(['stock_quantity' => 5, 'stock_status' => 'outofstock'])
    );

    expect($payload)->toBe([
        'manage_stock' => true,
        'stock_quantity' => 5,
        'stock_status' => 'instock',
    ]);
});

it('qty 5 + onbackorder → preserved even with stock', function (): void {
    $payload = stockPayloadHost()->build(
        new Product(['stock_quantity' => 5, 'stock_status' => 'onbackorder'])
    );

    expect($payload)->toBe([
        'manage_stock' => true,
        'stock_quantity' => 5,
        'stock_status' => 'onbackorder',
    ]);
});
