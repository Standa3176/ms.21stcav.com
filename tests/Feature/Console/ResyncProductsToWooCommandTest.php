<?php

declare(strict_types=1);

use App\Domain\ProductAutoCreate\Services\ProductBrandTermResolver;
use App\Domain\Products\Models\Product;
use App\Domain\Sync\Services\WooClient;
use App\Domain\Sync\Services\WpRestClient;
use Illuminate\Support\Facades\Cache;

/*
|--------------------------------------------------------------------------
| 260703-p8m — products:resync-to-woo --brand filter
|--------------------------------------------------------------------------
|
| The command already resyncs by --skus. This adds --brand=<comma,list>:
| resolve each (case-insensitive) brand name → brand_id via
| TaxonomyResolver::allBrands, gather every Product with that brand_id + a
| woo_product_id, merge with any --skus. At least one of --skus/--brand is
| required (the old "--skus is required" error becomes "Provide --skus or
| --brand.").
|
| BOUNDARY STRATEGY:
|   - WooClient is stubbed with a recording double bound in the container so
|     we can assert the resync PUT ran for the brand's woo-published products.
|   - ProductBrandTermResolver is a no-op double (never touches the network).
|   - taxonomy.brands cache is seeded directly so TaxonomyResolver::allBrands
|     returns a canned list without a live Woo call.
*/

/** Recording WooClient double — captures every put() endpoint. */
function recordingWoo(): WooClient
{
    return new class extends WooClient
    {
        /** @var array<int, string> */
        public array $puts = [];

        public function __construct() {}

        public function put(string $endpoint, array $payload): array
        {
            $this->puts[] = $endpoint;

            return ['id' => 1, 'endpoint' => $endpoint];
        }

        public function post(string $endpoint, array $payload): array
        {
            return ['id' => 1];
        }
    };
}

/** No-op ProductBrandTermResolver — never hits WP REST. */
function silentBrandResolver(): ProductBrandTermResolver
{
    return new class(new WpRestClient('https://example.test/wp-json', null, null)) extends ProductBrandTermResolver
    {
        public function getTermIdForName(?string $brandName): ?int
        {
            return null;
        }

        public function assignToProduct(int $wooProductId, array $termIds): bool
        {
            return true;
        }
    };
}

beforeEach(function (): void {
    Cache::flush();
    // Seed the taxonomy brand list so allBrands() resolves without Woo.
    Cache::put('taxonomy.brands', [
        ['id' => 3001, 'name' => 'Yealink'],
        ['id' => 3002, 'name' => 'Cisco'],
    ], 3600);
});

it('--brand=Yealink resyncs the brand woo-published products, skips the one without woo_product_id', function (): void {
    $woo = recordingWoo();
    $this->app->instance(WooClient::class, $woo);
    $this->app->instance(ProductBrandTermResolver::class, silentBrandResolver());

    $a = Product::factory()->create(['brand_id' => 3001, 'woo_product_id' => 5001, 'sku' => 'YEA-A', 'sell_price' => 100]);
    $b = Product::factory()->create(['brand_id' => 3001, 'woo_product_id' => 5002, 'sku' => 'YEA-B', 'sell_price' => 200]);
    // brand_id matches but never published to Woo → must be skipped.
    Product::factory()->create(['brand_id' => 3001, 'woo_product_id' => null, 'sku' => 'YEA-NOPUB', 'sell_price' => 300]);
    // different brand → must not be touched.
    Product::factory()->create(['brand_id' => 3002, 'woo_product_id' => 5099, 'sku' => 'CIS-X', 'sell_price' => 400]);

    $this->artisan('products:resync-to-woo', ['--brand' => 'Yealink'])
        ->assertExitCode(0);

    // PUTs landed on both Yealink woo ids, and NOT on the Cisco one.
    $joined = implode(' ', $woo->puts);
    expect($joined)->toContain('products/5001');
    expect($joined)->toContain('products/5002');
    expect($joined)->not->toContain('products/5099');
});

it('neither --skus nor --brand → errors "Provide --skus or --brand."', function (): void {
    $this->app->instance(WooClient::class, recordingWoo());
    $this->app->instance(ProductBrandTermResolver::class, silentBrandResolver());

    $this->artisan('products:resync-to-woo')
        ->expectsOutputToContain('Provide --skus or --brand.')
        ->assertExitCode(1);
});

it('--brand=Nonexistent resolves to no brand_id → nothing to resync, errors', function (): void {
    $this->app->instance(WooClient::class, recordingWoo());
    $this->app->instance(ProductBrandTermResolver::class, silentBrandResolver());

    Product::factory()->create(['brand_id' => 3001, 'woo_product_id' => 5001, 'sku' => 'YEA-A']);

    $this->artisan('products:resync-to-woo', ['--brand' => 'Nonexistent'])
        ->expectsOutputToContain('Provide --skus or --brand.')
        ->assertExitCode(1);
});
