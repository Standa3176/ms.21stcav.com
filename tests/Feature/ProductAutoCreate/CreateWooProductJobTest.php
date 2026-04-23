<?php

declare(strict_types=1);

use App\Domain\Pricing\Services\PriceCalculator;
use App\Domain\Pricing\Services\PricingResolution;
use App\Domain\Pricing\Services\RuleResolver;
use App\Domain\ProductAutoCreate\Events\AutoCreateAttempted;
use App\Domain\ProductAutoCreate\Events\AutoCreateFailed;
use App\Domain\ProductAutoCreate\Events\AutoCreateSucceeded;
use App\Domain\ProductAutoCreate\Jobs\CreateWooProductJob;
use App\Domain\ProductAutoCreate\Jobs\ProcessAutoCreateImageJob;
use App\Domain\ProductAutoCreate\Services\CompletenessScorer;
use App\Domain\ProductAutoCreate\Services\ProductContentBuilder;
use App\Domain\ProductAutoCreate\Services\ProductMatcher;
use App\Domain\ProductAutoCreate\Services\ProductSlugGenerator;
use App\Domain\ProductAutoCreate\Services\TaxonomyResolver;
use App\Domain\Products\Models\Product;
use App\Domain\Suggestions\Models\Suggestion;
use App\Domain\Sync\Services\SupplierClient;
use App\Domain\Sync\Services\WooClient;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Phase 6 Plan 03 Task 2 — CreateWooProductJob orchestrator
|--------------------------------------------------------------------------
| Covers:
|   - Constructor: queue=sync-woo-push, tries=3, backoff=[30,300,1800].
|   - Happy path: full pipeline ends with AutoCreateSucceeded + draft Product
|     + forceFill reconciliation of Woo-returned slug.
|   - Duplicate gate (AUTO-08): existsNormalised=true → AutoCreateFailed('duplicate'),
|     no Woo POST, no image job.
|   - Supplier not found: supplier returns [] → AutoCreateFailed('supplier_not_found').
|   - Needs-brand-or-category: either taxonomy id null → Product created with
|     auto_create_status='needs_brand_or_category_assignment', no Woo POST.
|   - Pre-POST Woo slug collision (P6-G option 1): WooClient::get returns non-empty
|     → regenerated slug `{base}-{sku}` used in POST payload.
|   - Woo-side slug reconciliation (P6-G option 2): POST returns a different slug
|     than sent → Product.slug persisted to Woo's value.
|   - failed() hook: writes kind='auto_create_failed' Suggestion with evidence.
|   - Chains ProcessAutoCreateImageJob::dispatch on success.
*/

beforeEach(function (): void {
    Context::add('correlation_id', (string) Str::uuid());
});

/**
 * Run the job's handle() directly with mocked service dependencies so we can
 * assert each branch independently of Laravel's queue plumbing.
 */
function runCreateJob(
    string $sku,
    WooClient $woo,
    SupplierClient $supplier,
    ?ProductSlugGenerator $slugGenerator = null,
    ?ProductMatcher $matcher = null,
    ?TaxonomyResolver $taxonomy = null,
    ?RuleResolver $ruleResolver = null,
    ?PriceCalculator $calculator = null,
    ?CompletenessScorer $scorer = null,
    ?ProductContentBuilder $content = null,
): void {
    $job = new CreateWooProductJob($sku);
    $job->handle(
        $woo,
        $supplier,
        $content ?? app(ProductContentBuilder::class),
        $slugGenerator ?? app(ProductSlugGenerator::class),
        $matcher ?? app(ProductMatcher::class),
        $taxonomy ?? app(TaxonomyResolver::class),
        $ruleResolver ?? app(RuleResolver::class),
        $calculator ?? app(PriceCalculator::class),
        $scorer ?? app(CompletenessScorer::class),
    );
}

it('constructor sets queue=sync-woo-push, tries=3, backoff=[30,300,1800]', function (): void {
    $job = new CreateWooProductJob('CTOR-01');

    expect($job->queue)->toBe('sync-woo-push');
    expect($job->tries)->toBe(3);
    expect($job->backoff)->toBe([30, 300, 1800]);
    expect($job->sku)->toBe('CTOR-01');
    expect($job->suggestionId)->toBeNull();
});

it('short-circuits with AutoCreateFailed(duplicate) when matcher hits', function (): void {
    Event::fake([AutoCreateAttempted::class, AutoCreateFailed::class, AutoCreateSucceeded::class]);
    Queue::fake();

    Product::factory()->create(['sku' => 'DUP-01']);

    $woo = Mockery::mock(WooClient::class);
    $woo->shouldNotReceive('post');
    $supplier = Mockery::mock(SupplierClient::class);
    $supplier->shouldNotReceive('fetchSingleProduct');

    runCreateJob('DUP-01', $woo, $supplier);

    Event::assertDispatched(AutoCreateAttempted::class);
    Event::assertDispatched(AutoCreateFailed::class,
        fn (AutoCreateFailed $e) => $e->sku === 'DUP-01' && $e->reason === 'duplicate');
    Event::assertNotDispatched(AutoCreateSucceeded::class);
    Queue::assertNotPushed(ProcessAutoCreateImageJob::class);
});

