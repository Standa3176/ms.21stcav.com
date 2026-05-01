<?php

declare(strict_types=1);

namespace App\Domain\Quotes\Services;

use App\Domain\Quotes\Models\Quote;
use App\Domain\Quotes\Models\QuoteLine;

/**
 * Phase 11 Plan 02 — QuoteLine writer (QUOT-02).
 *
 * SOLE creation path for QuoteLine rows within app/Domain/Quotes/. Composes
 * PriceSnapshotter::buildLine() + QuoteLine::create() into a single atomic
 * action with deterministic sort_order assignment (MAX(sort_order)+10 step
 * lets admins reorder lines without renumbering every row).
 *
 * Why a separate service vs putting QuoteLine::create() inline at call sites:
 * Plan 11-03 Filament Resource ($snapshotter is constructor-injected into the
 * Resource action), Plan 11-04 PDF preview, future Phase 13 WhatsApp / Phase
 * 14 chatbot quote-creation flows ALL go through this single seam — that
 * means the snapshot algorithm has one canonical entry point and Plan 11-02's
 * tests cover the only path lines can be created.
 *
 * Why `add` (not `create`/`make`): differentiates from Eloquent semantics.
 * Filament Resource can't accidentally call ::create() and skip the snapshot.
 */
final class QuoteLineWriter
{
    public function __construct(
        private readonly PriceSnapshotter $snapshotter,
    ) {}

    /**
     * Append a new QuoteLine to a Quote with snapshotted pricing.
     *
     * Sort order strategy: next = MAX(existing sort_order) + 10. The +10 step
     * lets admins drag-reorder via the Filament Resource without renumbering
     * every row (e.g. inserting between line A=10 and line B=20 by giving
     * the new line sort_order=15).
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException when SKU
     *         does not match any product (propagates from resolveForQuote).
     */
    public function add(Quote $quote, string $sku, int $quantity): QuoteLine
    {
        $nextSort = ((int) $quote->lines()->max('sort_order')) + 10;
        $data = $this->snapshotter->buildLine($quote, $sku, $quantity, $nextSort);

        return QuoteLine::create($data);
    }
}
