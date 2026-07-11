<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Filament\Widgets;

use App\Domain\Integrations\Models\GaChannelMetric;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

/**
 * Phase 15 Plan 15b-02 Task 3 — Marketing daily revenue trend (READ-ONLY).
 *
 * PURE PRESENTATION over ga_channel_metrics_daily (15a-02). Daily revenue (£ from
 * pennies) over the last 30 days as a single line dataset. No writes, no Google
 * calls, no data pull.
 *
 * Zero-safe / empty-state: with ZERO rows getData() returns
 * ['datasets' => [], 'labels' => []] — Filament renders an empty chart, never an
 * error (pennies → £ for DISPLAY only).
 *
 * Driver-portable: SUM + GROUP BY date + whereBetween('date', …) only (no
 * MySQL-only date functions) so SQLite tests and MariaDB prod agree.
 */
final class MarketingRevenueTrendChart extends ChartWidget
{
    /** Trailing-window length in days (inclusive of today). */
    private const WINDOW_DAYS = 30;

    protected static ?string $heading = 'Revenue trend (last 30 days)';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        // Mirror the GA4 Channels viewer — any authed workspace user may read.
        return auth()->user()?->can('viewAny', GaChannelMetric::class) ?? false;
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
        $from = Carbon::today()->subDays(self::WINDOW_DAYS - 1)->toDateString();
        $today = Carbon::today()->toDateString();

        $rows = GaChannelMetric::query()
            ->whereBetween('date', [$from, $today])
            ->select('date')
            ->selectRaw('SUM(purchase_revenue_pennies) as rev')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        if ($rows->isEmpty()) {
            return ['datasets' => [], 'labels' => []];
        }

        $labels = [];
        $data = [];
        foreach ($rows as $row) {
            // `date` is cast date:Y-m-d on the model → Carbon; format for the label.
            $labels[] = $row->date instanceof Carbon
                ? $row->date->format('Y-m-d')
                : (string) $row->date;
            // Pennies → £ for DISPLAY only.
            $data[] = round(((int) $row->rev) / 100, 2);
        }

        return [
            'datasets' => [[
                'label' => 'Revenue (£)',
                'data' => $data,
                'borderColor' => '#2563eb',
                'backgroundColor' => 'rgba(37, 99, 235, 0.1)',
                'fill' => true,
                'tension' => 0.2,
            ]],
            'labels' => $labels,
        ];
    }
}
