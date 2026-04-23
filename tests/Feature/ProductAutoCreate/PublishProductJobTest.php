<?php

declare(strict_types=1);

use App\Domain\ProductAutoCreate\Events\ProductPublished;
use App\Domain\ProductAutoCreate\Jobs\PublishProductJob;
use App\Domain\Products\Models\Product;
use App\Domain\Sync\Services\WooClient;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Phase 6 Plan 03 Task 2 — PublishProductJob
|--------------------------------------------------------------------------
| Covers:
|   - Queue routing + tries via constructor.
|   - happy path: flips auto_create_status='published' + status='publish' +
|     fires ProductPublished event with attribution.
|   - sync-woo-push queue lands the job.
|   - WooClient::put called with ['status' => 'publish'].
*/

beforeEach(function (): void {
    Context::add('correlation_id', (string) Str::uuid());
});

it('constructor sets queue=sync-woo-push and tries=3', function (): void {
    $job = new PublishProductJob(productId: 1, publishedByUserId: 99);

    expect($job->queue)->toBe('sync-woo-push');
    expect($job->tries)->toBe(3);
    expect($job->productId)->toBe(1);
    expect($job->publishedByUserId)->toBe(99);
});

it('flips status to publish + PUTs to Woo + fires ProductPublished', function (): void {
    Event::fake([ProductPublished::class]);

    $product = Product::factory()->create([
        'woo_product_id' => 500,
        'auto_create_status' => 'approved',
        'status' => 'draft',
    ]);

    $woo = Mockery::mock(WooClient::class);
    $woo->shouldReceive('put')
        ->with('/products/500', ['status' => 'publish'])
        ->once()
        ->andReturn(['id' => 500, 'status' => 'publish']);

    $job = new PublishProductJob(productId: (int) $product->id, publishedByUserId: 7);
    $job->handle($woo);

    $product->refresh();
    expect($product->auto_create_status)->toBe('published');
    expect($product->status)->toBe('publish');

    Event::assertDispatched(ProductPublished::class, function (ProductPublished $e) use ($product): bool {
        return $e->productId === (int) $product->id
            && $e->wooProductId === 500
            && $e->publishedByUserId === 7;
    });
});

it('skips Woo PUT when woo_product_id is null (locally-only drafts)', function (): void {
    Event::fake([ProductPublished::class]);

    $product = Product::factory()->create([
        'woo_product_id' => null,
        'auto_create_status' => 'approved',
        'status' => 'draft',
    ]);

    $woo = Mockery::mock(WooClient::class);
    $woo->shouldNotReceive('put');

    $job = new PublishProductJob(productId: (int) $product->id, publishedByUserId: 1);
    $job->handle($woo);

    $product->refresh();
    expect($product->auto_create_status)->toBe('published');

    Event::assertDispatched(ProductPublished::class);
});
