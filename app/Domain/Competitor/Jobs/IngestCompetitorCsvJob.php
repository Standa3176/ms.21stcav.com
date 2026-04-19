<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Jobs;

use App\Domain\Competitor\Events\CompetitorCsvIngested;
use App\Domain\Competitor\Models\Competitor;
use App\Domain\Competitor\Models\CompetitorCsvMapping;
use App\Domain\Competitor\Models\CompetitorIngestRun;
use App\Domain\Competitor\Models\CsvParseError;
use App\Domain\Competitor\Services\ColumnHeuristicDetector;
use App\Domain\Competitor\Services\DecimalFormatDetector;
use App\Domain\Competitor\Services\EncodingDetector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\SimpleExcel\SimpleExcelReader;
use Throwable;

/**
 * Phase 5 Plan 02 Task 2 — CSV-file entry point.
 *
 * Pipeline:
 *   1. Resolve Competitor + detect encoding + convert-to-UTF-8 (scratch file if needed)
 *   2. Check for an existing competitor_csv_mappings row:
 *        - Fast-path: use saved (sku_idx, price_idx, decimal_format)
 *        - First-ingest: detect headers + decimal format; persist mapping row
 *        - Ambiguous: quarantine + ambiguous_mapping CsvParseError; mark run failed; return
 *   3. Open the reader, buffer rows into chunks of config('competitor.csv_chunk_size')
 *   4. Dispatch all chunks as a Bus::batch with ->then()/->catch() callbacks:
 *        - then: move file incoming/processing → archive/{YYYY-MM-DD}/, mark run completed,
 *          fire CompetitorCsvIngested event
 *        - catch: move file → quarantine/{YYYY-MM-DD}/ with .error.json sidecar, write
 *          chunk_batch_failed CsvParseError, mark run failed
 *
 * Bus::batch is LOCKED per the plan — chain-terminal was rejected because it
 * loses "all chunks done → archive move" atomicity on a mid-chain failure.
 *
 * Queue: competitor-csv. Timeout 600s (OCR-heavy CSVs take time before the
 * batch starts). Tries: 2 — plus the per-chunk retries the job class carries.
 */
