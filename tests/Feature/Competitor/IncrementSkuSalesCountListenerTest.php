<?php

declare(strict_types=1);

use App\Domain\Competitor\Listeners\IncrementSkuSalesCount;
use App\Domain\Products\Models\Product;
use App\Domain\Webhooks\Events\OrderReceived;
use App\Domain\Webhooks\Models\WebhookReceipt;
use App\Providers\EventServiceProvider;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 5 Plan 03 Task 1 — IncrementSkuSalesCount listener (W1 semantics)
|--------------------------------------------------------------------------
|
| W1 FROZEN semantics: 1 increment per line-item per order, NOT multiplied
| by quantity. A line item with quantity=3 of SKU-1 counts as 1 (not 3).
| Two line items both containing SKU-1 count as 2 (degenerate Woo shape
| but semantically correct per the frozen semantics).
|
| RecacheSalesCountsJob (Task 3) MUST use identical aggregation to prevent
| drift — W1 is the ONE-per-line-item invariant both paths share.
*/

/**
 * Helper: build a WebhookReceipt with Woo-shaped raw_body.line_items
 */
function makeReceiptWithLineItems(array $lineItems): WebhookReceipt
{
    $payload = ['line_items' => $lineItems];

    return WebhookReceipt::create([
        'source' => 'woo',
        'topic' => 'order.created',
        'delivery_id' => 'test-'.uniqid(),
        'headers' => ['x-wc-webhook-topic' => 'order.created'],
        'raw_body' => json_encode($payload),
        'correlation_id' => 'test-corr-'.uniqid(),
        'received_at' => now(),
        'status' => 'received',
    ]);
}

it('increments last_sales_count_90d by 1 per line-item (NOT multiplied by quantity)', function (): void {
    Product::factory()->create(['sku' => 'SKU-1', 'last_sales_count_90d' => 0]);
    Product::factory()->create(['sku' => 'SKU-2', 'last_sales_count_90d' => 0]);

    $receipt = makeReceiptWithLineItems([
        ['sku' => 'SKU-1', 'quantity' => 3], // quantity=3 counts as 1
        ['sku' => 'SKU-2', 'quantity' => 1],
    ]);

    $event = new OrderReceived(webhookReceiptId: $receipt->id, deliveryId: $receipt->delivery_id);
    (new IncrementSkuSalesCount())->handle($event);

    expect(Product::where('sku', 'SKU-1')->value('last_sales_count_90d'))->toBe(1);
    expect(Product::where('sku', 'SKU-2')->value('last_sales_count_90d'))->toBe(1);
});

it('counts SAME SKU appearing in TWO line items as 2 increments (W1 per-line-item rule)', function (): void {
    Product::factory()->create(['sku' => 'DUP-SKU', 'last_sales_count_90d' => 0]);

    $receipt = makeReceiptWithLineItems([
        ['sku' => 'DUP-SKU', 'quantity' => 1],
        ['sku' => 'DUP-SKU', 'quantity' => 7], // degenerate Woo shape — counts as +1
    ]);

    $event = new OrderReceived(webhookReceiptId: $receipt->id, deliveryId: $receipt->delivery_id);
    (new IncrementSkuSalesCount())->handle($event);

    expect(Product::where('sku', 'DUP-SKU')->value('last_sales_count_90d'))->toBe(2);
});

it('ignores line items with null or empty sku', function (): void {
    Product::factory()->create(['sku' => 'GOOD-SKU', 'last_sales_count_90d' => 0]);

    $receipt = makeReceiptWithLineItems([
        ['sku' => 'GOOD-SKU', 'quantity' => 1],
        ['sku' => null, 'quantity' => 1],
        ['sku' => '', 'quantity' => 1],
        ['quantity' => 1], // sku key missing entirely
    ]);

    $event = new OrderReceived(webhookReceiptId: $receipt->id, deliveryId: $receipt->delivery_id);
    (new IncrementSkuSalesCount())->handle($event);

    expect(Product::where('sku', 'GOOD-SKU')->value('last_sales_count_90d'))->toBe(1);
});

it('silently no-ops when SKU is not in products table (no row to update)', function (): void {
    $receipt = makeReceiptWithLineItems([
        ['sku' => 'GHOST-SKU', 'quantity' => 1],
    ]);

    $event = new OrderReceived(webhookReceiptId: $receipt->id, deliveryId: $receipt->delivery_id);

    // Should not throw; no product row to update
    (new IncrementSkuSalesCount())->handle($event);

    expect(Product::where('sku', 'GHOST-SKU')->exists())->toBeFalse();
});

it('no-ops gracefully when raw_body has no line_items key', function (): void {
    Product::factory()->create(['sku' => 'SKU-A', 'last_sales_count_90d' => 5]);

    $receipt = WebhookReceipt::create([
        'source' => 'woo',
        'topic' => 'order.created',
        'delivery_id' => 'no-items-'.uniqid(),
        'headers' => [],
        'raw_body' => json_encode(['some_other_key' => 'value']),
        'correlation_id' => 'test-corr',
        'received_at' => now(),
        'status' => 'received',
    ]);

    $event = new OrderReceived(webhookReceiptId: $receipt->id, deliveryId: $receipt->delivery_id);
    (new IncrementSkuSalesCount())->handle($event);

    expect(Product::where('sku', 'SKU-A')->value('last_sales_count_90d'))->toBe(5);
});

it('no-ops when WebhookReceipt does not exist (defensive findOrFail guard)', function (): void {
    $event = new OrderReceived(webhookReceiptId: 99999, deliveryId: 'nonexistent');

    // Should not throw — listener swallows missing receipt silently
    (new IncrementSkuSalesCount())->handle($event);

    expect(true)->toBeTrue();
});

it('implements ShouldQueue and routes to default queue', function (): void {
    $listener = new IncrementSkuSalesCount();

    expect($listener)->toBeInstanceOf(ShouldQueue::class);
    expect($listener->queue)->toBe('default');
});

it('is registered in EventServiceProvider::$listen for OrderReceived', function (): void {
    $provider = new EventServiceProvider(app());
    $reflection = new ReflectionClass($provider);
    $listenProperty = $reflection->getProperty('listen');
    $listenProperty->setAccessible(true);
    $listen = $listenProperty->getValue($provider);

    expect($listen)->toHaveKey(OrderReceived::class);
    expect($listen[OrderReceived::class])->toContain(IncrementSkuSalesCount::class);
});
