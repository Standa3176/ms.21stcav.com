<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Filament\Resources;

use App\Domain\Competitor\Filament\Resources\CompetitorPriceResource\Pages;
use App\Domain\Competitor\Models\Competitor;
use App\Domain\Competitor\Models\CompetitorPrice;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Phase 5 Plan 04a — CompetitorPriceResource (COMP-05/COMP-10 surface).
 *
 * READ-ONLY browser over competitor_prices. History is NEVER truncated
 * (COMP-07) so the table is append-only; no create/edit/delete UI paths.
 *
 * RBAC (via CompetitorPricePolicy — hand-written, Pitfall P5-F):
 *   - admin + pricing_manager + sales: viewAny + view
 *   - all mutations: false
 *
 * Navigation group 'Competitor Intelligence' keeps the 3 new Phase 5
 * Resources together under a single sidebar section.
 */
class CompetitorPriceResource extends Resource
{
    protected static ?string $model = CompetitorPrice::class;

    protected static ?string $navigationIcon = 'heroicon-o-currency-pound';

    protected static ?string $navigationGroup = 'Competitor Intelligence';

    protected static ?int $navigationSort = 10;

    protected static ?string $pluralModelLabel = 'Competitor Prices';

    protected static ?string $modelLabel = 'Competitor Price';

    /**
     * Eager-load the belongsTo Competitor so the relationship column doesn't
     * trigger per-row queries (Pitfall 10 / Phase 2 P2-G precedent).
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['competitor']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sku')
                    ->searchable()
                    ->sortable()
                    ->fontFamily('mono'),
                TextColumn::make('competitor.name')
                    ->label('Competitor')
                    ->sortable(),
                TextColumn::make('price_pennies_gross')
                    ->label('Gross')
                    ->money('GBP', divideBy: 100)
                    ->sortable(),
                TextColumn::make('price_pennies_ex_vat')
                    ->label('Ex VAT')
                    ->money('GBP', divideBy: 100)
                    ->sortable(),
                TextColumn::make('recorded_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('ingest_run_id')
                    ->label('Run')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('recorded_at', 'desc')
            ->filters([
                SelectFilter::make('competitor_id')
                    ->label('Competitor')
                    ->options(fn (): array => Competitor::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
                Filter::make('recorded_at')
                    ->form([
                        DatePicker::make('from')->label('Recorded from'),
                        DatePicker::make('to')->label('Recorded to'),
                    ])
                    ->query(function (Builder $q, array $data): Builder {
                        if (! empty($data['from'])) {
                            $q->whereDate('recorded_at', '>=', $data['from']);
                        }
                        if (! empty($data['to'])) {
                            $q->whereDate('recorded_at', '<=', $data['to']);
                        }

                        return $q;
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCompetitorPrices::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        // competitor_prices is producer-owned (IngestCompetitorCsvJob); no UI creation.
        return false;
    }

    public static function canEdit($record): bool
    {
        // COMP-07 — history is immutable.
        return false;
    }

    public static function canDelete($record): bool
    {
        // COMP-07 — history is immutable; retention prunes raw CSVs, never this table.
        return false;
    }
}
