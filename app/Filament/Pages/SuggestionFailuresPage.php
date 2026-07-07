<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Suggestions\Jobs\ApplySuggestionJob;
use App\Domain\Suggestions\Models\Suggestion;
use App\Models\User;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Quick task 260707-mm7 — dedicated failure-kind triage view.
 *
 * The main SuggestionResource (opportunities list) already hides the
 * failure kinds behind its Tier-1 default kind filter. This page gives
 * those failures a home of their own — scoped to crm_push_failed /
 * auto_create_failed / agent_guardrail_blocked — carrying the same
 * Replay + Reject actions the operator used inside SuggestionResource.
 *
 * PURELY ADDITIVE: SuggestionResource is NOT modified, so none of its
 * filter/action/getEloquentQuery tests break (CrmPushFailedSuggestionTest,
 * SuggestionResourceGuardrailBlockedFilterTest, the agent/guardrail
 * suites all still assert the current SuggestionResource behaviour). A
 * later, higher-cost step could REMOVE the failure kinds from
 * SuggestionResource's kind filter entirely — deferred (test blast radius).
 *
 * Structured on AutoCreateHealthPage (260606-mx9): Page implements
 * HasTable + InteractsWithTable, admin-only canAccess, a nav badge with
 * a defensive try/catch so a broken count query never 500s the sidebar.
 */
final class SuggestionFailuresPage extends Page implements HasTable
{
    use InteractsWithTable;

    /** The kinds this page owns — failures/blocks needing triage, split out of the opportunities list. */
    public const FAILURE_KINDS = ['crm_push_failed', 'auto_create_failed', 'agent_guardrail_blocked'];

    protected static ?string $slug = 'suggestion-failures';

    protected static ?string $navigationGroup = 'Operations';

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $navigationLabel = 'Suggestion Failures';

    /** After Auto-create Health (110) — sits third in the Operations group. */
    protected static ?int $navigationSort = 120;

    protected static string $view = 'filament.pages.suggestion-failures';

    /**
     * Admin-only page gate. The replay actions dispatch real work
     * (ApplySuggestionJob → CreateWooProductJob / CRM push), so we match
     * AutoCreateHealthPage's admin-only stance rather than a per-role gate.
     */
    public static function canAccess(): bool
    {
        /** @var User|null $user */
        $user = auth()->user();

        return (bool) $user?->hasRole('admin');
    }

