<?php

declare(strict_types=1);

use App\Domain\CRM\Jobs\BackfillOrdersChunkJob;
use App\Domain\CRM\Models\BitrixBackfillRun;
use App\Domain\Sync\Services\WooClient;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| Phase 4 Plan 05 Task 1 — bitrix:backfill-orders command tests.
|--------------------------------------------------------------------------
|
| Covers the three modes (dry-run / live / adopt-legacy-deal-ids),
| the --since required guard, --only-order-id surgical path,
| chunking + sleep pacing, and the bitrix_backfill_runs progress
| invariants.
*/

beforeEach(function (): void {
    config(['services.bitrix.write_enabled' => false]);
    config(['services.bitrix.webhook_url' => 'https://example.bitrix24.com/rest/1/fake-token/']);
});

function mockWooClientWithOrders(array $pages): \Mockery\MockInterface
{
    $woo = Mockery::mock(WooClient::class);
    $call = 0;
    foreach ($pages as $idx => $orders) {
        $woo->shouldReceive('get')
            ->with('orders', Mockery::on(fn ($query) => isset($query['page']) && $query['page'] === $idx + 1))
            ->andReturn($orders);
        $call++;
    }
    // Per-order fetch path (orders/{id}) used by chunk job. Build a map of id→order.
    $allOrders = [];
    foreach ($pages as $orders) {
        foreach ($orders as $o) {
            $allOrders[(int) $o['id']] = $o;
        }
    }
    $woo->shouldReceive('get')
        ->with(Mockery::on(fn ($endpoint) => is_string($endpoint) && str_starts_with($endpoint, 'orders/')), Mockery::any())
        ->andReturnUsing(function (string $endpoint) use ($allOrders) {
            $id = (int) substr($endpoint, strlen('orders/'));

            return $allOrders[$id] ?? null;
        });
    $woo->shouldReceive('get')
        ->with(Mockery::on(fn ($endpoint) => is_string($endpoint) && str_starts_with($endpoint, 'orders/')))
        ->andReturnUsing(function (string $endpoint) use ($allOrders) {
            $id = (int) substr($endpoint, strlen('orders/'));

            return $allOrders[$id] ?? null;
        });
    app()->instance(WooClient::class, $woo);

    return $woo;
}

function sampleOrder(int $id): array
{
    return [
        'id' => $id,
        'number' => (string) $id,
        'status' => 'pending',
        'total' => '199.99',
        'currency' => 'GBP',
        'customer_id' => 100 + $id,
        'billing' => [
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'email' => "jane+{$id}@example.com",
            'phone' => '+447700900111',
            'company' => '',
            'postcode' => 'SW1A 1AA',
            'country' => 'GB',
        ],
        'meta_data' => [],
    ];
}

// ══════════════════════════════════════════════════════════════════════════════
// Test 1 — --since is required
// ══════════════════════════════════════════════════════════════════════════════

