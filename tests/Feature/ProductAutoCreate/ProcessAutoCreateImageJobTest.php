<?php

declare(strict_types=1);

use App\Domain\Integrations\Services\IntegrationCredentialResolver;
use App\Domain\ProductAutoCreate\Jobs\ProcessAutoCreateImageJob;
use App\Domain\Products\Models\Product;
use App\Domain\Suggestions\Models\Suggestion;
use App\Domain\Sync\Models\SyncDiff;
use App\Domain\Sync\Services\WooClient;
use App\Foundation\Integration\Models\IntegrationEvent;
use App\Foundation\Integration\Services\IntegrationLogger;
use Automattic\WooCommerce\Client as AutomatticClient;
use Automattic\WooCommerce\HttpClient\Response as WooResponse;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Phase 6 Plan 02 Task 3 — ProcessAutoCreateImageJob tests.
 *
 * Covers 4 branches:
 *   - Happy: fetch + process + store + PUT + persist image_url.
 *   - Placeholder fallback: every URL 404s → placeholder URL, requires_manual_image_review=true.
 *   - Woo PUT fails: services.woo.write_enabled=true + mocked 500 → failed() writes Suggestion.
 *   - Shadow mode: write_enabled=false → PUT lands in sync_diffs, Product.image_url still persisted.
 *
 * AND queue routing: constructor puts $this->queue = 'sync-bulk' via onQueue(),
 * retries = 3, backoff = [30, 300, 1800].
 */
beforeEach(function (): void {
    Context::add('correlation_id', (string) Str::uuid());
    Storage::fake('public');
});

function sampleImageBytesForJob(): string
{
    $bytes = file_get_contents(base_path('tests/Fixtures/ProductAutoCreate/sample.jpg'));

    return $bytes !== false ? $bytes : str_repeat('x', 20_000);
}

it('routes to sync-bulk queue with 3 retries + [30, 300, 1800] backoff', function (): void {
    $job = new ProcessAutoCreateImageJob(
        productId: 123,
        supplierImageUrl: 'https://cdn.supplier.com/x.jpg',
    );

    expect($job->queue)->toBe('sync-bulk');
    expect($job->tries)->toBe(3);
    expect($job->backoff)->toBe([30, 300, 1800]);
});

