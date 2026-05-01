<?php

declare(strict_types=1);

namespace App\Domain\Quotes\Observers;

use App\Domain\Quotes\Exceptions\QuoteLineImmutableException;
use App\Domain\Quotes\Models\Quote;
use App\Domain\Quotes\Models\QuoteLine;

/**
 * Phase 11 Plan 02 — D-13 line snapshot immutability gate.
 *
 * Registered on QuoteLine via AppServiceProvider::boot in array form alongside
 * QuoteTotalRecomputeObserver. ORDER MATTERS: this observer runs FIRST so a
 * blocked save NEVER fires the recompute observer (Eloquent observe() array
 * preserves registration order; throwing in `saving` halts the save).
 *
 * Behavioural contract (PriceSnapshotterTest + QuoteLineImmutabilityObserverTest
 * lock both branches):
 *
 *   1. CREATION (! $line->exists) — no-op. PriceSnapshotter is the sole
 *      legitimate writer; the columns are set ONCE here.
 *
 *   2. UPDATE while parent Quote.status == draft:
 *      - quantity_int change ALLOWED + recomputes line_total_pence_at_quote
 *        (= unit_price_pence_at_quote * quantity_int) so QuoteTotalRecomputeObserver
 *        sees a fresh total even when the line was patched in-place.
 *      - mutation of unit_price_pence_at_quote / product_snapshot / sku /
 *        quote_id is FORBIDDEN — these are set ONCE on creation.
 *      - mutation of line_total_pence_at_quote alone is allowed (the observer
 *        just wrote it — see above).
 *
 *   3. UPDATE while parent Quote.status != draft:
 *      - ALL six business columns (sku, quantity_int, unit_price_pence_at_quote,
 *        line_total_pence_at_quote, product_snapshot, quote_id) are FORBIDDEN.
 *      - Only timestamps + the FK chain are allowed (Eloquent updates updated_at
 *        every save — that's not in the forbidden list).
 *
 * Threat model link: T-11-02-01 (Tampering). The PinnedQuotePricesSurviveRuleEditTest
 * Step 6 (Plan 11-02 Task 2) verifies this branch live-fires.
 */
final class QuoteLineImmutabilityObserver
{
    /** Columns that may NEVER be mutated after a line exists, even in draft. */
    private const FORBIDDEN_IN_DRAFT = [
        'unit_price_pence_at_quote',
        'product_snapshot',
        'sku',
        'quote_id',
    ];

    /** Columns that may NEVER be mutated when parent Quote.status != draft. */
    private const FORBIDDEN_AFTER_DRAFT = [
        'unit_price_pence_at_quote',
        'line_total_pence_at_quote',
        'product_snapshot',
        'sku',
        'quote_id',
        'quantity_int',
    ];

    public function saving(QuoteLine $line): void
    {
        // Initial creation — PriceSnapshotter is the sole legitimate writer
        // (no observer enforcement needed, factories + writer set ONCE).
        if (! $line->exists) {
            return;
        }

        $quote = $line->quote()->first();
        if ($quote === null) {
            // Defensive: a parent-less line shouldn't exist (FK CASCADE), but if
            // someone built one in-memory we treat the absent parent as locked.
            return;
        }

        $isDraft = $quote->status === Quote::STATUS_DRAFT;

        if ($isDraft) {
            // Allow quantity_int edits + recompute the cached line_total. The
            // recompute fires BEFORE the save persists, so the row written to
            // the DB already carries the up-to-date total.
            if ($line->isDirty('quantity_int')) {
                $line->line_total_pence_at_quote = (int) $line->unit_price_pence_at_quote * (int) $line->quantity_int;
            }

            $dirty = $this->collectDirty($line, self::FORBIDDEN_IN_DRAFT);
            if (! empty($dirty)) {
                throw QuoteLineImmutableException::forDirtyColumns(
                    $line->id,
                    $quote->id,
                    $quote->status,
                    $dirty,
                );
            }

            return;
        }

        // Status != draft — every business column is locked.
        $dirty = $this->collectDirty($line, self::FORBIDDEN_AFTER_DRAFT);
        if (! empty($dirty)) {
            throw QuoteLineImmutableException::forDirtyColumns(
                $line->id,
                $quote->id,
                $quote->status,
                $dirty,
            );
        }
    }

    /**
     * @param  array<int, string>  $columns
     * @return array<string, array{old: mixed, new: mixed}>
     */
    private function collectDirty(QuoteLine $line, array $columns): array
    {
        $dirty = [];
        foreach ($columns as $column) {
            if ($line->isDirty($column)) {
                $dirty[$column] = [
                    'old' => $line->getOriginal($column),
                    'new' => $line->getAttribute($column),
                ];
            }
        }

        return $dirty;
    }
}
