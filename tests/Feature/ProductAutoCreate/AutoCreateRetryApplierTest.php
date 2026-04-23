<?php

declare(strict_types=1);

use App\Domain\ProductAutoCreate\Appliers\AutoCreateRetryApplier;
use App\Domain\ProductAutoCreate\Jobs\CreateWooProductJob;
use App\Domain\Suggestions\Models\Suggestion;
use App\Domain\Suggestions\Services\SuggestionApplierResolver;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| Phase 6 Plan 03 Task 3 — AutoCreateRetryApplier (kind='auto_create_failed')
|--------------------------------------------------------------------------
| Covers:
|   - supports() returns ['auto_create_failed'].
|   - apply() dispatches CreateWooProductJob + returns {retry_dispatched, sku}.
|   - missing evidence.sku → error + no dispatch.
|   - SuggestionApplierResolver resolves kind='auto_create_failed' to this applier.
*/

it('supports() returns auto_create_failed', function (): void {
    expect((new AutoCreateRetryApplier())->supports())->toBe(['auto_create_failed']);
});

it('apply() dispatches CreateWooProductJob + returns retry_dispatched marker', function (): void {
    Queue::fake();

    $suggestion = Suggestion::create([
        'kind' => 'auto_create_failed',
        'status' => Suggestion::STATUS_PENDING,
        'correlation_id' => 'retry-corr',
        'evidence' => ['sku' => 'RETRY-SKU', 'source' => 'CreateWooProductJob', 'error' => 'woo 500'],
        'payload' => [],
        'proposed_at' => now(),
    ]);

    $result = (new AutoCreateRetryApplier())->apply($suggestion);

    expect($result)->toMatchArray([
        'retry_dispatched' => true,
        'sku' => 'RETRY-SKU',
    ]);

    Queue::assertPushed(CreateWooProductJob::class, function (CreateWooProductJob $job) use ($suggestion): bool {
        return $job->sku === 'RETRY-SKU' && $job->suggestionId === (string) $suggestion->id;
    });
});

it('apply() returns error when evidence.sku missing', function (): void {
    Queue::fake();

    $suggestion = Suggestion::create([
        'kind' => 'auto_create_failed',
        'status' => Suggestion::STATUS_PENDING,
        'correlation_id' => 'retry-missing-sku',
        'evidence' => [],
        'payload' => [],
        'proposed_at' => now(),
    ]);

    $result = (new AutoCreateRetryApplier())->apply($suggestion);

    expect($result['error'] ?? null)->toBe('missing_sku_in_evidence');
    Queue::assertNotPushed(CreateWooProductJob::class);
});

it('SuggestionApplierResolver resolves auto_create_failed to this applier', function (): void {
    $resolver = app(SuggestionApplierResolver::class);

    $suggestion = Suggestion::create([
        'kind' => 'auto_create_failed',
        'status' => Suggestion::STATUS_PENDING,
        'correlation_id' => 'resolver-test',
        'evidence' => ['sku' => 'ANY'],
        'payload' => [],
        'proposed_at' => now(),
    ]);

    $applier = $resolver->resolve($suggestion);

    expect($applier)->toBeInstanceOf(AutoCreateRetryApplier::class);
});