it('HAPPY PATH: fetches + processes + stores + PUTs to Woo + persists image_url (shadow-mode)', function (): void {
    config(['services.woo.write_enabled' => false]);

    /** @var Product $product */
    $product = Product::factory()->create([
        'sku' => 'HAPPY-01',
        'slug' => 'logitech-meetup',
        'name' => 'Logitech MeetUp',
        'woo_product_id' => 500,
        'auto_create_status' => 'draft',
        'requires_manual_image_review' => true, // should be cleared on success
    ]);

    Http::fake([
        'https://cdn.supplier.com/happy.jpg' => Http::sequence()
            ->push('', 200, ['Content-Type' => 'image/jpeg'])
            ->push(sampleImageBytesForJob(), 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $job = new ProcessAutoCreateImageJob(
        productId: $product->id,
        supplierImageUrl: 'https://cdn.supplier.com/happy.jpg',
    );
    app()->call([$job, 'handle']);

    // (a) File written to public disk at the expected path
    expect(Storage::disk('public')->exists('auto-create-images/logitech-meetup-main.webp'))->toBeTrue();

    // (b) WooClient recorded the update via the shadow-mode path — sync_diffs row.
    // WooClient::put() emits method=POST when services.woo.use_post_for_updates
    // is true (the default) — Woo REST tunnels updates through POST.
    expect(SyncDiff::count())->toBe(1);
    $diff = SyncDiff::first();
    expect($diff->method)->toBe('POST');
    expect($diff->endpoint)->toBe('/products/500');
    expect($diff->payload['images'])->toHaveCount(1);
    expect($diff->payload['images'][0]['src'])->toContain('logitech-meetup-main.webp');

    // (c) Product reloaded has image_url set + requires_manual_image_review=false
    $product->refresh();
    expect($product->image_url)->not->toBeNull();
    expect($product->image_url)->toContain('auto-create-images/logitech-meetup-main.webp');
    expect($product->requires_manual_image_review)->toBeFalse();
});

it('FALLBACK PATH: all URLs 404 → placeholder URL + requires_manual_image_review=true + fetch_exhausted log', function (): void {
    config(['services.woo.write_enabled' => false]);
    config(['product_auto_create.placeholder_image_url' => 'https://ops.meetingstore.co.uk/images/av-product-placeholder.webp']);

    /** @var Product $product */
    $product = Product::factory()->create([
        'sku' => 'FALLBACK-01',
        'slug' => 'dead-link-product',
        'name' => 'Dead Link Product',
        'woo_product_id' => 600,
        'auto_create_status' => 'draft',
    ]);

    Http::fake([
        '*' => Http::response('', 404, ['Content-Type' => 'text/html']),
    ]);

    $job = new ProcessAutoCreateImageJob(
        productId: $product->id,
        supplierImageUrl: 'https://cdn.supplier.com/dead.jpg',
        supplierFallbackUrls: ['https://cdn.supplier.com/alt.jpg'],
    );
    app()->call([$job, 'handle']);

    // (a) NO file written — fetcher returned null
    expect(Storage::disk('public')->exists('auto-create-images/dead-link-product-main.webp'))->toBeFalse();

    // (b) WooClient PUT used the placeholder URL
    $diff = SyncDiff::first();
    expect($diff->payload['images'])->toHaveCount(1);
    expect($diff->payload['images'][0]['src'])->toBe('https://ops.meetingstore.co.uk/images/av-product-placeholder.webp');

    // (c) Product flag set
    $product->refresh();
    expect($product->requires_manual_image_review)->toBeTrue();
    expect($product->image_url)->toBe('https://ops.meetingstore.co.uk/images/av-product-placeholder.webp');

    // (d) IntegrationLogger recorded image.fetch_exhausted
    expect(IntegrationEvent::where('operation', 'image.fetch_exhausted')->count())->toBe(1);
});

it('PROCESS FAILURE: decode-fail after successful fetch → placeholder fallback + image.process.failed log', function (): void {
    config(['services.woo.write_enabled' => false]);
    config(['product_auto_create.placeholder_image_url' => 'https://ops.example/placeholder.webp']);

    /** @var Product $product */
    $product = Product::factory()->create([
        'sku' => 'DECODE-01',
        'slug' => 'decode-fail-product',
        'woo_product_id' => 700,
    ]);

    // Fetcher succeeds (size > min_bytes + content-type image/*) but the body
    // is garbage — Processor's intervention->read() throws DecoderException.
    // Stage a high-entropy non-image body of 20KB so the fetcher accepts it.
    $bogus = random_bytes(20_000);

    Http::fake([
        'https://cdn.supplier.com/bogus.jpg' => Http::sequence()
            ->push('', 200, ['Content-Type' => 'image/jpeg'])
            ->push($bogus, 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $job = new ProcessAutoCreateImageJob(
        productId: $product->id,
        supplierImageUrl: 'https://cdn.supplier.com/bogus.jpg',
    );
    app()->call([$job, 'handle']);

    // Placeholder used; review flag still true
    $product->refresh();
    expect($product->requires_manual_image_review)->toBeTrue();
    expect($product->image_url)->toBe('https://ops.example/placeholder.webp');

    // image.process.failed logged
    expect(IntegrationEvent::where('operation', 'image.process.failed')->count())->toBe(1);
});

it('SHADOW MODE: services.woo.write_enabled=false → PUT lands in sync_diffs, image_url still persisted locally', function (): void {
    // Same as happy-path essentially — double-check that no live HTTP hit.
    config(['services.woo.write_enabled' => false]);

    /** @var Product $product */
    $product = Product::factory()->create([
        'sku' => 'SHADOW-01',
        'slug' => 'shadow-product',
        'woo_product_id' => 800,
    ]);

    Http::fake([
        'https://cdn.supplier.com/s.jpg' => Http::sequence()
            ->push('', 200, ['Content-Type' => 'image/jpeg'])
            ->push(sampleImageBytesForJob(), 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $job = new ProcessAutoCreateImageJob(
        productId: $product->id,
        supplierImageUrl: 'https://cdn.supplier.com/s.jpg',
    );
    app()->call([$job, 'handle']);

    // 1 sync_diff row from the shadow-mode update (POST-tunnelled — see put()).
    expect(SyncDiff::where('method', 'POST')->count())->toBe(1);

    // Product still has the image_url persisted locally (independent of Woo)
    $product->refresh();
    expect($product->image_url)->toContain('shadow-product-main.webp');
});

it('FAILED HOOK: creates auto_create_failed Suggestion with evidence on final-retry exhaustion', function (): void {
    $job = new ProcessAutoCreateImageJob(
        productId: 999,
        supplierImageUrl: 'https://cdn.supplier.com/x.jpg',
        supplierFallbackUrls: ['https://cdn.supplier.com/y.jpg'],
    );

    Context::add('correlation_id', (string) Str::uuid());

    $exception = new RuntimeException('Woo returned 500 after 3 retries');
    $job->failed($exception);

    $suggestion = Suggestion::where('kind', 'auto_create_failed')->firstOrFail();
    expect($suggestion->status)->toBe(Suggestion::STATUS_PENDING);
    expect($suggestion->evidence)->toMatchArray([
        'source' => 'ProcessAutoCreateImageJob',
        'product_id' => 999,
        'supplier_image_url' => 'https://cdn.supplier.com/x.jpg',
        'fallback_count' => 1,
        'error' => 'Woo returned 500 after 3 retries',
        'exception' => RuntimeException::class,
    ]);
});

it('LIVE PATH: services.woo.write_enabled=true → Automattic client PUT with images payload', function (): void {
    config(['services.woo.write_enabled' => true]);

    /** @var Product $product */
    $product = Product::factory()->create([
        'sku' => 'LIVE-01',
        'slug' => 'live-product',
        'woo_product_id' => 900,
    ]);

    Http::fake([
        'https://cdn.supplier.com/live.jpg' => Http::sequence()
            ->push('', 200, ['Content-Type' => 'image/jpeg'])
            ->push(sampleImageBytesForJob(), 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $mockInner = Mockery::mock(AutomatticClient::class);
    $mockHttp = Mockery::mock();
    $mockHttp->shouldReceive('getResponse')->andReturn(new WooResponse(200, [], ''));
    $mockInner->http = $mockHttp;

    // WooClient::put() tunnels updates through the SDK's post() when
    // services.woo.use_post_for_updates is true (default) — mock post().
    $capturedPayload = null;
    $mockInner->shouldReceive('post')
        ->once()
        ->with('/products/900', Mockery::capture($capturedPayload))
        ->andReturn([
            'id' => 900,
            'images' => [
                ['id' => 1234, 'src' => 'https://meetingstore.co.uk/wp-content/uploads/live.webp'],
            ],
        ]);

    app()->instance(WooClient::class, new WooClient(
        app(IntegrationLogger::class),
        app(IntegrationCredentialResolver::class),
        $mockInner,
    ));

    $job = new ProcessAutoCreateImageJob(
        productId: $product->id,
        supplierImageUrl: 'https://cdn.supplier.com/live.jpg',
    );
    app()->call([$job, 'handle']);

    // Assertions on the captured payload (URL pass-through shape)
    expect($capturedPayload)->toHaveKey('images');
    expect($capturedPayload['images'])->toHaveCount(1);
    expect($capturedPayload['images'][0]['src'])->toContain('live-product-main.webp');
    expect($capturedPayload['images'][0])->toHaveKey('name');
    expect($capturedPayload['images'][0])->toHaveKey('alt');

    // No sync_diffs (live path)
    expect(SyncDiff::count())->toBe(0);
});
