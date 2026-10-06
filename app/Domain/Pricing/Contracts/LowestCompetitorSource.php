<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Contracts;

/**
 * Quick task 261006-r3n — "what is the cheapest competitor price", as Pricing
 * needs to ask it.
 *
 * WHY AN INTERFACE AND NOT THE CLASS: the real implementation,
 * Competitor\Services\LowestCompetitorResolver, lives in the Competitor domain,
 * and deptrac forbids Pricing from depending on Competitor — the arrow runs the
 * other way (Competitor is allowed to depend on Pricing). That rule is why both
 * ad scanners hand-rolled their own windowed SQL against `competitor_prices`
 * instead of reusing the pricing job's lookup.
 *
 * The cost of that workaround showed up in production on 2026-09-30: the
 * scanners' private queries applied the recency window and NONE of the guards
 * the pricing job applies — quarantined feed rows, paused competitors, SKU
 * homonym exclusions. An ad shortlist whose "we undercut them" winners are
 * partly chosen from bad feed rows is worse than no shortlist, because budget
 * follows it.
 *
 * Inverting the dependency fixes both problems at once: Pricing depends on this
 * interface, which it owns; Competitor implements it, which it is already
 * allowed to do; and there is exactly ONE definition of the rule.
 */
interface LowestCompetitorSource
{
    /**
     * Lowest CURRENT competitor gross price per match key, for a whole-catalogue
     * pass.
     *
     * "Current" = the latest row per (competitor, sku) inside the window, then
     * the minimum across competitors. Keys are the competitor row's sku AND mpn,
     * lower-cased and trimmed. Quarantined rows, paused competitors and match
     * exclusions are already applied — a caller gets only prices the pricing job
     * would itself act on.
     *
     * @return array<string, array{lowest: int, competitors: int}>
     */
    public function bulkCurrentByKey(int $windowDays): array;
}
