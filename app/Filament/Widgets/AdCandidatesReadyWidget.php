<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Dashboard\Models\DashboardSnapshot;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Quick task 260607-pys — Tile: "Ad Candidates Ready".
 *
 * Home-dashboard tile surfacing the count of live products that meet the
 * golden-ad-target criteria (margin ≥ £199 + supplier in stock + we beat
 * lowest competitor). Reads from the `ad_candidates_health` snapshot key
 * populated by SnapshotAggregator::computeAdCandidatesHealth during
 * dashboard:refresh (5-min cadence) — widget render path NEVER calls
 * AdCandidateScanner directly (T-pys-04 mitigation: dashboard read is one
 * indexed SELECT against dashboard_snapshots, not a windowed-SQL scan
 * against competitor_prices on every page load).
 *
 * Click-through lands on /admin/ad-candidates with the page's default
 * filters in effect — no pre-applied URL filter chips because the page
 * defaults are correct.
 *
 * RBAC: admin + pricing_manager only. Sales / read_only see silent
 * absence (the dashboard layout adapts; no 403). Mirrors
 * HighConfidenceSourceableWidget's pattern.
 */
final class AdCandidatesReadyWidget extends StatsOverviewWidget
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
        $snapshot = DashboardSnapshot::where('metric_key', 'ad_candidates_health')->first();
        $payload = is_array($snapshot?->metric_value_json) ? $snapshot->metric_value_json : [];

        $count = (int) ($payload['count'] ?? 0);
        $total = (int) ($payload['total_margin_pence'] ?? 0);

        $stale = $snapshot?->computed_at !== null && $snapshot->isStale();
        $ring = $stale ? ['class' => 'ring-2 ring-amber-400'] : [];

        return [
            Stat::make('Ad Candidates Ready', number_format($count))
                ->description(
                    '£'.number_format($total / 100, 2).' potential margin · Click to plan your next campaign',
                )
                ->descriptionIcon('heroicon-m-megaphone')
                ->color($count >= 1 ? 'success' : 'gray')
                ->url('/admin/ad-candidates')
                ->extraAttributes($ring),
        ];
    }
}