final class IngestCompetitorCsvJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 2;

    // PHP 8.4 trait-collision avoidance — queue name set via onQueue() in constructor.

    public function __construct(
        public readonly string $processingPath,
        public readonly int $competitorId,
    ) {
        $this->onQueue('competitor-csv');
    }

    public function handle(
        ColumnHeuristicDetector $cols,
        DecimalFormatDetector $decimalDetector,
        EncodingDetector $encoding,
    ): void {
        /** @var Competitor $competitor */
        $competitor = Competitor::findOrFail($this->competitorId);

        $filename = basename($this->processingPath);
        $correlationId = (string) (Context::get('correlation_id') ?? Str::uuid());
        Context::add('correlation_id', $correlationId);

        $run = CompetitorIngestRun::create([
            'competitor_id' => $competitor->id,
            'filename' => $filename,
            'rows_total' => 0,
            'rows_written' => 0,
            'rows_errored' => 0,
            'rows_orphaned' => 0,
            'status' => CompetitorIngestRun::STATUS_STARTED,
            'started_at' => now(),
            'correlation_id' => $correlationId,
        ]);

        try {
            // ── Stage 1: encoding + UTF-8 conversion ──
            $detectedEnc = $encoding->detect($this->processingPath);
            $readPath = $encoding->convertToUtf8($this->processingPath, $detectedEnc);

            // ── Stage 2: resolve mapping (fast-path OR first-ingest detection) ──
            $mappingRow = CompetitorCsvMapping::where('competitor_id', $competitor->id)->first();

            if ($mappingRow === null) {
                $mappingRow = $this->detectAndPersistMapping(
                    competitor: $competitor,
                    run: $run,
                    readPath: $readPath,
                    colsDetector: $cols,
                    decimalDetector: $decimalDetector,
                );

                if ($mappingRow === null) {
                    $this->quarantine($run, 'ambiguous_mapping', 'Column auto-detection returned no candidates');

                    return;
                }
            }

            // ── Stage 3: buffer chunks + dispatch Bus::batch ──
            $this->dispatchChunkBatch($run, $mappingRow, $readPath);
        } catch (Throwable $e) {
            Log::error('competitor.ingest_pre_batch_failure', [
                'competitor_id' => $competitor->id,
                'run_id' => $run->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            $run->update([
                'status' => CompetitorIngestRun::STATUS_FAILED,
                'error_message' => substr($e->getMessage(), 0, 500),
                'completed_at' => now(),
            ]);
            $this->moveToQuarantine($filename, $e->getMessage());
        }
    }

    /**
     * Detect column + decimal mapping from the first 11 rows (header + 10 samples).
     * Returns the persisted CompetitorCsvMapping row, or null on ambiguity.
     */
    private function detectAndPersistMapping(
        Competitor $competitor,
        CompetitorIngestRun $run,
        string $readPath,
        ColumnHeuristicDetector $colsDetector,
        DecimalFormatDetector $decimalDetector,
    ): ?CompetitorCsvMapping {
        $reader = SimpleExcelReader::create($readPath)->noHeaderRow();
        $sampleRows = [];
        $count = 0;
        foreach ($reader->getRows() as $row) {
            $sampleRows[] = array_values((array) $row);
            $count++;
            if ($count >= 11) {
                break;
            }
        }

        if (empty($sampleRows)) {
            return null;
        }

        $header = $sampleRows[0];
        $detected = $colsDetector->detect($header);
        if ($detected === null) {
            return null;
        }

        $decimalFormat = $decimalDetector->detect($sampleRows, $detected['price_column_index']);

        return CompetitorCsvMapping::create([
            'competitor_id' => $competitor->id,
            'sku_column_index' => $detected['sku_column_index'],
            'price_column_index' => $detected['price_column_index'],
            'decimal_format' => $decimalFormat,
            'detected_at' => now(),
        ]);
    }

    /**
     * Read the CSV, buffer rows into chunks, dispatch a Bus::batch of CompetitorCsvChunkJob.
     */
    private function dispatchChunkBatch(
        CompetitorIngestRun $run,
        CompetitorCsvMapping $mappingRow,
        string $readPath,
    ): void {
        $chunkSize = (int) config('competitor.csv_chunk_size', 100);
        $mapping = [
            'sku_column_index' => $mappingRow->sku_column_index,
            'price_column_index' => $mappingRow->price_column_index,
            'decimal_format' => $mappingRow->decimal_format,
        ];

        // Read without header-row interpretation: spatie returns associative rows
        // by default, but we want positional indexes so ColumnHeuristicDetector's
        // resolved column INDEX works. ->noHeaderRow() keeps positional semantics
        // end-to-end (detector + writer agree on the index scheme).
        $reader = SimpleExcelReader::create($readPath)->noHeaderRow();
        $buffer = [];
        $chunkJobs = [];
        $rowsTotal = 0;
        $rowIdx = 0;

        foreach ($reader->getRows() as $row) {
            // Skip the header row (index 0) — we pushed its interpretation into the mapping.
            if ($rowIdx === 0) {
                $rowIdx++;

                continue;
            }
            $rowIdx++;
            $rowsTotal++;

            $buffer[] = array_values((array) $row);
            if (count($buffer) >= $chunkSize) {
                $chunkJobs[] = new CompetitorCsvChunkJob($run->id, $mapping, $buffer);
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            $chunkJobs[] = new CompetitorCsvChunkJob($run->id, $mapping, $buffer);
            $buffer = [];
        }

        $run->update(['rows_total' => $rowsTotal]);

        if ($chunkJobs === []) {
            // Empty CSV (header-only) — immediately flip to completed + archive.
            $this->finalise($run);

            return;
        }

        $runId = $run->id;
        $competitorId = $run->competitor_id;
        $filename = $run->filename;
        $processingPath = $this->processingPath;

        Bus::batch($chunkJobs)
            ->name(sprintf('competitor-ingest-%s', $filename))
            ->onQueue('competitor-csv')
            ->allowFailures()
            ->then(function () use ($runId, $competitorId, $filename, $processingPath) {
                $freshRun = CompetitorIngestRun::find($runId);
                if ($freshRun === null) {
                    return;
                }
                self::moveToArchive($processingPath, $filename);
                $competitor = Competitor::find($competitorId);
                if ($competitor !== null) {
                    $competitor->update(['last_ingest_at' => now()]);
                }
                $freshRun->update([
                    'status' => CompetitorIngestRun::STATUS_COMPLETED,
                    'completed_at' => now(),
                ]);

                event(new CompetitorCsvIngested(
                    competitorId: (int) $competitorId,
                    ingestRunId: (int) $runId,
                    filename: (string) $filename,
                    rowsTotal: (int) $freshRun->rows_total,
                    rowsWritten: (int) $freshRun->rows_written,
                    rowsErrored: (int) $freshRun->rows_errored,
                    rowsOrphaned: (int) $freshRun->rows_orphaned,
                ));
            })
            ->catch(function ($batch, Throwable $e) use ($runId, $competitorId, $filename, $processingPath) {
                Log::error('competitor.batch_failed', [
                    'run_id' => $runId,
                    'competitor_id' => $competitorId,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
                $freshRun = CompetitorIngestRun::find($runId);
                if ($freshRun !== null) {
                    $freshRun->update([
                        'status' => CompetitorIngestRun::STATUS_FAILED,
                        'error_message' => substr($e->getMessage(), 0, 500),
                        'completed_at' => now(),
                    ]);
                }
                CsvParseError::create([
                    'ingest_run_id' => $runId,
                    'competitor_id' => $competitorId,
                    'filename' => (string) $filename,
                    'issue_type' => CsvParseError::TYPE_ENCODING_FAILURE, // closest enum; batch-level failure
                    'context' => [
                        'chunk_batch_failed' => true,
                        'batch_id' => $batch->id ?? null,
                        'exception' => $e::class,
                        'message' => $e->getMessage(),
                    ],
                ]);
                self::moveToQuarantineStatic($processingPath, $filename, $e->getMessage());
            })
            ->dispatch();
    }

    /**
     * Finalise a zero-row-dispatched run (header-only CSV): archive + mark completed.
     */
    private function finalise(CompetitorIngestRun $run): void
    {
        self::moveToArchive($this->processingPath, $run->filename);
        $run->competitor?->update(['last_ingest_at' => now()]);
        $run->update([
            'status' => CompetitorIngestRun::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);

        event(new CompetitorCsvIngested(
            competitorId: (int) $run->competitor_id,
            ingestRunId: (int) $run->id,
            filename: (string) $run->filename,
            rowsTotal: (int) $run->rows_total,
            rowsWritten: (int) $run->rows_written,
            rowsErrored: (int) $run->rows_errored,
            rowsOrphaned: (int) $run->rows_orphaned,
        ));
    }

    private function quarantine(CompetitorIngestRun $run, string $issueType, string $detail): void
    {
        CsvParseError::create([
            'ingest_run_id' => $run->id,
            'competitor_id' => $run->competitor_id,
            'filename' => (string) $run->filename,
            'issue_type' => match ($issueType) {
                'ambiguous_mapping' => CsvParseError::TYPE_AMBIGUOUS_MAPPING,
                'encoding_failure' => CsvParseError::TYPE_ENCODING_FAILURE,
                default => CsvParseError::TYPE_AMBIGUOUS_MAPPING,
            },
            'context' => ['detail' => $detail],
        ]);
        $run->update([
            'status' => CompetitorIngestRun::STATUS_FAILED,
            'error_message' => substr($detail, 0, 500),
            'completed_at' => now(),
        ]);
        $this->moveToQuarantine($run->filename, $detail);
    }

    private function moveToQuarantine(string $filename, string $reason): void
    {
        self::moveToQuarantineStatic($this->processingPath, $filename, $reason);
    }

    public static function moveToQuarantineStatic(string $processingPath, string $filename, string $reason): void
    {
        if (! is_file($processingPath)) {
            return;
        }
        $destDir = storage_path('app/competitors/quarantine/'.now()->format('Y-m-d'));
        if (! is_dir($destDir)) {
            @mkdir($destDir, 0o775, true);
        }
        $dest = $destDir.DIRECTORY_SEPARATOR.$filename;
        @rename($processingPath, $dest);
        file_put_contents(
            $destDir.DIRECTORY_SEPARATOR.$filename.'.error.json',
            json_encode(['reason' => $reason, 'quarantined_at' => now()->toIso8601String()], JSON_PRETTY_PRINT),
        );
    }

    public static function moveToArchive(string $processingPath, string $filename): void
    {
        if (! is_file($processingPath)) {
            return;
        }
        $destDir = storage_path('app/competitors/archive/'.now()->format('Y-m-d'));
        if (! is_dir($destDir)) {
            @mkdir($destDir, 0o775, true);
        }
        $dest = $destDir.DIRECTORY_SEPARATOR.$filename;
        @rename($processingPath, $dest);
    }
}
