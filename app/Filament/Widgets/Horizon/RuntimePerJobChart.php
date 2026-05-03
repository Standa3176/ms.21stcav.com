<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Horizon;

use Filament\Widgets\ChartWidget;
use Laravel\Horizon\Contracts\MetricsRepository;

/**
 * Phase 7 Plan 02 — D-03 native Horizon Pages (post-09.1 follow-up #3).
 *
 * Bar chart of average runtime (ms) per measured job class. Reads
 * MetricsRepository::measuredJobs() then runtimeForJob($name) — same
 * data Horizon's own /horizon/metrics page surfaces in its job table.
 *
 * Sorted descending by runtime (slowest job classes surface at the top).
 * Top 20 only — long tails get truncated to keep the chart legible.
 *
 * Polling 10s — matches Horizon's own dashboard refresh cadence.
 */
final class RuntimePerJobChart extends ChartWidget
{
    protected static ?string $heading = 'Avg Runtime per Job Class (ms)';

    protected static ?string $pollingInterval = '10s';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    protected function getType(): string
    {
        return 'bar';
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

        $rows = [];
        foreach ($jobs as $job) {
            $rows[$job] = (float) $metrics->runtimeForJob($job);
        }

        // Drop zero-runtime entries + sort desc + cap to top 20.
        $rows = array_filter($rows, fn (float $v): bool => $v > 0);
        arsort($rows);
        $rows = array_slice($rows, 0, 20, true);

        if ($rows === []) {
            return ['datasets' => [], 'labels' => []];
        }

        // Shorten FQCN labels to the basename so the chart x-axis stays readable.
        $labels = array_map(
            fn (string $fqcn): string => substr((string) strrchr('\\'.$fqcn, '\\'), 1) ?: $fqcn,
            array_keys($rows),
        );

        return [
            'datasets' => [[
                'label' => 'Avg runtime (ms)',
                'data' => array_map(fn (float $v): int => (int) round($v), array_values($rows)),
                'backgroundColor' => 'rgba(245, 158, 11, 0.6)',
                'borderColor' => 'rgb(245, 158, 11)',
                'borderWidth' => 1,
            ]],
            'labels' => $labels,
        ];
    }
}
