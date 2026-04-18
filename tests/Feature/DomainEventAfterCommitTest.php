<?php

declare(strict_types=1);

use App\Domain\Sync\Events\SupplierPriceChanged;
use App\Domain\Webhooks\Events\CustomerRegistered;
use App\Domain\Webhooks\Events\OrderReceived;
use App\Foundation\Events\DomainEvent;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    // correlation_id column is VARCHAR(36) — use plain UUID (no prefix).
    Context::add('correlation_id', (string) Str::uuid());
});

// -----------------------------------------------------------------------------
// Test A1 — OUTSIDE a transaction, events fire immediately (unchanged semantics)
// -----------------------------------------------------------------------------
test('A1: DomainEvent dispatched OUTSIDE transaction fires listener immediately', function () {
    Event::fake([SupplierPriceChanged::class]);

    event(new SupplierPriceChanged(
        sku: 'SKU-A1',
        wooProductId: 100,
        wooVariationId: null,
        oldPrice: '10.00',
        newPrice: '12.00',
    ));

    Event::assertDispatched(SupplierPriceChanged::class);
});

// -----------------------------------------------------------------------------
// Test A2 — INSIDE a transaction that COMMITS, events fire AFTER commit
// -----------------------------------------------------------------------------
test('A2: DomainEvent dispatched INSIDE committed transaction fires AFTER commit', function () {
    Event::fake([SupplierPriceChanged::class]);

    DB::transaction(function () {
        event(new SupplierPriceChanged(
            sku: 'SKU-A2',
            wooProductId: 200,
            wooVariationId: null,
            oldPrice: '10.00',
            newPrice: '15.00',
        ));
    });

    Event::assertDispatched(SupplierPriceChanged::class, 1);
});

// -----------------------------------------------------------------------------
// Test A3 — INSIDE a transaction that ROLLS BACK, events DO NOT fire (P2-I fix)
// -----------------------------------------------------------------------------
test('A3: DomainEvent dispatched INSIDE rolled-back transaction does NOT fire (Pitfall P2-I)', function () {
    Event::fake([SupplierPriceChanged::class]);

    try {
        DB::transaction(function () {
            event(new SupplierPriceChanged(
                sku: 'SKU-A3',
                wooProductId: 300,
                wooVariationId: null,
                oldPrice: '10.00',
                newPrice: '99.00',
            ));
            throw new \RuntimeException('simulated failure — transaction must roll back');
        });
    } catch (\RuntimeException) {
        // expected
    }

    Event::assertNotDispatched(SupplierPriceChanged::class);
});

// -----------------------------------------------------------------------------
// Test A4 — Phase 1 subclasses (OrderReceived, CustomerRegistered) respect after-commit
// -----------------------------------------------------------------------------
test('A4: Phase 1 DomainEvent subclasses respect after-commit on rollback', function () {
    Event::fake([OrderReceived::class, CustomerRegistered::class]);

    try {
        DB::transaction(function () {
            event(new OrderReceived(webhookReceiptId: 1, deliveryId: 'd-1'));
            event(new CustomerRegistered(webhookReceiptId: 2, deliveryId: 'd-2'));
            throw new \RuntimeException('force rollback');
        });
    } catch (\RuntimeException) {
        // expected
    }

    Event::assertNotDispatched(OrderReceived::class);
    Event::assertNotDispatched(CustomerRegistered::class);
});

// -----------------------------------------------------------------------------
// Test A5 — DomainEvent base class implements ShouldDispatchAfterCommit (reflection)
// -----------------------------------------------------------------------------
test('A5: DomainEvent base class implements ShouldDispatchAfterCommit', function () {
    $reflection = new ReflectionClass(DomainEvent::class);

    expect($reflection->implementsInterface(ShouldDispatchAfterCommit::class))->toBeTrue();
});
