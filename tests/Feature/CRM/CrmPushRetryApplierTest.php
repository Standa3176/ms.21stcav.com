<?php

declare(strict_types=1);

use App\Domain\CRM\Appliers\CrmPushRetryApplier;
use App\Domain\CRM\Jobs\PushCustomerToBitrixJob;
use App\Domain\CRM\Jobs\PushOrderToBitrixJob;
use App\Domain\Suggestions\Models\Suggestion;
use App\Domain\Suggestions\Services\SuggestionApplierResolver;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| Phase 4 Plan 03 Task 3 — CrmPushRetryApplier
|--------------------------------------------------------------------------
|
| First real producer on the Phase 1 suggestions applier seam. Resolver wires
| `crm_push_failed` → CrmPushRetryApplier. Approving a failed push re-dispatches
| the original job with a reset attempts counter.
*/

function makeCrmPushFailedSuggestion(array $overrides = []): Suggestion
{
    return Suggestion::create(array_merge([
        'kind' => 'crm_push_failed',
        'status' => Suggestion::STATUS_PENDING,
        'correlation_id' => 'cid-test',
        'payload' => [
            'sub_kind' => 'push_exhausted',
            'entity_type' => 'deal',
            'woo_id' => 42,
            'topic' => 'order.created',
            'http_status' => 500,
            'error_message' => 'timeout',
        ],
        'evidence' => [
            'correlation_id' => 'cid-test',
            'webhook_receipt_id' => 42,
            'retry_count' => 3,
            'request_payload' => ['id' => 42],
        ],
        'proposed_at' => now(),
    ], $overrides));
}

it('declares support for crm_push_failed kind', function (): void {
    expect((new CrmPushRetryApplier())->supports())->toBe(['crm_push_failed']);
});

it('dispatches PushOrderToBitrixJob with topic preserved for entity_type=deal', function (): void {
    Queue::fake();

    $suggestion = makeCrmPushFailedSuggestion();

    $result = (new CrmPushRetryApplier())->apply($suggestion);

    Queue::assertPushed(PushOrderToBitrixJob::class, function (PushOrderToBitrixJob $job) {
        return $job->webhookReceiptId === 42
            && $job->topic === 'order.created'
            && $job->updateMissRetries === 0;
    });

    expect($result['dispatched_job'])->toBe(PushOrderToBitrixJob::class);
    expect($result['webhook_receipt_id'])->toBe(42);
    expect($result['original_sub_kind'])->toBe('push_exhausted');
});

it('dispatches PushCustomerToBitrixJob for entity_type=contact', function (): void {
    Queue::fake();

    $suggestion = makeCrmPushFailedSuggestion([
        'payload' => [
            'sub_kind' => 'push_exhausted',
            'entity_type' => 'contact',
            'woo_id' => 7,
            'topic' => 'customer.updated',
            'http_status' => 500,
            'error_message' => 'timeout',
        ],
        'evidence' => [
            'correlation_id' => 'cid-test',
            'webhook_receipt_id' => 77,
            'retry_count' => 3,
            'request_payload' => ['id' => 7],
        ],
    ]);

    $result = (new CrmPushRetryApplier())->apply($suggestion);

    Queue::assertPushed(PushCustomerToBitrixJob::class, function (PushCustomerToBitrixJob $job) {
        return $job->webhookReceiptId === 77 && $job->topic === 'customer.updated';
    });

    expect($result['dispatched_job'])->toBe(PushCustomerToBitrixJob::class);
});

it('dispatches fresh PushOrderToBitrixJob for sub_kind=update_before_create (race retry)', function (): void {
    Queue::fake();

    $suggestion = makeCrmPushFailedSuggestion([
        'payload' => [
            'sub_kind' => 'update_before_create',
            'entity_type' => 'deal',
            'woo_id' => 42,
            'topic' => 'order.updated',
            'http_status' => 0,
            'error_message' => 'map missing',
        ],
    ]);

    (new CrmPushRetryApplier())->apply($suggestion);

    Queue::assertPushed(PushOrderToBitrixJob::class, fn (PushOrderToBitrixJob $job) => $job->updateMissRetries === 0);
});

it('throws on missing evidence.webhook_receipt_id', function (): void {
    Queue::fake();

    $suggestion = makeCrmPushFailedSuggestion([
        'evidence' => ['correlation_id' => 'cid-test', 'retry_count' => 0, 'request_payload' => []],
    ]);

    expect(fn () => (new CrmPushRetryApplier())->apply($suggestion))
        ->toThrow(\RuntimeException::class, 'webhook_receipt_id missing');
});

it('throws on unsupported entity_type', function (): void {
    Queue::fake();

    $suggestion = makeCrmPushFailedSuggestion([
        'payload' => [
            'sub_kind' => 'push_exhausted',
            'entity_type' => 'lead',
            'woo_id' => 1,
            'topic' => 'order.created',
            'http_status' => 0,
            'error_message' => 'x',
        ],
    ]);

    expect(fn () => (new CrmPushRetryApplier())->apply($suggestion))
        ->toThrow(\RuntimeException::class, "unsupported entity_type 'lead'");
});

it('SuggestionApplierResolver resolves CrmPushRetryApplier for crm_push_failed kind', function (): void {
    $suggestion = makeCrmPushFailedSuggestion();

    $applier = app(SuggestionApplierResolver::class)->resolve($suggestion);

    expect($applier)->toBeInstanceOf(CrmPushRetryApplier::class);
});
