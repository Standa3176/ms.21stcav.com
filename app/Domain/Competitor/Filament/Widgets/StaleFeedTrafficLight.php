<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Filament\Widgets;

use App\Domain\Competitor\Models\Competitor;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Phase 5 Plan 04b — Stale-feed traffic light widget (header on CompetitorAnalysisPage).
 *
 * Renders three Stat tiles — Fresh / Stale / Missing — driven by
 * `config('competitor.stale_feed_hours', 48)` (Plan 05-01 locked the default to 48).
 *
 * Buckets (against status=active AND is_active=true competitors only):
 *   - Fresh:   last_ingest_at IS NOT NULL AND now - last_ingest_at <  threshold_hours
 *   - Stale:   last_ingest_at IS NOT NULL AND now - last_ingest_at >= threshold_hours
 *   - Missing: last_ingest_at IS NULL (COMP-11 — pending first-ingest)
 *
 * Shares its threshold constant with CompetitorCheckStaleCommand (Task 2) so the
 * hourly notifier and the UI badge can never drift. Reads all active competitors
 * in one query then filters in PHP — the active-competitor row count is tiny
 * (≤ ~20) so the extra N+1 avoidance of a triple-COUNT query isn't worth the code.
 */
final class StaleFeedTrafficLight extends StatsOverviewWidget
{
    protected static ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $threshold = (int) config('competitor.stale_feed_hours', 48);

        $active = Competitor::query()
            ->where('status', Competitor::STATUS_ACTIVE)
            ->where('is_active', true)
            ->get();

        $now = now();

        $fresh = $active->filter(
            fn (Competitor $c): bool => $c->last_ingest_at !== null
                && $c->last_ingest_at->diffInHours($now) < $threshold
        )->count();

        $stale = $active->filter(
            fn (Competitor $c): bool => $c->last_ingest_at !== null
                && $c->last_ingest_at->diffInHours($now) >= $threshold
        )->count();

        $missing = $active->filter(
            fn (Competitor $c): bool => $c->last_ingest_at === null
        )->count();

        return [
            Stat::make('Fresh feeds', (string) $fresh)
                ->description(sprintf('Last ingest <%dh ago', $threshold))
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('success'),
            Stat::make('Stale feeds', (string) $stale)
                ->description(sprintf('Last ingest >=%dh ago', $threshold))
                ->descriptionIcon('heroicon-m-clock')
                ->color('warning'),
            Stat::make('Missing feeds', (string) $missing)
                ->description('No ingest recorded')
                ->descriptionIcon('heroicon-m-x-circle')
                ->color('danger'),
        ];
    }
}
