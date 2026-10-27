<?php

declare(strict_types=1);

use App\Domain\Integrations\Services\IntegrationCredentialResolver;
use App\Domain\Products\Models\Product;
use App\Domain\Sync\Commands\SupplierDbSyncCommand;
use App\Domain\Sync\Models\ImportIssue;
use App\Domain\Sync\Services\ProductSupplierBlockResolver;
use App\Domain\Sync\Services\SupplierExclusionResolver;
use App\Domain\Sync\Services\SupplierFreshnessResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Quick task 261027-duz — supplier:db-sync cost outlier guard
|--------------------------------------------------------------------------
|
| buildBestOfferMap annotates each key with `cost_outlier` (guard evidence or
| null); resolveBuyForProduct holds the existing buy_price for an outlier key
| and raises an unresolved supplier_cost_outlier ImportIssue. Both public seams
| — no mysqli, no perform().
*/

function makeOutlierSyncCommand(): SupplierDbSyncCommand
{
    return new SupplierDbSyncCommand(
        app(IntegrationCredentialResolver::class),
        app(SupplierFreshnessResolver::class),
        excludeStaleSuppliersFromBuyPrice: false,
    );
}

function forgetOutlierCaches(): void
{
    app(ProductSupplierBlockResolver::class)->forget();
    app(SupplierExclusionResolver::class)->forget();
    app(SupplierFreshnessResolver::class)->forget();
}

/** @return array<int, array<string, string>> */
function outlierYealinkRows(): array
{
    return [
        ['mpn' => 'MW-CABLE-X', 'suppliersku' => '1301222', 'supplierid' => 'MIDWICH', 'supplier_name' => 'Midwich', 'price' => '7.20', 'stock' => '50'],
        ['mpn' => 'T74U', 'suppliersku' => '1301222', 'supplierid' => 'NIMANS', 'supplier_name' => 'Nimans', 'price' => '88.20', 'stock' => '3'],
    ];
}

function seedOutlierProduct(array $overrides = []): Product
{
    return Product::withoutEvents(fn (): Product => Product::create(array_merge([
        'woo_product_id' => 5969,
        'sku' => '1301222',
        'name' => 'Yealink T74U',
        'type' => 'simple',
        'status' => 'publish',
        'stock_status' => 'instock',
        'stock_quantity' => 3,
        'sell_price' => '105.0000',
        'buy_price' => '88.2000',
        'is_custom_ms' => false,
        'exclude_from_auto_update' => false,
        'tags' => [],
    ], $overrides)));
}

beforeEach(function (): void {
    forgetOutlierCaches();
});

it('flags the Yealink key as an outlier and excludes the outlier supplier stock', function (): void {
    config(['supplier.cost_outlier_guard.enabled' => true, 'supplier.cost_outlier_guard.ratio' => 3]);

    $map = makeOutlierSyncCommand()->buildBestOfferMap(outlierYealinkRows());
    $outlier = $map['1301222']['cost_outlier'];

    expect($outlier)->not->toBeNull()
        ->and($outlier['chosen_supplier_id'])->toBe('MIDWICH')
        ->and($outlier['chosen_supplier'])->toBe('Midwich')
        ->and($outlier['chosen_price'])->toBe(7.20)
        ->and($outlier['median_of_others'])->toBe(88.20)
        // 'buy' stays the raw cheapest so explain/logging see it; the hold is in the product loop.
        ->and($map['1301222']['buy'])->toBe('7.20')
        ->and($map['1301222']['stock'])->toBe(3)
        // Single-supplier keys are never outliers.
        ->and($map['mw-cable-x']['cost_outlier'])->toBeNull()
        ->and($map['t74u']['cost_outlier'])->toBeNull();
});

it('guard disabled → legacy cheapest-wins behaviour (buy 7.20, stock 53)', function (): void {
    config(['supplier.cost_outlier_guard.enabled' => false]);

    $map = makeOutlierSyncCommand()->buildBestOfferMap(outlierYealinkRows());

    expect($map['1301222']['cost_outlier'])->toBeNull()
        ->and($map['1301222']['buy'])->toBe('7.20')
        ->and($map['1301222']['stock'])->toBe(53);
});

