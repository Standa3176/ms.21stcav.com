<?php

declare(strict_types=1);

use App\Domain\Integrations\Services\IntegrationCredentialResolver;
use App\Domain\Sync\Commands\SupplierDbSyncCommand;

/**
 * Quick task 260504-m5w Tests.
 *
 * Helper-method coverage only. The mysqli connection path is exercised by the
 * live verification step (see 260504-m5w-SUMMARY.md) — fragile to mock and the
 * runtime contract is the same one TestIntegrationAction::testSupplierDb
 * already proves end-to-end on every operator-triggered "Test connection".
 */
function makeSupplierDbSyncCommand(): SupplierDbSyncCommand
{
    return new SupplierDbSyncCommand(app(IntegrationCredentialResolver::class));
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

it('buildSkuMap preserves the FIRST row per key — caller pre-sorts by updated_at DESC', function (): void {
    $cmd = makeSupplierDbSyncCommand();

    // Two rows with the SAME mpn — first is "newer" by caller contract (ORDER
    // BY updated_at DESC). buildSkuMap must keep the first (newer) one.
    $map = $cmd->buildSkuMap([
        ['id' => 1, 'mpn' => 'WIDGET-A', 'suppliersku' => 'SUP-1', 'price' => '10.00', 'stock' => '5',  'updated_at' => '2026-05-04 10:00:00'],
        ['id' => 2, 'mpn' => 'WIDGET-A', 'suppliersku' => 'SUP-2', 'price' => '99.99', 'stock' => '0',  'updated_at' => '2026-01-01 09:00:00'],
    ]);

    expect($map['widget-a']['id'])->toBe(1);
    expect($map['widget-a']['price'])->toBe('10.00');
    expect($map['widget-a']['matched_via'])->toBe('mpn');
});

it('buildSkuMap registers BOTH the mpn and suppliersku key for the same row', function (): void {
    $cmd = makeSupplierDbSyncCommand();

    // One row, two distinct identifying columns. Both keys must point at the
    // same row data, but the matched_via tag differs so callers can tell
    // which path the lookup took.
    $map = $cmd->buildSkuMap([
        ['id' => 7, 'mpn' => 'MPN-X', 'suppliersku' => 'SUP-X', 'price' => '40.00', 'stock' => '12', 'updated_at' => '2026-05-04 12:00:00'],
    ]);

    expect($map)->toHaveKey('mpn-x');
    expect($map)->toHaveKey('sup-x');
    expect($map['mpn-x']['matched_via'])->toBe('mpn');
    expect($map['sup-x']['matched_via'])->toBe('suppliersku');
    // Same row data on both lookups.
    expect($map['mpn-x']['id'])->toBe(7);
    expect($map['sup-x']['id'])->toBe(7);
});

it('buildSkuMap skips rows where both mpn and suppliersku are empty', function (): void {
    $cmd = makeSupplierDbSyncCommand();

    $map = $cmd->buildSkuMap([
        ['id' => 1, 'mpn' => '',       'suppliersku' => '',      'price' => '5.00',  'stock' => '1', 'updated_at' => '2026-05-04 10:00:00'],
        ['id' => 2, 'mpn' => 'WIDGET', 'suppliersku' => '',      'price' => '6.00',  'stock' => '2', 'updated_at' => '2026-05-04 10:00:00'],
        ['id' => 3, 'mpn' => '',       'suppliersku' => 'SUP-1', 'price' => '7.00',  'stock' => '3', 'updated_at' => '2026-05-04 10:00:00'],
    ]);

    expect($map)->toHaveCount(2);
    expect($map)->toHaveKey('widget');
    expect($map)->toHaveKey('sup-1');
});

it('buildSkuMap normalises keys via lowercase + trim', function (): void {
    $cmd = makeSupplierDbSyncCommand();

    $map = $cmd->buildSkuMap([
        ['id' => 1, 'mpn' => '  WIDGET-A  ', 'suppliersku' => 'sup-1', 'price' => '10.00', 'stock' => '5', 'updated_at' => '2026-05-04 10:00:00'],
    ]);

    expect($map)->toHaveKey('widget-a');
    expect($map)->not->toHaveKey('  WIDGET-A  ');
    expect($map['widget-a']['id'])->toBe(1);
});
