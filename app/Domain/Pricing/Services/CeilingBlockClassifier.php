<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Services;

/**
 * Quick task 260825-t4m / 260825-z8q — triage a margin-ceiling block.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * WHAT THIS DOES NOT DO
 *
 * It does NOT decide whether to block. Every price the ceiling withheld before
 * this class existed is still withheld. Classification is reporting only —
 * releasing a blocked price stays an operator decision, because a block is not
 * automatically an opportunity: the competitor row may simply be wrong, which
 * is the whole reason the ceiling exists (2026-08-09 incident, SKU 9C941AA).
 * ─────────────────────────────────────────────────────────────────────────
 *
 * Measured on prod 2026-08-25 — 37 blocks classed as faults, GBP 19,829/unit,
 * GBP 17,186 of it on 24 published products. Reading them showed the single
 * "data fault" label was still hiding two problems with opposite fixes:
 *
 *   COST_FAULT        Our OWN cost→price relationship is already absurd before
 *                     a competitor is involved. CP4: cost GBP 24.96 against a
 *                     GBP 1,517.99 price — 4,968% before any competitor row is
 *                     read, and our price AGREES with the competitor's
 *                     GBP 1,748.39. The cost is the outlier, and a wrong cost
 *                     feeds the margin rules, the 6% floor, the demote/restore
 *                     decision and every report. Poisons pricing globally.
 *                     Also 83Z50AA#ABB and 772C8AA, where our price EQUALS the
 *                     competitor's and only the margin reads absurd.
 *
 *   COMPETITOR_FAULT  Our cost→price is sane (92L53AA#ABU sits at exactly the
 *                     22% default tier) but the competitor-led price implies
 *                     622%. Their data, not ours; inert once blocked.
 *                     92L53AA#ABU and 83Z49AA#ABU both read 622.1% with
 *                     competitor/cost ratios identical to four significant
 *                     figures — two products cannot land there by chance, so
 *                     that is a feed transformation, not a market.
 *
 * The discriminator is simply: DOES OUR OWN COST→PRICE LOOK SANE?
 *
 * Two conditions, both required, so a deliberately fat line is not libelled as
 * a broken one:
 *   1. current implied margin >= costFaultBps (absolute; 9H.JND77.1HE runs a
 *      legitimate 99.5% and must stay below this)
 *   2. current implied margin exceeds the product's RESOLVED rule margin by
 *      more than the tolerance — so a product deliberately pinned high by a
 *      ProductOverride (260824-w9k) resolves to its own margin and is exempt
 *
 * Pure: no DB, no clock, no config reads. Thresholds are injected so a test can
 * state them outright and the caller owns the config lookup.
 */
final class CeilingBlockClassifier
{
    /** Our own cost is suspect — the fault is in OUR data and spreads. */
    public const COST_FAULT = 'cost_fault';

    /** Our cost is sane; the competitor row is implausible. Contained. */
    public const COMPETITOR_FAULT = 'competitor_fault';

    /**
     * Legacy, pre-260825-z8q: a fault we could not attribute because the
     * current margin was not recorded. Retained so stored rows still resolve;
     * re-classified on read wherever the product is still available.
     */
    public const DATA_FAULT = 'data_fault';

    /** Plausible margin, meaningful money — an operator should look. */
    public const REVIEW = 'review';

    /** Plausible margin, trivial money — a high percentage of very little. */
    public const NOISE = 'noise';

    /** Competitor at or below us; releasing would CUT the price. */
    public const NO_UPSIDE = 'no_upside';

    /** Both fault types, for --severity=data_fault backwards compatibility. */
    public const FAULT_SEVERITIES = [self::COST_FAULT, self::COMPETITOR_FAULT, self::DATA_FAULT];

