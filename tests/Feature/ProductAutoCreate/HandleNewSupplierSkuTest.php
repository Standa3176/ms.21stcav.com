<?php

declare(strict_types=1);

use App\Domain\ProductAutoCreate\Jobs\CreateWooProductJob;
use App\Domain\ProductAutoCreate\Listeners\HandleNewSupplierSku;
use App\Domain\ProductAutoCreate\Models\AutoCreateSkipRule;
use App\Domain\Sync\Events\NewSupplierSkuDetected;
use App\Foundation\Integration\Models\IntegrationEvent;
use App\Foundation\Integration\Services\IntegrationLogger;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Phase 6 Plan 03 Task 1 — HandleNewSupplierSku listener
|--------------------------------------------------------------------------
| Covers:
|   - No matching skip rule → CreateWooProductJob dispatched with SKU arg.
|   - sku_pattern regex rule matches → silent skip + integration_events
|     'auto_skipped' row with matched_rule_ids in request_body.
|   - price_range <N rule matches → silent skip.
|   - sku_pattern that does NOT match → job IS dispatched.
|   - Malformed skip-rule regex → fail-soft (no job dispatched loss;
|     actually we dispatch because no rule matched — no crash).
|   - Listener is queueable via ShouldQueue + onQueue('sync-bulk').
*/

beforeEach(function (): void {
    Context::add('correlation_id', (string) Str::uuid());
});

it('dispatches CreateWooProductJob with no matching skip rules', function (): void {
    Queue::fake();

    $listener = app(HandleNewSupplierSku::class);
    $listener->handle(new NewSupplierSkuDetected(
        sku: 'LOG-MEETUP',
        supplierPrice: '1100',
        supplierStock: 10,
    ));

    Queue::assertPushed(CreateWooProductJob::class, function (CreateWooProductJob $job): bool {
        return $job->sku === 'LOG-MEETUP';
    });
});

it('short-circuits on matching sku_pattern rule and logs auto_skipped', function (): void {
    Queue::fake();

    $rule = AutoCreateSkipRule::factory()->create([
        'scope' => AutoCreateSkipRule::SCOPE_SKU_PATTERN,
        'value' => '^TEST-',
        'reason' => 'other',
        'is_active' => true,
    ]);

    $listener = app(HandleNewSupplierSku::class);
    $listener->handle(new NewSupplierSkuDetected(
        sku: 'TEST-ABC',
        supplierPrice: '500',
        supplierStock: 1,
    ));

    Queue::assertNotPushed(CreateWooProductJob::class);

    $event = IntegrationEvent::query()
        ->where('operation', 'auto_skipped')
        ->where('channel', 'woo-auto-create')
        ->first();

    expect($event)->not->toBeNull();
    expect($event->status)->toBe('success');
    expect($event->request_body)->toMatchArray(['sku' => 'TEST-ABC']);
    expect((array) ($event->request_body['matched_rule_ids'] ?? []))
        ->toContain($rule->id);
});

it('short-circuits on matching price_range <N rule', function (): void {
    Queue::fake();

    AutoCreateSkipRule::factory()->create([
        'scope' => AutoCreateSkipRule::SCOPE_PRICE_RANGE,
        'value' => '<1200',
        'reason' => 'below_viability_threshold',
        'is_active' => true,
    ]);

    $listener = app(HandleNewSupplierSku::class);
    $listener->handle(new NewSupplierSkuDetected(
        sku: 'CHEAPY',
        supplierPrice: '1100',
        supplierStock: 5,
    ));

    Queue::assertNotPushed(CreateWooProductJob::class);

    expect(IntegrationEvent::query()
        ->where('operation', 'auto_skipped')
        ->exists())->toBeTrue();
});

it('dispatches job when sku_pattern does NOT match', function (): void {
    Queue::fake();

    AutoCreateSkipRule::factory()->create([
        'scope' => AutoCreateSkipRule::SCOPE_SKU_PATTERN,
        'value' => '^TEST-',
        'reason' => 'other',
        'is_active' => true,
    ]);

    $listener = app(HandleNewSupplierSku::class);
    $listener->handle(new NewSupplierSkuDetected(
        sku: 'LOG-MEETUP',
        supplierPrice: '1100',
        supplierStock: 10,
    ));

    Queue::assertPushed(CreateWooProductJob::class, 1);
});

it('fails soft when skip rule regex is catastrophic and still dispatches job', function (): void {
    Queue::fake();

    // A pathological but syntactically valid regex — AutoCreateSkipRule::matches
    // wraps preg_match in @ to swallow catastrophic-backtracking errors; our
    // rule evaluation method treats any throw as a rule-skip (T-06-03-04).
    AutoCreateSkipRule::factory()->create([
        'scope' => AutoCreateSkipRule::SCOPE_SKU_PATTERN,
        // Deliberately malformed — unmatched bracket is handled by @preg_match.
        'value' => '[unclosed',
        'reason' => 'other',
        'is_active' => true,
    ]);

    $listener = app(HandleNewSupplierSku::class);
    $listener->handle(new NewSupplierSkuDetected(
        sku: 'NO-MATCH',
        supplierPrice: '500',
        supplierStock: 1,
    ));

    Queue::assertPushed(CreateWooProductJob::class, 1);
});

it('implements ShouldQueue with sync-bulk queue', function (): void {
    $logger = app(IntegrationLogger::class);
    $listener = new HandleNewSupplierSku($logger);

    expect($listener)->toBeInstanceOf(Illuminate\Contracts\Queue\ShouldQueue::class);
    expect($listener->queue)->toBe('sync-bulk');
});

it('inactive skip rule does not short-circuit dispatch', function (): void {
    Queue::fake();

    AutoCreateSkipRule::factory()->create([
        'scope' => AutoCreateSkipRule::SCOPE_SKU_PATTERN,
        'value' => '^LOG-',
        'reason' => 'other',
        'is_active' => false,  // disabled — must be ignored
    ]);

    $listener = app(HandleNewSupplierSku::class);
    $listener->handle(new NewSupplierSkuDetected(
        sku: 'LOG-MEETUP',
        supplierPrice: '1100',
        supplierStock: 10,
    ));

    Queue::assertPushed(CreateWooProductJob::class, 1);
});
