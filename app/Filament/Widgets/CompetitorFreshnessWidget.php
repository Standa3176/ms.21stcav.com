<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Dashboard\Models\DashboardSnapshot;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Phase 7 Plan 02 — Row 1 freshness tile: competitor-feed ingest traffic-light.
 *
 * Reads `competitor_freshness` metric_key. Threshold is sourced from
 * `config('competitor.stale_feed_hours', 48)` inside SnapshotAggregator —
 * widget just renders the pre-bucketed counts.
 *
 * Mirrors the existing Phase 5 StaleFeedTrafficLight widget (Competitor
 * analysis page header) but reads from the snapshot table instead of the
 * live Competitor query, so this widget adds no DB load at page render time.
 */
final class CompetitorFreshnessWidget extends StatsOverviewWidget
{
    protected static ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 1;

    public function __construct()
    {
        static::$pollingInterval = (int) config('dashboard.widget_poll_seconds', 60) . 's';
    }

    public static function canView(): bool
    {
        return auth()->user()?->can('viewAny', DashboardSnapshot::class) ?? false;
    }

    protected function getStats(): array
    {
        $snapshot = DashboardSnapshot::where('metric_key', 'competitor_freshness')->first();

        if ($snapshot === null || $snapshot->computed_at === null) {
            return [
                Stat::make('Competitor feeds', '—')
                    ->description('No data yet')
                    ->descriptionIcon('heroicon-m-arrow-path')
                    ->color('gray'),
            ];
        }

        $payload = (array) $snapshot->metric_value_json;
        $threshold = (int) ($payload['threshold_hours'] ?? config('competitor.stale_feed_hours', 48));
        $missing = (int) ($payload['missing'] ?? 0);
        $staleCount = (int) ($payload['stale'] ?? 0);
        $fresh = (int) ($payload['fresh'] ?? 0);

        $stale = $snapshot->isStale();
        $ring = $stale ? ['class' => 'ring-2 ring-amber-400'] : [];

        return [
            Stat::make('Fresh feeds', (string) $fresh)
                ->description(sprintf('Last ingest <%dh ago', $threshold))
                ->descriptionIcon('heroicon-m-clock')
                ->color($fresh > 0 ? 'success' : 'gray')
                ->extraAttributes($ring),
            Stat::make('Stale feeds', (string) $staleCount)
                ->description(sprintf('Last ingest >=%dh ago', $threshold))
                ->descriptionIcon('heroicon-m-clock')
                ->color($staleCount > 0 ? 'warning' : 'gray'),
            Stat::make('Missing feeds', (string) $missing)
                ->description('No ingest recorded')
                ->descriptionIcon('heroicon-m-clock')
                ->color($missing > 0 ? 'danger' : 'gray'),
        ];
    }
}
