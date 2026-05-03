<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Dashboard\Models\DashboardSnapshot;
use Carbon\Carbon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Phase 7 Plan 02 — Row 1 freshness tile: last completed supplier sync.
 *
 * Reads the `last_sync_run` metric_key from dashboard_snapshots (written by
 * `dashboard:refresh` every 5 min). Renders a single Stat with:
 *   - value: human-readable age ("30 minutes ago" / "Never")
 *   - description: duration + updated + failed counts
 *   - colour: green / amber / danger from age_traffic_light
 *   - extraAttributes: amber ring overlay when the snapshot itself is stale
 */
final class LastSyncRunWidget extends StatsOverviewWidget
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
        $snapshot = DashboardSnapshot::where('metric_key', 'last_sync_run')->first();

        // Empty-state — em-dash + friendly "No data yet" reads softer than
        // "Awaiting first dashboard:refresh" for non-engineer ops users.
        if ($snapshot === null || $snapshot->computed_at === null) {
            return [
                Stat::make('Last sync', '—')
                    ->description('No data yet')
                    ->descriptionIcon('heroicon-m-arrow-path')
                    ->color('gray'),
            ];
        }

        $payload = (array) $snapshot->metric_value_json;
        $lastCompletedAt = $payload['last_completed_at'] ?? null;
        $age = $lastCompletedAt ? Carbon::parse($lastCompletedAt)->diffForHumans() : 'Never';

        $description = sprintf(
            '%ss · %d updated · %d failed',
            (int) ($payload['duration_seconds'] ?? 0),
            (int) ($payload['updated_count'] ?? 0),
            (int) ($payload['failed_count'] ?? 0),
        );

        $color = match ($payload['age_traffic_light'] ?? 'red') {
            'green' => 'success',
            'amber' => 'warning',
            default => 'danger',
        };

        $stat = Stat::make('Last sync', $age)
            ->description($description)
            ->descriptionIcon('heroicon-m-arrow-path')
            ->color($color);

        if ($snapshot->isStale()) {
            $stat = $stat->extraAttributes(['class' => 'ring-2 ring-amber-400']);
        }

        return [$stat];
    }
}
