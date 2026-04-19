<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Filament\Widgets;

use App\Domain\Competitor\Models\CompetitorPrice;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Phase 5 Plan 04b — Biggest margin deltas table (COMP-10 footer widget).
 *
 * Lists the top 50 (competitor, SKU) pairs ordered by absolute price delta
 * between our current sell_price and the competitor's most-recent ex-VAT price.
 *
 * W4 null-safety: `products.sell_price` is nullable — Phase 3's
 * RecomputePriceListener writes it asynchronously on SupplierPriceChanged, so
 * a newly-imported product may not have a sell_price yet. The WHERE clause
 * `products.sell_price IS NOT NULL` skips those rows entirely; help text on
 * the page explains they're "not yet analysed" rather than silently missing.
 *
 * NOTE: the plan references `products.sell_price_pennies` — that column name
 * was superseded during Phase 2 by `products.sell_price` (decimal(12,4) GBP).
 * The contract holds: null-safety still applies, and the pennies conversion
 * happens at query time via `ROUND(products.sell_price * 100)`.
 *
 * Query uses a subquery to keep only the LATEST CompetitorPrice per
 * (competitor, sku) pair — otherwise history duplicates the SKU repeatedly.
 *
 * Reads are cheap: top-50 LIMIT + composite index on competitor_prices already
 * exists from Plan 05-01.
 */
final class BiggestMarginDeltasTable extends TableWidget
{
    protected static ?string $heading = 'Biggest margin deltas';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(static::baseQuery())
            ->columns([
                TextColumn::make('sku')
                    ->searchable()
                    ->fontFamily('mono'),
                TextColumn::make('competitor_name')->label('Competitor'),
                TextColumn::make('our_sell_pennies')
                    ->label('Our sell')
                    ->money('GBP', divideBy: 100),
                TextColumn::make('price_pennies_ex_vat')
                    ->label('Competitor ex-VAT')
                    ->money('GBP', divideBy: 100),
                TextColumn::make('delta_pennies')
                    ->label('Delta')
                    ->money('GBP', divideBy: 100)
                    ->color(
                        fn ($record): string => (int) $record->price_pennies_ex_vat < (int) $record->our_sell_pennies
                            ? 'danger'
                            : 'success'
                    ),
                TextColumn::make('recorded_at')->dateTime(),
            ])
            ->defaultPaginationPageOption(25)
            ->paginated([25, 50])
            ->description('Products without a recomputed sell_price are omitted until Phase 3 recompute runs. Delta = |our_sell − competitor_ex_vat|.');
    }

    /**
     * Static so tests can invoke the underlying query shape directly.
     */
    public static function baseQuery(): Builder
    {
        return CompetitorPrice::query()
            ->selectRaw('competitor_prices.*, ROUND(products.sell_price * 100) as our_sell_pennies, ABS(ROUND(products.sell_price * 100) - competitor_prices.price_pennies_ex_vat) as delta_pennies, competitors.name as competitor_name')
            ->join('products', 'products.sku', '=', 'competitor_prices.sku')
            ->join('competitors', 'competitors.id', '=', 'competitor_prices.competitor_id')
            // W4 null-safety — skip products where Phase 3 recompute hasn't
            // populated sell_price yet. Help text on the page explains.
            ->whereNotNull('products.sell_price')
            ->whereIn('competitor_prices.id', function ($q): void {
                $q->selectRaw('MAX(id)')
                    ->from('competitor_prices as cp')
                    ->whereColumn('cp.competitor_id', 'competitor_prices.competitor_id')
                    ->whereColumn('cp.sku', 'competitor_prices.sku')
                    ->groupBy('cp.competitor_id', 'cp.sku');
            })
            ->orderByRaw('delta_pennies DESC')
            ->limit(50);
    }
}
