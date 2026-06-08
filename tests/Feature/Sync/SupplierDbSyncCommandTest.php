<?php

declare(strict_types=1);

use App\Domain\Integrations\Services\IntegrationCredentialResolver;
use App\Domain\Products\Models\Product;
use App\Domain\Sync\Commands\SupplierDbSyncCommand;
use App\Domain\Sync\Services\SupplierFreshnessResolver;

/**
 * Quick task 260504-m5w Tests.
 *
 * Helper-method coverage only. The mysqli connection path is exercised by the
 * live verification step (see 260504-m5w-SUMMARY.md) — fragile to mock and the
 * runtime contract is the same one TestIntegrationAction::testSupplierDb
 * already proves end-to-end on every operator-triggered "Test connection".
 *
 * Quick task 260608-g8x — factory now passes the SupplierFreshnessResolver
 * (newly-required constructor arg). Helper-method tests do NOT seed any
 * supplier_offer_snapshots → resolver classifies every supplier_id as
 * 'unknown' → stale list is empty → the new pre-filter in buildBestOfferMap
 * is a no-op, preserving the existing tests' golden output byte-for-byte.
 */
function makeSupplierDbSyncCommand(): SupplierDbSyncCommand
{
    return new SupplierDbSyncCommand(
        app(IntegrationCredentialResolver::class),
        app(SupplierFreshnessResolver::class),
    );
}

it('parsePrice handles null, empty, plain numeric, currency-prefixed, and comma-separated input', function (): void {
    $cmd = makeSupplierDbSyncCommand();

    expect($cmd->parsePrice(null))->toBeNull();
    expect($cmd->parsePrice(''))->toBeNull();
    expect($cmd->parsePrice('   '))->toBeNull();
    expect($cmd->parsePrice('12.34'))->toBe('12.34');
    expect($cmd->parsePrice('£12.34'))->toBe('12.34');
    expect($cmd->parsePrice('$99'))->toBe('99');
    expect($cmd->parsePrice('1,234.56'))->toBe('1234.56');
    expect($cmd->parsePrice('abc'))->toBeNull();
    expect($cmd->parsePrice('£0.00'))->toBe('0.00');
});

it('parseStock handles null, empty, plain integer, "n/a", malformed, and negative input', function (): void {
    $cmd = makeSupplierDbSyncCommand();

    expect($cmd->parseStock(null))->toBeNull();
    expect($cmd->parseStock(''))->toBeNull();
    expect($cmd->parseStock('   '))->toBeNull();
    expect($cmd->parseStock('0'))->toBe(0);
    expect($cmd->parseStock('230'))->toBe(230);
    expect($cmd->parseStock('n/a'))->toBeNull();
    expect($cmd->parseStock('abc'))->toBeNull();
    expect($cmd->parseStock('-5'))->toBe(-5);
    // Whitespace-padded numerics still parse.
    expect($cmd->parseStock(' 42 '))->toBe(42);
});

it('buildBestOfferMap picks the cheapest IN-STOCK supplier (the MUYHSMFFADW case)', function (): void {
    $cmd = makeSupplierDbSyncCommand();

    // Same mpn at two suppliers: Ingram 42p in stock vs Westcoast £4.78 no stock.
    // We can only buy what's in stock at the cheapest — Ingram 42p.
    $map = $cmd->buildBestOfferMap([
        ['mpn' => 'PART-X', 'suppliersku' => 'ING-1', 'supplierid' => '1', 'supplier_name' => 'Ingram',    'price' => '0.42', 'stock' => '15'],
        ['mpn' => 'PART-X', 'suppliersku' => 'WC-9',  'supplierid' => '2', 'supplier_name' => 'Westcoast', 'price' => '4.78', 'stock' => '0'],
    ]);

    expect($map['part-x']['buy'])->toBe('0.42')
        ->and($map['part-x']['supplier'])->toBe('Ingram')
        ->and($map['part-x']['in_stock'])->toBeTrue()
        ->and($map['part-x']['stock'])->toBe(15);
});

