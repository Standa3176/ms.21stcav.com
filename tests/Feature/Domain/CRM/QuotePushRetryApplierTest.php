<?php

declare(strict_types=1);

use App\Domain\CRM\Appliers\QuotePushRetryApplier;
use App\Domain\CRM\Jobs\PushQuoteToBitrixDealJob;
use App\Domain\Quotes\Models\Quote;
use App\Domain\Suggestions\Models\Suggestion;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| Phase 11 Plan 05 Task 1 — QuotePushRetryApplier
|--------------------------------------------------------------------------
|
| DLQ replay applier for kind='quote_push_failed' (Phase 11 Plan 04 producer).
| Clones Phase 4 CrmPushRetryApplier shape verbatim. Coverage:
|
|   1. supports() returns ['quote_push_failed']
|   2. apply() dispatches PushQuoteToBitrixDealJob with quote_id from payload
|   3. correlation_id is preserved from Suggestion → fresh Job
|   4. result array carries quote_id + dispatched_job + correlation_id
|   5. payload missing quote_id → throws RuntimeException (admin sees in inbox)
|   6. Quote::find returns null (hard-deleted) → throws RuntimeException
*/

function skipIfMySqlOfflineQuoteRetryApplier(): void
{
    try {
        \DB::connection()->getPdo();
    } catch (\Throwable $e) {
        test()->markTestSkipped('MySQL offline: '.$e->getMessage());
    }
}

function makeQuotePushFailedSuggestion(array $overrides = []): Suggestion
{
    return Suggestion::create(array_merge([
        'kind' => 'quote_push_failed',
        'status' => Suggestion::STATUS_PENDING,
        'correlation_id' => 'cid-quote-test',
        'payload' => [
            'sub_kind' => 'push_exhausted',
            'quote_id' => '01H000000000000000000FAKE0',
            'http_status' => 500,
            'error_message' => 'transient',
        ],
        'evidence' => [
            'correlation_id' => 'cid-quote-test',
            'attempt_count' => 3,
        ],
        'proposed_at' => now(),
    ], $overrides));
}

beforeEach(function (): void {
    skipIfMySqlOfflineQuoteRetryApplier();
});

it('declares support for quote_push_failed kind', function (): void {
    expect((new QuotePushRetryApplier())->supports())->toBe(['quote_push_failed']);
});

it('dispatches PushQuoteToBitrixDealJob with quote_id from suggestion payload', function (): void {
    skipIfMySqlOfflineQuoteRetryApplier();
    Queue::fake();

    $quote = Quote::factory()->create(['status' => Quote::STATUS_SENT]);
    $suggestion = makeQuotePushFailedSuggestion([
        'payload' => [
            'sub_kind' => 'push_exhausted',
            'quote_id' => $quote->id,
            'http_status' => 500,
            'error_message' => 'timeout',
        ],
    ]);

    $result = (new QuotePushRetryApplier())->apply($suggestion);

    Queue::assertPushed(PushQuoteToBitrixDealJob::class, function (PushQuoteToBitrixDealJob $job) use ($quote): bool {
        return $job->quoteId === $quote->id;
    });

    expect($result['dispatched_job'])->toBe(PushQuoteToBitrixDealJob::class);
    expect($result['quote_id'])->toBe($quote->id);
});

it('preserves correlation_id from suggestion when dispatching the fresh job', function (): void {
    skipIfMySqlOfflineQuoteRetryApplier();
    Queue::fake();

    $quote = Quote::factory()->create(['status' => Quote::STATUS_SENT]);
    $suggestion = makeQuotePushFailedSuggestion([
        'correlation_id' => 'cid-deterministic',
        'payload' => [
            'sub_kind' => 'push_exhausted',
            'quote_id' => $quote->id,
        ],
    ]);

    $result = (new QuotePushRetryApplier())->apply($suggestion);

    expect($result['correlation_id'])->toBe('cid-deterministic');

    Queue::assertPushed(PushQuoteToBitrixDealJob::class, function (PushQuoteToBitrixDealJob $job): bool {
        return $job->correlationId === 'cid-deterministic';
    });
});

it('returns the dispatched_job class + sub_kind metadata in the result array', function (): void {
    skipIfMySqlOfflineQuoteRetryApplier();
    Queue::fake();

    $quote = Quote::factory()->create(['status' => Quote::STATUS_SENT]);
    $suggestion = makeQuotePushFailedSuggestion([
        'payload' => [
            'sub_kind' => 'permanent_validation',
            'quote_id' => $quote->id,
            'attempt_count' => 1,
        ],
    ]);

    $result = (new QuotePushRetryApplier())->apply($suggestion);

    expect($result)->toHaveKeys(['dispatched_job', 'quote_id', 'correlation_id', 'sub_kind', 'original_attempt_count']);
    expect($result['sub_kind'])->toBe('permanent_validation');
    expect($result['original_attempt_count'])->toBe(1);
});

it('throws RuntimeException when payload.quote_id is missing', function (): void {
    skipIfMySqlOfflineQuoteRetryApplier();
    Queue::fake();

    $suggestion = makeQuotePushFailedSuggestion([
        'payload' => [
            'sub_kind' => 'push_exhausted',
            // intentionally NO quote_id
        ],
    ]);

    (new QuotePushRetryApplier())->apply($suggestion);
})->throws(RuntimeException::class, 'payload.quote_id missing');

it('throws RuntimeException when Quote no longer exists (hard-deleted between failure and replay)', function (): void {
    skipIfMySqlOfflineQuoteRetryApplier();
    Queue::fake();

    $suggestion = makeQuotePushFailedSuggestion([
        'payload' => [
            'sub_kind' => 'push_exhausted',
            'quote_id' => '01H000000000000000000GHOST', // 26-char ULID-shaped but not in DB
        ],
    ]);

    (new QuotePushRetryApplier())->apply($suggestion);

    Queue::assertNothingPushed();
})->throws(RuntimeException::class, 'not found');
