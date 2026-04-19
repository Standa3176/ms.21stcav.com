<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Appliers;

use App\Domain\Pricing\Models\PricingRule;
use App\Domain\Suggestions\Contracts\SuggestionApplier;
use App\Domain\Suggestions\Models\Suggestion;
use App\Foundation\Audit\Services\Auditor;

/**
 * Phase 5 Plan 03 Task 3 — SECOND real SuggestionApplier (after Phase 4's
 * CrmPushRetryApplier). Approving a margin_change Suggestion triggers
 * ApplySuggestionJob → this applier → PricingRule update → observer fires
 * PricingRuleChanged event (Task 1).
 *
 * The observer chain — not direct event dispatch — is deliberate:
 *   - Single source of truth for "margin_basis_points changed" semantics.
 *   - Future listeners on PricingRuleChanged (e.g. a bulk-recompute trigger)
 *     don't need to know about this applier; they only subscribe to the
 *     event. Decoupling preserved.
 *
 * Idempotency (Phase 1 D-15 + Plan 05-03 test): a second apply() call with
 * the same (already-applied) payload is a no-op because the observer's
 * wasChanged('margin_basis_points') guard suppresses the re-fire — the
 * Eloquent update itself is cheap (single UPDATE) so re-running it does
 * no harm.
 *
 * Auditor discipline: Auditor::record BEFORE return so a subsequent
 * failure in the caller doesn't orphan the audit. Phase 1 FOUND-04 pattern.
 */
final class MarginChangeApplier implements SuggestionApplier
{
    public function __construct(private Auditor $auditor) {}

    public function supports(): array
    {
        return ['margin_change'];
    }

    public function apply(Suggestion $suggestion): array
    {
        $payload = (array) $suggestion->payload;
        $pricingRuleId = (int) ($payload['pricing_rule_id'] ?? 0);
        $newMarginBps = (int) ($payload['new_margin_basis_points'] ?? 0);

        $rule = PricingRule::findOrFail($pricingRuleId);
        $oldMarginBps = (int) $rule->margin_basis_points;

        // Eloquent update — PricingRuleObserver fires PricingRuleChanged IF dirty
        // (the observer's wasChanged + old!==new guard ensures idempotency).
        $rule->update(['margin_basis_points' => $newMarginBps]);

        $freshMarginBps = (int) $rule->fresh()->margin_basis_points;

        $this->auditor->record('competitor.margin_change_applied', [
            'suggestion_id' => $suggestion->id,
            'pricing_rule_id' => $rule->id,
            'old_margin_bps' => $oldMarginBps,
            'new_margin_bps' => $freshMarginBps,
        ]);

        return [
            'applied' => true,
            'pricing_rule_id' => (int) $rule->id,
            'old_margin_bps' => $oldMarginBps,
            'new_margin_bps' => $freshMarginBps,
        ];
    }
}
