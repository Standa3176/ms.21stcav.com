<?php

declare(strict_types=1);

use App\Domain\Sync\Events\NewSupplierSkuDetected;
use App\Domain\Sync\Events\SupplierPriceChanged;
use App\Domain\Sync\Events\SupplierSkuMissing;
use App\Domain\Sync\Events\SupplierStockChanged;
use App\Domain\Sync\Listeners\StubNewSupplierSkuListener;
use App\Foundation\Events\DomainEvent;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Context::add('correlation_id', (string) Str::uuid());
});

// -----------------------------------------------------------------------------
// Test E1 — SupplierPriceChanged populates correlationId + occurredAt + payload
// -----------------------------------------------------------------------------
test('E1: SupplierPriceChanged carries correlationId + occurredAt + full payload', function () {
    $correlationId = (string) Str::uuid();
    Context::add('correlation_id', $correlationId);

    $event = new SupplierPriceChanged(
        sku: 'WIDGET-1',
        wooProductId: 1001,
        wooVariationId: null,
        oldPrice: '10.00',
        newPrice: '12.50',
    );

    expect($event->sku)->toBe('WIDGET-1')
        ->and($event->wooProductId)->toBe(1001)
        ->and($event->wooVariationId)->toBeNull()
        ->and($event->oldPrice)->toBe('10.00')
        ->and($event->newPrice)->toBe('12.50')
        ->and($event->reason)->toBe('supplier_sync')
        ->and($event->correlationId)->toBe($correlationId)
        ->and($event->occurredAt)->toBeString();
});

// -----------------------------------------------------------------------------
// Test E2 — SupplierStockChanged payload shape
// -----------------------------------------------------------------------------
test('E2: SupplierStockChanged payload shape {sku, wooProductId, wooVariationId, oldStock, newStock}', function () {
    $event = new SupplierStockChanged(
        sku: 'GIZMO-2',
        wooProductId: 2002,
        wooVariationId: 5001,
        oldStock: 10,
        newStock: 3,
    );

    expect($event->sku)->toBe('GIZMO-2')
        ->and($event->wooProductId)->toBe(2002)
        ->and($event->wooVariationId)->toBe(5001)
        ->and($event->oldStock)->toBe(10)
        ->and($event->newStock)->toBe(3)
        ->and($event->reason)->toBe('supplier_sync');
});

// -----------------------------------------------------------------------------
// Test E3 — SupplierSkuMissing payload shape
// -----------------------------------------------------------------------------
test('E3: SupplierSkuMissing payload shape {sku, wooProductId, wooVariationId, hadCustomMsTag, newStatus}', function () {
    $event = new SupplierSkuMissing(
        sku: 'MISSING-3',
        wooProductId: 3003,
        wooVariationId: null,
        hadCustomMsTag: false,
        newStatus: 'pending',
    );

    expect($event->sku)->toBe('MISSING-3')
        ->and($event->wooProductId)->toBe(3003)
        ->and($event->wooVariationId)->toBeNull()
        ->and($event->hadCustomMsTag)->toBeFalse()
        ->and($event->newStatus)->toBe('pending');
});

// -----------------------------------------------------------------------------
// Test E4 — NewSupplierSkuDetected payload shape
// -----------------------------------------------------------------------------
test('E4: NewSupplierSkuDetected payload {sku, supplierPrice, supplierStock}', function () {
    $event = new NewSupplierSkuDetected(
        sku: 'NEW-4',
        supplierPrice: '45.00',
        supplierStock: 100,
    );

    expect($event->sku)->toBe('NEW-4')
        ->and($event->supplierPrice)->toBe('45.00')
        ->and($event->supplierStock)->toBe(100);
});

// -----------------------------------------------------------------------------
// Test E5 — All 4 events extend DomainEvent AND implement ShouldDispatchAfterCommit
// -----------------------------------------------------------------------------
test('E5: all 4 Phase 2 events extend DomainEvent and inherit ShouldDispatchAfterCommit', function () {
    $events = [
        SupplierPriceChanged::class,
        SupplierStockChanged::class,
        SupplierSkuMissing::class,
        NewSupplierSkuDetected::class,
    ];

    foreach ($events as $class) {
        $reflection = new ReflectionClass($class);
        expect($reflection->isSubclassOf(DomainEvent::class))->toBeTrue("{$class} must extend DomainEvent");
        expect($reflection->implementsInterface(ShouldDispatchAfterCommit::class))
            ->toBeTrue("{$class} must implement ShouldDispatchAfterCommit via DomainEvent");
    }
});

// -----------------------------------------------------------------------------
// Test E6 — NewSupplierSkuDetected invokes StubNewSupplierSkuListener exactly once
// -----------------------------------------------------------------------------
test('E6: dispatching NewSupplierSkuDetected invokes StubNewSupplierSkuListener', function () {
    Event::fake([NewSupplierSkuDetected::class]);

    event(new NewSupplierSkuDetected(
        sku: 'STUB-TEST-6',
        supplierPrice: '9.99',
        supplierStock: 42,
    ));

    Event::assertListening(
        NewSupplierSkuDetected::class,
        StubNewSupplierSkuListener::class,
    );

    Event::assertDispatched(NewSupplierSkuDetected::class, 1);
});

// -----------------------------------------------------------------------------
// Test E7 — StubNewSupplierSkuListener handle() is invokable and no-op-safe
// -----------------------------------------------------------------------------
test('E7: StubNewSupplierSkuListener logs receipt and returns without side effects', function () {
    Log::spy();

    $listener = new StubNewSupplierSkuListener();
    $event = new NewSupplierSkuDetected(
        sku: 'LOGGED-7',
        supplierPrice: '15.00',
        supplierStock: 7,
    );

    $listener->handle($event);

    Log::shouldHaveReceived('info')->once();
});
