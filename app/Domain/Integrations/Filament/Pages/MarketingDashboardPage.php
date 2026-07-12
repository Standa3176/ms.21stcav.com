<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Filament\Pages;

use App\Domain\Agents\Jobs\RunAdOptimisationJob;
use App\Domain\Integrations\Filament\Widgets\LatestMarketingAdviceWidget;
use App\Domain\Integrations\Filament\Widgets\MarketingOverviewStats;
use App\Domain\Integrations\Filament\Widgets\MarketingRevenueTrendChart;
use App\Domain\Integrations\Models\GaChannelMetric;
use App\Domain\Integrations\Support\MarketingDateRange;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

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
    // 260712-mdr — Filament's page-filters→header-widgets mechanism. The trait
    // adds the `#[Url] public ?array $filters` state that getWidgetData() shares
    // with the two GA-data header widgets (they use InteractsWithPageFilters).
    use HasFiltersForm;

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

    /**
     * Task 6 — on-demand "Review with Claude" header action. Runs the EXISTING
     * 15b-01 AdOptimisationAgent now (admin-pull) instead of waiting for the
     * 6-hourly schedule. REUSES RunAdOptimisationJob — no new agent, no new
     * external integration, no Google calls. Money-safe: admin-gated,
     * requiresConfirmation, 300p daily budget cap (enforced by the job's
     * BudgetGuard), advice-only.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('review_with_claude')
                ->label('Review with Claude')
                ->icon('heroicon-o-sparkles')
                ->color('primary')
                // Admin gate — same posture as RunPricingAgentAction (admin-pull).
                ->authorize(fn (): bool => auth()->user()?->hasRole('admin') ?? false)
                ->requiresConfirmation()
                ->modalHeading('Review marketing data with Claude')
                ->modalDescription(
                    'Dispatches the advice-only ad-optimisation agent on the `agents` queue under '
                    .'the 300p daily budget cap. Claude reviews recent GA4 performance plus your '
                    .'high-margin in-stock products and proposes marketing actions in the Suggestions '
                    .'inbox (approval does nothing external — advice-only). Advice appears in ~10-30s.'
                )
                ->modalSubmitActionLabel('Review now')
                ->action(fn () => $this->dispatchReview()),
        ];
    }

    /**
     * 260712-mdr — date-range control that drives the two GA-data header widgets.
     * A Select of presets (default 90d) plus two DatePickers that only show for
     * the `custom` preset. Filament shares this `$this->filters` state with the
     * header widgets via getWidgetData(); each widget resolves it through the
     * shared MarketingDateRange resolver so both agree on the same window.
     */
    public function filtersForm(Form $form): Form
    {
        return $form->schema([
            Select::make('range')
                ->label('Date range')
                ->options(MarketingDateRange::options())
                ->default(MarketingDateRange::DEFAULT)
                ->selectablePlaceholder(false)
                ->native(false)
                ->live(),
            DatePicker::make('from')
                ->label('From')
                ->native(false)
                ->maxDate(now())
                ->visible(fn (Get $get): bool => $get('range') === 'custom'),
            DatePicker::make('to')
                ->label('To')
                ->native(false)
                ->maxDate(now())
                ->visible(fn (Get $get): bool => $get('range') === 'custom'),
        ]);
    }

    /**
     * Share the page filters with the header widgets. The custom page view uses
     * <x-filament-panels::page>, whose widget data comes from getWidgetData()
     * (the built-in dashboard blade would inject `filters` for us, but this page
     * is a plain Page). Passing `filters` here lets MarketingOverviewStats +
     * MarketingRevenueTrendChart (InteractsWithPageFilters) resolve the window.
     *
     * @return array<string, mixed>
     */
    public function getWidgetData(): array
    {
        return [
            'filters' => $this->filters,
        ];
    }

    /**
     * No-data guard mirrors `agents:run-ad-optimisation` (RunAdOptimisationCommand)
     * VERBATIM so the button and the schedule agree: count ga_channel_metrics_daily
     * rows within the same lookback window; dispatch NOTHING when empty.
     */
    private function dispatchReview(): void
    {
        $lookbackDays = (int) config('agents.ad_optimisation.data_lookback_days', 14);

        $recentRows = GaChannelMetric::query()
            ->where('date', '>=', Carbon::today()->subDays($lookbackDays)->toDateString())
            ->count();

        // ── SAFE NO-OP — no recent GA4 data means nothing to analyse ──────────
        if ($recentRows === 0) {
            Notification::make()
                ->warning()
                ->title('No GA4 data yet')
                ->body("No Google Analytics 4 rows in the last {$lookbackDays} days — connect Google Analytics to review. Nothing was dispatched (no agent spend).")
                ->send();

            return;
        }

        RunAdOptimisationJob::dispatch((string) Str::uuid());

        // Shadow-mode honesty — when writes are disabled the run is forensic-only.
        $shadow = ! (bool) config('agents.write_enabled', false);
        $body = 'Claude is reviewing your marketing data — advice will appear in Suggestions shortly.';
        if ($shadow) {
            $body .= ' Shadow mode is on: this run is forensic-only (the AgentRun is recorded, but no '
                .'Suggestion is persisted until AGENT_WRITE_ENABLED=true).';
        }

        Notification::make()
            ->success()
            ->title('Marketing review dispatched')
            ->body($body)
            ->send();
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
