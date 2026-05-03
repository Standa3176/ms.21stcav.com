<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Filament\Resources;

use App\Domain\Competitor\Filament\Resources\CompetitorPriceResource\Pages;
use App\Domain\Competitor\Models\Competitor;
use App\Domain\Competitor\Models\CompetitorPrice;
use App\Filament\Actions\QueueCsvExportAction;
use App\Filament\Actions\SavedFilterAction;
use App\Filament\Concerns\HasExportableTable;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

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
    use HasExportableTable;

    protected static ?string $model = CompetitorPrice::class;

    protected static ?string $navigationIcon = 'heroicon-o-currency-pound';

    // Phase 9 Plan 02 — Brand recolor + nav restructure (4 groups). Competitor
    // price browser folded into Catalogue (sits next to product/pricing data).
    protected static ?string $navigationGroup = 'Catalogue';

    protected static ?int $navigationSort = 30;

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
            ])
            // Phase 7 Plan 03 — DASH-04 saved-filter header action (per-user).
            ->headerActions([
                SavedFilterAction::buildActionGroup(static::getSlug()),
            ])
            // Phase 7 Plan 03 — DASH-04 CSV export (inline <10k + queued 10k-100k).
            // competitor_prices has no other bulk actions (COMP-07 immutable history).
            ->bulkActions([
                static::getExportBulkAction(),
                QueueCsvExportAction::make(static::class),
            ]);
    }

    // ── Phase 7 Plan 03 — DASH-03 global search (D-04) ─────────────────────

    /** @return array<int, string> */
    public static function getGloballySearchableAttributes(): array
    {
        return ['sku'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        /** @var CompetitorPrice $record */
        $competitor = $record->competitor?->name ?? '?';

        return ($record->sku ?? '—').' @ '.$competitor;
    }

    /** @return array<string, string|int|null> */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var CompetitorPrice $record */
        return [
            'Gross' => $record->price_pennies_gross !== null
                ? '£'.number_format($record->price_pennies_gross / 100, 2)
                : '—',
            'Ex VAT' => $record->price_pennies_ex_vat !== null
                ? '£'.number_format($record->price_pennies_ex_vat / 100, 2)
                : '—',
            'Recorded' => optional($record->recorded_at)->diffForHumans() ?? '—',
        ];
    }

    public static function getGlobalSearchResultUrl(Model $record): string
    {
        return static::getUrl('index');
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
