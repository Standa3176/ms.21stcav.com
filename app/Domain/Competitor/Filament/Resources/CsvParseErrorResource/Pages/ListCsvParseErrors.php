<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Filament\Resources\CsvParseErrorResource\Pages;

use App\Domain\Competitor\Filament\Resources\CsvParseErrorResource;
use App\Domain\Competitor\Filament\Widgets\CsvParseErrorsByCompetitorWidget;
use App\Domain\Competitor\Models\CsvParseError;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ListCsvParseErrors extends ListRecords
{
    protected static string $resource = CsvParseErrorResource::class;

    /**
     * Header widget — per-competitor unresolved-error count. Gives the operator
     * an at-a-glance "which competitor's feed is generating the most pain" so
     * they can target the worst offender first.
     */
    protected function getHeaderWidgets(): array
    {
        return [
            CsvParseErrorsByCompetitorWidget::class,
        ];
    }

    /**
     * "Export unresolved (XLSX)" — dumps every UNRESOLVED error row to .xlsx
     * (one row per error) with the fields needed to actually fix the source
     * data: competitor name, filename, issue type, line number, raw_line and
     * the parser's context hint. Designed for round-tripping into Claude Code:
     * paste the file, ask "fix these" → Claude has the failing data + parser
     * context in one place.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportUnresolvedXlsx')
                ->label('Export unresolved (XLSX)')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->authorize(fn (): bool => auth()->user()?->can('viewAny', CsvParseError::class) ?? false)
                ->action(function (): ?BinaryFileResponse {
                    $rows = CsvParseError::query()
                        ->with('competitor:id,name')
                        ->whereNull('resolved_at')
                        ->orderBy('competitor_id')
                        ->orderBy('issue_type')
                        ->orderByDesc('created_at')
                        ->get();

                    if ($rows->isEmpty()) {
                        Notification::make()
                            ->title('Nothing to export')
                            ->body('No unresolved CSV parse errors right now. 🎉')
                            ->success()
                            ->send();

                        return null;
                    }

                    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'csv-parse-errors-'.date('Y-m-d').'-'.uniqid('', true).'.xlsx';
                    $writer = SimpleExcelWriter::create($path);

                    foreach ($rows as $r) {
                        $writer->addRow([
                            'id' => $r->id,
                            'competitor' => $r->competitor?->name ?? '(orphan)',
                            'filename' => (string) $r->filename,
                            'issue_type' => (string) $r->issue_type,
                            'line_number' => $r->line_number !== null ? (int) $r->line_number : '',
                            'raw_line' => (string) ($r->raw_line ?? ''),
                            'context' => $r->context !== null ? json_encode($r->context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '',
                            'created_at' => $r->created_at?->toIso8601String() ?? '',
                            'ingest_run_id' => $r->ingest_run_id,
                        ]);
                    }
                    $writer->close();

                    return response()->download(
                        $path,
                        'csv-parse-errors-unresolved-'.date('Y-m-d').'.xlsx',
                        ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
                    )->deleteFileAfterSend();
                }),
        ];
    }
}