it('short-circuits with AutoCreateFailed(supplier_not_found) on empty supplier response', function (): void {
    Event::fake([AutoCreateFailed::class, AutoCreateSucceeded::class]);

    $woo = Mockery::mock(WooClient::class);
    $woo->shouldNotReceive('post');
    $supplier = Mockery::mock(SupplierClient::class);
    $supplier->shouldReceive('fetchSingleProduct')->with('GHOST-01')->once()->andReturn([]);

    runCreateJob('GHOST-01', $woo, $supplier);

    Event::assertDispatched(AutoCreateFailed::class,
        fn (AutoCreateFailed $e) => $e->reason === 'supplier_not_found');
    Event::assertNotDispatched(AutoCreateSucceeded::class);
});

it('parks product when brand taxonomy unresolved (no Woo POST, no success event)', function (): void {
    Event::fake();
    Queue::fake();

    $supplier = Mockery::mock(SupplierClient::class);
    $supplier->shouldReceive('fetchSingleProduct')->andReturn([
        'sku' => 'UNMAPPED-01',
        'name' => 'Mystery Device',
        'brand' => 'UnknownBrand',
        'category' => 'UnknownCategory',
        'price' => 100,
    ]);

    $woo = Mockery::mock(WooClient::class);
    $woo->shouldReceive('get')->andReturn([]);  // no slug collision
    $woo->shouldNotReceive('post');  // critical assertion — no Woo POST in this branch

    $taxonomy = Mockery::mock(TaxonomyResolver::class);
    $taxonomy->shouldReceive('resolveBrand')->andReturn(null);
    $taxonomy->shouldReceive('resolveCategory')->andReturn(null);

    runCreateJob('UNMAPPED-01', $woo, $supplier, taxonomy: $taxonomy);

    $product = Product::where('sku', 'UNMAPPED-01')->first();
    expect($product)->not->toBeNull();
    expect($product->auto_create_status)->toBe('needs_brand_or_category_assignment');
    expect($product->woo_product_id)->toBeNull();

    Event::assertNotDispatched(AutoCreateSucceeded::class);
    Queue::assertNotPushed(ProcessAutoCreateImageJob::class);
});

it('pre-POST slug probe regenerates candidate on Woo collision (P6-G option 1)', function (): void {
    Event::fake();
    Queue::fake();

    $supplier = Mockery::mock(SupplierClient::class);
    $supplier->shouldReceive('fetchSingleProduct')->andReturn([
        'sku' => 'LOG-MEETUP',
        'name' => 'Logitech MeetUp',
        'brand' => 'Logitech',
        'category' => 'Video Conferencing',
        'price' => 1000,
        'image_url' => 'https://cdn.supplier.com/logitech-meetup.jpg',
    ]);

    $taxonomy = Mockery::mock(TaxonomyResolver::class);
    $taxonomy->shouldReceive('resolveBrand')->andReturn(42);
    $taxonomy->shouldReceive('resolveCategory')->andReturn(7);

    // First WooClient::get (slug probe) returns a collision; POST then observes
    // the regenerated -{sku} slug.
    $woo = Mockery::mock(WooClient::class);
    $woo->shouldReceive('get')->with('/products', Mockery::subset(['slug' => 'logitech-meetup']))
        ->andReturn([['id' => 200, 'slug' => 'logitech-meetup']]);
    $woo->shouldReceive('post')->with(
        '/products',
        Mockery::on(fn ($payload) => str_contains((string) ($payload['slug'] ?? ''), '-log-meetup'))
    )->once()->andReturn([
        'id' => 500,
        'slug' => 'logitech-meetup-log-meetup',
    ]);

    runCreateJob('LOG-MEETUP', $woo, $supplier, taxonomy: $taxonomy);

    $product = Product::where('sku', 'LOG-MEETUP')->first();
    expect($product)->not->toBeNull();
    expect($product->slug)->toBe('logitech-meetup-log-meetup');
});

it('reconciles Woo-returned slug onto Product.slug (P6-G option 2)', function (): void {
    Event::fake([AutoCreateSucceeded::class]);
    Queue::fake();

    $supplier = Mockery::mock(SupplierClient::class);
    $supplier->shouldReceive('fetchSingleProduct')->andReturn([
        'sku' => 'RECON-01',
        'name' => 'Reconcile Device',
        'brand' => 'BrandX',
        'category' => 'Cat1',
        'price' => 100,
    ]);

    $taxonomy = Mockery::mock(TaxonomyResolver::class);
    $taxonomy->shouldReceive('resolveBrand')->andReturn(1);
    $taxonomy->shouldReceive('resolveCategory')->andReturn(1);

    $woo = Mockery::mock(WooClient::class);
    $woo->shouldReceive('get')->andReturn([]);  // no pre-POST collision
    $woo->shouldReceive('post')->andReturn([
        'id' => 777,
        'slug' => 'reconcile-device-2',  // Woo auto-disambiguated to -2
    ]);

    runCreateJob('RECON-01', $woo, $supplier, taxonomy: $taxonomy);

    $product = Product::where('sku', 'RECON-01')->first();
    expect($product->slug)->toBe('reconcile-device-2');
    expect($product->woo_product_id)->toBe(777);

    Event::assertDispatched(AutoCreateSucceeded::class,
        fn (AutoCreateSucceeded $e) => $e->slug === 'reconcile-device-2' && $e->wooProductId === 777);
});

