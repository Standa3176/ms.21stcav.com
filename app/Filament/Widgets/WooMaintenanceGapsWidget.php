<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Products\Services\ProductGapReport;
use App\Filament\Pages\CatalogueGapsPage;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

/**
 * Quick task 260707-w2w — header widget for the Woo Maintenance Overview.
 *
 * Quick task 260708-akz — auto-poll DISABLED ($pollingInterval = null): the
 * widget is auto-discovered onto the Dashboard and the 30s poll re-ran
 * counts() on a timer; the gap totals move on the daily sync cadence.
 *
 * Quick task 260707-wa9 — each per-gap Stat ->url()s into CatalogueGapsPage
 * pre-filtered to that gap via the Filament SelectFilter form-state deep-link.
 *
 * Quick task 260708-cey — PASS 2 REWIRE. counts() now reports the TRUE
 * whole-shop state from the reconciled woo_* mirror plus coverage/freshness,
 * so the Overview shows:
 *   - a leading "Live on Woo" total,
 *   - a "Reconciled X / Y" coverage stat with the last-reconciled time
 *     (warning if anything is un-reconciled or reconciliation never ran —
 *     prompts the operator to run products:reconcile-woo-maintenance), and
 *   - the 3 real gap stats (missing images / EAN / category), each linking
 *     into the pre-filtered Catalogue Gaps drill-down.
 */
final class WooMaintenanceGapsWidget extends StatsOverviewWidget
{
    protected static ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $counts = app(ProductGapReport::class)->counts();

        $total = (int) $counts['total'];
        $reconciled = (int) $counts['reconciled'];
        $notReconciled = (int) $counts['not_reconciled'];
        $lastReconciledAt = $counts['last_reconciled_at'] ?? null;

        $freshness = $lastReconciledAt !== null
            ? 'last: '.Carbon::parse($lastReconciledAt)->diffForHumans()
            : 'never — run products:reconcile-woo-maintenance';

        $stats = [
            Stat::make('Live on Woo', (string) $total)
                ->description('Products live on the shop (publish + Woo ID)')
                ->descriptionIcon('heroicon-m-globe-alt')
                ->color('primary')
                ->url(CatalogueGapsPage::getUrl()),

            Stat::make('Reconciled', $reconciled.' / '.$total)
                ->description($freshness)
                ->descriptionIcon('heroicon-m-arrow-path')
                ->color(($notReconciled > 0 || $reconciled === 0) ? 'warning' : 'success'),
        ];

        foreach (ProductGapReport::GAPS as $key => $label) {
            $count = (int) ($counts['gaps'][$key] ?? 0);

            $stats[] = Stat::make($label, (string) $count)
                ->description(sprintf('of %s reconciled products', number_format($reconciled)))
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($count > 0 ? 'warning' : 'success')
                ->url(CatalogueGapsPage::getUrl().'?tableFilters[gap][value]='.$key);
        }

        return $stats;
    }
}
