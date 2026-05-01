<?php

declare(strict_types=1);

namespace App\Domain\CRM\Appliers;

use App\Domain\CRM\Jobs\PushQuoteToBitrixDealJob;
use App\Domain\Quotes\Models\Quote;
use App\Domain\Suggestions\Contracts\SuggestionApplier;
use App\Domain\Suggestions\Models\Suggestion;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Phase 11 Plan 05 Task 1 — DLQ recovery applier for `quote_push_failed`.
 *
 * Clones Phase 4 CrmPushRetryApplier shape verbatim. Wired in
 * AppServiceProvider against kind='quote_push_failed' so the Filament
 * Suggestions inbox Replay action resolves to this applier and re-dispatches
 * a fresh PushQuoteToBitrixDealJob with the original quote_id +
 * correlation_id.
 *
 * Producer side: Plan 11-04 PushQuoteToBitrixDealJob writes the failed
 * Suggestion (kind='quote_push_failed') in two paths:
 *   1. handle()-catch on BitrixPermanentException (4xx fail-fast)
 *   2. failed() hook after all retries exhausted
 * Both writers populate `payload.quote_id` (string ULID) and the
 * correlation_id is propagated via Suggestion::correlation_id.
 *
 * Idempotency: ApplySuggestionJob short-circuits when Suggestion.status is
 * already 'applied' (Phase 1 D-15), so double-invoking this applier is safe.
 * Each re-dispatch produces a fresh PushQuoteToBitrixDealJob with its own
 * tries=3 + backoff=[30,300,1800] retry budget — operator-driven recovery
 * loop (matches RESEARCH OQ-5 resolution: no auto-retry from the Suggestion
 * itself; admin clicks Replay to re-engage the push pipeline).
 *
 * Threat model T-11-05-03 (re-play for wrong quote): payload.quote_id missing
 * or referencing a deleted Quote both throw RuntimeException — the
 * ApplySuggestionJob catches, flips Suggestion status to 'failed', and
 * surfaces in the inbox. The wrong-quote replay never reaches BitrixClient.
 */
final class QuotePushRetryApplier implements SuggestionApplier
{
    public function supports(): array
    {
        return ['quote_push_failed'];
    }

    public function apply(Suggestion $suggestion): array
    {
        $payload = (array) $suggestion->payload;
        $quoteId = (string) ($payload['quote_id'] ?? '');

        if ($quoteId === '') {
            throw new RuntimeException(sprintf(
                'QuotePushRetryApplier: payload.quote_id missing on suggestion %s',
                $suggestion->id,
            ));
        }

        // Verify the Quote still exists — operator could have hard-deleted in the
        // window between the producer's failed() hook and the admin Replay click.
        $quote = Quote::find($quoteId);
        if ($quote === null) {
            throw new RuntimeException(sprintf(
                'QuotePushRetryApplier: Quote %s not found (suggestion %s); admin should reject the suggestion',
                $quoteId,
                $suggestion->id,
            ));
        }

        $correlationId = (string) ($suggestion->correlation_id ?? Str::ulid()->toBase32());

        PushQuoteToBitrixDealJob::dispatch($quoteId, $correlationId);

        return [
            'dispatched_job' => PushQuoteToBitrixDealJob::class,
            'quote_id' => $quoteId,
            'correlation_id' => $correlationId,
            'original_attempt_count' => (int) ($payload['attempt_count'] ?? 0),
            'sub_kind' => (string) ($payload['sub_kind'] ?? 'unknown'),
        ];
    }
}
