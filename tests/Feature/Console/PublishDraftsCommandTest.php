<?php

declare(strict_types=1);

use App\Domain\ProductAutoCreate\Events\ProductPublished;
use App\Domain\ProductAutoCreate\Services\TaxonomyResolver;
use App\Domain\Products\Models\Product;
use App\Domain\Sync\Services\LiveSupplierStockResolver;
use App\Domain\Sync\Services\WooClient;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Quick task 260703-hn1 — products:publish-drafts
|--------------------------------------------------------------------------
|
| Bulk-publishes COMPLETE (brand_id + category_id set), never-pushed
| (woo_product_id IS NULL) auto-created drafts to Woo through the SAME
| PublishProductJob path the review-inbox Approve uses.
|
| Test seams (mirrors tests/Feature/ProductAutoCreate/PublishProductStockTest.php):
|   - WooClient stub: anonymous subclass whose post() returns ['id'=>N,'slug'=>..]
|     so PublishProductJob's Path-B create back-fills woo_product_id.
|   - LiveSupplierStockResolver: null-returning Mockery double so the 260702-pes
|     publish-time stock hydration is a harmless no-op.
|   - TaxonomyResolver: empty allBrands() so the post-publish brand linkage is
|     skipped without a live Woo REST call.
|
| Helpers are prefixed hn1* (unique names) rather than reusing
| PublishProductStockTest's bindPublishWooStockStub / bindLiveStockResolver: those are
| declared there WITHOUT a function_exists guard, so any same-named helper here
| would redeclare-fatal when both files load in the same suite run. Unique names
| keep this file self-contained (runs standalone) AND collision-free (full suite).
*/

beforeEach(function (): void {
    Context::add('correlation_id', (string) Str::uuid());
    config()->set('services.woo.write_enabled', true);
    hn1BindWooStub(postResponse: ['id' => 4242, 'slug' => 'published-draft']);
    hn1BindLiveStock(null);
    hn1BindNoBrandsTaxonomy();
});

/**
 * Bind a Mockery LiveSupplierStockResolver double whose resolveForSku() returns
 * $offer (null = genuine OOS / no live offer → 260702-pes hydration no-op).
 *
 * @param  array{stock_quantity:int, stock_status:string, buy_price:?float}|null  $offer
 */
function hn1BindLiveStock(?array $offer): void
{
    $mock = Mockery::mock(LiveSupplierStockResolver::class);
    $mock->shouldReceive('resolveForSku')->andReturn($offer);
    app()->instance(LiveSupplierStockResolver::class, $mock);
}

/**
 * Bind a WooClient stub that records every put()/post() call and returns a
 * canned response. post() returning ['id'=>N,'slug'=>..] makes PublishProductJob
 * Path-B back-fill woo_product_id.
 *
 * @return object stub with a public array $calls
 */
function hn1BindWooStub(array $putResponse = [], array $postResponse = []): object
{
    $stub = new class($putResponse, $postResponse) extends WooClient
    {
        /** @var array<int, array{method:string, path:string, body:array<string,mixed>}> */
        public array $calls = [];

        public function __construct(
            public array $putResponse,
            public array $postResponse,
        ) {
            // Skip parent constructor — no IntegrationLogger / resolver needed.
        }

        public function put(string $endpoint, array $payload): array
        {
            $this->calls[] = ['method' => 'PUT', 'path' => $endpoint, 'body' => $payload];

            return $this->putResponse;
        }

        public function post(string $endpoint, array $payload): array
        {
            $this->calls[] = ['method' => 'POST', 'path' => $endpoint, 'body' => $payload];

            return $this->postResponse;
        }
    };

    app()->instance(WooClient::class, $stub);

    return $stub;
}

/**
 * Bind a TaxonomyResolver whose allBrands() is empty so PublishProductJob's
 * post-publish brand linkage resolves to null (skipped) without a live Woo REST
 * call. Constructor is bypassed (no WooClient dep needed).
 */
function hn1BindNoBrandsTaxonomy(): void
{
    $stub = new class extends TaxonomyResolver
    {
        public function __construct() {}

        public function allBrands(): array
        {
            return [];
        }
    };

    app()->instance(TaxonomyResolver::class, $stub);
}

/** Create a COMPLETE, never-pushed auto-created draft. */
function hn1CompleteDraft(array $overrides = []): Product
{
    return Product::factory()->create(array_merge([
        'woo_product_id' => null,
        'brand_id' => 2907,
        'category_id' => 42,
        'auto_create_status' => 'draft',
        'status' => 'draft',
        'sell_price' => null,
    ], $overrides));
}

it('publishes a complete not-pushed draft via PublishProductJob (woo_product_id + published)', function (): void {
    Event::fake([ProductPublished::class]);

    $draft = hn1CompleteDraft(['sku' => 'HN1-COMPLETE-A', 'name' => 'Complete Widget A']);

    $this->artisan('products:publish-drafts')
        ->assertExitCode(0);

    $draft->refresh();
    expect((int) $draft->woo_product_id)->toBe(4242);
    expect($draft->auto_create_status)->toBe('published');
    expect($draft->status)->toBe('publish');
});

