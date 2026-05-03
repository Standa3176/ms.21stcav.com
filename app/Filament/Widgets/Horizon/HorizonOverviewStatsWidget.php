<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Horizon;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;
use Laravel\Horizon\WaitTimeCalculator;

/**
 * Phase 7 Plan 02 — D-03 native Horizon Pages (post-09.1 follow-up #3).
 *
 * 8-tile overview matching Horizon's own /horizon dashboard:
 *   Row 1: Jobs/Min · Jobs Past Hour · Failed (7d) · Status
 *   Row 2: Total Processes · Max Wait · Max Runtime Queue · Max Throughput Queue
 *
 * Reads through the same repository contracts Horizon's own
 * DashboardStatsController uses, so values stay consistent across the
 * native Filament view and Horizon's native dashboard.
 *
 * Polling: 10s — matches Horizon's own dashboard refresh cadence (its
 * Vue front-end polls every 10s by default).
 *
 * RBAC: page-level canAccess on HorizonDashboardPage is the load-bearing
 * gate; widget canView is a duplicate admin check (defence in depth).
 */
final class HorizonOverviewStatsWidget extends StatsOverviewWidget
{
    protected static ?string $pollingInterval = '10s';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $jobsPerMinute = (int) app(MetricsRepository::class)->jobsProcessedPerMinute();
        $jobsPastHour = (int) app(JobRepository::class)->countRecent();
        $failedRecent = (int) app(JobRepository::class)->countRecentlyFailed();
        $status = $this->currentStatus();
        $processes = $this->totalProcessCount();
        $maxWait = $this->maxWaitTime();
        $queueWithMaxRuntime = (string) (app(MetricsRepository::class)->queueWithMaximumRuntime() ?: '—');
        $queueWithMaxThroughput = (string) (app(MetricsRepository::class)->queueWithMaximumThroughput() ?: '—');

        return [
            Stat::make('Jobs Per Minute', (string) $jobsPerMinute)
                ->description('Throughput right now')
                ->color($jobsPerMinute > 0 ? 'success' : 'gray'),
            Stat::make('Jobs Past Hour', (string) $jobsPastHour)
                ->description('Recent rolling window')
                ->color('primary'),
            Stat::make('Failed Jobs (7d)', (string) $failedRecent)
                ->description('Recently-failed window')
                ->color($failedRecent > 0 ? 'danger' : 'success'),
            Stat::make('Status', ucfirst($status))
                ->description('Master supervisor state')
                ->color(match ($status) {
                    'running' => 'success',
                    'paused' => 'warning',
                    default => 'danger',
                }),
            Stat::make('Total Processes', (string) $processes)
                ->description('Across all supervisors')
                ->color($processes > 0 ? 'success' : 'danger'),
            Stat::make('Max Wait Time', $maxWait === null ? '—' : "{$maxWait}s")
                ->description('Longest queue wait')
                ->color($maxWait !== null && $maxWait > 60 ? 'warning' : 'success'),
            Stat::make('Max Runtime Queue', $queueWithMaxRuntime)
                ->description('Slowest job per queue')
                ->color('primary'),
            Stat::make('Max Throughput Queue', $queueWithMaxThroughput)
                ->description('Busiest queue')
                ->color('primary'),
        ];
    }

    /**
     * Mirrors DashboardStatsController::currentStatus().
     */
    private function currentStatus(): string
    {
        $masters = app(MasterSupervisorRepository::class)->all();
        if (empty($masters)) {
            return 'inactive';
        }

        return collect($masters)
            ->every(fn ($master) => ($master->status ?? '') === 'paused')
                ? 'paused'
                : 'running';
    }

    /**
     * Mirrors DashboardStatsController::totalProcessCount().
     */
    private function totalProcessCount(): int
    {
        $supervisors = app(SupervisorRepository::class)->all();

        return (int) collect($supervisors)
            ->reduce(
                fn (int $carry, $supervisor): int => $carry + (int) collect($supervisor->processes ?? [])->sum(),
                0,
            );
    }

    /**
     * Mirrors DashboardStatsController::index() `wait` payload — first entry
     * of WaitTimeCalculator::calculate(). Returns null when no queue is
     * being measured (cold start).
     */
    private function maxWaitTime(): ?int
    {
        $waits = collect(app(WaitTimeCalculator::class)->calculate());
        if ($waits->isEmpty()) {
            return null;
        }

        return (int) $waits->take(1)->first();
    }
}
