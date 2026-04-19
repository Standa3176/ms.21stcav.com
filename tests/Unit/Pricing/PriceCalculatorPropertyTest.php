<?php

declare(strict_types=1);

use App\Domain\Pricing\Services\PriceCalculator;

/*
|--------------------------------------------------------------------------
| PriceCalculator property-based tests — rounding stability (Pitfall 5).
|--------------------------------------------------------------------------
|
| Determinism: same inputs ALWAYS produce same output (pure function
| guarantee). This is the anti-drift property that catches any future
| regression that leaks config state, reads a clock, or otherwise makes the
| calculator non-pure.
|
| Uses mt_srand(12345) for reproducibility; failures can be replayed
| identically on any machine.
*/

it('is a pure function — same inputs produce identical outputs over 1000 random pairs', function (): void {
    $calc = new PriceCalculator;

    mt_srand(12345);

    for ($i = 0; $i < 1000; $i++) {
        // Realistic supplier range: £0.50 .. £5000 (50px .. 500000px)
        $supplier = mt_rand(50, 500_000);
        // Realistic margin range: 0% .. 100% (0 .. 10000 bps)
        $margin = mt_rand(0, 10_000);

        $a = $calc->compute($supplier, $margin);
        $b = $calc->compute($supplier, $margin);

        expect($a)->toBe($b, sprintf(
            'Determinism broken at supplier=%dpx margin=%dbps: first=%d second=%d',
            $supplier, $margin, $a, $b,
        ));
        expect($a)->toBeInt()->toBeGreaterThan(0);
    }
});

it('honours the rounding mode from config/pricing.php — locked to PHP_ROUND_HALF_UP', function (): void {
    $calc = new PriceCalculator;

    // Default config locks PHP_ROUND_HALF_UP. Clean 50.25 px * 1 * 1 = 5025 (no drift).
    expect($calc->compute(5025, 0, 0))->toBe(5025);
    expect(config('pricing.rounding_mode'))->toBe(PHP_ROUND_HALF_UP);
});

it('reads rounding mode at call-time — no static caching', function (): void {
    $calc = new PriceCalculator;

    // supplier 125 px, 0% margin, 0% vat → numerator/denominator = 125 exactly (no rounding)
    expect($calc->compute(125, 0, 0))->toBe(125);

    // Flip rounding mode mid-test; calculator must honour it on the next call.
    // We pick an input where HALF_UP vs HALF_EVEN diverges.
    // supplier = 1, margin = 5000, vat = 0 → 1 * 15000 * 10000 / 100_000_000 = 1.5
    //   HALF_UP → 2, HALF_EVEN → 2 (even) — both same, bad choice.
    // Use 3 * 5000 = 4.5 → HALF_UP = 5, HALF_EVEN = 4.
    $a = $calc->compute(3, 5000, 0);  // HALF_UP default
    expect($a)->toBe(5);

    config()->set('pricing.rounding_mode', PHP_ROUND_HALF_EVEN);
    $b = $calc->compute(3, 5000, 0);
    expect($b)->toBe(4);

    // Reset so subsequent tests see the locked default.
    config()->set('pricing.rounding_mode', PHP_ROUND_HALF_UP);
});

it('handles large catalogue values without overflow — 2^63 headroom documented', function (): void {
    $calc = new PriceCalculator;

    // Top of realistic AV catalogue: £10k supplier, 100% margin, 20% VAT.
    // Numerator: 1_000_000 * 20000 * 12000 = 2.4e14 — well under 2^63 (9.22e18).
    $result = $calc->compute(1_000_000, 10_000, 2000);

    expect($result)->toBeInt()->toBeGreaterThan(0);
    // 1_000_000 * 2 * 1.2 = 2_400_000 px = £24,000 — sensible retail price.
    expect($result)->toBe(2_400_000);
});
