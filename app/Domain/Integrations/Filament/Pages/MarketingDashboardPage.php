<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Filament\Pages;

use App\Domain\Integrations\Filament\Widgets\LatestMarketingAdviceWidget;
use App\Domain\Integrations\Filament\Widgets\MarketingOverviewStats;
use App\Domain\Integrations\Filament\Widgets\MarketingRevenueTrendChart;
use App\Domain\Integrations\Models\GaChannelMetric;
use Filament\Pages\Page;

/**
 * Phase 15 Plan 15b-02 — Marketing dashboard (READ-ONLY presentation).
 *
 * A single at-a-glance screen over ga_channel_metrics_daily (15a-02) + the
 * ad_optimisation Suggestions produced by the 15b-01 AdOptimisationAgent, PLUS
 * an on-demand "Review with Claude" header action (Task 6) that runs the
 * EXISTING 15b-01 agent now instead of waiting for the 6-hourly schedule.
 *
 * PURE PRESENTATION: no new tables, no data pull, no writes, no Google calls.
 * Lives in the Integrations domain alongside the 15a-02 GA4 Channels resource
 * (presentation layer — deptrac clean; the domain Filament subdir is the Http layer).
 *
 * Empty-state is a hard requirement: with ZERO GaChannelMetric rows the page and
 * every header widget render friendly (never an error) and a callout points the
 * operator to connect GA4 in Integration Credentials.
 */
class MarketingDashboardPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-presentation-chart-line';

    protected static ?string $navigationGroup = 'Marketing';

    // First in the Marketing group, before the GA4 Channels resource (sort 10).
    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Marketing Dashboard';

    protected static ?string $title = 'Marketing Dashboard';

    protected static string $view = 'filament.pages.marketing-dashboard';

    protected static ?string $slug = 'marketing-dashboard';

    public static function canAccess(): bool
    {
        // Consistent with the GA4 Channels resource — authed workspace read.
        return auth()->user()?->can('viewAny', GaChannelMetric::class) ?? false;
    }

    protected function getHeaderWidgets(): array
    {
        return [
            MarketingOverviewStats::class,
            MarketingRevenueTrendChart::class,
            LatestMarketingAdviceWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            // Empty-state gate — true once GA4 data has been pulled at least once.
            'hasMetrics' => GaChannelMetric::query()->exists(),
        ];
    }
}
