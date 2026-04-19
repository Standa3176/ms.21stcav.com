<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Events;

use App\Foundation\Events\DomainEvent;

/**
 * Phase 5 Plan 02 — fires after every valid competitor_prices row write.
 *
 * The hook Plan 05-03's MarginAnalyser listener subscribes to — one event
 * per SKU per scrape. Payload is primitive-only (T-03-05) so queue
 * serialization is stable across releases and no Eloquent hidden-column
 * leaks occur.
 *
 * Emitted from CompetitorCsvRowWriter on the competitor-csv queue; because
 * DomainEvent implements ShouldDispatchAfterCommit, a rolled-back DB write
 * (dedupe on UNIQUE(competitor_id, sku, recorded_at)) WILL NOT fire this
 * listener — idempotent re-ingest produces zero false-positive analysis.
 */
final class CompetitorPriceRecorded extends DomainEvent
{
    public function __construct(
        public readonly int $competitorId,
        public readonly string $sku,
        public readonly int $priceGrossPennies,
        public readonly int $priceExVatPennies,
        public readonly int $ingestRunId,
    ) {
        parent::__construct();
    }
}
