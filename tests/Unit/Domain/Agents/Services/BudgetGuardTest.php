<?php

declare(strict_types=1);

use App\Domain\Agents\Exceptions\BudgetExceededException;
use App\Domain\Agents\Exceptions\MonthlyBudgetExceededException;
use App\Domain\Agents\Services\BudgetGuard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/*
|--------------------------------------------------------------------------
| Phase 8 Plan 03 Task 2 — BudgetGuard two-layer atomic enforcement (AGNT-04)
|--------------------------------------------------------------------------
|
| Verifies D-01..D-05 budget contract:
|   - daily soft caps per kind (D-03)
|   - monthly hard kill-switch atop daily (D-01/D-02)
|   - Europe/London day boundary (D-04)
|   - 100p default for unknown kinds (D-05)
|   - sequential-spend correctness via Cache::add SET-NX-EX semantics
|     (concurrency bounded by Horizon agents-supervisor maxProcesses=2;
|      Cache::lock NOT required for v2.0 — plan-checker iter 1 I11)
|
| Tests use the array cache driver from phpunit.xml — no Redis required.
| Pure unit tests; no DB / RefreshDatabase.
*/

beforeEach(function (): void {
    // Fresh array-driven cache per test so daily/monthly counters don't leak.
    Cache::flush();
    // Reset Carbon::now() in case a previous test set it.
    Carbon::setTestNow(null);
    // Lock the daily caps to known values for deterministic assertions.
    config([
        'agents.daily_caps' => [
            'echo' => 50,
            'pricing' => 500,
            'seo' => 300,
        ],
        'agents.default_daily_cap_pence' => 100,
        'agents.monthly_ceiling_pence' => 20000,
        'agents.day_boundary_timezone' => 'Europe/London',
    ]);
});

afterEach(function (): void {
    Carbon::setTestNow(null);
});

it('passes when no spend recorded yet (Test 1)', function (): void {
    $guard = app(BudgetGuard::class);

    expect(fn () => $guard->assertHasBudget('echo'))->not->toThrow(\Throwable::class);
});

it('throws BudgetExceededException when daily cap reached (Test 2)', function (): void {
    $guard = app(BudgetGuard::class);
    $guard->recordSpend('echo', 51);   // echo cap = 50p

    expect(fn () => $guard->assertHasBudget('echo'))
        ->toThrow(BudgetExceededException::class, 'Daily agent budget for echo');
});

it('throws MonthlyBudgetExceededException when monthly ceiling reached across kinds (Test 3)', function (): void {
    $guard = app(BudgetGuard::class);
    // Use a kind with a high daily cap so daily doesn't trip first.
    config(['agents.daily_caps.bigspender' => 50000]);
    $guard->recordSpend('bigspender', 20001);  // monthly cap = 20000p

    expect(fn () => $guard->assertHasBudget('bigspender'))
        ->toThrow(MonthlyBudgetExceededException::class, 'Monthly agent budget reached');
});

it('uses 100p default cap for unknown kind (Test 4 — D-05 fail-safe)', function (): void {
    $guard = app(BudgetGuard::class);
    $guard->recordSpend('newkind', 101);

    expect(fn () => $guard->assertHasBudget('newkind'))
        ->toThrow(BudgetExceededException::class, 'Daily agent budget for newkind');
});

it('day boundary follows Europe/London not UTC (Test 5 — D-04)', function (): void {
    // 2026-04-25 23:30 UTC = 2026-04-26 00:30 London (BST = UTC+1).
    Carbon::setTestNow('2026-04-25T23:30:00Z');
    $guard = app(BudgetGuard::class);

    expect($guard->dailyKey('echo'))->toContain('2026-04-26')
        ->and($guard->dailyKey('echo'))->not->toContain('2026-04-25');
});

it('sequential spends accumulate via Cache::add SET-NX-EX semantics (Test 6 — I11)', function (): void {
    // Plan-checker iter 1 I11: concurrency bounded by maxProcesses=2; the
    // contract this test enforces is sum-correctness over Cache::add semantics
    // (initialise once, then INCRBY linearly on each subsequent call).
    // Cache::lock is NOT required for v2.0.
    $guard = app(BudgetGuard::class);
    $guard->recordSpend('echo', 100);
    $guard->recordSpend('echo', 100);

    $key = $guard->dailyKey('echo');
    expect((int) Cache::get($key))->toBe(200);
});

it('hitting daily cap on one kind does NOT block another kind (Test 7)', function (): void {
    $guard = app(BudgetGuard::class);
    $guard->recordSpend('echo', 51);  // echo cap reached

    expect(fn () => $guard->assertHasBudget('seo'))->not->toThrow(\Throwable::class);
});

it('monthly kill-switch precedence: even brand-new kind blocked when monthly exhausted (Test 8)', function (): void {
    $guard = app(BudgetGuard::class);
    config(['agents.daily_caps.bigspender' => 50000]);
    $guard->recordSpend('bigspender', 20001);

    // 'fresh-kind' has 0 daily spend — daily cap WOULD pass — but monthly is hit.
    expect(fn () => $guard->assertHasBudget('fresh-kind'))
        ->toThrow(MonthlyBudgetExceededException::class);
});

it('recordSpend then assertHasBudget reads back accumulated spend (Test 9)', function (): void {
    $guard = app(BudgetGuard::class);
    $guard->recordSpend('echo', 25);
    expect(fn () => $guard->assertHasBudget('echo'))->not->toThrow(\Throwable::class);

    $guard->recordSpend('echo', 30);  // total = 55p > 50p cap
    expect(fn () => $guard->assertHasBudget('echo'))
        ->toThrow(BudgetExceededException::class);
});

it('Cache::add idempotence: counter initialised once, increments accumulate without double-init (Test 10)', function (): void {
    $guard = app(BudgetGuard::class);
    $guard->recordSpend('echo', 10);
    $guard->recordSpend('echo', 10);

    $key = $guard->dailyKey('echo');
    // If Cache::add re-initialised on the second call, the counter would be 10 (not 20).
    expect((int) Cache::get($key))->toBe(20);
});
