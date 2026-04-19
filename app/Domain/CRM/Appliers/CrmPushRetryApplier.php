<?php

declare(strict_types=1);

namespace App\Domain\CRM\Appliers;

use App\Domain\CRM\Jobs\PushCustomerToBitrixJob;
use App\Domain\CRM\Jobs\PushOrderToBitrixJob;
use App\Domain\Suggestions\Contracts\SuggestionApplier;
use App\Domain\Suggestions\Models\Suggestion;
use RuntimeException;

/**
 * Phase 4 Plan 03 Task 3 — FIRST real producer of the Phase 1 suggestions
 * applier seam (D-12).
 *
 * Registered in AppServiceProvider::boot() against kind='crm_push_failed'.
 * Approving a `crm_push_failed` suggestion triggers ApplySuggestionJob, which
 * resolves this applier and calls apply(); we re-dispatch a FRESH push job
 * with the ORIGINAL webhook_receipt_id + topic but a reset attempts counter
 * (updateMissRetries=0). The original correlation_id travels via Context
 * because ApplySuggestionJob already restored it before calling us.
 *
 * Idempotency: ApplySuggestionJob short-circuits when Suggestion.status is
 * 'applied' (D-15), so double-invoking this applier is safe. Each re-dispatch
 * produces a fresh PushOrderToBitrixJob with its own attempt counter.
 */
final class CrmPushRetryApplier implements SuggestionApplier
{
    public function supports(): array
    {
        return ['crm_push_failed'];
    }

    public function apply(Suggestion $suggestion): array
    {
        $payload = (array) $suggestion->payload;
        $evidence = (array) $suggestion->evidence;

        $entityType = (string) ($payload['entity_type'] ?? 'deal');
        $topic = (string) ($payload['topic'] ?? 'order.created');
        $webhookReceiptId = (int) ($evidence['webhook_receipt_id'] ?? 0);
        $correlationId = (string) ($suggestion->correlation_id ?? ($evidence['correlation_id'] ?? ''));

        if ($webhookReceiptId <= 0) {
            throw new RuntimeException(sprintf(
                'CrmPushRetryApplier: evidence.webhook_receipt_id missing on suggestion %s',
                $suggestion->id,
            ));
        }

        $jobClass = match ($entityType) {
            'deal' => PushOrderToBitrixJob::class,
            'contact' => PushCustomerToBitrixJob::class,
            default => throw new RuntimeException("CrmPushRetryApplier: unsupported entity_type '{$entityType}' on suggestion {$suggestion->id}"),
        };

        if ($jobClass === PushOrderToBitrixJob::class) {
            PushOrderToBitrixJob::dispatch($webhookReceiptId, $topic, 0);
        } else {
            PushCustomerToBitrixJob::dispatch($webhookReceiptId, $topic);
        }

        return [
            'dispatched_job' => $jobClass,
            'webhook_receipt_id' => $webhookReceiptId,
            'topic' => $topic,
            'correlation_id' => $correlationId,
            'original_sub_kind' => (string) ($payload['sub_kind'] ?? 'unknown'),
        ];
    }
}
