<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Services;

use App\Domain\Pricing\Exceptions\SupplierPriceUnusableException;

/**
 * Phase 3 Plan 01 — the Phase 3 SHIP GATE (PRCE-06).
 *
 * Pure integer-pennies VAT-inclusive retail calculator. NO Eloquent, NO events,
 * NO logging, NO state — this class exists to be pinned by the golden-fixture
 * parity test in tests/Unit/Pricing/PriceCalculatorGoldenFixtureTest.php. Any
 * drift by a single penny fails the build.
 *
 * Implementation guarantees (D-01..D-05, Pitfall 5):
 *   - All arithmetic in integer pennies + basis points
 *   - Exactly ONE round() per public method, at the return boundary
 *   - Rounding mode read from config('pricing.rounding_mode') at call-time
 *     (no static caching — a config()->set() override takes effect immediately)
 *   - NO float intermediates, NO BCMath (native int arithmetic has enough
 *     headroom: top realistic input 1_000_000 × 20000 × 12000 = 2.4e14,
 *     well under 2^63 ≈ 9.22e18)
 *   - Zero/negative supplier price → SupplierPriceUnusableException (D-10 guard)
 *
 * Formula (D-03):
 *   final_pennies = round(supplier_pennies × (1 + margin/100) × (1 + vat/100), 0)
 *
 * In integer form:
 *   numerator   = supplier × (10000 + margin_bps) × (10000 + vat_bps)
 *   denominator = 100_000_000
 *   final       = round(numerator / denominator, 0, mode)
 *
 * The class is stateless and safe to instantiate anywhere (`new PriceCalculator()`)
 * or resolve from the container. No constructor dependencies.
 *
 * @see config/pricing.php — rounding_mode + vat_basis_points locked here
 * @see tests/Fixtures/Pricing/golden-fixtures.json — 50-triple ship gate
 */
final class PriceCalculator
{
    // ══════════════════════════════════════════════════════════════════════════
    // compute — the Phase 3 ship gate entry point
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Compute final VAT-inclusive retail price in integer pennies.
     *
     * @param  int  $supplierPennies  Supplier ex-VAT price in pennies (MUST be > 0)
     * @param  int  $marginBasisPoints  Margin percent × 100 (e.g. 2200 = 22.00%)
     * @param  int  $vatBasisPoints  VAT percent × 100 (default 2000 = 20% UK)
     * @return int Retail price in pennies, rounded once at the return boundary
     *
     * @throws SupplierPriceUnusableException when $supplierPennies <= 0 (D-10)
     */
    public function compute(int $supplierPennies, int $marginBasisPoints, int $vatBasisPoints = 2000): int
    {
        // ── D-10 guard ── no £0 ever reaches retail
        if ($supplierPennies <= 0) {
            throw SupplierPriceUnusableException::zeroOrNegative($supplierPennies);
        }

        // ── Integer math ── 2^63 headroom documented in class PHPDoc
        $numerator = $supplierPennies * (10000 + $marginBasisPoints) * (10000 + $vatBasisPoints);
        $denominator = 100_000_000;

        // ── Single round at boundary (Pitfall 5) ── rounding mode from config
        return (int) round(
            $numerator / $denominator,
            0,
            (int) config('pricing.rounding_mode', PHP_ROUND_HALF_UP),
        );
    }

    // ══════════════════════════════════════════════════════════════════════════
    // stripVat — D-05 helper, reused by Phase 5 competitor-CSV ingest
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Strip VAT from a gross-inclusive price.
     *
     * Single-place VAT removal so Phase 5 competitor ingest reuses this
     * unchanged — prevents parallel rounding logic from drifting (D-05,
     * Pitfall 5).
     *
     * Formula: ex_vat = gross × 10000 / (10000 + vat_bps), rounded once.
     *
     * @param  int  $grossPennies  Inclusive-of-VAT price in pennies
     * @param  int  $vatBasisPoints  VAT percent × 100 (default 2000)
     * @return int Ex-VAT price in pennies (0 for non-positive input)
     */
    public function stripVat(int $grossPennies, int $vatBasisPoints = 2000): int
    {
        if ($grossPennies <= 0) {
            return 0;
        }

        $numerator = $grossPennies * 10000;
        $denominator = 10000 + $vatBasisPoints;

        return (int) round(
            $numerator / $denominator,
            0,
            (int) config('pricing.rounding_mode', PHP_ROUND_HALF_UP),
        );
    }
}
