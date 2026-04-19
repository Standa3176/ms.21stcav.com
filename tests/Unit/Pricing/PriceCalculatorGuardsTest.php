<?php

declare(strict_types=1);

use App\Domain\Pricing\Exceptions\SupplierPriceUnusableException;
use App\Domain\Pricing\Services\PriceCalculator;

/*
|--------------------------------------------------------------------------
| PriceCalculator guard clauses (D-10 zero-price handling).
|--------------------------------------------------------------------------
|
| Zero and negative supplier prices throw SupplierPriceUnusableException so
| the listener can log an ImportIssue and leave products.sell_price alone.
| Zero margin is explicitly legal (supplier list-price resale is a legit
| scenario e.g. MAP-enforced lines).
*/

it('throws SupplierPriceUnusableException for zero supplier price', function (): void {
    $calc = new PriceCalculator;

    expect(fn () => $calc->compute(0, 2200))
        ->toThrow(SupplierPriceUnusableException::class, 'must be > 0');
});

it('throws SupplierPriceUnusableException for negative supplier price', function (): void {
    $calc = new PriceCalculator;

    expect(fn () => $calc->compute(-100, 2200))
        ->toThrow(SupplierPriceUnusableException::class, 'must be > 0');
});

it('allows zero margin — list-price resale is a legal scenario', function (): void {
    $calc = new PriceCalculator;

    // 10000 px × 1.0 × 1.2 = 12000 px exactly, no rounding drift
    expect($calc->compute(10000, 0))->toBe(12000);
});

it('allows negative margin — loss-leader promotion is a v2 use case but schema supports it', function (): void {
    $calc = new PriceCalculator;

    // 10000 px × 0.9 × 1.2 = 10800 — -10% margin still computes correctly
    expect($calc->compute(10000, -1000))->toBe(10800);
});

it('returns strictly positive integer for all realistic inputs', function (): void {
    $calc = new PriceCalculator;

    $result = $calc->compute(100, 2200);
    expect($result)->toBeInt()->toBeGreaterThan(0);

    $result2 = $calc->compute(1_000_000, 5000); // £10k supplier, 50% margin — top of realistic range
    expect($result2)->toBeInt()->toBeGreaterThan(0);
});
