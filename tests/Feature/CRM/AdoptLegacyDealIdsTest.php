<?php

declare(strict_types=1);

use App\Domain\CRM\Jobs\BackfillOrdersChunkJob;
use App\Domain\CRM\Jobs\PushOrderToBitrixJob;
use App\Domain\CRM\Models\BitrixBackfillRun;
use App\Domain\CRM\Models\BitrixEntityMap;
use App\Domain\CRM\Services\BitrixClient;
use App\Domain\Sync\Services\WooClient;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| Phase 4 Plan 05 Task 1 — --adopt-legacy-deal-ids path (Pitfall 5).
|--------------------------------------------------------------------------
|
| Reads Woo orders that carry _wc_bitrix24_deal_id post_meta (from the
| legacy itgalaxy plugin). For each, calls BitrixClient::dealUpdate on the
| legacy Deal ID with UF_CRM_WOO_ORDER_ID, then writes a BitrixEntityMap
| row with created_via='adopted_legacy'. Idempotent; does NOT run the full
| push path.
*/

beforeEach(function (): void {
    config(['services.bitrix.write_enabled' => true]);
});

function legacyOrderWithDealId(int $orderId, string $legacyDealId): array
{
    return [
        'id' => $orderId,
        'number' => (string) $orderId,
        'status' => 'completed',
        'total' => '149.99',
        'customer_id' => 500,
        'billing' => ['email' => "legacy+{$orderId}@example.com", 'postcode' => 'SW1A 1AA'],
        'meta_data' => [
            ['id' => 9001, 'key' => '_wc_bitrix24_deal_id', 'value' => $legacyDealId],
            ['id' => 9002, 'key' => '_paid_date', 'value' => '2026-02-15'],
        ],
    ];
}

function orderWithoutLegacyDealId(int $orderId): array
{
    return [
        'id' => $orderId,
        'number' => (string) $orderId,
        'status' => 'completed',
        'total' => '29.99',
        'customer_id' => 501,
        'billing' => ['email' => "no-legacy+{$orderId}@example.com", 'postcode' => 'SW1A 1AA'],
        'meta_data' => [
            ['id' => 9100, 'key' => '_some_other_meta', 'value' => 'whatever'],
        ],
    ];
}

// ══════════════════════════════════════════════════════════════════════════════
// Test 1 — adopts legacy Deal via _wc_bitrix24_deal_id
// ══════════════════════════════════════════════════════════════════════════════

