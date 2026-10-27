<?php

declare(strict_types=1);

use App\Domain\Sync\Services\SupplierCostOutlierGuard;

/*
|--------------------------------------------------------------------------
| Quick task 261027-duz — SupplierCostOutlierGuard (pure)
|--------------------------------------------------------------------------
|
| Incident 2026-10-27: Yealink T74U (local SKU 1301222) was costed at £7.20
| because Midwich's code 1301222 is an unrelated item; Nimans (the real item)
| quotes £88.20. The guard refuses a cheapest price that is < 1/ratio of the
| LOWER median of the other suppliers' prices.
*/

it('fires on the Yealink T74U collision (Midwich 7.20 vs Nimans 88.20)', function (): void {
    $result = SupplierCostOutlierGuard::detect(['MIDWICH' => 7.20, 'NIMANS' => 88.20], 'MIDWICH', 3.0);

    expect($result)->not->toBeNull()
        ->and($result['chosen_supplier_id'])->toBe('MIDWICH')
        ->and($result['chosen_price'])->toBe(7.20)
        ->and($result['median_of_others'])->toBe(88.20)
        ->and($result['ratio'])->toBe(3.0)
        ->and($result['others'])->toBe(['NIMANS' => 88.20]);
});

it('fires on the Epson CoverPlus pattern (lower median of 189/225 = 189)', function (): void {
    $result = SupplierCostOutlierGuard::detect(['MIDWICH' => 50.0, 'A' => 189.0, 'B' => 225.0], 'MIDWICH', 3.0);

    expect($result)->not->toBeNull()
        ->and($result['median_of_others'])->toBe(189.0);
});

it('fires on the Poly G7500 pattern (Tech Data 564.82 vs Ingram 3941.83)', function (): void {
    $result = SupplierCostOutlierGuard::detect(['TECHDATA' => 564.82, 'INGRAM' => 3941.83], 'TECHDATA', 3.0);

    expect($result)->not->toBeNull()
        ->and($result['median_of_others'])->toBe(3941.83);
});

it('does NOT fire when the cheap price is agreed and one expensive supplier is the odd one out (3 suppliers)', function (): void {
    expect(SupplierCostOutlierGuard::detect(['NIMANS' => 5.00, 'X' => 5.50, 'LINDYCOLL' => 50.00], 'NIMANS', 3.0))
        ->toBeNull();
});

it('does NOT fire for the harmless 4-supplier pattern', function (): void {
    expect(SupplierCostOutlierGuard::detect(['A' => 5.00, 'B' => 5.20, 'C' => 5.50, 'D' => 60.00], 'A', 3.0))
        ->toBeNull();
});

it('is inert for a single supplier', function (): void {
    expect(SupplierCostOutlierGuard::detect(['MIDWICH' => 7.20], 'MIDWICH', 3.0))->toBeNull();
});

it('is inert when the chosen supplier is absent from the map', function (): void {
    expect(SupplierCostOutlierGuard::detect(['NIMANS' => 88.20, 'X' => 90.0], 'MIDWICH', 3.0))->toBeNull();
});

it('is inert when ratio <= 1', function (): void {
    expect(SupplierCostOutlierGuard::detect(['MIDWICH' => 7.20, 'NIMANS' => 88.20], 'MIDWICH', 1.0))->toBeNull()
        ->and(SupplierCostOutlierGuard::detect(['MIDWICH' => 7.20, 'NIMANS' => 88.20], 'MIDWICH', 0.5))->toBeNull();
});

it('uses strict less-than at the boundary (chosen == median / ratio does not fire)', function (): void {
    expect(SupplierCostOutlierGuard::detect(['A' => 30.0, 'B' => 90.0], 'A', 3.0))->toBeNull()
        ->and(SupplierCostOutlierGuard::detect(['A' => 29.99, 'B' => 90.0], 'A', 3.0))->not->toBeNull();
});

it('ignores non-positive prices in the map', function (): void {
    // Others with <= 0 are dropped → no other supplier → inert.
    expect(SupplierCostOutlierGuard::detect(['A' => 7.20, 'B' => 0.0, 'C' => -5.0], 'A', 3.0))->toBeNull();

    // A zero-priced other is ignored and does not drag the median down.
    $result = SupplierCostOutlierGuard::detect(['A' => 7.20, 'B' => 0.0, 'C' => 88.20], 'A', 3.0);
    expect($result)->not->toBeNull()
        ->and($result['median_of_others'])->toBe(88.20)
        ->and($result['others'])->toBe(['C' => 88.20]);

    // A non-positive CHOSEN price → inert.
    expect(SupplierCostOutlierGuard::detect(['A' => 0.0, 'C' => 88.20], 'A', 3.0))->toBeNull();
});
