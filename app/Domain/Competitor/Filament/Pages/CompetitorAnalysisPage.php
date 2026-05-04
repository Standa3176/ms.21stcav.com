<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Filament\Pages;

use App\Domain\Competitor\Filament\Widgets\BiggestMarginDeltasTable;
use App\Domain\Competitor\Filament\Widgets\SkuPriceTrendChart;
use App\Domain\Competitor\Filament\Widgets\StaleFeedTrafficLight;
use App\Domain\Competitor\Models\CompetitorPrice;
use Filament\Pages\Page;

/**
 * Phase 5 Plan 04b — CompetitorAnalysisPage (COMP-10).
 *
 * Custom Filament Page hosting three widgets:
 *   - Header:  StaleFeedTrafficLight (Fresh / Stale / Missing counts)
 *   - Footer:  SkuPriceTrendChart (per-SKU line chart with 7/30/90/365-day toggle)
 *   - Footer:  BiggestMarginDeltasTable (top 50 abs(delta) rows)
 *
 * RBAC: gated on CompetitorPricePolicy::viewAny — admin + pricing_manager +
 * sales can view; read_only is denied. Defense-in-depth: the widgets each
 * run their own query through the same models which are Gate::policy-wired
 * in AppServiceProvider, so a crafted Livewire request still can't pull the
 * data.
 *
 * Navigation group 'Competitor Intelligence' matches the 3 Resources shipped
 * in Plan 05-04a so the sidebar keeps the module together.
 */
class CompetitorAnalysisPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-presentation-chart-line';

    // Quick task 260504-ev5 — 8-group nav restructure. Moved into the
    // dedicated 'Competitors' group at sort 30.
    protected static ?string $navigationGroup = 'Competitors';

    protected static ?int $navigationSort = 30;

    protected static ?string $navigationLabel = 'Competitor Analysis';

    protected static ?string $title = 'Competitor Analysis';

    protected static string $view = 'filament.pages.competitor-analysis';

    /**
     * RBAC: reuse CompetitorPrice viewAny — admin + pricing_manager + sales.
     * read_only has no CompetitorPrice grant so canAccess() returns false.
     */
    public static function canAccess(): bool
    {
        return auth()->user()?->can('viewAny', CompetitorPrice::class) ?? false;
    }

    protected function getHeaderWidgets(): array
    {
        return [
            StaleFeedTrafficLight::class,
        ];
    }

    protected function getFooterWidgets(): array
    {
        return [
            SkuPriceTrendChart::class,
            BiggestMarginDeltasTable::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 3;
    }

    public function getFooterWidgetsColumns(): int|array
    {
        return 1;
    }
}
