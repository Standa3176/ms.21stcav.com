<?php

declare(strict_types=1);

namespace App\Filament\Exports;

use Illuminate\Support\Str;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Phase 7 Plan 03 — CSV export writer service (D-06).
 *
 * Moved from App\Domain\Dashboard\Services in Plan 07-06 (Rule 1 — Bug):
 * previous namespace placed this cross-cutting CSV-export infrastructure
 * inside the Dashboard Deptrac layer, which tripped the one-way arrow for
 * every Filament Resource that `use HasExportableTable`. The service is
 * pure infrastructure (no dashboard-specific semantics) and now lives
 * under app/Filament/Exports/ — outside every Deptrac layer, free for
 * cross-domain import. Plan 07-06 re-run of deptrac analyse confirms 0
 * violations introduced by this relocation.
 *
 * Two write modes:
 *   - streamDownload(): pipe rows directly to the browser via spatie/simple-excel
 *     on a php://output stream. Used by the in-browser <10k export path.
 *   - writeToFile():    write rows to a filesystem path (storage/app/exports).
 *     Used by QueuedCsvExportJob for the 10k-100k queued path where the
 *     resulting file is later served via a temporarySignedRoute.
 *
 * Pitfall P2-A (phase 2 sync report CSV lesson) — SimpleExcelWriter flushes
 * on __destruct which is non-deterministic timing. If Mail::attach() or a
 * StreamedResponse fires before PHP GC runs, a partial file / truncated CSV
 * lands on the wire. We `unset($writer)` explicitly before the function
 * returns so the flush happens RIGHT NOW, deterministically.
 *
 * Filename convention (D-06):
 *   {resource_slug}_{YYYY-MM-DD}_{correlation_id_short}.csv
 *
 * The correlation_id short form is the first 8 chars of the uuid (dashes
 * stripped) — enough entropy to avoid collisions within a day, short enough
 * to fit nicely in a filename.
 */
final class CsvExportWriter
{
    public const CORRELATION_ID_SHORT = 8;

    public function filename(string $resourceSlug, ?string $correlationId = null): string
    {
        $cid = $correlationId ?? (string) Str::uuid();
        $short = substr(str_replace('-', '', $cid), 0, self::CORRELATION_ID_SHORT);

        return sprintf('%s_%s_%s.csv', $resourceSlug, now()->format('Y-m-d'), $short);
    }

    /**
     * Stream rows directly to the browser. Pitfall P2-A — explicit unset
     * inside the stream closure to force flush before the StreamedResponse
     * completes.
     *
     * @param  iterable<int, array<string, mixed>>  $rows  Generator-safe.
     * @param  array<int, string>  $headers  Optional column header row.
     */
    public function streamDownload(iterable $rows, string $filename, array $headers = []): StreamedResponse
    {
        return response()->streamDownload(function () use ($rows, $headers): void {
            $writer = SimpleExcelWriter::create('php://output', 'csv');
            if ($headers !== []) {
                $writer->addHeader($headers);
            }
            foreach ($rows as $row) {
                $writer->addRow(is_array($row) ? $row : (array) $row);
            }
            // Pitfall P2-A — force flush before the closure returns.
            unset($writer);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * Write rows to a filesystem path. Used by QueuedCsvExportJob after the
     * UX prompts "queue this export to email?" and the job builds the file
     * out-of-band from the browser request.
     *
     * @param  iterable<int, array<string, mixed>>  $rows  Generator-safe.
     * @param  array<int, string>  $headers  Optional column header row.
     */
    public function writeToFile(iterable $rows, string $path, array $headers = []): void
    {
        $writer = SimpleExcelWriter::create($path, 'csv');
        if ($headers !== []) {
            $writer->addHeader($headers);
        }
        foreach ($rows as $row) {
            $writer->addRow(is_array($row) ? $row : (array) $row);
        }
        // Pitfall P2-A — force flush before the method returns so the caller
        // (QueuedCsvExportJob -> Mail::to) reads a fully-written file.
        unset($writer);
    }
}
