<?php

declare(strict_types=1);

use App\Domain\ProductAutoCreate\Listeners\PushProductFieldsToWoo;
use App\Domain\Products\Events\ProductFieldsChangedEvent;
use App\Domain\Products\Models\Product;
use App\Domain\Sync\Services\WooProductWriter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Spatie\Activitylog\Models\Activity;

/*
|--------------------------------------------------------------------------
| Quick task 260611-s2d — PushProductFieldsToWoo listener
|--------------------------------------------------------------------------
|
| 6 Pest cases E-J cover:
|   E — structural: ShouldQueue + $queue='sync-woo-push' + $tries=4.
|   F — handle() invokes writer with the event's Product, changedFields, correlationId.
|   G — status='pushed' → Auditor writes 'events.product_pushed' activity with result='pushed'.
|   H — status='woo_not_found' → no throw, activity written with result='woo_not_found'.
|   I — status='error' → throws RuntimeException with the reason embedded.
|   J — Product::find returns null (deleted between dispatch + handle) → writer NEVER called.
|
| Stub pattern (mirrors 260611-g4q PushDivergenceToWooCommandTest):
|   - Anonymous-subclass WooProductWriter with public $calls = [].
|   - Auditor is final → cannot be mocked. Use Spatie's activity log as the
|     observable side effect (Auditor::record('events.product_pushed', $ctx)
|     writes an Activity row we can query). Mirrors AutoSyncDivergenceCommandTest
|     + MarginChangeApplierTest patterns.
*/

it('Case E: implements ShouldQueue with $queue=woo-writes and $tries=4', function (): void {
    $ref = new ReflectionClass(PushProductFieldsToWoo::class);

    expect($ref->implementsInterface(ShouldQueue::class))->toBeTrue();

    // 260719-wth — moved from sync-woo-push to the dedicated single-worker write queue.
    $defaults = $ref->getDefaultProperties();
    expect($defaults['queue'] ?? null)->toBe('woo-writes');
    expect($defaults['tries'] ?? null)->toBe(4);
});

it('Case F: handle() calls writer->putProductFields with the Product, changedFields, and correlationId', function (): void {
    $product = Product::factory()->create([
        'sku' => 'F-LST',
        'woo_product_id' => 9201,
        'stock_quantity' => 42,
    ]);

    $writer = bindStubWriter(['status' => 'pushed', 'fields_pushed' => ['stock_quantity'], 'http_status' => 200, 'reason' => null]);

    $event = new ProductFieldsChangedEvent(
        productId: $product->id,
        sku: 'F-LST',
        changedFields: ['stock_quantity'],
    );

    app(PushProductFieldsToWoo::class)->handle($event);

    expect($writer->calls)->toHaveCount(1);
    expect($writer->calls[0]['product_id'])->toBe($product->id);
    expect($writer->calls[0]['fields'])->toBe(['stock_quantity']);
    expect($writer->calls[0]['correlation_id'])->toBe($event->correlationId);
});

it('Case G: status=pushed → activity log records events.product_pushed with result=pushed', function (): void {
    Activity::query()->delete();

    $product = Product::factory()->create([
        'sku' => 'G-LST',
        'woo_product_id' => 9202,
        'buy_price' => 50.00,
    ]);

    bindStubWriter([
        'status' => 'pushed',
        'fields_pushed' => ['buy_price'],
        'http_status' => 200,
        'reason' => null,
    ]);

    $event = new ProductFieldsChangedEvent(
        productId: $product->id,
        sku: 'G-LST',
        changedFields: ['buy_price'],
    );

    app(PushProductFieldsToWoo::class)->handle($event);

    $entry = Activity::query()
        ->where('log_name', 'system')
        ->where('description', 'events.product_pushed')
        ->first();

    expect($entry)->not->toBeNull();
    expect($entry->properties['result'] ?? null)->toBe('pushed');
    expect($entry->properties['fields_pushed'] ?? null)->toBe(['buy_price']);
    expect($entry->properties['product_id'] ?? null)->toBe($product->id);
    expect($entry->properties['sku'] ?? null)->toBe('G-LST');
});

it('Case H: status=woo_not_found → no throw, activity logged with result=woo_not_found', function (): void {
    Activity::query()->delete();

    $product = Product::factory()->create([
        'sku' => 'H-LST',
        'woo_product_id' => 9203,
    ]);

    bindStubWriter([
        'status' => 'woo_not_found',
        'fields_pushed' => [],
        'http_status' => 404,
        'reason' => null,
    ]);

    $event = new ProductFieldsChangedEvent(
        productId: $product->id,
        sku: 'H-LST',
        changedFields: ['stock_quantity'],
    );

    // Must not throw.
    app(PushProductFieldsToWoo::class)->handle($event);

    $entry = Activity::query()
        ->where('log_name', 'system')
        ->where('description', 'events.product_pushed')
        ->first();

    expect($entry)->not->toBeNull();
    expect($entry->properties['result'] ?? null)->toBe('woo_not_found');
});

it('Case I: status=error → throws RuntimeException with the reason embedded', function (): void {
    $product = Product::factory()->create([
        'sku' => 'I-LST',
        'woo_product_id' => 9204,
    ]);

    bindStubWriter([
        'status' => 'error',
        'fields_pushed' => [],
        'http_status' => null,
        'reason' => '5xx upstream',
    ]);

    $event = new ProductFieldsChangedEvent(
        productId: $product->id,
        sku: 'I-LST',
        changedFields: ['stock_quantity'],
    );

    expect(fn () => app(PushProductFieldsToWoo::class)->handle($event))
        ->toThrow(RuntimeException::class, '5xx upstream');
});

it('Case J: Product::find returns null (deleted before handle) → writer NEVER called', function (): void {
    $writer = bindStubWriter(['status' => 'pushed', 'fields_pushed' => [], 'http_status' => 200, 'reason' => null]);

    // Non-existent product id — Product::find returns null in the listener.
    $event = new ProductFieldsChangedEvent(
        productId: 999_999,
        sku: 'J-LST',
        changedFields: ['stock_quantity'],
    );

    app(PushProductFieldsToWoo::class)->handle($event);

    expect($writer->calls)->toBe([]);
});

/**
 * Bind an anonymous-subclass WooProductWriter stub with canned return value.
 *
 * @param  array{status:string, fields_pushed:array<int,string>, http_status:?int, reason:?string}  $return
 * @return object the bound stub with public $calls array.
 */
function bindStubWriter(array $return): object
{
    $stub = new class($return) extends WooProductWriter
    {
        /** @var array<int, array{product_id:int, fields:array<int,string>, correlation_id:?string}> */
        public array $calls = [];

        public function __construct(public array $cannedReturn)
        {
            // Skip parent constructor — no WooClient needed for the stub.
        }

        public function putProductFields(Product $product, array $fields, ?string $correlationId = null): array
        {
            $this->calls[] = [
                'product_id' => $product->id,
                'fields' => $fields,
                'correlation_id' => $correlationId,
            ];

            return $this->cannedReturn;
        }
    };

    app()->instance(WooProductWriter::class, $stub);

    return $stub;
}