it('adopts a legacy Deal via _wc_bitrix24_deal_id post_meta', function (): void {
    $woo = Mockery::mock(WooClient::class);
    $woo->shouldReceive('get')->with('orders/12345', Mockery::any())->andReturn(legacyOrderWithDealId(12345, '7777'));
    $woo->shouldReceive('get')->with('orders/12345')->andReturn(legacyOrderWithDealId(12345, '7777'));
    app()->instance(WooClient::class, $woo);

    $bitrix = Mockery::mock(BitrixClient::class);
    $bitrix->shouldReceive('dealUpdate')
        ->once()
        ->withArgs(function (string $dealId, array $fields) {
            return $dealId === '7777' && ($fields['UF_CRM_WOO_ORDER_ID'] ?? null) === 12345;
        });
    app()->instance(BitrixClient::class, $bitrix);

    $run = BitrixBackfillRun::factory()->adoptLegacy()->create([
        'started_at' => now(), 'finished_at' => null,
    ]);

    (new BackfillOrdersChunkJob(
        orderIds: [12345],
        backfillRunId: $run->id,
        mode: BitrixBackfillRun::MODE_ADOPT_LEGACY,
        correlationId: 'cid-test-1',
    ))->handle($woo, $bitrix);

    $map = BitrixEntityMap::where('entity_type', BitrixEntityMap::ENTITY_DEAL)
        ->where('woo_id', 12345)
        ->first();
    expect($map)->not->toBeNull();
    expect($map->bitrix_id)->toBe('7777');
    expect($map->created_via)->toBe(BitrixEntityMap::VIA_ADOPTED_LEGACY);

    $run->refresh();
    expect($run->adopted_legacy_count)->toBe(1);
    expect($run->processed_orders)->toBe(1);
    expect($run->last_cursor)->toBe('12345');
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 2 — skip when meta key absent
// ══════════════════════════════════════════════════════════════════════════════

it('skips orders without _wc_bitrix24_deal_id in adopt-legacy mode', function (): void {
    $woo = Mockery::mock(WooClient::class);
    $woo->shouldReceive('get')->with('orders/999', Mockery::any())->andReturn(orderWithoutLegacyDealId(999));
    $woo->shouldReceive('get')->with('orders/999')->andReturn(orderWithoutLegacyDealId(999));
    app()->instance(WooClient::class, $woo);

    $bitrix = Mockery::mock(BitrixClient::class);
    $bitrix->shouldNotReceive('dealUpdate');
    app()->instance(BitrixClient::class, $bitrix);

    $run = BitrixBackfillRun::factory()->adoptLegacy()->create([
        'started_at' => now(), 'finished_at' => null,
    ]);

    (new BackfillOrdersChunkJob(
        orderIds: [999],
        backfillRunId: $run->id,
        mode: BitrixBackfillRun::MODE_ADOPT_LEGACY,
        correlationId: 'cid-test-2',
    ))->handle($woo, $bitrix);

    expect(BitrixEntityMap::where('woo_id', 999)->count())->toBe(0);

    $run->refresh();
    expect($run->skipped_orders)->toBe(1);
    expect($run->adopted_legacy_count)->toBe(0);
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 3 — idempotent: second run skips already-mapped orders
// ══════════════════════════════════════════════════════════════════════════════

it('is idempotent on second adopt-legacy pass', function (): void {
    // Pre-existing map row from a prior adoption pass.
    BitrixEntityMap::create([
        'entity_type' => BitrixEntityMap::ENTITY_DEAL,
        'woo_id' => 12345,
        'bitrix_id' => '7777',
        'last_status_snapshot' => 'completed',
        'last_pushed_at' => now()->subDay(),
        'created_via' => BitrixEntityMap::VIA_ADOPTED_LEGACY,
    ]);

    $woo = Mockery::mock(WooClient::class);
    $woo->shouldReceive('get')->with('orders/12345', Mockery::any())->andReturn(legacyOrderWithDealId(12345, '7777'));
    $woo->shouldReceive('get')->with('orders/12345')->andReturn(legacyOrderWithDealId(12345, '7777'));
    app()->instance(WooClient::class, $woo);

    $bitrix = Mockery::mock(BitrixClient::class);
    $bitrix->shouldNotReceive('dealUpdate');   // short-circuited by map check
    app()->instance(BitrixClient::class, $bitrix);

    $run = BitrixBackfillRun::factory()->adoptLegacy()->create([
        'started_at' => now(), 'finished_at' => null,
    ]);

    (new BackfillOrdersChunkJob(
        orderIds: [12345],
        backfillRunId: $run->id,
        mode: BitrixBackfillRun::MODE_ADOPT_LEGACY,
        correlationId: 'cid-test-3',
    ))->handle($woo, $bitrix);

    // Still exactly ONE map row with the original bitrix_id.
    expect(BitrixEntityMap::where('woo_id', 12345)->count())->toBe(1);

    $run->refresh();
    expect($run->skipped_orders)->toBe(1);
    expect($run->adopted_legacy_count)->toBe(0);
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 4 — adopt mode NEVER runs PushOrderToBitrixJob
// ══════════════════════════════════════════════════════════════════════════════

it('does NOT run full push path in adopt-legacy mode', function (): void {
    Queue::fake();

    $woo = Mockery::mock(WooClient::class);
    $woo->shouldReceive('get')->with('orders/12345', Mockery::any())->andReturn(legacyOrderWithDealId(12345, '7777'));
    $woo->shouldReceive('get')->with('orders/12345')->andReturn(legacyOrderWithDealId(12345, '7777'));
    app()->instance(WooClient::class, $woo);

    $bitrix = Mockery::mock(BitrixClient::class);
    $bitrix->shouldReceive('dealUpdate')->once();
    app()->instance(BitrixClient::class, $bitrix);

    $run = BitrixBackfillRun::factory()->adoptLegacy()->create([
        'started_at' => now(), 'finished_at' => null,
    ]);

    (new BackfillOrdersChunkJob(
        orderIds: [12345],
        backfillRunId: $run->id,
        mode: BitrixBackfillRun::MODE_ADOPT_LEGACY,
        correlationId: 'cid-test-4',
    ))->handle($woo, $bitrix);

    // PushOrderToBitrixJob never dispatched — adoption-only.
    Queue::assertNotPushed(PushOrderToBitrixJob::class);
});
