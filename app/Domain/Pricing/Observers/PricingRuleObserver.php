<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Observers;

use App\Domain\Pricing\Events\PricingRuleChanged;
use App\Domain\Pricing\Models\PricingRule;

/**
 * Phase 5 Plan 03 Task 1 — fires PricingRuleChanged ONLY when
 * margin_basis_points is dirty after a save.
 *
 * Defensive guards:
 *   - updated() uses wasChanged() (post-persist view) so detached models
 *     and no-op saves don't fire the event.
 *   - Explicit old-vs-new equality check as a second gate because cast
 *     normalisation ("5000" → 5000) can make wasChanged report false
 *     positives in the general case; we want PURE integer diff semantics.
 *
 * Registered via #[ObservedBy] on PricingRule (Laravel 11+ attribute-based
 * registration — consistent with how existing code-base domain models wire
 * observers; the AppServiceProvider::boot() path is kept free of per-model
 * Model::observe() calls in this repo).
 */
class PricingRuleObserver
{
    public function updated(PricingRule $rule): void
    {
        if (! $rule->wasChanged('margin_basis_points')) {
            return;
        }

        $old = (int) $rule->getOriginal('margin_basis_points');
        $new = (int) $rule->margin_basis_points;

        if ($old === $new) {
            return; // defensive: cast-normalisation guard — no actual change
        }

        event(new PricingRuleChanged(
            ruleId: (int) $rule->id,
            oldMarginBps: $old,
            newMarginBps: $new,
        ));
    }
}
