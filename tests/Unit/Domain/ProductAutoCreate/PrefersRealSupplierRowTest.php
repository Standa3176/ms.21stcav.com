<?php

declare(strict_types=1);

use App\Domain\ProductAutoCreate\Concerns\PrefersRealSupplierRow;

/*
|--------------------------------------------------------------------------
| Quick task 260630-c3q — PrefersRealSupplierRow trait coverage
|--------------------------------------------------------------------------
|
| Pure unit coverage for the shared supplier-row picker used by
| products:generate-drafts and products:assign-taxonomy. When a SKU
| matches multiple supplier_products rows (the real product + a
| warranty/add-on row sharing the MPN), the picker must source the REAL
| product row — preferring a manufacturer that resolves to a Woo brand,
| then in-stock — rather than the most-recent row (which was a regression
| that let "Protect Plus" warranty rows hijack HD226's title + brand).
|
| Selection order:
|   1. manufacturer resolves to a Woo brand (isBrand callback)
|   2. stock > 0
|   3. input order (caller passes ORDER BY updated_at DESC → most-recent
|      is the final tiebreak; strict > on score keeps the earliest index).
|
| The trait is exercised through an anonymous class that `use`s it and
| exposes pickBestSupplierRow() publicly. The $isBrand stub treats only
| 'brightsign' and 'lindy' (case-insensitive) as Woo brands — no live
| TaxonomyResolver / Woo traffic.
*/

/** @return object{pick: callable} A harness exposing the protected trait method publicly. */
function c3qHarness(): object
{
    return new class
    {
        use PrefersRealSupplierRow;

        /**
         * @param  array<int, array<string, mixed>>  $rows
         * @param  callable(string): bool  $isBrand
         * @return array<string, mixed>|null
         */
        public function pick(array $rows, callable $isBrand): ?array
        {
            return $this->pickBestSupplierRow($rows, $isBrand);
        }
    };
}

/** @return callable(string): bool */
function c3qIsBrand(): callable
{
    return static fn (string $m): bool => in_array(strtolower(trim($m)), ['brightsign', 'lindy'], true);
}

it('returns null when there are no rows', function (): void {
    expect(c3qHarness()->pick([], c3qIsBrand()))->toBeNull();
});

it('returns the single candidate row unchanged', function (): void {
    $row = ['manufacturer' => 'BrightSign', 'stock' => 118];

    expect(c3qHarness()->pick([$row], c3qIsBrand()))->toBe($row);
});

it('prefers the real BrightSign row over the Protect Plus warranty row (HD226 case)', function (): void {
    $rows = [
        ['manufacturer' => 'Protect Plus', 'stock' => 0],
        ['manufacturer' => 'BrightSign', 'stock' => 118],
    ];

    expect(c3qHarness()->pick($rows, c3qIsBrand()))
        ->toBe(['manufacturer' => 'BrightSign', 'stock' => 118]);
});

it('picks the brand-resolving row regardless of input order', function (): void {
    $rows = [
        ['manufacturer' => 'BrightSign', 'stock' => 118],
        ['manufacturer' => 'Protect Plus', 'stock' => 0],
    ];

    expect(c3qHarness()->pick($rows, c3qIsBrand()))
        ->toBe(['manufacturer' => 'BrightSign', 'stock' => 118]);
});

it('breaks a no-brand tie on stock (in-stock WarrantyCo over out-of-stock Protect Plus)', function (): void {
    $rows = [
        ['manufacturer' => 'Protect Plus', 'stock' => 0],
        ['manufacturer' => 'WarrantyCo', 'stock' => 5],
    ];

    expect(c3qHarness()->pick($rows, c3qIsBrand()))
        ->toBe(['manufacturer' => 'WarrantyCo', 'stock' => 5]);
});

it('breaks a brand tie on stock (in-stock Lindy over out-of-stock BrightSign)', function (): void {
    $rows = [
        ['manufacturer' => 'BrightSign', 'stock' => 0],
        ['manufacturer' => 'Lindy', 'stock' => 9],
    ];

    expect(c3qHarness()->pick($rows, c3qIsBrand()))
        ->toBe(['manufacturer' => 'Lindy', 'stock' => 9]);
});

it('keeps the first row when brand + stock are all equal (most-recent tiebreak)', function (): void {
    $rows = [
        ['manufacturer' => 'BrightSign', 'stock' => 5],
        ['manufacturer' => 'Lindy', 'stock' => 5],
    ];

    expect(c3qHarness()->pick($rows, c3qIsBrand()))
        ->toBe(['manufacturer' => 'BrightSign', 'stock' => 5]);
});
