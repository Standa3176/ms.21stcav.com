<?php

declare(strict_types=1);

namespace App\Domain\Quotes\Filament\Actions;

use App\Domain\Quotes\Events\QuoteApproved;
use App\Domain\Quotes\Mail\QuoteSentMail;
use App\Domain\Quotes\Models\Quote;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Phase 11 Plan 03 — ApproveQuoteAction (D-04 + QUOT-05).
 *
 * Single-button transition draft → sent. Wraps in DB::transaction:
 *   1. status flip + sent_at + correlation_id update on the Quote row
 *   2. QuoteApproved event dispatch (Plan 11-04 listener consumes — pushes
 *      to Bitrix as a Deal of TYPE_ID=QUOTE)
 *   3. QuoteSentMail queued to customer_email (Plan 11-04 fills PDF body)
 *
 * D-04 separation-of-duties — ->authorize('approve', $record) defers to
 * QuotePolicy::approve which DENIES sales role explicitly. Sales sees the
 * button only via UI visibility (visible() closure includes role check) but
 * ALSO the server-side authorize() gate catches direct handler calls
 * (Pitfall 7 — visibility() is UI hint, authorize() is the actual gate).
 *
 * Visibility: only when status === draft. After flipping to sent the button
 * disappears and RevertQuoteAction / MarkAcceptedAction / MarkRejectedAction
 * surface in its place.
 *
 * Notification: success flash "Quote sent — Bitrix push and customer email
 * queued" — text matches Plan 11-04's downstream listener naming so the
 * operator UAT step 10 verbal confirmation aligns.
 */
final class ApproveQuoteAction
{
    public static function make(): Action
    {
        return Action::make('approve')
            ->label('Approve & send')
            ->icon('heroicon-o-paper-airplane')
            ->color('success')
            ->visible(fn (Quote $record): bool => $record->status === Quote::STATUS_DRAFT)
            // Pitfall 7 — server-side gate. QuotePolicy::approve denies sales role.
            ->authorize(fn (Quote $record): bool => auth()->user()?->can('approve', $record) ?? false)
            ->requiresConfirmation()
            ->modalHeading('Approve quote and send to customer?')
            ->modalDescription(fn (Quote $record): string => 'This will: (1) flip status to sent, '
                .'(2) email the quote PDF to '.$record->customer_email.', '
                .'(3) queue Bitrix Deal push (gated by QUOTE_BITRIX_PUSH_ENABLED).')
            ->modalSubmitActionLabel('Approve and send')
            ->action(function (Quote $record): void {
                DB::transaction(function () use ($record): void {
                    $correlationId = (string) Str::ulid();
                    $statusBefore = $record->status;

                    $record->update([
                        'status' => Quote::STATUS_SENT,
                        'sent_at' => now(),
                        'correlation_id' => $correlationId,
                    ]);

                    QuoteApproved::dispatch(
                        $record->id,
                        $record->user_id,
                        $record->customer_email,
                        $record->customer_group_id,
                        $statusBefore,
                        Quote::STATUS_SENT,
                        $correlationId,
                    );

                    Mail::to($record->customer_email)->queue(new QuoteSentMail($record));
                });

                Notification::make()
                    ->success()
                    ->title('Quote sent')
                    ->body('Bitrix push and customer email queued.')
                    ->send();
            });
    }
}
