<?php

declare(strict_types=1);

use App\Domain\Sync\Services\LiveSupplierStockResolver;

/*
|--------------------------------------------------------------------------
| Quick task 260702-pes — LiveSupplierStockResolver pure-surface unit tests
|--------------------------------------------------------------------------
|
| Only the PURE methods are exercised here (no supplier_db connection):
|   - pickCheapestInStock — cheapest offer with resolved stock > 0.
|   - buildOfferSql — parameterized SQL shape (trait fragments + placeholders).
|
| The resolver is resolved via app() — its two constructor deps
| (IntegrationCredentialResolver + SupplierFreshnessResolver) are never touched
| by the pure methods, so container construction is safe + dependency-free.
*/

it('pickCheapestInStock: the real 43376 three-row case yields qty 64 @ 76.92', function (): void {
    $resolver = app(LiveSupplierStockResolver::class);

    // SKU 43376 (Lindy) — three feed rows: stock 0/64/0 at £71.36/76.92/79.20.
    // Only the middle row has stock, so 64 @ 76.92 wins despite not being cheapest.
    $rows = [
        ['price' => '71.36', 'stock' => '0'],
        ['price' => '76.92', 'stock' => '64'],
        ['price' => '79.20', 'stock' => '0'],
    ];

    expect($resolver->pickCheapestInStock($rows))->toBe([
        'stock_quantity' => 64,
        'stock_status' => 'instock',
        'buy_price' => 76.92,
    ]);
});

it('pickCheapestInStock: cheapest in-stock offer wins across multiple in-stock rows', function (): void {
    $resolver = app(LiveSupplierStockResolver::class);

    $rows = [
        ['price' => '50', 'stock' => '2'],
        ['price' => '40.00', 'stock' => '5'],
    ];

    expect($resolver->pickCheapestInStock($rows))->toBe([
        'stock_quantity' => 5,
        'stock_status' => 'instock',
        'buy_price' => 40.0,
    ]);
});

it('pickCheapestInStock: all-zero stock returns null', function (): void {
    $resolver = app(LiveSupplierStockResolver::class);

    $rows = [
        ['price' => '10.00', 'stock' => '0'],
        ['price' => '20.00', 'stock' => '0'],
    ];

    expect($resolver->pickCheapestInStock($rows))->toBeNull();
});

it('pickCheapestInStock: empty rows return null', function (): void {
    $resolver = app(LiveSupplierStockResolver::class);

    expect($resolver->pickCheapestInStock([]))->toBeNull();
});

it('buildOfferSql: emits the trait fragments, WHERE guards and exactly 5 placeholders', function (): void {
    $resolver = app(LiveSupplierStockResolver::class);

    $sql = $resolver->buildOfferSql(3);

    expect($sql)->toContain('LEFT JOIN stockseparate');
    expect($sql)->toContain('is_stock_separate = 1');
    expect($sql)->toContain('fp.product_excluded = 0');
    expect($sql)->toContain('fp.supplierid IN (?,?,?)');
    expect($sql)->toContain('LOWER(TRIM(fp.mpn)) = ?');
    expect($sql)->toContain('LOWER(TRIM(fp.suppliersku)) = ?');

    // 3 fresh-supplier placeholders + 2 match placeholders = 5 total.
    expect(substr_count($sql, '?'))->toBe(5);
});