it('does not fire on the harmless 3-supplier key (cheap price agreed)', function (): void {
    config(['supplier.cost_outlier_guard.enabled' => true]);

    $map = makeOutlierSyncCommand()->buildBestOfferMap([
        ['mpn' => 'CAB-1', 'suppliersku' => 'n-1', 'supplierid' => 'NIMANS', 'supplier_name' => 'Nimans', 'price' => '5.00', 'stock' => '10'],
        ['mpn' => 'CAB-1', 'suppliersku' => 'x-1', 'supplierid' => 'X', 'supplier_name' => 'X Ltd', 'price' => '5.50', 'stock' => '10'],
        ['mpn' => 'CAB-1', 'suppliersku' => 'l-1', 'supplierid' => 'LINDYCOLL', 'supplier_name' => 'Lindy', 'price' => '50.00', 'stock' => '10'],
    ]);

    expect($map['cab-1']['cost_outlier'])->toBeNull()
        ->and($map['cab-1']['buy'])->toBe('5.00')
        ->and($map['cab-1']['stock'])->toBe(30);
});

it('uses each supplier\'s MIN price when a supplier has two rows for one key', function (): void {
    config(['supplier.cost_outlier_guard.enabled' => true]);

    // NIMANS has 80.00 and 88.20 → min 80.00; MIDWICH 30.00 is NOT < 80/3 (26.67).
    $map = makeOutlierSyncCommand()->buildBestOfferMap([
        ['mpn' => 'K1', 'suppliersku' => 'a', 'supplierid' => 'MIDWICH', 'supplier_name' => 'Midwich', 'price' => '30.00', 'stock' => '1'],
        ['mpn' => 'K1', 'suppliersku' => 'b', 'supplierid' => 'NIMANS', 'supplier_name' => 'Nimans', 'price' => '88.20', 'stock' => '1'],
        ['mpn' => 'K1', 'suppliersku' => 'c', 'supplierid' => 'NIMANS', 'supplier_name' => 'Nimans', 'price' => '80.00', 'stock' => '1'],
    ]);

    expect($map['k1']['cost_outlier'])->toBeNull();

    // MIDWICH 26.00 IS < 80/3 → fires with median 80.00 (Nimans' min, not 88.20).
    $map = makeOutlierSyncCommand()->buildBestOfferMap([
        ['mpn' => 'K2', 'suppliersku' => 'a', 'supplierid' => 'MIDWICH', 'supplier_name' => 'Midwich', 'price' => '26.00', 'stock' => '1'],
        ['mpn' => 'K2', 'suppliersku' => 'b', 'supplierid' => 'NIMANS', 'supplier_name' => 'Nimans', 'price' => '88.20', 'stock' => '1'],
        ['mpn' => 'K2', 'suppliersku' => 'c', 'supplierid' => 'NIMANS', 'supplier_name' => 'Nimans', 'price' => '80.00', 'stock' => '1'],
    ]);

    expect($map['k2']['cost_outlier'])->not->toBeNull()
        ->and($map['k2']['cost_outlier']['median_of_others'])->toBe(80.0);
});

it('resolveBuyForProduct holds the existing buy_price and writes ONE issue with evidence', function (): void {
    config(['supplier.cost_outlier_guard.enabled' => true]);
    $p = seedOutlierProduct();
    $cmd = makeOutlierSyncCommand();
    $row = $cmd->buildBestOfferMap(outlierYealinkRows())['1301222'];
    $cid = (string) Str::uuid();

    $buy = $cmd->resolveBuyForProduct($p, '1301222', $row, $cid, dryRun: false);

    expect((float) $buy)->toBe(88.2);

    $issues = ImportIssue::where('issue_type', ImportIssue::SUPPLIER_COST_OUTLIER)->get();
    expect($issues)->toHaveCount(1);
    $issue = $issues->first();
    expect($issue->sku)->toBe('1301222')
        ->and((int) $issue->woo_product_id)->toBe(5969)
        ->and($issue->resolved_at)->toBeNull()
        ->and($issue->correlation_id)->toBe($cid)
        ->and($issue->notes)->toContain('1301222')
        ->and($issue->notes)->toContain('Midwich')
        ->and($issue->notes)->toContain('MIDWICH')
        ->and($issue->notes)->toContain('7.20')
        ->and($issue->notes)->toContain('88.20')
        ->and($issue->notes)->toContain('supplier:block-offer');

    $firstSeen = $issue->last_seen_at;
    $this->travel(5)->minutes();

    $cmd->resolveBuyForProduct($p, '1301222', $row, (string) Str::uuid(), dryRun: false);

    expect(ImportIssue::where('issue_type', ImportIssue::SUPPLIER_COST_OUTLIER)->count())->toBe(1)
        ->and(ImportIssue::first()->last_seen_at->gt($firstSeen))->toBeTrue();
});

