<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Services;

/**
 * Quick task 260930-eo3 — where a SKU sits against the market, and against our
 * own floor.
 *
 * Answers the three questions an ad shortlist actually turns on:
 *
 *   BEST_PRICE      we are currently the cheapest of the tracked resellers
 *   COULD_BE_BEST   we are not cheapest, but we could undercut the leader and
 *                   STILL clear the minimum-margin floor
 *   CANNOT_WIN      we are not cheapest, and beating the leader would breach
 *                   the floor — the competitor is selling below where we can go
 *   BELOW_FLOOR     our live price is already under cost + the floor margin
 *   BELOW_COST      worse: our net sell price is under the supplier cost. Every
 *                   unit loses money.
 *   NO_COMPETITOR   nobody tracked lists it, so there is no market read
 *   NO_COST         no buy price, so the floor cannot be computed
 *
 * WHY IT DELEGATES RATHER THAN RE-DERIVES: `CompetitorUndercutPricer::decide()`
 * is what actually sets prices every morning. If this report computed its own
 * notion of "the floor" the two would drift, and the report would tell you a
 * SKU is winnable that the pricing job then refuses to move — the most
 * expensive kind of wrong, because you would spend ad budget on it. So the
 * floor here IS that class's floor, via the same PriceCalculator.
 *
 * Note that the floor margin is a MARKUP ON COST, not a share of the sell price
 * — `PriceCalculator::compute($buy, $bps)` is cost-plus. 600bps means cost + 6%,
 * which is about 5.7% of the sell price. The two are easy to confuse and the
 * difference decides whether a row reads as a loss.
 *
 * VAT: `sell_price` is VAT-INCLUSIVE, `buy_price` is the supplier's EX-VAT cost
 * (260927-p41). Competitor gross prices are VAT-inclusive too, so the
 * competitive comparison is gross-vs-gross and the cost comparison is
 * net-vs-net. Mixing them is the bug that made loss-makers look profitable.
 *
 * Pure: no DB, no HTTP, no clock.
 */
final class PricePositionClassifier
{
    public const BEST_PRICE = 'best_price';

    public const COULD_BE_BEST = 'could_be_best';

    public const CANNOT_WIN = 'cannot_win';

    public const BELOW_FLOOR = 'below_floor';

    public const BELOW_COST = 'below_cost';

    public const NO_COMPETITOR = 'no_competitor';

    public const NO_COST = 'no_cost';

    public function __construct(
        private readonly PriceCalculator $calculator,
        private readonly CompetitorUndercutPricer $pricer,
    ) {}

