<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Products\Services\ProductGapReport;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Quick task 260707-w2w — header widget for the Woo Maintenance Overview
 * page. Renders one Stat card per catalogue gap (missing images / EAN /
 * stock status / brand / category) plus a leading "Live on Woo" total,
 * all read from the shared, 60s-cached ProductGapReport::counts() — so
 * this overview and the Pass-2 drill-down list stay in lock-step.
 *
 * A gap card is 'warning' when its count > 0 (something to fix), 'success'
 * when 0. No ->url() yet — Pass 2 wires the stat cards to the drill-down.
 */
final class WooMaintenanceGapsWidget extends StatsOverviewWidget
{
    protected static ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $counts = app(ProductGapReport::class)->counts();
        $total = (int) $counts['total'];

        $stats = [
            Stat::make('Live on Woo', (string) $total)
                ->description('Products live on the shop (publish + Woo ID)')
                ->descriptionIcon('heroicon-m-globe-alt')
                ->color('primary'),
        ];

        foreach (ProductGapReport::GAPS as $key => $label) {
            $count = (int) ($counts['gaps'][$key] ?? 0);

            $stats[] = Stat::make($label, (string) $count)
                ->description(sprintf('of %s live products', number_format($total)))
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($count > 0 ? 'warning' : 'success');
        }

        return $stats;
    }
}
