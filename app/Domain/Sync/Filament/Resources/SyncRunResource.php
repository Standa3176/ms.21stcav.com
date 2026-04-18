<?php

declare(strict_types=1);

namespace App\Domain\Sync\Filament\Resources;

use App\Domain\Sync\Filament\Resources\SyncRunResource\Pages;
use App\Domain\Sync\Filament\Resources\SyncRunResource\RelationManagers\SyncErrorsRelationManager;
use App\Domain\Sync\Filament\Resources\SyncRunResource\RelationManagers\SyncRunItemsRelationManager;
use App\Domain\Sync\Models\SyncRun;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Phase 2 Plan 02-04 — SYNC-11 Supplier Sync Status Resource.
 *
 * Read-only listing + drill-down view. SyncRuns are producer-owned (CLI writes
 * them) so create/update are disabled at the UI layer. Admin-only "Retry"
 * action is a stub placeholder until Phase 7 cutover wires re-dispatch.
 *
 * N+1 prevention (Pitfall P2-G): getEloquentQuery() eager-counts errors + items
 * so the table's errors_count / items_count columns render without per-row queries.
 */
class SyncRunResource extends Resource
{
    protected static ?string $model = SyncRun::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';

    protected static ?string $navigationGroup = 'Sync';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'id';

    protected static ?string $pluralModelLabel = 'Sync Runs';

    /**
     * Eager-load counts to avoid N+1 when rendering errors_count + items_count
     * columns (Pitfall P2-G from 02-RESEARCH).
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withCount(['errors', 'items'])
            ->latest('started_at');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->sortable()->label('#'),
                TextColumn::make('started_at')->dateTime()->sortable()->label('Started'),
                TextColumn::make('duration')
                    ->label('Duration')
                    ->state(fn (SyncRun $r) => $r->completed_at && $r->started_at
                        ? $r->started_at->diffForHumans($r->completed_at, ['syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE])
                        : '—'
                    ),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        SyncRun::STATUS_QUEUED => 'gray',
                        SyncRun::STATUS_RUNNING => 'warning',
                        SyncRun::STATUS_COMPLETED => 'success',
                        SyncRun::STATUS_ABORTED, SyncRun::STATUS_FAILED => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                IconColumn::make('dry_run')->boolean()->label('Dry-run?'),
                TextColumn::make('updated_count')->label('Updated')->numeric(),
                TextColumn::make('failed_count')->label('Failed')->numeric(),
                TextColumn::make('errors_count')->label('Error rows')->numeric(),
                TextColumn::make('items_count')->label('Run items')->numeric(),
                TextColumn::make('abort_reason')->label('Abort reason')->placeholder('—'),
                TextColumn::make('correlation_id')
                    ->fontFamily('mono')
                    ->limit(12)
                    ->copyable()
                    ->tooltip(fn (SyncRun $r) => $r->correlation_id),
            ])
            ->defaultSort('started_at', 'desc')
            ->filters([
                SelectFilter::make('status')->multiple()->options([
                    SyncRun::STATUS_QUEUED => 'Queued',
                    SyncRun::STATUS_RUNNING => 'Running',
                    SyncRun::STATUS_COMPLETED => 'Completed',
                    SyncRun::STATUS_ABORTED => 'Aborted',
                    SyncRun::STATUS_FAILED => 'Failed',
                ]),
                TernaryFilter::make('dry_run')->label('Dry-run'),
            ])
            ->headerActions([
                // Admin-only "Retry" action — Pitfall K pattern: BOTH authorize AND visible.
                // Wiring to a real dispatch lives in Phase 7 cutover; keep stub here so
                // admins see the button-and-permission path today.
                Action::make('retry')
                    ->label('Retry aborted run')
                    ->icon('heroicon-o-play')
                    ->visible(fn (): bool => auth()->user()?->hasRole('admin') ?? false)
                    ->authorize(fn (): bool => auth()->user()?->hasRole('admin') ?? false)
                    ->disabled()  // stub — Phase 7 wires the dispatch
                    ->tooltip('Wiring deferred to Phase 7 cutover runbook'),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            SyncErrorsRelationManager::class,
            SyncRunItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSyncRuns::route('/'),
            'view' => Pages\ViewSyncRun::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        // SyncRuns are orchestrator-owned; no UI creation (SyncRunPolicy::create returns false too).
        return false;
    }
}
