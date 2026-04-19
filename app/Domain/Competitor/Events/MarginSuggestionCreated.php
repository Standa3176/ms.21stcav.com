<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Events;

use App\Foundation\Events\DomainEvent;

/**
 * Phase 5 Plan 03 Task 2 — fires when ComputeMarginSuggestionJob successfully
 * creates a Suggestion(kind='margin_change') row.
 *
 * Phase 7 dashboard subscribes for "new margin suggestions today" badge.
 * Primitives only per T-03-05 — suggestionId is the ULID string from
 * Suggestion::create().
 */
final class MarginSuggestionCreated extends DomainEvent
{
    public function __construct(
        public readonly string $suggestionId,
        public readonly int $competitorId,
        public readonly string $sku,
        public readonly int $proposedMarginBps,
    ) {
        parent::__construct();
    }
}
