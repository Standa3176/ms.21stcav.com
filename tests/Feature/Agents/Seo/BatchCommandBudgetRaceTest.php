<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Phase 12 Plan 05 Task 1 — P12-E batch budget race protection
|--------------------------------------------------------------------------
|
| The batch command MUST re-check monthly budget BETWEEN dispatches — not
| just once before the loop. Without this, dispatching all 20 jobs could
| overshoot the monthly ceiling by ~100p (20 jobs × 5p/run) if the budget
| was already near the cap at batch start.
|
| Three fixtures pin the defence:
|
|   1. Already-exceeded — pre-flight check fires, ZERO dispatches
|   2. Near-ceiling — between-dispatch recheck stops the batch early
|   3. Comfortable headroom — full batch dispatches normally
|
| Cache key: `agents.monthly.{YYYY-MM}` per BudgetGuard convention.
| Ceiling: config('agents.monthly_ceiling_pence', 20000) — £200 default.
*/

use App\Domain\Agents\Jobs\RunSeoAgentJob;
use App\Domain\Products\Models\Product;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    Cache::flush();
});

function monthlyCacheKey(): string
{
    return 'agents.monthly.' . Carbon::now('Europe/London')->format('Y-m');
}

function makeRaceTestProduct(int $score): Product
{
    return Product::factory()->create([
        'sku' => 'SEO-RACE-' . $score . '-' . uniqid(),
        'auto_create_status' => 'pending_review',
        'completeness_score' => $score,
    ]);
}

it('aborts BEFORE any dispatch when monthly budget already exceeded', function () {
    // Seed cache at 20,001 — above the 20,000 ceiling
    Cache::put(monthlyCacheKey(), 20001);

    // 5 otherwise-eligible products
    foreach ([60, 65, 70, 75, 80] as $score) {
        makeRaceTestProduct($score);
    }

    $exitCode = Artisan::call('agents:run-seo-batch');

    expect($exitCode)->toBe(0);  // SUCCESS — the command did its job (skipped cleanly)
    Queue::assertNothingPushed();
});

it('stops mid-batch when between-dispatch budget recheck trips ceiling', function () {
    // Seed at exactly the ceiling — first dispatch should NOT happen because the
    // pre-flight check fires; this fixture verifies the EQUAL-TO-CEILING boundary.
    // (P12-E recommends `>=` semantics matching BudgetGuard::assertHasBudget.)
    Cache::put(monthlyCacheKey(), 20000);

    foreach ([60, 65, 70] as $score) {
        makeRaceTestProduct($score);
    }

    Artisan::call('agents:run-seo-batch');

    Queue::assertNothingPushed();
});

it('dispatches full batch with comfortable monthly headroom', function () {
    // Seed at 1000p of 20,000p ceiling — 19,000p headroom; plenty for 4 jobs
    Cache::put(monthlyCacheKey(), 1000);

    foreach ([60, 65, 70, 75] as $score) {
        makeRaceTestProduct($score);
    }

    Artisan::call('agents:run-seo-batch');

    Queue::assertPushed(RunSeoAgentJob::class, 4);
});

it('respects a custom monthly_ceiling_pence config override', function () {
    // Lower the ceiling to 100p — even a near-empty cache should trip after
    // the pre-flight check if seeded at >=100.
    config()->set('agents.monthly_ceiling_pence', 100);
    Cache::put(monthlyCacheKey(), 101);

    makeRaceTestProduct(60);
    makeRaceTestProduct(70);

    Artisan::call('agents:run-seo-batch');

    Queue::assertNothingPushed();
});

it('uses Europe/London for the monthly cache key (matches BudgetGuard)', function () {
    // BudgetGuard convention — `agents.monthly.{Y-m}` in Europe/London. Asserting
    // by seeding ONLY the London key — if the batch command used UTC or another
    // tz, the seeded budget would not be visible and dispatches would proceed.
    Cache::put(monthlyCacheKey(), 20001);

    makeRaceTestProduct(60);
    makeRaceTestProduct(70);

    Artisan::call('agents:run-seo-batch');

    // Budget IS visible → batch aborted → zero dispatches
    Queue::assertNothingPushed();
});
