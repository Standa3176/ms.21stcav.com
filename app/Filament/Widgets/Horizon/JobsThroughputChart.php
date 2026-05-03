<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Horizon;

use Filament\Widgets\ChartWidget;
use Laravel\Horizon\Contracts\MetricsRepository;

/**
 * Phase 7 Plan 02 — D-03 native Horizon Pages (post-09.1 follow-up #3).
 *
 * Line chart of jobs-per-minute throughput over the last 24h, summed
 * across every measured job class (MetricsRepository::measuredJobs() →
 * snapshotsForJob() per class). Mirrors Horizon's own /horizon/metrics
 * "Jobs per Minute" series.
 *
 * Data shape per snapshot (per Horizon source):
 *   { time: int (unix), runtime: float (ms), throughput: int }
 *
 * X-axis: snapshot timestamps formatted as H:i
 * Y-axis: total throughput (jobs/min) summed across job classes
 *
 * Polling 10s — matches Horizon's own dashboard refresh cadence.
 */
final class JobsThroughputChart extends ChartWidget
{
    protected static ?string $heading = 'Jobs Throughput (24h)';

    protected static ?string $pollingInterval = '10s';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    protected function getType(): string
    {
        return 'line';
    }

    /**
     * @return array{datasets: array<int, array<string, mixed>>, labels: array<int, string>}
     */
    protected function getData(): array
    {
        $metrics = app(MetricsRepository::class);
        $jobs = $metrics->measuredJobs();

        if (empty($jobs)) {
            return ['datasets' => [], 'labels' => []];
        }

        // Sum throughput across all job classes per timestamp.
        $perTime = [];
        foreach ($jobs as $job) {
            $snapshots = (array) $metrics->snapshotsForJob($job);
            foreach ($snapshots as $snap) {
                $time = (int) ($snap->time ?? 0);
                if ($time === 0) {
                    continue;
                }
                $perTime[$time] = ($perTime[$time] ?? 0) + (int) ($snap->throughput ?? 0);
            }
        }

        if ($perTime === []) {
            return ['datasets' => [], 'labels' => []];
        }

        ksort($perTime);

        $labels = array_map(
            fn (int $time): string => date('H:i', $time),
            array_keys($perTime),
        );

        return [
            'datasets' => [[
                'label' => 'Throughput (jobs/min)',
                'data' => array_values($perTime),
                'borderColor' => 'rgb(59, 130, 246)',
                'backgroundColor' => 'rgba(59, 130, 246, 0.15)',
                'fill' => true,
                'tension' => 0.3,
            ]],
            'labels' => $labels,
        ];
    }
}
