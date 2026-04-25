<?php

declare(strict_types=1);

namespace App\Domain\Agents\Services;

use App\Domain\Agents\Exceptions\BudgetExceededException;
use App\Domain\Agents\Exceptions\MonthlyBudgetExceededException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Carbon;

/**
 * Phase 8 Plan 03 (AGNT-04) — two-layer atomic budget enforcement.
 *
 * Layer 1 — per-kind daily soft caps (D-03):
 *   pricing 500p / seo 300p / chatbot 200p-per-session / ad 300p / echo 50p
 *   (config('agents.daily_caps.{kind}'); default 100p per D-05).
 *
 * Layer 2 — global monthly hard ceiling kill-switch (D-01/D-02):
 *   £200/month default (config('agents.monthly_ceiling_pence', 20000)).
 *   Once reached, ALL new dispatches reject with MonthlyBudgetExceededException
 *   regardless of kind. In-flight runs complete normally per D-02.
 *
 * Day boundary is Europe/London (D-04) — matches v1 scheduling convention.
 * Cache key TTL aligns to next 00:00 London (so a UTC-midnight rollover
 * during BST does not prematurely reset the counter).
 *
 * Atomicity contract:
 *   - Cache::add($key, 0, $ttl) is SET NX EX at the Redis layer (predis ships
 *     the SET NX EX command); initialises the counter exactly once per period.
 *   - Cache::increment($key, $cost) is INCRBY at the Redis layer.
 *
 * Sequential workers calling recordSpend() produce a correct sum because both
 * increments land linearly on Redis. The reserve-vs-spend gap (post-flight
 * increment) means a tiny over-budget window is possible if 2 concurrent
 * runs both pass assertHasBudget then both spend — bounded by the
 * agents-supervisor maxProcesses=2 cap (Plan 01) and the withMaxSteps(8)
 * per-run loop ceiling, so worst-case overspend per kind ≤ (1 extra run ×
 * max single-call cost ≈ 5p). Acceptable per CONTEXT D-01 + plan-checker
 * iter 1 I11. Cache::lock NOT required for v2.0; re-evaluate at v2.1 if
 * maxProcesses raises above 2 OR average call cost exceeds £0.50.
 *
 * Method contract (Plan 04 RunAgentJob calls these in this order):
 *   1. assertHasBudget(kind)               — pre-flight; throws on cap breach
 *   2. ClaudeClient::generate(...)         — actual Anthropic call
 *   3. recordSpend(kind, costPence)        — post-flight; updates counters
 */
final class BudgetGuard
{
    public function __construct(private readonly CacheRepository $cache) {}

    /**
     * Pre-flight cap check — kill-switch FIRST so monthly precedence holds
     * (D-02): once the £200 ceiling fires, every kind is blocked regardless
     * of its own daily spend.
     */
    public function assertHasBudget(string $kind): void
    {
        $monthlySpent = (int) $this->cache->get($this->monthlyKey(), 0);
        $monthlyCap = (int) config('agents.monthly_ceiling_pence', 20000);
        if ($monthlySpent >= $monthlyCap) {
            throw new MonthlyBudgetExceededException(
                "Monthly agent budget reached: {$monthlySpent}p / {$monthlyCap}p. "
                .'Review at /admin/agent-runs or raise via ops command.'
            );
        }

        $dailySpent = (int) $this->cache->get($this->dailyKey($kind), 0);
        $dailyCap = $this->dailyCapFor($kind);
        if ($dailySpent >= $dailyCap) {
            throw new BudgetExceededException(
                "Daily agent budget for {$kind}: {$dailySpent}p / {$dailyCap}p"
            );
        }
    }

    /**
     * Post-flight spend recording — initialises both counters atomically
     * (Cache::add is no-op if key exists), then INCRBYs by costPence.
     *
     * Negative or zero costs are skipped so a botched cost calculation
     * never decrements the budget. Plan 02's CostCalculator throws
     * RuntimeException on unknown model so 0 is the only "skip" path.
     */
    public function recordSpend(string $kind, int $costPence): void
    {
        if ($costPence <= 0) {
            return;
        }

        $dailyKey = $this->dailyKey($kind);
        $monthlyKey = $this->monthlyKey();

        $this->cache->add($dailyKey, 0, $this->ttlUntilNextDay());
        $this->cache->add($monthlyKey, 0, $this->ttlUntilNextMonth());

        $this->cache->increment($dailyKey, $costPence);
        $this->cache->increment($monthlyKey, $costPence);
    }

    public function dailyCapFor(string $kind): int
    {
        $explicit = config("agents.daily_caps.{$kind}");
        if ($explicit !== null) {
            return (int) $explicit;
        }

        return (int) config('agents.default_daily_cap_pence', 100);
    }

    public function dailyKey(string $kind): string
    {
        $tz = (string) config('agents.day_boundary_timezone', 'Europe/London');
        $date = Carbon::now($tz)->format('Y-m-d');

        return "agents.daily.{$kind}.{$date}";
    }

    public function monthlyKey(): string
    {
        $tz = (string) config('agents.day_boundary_timezone', 'Europe/London');
        $month = Carbon::now($tz)->format('Y-m');

        return "agents.monthly.{$month}";
    }

    private function ttlUntilNextDay(): int
    {
        $tz = (string) config('agents.day_boundary_timezone', 'Europe/London');
        $now = Carbon::now($tz);

        return (int) $now->copy()->endOfDay()->diffInSeconds($now) + 1;
    }

    private function ttlUntilNextMonth(): int
    {
        $tz = (string) config('agents.day_boundary_timezone', 'Europe/London');
        $now = Carbon::now($tz);

        return (int) $now->copy()->endOfMonth()->diffInSeconds($now) + 1;
    }
}
