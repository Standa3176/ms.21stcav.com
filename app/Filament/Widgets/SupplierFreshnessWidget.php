<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Dashboard\Models\DashboardSnapshot;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Quick task 260608-g8x — supplier-feed freshness tile.
 *
 * Reads `supplier_freshness` metric_key (written every 5 min by
 * dashboard:refresh, sourced from the supplier_freshness_snapshots table
 * which `suppliers:check-stale` populates Mon-Fri 07:45 London).
 *
 * Mirrors CompetitorFreshnessWidget pattern + DashboardSnapshot policy
 * gating + StatsOverviewWidget polling.
 */
final class SupplierFreshnessWidget extends StatsOverviewWidget
{
    protected static ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 1;

    public function __construct()
    {
        static::$pollingInterval = (int) config('dashboard.widget_poll_seconds', 60).'s';
    }

    public static function canView(): bool
    {
        return auth()->user()?->can('viewAny', DashboardSnapshot::class) ?? false;
    }

    protected function getStats(): array
    {
        $snapshot = DashboardSnapshot::where('metric_key', 'supplier_freshness')->first();

        if ($snapshot === null || $snapshot->computed_at === null) {
            return [
                Stat::make('Suppliers', '—')
                    ->description('No data yet (suppliers:check-stale not run)')
                    ->descriptionIcon('heroicon-m-arrow-path')
                    ->color('gray'),
            ];
        }

        $payload = (array) $snapshot->metric_value_json;
        $threshold = (int) ($payload['threshold_default_days']
            ?? config('supplier.default_stale_after_days', 7));
        $fresh = (int) ($payload['fresh'] ?? 0);
        $amber = (int) ($payload['amber'] ?? 0);
        $stale = (int) ($payload['stale'] ?? 0);
        $unknown = (int) ($payload['unknown'] ?? 0);

        $isWidgetStale = $snapshot->isStale();
        $ring = $isWidgetStale ? ['class' => 'ring-2 ring-amber-400'] : [];

        return [
            Stat::make('Fresh suppliers', (string) $fresh)
                ->description(sprintf('Synced within last %dd', $threshold))
                ->descriptionIcon('heroicon-m-clock')
                ->color($fresh > 0 ? 'success' : 'gray')
                ->extraAttributes($ring),
            Stat::make('Amber suppliers', (string) $amber)
                ->description(sprintf('Within last 30%% of %dd window', $threshold))
                ->descriptionIcon('heroicon-m-clock')
                ->color($amber > 0 ? 'warning' : 'gray'),
            Stat::make('Stale suppliers', (string) $stale)
                ->description(sprintf('No sync >=%dd (stock-decayed)', $threshold))
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($stale > 0 ? 'danger' : 'gray'),
            Stat::make('Unknown / dormant', (string) $unknown)
                ->description('Never synced')
                ->descriptionIcon('heroicon-m-question-mark-circle')
                ->color('gray'),
        ];
    }
}