it('exits 1 when --since is missing', function (): void {
    $this->artisan('bitrix:backfill-orders')
        ->expectsOutputToContain('--since is required')
        ->assertExitCode(1);

    expect(BitrixBackfillRun::count())->toBe(0);
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 2 — --since / --live mutual exclusion? --live + --dry-run are exclusive
// ══════════════════════════════════════════════════════════════════════════════

it('rejects --live and --dry-run combined', function (): void {
    $this->artisan('bitrix:backfill-orders', ['--since' => '2026-01-01', '--live' => true, '--dry-run' => true])
        ->expectsOutputToContain('mutually exclusive')
        ->assertExitCode(\Symfony\Component\Console\Command\Command::INVALID);
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 3 — default is dry-run
// ══════════════════════════════════════════════════════════════════════════════

it('defaults to dry-run when --live absent', function (): void {
    Queue::fake();

    mockWooClientWithOrders([
        [sampleOrder(1), sampleOrder(2)],  // page 1 — 2 orders
    ]);

    $this->artisan('bitrix:backfill-orders', ['--since' => '2026-01-01', '--chunk' => 50])
        ->expectsOutputToContain('DRY-RUN')
        ->assertExitCode(0);

    $run = BitrixBackfillRun::latest('id')->first();
    expect($run)->not->toBeNull();
    expect($run->mode)->toBe(BitrixBackfillRun::MODE_DRY_RUN);
    expect($run->total_orders)->toBe(2);
    expect($run->finished_at)->not->toBeNull();

    Queue::assertPushed(BackfillOrdersChunkJob::class, 1);
    Queue::assertPushed(BackfillOrdersChunkJob::class, function (BackfillOrdersChunkJob $job): bool {
        return $job->mode === BitrixBackfillRun::MODE_DRY_RUN
            && $job->orderIds === [1, 2]
            && $job->queue === 'sync-bulk';
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 4 — --live sets mode=live on the run
// ══════════════════════════════════════════════════════════════════════════════

it('--live sets mode=live on the BitrixBackfillRun', function (): void {
    Queue::fake();

    mockWooClientWithOrders([
        [sampleOrder(10)],
    ]);

    $this->artisan('bitrix:backfill-orders', ['--since' => '2026-01-01', '--live' => true, '--chunk' => 50])
        ->expectsOutputToContain('LIVE mode')
        ->assertExitCode(0);

    $run = BitrixBackfillRun::latest('id')->first();
    expect($run->mode)->toBe(BitrixBackfillRun::MODE_LIVE);

    Queue::assertPushed(BackfillOrdersChunkJob::class, function (BackfillOrdersChunkJob $job): bool {
        return $job->mode === BitrixBackfillRun::MODE_LIVE;
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 5 — --adopt-legacy-deal-ids takes priority over --live
// ══════════════════════════════════════════════════════════════════════════════

it('--adopt-legacy-deal-ids takes priority over --live', function (): void {
    Queue::fake();

    mockWooClientWithOrders([
        [sampleOrder(20)],
    ]);

    $this->artisan('bitrix:backfill-orders', [
        '--since' => '2026-01-01',
        '--live' => true,
        '--adopt-legacy-deal-ids' => true,
        '--chunk' => 50,
    ])
        ->expectsOutputToContain('ADOPT-LEGACY')
        ->assertExitCode(0);

    $run = BitrixBackfillRun::latest('id')->first();
    expect($run->mode)->toBe(BitrixBackfillRun::MODE_ADOPT_LEGACY);

    Queue::assertPushed(BackfillOrdersChunkJob::class, function (BackfillOrdersChunkJob $job): bool {
        return $job->mode === BitrixBackfillRun::MODE_ADOPT_LEGACY;
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 6 — --chunk controls the page-size
// ══════════════════════════════════════════════════════════════════════════════

it('--chunk=2 dispatches one BackfillOrdersChunkJob per 2-order page', function (): void {
    Queue::fake();

    mockWooClientWithOrders([
        [sampleOrder(1), sampleOrder(2)],  // page 1 (chunk 2)
        [sampleOrder(3)],                   // page 2 (partial — ends iteration)
    ]);

    $this->artisan('bitrix:backfill-orders', ['--since' => '2026-01-01', '--chunk' => 2, '--sleep-ms' => 0])
        ->assertExitCode(0);

    Queue::assertPushed(BackfillOrdersChunkJob::class, 2);
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 7 — --only-order-id=42 skips iteration
// ══════════════════════════════════════════════════════════════════════════════

it('--only-order-id=42 skips pagination and dispatches single chunk', function (): void {
    Queue::fake();

    // No pagination should happen; mock WooClient should NOT receive 'orders' with pagination.
    $woo = Mockery::mock(WooClient::class);
    $woo->shouldNotReceive('get')->with('orders', Mockery::any());
    app()->instance(WooClient::class, $woo);

    $this->artisan('bitrix:backfill-orders', ['--only-order-id' => ['42'], '--chunk' => 50])
        ->assertExitCode(0);

    Queue::assertPushed(BackfillOrdersChunkJob::class, function (BackfillOrdersChunkJob $job): bool {
        return $job->orderIds === [42];
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 8 — run record captures correlation_id + since_date
// ══════════════════════════════════════════════════════════════════════════════

it('records since_date + correlation_id + started_at/finished_at on the BitrixBackfillRun', function (): void {
    Queue::fake();

    mockWooClientWithOrders([
        [sampleOrder(50)],
    ]);

    $this->artisan('bitrix:backfill-orders', ['--since' => '2026-02-15', '--chunk' => 50])
        ->assertExitCode(0);

    $run = BitrixBackfillRun::latest('id')->first();
    expect($run->since_date->toDateString())->toBe('2026-02-15');
    expect($run->correlation_id)->not->toBeNull();
    expect($run->started_at)->not->toBeNull();
    expect($run->finished_at)->not->toBeNull();
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 9 — concurrent-run guard (T-04-05-02)
// ══════════════════════════════════════════════════════════════════════════════

it('refuses to start when an in-progress run of same mode is less than 1h old', function (): void {
    BitrixBackfillRun::factory()->create([
        'mode' => BitrixBackfillRun::MODE_DRY_RUN,
        'started_at' => now()->subMinutes(15),
        'finished_at' => null,
    ]);

    $this->artisan('bitrix:backfill-orders', ['--since' => '2026-01-01'])
        ->expectsOutputToContain('already in progress')
        ->assertExitCode(1);
});