it('resolveBuyForProduct keeps a NULL buy_price null but still writes the issue', function (): void {
    config(['supplier.cost_outlier_guard.enabled' => true]);
    $p = seedOutlierProduct(['buy_price' => null]);
    $cmd = makeOutlierSyncCommand();
    $row = $cmd->buildBestOfferMap(outlierYealinkRows())['1301222'];

    expect($cmd->resolveBuyForProduct($p, '1301222', $row, (string) Str::uuid(), dryRun: false))->toBeNull()
        ->and(ImportIssue::where('issue_type', ImportIssue::SUPPLIER_COST_OUTLIER)->count())->toBe(1);
});

it('resolveBuyForProduct notes an already-contaminated cost', function (): void {
    config(['supplier.cost_outlier_guard.enabled' => true]);
    $p = seedOutlierProduct(['buy_price' => '7.2000']);
    $cmd = makeOutlierSyncCommand();
    $row = $cmd->buildBestOfferMap(outlierYealinkRows())['1301222'];

    $cmd->resolveBuyForProduct($p, '1301222', $row, (string) Str::uuid(), dryRun: false);

    expect(ImportIssue::first()->notes)->toContain('already equals the outlier');
});

it('resolveBuyForProduct in dry-run returns the existing buy and writes nothing', function (): void {
    config(['supplier.cost_outlier_guard.enabled' => true]);
    $p = seedOutlierProduct();
    $cmd = makeOutlierSyncCommand();
    $row = $cmd->buildBestOfferMap(outlierYealinkRows())['1301222'];

    expect((float) $cmd->resolveBuyForProduct($p, '1301222', $row, (string) Str::uuid(), dryRun: true))->toBe(88.2)
        ->and(ImportIssue::count())->toBe(0);
});

it('resolveBuyForProduct holds even for auto-update carve-out products (undercut ignores those flags)', function (): void {
    config(['supplier.cost_outlier_guard.enabled' => true]);
    $p = seedOutlierProduct(['exclude_from_auto_update' => true]);
    $cmd = makeOutlierSyncCommand();
    $row = $cmd->buildBestOfferMap(outlierYealinkRows())['1301222'];

    expect((float) $cmd->resolveBuyForProduct($p, '1301222', $row, (string) Str::uuid(), dryRun: false))->toBe(88.2)
        ->and(ImportIssue::count())->toBe(1);
});

it('resolveBuyForProduct returns the row buy for a non-outlier row and writes nothing', function (): void {
    $p = seedOutlierProduct();
    $row = ['buy' => '90.00', 'stock' => 3, 'supplier' => 'Nimans', 'in_stock' => true, 'matched_via' => 'mpn', 'cost_outlier' => null];

    expect(makeOutlierSyncCommand()->resolveBuyForProduct($p, '1301222', $row, (string) Str::uuid(), dryRun: false))->toBe('90.00')
        ->and(ImportIssue::count())->toBe(0);
});

it('compares like with like: an out-of-stock expensive supplier does not flag a cheap in-stock one (MUYHSMFFADW)', function (): void {
    config(['supplier.cost_outlier_guard.enabled' => true]);

    $map = makeOutlierSyncCommand()->buildBestOfferMap([
        ['mpn' => 'PART-X', 'suppliersku' => 'ING-1', 'supplierid' => '1', 'supplier_name' => 'Ingram', 'price' => '0.42', 'stock' => '15'],
        ['mpn' => 'PART-X', 'suppliersku' => 'WC-9', 'supplierid' => '2', 'supplier_name' => 'Westcoast', 'price' => '4.78', 'stock' => '0'],
    ]);

    expect($map['part-x']['cost_outlier'])->toBeNull()
        ->and($map['part-x']['buy'])->toBe('0.42')
        ->and($map['part-x']['stock'])->toBe(15);
});

it('on the nothing-in-stock fallback the pool is every supplier (still fires)', function (): void {
    config(['supplier.cost_outlier_guard.enabled' => true]);

    $rows = array_map(static fn (array $r): array => ['stock' => '0'] + $r, outlierYealinkRows());
    $map = makeOutlierSyncCommand()->buildBestOfferMap($rows);

    expect($map['1301222']['in_stock'])->toBeFalse()
        ->and($map['1301222']['cost_outlier'])->not->toBeNull()
        ->and($map['1301222']['stock'])->toBe(0);
});
