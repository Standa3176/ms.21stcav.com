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

// ══════════════════════════════════════════════════════════════════════════════
// 12.1-04 — shadow overwrite, map-before-stamp, --legacy-map-csv
// ══════════════════════════════════════════════════════════════════════════════

function runAdoptChunk(int $orderId, array $wooOrder, $bitrix, array $legacyDealIds = []): BitrixBackfillRun
{
    $woo = Mockery::mock(WooClient::class);
    $woo->shouldReceive('get')->with('orders/'.$orderId)->andReturn($wooOrder);
    $run = BitrixBackfillRun::factory()->adoptLegacy()->create(['started_at' => now(), 'finished_at' => null]);

    (new BackfillOrdersChunkJob(
        orderIds: [$orderId],
        backfillRunId: $run->id,
        mode: BitrixBackfillRun::MODE_ADOPT_LEGACY,
        correlationId: 'cid-1204',
        legacyDealIds: $legacyDealIds,
    ))->handle($woo, $bitrix);

    return $run->refresh();
}

it('12.1-04: overwrites a SHADOW- deal map row with the legacy id', function (): void {
    BitrixEntityMap::create([
        'entity_type' => BitrixEntityMap::ENTITY_DEAL,
        'woo_id' => 101,
        'bitrix_id' => 'SHADOW-x',
        'created_via' => BitrixEntityMap::VIA_PUSH,
    ]);
    $bitrix = Mockery::mock(BitrixClient::class);
    $bitrix->shouldReceive('dealUpdate')->once();

    $run = runAdoptChunk(101, legacyOrderWithDealId(101, '34582'), $bitrix);

    $rows = BitrixEntityMap::where('entity_type', 'deal')->where('woo_id', 101)->get();
    expect($rows)->toHaveCount(1);
    expect($rows->first()->bitrix_id)->toBe('34582');
    expect($rows->first()->created_via)->toBe(BitrixEntityMap::VIA_ADOPTED_LEGACY);
    expect($run->adopted_legacy_count)->toBe(1);
    expect($run->skipped_orders)->toBe(0);
});

it('12.1-04: a failed UF stamp still leaves the map row and counts as adopted', function (): void {
    Illuminate\Support\Facades\Log::spy();
    $bitrix = Mockery::mock(BitrixClient::class);
    $bitrix->shouldReceive('dealUpdate')->once()->andThrow(new App\Domain\CRM\Exceptions\BitrixPermanentException('field missing'));

    $run = runAdoptChunk(102, legacyOrderWithDealId(102, '555'), $bitrix);

    expect(BitrixEntityMap::where('woo_id', 102)->value('bitrix_id'))->toBe('555');
    expect($run->adopted_legacy_count)->toBe(1);
    expect($run->failed_orders)->toBe(0);
    Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->withArgs(fn ($m) => $m === 'BackfillOrdersChunkJob: legacy UF stamp failed');
});

it('12.1-04: legacyDealIds wins and adoption works when Woo returns no meta_data', function (): void {
    $bitrix = Mockery::mock(BitrixClient::class);
    $bitrix->shouldReceive('dealUpdate')->once()->withArgs(fn (string $id) => $id === '34001');

    $run = runAdoptChunk(185152, orderWithoutLegacyDealId(185152), $bitrix, [185152 => '34001']);

    expect(BitrixEntityMap::where('woo_id', 185152)->value('bitrix_id'))->toBe('34001');
    expect($run->adopted_legacy_count)->toBe(1);
});

function adoptCsv(string $body): string
{
    $path = tempnam(sys_get_temp_dir(), 'adopt');
    file_put_contents($path, $body);

    return $path;
}

it('12.1-04: --legacy-map-csv adopts both orders end to end without --since', function (): void {
    config(['queue.default' => 'sync']);
    $woo = Mockery::mock(WooClient::class);
    $woo->shouldReceive('get')->with('orders/185152')->andReturn(orderWithoutLegacyDealId(185152));
    $woo->shouldReceive('get')->with('orders/185264')->andReturn(orderWithoutLegacyDealId(185264));
    app()->instance(WooClient::class, $woo);
    $bitrix = Mockery::mock(BitrixClient::class);
    $bitrix->shouldReceive('dealUpdate')->twice();
    app()->instance(BitrixClient::class, $bitrix);

    $path = adoptCsv("order_id,deal_id\n185152,34001\n185264,34002\n");
    $this->artisan('bitrix:backfill-orders', ['--adopt-legacy-deal-ids' => true, '--legacy-map-csv' => $path])
        ->assertExitCode(0);
    @unlink($path);

    $maps = BitrixEntityMap::where('entity_type', 'deal')->orderBy('woo_id')->get();
    expect($maps->pluck('bitrix_id')->all())->toBe(['34001', '34002']);
    expect($maps->pluck('created_via')->unique()->all())->toBe([BitrixEntityMap::VIA_ADOPTED_LEGACY]);
});

it('12.1-04: a bad or duplicate CSV exits 1 and dispatches nothing', function (string $body): void {
    Queue::fake();
    $path = adoptCsv($body);

    $this->artisan('bitrix:backfill-orders', ['--adopt-legacy-deal-ids' => true, '--legacy-map-csv' => $path])
        ->assertExitCode(1);
    @unlink($path);

    Queue::assertNothingPushed();
    expect(BitrixBackfillRun::count())->toBe(0);
})->with([
    'non-numeric' => ["order_id,deal_id\n123,abc\n"],
    'duplicate order' => ["order_id,deal_id\n123,1\n123,2\n"],
    'bad header' => ["foo,bar\n123,1\n"],
]);

it('12.1-04: --legacy-map-csv without --adopt-legacy-deal-ids is INVALID', function (): void {
    $path = adoptCsv("order_id,deal_id\n1,2\n");
    $this->artisan('bitrix:backfill-orders', ['--legacy-map-csv' => $path])->assertExitCode(2);
    @unlink($path);
});
