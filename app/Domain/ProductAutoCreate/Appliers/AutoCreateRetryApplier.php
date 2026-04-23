<?php

declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Appliers;

use App\Domain\ProductAutoCreate\Jobs\CreateWooProductJob;
use App\Domain\Suggestions\Contracts\SuggestionApplier;
use App\Domain\Suggestions\Models\Suggestion;

/**
 * Phase 6 Plan 03 — replay applier for kind='auto_create_failed' DLQ rows.
 *
 * When CreateWooProductJob::failed() fires after Laravel exhausts $tries, it
 * writes a `kind=auto_create_failed` Suggestion with evidence['sku'] so the
 * Plan 04 Filament inbox can offer an admin "Replay" action. Clicking Replay
 * enqueues ApplySuggestionJob → this applier → CreateWooProductJob::dispatch.
 *
 * Mirrors Phase 4's CrmPushRetryApplier pattern (D-12 / Phase 4 Plan 03 D-08).
 * ApplySuggestionJob is idempotent per Phase 1 D-15 — a double-click simply
 * dispatches two jobs, each with its own attempt counter.
 */
final class AutoCreateRetryApplier implements SuggestionApplier
{
    /** @return array<int, string> */
    public function supports(): array
    {
        return ['auto_create_failed'];
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
            'retry_dispatched' => true,
            'sku' => $sku,
        ];
    }
}
