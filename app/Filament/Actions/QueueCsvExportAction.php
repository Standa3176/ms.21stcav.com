<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Domain\Dashboard\Jobs\QueuedCsvExportJob;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\BulkAction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Phase 7 Plan 03 — factory for the "Queue CSV export" bulk action (D-06).
 *
 * Used by HasExportableTable-using Resources as the second bulk action
 * offered alongside the inline ->exportable() path. When the filtered
 * rowset is >10k, the inline export prompts the operator to use this
 * affordance instead, which dispatches QueuedCsvExportJob on sync-bulk
 * so the operator gets an email with a signed download link.
 *
 * Threat T-07-03-04 (DoS via unbounded export): the filter payload from
 * request()->get('tableFilters') is trusted ONLY insofar as Filament has
 * already type-checked it through the Resource's declared filter schema.
 * QueuedCsvExportJob itself applies the payload as scalar `where()` pairs
 * and discards non-scalar values.
 *
 * Authorisation: every Resource gates viewAny via its existing policy —
 * we add a defence-in-depth ->authorize() on the bulk action so a crafted
 * POST can't trigger a job for a Resource the user isn't allowed to read.
 */
final class QueueCsvExportAction
{
    public static function make(string $resourceClass): BulkAction
    {
        return BulkAction::make('queue-csv-export')
            ->label('Queue CSV export (email)')
            ->icon('heroicon-o-envelope')
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading('Queue CSV export to email')
            ->modalDescription('The filtered result set is large — we will queue the export on the sync-bulk worker and email you a signed download link when it is ready (link expires in 7 days).')
            ->authorize(fn (): bool => auth()->user()?->can('viewAny', $resourceClass::getModel()) ?? false)
            ->action(function (Collection $records) use ($resourceClass): void {
                QueuedCsvExportJob::dispatch(
                    resourceClass: $resourceClass,
                    filterPayload: (array) request()->get('tableFilters', []),
                    userId: (int) auth()->id(),
                    correlationId: (string) Str::uuid(),
                );

                Notification::make()
                    ->success()
                    ->title('Export queued')
                    ->body('You will receive an email with a download link when it is ready (usually within a few minutes).')
                    ->send();
            });
    }
}
