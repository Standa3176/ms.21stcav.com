<?php

declare(strict_types=1);

namespace App\Domain\Agents\Appliers;

use App\Domain\Suggestions\Contracts\SuggestionApplier;
use App\Domain\Suggestions\Models\Suggestion;
use Illuminate\Support\Facades\Log;

/**
 * Phase 8 Plan 04 — EchoApplier stub for kind='echo_health'.
 *
 * Marks the Suggestion applied with no business side effects — EchoAgent is
 * the framework smoke test, not a business workflow. Phase 10 PricingAgent
 * + later agents register concrete appliers using the same pattern (see
 * Phase 4 CrmPushRetryApplier or Phase 5 MarginChangeApplier for production
 * shape).
 *
 * Contract: SuggestionApplier::apply returns array — logged to
 * integration_events.response_body by ApplySuggestionJob (Phase 1 seam).
 * Idempotent — ApplySuggestionJob short-circuits on STATUS_APPLIED so
 * re-running this applier is safe.
 */
final class EchoApplier implements SuggestionApplier
{
    public function supports(): array
    {
        return ['echo_health'];
    }

    /** @return array<string, mixed> */
    public function apply(Suggestion $suggestion): array
    {
        Log::info('EchoApplier: marking echo_health suggestion applied', [
            'suggestion_id' => $suggestion->id,
            'correlation_id' => $suggestion->correlation_id,
            'evidence_keys' => array_keys((array) $suggestion->evidence),
        ]);

        return [
            'applier' => self::class,
            'kind' => 'echo_health',
            'suggestion_id' => $suggestion->id,
            'no_side_effects' => true,
            'note' => 'EchoApplier is the framework smoke-test applier — no business state changes.',
        ];
    }
}
