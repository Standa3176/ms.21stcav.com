<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Dashboard\Models\DashboardSnapshot;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Quick task 260606-lhp — Tile 2: "Decision queue health".
 *
 * Reads the SAME suggestions_triage_health snapshot Tile 1 reads (one
 * aggregator method, one DB round-trip per snapshot refresh). Renders 3
 * stacked stats — Applied (7d), Rejected (7d), Oldest pending age.
 *
 * Oldest pending turns warning-colored above 30 days — operator signal that
 * the queue is silently aging out (long-waiting rows often indicate the
 * suggestion pipeline has stalled or the operator triage is bottlenecked).
 *
 * No click-through — Tile 2 is informational only.
 *
 * RBAC: admin + pricing_manager only. Sales / read_only see silent absence.
 */
final class SuggestionsQueueHealthWidget extends StatsOverviewWidget
{
    protected static ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 1;

    public static function canView(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'pricing_manager']) ?? false;
    }

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $snapshot = DashboardSnapshot::where('metric_key', 'suggestions_triage_health')->first();
        $payload = is_array($snapshot?->metric_value_json) ? $snapshot->metric_value_json : [];

        $applied = (int) ($payload['applied_7d'] ?? 0);
        $rejected = (int) ($payload['rejected_7d'] ?? 0);
        $oldest = $payload['oldest_pending_days'] ?? null;

        $stale = $snapshot?->computed_at !== null && $snapshot->isStale();
        $ring = $stale ? ['class' => 'ring-2 ring-amber-400'] : [];

        $oldestLabel = $oldest === null
            ? '—'
            : sprintf('%d day%s', (int) $oldest, (int) $oldest === 1 ? '' : 's');

        $oldestColor = ($oldest !== null && (int) $oldest > 30) ? 'warning' : 'gray';

        return [
            Stat::make('Applied (7d)', number_format($applied))
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('success')
                ->extraAttributes($ring),
            Stat::make('Rejected (7d)', number_format($rejected))
                ->descriptionIcon('heroicon-m-x-circle')
                ->color('gray'),
            Stat::make('Oldest pending', $oldestLabel)
                ->description('Days since the longest-waiting pending NPO row')
                ->descriptionIcon('heroicon-m-clock')
                ->color($oldestColor),
        ];
    }
}
