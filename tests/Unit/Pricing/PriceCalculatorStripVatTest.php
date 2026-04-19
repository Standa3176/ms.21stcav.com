<?php

declare(strict_types=1);

use App\Domain\Pricing\Services\PriceCalculator;

/*
|--------------------------------------------------------------------------
| PriceCalculator::stripVat — D-05 helper.
|--------------------------------------------------------------------------
|
| Phase 5 competitor-CSV ingest imports this unchanged. Reversibility on
| clean multiples of 5p is the key property: stripVat(compute(x, 0, vat), vat)
| must round-trip exactly when the numbers align to VAT-friendly values.
|
| Non-reversibility on awkward pennies is expected and documented in Pitfall 5;
| any single-direction rounding loses at most 1p.
*/

it('strips VAT from a clean gross price', function (): void {
    $calc = new PriceCalculator;

    // 12000 px gross @ 20% VAT → 10000 px ex-VAT (round-trip of compute(10000, 0, 2000))
    expect($calc->stripVat(12000, 2000))->toBe(10000);
});

it('strips VAT from a gross price that produces a rounding edge', function (): void {
    $calc = new PriceCalculator;

    // 7188 px gross → 7188 * 10000 / 12000 = 5990 px ex-VAT (fixture-style value)
    expect($calc->stripVat(7188, 2000))->toBe(5990);
});

it('returns 0 for zero gross price (guard, matches D-10 spirit)', function (): void {
    $calc = new PriceCalculator;

    expect($calc->stripVat(0, 2000))->toBe(0);
});

it('returns 0 for negative gross price (defensive guard)', function (): void {
    $calc = new PriceCalculator;

    expect($calc->stripVat(-500, 2000))->toBe(0);
});

it('uses the default VAT rate when not specified', function (): void {
    $calc = new PriceCalculator;

    // Default vatBasisPoints = 2000 (20%) per the signature default.
    expect($calc->stripVat(12000))->toBe(10000);
});

it('handles non-20% VAT rates for forward compatibility', function (): void {
    $calc = new PriceCalculator;

    // 10500 px @ 5% VAT → 10500 * 10000 / 10500 = 10000
    expect($calc->stripVat(10500, 500))->toBe(10000);
});
