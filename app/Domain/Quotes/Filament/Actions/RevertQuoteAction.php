<?php

declare(strict_types=1);

namespace App\Domain\Quotes\Filament\Actions;

use App\Domain\Quotes\Models\Quote;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action;

/**
 * Phase 11 Plan 03 — RevertQuoteAction (D-05).
 *
 * Narrow 5-minute escape hatch for accidental sends — admin only. After 5 min
 * the action is HIDDEN in the UI (PDF presumed read by the customer). UI
 * hiding is belt; QuotePolicy::revert is braces (server-side authorize gate
 * checks the same 5-minute window — Pitfall 7).
 *
 * Visibility (UI hint, NOT auth):
 *   - status === sent
 *   - admin role (only — sales/pricing_manager can't even see the button)
 *   - sent_at within last 5 minutes
 *
 * Authorisation (server-side gate via QuotePolicy::revert):
 *   - same checks as visibility, enforced at handler entry
 *
 * Action: flip status sent → draft, null sent_at. correlation_id is
 * preserved so the original Plan 11-04 push attempt's audit trail keeps
 * its thread; subsequent re-approve creates a new correlation_id.
 *
 * NOTE: This action does NOT undo the Plan 11-04 Bitrix push (Plan 11-04
 * listener is queued and may have already run). Plan 11-04 listener checks
 * BitrixEntityMap entries before push — re-approve idempotent.
 */
final class RevertQuoteAction
{
    public static function make(): Action
    {
        return Action::make('revert')
            ->label('Revert to draft')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('warning')
            ->visible(function (Quote $record): bool {
                if ($record->status !== Quote::STATUS_SENT) {
                    return false;
                }
                if (! (auth()->user()?->hasRole('admin') ?? false)) {
                    return false;
                }
                if ($record->sent_at === null) {
                    return false;
                }

                return $record->sent_at->diffInMinutes(now()) < 5;
            })
            // Pitfall 7 — server-side gate (QuotePolicy::revert checks the same window).
            ->authorize(fn (Quote $record): bool => auth()->user()?->can('revert', $record) ?? false)
            ->requiresConfirmation()
            ->modalHeading('Revert quote to draft?')
            ->modalDescription('Use within 5 minutes of send for accidental approvals only. '
                .'After revert you can edit the quote and re-approve. Note: a Bitrix Deal may '
                .'already exist; re-approval is idempotent (matches on UF_CRM_WOO_QUOTE_ID).')
            ->modalSubmitActionLabel('Revert')
            ->action(function (Quote $record): void {
                $record->update([
                    'status' => Quote::STATUS_DRAFT,
                    'sent_at' => null,
                ]);

                Notification::make()
                    ->success()
                    ->title('Quote reverted to draft')
                    ->body('You can now edit lines and re-approve.')
                    ->send();
            });
    }
}
