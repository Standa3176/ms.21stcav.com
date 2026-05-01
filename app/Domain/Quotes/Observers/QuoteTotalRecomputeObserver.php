<?php

declare(strict_types=1);

namespace App\Domain\Quotes\Observers;

use App\Domain\Quotes\Models\Quote;
use App\Domain\Quotes\Models\QuoteLine;

/**
 * Phase 11 Plan 02 — Quote.total_pence_at_quote derived-column recompute (OQ-1).
 *
 * Registered on QuoteLine SECOND in AppServiceProvider::boot — runs only
 * when the immutability observer's `saving` gate has passed (so a blocked
 * save never bumps the parent total). Hooks `saved` + `deleted`:
 *
 *   - saved: a line was inserted OR updated. SUM(line_total_pence_at_quote)
 *     across all lines is rewritten into the parent Quote.
 *   - deleted: a line was removed. SUM rewritten.
 *
 * Status guard:
 *   Plan 11-01 cached `quotes.total_pence_at_quote` is locked alongside the
 *   lines after status=sent (D-13). The immutability observer already blocks
 *   line-level mutations after draft; this observer additionally short-circuits
 *   when Quote.status != draft so any indirect path that did slip through (e.g.
 *   a soft-delete cascade caught by deleted()) does not silently overwrite the
 *   total snapshot.
 *
 * saveQuietly() is intentional — Quote.LogsActivity (Plan 11-01) DOES include
 * total_pence_at_quote in logOnly, but a derived recompute is not a meaningful
 * audit event (T-11-02-04 mitigation: prevent activity_log noise on every line
 * edit). The status / status-timestamp transitions (the meaningful audit
 * surface) still log normally because they fire on the Quote model directly.
 */
final class QuoteTotalRecomputeObserver
{
    public function saved(QuoteLine $line): void
    {
        $this->recompute($line);
    }

    public function deleted(QuoteLine $line): void
    {
        $this->recompute($line);
    }

    private function recompute(QuoteLine $line): void
    {
        // Bypass cached relation — the line we just touched might have been
        // detached from the relation cache; re-fetch ensures we see the
        // current parent state.
        $quote = $line->quote()->first();
        if ($quote === null) {
            return;
        }

        if ($quote->status !== Quote::STATUS_DRAFT) {
            return; // Locked — total moves with the lines, both frozen.
        }

        $quote->total_pence_at_quote = (int) $quote->lines()->sum('line_total_pence_at_quote');
        $quote->saveQuietly();
    }
}
