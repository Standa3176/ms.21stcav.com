<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Dashboard\Models\DashboardSnapshot;
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
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Pages\Dashboard;
use Illuminate\Support\Facades\Artisan;

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

    protected static ?string $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 10;

    /**
     * Dashboard redesign — render the home widgets in four priority-ordered,
     * labelled sections (triage flow) instead of one undifferentiated grid.
     * Layout lives in resources/views/filament/pages/home-dashboard.blade.php;
     * the grouping is defined in getDashboardSections().
     */
    protected static string $view = 'filament.pages.home-dashboard';

    /**
     * Sub-heading rendered under the page title — gives ops a single
     * "when did the dashboard last refresh?" anchor instead of having
     * to read each widget's `extraAttributes` ring.
     */
    public function getSubheading(): ?string
    {
        $latest = DashboardSnapshot::max('computed_at');

        if (! $latest) {
            return 'Last refreshed: Never run · click Refresh data to populate the dashboard';
        }

        return 'Last refreshed: ' . Carbon::parse($latest)->diffForHumans();
    }

    /**
     * Global "Refresh data" action — synchronous run of the
     * `dashboard:refresh` command + Livewire $refresh dispatch so the
     * 9 widgets re-render against the new snapshot rows. Admin-only.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('refreshData')
                ->label('Refresh data')
                ->icon('heroicon-m-arrow-path')
                ->color('primary')
                ->requiresConfirmation(false)
                ->action(function () {
                    Artisan::call('dashboard:refresh');
                    // Re-render all dashboard widgets after the snapshot rows land.
                    $this->dispatch('$refresh');
                })
                ->successNotificationTitle('Dashboard refreshed')
                ->visible(fn () => auth()->user()?->hasRole('admin') ?? false),
        ];
    }

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

    /**
     * Dashboard redesign — group the home widgets into four priority-ordered
     * sections so the operator triages top-to-bottom:
     *   1. Needs attention — error queues + review backlogs (act first)
     *   2. Today's sync     — did the daily pipelines run?
     *   3. Catalogue        — product counts across the publish pipeline
     *   4. System health    — jobs, parity, integrations, schedule (reference)
     *
     * Each entry's widgets are canView()-filtered so RBAC still applies.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getDashboardSections(): array
    {
        return [
            [
                'title' => 'Needs attention',
                'description' => 'Errors and review queues waiting on you',
                'icon' => 'heroicon-o-exclamation-triangle',
                'tone' => 'danger',
                'columns' => 2,
                'widgets' => $this->visibleSectionWidgets([
                    ImportIssuesWidget::class,
                    PendingReviewsWidget::class,
                ]),
            ],
            [
                'title' => "Today's sync",
                'description' => 'Did the daily pipelines run cleanly?',
                'icon' => 'heroicon-o-arrow-path',
                'tone' => 'primary',
                'columns' => 3,
                'widgets' => $this->visibleSectionWidgets([
                    LastSyncRunWidget::class,
                    CrmPushSuccessRateWidget::class,
                    CompetitorFreshnessWidget::class,
                ]),
            ],
            [
                'title' => 'Catalogue & pipeline',
                'description' => 'Product counts across the publish pipeline',
                'icon' => 'heroicon-o-cube',
                'tone' => 'primary',
                'columns' => 1,
                'widgets' => $this->visibleSectionWidgets([
                    ProductCatalogueHealthWidget::class,
                ]),
            ],
            [
                'title' => 'System health',
                'description' => 'Background jobs, parity, integrations, schedule',
                'icon' => 'heroicon-o-heart',
                'tone' => 'gray',
                'columns' => 2,
                'widgets' => $this->visibleSectionWidgets([
                    SyncDiffsParityWidget::class,
                    HorizonFailedJobsWidget::class,
                    WeeklyReportStatusWidget::class,
                    IntegrationHealthWidget::class,
                ]),
            ],
        ];
    }

    /**
     * Filter a section's widget classes by canView() so the grouped view
     * honours the same RBAC gates as the default Filament widget render.
     *
     * @param  array<int, class-string>  $classes
     * @return array<int, class-string>
     */
    protected function visibleSectionWidgets(array $classes): array
    {
        return array_values(array_filter(
            $classes,
            static fn (string $class): bool => method_exists($class, 'canView') ? $class::canView() : true,
        ));
    }
}
