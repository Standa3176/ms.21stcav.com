<?php

declare(strict_types=1);

namespace App\Domain\CRM\Filament\Actions;

use App\Domain\CRM\Jobs\EraseBitrixContactJob;
use Closure;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action;
use Illuminate\Support\Facades\Context;

/**
 * Phase 4 Plan 05 Task 2 — GDPR erasure Filament action (CRM-13).
 *
 * Header action on `CrmPushLogResource` (and available on the View page).
 * Admin-only via ->authorize(); confirmation field must match 'ERASE'
 * exactly before the job dispatches.
 *
 * Dispatches the SAME EraseBitrixContactJob the CLI does. Dual-entry-point
 * semantics locked — GDPR operator never needs to choose between CLI vs UI;
 * both converge on one audit trail (gdpr_erasure_log + activity_log).
 */
final class EraseCustomerAction
{
    public static function make(string $name = 'erase_customer'): Action
    {
        return Action::make($name)
            ->label('GDPR Erase Customer')
            ->icon('heroicon-o-user-minus')
            ->color('danger')
            ->authorize(fn () => auth()->user()?->hasRole('admin') ?? false)
            ->form([
                TextInput::make('email')
                    ->label('Customer email')
                    ->email()
                    ->required(),
                TextInput::make('confirmation')
                    ->label('Type ERASE to confirm')
                    ->required()
                    ->helperText('Irreversible scrub of Bitrix Contact PII + linked Deal PII. Financial records preserved per HMRC retention.')
                    ->rule('in:ERASE')
                    ->validationMessages(['in' => 'The confirmation must be exactly ERASE (all caps).']),
            ])
            ->modalHeading('GDPR Right-to-Erasure')
            ->modalDescription('This dispatches a background job that scrubs 18 Contact PII fields + 4 Deal PII fields in Bitrix. The gdpr_erasure_log entry is retained indefinitely. Non-admin users cannot invoke this.')
            ->modalSubmitActionLabel('Dispatch erasure job')
            ->action(function (array $data): void {
                $email = mb_strtolower(trim((string) ($data['email'] ?? '')));
                $actorId = auth()->id();
                $correlationId = (string) (Context::get('correlation_id') ?? '');

                EraseBitrixContactJob::dispatch(
                    $email,
                    $actorId,
                    $correlationId !== '' ? $correlationId : null,
                );

                Notification::make()
                    ->success()
                    ->title('GDPR erasure dispatched')
                    ->body('Audit row will appear in gdpr_erasure_log within 30 seconds.')
                    ->send();
            });
    }

    /**
     * Returns a Laravel validation rule closure that rejects anything other
     * than the exact literal `ERASE`.
     */
    private static function confirmationRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($value !== 'ERASE') {
                $fail('You must type ERASE (all caps) to confirm the irreversible PII scrub.');
            }
        };
    }
}
