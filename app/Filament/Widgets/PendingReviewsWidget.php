<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Dashboard\Models\DashboardSnapshot;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Phase 7 Plan 02 — Row 2 actions tile: pending reviews.
 *
 * Reads `pending_reviews` metric_key. Renders 2 Stats — Auto-create drafts +
 * Margin changes. Each tile is a distinct, non-redundant signal.
 *
 * Quick task 260606-lhp REMOVED the third "New product opportunities" Stat
 * because the HighConfidenceSourceableWidget tile (added in the same task)
 * carries that signal in a decision-grade form (high-confidence • sourceable
 * • raw-pending breakdown) instead of the raw 14k pending count that hid the
 * 5.4k actionable rows under 8.8k competitor-only orphans. The
 * `new_product_opportunity_suggestions` key stays in the snapshot payload
 * (SnapshotAggregator::computePendingReviews still computes it — other
 * consumers may read it); only the widget rendering is dropped.
 */
final class PendingReviewsWidget extends StatsOverviewWidget
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
        $snapshot = DashboardSnapshot::where('metric_key', 'pending_reviews')->first();

        if ($snapshot === null || $snapshot->computed_at === null) {
            return [
                Stat::make('Pending reviews', '—')
                    ->description('No data yet')
                    ->descriptionIcon('heroicon-m-arrow-path')
                    ->color('gray'),
            ];
        }

        $payload = (array) $snapshot->metric_value_json;
        $drafts = (int) ($payload['auto_create_drafts'] ?? 0);
        $margin = (int) ($payload['margin_change_suggestions'] ?? 0);

        // Threshold logic: <5 success, 5–20 warning, >20 danger.
        $bucketColor = fn (int $count) => $count === 0
            ? 'success'
            : ($count <= 5 ? 'success' : ($count <= 20 ? 'warning' : 'danger'));

        $stale = $snapshot->isStale();
        $ring = $stale ? ['class' => 'ring-2 ring-amber-400'] : [];

        // Quick task 260606-lhp — third Stat "New product opportunities"
        // removed. HighConfidenceSourceableWidget carries that signal in a
        // decision-grade form. See class docblock.
        return [
            Stat::make('Auto-create drafts', (string) $drafts)
                ->description('Ready for human review')
                ->descriptionIcon('heroicon-m-inbox-stack')
                ->color($bucketColor($drafts))
                ->extraAttributes($ring),
            Stat::make('Margin changes', (string) $margin)
                ->description('Pending pricing adjustments')
                ->descriptionIcon('heroicon-m-inbox-stack')
                ->color($bucketColor($margin)),
        ];
    }
}
