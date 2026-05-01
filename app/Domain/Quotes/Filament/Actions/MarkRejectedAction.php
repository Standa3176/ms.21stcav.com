<?php

declare(strict_types=1);

namespace App\Domain\Quotes\Filament\Actions;

use App\Domain\Quotes\Enums\RejectionReason;
use App\Domain\Quotes\Models\Quote;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action;

/**
 * Phase 11 Plan 03 — MarkRejectedAction (D-07 + D-08).
 *
 * Manual sales bookkeeping with STRUCTURED rejection-reason capture. D-08
 * reason capture mirrors Phase 10 D-09 agent_rejection_feedback shape — feeds
 * future v2.x analytics on quote-loss patterns (deferred dashboard surface).
 *
 * Persists rejection_metadata JSON: {reason, notes?, rejected_by_user_id,
 * rejected_at} — the at-key inside the JSON parallels the schema rejected_at
 * timestamp column for query convenience (operators can grep the JSON in
 * MySQL via JSON_EXTRACT without joining activity_log).
 *
 * Authorisation: admin + pricing_manager + sales (D-07).
 *
 * Form modal:
 *   - Select 'reason' — RejectionReason enum (5 D-08 cases) — required
 *   - Textarea 'notes' — optional free-text (max 2000 chars; trimmed)
 */
final class MarkRejectedAction
{
    public static function make(): Action
    {
        return Action::make('mark_rejected')
            ->label('Mark as rejected')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (Quote $record): bool => $record->status === Quote::STATUS_SENT)
            // Pitfall 7 — server-side gate via QuotePolicy::markRejected.
            ->authorize(fn (Quote $record): bool => auth()->user()?->can('markRejected', $record) ?? false)
            ->form([
                Select::make('reason')
                    ->label('Rejection reason')
                    ->options(collect(RejectionReason::cases())->mapWithKeys(fn (RejectionReason $r): array => [
                        $r->value => ucfirst(str_replace('_', ' ', $r->value)),
                    ])->all())
                    ->required()
                    ->helperText('Structured for v2.x analytics — pick the closest match.'),

                Textarea::make('notes')
                    ->label('Notes (optional)')
                    ->maxLength(2000)
                    ->rows(3)
                    ->helperText('Free-text context — competitor name, customer pushback summary, etc.'),
            ])
            ->modalHeading('Mark this quote as rejected')
            ->modalSubmitActionLabel('Mark rejected')
            ->action(function (array $data, Quote $record): void {
                $record->update([
                    'status' => Quote::STATUS_REJECTED,
                    'rejected_at' => now(),
                    'rejection_metadata' => [
                        'reason' => $data['reason'],
                        'notes' => isset($data['notes']) && $data['notes'] !== '' ? trim($data['notes']) : null,
                        'rejected_by_user_id' => auth()->id(),
                        'rejected_at' => now()->toIso8601String(),
                    ],
                ]);

                Notification::make()
                    ->success()
                    ->title('Quote marked as rejected')
                    ->body('Reason: '.$data['reason'])
                    ->send();
            });
    }
}
