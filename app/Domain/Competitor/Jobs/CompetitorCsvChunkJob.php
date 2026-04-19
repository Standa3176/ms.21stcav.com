<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Jobs;

use App\Domain\Competitor\Models\CompetitorIngestRun;
use App\Domain\Competitor\Services\CompetitorCsvRowWriter;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Context;

/**
 * Phase 5 Plan 02 Task 2 — 100-row chunk processor.
 *
 * Dispatched from IngestCompetitorCsvJob via Bus::batch. One job per
 * chunk (default config('competitor.csv_chunk_size') = 100 rows). Each
 * row is routed through CompetitorCsvRowWriter — valid → DB + event,
 * orphan → Suggestion, parse error → CsvParseError row.
 *
 * Queue: competitor-csv (Phase 1 FOUND-09 pre-allocated supervisor).
 * Timeout: 120s per RESEARCH §5 — 100 rows × ~200ms = 20s headroom.
 * Tries: 2 — matches Phase 2 SyncChunkJob pattern.
 */
final class CompetitorCsvChunkJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 2;

    /**
     * PHP 8.4 trait-collision avoidance (Pitfall P05 from Phase 1) — do NOT
     * declare `public string $queue = 'competitor-csv'` as a property since
     * Queueable already defines it with a different default. Instead, route
     * via onQueue() in the constructor + expose the name via a read-only
     * accessor for tests.
     */
    /**
     * @param  array<string, mixed>  $mapping  keys: sku_column_index, price_column_index, decimal_format
     * @param  array<int, array<int|string, mixed>>  $rows
     */
    public function __construct(
        public readonly int $ingestRunId,
        public readonly array $mapping,
        public readonly array $rows,
    ) {
        $this->onQueue('competitor-csv');
    }

    public function handle(CompetitorCsvRowWriter $writer): void
    {
        /** @var CompetitorIngestRun $run */
        $run = CompetitorIngestRun::with('competitor')->findOrFail($this->ingestRunId);

        if ($run->correlation_id !== null && $run->correlation_id !== '') {
            Context::add('correlation_id', $run->correlation_id);
        }

        foreach ($this->rows as $row) {
            $writer->write($run, $this->mapping, (array) $row);
        }
    }
}
