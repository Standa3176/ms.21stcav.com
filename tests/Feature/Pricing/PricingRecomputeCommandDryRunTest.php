<?php

declare(strict_types=1);

use App\Domain\Pricing\Events\ProductPriceChanged;
use App\Domain\Pricing\Jobs\RecomputePriceJob;
use App\Domain\Products\Models\Product;
use Database\Seeders\Phase3\DefaultPricingTierSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 3 Plan 04 Task 2 — pricing:recompute command DRY-RUN tests.
|--------------------------------------------------------------------------
|
| D-12 default: `pricing:recompute --all` runs dry-run.
| Dry-run MUST:
|   - write nothing to products.sell_price
|   - dispatch no ProductPriceChanged events
|   - still dispatch RecomputePriceJob instances (persist=false) so Horizon
|     shows progress and the bulk command can surface a report
|
| --live and --dry-run together → command error (validation, not exception).
| --all alone → allowed (default scope + dry-run by default).
| No scope flag at all → command error ("One of --all / --only / ... required").
*/

beforeEach(function () {
    $this->seed(DefaultPricingTierSeeder::class);
});

// ══════════════════════════════════════════════════════════════════════════════
// Test D1 — default is dry-run; output contains "DRY-RUN", no writes, no events
// ══════════════════════════════════════════════════════════════════════════════

it('pricing:recompute --all (no --live) defaults to dry-run and performs no writes', function () {
    Event::fake([ProductPriceChanged::class]);
    Bus::fake([RecomputePriceJob::class]);

    Product::factory()->count(3)->create([
        'type' => 'simple',
        'brand_id' => null,
        'category_id' => null,
        'buy_price' => '50.0000',
        'sell_price' => '999.0000',  // deliberately wrong so a live run WOULD diff
    ]);

    $exit = $this->artisan('pricing:recompute', ['--all' => true])
        ->expectsOutputToContain('DRY-RUN')
        ->assertSuccessful()
        ->run();

    // Bus::fake caught the jobs so they never ran — but they were dispatched
    // with persist=false. Products remain untouched; no events fired.
    Bus::assertBatchCount(1);
    Event::assertNotDispatched(ProductPriceChanged::class);

    foreach (Product::all() as $p) {
        expect($p->sell_price)->toBe('999.0000');
    }
});

// ══════════════════════════════════════════════════════════════════════════════
// Test D2 — --dry-run explicit works identically to no flag
// ══════════════════════════════════════════════════════════════════════════════

it('pricing:recompute --all --dry-run behaves identically to the default (dry-run)', function () {
    Bus::fake([RecomputePriceJob::class]);

    Product::factory()->count(2)->create([
        'type' => 'simple',
        'brand_id' => null,
        'category_id' => null,
        'buy_price' => '50.0000',
        'sell_price' => '999.0000',
    ]);

    $this->artisan('pricing:recompute', ['--all' => true, '--dry-run' => true])
        ->expectsOutputToContain('DRY-RUN')
        ->assertSuccessful();

    Bus::assertBatched(function ($batch) {
        foreach ($batch->jobs as $job) {
            if (! $job instanceof RecomputePriceJob) {
                return false;
            }
            if ($job->persist !== false) {
                return false;
            }
        }

        return true;
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// Test D3 — --only scopes to exactly the SKUs named
// ══════════════════════════════════════════════════════════════════════════════

it('pricing:recompute --only=SKU1,SKU2 dispatches exactly 2 jobs (not the whole catalogue)', function () {
    Bus::fake([RecomputePriceJob::class]);

    Product::factory()->create([
        'sku' => 'ONLY-A',
        'type' => 'simple',
        'brand_id' => null,
        'category_id' => null,
        'buy_price' => '50.0000',
    ]);
    Product::factory()->create([
        'sku' => 'ONLY-B',
        'type' => 'simple',
        'brand_id' => null,
        'category_id' => null,
        'buy_price' => '50.0000',
    ]);
    Product::factory()->create([
        'sku' => 'NOT-SELECTED',
        'type' => 'simple',
        'brand_id' => null,
        'category_id' => null,
        'buy_price' => '50.0000',
    ]);

    $this->artisan('pricing:recompute', ['--only' => 'ONLY-A,ONLY-B'])
        ->assertSuccessful();

    Bus::assertBatched(function ($batch) {
        $jobs = collect($batch->jobs);
        if ($jobs->count() !== 2) {
            return false;
        }
        $skus = $jobs->map(fn ($j) => $j->sku)->sort()->values()->all();

        return $skus === ['ONLY-A', 'ONLY-B'];
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// Test D4 — report summary line structure
// ══════════════════════════════════════════════════════════════════════════════

it('pricing:recompute --all reports processed + mode in the summary output', function () {
    Bus::fake([RecomputePriceJob::class]);

    Product::factory()->count(4)->create([
        'type' => 'simple',
        'brand_id' => null,
        'category_id' => null,
        'buy_price' => '50.0000',
    ]);

    $this->artisan('pricing:recompute', ['--all' => true])
        ->expectsOutputToContain('processed')
        ->expectsOutputToContain('DRY-RUN')
        ->assertSuccessful();
});

// ══════════════════════════════════════════════════════════════════════════════
// Test D5 — --brand filter scopes jobs by brand_id
// ══════════════════════════════════════════════════════════════════════════════

it('pricing:recompute --brand=42 dispatches only jobs for products with brand_id=42', function () {
    Bus::fake([RecomputePriceJob::class]);

    Product::factory()->create([
        'sku' => 'BRAND-42-A',
        'type' => 'simple',
        'brand_id' => 42,
        'category_id' => null,
        'buy_price' => '50.0000',
    ]);
    Product::factory()->create([
        'sku' => 'BRAND-42-B',
        'type' => 'simple',
        'brand_id' => 42,
        'category_id' => null,
        'buy_price' => '50.0000',
    ]);
    Product::factory()->create([
        'sku' => 'BRAND-99-OTHER',
        'type' => 'simple',
        'brand_id' => 99,
        'category_id' => null,
        'buy_price' => '50.0000',
    ]);

    $this->artisan('pricing:recompute', ['--brand' => '42'])
        ->assertSuccessful();

    Bus::assertBatched(function ($batch) {
        $jobs = collect($batch->jobs);
        if ($jobs->count() !== 2) {
            return false;
        }
        $skus = $jobs->map(fn ($j) => $j->sku)->sort()->values()->all();

        return $skus === ['BRAND-42-A', 'BRAND-42-B'];
    });
});

// ══════════════════════════════════════════════════════════════════════════════
// Test D6 — --live + --dry-run together errors with "mutually exclusive"
// ══════════════════════════════════════════════════════════════════════════════

it('pricing:recompute --live --dry-run errors with "mutually exclusive"', function () {
    $this->artisan('pricing:recompute', ['--all' => true, '--live' => true, '--dry-run' => true])
        ->expectsOutputToContain('mutually exclusive')
        ->assertExitCode(2);  // Symfony INVALID
});

// ══════════════════════════════════════════════════════════════════════════════
// Test D7 — no scope flags → error "one of --all required"
// ══════════════════════════════════════════════════════════════════════════════

it('pricing:recompute with no scope flags errors telling the operator to pick a scope', function () {
    $this->artisan('pricing:recompute')
        ->expectsOutputToContain('--all')
        ->assertExitCode(2);
});
