<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Services;

/**
 * Competitor-undercut pricing decision (pure).
 *
 * Given a product's supplier cost and the LOWEST current competitor gross price,
 * decide the VAT-inclusive sell price in integer pennies:
 *
 *   - On at least one competitor → land `undercutPennies` BELOW the lowest
 *     competitor gross, but NEVER below cost + the minimum-margin floor
 *     (config competitor.min_margin_floor_bps, default 5%). When the competitor
 *     is so cheap that beating it would breach the floor, we hold at the floor
 *     price (source = competitor_floor) rather than sell at a loss.
 *   - On NO competitor → cost-plus the resolved rule margin (the "set margin"
 *     case), source = margin.
 *
 * All VAT + rounding math is delegated to PriceCalculator (the Phase 3 golden-
 * fixture ship gate) so there is exactly one place that does pennies/VAT — this
 * class never reimplements it. Pure: no DB, no events, no clock. The command
 * (CompetitorUndercutPricingCommand) supplies the inputs and performs the write.
 */
final class CompetitorUndercutPricer
{
    public function __construct(private PriceCalculator $calculator) {}

    /**
     * @param  int  $buyPennies  Supplier ex-VAT cost in pennies (MUST be > 0).
     * @param  int|null  $lowestCompetitorGrossPennies  Lowest current competitor
     *         VAT-inclusive price in pennies; null/≤0 = on no competitor.
     * @param  int  $ruleMarginBps  Resolved cost-plus margin for the no-competitor case.
     * @param  int  $undercutPennies  How far below the lowest competitor to land.
     * @param  int  $minFloorBps  Minimum acceptable margin when undercutting.
     * @return array{final_pennies:int, source:string, effective_margin_bps:int}
     */
    public function decide(
        int $buyPennies,
        ?int $lowestCompetitorGrossPennies,
        int $ruleMarginBps,
        int $undercutPennies,
        int $minFloorBps,
        int $vatBps = 2000,
    ): array {
        // ── No competitor → set cost-plus margin ─────────────────────────────
        if ($lowestCompetitorGrossPennies === null || $lowestCompetitorGrossPennies <= 0) {
            return [
                'final_pennies' => $this->calculator->compute($buyPennies, $ruleMarginBps, $vatBps),
                'source' => 'margin',
                'effective_margin_bps' => $ruleMarginBps,
            ];
        }

        // ── Competitor present → undercut, floored at cost + min margin ──────
        $target = $lowestCompetitorGrossPennies - $undercutPennies;
        $floor = $this->calculator->compute($buyPennies, $minFloorBps, $vatBps);

        if ($target < $floor) {
            return [
                'final_pennies' => $floor,
                'source' => 'competitor_floor',
                'effective_margin_bps' => $minFloorBps,
            ];
        }

        // Undercut accepted — report the resulting margin for transparency.
        $targetExVat = $this->calculator->stripVat($target, $vatBps);
        $effectiveMarginBps = intdiv(($targetExVat - $buyPennies) * 10000, $buyPennies);

        return [
            'final_pennies' => $target,
            'source' => 'competitor_undercut',
            'effective_margin_bps' => $effectiveMarginBps,
        ];
    }
}