it('buildBestOfferMap falls back to cheapest overall when nothing is in stock', function (): void {
    $cmd = makeSupplierDbSyncCommand();

    $map = $cmd->buildBestOfferMap([
        ['mpn' => 'OOS', 'suppliersku' => 'A', 'supplier_name' => 'SupA', 'price' => '9.00', 'stock' => '0'],
        ['mpn' => 'OOS', 'suppliersku' => 'B', 'supplier_name' => 'SupB', 'price' => '7.50', 'stock' => '0'],
    ]);

    expect($map['oos']['buy'])->toBe('7.50')
        ->and($map['oos']['supplier'])->toBe('SupB')
        ->and($map['oos']['in_stock'])->toBeFalse()
        ->and($map['oos']['stock'])->toBe(0);
});

it('buildBestOfferMap ignores a cheaper out-of-stock offer + sums in-stock units', function (): void {
    $cmd = makeSupplierDbSyncCommand();

    $map = $cmd->buildBestOfferMap([
        ['mpn' => 'M', 'suppliersku' => 'S1', 'supplier_name' => 'A', 'price' => '5.00', 'stock' => '10'],
        ['mpn' => 'M', 'suppliersku' => 'S2', 'supplier_name' => 'B', 'price' => '6.00', 'stock' => '4'],
        ['mpn' => 'M', 'suppliersku' => 'S3', 'supplier_name' => 'C', 'price' => '4.00', 'stock' => '0'], // cheapest but OOS → ignored
    ]);

    expect($map['m']['buy'])->toBe('5.00')      // cheapest in-stock, not the £4.00 OOS
        ->and($map['m']['supplier'])->toBe('A')
        ->and($map['m']['stock'])->toBe(14);    // 10 + 4 (in-stock only)
});

it('buildBestOfferMap registers BOTH the mpn and suppliersku key for the same offer', function (): void {
    $cmd = makeSupplierDbSyncCommand();

    $map = $cmd->buildBestOfferMap([
        ['mpn' => 'MPN-X', 'suppliersku' => 'SUP-X', 'supplier_name' => 'A', 'price' => '40.00', 'stock' => '12'],
    ]);

    expect($map)->toHaveKey('mpn-x')->toHaveKey('sup-x')
        ->and($map['mpn-x']['matched_via'])->toBe('mpn')
        ->and($map['sup-x']['matched_via'])->toBe('suppliersku')
        ->and($map['mpn-x']['buy'])->toBe('40.00')
        ->and($map['sup-x']['buy'])->toBe('40.00');
});

it('buildBestOfferMap skips empty-key rows + normalises keys via lowercase + trim', function (): void {
    $cmd = makeSupplierDbSyncCommand();

    $map = $cmd->buildBestOfferMap([
        ['mpn' => '',           'suppliersku' => '',      'supplier_name' => 'A', 'price' => '5.00', 'stock' => '1'],
        ['mpn' => '  WIDGET  ', 'suppliersku' => '',      'supplier_name' => 'B', 'price' => '6.00', 'stock' => '2'],
        ['mpn' => '',           'suppliersku' => 'SUP-1', 'supplier_name' => 'C', 'price' => '7.00', 'stock' => '3'],
    ]);

    expect($map)->toHaveCount(2)
        ->and($map)->toHaveKey('widget')
        ->and($map)->toHaveKey('sup-1')
        ->and($map)->not->toHaveKey('  WIDGET  ');
});

it('isObsoleteCandidate flags a published, non-custom product', function (): void {
    $cmd = makeSupplierDbSyncCommand();

    $p = new Product(['status' => 'publish', 'is_custom_ms' => false, 'exclude_from_auto_update' => false, 'tags' => []]);

    expect($cmd->isObsoleteCandidate($p))->toBeTrue();
});

it('isObsoleteCandidate skips non-published / custom-ms (field or tag) / excluded products', function (): void {
    $cmd = makeSupplierDbSyncCommand();

    expect($cmd->isObsoleteCandidate(new Product(['status' => 'pending', 'tags' => []])))->toBeFalse()
        ->and($cmd->isObsoleteCandidate(new Product(['status' => 'draft', 'tags' => []])))->toBeFalse()
        ->and($cmd->isObsoleteCandidate(new Product(['status' => 'publish', 'is_custom_ms' => true, 'tags' => []])))->toBeFalse()
        ->and($cmd->isObsoleteCandidate(new Product(['status' => 'publish', 'exclude_from_auto_update' => true, 'tags' => []])))->toBeFalse()
        ->and($cmd->isObsoleteCandidate(new Product(['status' => 'publish', 'tags' => ['custom-ms']])))->toBeFalse();
});
