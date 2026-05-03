<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Dashboard\Models\DashboardSnapshot;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Phase 7 Plan 02 — Row 2 actions tile: Horizon failed-jobs counter.
 *
 * Reads `horizon_failed_jobs` metric_key. 2 tiles (5-min + 24h windows).
 * The 5-min tile is the tight ops pulse — anything > 0 warrants immediate
 * investigation via /horizon/failed (link surfaced via HorizonLinkNavigationItem).
 */
final class HorizonFailedJobsWidget extends StatsOverviewWidget
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
        $snapshot = DashboardSnapshot::where('metric_key', 'horizon_failed_jobs')->first();

        if ($snapshot === null || $snapshot->computed_at === null) {
            return [
                Stat::make('Horizon failures', '—')
                    ->description('No data yet')
                    ->descriptionIcon('heroicon-m-arrow-path')
                    ->color('gray'),
            ];
        }

        $payload = (array) $snapshot->metric_value_json;
        $fiveMin = (int) ($payload['last_5_min'] ?? 0);
        $day = (int) ($payload['last_24_hours'] ?? 0);

        // Threshold logic: 0 success, 1–10 warning, >10 danger. The 5-min window
        // is the tighter pulse — anything > 0 is treated as warning+ regardless
        // of magnitude (immediate-attention semantics from the original code).
        $dayColor = $day === 0 ? 'success' : ($day <= 10 ? 'warning' : 'danger');
        $fiveMinColor = $fiveMin === 0 ? 'success' : ($fiveMin <= 10 ? 'warning' : 'danger');

        $stale = $snapshot->isStale();
        $ring = $stale ? ['class' => 'ring-2 ring-amber-400'] : [];

        return [
            Stat::make('Failed (last 5 min)', (string) $fiveMin)
                ->description('Immediate attention')
                ->descriptionIcon('heroicon-m-x-circle')
                ->color($fiveMinColor)
                ->extraAttributes($ring),
            Stat::make('Failed (24h)', (string) $day)
                ->description('Rolling 24h window')
                ->descriptionIcon('heroicon-m-x-circle')
                ->color($dayColor),
        ];
    }
}
