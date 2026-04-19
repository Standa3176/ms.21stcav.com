<?php

declare(strict_types=1);

use App\Domain\Competitor\Events\CompetitorCsvIngested;
use App\Domain\Competitor\Events\CompetitorPriceRecorded;
use App\Foundation\Events\DomainEvent;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Facades\Context;

/*
|--------------------------------------------------------------------------
| Phase 5 Plan 02 Task 1 — Two new DomainEvents
|--------------------------------------------------------------------------
|
| CompetitorPriceRecorded fires after every valid row write (05-03
| MarginAnalyser listener subscribes). CompetitorCsvIngested fires ONCE
| per file on batch completion.
*/

it('CompetitorPriceRecorded carries all 5 primitive fields and auto-fills correlation_id', function (): void {
    Context::add('correlation_id', 'test-corr-id-123');

    $event = new CompetitorPriceRecorded(
        competitorId: 42,
        sku: 'SKU-1',
        priceGrossPennies: 8999,
        priceExVatPennies: 7499,
        ingestRunId: 5,
    );

    expect($event)
        ->toBeInstanceOf(DomainEvent::class)
        ->toBeInstanceOf(ShouldDispatchAfterCommit::class);
    expect($event->competitorId)->toBe(42);
    expect($event->sku)->toBe('SKU-1');
    expect($event->priceGrossPennies)->toBe(8999);
    expect($event->priceExVatPennies)->toBe(7499);
    expect($event->ingestRunId)->toBe(5);
    expect($event->correlationId)->toBe('test-corr-id-123');
    expect($event->occurredAt)->toBeString();
});

it('CompetitorCsvIngested carries file-level counters + extends DomainEvent', function (): void {
    Context::add('correlation_id', 'batch-corr-id-456');

    $event = new CompetitorCsvIngested(
        competitorId: 42,
        ingestRunId: 9,
        filename: 'acme_2026-04-21.csv',
        rowsTotal: 100,
        rowsWritten: 95,
        rowsErrored: 2,
        rowsOrphaned: 3,
    );

    expect($event)->toBeInstanceOf(DomainEvent::class);
    expect($event->competitorId)->toBe(42);
    expect($event->ingestRunId)->toBe(9);
    expect($event->filename)->toBe('acme_2026-04-21.csv');
    expect($event->rowsTotal)->toBe(100);
    expect($event->rowsWritten)->toBe(95);
    expect($event->rowsErrored)->toBe(2);
    expect($event->rowsOrphaned)->toBe(3);
    expect($event->correlationId)->toBe('batch-corr-id-456');
});
