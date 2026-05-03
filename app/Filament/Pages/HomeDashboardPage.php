<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Widgets\CompetitorFreshnessWidget;
use App\Filament\Widgets\CrmPushSuccessRateWidget;
use App\Filament\Widgets\HorizonFailedJobsWidget;
use App\Filament\Widgets\ImportIssuesWidget;
use App\Filament\Widgets\IntegrationHealthWidget;
use App\Filament\Widgets\LastSyncRunWidget;
use App\Filament\Widgets\PendingReviewsWidget;
use App\Filament\Widgets\ProductCatalogueHealthWidget;
use App\Filament\Widgets\SyncDiffsParityWidget;
use App\Filament\Widgets\WeeklyReportStatusWidget;
use Filament\Pages\Dashboard;

/**
 * Phase 7 Plan 02 — Home dashboard page (D-01).
 *
 * Replaces Filament's default dashboard at /admin. Custom Page extending
 * Filament\Pages\Dashboard with an explicit getWidgets() returning the
 * 9 home-dashboard widget classes in display order.
 *
 * Filament 3 convention: a Page extending Dashboard registered in the panel's
 * ->pages([...]) list overrides the default dashboard page at the root slug.
 * getWidgets() is the source of truth for widget composition on this page —
 * the panel-level ->widgets([...]) registration is required so Filament
 * resolves classes through discovery + policy gates, but the final render
 * order comes from here.
 *
 * Layout:
 *   Row 1 (freshness — what happened recently):
 *     LastSyncRun · CrmPushSuccessRate · CompetitorFreshness
 *   Row 2 (actions — what ops should look at):
 *     PendingReviews · ImportIssues · HorizonFailedJobs
 *   Row 3 (system health — big picture):
 *     SyncDiffsParity · ProductCatalogueHealth · WeeklyReportStatus
 *
 * Columns: 3 across on md+; widget ->columnSpan(1) ensures each row gets 3.
 *
 * RBAC: every widget has its own canView() gate checking
 * `Gate::allows('viewAny', DashboardSnapshot::class)`; the Phase 7 Plan 01
 * policy grants that to admin + pricing_manager + sales + read_only. The
 * page itself inherits the panel auth middleware (login required).
 */
class HomeDashboardPage extends Dashboard
{
    protected static ?string $navigationIcon = 'heroicon-o-home';

    protected static ?string $navigationLabel = 'Home';

    protected static ?int $navigationSort = -100;

    /**
     * @return array<int, class-string>
     */
    public function getWidgets(): array
    {
        return [
            // Row 1 — Freshness (what happened recently)
            LastSyncRunWidget::class,
            CrmPushSuccessRateWidget::class,
            CompetitorFreshnessWidget::class,
            // Row 2 — Actions (what ops should look at)
            PendingReviewsWidget::class,
            ImportIssuesWidget::class,
            HorizonFailedJobsWidget::class,
            // Row 3 — System health (big picture)
            SyncDiffsParityWidget::class,
            ProductCatalogueHealthWidget::class,
            WeeklyReportStatusWidget::class,
            // Row 4 — Phase 09.1 Plan 01 (D-15) — Admin-only operational tile.
            // 5 traffic-light tiles (one per integration kind) reading the
            // 'integration_health' metric_key from dashboard_snapshots.
            IntegrationHealthWidget::class,
        ];
    }

    /**
     * 3-column grid on md+ screens; Filament stacks on sm/xs automatically.
     *
     * @return int|array<string, int>
     */
    public function getColumns(): int|array
    {
        return [
            'md' => 3,
            'xl' => 3,
        ];
    }
}
