<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Services;

/**
 * Phase 3 Plan 04 — classification of what PriceRecomputer::recompute() found.
 *
 * Shared vocabulary for the event-driven listener (Plan 02) AND the bulk
 * recompute job (Plan 04). Every recompute call reports back via one of these
 * values so the bulk command's summary report (sync-bulk queue) can tally
 * processed / changed / unchanged / skipped counts without peeking at
 * internal listener state.
 *
 * - Changed           — the recompute produced an integer-penny diff vs stored
 *                       sell_price. When $persist=true, sell_price was written
 *                       AND ProductPriceChanged was dispatched.
 * - Unchanged         — the recompute reproduced the stored sell_price exactly.
 *                       No write, no event (D-13 fire-on-diff gate held).
 * - ZeroPriceSkipped  — buy_price ≤ 0 or null. ImportIssue row written in BOTH
 *                       persist modes (D-10 + D-11). No sell_price write,
 *                       no event.
 * - NoRuleMatched     — RuleResolver threw NoPricingRuleMatchedException
 *                       (default tiers missing / buy_price out of all ranges
 *                       AND no brand/category rule). Logged at ERROR; nothing
 *                       touched. ImportIssue is NOT written — this is a
 *                       catalogue-configuration issue, not a data-quality row.
 * - ProductNotFound   — neither woo_product_id nor woo_variation_id resolved
 *                       to a Product/ProductVariant. Logged at WARNING; nothing
 *                       touched.
 */
enum RecomputeOutcomeKind: string
{
    case Changed = 'changed';
    case Unchanged = 'unchanged';
    case ZeroPriceSkipped = 'zero_price_skipped';
    case NoRuleMatched = 'no_rule_matched';
    case ProductNotFound = 'product_not_found';
}
