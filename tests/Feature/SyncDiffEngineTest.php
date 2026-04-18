<?php

declare(strict_types=1);

use App\Domain\Sync\Services\SyncDiffEngine;

function skuRowSimple(array $overrides = []): array
{
    return array_merge([
        'type' => 'simple',
        'sku' => 'SIMPLE-1',
        'woo_product_id' => 1234,
        'woo_variation_id' => null,
        'price' => '100.00',
        'stock_quantity' => 5,
        'is_custom_ms' => false,
        'exclude_from_auto_update' => false,
    ], $overrides);
}

// -----------------------------------------------------------------------------
// D1: exact match returns null (no-op)
// -----------------------------------------------------------------------------
test('D1: exact supplier match returns null (no-op)', function () {
    $engine = new SyncDiffEngine();

    $diff = $engine->diff(
        skuRowSimple(['price' => '100.00', 'stock_quantity' => 5]),
        ['price' => '100.00', 'stock' => 5],
    );

    expect($diff)->toBeNull();
});

// -----------------------------------------------------------------------------
// D2: price-only diff
// -----------------------------------------------------------------------------
test('D2: price-only change produces updated action with regular_price payload', function () {
    $engine = new SyncDiffEngine();

    $diff = $engine->diff(
        skuRowSimple(['price' => '180.00', 'stock_quantity' => 5]),
        ['price' => '199.00', 'stock' => 5],
    );

    expect($diff)->not->toBeNull()
        ->and($diff['action'])->toBe('updated')
        ->and($diff['endpoint'])->toBe('products/1234')
        ->and($diff['payload'])->toBe(['regular_price' => '199.00'])
        ->and($diff['old_price'])->toBe('180.00')
        ->and($diff['new_price'])->toBe('199.00')
        ->and($diff['old_stock'])->toBe(5)
        ->and($diff['new_stock'])->toBe(5);
});

// -----------------------------------------------------------------------------
// D3: stock-only diff
// -----------------------------------------------------------------------------
test('D3: stock-only change produces updated action with stock_quantity payload', function () {
    $engine = new SyncDiffEngine();

    $diff = $engine->diff(
        skuRowSimple(['price' => '100.00', 'stock_quantity' => 5]),
        ['price' => '100.00', 'stock' => 2],
    );

    expect($diff['action'])->toBe('updated')
        ->and($diff['payload'])->toBe(['stock_quantity' => 2])
        ->and($diff['old_stock'])->toBe(5)
        ->and($diff['new_stock'])->toBe(2);
});

// -----------------------------------------------------------------------------
// D4: both price + stock diff
// -----------------------------------------------------------------------------
test('D4: both price and stock change — payload has both keys', function () {
    $engine = new SyncDiffEngine();

    $diff = $engine->diff(
        skuRowSimple(['price' => '100.00', 'stock_quantity' => 5]),
        ['price' => '120.00', 'stock' => 10],
    );

    expect($diff['action'])->toBe('updated')
        ->and($diff['payload'])->toHaveKeys(['regular_price', 'stock_quantity'])
        ->and($diff['payload']['regular_price'])->toBe('120.00')
        ->and($diff['payload']['stock_quantity'])->toBe(10);
});

// -----------------------------------------------------------------------------
// D5: endpoint varies by type (simple vs variation)
// -----------------------------------------------------------------------------
test('D5: endpoint is products/{id}/variations/{vid} for variations', function () {
    $engine = new SyncDiffEngine();

    $variationRow = [
        'type' => 'variation',
        'sku' => 'VAR-1',
        'woo_product_id' => 1000,
        'woo_variation_id' => 2000,
        'price' => '10.00',
        'stock_quantity' => 0,
        'is_custom_ms' => false,
        'exclude_from_auto_update' => false,
    ];

    $diff = $engine->diff($variationRow, ['price' => '15.00', 'stock' => 0]);

    expect($diff['endpoint'])->toBe('products/1000/variations/2000');
});

// -----------------------------------------------------------------------------
// D6: exclude_from_auto_update returns skipped regardless of diff (SYNC-07)
// -----------------------------------------------------------------------------
test('D6: exclude_from_auto_update returns skipped action regardless of price/stock diff', function () {
    $engine = new SyncDiffEngine();

    $diff = $engine->diff(
        skuRowSimple(['exclude_from_auto_update' => true, 'price' => '100.00', 'stock_quantity' => 5]),
        ['price' => '999.99', 'stock' => 999],  // big diff shouldn't matter
    );

    expect($diff['action'])->toBe('skipped')
        ->and($diff['reason'])->toBe('exclude_from_auto_update')
        ->and($diff['payload'])->toBe([]);
});

// -----------------------------------------------------------------------------
// D7: supplier row missing (null) → null return (MarkMissingSkusJob handles)
// -----------------------------------------------------------------------------
test('D7: null supplier row returns null (delegated to MarkMissingSkusJob)', function () {
    $engine = new SyncDiffEngine();

    $diff = $engine->diff(skuRowSimple(), null);

    expect($diff)->toBeNull();
});

// -----------------------------------------------------------------------------
// D8: 2dp normalisation — trailing zeros stripped for comparison
// -----------------------------------------------------------------------------
test('D8: price comparison treats 199, 199.0, 199.00 as equal', function () {
    $engine = new SyncDiffEngine();

    // Same numeric value, different string representations → no diff.
    $variants = [
        ['199', '199.00'],
        ['199.0', '199.00'],
        ['199.00', '199'],
        ['199.00', '199.0'],
    ];

    foreach ($variants as [$oldStr, $newStr]) {
        $diff = $engine->diff(
            skuRowSimple(['price' => $oldStr, 'stock_quantity' => 1]),
            ['price' => $newStr, 'stock' => 1],
        );
        expect($diff)->toBeNull("Expected null for {$oldStr} <=> {$newStr}");
    }

    // Sanity: an actual difference still triggers an update.
    $diff = $engine->diff(
        skuRowSimple(['price' => '199.00', 'stock_quantity' => 1]),
        ['price' => '199.50', 'stock' => 1],
    );
    expect($diff)->not->toBeNull();
});
