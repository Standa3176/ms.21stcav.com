<?php

declare(strict_types=1);

use App\Domain\ProductAutoCreate\Events\AutoCreateAttempted;
use App\Domain\ProductAutoCreate\Events\AutoCreateFailed;
use App\Domain\ProductAutoCreate\Events\AutoCreateSucceeded;
use App\Domain\ProductAutoCreate\Events\ProductPublished;
use App\Foundation\Events\DomainEvent;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Phase 6 Plan 03 Task 1 — AutoCreate domain events
|--------------------------------------------------------------------------
| Covers:
|   - All 4 events extend DomainEvent + inherit ShouldDispatchAfterCommit
|   - AutoCreateAttempted carries `sku` only
|   - AutoCreateSucceeded carries the 6 readonly properties
|   - AutoCreateFailed carries sku + reason + optional exception tuple
|   - ProductPublished carries productId + wooProductId + publishedByUserId
|   - correlationId propagates from Context when available
*/

beforeEach(function (): void {
    Context::add('correlation_id', (string) Str::uuid());
});

it('AutoCreateAttempted extends DomainEvent + carries sku', function (): void {
    $e = new AutoCreateAttempted('ATT-01');

    expect($e)->toBeInstanceOf(DomainEvent::class);
    expect($e)->toBeInstanceOf(ShouldDispatchAfterCommit::class);
    expect($e->sku)->toBe('ATT-01');
    expect($e->correlationId)->not->toBeEmpty();
    expect($e->occurredAt)->toBeString();
});

it('AutoCreateSucceeded extends DomainEvent + carries all 6 readonly properties', function (): void {
    $e = new AutoCreateSucceeded(
        productId: 101,
        wooProductId: 500,
        sku: 'SUC-02',
        slug: 'success-slug',
        completenessScore: 78,
        autoCreateStatus: 'draft',
    );

    expect($e)->toBeInstanceOf(DomainEvent::class);
    expect($e)->toBeInstanceOf(ShouldDispatchAfterCommit::class);
    expect($e->productId)->toBe(101);
    expect($e->wooProductId)->toBe(500);
    expect($e->sku)->toBe('SUC-02');
    expect($e->slug)->toBe('success-slug');
    expect($e->completenessScore)->toBe(78);
    expect($e->autoCreateStatus)->toBe('draft');
});

it('AutoCreateFailed extends DomainEvent + supports optional exception tuple', function (): void {
    $e1 = new AutoCreateFailed(sku: 'FAIL-03', reason: 'duplicate');
    expect($e1)->toBeInstanceOf(DomainEvent::class);
    expect($e1->sku)->toBe('FAIL-03');
    expect($e1->reason)->toBe('duplicate');
    expect($e1->exceptionClass)->toBeNull();
    expect($e1->exceptionMessage)->toBeNull();

    $e2 = new AutoCreateFailed(
        sku: 'FAIL-04',
        reason: 'woo_rest_error',
        exceptionClass: RuntimeException::class,
        exceptionMessage: 'boom',
    );
    expect($e2->exceptionClass)->toBe(RuntimeException::class);
    expect($e2->exceptionMessage)->toBe('boom');
});

it('ProductPublished extends DomainEvent + carries attribution fields', function (): void {
    $e = new ProductPublished(productId: 5, wooProductId: 123, publishedByUserId: 7);

    expect($e)->toBeInstanceOf(DomainEvent::class);
    expect($e)->toBeInstanceOf(ShouldDispatchAfterCommit::class);
    expect($e->productId)->toBe(5);
    expect($e->wooProductId)->toBe(123);
    expect($e->publishedByUserId)->toBe(7);
});

it('events propagate correlation_id from Context', function (): void {
    $corr = '11111111-2222-3333-4444-555555555555';
    Context::add('correlation_id', $corr);

    $e = new AutoCreateAttempted('CORR-05');
    expect($e->correlationId)->toBe($corr);
});
