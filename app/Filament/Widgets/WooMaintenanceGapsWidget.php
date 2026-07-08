<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Products\Services\ProductGapReport;
use App\Filament\Pages\CatalogueGapsPage;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Quick task 260707-w2w — header widget for the Woo Maintenance Overview
 * page. Renders one Stat card per catalogue gap (missing images / EAN /
 * stock status / brand / category) plus a leading "Live on Woo" total,
 * all read from the shared, 300s-cached ProductGapReport::counts() — so
 * this overview and the Pass-2 drill-down list stay in lock-step.
 *
 * A gap card is 'warning' when its count > 0 (something to fix), 'success'
 * when 0.
 *
 * Quick task 260708-akz — auto-poll DISABLED ($pollingInterval = null).
 * This widget is auto-discovered onto the Dashboard; the StatsOverviewWidget
 * default 30s poll re-ran counts() on a timer and, before the query was made
 * cheap, hung the admin. counts() is now one cheap cached aggregate, but the
 * poll stays off — the gap totals move on the daily sync cadence, not per-30s.
 *
 * Quick task 260707-wa9 (Pass 2) — each per-gap Stat now ->url()s into the
 * CatalogueGapsPage drill-down pre-filtered to that gap via the Filament
 * SelectFilter form-state deep-link (?tableFilters[gap][value]=<gap>), so
 * clicking e.g. "Missing EAN" opens the list of exactly those products.
 * Deep-link precedent: HighConfidenceSourceableWidget. The 'Live on Woo'
 * total links to the page unfiltered (defaults to missing_images there).
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
                ->color('primary')
                ->url(CatalogueGapsPage::getUrl()),
        ];

        foreach (ProductGapReport::GAPS as $key => $label) {
            $count = (int) ($counts['gaps'][$key] ?? 0);

            $stats[] = Stat::make($label, (string) $count)
                ->description(sprintf('of %s live products', number_format($total)))
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($count > 0 ? 'warning' : 'success')
                ->url(CatalogueGapsPage::getUrl().'?tableFilters[gap][value]='.$key);
        }

        return $stats;
    }
}
