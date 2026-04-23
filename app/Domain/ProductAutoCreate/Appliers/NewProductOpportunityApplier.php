<?php

declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Appliers;

use App\Domain\ProductAutoCreate\Jobs\CreateWooProductJob;
use App\Domain\Suggestions\Contracts\SuggestionApplier;
use App\Domain\Suggestions\Models\Suggestion;

/**
 * Phase 6 Plan 03 — REAL applier for kind='new_product_opportunity' (RESEARCH
 * Q4 resolution — option b: MOVED from app/Domain/Competitor/Appliers/ to
 * ProductAutoCreate because the auto-create dispatch is Phase 6's
 * responsibility).
 *
 * Phase 5 Plan 02 shipped this under the Competitor namespace as a no-op stub
 * (`phase_5_stub => true`) because the real pipeline hadn't been built yet.
 * Phase 6 ships the pipeline, so we promote the applier to its real body AND
 * relocate it into ProductAutoCreate so the Competitor layer doesn't need
 * Deptrac visibility into ProductAutoCreate.
 *
 * Behaviour: reads evidence['sku'] → dispatches CreateWooProductJob
 * (optionally threading the suggestion id through as the DLQ replay hint).
 */
final class NewProductOpportunityApplier implements SuggestionApplier
{
    /** @return array<int, string> */
    public function supports(): array
    {
        return ['new_product_opportunity'];
    }

    /**
     * @return array<string, mixed>
     */
    public function apply(Suggestion $suggestion): array
    {
        $sku = (string) ($suggestion->evidence['sku'] ?? '');
        if ($sku === '') {
            return [
                'error' => 'missing_sku_in_evidence',
                'suggestion_id' => $suggestion->id,
            ];
        }

        CreateWooProductJob::dispatch($sku, (string) $suggestion->id);

        return [
            'phase_6_live' => true,
            'sku' => $sku,
            'dispatched_job_class' => CreateWooProductJob::class,
        ];
    }
}
