<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Appliers;

use App\Domain\Suggestions\Contracts\SuggestionApplier;
use App\Domain\Suggestions\Models\Suggestion;
use Illuminate\Support\Facades\Log;

/**
 * Phase 5 Plan 02 Task 2 — SECOND real producer on the Suggestions seam
 * (after Phase 4 CrmPushRetryApplier).
 *
 * Registered in AppServiceProvider::boot against kind='new_product_opportunity'
 * so the Filament Approve action is clickable and the kind is recognised.
 * Phase 6 will REPLACE the body — wire supplier-request-list integration
 * so approving the suggestion adds the SKU to the supplier's next purchase
 * request. Until then, this is a no-op stub that logs intent + marks the
 * suggestion applied with a note so ops sees progress without a false
 * promise of end-to-end integration.
 */
final class NewProductOpportunityApplier implements SuggestionApplier
{
    public function supports(): array
    {
        return ['new_product_opportunity'];
    }

    public function apply(Suggestion $suggestion): array
    {
        $sku = data_get($suggestion->evidence, 'sku');

        Log::info('new_product_opportunity.stub_applied', [
            'suggestion_id' => $suggestion->id,
            'sku' => $sku,
            'note' => 'Phase 6 will wire supplier-request-list integration',
            'correlation_id' => $suggestion->correlation_id,
        ]);

        return [
            'phase_5_stub' => true,
            'sku' => $sku,
            'applied_at' => now()->toIso8601String(),
            'applier' => self::class,
            'note' => 'Phase 6 will wire supplier-request-list integration',
        ];
    }
}
