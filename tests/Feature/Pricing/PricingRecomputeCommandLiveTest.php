<?php

declare(strict_types=1);

use App\Domain\Pricing\Jobs\RecomputePriceJob;
use App\Domain\Products\Models\Product;
use Database\Seeders\Phase3\DefaultPricingTierSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 3 Plan 04 Task 2 — pricing:recompute command LIVE mode tests.
|--------------------------------------------------------------------------
|
| --live opts into persist=true at the job level. That flag flows through
| RecomputePriceJob → PriceRecomputer::recompute(persist=true) → writes
| products.sell_price + emits ProductPriceChanged on diff.
|
| The command output carries a "LIVE" banner + WOO_WRITE_ENABLED caveat so
| operators have a visual confirmation of the mode they just invoked.
*/

beforeEach(function () {
    $this->seed(DefaultPricingTierSeeder::class);
});

// ══════════════════════════════════════════════════════════════════════════════
// Test L1 — --live dispatches RecomputePriceJob instances with persist=true
// ══════════════════════════════════════════════════════════════════════════════

it('pricing:recompute --all --live dispatches jobs with persist=true', function () {
    Bus::fake([RecomputePriceJob::class]);

    Product::factory()->count(3)->create([
        'type' => 'simple',
        'brand_id' => null,
        'category_id' => null,
        'buy_price' => '50.0000',
    ]);

    $this->artisan('pricing:recompute', ['--all' => true, '--live' => true])
        ->expectsOutputToContain('LIVE')
        ->assertSuccessful();

    Bus::assertBatched(function ($batch) {
        if (count($batch->jobs) !== 3) {
            return false;
        }
        foreach ($batch->jobs as $job) {
            if (! $job instanceof RecomputePriceJob) {
                return false;
            }
            if ($job->persist !== true) {
                return false;
            }
        }

        return true;
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// Test L2 — --live --only=SKU dispatches one job with persist=true
// ══════════════════════════════════════════════════════════════════════════════

it('pricing:recompute --only=SKU --live dispatches exactly one persist=true job', function () {
    Bus::fake([RecomputePriceJob::class]);

    Product::factory()->create([
        'sku' => 'LIVE-ONLY-001',
        'type' => 'simple',
        'brand_id' => null,
        'category_id' => null,
        'buy_price' => '50.0000',
    ]);
    Product::factory()->create([
        'sku' => 'LIVE-OTHER-001',
        'type' => 'simple',
        'brand_id' => null,
        'category_id' => null,
        'buy_price' => '50.0000',
    ]);

    $this->artisan('pricing:recompute', ['--only' => 'LIVE-ONLY-001', '--live' => true])
        ->expectsOutputToContain('LIVE')
        ->assertSuccessful();

    Bus::assertBatched(function ($batch) {
        if (count($batch->jobs) !== 1) {
            return false;
        }
        $job = $batch->jobs[0];

        return $job->sku === 'LIVE-ONLY-001' && $job->persist === true;
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// Test L3 — batch routes to sync-bulk queue
// ══════════════════════════════════════════════════════════════════════════════

it('pricing:recompute --all --live routes the batch onto sync-bulk queue', function () {
    Bus::fake([RecomputePriceJob::class]);

    Product::factory()->count(2)->create([
        'type' => 'simple',
        'brand_id' => null,
        'category_id' => null,
        'buy_price' => '50.0000',
    ]);

    $this->artisan('pricing:recompute', ['--all' => true, '--live' => true])
        ->assertSuccessful();

    // Every job in the batch carries the sync-bulk queue assignment (set
    // in the RecomputePriceJob constructor — the batch dispatcher preserves it).
    Bus::assertBatched(function ($batch) {
        foreach ($batch->jobs as $job) {
            if ($job->queue !== 'sync-bulk') {
                return false;
            }
        }

        return true;
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// Test L4 — LIVE banner + WOO_WRITE_ENABLED warning
// ══════════════════════════════════════════════════════════════════════════════

it('pricing:recompute --live output carries LIVE banner and WOO_WRITE_ENABLED warning', function () {
    Bus::fake([RecomputePriceJob::class]);

    Product::factory()->create([
        'type' => 'simple',
        'brand_id' => null,
        'category_id' => null,
        'buy_price' => '50.0000',
    ]);

    $this->artisan('pricing:recompute', ['--all' => true, '--live' => true])
        ->expectsOutputToContain('LIVE')
        ->expectsOutputToContain('WOO_WRITE_ENABLED')
        ->assertSuccessful();
});
