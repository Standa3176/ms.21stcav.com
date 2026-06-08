<?php

declare(strict_types=1);

use App\Domain\Competitor\Models\CompetitorPrice;
use App\Domain\Pricing\Services\CompetitorPositionScanner;
use App\Domain\Products\Models\Product;
use App\Domain\Products\Models\SupplierOfferSnapshot;
use App\Domain\Sync\Services\SupplierFreshnessResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Quick task 260608-g8x — CompetitorPositionScanner stale-supplier exclusion
|--------------------------------------------------------------------------
|
| Pins the wiring of SupplierFreshnessResolver into the bucket-popup
| "Our cost (ex)" supplier-name resolver.
|
| Default (flag ON): cheapest-supplier-name picks the cheapest FRESH
| supplier; stale offers are excluded → if all suppliers stale, popup
| shows no supplier name (null).
|
| Override (flag OFF): the original cheapest-overall semantic is restored
| for back-compat / debugging.
*/

beforeEach(function (): void {
    config(['competitor.min_margin_floor_bps' => 600]);
});

function seedStaleVsFreshSupplier(string $sku, int $silentDaysAgo): Product
{
    $product = Product::factory()->create([
        'type' => 'simple',
        'status' => 'publish',
        'sku' => $sku,
        'buy_price' => 100.00,
    ]);

    // Competitor below cost so the SKU lands in the below_cost bucket
    // (the supplier-name resolver is invoked for every kept row).
    CompetitorPrice::factory()->forSku($sku)->create([
        'price_pennies_ex_vat' => 9000,
    ]);

    return $product;
}

it('Test A: flag ON picks the cheapest FRESH supplier (skips the cheaper SILENT supplier)', function (): void {
    $product = seedStaleVsFreshSupplier('TST-A', silentDaysAgo: 30);

    // SILENT supplier — cheaper at £40 but last spoke 30 days ago.
    SupplierOfferSnapshot::create([
        'sku' => 'tst-a',
        'product_id' => $product->id,
        'supplier_id' => 'SILENT',
        'supplier_name' => 'SilentSupplier',
        'price' => 40,
        'stock' => 5,
        'rrp' => 100,
        'recorded_at' => today()->subDays(30),
    ]);
    // FRESH supplier — pricier at £80 but today.
    SupplierOfferSnapshot::create([
        'sku' => 'tst-a',
        'product_id' => $product->id,
        'supplier_id' => 'FRESH',
        'supplier_name' => 'FreshSupplier',
        'price' => 80,
        'stock' => 5,
        'rrp' => 100,
        'recorded_at' => today(),
    ]);

    $scan = app(CompetitorPositionScanner::class)->compute();

    $row = collect($scan['below_cost'])->firstWhere('sku', 'TST-A');
    expect($row)->not->toBeNull();
    expect($row['supplier_name'])->toBe('FreshSupplier');
});

it('Test A inverse: flag OFF surfaces the SILENT supplier when SILENT alone has the freshest snapshot (back-compat)', function (): void {
    // Backwards-compat assertion: the original (pre-260608-g8x) selection is
    // "freshest snapshot wins, ties broken by lowest price" — NOT
    // "cheapest overall." Verify the flag-OFF path mirrors that semantic by
    // making the SILENT supplier the SOLE row in the recency window, so it
    // wins by default. Under flag ON, freshOnly filters SILENT out → null.
    $product = seedStaleVsFreshSupplier('TST-A2', silentDaysAgo: 20);

    SupplierOfferSnapshot::create([
        'sku' => 'tst-a2',
        'product_id' => $product->id,
        'supplier_id' => 'SILENT',
        'supplier_name' => 'SilentSupplier',
        'price' => 40,
        'stock' => 5,
        'rrp' => 100,
        'recorded_at' => today()->subDays(20),
    ]);

    $scanner = new CompetitorPositionScanner(
        app(SupplierFreshnessResolver::class),
        excludeStaleSuppliers: false,
    );
    $scan = $scanner->compute();

    $row = collect($scan['below_cost'])->firstWhere('sku', 'TST-A2');
    expect($row)->not->toBeNull();
    expect($row['supplier_name'])->toBe('SilentSupplier');
});

it('Test B: SILENT-only product surfaces no supplier name when flag ON', function (): void {
    $product = seedStaleVsFreshSupplier('TST-B', silentDaysAgo: 20);

    SupplierOfferSnapshot::create([
        'sku' => 'tst-b',
        'product_id' => $product->id,
        'supplier_id' => 'SILENT',
        'supplier_name' => 'SilentSupplier',
        'price' => 40,
        'stock' => 5,
        'rrp' => 100,
        'recorded_at' => today()->subDays(20),
    ]);

    $scan = app(CompetitorPositionScanner::class)->compute();
    $row = collect($scan['below_cost'])->firstWhere('sku', 'TST-B');
    expect($row)->not->toBeNull();
    // SILENT excluded → no other supplier → supplier_name is null.
    expect($row['supplier_name'])->toBeNull();
});

it('Test B inverse: SILENT-only product surfaces its supplier when flag OFF', function (): void {
    $product = seedStaleVsFreshSupplier('TST-B2', silentDaysAgo: 20);

    SupplierOfferSnapshot::create([
        'sku' => 'tst-b2',
        'product_id' => $product->id,
        'supplier_id' => 'SILENT',
        'supplier_name' => 'SilentSupplier',
        'price' => 40,
        'stock' => 5,
        'rrp' => 100,
        'recorded_at' => today()->subDays(20),
    ]);

    $scanner = new CompetitorPositionScanner(
        app(SupplierFreshnessResolver::class),
        excludeStaleSuppliers: false,
    );
    $scan = $scanner->compute();
    $row = collect($scan['below_cost'])->firstWhere('sku', 'TST-B2');
    expect($row)->not->toBeNull();
    expect($row['supplier_name'])->toBe('SilentSupplier');
});
