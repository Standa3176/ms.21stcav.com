<?php

declare(strict_types=1);

use App\Domain\Products\Events\ProductFieldsChangedEvent;
use App\Domain\Products\Models\Product;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| Quick task 260611-s2d — ProductObserver
|--------------------------------------------------------------------------
|
| 4 Pest cases A-D cover:
|   A — flag=false: no event on tracked-field change.
|   B — flag=true:  event dispatched once on stock_quantity change.
|   C — flag=true:  multi-field change (stock_quantity + buy_price) emits
|                   ONE event with both fields in changedFields[] (order-agnostic).
|   D — flag=true:  non-tracked field change (name) emits NO event.
|
| The observer is registered always (AppServiceProvider::boot) but gated on
| config('cutover.event_driven_push_enabled'). Tests flip the flag per case
| via config([...]) so cached-config doesn't bite.
*/

it('Case A: flag=false — stock_quantity change does NOT dispatch event', function (): void {
    config(['cutover.event_driven_push_enabled' => false]);

    Event::fake([ProductFieldsChangedEvent::class]);

    $product = Product::factory()->create([
        'sku' => 'A-OBS',
        'stock_quantity' => 0,
    ]);

    $product->stock_quantity = 42;
    $product->save();

    Event::assertNotDispatched(ProductFieldsChangedEvent::class);
});

it('Case B: flag=true — stock_quantity change dispatches one event with changedFields=[stock_quantity]', function (): void {
    config(['cutover.event_driven_push_enabled' => true]);

    Event::fake([ProductFieldsChangedEvent::class]);

    $product = Product::factory()->create([
        'sku' => 'B-OBS',
        'stock_quantity' => 0,
    ]);

    $product->stock_quantity = 17;
    $product->save();

    Event::assertDispatched(
        ProductFieldsChangedEvent::class,
        function (ProductFieldsChangedEvent $event) use ($product): bool {
            return $event->productId === $product->id
                && $event->sku === 'B-OBS'
                && $event->changedFields === ['stock_quantity'];
        },
    );
    Event::assertDispatchedTimes(ProductFieldsChangedEvent::class, 1);
});

it('Case C: flag=true — stock_quantity AND buy_price change in same save emits ONE event with BOTH fields', function (): void {
    config(['cutover.event_driven_push_enabled' => true]);

    Event::fake([ProductFieldsChangedEvent::class]);

    $product = Product::factory()->create([
        'sku' => 'C-OBS',
        'stock_quantity' => 0,
        'buy_price' => 10.00,
    ]);

    $product->stock_quantity = 100;
    $product->buy_price = 87.50;
    $product->save();

    Event::assertDispatchedTimes(ProductFieldsChangedEvent::class, 1);
    Event::assertDispatched(
        ProductFieldsChangedEvent::class,
        function (ProductFieldsChangedEvent $event): bool {
            // Order-agnostic — must contain BOTH names. Copy to a local
            // before sort() — $changedFields is readonly on the event.
            $fields = $event->changedFields;
            sort($fields);

            return $fields === ['buy_price', 'stock_quantity'];
        },
    );
});

it('Case D: flag=true — non-tracked field (name) change does NOT dispatch event', function (): void {
    config(['cutover.event_driven_push_enabled' => true]);

    Event::fake([ProductFieldsChangedEvent::class]);

    $product = Product::factory()->create([
        'sku' => 'D-OBS',
        'name' => 'Original name',
    ]);

    $product->name = 'Updated name (non-tracked field)';
    $product->save();

    Event::assertNotDispatched(ProductFieldsChangedEvent::class);
});
