<?php

declare(strict_types=1);

use App\Domain\Products\Models\Product;
use App\Domain\Products\Models\SupplierSkuCache;
use App\Domain\Sync\Services\SupplierFeedReader;

/*
|--------------------------------------------------------------------------
| supplier:probe-sourceability-gap — command wiring with a STUBBED feed reader
|--------------------------------------------------------------------------
|
| Quick task 260719-mgp. Proves the read-only command:
|   - samples on-Woo products NOT in supplier_sku_cache
|   - classifies each against the (stubbed, in-memory) remote feed
|   - prints the count + %-per-bucket summary with interpretation lines
|
| NO real network: the SupplierFeedReader interface is bound to an in-memory
| fake keyed by manufacturer, so the whole classification runs against SQLite
| + PHP arrays. This mirrors the SourcingGapScanner's injectable-checker seam.
*/

/**
 * In-memory feed reader: manufacturer (lowercased) => list of {mpn, suppliersku}.
 */
final class FakeSupplierFeedReader implements SupplierFeedReader
{
    /** @param array<string, array<int, array{mpn:string, suppliersku:string}>> $byManufacturer */
    public function __construct(private array $byManufacturer = []) {}

    public function rowsForManufacturer(string $manufacturer, int $cap = 5000): array
    {
        return $this->byManufacturer[mb_strtolower(trim($manufacturer))] ?? [];
    }
}

beforeEach(function (): void {
    SupplierSkuCache::query()->truncate();
});

it('prints a bucketed summary classifying the sample against the stubbed feed', function (): void {
    // ── Local products (all on Woo, all NOT in supplier_sku_cache) ──

    // (a) matching_gap — feed carries the same part in a squashed format.
    Product::factory()->create(['sku' => 'MR.JQU11.002', 'name' => 'Acer Projector Lamp', 'status' => 'publish']);
    // (b) brand_in_feed_item_absent — Yealink has feed rows but not this SKU.
    Product::factory()->create(['sku' => 'YEA-GONE-1', 'name' => 'Yealink Legacy Phone', 'status' => 'publish']);
    // (c) not_in_feed — manufacturer absent from the feed.
    Product::factory()->create(['sku' => 'OBS-1', 'name' => 'Obscurabrand Widget', 'status' => 'publish']);
    // (d) no_manufacturer — blank name → nothing to key on.
    Product::factory()->create(['sku' => 'NOMFR-1', 'name' => '', 'status' => 'publish']);

    // A product that IS in the cache must be excluded from the sample entirely.
    Product::factory()->create(['sku' => 'IN-CACHE-1', 'name' => 'Acer In Cache', 'status' => 'publish']);
    SupplierSkuCache::query()->insert(['sku' => 'in-cache-1']);

    $fake = new FakeSupplierFeedReader([
        'acer' => [
            ['mpn' => 'MRJQU11002', 'suppliersku' => 'WC-99'], // squashed match → (a)
        ],
        'yealink' => [
            ['mpn' => 'YEA-STILL-HERE', 'suppliersku' => 'S-1'], // rows exist, no match → (b)
        ],
        // 'obscurabrand' intentionally absent → (c)
    ]);
    app()->instance(SupplierFeedReader::class, $fake);

    $this->artisan('supplier:probe-sourceability-gap', ['--limit' => 150, '--status' => 'all'])
        ->assertSuccessful()
        ->expectsOutputToContain('matching_gap')
        ->expectsOutputToContain('brand_in_feed_item_absent')
        ->expectsOutputToContain('not_in_feed')
        ->expectsOutputToContain('no_manufacturer')
        // Interpretation lines make the split actionable for the operator.
        ->expectsOutputToContain('fixable');
});

it('never queries the feed for a product with no resolvable manufacturer', function (): void {
    Product::factory()->create(['sku' => 'NOMFR-ONLY', 'name' => '', 'status' => 'publish']);

    // Reader that explodes if touched — proves (d) short-circuits before any feed read.
    $exploding = new class implements SupplierFeedReader
    {
        public function rowsForManufacturer(string $manufacturer, int $cap = 5000): array
        {
            throw new RuntimeException('feed must not be read for no_manufacturer products');
        }
    };
    app()->instance(SupplierFeedReader::class, $exploding);

    $this->artisan('supplier:probe-sourceability-gap', ['--limit' => 10])
        ->assertSuccessful()
        ->expectsOutputToContain('no_manufacturer');
});

it('excludes products already present in supplier_sku_cache from the sample', function (): void {
    Product::factory()->create(['sku' => 'CACHED-A', 'name' => 'Acer Thing', 'status' => 'publish']);
    SupplierSkuCache::query()->insert(['sku' => 'cached-a']);

    app()->instance(SupplierFeedReader::class, new FakeSupplierFeedReader);

    $this->artisan('supplier:probe-sourceability-gap', ['--limit' => 10])
        ->assertSuccessful()
        ->expectsOutputToContain('Sampled 0');
});

it('honours the --status filter (publish only excludes pending)', function (): void {
    Product::factory()->create(['sku' => 'PUB-1', 'name' => 'Acer Pub', 'status' => 'publish']);
    Product::factory()->create(['sku' => 'PEND-1', 'name' => 'Acer Pend', 'status' => 'pending']);

    app()->instance(SupplierFeedReader::class, new FakeSupplierFeedReader(['acer' => []]));

    $this->artisan('supplier:probe-sourceability-gap', ['--limit' => 150, '--status' => 'publish'])
        ->assertSuccessful()
        ->expectsOutputToContain('Sampled 1');
});