    public function __construct(
        private readonly int $dataFaultBps,
        private readonly int $minCashPence,
        private readonly int $costFaultBps = 20000,
        private readonly int $costFaultToleranceBps = 5000,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (int) config('competitor.ceiling_data_fault_bps', 20000),
            (int) config('competitor.ceiling_min_cash_pence', 500),
            (int) config('competitor.ceiling_cost_fault_bps', 20000),
            (int) config('competitor.ceiling_cost_fault_tolerance_bps', 5000),
        );
    }

    /**
     * @param  int  $marginBps  margin the COMPETITOR-LED price would imply
     * @param  int  $cashPence  EX-VAT cash uplift per unit (may be <= 0)
     * @param  int|null  $currentMarginBps  margin our CURRENT price already earns
     *                                      on our own cost; null = unknown (legacy)
     * @param  int|null  $ruleMarginBps  the product's resolved rule margin, so a
     *                                   deliberately-pinned line is not accused
     */
    public function classify(
        int $marginBps,
        int $cashPence,
        ?int $currentMarginBps = null,
        ?int $ruleMarginBps = null,
    ): string {
        // A broken cost outranks everything, INCLUDING the competitor margin.
        // Hoisted above the ceiling test on purpose: a product whose own cost is
        // nonsense is a cost fault whether the competitor implies 60% or 6,000%,
        // and calling it "review" would put a poisoned record on the
        // opportunities list.
        if ($this->isCostFault($currentMarginBps, $ruleMarginBps)) {
            return self::COST_FAULT;
        }

        if ($marginBps >= $this->dataFaultBps) {
            // Cash-first ordering would promote broken records to the top of the
            // queue as money: CP4's 5,737% carried GBP 192 of apparent upside
            // and DBKT10027's 560% carried GBP 207, beating genuine
            // opportunities. Margin decides here, never cash.
            return $currentMarginBps === null
                ? self::DATA_FAULT
                : self::COMPETITOR_FAULT;
        }

        if ($cashPence <= 0) {
            return self::NO_UPSIDE;
        }

        if ($cashPence < $this->minCashPence) {
            return self::NOISE;
        }

        return self::REVIEW;
    }

    /**
     * Is our own cost→price relationship absurd, independent of any competitor?
     *
     * Both conditions are required. The absolute floor keeps a legitimately fat
     * line (9H.JND77.1HE at 99.5%) out of it; the rule comparison keeps a line
     * deliberately pinned by a ProductOverride out of it, since such a product
     * resolves to its own margin and so cannot exceed it by the tolerance.
     */
    private function isCostFault(?int $currentMarginBps, ?int $ruleMarginBps): bool
    {
        if ($currentMarginBps === null || $currentMarginBps < $this->costFaultBps) {
            return false;
        }

        if ($ruleMarginBps === null) {
            return true;
        }

        return $currentMarginBps > ($ruleMarginBps + $this->costFaultToleranceBps);
    }

    /**
     * Margin our current price earns on our own cost, in basis points.
     * Returns null when there is no usable cost — absence of evidence, not a fault.
     */
    public static function currentMarginBps(int $buyPennies, int $sellPennies, int $vatBps = 2000): ?int
    {
        if ($buyPennies <= 0 || $sellPennies <= 0) {
            return null;
        }

        $exVat = (int) round($sellPennies / (1 + ($vatBps / 10000)));

        return (int) round((($exVat - $buyPennies) / $buyPennies) * 10000);
    }

    /**
     * Cash forgone per unit, EX VAT — VAT is not margin, and reporting the
     * gross figure overstates every opportunity by the VAT rate.
     */
    public static function cashUpliftPence(int $proposedPennies, int $currentPennies, int $vatBps = 2000): int
    {
        $divisor = 1 + ($vatBps / 10000);

        return (int) round(($proposedPennies - $currentPennies) / $divisor);
    }

    /** Severities an operator should actually see by default. */
    public static function isActionable(string $severity): bool
    {
        return in_array($severity, [self::COST_FAULT, self::COMPETITOR_FAULT, self::DATA_FAULT, self::REVIEW], true);
    }

    /** Does this severity indicate broken data rather than a pricing decision? */
    public static function isFault(string $severity): bool
    {
        return in_array($severity, self::FAULT_SEVERITIES, true);
    }

    public static function label(string $severity): string
    {
        return match ($severity) {
            self::COST_FAULT => 'COST FAULT',
            self::COMPETITOR_FAULT => 'competitor fault',
            self::DATA_FAULT => 'DATA FAULT',
            self::REVIEW => 'review',
            self::NOISE => 'noise',
            self::NO_UPSIDE => 'no upside',
            default => $severity,
        };
    }
}
