<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Exceptions;

use RuntimeException;

/**
 * Phase 3 Plan 01 — thrown by PriceCalculator::compute() when the supplier
 * price is zero, null-coerced-to-zero, or negative (D-10).
 *
 * Callers (RecomputePriceListener in Plan 02 et seq) catch this and log an
 * ImportIssue with issue_type=missing_cost_price rather than writing £0 to
 * products.sell_price. The existing row stays untouched — NO £0 retail leak.
 *
 * Message carries the offending integer pennies for debugging only; this is
 * an internal audit metric, not PII (T-03-01-05 disposition=accept).
 */
final class SupplierPriceUnusableException extends RuntimeException
{
    public static function zeroOrNegative(int $pennies): self
    {
        return new self("Supplier price must be > 0 pennies; got {$pennies}");
    }
}
