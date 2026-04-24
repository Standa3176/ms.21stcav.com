<?php

declare(strict_types=1);

namespace App\Domain\Suggestions\Filament\Resources;

use App\Domain\Suggestions\Filament\Resources\SuggestionResource\Pages;
use App\Domain\Suggestions\Jobs\ApplySuggestionJob;
use App\Domain\Suggestions\Models\Suggestion;
use App\Filament\Actions\QueueCsvExportAction;
use App\Filament\Actions\SavedFilterAction;
use App\Filament\Concerns\HasExportableTable;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class SuggestionResource extends Resource
{
    use HasExportableTable;

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
                // Phase 5 Plan 04a — supporting_competitors badge for new_product_opportunity rows.
                // Renders as a count badge; blank for other kinds (no accessor trip because
                // data_get on an array returns null if the key is missing).
                TextColumn::make('supporting_competitors')
                    ->label('Comp')
                    ->badge()
                    ->color('info')
                    ->state(fn (Suggestion $record) => $record->kind === 'new_product_opportunity'
                        ? (int) (data_get($record->evidence, 'supporting_competitors', 0))
                        : null)
                    ->placeholder('—'),
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
                    // Phase 5 Plan 04a / Phase 6 Plan 04 — generic approve EXCLUDES kinds with
                    // their own kind-specific approve actions below (richer modals + evidence rendering).
                    ->visible(fn (Suggestion $r) => $r->status === Suggestion::STATUS_PENDING
                        && ! in_array($r->kind, ['margin_change', 'new_product_opportunity', 'crm_push_failed', 'auto_create_failed'], true))
                    ->action(function (Suggestion $record): void {
                        $record->update([
                            'status' => Suggestion::STATUS_APPROVED,
                            'resolved_by_user_id' => auth()->id(),
                            'resolved_at' => now(),
                        ]);
                        ApplySuggestionJob::dispatch($record->id);
                    }),
                // Phase 5 Plan 04a — margin_change kind approve.
                // Renders old→new margin delta from the D-07 FROZEN evidence JSON
                // (shipped in Plan 05-03). Approve dispatches ApplySuggestionJob
                // which resolves MarginChangeApplier and updates the PricingRule;
                // PricingRuleObserver fires PricingRuleChanged for downstream listeners.
                Action::make('approve_margin_change')
                    ->label('Approve margin change')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->authorize(fn (Suggestion $record) => auth()->user()?->hasRole('admin') ?? false)
                    ->visible(fn (Suggestion $r) => $r->kind === 'margin_change' && $r->status === Suggestion::STATUS_PENDING)
                    ->requiresConfirmation()
                    ->modalHeading(fn (Suggestion $r) => 'Approve margin change for '.(string) data_get($r->evidence, 'sku', '?'))
                    ->modalDescription(function (Suggestion $record): string {
                        $old = (int) data_get($record->evidence, 'our_current_margin_bps', 0);
                        $new = (int) data_get($record->evidence, 'proposed_margin_bps', 0);
                        $delta = (int) data_get($record->evidence, 'margin_delta_bps', 0);
                        $sku = (string) data_get($record->evidence, 'sku', '?');
                        $competitor = (string) data_get($record->evidence, 'competitor_name', '?');

                        return sprintf(
                            'SKU %s (vs %s): margin %d bps → %d bps (Δ %+d bps). Approving updates the PricingRule; PricingRuleChanged fires for downstream recompute.',
                            $sku,
                            $competitor,
                            $old,
                            $new,
                            $delta,
                        );
                    })
                    ->action(function (Suggestion $record): void {
                        $record->update([
                            'status' => Suggestion::STATUS_APPROVED,
                            'resolved_by_user_id' => auth()->id(),
                            'resolved_at' => now(),
                        ]);
                        ApplySuggestionJob::dispatch($record->id);
                    }),
                // Phase 5 Plan 04a / Phase 6 Plan 04 — new_product_opportunity kind approve.
                // Phase 6 Plan 03 REPLACED the Phase 5 no-op stub applier with
                // a real NewProductOpportunityApplier that dispatches
                // CreateWooProductJob. This action now triggers the full
                // auto-create pipeline via ApplySuggestionJob → Applier →
                // CreateWooProductJob.
                Action::make('approve_new_product_opportunity')
                    ->label('Approve — create product')
                    ->icon('heroicon-o-plus-circle')
                    ->color('primary')
                    ->authorize(fn (Suggestion $record) => auth()->user()?->hasRole('admin') ?? false)
                    ->visible(fn (Suggestion $r) => $r->kind === 'new_product_opportunity' && $r->status === Suggestion::STATUS_PENDING)
                    ->requiresConfirmation()
                    ->modalHeading(fn (Suggestion $r) => 'Approve new product: '.(string) data_get($r->evidence, 'sku', '?'))
                    ->modalDescription(function (Suggestion $record): string {
                        $supporting = (int) data_get($record->evidence, 'supporting_competitors', 1);
                        $sku = (string) data_get($record->evidence, 'sku', '?');

                        return sprintf(
                            'SKU %s tracked by %d competitor(s). Dispatches CreateWooProductJob via the real Phase 6 NewProductOpportunityApplier — draft will appear in the Auto-Create Review inbox.',
                            $sku,
                            $supporting,
                        );
                    })
                    ->action(function (Suggestion $record): void {
                        $record->update([
                            'status' => Suggestion::STATUS_APPROVED,
                            'resolved_by_user_id' => auth()->id(),
                            'resolved_at' => now(),
                        ]);
                        ApplySuggestionJob::dispatch($record->id);
                    }),

                // Phase 6 Plan 04 — auto_create_failed DLQ replay action.
                // CreateWooProductJob::failed() writes the Suggestion row when
                // the retry chain exhausts. Replay dispatches ApplySuggestionJob
                // → AutoCreateRetryApplier → fresh CreateWooProductJob (mirrors
                // the Phase 4 crm_push_failed Replay precedent above).
                Action::make('replay_auto_create')
                    ->label('Replay auto-create')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->authorize(fn (Suggestion $record) => auth()->user()?->hasRole('admin') ?? false)
                    ->visible(fn (Suggestion $r) => $r->kind === 'auto_create_failed' && $r->status === Suggestion::STATUS_PENDING)
                    ->requiresConfirmation()
                    ->modalHeading(fn (Suggestion $r) => 'Replay auto-create for '.(string) data_get($r->evidence, 'sku', '?'))
                    ->modalDescription('Dispatches ApplySuggestionJob which routes to AutoCreateRetryApplier and re-fires CreateWooProductJob with a fresh attempts counter. Check Horizon + the Auto-Create Review inbox after a few seconds.')
                    ->action(function (Suggestion $record): void {
                        $record->update([
                            'status' => Suggestion::STATUS_APPROVED,
                            'resolved_by_user_id' => auth()->id(),
                            'resolved_at' => now(),
                        ]);
                        ApplySuggestionJob::dispatch($record->id);

                        \Filament\Notifications\Notification::make()
                            ->success()
                            ->title('Auto-create replay dispatched')
                            ->body('Check the Auto-Create Review inbox after a few seconds.')
                            ->send();
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
                // Phase 4 Plan 04 — replay action for crm_push_failed suggestions.
                // Dispatches ApplySuggestionJob which resolves CrmPushRetryApplier
                // (registered in AppServiceProvider) and re-dispatches the original
                // PushOrderToBitrixJob / PushCustomerToBitrixJob with a fresh attempts
                // counter. Warning 9 mandates ->authorize() alongside ->visible().
                Action::make('replay')
                    ->label('Replay CRM Push')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->authorize(fn (Suggestion $record) => auth()->user()?->hasRole('admin') ?? false)
                    ->visible(fn (Suggestion $r) => $r->kind === 'crm_push_failed' && $r->status === Suggestion::STATUS_PENDING)
                    ->requiresConfirmation()
                    ->modalHeading(fn (Suggestion $r) => 'Replay CRM push for order #'.((is_array($r->payload) ? ($r->payload['woo_id'] ?? '?') : '?')))
                    ->modalDescription('Dispatches ApplySuggestionJob which re-fires the original push job with a fresh attempts counter. Check the CRM Push Log for the retry result.')
                    ->action(function (Suggestion $record): void {
                        $record->update([
                            'status' => Suggestion::STATUS_APPROVED,
                            'resolved_by_user_id' => auth()->id(),
                            'resolved_at' => now(),
                        ]);
                        ApplySuggestionJob::dispatch($record->id);

                        \Filament\Notifications\Notification::make()
                            ->success()
                            ->title('CRM push replay dispatched')
                            ->body('Check CRM Push Log after a few seconds for the retry result.')
                            ->send();
                    }),
            ])
            // Phase 7 Plan 03 — DASH-04 saved-filter header action (per-user).
            ->headerActions([
                SavedFilterAction::buildActionGroup(static::getSlug()),
            ])
            // Phase 7 Plan 03 — DASH-04 CSV export (inline <10k + queued 10k-100k).
            ->bulkActions([
                static::getExportBulkAction(),
                QueueCsvExportAction::make(static::class),
            ]);
    }

    // ── Phase 7 Plan 03 — DASH-03 global search (D-04) ─────────────────────

    /** @return array<int, string> */
    public static function getGloballySearchableAttributes(): array
    {
        return ['kind', 'correlation_id'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        /** @var Suggestion $record */
        return '['.($record->kind ?? '—').'] · '.($record->status ?? '—');
    }

    /** @return array<string, string|int|null> */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var Suggestion $record */
        return [
            'Kind' => $record->kind ?? '—',
            'Status' => $record->status ?? '—',
            'Proposed' => optional($record->proposed_at)->diffForHumans() ?? '—',
            'CID' => substr((string) ($record->correlation_id ?? ''), 0, 8),
        ];
    }

    public static function getGlobalSearchResultUrl(Model $record): string
    {
        return static::getUrl('view', ['record' => $record]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSuggestions::route('/'),
            'view' => Pages\ViewSuggestion::route('/{record}'),
        ];
    }
}
