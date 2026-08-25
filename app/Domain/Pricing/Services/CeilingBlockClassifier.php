<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Services;

/**
 * Quick task 260825-t4m — triage a margin-ceiling block into one of three
 * genuinely different things the single 50% line cannot tell apart.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * WHAT THIS DOES NOT DO
 *
 * It does NOT decide whether to block. Every price withheld by the ceiling
 * before this class existed is still withheld. Classification is reporting
 * only — releasing a blocked price stays an operator decision, because a block
 * is not automatically an opportunity: the competitor row may simply be wrong,
 * which is the whole reason the ceiling exists (2026-08-09 incident, SKU
 * 9C941AA).
 * ─────────────────────────────────────────────────────────────────────────
 *
 * Measured on prod 2026-08-25 — 48 published blocks, and the totals hid three
 * populations:
 *
 *   DATA_FAULT   4 at 432%-5,737%. CP4 carried a GBP 24.96 cost against a
 *                GBP 1,517.99 selling price. That is a broken cost or a wrong
 *                identity, not a market signal. Cash is irrelevant here — a
 *                fault worth GBP 556 is still a fault — so margin alone
 *                decides, and these are never suppressed.
 *
 *   REVIEW       9 at 50%-85% carrying real money (FW-98BZ30L at GBP 1,339 a
 *                unit). Plausible competitor prices worth an operator's time.
 *
 *   NOISE        20 under GBP 5 cash/unit contributing GBP 0.00 in total — we
 *                already sit at the competitor's price and only the PERCENTAGE
 *                looks alarming. A GBP 1.16 cable at GBP 2.21 is a normal
 *                markup. These clog the queue that should be showing the nine.
 *
 *   NO_UPSIDE    Competitor is at or below our price. Not an opportunity in any
 *                sense; unblocking would CUT the price. Three of the 48 published
 *                blocks were in this state, which is why "blocked" must never be
 *                read as a synonym for "money available".
 *
 * Pure: no DB, no clock, no config reads. Thresholds are injected so a test can
 * state them outright and the caller owns the config lookup.
 */
final class CeilingBlockClassifier
{
    /** Margin so high it indicts the data, whatever the cash. */
    public const DATA_FAULT = 'data_fault';

    /** Plausible margin, meaningful money — an operator should look. */
    public const REVIEW = 'review';

    /** Plausible margin, trivial money — a high percentage of very little. */
    public const NOISE = 'noise';

    /** Competitor at or below us; releasing would cut the price. */
    public const NO_UPSIDE = 'no_upside';

    public function __construct(
        private readonly int $dataFaultBps,
        private readonly int $minCashPence,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (int) config('competitor.ceiling_data_fault_bps', 20000),
            (int) config('competitor.ceiling_min_cash_pence', 500),
        );
    }

    /**
     * @param  int  $marginBps  effective margin the competitor-led price implies
     * @param  int  $cashPence  EX-VAT cash uplift per unit (may be <= 0)
     */
    public function classify(int $marginBps, int $cashPence): string
    {
        // Order matters. A data fault outranks everything: CP4's 5,737% margin
        // carried GBP 192 of "upside" and DBKT10027's 560% carried GBP 207 —
        // cash-first ordering would have promoted both into the review queue as
        // opportunities when they are broken records.
        if ($marginBps >= $this->dataFaultBps) {
            return self::DATA_FAULT;
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
        return $severity === self::DATA_FAULT || $severity === self::REVIEW;
    }

    public static function label(string $severity): string
    {
        return match ($severity) {
            self::DATA_FAULT => 'DATA FAULT',
            self::REVIEW => 'review',
            self::NOISE => 'noise',
            self::NO_UPSIDE => 'no upside',
            default => $severity,
        };
    }
}
