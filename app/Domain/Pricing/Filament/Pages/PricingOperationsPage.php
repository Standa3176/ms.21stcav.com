<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Filament\Pages;

use App\Domain\Competitor\Models\CompetitorPrice;
use App\Domain\Pricing\Services\PricingOpsReport;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Contracts\View\View;

/**
 * Pricing Operations — one screen for the core-loop pricing picture (built
 * 2026-05-25 on operator request).
 *
 * Four summary tiles (matched / winnable / at-floor / below-cost) are
 * clickable → each opens a modal listing the full underlying rows with an
 * "Export CSV" button. Below, four preview panels: recent sell-price changes,
 * new SKUs awaiting review, at-floor, below-cost (each with its own export).
 *
 * All data comes from PricingOpsReport (cached competitor scan + snapshot/
 * draft queries). RBAC mirrors CompetitorAnalysisPage — admin + pricing_manager
 * + sales (CompetitorPrice viewAny); read_only denied.
 */
class PricingOperationsPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-currency-pound';

    protected static ?string $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 12; // after Home (10) in the Operations group

    protected static ?string $navigationLabel = 'Pricing Operations';

    protected static ?string $title = 'Pricing Operations';

    protected static string $view = 'filament.pages.pricing-operations';

    protected static ?string $slug = 'pricing-operations';

    /** How many rows to render inside a tile modal (export gives the full set). */
    private const MODAL_ROW_CAP = 500;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('viewAny', CompetitorPrice::class) ?? false;
    }

    private function report(): PricingOpsReport
    {
        return app(PricingOpsReport::class);
    }

    /** Header "Recompute" — bust the cached competitor scan. */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('recompute')
                ->label('Recompute positions')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(function (): void {
                    $this->report()->forgetPositions();
                    Notification::make()->success()->title('Recomputed competitor positions')->send();
                }),
        ];
    }

    // ── Tile modals (clickable summary numbers) ───────────────────────────────

    public function belowCostAction(): Action
    {
        return $this->bucketModal('belowCost', 'below_cost', 'Competitor below our cost', 'heroicon-o-no-symbol');
    }

    public function atFloorAction(): Action
    {
        return $this->bucketModal('atFloor', 'at_floor', 'Competitor at/below our floor', 'heroicon-o-exclamation-triangle');
    }

    public function winnableAction(): Action
    {
        return $this->bucketModal('winnable', 'winnable', 'Winnable — we can undercut profitably', 'heroicon-o-check-circle');
    }

    public function matchedAction(): Action
    {
        return $this->bucketModal('matched', 'matched', 'All matched products (cost + current competitor)', 'heroicon-o-table-cells');
    }

    /** Catalogue-expansion: parts on ≥N suppliers we don't sell yet (cached scan). */
    public function addCandidatesAction(): Action
    {
        return Action::make('addCandidates')
            ->modalHeading('Products to add — suppliers carry, we don\'t sell')
            ->modalIcon('heroicon-o-plus-circle')
            ->modalWidth(MaxWidth::SevenExtraLarge)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->modalContent(function (): View {
                $data = $this->report()->addCandidates();

                return view('filament.pages.pricing-ops-add-candidates', [
                    'rows' => array_slice($data['candidates'], 0, self::MODAL_ROW_CAP),
                    'total' => $data['count'],
                    'minSuppliers' => $data['min_suppliers'],
                    'computedAt' => $data['computed_at'],
                ]);
            })
            ->extraModalFooterActions([
                Action::make('addCandidatesExportCsv')
                    ->label('Export CSV')->icon('heroicon-o-arrow-down-tray')->color('gray')
                    ->url(route('pricing-ops.export', ['bucket' => 'add_candidates']))->openUrlInNewTab(),
                Action::make('addCandidatesExportXls')
                    ->label('Export XLS')->icon('heroicon-o-table-cells')->color('gray')
                    ->url(route('pricing-ops.export', ['bucket' => 'add_candidates', 'format' => 'xlsx']))->openUrlInNewTab(),
            ]);
    }

    /** Sourcing gaps: competitor lists it, no supplier carries it, we don't sell it (cached scan). */
    public function sourcingGapsAction(): Action
    {
        return Action::make('sourcingGaps')
            ->modalHeading('Sourcing gaps — competitor sells, no supplier carries it')
            ->modalIcon('heroicon-o-magnifying-glass-circle')
            ->modalWidth(MaxWidth::SevenExtraLarge)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->modalContent(function (): View {
                $data = $this->report()->sourcingGaps();

                return view('filament.pages.pricing-ops-sourcing-gaps', [
                    'rows' => array_slice($data['gaps'], 0, self::MODAL_ROW_CAP),
                    'total' => $data['count'],
                    'maxAgeDays' => $data['max_age_days'],
                    'computedAt' => $data['computed_at'],
                ]);
            })
            ->extraModalFooterActions([
                Action::make('sourcingGapsExportCsv')
                    ->label('Export CSV')->icon('heroicon-o-arrow-down-tray')->color('gray')
                    ->url(route('pricing-ops.export', ['bucket' => 'sourcing_gaps']))->openUrlInNewTab(),
                Action::make('sourcingGapsExportXls')
                    ->label('Export XLS')->icon('heroicon-o-table-cells')->color('gray')
                    ->url(route('pricing-ops.export', ['bucket' => 'sourcing_gaps', 'format' => 'xlsx']))->openUrlInNewTab(),
            ]);
    }

    private function bucketModal(string $name, string $bucket, string $heading, string $icon): Action
    {
        return Action::make($name)
            ->modalHeading($heading)
            ->modalIcon($icon)
            ->modalWidth(MaxWidth::SevenExtraLarge)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->modalContent(function () use ($bucket): View {
                $rows = $this->report()->competitorBucket($bucket);

                return view('filament.pages.pricing-ops-bucket', [
                    'rows' => array_slice($rows, 0, self::MODAL_ROW_CAP),
                    'total' => count($rows),
                    'cap' => self::MODAL_ROW_CAP,
                ]);
            })
            ->extraModalFooterActions([
                Action::make($name.'ExportCsv')
                    ->label('Export CSV')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->url(route('pricing-ops.export', ['bucket' => $bucket]))
                    ->openUrlInNewTab(),
                Action::make($name.'ExportXls')
                    ->label('Export XLS')
                    ->icon('heroicon-o-table-cells')
                    ->color('gray')
                    ->url(route('pricing-ops.export', ['bucket' => $bucket, 'format' => 'xlsx']))
                    ->openUrlInNewTab(),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $report = $this->report();

        return [
            'scan' => $report->positions(),
            'recentChanges' => array_slice($report->recentChanges(), 0, 50),
            'newSkus' => $report->newSkus(50),
            'addCandidates' => $report->addCandidates(),
            'sourcingGaps' => $report->sourcingGaps(),
        ];
    }
}
