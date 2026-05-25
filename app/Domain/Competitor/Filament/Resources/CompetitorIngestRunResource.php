<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Filament\Resources;

use App\Domain\Competitor\Filament\Resources\CompetitorIngestRunResource\Pages;
use App\Domain\Competitor\Models\Competitor;
use App\Domain\Competitor\Models\CompetitorIngestRun;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Phase 5 Plan 04a — CompetitorIngestRunResource (mirror of Phase 2 SyncRunResource).
 *
 * READ-ONLY log of every CSV file the watcher has dispatched. Rows are produced
 * by IngestCompetitorCsvJob (Plan 05-02) — no UI creation path.
 *
 * RBAC (via CompetitorIngestRunPolicy — hand-written, Pitfall P5-F):
 *   - admin + pricing_manager + sales: viewAny + view
 *   - mutations: false
 *
 * Correlation_id is copyable for cross-table log drilldown (5-tuple trace:
 * watcher → ingest job → chunk jobs → prices → parse errors all carry the
 * same 36-char UUID).
 */
class CompetitorIngestRunResource extends Resource
{
    protected static ?string $model = CompetitorIngestRun::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = 'Configuration';

    protected static ?int $navigationSort = 70;

    protected static ?string $pluralModelLabel = 'Competitor Ingest Runs';

    protected static ?string $modelLabel = 'Competitor Ingest Run';

    /**
     * Eager-load competitor + parse-errors count so the relationship column
     * and error badge render without per-row queries.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['competitor'])
            ->withCount(['parseErrors']);
    }

    /**
     * Quick task 260504-ev5 — danger badge when a competitor CSV ingest run
     * failed in the last 24h. Bounded window keeps the badge actionable.
     */
    public static function getNavigationBadge(): ?string
    {
        // Defensive: badge runs on every sidebar render — failed query (missing table, broken connection) must not 500 the entire admin.
        try {
            $count = CompetitorIngestRun::query()
                ->where('status', CompetitorIngestRun::STATUS_FAILED)
                ->where('started_at', '>=', now()->subDay())
                ->count();
        } catch (\Throwable) {
            return null;
        }

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('competitor.name')
                    ->label('Competitor')
                    ->placeholder('— (pre-match)')
                    ->sortable(),
                TextColumn::make('filename')
                    ->searchable()
                    ->limit(40)
                    ->tooltip(fn (CompetitorIngestRun $r): ?string => $r->filename),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        CompetitorIngestRun::STATUS_STARTED => 'warning',
                        CompetitorIngestRun::STATUS_COMPLETED => 'success',
                        CompetitorIngestRun::STATUS_FAILED => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('rows_total')->label('Total')->numeric(),
                TextColumn::make('rows_written')->label('Written')->numeric(),
                TextColumn::make('rows_errored')->label('Errored')->numeric(),
                TextColumn::make('rows_orphaned')->label('Orphans')->numeric(),
                TextColumn::make('parse_errors_count')
                    ->label('Parse errors')
                    ->badge()
                    ->color(fn ($state): string => $state > 0 ? 'danger' : 'gray'),
                TextColumn::make('started_at')->dateTime()->sortable(),
                TextColumn::make('completed_at')->dateTime()->placeholder('—')->sortable(),
                TextColumn::make('correlation_id')
                    ->fontFamily('mono')
                    ->limit(8)
                    ->copyable()
                    ->tooltip(fn (CompetitorIngestRun $r): ?string => $r->correlation_id),
                TextColumn::make('error_message')
                    ->label('Error')
                    ->limit(40)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('started_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->multiple()
                    ->options([
                        CompetitorIngestRun::STATUS_STARTED => 'Started',
                        CompetitorIngestRun::STATUS_COMPLETED => 'Completed',
                        CompetitorIngestRun::STATUS_FAILED => 'Failed',
                    ]),
                SelectFilter::make('competitor_id')
                    ->label('Competitor')
                    ->options(fn (): array => Competitor::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCompetitorIngestRuns::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        // Runs are produced by IngestCompetitorCsvJob; no UI creation.
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }
}