it('chains ProcessAutoCreateImageJob with supplier image URL + fallbacks', function (): void {
    Event::fake();
    Queue::fake();

    $supplier = Mockery::mock(SupplierClient::class);
    $supplier->shouldReceive('fetchSingleProduct')->andReturn([
        'sku' => 'IMG-CHAIN-01',
        'name' => 'Image Chain',
        'brand' => 'B',
        'category' => 'C',
        'price' => 200,
        'image_url' => 'https://primary.example.com/x.jpg',
        'image_fallback_urls' => ['https://fallback.example.com/y.jpg'],
    ]);

    $taxonomy = Mockery::mock(TaxonomyResolver::class);
    $taxonomy->shouldReceive('resolveBrand')->andReturn(1);
    $taxonomy->shouldReceive('resolveCategory')->andReturn(1);

    $woo = Mockery::mock(WooClient::class);
    $woo->shouldReceive('get')->andReturn([]);
    $woo->shouldReceive('post')->andReturn(['id' => 888, 'slug' => 'image-chain']);

    runCreateJob('IMG-CHAIN-01', $woo, $supplier, taxonomy: $taxonomy);

    Queue::assertPushed(ProcessAutoCreateImageJob::class, function (ProcessAutoCreateImageJob $job): bool {
        return $job->supplierImageUrl === 'https://primary.example.com/x.jpg'
            && $job->supplierFallbackUrls === ['https://fallback.example.com/y.jpg'];
    });
});

it('failed() writes kind=auto_create_failed Suggestion with evidence', function (): void {
    $job = new CreateWooProductJob('DLQ-01', suggestionId: '01HABCDEFGHJKMNPQRSTVWXYZ0');
    $job->failed(new RuntimeException('woo exploded'));

    $row = Suggestion::query()->where('kind', 'auto_create_failed')->first();
    expect($row)->not->toBeNull();
    expect($row->status)->toBe(Suggestion::STATUS_PENDING);
    expect($row->evidence['sku'] ?? null)->toBe('DLQ-01');
    expect($row->evidence['source'] ?? null)->toBe('CreateWooProductJob');
    expect($row->evidence['error'] ?? null)->toBe('woo exploded');
    expect($row->evidence['exception'] ?? null)->toBe(RuntimeException::class);
    expect($row->evidence['original_suggestion_id'] ?? null)->toBe('01HABCDEFGHJKMNPQRSTVWXYZ0');
});

it('happy path: fires AutoCreateSucceeded, persists Product.sell_price, chains image job', function (): void {
    Event::fake([AutoCreateAttempted::class, AutoCreateSucceeded::class]);
    Queue::fake();

    $supplier = Mockery::mock(SupplierClient::class);
    $supplier->shouldReceive('fetchSingleProduct')->andReturn([
        'sku' => 'HAPPY-01',
        'name' => 'Happy Product',
        'brand' => 'Logitech',
        'category' => 'Cameras',
        'price' => 500,
        'image_url' => 'https://example.com/happy.jpg',
    ]);

    $taxonomy = Mockery::mock(TaxonomyResolver::class);
    $taxonomy->shouldReceive('resolveBrand')->andReturn(10);
    $taxonomy->shouldReceive('resolveCategory')->andReturn(20);

    // Pricing: resolution margin 4000 bps (40%) → compute returns a positive int.
    $resolver = Mockery::mock(RuleResolver::class);
    $resolver->shouldReceive('resolve')->andReturn(new PricingResolution(
        marginBasisPoints: 4000,
        source: 'default_tier',
        matchedRuleId: 1,
        overrideId: null,
        chain: ['default_tier'],
    ));
    $calculator = Mockery::mock(PriceCalculator::class);
    $calculator->shouldReceive('compute')->andReturn(84000); // £840.00

    $woo = Mockery::mock(WooClient::class);
    $woo->shouldReceive('get')->andReturn([]);
    $woo->shouldReceive('post')->once()->andReturn([
        'id' => 1001,
        'slug' => 'happy-product',
    ]);

    runCreateJob('HAPPY-01', $woo, $supplier, taxonomy: $taxonomy,
        ruleResolver: $resolver, calculator: $calculator);

    $product = Product::where('sku', 'HAPPY-01')->first();
    expect($product)->not->toBeNull();
    expect($product->woo_product_id)->toBe(1001);
    expect($product->auto_create_status)->toBe('draft');
    expect((float) $product->sell_price)->toBe(840.0);

    Event::assertDispatched(AutoCreateSucceeded::class);
    Queue::assertPushed(ProcessAutoCreateImageJob::class, 1);
});
