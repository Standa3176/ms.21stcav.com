<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Services;

use App\Domain\Pricing\Services\PriceCalculator;
use Illuminate\Support\Facades\Log;

/**
 * Phase 5 Plan 03 Task 2 — reverse-margin calculator (D-07).
 *
 * Given a competitor's VAT-inclusive gross price in pennies and our
 * supplier ex-VAT cost in pennies, compute the margin (basis points) we
 * would need on our PricingRule to land at (competitor_ex_vat - beat_by_pennies)
 * as our sell price.
 *
 * Algorithm:
 *   competitorExVat = PriceCalculator::stripVat(gross, 2000)   [COMP-06]
 *   targetSellExVat = competitorExVat - beat_by_pennies
 *   marginBps       = intdiv((targetSellExVat - supplier) * 10000, supplier)
 *
 * Guards:
 *   - supplierExVatPennies <= 0 → null (Phase 3 D-10 analogue; zero-cost
 *     products have no defined margin curve).
 *   - marginBps < config('competitor.min_margin_floor_bps') → null +
 *     Log::warning('suggestion_suppressed_low_margin') (Pitfall P5-E).
 *     NEVER recommend a rule change that would drive our margin below 5% —
 *     aggressive competitor pricing doesn't justify a money-losing tier.
 *
 * COMP-06 discipline: ALWAYS call PriceCalculator::stripVat. NEVER
 * reimplement VAT math — the StripVatReuseTest grep-harness would fail the
 * build if any VAT-divide short-hand or local stripVat function appears
 * under app/Domain/Competitor/.
 *
 * Pure integer arithmetic — no float intermediates; intdiv() for the final
 * basis-points conversion keeps drift at zero.
 */
class MarginAnalyser
{
    public function __construct(private PriceCalculator $calculator) {}

    public function computeProposal(int $competitorGrossPennies, int $supplierExVatPennies): ?MarginProposal
    {
        if ($supplierExVatPennies <= 0) {
            return null;
        }

        $beatByPennies = (int) config('competitor.beat_by_pennies', 1);
        $minFloorBps = (int) config('competitor.min_margin_floor_bps', 500);

        $competitorExVat = $this->calculator->stripVat($competitorGrossPennies, 2000);
        $targetSellExVat = $competitorExVat - $beatByPennies;

        // marginBps = ((target_sell - supplier) / supplier) * 10000
        // intdiv handles both positive (healthy margin) and negative (wrong-direction)
        // cases without float rounding drift.
        $marginBps = intdiv(($targetSellExVat - $supplierExVatPennies) * 10000, $supplierExVatPennies);

        if ($marginBps < $minFloorBps) {
            Log::warning('suggestion_suppressed_low_margin', [
                'supplier_ex_vat_pennies' => $supplierExVatPennies,
                'competitor_ex_vat_pennies' => $competitorExVat,
                'proposed_margin_bps' => $marginBps,
                'floor_bps' => $minFloorBps,
            ]);

            return null;
        }

        return new MarginProposal(
            proposedMarginBasisPoints: $marginBps,
            competitorExVatPennies: $competitorExVat,
            supplierExVatPennies: $supplierExVatPennies,
            beatByPennies: $beatByPennies,
        );
    }
}
