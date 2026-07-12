<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Filament\Widgets;

use App\Domain\Integrations\Models\GaChannelMetric;
use App\Domain\Integrations\Support\MarketingDateRange;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Support\Carbon;

/**
 * Phase 15 Plan 15b-02 Task 3 — Marketing daily revenue trend (READ-ONLY).
 *
 * PURE PRESENTATION over ga_channel_metrics_daily (15a-02). Daily revenue (£ from
 * pennies) over the window chosen in the page's date-range filter (260712-mdr;
 * default 90d) as a single line dataset. No writes, no Google calls, no data pull.
 *
 * Zero-safe / empty-state: with ZERO rows getData() returns
 * ['datasets' => [], 'labels' => []] — Filament renders an empty chart, never an
 * error (pennies → £ for DISPLAY only).
 *
 * Driver-portable: SUM + GROUP BY date + whereBetween('date', …) only (no
 * MySQL-only date functions) so SQLite tests and MariaDB prod agree. The window
 * is resolved through the shared MarketingDateRange resolver so this chart and
 * the overview stats always agree on the same [from, to].
 */
final class MarketingRevenueTrendChart extends ChartWidget
{
    // 260712-mdr — receive the page's `filters` state (range/from/to).
    use InteractsWithPageFilters;

    protected int|string|array $columnSpan = 'full';

    public function getHeading(): string
    {
        return 'Revenue trend · '.$this->resolveRange()->label;
    }

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
        $range = $this->resolveRange();

        $rows = GaChannelMetric::query()
            ->whereBetween('date', [$range->from, $range->to])
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

    /** Resolve the page-filter state to a concrete window (shared resolver). */
    private function resolveRange(): MarketingDateRange
    {
        return MarketingDateRange::resolve(
            $this->filters['range'] ?? null,
            $this->filters['from'] ?? null,
            $this->filters['to'] ?? null,
        );
    }
}
