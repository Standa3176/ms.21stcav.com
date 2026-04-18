<?php

declare(strict_types=1);

namespace App\Domain\Suggestions\Filament\Resources;

use App\Domain\Suggestions\Filament\Resources\SuggestionResource\Pages;
use App\Domain\Suggestions\Jobs\ApplySuggestionJob;
use App\Domain\Suggestions\Models\Suggestion;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SuggestionResource extends Resource
{
    protected static ?string $model = Suggestion::class;

    protected static ?string $navigationIcon = 'heroicon-o-inbox';

    protected static ?string $navigationGroup = 'Review';

    protected static ?string $recordTitleAttribute = 'kind';

    /**
     * Eager-load relations displayed in the table to prevent N+1 queries
     * (Gemini Concern MEDIUM, PITFALLS Pitfall 10).
     *
     * Currently rendered relation columns:
     *   - resolvedByUser.name  -> belongsTo(User)
     *
     * `proposedBy` is a polymorphic morphTo. If a future column renders proposedBy.* fields,
     * extend this with `->with(['proposedBy'])` (Eloquent will fan out per concrete type).
     *
     * The accompanying tests/Feature/SuggestionResourceQueryCountTest.php asserts that listing
     * N suggestions executes a bounded number of queries (not N + relation-fetches per row).
     */
    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->with(['resolvedByUser']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('kind')->badge()->sortable(),
                TextColumn::make('status')->badge()->color(fn ($state) => match ($state) {
                    'pending' => 'warning',
                    'approved' => 'primary',
                    'rejected' => 'danger',
                    'applied' => 'success',
                    'failed' => 'danger',
                    default => 'gray',
                })->sortable(),
                TextColumn::make('correlation_id')
                    ->fontFamily('mono')
                    ->copyable()
                    ->limit(8)
                    ->tooltip(fn ($record) => $record->correlation_id),
                TextColumn::make('proposed_at')->dateTime()->sortable(),
                TextColumn::make('resolvedByUser.name')->label('Resolved by')->placeholder('—'),
            ])
            ->defaultSort('proposed_at', 'desc')
            ->filters([
                SelectFilter::make('kind'),
                SelectFilter::make('status')->options([
                    'pending' => 'Pending',
                    'approved' => 'Approved',
                    'rejected' => 'Rejected',
                    'applied' => 'Applied',
                    'failed' => 'Failed',
                ]),
            ])
            ->actions([
                Action::make('approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    // Warning 9 — defence-in-depth: ->authorize() enforces at the POST level even if
                    // a crafted request bypasses ->visible(). Sales/read_only/pricing_manager get 403.
                    ->authorize(fn (Suggestion $record) => auth()->user()?->hasRole('admin') ?? false)
                    ->visible(fn (Suggestion $r) => $r->status === Suggestion::STATUS_PENDING)
                    ->action(function (Suggestion $record): void {
                        $record->update([
                            'status' => Suggestion::STATUS_APPROVED,
                            'resolved_by_user_id' => auth()->id(),
                            'resolved_at' => now(),
                        ]);
                        ApplySuggestionJob::dispatch($record->id);
                    }),
                Action::make('reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->form([Textarea::make('rejection_reason')->required()->maxLength(2000)])
                    ->authorize(fn (Suggestion $record) => auth()->user()?->hasRole('admin') ?? false)
                    ->visible(fn (Suggestion $r) => $r->status === Suggestion::STATUS_PENDING)
                    ->action(function (Suggestion $record, array $data): void {
                        $record->update([
                            'status' => Suggestion::STATUS_REJECTED,
                            'rejection_reason' => $data['rejection_reason'],
                            'resolved_by_user_id' => auth()->id(),
                            'resolved_at' => now(),
                        ]);
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSuggestions::route('/'),
            'view' => Pages\ViewSuggestion::route('/{record}'),
        ];
    }
}
