<?php

declare(strict_types=1);

namespace App\Domain\CRM\Services;

use App\Domain\CRM\Models\BitrixBackfillRun;

/**
 * Phase 4 Plan 05 — BitrixBackfillRun progress tracker.
 *
 * Each BackfillOrdersChunkJob holds an instance of this tracker and increments
 * the BitrixBackfillRun counters atomically via `->increment()` (SQL UPDATE …
 * SET col = col + n) so multiple chunk jobs running in parallel on the same
 * sync-bulk queue do not race-clobber each other's counts.
 *
 * The orchestrator (BitrixBackfillOrdersCommand) resolves a single tracker per
 * run and reuses it across chunks.
 */
final class BackfillProgressTracker
{
    public function __construct(
        private readonly BitrixBackfillRun $run,
    ) {
    }

    public function run(): BitrixBackfillRun
    {
        return $this->run;
    }

    public function incrementProcessed(int $n = 1): void
    {
        $this->run->increment('processed_orders', $n);
    }

    public function incrementSkipped(int $n = 1): void
    {
        $this->run->increment('skipped_orders', $n);
    }

    public function incrementFailed(int $n = 1): void
    {
        $this->run->increment('failed_orders', $n);
    }

    public function incrementAdoptedLegacy(int $n = 1): void
    {
        $this->run->increment('adopted_legacy_count', $n);
    }

    public function incrementTotal(int $n = 1): void
    {
        $this->run->increment('total_orders', $n);
    }

    public function updateCursor(string $wooOrderId): void
    {
        $this->run->update(['last_cursor' => $wooOrderId]);
    }

    public function finish(?string $notes = null): void
    {
        $this->run->update([
            'finished_at' => now(),
            'notes' => $notes,
        ]);
    }
}