it('reports published counter = 1 for a single complete draft', function (): void {
    Event::fake([ProductPublished::class]);

    hn1CompleteDraft(['sku' => 'HN1-COUNT-1', 'name' => 'Count Widget']);

    $this->artisan('products:publish-drafts')
        ->expectsOutputToContain('published: 1')
        ->assertExitCode(0);
});

it('never publishes an incomplete draft (brand_id null) — stays draft/not-pushed', function (): void {
    Event::fake([ProductPublished::class]);

    $incomplete = hn1CompleteDraft([
        'sku' => 'HN1-INCOMPLETE',
        'name' => 'Incomplete Widget',
        'brand_id' => null,
    ]);

    $this->artisan('products:publish-drafts')
        ->assertExitCode(0);

    $incomplete->refresh();
    expect($incomplete->woo_product_id)->toBeNull();
    expect($incomplete->auto_create_status)->toBe('draft');
});

it('never publishes an incomplete draft (category_id null)', function (): void {
    Event::fake([ProductPublished::class]);

    $incomplete = hn1CompleteDraft([
        'sku' => 'HN1-NOCAT',
        'name' => 'No Category Widget',
        'category_id' => null,
    ]);

    $this->artisan('products:publish-drafts')->assertExitCode(0);

    $incomplete->refresh();
    expect($incomplete->woo_product_id)->toBeNull();
    expect($incomplete->auto_create_status)->toBe('draft');
});

it('skips an already-pushed product (woo_product_id set)', function (): void {
    Event::fake([ProductPublished::class]);

    $pushed = hn1CompleteDraft([
        'sku' => 'HN1-ALREADY',
        'name' => 'Already Pushed Widget',
        'woo_product_id' => 555,
        'auto_create_status' => 'published',
        'status' => 'publish',
    ]);

    $this->artisan('products:publish-drafts')->assertExitCode(0);

    $pushed->refresh();
    // Untouched — still on its original Woo id.
    expect((int) $pushed->woo_product_id)->toBe(555);
});

it('--dry-run publishes nothing and lists the matching SKU + total', function (): void {
    Event::fake([ProductPublished::class]);

    $draft = hn1CompleteDraft(['sku' => 'HN1-DRYRUN', 'name' => 'Dry Run Widget']);

    $this->artisan('products:publish-drafts --dry-run')
        ->expectsOutputToContain('HN1-DRYRUN')
        ->expectsOutputToContain('Would publish 1')
        ->assertExitCode(0);

    $draft->refresh();
    expect($draft->woo_product_id)->toBeNull();
    expect($draft->auto_create_status)->toBe('draft');
});

it('--require-images excludes a 0-image draft and includes a draft with a gallery', function (): void {
    Event::fake([ProductPublished::class]);

    $noImages = hn1CompleteDraft([
        'sku' => 'HN1-NOIMG',
        'name' => 'No Image Widget',
        'gallery_image_urls' => [],
    ]);
    $withImages = hn1CompleteDraft([
        'sku' => 'HN1-WITHIMG',
        'name' => 'With Image Widget',
        'gallery_image_urls' => ['https://ms.example/img/a.webp'],
    ]);

    $this->artisan('products:publish-drafts --require-images')->assertExitCode(0);

    $noImages->refresh();
    $withImages->refresh();

    expect($noImages->woo_product_id)->toBeNull();
    expect($noImages->auto_create_status)->toBe('draft');

    expect((int) $withImages->woo_product_id)->toBe(4242);
    expect($withImages->auto_create_status)->toBe('published');
});

it('--skus=A only publishes A even when B also qualifies', function (): void {
    Event::fake([ProductPublished::class]);

    $a = hn1CompleteDraft(['sku' => 'HN1-ONLY-A', 'name' => 'Widget A']);
    $b = hn1CompleteDraft(['sku' => 'HN1-ALSO-B', 'name' => 'Widget B']);

    $this->artisan('products:publish-drafts --skus=HN1-ONLY-A')->assertExitCode(0);

    $a->refresh();
    $b->refresh();

    expect((int) $a->woo_product_id)->toBe(4242);
    expect($a->auto_create_status)->toBe('published');

    expect($b->woo_product_id)->toBeNull();
    expect($b->auto_create_status)->toBe('draft');
});

it('reports a clean no-op when nothing matches', function (): void {
    Event::fake([ProductPublished::class]);

    // A manual (non-auto-created) product must never be selected.
    Product::factory()->create([
        'sku' => 'HN1-MANUAL',
        'woo_product_id' => null,
        'brand_id' => 1,
        'category_id' => 1,
        'auto_create_status' => 'manual',
    ]);

    $this->artisan('products:publish-drafts')
        ->expectsOutputToContain('No complete not-pushed drafts match.')
        ->assertExitCode(0);
});