    /**
     * Sidebar attention badge — count of PENDING failure-kind rows.
     *
     * Wrapped in try/catch returning null on Throwable (mirror
     * AutoCreateHealthPage / SuggestionResource precedent) — the badge
     * runs on every sidebar render and must never 500 the admin chrome.
     * Hides at 0 so the operator only sees it when there is triage owed.
     */
    public static function getNavigationBadge(): ?string
    {
        try {
            $count = Suggestion::query()
                ->whereIn('kind', self::FAILURE_KINDS)
                ->where('status', Suggestion::STATUS_PENDING)
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

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Suggestion::query()->whereIn('kind', self::FAILURE_KINDS))
            ->defaultSort('proposed_at', 'desc')
            ->columns([
                TextColumn::make('kind')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'crm_push_failed', 'auto_create_failed' => 'danger',
                        'agent_guardrail_blocked' => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('context')
                    ->label('Item')
                    ->state(fn (Suggestion $record): string => (string) (
                        data_get($record->evidence, 'sku')
                        ?? (data_get($record->payload, 'woo_id') !== null ? 'order #'.data_get($record->payload, 'woo_id') : null)
                        ?? '—'
                    ))
                    ->fontFamily('mono'),

                TextColumn::make('reason')
                    ->label('Error / reason')
                    ->state(fn (Suggestion $record): ?string => data_get($record->evidence, 'error')
                        ?? data_get($record->evidence, 'reason')
                        ?? data_get($record->evidence, 'message')
                        ?? data_get($record->evidence, 'guardrail_reason'))
                    ->placeholder('—')
                    ->wrap()
                    ->limit(120),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'warning',
                        'approved' => 'primary',
                        'rejected', 'failed' => 'danger',
                        'applied' => 'success',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('proposed_at')
                    ->dateTime()
                    ->sortable(),

                TextColumn::make('correlation_id')
                    ->fontFamily('mono')
                    ->copyable()
                    ->limit(8)
                    ->tooltip(fn (Suggestion $record): ?string => $record->correlation_id)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('kind')
                    ->options(array_combine(self::FAILURE_KINDS, self::FAILURE_KINDS)),
                SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'approved' => 'Approved',
                        'rejected' => 'Rejected',
                        'applied' => 'Applied',
                        'failed' => 'Failed',
                    ])
                    ->default('pending'),
            ])
            ->actions([
                // Replay auto-create — mirrors SuggestionResource::replay_auto_create.
                Action::make('replay_auto_create')
                    ->label('Replay auto-create')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->authorize(fn (): bool => auth()->user()?->hasRole('admin') ?? false)
                    ->visible(fn (Suggestion $record): bool => $record->kind === 'auto_create_failed' && $record->status === Suggestion::STATUS_PENDING)
                    ->requiresConfirmation()
                    ->modalHeading(fn (Suggestion $record): string => 'Replay auto-create for '.(string) data_get($record->evidence, 'sku', '?'))
                    ->modalDescription('Dispatches ApplySuggestionJob which routes to AutoCreateRetryApplier and re-fires CreateWooProductJob with a fresh attempts counter. Check Horizon + the Auto-Create Review inbox after a few seconds.')
                    ->action(function (Suggestion $record): void {
                        $record->update([
                            'status' => Suggestion::STATUS_APPROVED,
                            'resolved_by_user_id' => auth()->id(),
                            'resolved_at' => now(),
                        ]);
                        ApplySuggestionJob::dispatch($record->id);

                        Notification::make()
                            ->success()
                            ->title('Auto-create replay dispatched')
                            ->body('Check the Auto-Create Review inbox after a few seconds.')
                            ->send();
                    }),

                // Replay CRM push — mirrors SuggestionResource::replay.
                Action::make('replay_crm')
                    ->label('Replay CRM push')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->authorize(fn (): bool => auth()->user()?->hasRole('admin') ?? false)
                    ->visible(fn (Suggestion $record): bool => $record->kind === 'crm_push_failed' && $record->status === Suggestion::STATUS_PENDING)
                    ->requiresConfirmation()
                    ->modalHeading(fn (Suggestion $record): string => 'Replay CRM push for order #'.((is_array($record->payload) ? ($record->payload['woo_id'] ?? '?') : '?')))
                    ->modalDescription('Dispatches ApplySuggestionJob which re-fires the original push job with a fresh attempts counter. Check the CRM Push Log for the retry result.')
                    ->action(function (Suggestion $record): void {
                        $record->update([
                            'status' => Suggestion::STATUS_APPROVED,
                            'resolved_by_user_id' => auth()->id(),
                            'resolved_at' => now(),
                        ]);
                        ApplySuggestionJob::dispatch($record->id);

                        Notification::make()
                            ->success()
                            ->title('CRM push replay dispatched')
                            ->body('Check CRM Push Log after a few seconds for the retry result.')
                            ->send();
                    }),

                // Reject — mirrors SuggestionResource::reject (writes rejection_reason).
                Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->authorize(fn (): bool => auth()->user()?->hasRole('admin') ?? false)
                    ->visible(fn (Suggestion $record): bool => $record->status === Suggestion::STATUS_PENDING)
                    ->requiresConfirmation()
                    ->form([
                        Textarea::make('rejection_reason')->maxLength(2000),
                    ])
                    ->action(function (Suggestion $record, array $data): void {
                        $record->update([
                            'status' => Suggestion::STATUS_REJECTED,
                            'rejection_reason' => $data['rejection_reason'] ?? null,
                            'resolved_by_user_id' => auth()->id(),
                            'resolved_at' => now(),
                        ]);

                        Notification::make()
                            ->success()
                            ->title('Suggestion rejected')
                            ->send();
                    }),
            ]);
    }
}
