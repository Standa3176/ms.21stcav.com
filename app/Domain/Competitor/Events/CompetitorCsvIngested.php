<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Events;

use App\Foundation\Events\DomainEvent;

/**
 * Phase 5 Plan 02 — fires once per CSV file on Bus::batch ->then() completion.
 *
 * Carries file-level counters (rowsTotal / rowsWritten / rowsErrored /
 * rowsOrphaned) so Phase 7 dashboard consumers can render an
 * ingest-summary timeline without re-aggregating CompetitorPriceRecorded.
 *
 * Fired from IngestCompetitorCsvJob::then() AFTER the file has been moved
 * from processing/ to archive/ and the run flipped to status=completed.
 * Fails atomically — a failed batch goes through ->catch() and never emits
 * this event.
 */
final class CompetitorCsvIngested extends DomainEvent
{
    public function __construct(
        public readonly int $competitorId,
        public readonly int $ingestRunId,
        public readonly string $filename,
        public readonly int $rowsTotal,
        public readonly int $rowsWritten,
        public readonly int $rowsErrored,
        public readonly int $rowsOrphaned,
    ) {
        parent::__construct();
    }
}
