<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Domain\Dashboard\Services\CsvExportWriter;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\BulkAction;
use Illuminate\Database\Eloquent\Collection;

/**
 * Phase 7 Plan 03 — inline CSV export bulk action for Filament Resources (D-06).
 *
 * Mixed into each of the 6 Resource classes (Product, PricingRule,
 * CrmPushLog, Suggestion, CompetitorPrice, AutoCreateReview) to expose
 * a shared "Export CSV" bulk action without duplicating the threshold
 * logic across 6 files.
 *
 * Threshold semantics (from config/dashboard.php — Plan 07-01):
 *   records > csv_export_hard_cap (100k default)        → hard fail (danger toast)
 *   records > csv_export_queue_threshold (10k default)  → prompt user to use QueueCsvExportAction
 *   records <= csv_export_queue_threshold               → stream inline via CsvExportWriter
 *
 * The inline stream path writes via `php://output` through spatie/simple-excel.
 * Pitfall P2-A (explicit unset to force flush) is enforced inside the writer
 * service — do NOT attempt to bypass it with a manual fputcsv loop here.
 *
 * Filename convention: {resource_slug}_{YYYY-MM-DD}_{correlation_id_short}.csv
 *   — correlation_id short = first 8 chars of a fresh uuid (CsvExportWriter::filename).
 */
trait HasExportableTable
{
    public static function getExportBulkAction(): BulkAction
    {
        return BulkAction::make('export-csv')
            ->label('Export CSV')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->authorize(fn (): bool => auth()->user()?->can('viewAny', static::getModel()) ?? false)
            ->action(function (Collection $records) {
                $hardCap = (int) config('dashboard.csv_export_hard_cap', 100000);
                $queueThreshold = (int) config('dashboard.csv_export_queue_threshold', 10000);
                $count = $records->count();

                if ($count > $hardCap) {
                    Notification::make()
                        ->danger()
                        ->title("Export too large ({$count} rows > {$hardCap})")
                        ->body('Use the artisan command or narrow your filter.')
                        ->send();

                    return null;
                }

                if ($count > $queueThreshold) {
                    Notification::make()
                        ->warning()
                        ->title("Large export ({$count} rows)")
                        ->body('Use "Queue CSV export (email)" to export to email — streaming this many rows inline would time out.')
                        ->send();

                    return null;
                }

                if ($count === 0) {
                    Notification::make()
                        ->warning()
                        ->title('No rows selected')
                        ->send();

                    return null;
                }

                /** @var CsvExportWriter $writer */
                $writer = app(CsvExportWriter::class);
                $filename = $writer->filename(static::getSlug());

                // Use the first record's array shape to derive the header row.
                $firstAsArray = $records->first()?->toArray() ?? [];
                $headers = array_keys($firstAsArray);

                $rows = $records->map(fn ($record) => $record->toArray());

                return $writer->streamDownload($rows, $filename, $headers);
            });
    }
}
