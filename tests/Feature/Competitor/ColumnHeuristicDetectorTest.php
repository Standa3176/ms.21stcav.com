<?php

declare(strict_types=1);

use App\Domain\Competitor\Services\ColumnHeuristicDetector;

/*
|--------------------------------------------------------------------------
| Phase 5 Plan 02 Task 1 — ColumnHeuristicDetector (COMP-02 + D-03/D-04)
|--------------------------------------------------------------------------
|
| Picks the FIRST matching column per (sku, price) precedence list.
| Returns null on zero-matches -> triggers the quarantine flow (D-04).
*/

it('detects "Product Code" + "Price GBP" via precedence list', function (): void {
    $detected = (new ColumnHeuristicDetector())->detect(['Product Code', 'Price GBP', 'Stock']);

    expect($detected)->toMatchArray([
        'sku_column_index' => 0,
        'price_column_index' => 1,
    ]);
});

it('returns null for headers with zero SKU and zero price candidates', function (): void {
    expect((new ColumnHeuristicDetector())->detect(['foo', 'bar', 'baz']))->toBeNull();
});

it('picks the first matching SKU candidate in precedence order', function (): void {
    // "sku" has higher precedence than "mpn"; both match, sku wins at index 0.
    $detected = (new ColumnHeuristicDetector())->detect(['sku', 'mpn', 'price']);

    expect($detected)->toMatchArray([
        'sku_column_index' => 0,
        'price_column_index' => 2,
    ]);
});

it('is case + whitespace insensitive', function (): void {
    $detected = (new ColumnHeuristicDetector())->detect(['  SKU  ', '  Price  ']);

    expect($detected)->toMatchArray([
        'sku_column_index' => 0,
        'price_column_index' => 1,
    ]);
});

it('matches mpn when sku header is absent', function (): void {
    $detected = (new ColumnHeuristicDetector())->detect(['mpn', 'rrp']);

    expect($detected)->toMatchArray([
        'sku_column_index' => 0,
        'price_column_index' => 1,
    ]);
});

it('returns null when only a price candidate matches (sku absent)', function (): void {
    expect((new ColumnHeuristicDetector())->detect(['brand', 'price', 'stock']))->toBeNull();
});

it('returns null when only a sku candidate matches (price absent)', function (): void {
    expect((new ColumnHeuristicDetector())->detect(['sku', 'brand', 'stock']))->toBeNull();
});
