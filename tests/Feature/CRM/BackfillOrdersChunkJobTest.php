<?php

declare(strict_types=1);

use App\Domain\CRM\Jobs\BackfillOrdersChunkJob;
use App\Domain\CRM\Models\BitrixBackfillRun;
use App\Domain\CRM\Models\BitrixEntityMap;
use App\Domain\CRM\Services\BitrixClient;
use App\Domain\Sync\Services\WooClient;
use App\Domain\Webhooks\Models\WebhookReceipt;

/*
|--------------------------------------------------------------------------
| Phase 4 Plan 05 Task 1 — BackfillOrdersChunkJob semantics.
|--------------------------------------------------------------------------
|
| Queue routing, per-order failure handling, live-mode idempotency
| short-circuit, and cursor updates.
*/

beforeEach(function (): void {
    config(['services.bitrix.webhook_url' => 'https://example.bitrix24.com/rest/1/fake-token/']);
});

function dummyOrder(int $id): array
{
    return [
        'id' => $id,
        'number' => (string) $id,
        'status' => 'completed',
        'total' => '99.00',
        'customer_id' => 100 + $id,
        'billing' => ['email' => "x{$id}@example.com", 'postcode' => 'SW1A 1AA'],
        'meta_data' => [],
    ];
}

// ══════════════════════════════════════════════════════════════════════════════
// Test 1 — queue is sync-bulk (NOT crm-bitrix; Pitfall 7)
// ══════════════════════════════════════════════════════════════════════════════

it('dispatches on the sync-bulk queue (Pitfall 7 — never starves crm-bitrix)', function (): void {
    $job = new BackfillOrdersChunkJob(
        orderIds: [1],
        backfillRunId: 99,
        mode: BitrixBackfillRun::MODE_DRY_RUN,
        correlationId: 'cid-x',
    );

    expect($job->queue)->toBe('sync-bulk');
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 2 — live mode short-circuits when BitrixEntityMap already maps the order
// ══════════════════════════════════════════════════════════════════════════════

it('short-circuits live mode when BitrixEntityMap already contains the order', function (): void {
    config(['services.bitrix.write_enabled' => true]);

    // Pre-existing map row — represents a prior successful push.
    BitrixEntityMap::create([
        'entity_type' => BitrixEntityMap::ENTITY_DEAL,
        'woo_id' => 777,
        'bitrix_id' => 'D777',
        'last_status_snapshot' => 'pending',
        'last_pushed_at' => now()->subDay(),
        'created_via' => BitrixEntityMap::VIA_PUSH,
    ]);

    $woo = Mockery::mock(WooClient::class);
    $woo->shouldReceive('get')->with('orders/777', Mockery::any())->andReturn(dummyOrder(777));
    $woo->shouldReceive('get')->with('orders/777')->andReturn(dummyOrder(777));
    app()->instance(WooClient::class, $woo);

    $bitrix = Mockery::mock(BitrixClient::class);
    // No Bitrix writes at all — idempotency is entirely DB-side.
    $bitrix->shouldNotReceive('dealAdd');
    $bitrix->shouldNotReceive('dealUpdate');
    app()->instance(BitrixClient::class, $bitrix);

    $run = BitrixBackfillRun::factory()->live()->create([
        'started_at' => now(), 'finished_at' => null,
    ]);

    (new BackfillOrdersChunkJob(
        orderIds: [777],
        backfillRunId: $run->id,
        mode: BitrixBackfillRun::MODE_LIVE,
        correlationId: 'cid-live',
    ))->handle($woo, $bitrix);

    // NO WebhookReceipt should have been manufactured either.
    expect(WebhookReceipt::where('source', 'backfill-manual')->count())->toBe(0);

    $run->refresh();
    expect($run->skipped_orders)->toBe(1);
    expect($run->processed_orders)->toBe(0);
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 3 — per-order fetch failures count as failed, chunk doesn't abort
// ══════════════════════════════════════════════════════════════════════════════

it('counts failed Woo fetches as failed_orders and continues to next order', function (): void {
    $woo = Mockery::mock(WooClient::class);
    $woo->shouldReceive('get')->with('orders/1', Mockery::any())->andThrow(new \RuntimeException('Woo 500'));
    $woo->shouldReceive('get')->with('orders/1')->andThrow(new \RuntimeException('Woo 500'));
    $woo->shouldReceive('get')->with('orders/2', Mockery::any())->andReturn(dummyOrder(2));
    $woo->shouldReceive('get')->with('orders/2')->andReturn(dummyOrder(2));
    app()->instance(WooClient::class, $woo);

    $bitrix = Mockery::mock(BitrixClient::class);
    app()->instance(BitrixClient::class, $bitrix);

    $run = BitrixBackfillRun::factory()->adoptLegacy()->create([
        'started_at' => now(), 'finished_at' => null,
    ]);

    (new BackfillOrdersChunkJob(
        orderIds: [1, 2],
        backfillRunId: $run->id,
        mode: BitrixBackfillRun::MODE_ADOPT_LEGACY,  // simpler path — no pushing
        correlationId: 'cid-partial',
    ))->handle($woo, $bitrix);

    $run->refresh();
    expect($run->failed_orders)->toBe(1);
    // Order 2 has no legacy meta, so it's counted as skipped.
    expect($run->skipped_orders)->toBe(1);
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 4 — run-not-found early-exits without raising
// ══════════════════════════════════════════════════════════════════════════════

it('no-ops when backfill run id is stale / deleted', function (): void {
    $woo = Mockery::mock(WooClient::class);
    $woo->shouldNotReceive('get');
    app()->instance(WooClient::class, $woo);

    $bitrix = Mockery::mock(BitrixClient::class);
    app()->instance(BitrixClient::class, $bitrix);

    (new BackfillOrdersChunkJob(
        orderIds: [1],
        backfillRunId: 999999,
        mode: BitrixBackfillRun::MODE_DRY_RUN,
        correlationId: 'cid-stale',
    ))->handle($woo, $bitrix);

    expect(true)->toBeTrue();   // reached without exception
});
