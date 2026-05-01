<?php

declare(strict_types=1);

namespace App\Domain\Quotes\Filament\Actions;

use App\Domain\Quotes\Models\Quote;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action;

/**
 * Phase 11 Plan 03 — MarkAcceptedAction (D-07).
 *
 * Manual sales bookkeeping: customer replied yes via email/phone/Bitrix Deal
 * stage transition externally. Sales clicks "Mark as accepted" — flips
 * status sent → accepted, sets accepted_at = now().
 *
 * v1 ONLY ships the manual flow (no public accept-link, no chatbot self-
 * service, no Bitrix stage mirror-back). D-09 explicitly defers all three.
 *
 * Authorisation: admin + pricing_manager + sales (D-07) — every role that
 * can create a quote can ALSO mark its acceptance, because the sales person
 * who created it is typically the one to hear back from the customer.
 *
 * No structured form needed (acceptance is unambiguous; rejection has the
 * 5-case D-08 reason capture in MarkRejectedAction).
 */
final class MarkAcceptedAction
{
    public static function make(): Action
    {
        return Action::make('mark_accepted')
            ->label('Mark as accepted')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (Quote $record): bool => $record->status === Quote::STATUS_SENT)
            // Pitfall 7 — server-side gate via QuotePolicy::markAccepted.
            ->authorize(fn (Quote $record): bool => auth()->user()?->can('markAccepted', $record) ?? false)
            ->requiresConfirmation()
            ->modalHeading('Mark this quote as accepted?')
            ->modalDescription('Confirms the customer accepted the quote. The audit trail '
                .'records the transition; Plan 11-04 may also fire a QuoteAccepted event.')
            ->modalSubmitActionLabel('Mark accepted')
            ->action(function (Quote $record): void {
                $record->update([
                    'status' => Quote::STATUS_ACCEPTED,
                    'accepted_at' => now(),
                ]);

                Notification::make()
                    ->success()
                    ->title('Quote marked as accepted')
                    ->send();
            });
    }
}