    /**
     * @param  int  $sellGrossPence  our live VAT-INCLUSIVE sell price
     * @param  int  $buyPence  supplier EX-VAT cost (0 = unknown)
     * @param  int|null  $lowestCompetitorGrossPence  cheapest tracked reseller, VAT-inc
     * @return array{
     *     status: string,
     *     floor_gross_pence: int,
     *     net_sell_pence: int,
     *     true_margin_pence: int,
     *     headroom_pence: int,
     *     undercut_target_pence: int|null,
     *     delta_vs_lowest_pence: int|null
     * }
     */
    public function classify(
        int $sellGrossPence,
        int $buyPence,
        ?int $lowestCompetitorGrossPence,
        ?int $beatByPence = null,
        ?int $minFloorBps = null,
    ): array {
        $beatBy = $beatByPence ?? (int) config('competitor.beat_by_pennies', 1);
        $floorBps = $minFloorBps ?? (int) config('competitor.min_margin_floor_bps', 600);

        $netSell = $this->calculator->stripVat(max(0, $sellGrossPence));
        $trueMargin = $netSell - $buyPence;

        // No cost → the floor is undefined. Report the competitive position only
        // rather than inventing a floor of zero, which would call every row safe.
        if ($buyPence <= 0) {
            return $this->row(
                self::NO_COST,
                floorGross: 0,
                netSell: $netSell,
                trueMargin: 0,
                headroom: 0,
                undercutTarget: null,
                deltaVsLowest: $lowestCompetitorGrossPence !== null
                    ? $sellGrossPence - $lowestCompetitorGrossPence
                    : null,
            );
        }

        // The floor, exactly as CompetitorUndercutPricer computes it.
        $floorGross = $this->calculator->compute($buyPence, $floorBps);
        $headroom = $sellGrossPence - $floorGross;

        $delta = $lowestCompetitorGrossPence !== null
            ? $sellGrossPence - $lowestCompetitorGrossPence
            : null;

        $undercutTarget = $lowestCompetitorGrossPence !== null
            ? $lowestCompetitorGrossPence - $beatBy
            : null;

        // ── Loss states take precedence ──────────────────────────────────────
        // A SKU can be BOTH the cheapest on the market AND sold at a loss — in
        // fact that is usually WHY it is cheapest. Advertising it would buy
        // volume at a negative contribution, so the loss is the headline and the
        // competitive position is reported alongside it, never instead of it.
        if ($trueMargin < 0) {
            return $this->row(self::BELOW_COST, $floorGross, $netSell, $trueMargin, $headroom, $undercutTarget, $delta);
        }

        if ($sellGrossPence < $floorGross) {
            return $this->row(self::BELOW_FLOOR, $floorGross, $netSell, $trueMargin, $headroom, $undercutTarget, $delta);
        }

        if ($lowestCompetitorGrossPence === null || $lowestCompetitorGrossPence <= 0) {
            return $this->row(self::NO_COMPETITOR, $floorGross, $netSell, $trueMargin, $headroom, null, null);
        }

        if ($sellGrossPence < $lowestCompetitorGrossPence) {
            return $this->row(self::BEST_PRICE, $floorGross, $netSell, $trueMargin, $headroom, $undercutTarget, $delta);
        }

        // Not cheapest. Could we be, without breaching the floor? Ask the pricer
        // that actually makes this decision every morning rather than repeating
        // its arithmetic here.
        $decision = $this->pricer->decide(
            buyPennies: $buyPence,
            lowestCompetitorGrossPennies: $lowestCompetitorGrossPence,
            ruleMarginBps: $floorBps,
            undercutPennies: $beatBy,
            minFloorBps: $floorBps,
        );

        // source === 'competitor_floor' means it refused to chase: the leader is
        // cheaper than our floor allows.
        $status = $decision['source'] === 'competitor_floor'
            ? self::CANNOT_WIN
            : self::COULD_BE_BEST;

        return $this->row($status, $floorGross, $netSell, $trueMargin, $headroom, $undercutTarget, $delta);
    }

    /**
     * Human label + a one-line reason, for the PDF. Kept beside the constants so
     * a new status cannot be added without a label.
     *
     * @return array{label: string, tone: string}
     */
    public static function describe(string $status): array
    {
        return match ($status) {
            self::BEST_PRICE => ['label' => 'Best price now', 'tone' => 'good'],
            self::COULD_BE_BEST => ['label' => 'Could be best', 'tone' => 'opportunity'],
            self::CANNOT_WIN => ['label' => 'Cannot win at floor', 'tone' => 'warn'],
            self::BELOW_FLOOR => ['label' => 'Below margin floor', 'tone' => 'bad'],
            self::BELOW_COST => ['label' => 'BELOW COST', 'tone' => 'bad'],
            self::NO_COMPETITOR => ['label' => 'No competitor data', 'tone' => 'muted'],
            self::NO_COST => ['label' => 'No cost on file', 'tone' => 'muted'],
            default => ['label' => $status, 'tone' => 'muted'],
        };
    }

    /**
     * @return array{
     *     status: string, floor_gross_pence: int, net_sell_pence: int,
     *     true_margin_pence: int, headroom_pence: int,
     *     undercut_target_pence: int|null, delta_vs_lowest_pence: int|null
     * }
     */
    private function row(
        string $status,
        int $floorGross,
        int $netSell,
        int $trueMargin,
        int $headroom,
        ?int $undercutTarget,
        ?int $deltaVsLowest,
    ): array {
        return [
            'status' => $status,
            'floor_gross_pence' => $floorGross,
            'net_sell_pence' => $netSell,
            'true_margin_pence' => $trueMargin,
            'headroom_pence' => $headroom,
            'undercut_target_pence' => $undercutTarget,
            'delta_vs_lowest_pence' => $deltaVsLowest,
        ];
    }
}
